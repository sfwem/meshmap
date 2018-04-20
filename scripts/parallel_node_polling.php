<?php
/******
* parallel_node_polling script v3 by kg6wxc\eric satterlee kg6wxc@gmail.com
* Licensed under GPLv3 or later
*
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

if (PHP_SAPI !== 'cli') {
    $file = basename($_SERVER['PHP_SELF']);
    exit("<style>html{text-align: center;}p{display: inline;}</style>
        <br><strong>This script ($file) should only be run from the
        <p style='color: red;'>command line</p>!</strong>
        <br>exiting...");
}
$INCLUDE_DIR = "..";
$USER_SETTINGS = parse_ini_file($INCLUDE_DIR . "/scripts/user-settings.ini");
require $INCLUDE_DIR . "/scripts/wxc_functions.inc";
@include $INCLUDE_DIR . "/custom.inc";
//foreach (get_included_files() as $filename) {
//	if (strpos($filename, 'wxc_custom.inc')) {
//		$wxc_custom = 1;
//	}
//}

$ipAddr = $argv[1];
$do_sql = $argv[2];
$testNodePolling = $argv[3];
if ($do_sql) {
	$sql_connection =  wxc_connectToMySql();
}
$sql_db_tbl = $USER_SETTINGS['sql_db_tbl'];
$sql_db_tbl_node = $USER_SETTINGS['sql_db_tbl_node'];
$sql_db_tbl_topo = $USER_SETTINGS['sql_db_tbl_topo'];

$sysinfoJson = @file_get_contents("http://$ipAddr:8080/cgi-bin/sysinfo.json"); //get the .json file
$jsonFetchSuccess = 1;
if($sysinfoJson === FALSE) {
	$jsonFetchSuccess = 0;
	$error = error_get_last();
	wxc_checkErrorMessage($error, $ipAddr);
			
	//got nothing...
	//break;
	//just skip the next IP
	//continue;
}else {
	//node is there, get all the info we can
	//get all the data from the json file and decode it
	$result = json_decode($sysinfoJson,true);
	
	//if there's nothing really there just skip to the next IP
	if (!$result) {
		//continue;
	}
	//first let's see what node we are dealing with
	//$node = $GLOBALS['node'] = $result['node'];
	$node = $result['node'];
			
	//gather lots of other information from OLSRD about the node being polled (we hopefully can use this later)
	//this requires a second connection to the node. no way around that.
	//but only if we have a firmware version >= than 3.16.0 (or "develop-16")
	$firmware_version = $result['firmware_version'];
	$olsrdInfo = 0;
	
	//chaged to always on for testing 10-10-2017
	//changed again 10-21-2017 excluding a couple of nodes
	//if (version_compare($result['firmware_version'], "3.16.0.0", ">") || strpos($result['firmware_version'], "evelop-16") || $result['firmware_version'] === "linux" || $result['firmware_version'] === "Linux") {
	//if ($ipAddr !== "10.162.170.94" && $ipAddr !== "10.242.25.124" && $ipAddr !== "10.108.102.244") {
		/************
		* Temporarily REMOVED due to issues
		************/
	//uncommented 11:30pm oct11 2017
	//commented out again 14:20 oct13 2017
	//enabled again 10.20.2017
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
// 	if ($wxc_custom) {
// 		if($do_sql) {
// 			$nodesToFixLocations = fixLocations($node, $lat, $lon);
// 			list ($node, $lat, $lon) = explode(" ", $nodesToFixLocations);
// 			$node = $node;
// 			$lat = $lat;
// 			$lon = $lon;
// 		}
// 	}
			
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

//$sql_connection =  wxc_connectToMySql();
//if ($sql_connection) {
	
//}
?>
