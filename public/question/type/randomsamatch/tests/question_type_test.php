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

namespace qtype_randomsamatch;

use qtype_randomsamatch;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/question/engine/tests/helpers.php');
require_once($CFG->dirroot . '/question/type/randomsamatch/questiontype.php');

/**
 * Unit tests for the random shortanswer matching question definition class.
 *
 * @package   qtype_randomsamatch
 * @copyright 2025 Dustin Huynh (dustinhuynh@catalyst-au.net)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class question_type_test extends \advanced_testcase {
    public function test_get_random_guess_score(): void {
        $qtype = new qtype_randomsamatch();
        $question = \test_question_maker::make_question('randomsamatch');
        $question->options = new \stdClass();
        $question->options->choose = 2;
        $this->assertEquals(0.5, $qtype->get_random_guess_score($question));
    }
    public function test_get_random_guess_score_broken_question(): void {
        $qtype = new qtype_randomsamatch();
        $question = \test_question_maker::make_question('randomsamatch');
        $this->assertNull($qtype->get_random_guess_score($question));
    }

}
