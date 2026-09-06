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
 * Privacy provider tests.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis;

use context;
use context_course;
use context_system;
use core\di;
use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\database_table;
use core_privacy\local\metadata\types\external_location;
use core_privacy\local\metadata\types\plugintype_link;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\request\content_writer;
use local_ai_manager\local\data_wiper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use report_ai_analysis\privacy\provider;
use report_ai_analysis\local\ai_request_provider;
use report_ai_analysis\task\process_analysis_task;
use report_ai_analysis\task\request_provider;
use stdClass;

/**
 * Privacy discovery, export isolation and deletion integration tests.
 *
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(provider::class)]
#[Group('report_ai_analysis')]
#[Group('local_mbs')]
#[Group('mebis')]
final class privacy_test extends \core_privacy\tests\provider_testcase {
    /**
     * Metadata declares stored fields and external processing; discovery reflects persisted subjects and legacy mappings;
     * exports isolate each subject and omit combined, foreign and technical data.
     */
    public function test_metadata_discovery_and_export_isolation(): void {
        global $CFG, $DB;
        $this->resetAfterTest();

        $collection = new collection('report_ai_analysis');
        $this->assertSame($collection, provider::get_metadata($collection));
        $items = [];
        foreach ($collection->get_collection() as $item) {
            $items[$item->get_name()] = $item;
        }

        $this->assertCount(4, $items);
        foreach (['report_ai_analysis_reports', 'report_ai_analysis_users'] as $table) {
            $this->assertInstanceOf(database_table::class, $items[$table]);
            $columns = array_diff(array_keys($DB->get_columns($table)), ['id']);
            $this->assertEqualsCanonicalizing($columns, array_keys($items[$table]->get_privacy_fields()));
        }
        $this->assertInstanceOf(plugintype_link::class, $items['local_ai_manager']);
        $this->assertEqualsCanonicalizing(
            ['userid', 'contextid', 'itemid', 'prompttext', 'promptcompletion', 'requestoptions', 'timecreated'],
            array_keys($items['local_ai_manager']->get_privacy_fields())
        );
        $this->assertInstanceOf(external_location::class, $items['ai_service']);
        $this->assertEqualsCanonicalizing(['prompt', 'source_data'], array_keys($items['ai_service']->get_privacy_fields()));
        $this->assertTrue(get_string_manager()->string_exists($items['ai_service']->get_summary(), 'report_ai_analysis'));

        // Discovery reflects persisted authors and conservative legacy mappings, surviving unenrolment.
        set_config('store_raw_data', 1, 'report_ai_analysis');
        $owner = $this->getDataGenerator()->create_user();
        $subject = $this->getDataGenerator()->create_user();
        $replyauthor = $this->getDataGenerator()->create_user();
        $excluded = $this->getDataGenerator()->create_user();
        $formerstudent = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $emptycourse = $this->getDataGenerator()->create_course();
        $context = context_course::instance($course->id);
        $othercontext = context_course::instance($othercourse->id);
        $emptycontext = context_course::instance($emptycourse->id);
        $this->getDataGenerator()->enrol_user($excluded->id, $course->id);
        $this->getDataGenerator()->enrol_user($subject->id, $course->id);

        // A running report's subjects are discoverable before the external request completes, ignoring filters.
        $report = $this->create_report($context, $owner->id, [
            'status' => 'running',
            'raw_data' => 'Stored combined data',
            'scope_details' => json_encode(['filters' => ['participants' => [$excluded->id]]]),
        ]);
        $this->add_subject($report, $subject->id, 'Included contribution');
        $this->add_subject($report, $replyauthor->id, 'Included reply');
        $secondreport = $this->create_report($context, $owner->id);
        $this->add_subject($secondreport, $owner->id);
        $this->add_subject($secondreport, $subject->id);
        $otherreport = $this->create_report($othercontext, $replyauthor->id);
        $this->add_subject($otherreport, $subject->id);

        // Conservative legacy associations survive unenrolment and are never inferred from current access.
        $legacy = $this->create_report($context, $owner->id, [
            'legacydata' => 1,
            'scope_details' => json_encode(['filters' => ['students' => [$formerstudent->id]]]),
        ]);
        foreach ([$owner, $subject, $formerstudent] as $user) {
            $this->add_subject($legacy, $user->id);
        }
        $enrolment = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        enrol_get_plugin('manual')->unenrol_user($enrolment, $subject->id);

        $this->assertEqualsCanonicalizing([$context->id], provider::get_contexts_for_userid($owner->id)->get_contextids());
        foreach ([$subject, $replyauthor] as $user) {
            $this->assertEqualsCanonicalizing(
                [$context->id, $othercontext->id],
                provider::get_contexts_for_userid($user->id)->get_contextids()
            );
        }
        $this->assertEqualsCanonicalizing(
            [$context->id],
            provider::get_contexts_for_userid($formerstudent->id)->get_contextids()
        );
        $this->assertSame([], provider::get_contexts_for_userid($excluded->id)->get_contextids());
        $this->assert_context_users($context, [$owner->id, $subject->id, $replyauthor->id, $formerstudent->id]);
        $this->assert_context_users($othercontext, [$subject->id, $replyauthor->id]);
        $this->assert_context_users($emptycontext, []);
        $this->assert_context_users(context_system::instance(), []);

        // Discovery never deletes or modifies any stored report.
        $this->assertEquals($legacy, $DB->get_record('report_ai_analysis_reports', ['id' => $legacy->id], '*', MUST_EXIST));
        $this->assertEquals(3, $DB->count_records('report_ai_analysis_users', ['reportid' => $legacy->id]));

        // Exports isolate each subject's data, omit combined content from creators, and never leak diagnostics.
        // A subject receives only their own row, never a creator's prompt or another subject's data.
        set_config('store_raw_data', 1, 'report_ai_analysis');
        $owner = $this->getDataGenerator()->create_user();
        $subject = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $context = context_course::instance($course->id);
        $othercontext = context_course::instance($othercourse->id);
        $this->setUser($owner);
        $report = $this->create_report($context, $owner->id, [
            'title' => 'PRIVATE_OWNER_TITLE',
            'prompt' => 'PRIVATE_OWNER_PROMPT',
            'scope_details' => json_encode(['filters' => ['participants' => [$subject->id, $other->id]]]),
            'raw_data' => 'PRIVATE_COMBINED_SOURCE',
            'ai_result' => 'PRIVATE_COMBINED_RESULT',
            'error_details' => 'PRIVATE_DIAGNOSTICS',
        ]);
        $this->add_subject($report, $subject->id, 'My included contribution', '<p>My individual assessment</p>');
        $this->add_subject($report, $other->id, 'PRIVATE_OTHER_SOURCE', 'PRIVATE_OTHER_RESULT');
        $unrelated = $this->create_report($context, $other->id);
        $unapproved = $this->create_report($othercontext, $owner->id);
        $this->add_subject($unapproved, $subject->id, 'PRIVATE_UNAPPROVED_SOURCE');

        provider::export_user_data(new approved_contextlist($subject, 'report_ai_analysis', [$context->id]));

        $data = $this->get_export_data($context, $report->id);
        $this->assertSame((int) $report->id, $data->reportid);
        $this->assertSame($report->status, $data->status);
        $this->assertSame('My included contribution', $data->source_data);
        $this->assertSame('<p>My individual assessment</p>', $data->ai_result);
        $this->assertSame(transform::datetime($report->timecreated), $data->timecreated);
        $this->assertSame(transform::datetime($report->timemodified), $data->timemodified);
        $this->assertSame(transform::datetime($report->timecompleted), $data->timecompleted);
        $this->assertSame(get_string('privacy:export:shareddata', 'report_ai_analysis'), $data->shared_data);
        $this->assertEqualsCanonicalizing(
            ['reportid', 'status', 'source_data', 'ai_result', 'shared_data', 'timecreated', 'timemodified', 'timecompleted'],
            array_keys((array) $data)
        );
        $this->assertStringNotContainsString('PRIVATE_', json_encode($data));
        /** @var content_writer $contextwriter */
        $contextwriter = writer::with_context($context);
        $this->assertFalse($contextwriter->has_any_data([
            get_string('privacy:export:reports', 'report_ai_analysis'), (string) $unrelated->id,
        ]));
        /** @var content_writer $otherwriter */
        $otherwriter = writer::with_context($othercontext);
        $this->assertFalse($otherwriter->has_any_data());

        // Creators retain their inputs, but their export is not a copy of the class-wide report.
        writer::reset();
        $ownercontext = context_course::instance($this->getDataGenerator()->create_course()->id);
        $creator = $this->getDataGenerator()->create_user();
        $another = $this->getDataGenerator()->create_user();
        $ownerreport = $this->create_report($ownercontext, $creator->id, [
            'title' => 'My authored title',
            'prompt' => 'My authored instructions',
            'scope_details' => json_encode(['private_selection' => $another->id]),
            'raw_data' => 'DO_NOT_EXPORT_COMBINED_SOURCE',
            'ai_result' => 'DO_NOT_EXPORT_COMBINED_RESULT',
        ]);
        $this->add_subject($ownerreport, $creator->id, 'My own source', '<p>My own result</p>');
        $this->add_subject($ownerreport, $another->id, 'DO_NOT_EXPORT_OTHER_SOURCE', 'DO_NOT_EXPORT_OTHER_RESULT');

        provider::export_user_data(new approved_contextlist($creator, 'report_ai_analysis', [$ownercontext->id]));

        $ownerdata = $this->get_export_data($ownercontext, $ownerreport->id);
        $this->assertSame($ownerreport->title, $ownerdata->title);
        $this->assertSame($ownerreport->prompt, $ownerdata->prompt);
        $this->assertSame(2, $ownerdata->subject_count);
        $this->assertSame('My own source', $ownerdata->source_data);
        $this->assertSame('<p>My own result</p>', $ownerdata->ai_result);
        $this->assertSame((int) $ownerreport->execution_time, $ownerdata->execution_time);
        $this->assertSame((int) $ownerreport->runversion, $ownerdata->runversion);
        $this->assertSame((int) $ownerreport->resultformat, $ownerdata->resultformat);
        $this->assertSame(get_string('privacy:export:shareddata', 'report_ai_analysis'), $ownerdata->shared_data);
        foreach (['scope_details', 'raw_data', 'error_details', 'userid'] as $field) {
            $this->assertFalse(property_exists($ownerdata, $field), $field);
        }
        $this->assertStringNotContainsString('DO_NOT_EXPORT_', json_encode($ownerdata));

        // A conservative legacy mapping must not be mistaken for separable personal content.
        $legacycontext = context_course::instance($this->getDataGenerator()->create_course()->id);
        $legacyowner = $this->getDataGenerator()->create_user();
        $legacysubject = $this->getDataGenerator()->create_user();
        $legacyreport = $this->create_report($legacycontext, $legacyowner->id, ['legacydata' => 1]);
        foreach ([$legacyowner, $legacysubject] as $user) {
            $this->add_subject($legacyreport, $user->id, 'UNSEPARATED_LEGACY_SOURCE', 'UNSEPARATED_LEGACY_RESULT');
        }
        foreach ([$legacyowner, $legacysubject] as $user) {
            writer::reset();
            provider::export_user_data(new approved_contextlist($user, 'report_ai_analysis', [$legacycontext->id]));
            $legacydata = $this->get_export_data($legacycontext, $legacyreport->id);
            $this->assertNull($legacydata->source_data);
            $this->assertNull($legacydata->ai_result);
            $this->assertSame(get_string('privacy:export:legacydata', 'report_ai_analysis'), $legacydata->legacy_data);
            $this->assertStringNotContainsString('UNSEPARATED_', json_encode($legacydata));
            $this->assertFalse(property_exists($legacydata, 'scope_details'));
            $this->assertFalse(property_exists($legacydata, 'raw_data'));
        }

        // Privacy exports never contain diagnostic text, even for a debugging-enabled creator.
        $CFG->debugdeveloper = true;
        $CFG->debugdisplay = true;
        foreach (self::error_code_provider() as [$storedcode, $exportedcode]) {
            writer::reset();
            $errorcontext = context_course::instance($this->getDataGenerator()->create_course()->id);
            $erroruser = $this->getDataGenerator()->create_user();
            $errorreport = $this->create_report($errorcontext, $erroruser->id, [
                'status' => 'failed',
                'error_code' => $storedcode,
                'error_message' => 'PRIVATE_LEGACY_DIAGNOSTICS',
                'error_details' => 'PRIVATE_CONNECTOR_DIAGNOSTICS',
                'timecompleted' => null,
            ]);

            provider::export_user_data(new approved_contextlist($erroruser, 'report_ai_analysis', [$errorcontext->id]));

            $errordata = $this->get_export_data($errorcontext, $errorreport->id);
            $this->assertSame(error_info::get_description($exportedcode), $errordata->error_message);
            $this->assertSame($exportedcode, $errordata->error_code);
            $this->assertNull($errordata->timecompleted);
            $this->assertFalse(property_exists($errordata, 'error_details'));
            $this->assertStringNotContainsString('PRIVATE_', json_encode($errordata));
        }

        // An approved context does not make every report in that context exportable.
        writer::reset();
        $emptycontext = context_course::instance($this->getDataGenerator()->create_course()->id);
        $emptyowner = $this->getDataGenerator()->create_user();
        $emptyunrelated = $this->getDataGenerator()->create_user();
        $emptyreport = $this->create_report($emptycontext, $emptyowner->id);
        $this->add_subject($emptyreport, $emptyowner->id, 'Owner source', 'Owner result');

        provider::export_user_data(new approved_contextlist($emptyowner, 'report_ai_analysis', []));
        provider::export_user_data(new approved_contextlist($emptyunrelated, 'report_ai_analysis', [$emptycontext->id]));

        /** @var content_writer $emptywriter */
        $emptywriter = writer::with_context($emptycontext);
        $this->assertFalse($emptywriter->has_any_data());
    }

    /**
     * Error codes must never become an unfiltered diagnostic side channel.
     *
     * @return array Test cases
     */
    public static function error_code_provider(): array {
        return [
            'known code' => ['error_ai_request', 'error_ai_request'],
            'unknown code' => ['PRIVATE_UNRECOGNISED_CODE', null],
            'missing code' => [null, null],
        ];
    }

    /**
     * Every erasure entrypoint is context- and user-scoped and conservative for legacy data; a successful worker stays
     * private in both plugin exports; orphans survive until system-context erasure.
     */
    public function test_erasure_worker_privacy_and_orphan_lifecycle(): void {
        global $DB;
        $this->resetAfterTest();

        // Single-user deletion removes shared and authored reports, only in approved contexts.
        $owner = $this->getDataGenerator()->create_user();
        $subject = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $context = context_course::instance($this->getDataGenerator()->create_course()->id);
        $othercontext = context_course::instance($this->getDataGenerator()->create_course()->id);
        $shared = $this->create_report($context, $owner->id, ['status' => 'running']);
        $this->add_subject($shared, $subject->id);
        $this->add_subject($shared, $other->id);
        $owned = $this->create_report($context, $subject->id);
        $this->add_subject($owned, $other->id);
        $unrelated = $this->create_report($context, $other->id);
        $this->add_subject($unrelated, $other->id);
        $unapproved = $this->create_report($othercontext, $owner->id);
        $this->add_subject($unapproved, $subject->id);
        $ownedelsewhere = $this->create_report($othercontext, $subject->id);
        $this->add_subject($ownedelsewhere, $subject->id);
        $sharedlog = $this->create_log($shared, ['deleted' => 1]);
        $ownedlog = $this->create_log($owned);
        $unrelatedlog = $this->create_log($unrelated);
        $unapprovedlog = $this->create_log($unapproved);
        $unlinkedlog = $this->create_log($unrelated, ['itemid' => 0]);
        $foreignlog = $this->create_log($shared, ['component' => 'mod_aichat']);
        // This subject has neither report ownership nor a report-management capability.
        $this->setUser($subject);

        $approved = new approved_contextlist($subject, 'report_ai_analysis', [$context->id]);
        provider::delete_data_for_user($approved);
        provider::delete_data_for_user($approved);

        $this->assert_report_deleted($shared);
        $this->assert_report_deleted($owned);
        foreach ([$unrelated, $unapproved, $ownedelsewhere] as $report) {
            $this->assert_report_unchanged($report);
        }
        $this->assert_log_anonymized($sharedlog);
        $this->assert_log_anonymized($ownedlog);
        foreach ([$unrelatedlog, $unapprovedlog, $unlinkedlog, $foreignlog] as $log) {
            $this->assert_log_unchanged($log);
        }
        $this->assertEqualsCanonicalizing([$othercontext->id], provider::get_contexts_for_userid($subject->id)->get_contextids());

        // Batch deletion covers owners and mapped subjects without crossing context or user approvals.
        $owner = $this->getDataGenerator()->create_user();
        $subject = $this->getDataGenerator()->create_user();
        $secondsubject = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $context = context_course::instance($this->getDataGenerator()->create_course()->id);
        $othercontext = context_course::instance($this->getDataGenerator()->create_course()->id);
        $shared = $this->create_report($context, $owner->id);
        $this->add_subject($shared, $subject->id);
        $this->add_subject($shared, $secondsubject->id);
        $owned = $this->create_report($context, $secondsubject->id);
        $this->add_subject($owned, $other->id);
        $unrelated = $this->create_report($context, $other->id);
        $this->add_subject($unrelated, $other->id);
        $unapproved = $this->create_report($othercontext, $subject->id);
        $this->add_subject($unapproved, $secondsubject->id);
        $sharedlog = $this->create_log($shared);
        $ownedlog = $this->create_log($owned);
        $unrelatedlog = $this->create_log($unrelated);
        $unapprovedlog = $this->create_log($unapproved);
        $this->setUser($subject);

        provider::delete_data_for_users(new approved_userlist(
            $context,
            'report_ai_analysis',
            [$subject->id, $secondsubject->id]
        ));

        $this->assert_report_deleted($shared);
        $this->assert_report_deleted($owned);
        $this->assert_report_unchanged($unrelated);
        $this->assert_report_unchanged($unapproved);
        $this->assert_log_anonymized($sharedlog);
        $this->assert_log_anonymized($ownedlog);
        $this->assert_log_unchanged($unrelatedlog);
        $this->assert_log_unchanged($unapprovedlog);
        $this->assert_context_users($context, [$other->id]);

        // Whole-context deletion also cleans unlinked and orphaned analysis logs, but no other component.
        $owner = $this->getDataGenerator()->create_user();
        $subject = $this->getDataGenerator()->create_user();
        $context = context_course::instance($this->getDataGenerator()->create_course()->id);
        $othercontext = context_course::instance($this->getDataGenerator()->create_course()->id);
        $first = $this->create_report($context, $owner->id, ['status' => 'pending']);
        $second = $this->create_report($context, $subject->id, ['legacydata' => 1]);
        $unapproved = $this->create_report($othercontext, $owner->id);
        foreach ([$first, $second, $unapproved] as $report) {
            $this->add_subject($report, $subject->id);
        }
        $logs = [
            $this->create_log($first, ['deleted' => 1]),
            $this->create_log($second),
            $this->create_log($first, ['itemid' => 0]),
            $this->create_log($first, ['itemid' => null]),
            $this->create_log($first, ['itemid' => 999999]),
        ];
        $unapprovedlog = $this->create_log($unapproved);
        $foreignlog = $this->create_log($first, ['component' => 'mod_aichat']);

        provider::delete_data_for_all_users_in_context($context);
        provider::delete_data_for_all_users_in_context($context);

        $this->assert_report_deleted($first);
        $this->assert_report_deleted($second);
        $this->assert_report_unchanged($unapproved);
        foreach ($logs as $log) {
            $this->assert_log_anonymized($log);
        }
        $this->assert_log_unchanged($unapprovedlog);
        $this->assert_log_unchanged($foreignlog);
        $this->assert_context_users($context, []);

        // Empty approvals and unrelated users must not trigger a broad deletion or log wipe.
        $owner = $this->getDataGenerator()->create_user();
        $unrelateduser = $this->getDataGenerator()->create_user();
        $context = context_course::instance($this->getDataGenerator()->create_course()->id);
        $report = $this->create_report($context, $owner->id, ['legacydata' => 1]);
        $this->add_subject($report, $owner->id);
        $log = $this->create_log($report);
        $legacylog = $this->create_log($report, ['itemid' => 0]);

        provider::delete_data_for_user(new approved_contextlist($owner, 'report_ai_analysis', []));
        provider::delete_data_for_user(new approved_contextlist($unrelateduser, 'report_ai_analysis', [$context->id]));
        provider::delete_data_for_users(new approved_userlist($context, 'report_ai_analysis', []));
        provider::delete_data_for_users(new approved_userlist($context, 'report_ai_analysis', [$unrelateduser->id]));

        $this->assert_report_unchanged($report);
        $this->assert_log_unchanged($log);
        $this->assert_log_unchanged($legacylog);

        // A legacy subject deletion wipes ambiguous logs only in that context, never modern sibling reports.
        foreach ([false, true] as $batch) {
            $owner = $this->getDataGenerator()->create_user();
            $formerstudent = $this->getDataGenerator()->create_user();
            $other = $this->getDataGenerator()->create_user();
            $context = context_course::instance($this->getDataGenerator()->create_course()->id);
            $othercontext = context_course::instance($this->getDataGenerator()->create_course()->id);
            $legacy = $this->create_report($context, $owner->id, ['legacydata' => 1]);
            $this->add_subject($legacy, $formerstudent->id);
            $modern = $this->create_report($context, $other->id);
            $this->add_subject($modern, $other->id);
            $unapproved = $this->create_report($othercontext, $owner->id, ['legacydata' => 1]);
            $this->add_subject($unapproved, $formerstudent->id);
            $legacylinked = $this->create_log($legacy);
            $unlinked = $this->create_log($legacy, ['itemid' => 0, 'deleted' => 1]);
            $nullitem = $this->create_log($legacy, ['itemid' => null]);
            $modernlog = $this->create_log($modern);
            $unapprovedlog = $this->create_log($unapproved, ['itemid' => 0]);
            $foreignlog = $this->create_log($legacy, ['itemid' => 0, 'component' => 'mod_aichat']);

            if ($batch) {
                provider::delete_data_for_users(new approved_userlist($context, 'report_ai_analysis', [$formerstudent->id]));
            } else {
                provider::delete_data_for_user(new approved_contextlist($formerstudent, 'report_ai_analysis', [$context->id]));
            }

            $this->assert_report_deleted($legacy);
            $this->assert_report_unchanged($modern);
            $this->assert_report_unchanged($unapproved);
            foreach ([$legacylinked, $unlinked, $nullitem] as $log) {
                $this->assert_log_anonymized($log);
            }
            foreach ([$modernlog, $unapprovedlog, $foreignlog] as $log) {
                $this->assert_log_unchanged($log);
            }
        }

        // A successful run stays private in both plugin exports, and orphans survive until system-context erasure.
        $this->setAdminUser();
        require_once(__DIR__ . '/task/fixtures/request_provider.php');

        // A complete successful run cannot leak pupil data through either plugin's teacher export.
        foreach (self::successful_privacy_modes() as [$mode]) {
            writer::reset();
            $generator = $this->getDataGenerator();
            $course = $generator->create_course();
            $context = context_course::instance($course->id);
            $teacher = $generator->create_user();
            $subject = $generator->create_user();
            $other = $generator->create_user();
            $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
            foreach ([$subject, $other] as $user) {
                $generator->enrol_user($user->id, $course->id, 'student');
            }
            $forum = $generator->create_module('forum', ['course' => $course->id]);
            /** @var \mod_forum_generator $forumgenerator */
            $forumgenerator = $generator->get_plugin_generator('mod_forum');
            $discussion = $forumgenerator->create_discussion(['forum' => $forum->id, 'course' => $course->id,
                'userid' => $subject->id, 'message' => 'PUPIL_SOURCE_ONE']);
            $forumgenerator->create_post(['discussion' => $discussion->id, 'parent' => $discussion->firstpost,
                'userid' => $other->id, 'message' => 'PUPIL_SOURCE_TWO']);
            $scope = (new scope_builder($course->id))->set_analysis_mode($mode)->with_sources(['cm_' . $forum->cmid])
                ->filter_by_participants([$subject->id, $other->id]);
            $report = $this->create_report($context, $teacher->id, ['status' => 'pending', 'scope_details' => $scope->build()]);
            $adapter = new request_provider();
            $adapter->onrequest = static fn(): \local_ai_manager\local\prompt_response =>
                request_provider::success('<p>PUPIL_ASSESSMENT</p>');
            di::set(ai_request_provider::class, $adapter);
            $this->setUser($teacher);
            set_config('store_raw_data', 1, 'report_ai_analysis');
            set_config('max_records_per_analysis', 100, 'report_ai_analysis');
            $task = new process_analysis_task();
            $task->set_userid((int) $teacher->id);
            $task->set_custom_data(['reportid' => (int) $report->id, 'runversion' => 1, 'contextid' => (int) $context->id]);
            try {
                $task->execute();
            } finally {
                di::reset_container();
            }
            $this->assertSame('completed', $DB->get_field('report_ai_analysis_reports', 'status', ['id' => $report->id]));
            $this->assertCount($mode === 'individual' ? 2 : 1, $adapter->logids);
            foreach ($adapter->logids as $id) {
                $log = $DB->get_record('local_ai_manager_request_log', ['id' => $id], '*', MUST_EXIST);
                $this->assertNull($log->userid);
                $this->assertSame((int) $report->id, (int) $log->itemid);
                $this->assertSame(12, (int) $log->value);
                foreach (['prompttext', 'promptcompletion', 'requestoptions'] as $field) {
                    $this->assertSame(data_wiper::ANONYMIZE_STRING, $log->{$field});
                }
            }
            // A retained unrelated request proves the manager exporter really ran, not just an empty-context shortcut.
            $ownlog = $this->create_log($report, ['component' => 'unrelated_component', 'itemid' => 99999,
                'prompttext' => 'TEACHER_OWN_REQUEST', 'promptcompletion' => 'TEACHER_OWN_RESULT']);
            provider::export_user_data(new approved_contextlist($teacher, 'report_ai_analysis', [$context->id]));
            \local_ai_manager\privacy\provider::export_user_data(
                new approved_contextlist($teacher, 'local_ai_manager', [$context->id, context_system::instance()->id])
            );
            $export = $this->get_export_data($context, (int) $report->id);
            $this->assertNull($export->source_data);
            $this->assertNull($export->ai_result);
            $this->assertStringNotContainsString('PUPIL_', json_encode($export));
            /** @var content_writer $contextwriter */
            $contextwriter = writer::with_context($context);
            $managerexport = $contextwriter->get_data([
                get_string('pluginname', 'local_ai_manager'),
                get_string('privacy:metadata:local_ai_manager_request_log', 'local_ai_manager'),
            ]);
            $this->assertCount(1, $managerexport->requests);
            $this->assertArrayHasKey($ownlog->id, $managerexport->requests);
            $this->assertStringNotContainsString('PUPIL_', json_encode($managerexport));

            writer::reset();
            provider::export_user_data(new approved_contextlist($subject, 'report_ai_analysis', [$context->id]));
            $export = $this->get_export_data($context, (int) $report->id);
            $this->assertStringContainsString('PUPIL_SOURCE_ONE', $export->source_data);
            $this->assertStringNotContainsString('PUPIL_SOURCE_TWO', json_encode($export));
            $this->assertSame($mode === 'individual' ? '<p>PUPIL_ASSESSMENT</p>' : null, $export->ai_result);
        }

        // Orphans are retained, discovered and exported conservatively until system-context approval.
        foreach (self::orphan_privacy_cases() as [$missing, $deletion]) {
            writer::reset();
            $owner = $this->getDataGenerator()->create_user();
            $subject = $this->getDataGenerator()->create_user();
            $other = $this->getDataGenerator()->create_user();
            $course = $this->getDataGenerator()->create_course();
            $validcourse = $this->getDataGenerator()->create_course();
            $context = context_course::instance($course->id);
            $validcontext = context_course::instance($validcourse->id);
            $system = context_system::instance();
            $orphan = $this->create_report($context, $owner->id, ['legacydata' => 1]);
            $this->add_subject($orphan, $subject->id, 'UNVERIFIED_PERSONAL_SOURCE', 'UNVERIFIED_ASSESSMENT');
            $otherorphan = $this->create_report($context, $other->id, ['legacydata' => 1]);
            $this->add_subject($otherorphan, $other->id);
            $valid = $this->create_report($validcontext, $owner->id);
            $this->add_subject($valid, $subject->id);
            $orphanlog = $this->create_log($orphan);
            $validlog = $this->create_log($valid);
            $DB->delete_records(
                $missing === 'context' ? 'context' : 'course',
                ['id' => $missing === 'context' ? $context->id : $course->id]
            );

            foreach ([$owner, $subject] as $user) {
                $this->assertEqualsCanonicalizing(
                    [$system->id, $validcontext->id],
                    provider::get_contexts_for_userid($user->id)->get_contextids()
                );
            }
            $this->assert_context_users($system, [$owner->id, $subject->id, $other->id]);
            $this->assert_report_unchanged($orphan);
            provider::export_user_data(new approved_contextlist($subject, 'report_ai_analysis', [$system->id]));
            $export = $this->get_export_data($system, (int) $orphan->id);
            $this->assertNull($export->source_data);
            $this->assertNull($export->ai_result);
            $this->assertStringNotContainsString('UNVERIFIED_', json_encode($export));
            $this->assert_report_unchanged($orphan);

            if ($deletion === 'single') {
                provider::delete_data_for_user(new approved_contextlist($subject, 'report_ai_analysis', [$system->id]));
            } else if ($deletion === 'bulk') {
                provider::delete_data_for_users(new approved_userlist($system, 'report_ai_analysis', [$subject->id]));
            } else {
                provider::delete_data_for_all_users_in_context($system);
            }
            $this->assert_report_deleted($orphan);
            $this->assert_log_anonymized($orphanlog);
            $this->assert_report_unchanged($valid);
            $this->assert_log_unchanged($validlog);
            if ($deletion === 'all') {
                $this->assert_report_deleted($otherorphan);
            } else {
                $this->assert_report_unchanged($otherorphan);
            }
            // Reset the shared system-context sink so the next orphan case starts from a clean slate.
            provider::delete_data_for_all_users_in_context($system);
        }
    }

    /**
     * Both success modes must remove the manager's actor-owned copy.
     *
     * @return array Processing modes
     */
    public static function successful_privacy_modes(): array {
        return [['aggregated'], ['individual']];
    }

    /**
     * All Privacy API erasure entrypoints cover both historical orphan types.
     *
     * @return array Orphan kind and deletion entrypoint
     */
    public static function orphan_privacy_cases(): array {
        return [
            ['context', 'single'], ['context', 'bulk'], ['context', 'all'],
            ['course', 'single'], ['course', 'bulk'], ['course', 'all'],
        ];
    }

    /**
     * Create a persisted report with exact nullable values, without scheduling an AI request.
     *
     * @param context $context Report context
     * @param int $userid Report creator
     * @param array $overrides Field overrides
     * @return stdClass Persisted report
     */
    private function create_report(context $context, int $userid, array $overrides = []): stdClass {
        global $DB;
        $record = (object) array_merge([
            'contextid' => $context->id,
            'userid' => $userid,
            'title' => 'Owner-authored title',
            'prompt' => 'Owner-authored prompt',
            'scope_details' => json_encode(['courseid' => $context->instanceid, 'filters' => []]),
            'status' => 'completed',
            'ai_result' => 'Combined assessment',
            'raw_data' => 'Combined source text',
            'ai_model_name' => 'test-model',
            'execution_time' => 2,
            'token_usage' => 30,
            'runversion' => 1,
            'resultformat' => FORMAT_HTML,
            'timecreated' => 1700000000,
            'timemodified' => 1700000002,
            'timecompleted' => 1700000002,
        ], $overrides);
        $id = $DB->insert_record('report_ai_analysis_reports', $record);
        return $DB->get_record('report_ai_analysis_reports', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Persist the actual or conservatively migrated participant association.
     *
     * @param stdClass $report Report record
     * @param int $userid Subject ID
     * @param string|null $source Optional subject-only source data
     * @param string|null $result Optional individual AI result
     */
    private function add_subject(stdClass $report, int $userid, ?string $source = null, ?string $result = null): void {
        global $DB;
        $DB->insert_record('report_ai_analysis_users', (object) [
            'reportid' => $report->id,
            'userid' => $userid,
            'source_data' => $source,
            'ai_result' => $result,
        ]);
    }

    /**
     * Read a report export at its localised, identity-free path.
     *
     * @param context $context Approved context
     * @param int $reportid Report ID
     * @return stdClass Exported report
     */
    private function get_export_data(context $context, int $reportid): stdClass {
        /** @var content_writer $contextwriter */
        $contextwriter = writer::with_context($context);
        return $contextwriter->get_data([
            get_string('privacy:export:reports', 'report_ai_analysis'), (string) $reportid,
        ]);
    }

    /**
     * Assert exact context discovery, with no duplicate creators or participants.
     *
     * @param context $context Context to inspect
     * @param int[] $expected Expected user IDs
     */
    private function assert_context_users(context $context, array $expected): void {
        $userlist = new userlist($context, 'report_ai_analysis');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing($expected, $userlist->get_userids());
    }

    /**
     * Create a log fixture through the AI manager's generator, not an external AI call.
     *
     * @param stdClass $report The associated report
     * @param array $overrides Log field overrides
     * @return stdClass Persisted log record, including database-normalised statistics
     */
    private function create_log(stdClass $report, array $overrides = []): stdClass {
        global $DB;
        /** @var \local_ai_manager_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_ai_manager');
        $log = $generator->create_request_log_entry(array_merge([
            'component' => 'report_ai_analysis',
            'contextid' => $report->contextid,
            'coursecontextid' => $report->contextid,
            'userid' => $report->userid,
            'itemid' => $report->id,
            'purpose' => 'singleprompt',
        ], $overrides));
        return $DB->get_record('local_ai_manager_request_log', ['id' => $log->id], '*', MUST_EXIST);
    }

    /**
     * All associations, including those of other subjects, disappear with an inseparable report.
     *
     * @param stdClass $report Deleted report
     */
    private function assert_report_deleted(stdClass $report): void {
        global $DB;
        $this->assertFalse($DB->record_exists('report_ai_analysis_reports', ['id' => $report->id]));
        $this->assertFalse($DB->record_exists('report_ai_analysis_users', ['reportid' => $report->id]));
    }

    /**
     * An unapproved report and its subject associations must remain intact.
     *
     * @param stdClass $report Original report
     */
    private function assert_report_unchanged(stdClass $report): void {
        global $DB;
        $this->assertEquals($report, $DB->get_record('report_ai_analysis_reports', ['id' => $report->id], '*', MUST_EXIST));
        $this->assertTrue($DB->record_exists('report_ai_analysis_users', ['reportid' => $report->id]));
    }

    /**
     * Only personally identifying fields are changed; all statistics and timestamps survive.
     *
     * @param stdClass $original Original log record
     */
    private function assert_log_anonymized(stdClass $original): void {
        $expected = clone $original;
        $expected->userid = null;
        $expected->prompttext = data_wiper::ANONYMIZE_STRING;
        $expected->promptcompletion = data_wiper::ANONYMIZE_STRING;
        $expected->requestoptions = data_wiper::ANONYMIZE_STRING;
        $this->assert_log_unchanged($expected);
    }

    /**
     * Assert a complete log record is unchanged.
     *
     * @param stdClass $expected Expected log record
     */
    private function assert_log_unchanged(stdClass $expected): void {
        global $DB;
        $actual = $DB->get_record('local_ai_manager_request_log', ['id' => $expected->id], '*', MUST_EXIST);
        $this->assertSame((array) $expected, (array) $actual);
    }
}
