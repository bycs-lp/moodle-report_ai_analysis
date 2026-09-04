# AI Conversation Analysis Report Plugin

A Moodle report plugin that allows teachers and administrators to analyze conversation data from AI-supported activities using AI-driven insights.
Forums can also be analyzed using AI. In the future, other activities will also be analyzed using AI.

## Features

✅ **Multi-Context Analysis**: Analyze conversations at course, or activity level
✅ **AI-Powered Insights**: Uses local_ai_manager for intelligent analysis
✅ **Flexible Filtering**: Filter by courses, activities, students, and groups
✅ **Analysis Modes**:
  - Aggregated analysis (all participants combined)
  - Individual analysis (per participant)
✅ **Time Range Filtering**: Analyze conversations from specific time periods
✅ **Background Processing**: Asynchronous task handling with retry logic
✅ **Re-run Capability**: Re-execute completed, failed, or cancelled reports
✅ **Export Functionality**: Export reports as JSON or HTML
✅ **Pagination Support**: Handle large numbers of reports efficiently
✅ **Prompt Templates**: Pre-defined analysis prompts (admin-configurable)
✅ **Permission System**: Granular capability-based access control
✅ **Privacy Compliant**: Full GDPR support with privacy provider

## Requirements

- Moodle 5.0 or higher
- **local_ai_manager** plugin (required for AI functionality)
- PHP 8.3 or higher

## Installation

1. Extract the plugin to `/path/to/moodle/report/ai_analysis`
2. Visit **Site Administration → Notifications** to install
3. Configure AI Manager:
   - Go to **Site Administration → Plugins → Local plugins → AI Manager**
   - Configure the **"singleprompt"** purpose
   - Ensure an AI connector is active

## Configuration

Go to **Site Administration → Plugins → Reports → AI Conversation Analysis**

### Available Settings

| Setting | Default | Description |
|---------|---------|-------------|
| **System Prompt** | Educational analyst prompt | The base prompt sent to AI before analysis |
| **Prompt Templates** | Empty | Pre-defined templates (format: "Name\|Prompt", one per line) |
| **Max Records** | 1000 | Maximum conversation records per analysis |
| **Store Raw Data** | Yes | Store conversation data in database |
| **Truncate Length** | 50000 | Max characters for raw data storage |
| **Retry on Failure** | 2 | Number of automatic retries (0-3) |
| **Timeout** | 60 seconds | API request timeout |
| **Share Reports** | No | Allow teachers to view each other's reports |
| **Markdown Conversion** | Yes | Render AI responses as Markdown |

### Prompt Templates

Administrators can define reusable prompt templates to help teachers create consistent analyses:

1. Go to plugin settings
2. Add templates in the **Prompt Templates** field
3. Format: `Template Name|Prompt text` (one per line)
4. Example:
   ```
   Theme Analysis|Analyze all conversations and identify the main themes and topics discussed by students.
   Engagement Assessment|Evaluate student engagement levels based on conversation frequency and depth.
   Misconception Detection|Identify common misconceptions or misunderstandings in student responses.
   ```
5. Teachers will see these templates in a dropdown when creating reports

## Usage

### Creating an Analysis Report

1. Navigate to a course or activity
2. Go to **Reports → AI Conversation Analysis**
3. Click **"New analysis"**
4. Configure:
   - **Title**: Optional custom title for the report
   - **Analysis prompt**: Describe what you want the AI to analyze (required)
   - **Analysis mode**:
     - Aggregated (all participants) - combines all conversation data
     - Individual (per participant) - creates separate analysis per user
   - **Scope - Data sources**: Select specific activities (forums, quizzes, etc.)
   - **Scope - Select participants**: Filter by specific students
   - **Scope - Groups**: Filter by course groups
   - **Time range**: Optional time period filtering
5. Submit to queue the analysis

### Analysis Modes Explained

**Aggregated Analysis (Default)**:
- Combines all conversation data from selected participants
- Provides overview and patterns across all users
- Useful for identifying common themes, issues, or trends

**Individual Analysis**:
- Creates separate analysis for each participant
- Allows comparison between individual student performances
- Useful for personalized feedback and assessment

### Form Validation

The create report form enforces the following validation:
- **Analysis prompt** is required (cannot be empty)
- **Title** is optional
- At least one scope filter is recommended but not required
- Cancelling the form returns to the report list without creating

### Using Prompt Templates

If your administrator has configured prompt templates:
1. Open the create report form
2. Look for the template dropdown/selector
3. Select a pre-defined template
4. The prompt field will be populated automatically
5. You can still modify the prompt after selecting a template

### Viewing Reports

- Reports are listed with status badges: Pending, Running, Completed, Failed, or Cancelled
- Pagination is available when dealing with many reports (12+ per page)
- Click **"View"** to see the full analysis
- Completed reports show:
  - AI analysis result (Markdown formatted)
  - Metadata (model, tokens, duration, retries)
  - Raw conversation data (if permitted via `viewrawdata` capability)

### Report Status Indicators

| Status | Description | Available Actions |
|--------|-------------|-------------------|
| **Pending** | Queued for processing | Delete |
| **Running** | Currently being analyzed | Delete |
| **Completed** | Analysis finished successfully | View, Delete, Export, Re-run |
| **Failed** | Analysis encountered an error | Delete, Re-run |
| **Cancelled** | User cancelled the report | Delete, Re-run |

### Action Availability Matrix

The following table shows which actions are available for each report status:

| Action | Pending | Running | Completed | Failed | Cancelled |
|--------|---------|---------|-----------|--------|-----------|
| View Details | ❌ | ❌ | ✅ | ❌ | ❌ |
| Delete | ✅ | ✅ | ✅ | ✅ | ✅ |
| Export | ❌ | ❌ | ✅ | ❌ | ❌ |
| Re-run | ❌ | ❌ | ✅ | ✅ | ✅ |

**Notes**:
- Delete is always available (with appropriate capability)
- Export only works with completed reports containing results
- Re-run creates a new adhoc task and resets status to pending
- View details only shows meaningful data for completed reports

### Managing Reports

- **Delete**: Remove reports (requires `report/ai_analysis:delete` capability)
  - Available for all report statuses
  - Managers can delete any report in their context
  - Teachers can only delete their own reports
  - Deleting pending reports removes them from the queue
- **Export**: Download as JSON or HTML (only for completed reports)
  - Not available for pending or running reports
- **Re-run**: Execute analysis again with same parameters
  - Available for completed, failed, or cancelled reports
  - Not available for pending or running reports
  - Resets the report to pending status and queues a new adhoc task
  - Requires `report/ai_analysis:rerun` capability

## Capabilities

| Capability | Description | Default Roles |
|------------|-------------|---------------|
| `view` | View the AI Analysis Report interface | Teacher, Editing Teacher, Manager |
| `viewsystem` | View reports at system level | Manager |
| `viewcategory` | View reports in categories | Manager |
| `viewcourse` | View reports in courses | Teacher, Editing Teacher |
| `viewactivity` | View reports in activities | Teacher, Editing Teacher |
| `viewall` | View all reports in context (including other users') | Manager |
| `viewrawdata` | View raw conversation data in reports | Manager |
| `create` | Create new analysis reports | Editing Teacher, Manager |
| `delete` | Delete reports (own reports for teachers, all for managers) | Editing Teacher, Manager |
| `rerun` | Re-run existing analyses | Editing Teacher, Manager |

### Capability Notes

- **Teachers (non-editing)** have view access by default but cannot create or delete
- **Editing Teachers** have full access except viewing other users' raw data
- **Managers** have unrestricted access to all features
- **Students** have no access by default but can be granted specific capabilities via permission overrides
- Capabilities are enforced at the appropriate context level (system, category, course, activity)

## Privacy

The plugin implements Moodle's Privacy API:

- **User Data Stored**:
  - Report titles and prompts
  - AI analysis results
  - Raw conversation data (if enabled)
  - Creation timestamps

- **Data Lifecycle**:
  - User data is exported in privacy exports
  - User data is deleted when user is deleted
  - Contexts are deleted when associated context is removed

## Background Tasks

The plugin uses Moodle's adhoc task system:

- **Task**: `report_ai_analysis\task\process_analysis_task`
- **Execution**: Automatic via cron
- **Retry Logic**: Configurable retries (0-3) with exponential backoff
- **Error Handling**: Retryable errors (timeout, connection, rate limit)

### Monitoring Tasks

```bash
# View queued tasks
php admin/cli/adhoc_task.php --execute --classname='\report_ai_analysis\task\process_analysis_task'

# Run cron manually
php admin/cli/cron.php
```

## Development

### Running Tests

**PHPUnit Tests**:
```bash
 --filter report_ai_analysis
```

**Behat Tests**:
```bash
 --tags="@report_ai_analysis"
```

### Behat Test Coverage

The plugin includes comprehensive Behat tests covering:

- **basic_navigation.feature**: Access control and navigation
  - Teachers can access the report
  - Students cannot access by default
  - Navigation menu links work correctly

- **create_report.feature**: Report creation with various filters
  - Simple report creation with just a prompt
  - Reports with custom titles
  - Participant filtering
  - Group filtering
  - Activity/data source filtering
  - Aggregated vs Individual analysis modes
  - Time range filtering
  - Comprehensive reports with multiple filters
  - Form validation (required fields)

- **create_report_simple.feature**: Basic creation scenarios
  - Form accessibility
  - Required field validation

- **view_reports.feature**: Viewing and listing reports
  - Report list displays all statuses
  - Status badges show correctly
  - Actions available based on status
  - Pagination support for many reports

- **delete_reports.feature**: Report deletion
  - Teachers can delete own reports
  - Managers can delete any report
  - Pending reports are removed from queue
  - Confirmation dialog is shown

- **rerun_reports.feature**: Re-running analyses
  - Re-run available for completed reports
  - Re-run available for failed reports
  - Re-run available for cancelled reports
  - Not available for pending/running reports
  - Confirmation dialog and status reset
  - Adhoc task queueing
  - Capability enforcement

- **export_reports.feature**: Exporting reports
  - Export only available for completed reports
  - Multiple export formats (JSON, HTML)

- **capabilities.feature**: Permission enforcement
  - Default permissions for each role
  - Custom permission overrides
  - Context-level capability checks
  - Granular access control (view, create, delete, rerun)
  - Student access with custom permissions

- **manage_templates.feature**: Admin template management
  - Template configuration access
  - Admin-only settings

### Database Schema

**Table**: `report_ai_analysis_reports`

| Field | Type | Description |
|-------|------|-------------|
| `id` | INT | Primary key |
| `contextid` | INT | Context ID |
| `userid` | INT | Creator user ID |
| `title` | VARCHAR(255) | Report title |
| `scope_details` | TEXT | JSON scope configuration |
| `prompt` | TEXT | Analysis prompt |
| `ai_result` | LONGTEXT | AI response |
| `status` | VARCHAR(20) | Status (pending/running/completed/failed/cancelled) |
| `error_message` | TEXT | Localized user-facing error description |
| `error_details` | TEXT | Technical details, displayed only when Moodle developer debugging and debug display are enabled |
| `error_code` | VARCHAR(50) | Error code |
| `ai_model` | VARCHAR(100) | AI model used |
| `token_usage` | VARCHAR(100) | Token statistics |
| `retry_count` | INT | Number of retries |
| `raw_data` | LONGTEXT | Raw conversation JSON |
| `timecreated` | INT | Creation timestamp |
| `timecompleted` | INT | Completion timestamp |

### Behat Test Generators

The plugin provides custom Behat generators for testing:

**Generator: `report_ai_analysis > reports`**

Creates test reports with specified properties:

```gherkin
Given the following "report_ai_analysis > reports" exist:
  | title              | course | userid   | status    | prompt            | ai_result          |
  | Completed Report   | C1     | teacher1 | completed | Test analysis     | Analysis result    |
  | Failed Report      | C1     | teacher1 | failed    | Failed test       |                    |
  | Pending Report     | C1     | teacher1 | pending   | Waiting           |                    |
```

**Available fields**:
- `title`: Report title (string)
- `course`: Course shortname (string, required)
- `userid`: Username of report creator (string, required)
- `status`: Report status (pending/running/completed/failed/cancelled)
- `prompt`: Analysis prompt (string, required)
- `ai_result`: AI analysis result (string, optional)
- `error_message`: User-facing error description for failed reports (string, optional)
- `error_details`: Technical error details for debug-display tests (string, optional)
- `error_code`: Error code (string, optional)
- `ai_model`: AI model identifier (string, optional)
- `token_usage`: Token statistics (string, optional)
- `retry_count`: Number of retries (int, default: 0)

## Troubleshooting

### Reports stuck in "Pending" status

**Cause**: Cron not running or adhoc tasks not processing

**Solution**:
```bash
# Run cron manually
php admin/cli/cron.php

# Execute specific adhoc task
php admin/cli/adhoc_task.php --execute --classname='\report_ai_analysis\task\process_analysis_task'

# Check adhoc task queue
SELECT * FROM {task_adhoc} WHERE classname LIKE '%ai_analysis%';
```

### Error: "Purpose not configured"

**Cause**: AI Manager's "singleprompt" purpose not set up

**Solution**:
1. Go to **Site Administration → AI Manager**
2. Create or configure "singleprompt" purpose
3. Assign an active connector

### No data found for analysis

**Cause**: No conversation data in selected scope

**Solution**:
- Verify students have had AI conversations in selected activities
- Check conversation data exists in `local_ai_manager_conversation` table
- Expand scope filters (remove activity/participant restrictions)
- Check time range filters aren't too restrictive

### Re-run button not visible

**Cause**: Missing `report/ai_analysis:rerun` capability or wrong report status

**Solution**:
- Verify user has rerun capability in the context
- Re-run is only available for completed, failed, or cancelled reports
- Not available for pending or running reports

### Cannot create reports

**Cause**: Missing `report/ai_analysis:create` capability

**Solution**:
- Verify user role has create capability
- Check permission overrides haven't prohibited the capability
- Editing teachers have create by default, non-editing teachers don't

### Export not available

**Cause**: Report is not in completed status

**Solution**:
- Export is only available for completed reports
- Wait for pending/running reports to finish
- Failed reports must be re-run successfully first

## License

Copyright © 2025 ISB Bayern

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

## Support

For issues and feature requests, please contact the ISB Bayern development team.

## Credits

Developed with love by Dr. Peter Mayer; ISB Bayern for the MBS Moodle project.
