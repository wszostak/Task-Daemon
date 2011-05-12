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
class Model_Task extends Sprig
{
	protected $_serialize_column = array('uri', 'args');

	public function _init()
	{
		$this->_fields = array(
			'task_id'       => new Sprig_Field_Auto,
                        'route'         => new Sprig_Field_Char(array(
				'max_width' => 50,
			)),
                        'uri'           => new Sprig_Field_Text,
                        'active'        => new Sprig_Field_Boolean(array(
				'default' => TRUE,
			)),
                        'priority'      => new Sprig_Field_Integer(array(
				'max_width' => 2,
				'default'   => 5,
			)),
                        'recurring'     => new Sprig_Field_Integer(array(
				'max_width' => 8,
			)),
                        'pid'           => new Sprig_Field_Integer(array(
				'max_width' => 5,
			)),
			'created'       => new Sprig_Field_Timestamp(array(
				// 'on_create'
			)),
			'nextrun'       => new Sprig_Field_Timestamp,
			'lastrun'       => new Sprig_Field_Timestamp,
			'fail_on_error' => new Sprig_Field_Boolean(array(
				'default' => FALSE,
			)),
                        'failed'        => new Sprig_Field_Integer(array(
				'max_width' => 10,
			)),
			'failed_msg'    => new Sprig_Field_Text,
		);
	}

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