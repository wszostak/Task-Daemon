<?php defined('SYSPATH') or die('No direct script access.');

class Model_Tasks extends ORM
{
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

	public function ran($error=false, $err_msg=null)
	{
		// Error occured.
		if($error)
		{
			$this->failed = DB::expr("UNIX_TIMESTAMP()");
			$this->failed_msg = $err_msg;

			// Check to see if we need to kill this task on error, otherwise it will continue to try to run.
			if($this->fail_on_error == 1)
			{
				$this->active = 0; // deactivate
			}
		}

		// We completed successfully so lets see what we need to do
		if($this->active == 1)
		{
			// We have a recurring task so we need to reset it.
			if($this->recurring > 0)
			{
				$this->nextrun = DB::expr("UNIX_TIMESTAMP() + {$this->recurring}");
			}
			else // Single task so mark as completed.
			{
				$this->active = 0; // deactivate
			}
		}

		// Task is no longer running.
		$this->running = 0;

		// We finished this right now
		$this->lastrun = DB::expr("UNIX_TIMESTAMP()");

		return $this->save();
	}

	public function cleanup()
	{
		// @todo: add in cleanup
	}
}