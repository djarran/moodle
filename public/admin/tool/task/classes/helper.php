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

namespace tool_task;

use core\output\html_writer;

/**
 * Helper methods for task administration.
 *
 * @package    tool_task
 * @copyright  2026 Djarran Cotleanu <djarrancotleanu@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {
    /**
     * Format custom data as preformatted HTML.
     *
     * @param string|null $customdata Raw JSON custom data.
     * @return string
     */
    public static function format_custom_data(?string $customdata): string {
        if (!$customdata) {
            return '';
        }

        $data = json_encode(json_decode($customdata), JSON_PRETTY_PRINT);
        $data = self::truncate_lines($data);
        return html_writer::tag(
            "pre",
            html_writer::tag("small", $data),
            ["class" => "tool-task-custom-data m-0"]
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
        $lines[] = get_string('remaininglines', 'tool_task', $remaining);

        return implode("\n", $lines);
    }
}
