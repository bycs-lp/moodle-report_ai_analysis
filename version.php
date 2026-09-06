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
 * Version information for report_ai_analysis.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'report_ai_analysis';
$plugin->version = 2026090600;
$plugin->requires = 2025041400; // Moodle 5.0.
$plugin->supported = [500, 502];
$plugin->maturity = MATURITY_ALPHA;
$plugin->release = 'v1.0.0';
$plugin->dependencies = [
    // Verified public request, log, availability and data_wiper contract; earlier releases are not verified.
    'local_ai_manager' => 2026102300,
];
