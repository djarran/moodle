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

/**
 * Contains the class containing unit tests for the quiz overrides import process.
 *
 * @package   mod_quiz
 * @copyright 2024 Djarran Cotleanu
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quiz;

use advanced_testcase;
use context_course;
use context_module;
use question_engine;
use mod_quiz\quiz_settings;
use stdClass;


defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->libdir . '/csvlib.class.php');


/**
 * Class containing unit tests for the quiz overrides import process.
 *
 * @package mod_quiz
 * @copyright 2024 Djarran Cotleanu
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_quiz\process_override_imports
 */
final class quiz_override_import_test extends advanced_testcase {

    /** @var \stdClass $course Test course to contain quiz. */
    protected $course;

    /** @var \stdClass $quiz A test quiz. */
    protected $quiz;

    /** @var context The quiz context. */
    protected $context;

    /** @var stdClass The course_module. */
    protected $cm;

    /** @var stdClass[] Array of students. */
    protected $students;

    /** @var stdClass[] Array of groups. */
    protected $groups;

    /**
     * Create a course with a quiz and a student and a(n editing) teacher.
     *
     */
    public function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        // Setup test data.
        $this->course = $this->getDataGenerator()->create_course();
        $this->quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id]);
        $this->context = context_module::instance($this->quiz->cmid);
        $this->cm = get_coursemodule_from_instance('quiz', $this->quiz->id);

        // Get roles.
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);

        // Create users.
        $this->students[] = $this->getDataGenerator()->create_user(['username' => 'student1']);
        $this->students[] = $this->getDataGenerator()->create_user(['username' => 'student2']);
        $this->students[] = $this->getDataGenerator()->create_user(['username' => 'student3']);

        // Enrol users.
        $this->getDataGenerator()->enrol_user($this->students[0]->id, $this->course->id, $studentrole->id);
        $this->getDataGenerator()->enrol_user($this->students[1]->id, $this->course->id, $studentrole->id);
        $this->getDataGenerator()->enrol_user($this->students[2]->id, $this->course->id, $studentrole->id);

        // Create groups.
        $this->groups[] = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->groups[] = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);

        // Assign users to groups.
        $this->getDataGenerator()->create_group_member(['userid' => $this->students[0]->id, 'groupid' => $this->groups[0]->id]);
        $this->getDataGenerator()->create_group_member(['userid' => $this->students[1]->id, 'groupid' => $this->groups[1]->id]);
        $this->getDataGenerator()->create_group_member(['userid' => $this->students[2]->id, 'groupid' => $this->groups[1]->id]);
        $DB->set_field('course', 'groupmode', SEPARATEGROUPS, ['id' => $this->course->id]);

        // Make a quiz.
        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $this->quiz = $quizgenerator->create_instance(['course' => $this->course->id, 'questionsperpage' => 0,
            'grade' => 100.0, 'sumgrades' => 2]);
    }

    /**
     * Test import functionality with invalid headers.
     */
    public function test_import_invalid_headers(): void {
        $csvrows = [
            ['userid', 'incorrectheader', 'username', 'timeopen', 'timeclose', 'timelimit', 'attempts', 'password', 'generate'],
            [1, '100', 'student1', '2024-01-01 08:00 AM GMT', '2024-01-01 10:00 AM GMT', '3600', '1', 'mypassword1', '0'],
        ];

        $csvcontent = '';
        foreach ($csvrows as $row) {
            $csvcontent .= implode(',', $row) . "\n";
        }

        $importid = \csv_import_reader::get_new_iid('importquizoverrides');
        $importer = new \csv_import_reader($importid, 'importquizoverrides');
        $importer->load_csv_content($csvcontent, 'UTF-8', 'comma');

        $process = new process_override_imports($importer, 'user', $this->quiz, $this->course);

        $result = $process->process();
        $this->assertFalse($result, 'Processing should fail due to invalid headers.');
        $this->assertNotEmpty($process->get_header_error(), 'There should be a header error message.');
    }

    /**
     * Test import functionality with valid group overrides.
     */
    public function test_import_valid_group_overrides(): void {
        global $DB;

        $csvdata = [
            [
                'groupid' => $this->groups[0]->id,
                'groupidnumber' => '200',
                'groupname' => 'group1',
                'timeopen' => '2024-01-01 08:00 +10:00',
                'timeclose' => '2024-01-01 10:00 +10:00',
                'timelimit' => '3600',
                'attempts' => '1',
                'password' => 'grouppassword1',
                'generate' => '0',
            ],
            [
                'groupid' => $this->groups[1]->id,
                'groupidnumber' => '201',
                'groupname' => 'group2',
                'timeopen' => '2024-01-02 08:00 +10:00',
                'timeclose' => '2024-01-02 10:00 +10:00',
                'timelimit' => '7200',
                'attempts' => '2',
                'password' => '',
                'generate' => '1',
            ],
        ];

        $csvfile = '';
        if (!empty($csvdata)) {
            $headers = array_keys($csvdata[0]);
            $csvfile .= implode(',', $headers) . "\n";
            foreach ($csvdata as $row) {
                $csvfile .= implode(',', $row) . "\n";
            }
        }

        $importid = \csv_import_reader::get_new_iid('importquizoverrides');
        $importer = new \csv_import_reader($importid, 'importquizoverrides');
        $importer->load_csv_content($csvfile, 'UTF-8', 'comma');

        $process = new process_override_imports($importer, 'group', $this->quiz, $this->course);

        $processed = $process->process();
        $this->assertTrue($processed, 'Processing the uploaded CSV data should be successful.');
        $this->assertCount(2, $process->overrides, 'The number of processed overrides should be 2.');

        $this->assertTrue($process->canimport, 'No errors should have occurred when processing and validating each row.');

        $potentialerrors = ['groupid', 'timeopen', 'timeclose', 'timelimit', 'attempts', 'password', 'generate'];
        foreach ($process->overrides as $override) {
            foreach ($potentialerrors as $potentialerror) {
                // Ensure that no error actually occurred during validation checks.
                $this->assertArrayNotHasKey($potentialerror, $override->errors,
                  "The {$potentialerror} column contains an invalid value: {$override->{$potentialerror}}.");
            }
        }

        // Import the data into the database.
        $imported = $process->import();
        $this->assertTrue($imported, 'Importing the processed CSV data should be successful.');

        // Verify the overrides in the database.
        foreach ($csvdata as $row) {
            $override = $DB->get_record('quiz_overrides', ['quiz' => $this->quiz->id, 'groupid' => $row['groupid']]);
            $this->assertNotFalse($override, 'The override for group ' . $row['groupid'] . ' should exist in the database.');
            $this->assertEquals(strtotime($row['timeopen']), $override->timeopen);
            $this->assertEquals(strtotime($row['timeclose']), $override->timeclose);
            $this->assertEquals($row['timelimit'], $override->timelimit);
            $this->assertEquals($row['attempts'], $override->attempts);

            if ($row['generate'] == 1) {
                $this->assertNotEmpty($override->password);
            } else {
                $this->assertEquals($row['password'], $override->password);
            }
        }
    }

    /**
     * Test import functionality with invalid group overrides.
     */
    public function test_import_invalid_group_overrides(): void {
        global $DB;

        $csvdata = [
            [
                'groupid' => '', // Invalid.
                'groupidnumber' => '100',
                'groupname' => 'student1',
                'timeopen' => '2024-01-01 08:00 +10:00',
                'timeclose' => 'invalid date', // Invalid.
                'timelimit' => '-3600', // Invalid.
                'attempts' => 'one', // Invalid.
                'password' => 'mypassword1',
                'generate' => '0',
            ],
            [
                'groupid' => $this->groups[1]->id,
                'groupidnumber' => '101',
                'groupname' => 'student2',
                'timeopen' => '2024-01-02 08:00 +10:00',
                'timeclose' => '2024-01-01 07:00 +10:00', // Invalid.
                'timelimit' => '7200',
                'attempts' => '2',
                'password' => '',
                'generate' => 'afgsdfg', // Invalid.
            ],
        ];

        $csvfile = '';
        if (!empty($csvdata)) {
            $headers = array_keys($csvdata[0]);
            $csvfile .= implode(',', $headers) . "\n";
            foreach ($csvdata as $row) {
                $csvfile .= implode(',', $row) . "\n";
            }
        }

        $importid = \csv_import_reader::get_new_iid('importquizoverrides');
        $importer = new \csv_import_reader($importid, 'importquizoverrides');
        $importer->load_csv_content($csvfile, 'UTF-8', 'comma');

        $process = new process_override_imports($importer, 'group', $this->quiz, $this->course);

        $processed = $process->process();
        $this->assertTrue($processed, 'The CSV file should still have been processed.');

        // Check if data cannot be imported due to errors.
        $this->assertFalse($process->canimport, 'Errors should have occurred when processing and validating each row.');

        // Validate that the correct errors are found.
        $errorsbyrow = [
            0 => ['groupid', 'timeclose', 'timelimit', 'attempts'],
            1 => ['timeopen', 'generate'],
        ];

        foreach ($process->overrides as $index => $override) {
            $expectederrors = $errorsbyrow[$index];
            foreach ($expectederrors as $expectederror) {
                // Ensure an error has occurred during validation checks.
                $this->assertArrayHasKey($expectederror, $override->errors,
                  "The column '{$expectederror}' should contain an invalid value in row {$index}");
            }
        }
    }

    /**
     * Test import functionality with valid overrides.
     */
    public function test_import_valid_user_overrides(): void {
        global $DB;

        $csvdata = [
            [
                'userid' => $this->students[0]->id,
                'useridnumber' => '100',
                'username' => 'student1',
                'timeopen' => '2024-01-01 08:00 +10:00',
                'timeclose' => '2024-01-01 10:00 +10:00',
                'timelimit' => '3600',
                'attempts' => '1',
                'password' => 'mypassword1',
                'generate' => '0',
            ],
            [
                'userid' => $this->students[1]->id,
                'useridnumber' => '101',
                'username' => 'student2',
                'timeopen' => '2024-01-02 08:00 +10:00',
                'timeclose' => '2024-01-02 10:00 +10:00',
                'timelimit' => '7200',
                'attempts' => '2',
                'password' => '',
                'generate' => '1',
            ],
        ];

        $csvfile = '';
        if (!empty($csvdata)) {
            $headers = array_keys($csvdata[0]);
            $csvfile .= implode(',', $headers) . "\n";
            foreach ($csvdata as $row) {
                $csvfile .= implode(',', $row) . "\n";
            }
        }

        $importid = \csv_import_reader::get_new_iid('importquizoverrides');
        $importer = new \csv_import_reader($importid, 'importquizoverrides');
        $importer->load_csv_content($csvfile, 'UTF-8', 'comma');

        $process = new process_override_imports($importer, 'user', $this->quiz, $this->course);

        $processed = $process->process();
        $this->assertTrue($processed, 'Processing the uploaded CSV data should be successful.');
        $this->assertCount(2, $process->overrides, 'The number of processed overrides should be 2.');

        // Check if data can be imported.
        $this->assertTrue($process->canimport, 'No errors should have occured when processing and validating each row.');

        $potentialerrors = ['userid', 'timeopen', 'timeclose', 'timelimit', 'attempts', 'password', 'generate'];
        foreach ($process->overrides as $override) {
            foreach ($potentialerrors as $potentialerror) {
                // Ensure that no error actually occurred during validation checks.
                $this->assertArrayNotHasKey($potentialerror, $override->errors,
                  "The {$potentialerror} column contains an invalid value: {$override->{$potentialerror}}.");
            }
        }

        // Import the data into the database.
        $imported = $process->import();
        $this->assertTrue($imported, 'Importing the processed CSV data should be successful.');

        // Verify the overrides in the database.
        foreach ($csvdata as $row) {
            $override = $DB->get_record('quiz_overrides', ['quiz' => $this->quiz->id, 'userid' => $row['userid']]);
            $this->assertNotFalse($override, 'The override for user ' . $row['userid'] . ' should exist in the database.');
            $this->assertEquals(strtotime($row['timeopen']), $override->timeopen);
            $this->assertEquals(strtotime($row['timeclose']), $override->timeclose);
            $this->assertEquals($row['timelimit'], $override->timelimit);
            $this->assertEquals($row['attempts'], $override->attempts);

            if ($row['generate'] == 1) {
                $this->assertNotEmpty($override->password);
            } else {
                $this->assertEquals($row['password'], $override->password);
            }
        }
    }

    /**
     * Test import functionality with invalid overrides.
     *
     * @return void
     */
    public function test_import_invalid_user_overrides(): void {
        global $DB;

        $csvdata = [
            [
                'userid' => '', // Invalid.
                'useridnumber' => '100',
                'username' => 'student1',
                'timeopen' => '2024-01-01 08:00 +10:00',
                'timeclose' => 'invalid date', // Invalid.
                'timelimit' => '-3600', // Invalid.
                'attempts' => 'one', // Invalid.
                'password' => 'mypassword1',
                'generate' => '0',
            ],
            [
                'userid' => $this->students[1]->id,
                'useridnumber' => '101',
                'username' => 'student2',
                'timeopen' => '2024-01-02 08:00 +10:00',
                'timeclose' => '2023-01-01 07:00 +10:00', // Invalid: close time before open time.
                'timelimit' => '7200',
                'attempts' => '2',
                'password' => '',
                'generate' => 'apples', // Invalid.
            ],
        ];

        $csvfile = '';
        if (!empty($csvdata)) {
            $headers = array_keys($csvdata[0]);
            $csvfile .= implode(',', $headers) . "\n";
            foreach ($csvdata as $row) {
                $csvfile .= implode(',', $row) . "\n";
            }
        }

        $importid = \csv_import_reader::get_new_iid('importquizoverrides');
        $importer = new \csv_import_reader($importid, 'importquizoverrides');
        $importer->load_csv_content($csvfile, 'UTF-8', 'comma');

        $process = new process_override_imports($importer, 'user', $this->quiz, $this->course);

        $processed = $process->process();

        // Check if data cannot be imported due to errors.
        $this->assertFalse($process->canimport, 'Errors should have occurred when processing and validating each row.');

        // Validate that the errors have been captured correctly.
        $errorsbyrow = [
            0 => ['userid', 'timeclose', 'timelimit', 'attempts'],
            1 => ['timeopen', 'generate'],
        ];

        foreach ($process->overrides as $index => $override) {
            $expectederrors = $errorsbyrow[$index];
            foreach ($expectederrors as $expectederror) {
                // Ensure an error has occurred during validation checks.
                $this->assertArrayHasKey($expectederror, $override->errors,
                  "The column '{$expectederror}' should contain an invalid value in row {$index}");
            }
        }
    }

}
