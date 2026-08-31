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

/**
 * Tests for task helper methods.
 *
 * @package    core
 * @category   test
 * @covers     \core\task\helper
 * @copyright  2026 Djarran Cotleanu <djarrancotleanu@catalyst-au.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class helper_test extends \advanced_testcase {
    /**
     * Test that long custom data is truncated.
     */
    public function test_format_custom_data_truncates_long_data(): void {
        $customdata = json_encode(range(1, 110));
        $formatted = helper::format_custom_data($customdata);

        $this->assertStringContainsString(get_string('taskremaininglines', 'admin', 12), $formatted);
        $this->assertStringNotContainsString('    110', $formatted);
    }
}
