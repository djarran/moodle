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
global $DB, $OUTPUT, $PAGE, $SESSION;

use mod_quiz\overrides_table;
use mod_quiz\quiz_settings;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot.'/mod/quiz/lib.php');
require_once($CFG->dirroot.'/mod/quiz/locallib.php');
require_once($CFG->libdir.'/csvlib.class.php');

$cmid = required_param('cmid', PARAM_INT);
$mode = required_param('mode', PARAM_ALPHA);
$action = optional_param('action', 'upload', PARAM_ALPHA);

// After user accepts preview, use id to get instance of CSV import reader.
$importid = optional_param('importid', '', PARAM_INT);

$quizobj = quiz_settings::create_for_cmid($cmid);
$quiz = $quizobj->get_quiz();
$cm = $quizobj->get_cm();
$course = $quizobj->get_course();
$context = $quizobj->get_context();

require_login($course, false, $cm);

$url = new moodle_url('/mod/quiz/overrideimport.php', ['cmid' => $cmid, 'mode' => $mode]);
$PAGE->set_secondary_active_tab("mod_quiz_useroverrides");
$PAGE->set_pagelayout('report');
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title(get_string('importoverrides', 'quiz', $mode));
$PAGE->set_heading(get_string('importoverrides', 'quiz', $mode));
$PAGE->activityheader->disable();

// Capability to add or edit an override.
require_capability('mod/quiz:manageoverrides', $context);

// Capability to see all groups.
require_capability('moodle/site:accessallgroups', $context);

// If user hasn't uploaded and previewed file process.
if (empty($importid)) {

    $form = new \mod_quiz\form\import_override_form($url->out(false));

    if ($form->is_cancelled()) {
        $returnurl = new moodle_url('/mod/quiz/overrides.php', ['cmid' => $cmid, 'mode' => $mode]);
        redirect($returnurl);
    }

    if ($data = $form->get_data()) {

        $importid = csv_import_reader::get_new_iid('importquizoverrides');
        $importer = new csv_import_reader($importid, 'importquizoverrides');
        $content = $form->get_file_content('overridefile');
        $readcount = $importer->load_csv_content($content, $data->encoding, $data->delimiter_name);

        if ($readcount === false) {
            throw new \moodle_exception('csvfileerror', 'error', $PAGE->url, $importer->get_error());
        } else if ($readcount == 0) {
            throw new \moodle_exception('csvemptyfile', 'error', $PAGE->url, $importer->get_error());
        } else if ($readcount == 1) {
            throw new \moodle_exception('csvnodata', 'error', $PAGE->url);
        }

        // Process uploaded file, perform validation.
        $process = new \mod_quiz\process_override_imports($importer, $mode, $quiz, $course);
        $processed = $process->process();

        // If all rows were processed.
        if ($processed) {

            // If rows processed but no rows to import, redirect.
            if (count($process->overrides) == 0) {
                $SESSION->quiz_import_error = get_string('errornoimport', 'quiz');
                redirect(new moodle_url('/mod/quiz/overrideimport.php', ['cmid' => $cmid, 'mode' => $mode]));
            }

            $previewtable = new overrides_table('previewtable', $mode, $cm, $context, true);
            $previewtable->define_baseurl(new moodle_url('overrideimport.php', ['cmid' => $cmid, 'mode' => $mode]));

            echo $OUTPUT->header();
            echo $OUTPUT->heading(get_string('importpreview', 'quiz'));

            // If some rows contain validation errors, show error notification.
            if ($process->canimport) {
                echo $OUTPUT->notification(get_string('successprocessing', 'quiz'), 'notifysuccess', false);
            } else {
                echo $OUTPUT->notification(get_string('errorprocessing', 'quiz'), 'notifyerror', false);
            }

            // Add processed data to table and output.
            $previewtable->setup();
            $previewtable->format_and_add_array_of_rows($process->overrides, false);
            $previewtable->preview_output();

            // Render action buttons.
            $importurl = new moodle_url('/mod/quiz/overrideimport.php',
              ['cmid' => $cmid, 'mode' => $mode, 'action' => 'import', 'importid' => $importid]);
            $returnurl = new moodle_url('/mod/quiz/overrideimport.php', ['cmid' => $cmid, 'mode' => $mode]);

            $importbutton = new single_button($importurl, get_string('import'), 'get', \single_button::BUTTON_PRIMARY,
              !$process->canimport ? ['disabled' => 'disabled'] : []);
            $importbutton->add_confirm_action(get_string('importconfirm', 'quiz'));
            $cancelbutton = new single_button($url, 'Cancel', 'post');

            echo html_writer::empty_tag('br');
            echo $OUTPUT->render($importbutton);
            echo $OUTPUT->render($cancelbutton);

            echo $OUTPUT->footer();
        } else {
            // If table has incorrect headers.
            $SESSION->quiz_import_error = $process->get_header_error();
            redirect(new moodle_url('/mod/quiz/overrideimport.php', ['cmid' => $cmid, 'mode' => $mode]));
        }
    }

    // If the form has not yet been submitted, display upload form.
    if (!$data) {
        echo $OUTPUT->header();

        if (!empty($SESSION->quiz_import_error)) {
            echo $OUTPUT->notification($SESSION->quiz_import_error, 'error');
            unset($SESSION->quiz_import_error);
        }

        echo $OUTPUT->heading(get_string('importoverrides', 'quiz', $mode));
        $form->display();
        echo $OUTPUT->footer();
    }
} else {
    // If form has been submitted and user wants to import.

    // Get previous importer using importid.
    $importer = new csv_import_reader($importid, 'importquizoverrides');

    // Process CSV, perform validation again.
    $process = new \mod_quiz\process_override_imports($importer, $mode, $quiz, $course);
    $processed = $process->process();
    $imported = $process->import();

    // If all overrides successfully imported, redirect to overrides page with success message.
    if ($imported) {
        $SESSION->quiz_import_success = get_string('importsuccess', 'quiz');
        redirect(new moodle_url('/mod/quiz/overrides.php', ['cmid' => $cmid, 'mode' => $mode]));
    }

    $url = new moodle_url('/mod/quiz/overrideimport.php', ['cmid' => $cmid, 'mode' => $mode]);
    redirect($url);
}

