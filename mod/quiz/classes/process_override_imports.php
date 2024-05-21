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

namespace mod_quiz;

use context;
use stdClass;

/**
 * Handles the import and processing of quiz overrides from a CSV file in Moodle.
 *
 * @package   mod_quiz
 * @copyright 2024 Djarran Cotleanu
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_override_imports {
    /**
     * @var stdClass The course object the quiz belongs to.
     */
    protected $course;

    /**
     * @var stdClass The course module object, if applicable.
     */
    protected $cm;

    /**
     * @var stdClass The quiz object that is being overridden.
     */
    protected $quiz;

    /**
     * @var context The context in which the quiz operates.
     */
    protected $context;

    /**
     * @var string Mode of operation, typically 'user' or 'group', determining how overrides are processed.
     */
    protected $mode;

    /**
     * @var csv_import_reader An instance of csv_import_reader used for processing CSV import data.
     */
    protected $importer;

    /**
     * @var array Collects error messages encountered during the processing of CSV data.
     */
    protected $errors = [];

    /**
     * Constructs a process_override_imports instance.
     *
     * @param \csv_import_reader $importer An instance of csv_import_reader used for reading the CSV data.
     * @param string $mode The mode of processing, which can be 'user' or 'group'.
     * @param stdClass $quiz An object representing the quiz for which overrides are being imported.
     * @param stdClass $course An object representing the course associated with the quiz.
     */
    public function __construct(\csv_import_reader $importer, $mode, $quiz, $course) {
        global $DB;

        $this->importer = $importer;
        $this->mode = $mode;
        $this->quiz = $quiz;
        $this->course = $course;
    }

    /**
     * Processes the imported CSV data for quiz overrides.
     *
     * @return bool Returns true on successful processing, false otherwise, along with appropriate notifications.
     */
    public function process(): bool {
        global $DB;

        $this->importer->init();

        if (!$this->validate_headers()) {
            return false;
        }

        // A given user or group override may already exist and need to be updated.
        // Create different arrays to later insert or update after all rows have been processed.
        $insertoverrides = [];
        $updateoverrides = [];

        $currentrow = 0; // Start from 0 to account for header row
        $typeid = $this->mode . 'id';

        while ($record = $this->importer->next()) {
            $currentrow++;

            list($modeid, $name, $quizopens, $quizcloses, $timelimit, $attempts, $password) = $record;

            if ($this->mode == 'group' && empty($modeid) && !empty($name)) {
                $modeid = $this->get_group_id($name);
                if (!$modeid) {
                    continue;
                }
            }

            $rowerrors = $this->validate_row_data($modeid, $quizopens, $quizcloses, $timelimit, $attempts, $password, $currentrow);

            if (!empty($rowerrors)) {
                $formattederrors = "<ul><li>" . implode("</li><li>", $rowerrors) . "</li></ul>";
                $this->errors[] = "Row {$currentrow}: {$formattederrors}";
                continue;
            }

            // Create override object to add to database.
            $override = new stdClass();
            $override->id = $this->get_existing_override_id($this->quiz->id, $typeid, $modeid) ?: null;
            $override->quiz = $this->quiz->id;
            $override->$typeid = $modeid;
            $override->timeopen = strtotime($quizopens);
            $override->timeclose = strtotime($quizcloses);
            $override->timelimit = $timelimit;
            $override->attempts = $attempts === 'Unlimited' ? 0 : intval($attempts);
            $override->password = $password == 'generate' ? generate_password(20) : $password;

            if (isset($override->id)) {
                $updateoverrides[] = $override;
            } else {
                $insertoverrides[] = $override;
            }
        }

        if (empty($this->errors)) {
            $transaction = $DB->start_delegated_transaction();

            try {
                foreach ($updateoverrides as $override) {
                    $DB->update_record('quiz_overrides', $override);
                }

                foreach ($insertoverrides as $override) {
                    $DB->insert_record('quiz_overrides', $override);
                }

                $transaction->allow_commit();
            } catch (\Exception $e) {
                $transaction->rollback($e);
                $this->errors[] = get_string('errordbinsert', 'quiz', $e->getMessage());
                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * Retrieves the existing override ID if it exists.
     *
     * @param int $quizid The ID of the quiz.
     * @param string $typeid The type of override identifier (e.g., 'userid', 'groupid').
     * @param int|string $modeid The ID of the mode (user or group) to check.
     * @return int|null The existing override ID or null if none exists.
     */
    private function get_existing_override_id($quizid, $typeid, $modeid): ?int {
        global $DB;

        $conditions = ['quiz' => $quizid, $typeid => $modeid];
        $existingoverride = $DB->get_record('quiz_overrides', $conditions, 'id');

        return $existingoverride ? $existingoverride->id : null;
    }

    /**
     * Returns the collected errors encountered during the processing of CSV data.
     *
     * @return array An array of error messages.
     */
    public function get_errors(): array {
        return $this->errors;
    }

    /**
     * Validates the headers of the imported CSV file.
     *
     * @return bool Returns true if the headers are correctly formatted and meet the expected
     *              column count and naming conventions. Returns false and outputs a notification
     *              if the headers do not meet the expected criteria.
     */
    private function validate_headers(): bool {
        global $OUTPUT;

        $headers = $this->importer->get_columns();

        if (!$this->quiz_validate_import_header($this->mode, $headers)) {
            $this->errors[] = get_string('errorincorrectheaders', 'quiz');
            return false;
        }

        if (count($headers) != 7) {
            $this->errors[] = get_string('errorincorrectcolumns', 'quiz');
            return false;
        }

        return true;
    }

    /**
     * Validates the headers of the imported CSV file based on the given mode.
     *
     * @param string $mode The mode of the import, either 'group' or 'user'.
     * @param array $headers The array containing the headers from the CSV file.
     * 
     * @return bool Returns true if the headers match the expected format for the given mode, false otherwise.
     */
    private function quiz_validate_import_header($mode, $headers): bool {
      $groupshape = ['Group ID', 'Group', 'Quiz opens', 'Quiz closes', 'Time limit', 'Attempts', 'Password'];
      $usershape = ['User ID', 'User', 'Quiz opens', 'Quiz closes', 'Time limit', 'Attempts', 'Password'];

      if ($mode == 'group') {
          return $headers == $groupshape;
      } else {
          return $headers == $usershape;
      }
    }

    /**
     * Fetches existing quiz overrides from the database, indexed by user or group ID.
     * Returns overrides based on the current mode ('user' or 'group') and quiz ID.
     *
     * @return array Associative array of override records, indexed by 'userid' or 'groupid'.
     */
    private function get_current_overrides(): array {
        global $DB;

        $condition = "quiz = ? AND " . $this->mode . "id IS NOT NULL";
        $currentoverrides = $DB->get_records_select('quiz_overrides', $condition, [$this->quiz->id]);

        return array_column($currentoverrides, null, $this->mode . 'id');
    }

    /**
     * Retrieves the ID of a group based on its name and course.
     *
     * @param string $groupname The name of the group to search for.
     * @return int|false The ID of the group if found and unique, false otherwise.
     */
    private function get_group_id($groupname): int|false {
        global $DB;

        // Retrieve groups by the course ID and group name.
        $grouprecords = $DB->get_records('groups', ['courseid' => $this->course->id, 'name' => $groupname]);

        // Check if no groups are found.
        if (empty($grouprecords)) {
            $this->errors[] = get_string('errorgroupnotfound', 'quiz', $groupname);
            return false;
        }

        // Check if multiple groups are found.
        if (count($grouprecords) > 1) {
            $this->errors[] = get_string('errormultiplegroups', 'quiz', $groupname);
            return false;
        }

        // Retrieve the ID of the single group found.
        $group = reset($grouprecords);
        return $group->id;
    }

    /**
     * Validates the data for a single row imported from the CSV.
     *
     * @param mixed $id The user or group ID depending on mode.
     * @param string $quizopens Opening time of the quiz.
     * @param string $quizcloses Closing time of the quiz.
     * @param int $timelimit The time limit for the quiz.
     * @param int|string $attempts The number of attempts allowed.
     * @param string $password The password for accessing the quiz, if applicable.
     * @param int $rownum The current row number in the CSV.
     * @return mixed True if data is valid, false otherwise along with error notifications.
     */
    private function validate_row_data($id, $quizopens, $quizcloses, $timelimit, $attempts, $password, $rownum): array {
        global $DB;

        $errors = [];

        // Validate the ID column.
        if (empty($id)) {
            $errors[] = get_string('erroridempty', 'quiz');
        } else {
            if ($this->mode == 'user' && !$DB->record_exists('user', ['id' => $id])) {
                $errors[] = get_string('errorusernotexist', 'quiz', $id);
            }

            if ($this->mode == 'group' && !$DB->record_exists('groups', ['id' => $id, 'courseid' => $this->course->id])) {
                $errors[] = get_string('errorgroupnotexist', 'quiz', $id);
            }
        }

        // Validate the given dateformat.
        $invaliddateformat = false;
        if (!empty($quizopens) && !$this->validate_date($quizopens)) {
            $errors[] = get_string('errorinvaliddatetime', 'quiz', 'timeopen');
            $invaliddateformat = true;
        }

        if (!empty($quizcloses) && !$this->validate_date($quizcloses)) {
            $errors[] = get_string('errorinvaliddatetime', 'quiz', 'timeclose');
            $invaliddateformat = true;
        }

        // Validate that timeopen < timeclose.
        if (!$invaliddateformat && !empty($quizopens) && !empty($quizcloses) && strtotime($quizopens) > strtotime($quizcloses)) {
            $errors[] = get_string('erroropenclose', 'quiz');
        }

        // Validate the time limit.
        if (!empty($timelimit) && (!is_numeric($timelimit) || intval($timelimit) < 0)) {
            $errors[] = get_string('errortimelimit', 'quiz', $timelimit);
        }

        // Validate the attempts.
        if (!empty($attempts) && ($attempts !== 'Unlimited' && (!is_numeric($attempts) || intval($attempts) < 0))) {
            $errors[] = get_string('errorattempts', 'quiz', $attempts);
        }

        return $errors;
    }

    /**
     * Validates that a given date string matches the expected format 'Y-m-d H:i'.
     *
     * @param string $date The date string to validate.
     * @return bool True if the date string matches the format, otherwise false.
     */
    private function validate_date($date): bool {
        $format = 'Y-m-d H:i';
        $d = \DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }
}
