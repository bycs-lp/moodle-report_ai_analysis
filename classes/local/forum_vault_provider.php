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
 * Forum vault provider wrapper for Dependency Injection.
 *
 * This class wraps static calls to mod_forum\local\container to enable
 * proper Dependency Injection and mocking in unit tests.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis\local;

use mod_forum\local\container as forum_container;
use mod_forum\local\factories\vault as vault_factory;

/**
 * Provider class for forum vault access.
 *
 * This wrapper enables Dependency Injection for forum vault operations,
 * making the code more testable by allowing mocking of forum_container
 * static calls.
 *
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class forum_vault_provider {
    /**
     * Get the forum vault factory.
     *
     * @return vault_factory The vault factory instance.
     */
    public function get_vault_factory(): vault_factory {
        return forum_container::get_vault_factory();
    }

    /**
     * Get the discussions vault.
     *
     * @return \mod_forum\local\vaults\discussion_list The discussions vault.
     */
    public function get_discussions_vault() {
        return $this->get_vault_factory()->get_discussions_in_forum_vault();
    }

    /**
     * Get the post vault.
     *
     * @return \mod_forum\local\vaults\post The post vault.
     */
    public function get_post_vault() {
        return $this->get_vault_factory()->get_post_vault();
    }

    /**
     * Get the forum vault.
     *
     * @return \mod_forum\local\vaults\forum The forum vault.
     */
    public function get_forum_vault() {
        return $this->get_vault_factory()->get_forum_vault();
    }

    /**
     * Get the author vault.
     *
     * @return \mod_forum\local\vaults\author The author vault.
     */
    public function get_author_vault() {
        return $this->get_vault_factory()->get_author_vault();
    }
}
