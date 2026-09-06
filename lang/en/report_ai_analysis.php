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
 * Language strings for report_ai_analysis.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['acceptaiterms'] = 'Review and accept AI terms of use';
$string['activities'] = 'Activities';
$string['add_template'] = 'Add new template';
$string['ai_analysis:create'] = 'Create AI analysis reports';
$string['ai_analysis:delete'] = 'Delete AI analysis reports';
$string['ai_analysis:manageall'] = 'Manage other users\' AI analysis reports (requires the action capability)';
$string['ai_analysis:rerun'] = 'Re-run AI analysis reports';
$string['ai_analysis:view'] = 'View AI analysis reports';
$string['ai_analysis:viewall'] = 'View other users\' AI analysis reports when course sharing is disabled';
$string['ai_analysis:viewrawdata'] = 'View raw conversation data in reports';
$string['aimodel'] = 'AI Model';
$string['aiunavailable'] = 'AI analysis is currently unavailable. Check the AI configuration, terms of use and remaining quota.';
$string['allusers'] = 'All users';
$string['analysis_mode'] = 'Analysis mode';
$string['analysis_mode_aggregated'] = 'Aggregated (all participants)';
$string['analysis_mode_help'] = 'Choose the analysis mode:<ul><li><strong>Individual:</strong> Creates separate analyses for each selected participant. Ideal for identifying individual learning difficulties.</li><li><strong>Aggregated:</strong> Creates a summary analysis across all selected participants. Ideal for requirement analyses and overall overviews.</li></ul>';
$string['analysis_mode_individual'] = 'Individual (per participant)';
$string['analysis_result'] = 'Analysis Result';
$string['analysis_truncated'] = 'This analysis is incomplete: source data or the final prompt was shortened to meet a safety limit. Do not treat the result as a complete assessment.';
$string['analysisqueued'] = 'Analysis has been queued for background processing';
$string['cachedef_providers'] = 'Cache for data source providers';
$string['cachedef_role_names'] = 'Course role names';
$string['cachedef_scope_parse'] = 'Parsed analysis scopes';
$string['cancel'] = 'Cancel';
$string['cancelwarning'] = 'Cancelling prevents further processing and discards late results. An AI request that has already been sent cannot be recalled.';
$string['cannotdeleteothersreports'] = 'You cannot delete reports created by other users';
$string['cannoteditrunningreport'] = 'Cannot edit a report that is currently running';
$string['cannotexportreport'] = 'Only completed or failed reports can be exported.';
$string['cannotrerunreport'] = 'This report cannot be re-run. Only completed, failed, or cancelled reports can be re-run.';
$string['confirm_delete_template'] = 'Delete template';
$string['confirm_delete_template_text'] = 'Are you sure you want to delete the template "{$a}"?';
$string['confirmdelete'] = 'Are you sure you want to delete the report "{$a}"? This action cannot be undone.';
$string['confirmdeletion'] = 'Confirm deletion';
$string['context_system'] = 'System';
$string['coresystem'] = 'System';
$string['coursename'] = 'Course';
$string['courses'] = 'Courses';
$string['createanalysis'] = 'Create new analysis';
$string['created'] = 'Created';
$string['dataprivacy_notice'] = 'The selected participants\' permitted contributions and your prompt are sent to the configured AI service through AI Manager. Disabling raw data storage only removes this report\'s source copy; it does not prevent transmission or AI Manager logging. Review the AI terms and your organisation\'s data protection requirements before continuing.';
$string['datasource'] = 'Data source';
$string['datasource_help'] = 'Select accessible forums or AI Chat blocks in this course. An empty source selection includes all supported sources you may access, not other activity types.';
$string['delete'] = 'Delete';
$string['deletereport'] = 'Delete Report';
$string['disable'] = 'Disable';
$string['duration'] = 'Duration';
$string['edit'] = 'Edit';
$string['edit_template'] = 'Edit template';
$string['editreport'] = 'Edit Report';
$string['enable'] = 'Enable';
$string['enable_markdown_conversion'] = 'Enable Markdown rendering';
$string['enable_markdown_conversion_desc'] = 'Render prompts and legacy Markdown results with formatting. New AI Manager results already contain HTML and are cleaned as HTML. If disabled, results are displayed as plain text, without HTML tags.';
$string['error_access_revoked'] = 'Course access or a required analysis permission was revoked. No further analysis can be performed.';
$string['error_ai_chat_not_available'] = 'Block ai_chat is not installed or not enabled';
$string['error_ai_request'] = 'The AI request failed. Please try again later or contact an administrator.';
$string['error_api_connection_error'] = 'Could not connect to AI service';
$string['error_api_timeout'] = 'API request timed out';
$string['error_contextmismatch'] = 'Report does not belong to the specified course context';
$string['error_deleting_template'] = 'Error deleting template';
$string['error_empty_response'] = 'AI service returned empty response';
$string['error_forum_not_available'] = 'Forum module is not installed or not enabled';
$string['error_invalid_limit'] = 'Enter a positive whole number within the range shown in the setting description.';
$string['error_invalid_timerange'] = 'The end time must be on or after the start time. Either boundary may be left disabled.';
$string['error_no_data'] = 'No conversation data found for analysis';
$string['error_prompt_too_long'] = 'Prompt is too long for AI service';
$string['error_prompt_too_short'] = 'Prompt must be at least 10 characters long';
$string['error_purposenotconfigured'] = 'The AI purpose "singleprompt" is not configured. Please contact your administrator.';
$string['error_rate_limit'] = 'AI service rate limit reached';
$string['error_saving_template'] = 'Error saving template';
$string['error_source_failed'] = 'A selected data source could not be collected. The analysis was stopped rather than producing an unmarked partial result.';
$string['error_source_forbidden'] = 'You no longer have permission to analyse one or more selected sources or participants.';
$string['error_task_changed'] = 'This analysis run is no longer current. Its result will not replace the current report.';
$string['error_terms_not_accepted'] = 'You have not yet accepted the AI terms of use. Accept them before re-running the report.';
$string['error_title_too_long'] = 'Title must not exceed 255 characters';
$string['error_title_too_short'] = 'Title must be at least 3 characters long';
$string['error_unknown'] = 'Unknown error occurred';
$string['errorrerunningreport'] = 'Error re-running report';
$string['eventreportdeleted'] = 'AI analysis report deleted';
$string['export'] = 'Export';
$string['export_context_id'] = 'Context ID';
$string['export_conversation_thread'] = 'Conversation Thread';
$string['export_conversations_header'] = 'AI CHAT CONVERSATIONS';
$string['export_course'] = 'Course';
$string['export_created'] = 'Created';
$string['export_discussion'] = 'Discussion';
$string['export_discussions_header'] = 'FORUM DISCUSSIONS';
$string['export_forum'] = 'Forum';
$string['export_messages'] = 'Messages';
$string['export_modified'] = 'Last Modified';
$string['export_posts'] = 'Posts';
$string['export_started_by'] = 'Started by';
$string['export_total_conversations'] = 'Total conversations';
$string['export_total_discussions'] = 'Total discussions';
$string['export_truncated'] = '[truncated]';
$string['export_user'] = 'User';
$string['exportedat'] = 'Exported at';
$string['exportedfrom'] = 'Exported from';
$string['exporthtml'] = 'Export as HTML';
$string['exportjson'] = 'Export as JSON';
$string['groups'] = 'Groups';
$string['includesubcategories'] = 'Include subcategories';
$string['individual_mode_warning'] = 'Warning: In individual mode, a separate analysis will be created for each participant. This may result in high token usage with many participants.';
$string['invalidformat'] = 'Invalid export format specified';
$string['manage_templates'] = 'Manage prompt templates';
$string['max_records_per_analysis'] = 'Maximum records per analysis';
$string['max_records_per_analysis_desc'] = 'From 1 to 10000 records in total across all selected sources and participants. Each forum post or AI Chat request log entry counts as one record. Reaching a collection or prompt limit is shown as truncation.';
$string['metadata'] = 'Analysis Metadata';
$string['newanalysis'] = 'New analysis';
$string['no_templates'] = 'No templates configured yet';
$string['nopermission'] = 'You do not have permission to perform this action';
$string['order'] = 'Order';
$string['participant'] = 'Participant';
$string['participants'] = 'Participants';
$string['pluginname'] = 'AI Conversation Analysis';
$string['privacy:export:legacydata'] = 'This legacy report cannot be reliably separated by person. Shared source data and results are withheld; the saved conservative participant mapping is used for discovery and deletion.';
$string['privacy:export:reports'] = 'AI analysis reports';
$string['privacy:export:shareddata'] = 'Only source data and individual results attributed to you are included. Combined results, shared source data, other participants\' data and technical diagnostics are withheld.';
$string['privacy:metadata:ai_service'] = 'The configured external AI service receives the analysis instructions and permitted source contributions through AI Manager. Its retention rules are separate from this report\'s storage settings.';
$string['privacy:metadata:ai_service:prompt'] = 'The system instructions and report creator\'s analysis prompt sent to the AI service';
$string['privacy:metadata:ai_service:source_data'] = 'Permitted forum posts or AI Chat request/response pairs, including the identities of their authors where included';
$string['privacy:metadata:local_ai_manager'] = 'AI Manager processes and logs analysis requests independently of the report\'s raw data storage setting. Report-linked logs are anonymised through its data wiping API while retaining usage statistics.';
$string['privacy:metadata:local_ai_manager:contextid'] = 'The course context in which the analysis request was made';
$string['privacy:metadata:local_ai_manager:itemid'] = 'The report ID linking the analysis to its AI Manager request logs';
$string['privacy:metadata:local_ai_manager:promptcompletion'] = 'The AI response recorded by AI Manager';
$string['privacy:metadata:local_ai_manager:prompttext'] = 'The complete analysis request, including instructions and selected source contributions';
$string['privacy:metadata:local_ai_manager:requestoptions'] = 'Request options, including the report identifier';
$string['privacy:metadata:local_ai_manager:timecreated'] = 'The time the analysis request was logged';
$string['privacy:metadata:local_ai_manager:userid'] = 'The user executing the analysis, not necessarily the author of the analysed contributions';
$string['privacy:metadata:report_ai_analysis_reports'] = 'Stores AI analysis reports created by users';
$string['privacy:metadata:report_ai_analysis_reports:action'] = 'The action whose permissions must be rechecked for the queued run';
$string['privacy:metadata:report_ai_analysis_reports:ai_model_name'] = 'The AI model used for the analysis';
$string['privacy:metadata:report_ai_analysis_reports:ai_result'] = 'The AI-generated analysis result';
$string['privacy:metadata:report_ai_analysis_reports:contextid'] = 'The course context containing the report';
$string['privacy:metadata:report_ai_analysis_reports:error_code'] = 'The classified error code for a failed analysis';
$string['privacy:metadata:report_ai_analysis_reports:error_details'] = 'Technical diagnostic details recorded for a failed analysis';
$string['privacy:metadata:report_ai_analysis_reports:error_message'] = 'The user-facing description of a failed analysis';
$string['privacy:metadata:report_ai_analysis_reports:execution_time'] = 'The analysis duration in whole seconds';
$string['privacy:metadata:report_ai_analysis_reports:legacydata'] = 'Whether legacy content cannot be reliably separated by person';
$string['privacy:metadata:report_ai_analysis_reports:prompt'] = 'The analysis prompt provided by the user';
$string['privacy:metadata:report_ai_analysis_reports:raw_data'] = 'The raw conversation data analyzed';
$string['privacy:metadata:report_ai_analysis_reports:resultformat'] = 'The format used to interpret the stored AI result';
$string['privacy:metadata:report_ai_analysis_reports:retry_count'] = 'The number of automatic retries in this run';
$string['privacy:metadata:report_ai_analysis_reports:runversion'] = 'The generation number used to prevent stale analysis results from replacing newer data';
$string['privacy:metadata:report_ai_analysis_reports:scope_details'] = 'The course, analysis mode and source, participant, role, group and date selections';
$string['privacy:metadata:report_ai_analysis_reports:status'] = 'The current processing state of the report';
$string['privacy:metadata:report_ai_analysis_reports:timecompleted'] = 'The time processing finished';
$string['privacy:metadata:report_ai_analysis_reports:timecreated'] = 'The time when the report was created';
$string['privacy:metadata:report_ai_analysis_reports:timemodified'] = 'The time the report was last updated';
$string['privacy:metadata:report_ai_analysis_reports:title'] = 'The title of the analysis report';
$string['privacy:metadata:report_ai_analysis_reports:token_usage'] = 'The recorded token usage for the analysis';
$string['privacy:metadata:report_ai_analysis_reports:truncated'] = 'Whether source collection or the final prompt was shortened by a safety limit';
$string['privacy:metadata:report_ai_analysis_reports:userid'] = 'The ID of the user who created the report';
$string['privacy:metadata:report_ai_analysis_users'] = 'Maps reports to the people whose contributions were included, with separately attributable data where available';
$string['privacy:metadata:report_ai_analysis_users:ai_result'] = 'The individual AI result for this participant, or no result for an aggregated analysis';
$string['privacy:metadata:report_ai_analysis_users:reportid'] = 'The report containing data about the participant';
$string['privacy:metadata:report_ai_analysis_users:source_data'] = 'Only this participant\'s included source contributions, when report raw data storage is enabled';
$string['privacy:metadata:report_ai_analysis_users:userid'] = 'The participant whose contributions were included, or a conservatively mapped legacy data subject';
$string['prompt'] = 'Analysis prompt';
$string['prompt_help'] = 'Describe what you want the AI to analyze about the conversation data.';
$string['prompt_preview'] = 'Preview';
$string['prompt_templates'] = 'Prompt templates';
$string['prompt_templates_desc'] = 'Create, edit, enable and reorder reusable prompts using Manage prompt templates. Templates are stored separately, not as a line-based setting.';
$string['purposeplacedescription'] = 'Course reports analysing permitted forum posts and AI Chat entries, either together or in separate requests per participant.';
$string['raw_data'] = 'Raw Conversation Data';
$string['report_actions'] = 'Actions';
$string['report_created'] = 'Created';
$string['report_creator'] = 'Creator';
$string['report_scope'] = 'Scope';
$string['report_status'] = 'Status';
$string['report_title'] = 'Title';
$string['reportcancelled'] = 'Report cancelled successfully';
$string['reportdeleted'] = 'Report deleted successfully';
$string['reportrerunsuccess'] = 'Report has been queued for re-processing';
$string['reportupdated'] = 'Report updated successfully';
$string['reportupdatedandqueued'] = 'Report updated and queued for re-processing';
$string['rerun'] = 'Re-run';
$string['rerunreport'] = 'Re-run Report';
$string['rerunreportconfirm'] = 'Are you sure you want to re-run the report "{$a}"? This will reset the current results and process the analysis again.';
$string['retries'] = 'Retries';
$string['retry_on_failure'] = 'Retry on failure';
$string['retry_on_failure_desc'] = 'Number of automatic retries for transient errors (0 = no retries, max 3)';
$string['roles'] = 'Roles';
$string['scope'] = 'Scope';
$string['select_participants'] = 'Select participants';
$string['select_participants_help'] = 'Select which course participants should be included in the analysis. You can filter by course roles.';
$string['select_roles'] = 'Filter by roles';
$string['select_roles_help'] = 'Select which course roles to include. Leave empty to include all roles.';
$string['select_template'] = 'Choose an example prompt...';
$string['selectstudents'] = 'Select specific students';
$string['share_reports_in_course'] = 'Share reports in course';
$string['share_reports_in_course_desc'] = 'Allow users with the view capability to read other users\' reports in the same course. Sharing never grants editing, re-run, cancel or delete permissions. Raw data still requires the separate view raw data capability.';
$string['show_raw_data'] = 'Show raw data';
$string['sortorder'] = 'Sort order';
$string['sources'] = 'Data sources';
$string['status'] = 'Status';
$string['status_cancelled'] = 'Cancelled';
$string['status_completed'] = 'Completed';
$string['status_failed'] = 'Failed';
$string['status_pending'] = 'Pending';
$string['status_running'] = 'Running';
$string['store_raw_data'] = 'Store raw data';
$string['store_raw_data_desc'] = 'Store a bounded source copy in the report and per-participant rows. Disabling this does not stop transmission to the AI service, storage of results or full request logging by AI Manager.';
$string['students'] = 'Students';
$string['system_prompt'] = 'System prompt';
$string['system_prompt_default'] = 'You are an educational data analyst. Analyze the provided conversation data and provide insights.';
$string['system_prompt_desc'] = 'The system prompt sent to the AI before the user prompt';
$string['task_process_analysis'] = 'Process AI analysis report';
$string['template_created'] = 'Template created successfully';
$string['template_deleted'] = 'Template deleted successfully';
$string['template_enabled'] = 'Enabled';
$string['template_enabled_help'] = 'Disabled templates will not be shown in the form but are not deleted';
$string['template_prompt'] = 'Prompt text';
$string['template_prompt_help'] = 'The prompt text that will be inserted into the analysis form when this template is selected';
$string['template_title'] = 'Template title';
$string['template_title_help'] = 'Short descriptive title for the button or dropdown option';
$string['template_updated'] = 'Template updated successfully';
$string['timecompleted'] = 'Completed';
$string['timeend'] = 'End time';
$string['timerange_help'] = 'Both boundaries are optional and inclusive; a disabled boundary is open. AI Chat includes only request entries within the range. For forums, the range selects threads with a visible post by a selected author in the range; other permitted posts by selected authors in those threads may be older or newer.';
$string['timestart'] = 'Start time';
$string['title'] = 'Title';
$string['title_help'] = 'Optional title for this analysis. If empty, the first 80 characters of the prompt will be used.';
$string['tokenusage'] = 'Token Usage';
$string['truncate_raw_data_length'] = 'Truncate raw data length';
$string['truncate_raw_data_length_desc'] = 'From 1 to 500000 characters per stored source copy (default 50000). This storage limit does not reduce AI Manager logging. The final AI prompt has a separate hard limit of 1000000 characters, including instructions.';
$string['unknown'] = 'Unknown';
$string['use_template'] = 'Use a template';
$string['view'] = 'View';
