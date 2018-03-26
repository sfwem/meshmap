<?php

/**
 * @name        map_functions.inc
 * @category    MESH MAP
 * @subcategory Display Map routines
 * @package     Active Node List
 * @author      Eric Satterlee, KG6WXC with K6GSE
 * @copyright   Copyright (c) 2017 as Open Source
 * @version     $Id$
 * @abstract    Eric has written a tool called get-map-info which retrieves HAM Mesh network devices,
 *                     their configuration and Linkage information. These details are populated in several SQL tables.
 *
 *
 *             These are the working functions for the meshMap display.
 *             There are three major sections of functions in this file:
 *             MESHMAP Map       - These are used to build the Markers and Links
 *             DATABASE access   - Used for all read-only access to the Database
 *             HTML Page Setup   - Used to build the infrastructure needed for the map display
 *
 *
 ********************************************************************************************************************/


/*
* MESHMAP Map Functions
*
*********************************************************************************************************************/
/**
 * @param $NodeList
 * @param $TopoList
 */
function build_NodesAndLinks($NodeList, $TopoList, $MarkerList = null)
{
    global $STABLE_MESH_VERSION;
    global $useNodes;
    global $useLinks;
    global $useMarkers;
    //icon names: default (green), update firmware (red) and for the different bands

    $markerList = "";

    //then get info from the node info database
    //use it to:
    //1: create markers for the map
    //2: do lots of stuff, like, use the topology data to make the link lines,
    //try to detect the tunnels vs everything else,
    //check what band the device is on,
    //find distance and bearing info of linked nodes. :) yes really!
    //try and tell the dtd links apart from the ones that are not dtd links but appear to be,
    //*real* DTD links will be very close together, if not the same,
    //the others, (like what Ventura Mesh uses) will be far apart and therefore probably not a "real" dtd link.

    $display_NodeList = "";
    if (is_array($NodeList) && !empty($NodeList))
    {
        foreach ($NodeList as $node_info)
        {
            $node_FirmwareStatus = checkVersion($node_info['firmware_version'], $STABLE_MESH_VERSION);

            if ($node_info['lat'] && $node_info['lon']) // Are there nodes with locations?
            {
                /*
                * find the Linked nodes to list
                *********************/
                $node_LinkedList = "";
                $node_LinkedList = load_LinkedTO($node_info, $TopoList);

                /*
                * Find the Services for the node
                ********************************/
                $node_ServiceList = load_ServiceList($node_info['olsrinfo_json']);

                /*
                * Build the Nodes Marker
                */
                $display_NodeList .= build_Marker($node_info, $node_ServiceList, $node_LinkedList, $node_FirmwareStatus);
            }
        }
        if (!empty($display_NodeList))
        {
            $display_NodeList = rtrim($display_NodeList); // Rtrim off an ending newline
        }
    }

    $display_MarkerList = "";
    if (isset($useMarkers) && $useMarkers)
    {
        foreach ($MarkerList as $Marker)
        {
            if ($Marker['lat'] && $Marker['lon']) //Are there Markers with location?
            {
                $display_MarkerList .= buildStationMarker($Marker); // Build Marker
            }
        }
        if (!empty($display_MarkerList))
        {
            $display_MarkerList = rtrim($display_MarkerList); // Rtrim off an ending newline
        }
    }

    /*
    * Create and Display the Link Lines
    ****************************************/
    $display_LinkList = "";
    if (isset($useLinks) && $useLinks)
    {
        $display_LinkList = load_LinkList($NodeList, $TopoList);
        if (!empty($display_LinkList))
        {
            $display_LinkList = rtrim($display_LinkList); // Rtrim off an ending newline
        }
    }
    $display_MarkersAndLinks = $display_NodeList . $display_MarkerList . $display_LinkList;
    if (!empty($display_MarkersAndLinks))
    {
        $display_MarkersAndLinks = rtrim($display_MarkersAndLinks, ','); // Trim off the very last comma
    }
    $display_MarkersAndLinks .= ";\n\n";

    return $display_MarkersAndLinks;
}

/**
 * loadServiceList
 * This function parses the json data obtained from the node.
 * It looks for all of the plugins/services installed on the node.
 * The Service_line format is:
 *         Link or host value | [TCP | UDP ] | Advertised  service
 *
 * This routine will return the last ( end ) field of the service line, which is the Advertised service
 *
 * @param $json_array
 *
 * @return string List of Services to Display
 */
function load_ServiceList($json_data)
{
    $json_array =  wxc_findServices($json_data, 'services');
    $serviceList = "";

    if (is_array($json_array))
    {
        foreach ($json_array as $key => $value)
        {
            //WXC 6-30-2017: removed links for services that have no link
            if ($value == NULL) {
                $serviceList .= '<br>'. $key;
            }else {
                //WXC 6-30-2017: Moved <br> from after link to before the link,
                //made the pop up look better.
                $serviceList .= "<br><a href='" . $value . "' target='service'>" . $key . "</a>";
            }
        }
    }
    return $serviceList;
}

/**
 * loadLinkList
 * This function iterates through the topology array to build a leaflet package polyline
 * There are four types of links defined, each is represented by a different color.
 * RF Link - Standard Mesh Node link
 * DTD Link - Mesh nodes connected by direct link ( typically via a switch )
 * Tunnel Link -
 * Infinite Link - Links reported by and marked as unusable by olsr
 *
 * @param $NodeList
 * @param $TopoList
 *
 * @return mixed
 */
function load_LinkList($NodeList, $TopoList)
{
    global $MESH_SETTINGS;
    $linkList = "";
    if (isset($TopoList))
    {
        foreach ($TopoList as $value)
        {
            if ($value['nodelat'] && $value['linklon'])
                //if there's no location info ignore the entry
            {
                $node_lat = $value['nodelat'];
                $node_lon = $value['nodelon'];
                $link_lat = $value['linklat'];
                $link_lon = $value['linklon'];
                $display_color = "red";
                $display_weight = 2;
                $display_opacity = 1;
                $display_offset = 2;
                $map_LayerAssigned = "";
                $tunnel = 0;
                $dtd = 0;
                $moreThanTen = 0;
                $infinite = 0;
                $nodeHasTunnel = 0;
                $linkHasTunnel = 0;
                $nodeName = $value['node'];
                $linkName = $value['linkto'];
                if ($value['cost'] == 1.0 && !($tunnel)) //find the tunnels
                {
                    foreach ($NodeList as $searching_for_tunnels)
                    {
                        if ($searching_for_tunnels['node'] == $nodeName)
                        {
                            if ($searching_for_tunnels['active_tunnel_count'] >= 1)
                            {
                                $nodeHasTunnel = 1;
                            }
                        }
                        if ($searching_for_tunnels['node'] == $linkName)
                        {
                            if ($searching_for_tunnels['active_tunnel_count'] >= 1)
                            {
                                $linkHasTunnel = 1;
                            }
                        }
                        if (($linkHasTunnel) && ($nodeHasTunnel))
                        {
                            $tunnel = 1;
                            $nodeHasTunnel = 0;
                            $linkHasTunnel = 0;
                            $display_color = $MESH_SETTINGS['Link_Tunnel'];
                            $dtd = 1;
                            $display_opacity = 0.5;
                            $map_LayerAssigned = "tunnelLinks";
                        }
                    }
                }
                if ($value['cost'] == 0.1 && $value['distance'] <= 0.7)
                    //find DTD links (always a cost of 0.1)
                {
                    $display_color = $MESH_SETTINGS['DTD_Link_Minus'];
                    $dtd = 1;
                    $display_opacity = 0.2;
                    $map_LayerAssigned = 'dtdLinks';
                }
                //this is for the "other" DTD links
                //using other wireless tech to create links that are seen as "DTD" by the AREDN software
                if ($value['cost'] == 0.1 && $value['distance'] > 0.7)
                {
                    $display_color = $MESH_SETTINGS['DTD_Link_Plus'];
                    $dtd = 1;
                    $display_opacity = 0.4;
                    $map_LayerAssigned = 'dtdLinks';
                }
                //find the "infinite cost" links (they show as "INFINITE" in the olsr files the other script changes that to 99.99)
                if ($value['cost'] == 99.99)
                {
                    $display_color = $MESH_SETTINGS['Link_Infinite'];
                    $infinite = 1;
                    $display_opacity = 0.2;
                    $map_LayerAssigned = 'infiniteLinks';
                }
                //links with a cost of less than 1.0 but NOT tunnels or DTD links
                //tunnels are always 1.0 and DTD is always 0.1
                if (($value['cost'] <= 1.000) && ($value['cost'] != 0.1) && !($tunnel))
                {
                    //these will always be a solid green
                    $display_color = $MESH_SETTINGS['Link_GOOD'];
                    $display_opacity = 0.5;
                    $map_LayerAssigned = 'rfLinks';
                }
                //links of greater than 10.000 ETX, but not "INFINITE" (these links are always red)
                if ($value['cost'] > 10.000 && $value['cost'] != 99.99)
                {
                    $display_color = $MESH_SETTINGS['Link_BAD'];
                    $moreThanTen = 1;
                    $display_opacity = 0.3;
                    $map_LayerAssigned = 'rfLinks';
                }
                //now for the more normal links
                //less than ETX 5.0 links
                if ($value['cost'] < 5 && !($dtd) && !($tunnel))
                {
                    $display_color = sprintf("#%02XFF00", ($value['cost'] - 1) * (255 / (5 - 1)));
                    $map_LayerAssigned = 'rfLinks';
                }
                //more than ETX 5.0 links
                if ($value['cost'] > 5 && !($moreThanTen) && !($infinite))
                {
                    $display_color = sprintf("#FF%02X00", ($value['cost'] - 1) * (255 / (5 - 1)) -
                        255);
                    $display_opacity = 0.5;
                    $map_LayerAssigned = 'rfLinks';
                }
                if ($map_LayerAssigned)
                {
                    $linkList .= "L.polyline([[" . $node_lat . "," . $node_lon . "],[" . $link_lat .
                        "," . $link_lon . "]], {color: '" . $display_color . "', opacity: " . $display_opacity .
                        ", weight: " . $display_weight . ", offset: " . $display_offset . "})
						.bindPopup(\"<strong>  $nodeName  </strong> to <strong> $linkName</strong><br>   <strong>Cost</strong>: " .
                        $value['cost'] . "\")
						.addTo(" . $map_LayerAssigned . "),\n";

                }
            }
        }
    }

    return $linkList;
}

/**
 * loadLinkedTO
 * This routine create a HTML formatted text list of link details.
 * The information gathered here is display as part of the node popup detail.
 *
 * @param $node_info
 * @param $TopoList
 *
 * @return string
 */
function load_LinkedTO($node_info, $TopoList)
{
    $linkedToList = "";
    $linkInfoForStationPopups = array($node_info['node'] => "");
    $node = $node_info['node'];
    //and build a big 3D array of it all
    //like  this:
    //array
    //	"NodeName"
    //		"1stLinkedNodesName"
    //			"CostFrom"
    //			"CostTo"
    //			"distance"
    //			"bearing"
    //		"2ndLinkedNodesName"
    //			"CostFrom"
    //			"CostTo"
    //etc..etc..
    if (isset($TopoList))
    {
        foreach ($TopoList as $value)
        {
            //if there's no location info (at each end of the link) ignore the entry
            if ($value['nodelat'] && $value['linklon'])
            {
                if ($value['linkto'] == $node)
                {
                    //echo var_dump($node_info['node']);
                    $linkedNode = array($value['node']);
                    $linkedNodesKeyNameArray = array("costFrom", "costTo", "distance", "bearing");
                    $linkedNode[$value['node']] = array_fill_keys($linkedNodesKeyNameArray, "");
                    $linkedNode[$value['node']]['costFrom'] = $value['cost'];
                    $linkInfoForStationPopups[$node_info['node']][] = $linkedNode;
                    //this will be the cost FROM each linked station back TO the node we are currently looking at in the while loop
                    //$linkInfoForStationPopups[$node_info['node']][$value['node']] = array_fill_keys($linkedNodesKeyNameArray, $value['cost']);
                    unset($linkedNodesKeyNameArray);
                    //$linkInfoForStationPopups[$node_info['node']] = array($value['node'] => "");
                }
                if ($value['node'] == $node)
                {
                    //$linkedNodesKeyNameArray = array("costTo", "distance", "bearing");
                    //this will be the cost TO each linked station FROM the node we are currently looking at in the while loop
                    //distance and bearing added too for goo measure! :)
                    //$linkInfoForStationPopups[$node_info['node']][$value['linkto']] = array_fill_keys($linkedNodesKeyNameArray, "");
                    $linkInfoForStationPopups[$node_info['node']][$value['linkto']]["costTo"] = $value['cost'];
                    //add in the distance to that linked node
                    $linkInfoForStationPopups[$node_info['node']][$value['linkto']]["distance"] = $value['distance'];
                    //add in the bearing to that linked node
                    $linkInfoForStationPopups[$node_info['node']][$value['linkto']]["bearing"] = $value['bearing'];
                }
            }
        }
    }
    //now take all that info back out and put into a properly formatted string variable that we'll use later]
    foreach ($linkInfoForStationPopups as $nodeNameLinkArray => $linktoArray)
    {
        if ($linktoArray > "")
        {
            foreach ($linktoArray as $linkedNodeName => $costArray)
            {
                if (($costArray['costTo'] == 0.1) && ($costArray['costFrom'] == 0.1))
                {
                    $display_cost = ' <Link_DTD>(DTD)</Link_DTD> ';
                }
                else
                {
                    $display_cost = " (" . $costArray['costTo'] . "/" . $costArray['costFrom'] .
                        ") ";
                }
                $linkedToList .= $linkedNodeName . $display_cost . $costArray['distance'] . " mi " .
                    $costArray['bearing'] . "&deg;<br>";
            }
        }
    }

    return $linkedToList;
}


/**
 * buildMarker
 * This routine builds Mesh Markers.
 * These mesh markers are added to various map layer depending upon the type of node
 * The currently defined Mesh Markers are categorized by frequency
 *          2Ghz, 3Ghz, 5Ghz, 900Mhz, Linux systems
 *
 * Mesh Markers are stored in the node_info table, which is updated by the get-map-info.php routine
 *
 * @param $node_info
 * @param $node_ServiceList
 * @param $node_LinkedList
 * @param $node_FirmwareStatus
 *
 * @return string AssignedLayer
 */
function build_Marker($node_info, $node_ServiceList, $node_LinkedList, $node_FirmwareStatus)
{
    $markerList = "";
    $nodeUrl = "<NodeTitle><a href='http://" . $node_info['node'] . ".local.mesh:8080'>" . $node_info['node'] .
        "</a></NodeTitle>";

    //Now we look at the 'channel' value, for each node, from the node_info database.
    //Then compare it to what we know about each bands channels
    //if we find a match, we assume we're in that band...

    if ($node_info['firmware_version'] == "Linux")
    { // Linux Devices ( Special for SNOOPY )
        $icon = "linuxCircle";
        $band = "<Device_Linux>Linux</Device_Linux>";
        $AssignedLayer = 'otherStations';
    }
    else
    {
        switch ((wxc_checkBand($node_info['channel'])))
        {
            case "2GHz": // 2.4GHz devices
                $icon = "twoRadioCircle";
                $band = "<Device_24GHz>2.4GHz</Device_24GHz>";
                $AssignedLayer = 'twoGHzStations';
                break;
            case "3GHz": // 3GHz devices
                $icon = "threeRadioCircle";
                $band = "<Device_3GHz>3.4GHz</Device_3GHz>";
                $AssignedLayer = 'threeGHzStations';
                break;
            case "5GHz": // 5GHz devices
                $icon = "fiveRadioCircle";
                $band = "<Device_5GHz>5.8GHz</Device_5GHz>";
                $AssignedLayer = 'fiveGHzStations';
                break;
            case "900MHz": // Note: Channel values borked for 900Mhz
                $icon = "nineRadioCircle";
                $band = "<Device_900MHz>900MHz</Device_900MHz>";
                $AssignedLayer = 'nineHundredMHzStations';
                break;
            default: // unknown
                $icon = "unknownRadioCircle";
                $band = "<unknown>?</unknown>";
                $AssignedLayer = 'otherStations';
        }
    }


    /*
    * check for Out of Date Firmware
    */
    switch ($node_FirmwareStatus)
    {
        case 1:
            $firmware = "<OutOfDate>Firmware: " . $node_info['firmware_version'] . "</OutOfDate>";
            break;
        case 2:
            $firmware = "<Experimental>Firmware: " . $node_info['firmware_version'] .
                "</Experimental>";
            break;
        default:
            $firmware = $node_info['firmware_version'];
    }


    /*
    * Now build the marker
    */
    if ($node_ServiceList)
    {
        $node_ServiceList = "<hr><Strong>Services:</Strong>" . $node_ServiceList;
    }
    if ($node_LinkedList)
    {
        $node_LinkedList =
            "<hr><Strong>Linked to:<br>Node Name (cost to/from) distance bearing</Strong><br>" . $node_LinkedList;
    }
    $markerList .= "L.marker([" . $node_info['lat'] . "," . $node_info['lon'] . "], {title: '" . $node_info['node'] .
        "', icon: " . $icon . "}).bindPopup(\" " . $nodeUrl . " (" . $band . ")<br>" . $node_info['lat'] .
        ", " . $node_info['lon'] . "<br>SSID: " . $node_info['ssid'] . "<br>Channel: " . $node_info['channel'] .
        ", Bandwidth: " . $node_info['chanbw'] . "<br>" . $node_info['model'] . "<br>" . $firmware .
        $node_ServiceList . $node_LinkedList . "\").addTo(" . $AssignedLayer . ") ,\n"; // Close out this node
    /*
    * If Upgrade Suggested add Node to that layer
    */
    if ($node_FirmwareStatus > 0)
    {
        $markerList .= "L.marker([" . $node_info['lat'] . "," . $node_info['lon'] . "], {title: '" .
            $node_info['node'] . "', icon: " . (($node_FirmwareStatus == 1) ? "redCircle" :
                "orangeCircle") . "}).bindPopup(\" " . $nodeUrl . " (" . $band . ")<br>" . $node_info['model'] .
            "<br>" . $firmware . "<br>" . (($node_FirmwareStatus == 1) ?
                "Software upgrade is highly recommended" : "Experimental") . " \").addTo(upgradeStations) ,\n";
    }

    return $markerList;
}

/**
 * buildStationMarker
 * This routine builds Station Markers. Station Markers are auxiliary (non-mesh) details to be displayed on the map.
 * These markers are added to the "Other Stations" layer.
 * The currently defined Station Markers are:
 *          Operator, Police Station, Fire Station, EOC, Hospital, Other
 *
 * Station Markers are stored in the station_info table. Station Markers are manually created.
 *
 * @param $station_info
 *
 * @return string AssignedLayer
 */
function buildStationMarker($Marker)
{

    //Now we look at the 'channel' value, for each node, from the node_info database.
    //Then compare it to what we know about each bands channels
    //if we find a match, we assume we're in that band...

    switch ($Marker['type'])
    {
        case "operator":
            // Operator Home Station
            $icon = "operatorIcon";
            $AssignedLayer = 'operatorsElements';
            break;
        case "police":
            $icon = "policeIcon";
            $AssignedLayer = 'policeElements';
            break;
        case "eoc":
            $icon = "eocIcon";
            $AssignedLayer = 'racesElements';
            break;
        case "firedepartment":
            $icon = "fireIcon";
            $AssignedLayer = 'fireElements';
            break;
        case "hospital":
            $icon = "hospitalIcon";
            $AssignedLayer = 'hospitalElements';
            break;
        default:
            // unknown
            $icon = "Red_Marker";
            $AssignedLayer = 'otherElements';
    }

    /*
    * Now build the marker
    */
    $markerList = "L.marker([" . $Marker['lat'] . "," . $Marker['lon'] . "], {title: '" . $Marker['name'] .
        "', icon: " . $icon . "}).bindPopup(\" " . $Marker['name'] . "<br>" . $Marker['description'] .
        "<br>" . $Marker['lat'] . ", " . $Marker['lon'] . "<br>TYPE: " . $Marker['type'] . "\")
        .addTo(" . $AssignedLayer . ") ,\n";

    return $markerList;
}


/*
* DATABASE access routines
**********************************************************************************************************************/
/**
 * load the Node data into an array.
 *
 * Where Clause and Orderby are optional
 * @param null $whereClause
 * @param null $orderBy
 *
 * @return array
 */
function load_Nodes($whereClause = null, $orderBy = null)
{
    /*
    * Node Table Query
    */
    global $USER_SETTINGS;
    global $useNodes;
    $NodeList = array();
    if (isset($USER_SETTINGS['sql_db_tbl_node']))
    {
        // Setup the Query ( replace WHERE and ORDER By as needed )
        $query = "SELECT * FROM " . $USER_SETTINGS['sql_db_tbl_node'] . " " .
            ($whereClause ? $whereClause : " WHERE 1=1 ") . " " .
            ($orderBy ? $orderBy : " ORDER BY node");
        // Retrieve the data
        $node_info_db = mysqli_query($GLOBALS['sql_connection'], $query)
        or die('Could not Select Items from Node Table: ' . mysqli_error());
        // Load the data into an array for ease of handling
        while ($node_info = mysqli_fetch_array($node_info_db, MYSQLI_ASSOC))
        {
            $NodeList[] = $node_info;
        }
        $useNodes = true;
    }
    else
    {
        $useNodes = false;
    }
    return $NodeList;
}


/**
 * load the Marker data into an array.
 *
 * Where Clause and Orderby are optional
 * @param null $whereClause
 * @param null $orderBy
 *
 * @return array
 */
function load_Markers($whereClause = null, $orderBy = null)
{
    /*
    * marker Table Query
    */
    global $USER_SETTINGS;
    global $useMarkers;
    $MarkerList = array();
    if (isset($USER_SETTINGS['sql_db_tbl_marker']))
    {
        // Setup the Query ( replace WHERE and ORDER By as needed )
        $query = "SELECT * FROM " . $USER_SETTINGS['sql_db_tbl_marker'] . " " .
            ($whereClause ? $whereClause : " WHERE 1=1 ") . " " .
            ($orderBy ? $orderBy : " ORDER BY name");
        // Retrieve the data
        $marker_info_db = mysqli_query($GLOBALS['sql_connection'], $query)
        or die('Could not Select Items from marker Table: ' . mysqli_error());
        // Load the data into an array for ease of handling
        while ($marker_info = mysqli_fetch_array($marker_info_db, MYSQLI_ASSOC))
        {
            $MarkerList[] = $marker_info;
        }
        $useMarkers = true;
    }
    else
    {
        $useMarkers = false;
    }
    return $MarkerList;
}


/**
 * load the Topology (or Link) Data into an array.
 *
 * Where Clause and Orderby are optional
 * @param null $whereClause
 * @param null $orderBy
 *
 * @return array
 */
function load_Topology($whereClause = null, $orderBy = null)
{
    /*
    * Topology Table Query
    */
    global $USER_SETTINGS;
    global $useLinks;
    $TopoList = array();
    if (isset($USER_SETTINGS['sql_db_tbl_topo']))
    {
        // Setup the Query ( replace WHERE and ORDER By as needed )
        $query = "SELECT * FROM " . $USER_SETTINGS['sql_db_tbl_topo'] . " " .
            ($whereClause ? $whereClause : " WHERE 1=1 ") . " " .
            ($orderBy ? $orderBy : " ORDER BY distance, cost");
        // Retrieve the data
        $topology_info_db = mysqli_query($GLOBALS['sql_connection'], $query)
        or die('Could not Select Items from Topology Table: ' . mysqli_error());
        // Load the data into an array for ease of handling
        while ($topology_info = mysqli_fetch_array($topology_info_db, MYSQLI_ASSOC))
        {
            $TopoList[] = $topology_info;
        }
        $useLinks = true;
    }
    else
    {
        $useLinks = false;
    }
    return $TopoList;
}

/*
* HTML Page Setup routines
**********************************************************************************************************************/
/**
 * add_MapLayers
 *
 * This routine adds the MapLayers needed
 * the (navigator.online) controls the display for online or offline browsers
 * No it doesn't, it doesn't tell you crap really... being on a LAN makes navigator.onLine true!!!
 * It has no bearing whatsoever if internet access is available or not
 *
 *
 * @return string - This string will need to be outputed to the browser
 */
function add_MapLayers()
{
if($GLOBALS['mesh']) {
	$offline_map_tiles = $GLOBALS['USER_SETTINGS']['offlineMapTileDir'];
	$Content = <<< EOD
        // navigator.onLine *DOES NOT* work!!
        
            var offlineMapTiles = "$offline_map_tiles/{z}/{x}/{y}.png";
            var defaultMap = new L.tileLayer(offlineMapTiles);

            var baseLayers = {"Offline Map": defaultMap};

        \n\n
EOD;
}else {
    $Content = <<< EOD
        //if (navigator.onLine) <-- *DOES NOT* work. REMOVED
            var OSMMapURL = '//{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
            var terrainMapURL = '//stamen-tiles-{s}.a.ssl.fastly.net/terrain/{z}/{x}/{y}.jpg';
            var topoMapURL = '//{s}.tile.opentopomap.org/{z}/{x}/{y}.png';
            
            var OSMmap = new L.tileLayer(OSMMapURL);
            var defaultMap = new L.tileLayer(terrainMapURL);
            var topologyMap = new L.tileLayer(topoMapURL);
            
            var baseLayers = {"Topographic": topologyMap, "Street": OSMmap, "Terrain": defaultMap};

		\n\n
EOD;
}
    return $Content;
}


/**
 * @param $numNodes
 * @param $numLinks
 * @param $numMarkers
 *
 * @return string
 */
function add_MapImages($numNodes, $numLinks, $numMarkers)
{
    global $MESH_SETTINGS;
    $Content = "
    // Node and Link Icons
        var greenRadioCircle = L.icon({
            iconUrl: '" . $MESH_SETTINGS['Device_Default'] . "',
            iconSize: [18, 18], iconAnchor: [9, 9], popupAnchor: [0, -9]
        });
        // Out of Date node software
        var redCircle = L.icon({
            iconUrl: '" . $MESH_SETTINGS['Device_OOD'] . "',
            iconSize: [22, 22], iconAnchor: [11, 11], popupAnchor: [0, -9]
        });
        // Experiment node software
        var orangeCircle = L.icon({
            iconUrl: '" . $MESH_SETTINGS['Device_Experimental'] . "',
            iconSize: [22, 22], iconAnchor: [11, 11], popupAnchor: [0, -9]
        });
        // 900 Mhz
        var nineRadioCircle = L.icon({
            iconUrl: '" . $MESH_SETTINGS['Device_900MHz'] . "',
            iconSize: [18, 18], iconAnchor: [9, 9], popupAnchor: [0, -9]
        });
        //2.4GHz
        var twoRadioCircle = L.icon({
            iconUrl: '" . $MESH_SETTINGS['Device_24GHz'] . "',
            iconSize: [18, 18], iconAnchor: [9, 9], popupAnchor: [0, -9]
        });
        //3GHz
        var threeRadioCircle = L.icon({
            iconUrl: '" . $MESH_SETTINGS['Device_3GHz'] . "',
            iconSize: [18, 18], iconAnchor: [9, 9], popupAnchor: [0, -9]
        });
        //5GHz
        var fiveRadioCircle = L.icon({
            iconUrl: '" . $MESH_SETTINGS['Device_5GHz'] . "',
            iconSize: [18, 18], iconAnchor: [9, 9], popupAnchor: [0, -9]
        });
        //Unknown
        var unknownRadioCircle = L.icon({
            iconUrl: '" . $MESH_SETTINGS['Device_Unknown'] . "',
            iconSize: [18, 18], iconAnchor: [9, 9], popupAnchor: [0, -9]
        });
        
        //a special one
        var linuxCircle = L.icon({
            iconUrl: '" . $MESH_SETTINGS['Device_Linux'] . "',
            iconSize: [18, 18], iconAnchor: [9, 9], popupAnchor: [0, -9]
        });\n";

    if ($numMarkers > 0)
    {
        $Content .= "
        // Marker Icons
        var policeIcon = L.icon({
            iconUrl: '" . $MESH_SETTINGS['Marker_Police'] . "',
            iconSize: [25, 25], iconAnchor: [9, 9], popupAnchor: [0, -9]
        });
        var fireIcon = L.icon({
            iconUrl: '" . $MESH_SETTINGS['Marker_Fire'] . "',
            iconSize: [25, 25], iconAnchor: [9, 9], popupAnchor: [0, -9]
        });
        var operatorIcon = L.icon({
            iconUrl: '" . $MESH_SETTINGS['Marker_Operator'] . "',
            iconSize: [25, 25], iconAnchor: [9, 9], popupAnchor: [0, -9]
        });
        var hospitalIcon = L.icon({
            iconUrl: '" . $MESH_SETTINGS['Marker_Hospital'] . "',
            iconSize: [25, 25], iconAnchor: [9, 9], popupAnchor: [0, -9]
        });
        var eocIcon = L.icon({
            iconUrl: '" . $MESH_SETTINGS['Marker_EOC'] . "',
            iconSize: [25, 25], iconAnchor: [9, 9], popupAnchor: [0, -9]
        });
        var Red_Marker = L.icon({
            iconUrl: '" . $MESH_SETTINGS['Marker_Future'] . "',
            iconSize: [25, 25], iconAnchor: [9, 9], popupAnchor: [0, -9]
        });\n\n";
    }
    return $Content;
}


/**
 * @param $numNodes
 * @param $numLinks
 * @param $numMarkers
 *
 * @return string
 */
function create_MapLayers($numNodes, $numLinks, $numMarkers)
{
    //overlay groups to help superposition order
    $Content = "
    // Node Groups
    var nineHundredMHzStations = new L.LayerGroup();
    var twoGHzStations = new L.LayerGroup();
    var threeGHzStations = new L.LayerGroup();
    var fiveGHzStations = new L.LayerGroup();
    var otherStations = new L.LayerGroup();
    var upgradeStations = new L.LayerGroup();\n
";

    if ($numLinks > 0)
    {
        $Content .= "
        // Link Groups
        var rfLinks = new L . LayerGroup();
        var tunnelLinks = new L . LayerGroup();
        var dtdLinks = new L . LayerGroup();
        var infiniteLinks = new L . LayerGroup();
        var stations = new L . LayerGroup();
        var linkLines = new L . LayerGroup();
        var legendLayer = new L . layerGroup();\n
";
    }
    if ($numMarkers > 0)
    {
        $Content .= "
        // Marker Groups
        var otherElements = new L . LayerGroup();
        var policeElements = new L . LayerGroup();
        var fireElements = new L . LayerGroup();
        var hospitalElements = new L . LayerGroup();
        var racesElements = new L . LayerGroup();
        var operatorsElements = new L . LayerGroup();
        var otherElements = new L . LayerGroup();
        \n\n
";
    }

    return $Content;
}


/**
 * @param $numNodes
 * @param $numLinks
 * @param $numMarkers
 *
 * @return string
 */
function create_MapOverlays($numNodes, $numLinks, $numMarkers)
{
    global $MESH_SETTINGS;
    $Content = "// Node Overlays
    var groupedOverlays = {
        \"Stations\": {
                \"<img src='" . $MESH_SETTINGS['Device_24GHz'] . "' height='20'>2.4GHz\": twoGHzStations,
                \"<img src='" . $MESH_SETTINGS['Device_3GHz'] . "' height='20'>3.4GHz\": threeGHzStations,
                \"<img src='" . $MESH_SETTINGS['Device_5GHz'] . "' height='20'>5.8GHz\": fiveGHzStations,
                \"<img src='" . $MESH_SETTINGS['Device_900MHz'] . "' height='20'>900MHz\": nineHundredMHzStations,
                \"<img src='" . $MESH_SETTINGS['Device_Linux'] . "' height='20'>Additional Mesh\": otherStations,
                \"<img src='" . $MESH_SETTINGS['Device_OOD'] . "' height='20'>Check Versions\": upgradeStations
        }";

    if ($numLinks > 0)
    {
        $Content .= "
                ,\n
        // Link Overlays
    \"Links\": {
    \"<Link_RF_Only>RF Only</Link_RF_Only>\": rfLinks,
            \"<Link_DTD>DTD</Link_DTD>\": dtdLinks,
            \"<Link_Tunnel>Tunnels</Link_Tunnel>\": tunnelLinks,
            \"<Link_Infinite>Infinite</Link_Infinite>\": infiniteLinks
        }\n";
    }

    if ($numMarkers > 0)
    {
        $Content .= "
        ,\n
        // Marker Layers
        \"Additional\": {
            \"<img src='" . $MESH_SETTINGS['Marker_Police'] . "' height='20'>Police\": policeElements,
            \"<img src='" . $MESH_SETTINGS['Marker_Fire'] . "' height='20'>Fire Dept\": fireElements,
            \"<img src='" . $MESH_SETTINGS['Marker_Hospital'] . "' height='20'>Hospital\": hospitalElements,
            \"<img src='" . $MESH_SETTINGS['Marker_EOC'] . "' height='20'>EOC\": racesElements,
            \"<img src='" . $MESH_SETTINGS['Marker_Operator'] . "' height='20'>Operators\": operatorsElements,
            \"<img src='" . $MESH_SETTINGS['Marker_Future'] . "' height='20'>Future Mesh\": otherElements
        }\n";
    }
    $Content .= "};\n\n";

    return $Content;
}


/**
 * @return string
 */
function create_MapLegend()
{
    //legend overlay
    $Content = <<< EOD
    var legend = L.control({position: 'topright'});
    legend.onAdd = function (map) {
        var div = L.DomUtil.create('div', 'info legend', L.DomUtil.get('map'));
        div.innerHTML +=
            '<img src="images/mapLegend/LinkCostLegend.jpg" alt="legend" width="175" height="25"><br>' +
            '<img src="images/mapLegend/DTDLinkLegend.jpg" alt="legend" width="75" height="60" align="right">';
        return div;
    };\n\n
EOD;

    return $Content;
}


/**
 * @return string
 */
function create_MapImage()
{
    global $USER_SETTINGS;
    //init the map with size, zoom level, center co-ords, etc...
    //http://leafletjs.com/reference-1.0.3.html#control-zoom
    $Content = "
    var map = L.map('mapid', {
		//attributionControl: false,
        center: [" . $USER_SETTINGS['map_center_coordinates'] . "],
        zoom: [" . $USER_SETTINGS['map_initial_zoom_level'] . "],            
        minZoom: 0,
		maxZoom: 18,
		zoomSnap: 0,
		zoomDelta: 0.25,
        layers: [defaultMap, twoGHzStations, threeGHzStations, fiveGHzStations, nineHundredMHzStations, rfLinks, tunnelLinks, dtdLinks, infiniteLinks],
        fullscreenControl: true
    });\n\n
";

    return $Content;
}


/**
 * @param $numNodes
 * @param $numLinks
 * @param $numMarkers
 *
 * @return string
 */
function show_MapMarkerDetails($numNodes, $numLinks, $numMarkers, $numNodesTotal, $numLinksTotal)
{
    // Get the last time we updated the link info
    $filetime = wxc_scriptGetLastDateTime("LINKINFO", "topology");
    if ($filetime)
    {
        $filetime = date_format($filetime, 'F d Y H:i:s');
    }

    //$Content = "MeshMapDetails = '<strong>" . $numNodes . " Nodes shown, with " . $numLinks .
    //    " links " . " &nbsp;&nbsp;&nbsp;Data Last Acquired @ " . $filetime . "</strong>';\n";
    
    $Content = "MeshMapDetails = '<strong><a style=\"color: inherit;text-decoration: none;\" href=\"\" title=\"" .
    	$numNodesTotal . " Total nodes in the database." . '\n' . "Nodes without Location info cannot be shown.\">" .
    	$numNodes . "</a> Nodes shown, with <a style=\"color: inherit;text-decoration: none;\" href=\"\" title=\"" .
    	$numLinksTotal . " Total links in the database." . '\n' . "Nodes without Location info cannot have links shown.\">" . 
    	$numLinks . " links " . "&nbsp;|&nbsp;Data Last Acquired @ " . $filetime . "</strong>';\n";

    $Content .= "WXCAttribution = 'Map originally created by <a href=mailto:kg6wxc@gmail.com>KG6WXC</a> Oct 2016&nbsp;with N2MH and K6GSE contributions&nbsp;|&nbsp;';\n";

    $Content .= "MapAttribution = '<a href=http://leafletjs.com title=\'" .
        "Map data &copy; by OpenStreetMap (http://openstreetmap.org) with contributions from:" . '\n' .
        "CC-BY-SA (http://creativecommons.org/licenses/by-sa/2.0/" . '\n' .
        "Map tiles (http://maps.stamen.com) by Stamen Design (http://stamen.com), under CC BY 3.0" .
        '\n' . "Map style (http://viewfinderpanoramas.org)" . '\n' . "Icons (www.flaticon.com)" . '\n' .
        "OpenTopoMap (https://opentopomap.org)\'>Leaflet</a>&nbsp;';\n";

    $Content .= "attributionCtrl = map.attributionControl;\n";
    $Content .= "attributionCtrl.setPrefix(WXCAttribution + MapAttribution);\n";
    $Content .= "attributionCtrl.addAttribution(MeshMapDetails);\n";

    return $Content;
}


/**
 * @return string
 */
function instantiate_Map()
{
    $Content = <<< EOD
    var hash = new L.Hash(map);

    /*
     * MeshMap Legend
     */
    legend.addTo(map);
    /*
     * Layer Control
     */
    L.control.groupedLayers(baseLayers, groupedOverlays, {position: 'topleft'}).addTo(map);
    /*
     * Scale Control
     */
    L.control.scale().addTo(map);\n
	/* Attribution */
	//attributionCtrl({position: 'bottomleft'}).addTo(map);\n
EOD;

    return $Content;
}
?>



