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
 * Registry for supported analysis sources.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis;

/**
 * Registry for supported analysis sources.
 *
 * Defines which plugins/components can be analyzed and maintains
 * a whitelist for security and future extensibility.
 *
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class source_registry {
    /**
     * Source type definitions.
     *
     * Structure:
     * - 'type_identifier' => [
     *     'enabled' => bool (optional, default true),
     *     'plugins' => array (optional, whitelist of allowed plugins),
     *     'table' => string (optional, primary database table),
     *     'requires_context' => bool (optional, whether context is needed)
     * ]
     */
    private const SUPPORTED_SOURCES = [
        // Block Plugins (AI Chat).
        'block' => [
            'plugins' => [
                'block_ai_chat',
            ],
            'table' => 'block_instances',
            'requires_context' => true,
        ],

        // Forum discussions and posts.
        'mod_forum' => [
            'plugins' => ['mod_forum'],
            'table' => 'forum_discussions',
            'requires_context' => true,
        ],
    ];

    /**
     * Check if source type is supported and enabled.
     *
     * @param string $sourcetype Source type identifier
     * @return bool True if supported and enabled
     */
    public static function is_supported(string $sourcetype): bool {
        if (!isset(self::SUPPORTED_SOURCES[$sourcetype])) {
            return false;
        }

        $config = self::SUPPORTED_SOURCES[$sourcetype];
        return !isset($config['enabled']) || $config['enabled'] === true;
    }

    /**
     * Check if source type has a plugin whitelist.
     *
     * @param string $sourcetype Source type identifier
     * @return bool True if source type has plugin whitelist
     */
    public static function has_plugin_whitelist(string $sourcetype): bool {
        if (!isset(self::SUPPORTED_SOURCES[$sourcetype])) {
            return false;
        }

        return isset(self::SUPPORTED_SOURCES[$sourcetype]['plugins']);
    }

    /**
     * Check if plugin is allowed for given source type.
     *
     * @param string $sourcetype Source type identifier
     * @param string $pluginname Plugin name
     * @return bool True if plugin is whitelisted
     */
    public static function is_plugin_allowed(string $sourcetype, string $pluginname): bool {
        if (!self::is_supported($sourcetype)) {
            return false;
        }

        $config = self::SUPPORTED_SOURCES[$sourcetype];
        if (!isset($config['plugins'])) {
            return false;
        }

        return in_array($pluginname, $config['plugins'], true);
    }

    /**
     * Get all allowed plugins for a source type.
     *
     * @param string $sourcetype Source type identifier
     * @return array Array of plugin names
     */
    public static function get_allowed_plugins(string $sourcetype): array {
        if (!self::is_supported($sourcetype)) {
            return [];
        }

        $config = self::SUPPORTED_SOURCES[$sourcetype];
        return $config['plugins'] ?? [];
    }

    /**
     * Get all supported source types.
     *
     * Only returns types that have plugin whitelists configured.
     *
     * @return array Array of source type identifiers
     */
    public static function get_supported_types(): array {
        $types = [];
        foreach (self::SUPPORTED_SOURCES as $type => $config) {
            // Only include types that are enabled and have a plugin whitelist.
            if ((!isset($config['enabled']) || $config['enabled'] === true) && isset($config['plugins'])) {
                $types[] = $type;
            }
        }
        return $types;
    }

    /**
     * Get all allowed plugins across all source types.
     *
     * Useful for validation without knowing the source type.
     *
     * @return array Array of all allowed plugin names
     */
    public static function get_all_allowed_plugins(): array {
        $plugins = [];
        foreach (self::SUPPORTED_SOURCES as $type => $config) {
            if (self::is_supported($type) && isset($config['plugins'])) {
                $plugins = array_merge($plugins, $config['plugins']);
            }
        }
        return array_values(array_unique($plugins));
    }

    /**
     * Find source type for a given plugin name.
     *
     * @param string $pluginname Plugin name to look up
     * @return string|null Source type identifier or null if not found
     */
    public static function get_source_type_for_plugin(string $pluginname): ?string {
        foreach (self::SUPPORTED_SOURCES as $type => $config) {
            if (self::is_plugin_allowed($type, $pluginname)) {
                return $type;
            }
        }
        return null;
    }
}
