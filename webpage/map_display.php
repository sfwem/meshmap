<?php
/**
 * @name       MeshMap - a dynamic map for the mesh network
 * @category   Mesh
 * @author     Eric Satterlee, KG6WXC with K6GSE
 * @version    $Id$
 * @license    Open Source
 * @abstract   Eric has written a tool called get-map-info which retrieves HAM Mesh network devices,
 *                     their configuration and Linkage information. These details are populated in several SQL tables.
 *                     The map.php routine extracts the DB details and creates a dynamic map of those nodes and links.
 *
 *             The primary display tools are:
 *             Map Drawing by Leaflet http://leafletjs.com
 *             Map data by OpenStreetMap http://openstreetmap.org
 *                   with contributions from: CC-BY-SA http://creativecommons.org/licenses/by-sa/2.0/
 *             Map tiles by Stamen Design http://stamen.com, under CC BY 3.0
 *             Map style http://viewfinderpanoramas.org
 *             Icons from http://www.flaticon.com under CC BY 3.0
 *             OpenTopoMap https://opentopomap.org
 *
 **************************************************************************/

/* Current version notes
 * 
 * March 2018
 * wow, it's been that long huh?
 * now using git repo at https://mapping.kg6wxc.net/git/meshmap
 * fixed issues with running the webpage on PHP 7.2.3, it should be good now.
 * tested on PHP 5.6.30 7.0.27-0+deb9u1 and 7.2.3
 * had to create an array properly and not let PHP fix my mistakes for me. :)
 * more to come!
 *
 * June 2017
 * -----------------
 * Added CSS values
 * First pass at Optimization for Node and Topology array handling
 * Moved several control values to user-setting.ini
 *                       Including: Server Settings, Messages, Starting Coordinates and Zoom
 * GSE: Moved map specific details to meshmap-settings.inif
 * GSE: Optimized link building
 * GSE: Cloned map.php to map_display.php for additional ( non-mesh ) features
 * GSE: Moved Icon and Colour definitions to meshmap-settings.ini
 * GSE: Moved all map building infrastruures to individual routines.
 * GSE: Added ( Additional Markers ) - These are additional filtered non-mesh markers.
 * GSE: Added Services to the mesh node pop up
 * GSE: Added Popup to Link lines.
 * GSE: Added another popup for those nodes with an non-standard firmware version. ( Visiable when layer is active )
**/

/* Historical Notes ( colapsed )
* early march 2017
* -----------------
* sorted "Linked to:" popup list by distance and cost
* this was easiest by putting the distance info into the database.
* see the scripts for more info
* Can now tell the difference between "real" DTD links and those that are linked in some other way (mostly)
* Additionally, This page is becoming more and more modified to do things based on which "host" it is running on
* At SBARC we currently have 3 different "versions" of this page, plus my system I use for testing
* I was starting to lose track of what was where so it has all become one now.
* if you are running on a different host then things will default to "normal".
*
* early feb 2017
* -----------------
* changed to use only free (as in beer) maps
* OSM, openTopo, Stamen maps, etc.

* early jan 2017
* -----------------
* migrated to use mysqli
* also added the mapbox "topographic" maps (which suck)

* mid dec 2016
* -----------------
* yet another update (due to request)
* added distance and bearing info to the linked node listing in the station popup

* more updates dec 2016
* -----------------
* added lat, lon to the popup info
* added channel and bandwidth info to the station popups
* out of date (and maybe beta) firmware now shows up as red text in the station popups
* changed the bottom "attributions" section a bit, formatted it differently and added in the number of stations and links shown. :)

* v.03 early December 2016
* -----------------
* added fullscreen control.(mid november 2016).
* the basemap layers are now able to be switched.
* there is differentiation between the different bands.
* now more info in the stations popup.
* you can now filter out different bands and different types of links on the map.
* the node's name now shows up if you hover over the marker.
* I think I'm now able to pick out the tunnels vs. any other type of link... maybe
*
* v.02 early Nov 2016
* -----------------
* new "radio" icons
*  "two way" link lines
*  legend overlay
*
*  v.01 inital map Oct 2016
* -----------------
**/
//

$INCLUDE_DIR = "..";
$USER_SETTINGS = parse_ini_file($INCLUDE_DIR . "/scripts/user-settings.ini");
global $MESH_SETTINGS;
$MESH_SETTINGS = parse_ini_file($INCLUDE_DIR . "/scripts/meshmap-settings.ini");

/*******************************************************
 *YOU REALLY SHOULD NOT NEED TO EDIT ANYTHING BELOW HERE*
 *******************************************************/

require $INCLUDE_DIR . "/scripts/wxc_functions.inc";
require $INCLUDE_DIR . "/scripts/map_functions.inc";


date_default_timezone_set($USER_SETTINGS['localTimeZone']);
@include $INCLUDE_DIR . "/custom.inc";
/*
* SQL Connections
*********************/
wxc_connectToMySQL();

/*
* Node Table Query
*/
global $useNodes;
global $useMarkers;
global $useLinks;
$NodeList = load_Nodes();
$MarkerList = load_Markers();
$TopoList = load_Topology();

// Get the last time we updated the link info
$filetime = wxc_scriptGetLastDateTime("LINKINFO", "topology");
if ($filetime)
{
    $filetime = date_format($filetime, 'F d Y H:i:s');
}

global $STABLE_MESH_VERSION;
$STABLE_MESH_VERSION = $USER_SETTINGS['current_stable_fw_version'];

$page_header = <<< EOD
    <!DOCTYPE html PUBLIC
            '-//W3C//DTD XHTML 1.0 Transitional//EN''http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd'>
    <!-- START HTML  -->
    <!-- AREDN mesh network dynamic map -->
    <!-- Created by KG6WXC with help from N2MH and K6GSE -->
    <html xmlns='http://www.w3.org/1999/xhtml'>
    <head>
    <meta http-equiv='Pragma' content='no-cache'>
    <meta http-equiv='Expires' content='-1'>
    <meta http-equiv='Content-Type' content='text/html; charset=utf-8'>
EOD;

echo $page_header;
echo "<title>" . $USER_SETTINGS['pageTitle'] . "</title>\n";

// This section used to try and tell if we are being called from the mesh or not
// If we are being called from the mesh, we use the offline copies of the add-on scripts
// If not, set it up so everything is fetched from the internet

//But now, forget all this "try to determine if the client is "online" or not" BS.
//Do you know how many freaking definitions there for "being online" or not?
//We only want to test for one thing in this case, but noooooo, none of it works...
//Thanks for trying...

//Just serve up the damn files! I mean really, how much data is it?
//did we create a highspeed data mesh network or did we recreate packet?
//stop crying over a few extra bytes going over RF...
echo "<link href='css/meshmap.css' rel='stylesheet'>\n";
echo "<link href='css/leaflet.css' rel='stylesheet'>\n";
echo "<script src='javascripts/leaflet.js'></script>\n";
echo "<script src='javascripts/leaflet.polylineoffset.js'></script>\n";
echo "<script src='javascripts/Leaflet.fullscreen.min.js'></script>\n";
echo "<link href='css/leaflet.fullscreen.css' rel='stylesheet'>\n";
echo "<script src='javascripts/leaflet.groupedlayercontrol.min.js'></script>\n";
echo "<link href='css/leaflet.groupedlayercontrol.min.css' rel='stylesheet'>\n";
echo "<script src='javascripts/leaflet-hash.js'></script>\n";

// WXC change: is_connected() does not seem to work
//CORRECTION: is_connected() does work, but it only tells us if the SERVER has access to google...
//totally not what we want at all...
//we're just going to tell if we were called from a host that has ".local.mesh" in it and forget all this nonsense...
global $mesh;
$httpHostName = $_SERVER['HTTP_HOST'];
if (strpos($httpHostName, '.local.mesh')) { //|| strpos($httpHostName, '')) {
	$mesh = 1;
}

//Here was some javascript I was trying but it did not work right either.
//onload and onerror were *always* null no matter what I tried...
//whatever happened to trying to make things work the same across all platforms/browsers/etc?
//I'm going to leave this just in case someone comes up with a way to make it work.
$clientOnlineTest = <<< EOD
<script>
	function clientInternetAccess() {
		var jsOnline = ["//unpkg.com/leaflet@1.0.1/dist/leaflet.js",
					"//bbecquet.github.io/Leaflet.PolylineOffset/leaflet.polylineoffset.js",
					"//api.mapbox.com/mapbox.js/plugins/leaflet-fullscreen/v1.0.1/Leaflet.fullscreen.min.js",
					"//ismyrnow.github.io/leaflet-groupedlayercontrol/src/leaflet.groupedlayercontrol.js"];
		
		var cssOnline = ["//unpkg.com/leaflet@1.0.1/dist/leaflet.css",
					"//api.mapbox.com/mapbox.js/plugins/leaflet-fullscreen/v1.0.1/leaflet.fullscreen.css",
					"//ismyrnow.github.io/leaflet-groupedlayercontrol/src/leaflet.groupedlayercontrol.css"];
		for (var q = 0, l = jsOnline.length; q < l; q++) {
			document.getElementsByTagName("head")[0].innerHTML += ("<script src=\"" + jsOnline[q] + "\"></scr" + "ipt>");
		}
		for (var q = 0, l = cssOnline.length; q < l; q++) {
			document.getElementsByTagName("head")[0].innerHTML += ("<link href=\"" + cssOnline[q] + "\" rel=\"stylesheet\">");
		}
	}
	function clientNoInternet() {
		var jsLocal	=	["javascripts/leaflet.js", "javascripts/leaflet.polylineoffset.js",
						"javascripts/Leaflet.fullscreen.min.js",
						"javascripts/leaflet.groupedlayercontrol.min.js"];
		
		var cssLocal	=	["css/leaflet.css", "css/leaflet.fullscreen.css",
							"css/leaflet.groupedlayercontrol.min.css"];
		for (var q = 0, l = jsLocal.length; q < l; q++) {
			document.getElementsByTagName("head")[0].innerHTML += ("<script src=\"" + jsLocal[q] + "\"></scr" + "ipt>");
		}
		for (var q = 0, l = cssLocal.length; q < l; q++) {
			document.getElementsByTagName("head")[0].innerHTML += ("<link href=\"" + cssLocal[q] + "\" rel=\"stylesheet\">");
		}
	}
	var i = new Image();
	i.onload = clientInternetAccess();
	i.onerror = clientNoInternet();
	//change url to an image you know is live
	i.src = "http://www.weather.gov/css/images/usa_gov.png";
	//+ escape(Date());
	
	
</script>

EOD;
//end of WXC's javascript "onlinechecker" attempt

echo "\n";
echo "</head>\n";
echo "<body>\n";
//echo $clientOnlineTest;
//echo "\n";
//echo "\n";

// If this page *is* called from an Internet enabled site:
// Remove the top logo and make the map a bit smaller
// so that it fits in the nice little iFrame page
// Otherwise render a normal map page.

//GSE: [Removed]  if ($_SERVER['HTTP_HOST'] == $USER_SETTINGS['meshServerHostName'] || $_SERVER['HTTP_HOST'] ==
//    "kg6wxc-host.local.mesh"
//)
if (isset($USER_SETTINGS['map_iFrame_Enabled']) && ($USER_SETTINGS['map_iFrame_Enabled']))
{
    echo "<div id='meshmap' style='width: 740px; height: 500px;'>\n"; // Closing tag at end of primary routine
    echo "<div id='mapid' style='width: 100%; height: 95%;'>\n";
    echo "</div>\n";
}
else
{
    echo "<div id='meshmap' style='width: 1200px; height: 700px;'>\n"; // Closing tag at end of primary routine
    if (isset($USER_SETTINGS['pageLogo']))
    {
        echo "<MapTitle>";
        echo "<img src='" . $USER_SETTINGS['pageLogo'] .
            "' width='50' style='vertical-align: middle;'>\n";
        ;
        echo "</MapTitle>";
    }
    if (isset($USER_SETTINGS['logoHeaderText']))
    {
        echo "<MapTitle>";
        echo $USER_SETTINGS['logoHeaderText'];
        echo "<br>";
        echo "</MapTitle>";
    }
    if (isset($USER_SETTINGS['welcomeMessage']))
    {
        echo "<Welcome_MSG>";
        echo $USER_SETTINGS['welcomeMessage'];
        //echo "<br>";
        echo "&nbsp;&nbsp;";
        echo "</Welcome_MSG>";
		echo "<Welcome_MSG2>";
		echo $USER_SETTINGS['otherTopOfMapMsg'];
		echo "<br>";
		echo "</Welcome_MSG2>\n";
    }
    if (isset($USER_SETTINGS['meshWarning']) && $mesh)
    {
        echo "<Warning_MSG>";
        echo $USER_SETTINGS['meshWarning'];
        echo "<br>";
        echo "</Warning_MSG>";
    }
}

echo "<div id='mapid' style='width: 100%; height: 95%;'>\n";
echo "</div>\n";

//$numNodes = count($NodeList);	// WXC change: this was giving the wrong number.
//should not count nodes that have no location info, they are not on the map...
//just using this for now.
$numNodes = wxc_getMySql("SELECT COUNT(*) as nodesWithLocations FROM node_info where (lat is not null or 0 or '') and (lon is not null or 0 or '')");
$numNodes = $numNodes['nodesWithLocations'];
$numNodesTotal = count($NodeList);

//WXC comment: looks like this was for something else maybe?...
$numMarkers = count($MarkerList);

//$numLinks = count($TopoList);	// WXC change: probably the same thing going on here too
//just using this for now
$numLinks = wxc_getMySql("SELECT COUNT(*) as linksWithLocations FROM topology WHERE (nodelat is not null or 0 or '' or '0') and (nodelon is not null or 0 or '' or '0') or (linklat is not null or 0 or '' or '0') and (linklon is not null or 0 or '' or '0')");
$numLinks = $numLinks['linksWithLocations'];
$numLinksTotal = count($TopoList);

$Content = "";

//WXC change: commented out the below line, as it was causing another map sized <div> to be created.
//this additional <div> was empty, but then it would cause a second "you are connected to the internet...." message.
//$Content .= "<div id='meshmap' style='width: 100%; height: 685px;'>\n"; // Closing tag at end of primary routine

$filetime = 'Today';

//$Content .= "<div id='mapid' style='width: 100%; height: 95%;'>\n";
//$Content .= "</div>\n";
$Content .= "<script type=\"text/javascript\">";

$Content .= add_MapLayers();
$Content .= add_MapImages($numNodes, $numLinks, $numMarkers);
$Content .= create_MapLayers($numNodes, $numLinks, $numMarkers);
$Content .= create_MapOverlays($numNodes, $numLinks, $numMarkers);
//        echo $Content;
//        $Content = "";
$Content .= build_NodesAndLinks($NodeList, $TopoList, $MarkerList);
$Content .= create_MapLegend();
$Content .= create_MapImage();
$Content .= show_MapMarkerDetails($numNodes, $numLinks, $numMarkers, $numNodesTotal, $numLinksTotal);
$Content .= instantiate_Map();
/*
* Mesh messages and notes
*/

//if ($mesh && $USER_SETTINGS['meshServerText'])
//{
//    echo "<Mesh_MSG>";
//    echo sprintf($USER_SETTINGS['meshServerText'], $USER_SETTINGS['meshServerHostName']);
//    echo "<br>";
//    echo "</Mesh_MSG>";
//}

//who cares?? WXC
//if (is_connected() && $USER_SETTINGS['inetServerText'])
//{
//    echo "<Internet_MSG>";
//    echo sprintf($USER_SETTINGS['inetServerText'], $USER_SETTINGS['inetServerHostname']);
//    echo "<br>";
//    echo "</Internet_MSG>\n";
//}


$Content .= "</script>";
$Content .= "</div>\n"; // Closing tag


// Display Page
// -----------------------------
echo $Content;


//if (is_connected() && $USER_SETTINGS['inetServerText'])
//{
//    echo "<Internet_MSG>";
//    echo sprintf($USER_SETTINGS['inetServerText'], $USER_SETTINGS['inetServerHostname']);
//    echo "<br>";
//    echo "</Internet_MSG>";
//}

echo "</div>\n"; // End division meshmap
echo "</body>\n";
echo "</html>\n";
/*
* End of primary display
********************************************************************************************************************/

?>

