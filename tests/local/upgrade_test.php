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
 * Regression specifications for non-destructive legacy report migration.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis\local;

use context_block;
use context_course;
use context_system;
use core\lock\db_record_lock_factory;
use core\lock\lock_config;
use core\task\manager;
use dml_write_exception;
use local_ai_manager\local\data_wiper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use report_ai_analysis\task\process_analysis_task;
use stdClass;

/**
 * Exercise the migration against installed schema without running upgrade DDL or an analysis worker.
 *
 * The fixtures intentionally insert generation-zero reports using XMLDB defaults. Actual upgrade
 * savepoints, database-matrix execution and full privacy discovery remain integration obligations.
 *
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(upgrade::class)]
#[Group('report_ai_analysis')]
#[Group('local_mbs')]
#[Group('mebis')]
final class upgrade_test extends \advanced_testcase {
    /**
     * Installed schema supplies safe legacy defaults and backfills only course members, and identity discovery accepts
     * validated identities and surviving authors while never widening to a scope's foreign course.
     */
    public function test_schema_membership_backfill_and_identity_discovery(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $othercourse = $generator->create_course();
        $context = context_course::instance($course->id);
        $owner = $generator->create_user();
        $active = $generator->create_user();
        $suspended = $generator->create_user(['suspended' => 1]);
        $expired = $generator->create_user();
        $future = $generator->create_user();
        $disabled = $generator->create_user();
        $roleonly = $generator->create_user();
        $childrole = $generator->create_user();
        $grouponly = $generator->create_user();
        $sitewide = $generator->create_user();
        $foreign = $generator->create_user();

        // Role ID zero prevents the role-assignment fallback from masking broken enrolment coverage.
        $generator->enrol_user($active->id, $course->id, 0);
        $generator->enrol_user($suspended->id, $course->id, 0, 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $generator->enrol_user($expired->id, $course->id, 0, 'manual', 1, 2);
        $generator->enrol_user($future->id, $course->id, 0, 'manual', time() + DAYSECS);
        $generator->enrol_user($foreign->id, $othercourse->id, 0);
        // Manual enrolment deliberately permits only one instance, already created above.
        $self = enrol_get_plugin('self');
        $instanceid = $self->add_instance($course, ['status' => ENROL_INSTANCE_DISABLED]);
        $instance = $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
        $self->enrol_user($instance, $disabled->id, 0);
        $generator->role_assign('teacher', $roleonly->id, $context->id);
        $block = $generator->create_block('html', ['parentcontextid' => $context->id]);
        $generator->role_assign('teacher', $childrole->id, context_block::instance($block->id)->id);
        $generator->role_assign('manager', $sitewide->id, context_system::instance()->id);
        $group = $generator->create_group(['courseid' => $course->id]);
        // The group API requires enrolment; role ID zero keeps this candidate independent of roles.
        $generator->enrol_user($grouponly->id, $course->id, 0);
        $this->assertTrue($generator->create_group_member(['groupid' => $group->id, 'userid' => $grouponly->id]));
        $groupenrolment = $DB->get_record('user_enrolments', ['userid' => $grouponly->id], '*', MUST_EXIST);
        // Model a historical leftover: unenrol_user() would also delete the group membership.
        $DB->delete_records('user_enrolments', ['id' => $groupenrolment->id]);
        $this->assertFalse($DB->record_exists('user_enrolments', ['userid' => $grouponly->id]));
        $this->assertFalse($DB->record_exists('role_assignments', ['userid' => $grouponly->id]));
        $this->assertTrue($DB->record_exists('groups_members', [
            'groupid' => $group->id, 'userid' => $grouponly->id,
        ]));
        $report = $this->create_report($context->id, $owner->id, [
            'raw_data' => null,
            'scope_details' => '{"filters":{"participants":[],"roles":[],"groups":[]}}',
        ]);

        // Fresh records adopt safe legacy defaults.
        $this->assertSame(0, (int) $report->runversion);
        $this->assertSame(0, (int) $report->legacydata);

        $this->migrate_all();

        $this->assert_subjects($report->id, [
            $owner->id, $active->id, $suspended->id, $expired->id, $future->id,
            $disabled->id, $roleonly->id, $childrole->id, $grouponly->id,
        ]);
        $this->assertNull($DB->get_field('report_ai_analysis_reports', 'raw_data', ['id' => $report->id]));
        $subjects = $DB->get_records('report_ai_analysis_users', ['reportid' => $report->id]);
        $anysubject = reset($subjects);
        $this->assertNull($anysubject->source_data);
        $this->assertNull($anysubject->ai_result);

        // The installed subject table enforces one row per report/user pair.
        try {
            $DB->insert_record('report_ai_analysis_users', (object) ['reportid' => $report->id, 'userid' => $owner->id]);
            $this->fail('Duplicate report/user association must be rejected by the unique index');
        } catch (dml_write_exception $exception) {
            $this->assertInstanceOf(dml_write_exception::class, $exception);
        }

        // Only existing positive IDs are accepted; arbitrary JSON and name-only matches are not identities.
        $course = $generator->create_course();
        $owner = $generator->create_user();
        $selected = $generator->create_user();
        $legacy = $generator->create_user();
        $headeruser = $generator->create_user();
        $deleted = $generator->create_user();
        $nameonly = $generator->create_user(['firstname' => 'Name', 'lastname' => 'Only']);
        $DB->set_field('user', 'deleted', 1, ['id' => $deleted->id]);
        $scope = (object) ['filters' => (object) [
            'participants' => [
                $selected->id, (string) $selected->id, $deleted->id, true, false, null,
                1.5, 0, -1, '1e3', '999999999999999999999999999999999999',
                $nameonly->id . ' OR 1=1', ['id' => $nameonly->id], (object) ['userid' => $nameonly->id],
                99999999,
            ],
            'students' => [(string) $legacy->id],
        ]];
        $raw = "User: Former author (ID: {$headeruser->id})\n"
            . "Nutzer: Former author (ID: {$headeruser->id})\n"
            . "Started by: Former author (ID: {$headeruser->id})\n"
            . "Gestartet von: Former author (ID: {$headeruser->id})\n"
            . "[POST by Name Only at 2020-01-01 10:00:00]\n"
            . "Message: arbitrary body (ID: {$nameonly->id})\n"
            . "User: Bogus identifier (ID: 99999999)\n";
        $report = $this->create_report(context_course::instance($course->id)->id, $owner->id, [
            'scope_details' => json_encode($scope, JSON_THROW_ON_ERROR),
            'raw_data' => $raw,
            'ai_result' => '<p>Legacy HTML inside the Markdown fallback</p>',
        ]);

        $this->migrate_all();

        $this->assert_subjects($report->id, [$owner->id, $selected->id, $legacy->id, $headeruser->id, $deleted->id]);
        $stored = $DB->get_record('report_ai_analysis_reports', ['id' => $report->id], '*', MUST_EXIST);
        $this->assertSame($raw, $stored->raw_data);
        $this->assertSame($report->ai_result, $stored->ai_result);
        $this->assertSame((int) FORMAT_MARKDOWN, (int) $stored->resultformat);
        $this->assertSame(1, (int) $stored->legacydata);

        // Surviving forum authors and chat log owners remain candidates after unenrolment or source-context loss.
        $this->setAdminUser();
        $sourcecourse = $generator->create_course();
        $othercourse = $generator->create_course();
        $sourcecontext = context_course::instance($sourcecourse->id);
        $othercontext = context_course::instance($othercourse->id);
        $sourceowner = $generator->create_user();
        $starter = $generator->create_user();
        $replyauthor = $generator->create_user();
        $chatuser = $generator->create_user();
        $pathuser = $generator->create_user();
        $foreign = $generator->create_user();
        $nonchat = $generator->create_user();
        $othercomponent = $generator->create_user();
        $forum = $generator->create_module('forum', ['course' => $sourcecourse->id]);
        /** @var \mod_forum_generator $forumgenerator */
        $forumgenerator = $generator->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $sourcecourse->id, 'forum' => $forum->id, 'userid' => $starter->id,
        ]);
        $post = $forumgenerator->create_post([
            'discussion' => $discussion->id, 'parent' => $discussion->firstpost, 'userid' => $replyauthor->id,
        ]);
        $generator->enrol_user($replyauthor->id, $sourcecourse->id, 'student');
        $instance = $DB->get_record('enrol', ['courseid' => $sourcecourse->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        enrol_get_plugin('manual')->unenrol_user($instance, $replyauthor->id);
        $block = $generator->create_block('html', ['parentcontextid' => $sourcecontext->id]);
        $blockcontext = context_block::instance($block->id);
        $pathblock = $generator->create_block('html', ['parentcontextid' => $sourcecontext->id]);
        $pathcontext = context_block::instance($pathblock->id);
        /** @var \local_ai_manager_generator $loggenerator */
        $loggenerator = $generator->get_plugin_generator('local_ai_manager');
        $loggenerator->create_request_log_entry([
            'userid' => $chatuser->id, 'contextid' => $blockcontext->id, 'coursecontextid' => $sourcecontext->id,
            'component' => 'block_ai_chat', 'purpose' => 'chat', 'deleted' => 1,
        ]);
        $loggenerator->create_request_log_entry([
            'userid' => $pathuser->id, 'contextid' => $pathcontext->id, 'coursecontextid' => null,
            'component' => 'block_ai_chat', 'purpose' => 'chat',
        ]);
        $loggenerator->create_request_log_entry([
            'userid' => $foreign->id, 'contextid' => $othercontext->id, 'coursecontextid' => $othercontext->id,
            'component' => 'block_ai_chat', 'purpose' => 'chat',
        ]);
        $loggenerator->create_request_log_entry([
            'userid' => $nonchat->id, 'contextid' => $sourcecontext->id, 'coursecontextid' => $sourcecontext->id,
            'component' => 'block_ai_chat', 'purpose' => 'singleprompt',
        ]);
        $loggenerator->create_request_log_entry([
            'userid' => $othercomponent->id, 'contextid' => $sourcecontext->id, 'coursecontextid' => $sourcecontext->id,
            'component' => 'report_ai_analysis', 'purpose' => 'chat',
        ]);
        // Deliberately model a historical context orphan without triggering current deletion hooks.
        $DB->delete_records('context', ['id' => $blockcontext->id]);
        $originalpost = $DB->get_record('forum_posts', ['id' => $post->id], '*', MUST_EXIST);
        $originallogs = $DB->get_records('local_ai_manager_request_log', null, 'id ASC');
        $sourcereport = $this->create_report($sourcecontext->id, $sourceowner->id, [
            'raw_data' => null,
            'scope_details' => '{"filters":{"participants":[],"sources":[]}}',
        ]);

        $this->migrate_all();

        $this->assert_subjects($sourcereport->id, [$sourceowner->id, $starter->id, $replyauthor->id, $chatuser->id, $pathuser->id]);
        $this->assertEquals($originalpost, $DB->get_record('forum_posts', ['id' => $post->id], '*', MUST_EXIST));
        foreach ($originallogs as $log) {
            if ($log->component === 'report_ai_analysis' && empty($log->itemid)) {
                $log->userid = null;
                $log->prompttext = data_wiper::ANONYMIZE_STRING;
                $log->promptcompletion = data_wiper::ANONYMIZE_STRING;
                $log->requestoptions = data_wiper::ANONYMIZE_STRING;
            }
        }
        $this->assertEquals($originallogs, $DB->get_records('local_ai_manager_request_log', null, 'id ASC'));

        // A scope course ID cannot redirect membership discovery to another course.
        $conflictcourse = $generator->create_course();
        $conflictother = $generator->create_course();
        $conflictowner = $generator->create_user();
        $member = $generator->create_user();
        $conflictforeign = $generator->create_user();
        $generator->enrol_user($member->id, $conflictcourse->id, 0);
        $generator->enrol_user($conflictforeign->id, $conflictother->id, 0);
        $conflictscope = '{"courseid":' . $conflictother->id . ',"filters":{}}';
        $conflictreport = $this->create_report(
            context_course::instance($conflictcourse->id)->id,
            $conflictowner->id,
            ['scope_details' => $conflictscope]
        );

        $this->migrate_all();

        $this->assert_subjects($conflictreport->id, [$conflictowner->id, $member->id]);
        $storedconflict = $DB->get_field('report_ai_analysis_reports', 'scope_details', ['id' => $conflictreport->id]);
        $this->assertSame($conflictscope, $storedconflict);
    }

    /**
     * Scope text is canonicalised without widening; orphans and legacy generations preserve content; queue cleanup
     * stays task-scoped and resumable.
     */
    public function test_scope_content_and_queue_migration(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $this->getDataGenerator()->create_course();
        $context = context_course::instance($course->id);
        $owner = $this->getDataGenerator()->create_user();
        $subject = $this->getDataGenerator()->create_user();

        // Missing course IDs and legacy aliases become usable without discarding original keys.
        $restorescope = (object) [
            'analysis_mode' => 'individual',
            'filters' => (object) [
                'students' => [(int) $subject->id], 'roleids' => [3], 'groups' => [],
                'sources' => [], 'timestart' => 123, 'custom' => 'Keep this legacy value',
            ],
        ];
        $restore = $this->create_report($context->id, $owner->id, [
            'scope_details' => json_encode($restorescope, JSON_THROW_ON_ERROR),
        ]);

        // Explicit empty canonical filters and time ranges take precedence over contradictory aliases.
        $canonicalscope = json_encode((object) [
            'courseid' => (int) $course->id,
            'filters' => (object) [
                'participants' => [], 'students' => [$subject->id], 'roles' => [], 'roleids' => [3],
                'timerange' => (object) ['start' => 0, 'end' => 20], 'timestart' => 50, 'timeend' => 60,
            ],
        ], JSON_THROW_ON_ERROR);
        $canonical = $this->create_report($context->id, $owner->id, ['scope_details' => $canonicalscope]);

        // An empty filter array becomes an object without creating an empty participant restriction.
        $emptyarray = $this->create_report($context->id, $owner->id, ['scope_details' => '{"filters":[]}']);

        // Legacy open, closed and invalid time ranges are canonicalised or preserved individually.
        $legacytimeranges = [
            'start only' => [['timestart' => 10], ['start' => 10, 'end' => 0]],
            'end only' => [['timeend' => 20], ['start' => 0, 'end' => 20]],
            'closed' => [['timestart' => 10, 'timeend' => 20], ['start' => 10, 'end' => 20]],
            'zero' => [['timestart' => 0, 'timeend' => 0], ['start' => 0, 'end' => 0]],
            'reversed' => [['timestart' => 20, 'timeend' => 10], null],
            'negative' => [['timestart' => -1, 'timeend' => 10], null],
            'null is not absent' => [['timestart' => null, 'timeend' => 10], null],
            'string is not a timestamp' => [['timeend' => 'unknown'], null],
        ];
        $timecases = [];
        foreach ($legacytimeranges as $name => [$filters, $expected]) {
            $timecases[$name] = [
                'report' => $this->create_report($context->id, $owner->id, [
                    'scope_details' => json_encode(
                        (object) ['courseid' => (int) $course->id, 'filters' => (object) $filters],
                        JSON_THROW_ON_ERROR
                    ),
                ]),
                'filters' => $filters,
                'expected' => $expected,
            ];
        }

        // Invalid or non-object scope text is preserved rather than replaced with a broader selection.
        $invalidscopes = [
            'malformed' => '{"filters":',
            'array' => '[]',
            'null' => 'null',
            'string' => '"unrecognised legacy scope"',
        ];
        $invalidcases = [];
        foreach ($invalidscopes as $name => $scope) {
            $invalidcases[$name] = [
                'report' => $this->create_report($context->id, $owner->id, ['scope_details' => $scope]),
                'scope' => $scope,
            ];
        }

        $this->migrate_all();

        $storedrestore = json_decode($DB->get_field('report_ai_analysis_reports', 'scope_details', ['id' => $restore->id]));
        $expectedrestore = clone $restorescope;
        $expectedrestore->courseid = (int) $course->id;
        $expectedrestore->filters = clone $restorescope->filters;
        $expectedrestore->filters->participants = $restorescope->filters->students;
        $expectedrestore->filters->roles = [3];
        $expectedrestore->filters->timerange = (object) ['start' => 123, 'end' => 0];
        $this->assertEquals($expectedrestore, $storedrestore);

        // A privacy candidate is not an instruction to include that user in a future run.
        $this->assertSame(
            $canonicalscope,
            $DB->get_field('report_ai_analysis_reports', 'scope_details', ['id' => $canonical->id])
        );
        $this->assert_subjects($canonical->id, [$owner->id, $subject->id]);

        $storedempty = json_decode($DB->get_field('report_ai_analysis_reports', 'scope_details', ['id' => $emptyarray->id]));
        $this->assertInstanceOf(stdClass::class, $storedempty->filters);
        $this->assertFalse(property_exists($storedempty->filters, 'participants'));
        $this->assertSame((int) $course->id, $storedempty->courseid);

        foreach ($timecases as $name => $case) {
            $stored = json_decode($DB->get_field('report_ai_analysis_reports', 'scope_details', ['id' => $case['report']->id]));
            foreach ($case['filters'] as $key => $value) {
                $this->assertSame($value, $stored->filters->{$key}, $name);
            }
            if ($case['expected'] === null) {
                $this->assertFalse(property_exists($stored->filters, 'timerange'), $name);
            } else {
                $this->assertEquals((object) $case['expected'], $stored->filters->timerange, $name);
            }
        }

        foreach ($invalidcases as $name => $case) {
            $reportid = $case['report']->id;
            $storedscope = $DB->get_field('report_ai_analysis_reports', 'scope_details', ['id' => $reportid]);
            $this->assertSame($case['scope'], $storedscope, $name);
            $this->assert_subjects($reportid, [$owner->id]);
            $legacyflag = (int) $DB->get_field('report_ai_analysis_reports', 'legacydata', ['id' => $reportid]);
            $this->assertSame(1, $legacyflag, $name);
        }

        // Missing contexts/courses and non-course contexts do not justify erasure or reparenting.
        $orphankinds = [
            'missing context' => 'context',
            'missing course' => 'course',
            'system context' => 'system',
        ];
        $orphancases = [];
        foreach ($orphankinds as $name => $kind) {
            $orphancourse = $generator->create_course();
            $orphancontext = context_course::instance($orphancourse->id);
            $orphanowner = $generator->create_user();
            $orphansubject = $generator->create_user();
            $unrelated = $generator->create_user();
            $generator->enrol_user($unrelated->id, $orphancourse->id, 0);
            $scope = '{"courseid":' . $orphancourse->id . ',"filters":{"participants":[' . $orphansubject->id . ']}}';
            $contextid = $kind === 'system' ? context_system::instance()->id : $orphancontext->id;
            $report = $this->create_report($contextid, $orphanowner->id, ['scope_details' => $scope, 'status' => 'pending']);
            if ($kind === 'context') {
                $DB->delete_records('context', ['id' => $orphancontext->id]);
            } else if ($kind === 'course') {
                // Do not use delete_course(): this fixture models data left by the old missing lifecycle hook.
                $DB->delete_records('course', ['id' => $orphancourse->id]);
            }
            $orphancases[$name] = ['report' => $report, 'scope' => $scope, 'owner' => $orphanowner, 'subject' => $orphansubject];
        }

        $this->migrate_all();

        foreach ($orphancases as $name => $case) {
            $report = $case['report'];
            $stored = $DB->get_record('report_ai_analysis_reports', ['id' => $report->id], '*', MUST_EXIST);
            $this->assertSame($report->contextid, $stored->contextid, $name);
            $this->assertSame($case['scope'], $stored->scope_details, $name);
            $this->assertSame($report->raw_data, $stored->raw_data, $name);
            $this->assertSame($report->ai_result, $stored->ai_result, $name);
            $this->assertSame('cancelled', $stored->status, $name);
            $this->assertSame(1, (int) $stored->legacydata, $name);
            $this->assert_subjects($report->id, [$case['owner']->id, $case['subject']->id]);
        }

        // Only pending/running statuses change; result text and historical metadata are not reset.
        $statuscourse = $generator->create_course();
        $statuscontext = context_course::instance($statuscourse->id);
        $statusowner = $generator->create_user();
        $legacystatuses = [
            'pending' => ['pending', 'cancelled'],
            'running' => ['running', 'cancelled'],
            'completed' => ['completed', 'completed'],
            'failed' => ['failed', 'failed'],
            'cancelled' => ['cancelled', 'cancelled'],
        ];
        $statuscases = [];
        foreach ($legacystatuses as $name => [$status, $expectedstatus]) {
            $statuscases[$name] = [
                'report' => $this->create_report($statuscontext->id, $statusowner->id, [
                    'status' => $status,
                    'scope_details' => '{"courseid":' . $statuscourse->id . ',"filters":{}}',
                    'error_message' => 'Historical message', 'error_details' => 'Historical diagnostic',
                    'error_code' => 'error_ai_request',
                ]),
                'expected' => $expectedstatus,
            ];
        }

        $this->migrate_all();

        foreach ($statuscases as $name => $case) {
            $report = $case['report'];
            $stored = $DB->get_record('report_ai_analysis_reports', ['id' => $report->id], '*', MUST_EXIST);
            $this->assertSame($case['expected'], $stored->status, $name);
            $expected = (array) $report;
            $actual = (array) $stored;
            foreach (['runversion', 'legacydata', 'status', 'timemodified'] as $key) {
                unset($expected[$key], $actual[$key]);
            }
            $this->assertSame($expected, $actual, $name);
        }

        // Repeating migration cannot overwrite existing attribution or add people enrolled after the migration.
        $idemcourse = $generator->create_course();
        $idemcontext = context_course::instance($idemcourse->id);
        $idemowner = $generator->create_user();
        $idemsubject = $generator->create_user();
        $later = $generator->create_user();
        $idemreport = $this->create_report($idemcontext->id, $idemowner->id, [
            'scope_details' => '{"filters":{"participants":[' . $idemsubject->id . ']}}',
        ]);
        $existingid = $DB->insert_record('report_ai_analysis_users', (object) [
            'reportid' => $idemreport->id,
            'userid' => $idemsubject->id,
            'source_data' => 'Existing source',
            'ai_result' => 'Existing result',
        ]);
        $existing = $DB->get_record('report_ai_analysis_users', ['id' => $existingid], '*', MUST_EXIST);

        $this->migrate_all();
        $snapshot = $DB->get_record('report_ai_analysis_reports', ['id' => $idemreport->id], '*', MUST_EXIST);
        $maps = $DB->get_records('report_ai_analysis_users', ['reportid' => $idemreport->id], 'id ASC');
        $generator->enrol_user($later->id, $idemcourse->id, 0);
        $this->migrate_all();

        $this->assertEquals($snapshot, $DB->get_record('report_ai_analysis_reports', ['id' => $idemreport->id], '*', MUST_EXIST));
        $this->assertEquals($maps, $DB->get_records('report_ai_analysis_users', ['reportid' => $idemreport->id], 'id ASC'));
        $this->assertEquals($existing, $DB->get_record('report_ai_analysis_users', ['id' => $existingid], '*', MUST_EXIST));
        $this->assert_subjects($idemreport->id, [$idemowner->id, $idemsubject->id]);

        // Queue cleanup stays task-scoped and resumable: keyset cursors continue beyond the first bounded batch.
        // Isolate this section's keyset counts from the reports migrated by the earlier blocks.
        $DB->delete_records('report_ai_analysis_users');
        $DB->delete_records('report_ai_analysis_reports');
        $DB->delete_records('task_adhoc');
        $owner = $generator->create_user();
        $contextid = context_system::instance()->id;
        $reports = [];
        $tasks = [];
        for ($index = 0; $index < 102; $index++) {
            $report = $this->create_report($contextid, $owner->id);
            $reports[] = (int) $report->id;
            $tasks[] = $this->queue_task($owner->id, ['reportid' => $report->id]);
        }
        $cursor = upgrade::migrate_legacy_reports();
        $this->assertSame($reports[99], $cursor);
        $this->assertSame(100, $DB->count_records('report_ai_analysis_reports', ['legacydata' => 1]));
        $cursor = upgrade::migrate_legacy_reports($cursor);
        $this->assertSame($reports[101], $cursor);
        $this->assertSame(0, upgrade::migrate_legacy_reports($cursor));
        $this->assertSame(102, $DB->count_records('report_ai_analysis_users'));
        $cursor = upgrade::remove_legacy_tasks();
        $this->assertSame($tasks[99], $cursor);
        $this->assertSame(2, $DB->count_records('task_adhoc'));
        $cursor = upgrade::remove_legacy_tasks($cursor);
        $this->assertSame($tasks[101], $cursor);
        $this->assertSame(0, upgrade::remove_legacy_tasks($cursor));
        $this->assertSame(0, $DB->count_records('task_adhoc'));

        // Cleanup only removes obsolete plugin tasks, never running, positive-generation or foreign entries.
        $legacy = $this->queue_task($owner->id, ['reportid' => 10]);
        $zero = $this->queue_task($owner->id, ['reportid' => 10, 'runversion' => 0]);
        $malformed = $this->queue_task($owner->id, ['reportid' => 11]);
        $DB->set_field('task_adhoc', 'customdata', '{invalid', ['id' => $malformed]);
        $running = $this->queue_task($owner->id, ['reportid' => 12]);
        $DB->set_field('task_adhoc', 'timestarted', time(), ['id' => $running]);
        $modern = $this->queue_task($owner->id, ['reportid' => 13, 'runversion' => 1]);
        $foreign = $this->queue_task($owner->id, ['reportid' => 14]);
        $DB->set_field('task_adhoc', 'component', 'core', ['id' => $foreign]);
        $otherclass = $this->queue_task($owner->id, ['reportid' => 15]);
        $DB->set_field('task_adhoc', 'classname', '\\report_ai_analysis\\task\\unrelated_task', ['id' => $otherclass]);
        $retained = $DB->get_records_list('task_adhoc', 'id', [$running, $modern, $foreign, $otherclass], 'id ASC');

        $this->migrate_all();
        $this->migrate_all();

        foreach ([$legacy, $zero, $malformed] as $taskid) {
            $this->assertFalse($DB->record_exists('task_adhoc', ['id' => $taskid]));
        }
        $this->assertEquals($retained, $DB->get_records('task_adhoc', null, 'id ASC'));

        // A core task lock protects even a queued row whose timestarted field has not yet been set.
        // Use non-reentrant row locks for a deterministic single-process test on both database families.
        // PostgreSQL advisory locks are reentrant across factory instances sharing one database connection.
        $CFG->lock_factory = db_record_lock_factory::class;
        $lockedtask = $this->queue_task($owner->id, ['reportid' => 10]);
        $factory = lock_config::get_lock_factory('cron');
        $lock = $factory->get_lock('adhoc_' . $lockedtask, 0);
        $this->assertNotFalse($lock);
        try {
            $this->migrate_all();
            $this->assertTrue($DB->record_exists('task_adhoc', ['id' => $lockedtask]));
        } finally {
            $lock->release();
        }
        $this->migrate_all();
        $this->assertFalse($DB->record_exists('task_adhoc', ['id' => $lockedtask]));
    }

    /**
     * Insert a legacy report directly, allowing the installed XMLDB defaults to supply all new fields.
     *
     * @param int $contextid Stored context ID, including deliberately invalid historical contexts
     * @param int $userid Creator ID
     * @param array $overrides Stored field overrides
     * @return stdClass Complete persisted report with database-normalised types
     */
    private function create_report(int $contextid, int $userid, array $overrides = []): stdClass {
        global $DB;

        $record = (object) ($overrides + [
            'contextid' => $contextid,
            'userid' => $userid,
            'title' => 'Legacy report',
            'scope_details' => '{"filters":{}}',
            'prompt' => 'Keep the old analysis prompt',
            'raw_data' => 'Keep the old source text',
            'ai_result' => '# Keep the old result',
            'ai_model_name' => 'legacy-model',
            'status' => 'completed',
            'execution_time' => 7,
            'token_usage' => 19,
            'retry_count' => 1,
            'timecreated' => 1600000000,
            'timemodified' => 1600000010,
            'timecompleted' => 1600000010,
        ]);
        $id = $DB->insert_record('report_ai_analysis_reports', $record);
        return $DB->get_record('report_ai_analysis_reports', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Queue an actual analysis task without executing it or contacting the AI manager.
     *
     * @param int $userid Queued actor ID
     * @param array $data Deliberate legacy or generation-aware custom data
     * @return int Queued task ID
     */
    private function queue_task(int $userid, array $data): int {
        $task = new process_analysis_task();
        $task->set_component('report_ai_analysis');
        $task->set_userid($userid);
        $task->set_custom_data((object) $data);
        return manager::queue_adhoc_task($task);
    }

    /**
     * Run all data batches without upgrade savepoints, timeout state, schema changes or worker execution.
     */
    private function migrate_all(): void {
        $cursor = 0;
        do {
            $cursor = upgrade::migrate_legacy_reports($cursor);
        } while ($cursor !== 0);
        do {
            $cursor = upgrade::remove_legacy_tasks($cursor);
        } while ($cursor !== 0);
    }

    /**
     * Assert the exact candidate set without depending on database-specific scalar types or record keys.
     *
     * @param int $reportid Report ID
     * @param array $userids Expected candidate user IDs
     */
    private function assert_subjects(int $reportid, array $userids): void {
        global $DB;

        $actual = $DB->get_fieldset_select('report_ai_analysis_users', 'userid', 'reportid = :reportid', ['reportid' => $reportid]);
        $actual = array_map('intval', $actual);
        $expected = array_map('intval', $userids);
        sort($actual, SORT_NUMERIC);
        sort($expected, SORT_NUMERIC);
        $this->assertSame($expected, $actual);
    }
}
