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

namespace tool_monitor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;

/**
 * Unit tests for rule manager api.
 *
 * @package    tool_monitor
 * @category   test
 * @copyright  2014 onwards Simey Lameze <simey@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(rule_manager::class)]
#[CoversMethod(rule::class, 'get_subscribe_options')]
final class rule_manager_test extends \advanced_testcase {

    /**
     * Set up method.
     */
    public function setUp(): void {
        parent::setUp();
        // Enable monitor.
        set_config('enablemonitor', 1, 'tool_monitor');
    }

    /**
     * Test add_rule method.
     */
    public function test_add_rule(): void {
        $this->setAdminUser();
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $now = time();

        $rule = new \stdClass();
        $rule->userid = $user->id;
        $rule->courseid = $course->id;
        $rule->name = 'test rule 1';
        $rule->plugin = 'core';
        $rule->eventname = '\core\event\course_updated';
        $rule->description = 'test description 1';
        $rule->descriptionformat = FORMAT_HTML;
        $rule->frequency = 15;
        $rule->template = 'test template message';
        $rule->templateformat = FORMAT_HTML;
        $rule->timewindow = 300;
        $rule->timecreated = $now;
        $rule->timemodified = $now;

        $ruledata = \tool_monitor\rule_manager::add_rule($rule);
        foreach ($rule as $prop => $value) {
            $this->assertEquals($ruledata->$prop, $value);
        }
    }

    /**
     * Test get_rule method.
     */
    public function test_get_rule(): void {
        $this->setAdminUser();
        $this->resetAfterTest(true);

        $monitorgenerator = $this->getDataGenerator()->get_plugin_generator('tool_monitor');
        $rule = $monitorgenerator->create_rule();
        $rules1 = \tool_monitor\rule_manager::get_rule($rule->id);
        $this->assertInstanceOf('tool_monitor\rule', $rules1);
        $this->assertEquals($rules1, $rule);
    }

    /**
     * Tests activity subscriptions with the moodle/course:ignoreavailabilityrestrictions capability.
     */
    public function test_get_subscribe_options_uses_effective_access(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        // Create activity and restrict access until the next day.
        $availability = json_encode(\core_availability\tree::get_root_json(
            [
                \availability_date\condition::get_json(
                    \availability_date\condition::DIRECTION_FROM,
                    time() + DAYSECS,
                ),
            ],
            \core_availability\tree::OP_AND,
            false,
        ));
        $activity = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'availability' => $availability,
        ]);
        $activityevent = \mod_assign\event\course_module_viewed::class;

        $monitorgenerator = $this->getDataGenerator()->get_plugin_generator('tool_monitor');
        $rule = $monitorgenerator->create_rule([
            'plugin' => \core\component::get_component_from_classname($activityevent),
            'eventname' => '\\' . $activityevent,
        ]);

        // Ensure the student cannot see the activity as they have not met the availability conditions.
        $this->setUser($student);
        $modinfo = get_fast_modinfo($course, $student->id)->get_cm($activity->cmid);
        $this->assertFalse($modinfo->uservisible);
        $this->assertArrayNotHasKey($activity->cmid, $rule->get_subscribe_options($course->id)->options);

        // Ensure the teacher can see the activity as they have the ignoreavailabilityrestrictions capability.
        $this->setUser($teacher);
        $modinfo = get_fast_modinfo($course, $teacher->id)->get_cm($activity->cmid);
        $this->assertFalse($modinfo->available);
        $this->assertTrue($modinfo->uservisible);
        $this->assertArrayHasKey($activity->cmid, $rule->get_subscribe_options($course->id)->options);

        // Ensure the teacher can subscribe to the activity.
        $rule->subscribe_user($course->id, $activity->cmid);
        $usersubscription = $DB->record_exists('tool_monitor_subscriptions', [
            'ruleid' => $rule->id,
            'courseid' => $course->id,
            'cmid' => $activity->cmid,
            'userid' => $teacher->id,
        ]);
        $this->assertTrue($usersubscription);
    }

    /**
     * Test update_rule method.
     */
    public function test_update_rule(): void {
        $this->setAdminUser();
        $this->resetAfterTest(true);

        $monitorgenerator = $this->getDataGenerator()->get_plugin_generator('tool_monitor');
        $rule = $monitorgenerator->create_rule();

        $ruledata = new \stdClass;
        $ruledata->id = $rule->id;
        $ruledata->frequency = 25;

        \tool_monitor\rule_manager::update_rule($ruledata);
        $this->assertEquals(25, $ruledata->frequency);

    }

    /**
     * Test get_rules_by_courseid method.
     */
    public function test_get_rules_by_courseid(): void {
        $this->setAdminUser();
        $this->resetAfterTest(true);

        $monitorgenerator = $this->getDataGenerator()->get_plugin_generator('tool_monitor');

        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();

        $record = new \stdClass();
        $record->courseid = $course1->id;

        $record2 = new \stdClass();
        $record2->courseid = $course2->id;

        $ruleids = array();
        for ($i = 0; $i < 10; $i++) {
            $rule = $monitorgenerator->create_rule($record);
            $ruleids[] = $rule->id;
            $rule = $monitorgenerator->create_rule(); // Create some site level rules.
            $ruleids[] = $rule->id;
            $rule = $monitorgenerator->create_rule($record2); // Create rules in a different course.
        }
        $ruledata = \tool_monitor\rule_manager::get_rules_by_courseid($course1->id);
        $this->assertEmpty(array_merge(array_diff(array_keys($ruledata), $ruleids), array_diff($ruleids, array_keys($ruledata))));
        $this->assertCount(20, $ruledata);
    }

    /**
     * Test get_rules_by_plugin method.
     */
    public function test_get_rules_by_plugin(): void {
        $this->setAdminUser();
        $this->resetAfterTest(true);

        $monitorgenerator = $this->getDataGenerator()->get_plugin_generator('tool_monitor');

        $record = new \stdClass();
        $record->plugin = 'core';

        $record2 = new \stdClass();
        $record2->plugin = 'mod_assign';

        $ruleids = array();
        for ($i = 0; $i < 10; $i++) {
            $rule = $monitorgenerator->create_rule($record);
            $ruleids[] = $rule->id;
            $rule = $monitorgenerator->create_rule($record2); // Create rules in a different plugin.
        }

        $ruledata = \tool_monitor\rule_manager::get_rules_by_plugin('core');
        $this->assertEmpty(array_merge(array_diff(array_keys($ruledata), $ruleids), array_diff($ruleids, array_keys($ruledata))));
        $this->assertCount(10, $ruledata);
    }

    /**
     * Test get_rules_by_event method.
     */
    public function test_get_rules_by_event(): void {
        $this->setAdminUser();
        $this->resetAfterTest(true);

        $monitorgenerator = $this->getDataGenerator()->get_plugin_generator('tool_monitor');
        $rule = $monitorgenerator->create_rule();

        $record = new \stdClass();
        $record->eventname = '\core\event\calendar_event_created';

        $record2 = new \stdClass();
        $record2->eventname = '\core\event\calendar_event_updated';

        $ruleids = array();
        for ($i = 0; $i < 10; $i++) {
            $rule = $monitorgenerator->create_rule($record);
            $ruleids[] = $rule->id;
            $rule = $monitorgenerator->create_rule($record2); // Create rules in a different plugin.
        }

        $ruledata = \tool_monitor\rule_manager::get_rules_by_event('\core\event\calendar_event_created');
        $this->assertEmpty(array_diff(array_keys($ruledata), $ruleids));
        $this->assertCount(10, $ruledata);
    }
}
