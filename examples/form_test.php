<?php

require_once "../src/metadata_validator.php";
$dsn = array(
	'user' => 'root',
	'password' => '',
	'host' => 'localhost'
);
$mv	= new metadata_validator($dsn, "test", "example_table");


$is_post = ($_SERVER['REQUEST_METHOD'] == "POST");
$inserted = false;
if($is_post)
{	
	$response 	= $mv->validate($_POST);
	if(empty($response['errors']))
	{
		@mysql_connect('localhost', 'root', '');
		mysql_select_db('test');
		$insert_data = array();
		foreach($response['data'] as $column => $value)
		{
			if(!empty($value))
			{
				$insert_data[$column] = $value;
			}
		}
		$query = sprintf(
			'INSERT INTO test.example_table (%s) VALUES ("%s")', 
			implode(',', array_keys($insert_data)),
			implode('", "', array_values($insert_data))
		);
		mysql_query($query);
	}
}

?>
<html>
<head>
	<title>Forms Example</title>
	<script type="text/javascript" src="http://ajax.googleapis.com/ajax/libs/jquery/1.4.2/jquery.min.js"></script>
	<script type="text/javascript" src="jquery.mv.js"></script>
	<script type="text/javascript">
		$(document).ready(function(){
			$('#test_form').mv_form('<?=$mv->get_all_json_validators()?>')
		});
	</script>
	<style type="text/css">
		body,html{
			font-family:Helvetica,Arial,sans-serif;
			font-size:18px;
		}
		label,input{
			display:block;
		}
		input{
			padding:3px;
			width:40%;
		}

		.error, .notice, .success {
			display:none;
			float:right;
			width:45%;
			border:2px solid #DDDDDD;
			margin-bottom:1em;
			padding:0.8em;
		}
		.success {
			background:none repeat scroll 0 0 #E6EFC2;
			border-color:#C6D880;
			color:#264409;
		}
		.error  {
			background:none repeat scroll 0 0 #FBE3E4;
			border-color:#FBC2C4;
			color:#8A1F11;
		}
	</style>
</head>
<body>
	<!--  BUILD SUCCESS AND FAILURE MESSAGES -->
	
	<div class="error">
		<h3>Your form had the following errors:</h3>
		<ul id="error_list">
		<? if(is_array($response["errors"])):?>	
			<? foreach($response["errors"] as $error): ?>
				<li><?=$error?></li>
			<? endforeach; ?>
		<? endif; ?>
		</ul>
	</div>
	
	<div class="success" <?=($is_post && $inserted) ? 'style="display:block;"' : ''?>>
		<h3>Your form has successfully been submitted</h3>
	</div>

	<!--  END SUCCESS/ERROR MESSAGES -->
	<form id="test_form" action="<?=$_SERVER['REQUEST_URI']?>" method="post">
		<label>id</label>
		<input type="text" name="id" value="<?=@$response['data']['id']?>" />
		<label>event_name</label>
		<input type="text" name="event_name" id="controller" value="<?=@$response['data']['event_name'] ?>" />
		<label>city</label>		
		<input type="text" name="city" value="<?=@$response['data']['city'] ?>" />
		<label>zip</label>
		<input type="text" name="zip" value="<?= @$response['data']['zip']?>" />  	
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
