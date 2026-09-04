# GitHub Copilot Instructions for Moodle Plugin Development

## Project Context
This is a **Moodle Report Plugin** (`report_ai_analysis`) that analyzes AI-related data from various Moodle activities and provides comprehensive reports for educators.

## Critical Development Standards

### 🎯 Moodle Coding Style (MANDATORY)
- **Follow**: https://moodledev.io/general/development/policies/codingstyle
- **Line length**: Maximum 132 characters
- **Indentation**: 4 spaces (no tabs)
- **PHPDoc**: Required for all files, classes, functions, and class properties
- **Naming conventions**:
  - Classes: `pluginname_classname`
  - Functions: `lowercase_with_underscores`
  - Constants: `UPPERCASE_WITH_UNDERSCORES`

### 🛡️ Security First (CRITICAL)
```php
// Input Validation - ALWAYS use Moodle parameter functions
$id = required_param('id', PARAM_INT);
$text = optional_param('text', '', PARAM_TEXT);
$html = optional_param('content', '', PARAM_CLEANHTML);

// Output Escaping - NEVER output raw user data
echo html_writer::tag('div', s($usertext));
echo format_text($content, FORMAT_HTML, ['context' => $context]);

// Capability Checks - BEFORE any sensitive operation
require_capability('report/ai_analysis:view', $context);
```

#### Error and Exception Output (MANDATORY)
- Never use `$e->getMessage()`, `debuginfo`, stack traces, connector responses, or stored technical errors as a user-facing error description.
- Keep localized user-facing descriptions separate from technical details. Use an allow-listed language string for the description and dedicated non-user-facing storage for diagnostics.
- Technical details may be rendered only through `report_ai_analysis\error_info`, only when both `$CFG->debugdeveloper` and `$CFG->debugdisplay` are enabled, and never when `NO_DEBUG_DISPLAY` is active.
- Apply this rule to every output channel, including tables, detail pages, notifications, JSON/HTML exports, web services, and privacy exports. Privacy exports must never expose technical error details.
- Administrative task and cron output may include the original error message and debug information to support diagnosis. These channels must remain restricted to administrators.
- Use Moodle's `debugging($message, DEBUG_DEVELOPER)` for development diagnostics where appropriate; it already follows `debugdisplay` and `NO_DEBUG_DISPLAY`. Never replace it with direct output.
- Treat legacy database values as untrusted technical details; escaping is required but does not replace the Moodle debug-display check.
- Tests must prove that details are hidden when either Moodle debug setting is disabled and shown only when both are enabled.

### 📊 Database Operations (Core API Only)
```php
// Use ONLY Moodle DB API - NEVER raw SQL without parameters
$record = $DB->get_record('table', ['id' => $id], '*', MUST_EXIST);
$records = $DB->get_records('table', ['courseid' => $courseid]);

// Parameterized queries - ALWAYS use named placeholders
$sql = "SELECT * FROM {table}
        WHERE courseid = :courseid
        AND userid = :userid";
$params = ['courseid' => $courseid, 'userid' => $userid];
$records = $DB->get_records_sql($sql, $params);

// Transactions for multi-step operations
$transaction = $DB->start_delegated_transaction();
try {
    $DB->insert_record('table1', $data1);
    $DB->update_record('table2', $data2);
    $transaction->allow_commit();
} catch (Exception $e) {
    $transaction->rollback($e);
}
```

### 🔍 Code Review Focus Areas

#### 1. Security Vulnerabilities
- ❌ **Reject**: Direct access to `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`
  - ✅ **Require**: `required_param()`, `optional_param()`
- ❌ **Reject**: Unescaped output (`echo $variable`)
  - ✅ **Require**: `s()`, `format_text()`, `html_writer::*`
- ❌ **Reject**: Missing capability checks
  - ✅ **Require**: `require_capability()` before sensitive operations
- ❌ **Reject**: SQL injection risks (string concatenation in SQL)
  - ✅ **Require**: Named parameters (`:placeholder`)
- ❌ **Reject**: CSRF vulnerabilities (forms without sesskey)
  - ✅ **Require**: `confirm_sesskey()` in form processing
- ❌ **Reject**: Direct output of exception messages, debug information, stack traces, or connector errors
  - ✅ **Require**: Localized descriptions plus `report_ai_analysis\error_info` for conditional developer details

#### 2. Moodle Standards Compliance
- ❌ **Reject**: Incomplete PHPDoc blocks (missing @package, @copyright, @license)
- ❌ **Reject**: Hardcoded strings in code
  - ✅ **Require**: `get_string('key', 'report_ai_analysis')` + lang file entry
- ❌ **Reject**: Deprecated Moodle functions (check https://moodledev.io)
- ❌ **Reject**: Direct file system operations
  - ✅ **Require**: Moodle File API (`get_file_storage()`, etc.)

#### 3. Performance Issues
- ⚠️ **Flag**: Database queries inside loops (N+1 problem)
  - 💡 **Suggest**: Batch loading with `get_records_list()` or single query with JOINs
- ⚠️ **Flag**: `get_records()` without limits on potentially large datasets
  - 💡 **Suggest**: Add `limitfrom` and `limitnum` parameters
- ⚠️ **Flag**: Missing indexes for frequently queried columns
  - 💡 **Suggest**: Add `<KEY>` or `<INDEX>` in `db/install.xml`
- ⚠️ **Flag**: Repeated `get_string()` calls in loops
  - 💡 **Suggest**: Cache strings before loop

#### 4. Code Quality
- ⚠️ **Flag**: Functions longer than 50 lines
  - 💡 **Suggest**: Extract helper methods
- ⚠️ **Flag**: Classes longer than 500 lines
  - 💡 **Suggest**: Split responsibilities (SRP)
- ⚠️ **Flag**: Files longer than 1000 lines
  - 💡 **Suggest**: Refactor into multiple files
- ⚠️ **Flag**: Code duplication (DRY principle)
- ⚠️ **Flag**: Complex conditional logic (cyclomatic complexity > 10)
  - 💡 **Suggest**: Extract to named methods with clear purposes

#### 5. Testing Requirements
- ❌ **Reject**: New functionality without PHPUnit tests
  - ✅ **Require**: Test class in `tests/` directory
- ❌ **Reject**: Database operations without test coverage
  - ✅ **Require**: Tests using `$this->resetAfterTest()`
- ⚠️ **Flag**: Missing Behat tests for user-facing features
  - 💡 **Suggest**: Add feature file in `tests/behat/`

#### 6. Plugin-Specific Standards
- ❌ **Reject**: Changes to `db/install.xml` or `db/upgrade.php` without updating `version.php`
- ❌ **Reject**: New capabilities without documentation in `db/access.php`
- ❌ **Reject**: New language strings not in alphabetical order
- ⚠️ **Flag**: Missing German translations (`lang/de/`)
- ⚠️ **Flag**: Improper `die()` usage (not following `|| die()` pattern)
  - ✅ **Correct**: `defined('MOODLE_INTERNAL') || die();`
  - ❌ **Wrong**: `die('Error message');` (standalone)

### 📋 Review Checklist Template

When reviewing code, provide feedback in this structure:

```markdown
## 🔒 Security Issues
- [ ] All inputs validated with PARAM_* constants
- [ ] All outputs properly escaped
- [ ] Capability checks present
- [ ] No SQL injection risks
- [ ] CSRF protection in forms

## 🎯 Moodle Standards
- [ ] MOODLE_INTERNAL check present
- [ ] Complete PHPDoc headers
- [ ] Strings in language files
- [ ] Core API usage (no deprecated functions)
- [ ] Proper file structure

## ⚡ Performance
- [ ] No N+1 query patterns
- [ ] Database queries optimized
- [ ] Appropriate limits on bulk operations
- [ ] Caching used where applicable

## ✅ Code Quality
- [ ] Functions focused and < 50 lines
- [ ] Classes cohesive and < 500 lines
- [ ] No code duplication
- [ ] Clear variable/function names
- [ ] Adequate inline comments for complex logic

## 🧪 Testing
- [ ] PHPUnit tests present/updated
- [ ] Behat tests for user features
- [ ] Edge cases covered
- [ ] Test data properly cleaned up

## 📝 Documentation
- [ ] README updated (if needed)
- [ ] version.php updated (if DB changed)
- [ ] Upgrade notes documented
- [ ] API changes documented
```

### 💡 Common Moodle Patterns to Suggest

```php
// Context determination
$context = context_course::instance($courseid);
$context = context_module::instance($cmid);
$context = context_system::instance();

// URL generation
$url = new moodle_url('/report/ai_analysis/view.php', ['id' => $id]);

// Page setup
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_title(get_string('title', 'report_ai_analysis'));
$PAGE->set_heading($course->fullname);

// Output
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('heading', 'report_ai_analysis'));
echo $OUTPUT->footer();

// Forms
$mform = new report_ai_analysis_form();
if ($mform->is_cancelled()) {
    redirect($returnurl);
} else if ($data = $mform->get_data()) {
    confirm_sesskey();
    // Process data
}

// Notifications
\core\notification::success(get_string('success', 'report_ai_analysis'));
\core\notification::error(get_string('error', 'report_ai_analysis'));
```

### 🚫 Anti-Patterns to Flag

```php
// ❌ WRONG: Direct superglobal access
$id = $_GET['id'];

// ❌ WRONG: Unescaped output
echo "<div>$usertext</div>";

// ❌ WRONG: No capability check
$data = $DB->get_record('sensitive_table', ['id' => $id]);

// ❌ WRONG: SQL injection risk
$sql = "SELECT * FROM {table} WHERE name = '$name'";

// ❌ WRONG: N+1 query in loop
foreach ($users as $user) {
    $record = $DB->get_record('table', ['userid' => $user->id]);
}

// ❌ WRONG: Hardcoded string
echo "Welcome to the report";

// ❌ WRONG: Missing transaction for multi-step operation
$DB->insert_record('table1', $data1);
$DB->insert_record('table2', $data2); // Can fail leaving inconsistent state
```

### 📚 Reference Links
- **Moodle Dev Docs**: https://moodledev.io
- **Coding Style**: https://moodledev.io/general/development/policies/codingstyle
- **Security**: https://moodledev.io/general/development/policies/security
- **DB API**: https://moodledev.io/docs/apis/core/dml
- **Testing**: https://moodledev.io/general/development/tools/phpunit

### 🎯 Plugin-Specific Context

This plugin (`report_ai_analysis`):
- Collects data from `local_ai_manager` and `block_ai_chat`
- Provides reports on AI usage in courses
- Requires capabilities: `report/ai_analysis:view`
- Supports multiple export formats (CSV, Excel, PDF)
- Has collectors for: Forum, Quiz, Assignment, Chat conversations

When reviewing changes, consider:
- Integration with AI manager API
- Performance impact on large courses (1000+ students)
- Privacy compliance (GDPR)
- Accessibility (WCAG 2.1 AA)
- Mobile responsiveness

---

**Priority**: Security > Moodle Standards > Performance > Code Quality > Documentation
