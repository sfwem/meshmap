<?php
session_start();
if (!isset($_SESSION['userLoggedIn'])) {
	echo "You are not logged in!<br>\n";
	echo "This page should be run from within the admin interface!\n";
	exit;
}else {
$html = <<< EOD
<br>
<strong>Other Admin Tasks</strong>
<br>
<br>
<a href="export_mesh_nodes.php">Download CSV file of the node database.</a>
<br>
<br>
<script>
var arname="";
function other_loadThePage(div_id, script) {
	if (script == "fixPolling" ) {
		$.ajax({
			type: "POST",
			url: "fixStuckPolling.php",
			data: "arname="+arname,
			success: function(msg){
				alert('Polling should now resume.');
			}
		});
	}
}

$("#manualNodeExpire").submit(function(event) {
	event.preventDefault();
	
	var form = $(this),
 	which = form.find("input[type='submit'][name='submitExpireNodes']").val(),
	number = form.find("input[type='number'][name='daysSincePolled']").val(),
	url = form.attr("action");
	postData = new FormData(this);
	
	postData.append("daysSincePolled", number);
	postData.append("submitExpireNodes", which);

//	var posting = $.post(url, { submitNonMeshCSV: which, csvFile: postData } );
//	posting.done(function(data) { $("#admin_content").html(data); } );

	var posting = $.post({
			url: url,
			method: "POST",
			data: postData,
			contentType: false,
			processData: false,
			success: function(data) {
				$("#admin_content").html(data);
				}
			});

});

</script>
<a href="javascript:onclick=other_loadThePage('admin_content','fixPolling');">Fix stuck polling run.</a>
<br>
(It should reset itself after 3 * node_polling_interval, but you can manually do it here also.)
<br>
<!-- <a href="javascript:onclick=other_loadThePage('admin_content','manuallyRemoveNodes');">Manually expire nodes.</a> -->
EOD;

	echo $html . "\n";

	echo "<br>\n";
	//echo "<form action='otherAdmin.php' id='manualNodeExpire' enctype='multipart/form-data' method='POST'>\n";
	echo "<form action='otherAdmin.php' id='manualNodeExpire' enctype='multipart/form-data' method='POST'>\n";
	echo "Manually expire nodes that have not been polled in: \n";
	echo "<input type='number' size='4' name='daysSincePolled' id='daysSincePolled'> days.&nbsp;&nbsp;\n";
	echo "<input type='submit' value='Expire Nodes' id='submitExpireNodes' name='submitExpireNodes'></form>\n";
	
	if ((isset($_POST['submitExpireNodes']) == "Expire Nodes") && isset($_POST['daysSincePolled'])) {
		$INCLUDE_DIR = "../..";
		$USER_SETTINGS = parse_ini_file($INCLUDE_DIR . "/scripts/user-settings.ini");
		require $INCLUDE_DIR . "/scripts/wxc_functions.inc";
		@include $INCLUDE_DIR . "/custom.inc";
		$sql_connection = wxc_connectToMySQL();
		
		$daysSincePolled = $_POST['daysSincePolled'];
		$addedToSql = wxc_putMySQL("DELETE from node_info WHERE last_seen < DATE_SUB(NOW(), INTERVAL " . $daysSincePolled . " DAY)");
		if ($addedToSql = 1) {
			$expired = mysqli_affected_rows($sql_connection);
			echo "<script>alert('Expired " . $expired . " Nodes.')</script>\n";
			$_POST = array();
		}
	}
}

?>
