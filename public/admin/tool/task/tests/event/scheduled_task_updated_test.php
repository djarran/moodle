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
 * Tests for scheduled task updated event.
 *
 * @package    tool_task
 * @category   test
 * @copyright  2026 Djarran Cotleanu <djarrancotleanu@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_task\event\scheduled_task_updated
 */
final class scheduled_task_updated_test extends \advanced_testcase {
    /**
     * Setup testcase.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Data provider for test_scheduled_task_updated().
     *
     * @return array
     */
    public static function scheduled_task_provider(): array {
        return [
            'Session cleanup task enabled' => [
                'taskclass' => '\core\task\session_cleanup_task',
                'minute' => '5',
                'hour' => '*',
                'day' => '*',
                'month' => '*',
                'dayofweek' => '*',
                'disabled' => 0,
            ],
            'Cache cleanup task disabled' => [
                'taskclass' => '\core\task\cache_cleanup_task',
                'minute' => '10',
                'hour' => '2',
                'day' => '*',
                'month' => '*',
                'dayofweek' => '*',
                'disabled' => 1,
            ],
            'File temp cleanup task enabled' => [
                'taskclass' => '\core\task\file_temp_cleanup_task',
                'minute' => '0',
                'hour' => '*',
                'day' => '*',
                'month' => '*',
                'dayofweek' => '*',
                'disabled' => 0,
            ],
            'Antivirus cleanup task disabled' => [
                'taskclass' => '\core\task\antivirus_cleanup_task',
                'minute' => '30',
                'hour' => '3',
                'day' => '*',
                'month' => '*',
                'dayofweek' => '*',
                'disabled' => 1,
            ],
        ];
    }

    /**
     * Test the scheduled task updated event.
     *
     * @dataProvider scheduled_task_provider
     *
     * @param string $taskclass The scheduled task class name.
     * @param string $minute The minute schedule.
     * @param string $hour The hour schedule.
     * @param string $day The day schedule.
     * @param string $month The month schedule.
     * @param string $dayofweek The day of week schedule.
     * @param int $disabled Whether the task is disabled (0 or 1).
     */
    public function test_scheduled_task_updated(
        string $taskclass,
        string $minute,
        string $hour,
        string $day,
        string $month,
        string $dayofweek,
        int $disabled
    ): void {
        // Update task configuration.
        $task = \core\task\manager::get_scheduled_task($taskclass);
        $task->set_minute($minute);
        $task->set_hour($hour);
        $task->set_day($day);
        $task->set_month($month);
        $task->set_day_of_week($dayofweek);
        $task->set_disabled($disabled);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        \core\task\manager::configure_scheduled_task($task);
        $events = $sink->get_events();
        $event = reset($events);
        $sink->close();

        // Check that the event data is valid.
        $this->assertInstanceOf('\tool_task\event\scheduled_task_updated', $event);
        $updatedsettings = $event->other['updatedsettings'];
        $this->assertEquals($task->get_minute(), $updatedsettings['minute']);
        $this->assertEquals($task->get_hour(), $updatedsettings['hour']);
        $this->assertEquals($task->get_day(), $updatedsettings['day']);
        $this->assertEquals($task->get_month(), $updatedsettings['month']);
        $this->assertEquals($task->get_day_of_week(), $updatedsettings['dayofweek']);
        $this->assertEquals($task->get_disabled(), $updatedsettings['disabled']);
    }
}
