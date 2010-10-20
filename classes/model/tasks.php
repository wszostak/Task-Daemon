<?php defined('SYSPATH') or die('No direct script access.');

class Model_Tasks extends ORM
{
	protected $_db = 'default';
	protected $_table_name  = 'tasks';
	protected $_primary_key = 'task_id';

	protected $_created_column = array('column' => 'created', 'format' => TRUE);

	protected $_serialize_column = array('uri', 'args');

	/**
	 * Time to keep finished tasks in the table before deleting them.
	 *
	 * @var int
	 */
	protected $time_delete_finished = 604800; // 7 days

	public function __get($column)
	{
		if(in_array($column, $this->_serialize_column))
		{
			return json_decode(parent::__get($column), true);
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
}