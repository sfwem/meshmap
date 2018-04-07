#!/usr/bin/env php
<?php $mtimeStart = microtime(true);
//trying something with git... (remove this later)
/*************************************************************************************
* get-map-info script v2 by kg6wxc\eric satterlee kg6wxc@gmail.com
* This script is the heart of kg6wxcs' mesh map system.
* bug fixes, improvements and corrections are welcomed!
*                                                                                               
* One Script to rule them all!!
*
*late march 2017
*Many, many updates and changes.
*Almost a complete rewrite.
*No longer requires "olsr-topology-view",
*or wxc's original get-topology.sh or build_topology.php.
*Also no longer requires jsontomysql.php or get_nodes.sh.
*The temporary files called mesh_hosts and topology.new are no longer needed as well.
*You can safely delete all of those files if you had them.
*
*This one script should do it all.
*It will run different parts at different times. 
*
*Now using "netcat" to get the olsr info, this was Mark's (N2MH) idea.
*Turns out there are "pure" PHP ways to do that, do not even have to make a call to "nc" any longer.
*Mark also caught a bug and now there is a better way to find the wifi mac address that should work for all devices.
*
*Moved many things into the wxc_functions file.
*Alternate IP to host name solution, since PHP's own function would sometimes fail.
*Moved wxc's totally site-specific stuff into an alternate file that is not required.
*
**************************************************************************************/

/******
* OTHER NOTES HERE
******/

$INCLUDE_DIR = "/srv/meshmap";

//our user-settings file. (ALWAYS REQUIRED!, change path if you moved it!)
$USER_SETTINGS = parse_ini_file($INCLUDE_DIR . "/scripts/user-settings.ini");

//kg6wxc's functions. (ALWAYS REQUIRED!, change path if you moved it!)
require $INCLUDE_DIR . "/scripts/wxc_functions.inc";

//output only to console, nothing saved. (great to just see what it does)
$TEST_MODE_NO_SQL = 0;
//output to console, but *with* calls to the database. (see what it's doing while saving data)
$TEST_MODE_WITH_SQL = 1;

//are we in either test mode?
if ($TEST_MODE_NO_SQL) {
	$showRuntime = 1;
	$testLinkInfo = 1;
	$testNodePolling = 1;
	$do_sql = 0;
	$getLinkInfo = 1;
	$getNodeInfo = 1;
	echo "TEST MODE (NO SQL) ENABLED!\n";
}elseif ($TEST_MODE_WITH_SQL) {
	$showRuntime = 1;
	$testLinkInfo = 1;
	$testNodePolling = 1;
	$do_sql = 1;
	$getLinkInfo = 0;
	$getNodeInfo = 0;
	echo "TEST MODE (WITH SQL) ENABLED!\n";
}else {
	$showRuntime = 0;
	$testLinkInfo = 0;
	$testNodePolling = 0;
	$do_sql = 1;
	$getLinkInfo = 0;
	$getNodeInfo = 0;
}

/***********************************************************************
 *DO NOT CHANGE ANYTHING BELOW HERE UNLESS YOU KNOW WHAT YOU ARE DOING!!!!
 ************************************************************************/

//this is probably missing for you, do not worry about it.
//It just contains very site specific things, you don't need it.
@include $INCLUDE_DIR . "/wxc_custom.inc";
$wxc_custom = 0;

//(WiP)checks for some things we need to run
//(currently only really checks for the mysqli php extension
wxc_checkConfigs();

//what time is it now? returns a DateTime Object with current time.
date_default_timezone_set($USER_SETTINGS['localTimeZone']);
$currentTime = wxc_getCurrentDateTime();

//this just tells the script if kg6wxc's custom stuff is there or not
//it should not effect your site at all.
//It's safe to leave it here unless you have major problems because of it.
//That shouldn't really happen. Don't change unless you know what you are doing.
foreach (get_included_files() as $filename) {
	if (strpos($filename, 'wxc_custom.inc')) {
		$wxc_custom = 1;
	}
}

$sql_db_tbl = $USER_SETTINGS['sql_db_tbl'];
$sql_db_tbl_node = $USER_SETTINGS['sql_db_tbl_node'];
$sql_db_tbl_topo = $USER_SETTINGS['sql_db_tbl_topo'];

if ($do_sql) {
	//an sql connection that we can reuse...
	$sql_connection	=	wxc_connectToMySQL();
}else {
	if ($TEST_MODE_NO_SQL) {
		wxc_echoWithColor("SQL Server access disabled!", "red");
		echo "\n";
		//echo "\033[31mSQL Server access disabled!\033[0m\n\n";
	}	
}
//check if we are ready to run
//(not quite done yet)
//wxc_checkConfigs();

//This controls when certain parts of the script run.
//We check the DB for the time we last checked and only run if we need to.
//intervals are now controled via the ini file now.
//thanks K6GSE!
if ($do_sql) {
	//if $do_sql is set to 1, check when we last got link info, if it was 5 minutes or more set the variable to 1
	$lastRunGetLinkInfo = wxc_scriptGetLastDateTime("LINKINFO", "topology");
	if($lastRunGetLinkInfo) {
		if ($USER_SETTINGS['link_update_interval'] > 0) {
			$intervalLINK = date_diff($lastRunGetLinkInfo, $currentTime);
			//if ($intervalLINK->i >= 5) {
			if ($intervalLINK->i >= intval($USER_SETTINGS['link_update_interval'])) {
				if ($TEST_MODE_WITH_SQL) {
					echo "It has been " . $USER_SETTINGS['link_update_interval'] . " or more minutes since this script got the link info\n";
					echo "Setting getLinkInfo to TRUE.\n";
				}
				$getLinkInfo = 1;
			}
		}
	}else {
		//probably never run before, let's get some data!
		$getLinkInfo = 1;
		}
		//if $do_sql is set to 1, check when we last polled all the known nodes, if it was 1 hour or more set the variable to 1
	$lastRunGetNodeInfo = wxc_scriptGetLastDateTime("NODEINFO", "node_info");
	if ($lastRunGetNodeInfo) {
		if ($USER_SETTINGS['node_polling_interval'] > 0) {
			$intervalNODE = date_diff($lastRunGetNodeInfo, $currentTime);
			$intervalNodeInMinutes = $intervalNODE->days * 24 * 60;
			$intervalNodeInMinutes += $intervalNODE->h * 60;
			$intervalNodeInMinutes += $intervalNODE->i;
			//if ($intervalNODE->h >= 1) {
			//if ($intervalNODE->i >= intval($USER_SETTINGS['node_polling_interval'])) {
			if ($intervalNodeInMinutes >= intval($USER_SETTINGS['node_polling_interval'])) {
				if ($TEST_MODE_WITH_SQL) {
					echo "It has been " . $USER_SETTINGS['node_polling_interval'] . " or more minutes since this script polled all the nodes\n";
					echo "Setting getNodeInfo to TRUE.\n";
				}
				$getNodeInfo = 1;
			}
		}
	}else {
		//probably never run before, lets get some data!!
		$getNodeInfo = 1;
	}
}
//check the database to see if we are already polling nodes
if ($do_sql) {
	$currently_polling_nodes = wxc_getMySql("SELECT script_last_run, currently_running from map_info WHERE id = 'NODEINFO'");
	if (is_null($currently_polling_nodes['currently_running'])) {
		$currently_polling_nodes['currently_running'] = 0;
		$getNodeInfo = 1;
	}elseif ($currently_polling_nodes['currently_running'] == 1) {
		//if ($currently_polling_nodes['script_last_run'])
		$getNodeInfo = 0;
	}
}

//check for old outdated node info (intervals will be set in the ini file)
$no_expire = $USER_SETTINGS['expire_old_nodes'];
if ($do_sql && $no_expire) {
	wxc_checkOldNodes();
}

$resolvedHostname = "";
$nodeLocationFixed = 0;
$node = "";
$noPort9090 = 0;
if ($getNodeInfo) {
	//this section is what goes out to each node on the mesh and asks for it's info
	//this is really the heart of the mapping system, without this (and the sysinfo.json file),
	//none of this would even be possible.
	
	//tell the database we are actively polling nodes
	if ($do_sql && $currently_polling_nodes['currently_running'] == 0) {
		wxc_putMySql("INSERT INTO map_info (id, table_or_script_name, script_last_run, currently_running) VALUES ('NODEINFO', 'node_info', NOW(), '1') ON DUPLICATE KEY UPDATE table_or_script_name = 'node_info', script_last_run = NOW(), currently_running = '1'");
	}
	$meshNodes = wxc_netcat($USER_SETTINGS['localnode'], "2004", null, "ipOnly"); //get a new list of IP's
	if ($meshNodes) {
		/* TESTING IDEA */
		
		$ipAddrArray = explode("\n", $meshNodes);
		$ipAddrArrayChunks = array_chunk($ipAddrArray, $USER_SETTINGS['numParallelThreads']);
		foreach($ipAddrArrayChunks as $chunk => $ipList) {
			foreach($ipList as $ipAddr) {
				//echo "";
			}
		}
		foreach($ipAddrArray as $ipAddr) {
			//echo "";
		}
		
		/* END TESTING */
		foreach (preg_split("/((\r?\n)|(\r\r?))/", $meshNodes) as $line) {
		list ($ipAddr) = explode("\n", $line);
		
		//check for nodes that we know will not have the info we are going to request and skip them
		if ($do_sql) {
			if (wxc_getMySql("SELECT ip, reason FROM hosts_ignore WHERE ip = '$ipAddr'")) {
				continue;
			}
		}
		
		//copy to new var name (I dont feel like editing it all right now)
		$nodeName = $ipAddr;
		if ($USER_SETTINGS['node_polling_parallel']) {
			//for($count = 1; $count <= 20; $count++) {
			//	$ipAddrList .= $ipAddr . "\n";
			//}
			$parallel_pids = array();
			//$parallel_pids[] = trim(shell_exec("php $INCLUDE_DIR/scripts/parallel_node_polling.php $ipAddr $do_sql 0 > /dev/null 2>/dev/null & echo $!"));
			$parallel_pids[] = trim(shell_exec("php $INCLUDE_DIR/scripts/parallel_node_polling.php $ipAddr $do_sql 0 > /dev/null 2>/dev/null & echo $!"));
			//$parallel_pids[] = shell_exec("php $INCLUDE_DIR/scripts/parallel_node_polling.php $ipAddr $do_sql 0 > /dev/null & echo $!");
			
		      //var_dump($parallel_pids);
			 
		}else {
			//get the sysinfo.json file from the node being polled.
			$sysinfoJson = @file_get_contents("http://$ipAddr:8080/cgi-bin/sysinfo.json"); //get the .json file
			$jsonFetchSuccess = 1;
			//check if we got anything back, if not, try to tell why.
			if($sysinfoJson === FALSE) {
				$jsonFetchSuccess = 0;
				$error = error_get_last();
				wxc_checkErrorMessage($error, $nodeName);
				
				//just skip the next IP
				continue;
			}else {
				//node is there, get all the info we can
				//get all the data from the json file and decode it
				$result = json_decode($sysinfoJson,true);
				
				//if there's nothing really there just skip to the next IP
				if (!$result) {
					continue;
				}
				//first let's see what node we are dealing with
				//$node = $GLOBALS['node'] = $result['node'];
				$node = $result['node'];
				
				//gather lots of other information from OLSRD about the node being polled (we hopefully can use this later)
				//this requires a second connection to the node. no way around that.
				//but only if we have a firmware version >= than 3.16.0 (or "develop-16")
				$firmware_version = $result['firmware_version'];
				$olsrdInfo = 0;

				//changed again to exclude some nodes 10-21-2017
				/************
				 * REMOVED due to issues
				 * DO NOT UNCOMMENT FOR NOW
				 ************/
				//if (version_compare($result['firmware_version'], "3.16.0.0", ">") || strpos($result['firmware_version'], "evelop-16") || $result['firmware_version'] === "linux" || $result['firmware_version'] === "Linux") {
				//    $olsrdInfo = wxc_netcat("$ipAddr");
				//	$noPort9090 = 1;
				//}
								
				//maybe in the future we'll be able to get this info remotely too and use it for something
				//$olsrdDotDrawInfo = wxc_netcat("$nodeName.local.mesh","2004", null, null);	//save the polled nodes local dot_draw info (never know we may want it for something)
				
				//save json data to some variables
				//probably don't really need to do this, but it is what it is for now...
				$model = $result['model'];
				$lat = $result['lat'];
				$lon = $result['lon'];
				$chanbw = $result['chanbw'];
				$api_version = $result['api_version'];
				$board_id = $result['board_id'];
				$tunnel_installed = $result['tunnel_installed'];
				$ssid = $result['ssid'];
				$active_tunnel_count = $result['active_tunnel_count'];
				$channel = $result['channel'];
				$firmware_mfg = $result['firmware_mfg'];
				$grid_square = $result['grid_square'];
				
				//W6BI requested this info to be added, so here it is now. :)
				//current ip/mac address info
				if ($result['interfaces']) {
					foreach($result['interfaces'] as $interface) {
						$eth = "eth0";
						if ($result['model'] == "Ubiquiti Nanostation M XW" || $result['model'] == "AirRouter " || $result['model'] == "NanoStation M5 XW ") {
							//"AirRouter " model name bug caught and fixed by Mark, N2MH 13 March 2017.
							$eth = "eth0.0";
						}
						if ($interface['name'] == $eth) { // error with KK9DS on $name (undefined index)
							$lan_ip = $interface['ip']; //error with ke6upi here on $ip (undefinded index)
						}
						if ($interface['name'] == "wlan0") {
							$wlan_ip = $interface['ip'];
							$wifi_mac_address = $interface['mac']; //added to fix MAC address issue, caught by Mark, N2MH 14 March 2017.
						}
					}
				}
//**change node locations via admin page now!!**
// 				if ($wxc_custom) {
// 					if($do_sql) {
// 						$nodesToFixLocations = fixLocations($node, $lat, $lon);
// 						list ($node, $lat, $lon) = explode(" ", $nodesToFixLocations);
// 						$node = $node;
// 						$lat = $lat;
// 						$lon = $lon;
// 					}
// 				}
				
				if ($testNodePolling) {
					echo "Name: "; wxc_echoWithColor($result['node'], "purple"); echo "\n";
					echo "MAC Address: " . $wifi_mac_address . "\n";
					echo "Model: " . $result['model'] . "\n";
					if ($result['firmware_version'] !== $USER_SETTINGS['current_stable_fw_version']) {
						if (version_compare($result['firmware_version'], $USER_SETTINGS['current_stable_fw_version'], "<")) {
							if ($result['firmware_version'] === "Linux" || $result['firmware_version'] === "linux") {
								echo "Firmware: " . $result['firmware_version'] . "  <- \033[1;32mViva Linux!!!\033[0m\n";
							}else {
								echo "Firmware: " . $result['firmware_mfg'] . " " . $result['firmware_version'];
								wxc_echoWithColor(" Should update firmware!", "red");
								echo "\n";
							}
						}
						if (version_compare($result['firmware_version'], $USER_SETTINGS['current_stable_fw_version'], ">")) {
							//echo "Firmware: " . $result['firmware_mfg'] . " " . $result['firmware_version'] . "  <- \033[31mBeta firmware!\033[0m\n";
							echo "Firmware: " . $result['firmware_mfg'] . " " . $result['firmware_version'];
							wxc_echoWithColor(" Beta firmware!", "red");
							echo "\n";
						}
					}else {
						//echo "Firmware Version: " . $firmware_version . "\n";
						echo "Firmware: \033[32m" . $result['firmware_mfg'] . " " . $result['firmware_version'] . "\033[0m\n";
					}
					echo "LAN ip: " . $lan_ip . " WLAN ip: " . $wlan_ip . "\n";
					
					if (($result['lat']) && ($result['lon'])) {
						echo "Location: \033[32m" . $result['lat'] . ", " . $result['lon']. "\033[0m\n";
					//}elseif ($nodeLocationFixed = 1) {
					//	echo "\033[31mNo Location Info Set!\033[0m (FIXED!)\n";
					//	$nodeLocationFixed = 0;
					}else {
						echo "\033[31mNo Location Info Set!\033[0m\n";
					    //K6GSE's solution to deal with non-null values in the DB
						$lat = 0.00;
						$lon = 0.00;
						//end
					}
					
					if ($sysinfoJson && $olsrdInfo) {
						echo "\033[32mSaved OLSRd info and sysinfo.json\033[0m\n";
					}elseif ($sysinfoJson && !$olsrdInfo) {
						echo "\033[33mOnly saved sysinfo.json\033[0m\n";
						if ($olsrdInfo != 0) {
							echo $GLOBALS['errorMessageFrom_wxc_netcat_function'] . "\n";
						}else {
							echo "Did not try to fetch full OLSRd data...\n" .
							"\033[33mFirmware version less than 3.16 does not allow for remote OLSRd info gathering.\033[0m\n";
						}
					}else {
						echo "\033[31mGOT NOTHING FROM THE NODE!!!!\033[0m";					
					}
					echo "\n";
				}
				
				if ($do_sql) {
					$removed_node = wxc_getMySql("SELECT node, wifi_mac_address FROM removed_nodes WHERE node = '$node' OR wifi_mac_address = '$wifi_mac_address'");
					
					if ($removed_node['node'] == $node || $removed_node['wifi_mac_address'] == $wifi_mac_address) {
						wxc_putMySql("DELETE FROM removed_nodes WHERE node = '$node' OR wifi_mac_address = '$wifi_mac_address'");
					}
					
				}
					//our queries
				
				//this is saved in case it's actually needed later
				$sql_update_when_mac_addr_has_changed	=	"UPDATE $sql_db_tbl SET
            	wifi_mac_address=$wifi_mac_address,model=$model,
            	firmware_version=$firmware_version,
            	lat=$lat,lon=$lon,ssid=$ssid,chanbw=$chanbw,
            	api_version=$api_version,board_id=$board_id,
            	tunnel_install=$tunnel_installed,
            	active_tunnel_count=$active_tunnel_count,
            	channel=$channel,firmware_mfg=$firmware_mfg,
            	lan_ip=$lan_ip,wlan_ip=$wlan_ip,last_seen=NOW()
            	WHERE node=$node";
				
				/* testing trying to make things more readable and
				 * not use extra variables...
				 * it's not really working, quite a bitch to get formated just right
				 *
				 $sql = "INSERT INTO $sql_db_tbl(wifi_mac_address, node, model, firmware_version,
				 lat, lon, ssid, chanbw, api_version, board_id,
				 tunnel_installed, active_tunnel_count, channel,
				 firmware_mfg, lan_ip, wlan_ip, sysinfo_json, olsrinfo_json, last_seen) VALUES('" .
				 $wifi_mac_address . "','" .
				 $result['node'] . "','" .
				 $result['model'] . "','" .
				 $result['firmware_version'] . "','" .
				 $result['lat'] . "','" .
				 $result['lon'] . "','" .
				 $result['ssid'] . "','" .
				 $result['chanbw'] . "','" .
				 $result['api_version'] . "','" .
				 $result['board_id'] . "','" .
				 $result['tunnel_installed'] . "','" .
				 $result['active_tunnel_count'] . "','" .
				 $result['channel'] . "','" .
				 $result['firmware_mfg'] . "','" .
				 $lan_ip . "','" .
				 $wlan_ip . "','" .
				 $sysinfoJson . "','" .
				 $olsrdInfo . "', NOW()) " .
				 "ON DUPLICATE KEY UPDATE " .
				 "node = '" . $result['node'] . "','" .
				 "model = '" . $result['model'] . "','" .
				 "firmware_version = '" . $result['firmware_version'] . "','" .
				 "lat = '" . $result['lat'] . "','" .
				 "lon = '" . $result['lon'] . "','" .
				 "ssid = '" . $result['ssid'] . "','" .
				 "chanbw = '" . $result['chanbw'] . "','" .
				 "api_version = '" . $result['api_version'] . "','" .
				 "board_id = '" . $result['board_id'] . "','" .
				 "tunnel_installed = '" . $result['tunnel_installed'] . "','" .
				 "active_tunnel_count = '" . $result['active_tunnel_count'] . "','" .
				 "channel =' " . $result['channel'] . "','" .
				 "firmware_mfg = '" . $result['firmware_mfg'] . "','" .
				 "lan_ip = '" . $lan_ip . "','" .
				 $wlan_ip . "','" .
				 $sysinfoJson . "','" .
				 $olsrdInfo . "', NOW())";
				 */
				
				$sql	=	"INSERT INTO $sql_db_tbl(
				wifi_mac_address, node, model, firmware_version, lat, lon, grid_square, ssid, chanbw, api_version, board_id,
				tunnel_installed, active_tunnel_count, channel, firmware_mfg, lan_ip, wlan_ip, sysinfo_json, olsrinfo_json, last_seen)
				VALUES('$wifi_mac_address', '$node', '$model', '$firmware_version',
				'$lat', '$lon', '$grid_square', '$ssid', '$chanbw', '$api_version', '$board_id',
				'$tunnel_installed', '$active_tunnel_count', '$channel',
				'$firmware_mfg', '$lan_ip', '$wlan_ip', '$sysinfoJson', '$olsrdInfo', NOW())
				ON DUPLICATE KEY UPDATE wifi_mac_address = '$wifi_mac_address', node = '$node', model = '$model', firmware_version = '$firmware_version',
				lat = '$lat', lon = '$lon', grid_square = '$grid_square', ssid = '$ssid', chanbw = '$chanbw', api_version = '$api_version',
				board_id = '$board_id', tunnel_installed = '$tunnel_installed',
				active_tunnel_count = '$active_tunnel_count', channel = '$channel',
				firmware_mfg = '$firmware_mfg', lan_ip = '$lan_ip', wlan_ip = '$wlan_ip',
				sysinfo_json = '$sysinfoJson', olsrinfo_json = '$olsrdInfo', last_seen = NOW()";
				
				$sql_no_location_info  =    "INSERT INTO $sql_db_tbl(
				wifi_mac_address, node, model, firmware_version, grid_square, ssid, chanbw, api_version, board_id,
				tunnel_installed, active_tunnel_count, channel, firmware_mfg, lan_ip, wlan_ip, sysinfo_json, olsrinfo_json, last_seen)
				VALUES('$wifi_mac_address', '$node', '$model', '$firmware_version',
				'$grid_square', '$ssid', '$chanbw', '$api_version', '$board_id',
				'$tunnel_installed', '$active_tunnel_count', '$channel',
				'$firmware_mfg', '$lan_ip', '$wlan_ip', '$sysinfoJson', '$olsrdInfo', NOW())
				ON DUPLICATE KEY UPDATE wifi_mac_address = '$wifi_mac_address', node = '$node', model = '$model', firmware_version = '$firmware_version',
				grid_square = '$grid_square', ssid = '$ssid', chanbw = '$chanbw', api_version = '$api_version',
				board_id = '$board_id', tunnel_installed = '$tunnel_installed',
				active_tunnel_count = '$active_tunnel_count', channel = '$channel',
				firmware_mfg = '$firmware_mfg', lan_ip = '$lan_ip', wlan_ip = '$wlan_ip',
				sysinfo_json = '$sysinfoJson', olsrinfo_json = '$olsrdInfo', last_seen = NOW()";
				
				$sql_update_when_node_name_has_changed	=	"UPDATE $sql_db_tbl SET
            	node = '$node', model = '$model',
            	firmware_version = '$firmware_version',
            	lat = '$lat', lon = '$lon', grid_square = '$grid_square', ssid = '$ssid', chanbw = '$chanbw',
            	api_version = '$api_version', board_id = '$board_id',
            	tunnel_installed = '$tunnel_installed',
            	active_tunnel_count = '$active_tunnel_count',
            	channel = '$channel', firmware_mfg = '$firmware_mfg',
            	lan_ip = '$lan_ip', wlan_ip = '$wlan_ip', last_seen=NOW()
            	WHERE wifi_mac_address = '$wifi_mac_address'";
				
				$sql_update_when_node_name_has_changed_no_location_info	=	"UPDATE $sql_db_tbl SET
            	node = '$node', model = '$model',
            	firmware_version = '$firmware_version',
            	grid_square = '$grid_square', ssid = '$ssid', chanbw = '$chanbw',
            	api_version = '$api_version', board_id = '$board_id',
            	tunnel_installed = '$tunnel_installed',
            	active_tunnel_count = '$active_tunnel_count',
            	channel = '$channel', firmware_mfg = '$firmware_mfg',
            	lan_ip = '$lan_ip', wlan_ip = '$wlan_ip', last_seen=NOW()
            	WHERE wifi_mac_address = '$wifi_mac_address'";
				
				//find the currently stored name and mac address of the node we are looking at
				if($do_sql) {
				    $node_name_array	=	wxc_getMySql("SELECT node, wifi_mac_address FROM $sql_db_tbl WHERE wifi_mac_address = '$wifi_mac_address'");
				    $existing_node_name	=	$node_name_array['node'];
				    $existing_mac_addr	=	$node_name_array['wifi_mac_address'];
				}
				
				//check if we are updating this nodes location info or not
				if($do_sql) {
				    $fixedLocation = wxc_getMySql("SELECT location_fix FROM $sql_db_tbl_node WHERE node = '$node'");
				    if ($fixedLocation['location_fix'] == "1") {
				        //check if we have changed node name and have the same hardware.
				        //the database itself should handle if there is new hardware with the same node name.
				        //if name has not changed, update the DB as normal for each node
				        if ($do_sql && $sysinfoJson) {
				            if(!$existing_mac_addr) {
				                wxc_putMySql($sql_no_location_info);
				            }elseif($existing_mac_addr == $wifi_mac_address) {
				                if ($existing_node_name !== $node) {
				                    wxc_putMySql($sql_update_when_node_name_has_changed_no_location_info);
				                    //echo "";
				                }else {
				                    wxc_putMySQL($sql_no_location_info);
				                }
				            }
				        }
				    }else {
				        //check if we have changed node name and have the same hardware.
				        //the database itself should handle if there is new hardware with the same node name.
				        //if name has not changed, update the DB as normal for each node
				        if ($do_sql && $sysinfoJson) {
				            if(!$existing_mac_addr) {
				                wxc_putMySql($sql);
				            }elseif($existing_mac_addr == $wifi_mac_address) {
				                if ($existing_node_name !== $node) {
				                    wxc_putMySql($sql_update_when_node_name_has_changed);
				                    //echo "";
				                }else {
				                    wxc_putMySQL($sql);
				                }
				            }
				        }
				    }
				}
					//Thanks to K6GSE
					// Clear Variables so they do not carry over
					$wifi_mac_address = NULL;
					$node = NULL;
					$model = NULL;
					$firmware_version = NULL;
					$lat = NULL;
					$lon = NULL;
					$ssid = NULL;
					$chanbw = NULL;
					$api_version = NULL;
					$board_id = NULL;
					$tunnel_installed = NULL;
					$active_tunnel_count = NULL;
					$channel = NULL;
					$firmware_mfg = NULL;
					$lan_ip = NULL;
					$wlan_ip = NULL;
					$sysinfoJson = NULL;
					$olsrdInfo = NULL;
				}
			}
		}
	} //end of foreach loop

	//update the database with the time, so we know when this part of the script last ran
	if($do_sql) {
		wxc_scriptUpdateDateTime("NODEINFO", "node_info");
		wxc_putMySql("UPDATE map_info SET currently_running = '0' WHERE id = 'NODEINFO'");
	}
}
/*
foreach ($parallel_pids as $index => $pid) {
//    $stillRunning = count(exec("ps $pid"))
    exec ("ps $pid", $processState);
    $temp = count($processState >= 2);
    if ($temp = 1) {
        echo "still running...";
        continue;
    }else {
        unset($parallel_pids['$index']);
        $parallel_pids = value_arrays($parallel_pids);
        //continue;
    }
    echo "\nthere should be no more polling scripts running....\n";
    //var_dump($temp);
}
*/
if ($getLinkInfo) {
	if ($do_sql) {
		wxc_putMySQL("TRUNCATE TABLE $sql_db_tbl_topo");	//clear out the old info in the "topology" table first
	}
	
	$meshNodes = wxc_netcat($USER_SETTINGS['localnode'], "2004", null, "linkInfo");	//get the latest link info
	if ($meshNodes) {
		foreach (preg_split("/((\r?\n)|(\r\r?))/", $meshNodes) as $line) {	//split the string on the \n's (new line)
			//if ($line !== "") {
				list ($node, $linkto, $cost) = explode(" ", $line);
				//echo "Node: " . $node . " Linkto: " . $linkto . " Cost: " . $cost . "\n";
			//}
			
			$nodeIp = $node;
			$linktoIp = $linkto;
			//$nodeName = wxc_resolveIP($node);
			//$linktoName = wxc_resolveIP($linkto);
			$nodeName = $node;
			$linktoName = $linkto;
			
			if ($do_sql) {	//put the data into SQL, if we can.	
				wxc_putMySQL("INSERT INTO $sql_db_tbl_topo(node, linkto, cost) VALUES('$nodeName', '$linktoName', '$cost')");
			}
			
			if ($testLinkInfo) {	//output to screen if we are in "test mode".
				if ($cost > 0.1 && $cost <=2) {
					$cost = "\033[1;32m" . $cost . "\033[0m";
				}
				if ($cost >2 && $cost <4) {
					$cost = "\033[0;32m" . $cost . "\033[0m";
				}
				if ($cost >4 && $cost <6) {
					$cost = "\033[1;33m" . $cost . "\033[0m";
				}
				if ($cost >6 && $cost <10) {
					$cost = "\033[0;31m" . $cost . "\033[0m";
				}
				if ($cost >10) {
					$cost = "\033[1;31m" . $cost . "\033[0m";
				}
				echo "$nodeName -> $linktoName cost: $cost\n";
			}
			if($do_sql) {
				wxc_scriptUpdateDateTime("LINKINFO", "topology");
			}
		}
	}
}

$mtimeEnd = microtime(true);
$totalTime = $mtimeEnd-$mtimeStart;
if ($showRuntime) {
	echo "Time Elapsed: " . round($totalTime, 2) . " seconds ( " . round($totalTime/60, 2) . " minutes ).\n";
}

?>
