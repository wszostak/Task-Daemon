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
	 * The database config to connect to.
	 *
	 * @var string
	 */
	protected $_db = 'default';

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

		// Load config
		$params = $this->request->param();

		// First key is config
		$this->_config_name = count($params)
			? reset($params)
			: 'default';

		$this->_config = Kohana::config('daemon')->{$this->_config_name};

		if (empty($this->_config))
		{
			Kohana::$log->add('error', 'TaskDaemon: Config not found ("daemon.' . $this->_config_name . '"). Exiting.');
			echo 'TaskDaemon: Config not found ("daemon.' . $this->_config_name . '"). Exiting.' . PHP_EOL;
			exit;
		}

		// Set the pid file
		$this->_config['pid_path'] = $this->_config['pid_path'] . 'TaskDaemon.' . $this->_config_name . '.pid';

		/*
		 * Correct the delay time set so that we do not eat up all the processor(s)' time.  Setting usleep to too short of a delay
		 * will cause the process to eat up all the available CPU time.
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
				Kohana::$log->add('debug', 'TaskDaemon: Daemon already running at: ' . $pid);
				exit;
			}
		}

		// fork into background
		$pid = pcntl_fork();

		if ($pid == -1)
		{
			// Error - fork failed
			Kohana::$log->add('error', 'TaskDaemon: Initial fork failed');
			exit;
		}
		elseif ($pid)
		{
			// Fork successful - exit parent (daemon continues in child)
			Kohana::$log->add('debug', 'TaskDaemon: Daemon created succesfully at: ' . $pid);
			file_put_contents( $this->_config['pid_path'], $pid);
			$this->close_db();
			exit;
		}
		else
		{
			// Background process - run daemon

			Kohana::$log->add('debug',strtr('TaskDaemon: Config :config loaded, max: :max, sleep: :sleep', array(
				':config' => $this->_config_name,
				':max'    => $this->_config['max'],
				':sleep'  => $this->_config['sleep']
			)));

			// Write the log to ensure no memory issues
			Kohana::$log->write();

			// Close any DB connectiosn we have.
			$this->close_db();

			// Fire up a new DB connection.
			Database::instance($this->_db);

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
				Kohana::$log->add('debug','Sending SIGTERM to pid ' . $pid);
				echo 'Sending SIGTERM to pid ' . $pid . PHP_EOL;

				posix_kill($pid, SIGTERM);

				if ( posix_get_last_error() ===0)
				{
					echo "Signal send SIGTERM to pid ".$pid.PHP_EOL;
				}
				else
				{
					echo "An error occured while sending SIGTERM".PHP_EOL;
					unlink($this->_config['pid_path']);
				}
			}
			else
			{
				Kohana::$log->add("debug", "Could not find TaskDaemon pid in file :".$this->_config['pid_path']);
				echo "Could not find task_queue pid in file :".$this->_config['pid_path'].PHP_EOL;
			}
		}
		else
		{
			Kohana::$log->add("error", "TaskDaemon pid file ".$this->_config['pid_path']." does not exist");
			echo "TaskDaemon pid file ".$this->_config['pid_path']." does not exist".PHP_EOL;
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
		// Loop until we are told to die.
		while (!$this->_sigterm)
		{
			try
			{
				// Fire up a new DB connection.
				Database::instance($this->_db);

				// Find the tasks that need to be run.
				$tasks = ORM::factory('tasks')
					->where('active', '=', '1')
					->where('running', '=', '0')
					->where('nextrun', '<=', time())
					->order_by('priority', 'DESC')
					->order_by('nextrun', 'ASC')
					->limit($this->_config['max'])
					->find_all();

				foreach($tasks AS $task)
				{
					// Task did not load for some reason.
					if(!$task->loaded())
					{
						continue;
					}

					// Fire up a new DB connection.
					Database::instance($this->_db);

					// Reload the task, mainly to affect custom get/set
					$task = $task->reload();

					// We are at the max of allowed childern so this task will have to wait until next run.
					if (count($this->_pids) >= $this->_config['max'])
					{
						break;
					}

					// Update task status
					$task->running = 1;
					$task->save();

					// Write log to prevent memory issues
					Kohana::$log->write();

					// Fork process to execute task
					$pid = pcntl_fork();

					if ($pid == -1)
					{
						Kohana::$log->add('error', 'TaskDaemon: Could not spawn child task process.');
						exit;
					}
					elseif ($pid)
					{
						// Parent - add the child's PID to the running list
						$this->_pids[$pid] = time();
					}
					else
					{
						try
						{
							// Child - Execute task
							Request::factory( Route::get( $task->route )->uri( $task->uri ) )->execute();

							// Fire up a new DB connection.
							Database::instance($this->_db);

							// Flag the task as ran.
							$task->ran();
						}
						catch(Exception $e)
						{
							// Task failed - log message
							Kohana::$log->add('error', strtr('TaskDaemon: Task failed - route: :route, uri: :uri, msg: :msg', array(
								':route' => $task->route,
								':uri'   => http_build_query((array)$task->uri),
								':msg'   => $e->getMessage()
							)));

							// Fire up a new DB connection.
							Database::instance($this->_db);

							// Flag the task as ran.
							$task->ran(true, $e->getMessage());
						}

						exit;
					}

					// Close any DB connectiosn we have.
					$this->close_db();

					sleep(3);
				}

				// Lets not run the clean up all the time as it is not that important.
				if(mt_rand(1, 50) == 25)
				{
					// Lets clean up any old tasks.
					Tasks::clearCompleted();
				}
			}
			catch (Database_Exception $e)
			{
				Kohana::$log->add('error', 'TaskDaemon: Database error code: '.$e->getCode().' msg: '. $e->getMessage());

				// Write log to prevent memory issues
				Kohana::$log->write();

				// Mysql Server went away.
				if($e->getCode() == 2006)
				{
					$this->close_db();

					// Fire up a new DB connection.
					Database::instance($this->_db);
				}
			}

			// No tasks in queue - sleep
			usleep($this->_config['sleep']);
		}

		// clean up
		$this->clean();
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
			Kohana::$log('error','TaskDaemon: Could not kill all children');
		}

		// Remove PID file
		unlink($this->_config['pid_path']);

		// Now lets set all the tasks to not running since they are all dead now.
		DB::delete(ORM::factory('tasks')->table_name())
			->set(array('running' => 0))
			->where('running', '=', 1)
			->execute();

		$this->close_db();

		echo 'TaskDaemon exited' . PHP_EOL;
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

	protected function close_db()
	{
		foreach(Database::$instances as $db)
		{
			$db->disconnect();
		}

		unset($db);

		Database::$instances = array();
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
				Kohana::$log->add('debug', 'TaskDaemon: Hit a SIGTERM');
			break;
		}
	}
}
