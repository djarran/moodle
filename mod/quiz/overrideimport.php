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
 * This page handles the uploading of the CSV file containing the quiz overrides
 * to be imported.
 *
 * @package    mod_quiz
 * @copyright  2024 Djarran Cotleanu
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define('NO_OUTPUT_BUFFERING', true);
require_once(__DIR__ . '/../../config.php');
global $DB;

use mod_quiz\quiz_settings;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot.'/mod/quiz/lib.php');
require_once($CFG->dirroot.'/mod/quiz/locallib.php');

$cmid = required_param('cmid', PARAM_INT);
$mode = required_param('mode', PARAM_ALPHA);

$quizobj = quiz_settings::create_for_cmid($cmid);
$quiz = $quizobj->get_quiz();
$cm = $quizobj->get_cm();
$course = $quizobj->get_course();
$context = $quizobj->get_context();

require_login($course, false, $cm);

$url = new moodle_url('/mod/quiz/overrideimport.php');
$PAGE->set_secondary_active_tab("mod_quiz_useroverrides");
$PAGE->set_pagelayout('report');
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title(ucwords("Import ${mode} Overrides"));
$PAGE->set_heading(ucwords("Import ${mode} Overrides"));
$PAGE->activityheader->disable();

// Add or edit an override.
require_capability('mod/quiz:manageoverrides', $context);

$form = new \mod_quiz\form\import_override_form($url->out(false));
$returnurl = new moodle_url('/mod/quiz/overrides.php', ['cmid' => $cmid, 'mode' => $mode]);

if ($form->is_cancelled()) {
    redirect($returnurl);
}

if ($data = $form->get_data()) {
    // Create CSV import reader.
    $importid = csv_import_reader::get_new_iid('importquizoverrides');
    $importer = new csv_import_reader($importid, 'importquizoverrides');
    $content = $form->get_file_content('importfile');
    $importer->load_csv_content($content, $data->encoding, $data->delimiter_name);

    // Begin processing CSV.
    $process = new \mod_quiz\process_override_imports($importer, $mode, $quiz, $course);
    $imported = $process->process();

    if ($imported) {
        // If all overrides successfully imported, redirect to overrides page with success message.
        $SESSION->quiz_import_success = get_string('importsuccess', 'quiz');
        redirect(new moodle_url('/mod/quiz/overrides.php', array('cmid' => $cmid, 'mode' => $mode)));
    } else {
        // If overrides not imported, redirect to import page again with error message.
        $errors = $process->get_errors();
        array_unshift($errors, get_string('importfail', 'quiz'));
        $SESSION->quiz_import_error = implode('<br>', $errors);
        redirect(new moodle_url('/mod/quiz/overrideimport.php', array('cmid' => $cmid, 'mode' => $mode)));
    }
} else {
    echo $OUTPUT->header();

    if (!empty($SESSION->quiz_import_error)) {
        echo $OUTPUT->notification($SESSION->quiz_import_error, 'error');
        unset($SESSION->quiz_import_error);
    }

    echo $OUTPUT->heading(ucwords("Import ${mode} Overrides"));
    $form->display();
    echo $OUTPUT->footer();
}

