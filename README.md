# AI Conversation Analysis Report Plugin

A Moodle report plugin that allows teachers and administrators to analyze conversation data from AI-powered activities using AI-driven insights.

## Features

✅ **Multi-Context Analysis**: Analyze conversations at system, category, course, or activity level
✅ **AI-Powered Insights**: Uses local_ai_manager for intelligent analysis
✅ **Flexible Filtering**: Filter by courses, activities, students, and groups
✅ **Background Processing**: Asynchronous task handling with retry logic
✅ **Export Functionality**: Export reports as JSON or HTML
✅ **Permission System**: Granular capability-based access control
✅ **Privacy Compliant**: Full GDPR support with privacy provider

## Requirements

- Moodle 4.3 or higher
- **local_ai_manager** plugin (required for AI functionality)
- PHP 8.0 or higher

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
| **Prompt Templates** | Empty | Pre-defined templates (format: "Name\|Prompt") |
| **Max Records** | 1000 | Maximum conversation records per analysis |
| **Store Raw Data** | Yes | Store conversation data in database |
| **Truncate Length** | 50000 | Max characters for raw data storage |
| **Retry on Failure** | 2 | Number of automatic retries (0-3) |
| **Timeout** | 60 seconds | API request timeout |
| **Share Reports** | No | Allow teachers to view each other's reports |
| **Markdown Conversion** | Yes | Render AI responses as Markdown |

## Usage

### Creating an Analysis Report

1. Navigate to a course or activity
2. Go to **Reports → AI Conversation Analysis**
3. Click **"Create new analysis"**
4. Configure:
   - **Title**: Optional custom title
   - **Scope**: Select courses, activities, students, or groups
   - **Prompt**: Describe what you want the AI to analyze
5. Submit to queue the analysis

### Viewing Reports

- Reports are listed with status: Pending, Running, Completed, Failed, or Cancelled
- Click **"View"** to see the full analysis
- Completed reports show:
  - AI analysis result (Markdown formatted)
  - Metadata (model, tokens, duration, retries)
  - Raw conversation data (if permitted)

### Managing Reports

- **Delete**: Remove reports (requires `report/ai_analysis:delete`)
- **Export**: Download as JSON or HTML
- **Re-run**: Execute analysis again with same parameters (future feature)

## Capabilities

| Capability | Description | Default Roles |
|------------|-------------|---------------|
| `viewsystem` | View reports at system level | Manager |
| `viewcategory` | View reports in categories | Manager |
| `viewcourse` | View reports in courses | Teacher, Editing Teacher |
| `viewactivity` | View reports in activities | Teacher, Editing Teacher |
| `viewall` | View all reports in context | Manager |
| `viewrawdata` | View raw conversation data | Manager |
| `create` | Create new analysis reports | Teacher, Editing Teacher, Manager |
| `delete` | Delete reports | Editing Teacher, Manager |
| `rerun` | Re-run existing analyses | Teacher, Editing Teacher, Manager |

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

### Code Standards

All code follows Moodle Coding Standards:

```bash
# Check coding standards
./bindev/codechecker.sh report/ai_analysis

# Auto-fix issues
./bindev/codechecker_autofix.sh report/ai_analysis

# Check PHPDoc
./bindev/moodlecheck.sh report/ai_analysis
```

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
| `error_message` | TEXT | Error details |
| `error_code` | VARCHAR(50) | Error code |
| `ai_model` | VARCHAR(100) | AI model used |
| `token_usage` | VARCHAR(100) | Token statistics |
| `retry_count` | INT | Number of retries |
| `raw_data` | LONGTEXT | Raw conversation JSON |
| `timecreated` | INT | Creation timestamp |
| `timecompleted` | INT | Completion timestamp |

## Troubleshooting

### Reports stuck in "Pending" status

**Cause**: Cron not running or adhoc tasks not processing

**Solution**:
```bash
# Run cron manually
php admin/cli/cron.php

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
- Expand scope filters

## License

Copyright © 2025 ISB Bayern

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

## Support

For issues and feature requests, please contact the ISB Bayern development team.

## Credits

Developed by ISB Bayern for the MBS Moodle project.
