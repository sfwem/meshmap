<?php 
$INCLUDE_DIR = "../..";
$USER_SETTINGS = parse_ini_file($INCLUDE_DIR . "/scripts/user-settings.ini");
include $INCLUDE_DIR . "/scripts/wxc_functions.inc";
@include $INCLUDE_DIR . "/custom.inc";

$sqlSrvStatus;
$arname = "";

$sql_connection = wxc_connectToMySQL();

if (!$sql_connection) {
    $sqlSrvStatus = 0;
}else {
    $sqlSrvStatus = 1;
    $totalNumNodes = mysqli_num_rows(mysqli_query($sql_connection, "SELECT node from node_info"));
    $totalNumNodesWithLocations = mysqli_num_rows(mysqli_query($sql_connection, "SELECT node FROM node_info WHERE lat != '0' AND lon != '0'"));
    $totalNumLinks = mysqli_num_rows(mysqli_query($sql_connection, "SELECT node from topology"));
    $totalNumLinksWithLocations = mysqli_num_rows(mysqli_query($sql_connection, "SELECT node from topology where ((linklat != '0' OR linklat IS NOT NULL) AND (linklon != '0' OR linklon IS NOT NULL)) AND ((nodelat != '0' OR nodelat IS NOT NULL) AND (nodelon != '0' OR nodelon IS NOT NULL));"));
    $totalRemovedNodes = mysqli_num_rows(mysqli_query($sql_connection, "SELECT node from removed_nodes"));
    $totalIgnoredNodes = mysqli_num_rows(mysqli_query($sql_connection, "SELECT ip FROM hosts_ignore"));
    $lastUpdateNodeInfo = wxc_getMySql("SELECT currently_running, table_last_update FROM map_info WHERE id = 'NODEINFO'");
    $lastUpdateLinkInfo = wxc_getMySql("SELECT table_last_update FROM map_info WHERE id = 'LINKINFO'");
    $totalNumNonMeshMarkers = mysqli_num_rows(mysqli_query($sql_connection, "SELECT name from marker_info"));
    mysqli_close($sql_connection);    
}
?>
<!DOCTYPE html>
<html>
<head>
<title>MeshMap Admin Page</title>

<link rel='stylesheet' href='admin.css'>

<!-- extra javascripts and css needed -->
<script src='../javascripts/jquery-3.2.1.js'></script>
<!-- <script type="text/javascript" src="../javascripts/jquery.dataTables.min.js"></script> -->
<!--  <link rel="stylesheet" type="text/css" href="../css/jquery.dataTables.min.css"> -->
<!-- <style type="text/css" class="init"></style> -->

<script>
var arname="<?php $tr_arname=isset($_REQUEST['arname']); echo "$tr_arname"; ?>";
function loadThePage(div_id, script)
{
        $("#"+div_id).html('<center><br><b>Loading...</b><br><?php echo "$tr_arname"; ?></center>');
        if (script == "changeLocation" ) {
        	$.ajax({
                type: "POST",
                url: "update_node_location.php",
                data: "arname="+arname,
                success: function(msg){
                        $("#"+div_id).html(msg);
						$('#nav_link_location').css("background-color", "#dddddd");
						$('#nav_link_non_mesh').css("background-color", "");
						$('#nav_link_removed').css("background-color", "");
						$('#nav_link_report').css("background-color", "");
                	}
        	});
        }
        if (script == "nonMeshStations") {
            $.ajax({
                    type: "POST",
                    url: "non_mesh_stations.php",
                    data: "arname="+arname,
                    success: function(msg){
                        $("#"+div_id).html(msg);
						$('#nav_link_location').css("background-color", "");
						$('#nav_link_non_mesh').css("background-color", "#dddddd");
						$('#nav_link_removed').css("background-color", "");
						$('#nav_link_report').css("background-color", "");
                    }
            });
        }
        if (script == "removedNodes") {
            $.ajax({
                    type: "POST",
                    url: "view_removed_nodes.php",
                    data: "arname="+arname,
                    success: function(msg){
                        $("#"+div_id).html(msg);
						$('#nav_link_location').css("background-color", "");
						$('#nav_link_non_mesh').css("background-color", "");
						$('#nav_link_removed').css("background-color", "#dddddd");
						$('#nav_link_report').css("background-color", "");
                    }
            });
        }
        if (script == "nodeReport") {
            $.ajax({
                    type: "POST",
                    url: "../node_report.php",
                    data: "arname="+arname,
                    success: function(msg){
                        $("#"+div_id).html(msg);
						$('#nav_link_location').css("background-color", "");
						$('#nav_link_non_mesh').css("background-color", "");
						$('#nav_link_removed').css("background-color", "");
						$('#nav_link_report').css("background-color", "#dddddd");
					}
            });
        }
        if (script == "viewMap") {
            $.ajax({
                    type: "POST",
                    url: "../map.php",
                    data: "arname="+arname,
                    success: function(msg){
                            $("#"+div_id).html(msg);
                    }
            });
        }
}
//auto updating values in the "status" area (hopefully)
var db_stats_ajax_call = function() {
		//ajax query code
		$.ajax({
			type: "GET",
			url: "status_updates.php",
			data: "arname="+arname,
			success: function(msg) {
				$("#admin_status").html(msg);
			}
		});
}
var interval = 1000 * 60 * 1; //every 1 minute
setInterval(db_stats_ajax_call, interval);
</script>
</head>
<body>
<div id='admin_wrapper'>

<div id='admin_header'>
<img style="height: 1.5em;" src="../images/WXC.png">
<strong><span class='em1-5Text'><a class='normalTextLink' id='admin_main_link' href=''>Mesh Map Admin Page</a></span></strong>
<span class='emDot5Text'>(beta)</span>
<br>
Running on <?php
echo $_SERVER['HTTP_HOST'] . " ";
?>

<!-- The links at the lower part of the "header" -->
<div class='admin_nav_links' id='admin_nav_links'>
<a id="nav_link_location" href="javascript:onclick=loadThePage('admin_content', 'changeLocation');">Change Node Location</a>
&nbsp;&nbsp;
<a id="nav_link_non_mesh" href="javascript:onclick=loadThePage('admin_content', 'nonMeshStations');">Add Non-Mesh Map Marker</a>
&nbsp;&nbsp;
<a id="nav_link_removed" href="javascript:onclick=loadThePage('admin_content', 'removedNodes');">View Removed Nodes</a>
&nbsp;&nbsp;
<a id="nav_link_report" href="javascript:onclick=loadThePage('admin_content', 'nodeReport');">Node Report</a>
&nbsp;&nbsp;
<!-- <s>View Map</s>(not yet, reload map page to see changes) -->
<!-- <a href="javascript:onclick=loadThePage('admin_content', 'viewMap');">View Map</a> -->
</div> <!-- end admin_nav_links inner div -->

<div id='admin_status'>
<table id="admin_sql_status_table">
<thead>
<tr>
<th colspan="3" class="admin_sql_status_table_background">
<strong>SQL server </strong>
<?php
echo "&nbsp;( " . $USER_SETTINGS['sql_server'] . " ): \n";
if($sqlSrvStatus == 1) {
    echo '<img class="img-valign emDot75Text" src="../images/greenDot.png">' . "\n";
}else {
    echo '<img class="img-valign emDot75Text" src="../images/redDot.png">' . "\n";
}

echo "</th>";
echo "<th class=\"admin_sql_status_table_background\"></th>\n";
echo "<th class=\"admin_sql_status_table_background\"></th>\n";
echo "<th style=\"text-align: right;\" colspan=\"3\" class=\"admin_sql_status_table_background\">\n";
echo "Currently Polling Nodes: ";
if($lastUpdateNodeInfo['currently_running'] == 1) {
    echo '<img class="img-valign emDot75Text" src="../images/greenDot.png">' . "\n";
}else {
    echo '<img class="img-valign emDot75Text" src="../images/redDot.png">' . "\n";
}
echo "</tr>";
echo "</thead>\n";
//echo "<table id='admin_sql_status_table'>";
echo "<tbody>";
echo "<tr>\n";
echo "<td class=\"admin_sql_status_table_background\">Nodes:</td>\n";
echo "<td class=\"admin_sql_status_table_background\">$totalNumNodes</td>\n";
echo "<td class=\"admin_sql_status_table_background\">With Locations:</td>\n";
echo "<td class=\"admin_sql_status_table_background\">$totalNumNodesWithLocations</td>\n";
echo "<td colspan=\"2\" class=\"admin_sql_status_table_background\">Nodes Last Polled:</td>\n";
//echo "<td class=\"admin_sql_status_table_background\">&nbsp;</td>\n";
echo "<td class=\"admin_sql_status_table_background\">" . $lastUpdateNodeInfo['table_last_update'] . "\n";
echo "<td class=\"admin_sql_status_table_background\"></td>\n";
echo "</tr>";
echo "<tr>";
echo "<td class=\"admin_sql_status_table_background\">Links:</td>\n";
echo "<td class=\"admin_sql_status_table_background\">$totalNumLinks</td>\n";
echo "<td class=\"admin_sql_status_table_background\">With Locations:</td>\n";
echo "<td class=\"admin_sql_status_table_background\">$totalNumLinksWithLocations</td>\n";
echo "<td colspan=\"2\" class=\"admin_sql_status_table_background\">Links Last Updated:</td>\n";
//echo "<td class=\"admin_sql_status_table_background\">Updated:</td>\n";
echo "<td class=\"admin_sql_status_table_background\">" . $lastUpdateLinkInfo['table_last_update'] . "\n";
echo "<td class=\"admin_sql_status_table_background\">&nbsp;</td>\n";
echo "</tr>\n";
echo "<tr>\n";
echo "<td class=\"admin_sql_status_table_background\">Expired:</td>\n";
echo "<td style=\"text-align: left;\" class=\"admin_sql_status_table_background\">$totalRemovedNodes</td>\n";
echo "<td style=\"text-align: right;\" class=\"admin_sql_status_table_background\">Ignored:</td>\n";
echo "<td class=\"admin_sql_status_table_background\">$totalIgnoredNodes</td>\n";
//echo "<td class=\"admin_sql_status_table_background\"></td>\n";
//echo "<td class=\"admin_sql_status_table_background\"></td>\n";
echo "<td class=\"admin_sql_status_table_background\">Non Mesh Icons:</td>\n";
echo "<td class=\"admin_sql_status_table_background\">$totalNumNonMeshMarkers</td>\n";
echo "<td colspan=\"2\" style=\"text-align: right;\" class=\"admin_sql_status_table_background\">Parallel Mode: ";
if ($USER_SETTINGS['node_polling_parallel'] == "1") {
    echo "Yes";
}else {
    echo "No";
}
echo "</td>\n";
echo "</tr>\n";
echo "</tbody>";
//echo "</table>";
// echo "<br>Nodes: " . $totalNumNodes . " \n";
// echo "&nbsp;&nbsp;With Locations: " . $totalNumNodesWithLocations . "&nbsp;\n";
// echo "<br>Links: " . $totalNumLinks . "\n";
// echo "&nbsp;&nbsp;With Locations: " . $totalNumLinksWithLocations . "\n";
// echo "<br>Expired: " . $totalRemovedNodes . "\n";
?>
</table>
<!-- <strong>Currently Polling: </strong> -->
</div> <!-- end admin_status inner div -->

</div> <!-- end admin_header div -->

<div id='admin_content'>
<br>
Please use the tabs/links above to navigate to the different sections.
<br>
<br>
Most tables are sortable, just click on the header.
<br>
<br>
Eventually, you should be able to have different users and actually have to login to use most of this.
<br>
<br>
Have fun and let me know any issues or ideas you find.
<br>
(especially any "metrics" you can think of to watch in the "status" area)
<br>
-wxc
</div>

</div> <!-- wrapper close -->
<?php //mysqli_close($sql_connection); ?>
</body>
</html>

