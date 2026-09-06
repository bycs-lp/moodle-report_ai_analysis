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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace report_ai_analysis\task;

use context_course;
use core\di;
use local_ai_manager\base_purpose;
use local_ai_manager\local\config_manager;
use local_ai_manager\local\connector_factory;
use local_ai_manager\local\tenant;
use local_ai_manager\local\userinfo;
use local_ai_manager\local\userusage;
use local_ai_manager\plugininfo\aipurpose;
use local_ai_manager\plugininfo\aitool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use report_ai_analysis\local\ai_request_provider;

/**
 * Real manager availability classification without an HTTP request or mocked configuration responses.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(ai_request_provider::class)]
#[Group('report_ai_analysis')]
#[Group('local_mbs')]
#[Group('mebis')]
final class ai_request_provider_test extends \advanced_testcase {
    /**
     * Release tenant-aware injected services.
     */
    protected function tearDown(): void {
        di::reset_container();
        parent::tearDown();
    }

    /**
     * Current persisted consent, quota and purpose state produce safe typed failures.
     *
     * @param string $reason Configuration mutation
     * @param string|null $expected Expected error code, or null for available
     */
    #[DataProvider('availability_provider')]
    public function test_real_availability_classification(string $reason, ?string $expected): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $context = context_course::instance($course->id);
        set_config('requireconfirmtou', 1, 'local_ai_manager');
        set_config('restricttenants', 1, 'local_ai_manager');
        set_config('allowedtenants', tenant::DEFAULT_IDENTIFIER, 'local_ai_manager');
        aipurpose::enable_plugin('singleprompt', true);
        aitool::enable_plugin('chatgpt', true);
        $tenant = new tenant(tenant::DEFAULT_IDENTIFIER);
        di::set(tenant::class, $tenant);
        $config = new config_manager($tenant);
        di::set(config_manager::class, $config);
        $factory = new connector_factory($config);
        di::set(connector_factory::class, $factory);
        $info = new userinfo((int) $USER->id);
        $info->set_role(userinfo::ROLE_BASIC);
        $info->set_scope(userinfo::SCOPE_EVERYWHERE);
        $info->set_locked(false);
        $info->set_confirmed(true);
        $info->store();
        $instance = $factory->get_new_instance('chatgpt');
        $instance->set_name('Availability fixture');
        $instance->set_tenant(tenant::DEFAULT_IDENTIFIER);
        $instance->set_model('gpt-4o');
        $instance->set_endpoint('https://availability-fixture.invalid/v1/chat/completions');
        $instance->set_apikey('synthetic-test-placeholder');
        $instance->set_useglobalapikey(false);
        $instance->store();
        $config->set_config('tenantenabled', '1');
        $config->set_config('singleprompt_max_requests_basic', '2');
        $key = base_purpose::get_purpose_tool_config_key('singleprompt', userinfo::ROLE_BASIC);
        $config->set_config($key, (string) $instance->get_id());
        if ($reason === 'consent') {
            $info->set_confirmed(false);
            $info->store();
        } else if ($reason === 'quota') {
            $usage = new userusage($factory->get_purpose_by_purpose_string('singleprompt'), (int) $USER->id);
            $usage->set_currentusage(2);
            $usage->store();
        } else if ($reason === 'purpose') {
            $config->unset_config($key);
        } else if ($reason === 'disabled purpose') {
            aipurpose::enable_plugin('singleprompt', false);
        } else if ($reason === 'zero allowance') {
            $config->set_config('singleprompt_max_requests_basic', '0');
        } else if ($reason === 'tenant') {
            $config->set_config('tenantenabled', '0');
        }
        $provider = new ai_request_provider();
        if ($expected === null) {
            $this->assertNull($provider->require_available((int) $context->id));
            return;
        }
        try {
            $provider->require_available((int) $context->id);
            $this->fail('Unavailable backend must not pass preflight');
        } catch (\moodle_exception $exception) {
            $this->assertSame($expected, $exception->errorcode);
            $this->assertSame($reason === 'quota' ? 'local_ai_manager' : 'report_ai_analysis', $exception->module);
        }
    }

    /**
     * Configuration states; no translated message matching determines the expected failure.
     *
     * @return array State and expected local/manager code
     */
    public static function availability_provider(): array {
        return [
            ['available', null],
            ['consent', 'error_terms_not_accepted'],
            ['quota', 'error_limitreached'],
            ['purpose', 'error_purposenotconfigured'],
            ['disabled purpose', 'error_purposenotconfigured'],
            ['zero allowance', 'error_purposenotconfigured'],
            ['tenant', 'error_ai_request'],
        ];
    }
}
