<?php defined('SYSPATH') or die('No direct script access.');

class Tasks
{
	/**
	 * Add a new task to the list.
	 *
	 * @param string $route
	 * @param array $uri
	 * @param bool $fail_on_error
	 * @param int $recurring
	 * @param int $priority
	 * @throws TasksException
	 */
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

	/**
	 * Clear the completed tasks that are not actively recurring.
	 */
	static public function clearCompleted()
	{
		DB::delete(ORM::factory('tasks')->table_name())
			->where('active','=',0)
			->where('running','=',0)
			->where('failed','=',0)
			->where('lastrun','<=', DB::expr("UNIX_TIMESTAMP()-432000"))
			->execute();
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