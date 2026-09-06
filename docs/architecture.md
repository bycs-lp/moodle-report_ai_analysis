# Architecture and integration contract

MBS-11031 separates authorisation, lifecycle, collection and presentation. This document records the contract for the parallel implementation; it is not an executed verification report.

## Boundaries

- `local\report_access`: shared object-level checks for lists, direct endpoints, exports and mutations.
- `local\report_manager`: the only interactive mutation boundary; transactions and report locks protect generation changes and task queueing.
- `scope_builder` and `data_collector`: course-only selection, current source authorisation, global budgets and per-author attribution.
- `task\process_analysis_task`: revalidate the queued actor, collect, call the injectable request adapter and commit only into the current generation.
- `local\ai_request_provider`: public AI Manager request API, not a direct connector or HTTP client.
- `local\report_exporter`: clean prompt/result presentation shared by the detail page and downloadable formats.
- `privacy\provider` and `local\log_store`: creator/subject discovery, separated exports and public AI-Manager data wiping.

## Required public APIs

`report_manager::save(stdClass $data, scope_builder $scope, ?int $reportid = null): int` validates course scope and creates/updates a report and its task atomically. The course comes from the scope; posted ownership/status fields are not trusted. Existing reports keep their owner. `save` uses the create capability and ownership policy on edits.

`report_manager::rerun(int $reportid): void`, `cancel(int $reportid): void` and `delete(int $reportid): void` enforce their own access checks, not just checks in the calling page. Re-run uses `rerun`; cancel/delete use `delete`. Privacy and course deletion use the separately authorised lifecycle entry point `delete_for_privacy(int $reportid): void` rather than granting an interactive caller a bypass.

The UI already calls `report_access::require_view($report)` and `require_manage($report, $capability)`. The exact policy is:

- read = course access **and** `view` **and** (owner **or** sharing **or** `viewall`);
- mutate = course access **and** action capability **and** (owner **or** `manageall`).

Sharing never permits mutation. `viewall` never permits mutation. `manageall` does not grant `view` or the action capability. Default raw-data access for editing teachers and managers is preserved; it remains an additional check on an otherwise readable report. List filtering must occur before counts/pagination, not after fetching private rows.

The request adapter must accept the AI Manager's options array:

`ai_request_provider::perform_request(string $purpose, string $prompt, string $component, int $contextid, array $options = []): local_ai_manager\local\prompt_response`.

The worker passes `singleprompt`, `report_ai_analysis`, the report's course context and `['itemid' => $reportid]`. There is no `timeout` option and no unconditional `forcenewitemid` on re-run. The Behat adapter uses this signature; missing options support is an integration failure, not something tests silently ignore.

## Atomic runs

Persist `runversion`, `action`, `resultformat`, `truncated` and `legacydata` in `report_ai_analysis_reports`. Each task's custom data includes `reportid` and `runversion`, and `set_userid()` identifies the actual requesting actor, not automatically the report owner. `action` distinguishes the permission needed for save/create versus re-run.

1. Lock the report for a short state transition.
2. Validate access and legal status; reset result, source copies, failure fields, completion time, retry count and model/usage/duration metadata for a new run.
3. Increment the generation and write report/task/initial attribution within one delegated transaction.
4. Release the lock before external processing. Do not hold a database transaction across a network request.
5. Recheck existence, current generation, actor/course/source permissions and AI availability before collection/request and before accepting results.
6. Write final state and attributable data only for the still-current generation.

Cancel and deletion invalidate queued work. An old response, duplicate task or retry cannot overwrite a cancelled or newer generation. Removing a queued task does not stop an already transmitted request; generation checks reject its late result. Failed writes must not reapply a partly mutated success record. `execution_time` is a whole-number duration compatible with PostgreSQL's integer field.

Only typed response codes for transient failures are retryable, not arbitrary exception text containing “timeout”. Retry count is bounded by the configured maximum and hard maximum of three; delay is `60 * 2 ** (retry_count - 1)`. Consent, lost access, invalid scope, empty data/results and source failures are not transient retry reasons. Quota/rate-limit feedback is visible; accepting terms is an explicit user action, not an automatic retry loop.

## Collection and individual mode

The canonical scope contains `courseid`, `analysis_mode` and a `filters` object with `sources`, `participants`, `roles`, `groups` and optional `timerange: {start, end}`. Omitted participant filtering means all permitted active participants; an explicitly stored empty list means none. User/group/role filters are intersections. Sources resolve within the one course, with module/block visibility and source permissions applied for the actual executing user.

The forum provider returns only allowed posts by selected authors; the time range qualifies threads using visible selected-author posts. Other allowed selected-author posts in those threads may be outside the range. The chat provider reads bounded, undeleted `chat` entries through the public log API; its fifth boolean argument means `includedeleted`, not sort direction. Request options/history are not included. Missing foreign-prompt/date permissions must not be bypassed by report rights or tenant access.

`max_records_per_analysis` counts posts and chat request log entries across all providers and users. The hard record cap is 10000, not a per-user multiplier. Per-message and scan bounds must set `truncated`; provider errors must fail explicitly. The worker additionally caps the **complete** prompt, including system/user instructions, at 1000000 Unicode characters. The raw-copy bound of 500000 is independent of that request bound.

`data_collector::get_user_data()` returns source text keyed by actual author ID. Aggregated mode uses one combined request and records all included authors. Individual mode issues one request per author with nonempty data, stores that author's source/result in `report_ai_analysis_users`, and stores combined presentation HTML in the parent (`resultformat = FORMAT_HTML`). It must not fall back to one class-wide request. Attribution remains even when raw storage is disabled; `source_data` and `ai_result` can be null.

## Privacy and safe presentation

The participant table has a unique report/user association and fields `reportid`, `userid`, nullable `source_data` and nullable `ai_result`. Initial queued attribution must survive until the worker refines it to actual included authors. Discovery must cover both creators and mapped subjects. Legacy maps are conservative and saved during migration; do not infer away historical subjects from current enrolment or automatically delete historical content.

AI Manager logs identify the executing actor, not every analysed author. Therefore report cleanup uses the component/context/report linkage and the manager's public `data_wiper`, anonymising text/identifiers while preserving statistics. Privacy deletion of an inseparable aggregate removes the affected report through the locked lifecycle and invalidates pending work. Normal course deletion also uses this cleanup path. Privacy exports never return the parent aggregate or other people's data to a subject or merely because the requester created the report.

Prompts are untrusted Markdown/plain text; purpose responses are already HTML. Both are cleaned with the report context, with no `noclean` trust exemption. Plain-text mode converts purpose HTML to text rather than displaying tags. Standalone HTML and JSON presentation exports omit creator email. Failed exports contain classified user-facing errors; technical detail obeys Moodle's developer/debug-display gates. Privacy exports omit technical diagnostics even when debugging is enabled.

Copyright © 2026 ISB Bayern. Author: Dr. Peter Mayer. GNU GPL v3 or later.