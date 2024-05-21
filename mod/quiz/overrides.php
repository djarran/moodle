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
 * This page handles listing of quiz overrides
 *
 * @package    mod_quiz
 * @copyright  2010 Matt Petro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_quiz\overrides_table;
use mod_quiz\quiz_settings;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot.'/mod/quiz/lib.php');
require_once($CFG->dirroot.'/mod/quiz/locallib.php');

$cmid = required_param('cmid', PARAM_INT);
$mode = optional_param('mode', '', PARAM_ALPHA); // One of 'user' or 'group', default is 'group'.
$download = optional_param('download', '', PARAM_ALPHA);

$quizobj = quiz_settings::create_for_cmid($cmid);
$quiz = $quizobj->get_quiz();
$cm = $quizobj->get_cm();
$course = $quizobj->get_course();
$context = $quizobj->get_context();

require_login($course, false, $cm);

// Check the user has the required capabilities to list overrides.
$canedit = has_capability('mod/quiz:manageoverrides', $context);
if (!$canedit) {
    require_capability('mod/quiz:viewoverrides', $context);
}

$quizgroupmode = groups_get_activity_groupmode($cm);
$showallgroups = ($quizgroupmode == NOGROUPS) || has_capability('moodle/site:accessallgroups', $context);

// Get the course groups that the current user can access.
$groups = $showallgroups ? groups_get_all_groups($cm->course) : groups_get_activity_allowed_groups($cm);

// Default mode is "group", unless there are no groups.
if ($mode != "user" and $mode != "group") {
    if (!empty($groups)) {
        $mode = "group";
    } else {
        $mode = "user";
    }
}
$groupmode = ($mode == "group");

$url = new moodle_url('/mod/quiz/overrides.php', ['cmid' => $cm->id, 'mode' => $mode]);

$title = get_string('overridesforquiz', 'quiz',
        format_string($quiz->name, true, ['context' => $context]));
$PAGE->set_url($url);
// $PAGE->set_pagelayout('admin');
$PAGE->set_pagelayout('report');
// $PAGE->add_body_class('limitedwidth');
$PAGE->set_title($title);
$PAGE->set_heading($course->fullname);
$PAGE->activityheader->disable();

// Activate the secondary nav tab.
$PAGE->set_secondary_active_tab("mod_quiz_useroverrides");

// Delete orphaned group overrides.
// Keep this.
$sql = 'SELECT o.id
          FROM {quiz_overrides} o
     LEFT JOIN {groups} g ON o.groupid = g.id
         WHERE o.groupid IS NOT NULL
               AND g.id IS NULL
               AND o.quiz = ?';
$params = [$quiz->id];
$orphaned = $DB->get_records_sql($sql, $params);
if (!empty($orphaned)) {
    $DB->delete_records_list('quiz_overrides', 'id', array_keys($orphaned));
}

// Work out what else needs to be displayed.
$addenabled = true;
$warningmessage = '';
if ($canedit) {
    if ($groupmode) {
        if (empty($groups)) {
            // There are no groups.
            $warningmessage = get_string('groupsnone', 'quiz');
            $addenabled = false;
        }
    } else {
        // See if there are any students in the quiz.
        if ($showallgroups) {
            $users = get_users_by_capability($context, 'mod/quiz:attempt', 'u.id');
            $nousermessage = get_string('usersnone', 'quiz');
        } else if ($groups) {
            $users = get_users_by_capability($context, 'mod/quiz:attempt', 'u.id', '', '', '', array_keys($groups));
            $nousermessage = get_string('usersnone', 'quiz');
        } else {
            $users = [];
            $nousermessage = get_string('groupsnone', 'quiz');
        }
        $info = new \core_availability\info_module($cm);
        $users = $info->filter_user_list($users);

        if (empty($users)) {
            // There are no students.
            $warningmessage = $nousermessage;
            $addenabled = false;
        }
    }
}


$table = new overrides_table('uniqueid', $mode, $cm, $context, $download);
$table->define_baseurl(new moodle_url('overrides.php', ['cmid' => $cmid, 'mode' => $mode]));

if ($mode == 'user') {
    // User overrides.
    $userfieldsapi = \core_user\fields::for_identity($context)->with_name()->with_userpic();
    $extrauserfields = $userfieldsapi->get_required_fields([\core_user\fields::PURPOSE_IDENTITY]);
    $userfieldssql = $userfieldsapi->get_sql('u', true, '', 'userid', false);
    foreach ($extrauserfields as $field) {
        $colclasses[] = 'col' . $field;
        $headers[] = \core_user\fields::get_display_name($field);
    }

    list($sort, $params) = users_order_by_sql('u', null, $context, $extrauserfields);
    $params['quizid'] = $quiz->id;

    if ($showallgroups) {
        $groupsjoin = '';
        $groupswhere = '';

    } else if ($groups) {
        list($insql, $inparams) = $DB->get_in_or_equal(array_keys($groups), SQL_PARAMS_NAMED);
        $groupsjoin = 'JOIN {groups_members} gm ON u.id = gm.userid';
        $groupswhere = ' AND gm.groupid ' . $insql;
        $params += $inparams;

    } else {
        // User cannot see any data.
        $groupsjoin = '';
        $groupswhere = ' AND 1 = 2';
    }

    $table->set_sql("o.*, {$userfieldssql->selects}", "{quiz_overrides} o 
              JOIN {user} u ON o.userid = u.id
                  {$userfieldssql->joins}
              $groupsjoin
              ", "o.quiz = :quizid
              $groupswhere", array_merge($params, $userfieldssql->params));
} else {
      $sqlparams = array();
      $sqlparams['quizid'] = $quiz->id;
      $table->set_sql('o.*, g.name', "{quiz_overrides} o JOIN {groups} g ON o.groupid = g.id", 'o.quiz = :quizid', $sqlparams);
}

// Must be executed before header and footer outputs.
if ($download) {
    $courseshortname = format_string($course->shortname, true,
            ['context' => context_course::instance($course->id)]);
    $table->is_downloading($download, $courseshortname . '-' . format_string($quiz->name, true) . '-' . $mode . 'overrides' . '-' . date('Ymd'));
    raise_memory_limit(MEMORY_EXTRA);
    $table->out(20, false);
    exit();
}

// Tertiary navigation.
echo $OUTPUT->header();


if (!empty($SESSION->quiz_import_success)) {
    echo $OUTPUT->notification($SESSION->quiz_import_success, 'success');
    unset($SESSION->quiz_import_success); // Clear the error message after displaying it
}

$renderer = $PAGE->get_renderer('mod_quiz');
$tertiarynav = new \mod_quiz\output\overrides_actions($cmid, $mode, $canedit, $addenabled);
echo $renderer->render($tertiarynav);

if ($mode === 'user') {
    echo $OUTPUT->heading(get_string('useroverrides', 'quiz'));
} else {
    echo $OUTPUT->heading(get_string('groupoverrides', 'quiz'));
}

// Output the table and button.
echo html_writer::start_tag('div', ['id' => 'quizoverrides']);

$table->out(10, true);

if ($table->totalrows == 0) {
    if ($groupmode) {
        echo $OUTPUT->notification(get_string('overridesnoneforgroups', 'quiz'), 'info', false);
    } else {
        echo $OUTPUT->notification(get_string('overridesnoneforusers', 'quiz'), 'info', false);
    }
} else {
}

if ($table->hasinactive) {
    echo $OUTPUT->notification(get_string('inactiveoverridehelp', 'quiz'), 'info', false);
}

if ($warningmessage) {
    echo $OUTPUT->notification($warningmessage, 'error');
}

echo html_writer::end_tag('div');

// Finish the page.
echo $OUTPUT->footer();
