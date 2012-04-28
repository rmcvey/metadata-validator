<?php
require 'smart_field.php';
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
	private function _init($credentials)
	{
		$this->user			= $credentials['user'];
		$this->pass			= $credentials['password'];
		$this->host			= $credentials['host'];
		$this->safe_nullables	= array("date", "datetime");
		$this->response			= array('data' => NULL, 'errors' => NULL);
	}
	
	/**
	*	@param mode dictates whether or not nulls are checked and primary
	*/
	public function __construct($credentials, $database, $table = null, $mode="insert")
	{
		$this->_init($credentials);
		$this->db		= $database;
		$this->mode		= $mode;
		
		if($table){
			$this->table = $table;
		}

		try{
			$this->_connect();
		}catch(Exception $e){
			trigger_error("\nError in " . __METHOD__ . ': ' . $e->getMessage());
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
			trigger_error("\nError in " . __METHOD__ . ': ' . $e->getMessage());
		}
		return $this;
	}
	
	private function _connect()
	{
		$this->connection = @mysql_connect($this->host, $this->user, $this->pass);
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
		if(!is_null($table))
		{
			$this->set_table($table);
		}
		$result = $this->execute("SHOW FULL COLUMNS FROM {$this->table}");
		
		if($result)
		{
			$cols = array();
			//important to have an associative array here
			while($row = mysql_fetch_assoc($result)){
				$cols[] = new smart_field($row);
			}
			$this->cols = $cols;
		}
		else
		{
			//we won't be able to do anything if we don't have the columns
			trigger_error("MYSQL ERROR, unable to continue: " . mysql_error());
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
		if(is_array($this->cols) && !empty($this->cols))
		{
			return $this->cols;
		}
		else
		{
			$this->build_column_map();
			return $this->cols;
		}
	}

	/**
	*	@access public
	*
	*	This returns the full JSON validators object (adds "required"), useful for passing to javascript
	*/
	public function get_all_json_validators()
	{
		$return = array();
		$col_map = $this->get_column_map();
		foreach($col_map as $column)
		{
			$validators = $column->get_json_validators();
			$validators->validators->required = ($column->is_required() && !$column->is_primary());
			$return[] = array($column->get_type(), $validators->validators);
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
		foreach($this->cols as $column)
		{
			$names[$column->get_field()] = NULL;
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
		$size = count($data);
		if(empty($this->cols))
		{
			$this->build_column_map();
		}
		// checking that number of columns isn't less than our number of nullable columns (required columns) if we are inserting
		if($size < $this->num_nullable_columns() && $this->mode != "insert")
		{
			$this->response['errors'] []= $this->get_col_count_error($size);
			return $this->response;
		}
		else
		{	
			$column_names 	= $this->get_column_names();
			$col_keys		= array_keys( $column_names );
			$data_keys		= array_keys( $data );
			$differences	= array_diff( $col_keys, $data_keys );
			$intersections	= array_intersect( $col_keys, $data_keys);
			
			/**
			*	Process Differences (these are not present in the passed data, but are columns). 
			*	We really only care if the db columns are nullable
			*/
			foreach($differences as $index => $item)
			{
				$field = $this->cols[$index];
				if(!$field->is_nullable() && !$field->is_primary() && !$field->has_default())
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
				$column			= $this->cols[$index];
				$rules 			= $column->get_json_validators();
				$field_length 	= mb_strlen((string) $column->get_field(), '8bit');
				$max_length 	= $column->get_field_length();
				
				//produces an is_* function using a datatype map between mysql and php
				$php_type 		= $this->mysql_type_to_php($column->get_type());
				$dynamic_is_type_func = "is_$php_type";
				$value_type		= gettype($row_value);
				
				/**
				*	These validations have been broken down into small units of work so they can 
				*	be unit tested
				*/
				//Error if data is empty, not nullable, no default exists and it is not time
				$this->check_empty_not_nullable_no_default_and_not_time($row_value, $column, $data);
				
				//Error if data type different from column type and not primary key (could be null, would fail), or that it's the same type as the primary key
				$this->check_istype_if_not_primary_xor_empty($column, $value_type, $row_value, $dynamic_is_type_func);
				
				//Check lengths against column maximums
				$this->check_length_less_than_colmax($max_length, $field_length, $column);
				
				//Formats passed date into mysql format
				$this->check_datetime_formatting($column, $row_value);
				
				//Checks against array of valid options in enum row
				$this->check_enumerable($row_value, $column);
				
				$data[$item] = $row_value;

				//If special column validation metadata exists, check data against it
				if(is_object($rules))
				{
					$data[$item] = $this->process_json_validators($column, $row_value, $rules);				
				}
			}	
		}
		$this->response['data'] = $data;
		return $this->response;
	}
	
	/**
	*	If the field is datetime, check that it is formatted correctly
	*/
	private function check_datetime_formatting($column, &$row_value)
	{
		if($column->is_datetime() && !empty($row_value) && !preg_match("/^\d{4}-\d{2}-\d{2} [0-2][0-3]:[0-5][0-9]:[0-5][0-9]$/", $row_value))
		{
			//converts a formatted date to mysql format
			$fixed_time = date("Y-m-d H:i:s", strtotime($row_value));
			if(preg_match("/^\d{4}-\d{2}-\d{2} [0-2][0-3]:[0-5][0-9]:[0-5][0-9]$/", $fixed_time))
			{
				$this->response['errors'] []= $this->get_datetime_formatting_error($column->get_field(), $row_value, $fixed_time);
			}
			$row_value = $fixed_time;
		}
	}
	
	private function check_enumerable($row_value, $column)
	{
		$valid = $column->get_enum_values();
		if($valid)
		{
			if(!in_array("'$row_value'", $valid))
			{
				$this->response['errors'][] = $this->get_enum_error($row_value, $valid, $column->get_field());
			}
		}
	}
	
	/**
	*	Check that the length of value is less than the column max ( e.g. VARCHAR(128) )
	*/
	private function check_length_less_than_colmax($max_length, $field_length, $column)
	{
		if($max_length && $field_length > $max_length && !$column->is_safe_nullable())
		{
			$this->response['errors'] []= $this->get_length_error($field_length, $max_length, $column->get_field());
		}
	}
	
	/**
	*	If a field is not nullable, it contains no default value and it is not time (which can receive CURRENT_TIMESTAMP)
	* 	throw a not nullable error.
	*	Otherwise if the field is not empty and it is one of time types (date, datetime, timestamp) and give it a date value
	*/
	private function check_empty_not_nullable_no_default_and_not_time(&$row_value, $column, &$data)
	{
		if(empty($row_value) && !($column->is_nullable()) && !$column->is_safe_nullable() && !$column->has_default() && !$column->is_primary())
		{
			$this->response["errors"] []= $this->get_not_nullable_error($col["Field"]);
		}
		else if(empty($row_value) && $column->is_safe_nullable())
		{
			$data[$column->get_field()] = date("Y-m-d H:i:s");
		}
	}
	
	/**
	*	Run is_*() against row value if it is not primary or empty
	*
	*/
	private function check_istype_if_not_primary_xor_empty($column, $value_type, $row_value, $dynamic_is_type_func)
	{
		if(is_callable($dynamic_is_type_func) && !$dynamic_is_type_func($row_value) && (!$column->is_primary() ^ !empty($row_value)))
		{			
			$this->response['errors'] []= $this->get_datatype_mismatch_error($column->get_field(), $value_type, $column->get_type(), $row_value);
		}
	}
	
	/**
	*	Iterate over JSON data validators and run value through transforms
	*/
	protected function process_json_validators($column, $row_value, $validators)
	{
		if(property_exists($validators, 'validators'))
		{
			$checks = $validators->validators;
			$pattern = $checks->pattern;
		}
		else
		{
			$checks = $pattern = null;
		}
		
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
		
		if($checks)
		{
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
			if(property_exists($param, 'func'))
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
			'enum'		=> 'array',
			'datetime'  => 'string'
		);
		return @$type_map[$mysql_type];
	}
	
	protected function num_nullable_columns()
	{
		$count = 0;
		foreach($this->cols as $column)
		{
			if($column->is_nullable())
			{
				$count++;
			}
		}
		return $count;
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