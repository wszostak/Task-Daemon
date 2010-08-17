<?php
return array(
	'default' => array(
		// The instance to use when reconnecting to the database if the connection drops.
		'database_instance' => 'default',

		// Maximum number of tasks that can be executed at the same time (parallel)
		'max' => 4,

		// Sleep time (in microseconds) when there's no active task. Note that there is a floor for this value, cant set to 0.
		'sleep' => 5000000, // 5 seconds

		// save the PID file in this location
		'pid_path' => '/tmp/',
	),
);