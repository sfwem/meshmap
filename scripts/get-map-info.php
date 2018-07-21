#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli') {
    $file = basename($_SERVER['PHP_SELF']);
    exit("<style>html{text-align: center;}p{display: inline;}</style>
        <br><strong>This script ($file) should only be run from the 
        <p style='color: red;'>command line</p>!</strong>
        <br>exiting...");
}
$mtimeStart = microtime(true);
/*************************************************************************************
* get-map-info script v3 by kg6wxc\eric satterlee kg6wxc@gmail.com
* Licensed under GPLv3 or later
* This script is the heart of kg6wxcs' mesh map system.
* bug fixes, improvements and corrections are welcomed!
*                                                                                               
* One Script to rule them all!!
* 
* Mid April 2018
* Added the above check for CLI environment, this script should not be run in the browser!
* It will now exit if you try to do so.
* Cleaned up the Array generation in the map_functions file, PHP 7.2.3 barfed all over before,
* It is now done properly and has been tested on php5 and php7.
* moved the entire system to a new private git repo.
* more to come....
*
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
* This file is part of the Mesh Mapping System.
* The Mesh Mapping System is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* The Mesh Mapping System is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with The Mesh Mapping System.  If not, see <http://www.gnu.org/licenses/>.
******/

//Increase PHP memory limit to 128M (you may need more if you are connected to a "Mega Mesh" :) )
ini_set('memory_limit', '128M');

/***********************************************************************
 *DO NOT CHANGE ANYTHING BELOW HERE UNLESS YOU KNOW WHAT YOU ARE DOING!!!!
 ************************************************************************/
$INCLUDE_DIR = "..";

//check for users user-settings.ini file and use it if it exists
//inform the user and exit if the file does not exist
global $USER_SETTINGS;
if (file_exists($INCLUDE_DIR . "/scripts/user-settings.ini")) {
    $USER_SETTINGS = parse_ini_file($INCLUDE_DIR . "/scripts/user-settings.ini");
}else {
    exit("\n\nYou **must** copy the user-settings.ini-default file to user-settings.ini and edit it!!\n\n");
}

//kg6wxc's functions. (ALWAYS REQUIRED!, change path if you moved it!)
require $INCLUDE_DIR . "/scripts/wxc_functions.inc";

//the custom include file
//the "@" just suppresses any errors if the file is not found, the file is optional
@include $INCLUDE_DIR . "/custom.inc";

$script_arg = "";
if (isset($argv[1])) {
    $script_arg = $argv[1];
}
if ($script_arg == "--help" || $script_arg == "--h" || $script_arg == "-help" || $script_arg == "-h" || $script_arg == "/?" || $script_arg == "?") {
    echo $argv[0] . " Usage:\n\n";
    echo $argv[1] . "\tThis help message\n\n";
    echo "--test-mode-no-sql\tDO NOT access database only output to screen\n";
    echo "(useful to make sure everything is working)\n\n";
    echo "--test-mode-with-sql\tDO access the database AND output to screen\n";
    echo "(useful to see if everything is working and there are no errors reading/writing to the database)\n\n";
    echo "No arguments to this script will run it in \"silent\" mode, good for cron jobs! :)\n";
    echo "\n";
    exit();
}
$TEST_MODE_NO_SQL = 0;
$TEST_MODE_WITH_SQL = 0;
if ($script_arg == "--test-mode-no-sql") {
    //output only to console, nothing saved. (great to just see what it does)
    $TEST_MODE_NO_SQL = 1;
}
if ($script_arg == "--test-mode-with-sql") {
    //output to console, but *with* calls to the database. (see what it's doing while saving data)
    $TEST_MODE_WITH_SQL = 1;
}

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

//(WiP)checks for some things we need to run
//(currently only really checks for the mysqli php extension
wxc_checkConfigs();

//what time is it now? returns a DateTime Object with current time.
date_default_timezone_set($USER_SETTINGS['localTimeZone']);
$currentTime = wxc_getCurrentDateTime();

//what do we want to use in the sql server
$sql_db_tbl = $USER_SETTINGS['sql_db_tbl'];
$sql_db_tbl_node = $USER_SETTINGS['sql_db_tbl_node'];
$sql_db_tbl_topo = $USER_SETTINGS['sql_db_tbl_topo'];

if ($do_sql) {
	//an sql connection that we can reuse...
	//$sql_connection	=	wxc_connectToMySQL();
	wxc_connectToMySQL();
	//check for new or changed database items (tables, columns,etc)
	wxc_checkDB();
}else {
	if ($TEST_MODE_NO_SQL) {
		wxc_echoWithColor("SQL Server access disabled!", "red");
		echo "\n";
		//echo "\033[31mSQL Server access disabled!\033[0m\n\n";
	}	
}


//if ($do_sql) {
//	wxc_checkDB();
//}


//This controls when certain parts of the script run.
//We check the DB for the time we last checked and only run if we need to.
//intervals are now controled via the ini file.
//thanks K6GSE!
if ($do_sql) {
	//if $do_sql is set to 1, check when we last polled all the known nodes, if it was more than the interval set the variable to 1
	$lastRunGetNodeInfo = wxc_scriptGetLastDateTime("NODEINFO", "node_info");
	if ($lastRunGetNodeInfo) {
		if ($USER_SETTINGS['node_polling_interval'] > 0) {
			$intervalNODE = date_diff($lastRunGetNodeInfo, $currentTime);
			$intervalNodeInMinutes = $intervalNODE->days * 24 * 60;
			$intervalNodeInMinutes += $intervalNODE->h * 60;
			$intervalNodeInMinutes += $intervalNODE->i;
			if ($intervalNodeInMinutes >= intval($USER_SETTINGS['node_polling_interval'])) {
				if ($TEST_MODE_WITH_SQL) {
					echo "It has been " . $USER_SETTINGS['node_polling_interval'] . " or more minutes since this script polled all the nodes\n";
					echo "Set to poll nodes.\n";
				}
				$getNodeInfo = 1;
			}
		}
	}else {
		//probably never run before, lets get some data!!
		echo "Set to poll nodes.\n";
		$getNodeInfo = 1;
	}
	
	//if $do_sql is set to 1, check when we last got link info, if it was more than the interval set the variable to 1
	$lastRunGetLinkInfo = wxc_scriptGetLastDateTime("LINKINFO", "topology");
	if($lastRunGetLinkInfo) {
		if ($USER_SETTINGS['link_update_interval'] > 0) {
			$intervalLINK = date_diff($lastRunGetLinkInfo, $currentTime);
			if ($intervalLINK->i >= intval($USER_SETTINGS['link_update_interval'])) {
				if ($TEST_MODE_WITH_SQL) {
					echo "It has been " . $USER_SETTINGS['link_update_interval'] . " or more minutes since this script got the link info\n";
					echo "Set to get network linking info.\n\n";
				}
				$getLinkInfo = 1;
			}
		}
	}else {
		//probably never run before, let's get some data!
		echo "Set to get network linking info.\n\n";
		$getLinkInfo = 1;
	}
}
//check the database to see if we are already polling nodes
//this trys to prevent 2 polling runs at once
//it does nothing in "--test-mode-no-sql"
if ($do_sql) {
	$currently_polling_nodes = wxc_getMySql("SELECT script_last_run, currently_running from map_info WHERE id = 'NODEINFO'");
	if (is_null($currently_polling_nodes['currently_running'])) {
		$currently_polling_nodes['currently_running'] = 0;
		$getNodeInfo = 1;
	}elseif ($currently_polling_nodes['currently_running'] == 1) {
		$getNodeInfo = 0;
	}
	//hopefully catch a stalled polling run after 3 * node_polling_interval has expired.
	//something may have gone wonky and the node polling run never completed and never had a chance
	//to unset the "currently_running" bit in the DB,
	//this *should* catch that and and just run the node polling again
	if ($currently_polling_nodes['currently_running'] == 1 && $intervalNodeInMinutes >= intval($USER_SETTINGS['node_polling_interval']) * 3) {
	    $currently_polling_nodes['currently_running'] = 0;
		$getNodeInfo = 1;
	}
}

//check for old outdated node info (intervals will be set in the ini file)
$do_expire = $USER_SETTINGS['expire_old_nodes'];
if ($do_sql && $do_expire) {
	wxc_checkOldNodes();
}
if ($do_sql) {
	wxc_removeIgnoredNodes();
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
	//get a new list of IP's to poll
	$meshNodes = wxc_netcat($USER_SETTINGS['localnode'], "2004", null, "ipOnly");
	if ($meshNodes) {
		
		/* TESTING IDEA */
		/*
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
		*/
		/* END TESTING */
		
		
		foreach (preg_split("/((\r?\n)|(\r\r?))/", $meshNodes) as $line) {
		list ($ipAddr) = explode("\n", $line);
		
		//check for nodes that we know will not have the info we are going to request and skip them
		if ($do_sql) {
			if (wxc_getMySql("SELECT ip FROM hosts_ignore WHERE ip = '$ipAddr'")) {
				continue;
			}
		}
		
		//copy to new var name (I dont feel like editing it all right now)
		$nodeName = $ipAddr;
		if ($USER_SETTINGS['node_polling_parallel']) {
			//for($count = 1; $count <= 20; $count++) {
			//	$ipAddrList .= $ipAddr . "\n";
			//}
//			$parallel_pids = array();
			//$parallel_pids[] = trim(shell_exec("php $INCLUDE_DIR/scripts/parallel_node_polling.php $ipAddr $do_sql 0 > /dev/null 2>/dev/null & echo $!"));
//			$parallel_pids[] = trim(shell_exec("php $INCLUDE_DIR/scripts/parallel_node_polling.php $ipAddr $do_sql 0 > /dev/null 2>/dev/null & echo $!"));
			//$parallel_pids[] = shell_exec("php $INCLUDE_DIR/scripts/parallel_node_polling.php $ipAddr $do_sql 0 > /dev/null & echo $!");
			
		      //var_dump($parallel_pids);
			 
		}else {
			//get the sysinfo.json file from the node being polled.
			$sysinfoJson = @file_get_contents("http://$ipAddr:8080/cgi-bin/sysinfo.json?services_local=1"); //get the .json file
			$jsonFetchSuccess = 1;
			//check if we got anything back, if not, try to tell why.
			if($sysinfoJson === FALSE) {
				$jsonFetchSuccess = 0;
				$error = error_get_last();
				wxc_checkErrorMessage($error, $nodeName);
				//just skip to the next IP since there was an error
				continue;
			}else {
				//node is there, get all the info we can
				//get all the data from the json file and decode it
				$result = json_decode($sysinfoJson,true);
				
				//if there's nothing really there just skip to the next IP
				if (!$result || empty($result)) {
					continue;
				}
				//first let's see what node we are dealing with
				//$node = $GLOBALS['node'] = $result['node'];
				$node = $result['node'];
				
				//if it's nothing other than the node name, it's some other device
				//or something else entirely... 
				//people hack things onto the mesh all the time
				//
				//kg6wxc is *not* guilty of such things... :)
				//
				//just a few checks for nothing usually catches it.
                if ($result['node'] && $result['lat'] == "" && $result['lon'] == "" && $result['ssid'] == ""
                 && $result['model'] == "" && $result['firmware_mfg'] == ""
				 && $result['api_version'] == "") {
                        continue;
                }
				
				//save json data to some variables
				//probably don't really need to do this, but it is what it is for now...
                $firmware_version = $result['firmware_version'];
                $model = $result['model'];
				
				//this only seems to affect some nodes.
				//if lat || lon is blank, make it "0"
				//this was sometimes screwing up the SQL writing function
				//but not always of course.
				if ($result['lat'] == "") {
				    $lat = 0.0;
				}else {
				    $lat = $result['lat'];
				}
				if ($result['lon'] == "") {
				    $lon = 0.0;
				}else {
				    $lon = $result['lon'];
				}
				
				$chanbw = $result['chanbw'];
				$api_version = $result['api_version'];
				$board_id = $result['board_id'];
				$tunnel_installed = $result['tunnel_installed'];
				$ssid = $result['ssid'];
				$active_tunnel_count = $result['active_tunnel_count'];
				$channel = $result['channel'];
				$firmware_mfg = $result['firmware_mfg'];
				
				//had to screen scrape the status page for this info before (or use the evil port 9090!)
				//now it is here! :)
				$uptime = "NotAvailable";
				$loadavg = "NotAvailable";
				if (version_compare($api_version, "1.2", ">=")) {
					$uptime = $result['sysinfo']['uptime'];
					$loadavg = serialize($result['sysinfo']['loads']); // <-- this is an array that has been serialized!!
				}
				
				//local service listing are now in the json file!!  yay!
				//this required evil port 9090 before
				$services = "NotAvailable";
				if (version_compare($api_version, "1.3", ">=")) {
					$services = serialize($result['services_local']); // <-- this is an array that has been serialized!
				}
				
				//this only seems to affect some nodes.
				//if grid_square is blank, make it "none"
				//this was sometimes screwing up the SQL writing function
				//but not always of course.
				if ($result['grid_square'] == "") {
				    $grid_square = "none";
				}else {
				    $grid_square = $result['grid_square'];
				}
				
				//W6BI requested this info to be added, so here it is now. :)
				//current ip/mac address info
				if ($result['interfaces']) {
					foreach($result['interfaces'] as $interface => $infInfo) {
						$eth = "eth0";
						if ($result['model'] == "Ubiquiti Nanostation M XW" || $result['model'] == "AirRouter " || $result['model'] == "NanoStation M5 XW ") {
							//"AirRouter " model name bug caught and fixed by Mark, N2MH 13 March 2017.
							$eth = "eth0.0";
						}
						//
						// This should catch some of those pesky ones
						// what a fricken nightmare... jeez
						//
						if ($result['api_version'] == "1.0" && $infInfo['name'] == $eth && $interface !== $eth) {
							$lan_ip = $infInfo['ip'];
						}
						//elseif ($result['api_version'] == "1.0" && $infInfo['name'] == "eth0.0") {
						//	$lan_ip = $infInfo['ip'];
						//}
						elseif ($result['api_version'] == "1.0" && $infInfo['name'] == "wlan0" && $interface !== "wlan0") {
							$wlan_ip = $infInfo['ip'];
							$wifi_mac_address = $infInfo['mac'];
						}elseif ($result['api_version'] == "1.0" && $interface == $eth) {
							$lan_ip = $infInfo['ip'];
						}
						//elseif ($result['api_version'] == "1.0" && $interface == "eth0.0") {
						//	$lan_ip = $infInfo['ip'];
						//}
						elseif ($result['api_version'] == "1.0" && $interface == "wlan0") {
							$wlan_ip = $infInfo['ip'];
							$wifi_mac_address = $infInfo['mac'];
						}elseif (version_compare($result['api_version'], "1.0", ">")) {
							if ($infInfo['name'] == $eth) { // error with KK9DS on $name (undefined index)
								if (isset($infInfo['ip'])) {
									if ($infInfo['ip'] == "none") {
										$lan_ip = "NotAvailable";
									}else {
										$lan_ip = $infInfo['ip']; //error with ke6upi here on $ip (undefinded index)
									}
								}elseif (!isset($infInfo['ip'])) {
									$lan_ip = "NotAvailable";
								}
							}
							if ($infInfo['name'] == "wlan0") {
								$wlan_ip = $infInfo['ip'];
								$wifi_mac_address = $infInfo['mac']; //added to fix MAC address issue, caught by Mark, N2MH 14 March 2017.
							}
						}
					}
				}
				
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
					
					echo "LAN ip: ";
					if ($lan_ip == "NotAvailable") {
						wxc_echoWithColor($lan_ip, "orange");
					}else {
						echo $lan_ip;
					}
					echo " WLAN ip: " . $wlan_ip . "\n";
					
					if (($result['lat']) && ($result['lon'])) {
						echo "Location: \033[32m" . $result['lat'] . ", " . $result['lon']. "\033[0m\n";
					//}elseif ($nodeLocationFixed = 1) {
					//	echo "\033[31mNo Location Info Set!\033[0m (FIXED!)\n";
					//	$nodeLocationFixed = 0;
					}else {
						echo "\033[31mNo Location Info Set!\033[0m\n";
					    //K6GSE's solution to deal with non-null values in the DB
						$lat = 0.0;
						$lon = 0.0;
						//end
					}
					
					if ($uptime !== "NotAvailable") {
						echo "Uptime: \033[32m" . $uptime . "\033[0m\n";
					}else {
						echo "Uptime: \033[33m" . $uptime . "\033[0m\n";
					}
					//if (!empty($sysinfoJson) || $sysinfoJson !== NULL) {
					//	echo "\033[32mSaved sysinfo.json\033[0m\n";
					//}else {
					//						
					//}
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
				//should be handled by the SQL server now
				/*
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
				*/
				
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
				tunnel_installed, active_tunnel_count, channel, firmware_mfg, lan_ip, wlan_ip, uptime, loadavg, services, last_seen)
				VALUES('$wifi_mac_address', '$node', '$model', '$firmware_version',
				'$lat', '$lon', '$grid_square', '$ssid', '$chanbw', '$api_version', '$board_id',
				'$tunnel_installed', '$active_tunnel_count', '$channel',
				'$firmware_mfg', '$lan_ip', '$wlan_ip', '$uptime', '$loadavg', '$services', NOW())
				ON DUPLICATE KEY UPDATE wifi_mac_address = '$wifi_mac_address', node = '$node', model = '$model', firmware_version = '$firmware_version',
				lat = '$lat', lon = '$lon', grid_square = '$grid_square', ssid = '$ssid', chanbw = '$chanbw', api_version = '$api_version',
				board_id = '$board_id', tunnel_installed = '$tunnel_installed',
				active_tunnel_count = '$active_tunnel_count', channel = '$channel',
				firmware_mfg = '$firmware_mfg', lan_ip = '$lan_ip', wlan_ip = '$wlan_ip',
				uptime = '$uptime', loadavg = '$loadavg', services = '$services', last_seen = NOW()";
				
				$sql_no_location_info  =    "INSERT INTO $sql_db_tbl(
				wifi_mac_address, node, model, firmware_version, grid_square, ssid, chanbw, api_version, board_id,
				tunnel_installed, active_tunnel_count, channel, firmware_mfg, lan_ip, wlan_ip, uptime, loadavg, services, last_seen)
				VALUES('$wifi_mac_address', '$node', '$model', '$firmware_version',
				'$grid_square', '$ssid', '$chanbw', '$api_version', '$board_id',
				'$tunnel_installed', '$active_tunnel_count', '$channel',
				'$firmware_mfg', '$lan_ip', '$wlan_ip', '$uptime', '$loadavg', '$services', NOW())
				ON DUPLICATE KEY UPDATE wifi_mac_address = '$wifi_mac_address', node = '$node', model = '$model', firmware_version = '$firmware_version',
				grid_square = '$grid_square', ssid = '$ssid', chanbw = '$chanbw', api_version = '$api_version',
				board_id = '$board_id', tunnel_installed = '$tunnel_installed',
				active_tunnel_count = '$active_tunnel_count', channel = '$channel',
				firmware_mfg = '$firmware_mfg', lan_ip = '$lan_ip', wlan_ip = '$wlan_ip',
				uptime = '$uptime', loadavg = '$loadavg', services = '$services', last_seen = NOW()";
				
				$sql_update_when_node_name_has_changed	=	"UPDATE $sql_db_tbl SET
            	node = '$node', model = '$model',
            	firmware_version = '$firmware_version',
            	lat = '$lat', lon = '$lon', grid_square = '$grid_square', ssid = '$ssid', chanbw = '$chanbw',
            	api_version = '$api_version', board_id = '$board_id',
            	tunnel_installed = '$tunnel_installed',
            	active_tunnel_count = '$active_tunnel_count',
            	channel = '$channel', firmware_mfg = '$firmware_mfg',
            	lan_ip = '$lan_ip', wlan_ip = '$wlan_ip',
				uptime = '$uptime', loadavg = '$loadavg', services = '$services', last_seen=NOW()
            	WHERE wifi_mac_address = '$wifi_mac_address'";
				
				$sql_update_when_node_name_has_changed_no_location_info	=	"UPDATE $sql_db_tbl SET
            	node = '$node', model = '$model',
            	firmware_version = '$firmware_version',
            	grid_square = '$grid_square', ssid = '$ssid', chanbw = '$chanbw',
            	api_version = '$api_version', board_id = '$board_id',
            	tunnel_installed = '$tunnel_installed',
            	active_tunnel_count = '$active_tunnel_count',
            	channel = '$channel', firmware_mfg = '$firmware_mfg',
            	lan_ip = '$lan_ip', wlan_ip = '$wlan_ip',
				uptime = '$uptime', loadavg = '$loadavg', services = '$services', last_seen=NOW()
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
					$uptime = NULL;
					$loadavg = NULL;
					$services = NULL;
					$sysinfoJson = NULL;
					//$olsrdInfo = NULL;
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
