<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Daemon extends Controller
{
	/**
	 * Holds the name of the config array being used.
	 *
	 * @var string
	 */
	protected $_config_name = 'default';

	/**
	 * Holds the loaded config information.
	 *
	 * @var array
	 */
	protected $_config = array();

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

	/**
	 * Do some stuff to prepare the process to run as a daemon in the background.
	 * @see Kohana_Controller::before()
	 */
	public function before()
	{
		// Deny non-CLI access
		if (!Kohana::$is_cli)
		{
			throw new Kohana_Exception('The requested route does not exist: :route',
				array(':route' => $this->request->route));
		}

		// Setup
		ini_set("max_execution_time", "0");
		ini_set("max_input_time", "0");
		set_time_limit(0);

		// Signal handler
		pcntl_signal(SIGCHLD, array($this, 'sig_handler'));
		pcntl_signal(SIGTERM, array($this, 'sig_handler'));
		declare(ticks = 1);

		// Disable any errors from coming into the echo.
		ini_set('display_errors', 'off');
		ini_set('log_errors', 'on');
		error_reporting(E_ALL);

		// Load config
		$params = $this->request->param();

		// First key is config
		$this->_config_name = count($params)
			? reset($params)
			: 'default';

		$this->_config = Kohana::config('daemon')->{$this->_config_name};

		if (empty($this->_config))
		{
			Kohana::$log->add(Kohana::ERROR, 'TaskDaemon: Config not found ("daemon.' . $this->_config_name . '"). Exiting.');
			exit(1);
		}

		// Set the pid file
		$this->_config['pid_path'] = $this->_config['pid_path'] . 'TaskDaemon.' . $this->_config_name . '.pid';

		/*
		 * Correct the delay time set so that we do not eat up all the processor(s)' time.  Setting usleep to too short of a delay
		 * will cause the process to eat up all the available CPU time (i.e. process will run at 99-100%).
		 */
		$this->_config['sleep'] = ($this->_config['sleep'] >= 500)?$this->_config['sleep']:500;

	}

	/*
	 * Test route
	 *
	 * php index.php --uri=daemon
	 */
	public function action_index()
	{
		$this->request->response = 'TaskDaemon: Route is successful'.PHP_EOL;
	}

	/*
	 * Launch daemon
	 *
	 * php index.php --uri=daemon/launch
	 */
	public function action_launch()
	{
		/*$task = ORM::factory('tasks');
		$task->route = 'cli';
		$task->uri = array(
			'directory'  => 'cli',
			'controller' => 'services',
			'action'     => 'checksetups',
		);
		$task->recurring = 30;
		$task->nextrun = new Database_Expression("UNIX_TIMESTAMP()");
		$task->save();
		exit;*/

		// Lets make sure the system is only running one master file.
		if(file_exists($this->_config['pid_path']))
		{
			$pid = file_get_contents($this->_config['pid_path']);
			if (file_exists("/proc/".$pid))
			{
				Kohana::$log->add(KOHANA::DEBUG, 'TaskDaemon: Daemon already running at: ' . $pid);
				exit;
			}
		}

		// fork into background
		$pid = pcntl_fork();

		if ($pid == -1)
		{
			// Error - fork failed
			Kohana::$log->add(Kohana::ERROR, 'TaskDaemon: Initial fork failed');
			exit;
		}
		elseif ($pid)
		{
			// Fork successful - exit parent (daemon continues in child)
			Kohana::$log->add(KOHANA::DEBUG, 'TaskDaemon: Daemon created succesfully at: ' . $pid);
			file_put_contents( $this->_config['pid_path'], $pid);
			exit(0);
		}
		else
		{
			// Background process - run daemon
			Kohana::$log->add(KOHANA::DEBUG,strtr('TaskDaemon: Config :config loaded, max: :max, sleep: :sleep', array(
				':config' => $this->_config_name,
				':max'    => $this->_config['max'],
				':sleep'  => $this->_config['sleep']
			)));

			// Write the log to ensure no memory issues
			Kohana::$log->write();

			// run daemon
			$this->daemon();
		}
	}

	/*
	 * Exit daemon (if running)
	 *
	 * php index.php --uri=daemon/exit
	 */
	public function action_exit()
	{
		if ( file_exists( $this->_config['pid_path']))
		{
			$pid = file_get_contents($this->_config['pid_path']);

			if ( $pid !== 0)
			{
				Kohana::$log->add(KOHANA::DEBUG, 'Sending SIGTERM to pid ' . $pid);

				posix_kill($pid, SIGTERM);

				if (posix_get_last_error() === 0)
				{
					Kohana::$log->add(KOHANA::DEBUG, 'Signal sent SIGTERM to pid ' . $pid);
				}
				else
				{
					Kohana::$log->add(Kohana::ERROR, "TaskDaemon: An error occured while sending SIGTERM");
					unlink($this->_config['pid_path']);
				}
			}
			else
			{
				Kohana::$log->add(KOHANA::DEBUG, "Could not find TaskDaemon pid in file :".$this->_config['pid_path']);
			}
		}
		else
		{
			Kohana::$log->add(Kohana::ERROR, "TaskDaemon pid file ".$this->_config['pid_path']." does not exist");
		}
	}

	/*
	 * Get daemon & queue status
	 *
	 * php index.php --uri=daemon/status
	 */
	public function action_status()
	{
		$pid = file_exists($this->_config['pid_path'])
			? file_get_contents($this->_config['pid_path'])
			: FALSE;

		echo $pid
			? 'TaskDaemon is running at PID: ' . $pid . PHP_EOL
			: 'TaskDaemon is NOT running' . PHP_EOL;

		echo 'TaskDaemon has ' . ORM::factory('tasks')->count_all() . ' tasks in queue'.PHP_EOL;
	}

	/**
	 * The actual daemon script that runs in the background reading tasks and running crons.
	 */
	protected function daemon()
	{
		try {
			// Loop until we are told to die.
			while (!$this->_sigterm)
			{
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
					}
					else // We are child so lets do it!
					{
						try {
							// Get db connection.
							Tasks::openDB();

							Kohana::$log->add(Kohana::DEBUG, strtr('TaskDaemon; Child Execute task - route: :route, uri: :uri', array(
								':route' => $task->route,
								':uri'   => http_build_query($task->uri)
							)));

							// Write log to prevent memory issues
							Kohana::$log->write();

							// Child - Execute task
							Request::factory( Route::get( $task->route )->uri( $task->uri ) )->execute();

							// Flag the task as ran.
							Tasks::ranTask($task->task_id);
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
					usleep(10000);
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
	 * Performs clean up. Tries (several times if neccesary)
	 * to kill all children
	 */
	protected function clean()
	{
		// Load up a db instance.
		//$db = Tasks::openDB();

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

		// Remove PID file
		if(file_exists($this->_config['pid_path']))
		{
			unlink($this->_config['pid_path']);
		}

		// Now lets set all the tasks to not running since they are all dead now.
		DB::update(ORM::factory('tasks')->table_name())
			->set(array('running' => 0))
			->where('running', '=', 1)
			->execute();

		// Close the db instance
		//Tasks::closeDB();
		//unset($db);
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
				Kohana::$log->add(KOHANA::DEBUG, 'TaskDaemon: Hit a SIGTERM');
			break;
		}
	}
}
