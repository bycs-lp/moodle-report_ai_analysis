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
 * Prompt templates JavaScript module.
 *
 * @module     report_ai_analysis/prompt_templates
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery'], function($) {

    /**
     * Initialize prompt templates for dropdown variant.
     *
     * @param {Object} templates Template data indexed by template ID
     */
    var init = function(templates) {
        $('#id_template_selector').on('change', function() {
            var selectedId = $(this).val();
            if (selectedId && templates[selectedId]) {
                $('#id_prompt').val(templates[selectedId].prompt);
            }
        });
    };

    /**
     * Initialize prompt templates for button variant.
     */
    var initButtons = function() {
        $('.prompt-template-btn').on('click', function(e) {
            e.preventDefault();
            var prompt = $(this).data('prompt');
            if (prompt) {
                $('#id_prompt').val(prompt);
            }
        });
    };

    return {
        init: init,
        initButtons: initButtons
    };
});
