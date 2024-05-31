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
     * @var string Holds the error string when there is an error in validate_headers.
     */
    protected $headererror = '';

    /**
     * @var stdClass[] Array of overrides to be processed and possibly inserted or updated in the database.
     */
    public $overrides = [];

    /**
     * @var bool Indicates whether import can proceed given the validation status.
     */
    public $canimport = true;

    /**
     * Constructs a process_override_imports instance.
     *
     * @param \csv_import_reader $importer An instance of csv_import_reader used for reading the CSV data.
     * @param string $mode The mode of processing, which can be 'user' or 'group'.
     * @param stdClass $quiz An object representing the quiz for which overrides are being imported.
     * @param stdClass $course An object representing the course associated with the quiz.
     */
    public function __construct(\csv_import_reader $importer, string $mode, stdClass $quiz, stdClass $course) {
        $this->importer = $importer;
        $this->mode = $mode;
        $this->quiz = $quiz;
        $this->course = $course;
    }

    /**
     * Processes the imported CSV data for quiz overrides.
     *
     * @return bool Returns true on successful processing, false otherwise.
     */
    public function process(): bool {
        global $DB;

        $this->importer->init();
        $mode = $this->mode;
        $typeid = $mode . 'id';

        // Ensure that the file contains the correct header structure.
        if (!$this->validate_headers()) {
            return false;
        }

        // Keep track of current row for preview table.
        $currentrow = 0;

        $existingoverrides = [];
        if ($mode == 'group') {
            $existingrecords = $DB->get_records_select(
              'quiz_overrides', 'quiz = ? AND groupid IS NOT NULL', [$this->quiz->id], '', 'groupid, id');

            $existingoverrides = array_filter(array_column($existingrecords, 'groupid'));
        } else {
            $existingrecords = $DB->get_records_select(
              'quiz_overrides', 'quiz = ? AND userid IS NOT NULL', [$this->quiz->id], '', 'userid, id');
            $existingoverrides = array_filter(array_column($existingrecords, 'userid'));
        }

        while ($row = $this->importer->next()) {
            $currentrow++;

            list($modeid, $idnumber, $name, $timeopen, $timeclose, $timelimit, $attempts, $password, $generate) = $row;

            // Check if the current row has all empty overrides.
            $options = [$timeopen, $timeclose, $timelimit, $attempts, $password, $generate];
            $isoptionsempty = true;
            foreach ($options as $option) {
                if (!empty($option)) {
                    $isoptionsempty = false;
                    break;
                }
            }

            // If all empty and override already exists for user/group exists, set to delete.
            $delete = false;
            if ($isoptionsempty && !empty($modeid)) {
                if (in_array($modeid, $existingoverrides)) {
                    $delete = true;
                } else {
                    // We can skip this row.
                    continue;
                }
            }

            $errors = $this->validate_row_data($modeid, $timeopen, $timeclose, $timelimit, $attempts, $password, $generate);

            if ($this->canimport && !empty($errors)) {
                $this->canimport = false;
            }

            // Create override object to add to database.
            $override = new stdClass();
            $override->id = $this->get_existing_override_id($this->quiz->id, $typeid, $modeid) ?: null;
            $override->quiz = $this->quiz->id;
            $override->$typeid = $modeid;
            $override->timeopen = strtotime($timeopen) && empty($errors['timeopen']) ? strtotime($timeopen) : $timeopen;
            $override->timeclose = strtotime($timeclose) && empty($errors['timeclose']) ? strtotime($timeclose) : $timeclose;
            $override->timelimit = $timelimit;
            $override->attempts = is_numeric($attempts) ? intval($attempts) : $attempts;
            $override->password = empty($errors['generate']) && $generate ? generate_password(20) : $password;

            // Add additional fields for preview table.
            $action = '';
            if ($delete) {
                $action = 'delete';
            } else if (isset($override->id)) {
                $action = 'update';
            } else {
                $action = 'insert';
            }
            $override->recordstatus = $action;
            $override->errors = $errors;
            $override->csvrow = $currentrow;
            $override->generate = $generate;

            // Set any empty values explicitly to null for inserting into database.
            foreach ($override as $key => &$value) {
                if (empty($value) && !is_array($value)) {
                    $value = null;
                }
            }
            unset($value);

            if ($override->recordstatus != 'skip') {
                $this->overrides[] = (object) $override;
            }
        }

        return true;
    }

    /**
     * Retrieves header validation error encountered during the process function.
     *
     * @return string|null The header error message if it exists, or null if no error.
     */
    public function get_header_error(): ?string {
        if (!empty($this->headererror)) {
            return $this->headererror;
        }
        return null;
    }

    /**
     * Imports the processed overrides into the database. Handles insertion,
     * updating, and deletion of quiz overrides based on the overrides
     * collected during processing.
     *
     * @return bool Returns true if the import was successful, false otherwise.
     */
    public function import(): bool {
        global $DB;
        $transaction = $DB->start_delegated_transaction();

        try {
            foreach ($this->overrides as $override) {

                $action = $override->recordstatus;

                // Unset properties used for preview before inserting.
                unset($override->csvrow);
                unset($override->errors);
                unset($override->generate);
                unset($override->recordstatus);

                if ($action == 'update') {
                    $DB->update_record('quiz_overrides', $override);
                    continue;
                }

                if ($action == 'insert') {
                    $DB->insert_record('quiz_overrides', $override);
                    continue;
                }

                if ($action == 'delete') {
                    $DB->delete_records('quiz_overrides', ['id' => $override->id]);
                }
            }
            $transaction->allow_commit();
        } catch (\Exception $e) {
            $transaction->rollback($e);
            $this->errors[] = get_string('errordbinsert', 'quiz', $e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * Retrieves the existing override ID if it exists.
     *
     * @param int $quizid The ID of the quiz.
     * @param string $typeid The type of override identifier (e.g., 'userid', 'groupid').
     * @param string $modeid The ID of the mode (user or group) to check.
     * @return int|false The existing override ID or null if none exists.
     */
    private function get_existing_override_id(int $quizid, string $typeid, string $modeid): int|false {
        global $DB;

        if ($modeid == null) {
            return false;
        }

        $conditions = ['quiz' => $quizid, $typeid => $modeid];
        $existingoverride = $DB->get_record('quiz_overrides', $conditions, 'id');

        return $existingoverride ? $existingoverride->id : false;
    }

    /**
     * Validates the headers of the imported CSV file.
     *
     * @return bool Returns true if the headers are correctly formatted.
     */
    private function validate_headers(): bool {
        global $OUTPUT;

        $currentheaders = $this->importer->get_columns();
        $overrideheaders = ['timeopen', 'timeclose', 'timelimit', 'attempts', 'password', 'generate'];
        $requiredheaders = [];
        $isvalid = true;
        if ($this->mode == 'group') {
            $requiredheaders = array_merge(['groupid', 'groupidnumber', 'groupname'], $overrideheaders);
            $isvalid = $currentheaders == $requiredheaders;
        } else {
            $requiredheaders = array_merge(['userid', 'useridnumber', 'username'], $overrideheaders);
            $isvalid = $currentheaders == $requiredheaders;
        }

        if (!$isvalid) {
            $this->headererror = get_string('errorstructure', 'quiz',
              [
                'expected' => implode(", ", $requiredheaders),
                'actual' => implode(", ", $currentheaders),
                'mode' => $this->mode,
              ]);
            return false;
        }

        return true;
    }

    /**
     * Validates the data for a single row imported from the CSV.
     *
     * @param string $id The user or group ID depending on mode.
     * @param string $timeopen Opening time of the quiz.
     * @param string $timeclose Closing time of the quiz.
     * @param string $timelimit The time limit for the quiz.
     * @param string $attempts The number of attempts allowed.
     * @param string $password The password for accessing the quiz.
     * @param string $generate Specifies whether to generate the password automatically for the user.
     * @return array An array of error messages if validation fails, or an empty array if validation passes.
     */
    private function validate_row_data(string $id, string $timeopen, string $timeclose,
      string $timelimit, string $attempts, string $password, string $generate): array {
        global $DB;

        $errors = [];

        // Ensure that ID is not empty.
        if (empty($id)) {
            $errors[$this->mode . 'id'] = get_string('erroridempty', 'quiz');
        } else {
            // Ensure that user ID exists.
            if ($this->mode == 'user' && !$DB->record_exists('user', ['id' => $id])) {
                $errors['userid'] = get_string('errorusernotexist', 'quiz', $id);
            }

            // Ensure that group ID exists.
            if ($this->mode == 'group' && !$DB->record_exists('groups', ['id' => $id, 'courseid' => $this->course->id])) {
                $errors['groupid'] = get_string('errorgroupnotexist', 'quiz', $id);
            }
        }

        // Ensure that dateformat for timeopen is valid.
        $invaliddateformat = false;
        if (!empty($timeopen) && !$this->validate_date($timeopen)) {
            $errors['timeopen'] = get_string('errorinvaliddatetime', 'quiz', 'timeopen');
            $invaliddateformat = true;
        }

        // Ensure that dateformat for timeclose is valid.
        if (!empty($timeclose) && !$this->validate_date($timeclose)) {
            $errors['timeclose'] = get_string('errorinvaliddatetime', 'quiz', 'timeclose');
            $invaliddateformat = true;
        }

        // Ensure that timeopen < timeclose.
        if (!$invaliddateformat && !empty($timeopen) && !empty($timeclose) && strtotime($timeopen) > strtotime($timeclose)) {
            $errors['timeopen'] = get_string('erroropenclose', 'quiz');
        }

        // Ensure that the timelimit is an integer value more than zero.
        if (!empty($timelimit) && (!is_numeric($timelimit) || intval($timelimit) < 0)) {
            $errors['timelimit'] = get_string('errortimelimit', 'quiz', $timelimit);
        }

        // Ensure that attempts is an integer value more than zero.
        if (!empty($attempts) && (!is_numeric($attempts) || intval($attempts) < 0)) {
            $errors['attempts'] = get_string('errorattempts', 'quiz', $attempts);
        }

        // Ensure that password does not contain whitespace.
        // Adapted from validateSubmitValue in MoodleQuickForm_passwordunmask class.
        if (!empty($password) && $password !== trim($password)) {
            $errors['password'] = get_string('errorpassword', 'quiz');
        }

        // Ensure that generate is a valid boolean value or empty.
        if (!empty($generate) && !in_array($generate, ["0", "1"], true)) {
            $errors['generate'] = get_string('errorgenerate', 'quiz', $attempts);
        }

        return $errors;
    }

    /**
     * Validates that a given date string matches the expected format 'Y-m-d H:i P'.
     *
     * @param string $date The date string to validate.
     * @return bool True if the date string matches the format, otherwise false.
     */
    private function validate_date(string $date): bool {
        $format = 'Y-m-d H:i P';
        $d = \DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }
}
