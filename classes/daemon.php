<?php
/**
 * Daemon class
 *
 * Provides the basics for launching daemons from the controller in cli mode.
 *
 * Require:
 * - PHP 5.3.0+
 * - PHP PCNTL (http://www.php.net/manual/en/book.pcntl.php)
 *
 * This file is part of TaskDaemon.
 *
 * TaskDaemon is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * TaskDaemon is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with TaskDaemon.  If not, see <http://www.gnu.org/licenses/>.
 */
class Daemon
{
	static public $config = null;

	static public function launch()
	{
		// Get a new instance
		$inst = new self();

		// Now lets set some stuff
		$inst->_config = self::$config;

		// Now run the daemon
		$inst->run();
	}

	/**
	 * Holds the loaded config information.
	 *
	 * @var array
	 */
	public $_config = array();

	/**
	 * Flag to termainate the daemon process.
	 *
	 * @var bool
	 */
	protected $_sigterm = false;

	/**
	 * Array of childern currently running
	 *
	 * @var array
	 */
	protected $_pids = array();


	public function __construct()
	{
		// Setup
		ini_set("max_execution_time", "0");
		ini_set("max_input_time", "0");
		set_time_limit(0);

		// Signal handler
		pcntl_signal(SIGCHLD, array($this, 'sig_handler'));
		pcntl_signal(SIGTERM, array($this, 'sig_handler'));

		// Disable any errors from coming into the echo.
		ini_set('display_errors', 'off');
		ini_set('log_errors', 'on');
		error_reporting(E_ALL);

		// Start buffering
		ob_start();
	}

	public function run()
	{
		// At first start lets sleep.
		sleep(3);

		try {
			// Loop until we are told to die.
			while (!$this->_sigterm)
			{
				// Dispatch any signals.
				pcntl_signal_dispatch();

				// See if we are within our defined child limits.
				if(count($this->_pids) >= $this->_config['max'])
				{
					// Let's sleep on it.
					usleep($this->_config['sleep']);
					continue; // Restart.
				}

				// Lets get the next task
				if(($task = Tasks::getNextTask()) !== false)
				{
					// Write log to prevent memory issues
					Kohana::$log->write();

					// Fork process to execute task
					$pid = pcntl_fork();

					if ($pid == -1) // We failed, hard
					{
						Kohana::$log->add(Kohana::ERROR, 'TaskDaemon: Could not spawn child task process.');
						exit(1);
					}
					elseif ($pid) // Parent so add the pid to the list
					{
						// Parent - add the child's PID to the running list
						$this->_pids[$pid] = time();

						usleep($this->_config['sleep']);
					}
					else // We are child so lets do it!
					{
						// We need to detach from the master process and become our own master process.
						if (posix_setsid() == -1)
						{
						    Kohana::$log->add(Kohana::ERROR, 'TaskDaemon: Could not detach from terminal.');
							exit(1);
						}

						// Do some setup first
						set_time_limit(1800); // We set to run for a long time.  The tasks should have timelimits set.
						ob_implicit_flush();
						ignore_user_abort(true);

						ini_set("max_execution_time", "0");
						ini_set("max_input_time", "0");

						// Disable any errors from coming into the echo.
						ini_set('display_errors', 'off');
						ini_set('log_errors', 'on');
						error_reporting(E_ALL);

						try {
							// Get db connection.
							Tasks::openDB();

							/*Kohana::$log->add(Kohana::DEBUG, strtr('TaskDaemon; Child Execute task - route: :route, uri: :uri', array(
								':route' => $task->route,
								':uri'   => http_build_query($task->uri)
							)));*/

							// Child - Execute task
							Request::factory( Route::get( $task->route )->uri( $task->uri ) )->execute();

							// Flag the task as ran.
							Tasks::ranTask($task->task_id);

							// Write log to prevent memory issues
							Kohana::$log->write();
						}
						catch (Database_Exception $e)
						{
							Kohana::$log->add(Kohana::ERROR, 'TaskDaemon Task: Database error code: '.$e->getCode().' msg: '. $e->getMessage());

							// Write log to prevent memory issues
							Kohana::$log->write();

							// Flag the task as ran, but with error.
							Tasks::ranTask($task->task_id, true, $e->getMessage());

						}
						catch (Exception $e)
						{
							// Task failed - log message
							Kohana::$log->add(Kohana::ERROR, strtr('TaskDaemon: Task failed - route: :route, uri: :uri, msg: :msg', array(
								':route' => $task->route,
								':uri'   => http_build_query((array)$task->uri),
								':msg'   => $e->getMessage()
							)));

							// Write log to prevent memory issues
							Kohana::$log->write();

							// Flag the task as ran, but with error.
							Tasks::ranTask($task->task_id, true, $e->getMessage());
						}

						// We are done.
						unset($task);
						exit(0);
					}

					// Sleep for a short bit to keep from doing things too fast.
					usleep($this->_config['sleep']);
				}
				else
				{
					// Lets not run the clean up all the time as it is not that important.
					if(mt_rand(1, 50) == 25)
					{
						// Lets clean up any old tasks.
						Tasks::clearCompleted();
					}

					// Let's sleep on it.
					usleep($this->_config['sleep']);
				}

				// Lets do some stuff.
				clearstatcache();
			}

			// Loop has died so lets do some cleaning up.
			$this->clean();

			Kohana::$log->add(KOHANA::DEBUG, "Taskdaemon exited!");

			// Write log to prevent memory issues
			Kohana::$log->write();

			exit(0);
		}
		catch (Database_Exception $e)
		{
			Kohana::$log->add(Kohana::ERROR, 'TaskDaemon Task: Database error code: '.$e->getCode().' msg: '. $e->getMessage());

			Kohana::$log->add(KOHANA::DEBUG, "Taskdaemon exited!");

			// Write log to prevent memory issues
			Kohana::$log->write();

			exit(1);
		}
		catch (Exception $e)
		{
			Kohana::$log->add(Kohana::ERROR, 'TaskDaemon: '.$e->getCode().' msg: '. $e->getMessage());

			Kohana::$log->add(KOHANA::DEBUG, "Taskdaemon exited!");

			// Write log to prevent memory issues
			Kohana::$log->write();

			exit(1);
		}
	}

	/*
	 * Signal handler. Handles kill & child died signal
	 */
	public function sig_handler($signo)
	{
		switch ($signo)
		{
			case SIGCHLD:
				// Child died signal
				while(($pid = pcntl_wait($status, WNOHANG || WUNTRACED)) > 0)
				{
					// remove pid from list
					unset($this->_pids[$pid]);
				}
			break;

			case SIGTERM:
				// Kill signal
				$this->_sigterm = TRUE;
				//Kohana::$log->add(KOHANA::DEBUG, 'TaskDaemon: Hit a SIGTERM');
			break;

			default:
				Kohana::$log->add(KOHANA::DEBUG, 'TaskDaemon: Sighandler '.$signo);
				break;
		}
	}

	/*
	 * Performs clean up. Tries (several times if neccesary)
	 * to kill all children
	 */
	protected function clean()
	{
		$tries = 0;

		while ($tries++ < 5 && count($this->_pids))
		{
			$this->kill_all();
			sleep(1);
		}

		if (count($this->_pids))
		{
			Kohana::$log(Kohana::ERROR,'TaskDaemon: Could not kill all children');
		}

		// Now lets set all the tasks to not running since they are all dead now.
		DB::update(ORM::factory('tasks')->table_name())
			->set(array('running' => 0))
			->where('running', '=', 1)
			->execute();

		// Remove PID file
		if(file_exists($this->_config['pid_path']))
		{
			unlink($this->_config['pid_path']);
		}
	}

	/*
	 * Tries to kill all running children
	 */
	protected function kill_all()
	{
		foreach ($this->_pids as $pid => $time)
		{
			posix_kill($pid, SIGTERM);
			usleep(1000);
		}

		return count($this->_pids) === 0;
	}
}