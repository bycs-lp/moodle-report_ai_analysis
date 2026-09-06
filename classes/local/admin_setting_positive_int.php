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

namespace report_ai_analysis\local;

/**
 * A strictly positive integer setting with an explicit upper safety bound.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_positive_int extends \admin_setting_configtext {
    /** @var int Largest accepted value. */
    private int $maximum;

    /**
     * Create a bounded setting without silently cleaning an invalid value.
     *
     * @param string $name Component-qualified setting name
     * @param string $visiblename Localised label
     * @param string $description Localised description, including the allowed range
     * @param int $defaultsetting Default value
     * @param int $maximum Inclusive upper bound
     */
    public function __construct(
        string $name,
        string $visiblename,
        string $description,
        int $defaultsetting,
        int $maximum
    ) {
        if ($defaultsetting < 1 || $defaultsetting > $maximum) {
            throw new \coding_exception('The default must be within the positive integer setting range');
        }
        $this->maximum = $maximum;
        parent::__construct($name, $visiblename, $description, $defaultsetting, PARAM_INT, 8);
    }

    /**
     * Reject zero, negatives, fractions, non-numeric input and values exceeding the bound.
     *
     * @param mixed $data Submitted value; kept untyped to match the parent API
     * @return bool|string True on success, otherwise a localised validation error
     */
    public function validate($data): bool|string {
        if (
            !is_scalar($data) || !preg_match('/^[1-9][0-9]*$/D', (string) $data) ||
                strlen((string) $data) > strlen((string) $this->maximum) || (int) $data > $this->maximum
        ) {
            return get_string('error_invalid_limit', 'report_ai_analysis');
        }
        return true;
    }
}
