<?php
/**
 * @name       MeshMap - a dynamic map for the mesh network
 * @category   Mesh
 * @author     Eric Satterlee, KG6WXC with Glen, K6GSE and Mark, N2MH
 * @version    $Id$
 * @copyright  Copyright (c) 2018 as Open Source
 * @license    GPLv3 or later
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
 *             Non Mesh Marker Icons from http://www.flaticon.com under CC BY 3.0
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
*/

//Increase PHP memory limit to 128M (you may need more if you are connected to a "Mega Mesh" :) )
ini_set('memory_limit', '128M');

/******
* You should not need to change much below here
******/

$INCLUDE_DIR = "..";

//check for users user-settings.ini file and use it if it exists
//use the default one if it does not
global $USER_SETTINGS;
if (file_exists($INCLUDE_DIR . "/scripts/user-settings.ini")) {
    $USER_SETTINGS = parse_ini_file($INCLUDE_DIR . "/scripts/user-settings.ini");
}else {
    exit("You <strong><em>must</em></strong> copy the user-settings.ini-default file to user-settings.ini and edit it!\n");
    //$USER_SETTINGS = parse_ini_file($INCLUDE_DIR . "/scripts/user-settings.ini-default");
}
global $MESH_SETTINGS;
//check for users user-settings.ini file and use it if it exists
//use the default one if it does not
if (file_exists($INCLUDE_DIR . "/scripts/meshmap-settings.ini")) {
    $MESH_SETTINGS = parse_ini_file($INCLUDE_DIR . "/scripts/meshmap-settings.ini");
}else {
    $MESH_SETTINGS = parse_ini_file($INCLUDE_DIR . "/scripts/meshmap-settings.ini-default");
}



require $INCLUDE_DIR . "/scripts/wxc_functions.inc";
require $INCLUDE_DIR . "/scripts/map_functions.inc";

date_default_timezone_set($USER_SETTINGS['localTimeZone']);

/*
 * This section will try to tell if the client has internet access or not
 * If we are being called from the mesh, without internet access,
 * we use the offline copies of the add-on scripts and try to load maps locally
 * If there is internet access, set it up so everything is fetched from the internet
 */
global $inetAccess;
global $mesh;

if (isset($_COOKIE['meshmapClientInetAccess'])) {
    $inetAccess = $_COOKIE['meshmapClientInetAccess'];
    if ($inetAccess == "1") {
        $mesh = "0";
    }elseif ($inetAccess == "0") {
        $mesh = "1";
    }
}else {
    $inetAccess = "0";
    $mesh = "1";
}

/*
if (isset($_POST['inetAccess'])) {
    $inetAccess = $_POST['inetAccess'];
    if ($inetAccess == "1") {
        $mesh = "0";
    }elseif ($inetAccess == "0") {
        $mesh = "1";
    }
}else {
    $inetAccess = "0";
    $mesh = "1";
}
*/
@include $INCLUDE_DIR . "/custom.inc";

//if (!isset($GLOBALS['internet_only'])) {
function testForInet() {
    $page = <<< EOD
<!DOCTYPE html>
<html>
<head>
<title>meshmap internet check page</title>
<script src='javascripts/ping.min.js'></script>
<script>
var now = new Date();
//expire in 1 day
//var cookieExpireTime = new Date(now.getTime() + 1 * 24 * 3600 * 1000);
//expire in 30 minutes
var cookieExpireTime = new Date(now.getTime() + (30 * 60 * 1000));
function setCookie(name, value) {
        document.cookie = name + "=" + escape(value) + "; expires=" + cookieExpireTime.toGMTString();
}

function postData(path, params, method) {
    method = method || "post";
    var form = document.createElement("form");
    form.setAttribute("method", method);
    form.setAttribute("action", path);
    for (var key in params) {
        if (params.hasOwnProperty(key)) {
            var hiddenField = document.createElement("input");
            hiddenField.setAttribute("type", "hidden");
            hiddenField.setAttribute("name", key);
            hiddenField.setAttribute("value", params[key]);

            form.appendChild(hiddenField);
        }
    }
    document.body.appendChild(form);
    form.submit();
}

var p = new Ping();
var value = "0";
p.ping("//google.com", function(err, data) {
        if (err) {
                value = 0;
        }else {
                value = 1;
        }
        //window.location.replace("map_display.php?inetAccess="+value);
        //postData('map_display.php', {inetAccess: value});
        setCookie("meshmapClientInetAccess", value);
        window.location.replace("map_display.php");
});
</script>
</head>
<body>
Just a quick check for internet access so we can load the appropriate maps, etc...
<br>
Please be patient, this should only take a moment.
</body>
</html>
EOD;
    echo $page;
    //return;
    exit("<br><br>reloading...");
}
//}
if (!isset($GLOBALS['internet_only'])) {
    if (!isset($_COOKIE['meshmapClientInetAccess'])) {
        testForInet();
    }
}

/*
* SQL Connection
*/
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
<!DOCTYPE html>
<!-- AREDN mesh network dynamic map -->
<!-- Created by KG6WXC with help from N2MH and K6GSE -->
<html lang='en' xmlns='http://www.w3.org/1999/xhtml'>
<head>
<meta http-equiv='Pragma' content='no-cache'>
<meta http-equiv='Expires' content='-1'>
<meta http-equiv='Content-Type' content='text/html; charset=utf-8'>
EOD;

echo $page_header . "\n";
echo "<title>" . $USER_SETTINGS['pageTitle'] . "</title>\n";

/*
 * check for the users meshmap.css file and use it if it exists...
 * if not, use the "-default" file (meshmap.css-default)
 */
if (file_exists ("./css/meshmap.css")) {
    echo "<link href='css/meshmap.css' rel='stylesheet'>\n";
}else {
    echo "<link href='css/meshmap.css-default' rel='stylesheet'>\n";
}

/*
 * If the client has internet access load everything from there
 * if not, use the local resources.
 */
if (!$mesh) {
    echo "<link rel='stylesheet' href='//unpkg.com/leaflet@1.3.1/dist/leaflet.css'>\n";
    echo "<script src='//unpkg.com/leaflet@1.3.1/dist/leaflet.js'></script>\n";
    echo "<script src='//bbecquet.github.io/Leaflet.PolylineOffset/leaflet.polylineoffset.js'></script>\n";
    echo "<script src='//api.mapbox.com/mapbox.js/plugins/leaflet-fullscreen/v1.0.1/Leaflet.fullscreen.min.js'></script>\n";
    echo "<link href='https://api.mapbox.com/mapbox.js/plugins/leaflet-fullscreen/v1.0.1/leaflet.fullscreen.css' rel='stylesheet'>\n";
    echo "<script src='//ismyrnow.github.io/leaflet-groupedlayercontrol/src/leaflet.groupedlayercontrol.js'></script>\n";
    echo "<link rel='stylesheet' href='//ismyrnow.github.io/leaflet-groupedlayercontrol/src/leaflet.groupedlayercontrol.css'>\n";
    echo "<link rel='stylesheet' href='https://use.fontawesome.com/releases/v5.0.10/css/all.css' integrity='sha384-+d0P83n9kaQMCwj8F4RJB66tzIwOKmrdb46+porD/OvrJ+37WqIM7UoBtwHO6Nlg' crossorigin='anonymous'>\n";

}else {
    echo "<link href='css/leaflet.css' rel='stylesheet'>\n";
    echo "<script src='javascripts/leaflet.js'></script>\n";
    echo "<script src='javascripts/leaflet.polylineoffset.js'></script>\n";
    echo "<script src='javascripts/Leaflet.fullscreen.min.js'></script>\n";
    echo "<link href='css/leaflet.fullscreen.css' rel='stylesheet'>\n";
    echo "<script src='javascripts/leaflet.groupedlayercontrol.min.js'></script>\n";
    echo "<link href='css/leaflet.groupedlayercontrol.min.css' rel='stylesheet'>\n";
    echo "<link href='javascripts/fontawesome-all.css' rel='stylesheet'>\n";
}
echo "<script src='javascripts/leaflet-hash.js'></script>\n";
echo "<script src='javascripts/L.Control.SlideMenu.js'></script>\n";
echo "<link href='css/L.Control.SlideMenu.css' rel='stylesheet'>\n";
/*
echo "<link href='css/meshmap.css' rel='stylesheet'>\n";
echo "<link href='css/leaflet.css' rel='stylesheet'>\n";
echo "<script src='javascripts/leaflet.js'></script>\n";
echo "<script src='javascripts/leaflet.polylineoffset.js'></script>\n";
echo "<script src='javascripts/Leaflet.fullscreen.min.js'></script>\n";
echo "<link href='css/leaflet.fullscreen.css' rel='stylesheet'>\n";
echo "<script src='javascripts/leaflet.groupedlayercontrol.min.js'></script>\n";
echo "<link href='css/leaflet.groupedlayercontrol.min.css' rel='stylesheet'>\n";
echo "<script src='javascripts/leaflet-hash.js'></script>\n";
*/

echo "\n";
echo "</head>\n";
echo "<body>\n";

// If this page *is* called from an Internet enabled site:
// Remove the top logo and make the map a bit smaller
// so that it fits in the nice little iFrame page
// Otherwise render a normal map page.

//GSE: [Removed]  if ($_SERVER['HTTP_HOST'] == $USER_SETTINGS['meshServerHostName'] || $_SERVER['HTTP_HOST'] ==
//    "kg6wxc-host.local.mesh"
//)
if (isset($USER_SETTINGS['map_iFrame_Enabled']) && ($USER_SETTINGS['map_iFrame_Enabled']))
{
    echo "<div id='meshmap'>\n"; // Closing tag at end of primary routine
    echo "<div id='mapid'>\n";
    echo "</div>\n";
}
else
{
    echo "<div id='meshmap' style='width: 1200px; height: 700px;'>\n"; // Closing tag at end of primary routine
    if (isset($USER_SETTINGS['pageLogo']))
    {
        echo "<MapTitle>";
//        echo "<img id='logo' src='" . $USER_SETTINGS['pageLogo'] .
//        "' alt='Map or Club Logo' width='50' style='vertical-align: middle;'>";
        echo "<img id='pageLogo' src='" . $USER_SETTINGS['pageLogo'] .
        "' alt='The Logo'>";
        echo "</MapTitle>\n";
    }
    if (isset($USER_SETTINGS['logoHeaderText']))
    {
        echo "<MapTitle>";
        echo $USER_SETTINGS['logoHeaderText'];
        echo "</MapTitle>\n";
        echo "<br>";
    }
    if (isset($USER_SETTINGS['welcomeMessage']))
    {
        echo "<Welcome_MSG>";
        echo $USER_SETTINGS['welcomeMessage'];
        //echo "<br>";
        echo "&nbsp;&nbsp;";
        echo "</Welcome_MSG>\n";
    }
    if (isset($USER_SETTINGS['otherTopOfMapMsg'])) {
		echo "<Welcome_MSG2>";
		echo $USER_SETTINGS['otherTopOfMapMsg'];
		echo "</Welcome_MSG2>\n";
		echo "<br>";
    }
    if (isset($USER_SETTINGS['meshWarning']) && $mesh)
    {
        echo "<Warning_MSG>";
        echo $USER_SETTINGS['meshWarning'];
        echo "</Warning_MSG>";
        echo "<br>";
    }
}

/*
if (isset($GLOBALS['no_req_msg'])) {
    if ($GLOBALS['no_req_msg'] == "1") {
        //output nothing!!
    }
}else {
    echo "<strong><a style=\"color: inherit;text-decoration: none;\" href=\"\" title=\"" .
    $GLOBALS['USER_SETTINGS']['where_is_my_node'] . "\">Where the heck is my node??</a>\n";
        
}
*/
if (isset($GLOBALS['hide_admin'])) {
    if ($GLOBALS['hide_admin'] == "1") {
        //output nothing!!
    }
}else {
    echo "<strong><a style=\"float: right;\" href=\"admin/admin.php\">The \"Admin\" type page</a>\n";
}

echo "<div id='mapid' style='width: 100%; height: 95%;'>\n";
echo "</div>\n";

//$numNodes = count($NodeList);	// WXC change: this was giving the wrong number.
//should not count nodes that have no location info, they are not on the map...
//just using this for now.

//it is still giving the wrong number WXC -april 2018
//changing this based on other changes to get-map-info - may 2018
//$numNodes = wxc_getMySql("SELECT COUNT(*) as nodesWithLocations FROM node_info where (lat is not null or 0 or '') and (lon is not null or 0 or '')");
$numNodes = wxc_getMySql("SELECT COUNT(*) as nodesWithLocations FROM node_info where (lat != '0') and (lon != '0')");
$numNodes = $numNodes['nodesWithLocations'];
$numNodesTotal = count($NodeList);

//WXC comment: looks like this was for something else maybe?...
$numMarkers = count($MarkerList);

//$numLinks = count($TopoList);	// WXC change: probably the same thing going on here too
//just using this for now
//same here, probably still giving the wrong number WXC - april 2018
//changing this based on other changes to get-map-info - may 2018
//$numLinks = wxc_getMySql("SELECT COUNT(*) as linksWithLocations FROM topology WHERE (nodelat is not null or 0 or '' or '0') and (nodelon is not null or 0 or '' or '0') or (linklat is not null or 0 or '' or '0') and (linklon is not null or 0 or '' or '0')");
$numLinks = wxc_getMySql("SELECT COUNT(*) as linksWithLocations FROM topology WHERE (nodelat != '0') and (nodelon != '0') or (linklat != '0') and (linklon != '0')");
$numLinks = $numLinks['linksWithLocations'];
$numLinksTotal = count($TopoList);

$Content = "";

$filetime = 'Today';

//$Content .= "<div id='mapid' style='width: 100%; height: 95%;'>\n";
//$Content .= "</div>\n";
$Content .= "<script>";

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

$Content .= "</script>\n";
$Content .= "</div>\n"; // Closing tag


// Display Page
echo $Content;


//if (is_connected() && $USER_SETTINGS['inetServerText'])
//{
//    echo "<Internet_MSG>";
//    echo sprintf($USER_SETTINGS['inetServerText'], $USER_SETTINGS['inetServerHostname']);
//    echo "<br>";
//    echo "</Internet_MSG>";
//}

//echo "</div>\n"; // End division meshmap
echo "</body>\n";
echo "</html>\n";
/*
* End of primary display
********************************************************************************************************************/

?>
