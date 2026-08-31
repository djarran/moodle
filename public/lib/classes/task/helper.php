<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace core\task;

use core\check\result;
use core\output\html_writer;

/**
 * Helper methods for tasks.
 *
 * @package    core
 * @copyright  2026 Djarran Cotleanu <djarrancotleanu@catalyst-au.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {
    /**
     * Get the due status for a task from its time delta.
     *
     * @param int $delta Time elapsed since the task's next run time.
     * @return string
     */
    public static function get_due_status(int $delta): string {
        global $CFG;

        $expectedfrequency = $CFG->expectedcronfrequency ?? MINSECS;
        if ($delta > DAYSECS) {
            return result::CRITICAL;
        }
        if ($delta > $expectedfrequency + MINSECS) {
            return result::WARNING;
        }
        return result::OK;
    }

    /**
     * Format custom data as preformatted HTML.
     *
     * @param string|null $customdata Raw JSON custom data.
     * @return string
     */
    public static function format_custom_data(?string $customdata): string {
        if ($customdata === null || $customdata === '') {
            return '';
        }

        $decodeddata = json_decode($customdata);
        $data = json_last_error() === JSON_ERROR_NONE
            ? json_encode($decodeddata, JSON_PRETTY_PRINT)
            : $customdata;
        if ($data === false) {
            $data = $customdata;
        }

        $data = self::truncate_lines($data);
        return html_writer::tag(
            'pre',
            html_writer::tag('small', s($data)),
            ['class' => 'task-custom-data m-0']
        );
    }

    /**
     * Truncate custom data after 100 lines.
     *
     * @param string $data Formatted custom data.
     * @return string
     */
    private static function truncate_lines(string $data): string {
        $maxlines = 100;
        $lines = preg_split('/\R/', $data);
        if ($lines === false || count($lines) <= $maxlines) {
            return $data;
        }

        $remaining = count($lines) - $maxlines;
        $lines = array_slice($lines, 0, $maxlines);
        $lines[] = get_string('taskremaininglines', 'admin', $remaining);

        return implode("\n", $lines);
    }
}
