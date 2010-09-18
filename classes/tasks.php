<?php defined('SYSPATH') or die('No direct script access.');

class Tasks
{
	static public function add($route, Array $uri=null, $fail_on_error=true, $recurring=0, $priority=5)
	{
		try {
			// Lets add the task
			$task = ORM::factory('tasks');

			$task->route = $route;
			$task->uri = $uri;
			$task->recurring = $recurring;
			$task->priority = $priority;
			$task->fail_on_error = (bool)(int)$fail_on_error;

			$task->nextrun = DB::expr("UNIX_TIMESTAMP()");

			// Save the task
			$task->save();

			return true;

		}
		catch (Database_Exception $e)
		{
			throw new TasksException($e->getMessage(), $e->getCode());
			return false;
		}
	}
}

/**
 * Thrown when an exception happens.
 */
class TasksException extends Exception
{
	public function __construct($message, $code=0)
	{
		return parent::__construct('TaskDaemon: '. $message, $code);
	}
}