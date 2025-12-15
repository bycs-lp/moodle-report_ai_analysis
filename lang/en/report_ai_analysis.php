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

$string['activities'] = 'Activities';
$string['add_template'] = 'Add new template';
$string['ai_analysis:create'] = 'Create AI analysis reports';
$string['ai_analysis:delete'] = 'Delete AI analysis reports';
$string['ai_analysis:rerun'] = 'Re-run AI analysis reports';
$string['ai_analysis:view'] = 'View AI analysis reports';
$string['ai_analysis:viewrawdata'] = 'View raw conversation data in reports';
$string['aimodel'] = 'AI Model';
$string['allusers'] = 'All users';
$string['analysis_mode'] = 'Analysis mode';
$string['analysis_mode_aggregated'] = 'Aggregated (all participants)';
$string['analysis_mode_help'] = 'Choose the analysis mode:<ul><li><strong>Individual:</strong> Creates separate analyses for each selected participant. Ideal for identifying individual learning difficulties.</li><li><strong>Aggregated:</strong> Creates a summary analysis across all selected participants. Ideal for requirement analyses and overall overviews.</li></ul>';
$string['analysis_mode_individual'] = 'Individual (per participant)';
$string['analysis_result'] = 'Analysis Result';
$string['analysisqueued'] = 'Analysis has been queued for background processing';
$string['cachedef_providers'] = 'Cache for data source providers';
$string['cancel'] = 'Cancel';
$string['cannotdeleteothersreports'] = 'You cannot delete reports created by other users';
$string['cannoteditrunningreport'] = 'Cannot edit a report that is currently running';
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
$string['datasource'] = 'Data source';
$string['datasource_help'] = 'Select which data sources (activities, blocks, etc.) to analyze';
$string['delete'] = 'Delete';
$string['deletereport'] = 'Delete Report';
$string['disable'] = 'Disable';
$string['duration'] = 'Duration';
$string['edit'] = 'Edit';
$string['edit_template'] = 'Edit template';
$string['editreport'] = 'Edit Report';
$string['enable'] = 'Enable';
$string['enable_markdown_conversion'] = 'Enable Markdown rendering';
$string['enable_markdown_conversion_desc'] = 'Convert Markdown in AI responses to HTML';
$string['error_ai_chat_not_available'] = 'Block ai_chat is not installed or not enabled';
$string['error_ai_request'] = 'AI request failed: {$a}';
$string['error_api_connection_error'] = 'Could not connect to AI service';
$string['error_api_timeout'] = 'API request timed out';
$string['error_contextmismatch'] = 'Report does not belong to the specified course context';
$string['error_deleting_template'] = 'Error deleting template';
$string['error_empty_response'] = 'AI service returned empty response';
$string['error_forum_not_available'] = 'Forum module is not installed or not enabled';
$string['error_no_data'] = 'No conversation data found for analysis';
$string['error_prompt_too_long'] = 'Prompt is too long for AI service';
$string['error_prompt_too_short'] = 'Prompt must be at least 10 characters long';
$string['error_purposenotconfigured'] = 'The AI purpose "singleprompt" is not configured. Please contact your administrator.';
$string['error_rate_limit'] = 'AI service rate limit reached';
$string['error_saving_template'] = 'Error saving template';
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
$string['exporthtml'] = 'Export as HTML';
$string['exportjson'] = 'Export as JSON';
$string['groups'] = 'Groups';
$string['includesubcategories'] = 'Include subcategories';
$string['individual_mode_warning'] = 'Warning: In individual mode, a separate analysis will be created for each participant. This may result in high token usage with many participants.';
$string['invalidformat'] = 'Invalid export format specified';
$string['manage_templates'] = 'Manage prompt templates';
$string['max_records_per_analysis'] = 'Maximum records per analysis';
$string['max_records_per_analysis_desc'] = 'Maximum number of conversation records to include in one analysis';
$string['metadata'] = 'Analysis Metadata';
$string['newanalysis'] = 'New analysis';
$string['no_templates'] = 'No templates configured yet';
$string['nopermission'] = 'You do not have permission to perform this action';
$string['order'] = 'Order';
$string['participant'] = 'Participant';
$string['participants'] = 'Participants';
$string['pluginname'] = 'AI Conversation Analysis';
$string['privacy:metadata:report_ai_analysis_reports'] = 'Stores AI analysis reports created by users';
$string['privacy:metadata:report_ai_analysis_reports:ai_result'] = 'The AI-generated analysis result';
$string['privacy:metadata:report_ai_analysis_reports:prompt'] = 'The analysis prompt provided by the user';
$string['privacy:metadata:report_ai_analysis_reports:raw_data'] = 'The raw conversation data analyzed';
$string['privacy:metadata:report_ai_analysis_reports:timecreated'] = 'The time when the report was created';
$string['privacy:metadata:report_ai_analysis_reports:title'] = 'The title of the analysis report';
$string['privacy:metadata:report_ai_analysis_reports:userid'] = 'The ID of the user who created the report';
$string['prompt'] = 'Analysis prompt';
$string['prompt_help'] = 'Describe what you want the AI to analyze about the conversation data.';
$string['prompt_preview'] = 'Preview';
$string['prompt_templates'] = 'Prompt templates';
$string['prompt_templates_desc'] = 'Predefined prompt templates (one per line, format: "Template Name|Prompt text")';
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
$string['share_reports_in_course_desc'] = 'Allow teachers to see each other\'s reports in the same course';
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
$string['store_raw_data_desc'] = 'Store the raw conversation data in the database (can be disabled to save space)';
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
$string['timeout_seconds'] = 'Request timeout';
$string['timeout_seconds_desc'] = 'Timeout for AI requests in seconds';
$string['timestart'] = 'Start time';
$string['title'] = 'Title';
$string['title_help'] = 'Optional title for this analysis. If empty, the first 80 characters of the prompt will be used.';
$string['tokenusage'] = 'Token Usage';
$string['truncate_raw_data_length'] = 'Truncate raw data length';
$string['truncate_raw_data_length_desc'] = 'Maximum length of raw data to store (characters)';
$string['unknown'] = 'Unknown';
$string['use_template'] = 'Use a template';
$string['view'] = 'View';
