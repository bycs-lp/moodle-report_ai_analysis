# AI Conversation Analysis

Course-level Moodle reports for analysing permitted forum contributions and AI Chat request entries through `local_ai_manager` and its `singleprompt` purpose.

## Start here

- [User and administrator guide](docs/index.md): setup, settings, sharing, source selection and privacy.
- [Architecture and integration contract](docs/architecture.md): access policy, manager APIs, generations, limits, storage and cleanup.
- [Testing guide](docs/testing.md): Behat fixtures, positive/negative coverage and remaining integration checks.

The documentation describes the MBS-11031 contract. It is not a claim that a test suite or a deployment verification has passed. Installation and upgrade metadata remain authoritative for dependency checks; support for a Moodle/PHP/AI-Manager combination must also be verified on that combination.

## Requirements and setup

- Moodle 5.x with PHP 8.3 or later, subject to the exact Moodle release's requirements.
- A compatible `local_ai_manager` with the `singleprompt` purpose, public request/log APIs, availability widgets and `local\data_wiper`.
- An enabled AI connector instance assigned to `singleprompt` for the user's tenant and AI-Manager role.
- AI usage permission, enabled tenant, accepted terms and sufficient quota for the executing user.
- Moodle cron for asynchronous processing. Behat uses a synchronous, test-only request adapter and never requires a real AI service.

Install through Moodle's normal plugin installation/upgrade workflow. Configure the AI Manager tenant, connector and purpose before creating reports. Open **Course → Reports → AI Conversation Analysis**. Only courses are supported; there are no system/category reports, and quizzes are not data sources.

## Core behaviour

- **Aggregated:** one request for the permitted contributions of all included authors.
- **Individual:** one request for each author with included data; requests never include another participant's contributions. Individual results are stored separately and combined as HTML in the parent report.
- **Filters:** active enrolment, selected participants, roles, groups and source permissions are intersected. An explicitly empty participant selection means nobody, not everybody.
- **Time:** either boundary can be open (`0`). Chat entries are filtered by request time. Forums select qualifying threads, then retain only permitted posts by selected authors in those threads.
- **Limits:** one global record budget, at most 10000 posts/request entries, and a final prompt limit of 1000000 characters including instructions. Shortening is explicitly reported.
- **Sharing:** read-only access to other users' reports when enabled. Ownership or `manageall` plus the action capability is still required for mutations.
- **Export:** completed and failed reports only. JSON prompt/result fields contain safe presentation HTML; creator email is omitted. Raw data needs its separate capability.
- **Lifecycle:** atomic generations invalidate stale work after edit, cancel, re-run or deletion. Already transmitted requests cannot be recalled.

## Permissions at a glance

All capability names below have the prefix `report/ai_analysis:`. Course access is required in addition to the capability checks.

| Capability | Meaning |
| --- | --- |
| `view` | Read own reports, shared reports, or other reports when combined with `viewall` |
| `viewall` | Extend reading to other users' private reports; does not replace `view` |
| `create` | Create reports and edit reports owned by the user or covered by `manageall` |
| `rerun` | Re-run eligible reports owned by the user or covered by `manageall` |
| `delete` | Delete or cancel eligible reports owned by the user or covered by `manageall` |
| `manageall` | Extend mutation scope to other owners; never replaces an action capability |
| `viewrawdata` | Read stored raw data in an otherwise readable report |

Editing teachers and managers retain raw-data access by default. `viewall` and `manageall` are manager defaults. Non-editing teachers are read-only when granted `view`; check the installed capability defaults and local overrides, especially after an upgrade. Neither sharing nor report capabilities bypass forum/chat permissions when collecting data.

## Data protection

Turning off **Store raw data** disables the report's source copies, not AI transmission, result storage or AI Manager logging. Both report creators and actual analysed authors are privacy data subjects. Privacy exports omit other people's data and inseparable combined results. Deletion invalidates affected reports and anonymises linked manager logs through the public data-wiping API while preserving usage statistics. Legacy mappings are conservative; no scheduled automatic report deletion is promised.

## Licence

Copyright © 2026 ISB Bayern. Author: Dr. Peter Mayer.

GNU GPL v3 or later. See the Moodle distribution licence. Contact the ISB Bayern development team for issues and feature requests.
