// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * Shared AI Manager widgets with Moodle's pending lifecycle and safe error feedback.
 *
 * @module     report_ai_analysis/ai_widgets
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Pending from 'core/pending';
import {exception as displayException} from 'core/notification';
import {renderInfoBox} from 'local_ai_manager/infobox';
import {renderUserQuota} from 'local_ai_manager/userquota';
import {renderWarningBox} from 'local_ai_manager/warningbox';

/**
 * Initialize the information and quota widgets near the form.
 *
 * @param {string} selector Container selector
 * @param {number} userId Current user ID required by the public infobox signature
 * @param {string} errorMessage Localized generic error, never a backend exception
 * @returns {Promise<void>}
 */
export const initForm = async(selector, userId, errorMessage) => {
    const root = document.querySelector(selector);
    if (!root) {
        return;
    }
    const mode = document.getElementById('id_analysis_mode');
    const warning = document.getElementById('individual_mode_warning');
    if (mode && warning) {
        const updateWarning = () => {
            warning.hidden = mode.value !== 'individual';
        };
        mode.addEventListener('change', updateWarning);
        updateWarning();
    }

    const pending = new Pending('report_ai_analysis/ai_widgets:form');
    try {
        // Keep the server-rendered information until its enhanced replacement is ready.
        const info = root.querySelector('[data-region="ai-info"]');
        const enhanced = document.createElement('div');
        await renderInfoBox('report_ai_analysis', userId, enhanced, ['singleprompt']);
        info.replaceChildren(enhanced);
        await renderUserQuota(root.querySelector('[data-region="ai-quota"]'), ['singleprompt']);
    } catch {
        await displayException(new Error(errorMessage));
    } finally {
        pending.resolve();
    }
};

/**
 * Enhance the result warning while retaining its no-JavaScript fallback on failure.
 *
 * @param {string} selector Warning container selector
 * @param {string} errorMessage Localized generic error
 * @returns {Promise<void>}
 */
export const initWarning = async(selector, errorMessage) => {
    const target = document.querySelector(selector);
    if (!target) {
        return;
    }
    const pending = new Pending('report_ai_analysis/ai_widgets:warning');
    try {
        const enhanced = document.createElement('div');
        await renderWarningBox(enhanced);
        if (enhanced.hasChildNodes()) {
            target.replaceChildren(enhanced);
        }
    } catch {
        await displayException(new Error(errorMessage));
    } finally {
        pending.resolve();
    }
};