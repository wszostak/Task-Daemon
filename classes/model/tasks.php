<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Tasks Model Class
 *
 * Provides overloaded methods for managing the actual data for specific tasks
 * such as serializing column information where needed.
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
class Model_Tasks extends ORM
{
	protected $_db = 'default';
	protected $_table_name  = 'tasks';
	protected $_primary_key = 'task_id';

	protected $_created_column = array('column' => 'created', 'format' => TRUE);

	protected $_serialize_column = array('uri', 'args');

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