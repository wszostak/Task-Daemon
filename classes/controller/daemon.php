<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Daemon Controller Class
 *
 * Provides a CLI interface to getting information about the daemon(s) currently
 * running.
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

			Kohana::$log->write();

			exit(1);
		}

		// Set the pid file
		$this->_config['pid_path'] = $this->_config['pid_path'] . 'TaskDaemon.' . $this->_config_name . '.pid';

		/*
		 * Correct the delay time set so that we do not eat up all the processor(s)' time.  Setting usleep to too short of a delay
		 * will cause the process to eat up all the available CPU time (i.e. process will run at 99-100%).
		 */
		$this->_config['sleep'] = ($this->_config['sleep'] >= 10000)?$this->_config['sleep']:10000;
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
	 * php index.php --uri=daemon/launch --daemonize=yes|no (default: yes)
	 */
	public function action_launch()
	{
		$settings = CLI::options('daemonize');

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

		// We do not want to daemonize, useful if you are using some kind of monitoring program
		if(isset($settings['daemonize']) && $settings['daemonize'] == 'no')
		{
			// Fork successful - exit parent (daemon continues in child)
			Kohana::$log->add(KOHANA::DEBUG, 'TaskDaemon: Daemon created succesfully at: ' . posix_getpid());

			// Set the pid file for this daemon so we can see if it is running at any time.
			file_put_contents( $this->_config['pid_path'], posix_getpid());

			// Background process - run daemon
			Kohana::$log->add(KOHANA::DEBUG,strtr('TaskDaemon: Config :config loaded, max: :max, sleep: :sleep', array(
				':config' => $this->_config_name,
				':max'    => $this->_config['max'],
				':sleep'  => $this->_config['sleep']
			)));

			// Write the log to ensure no memory issues
			Kohana::$log->write();

			// Launch the daemon
			Daemon::launch($this->_config);

			unset($this);
		}
		else // Run as your own standalone daemon.
		{
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

				// Set the pid file for this daemon so we can see if it is running at any time.
				file_put_contents( $this->_config['pid_path'], $pid);

				Kohana::$log->write();

				// We are done so we exit out.
				exit(0);
			}
			else
			{
				// We need to detach from the master process and become our own master process.
				if (posix_setsid() == -1)
				{
				    Kohana::$log->add(Kohana::ERROR, 'TaskDaemon: Could not detach from terminal in launch.');
					exit(1);
				}

				// Background process - run daemon
				Kohana::$log->add(KOHANA::DEBUG,strtr('TaskDaemon: Config :config loaded, max: :max, sleep: :sleep', array(
					':config' => $this->_config_name,
					':max'    => $this->_config['max'],
					':sleep'  => $this->_config['sleep']
				)));

				// Write the log to ensure no memory issues
				Kohana::$log->write();

				// Launch the daemon
				Daemon::launch($this->_config);

				//exit(0);

				unset($this);
			}
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

		// Write log to prevent memory issues
		Kohana::$log->write();
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
}
