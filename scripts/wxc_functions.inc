<?php
/********************************************************
 * KG6WXC's Custom Functions for the MESH mapping system
 * With many many thanks to N2MH and K6GSE for helping
 * find bugs and make many good improvements over what I had before!
 *******************************************************/

/**********************
*The "netcat" function*
**********************/
//this will connect to a host(node) via open port(s) from OLSR.
//this can give us tons of info about olsr and the node it's running on.
//send "" as $command to $port 9090 for the full olsr output
//(this is the default behaviour)
//it can also be used to get the olsrd_mod_dot_draw info from the node (also in a special way if asked)
function wxc_netcat ($host, $port = null, $command = null, $option = null) {
	if (is_null($port)) {
		$port = "9090";
	}

	$netcatStream = @stream_socket_client("tcp://" . $host . ":" . $port, $errno, $errstr); //opens a socket to netcat port on the node
	if (!$netcatStream) {
		//echo "Could not connect to " . $host . " on port " . $port . " for OLSR data\nError Number: " . $errno . "\nError String: " . $errstr . "\n";
		$GLOBALS['errorMessageFrom_wxc_netcat_function'] =  "\033[31mCould not connect to " . $GLOBALS['node'] . " on port " . $port . " for OLSRd data!\033[0m\n" . $errstr;
		return 0;
	}else {
		if (is_null($command)) {
			stream_socket_shutdown($netcatStream, STREAM_SHUT_WR); //this is a very important line, do not mess with it. It closes the netcat stream.
		}else {
			fwrite($netcatStream, $command);
			stream_socket_shutdown($netcatStream, STREAM_SHUT_WR); //this is a very important line, do not mess with it. It closes the netcat stream.
		}
		if ($port == "2004" && ($option == "linkInfo" || $option == "ipOnly")) {
			$olsrDotDrawInfo = stream_get_contents($netcatStream);
			
			//apparently the olsr_dot_draw mod will sometimes give us the same information over and over.
			//this was causing there to be way way too many links in the topology table
			//sometimes 3x or more links would be drawn for the same link.
			//this is here to stop this behavior and just get the one set of info
			//it doesn't seem to effect the other olsrd files since the string is only for the .dot file
			$olsrDotDrawInfo = preg_replace("/\r?\n\r?\ndigraph.*/s", "", $olsrDotDrawInfo);
			
			$remove_from_olsr_info = "HNA|box|diamond|solid|shape|style|graph|{|}"; //list of lines we definitely do not want (not for this project at least, not now, ya never know tho)
			$replace_match = '/^.*(?:' . $remove_from_olsr_info . ').*$(?:\r\r|\n)?/m'; //sets up for the preg_replace function to remove the proper lines
			$olsrDotDrawInfo = preg_replace($replace_match, '', $olsrDotDrawInfo); //actually removes the lines
			//clean things up a bit more.
			//before we were removing entire lines
			//now we're replacing things with either nothing ("") or a space (" ").
			if ($option == "linkInfo") {
				$olsrDotDrawInfo = preg_replace("/\"\[label=\"/", " ", $olsrDotDrawInfo);
				$olsrDotDrawInfo = preg_replace("/ ->/", "", $olsrDotDrawInfo);
			}
			$olsrDotDrawInfo = preg_replace("/\"/", "", $olsrDotDrawInfo);
			
			if ($option == "ipOnly") {
				$olsrDotDrawInfo = preg_replace("/ ->.*/", "", $olsrDotDrawInfo);
				$olsrDotDrawInfo = preg_replace("/ .*/", "", $olsrDotDrawInfo);				//the info gets massaged in the wxc_ function, it returns a list of nodes
				$olsrDotDrawInfo = explode("\n", $olsrDotDrawInfo);							//and the links for those nodes, the problem is there are multiples
				$olsrDotDrawInfo = array_unique($olsrDotDrawInfo);							//we only need one IP for each node on the network
				$olsrDotDrawInfo = implode("\n", $olsrDotDrawInfo);							//that is what this part does, we're now left with a list of IP's, one per line.
				
				return $olsrDotDrawInfo;		//return only the list of IP's
			}else {
				$olsrDotDrawInfo = preg_replace("/\]\;/", "", $olsrDotDrawInfo);
				$olsrDotDrawInfo = preg_replace("/\[/", "", $olsrDotDrawInfo);
				
				//and one that just about got forgotten about, easier to do some math this way. :)
				$olsrDotDrawInfo= preg_replace("/INFINITE/", "99.99", $olsrDotDrawInfo);
				
				//apparently the olsr_dot_draw mod will sometimes give us the same information over and over.
				//this was causing there to be way way too many links in the topology table
				//sometimes 3x or more links would be drawn for the same link.
				//this is here to stop this behavior and just get the one set of info
				//$olsrDotDrawInfo = preg_replace("/^\n.*/m", "", $olsrDotDrawInfo);
				
				//trim off any empty lines at the ends (it shouldn't have any, but just in case)
				$olsrDotDrawInfo = trim($olsrDotDrawInfo);
				///////echo $olsrDotDrawInfo;
				return $olsrDotDrawInfo;
			}
		}
		//just send back the stream, we can json_decode() it later if needed.
		return stream_get_contents($netcatStream);
	}
}
function wxc_checkConfigs () {
//	if ($GLOBALS['do_sql']) {
		//is the needed php extension loaded?
		if (!extension_loaded('mysqli')) {
			echo "\033[31mmysqli extension not loaded!!\033[0m\n";
			echo "Please check your php.ini file for the line\n";
			echo "extension=mysqli.so, it may just be commented out.\n";
			echo "Restart your http server for good measure if you have to add that extension.\n";
			echo "(try \"service apache2 restart\" or \"/etc/init.d/apache2 restart\")\n";
		}
//		$db_check = "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '" . $GLOBALS['USER_SETTINGS']['sql_db'] . "'";
//		if (!wxc_getMySql($db_check)) {
//			wxc_echoWithColor("There is no Database named: " . $GLOBALS['USER_SETTINGS']['sql_db'], "red");
//			echo "\n";
//		}
//	}
}
function wxc_checkErrorMessage ($error, $nodeName) {
	//deal with different problems and try to keep track of why and when.
	//An excellent suggestion again by Mark, N2MH,
	//was to try and have this scipt automatically add 404 (file not found) errors to an "ignore" table in the DB.
	//and to also deal with "failed to open stream errors"
	//You had to figure this out by hand before...
	//I'm trying to add new errors and warnings as I see them.
	if (strpos($error['message'], 'No route to host') !== false) {
		//on our network this is not always a permanent thing.
		//We can keep track of these and try them again later.
		
		//save the IP
		$ip = $nodeName;
		//reset $nodeName to nothing
		$nodeName = null;
		//lets take a second to try and resolve the IP into a name
		$nodeName = wxc_resolveIP($ip);
		if (filter_var($nodeName, FILTER_VALIDATE_IP) != false) {
			if ($GLOBALS['testNodePolling']) {
				echo "\033[31mError while getting sysinfo.json\033[0m from \033[35m" . $ip . "\033[0m: no route to host!\n(also could not resolve ip address.)\n\n";
			}
			return;
		}else {
			$GLOBALS['resolvedHostname'] = $nodeName;
		}
		if ($GLOBALS['do_sql']) {
			wxc_putMySQL("INSERT INTO hosts_ignore SET ip = '$ip', name = '$nodeName', reason = 'no_route', timestamp = NOW() ON DUPLICATE KEY UPDATE ip = '$ip', name = '$nodeName', reason = 'refused', timestamp = NOW()");
			if (!wxc_getMySql("SELECT node FROM node_info WHERE wlan_ip = '$ip'")) {
				wxc_putMySql("INSERT INTO node_info SET node = '$nodeName', wlan_ip = '$ip' ON DUPLICATE KEY UPDATE wlan_ip = '$ip'");
			}
		}
		if ($GLOBALS['testNodePolling'] && $nodeName) {
			echo "\033[31mError while getting sysinfo.json\033[0m from \033[35m" . $nodeName . "\033[0m: no route to host!\n\n";
		}
		
	}
	if (strpos($error['message'], 'Temporary failure in name resolution') !== false) {
		//the DNS server told us this host did not currently exist.
		//it might again later, so we'll keep track of these for now.
		//no, actually we wont... just try again next run.
		//still, the error is reported when we are in test mode(s)
		if ($GLOBALS['testNodePolling']) {
			echo "\033[31mError while getting sysinfo.json\033[0m from \033[35m" . $ip . "\033[0m: Temporary name resolution failure!\n(also could not resolve ip address.)\n\n";
		}
		return;
/*		//save the IP
		$ip = $nodeName;
		//reset $nodeName to nothing
		$nodeName = null;
		
		$nodeName = wxc_resolveIP($nodeName);
		if (filter_var($nodeName, FILTER_VALIDATE_IP) != false) {
			return;
		}else {
			$GLOBALS['resolvedHostname'] = $nodeName;
		}
		if ($GLOBALS['do_sql']) {
			wxc_putMySQL("INSERT INTO hosts_ignore SET ip = '$ip', name = '$nodeName', reason = 'dns_fail', timestamp = NOW() ON DUPLICATE KEY UPDATE ip = '$ip', name = '$nodeName', reason = 'dns_fail', timestamp = NOW()");
			if (!wxc_getMySql("SELECT node FROM node_info WHERE wlan_ip = '$ip'")) {
				wxc_putMySql("INSERT INTO node_info SET node = '$nodeName', wlan_ip = '$ip' ON DUPLICATE KEY UPDATE wlan_ip = '$ip'");
			}
		}
		if ($GLOBALS['testNodePolling']) {
			echo "\033[31mError while getting sysinfo.json\033[0m from \033[35m" . $nodeName . "\033[0m: Temporary name resolution failure!\n\n";
		}
*/
	}
	if (strpos($error['message'], 'Connection refused') !== false) {
		//if we get a "connection refused" it's pretty safe to say we're not dealing with a node device
		//or the sucker has really, really old firmware (3.15.*).
		//but then I've actually seen "connection refused" from live nodes that I know are ok.
		//I think it can also happen when OLSRd restarts or is rehashing it's files or something.
		//these hosts get ignored for a while, but not too long.
		
		//save the IP
		$ip = $nodeName;
		//reset $nodeName to nothing
		$nodeName = null;
		
		$nodeName = wxc_resolveIP($ip);
		if (filter_var($nodeName, FILTER_VALIDATE_IP) != false) {
			if ($GLOBALS['testNodePolling']) {
				echo "\033[31mError while getting sysinfo.json\033[0m from \033[35m" . $ip . "\033[0m: Connection Refused!\n(also could not resolve ip address.)\n\n";
			}
			return;
		}else {
			$GLOBALS['resolvedHostname'] = $nodeName;
		}
		if ($GLOBALS['do_sql']) {
			wxc_putMySQL("INSERT INTO hosts_ignore SET ip = '$ip', name = '$nodeName', reason = 'refused', timestamp = NOW() ON DUPLICATE KEY UPDATE ip = '$ip', name = '$nodeName', reason = 'refused', timestamp = NOW()");
			if (!wxc_getMySql("SELECT node FROM node_info WHERE wlan_ip = '$ip'")) {
				wxc_putMySql("INSERT INTO node_info SET node = '$nodeName', wlan_ip = '$ip' ON DUPLICATE KEY UPDATE wlan_ip = '$ip'");
			}
		}
		if ($GLOBALS['testNodePolling'] && $nodeName) {
			echo "\033[31mError while getting sysinfo.json\033[0m from \033[35m" . $nodeName . "\033[0m: Connection Refused!\n\n";
		}
	}
	if (strpos($error['message'], '404') !== false) {
		//404 errors are probably nodes at firmware 3.15 or less or something else entirely
		//these are only going to be checked once a day.
		//if they *are* nodes hopefully someone can convince the owner to upgrade. :)
		
		//save the IP
		$ip = $nodeName;
		//reset $nodeName to nothing
		$nodeName = null;
		
		$nodeName = wxc_resolveIP($ip);
		if (filter_var($nodeName, FILTER_VALIDATE_IP) != false) {
			if ($GLOBALS['testNodePolling']) {
				echo "\033[31mError while getting sysinfo.json\033[0m from \033[35m" . $ip . "\033[0m: 404 - Not Found!\n(also could not resolve ip address.)\n\n";
			}
			return;
		}else {
			$GLOBALS['resolvedHostname'] = $nodeName;
		}
		if ($GLOBALS['do_sql']) {
			wxc_putMySQL("INSERT INTO hosts_ignore SET ip = '$ip', name = '$nodeName', reason = '404', timestamp = NOW() ON DUPLICATE KEY UPDATE ip = '$ip', name = '$nodeName', reason = '404', timestamp = NOW()");
			if (!wxc_getMySql("SELECT node FROM node_info WHERE wlan_ip = '$ip'")) {
				wxc_putMySql("INSERT INTO node_info SET node = '$nodeName', wlan_ip = '$ip' ON DUPLICATE KEY UPDATE wlan_ip = '$ip'");
			}
		}
		if ($GLOBALS['testNodePolling'] && $nodeName) {
			echo "\033[31mError while getting sysinfo.json\033[0m from \033[35m" . $nodeName . "\033[0m: 404 - Not Found!\n\n";
		}
	}
}

/**********************
*Resolve an IP for us*
**********************/
function wxc_resolveIP ($ipAddr) {
	require_once $GLOBALS['INCLUDE_DIR'] . "/scripts/gethostbyaddr.inc";	//another way to resolve ip's into names. this acts as a backup to php's own gethostbyaddr().
										// (REQUIRED!, change path if you moved it!)
	$hostName = null;
	if (filter_var($ipAddr, FILTER_VALIDATE_IP) != false) {							//check if the IP is valid
		$hostName = preg_replace("/.local.mesh/", "", gethostbyaddr("$ipAddr"));	//resolve the IP into a name
																					//(without ".local.mesh")
		
		if (filter_var($hostName, FILTER_VALIDATE_IP) != false) {	//check again if we actually resolved the name from the ip.
			///////echo $hostName . " did not resolve, trying again...\n";
			
			//ask localnode directly to resolve this IP for us
			$hostName = preg_replace("/.local.mesh/", "", gethostbyaddr_timeout("$hostName", $GLOBALS['USER_SETTINGS']['localnode'], 1));
		}
		if (filter_var($hostName, FILTER_VALIDATE_IP) != false) {		//check again
			echo "Unable to resolve: ". $ipAddr . "\n";					//give up and echo error msg and return the IP
		}
	}
	return $hostName;
}


/*******************
 *Node Polling and *
 *getLinkInfo maybe*
 ******************/
function wxc_pollNodes() {
	//nothing yet
}

function wxc_getLinkInfo() {
	//nothing yet to see here, move along.
}


/*********************
* Date and Time Stuff*
*********************/
//get current date and time as a DateTime Object
function wxc_getCurrentDateTime() {
	$currentDateTime = new DateTime("now", new DateTimeZone($GLOBALS['USER_SETTINGS']['localTimeZone']));
	//return date_format($currentDateTime, 'Y-m-d H:i:s');
	return $currentDateTime;
}

//get"last_run_time" for the scripts out of the DB.
//return it as a DateTime Object so it is easier to compare.
function wxc_scriptGetLastDateTime ($id, $name) {
	//ask the sql server when this script last ran.
	$lastRunSelf = wxc_getMySql("SELECT script_last_run FROM map_info WHERE id = '$id' AND table_or_script_name = '$name'");
	if (is_null($lastRunSelf)) {
//		wxc_putMySql("INSERT INTO map_info (id, table_or_script_name, script_last_run) VALUES ('$id', '$name', NOW()) ON DUPLICATE KEY UPDATE table_or_script_name = '$name', script_last_run = NOW()");
//		$lastRunSelf = wxc_getMySql("SELECT script_last_run FROM map_info WHERE id = '$id' AND table_or_script_name = '$name'");
		return 0;
	}
	//mysqli_close();
	return date_create($lastRunSelf['script_last_run']);
}

//put a new "last_run_time" for the script into the DB
function wxc_scriptUpdateDateTime ($id, $name) {
	wxc_putMySql("INSERT INTO map_info (id, table_or_script_name, script_last_run) VALUES ('$id', '$name', NOW()) ON DUPLICATE KEY UPDATE table_or_script_name = '$name', script_last_run = NOW()");
	return 1;
}


/***********
* SQL Stuff*
***********/
//main sql connection
function wxc_connectToMySQL() {
	$GLOBALS['sql_connection'] = mysqli_connect($GLOBALS['USER_SETTINGS']['sql_server'],$GLOBALS['USER_SETTINGS']['sql_user'],$GLOBALS['USER_SETTINGS']['sql_passwd'],$GLOBALS['USER_SETTINGS']['sql_db']) or die('Could not connect to mySQL database: ' . mysqli_error($GLOBALS['connection']) . "\n");
	return $GLOBALS['sql_connection'];
}


//get things from the MySQL DB.
function wxc_getMySql ($query) {
	
	
	if (is_null($query)) {
		echo "No query sent to \"fetchMySql\" function\n";
		return 0;
	}else {
		$theQuery = mysqli_query($GLOBALS['sql_connection'], $query) or die ("Could not fetch from SQL server. " . mysqli_error($GLOBALS['sql_connection']));
		//just return the "fetch_array" it's usually what we want anyways.
		return mysqli_fetch_array($theQuery, MYSQLI_ASSOC);
		
	}
}

//put things into the MySQL DB.
function wxc_putMySql ($query) {
//	$connection = mysqli_connect($sql_server,$sql_user,$sql_passwd,$sql_db) or die('Could not connect to mySQL database: ' . mysqli_error() . "\n");
	if (is_null($query)) {
		echo "No query sent to \"wxc_putMySql\" function\n";
		return 0;
	}else {
		if(!mysqli_query($GLOBALS['sql_connection'], $query)) {
			die ("Could not send to SQL. " . mysqli_error($GLOBALS['sql_connection']));
			return 0;
		}
		return 1;
	}
}




//check band and firmware?
//this is totally not done yet
/**
* checkBand
* Check the channel against the known band assignments
*
* @param $channel
*
* @return string Indicator of the assigned band
*/
function wxc_checkBand ($channel) {
		//band channel arrays
		//900Mhz
		//On 900MHz you dont get "channels", the node lets you choose the center frequency.
		//there are 4 to choose from: "(907)", "(912)", "(917)", and "(922)".
		//unfortunatly, it doesn't come across correctly in the json file
		//all I know is that "(917)" == "4" (for now) (which makes no sense at all, it should be 3 (or 2 actually))
		$nineHundredMhz					=	array('907', '912', '917', '922', '4');
		//2.4GHz only -1 and -2
		// Note: Channel 4 removed from the 2GHz range, to avoid confusion with 900MHz
		$twoGhz							=	array('-1', '-2', '1', '2', '3', '5', '6', '7', '8', '9', '10', '11');
		//3GHz 76-99
		$threeGhz						=	array('76','77','78','79','80','81','82','83','84','85','86','87','88','89','90','91','92','93','94','95','96','97','98','99','3380','3385','3390','3395','3400','3405','3410','3415','3420','3425','3430','3435','3440','3445','3450','3455','3460','3465','3470','3475','3480','3485','3490','3495');
		//5GHz 177-184
		$fiveGhz						=	array('133','137','149','157','160','165','168','170','171','172','174','175','176','177','178','179','180','181','182','183','184');
		
		
		//K6GSE nice switch statement!
		switch (true) {
			case (in_array($channel, $twoGhz)):
				return '2GHz';
				break;
				
			case (in_array($channel, $threeGhz)):
				return '3GHz';
				break;
				
			case (in_array($channel, $fiveGhz)):
				return '5GHz';
				break;
				
			case (in_array($channel, $nineHundredMhz)):
				return '900MHz';
				break;
				
			default:
				return 'Unknown';
		}

}

//this is a pipe dream, but I could use it myself if I wanted to
//lets see if AREDN does something special just for me (I doubt it)
function wxc_hostFileHTTP ($isItAvailable) {
	if ($testHostInfo = file_get_contents("http://localnode.local.mesh:8080/known_hosts.txt")) {
		return $testHostInfo;
	}else {
		return 0;
	}
}

//something I was playing with
function wxc_echoWithColor ($text, $color) {
	if ($color == "red") {
		echo "\33[31m" . $text . "\033[0m";
	}
	if ($color == "purple") {
		echo "\033[35m" . $text . "\033[0m";
	}
}

//may use this at some point
function wxc_firstRun() {
	//nothing yet, still thinking about this....
}

//check for nodes that have "expired" (interval should be set in the ini file)
function wxc_checkOldNodes() {
	//check for node that have not been heard from
	$oldNodeQuery = "SELECT * FROM node_info WHERE TIMESTAMPDIFF(DAY, node_info.last_seen, NOW()) > " . $GLOBALS['USER_SETTINGS']['node_expire_interval'];
	$oldNodes = mysqli_query($GLOBALS['sql_connection'], $oldNodeQuery) or die ("Could not fetch from SQL server. " . mysqli_error($GLOBALS['sql_connection']));
	if ($oldNodes) {
		$oldNodes = mysqli_fetch_all($oldNodes, MYSQLI_ASSOC);
	}else {
		return;
	}
	
	if (is_array($oldNodes)) {
		$oldNodeQuery = 
			"INSERT INTO removed_nodes (
			wifi_mac_address, node, model, firmware_version, lat, lon, ssid, chanbw, api_version, board_id,
			tunnel_installed, active_tunnel_count, channel, firmware_mfg, lan_ip, wlan_ip, sysinfo_json, olsrinfo_json, last_seen, time_removed)
			VALUES ";
		$valuesArray = array();
		foreach ($oldNodes as $row) {
			//echo "";
			$wifi_mac_address = $row['wifi_mac_address'];
			$node = $row['node'];
			$model = $row['model'];
			$firmware_version = $row['firmware_version'];
			$lat = $row['lat'];
			$lon = $row['lon'];
			$ssid = $row['ssid'];
			$chanbw = $row['chanbw'];
			$api_version = $row['api_version'];
			$board_id = $row['board_id'];
			$tunnel_installed = $row['tunnel_installed'];
			$active_tunnel_count = $row['active_tunnel_count'];
			$channel = $row['channel'];
			$firmware_mfg = $row['firmware_mfg'];
			$lan_ip = $row['lan_ip'];
			$wlan_ip = $row['wlan_ip'];
			$sysinfo_json = $row['sysinfo_json'];
			$olsrinfo_json = $row['olsrinfo_json'];
			$last_seen = $row['last_seen'];
			
			$valuesArray[] = "('$wifi_mac_address', '$node', '$model', '$firmware_version', '$lat', '$lon', '$ssid', '$chanbw', '$api_version', '$board_id',
				'$tunnel_installed', '$active_tunnel_count', '$channel', '$firmware_mfg', '$lan_ip', '$wlan_ip', '$sysinfo_json', '$olsrinfo_json', '$last_seen', NOW())";
		}
		
		if ($valuesArray) {
			$oldNodeQuery .= implode(',', $valuesArray);
			wxc_putMySql($oldNodeQuery);
			$oldNodeQuery = "DELETE FROM node_info WHERE TIMESTAMPDIFF(DAY, node_info.last_seen, NOW()) > " . $GLOBALS['USER_SETTINGS']['node_expire_interval'];
			wxc_putMySql($oldNodeQuery);
		}
	}
	//wxc_getMySql("DELETE FROM node_info WHERE TIMESTAMPDIFF(DAY, node_info.last_seen, NOW()) > 30");
	//return mysqli_fetch_all($theQuery);
	
	//check for entries to be removed from the "ignore_hosts" table
	//-- prune the hosts_ignore table
	//-- clear 404 errors after 26 hours, hopefully the user will update one day
	//-- also remove no_route/refused errors after 90 min
	$oldNodeQuery = "DELETE FROM hosts_ignore WHERE HOUR(TIMEDIFF(NOW(), hosts_ignore.timestamp)) > " . $GLOBALS['USER_SETTINGS']['error404_expire_interval'] . " AND hosts_ignore.reason = '404'";
	wxc_putMySql($oldNodeQuery);
	$oldNodeQuery = "DELETE FROM hosts_ignore WHERE TIMESTAMPDIFF(MINUTE, hosts_ignore.timestamp, NOW()) > " . $GLOBALS['USER_SETTINGS']['errorNoRoute_expire_interval'] . " AND hosts_ignore.reason = 'no_route'";
	wxc_putMySql($oldNodeQuery);
	$oldNodeQuery = "DELETE FROM hosts_ignore WHERE TIMESTAMPDIFF(MINUTE, hosts_ignore.timestamp, NOW()) > " . $GLOBALS['USER_SETTINGS']['errorRefused_expire_interval'] . " AND hosts_ignore.reason = 'refused'";
	wxc_putMySql($oldNodeQuery);
	//else {
		return;
	//}
}

//find any services the node knows about
function wxc_findServices($json_data, $type) {
	if ($type == "services") {
		$services = array();
		$olsrInfo = strstr($json_data, '"service"');
		if ($olsrInfo == false) {
			return FALSE;
		}else {
			$olsrInfo = preg_match_all('/service.*/', $olsrInfo, $out);
			foreach ($out as $key => $value) {
				foreach ($value as $line) {
					$line = preg_replace('/service\"\: \"/', "", $line);
					$line = preg_replace('/\",/', "", $line);
					$boom = explode("|", $line );
					if (strpos($boom['0'], ":0") !== FALSE) {
						$name = $boom['2'];
						$link = NULL;
					}else {
						$url = parse_url($boom[0]);
						$link = $url['scheme'] . "://" . $url['host'] . ".local.mesh:" . $url['port'] . $url['path'];
						//$host = $url['host'];
						//$port = $url['port'];
						//$link = $boom[0];
						$name = $boom[2];
					}
					$services[$name] = $link;
				}
			}
			return $services;
		}
	}
	//other "types" go here if needed
}

//distance and bearing function? (no longer needed)
function wxc_getDistanceAndBearing (float $node_lat, float $node_lon, float $linked_node_lat, float $linked_node_lon) {
	//kept in case we need it again
	//this is now done in the mysql server
	//the bearing and distance fields contain a math expression that does the same thing.
	//the distance expression is:
	// round(2*asin(sqrt(pow(sin((radians(linklat)-radians(nodelat))/2),2)+cos(radians(nodelat))*cos(radians(linklat))*pow(sin((radians(linklon)-radians(nodelon))/2),2)))*3959,2)
	//the bearing expression is:
	// round(mod(degrees(atan2(sin(radians(linklon)-radians(nodelon))*cos(radians(linklat)), cos(radians(nodelat))*sin(radians(linklat))-sin(radians(nodelat))*cos(radians(linklat))*cos(radians(linklon)-radians(nodelon)))) + 360,360),1)
	//or I guess you could use this function, but I'm not even sure it works
	
	//That can be done in later MySQL version (and in MariaDB 10) but now all the distance and bearing
	//is calculated via a trigger on the topology table in the database.
	//it was changed this way to have it be more compatible with other versions of MySQL
	
	//Find the distance and bearing between the 2 nodes
	$earthRadiusMiles	= 3959;
	$distanceLatFrom 	= deg2rad($node_lat);
	$distanceLonFrom 	= deg2rad($node_lon);
	$distanceLatTo		= deg2rad($linked_node_lat);
	$distanceLonTo		= deg2rad($linked_node_lon);
	
	$latDelta			= $distanceLatTo - $distanceLatFrom;
	$lonDelta			= $distanceLonTo - $distanceLonFrom;
	
	$angleBetweenTheTwoPoints = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) + cos($distanceLatFrom) * cos($distanceLatTo) * pow(sin($lonDelta / 2), 2)));
	
	$distance = $angleBetweenTheTwoPoints * $earthRadiusMiles;
	$distance = round($distance, 2);
	//an sql expression for distance? (to make the SQL server do this!)
	
	$bearing = (rad2deg(atan2(sin(deg2rad($linked_node_lon) - deg2rad($node_lon)) * cos(deg2rad($linked_node_lat)), cos(deg2rad($node_lat)) * sin(deg2rad($linked_node_lat)) - sin(deg2rad($node_lat)) * cos(deg2rad($linked_node_lat)) * cos(deg2rad($linked_node_lon) - deg2rad($node_lon)))) + 360) % 360;
	
	return array("distance" => $distance, "bearing" => $bearing);
}

//functions added for K6GSE, by K6GSE, WXC only cleaned up after the copy paste
/**
* check for Out of Date Firmware
*
* @param $node_firmware
* @param $stable_firmware
*
* @return integer 0 = none needed, 1 = older, 2 = Experimental
*/
function checkVersion($node_firmware, $stable_firmware)
{
	
	if (version_compare($node_firmware, $stable_firmware) < 0)
	{
		return 1;	
	}
	elseif (version_compare($node_firmware, $stable_firmware) > 0)
	{
		return 2;	
	}
	return 0;	
}
/**
* Are you connected to the internet
* No, this is actually: "does the server have access to google.com"?
* It does not help to tell if the client has internet access at all.
* It is a useless function and should be removed!!
* who cares if the server can access google or not? That really does not matter.
* 
* @return bool
*/
function is_connected()
{
	return true;
	//$connected = @fsockopen("www.google.com", 80);
	//$connected = @fsockopen("www.thisshouldtotallynotexistanywhere.zz.yy.com", 80);
	//website, port  (try 80 or 443)
	//if ($connected)
	//{
	//	$is_conn = true; //action when connected
	//	fclose($connected);	
	//}
	//else
	//{
	//	$is_conn = false; //action in connection failure	
	//}
	//return $is_conn;	
}
/**
* Are you connected to the mesh
* @return bool
*/
function is_connected_mesh()
{
	$connected = @fsockopen("localnode.local.mesh", 8080);
	//website, port  (try 80 or 443)
	if ($connected)
	{
		$is_conn = true; //action when connected
		fclose($connected);	
	}
	else
	{
		$is_conn = false; //action in connection failure	
	}
	return $is_conn;	
}
?>
