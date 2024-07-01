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
 * @copyright  2010 Matt Petro, 2024 Djarran Cotleanu
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
$manager = $quizobj->get_override_manager();

require_login($course, false, $cm);

// Check the user has the required capabilities to edit overrides.
$canedit = has_capability('mod/quiz:manageoverrides', $context);

// If not, check if they can view overrides.
if (!$canedit) {
    require_capability('mod/quiz:viewoverrides', $context);
}

$quizgroupmode = groups_get_activity_groupmode($cm);

// Check if the user can see all groups.
$showallgroups = ($quizgroupmode == NOGROUPS) || has_capability('moodle/site:accessallgroups', $context);

// Get the course groups that the current user can access.
$groups = $showallgroups ? groups_get_all_groups($cm->course) : groups_get_activity_allowed_groups($cm);

// Default mode is "group", unless there are no groups.
if ($mode != "user" && $mode != "group") {
    if (!empty($groups)) {
        $mode = "group";
    } else {
        $mode = "user";
    }
}
$groupmode = ($mode == "group");

// Delete orphaned group overrides.
$manager->delete_orphaned_group_overrides_in_course($course->id);

// Work out what else needs to be displayed.
$addenabled = true;

// Check to see if there are any users or groups that can be added.
// If not, disable the add button.
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
            $addenabled = false;
            $warningmessage = $nousermessage;
        }
    }
}

$table = new overrides_table('Overrides', $mode, $cm, $context, false, $canedit);
$table->define_baseurl(new moodle_url('overrides.php', ['cmid' => $cmid, 'mode' => $mode]));

// Set query to be used by overrides_table.
if ($mode == 'group') {
    $params = ['quizid' => $quiz->id];


    if (!empty($groups)) {
        
        // To filter the result by the list of groups that the current user has access to.
        list($insql, $inparams) = $DB->get_in_or_equal(array_keys($groups), SQL_PARAMS_NAMED);
        $params += $inparams;

        $table->set_sql('o.*, g.name as groupname',
          "{quiz_overrides} o
          JOIN {groups} g ON o.groupid = g.id",
          "o.quiz = :quizid AND g.id $insql",
          $params);

    } else {

        $table->set_sql('o.*, g.name as groupname',
          "{quiz_overrides} o
          JOIN {groups} g ON o.groupid = g.id",
          "1 = 0",
          $params);

    }

} else {
    // Get additional user identity fields (showuseridentity user policy).
    $userfieldsapi = \core_user\fields::for_identity($context)->with_name()->with_userpic();
    $useridentityfields = $userfieldsapi->get_required_fields([\core_user\fields::PURPOSE_IDENTITY]);

    // $fields = '';
    // if (count($useridentityfields) > 0) {
    //     $fields = 'userid, ' . implode(', ', $useridentityfields);
    // } else {
        $fields = 'userid';
    // }

    // Get necessary fields for query.
    $userfieldssql = $userfieldsapi->get_sql('u', true, '', $fields, false);

    // Get ORDER BY.
    list($sort, $params) = users_order_by_sql('u', null, $context, $useridentityfields);

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

    // Required fields from quiz_overrides table - userid retrieved using $userfieldssql.
    $overridefieldssql = 'o.timeopen, o.timeclose, o.timelimit, o.attempts, o.password, o.id';

    $table->set_sql(
        "{$userfieldssql->selects}, {$overridefieldssql}",
        "{quiz_overrides} o
        JOIN {user} u ON o.userid = u.id
        {$userfieldssql->joins}
        $groupsjoin
        ", "o.quiz = :quizid
        $groupswhere", array_merge($params, $userfieldssql->params));
}

// Render the page, print the table.
$url = new moodle_url('/mod/quiz/overrides.php', ['cmid' => $cm->id, 'mode' => $mode]);
$title = get_string('overridesforquiz', 'quiz',
        format_string($quiz->name, true, ['context' => $context]));
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title($title);
$PAGE->set_heading($course->fullname);
$PAGE->activityheader->disable();

// Activate the secondary nav tab.
$PAGE->set_secondary_active_tab("mod_quiz_useroverrides");

// Tertiary navigation.
echo $OUTPUT->header();

// Show success and error messages to the user after import.
if (!empty($SESSION->quiz_import_success)) {
    echo $OUTPUT->notification($SESSION->quiz_import_success, 'success');
    unset($SESSION->quiz_import_success);
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

$downloadurl = moodle_url::make_pluginfile_url(
    $context->id,
    'mod_quiz',
    'overrides',
    $mode,
    null,
    null,
    true,
);

$button = html_writer::link($downloadurl, get_string('overridedownload', 'quiz'), ['class' => 'btn btn-secondary']);

if ($canedit && !$table->isempty) {
    echo html_writer::empty_tag('br');
    echo $button;
}

if ($table->hasinactive) {
    echo $OUTPUT->notification(get_string('inactiveoverridehelp', 'quiz'), 'info', false);
}

if ($warningmessage) {
    echo $OUTPUT->notification($warningmessage, 'error', false);
}

echo html_writer::end_tag('div');

// Finish the page.
echo $OUTPUT->footer();
