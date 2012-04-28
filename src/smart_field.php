<?php

class smart_field
{
	private $enum_values = array();
	private $safe_nullables = array("date", "datetime");
	private $field_length = null;
	/**
	*	Should be constructed with (string)col => (array|object)metadata
	*/
	public function __construct($data)
	{
		$data = (array)$data;
		foreach($data as $key => $value)
		{
			$this->$key = $value;
		}
	}
	
	public function is_nullable()
	{
		return ($this->Null == "YES");
	}
	
	public function is_primary()
	{
		return ($this->Key == "PRI");
	}
	
	public function has_default()
	{
		return !empty($this->Default);
	}
	
	public function get_field()
	{
		return $this->Field;
	}
	
	public function get_type()
	{
		return preg_replace('/[^A-Za-z]/', '', $this->Type);
	}
	
	public function get_default()
	{
		if($this->has_default())
		{
			return $this->Default;
		}
		return false;
	}
	
	public function get_json_validators($as_array=false)
	{
		return json_decode($this->Comment, $as_array);
	}
	
	public function get_field_length()
	{
		if(!is_null($this->field_length))
		{
			return $this->field_length;
		}
		return preg_replace("/[^0-9]/", '', $this->get_type());
	}
	
	public function get_enum_values()
	{
		$enum = $this->get_type();
		$valid = array();
		if(strstr($enum, "enum("))
		{
			if(empty($this->enum_values))
			{
				$off  = strpos($enum,"(");
				$enum = substr($enum, $off+1, strlen($enum)-$off-2);
				$values = explode(",",$enum);

				for( $n = 0; $n < count($values); $n++) {
				  	$val = substr( $values[$n], 1, strlen($values[$n])-2);
					$val = str_replace("'","",$val);
					array_push( $this->enum_values, $val );
				}
			}
			return $this->enum_values;
		}
		else
		{
			return null;
		}
	}
	
	public function is_safe_nullable()
	{
		return in_array($this->get_type(), $this->safe_nullables);
	}
	
	public function is_required()
	{
		return (!$this->is_nullable() && !$this->is_primary() && !$this->has_default() && !in_array($this->get_type(), $this->safe_nullables));
	}
	
	public function get_special_null()
	{
		$json = json_decode($this->Comment);
		return ($json->{'special_null'} !== NULL) ? $json->{'special_null'} : "NULL";
	}
	
	public function is_nullable_datetime()
	{
		return ($this->Type == "datetime" && $this->is_nullable());
	}
	
	public function is_datetime()
	{
		$type = $this->get_type();
		return in_array($type, array('datetime', 'date'));
	}
}

?>