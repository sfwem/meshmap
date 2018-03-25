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
 *
 **************************************************************************/

/***************************************************************************
*It is very important to change the INCLUDE_DIR variable.                *
*
*The INCLUDE_DIR variable *must* be pointing the where you have the       *
*scripts directory, some users *
*may want to seperate them for whatever reason)                           *
*This page WILL NOT RUN otherwise!!! You have been warned!!!               *
***************************************************************************/


$INCLUDE_DIR = "/srv/meshmap";
$USER_SETTINGS = parse_ini_file($INCLUDE_DIR . "/scripts/user-settings.ini");
global $MESH_SETTINGS;
$MESH_SETTINGS = parse_ini_file($INCLUDE_DIR . "/scripts/meshmap-settings.ini");

require $INCLUDE_DIR . "/scripts/wxc_functions.inc";
require $INCLUDE_DIR . "/scripts/map_functions.inc";

date_default_timezone_set($USER_SETTINGS['localTimeZone']);
@include $INCLUDE_DIR . "/wxc_custom.inc";
/*
* SQL Connections
*********************/
wxc_connectToMySQL();
$NodeList = load_Nodes();           // Get the Node Data

$STABLE_MESH_VERSION = $USER_SETTINGS['current_stable_fw_version'];

/*
 * HTML Header includes all of the scripts needed by jQueryUI and datatables
 * This report is designed to run while connected to the internet.
 *********************************************************************************************************************/
$page_header = <<< EOD
<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-type" content="text/html; charset=utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Mesh Map Report</title>
    <link rel="alternate" type="application/rss+xml" title="RSS 2.0" href="http://www.datatables.net/rss.xml">
    <!-- <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.10.15/css/jquery.dataTables.min.css"> -->
    <link rel="stylesheet" type="text/css" href="css/jquery.dataTables.min.css">
	<style type="text/css" class="init"></style>
    <!-- <script type="text/javascript" language="javascript" src="//code.jquery.com/jquery-1.12.4.js"></script> -->
	<script type="text/javascript" language="javascript" src="javascripts/jquery-3.2.1.js"></script>
    <!-- <script type="text/javascript" language="javascript" src="https://cdn.datatables.net/1.10.15/js/jquery.dataTables.min.js"></script> -->
    <script type="text/javascript" language="javascript" src="javascripts/jquery.dataTables.min.js"></script>
	<script type="text/javascript" class="init">
        $(document).ready(function() {
            $('#meshdata').DataTable( {
            "scrollX": true,
            "pageLength": 25,
                "columnDefs": [ {
                    "visible": true,
                    "targets": -1, 
                } ]

            } );
        } );

    </script>
</head>
EOD;
echo $page_header;

/*
 * Modifications from here down to fit your specific needs
 **********************************************************************************************************************/

echo "\n\n<body class=\"wide comments meshdata\">";
echo "<a name=\"top\" id=\"top\"></a>\n";
echo "<h1 class=\"page_title\">Mesh Map Nodes</h1>\n";
echo "</div>\n";
echo "<div class=\"fw-container\">\n";
echo "<div class=\"fw-body\">\n";
echo "<div class=\"content\">\n";


echo "<table id=\"meshdata\" class=\"display\" cellspacing=\"0\" width=\"100%\">\n\n"; // Define the Table

echo "<thead>\n";                                        // Build the Table Header
display_HeaderTitles();
echo "</thead>\n\n";

echo "<tfoot>\n";
display_HeaderTitles();
echo "</tfoot>\n\n";


/*
 * Load the Data into the table
 */
if (is_array($NodeList) && !empty($NodeList))
{
    echo "<tbody>\n\n";
    foreach ($NodeList as $Node)
    {
        $node_FirmwareStatus = checkVersion($Node['firmware_version'], $STABLE_MESH_VERSION);
        /*
         * If you add columns here, make sure to add them to display_HeaderTitles()
         */
        echo "<tr>\n";
        echo "<td>"
	. "<a href=\"http://"
	. $Node['node']
	. ":8080\" target=\"node\">"
 	. $Node['node']
	. "</a>"
	. "</td>\n";
        echo "<td>" . $Node['lat'] . "</td>\n";
        echo "<td>" . $Node['lon'] . "</td>\n";
        echo "<td>" . $Node['ssid'] . "</td>\n";
        echo "<td>" . $Node['model'] . "</td>\n";
        echo "<td>" . $Node['firmware_mfg'] . "</td>\n";

        switch ($node_FirmwareStatus)
        {
            case 1:
                $firmware = "<font color='red'>" . $Node['firmware_version'] . "</font>";
                break;
            case 2:
                $firmware = "<font color='orange'>" . $Node['firmware_version'] . "</font>";
                break;
            default:
                $firmware = $Node['firmware_version'];
        }
        echo "<td>" . $firmware . "</td>\n";
        /*
        * Find the Services for the node
        ********************************/
        echo "<td>";
        echo load_ServiceList($Node['olsrinfo_json']);
        echo "</td>\n";
        echo "<td align=\"center\">"
	. $Node['last_seen']
	." GMT"
	."</td>\n";

        echo "</tr>\n\n";
    }
}

echo "</tbody>\n";
echo "</table>\n";
echo "</div>\n";
echo "</div>\n";
echo "<div class=\"fw-footer\">\n";
echo "<div class=\"copyright\">\n";
echo "KG6WXC/K6GSE/N2MH software provided as open source\n";
echo "</div>\n";
echo "</div>\n";
echo "</div>\n";

echo "</body>\n";
echo "</html>\n";

/**
 * Display the standard Header and Footer headings
 */
function display_HeaderTitles()
{
    echo "<tr>\n";
    echo "<th>Name</th>\n";
    echo "<th>lat</th>\n";
    echo "<th>lon</th>\n";
    echo "<th>ssid</th>\n";
    echo "<th>model</th>\n";
    echo "<th>mfg</th>\n";
    echo "<th>Version</th>\n";
    echo "<th>Services</th>\n";
    echo "<th>Last Seen</th>\n";
    echo "</tr>\n";
}
