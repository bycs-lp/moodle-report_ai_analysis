# AI analysis: user and administrator guide

This is the course-report contract for MBS-11031. For lifecycle details see [Architecture](architecture.md), and for reproducible checks see [Testing](testing.md).

## Configure availability first

In AI Manager, enable the relevant tenant, create an enabled connector instance and assign it to **singleprompt** for the user's AI-Manager role. The user needs AI usage permission, accepted terms and available quota. These are separate from report permissions. The creation form and re-run actions use the real availability API; an unavailable backend is not a reason to disable its checks.

The form shows the shared AI information and quota widgets and explains the transfer of participant data. Results show the shared AI warning. Individual analysis consumes a request for each included author, and automatic retries may consume more requests. AI output needs human review; it is not a verified assessment.

## Create a course report

1. Open **Reports → AI Conversation Analysis → New analysis** in the course.
2. Enter an analysis prompt (10–10000 characters). A title is optional, at most 255 characters; an omitted title uses the first 80 Unicode characters of the prompt.
3. Optionally choose a reusable prompt template. Up to five enabled templates appear as buttons; more than five appear in a selector. Both preserve quotation marks, ampersands and non-ASCII characters exactly.
4. Select aggregated or individual mode.
5. Select supported sources. Only accessible course forums and course-owned AI Chat blocks are supported. An empty source selection means all supported, authorised sources in this course, not arbitrary activities.
6. Leave **All users** checked, or uncheck it before selecting participants. Roles and groups further restrict the selection; they do not broaden it. An unchecked box with no participants selects nobody.
7. Optionally enable either or both date boundaries, then submit.

The queued task runs as the user who requested the action. Current course, report, source and AI permissions are rechecked at execution time. Access lost since queueing must not result in a request using previously authorised data.

### Source and time semantics

- **Forums:** a record is one visible post by an included author. Selected course modules are enforced; other forums, inaccessible activities, private replies and disallowed group discussions are excluded. An excluded discussion starter's name/title is not copied back as thread metadata.
- **Forum date ranges:** a thread qualifies only when a visible post by a selected author is inside the inclusive range. Other permitted posts by selected authors in that thread can lie outside the range. This is not unrestricted whole-thread collection.
- **AI Chat:** a record is one permitted log entry's own request and response. Deleted entries and non-chat purposes are excluded. Stored conversation history is not replayed or copied from request options.
- **Chat permissions:** foreign prompts need `local/ai_manager:viewprompts` in the source context. Foreign prompt dates additionally need `local/ai_manager:viewpromptsdates`. Date filtering still applies when dates must not be shown. Tenant-wide access is not a blanket course override.
- **Open ranges:** `0` means no boundary. Start-only, end-only and closed ranges are supported; reversed closed ranges are rejected by the form.
- **Failures and limits:** a source failure stops analysis, not a silent partial success. A bounded scan, record limit, message limit or prompt limit may yield explicitly marked truncated data. A truncated report must not be represented as complete.

## Reading, sharing and actions

With sharing off, users with `view` read their own reports; `viewall` extends this to other owners. With sharing on, `view` permits reading other reports in the same course. Sharing is always read-only. `manageall` extends ownership for an action but never supplies its capability. Raw data always requires `viewrawdata` as well as permission to read the report.

| Status | Read details | Edit | Re-run | Cancel | Delete | Export |
| --- | --- | --- | --- | --- | --- | --- |
| Pending | Yes | Yes | No | Yes | Yes | No |
| Running | Yes | No | No | Yes | Yes | No |
| Completed | Yes | Yes | Yes | No | Yes | Yes |
| Failed | Yes | Yes | Yes | No | Yes | Yes |
| Cancelled | Yes | Yes | Yes | No | Yes | No |

Each action still requires its capability and ownership/management checks. Edit and re-run also require AI availability. Cancel uses the delete capability. Cancelling invalidates the run; it cannot recall a request already sent to a provider. A late response cannot overwrite a cancelled, deleted or newer report generation.

JSON and HTML export are available for completed and failed reports. JSON is a presentation export: `prompt` and completed `ai_result` are cleaned HTML and marked `FORMAT_HTML`, not original stored markup. Failed exports expose the safe failure description and no result. Creator email is not exported. Technical diagnostics are only included when Moodle developer debugging **and** debug display are enabled. Treat all downloaded data as sensitive, including permitted raw data.

## Settings

Open **Site administration → Plugins → Reports → AI Conversation Analysis**.

| Setting | Default | Contract |
| --- | --- | --- |
| System prompt | Educational analyst instructions | Added before the user's prompt and selected source data |
| Maximum records per analysis | 1000 | Positive integer, 1–10000; one shared budget across sources and authors |
| Store raw data | Yes | Store bounded source copies in the parent and per-author rows |
| Truncate raw data length | 50000 | Positive integer, 1–500000 characters per source copy; separate from prompt limits |
| Retry on failure | 2 | 0–3 retries of typed transient failures; delays 60, 120, 240 seconds |
| Share reports in course | No | Read-only sharing for users with `view` |
| Enable Markdown rendering | Yes | Format prompts/legacy Markdown; new purpose HTML remains HTML; off gives plain text |
| Prompt templates | None | Link to the template-management page, not a pipe-delimited text setting |

There is **no report-specific request timeout**. The removed setting was not supported by `singleprompt`. Use only timeout controls actually supported by the configured AI Manager connector; do not invent per-request options.

## Privacy and retention

The report stores its creator, scope, prompt, status, model/usage metadata and result. The participant map records people whose data were included; per-author source data and individual results are nullable. Individual requests contain only one author's collected data; the parent result combines the individual results as HTML.

Disabling raw storage does not stop:

- sending the prompt and permitted contributions to the configured AI service;
- storing results and participant attribution;
- the AI Manager's complete request/response logging;
- the external service's own retention rules.

Report-linked logs use the report ID as `itemid`. Cleanup delegates to the AI Manager's public `data_wiper` and anonymises personal request material while retaining usage statistics; it does not claim to delete logs from an external provider. Privacy discovery includes creators and mapped participants, including conservative legacy mappings. Exports include only safely attributable data for the requesting person and omit combined results, other participants' data and diagnostics. Inseparable affected reports are invalidated/deleted through the lifecycle service rather than edited piecemeal. Course deletion uses the same cleanup boundary.

Legacy content is not reconstructed from today's enrolments. Conservative saved mappings remain relevant even without participant selection or raw storage. The upgrade must not silently purge old reports. There is no automatic report-expiry policy in this plugin; site administrators must define and operate the required retention process.

Copyright © 2026 ISB Bayern. Author: Dr. Peter Mayer. GNU GPL v3 or later.