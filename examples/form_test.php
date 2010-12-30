<?php

require_once "../../lib/php/metadata_validator.php";
//initialize object with database and table names
$mv	= new metadata_validator("test", "example_table");

if(isset($_POST) && !empty($_POST))
{	
	//call validate against posted data
	$response 	= $mv->validate($_POST);
}
?>
<html>
<head>
	<title>Forms Example</title>
	<link rel="stylesheet" type="text/css" href="/css/mv.css" />
	<script type="text/javascript" src="http://ajax.googleapis.com/ajax/libs/jquery/1.4.2/jquery.min.js"></script>
	<script type="text/javascript" src="mv.jquery.js"></script>
	<script type="text/javascript">
		$(document).ready(function(){
			//look at that! our validators work on the client-side too
			$('#test_form').mv_form('<?=$mv->get_all_json_validators()?>')
		});
	</script>
</head>
<body>
	<!--  BUILD SUCCESS AND FAILURE MESSAGES -->
	
	<div class="error" style="<? if(is_array($response["errors"])):?>display:block<?else:?>display:none<?endif?>">
		<h3>Your form had the following errors:</h3>
		<ul id="error_list">
		<? if(is_array($response["errors"])):?>	
			<? foreach($response["errors"] as $error): ?>
				<li><?=$error?></li>
			<? endforeach; ?>
		<? endif; ?>
		</ul>
	</div>
	
	<div class="success" style="<? if(isset($_POST) && !is_array($response["errors"])):?> display:block <?else:?> display:none <?endif?>">
		<h3>Your form has successfully been submitted</h3>
	</div>

	<!--  END SUCCESS/ERROR MESSAGES -->
	<form id="test_form" action="<?=$_SERVER['REQUEST_URI']?>" method="post">
		<label>id</label>
		<input type="text" name="id" value="<?=@$response['data']['id']?>" />
		<label>controller</label>
		<input type="text" name="controller" id="controller" value="<?=@$response['data']['controller'] ?>" />
		<label>action</label>		
		<input type="text" name="action" value="<?=@$response['data']['action'] ?>" />
		<label>site</label>
		<input type="text" name="site" value="<?= @$response['data']['site']?>" />  	
		<label>date_start</label>
		<input type="text" name="date_start" value="<?= @$response['data']['date_start']?>" />
		<label>date_end</label>
		<input type="text" name="date_end" value="<?= @$response['data']['date_end']?>" />
		<label>entered_via</label>
		<input type="text" name="entered_via" value="<?=@$response['data']['entered_via'] ?>" />
		<input type="submit" />
	</form> 
</body>
</html>
