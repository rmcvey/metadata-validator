<?php
/**
*	Metadata Validator provides automatic data validation by processing 
*	well formed JSON COMMENTS containing validation rules
*	@author Robert McVey
*/
class metadata_validator
{
	public	  $cols	= array();
	private	  $connection;
	private   $database;
	private   $db;
	private   $host;
	private	  $user;
	private	  $pass;
	private	  $table;
	private	  $errors = array();
	private	  $mode;
	private	  $response;
	private	  $safe_nullables;
	
	/*
	*	Needs to be updated to use passed credentials
	*/
	private function _init()
	{
		$this->user 			= //YOUR USERNAME;
		$this->pass			= //YOUR_PASSWORD;
		$this->host			= //YOUR_HOSTNAME;
		$this->safe_nullables		= array("date", "datetime");
		$this->response			= array('data' => NULL, 'errors' => NULL);
	}
	
	/**
	*	@param mode dictates whether or not nulls are checked and primary
	*/
	public function __construct($database, $table = null, $mode="insert")
	{
		$this->_init();
		$this->db		= $database;
		$this->mode		= $mode;
		
		if($table){
			$this->table = $table;
		}

		try{
			$this->_connect();
		}catch(Exception $e){
			echo "\nError in " . __METHOD__ . ':\n ' 
			. $e->getMessage();
		}
	}
	
	public function __destruct()
	{
		mysql_close($this->connection);
	}
	
	/**
	*	@param $host database host
	*/
	public function set_host($host)
	{
		$this->host = $host;
		try{
			$this->_connect();
		}catch(Exception $e){
			echo $e->getMessage();
		}
		return $this;
	}
	
	private function _connect()
	{
		$this->connection = mysql_connect($this->host, $this->user, $this->pass);
		if(!$this->connection){
			throw new Exception("Could not connect to database server: '{$this->host}'\n");
		}
		$this->database   = mysql_select_db($this->db, $this->connection);
		if(!$this->database){
			throw new Exception("Could not select database: '{$this->db}'\n");
		}
	}
	
	/**
	*	Sets the active database table
	*/
	public function set_table($table)
	{
		$this->table = $table;
		return $this;
	}
	
	/**
	*	@access public
	*	
	*	Does the introspection on the database table
	*/
	public function build_column_map($table=null)
	{
		if($table)
			$this->set_table($table);
		$result = $this->execute("SHOW FULL COLUMNS FROM {$this->table}");
		
		$cols = null;
		if($result)
		{
			$cols = array();
			//important to have an associative array here
			while($row = mysql_fetch_assoc($result)){
				$cols[]=$row;
			}
			$this->cols = $cols;
		}
		else
		{
			//we won't be able to do anything if we don't have the columns
			exit(mysql_error());
		}
		return $this;
	}
	
	/**
	*
	*	@access	public
	*	
	*	Returns an array of database columns and attributes
	*/
	public function get_column_map()
	{
		if(is_array($this->cols))
		{
			return $this->cols;
		}
		else
		{
			$this->cols = $this->build_column_map();
			return $this->cols;
		}
	}
	
	/**
	*	@access public
	*	@param $field associative array database result
	*	@param $as_array decode to object or array, default object
	*	
	*	This works on a per column basis
	*/
	public function get_json_validators($field, $as_array=false)
	{
		return json_decode($field['Comment'], $as_array);
	}


	/**
	*	@access public
	*
	*	This returns the clientside JSON object (adds "required")
	*/
	public function get_all_json_validators()
	{
		$return = array();
		foreach($this->get_column_map() as $column)
		{
			$validators = $this->get_json_validators($column);
			$validators->validators->required = $this->is_required($column);
			$return[] = array($column["Field"], $validators->validators);
		}
		return json_encode($return);
	}
	
	/**
	*	@access private
	*
	*	Let's us treat our cols attribute as a numbered array
	*/
	private function get_column_names()
	{
		$names = array();
		foreach($this->cols as $col)
		{
			$names[$col["Field"]] = NULL;
		}
		return $names;
	}
	
	/**
	*	@access public
	*	
	*	@param $data is an associative array of values, the key being the name of the column
	*	@return $result = array("data" => processed_data, "errors" => array( array of generated errors ))
	*
	*	Uses the standard metadata to provide validation and processes metadata validators
	* 	Works by processing the differing items between the columns and the passed data, then the similar items
	*/
	public function validate($data)
	{
		$size = sizeof($data);
		if(!$this->cols)
		{
			$this->build_column_map();
		}	
		if($size < $this->num_nullable_columns() && $this->mode != "insert")
		{
			$this->response['errors'] []= $this->get_col_count_error($size);
			return $this->response;
		}
		else
		{	
			$column_names 	= $this->get_column_names();
			$col_keys	= array_keys( $column_names );
			$data_keys	= array_keys( $data );
			$differences	= array_diff( $col_keys, $data_keys );
			$intersections	= array_intersect( $col_keys, $data_keys);
			
			/**
			*	Process Differences (these are not present in the passed data, but are columns). 
			*	We really only care if the db columns are nullable
			*/
			foreach($differences as $index => $item)
			{
				$field = $this->cols[$index];
				if(!$this->is_nullable($field) && !$this->is_primary($field) && !$this->has_default($field))
				{
					$this->response["errors"] []= $this->get_not_nullable_error($item);
				}
			}
			
			/**
			*	Process Intersections
			*/
			foreach($intersections as $index => $item)
			{
				$row_value  	= $data[$item];
				$col		= $this->cols[$index];
				$col_type 	= $this->get_field_type($col);
				$rules 		= $this->get_json_validators($col);
				$field_length 	= mb_strlen((string) $field, '8bit');
				$max_length 	= $this->get_field_length( $col );
				
				//produces an is_* function using a datatype map between mysql and php
				$php_type 		= $this->mysql_type_to_php($col_type);
				$dynamic_is_type_func 	= "is_$php_type";
				$value_type		= gettype($row_value);
				
				/**
				*	These validations have been broken down into small units of work so they can 
				*	be unit tested
				*/

				//Error if data is empty, not nullable, no default exists and it is not time
				$this->check_empty_not_nullable_no_default_and_not_time($row_value, $col, $data);
				
				//Error if data type different from column type and not primary key (could be null, would fail), or that it's the same type as the primary key
				$this->check_istype_if_not_primary_xor_empty($col, $value_type, $col_type, $row_value, $dynamic_is_type_func);
				
				//Check lengths against column maximums
				$this->check_length_less_than_colmax($max_length, $field_length, $col, $col_type);
				
				//Formats passed date into mysql format
				$this->check_datetime_formatting($col, $row_value);
				
				//Checks against array of valid options in enum row
				$this->check_enumerable($row_value, $col);
				
				$data[$item] = $row_value;

				//If special column validation metadata exists, check data against it
				if(is_object($rules))
				{
					$data[$item] = $this->process_json_validators($col, $row_value, $rules);				
				}
			}	
		}
		$this->response['data'] = $data;
		return $this->response;
	}
	
	/**
	*	If the field is datetime, check that it is formatted correctly
	*/
	private function check_datetime_formatting($col, &$row_value)
	{
		if($this->is_datetime($col) && !empty($row_value) && !preg_match("/^\d{4}-\d{2}-\d{2} [0-2][0-3]:[0-5][0-9]:[0-5][0-9]$/", $row_value))
		{
			//converts a formatted date to mysql format
			$fixed_time = date("Y-m-d H:i:s", strtotime($row_value));
			if(preg_match("/^\d{4}-\d{2}-\d{2} [0-2][0-3]:[0-5][0-9]:[0-5][0-9]$/", $fixed_time))
			{
				$this->response['errors'] []= $this->get_datetime_formatting_error($col["Field"], $row_value, $fixed_time);
			}
			$row_value = $fixed_time;
		}
	}
	
	private function check_enumerable($row_value, $col)
	{
		$valid = $this->get_enum_values($col);
		if($valid)
		{
			if(!in_array("'$row_value'", $valid))
			{
				$this->response['errors'][] = $this->get_enum_error($row_value, $valid, $col["Field"]);
			}
		}
	}
	
	/**
	*	Check that the length of value is less than the column max ( e.g. VARCHAR(128) )
	*/
	private function check_length_less_than_colmax($max_length, $field_length, $col, $col_type)
	{
		if($max_length && $field_length > $max_length && !in_array($col_type, $safe_nullables))
		{
			$this->response['errors'] []= $this->get_length_error($field_length, $max_length, $col["Field"] );
		}
	}
	
	/**
	*	If a field is not nullable, it contains no default value and it is not time (which can receive CURRENT_TIMESTAMP)
	* 	throw a not nullable error.
	*	Otherwise if the field is not empty and it is one of time types (date, datetime, timestamp) and give it a date value
	*/
	private function check_empty_not_nullable_no_default_and_not_time(&$row_value, $col, &$data)
	{
		if(empty($row_value) && !($this->is_nullable($col)) && !in_array($col_type, $this->safe_nullables) && !$this->has_default($col) && !$this->is_primary($col))
		{
			$this->response["errors"] []= $this->get_not_nullable_error($col["Field"]);
		}
		else if(empty($row_value) && in_array($col_type, $this->safe_nullables))
		{
			$data[$col["Field"]] = date("Y-m-d H:i:s");
		}
	}
	
	/**
	*	Run is_*() against row value if it is not primary or empty
	*
	*/
	private function check_istype_if_not_primary_xor_empty($col, $value_type, $col_type, $row_value, $dynamic_is_type_func)
	{
		if(is_callable($dynamic_is_type_func) && !$dynamic_is_type_func($row_value) && (!$this->is_primary($col) ^ !empty($row_value)))
		{			
			$this->response['errors'] []= $this->get_datatype_mismatch_error($col["Field"], $value_type, $col_type, $row_value);
		}
	}
	
	/**
	*	Iterate over JSON data validators and run value through transforms
	*/
	protected function process_json_validators($column, $row_value, $validators)
	{
		$checks = $validators->validators;
		$pattern = $checks->pattern;
		if($pattern)
		{
			if(is_object($pattern))
			{
				foreach($pattern as $regex)
				{
					if(preg_match("/$regex/", $row_value))
					{
						$this->response['errors'] []= $this->error("Field " . $column["Field"] . ": $row_value matches a user defined pattern /$regex/");
					}
				}
			}else{
				if(preg_match("/$pattern/", $row_value))
				{
					$this->response['errors'] []= $this->error("Field " . $column["Field"] . ": $row_value matches a user defined pattern /$pattern/");
				}
			}
		}
		if($checks->minlength)
		{
			if(strlen($row_value) < $checks->minlength)
			{
				$this->response['errors'] []= $this->error("Field: " . $column["Field"] . ": $row_value is too short, must be at least ".$checks->minlength . " long [USER_DEFINED]");
			}
		}
		if($checks->maxlength)
		{
			if(strlen($row_value) > $checks->maxlength)
			{
				$this->response['errors'] []= $this->error("Field: " . $column["Field"] . ": $row_value is too long, must be less than ".$checks->maxlength . " long [USER_DEFINED]");
			}
		}
		/**
		*	Process transform functions
		*/
		if($validators->funcs)
		{
			if(is_object($validators->funcs))
			{
				$functions = $validators->funcs;
				foreach($functions as $function)
				{
					$row_value = $this->exec_transform_function($function, $row_value);
				}
			}
		}
		return $row_value;
	}
	
	/**
	*	Dynamic function caller from JSON object, will recursively call itself if a parameters is a function
	*	@param $function Function object {"func":{"n":"FUNC_NAME","params":{"p1":"Hello","p2":{"func":{"n":"FUNC_AS_PARAM","params":{"p1":"World"}}}}}}
	*	@param $row_value Value to be transformed
	*/
	protected function exec_transform_function($function, &$row_value)
	{
		//Recursively call this method against params that are functions
		$function_name  = $function->n;
		$params			= $function->params;
		$parameters  = array();
		
		foreach($params as $param)
		{
			//Handle functions as parameters, useful for date() functions and string mutators
			if($param->func)
			{
				$recursed_value = $this->exec_transform_function($param->func, $row_value);
				array_push($parameters, $recursed_value);
				continue;
			}
			//Replaces a self reference with the value of data
			if($param == '@this')
			{
				$param = $row_value;
			}
			//add stored or computed parameter to function parameters
			array_push($parameters, $param);		
		}
		if(is_callable($function_name))
		{
			if(sizeof($parameters) > 0)
			{
				$row_value = call_user_func_array($function_name, $parameters);
			}
			else
			{
				$row_value = call_user_func($function_name);
			}
		}
		return $row_value;
	}
	
	protected function get_enum_values($col)
	{
		$enum = $col["Type"];
		$valid = array();
		if(strstr($enum, "enum("))
		{
			$off  = strpos($enum,"(");
			$enum = substr($enum, $off+1, strlen($enum)-$off-2);
			$values = explode(",",$enum);

			for( $n = 0; $n < count($values); $n++) {
			  	$val = substr( $values[$n], 1, strlen($values[$n])-2);
				$val = str_replace("'","",$val);
				array_push( $valid, $val );
			}
			return $values;
		}
		else
		{
			return null;
		}
	}
	
	/**
	*	parse the max data length from the field type
	*/
	protected function get_field_length($field)
	{
		return preg_replace("/[^0-9]/", '', $field["Type"]);
	}
	
	/**
	*	parse data type (e.g. removes (255) from int(255) )
	*/
	protected function get_field_type($field)
	{
		return preg_replace("/[^A-Za-z]/", "", trim($field["Type"]));
	}
	
	/**
	*	Maps native mysql data types to php readable types
	*/
	protected function mysql_type_to_php($mysql_type)
	{
		$type_map = array(
			'int'		=> 'numeric',
			'bigint'	=> 'numeric',
			'double'	=> 'numeric',
			'bit'		=> 'bool',
			'varchar' 	=> 'string',
			'char'		=> 'string',
			'enum'		=> 'array'
		);
		return $type_map[$mysql_type];
	}
	protected function num_nullable_columns()
	{
		$count = 0;
		foreach($this->cols as $col)
		{
			if($this->is_nullable($col))
				$count++;
		}
		return $count;
	}
	
	protected function is_required($col)
	{
		return (!$this->is_nullable($col) && !$this->is_primary($col) && !$this->has_default($col) && !in_array($col["Type"], $this->safe_nullables));
	}
	
	/**
	*	Retrieves a special null value stored in column comment
	*/
	protected function get_column_special_null($column)
	{
		$json = json_decode($column["Comment"]);
		return ($json->{'special_null'} !== NULL) ? $json->{'special_null'} : "NULL";
	}
	
	/**
	*	Retrieves a special global null value stored in table comment
	*/
	protected function get_table_special_null()
	{
		$sql = "SHOW CREATE TABLE `{$this->table}`;";
		$result = $this->execute($sql);
		$show = mysql_result($result, 0, 1);
		if($show) 
		{
			$position = strpos($show,"COMMENT=");
			if(!$position) 
			{
				return str_replace("'","", str_replace('"', "", substr($show, $position + 8)));
			}
		}
		return 'NULL';
	}
	
	public function is_nullable($column)
	{
		return ($column["Null"] == "YES");
	}
	
	public function is_primary($column)
	{
		return ($column["Key"] == "PRI");
	}
	
	public function has_default($column)
	{
		return !empty($column["Default"]);
	}
	
	public function is_nullable_datetime($column)
	{
		return ($column["Type"] == "datetime" && $column['Null'] == "YES");
	}
	
	public function is_datetime($column)
	{
		return ($column["Type"] == "datetime" || $column["Type"] == "date");
	}
	
	public function get_errors()
	{
		return $this->response['errors'];
	}
	
	protected function error($message)
	{
		return $this->err_prefix() . $message;
	}
	
	protected function get_col_count_error($col_count)
	{
		return $this->error("There are too few columns present ($col_count), the minimum is " . $this->num_nullable_columns());
	}
	
	protected function get_enum_error($value, $valid, $field)
	{
		return $this->error("Field $field has an invalid value: $value; the valid options are (".implode(",",$valid).")");
	}
	
	protected function get_not_nullable_error( $field )
	{
		return $this->error("Field $field cannot be null");
	}
	
	protected function get_datatype_mismatch_error( $field, $supplied_type, $expected_type, $value )
	{
		return $this->error("Field $field expects type $expected_type, you gave $supplied_type with value - $value");
	}
	
	protected function get_datetime_formatting_error( $field, $supplied_value, $fixed_time)
	{
		return $this->error("The date in $field is incorrectly formatted, $supplied_value will be converted to $fixed_time");
	}
	
	protected function get_length_error( $value, $max_length, $field )
	{
		if(is_string($value))
		{
			return $this->error("Field $field has a maximum length of $max_length, the size of $field is " . strlen($value));
		}
		else if(is_int($value))
		{
			return $this->error("Field $field has a maximum length of $max_length, the size of $field is " . $value);
		}
	}
	
	/**
	*	Prefix for all errors, can be set if you are using this with CSV and want to feed a line number
	*/
	protected function err_prefix()
	{
		return "";
		#return "Error on line {$this->row_num}: ";
	}	
	
	public function db_error()
	{
		return mysql_error();
	}
	
	public function execute($query)
	{
		return mysql_query($query);
	}
	
}

?>