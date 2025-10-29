# GitHub Copilot Automatische Code Review - Setup Anleitung

Diese Anleitung erklärt, wie GitHub Copilot so konfiguriert wird, dass es bei jedem Push in einen Pull Request automatisch eine Code-Review nach Moodle Coding Standards durchführt.

## 📋 Voraussetzungen

- GitHub Copilot Business oder Enterprise Lizenz
- Repository mit Admin-Rechten
- GitHub Actions aktiviert

## 🔧 Schritt-für-Schritt Konfiguration

### 1️⃣ Repository Settings - Copilot Code Review aktivieren

1. Gehe zu deinem Repository: `https://github.com/bycs-lp/moodle`
2. Klicke auf **Settings** (oben rechts)
3. Im linken Menü: **Code security and analysis**
4. Scrolle zu **"GitHub Copilot"**
5. Aktiviere: **"Copilot code review"** ✅

   ![Copilot Settings](https://docs.github.com/assets/cb-148076/mw-1440/images/help/repository/copilot-code-review-enable.webp)

### 2️⃣ Automatische Review-Anfragen aktivieren

1. Gehe zu **Settings** → **Pull Requests**
2. Aktiviere: **"Automatically request reviews from Copilot"** ✅
3. Konfiguriere die Trigger:
   - ✅ **On pull request open** (bei PR-Erstellung)
   - ✅ **On pull request synchronize** (bei jedem Push/Force Push)

### 3️⃣ Branch Protection Rules (Optional, aber empfohlen)

1. Gehe zu **Settings** → **Branches**
2. Erstelle/Bearbeite eine Branch Protection Rule für deinen Main-Branch
3. Aktiviere:
   - ✅ **Require pull request reviews before merging**
   - ✅ **Require review from Copilot** (erscheint nach Aktivierung von Copilot Reviews)

### 4️⃣ Dateien im Repository

Die folgenden Dateien sind bereits im Repository und konfiguriert:

#### `.github/CODEOWNERS`
Definiert, dass Copilot automatisch als Reviewer für relevante Dateien hinzugefügt wird:
```
*.php @copilot
*.js @copilot
*.mustache @copilot
db/*.xml @copilot
lang/**/*.php @copilot
```

#### `.github/copilot-instructions.md`
Enthält die Moodle-spezifischen Review-Kriterien für Copilot (siehe separate Datei).

#### `.github/workflows/ci.yml`
GitHub Actions Workflow, der bei jedem PR:
1. Die Moodle Review-Guidelines als Kommentar postet
2. Copilot mit `@copilot` erwähnt
3. Standard Moodle CI-Checks ausführt (phpcs, phpdoc, phpunit, behat)

## 🎯 Wie es funktioniert

### Bei Pull Request Erstellung:
1. **Automatisch:** Copilot wird als Reviewer hinzugefügt
2. **GitHub Actions:** Postet Moodle Review-Guidelines
3. **Copilot:** Beginnt automatisch mit der Code-Review
4. **Ergebnis:** Review-Kommentare direkt im PR

### Bei jedem Push in einen PR:
1. **Trigger:** `pull_request: synchronize` Event
2. **Copilot:** Reviewed nur die **neuen/geänderten** Dateien
3. **Update:** Vorherige Review-Kommentare werden als "outdated" markiert
4. **Neu:** Fresh Review-Kommentare für aktuelle Änderungen

### Bei Force Push:
1. **Erkannt als:** `pull_request: synchronize` Event
2. **Verhalten:** Identisch zu normalem Push
3. **Copilot:** Reviewed den kompletten neuen Stand

## ✅ Testen der Konfiguration

1. Erstelle einen Test-Branch:
   ```bash
   git checkout -b test/copilot-review
   ```

2. Mache eine kleine Änderung (z.B. in einer PHP-Datei):
   ```bash
   echo "// Test comment" >> classes/test.php
   git add .
   git commit -m "Test: Copilot Review"
   git push origin test/copilot-review
   ```

3. Erstelle einen Pull Request auf GitHub

4. Warte ca. 30-60 Sekunden

5. Du solltest sehen:
   - ✅ Copilot als Reviewer hinzugefügt
   - ✅ GitHub Actions Workflow läuft
   - ✅ Review-Guidelines Kommentar erscheint
   - ✅ Copilot beginnt mit der Review

## 🔍 Was wird geprüft?

### Automatische Checks durch Copilot:

#### 🔒 Security (CRITICAL):
- Input-Validierung mit `required_param()`/`optional_param()`
- Output-Escaping mit `s()`, `format_text()`, `html_writer`
- Capability-Checks mit `require_capability()`
- CSRF-Protection (sesskey)
- SQL-Injection Prevention (nur `$DB` API)

#### 📚 Moodle Coding Standards:
- PHPDoc Headers (@package, @copyright, @license)
- `defined('MOODLE_INTERNAL') || die();` in allen PHP-Dateien
- Core APIs statt Custom-Implementierungen
- Strings in Language-Files

#### ⚡ Performance:
- N+1 Query Detection
- Proper DB Query Limits
- Transaction Usage

#### 🎯 Best Practices:
- `version.php` Updates bei DB-Changes
- Capability Definitions in `db/access.php`
- Proper Context Usage

## 📊 Review-Output

Copilot postet Reviews im folgenden Format:

```markdown
### ✅ Successes
- Proper input validation with required_param()
- All outputs properly escaped
- Good PHPDoc documentation

### ⚠️ Warnings
- Function `calculate_total()` is 65 lines (should be < 50)
- Consider adding index for 'userid' column in db/install.xml

### ❌ Critical Issues
- Missing capability check in line 42
- Direct $_GET access in line 89 (use required_param instead)
- Missing CSRF protection in form
```

## 🚀 Erweiterte Konfiguration

### Custom Review-Trigger

Du kannst die Review auch manuell triggern mit:
```
@copilot review this PR according to Moodle coding standards
```

### Spezifische Dateien reviewen

```
@copilot review classes/manager.php for security issues
```

### Follow-Up Fragen

```
@copilot why is this a security issue?
@copilot how should I fix the N+1 query in line 45?
```

## 🛠️ Troubleshooting

### Copilot reviewed nicht automatisch?

1. **Check Repository Settings:**
   - Settings → Code security → Copilot code review ✅

2. **Check Lizenz:**
   - Copilot Business/Enterprise aktiv?
   - Repository in Organization eingebunden?

3. **Check CODEOWNERS:**
   - Datei `.github/CODEOWNERS` vorhanden?
   - `@copilot` als Owner eingetragen?

4. **Check Permissions:**
   - Hat GitHub Actions Schreibrechte?
   - `permissions: pull-requests: write` in `ci.yml`?

### Review erscheint verzögert?

- **Normal:** 30-60 Sekunden nach PR-Erstellung/Push
- **Bei großen PRs:** Bis zu 2-3 Minuten
- **Workaround:** Manuell triggern mit `@copilot review`

### Review-Guidelines erscheinen nicht?

1. **Check GitHub Actions:**
   - Läuft der Workflow erfolgreich?
   - Gehe zu **Actions** Tab und prüfe Logs

2. **Check Bedingungen in ci.yml:**
   ```yaml
   if: github.event_name == 'pull_request' &&
       matrix.php == '8.3' &&
       matrix.database == 'pgsql' &&
       matrix.moodle-branch == 'MOODLE_501_STABLE'
   ```

## 📚 Weitere Ressourcen

- [GitHub Copilot Code Review Docs](https://docs.github.com/en/copilot/how-tos/use-copilot-agents/request-a-code-review)
- [Moodle Coding Standards](https://moodledev.io/general/development/policies/codingstyle)
- [Moodle Plugin CI](https://moodlehq.github.io/moodle-plugin-ci/)

## 🎓 Best Practices

1. **Kleine PRs:** Copilot reviewed kleinere PRs schneller und gründlicher
2. **Klare Commits:** Aussagekräftige Commit-Messages helfen Copilot den Kontext zu verstehen
3. **Respond to Reviews:** Antworte auf Copilot-Kommentare für Follow-Up-Diskussionen
4. **Lokale Checks zuerst:** Run `codechecker.sh` und `moodlecheck.sh` lokal vor dem Push

---

**Erstellt:** Oktober 2025
**Für:** Moodle Plugin `report_ai_analysis`
**Maintainer:** MBS Team
