<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace tool_task\event;

/**
 * Event for updating scheduled task configuration.
 *
 * @package    tool_task
 * @copyright  2026 Djarran Cotleanu <djarrancotleanu@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scheduled_task_updated extends \core\event\base {
    /**
     * Initialise the event data.
     */
    protected function init() {
        $this->data['objecttable'] = 'task_scheduled';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Return event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventscheduledtaskupdated', 'tool_task');
    }

    /**
     * Return event description.
     *
     * @return string
     */
    public function get_description() {
        $settings = $this->other['updatedsettings'];
        $status = $settings['disabled'] ? get_string('taskdisabled', 'tool_task') : get_string('taskenabled', 'tool_task');

        return get_string('eventscheduledtaskupdated_desc', 'tool_task', [
            'userid' => $this->userid,
            'classname' => $this->other['classname'],
            'minute' => $settings['minute'],
            'hour' => $settings['hour'],
            'day' => $settings['day'],
            'month' => $settings['month'],
            'dayofweek' => $settings['dayofweek'],
            'status' => $status,
        ]);
    }

    /**
     * Return event URL.
     *
     * @return moodle_url
     */
    public function get_url() {
        return new \moodle_url('/admin/tool/task/scheduledtasks.php');
    }
}
