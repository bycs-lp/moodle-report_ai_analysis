# GitHub Actions CI/CD Pipeline

## Overview

This pipeline performs automatic tests and code reviews for the `report_ai_analysis` Moodle plugin.

## Pipeline Structure

### Pre-Code Review (Pull Requests Only)

The pipeline includes an automatic **Pre-Code Review** that runs on pull requests:

#### 1. Changed Files Detection
- Detects all changed `.php`, `.js` and `.mustache` files
- Uses: `tj-actions/changed-files@v41`

#### 2. Quick Complexity Check
- ⚠️ Warns about files > 500 lines
- 🔍 Searches for debug statements (`var_dump`, `print_r`, `die()`)
- 🔍 Finds TODOs and FIXMEs

#### 3. Moodle Specific Checks
- ✅ Checks for `MOODLE_INTERNAL || die()` in all PHP files
- ✅ Validates PHPDoc headers (@package, @copyright, @license)
- ⚠️ Warns about deprecated PHP functions

#### 4. Security Check
- 🔒 SQL injection risks (dynamic SQL queries)
- 🔒 XSS risks (direct output of user input)
- 🔒 File inclusion risks (dynamic includes)
- ✅ Counts capability checks

#### 5. Generate Report
- Creates automatic comment in pull request
- Shows changed files
- Lists found issues
- Provides complete review checklist

### Standard Moodle Plugin CI Tests

After the Pre-Code Review, standard tests run:

1. **PHP Lint** - Syntax validation
2. **Moodle Code Checker (phpcs)** - Coding Standards (max 0 warnings)
3. **Moodle PHPDoc Checker** - PHPDoc Standards (max 0 warnings)
4. **Validating** - Plugin structure validation
5. **Check upgrade savepoints** - Upgrade script consistency
6. **Mustache Lint** - Template validation
7. **Grunt** - JavaScript/CSS Linting (max 0 warnings)
8. **PHPUnit tests** - Unit tests (fail-on-warning)
9. **Behat features** - Integration tests (Chrome)

## Test Matrix

Tests run on the following combinations:

- **PHP Versions:** 8.1, 8.2, 8.3
- **Moodle Branches:** 
  - MOODLE_403_STABLE
  - MOODLE_404_STABLE
  - MOODLE_405_STABLE
  - MOODLE_500_STABLE
  - MOODLE_501_STABLE
- **Databases:** PostgreSQL 17, MariaDB 10.11

**Total:** 30 test combinations (3 × 5 × 2)

## Dependencies

The pipeline automatically installs:
- `mebis-lp/moodle-local_ai_manager`
- `bycs-lp/moodle-block_ai_chat`

## Local Tests Before Push

Before pushing, you should run the following checks locally:

```bash
# Code Checker (Coding Standards)
cd /home/peter/dev/500_docker_mbsmoodle_dev
./bindev/codechecker.sh report/ai_analysis

# Moodle Check (PHPDoc Standards)
./bindev/moodlecheck.sh report/ai_analysis

# Auto-Fix von Coding Standard Issues
./bindev/codechecker_autofix.sh report/ai_analysis

# PHPUnit Tests
./bindev/phpunit.sh --filter report_ai_analysis
```

## Pull Request Workflow

1. **Push your changes** to a feature branch
2. **Create a pull request** against `main`
3. **Pre-Code Review runs automatically**:
   - Comment with analysis appears in PR
   - Review checklist is generated
4. **Standard CI tests run**:
   - All 30 matrix combinations are tested
5. **Review and Merge**:
   - Fix all found issues
   - Complete the review checklist
   - Merge after successful review

## Behat Faildump

On Behat failures, screenshots and logs are automatically uploaded:
- **Retention:** 7 days
- **Location:** GitHub Actions Artifacts
- **Name:** `Behat Faildump (PHP-Version, Branch, DB)`

## Notes

### Pre-Code Review Comment
The automatic comment is created only for **one** matrix combination:
- PHP 8.3
- MOODLE_501_STABLE
- PostgreSQL

This prevents 30 identical comments in the PR.

### Fail-Fast Disabled
The matrix has `fail-fast: false`, so all combinations are tested even if one fails.

### Continue-on-Error
The Pre-Code Review steps use `|| true` to not abort on warnings.

## Troubleshooting

### Pipeline fails at PHP Lint
→ Check PHP syntax locally: `php -l <file.php>`

### Pipeline fails at Code Checker
→ Run locally: `./bindev/codechecker.sh report/ai_analysis`

### Pipeline fails at PHPDoc Checker
→ Run locally: `./bindev/moodlecheck.sh report/ai_analysis`

### Behat tests fail
→ Check the uploaded Behat Faildump artifacts for screenshots and logs

## Additional Resources

- [Moodle Coding Style](https://moodledev.io/general/development/policies/codingstyle)
- [Moodle Plugin CI](https://moodlehq.github.io/moodle-plugin-ci/)
- [GitHub Actions Docs](https://docs.github.com/en/actions)
