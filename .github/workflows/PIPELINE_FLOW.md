# CI/CD Pipeline Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                     TRIGGER: Push / Pull Request                 │
└───────────────────────────────┬─────────────────────────────────┘
                                │
                ┌───────────────┴───────────────┐
                │    Matrix Strategy (30x)       │
                │  PHP: 8.1, 8.2, 8.3           │
                │  Moodle: 403-501 STABLE       │
                │  DB: PostgreSQL, MariaDB      │
                └───────────────┬───────────────┘
                                │
        ┌───────────────────────┼───────────────────────┐
        │                       │                       │
        ▼                       ▼                       ▼
   ┌─────────┐           ┌─────────┐           ┌─────────┐
   │ PHP 8.1 │           │ PHP 8.2 │           │ PHP 8.3 │
   │ Moodle  │           │ Moodle  │           │ Moodle  │
   │ 403-501 │           │ 403-501 │           │ 403-501 │
   │ PG+MB   │           │ PG+MB   │           │ PG+MB   │
   └────┬────┘           └────┬────┘           └────┬────┘
        │                     │                     │
        └─────────────────────┴─────────────────────┘
                                │
                                ▼
        ┌────────────────────────────────────────────┐
        │  1. Checkout Repository                    │
        └────────────────┬───────────────────────────┘
                         ▼
        ┌────────────────────────────────────────────┐
        │  2. Setup PHP Environment                  │
        └────────────────┬───────────────────────────┘
                         ▼
        ┌────────────────────────────────────────────┐
        │  3. Initialize Moodle Plugin CI            │
        └────────────────┬───────────────────────────┘
                         ▼
        ┌────────────────────────────────────────────┐
        │  4. Install Dependencies                   │
        │     - local_ai_manager                     │
        │     - block_ai_chat                        │
        └────────────────┬───────────────────────────┘
                         │
        ┌────────────────┴────────────────────────────┐
        │  IF: Pull Request                           │
        │  ┌──────────────────────────────────────┐   │
        │  │ 🤖 PRE-CODE REVIEW                   │   │
        │  │                                      │   │
        │  │  5a. Detect Changed Files           │   │
        │  │      📝 *.php, *.js, *.mustache     │   │
        │  │                                      │   │
        │  │  5b. Quick Complexity Check         │   │
        │  │      ⚠️  Files > 500 lines          │   │
        │  │      🔍 Debug statements            │   │
        │  │      🔍 TODOs/FIXMEs                │   │
        │  │                                      │   │
        │  │  5c. Moodle Specific Checks         │   │
        │  │      ✅ MOODLE_INTERNAL             │   │
        │  │      ✅ PHPDoc headers              │   │
        │  │      ⚠️  Deprecated functions       │   │
        │  │                                      │   │
        │  │  5d. Security Check                 │   │
        │  │      🔒 SQL Injection risks         │   │
        │  │      🔒 XSS risks                   │   │
        │  │      🔒 File inclusion risks        │   │
        │  │      ✅ Capability checks           │   │
        │  │                                      │   │
        │  │  5e. Generate PR Comment            │   │
        │  │      💬 Review report               │   │
        │  │      📋 Manual checklist            │   │
        │  └──────────────────────────────────────┘   │
        └────────────────┬────────────────────────────┘
                         ▼
        ┌────────────────────────────────────────────┐
        │  6. PHP Lint (Syntax Check)                │
        │     ✅ PHP syntax validation               │
        └────────────────┬───────────────────────────┘
                         ▼
        ┌────────────────────────────────────────────┐
        │  7. Moodle Code Checker (phpcs)            │
        │     ✅ Coding standards (max 0 warnings)   │
        └────────────────┬───────────────────────────┘
                         ▼
        ┌────────────────────────────────────────────┐
        │  8. Moodle PHPDoc Checker                  │
        │     ✅ PHPDoc standards (max 0 warnings)   │
        └────────────────┬───────────────────────────┘
                         ▼
        ┌────────────────────────────────────────────┐
        │  9. Validate Plugin Structure              │
        │     ✅ version.php, db/, lang/             │
        └────────────────┬───────────────────────────┘
                         ▼
        ┌────────────────────────────────────────────┐
        │  10. Check Upgrade Savepoints              │
        │      ✅ db/upgrade.php consistency         │
        └────────────────┬───────────────────────────┘
                         ▼
        ┌────────────────────────────────────────────┐
        │  11. Mustache Lint                         │
        │      ✅ Template validation                │
        └────────────────┬───────────────────────────┘
                         ▼
        ┌────────────────────────────────────────────┐
        │  12. Grunt (JS/CSS)                        │
        │      ✅ ESLint, Stylelint                  │
        └────────────────┬───────────────────────────┘
                         ▼
        ┌────────────────────────────────────────────┐
        │  13. PHPUnit Tests                         │
        │      🧪 Unit tests (fail-on-warning)       │
        └────────────────┬───────────────────────────┘
                         ▼
        ┌────────────────────────────────────────────┐
        │  14. Behat Tests (Chrome)                  │
        │      🧪 Integration tests                  │
        └────────────────┬───────────────────────────┘
                         │
        ┌────────────────┴────────────────────────────┐
        │  IF: Behat Failure                          │
        │  ┌──────────────────────────────────────┐   │
        │  │ 15. Upload Behat Faildump            │   │
        │  │     📸 Screenshots                   │   │
        │  │     📝 Logs                          │   │
        │  │     ⏰ Retention: 7 days             │   │
        │  └──────────────────────────────────────┘   │
        └─────────────────────────────────────────────┘
                         │
        ┌────────────────┴────────────────┐
        │  SUCCESS                         │   FAILURE
        │  ✅ All checks passed            │   ❌ Check logs
        └──────────────────────────────────┘


═══════════════════════════════════════════════════════════════════
                         RESULT MATRIX
═══════════════════════════════════════════════════════════════════

         │ PHP 8.1 │ PHP 8.2 │ PHP 8.3 │
─────────┼─────────┼─────────┼─────────┤
M403 PG  │   ✅    │   ✅    │   ✅    │
M403 MB  │   ✅    │   ✅    │   ✅    │
M404 PG  │   ✅    │   ✅    │   ✅    │
M404 MB  │   ✅    │   ✅    │   ✅    │
M405 PG  │   ✅    │   ✅    │   ✅    │
M405 MB  │   ✅    │   ✅    │   ✅    │
M500 PG  │   ✅    │   ✅    │   ✅    │
M500 MB  │   ✅    │   ✅    │   ✅    │
M501 PG  │   ✅    │   ✅    │   ✅💬  │ ← PR Comment here
M501 MB  │   ✅    │   ✅    │   ✅    │
─────────┴─────────┴─────────┴─────────┘

Legend:
  M403 = MOODLE_403_STABLE
  M404 = MOODLE_404_STABLE
  M405 = MOODLE_405_STABLE
  M500 = MOODLE_500_STABLE
  M501 = MOODLE_501_STABLE
  PG   = PostgreSQL 17
  MB   = MariaDB 10.11
  💬   = Pre-Code Review Comment (only on this combination)
```

## Timeline (Estimated)

```
┌─────────────────────────────────────────────────────────────┐
│ Stage                    │ Time      │ Cumulative           │
├──────────────────────────┼───────────┼──────────────────────┤
│ Checkout & Setup         │ ~2 min    │ 0-2 min             │
│ Initialize Plugin CI     │ ~3 min    │ 2-5 min             │
│ Install Dependencies     │ ~5 min    │ 5-10 min            │
│ Pre-Code Review (PR)     │ ~1 min    │ 10-11 min           │
│ PHP Lint                 │ ~30 sec   │ 11-11.5 min         │
│ Code Checker (phpcs)     │ ~1 min    │ 11.5-12.5 min       │
│ PHPDoc Checker           │ ~1 min    │ 12.5-13.5 min       │
│ Validate & Savepoints    │ ~30 sec   │ 13.5-14 min         │
│ Mustache Lint            │ ~30 sec   │ 14-14.5 min         │
│ Grunt (JS/CSS)           │ ~2 min    │ 14.5-16.5 min       │
│ PHPUnit Tests            │ ~3 min    │ 16.5-19.5 min       │
│ Behat Tests              │ ~5 min    │ 19.5-24.5 min       │
└──────────────────────────┴───────────┴──────────────────────┘

Total per job: ~25 minutes
Total for all 30 jobs (parallel): ~25-30 minutes
```
