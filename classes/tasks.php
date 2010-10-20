<?php defined('SYSPATH') or die('No direct script access.');

class Tasks
{
	/**
	 * The database connection to use.
	 *
	 * @var string
	 */
	static public $_db = 'default';

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

	static public function clearFailed()
	{
		// @todo: Add code here.
	}

	/**
	 * Get the next task waiting and if you find one set it to run.
	 */
	static public function getNextTask()
	{
		$db = self::openDB();

		$task = ORM::factory('tasks')
			->where('active', '=', '1')
			->where('running', '=', '0')
			->where('nextrun', '<=', time())
			->order_by('priority', 'DESC')
			->order_by('nextrun', 'ASC')
			->limit(1)
			->find();

		if($task->loaded())
		{
			// We are going to run this one so lets flag it as running.
			$task->running = 1;
			$task->save();
		}
		else
		{
			$task = false;
		}

		self::closeDB();
		unset($db);

		return $task;
	}

	/**
	 * Flag a task as run. Also will decide if the task so be rerun or fail.
	 *
	 * @param int $task_id
	 * @param bool $error
	 * @param string $err_msg
	 */
	static public function ranTask($task_id, $error=false, $err_msg=null)
	{
		$db = self::openDB();

		$task = ORM::factory('tasks', $task_id);

		// Error occured.
		if($error)
		{
			$task->failed = DB::expr("UNIX_TIMESTAMP()");
			$task->failed_msg = $err_msg;

			// Check to see if we need to kill this task on error, otherwise it will continue to try to run.
			if($task->fail_on_error == 1)
			{
				$task->active = 0; // deactivate
			}
		}

		// We completed successfully so lets see what we need to do
		if($task->active == 1)
		{
			// We have a recurring task so we need to reset it.
			if($task->recurring > 0)
			{
				$task->nextrun = DB::expr("UNIX_TIMESTAMP() + {$task->recurring}");
			}
			else // Single task so mark as completed.
			{
				$task->active = 0; // deactivate
			}
		}

		// Task is no longer running.
		$task->running = 0;

		// We finished this right now
		$task->lastrun = DB::expr("UNIX_TIMESTAMP()");

		// Save the changes to the task.
		$res = $task->save();

		self::closeDB();
		unset($db);

		return $res;
	}

	/**
	 * Open and return a database connection.
	 */
	static public function openDB()
	{
		return Database::instance(self::$_db);
	}

	/**
	 * Close all the database connections
	 */
	static public function closeDB()
	{
		foreach(Database::$instances as $db)
		{
			$db->disconnect();
		}

		unset($db);

		Database::$instances = array();
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