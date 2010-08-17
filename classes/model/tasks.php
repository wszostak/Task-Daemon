<?php defined('SYSPATH') or die('No direct script access.');

class Model_Tasks extends ORM
{
	protected $_table_name  = 'tasks';
	protected $_primary_key = 'task_id';

	protected $_created_column = array('column' => 'created', 'format' => TRUE);

	protected $_serialize_column = array('uri', 'data');

	public function __get($column)
	{
		if(in_array($column, $this->_serialize_column))
		{
			return json_decode(parent::__get($column));
		}

		return parent::__get($column);
	}

	public function __set($column, $value)
	{
		if(in_array($column, $this->_serialize_column))
		{
			$value = json_encode($value);
		}

		parent::__set($column, $value);
	}

	public function ran($error=false, $err_msg=null)
	{
		$this->lastrun = new Database_Expression("UNIX_TIMESTAMP()");

		// Error occured.
		if($error)
		{
			$this->failed = new Database_Expression("UNIX_TIMESTAMP()");
			$this->failed_msg = $err_msg;
		}

		// We have a recurring task so we need to reset it.
		if($this->recurring > 0)
		{
			$this->running = 0;
			$this->nextrun = new Database_Expression("UNIX_TIMESTAMP() + {$this->recurring}");
		}

		return $this->save();
	}
}