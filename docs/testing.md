# Testing the MBS-11031 contract

No terminal commands, test suites or builds were run for this documentation/Behat change. The scenarios below are executable regression specifications, not recorded passing results. The main lifecycle, schema and request-adapter changes must be integrated before using them.

## Environment and backend fixtures

Use Moodle's isolated Behat installation with a JavaScript-capable driver, the plugin schema upgraded, and `local_ai_manager`, `aipurpose_singleprompt`, `aipurpose_chat`, `aitool_chatgpt`, `mod_forum` and `block_ai_chat` installed. Use the project's normal Behat workflow and the `@report_ai_analysis` tag. Behat covers end-to-end browser journeys only; the fine-grained authorisation, lifecycle, budget and privacy permutations are verified faster in the PHPUnit scenarios. Date-selector scenarios explicitly use UTC.

Every report UI background now uses **the AI analysis backend is configured**. This does not inject an availability response or disable consent checks. The fixture:

1. Grants only the real `local/ai_manager:use` capability through a test role; it does not grant report, source, `viewall` or `manageall` privileges.
2. Uses the public `userinfo` API to set the fixture users' role, scope, unlocked state and accepted terms; `requireconfirmtou` stays enabled.
3. Enables the installed connector/purpose, configures allowed tenants, and creates an actual connector instance through `connector_factory`/`base_instance`.
4. Assigns `singleprompt` through `config_manager` with a quota of 50 for the basic role.
5. Uses a reserved `.invalid` endpoint and a synthetic placeholder key, never a real provider credential.

The current AI Manager generator only implements `create_request_log_entry()` and has no Behat connector/purpose/consent generator. Therefore configuration uses its real public APIs; no nonexistent generator method is assumed. The real log generator is used for chat fixtures and representative analysis logs.

The negative availability step changes genuine persisted consent, quota, purpose assignment or tenant state. HTTP requests therefore exercise the real configuration and widgets, not a worker-process-only mock. Adding test bypasses to production hooks, availability classes or endpoints is not part of this strategy.

## Synchronous worker boundary

[The Behat context](../tests/behat/behat_report_ai_analysis.php) resolves a report by its unique title, finds exactly one actual queued task and obtains it through `core\task\manager::get_adhoc_task()`, including its lock. It runs the full worker under the queue record's `userid`, not the Behat admin and not automatically the owner, and completes the original task through the core task manager. Unexpected exceptions release the task through the failure path and are rethrown.

[The request fixture](../tests/behat/fixtures/request_provider.php) is required only by the synchronous step and guarded by `BEHAT_SITE_RUNNING`. It is installed temporarily with `core\di::set(ai_request_provider::class, ...)`. It captures the complete prompt, purpose, component, context, actor and options and returns the real `prompt_response`/`usage` types. The browser/web process has no mock registered. The actor, session, course and tenant-aware DI services are restored in `finally`, as is the original request provider.

The fixture validates report `itemid` linkage. Successful synthetic responses create representative logs through the AI Manager generator and set the response log ID, allowing real lifecycle/privacy cleanup to be exercised. This checks the consumer's linkage and cleanup, **not** the AI Manager's own HTTP transport, usage accounting or logging implementation. No external AI request is needed or intended.

One step executes one queued task only. Newly scheduled retries or replacement generations stay queued for assertions; the next explicit worker step can run that task irrespective of its due time. Retry checks compare `nextruntime` with the execution's start/end timestamps and the expected 60/120/240-second delay. There are no sleeps, polling loops, CLI subprocesses or arbitrary exception suppression. Request counts/contents refer to the most recent synchronous execution.

[The failing log fixture](../tests/behat/fixtures/failing_log_provider.php) replaces only a selected chat source read, then restores the original adapter. Forum collection, source authorisation, the global budget and the worker remain real.

## Generator contracts

[The shared data generator](../tests/generator/lib.php) supports deliberate stored fixtures; production creation is tested separately through `report_manager::save()` and the form. [The Behat generator](../tests/generator/behat_report_ai_analysis_generator.php) resolves readable names to IDs.

| Entity | Required Behat fields | Optional controls |
| --- | --- | --- |
| `report_ai_analysis > reports` | `title`, `course` (shortname), `user` (username), `prompt` | `status`, error fields, `ai_model_name`, `resultformat`, `legacydata`, `truncated`, `runversion`, `action`, `queue_task`, `taskuser`, scope fields |
| `report_ai_analysis > subjects` | `report` (unique title), `user` | Nullable `source_data` and `ai_result`; do not infer subjects from enrolments |
| `report_ai_analysis > chat entries` | `course`, `user`, `prompttext` | `promptcompletion`, `timecreated`, `deleted`, `purpose`, `requestoptions`, `itemid` |
| `report_ai_analysis > templates` | `title`, `content` | `enabled`, `sortorder` |

Report scope fields are `analysis_mode`, comma-separated `participants` (usernames), `roles` (shortnames), `groups` (idnumbers), `sources` (activity idnumbers or `chat`) and numeric `timestart`/`timeend`. An explicitly empty `participants` value stays an empty selection. Explicit `scope_details` can be supplied for legacy/invalid-scope fixtures. Normal default scope JSON includes the actual `courseid` and an empty filter **object**, without the old accidental empty participant restriction.

`queue_task=1` creates an actual task with the report generation; it is opt-in. A pending status alone does not imply a task exists. `taskuser` is independent of report ownership. The shared PHP generator also accepts explicit `subjects` user IDs and deliberately non-course contexts for negative API fixtures. It preserves supplied null source/result values. New fixture results default to `FORMAT_HTML`; legacy tests must explicitly request `FORMAT_MARKDOWN`. No PHPUnit test files were edited.

## Important new steps

- **I queue AI analysis … as … in … with:** submits the real manager API under the named actor with resolved source/participant/group/role/date filters.
- **I run the AI analysis task for …** runs the real queue entry and returns deterministic HTML. Variants return a typed HTTP-like failure, an empty successful response, an untyped exception, or a failing chat source.
- **… while I cancel / cancel and rerun / delete it** invokes the real manager from the simulated in-flight request callback. A stale result must not change the current generation, and linked logs must be anonymised when appropriate.
- **there should have been … AI analysis requests**, request content/absence/occurrence assertions and **all AI analysis requests should belong to …** inspect actual adapter calls and enforce the final prompt bound.
- **a direct … request … should be denied** authenticates with the browser cookie and current sesskey, checks the specific permission response, and compares report/subject/task snapshots. CSRF and wrong-course variants check their own errors. Driver/network/server failures are not accepted as permission denials.
- **an AI analysis manager … call … should be denied** checks the same ownership policy without relying on an endpoint's checks; only the expected permission exceptions are caught.
- Export assertions download from the real authenticated endpoint, parse JSON/HTML, check format markers, omit creator email and diagnostics, and enforce the raw-data capability. Browser presentation checks use harmless active-markup probes with `forceclean` disabled.
- State, task count/generation, retry delay, fresh generation, scope roundtrip, exact subject attribution and separate individual result assertions check database effects, not just notifications.
- Privacy discovery and approved deletion steps call the actual provider. Deletion assertions require linked logs to exist and verify anonymisation while retaining usage statistics.
- Bulk report and Unicode-boundary helpers make pagination and multibyte truncation deterministic. Template order is checked in rendered rows, without manipulating theme overlays.

## Coverage map

| Feature | Positive and negative contract covered |
| --- | --- |
| [basic_navigation.feature](../tests/behat/basic_navigation.feature) | Course navigation for editing teachers/managers; no default student navigation |
| [capabilities.feature](../tests/behat/capabilities.feature) | Explicit capability overrides, non-editing read-only access, student grants, missing action/raw-data permissions |
| [report_access.feature](../tests/behat/report_access.feature) | Private list/counts, sharing on/off, direct view/export/edit/re-run/cancel/delete denial, direct manager denial, `viewall`/`manageall` independence, manager actor distinct from owner, wrong course |
| [availability.feature](../tests/behat/availability.feature) | Genuine configured widgets and data-transfer notice; consent/quota/purpose disabled states; hidden tenant; usable Cancel |
| [create_report.feature](../tests/behat/create_report.feature), [create_report_simple.feature](../tests/behat/create_report_simple.feature) | Form fields, required prompt, supported sources, mode/filter selection, Unicode title, optional/open/reversed date ranges, unchanged edit roundtrip |
| [view_reports.feature](../tests/behat/view_reports.feature) | Statuses and failure messages, debug-display gates, real pagination beyond 25 rows |
| [delete_reports.feature](../tests/behat/delete_reports.feature), [rerun_reports.feature](../tests/behat/rerun_reports.feature) | Confirmation, eligible states, owner/manager actions, real queue removal, reset/generation/actor, missing capability and invalid sesskey |
| [background_processing.feature](../tests/behat/background_processing.feature) | Full task completion, integer duration, one aggregated versus per-author requests, separated results/subjects, raw-storage-off/privacy/log cleanup, in-flight cancel/re-run/delete, normal course deletion, access revocation, exponential bounded retries, consent/empty/untyped errors, final prompt limit |
| [source_scope.feature](../tests/behat/source_scope.feature) | Activity/author selection, role/group intersections, empty selections/roles, private replies, separate groups, revoked module/chat rights, forum thread-date semantics, chat open boundaries, deleted/non-chat/history/foreign-course exclusion, hidden dates, source failure, shared record budget |
| [export_reports.feature](../tests/behat/export_reports.feature) | Actual JSON/HTML downloads, completed/failed eligibility, rejected pending/running/cancelled export, safe markup/plain mode, separate raw-data capability |
| [manage_templates.feature](../tests/behat/manage_templates.feature) | Five-button/six-option exact prompt roundtrip, disabled templates, CRUD/toggle/order, administrator-only endpoints |
| [settings.feature](../tests/behat/settings.feature) | No unsupported timeout setting; invalid/zero/negative/fractional/out-of-range limits rejected; valid boundaries persisted |

## Main integration requirements and release checks

The parallel main implementation must supply the public signatures in [Architecture](architecture.md): `report_access`, `report_manager::save/rerun/cancel/delete`, `delete_for_privacy`, generation-aware task custom data and the request adapter's fifth options-array parameter. It must install the five new report fields and `report_ai_analysis_users`, preserve ownership while queueing the requesting actor, and set `FORMAT_HTML` for new results. The fixtures deliberately do not create substitute production classes or silently fall back when these APIs/schema elements are absent.

At the source inspection for this change, the lifecycle classes and schema work were still pending, and the request adapter still accepted only four parameters. Those are integration dependencies for the main owner, not reasons to weaken the Behat assertions. Verify the final access defaults, especially non-editing teacher `view`, against the integrated capability definitions; the read-only teacher scenarios use an explicit grant and do not depend on an unsettled default.

The main error-classification integration must also extend `error_info`'s allowlist for `error_access_revoked`, `error_source_forbidden`, `error_source_failed`, `error_task_changed` and, where used by the worker, `error_invalid_limit`. Their EN/DE strings now exist, but adding a translation alone does not prevent the existing allowlist from replacing a recognised new failure with `error_unknown`.

Before release, additionally run the main owner's PHPUnit/integration coverage on the supported database/version matrix, including PostgreSQL. The following are not proven by a browser fixture alone:

- atomic rollback on queue/persistence failure and multi-process locking/duplicate-task races;
- upgrade/orphan cleanup and conservative legacy subject maps, and database-matrix verification of the course deletion scenario;
- all Privacy API export/single-user/bulk/context deletion combinations, especially withholding other people's aggregate results;
- real AI Manager consent/quota enforcement, logging behaviour and transport integration (separate controlled tests, never a live external provider in this Behat suite);
- minimum Moodle/PHP/AI-Manager dependency declarations and CI matrix alignment;
- English/German language-key parity/sorting, translated HTML/privacy exports, coding standards and existing PHPUnit compatibility.

These are explicit remaining verification obligations, not passing claims. No production UI, collector, access/manager/task, schema, version, CI, library or PHPUnit files are owned by this change.

Copyright © 2026 ISB Bayern. Author: Dr. Peter Mayer. GNU GPL v3 or later.