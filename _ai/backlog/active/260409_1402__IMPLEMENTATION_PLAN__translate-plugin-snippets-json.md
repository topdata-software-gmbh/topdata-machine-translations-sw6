---
filename: "_ai/backlog/active/260409_1402__IMPLEMENTATION_PLAN__translate-plugin-snippets-json.md"
title: "Add CLI Command to Translate Plugin Snippet JSON Files"
createdAt: 2026-04-09 14:02
updatedAt: 2026-04-09 14:02
status: draft
priority: high
tags: [shopware6, cli, deepl, snippets, i18n]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

## 1) Problem Statement

The plugin currently supports translating database content and configuration XML files, but it does not yet support translating Shopware plugin snippet JSON files (e.g., `Resources/snippet/*.json`). This creates a gap in localization workflows because plugin UI text in snippet files still has to be translated manually.

The required outcome is a new command that can translate snippet JSON files for one specific plugin or for multiple/all plugins in the Shopware custom plugin directory, while preserving existing snippet structure and avoiding unnecessary overwrites.

## 2) Executive Summary

This plan introduces a new console command, `topdata:machine-translations:translate-snippets-json`, that scans plugin snippet files, extracts translatable strings from a source locale file, and writes translated JSON files for one or more target locales using DeepL.

The implementation is split into focused services to follow SOLID principles:
- a plugin/snippet discovery service,
- a snippet translation orchestration service,
- and a JSON file writer/persister service.

The command will support both scoped execution (`--plugin=PluginName`) and batch execution (`--all-plugins`), include safety options (`--force`, `--dry-run`, `--no-backup`), and produce clear CLI reporting. Documentation and examples will be added to the manual and README.

## 3) Project Environment Details

```text
Project: topdata-machine-translations-sw6
Type: Shopware 6 plugin (Symfony Console command architecture)
Language: PHP 8+
Framework/Libraries: Shopware 6, Symfony Console, Doctrine DBAL (existing), DeepL API via existing DeeplTranslator helper
Repository Root: /topdata/clones/focus/vol/www/custom/plugins/topdata-machine-translations-sw6
Plugin Directory to Process: /www/custom/plugins (runtime option; configurable via command option)
Existing Related Commands:
- topdata:machine-translations:translate-database
- topdata:machine-translations:translate-config-xml
Service Registration: src/Resources/config/services.xml
Documentation Folder: manual/
```

---

## 4) Scope, Assumptions, and Constraints

### In Scope
- Add new command for snippet JSON translation.
- Support translating one plugin or all plugins.
- Support one source language and one/multiple target languages.
- Preserve JSON key hierarchy and non-string values.
- Add CLI safety controls and progress logging.
- Update user documentation.

### Out of Scope
- Translating storefront/theme templates.
- Auto-detecting best source locale without explicit option.
- Continuous background translation jobs.
- Admin UI for triggering this command.

### Assumptions
- `DEEPL_FREE_API_KEY` is available in environment.
- Snippet files follow Shopware naming conventions (e.g., `de-DE.json`, `en-GB.json`).
- Command is executed in an environment with access to plugin directories.

### Constraints
- Keep compatibility with current plugin coding style.
- Avoid breaking existing commands.
- Avoid introducing heavy dependencies for JSON traversal.

---

## 5) Target CLI Design

### New Command
`topdata:machine-translations:translate-snippets-json`

### Proposed Options
- `--plugin=<TechnicalPluginName>` (repeatable or comma-separated, depending on implementation choice)
- `--all-plugins` (process all plugins in root)
- `--plugins-root=<path>` (default: `/www/custom/plugins`)
- `--from=<locale>` (required; e.g., `de-DE`)
- `--to=<locale>` (required, repeatable)
- `--force` (overwrite existing translated values)
- `--dry-run` (show changes only)
- `--no-backup` (skip backup of target files)
- `--skip-empty` (do not send empty strings to translator)

### Behavioral Rules
- Exactly one of `--plugin` or `--all-plugins` is required.
- If target file does not exist, create it.
- If target key exists and `--force` is not set, keep existing value.
- On `--dry-run`, no files are written and no backup created.

---

## 6) SOLID-Aligned Architecture

### Single Responsibility Principle
- Command handles input/output and orchestration only.
- Discovery service locates plugin snippet files.
- Translation service handles recursive key translation decisions.
- Persistence service writes JSON, backup, and formatting.

### Open/Closed Principle
- Translation strategy can be extended (e.g., glossary, exclusions) without changing command contract.

### Liskov Substitution Principle
- Services expose small interfaces; alternate implementations (mock/test adapters) remain substitutable.

### Interface Segregation Principle
- Keep APIs minimal (discovery, translation, writing separated).

### Dependency Inversion Principle
- Command depends on abstractions/services rather than concrete file scanning logic.

---

## 7) Multi-Phase Implementation Plan

### Phase 1 — Command Contract and Wiring

**Goals**
- Introduce new command class.
- Register service and command tag.
- Add robust option validation and guardrails.

**Files**

[NEW FILE] `src/Command/Command_TranslateSnippetsJson.php`
```php
<?php

namespace Topdata\TopdataMachineTranslationsSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataMachineTranslationsSW6\Service\SnippetPluginDiscovery;
use Topdata\TopdataMachineTranslationsSW6\Service\SnippetTranslationProcessor;

#[AsCommand(
    name: 'topdata:machine-translations:translate-snippets-json',
    description: 'Translate snippet JSON files for one or more plugins using DeepL'
)]
class Command_TranslateSnippetsJson extends AbstractTopdataCommand
{
    public function __construct(
        private readonly SnippetPluginDiscovery $discovery,
        private readonly SnippetTranslationProcessor $processor
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('plugin', 'p', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Plugin technical name(s)')
            ->addOption('all-plugins', null, InputOption::VALUE_NONE, 'Process all plugins in plugins root')
            ->addOption('plugins-root', null, InputOption::VALUE_REQUIRED, 'Plugins root directory', '/www/custom/plugins')
            ->addOption('from', 'f', InputOption::VALUE_REQUIRED, 'Source locale, e.g. de-DE')
            ->addOption('to', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Target locale(s), e.g. en-GB')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing target values')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview changes without writing files')
            ->addOption('no-backup', null, InputOption::VALUE_NONE, 'Do not create backup files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Validate options, resolve plugin set, call processor, print summary.
        return Command::SUCCESS;
    }
}
```

[MODIFY] `src/Resources/config/services.xml`
```xml
<service id="Topdata\TopdataMachineTranslationsSW6\Command\Command_TranslateSnippetsJson" autowire="true">
    <tag name="console.command"/>
</service>

<service id="Topdata\TopdataMachineTranslationsSW6\Service\SnippetPluginDiscovery" autowire="true"/>
<service id="Topdata\TopdataMachineTranslationsSW6\Service\SnippetTranslationProcessor" autowire="true"/>
<service id="Topdata\TopdataMachineTranslationsSW6\Service\SnippetJsonPersister" autowire="true"/>
```

**Acceptance Criteria**
- Command appears in `bin/console list topdata:machine-translations`.
- Invalid option combinations fail fast with clear error messages.

---

### Phase 2 — Snippet Discovery and File Selection

**Goals**
- Discover snippet directories for selected plugins.
- Resolve source and target snippet file paths.
- Filter out plugins/files with missing prerequisites.

**Files**

[NEW FILE] `src/Service/SnippetPluginDiscovery.php`
```php
<?php

namespace Topdata\TopdataMachineTranslationsSW6\Service;

final class SnippetPluginDiscovery
{
    public function resolvePluginPaths(string $pluginsRoot, array $pluginNames, bool $allPlugins): array
    {
        // Return plugin directories to process.
        return [];
    }

    public function resolveSnippetFiles(string $pluginPath, string $fromLocale, array $targetLocales): array
    {
        // Return source file + target files metadata for one plugin.
        return [];
    }
}
```

**Design Notes**
- Expected snippet folder: `<plugin>/src/Resources/snippet/`.
- Source file expected: `<from>.json`.
- Target files generated/updated as `<to>.json`.

**Acceptance Criteria**
- Discovery works for one plugin and all plugins.
- Missing source files are logged and skipped, not fatal for batch mode.

---

### Phase 3 — Recursive Translation Engine for JSON

**Goals**
- Translate nested JSON string values recursively.
- Preserve key hierarchy and non-string values.
- Respect force/non-force overwrite behavior.

**Files**

[NEW FILE] `src/Service/SnippetTranslationProcessor.php`
```php
<?php

namespace Topdata\TopdataMachineTranslationsSW6\Service;

use Topdata\TopdataMachineTranslationsSW6\Helper\DeeplTranslator;

final class SnippetTranslationProcessor
{
    public function __construct(
        private readonly DeeplTranslator $translator,
        private readonly SnippetJsonPersister $persister
    ) {}

    public function processPluginSnippets(array $snippetMeta, string $fromLocale, array $targetLocales, bool $force, bool $dryRun, bool $backup): array
    {
        // Iterate locales, merge source->target recursively, persist, return stats.
        return [];
    }

    private function translateNode(mixed $sourceNode, mixed $targetNode, string $sourceLang, string $targetLang, bool $force): mixed
    {
        // Recursive algorithm for arrays/maps/strings.
        return $targetNode;
    }
}
```

**Algorithm Rules**
1. If source node is object/array: recursively process children by key.
2. If source node is string:
   - if target empty/missing => translate;
   - if target exists and `--force` => re-translate;
   - else keep existing target.
3. If source node is numeric/bool/null: copy as-is when target missing.
4. Track counters per plugin/locale: scanned, translated, skipped, errors.

**Acceptance Criteria**
- Nested snippet structures are preserved.
- Existing non-forced translations remain unchanged.

---

### Phase 4 — JSON Persistence, Backup, and Error Handling

**Goals**
- Standardize JSON read/write with safe behavior.
- Backup target files unless disabled.
- Improve resilience for malformed input.

**Files**

[NEW FILE] `src/Service/SnippetJsonPersister.php`
```php
<?php

namespace Topdata\TopdataMachineTranslationsSW6\Service;

final class SnippetJsonPersister
{
    public function readJsonFile(string $path): array
    {
        // Decode JSON; throw domain exception on invalid JSON.
        return [];
    }

    public function backupFile(string $path): ?string
    {
        // Create timestamped backup if file exists.
        return null;
    }

    public function writeJsonFile(string $path, array $payload): void
    {
        // Save pretty-printed UTF-8 JSON.
    }
}
```

**Implementation Notes**
- Use `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`.
- Ensure deterministic key order (if feasible and safe).
- Use atomic write pattern (`tmp` + rename) to reduce corruption risk.

**Acceptance Criteria**
- Invalid JSON is clearly reported with plugin + locale context.
- Writes are skipped in `--dry-run`.

---

### Phase 5 — CLI Reporting, Validation, and UX

**Goals**
- Provide clear command summary and progress output.
- Add predictable exit codes.
- Ensure user can understand skipped/error scenarios quickly.

**Files**

[MODIFY] `src/Command/Command_TranslateSnippetsJson.php`
```php
// Add final CLI table, per-plugin stats, and exit status logic.
// Exit FAILURE when all plugin jobs fail; SUCCESS when at least one succeeds.
```

**Validation Matrix**
- No `DEEPL_FREE_API_KEY` => failure.
- No plugin selected and no `--all-plugins` => failure.
- Both selected simultaneously (if disallowed) => failure.
- Source equals target locale => skip with warning.
- Source file missing => warn + continue in batch.

**Acceptance Criteria**
- Operator gets concise per-plugin and per-locale status.
- Return code behavior is documented and consistent.

---

### Phase 6 — Tests and Verification

**Goals**
- Add focused tests for new service logic (if test harness exists).
- Provide CLI manual validation when automated tests are limited.

**Planned Checks**
1. Single plugin translation from `de-DE` to `en-GB`.
2. Multi-target translation in one run.
3. Existing translations untouched without `--force`.
4. Overwrite behavior with `--force`.
5. `--dry-run` produces no file changes.
6. Missing source file in batch mode does not stop other plugins.

**Example Verification Commands**
```bash
bin/console topdata:machine-translations:translate-snippets-json --plugin=SwagBasicExample --from=de-DE --to=en-GB
bin/console topdata:machine-translations:translate-snippets-json --all-plugins --from=de-DE --to=en-GB --to=cs-CZ --dry-run
bin/console topdata:machine-translations:translate-snippets-json --plugin=MyPlugin --from=de-DE --to=en-GB --force
```

---

### Phase 7 — Documentation Updates

**Goals**
- Document new command usage and options.
- Keep README and manual index aligned.

**Files**

[NEW FILE] `manual/36-snippet-translation.en.md`
```markdown
# Snippet JSON Translation Command

Describe purpose, prerequisites, usage, options, and examples for:
`topdata:machine-translations:translate-snippets-json`
```

[NEW FILE] `manual/36-snippet-translation.de.md`
```markdown
# Snippet-JSON Übersetzungsbefehl

Deutsche Dokumentation analog zur englischen Version.
```

[MODIFY] `README.md`
```markdown
- Add new feature bullet: snippet JSON translation
- Add link to new manual page
- Add quick-start command example
```

**Acceptance Criteria**
- User can run the new command using docs only.
- README and manual links are consistent.

---

### Phase 8 — Implementation Report Generation (Final Phase)

After implementation, generate:

`_ai/backlog/reports/260409_1402__IMPLEMENTATION_REPORT__translate-plugin-snippets-json.md`

with this frontmatter:

```yaml
---
filename: "_ai/backlog/reports/260409_1402__IMPLEMENTATION_REPORT__translate-plugin-snippets-json.md"
title: "Report: Add CLI Command to Translate Plugin Snippet JSON Files"
createdAt: 2026-04-09 14:02
updatedAt: 2026-04-09 14:02
planFile: "_ai/backlog/active/260409_1402__IMPLEMENTATION_PLAN__translate-plugin-snippets-json.md"
project: "topdata-machine-translations-sw6"
status: completed
filesCreated: 0
filesModified: 0
filesDeleted: 0
tags: [shopware6, cli, deepl, snippets, i18n]
documentType: IMPLEMENTATION_REPORT
---
```

Report content must include:
1. Summary (2-3 sentences)
2. Files Changed
3. Key Changes
4. Technical Decisions
5. Testing Notes
6. Usage Examples
7. Documentation Updates
8. Next Steps (optional)

---

## 8) Risks and Mitigations

- **DeepL quota/rate limits**: add retry/backoff and clear error output.
- **Malformed snippet JSON**: fail current plugin/locale safely, continue batch.
- **Unexpected snippet schema**: recursive algorithm handles mixed scalar/object structures.
- **Large plugin sets**: provide progress and optional plugin filtering.

---

## 9) Rollout Strategy

1. Implement command + services behind default safe behavior (`--force` off).
2. Run dry-run in staging across selected plugins.
3. Execute real run for one plugin first.
4. Expand to all plugins after output validation.

---

## 10) Definition of Done

- New command is available and functional for single/all plugin modes.
- Snippet JSON translation writes correct locale files.
- Overwrite/backup/dry-run behavior works as documented.
- README + manual pages are updated.
- Implementation report file is produced in `_ai/backlog/reports/`.
