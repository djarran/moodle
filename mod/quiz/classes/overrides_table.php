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
 * Table for displaying quiz overrides.
 *
 * @package    mod_quiz
 * @copyright  2024 Djarran Cotleanu
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quiz;
defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->libdir . '/tablelib.php');
use table_sql;
use moodle_url;
use html_writer;

/**
 * Table for displaying quiz overrides class.
 *
 * @package    mod_quiz
 * @copyright  2024 Djarran Cotleanu
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class overrides_table extends table_sql {

    /**
     * @var stdClass $cm Stores course module information.
     */
    protected $cm;

    /**
     * @var stdClass $context Stores context information.
     */
    protected $context;

    /**
     * @var boolean $download Indicates if the data should be prepared for download.
     */
    public $download;

    /**
     * @var boolean $isdownload Indicates if the table is in download mode.
     */
    protected $isdownload;

    /**
     * @var boolean $hasinactive Indicates if there are inactive entries in the table.
     */
    public $hasinactive;

    /**
     * Constructor
     * @param int $uniqueid all tables have to have a unique id, this is used
     *      as a key when storing table properties like sort order in the session.
     */
    function __construct($uniqueid, $mode, $cm, $context, $isdownload = null) {

        parent::__construct($uniqueid);

        $this->cm = $cm;
        $this->context = $context;
        $this->isdownload = $isdownload;

        $canedit = true;

        $columns = [];

        if ($isdownload) {
          $columns[] = $mode == 'group' ? 'groupid' : 'userid';
        }

        if ($mode == 'group') {
          $columns[] = 'name';
        } else {
          $columns[] = 'firstname';
        }

        // Define the list of columns to show.
        array_push($columns, 'timeopen', 'timeclose', 'timelimit', 'attempts', 'password');

        if (!$isdownload) {
            $columns[] = 'actions';
        }

        // $columns = array('username', 'password', 'firstname', 'lastname');
        $this->define_columns($columns);

        $headers = [];

        if ($isdownload) {
          $headers[] = $mode == 'group' ? 'Group ID' : 'User ID';
        }

        if ($mode == 'group') {
          $headers[] = 'Group';
        } else {
          $headers[] = 'User';
        }

        // Define the titles of columns to show in header.
        array_push($headers, 'Quiz opens', 'Quiz closes', 'Time limit', 'Attempts', 'Password');

        if (!$isdownload) {
            $headers[] = 'Actions';
        }

        // $headers = array('Username', 'Password', 'First name', 'Last name');
        $this->define_headers($headers);

        $this->collapsible(false);
        $this->sortable(false);
        $this->pageable(true);
        $this->pagesize = 20;
    }

    /**
     * Returns the dimmed CSS class if the override is not active.
     *
     * @param stdClass $row The row data.
     * @return string|null The CSS class for the row.
     */
    function get_row_class($row): ?string {
        if (!$this->is_active($row)) {
            return 'dimmed_text';
        }
        return null;
    }

    /**
     * Checks whether a given override is active.
     *
     * @param stdClass $override The override data object.
     * @return bool True if the override is active, false otherwise.
     */
    function is_active($override): bool {

        $active = true;
        if (!has_capability('mod/quiz:attempt', $this->context, $override->userid)) {

            // User not allowed to take the quiz.
            $active = false;

            // Display message.
            $this->hasinactive = true;
        } else if (!\core_availability\info_module::is_user_visible($this->cm, $override->userid)) {

            // User cannot access the module.
            $active = false;

            // Display message.
            $this->hasinactive = true;
        }

        return $active;
    }

    /**
     * Determines if the table is downloadable.
     *
     * @param bool|null $downloadable Optional parameter to set the download status.
     * @return bool Always returns true.
     */
    public function is_downloadable($downloadable = null): bool {
      return true;
    }

    /**
     * Generates the HTML content for the "firstname" column.
     *
     * @param stdClass $override The override data object.
     * @return string The HTML content for the "firstname" column.
     */
    public function col_firstname($override): string {

        $active = $this->is_active($override);

        $userurl = new moodle_url('/user/view.php', []);
        $extranamebit = $active ? '' : '*';

        return html_writer::link(new moodle_url($userurl, ['id' => $override->userid]),
          fullname($override) . $extranamebit);    
    }

    /**
     * Generates the HTML content for the "name" column.
     *
     * @param stdClass $override The override data object.
     * @return string The HTML content for the "name" column.
     */
    public function col_name($override): string {
        $groupurl = new moodle_url('/group/overview.php', ['id' => $this->cm->course]);
        $active = true;
        $extranamebit = $active ? '' : '*';

        $override->rowclasses[] = 'dimmed_text';

        return html_writer::link(new moodle_url($groupurl, ['group' => $override->groupid]),
            format_string($override->name, true, ['context' => $this->context]) . $extranamebit);
    }
    
    /**
     * Generates the HTML content for the "actions" column.
     *
     * @param stdClass $override The override data object.
     * @return string The HTML content for the "actions" column.
     */
    public function col_actions($override): string {
        global $OUTPUT;

        $overridedeleteurl = new moodle_url('/mod/quiz/overridedelete.php');
        $overrideediturl = new moodle_url('/mod/quiz/overrideedit.php');

        if (true) {
            $actiondiv = html_writer::start_div('actions');

            // Edit button.
            $editurlstr = $overrideediturl->out(true, ['id' => $override->id]);
            $editbutton = html_writer::link($editurlstr, $OUTPUT->pix_icon('t/edit', get_string('edit')),
                    // array('class' => 'btn btn-primary', 'title' => get_string('edit')));
                    array('title' => get_string('edit')));
            $actiondiv .= $editbutton;

            // Duplicate button.
            $copyurlstr = $overrideediturl->out(false,
                    ['id' => $override->id, 'action' => 'duplicate']);
            $copybutton = html_writer::link($copyurlstr, $OUTPUT->pix_icon('t/copy', get_string('copy')),
                    // array('class' => 'btn btn-secondary', 'title' => get_string('copy')));
                    array('title' => get_string('copy')));
            $actiondiv .= $copybutton;

            // Delete button.
            $deleteurlstr = $overridedeleteurl->out(true,
                    ['id' => $override->id, 'sesskey' => sesskey()]);
            $deletebutton = html_writer::link($deleteurlstr, $OUTPUT->pix_icon('t/delete', get_string('delete')),
                    array('title' => get_string('delete')));
            $actiondiv .= $deletebutton;

            $actiondiv .= html_writer::end_div();

            return $actiondiv;
        } else {
            return '';
        }
    }

    /**
     * This function is called for each data row to allow processing of
     * columns which do not have a col_* method.
     *
     * These contain simple validation and formatting checks.
     *
     * @param string $colname The name of the column.
     * @param stdClass $value The value of the column.
     * @return string|null Processed value. Returns NULL if no change has been made.
     */
    function other_cols($colname, $value) {

        // For security reasons we don't want to show the password hash.
        if ($colname == 'password' && !$this->isdownload) {
            return !empty($value->password) ?
              get_string('enabled', 'quiz') : get_string('none', 'quiz'); 
        }

        if ($colname == 'timeopen') {
          if ($this->isdownload) {
            return date('Y-m-d H:i', $value->timeopen);
          }
          return $value->timeopen > 0 ? userdate($value->timeopen) : get_string('noopen', 'quiz');
        }

        if ($colname == 'timeclose') {
          if ($this->isdownload) {
            return date('Y-m-d H:i', $value->timeclose);
          }
          return $value->timeclose > 0 ? userdate($value->timeclose) : get_string('noclose', 'quiz');
        }
        
        if ($colname == 'timelimit') {
          if (!$this->isdownload) {
            return $values[] = $value->timelimit > 0 ?
                    format_time($value->timelimit) : get_string('none', 'quiz');
          }
        }

        if ($colname == 'attempts') {
            return $values[] = $value->attempts > 0 && isset($value->attempts) ?
                    $value->attempts : 'Unlimited';
        }
    }
}
