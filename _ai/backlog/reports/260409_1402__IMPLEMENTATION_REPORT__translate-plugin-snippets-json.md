---
filename: "_ai/backlog/reports/260409_1402__IMPLEMENTATION_REPORT__translate-plugin-snippets-json.md"
title: "Report: Add CLI Command to Translate Plugin Snippet JSON Files"
createdAt: 2026-04-09 14:02
updatedAt: 2026-04-09 14:02
planFile: "_ai/backlog/active/260409_1402__IMPLEMENTATION_PLAN__translate-plugin-snippets-json.md"
project: "topdata-machine-translations-sw6"
status: completed
filesCreated: 7
filesModified: 2
filesDeleted: 0
tags: [shopware6, cli, deepl, snippets, i18n]
documentType: IMPLEMENTATION_REPORT
---

## 1. Summary

Implemented a new CLI command, `topdata:machine-translations:translate-snippets-json`, to translate plugin snippet JSON files for one plugin or all plugins. The implementation includes dedicated services for plugin/snippet discovery, recursive translation processing, and JSON persistence with backup and atomic writes. Documentation was added in English and German, and service wiring was updated for command registration.

## 2. Files Changed

### Created
- `src/Command/Command_TranslateSnippetsJson.php`
- `src/Service/SnippetPluginDiscovery.php`
- `src/Service/SnippetTranslationProcessor.php`
- `src/Service/SnippetJsonPersister.php`
- `manual/36-snippet-translation.en.md`
- `manual/36-snippet-translation.de.md`
- `_ai/backlog/reports/260409_1402__IMPLEMENTATION_REPORT__translate-plugin-snippets-json.md`

### Modified
- `src/Resources/config/services.xml`
- `README.md`

## 3. Key Changes

- Added command options and validation for:
  - `--plugin` vs `--all-plugins` execution mode
  - `--plugins-root`, `--from`, `--to`
  - `--force`, `--dry-run`, `--no-backup`, `--skip-empty`
- Implemented plugin and snippet-file discovery based on Shopware plugin conventions.
- Implemented recursive translation logic preserving JSON hierarchy and non-string values.
- Added per-locale counters (`scanned`, `translated`, `skipped`, `errors`) and CLI summary output.
- Added JSON persister with:
  - strict JSON decoding
  - optional timestamped backup
  - atomic write (`tmp` + `rename`)
- Updated docs and README with usage and examples.

## 4. Technical Decisions

- Kept translation service independent from container-provided API key injection; command validates `DEEPL_FREE_API_KEY` and instantiates `DeeplTranslator` explicitly.
- Preserved existing target values by default and only overwrote when `--force` is enabled.
- Treated missing snippet directory/source file as non-fatal at plugin level to support robust batch processing.
- Converted locales to DeepL-compatible language codes in processor:
  - source locale to 2-letter uppercase
  - target locale supports explicit variants (`EN-GB`, `EN-US`, `PT-BR`, `PT-PT`) and otherwise falls back to 2-letter uppercase.

## 5. Testing Notes

Executed PHP syntax checks for all new PHP files:

```bash
php -l src/Command/Command_TranslateSnippetsJson.php
php -l src/Service/SnippetPluginDiscovery.php
php -l src/Service/SnippetTranslationProcessor.php
php -l src/Service/SnippetJsonPersister.php
```

All checks returned: `No syntax errors detected`.

## 6. Usage Examples

```bash
bin/console topdata:machine-translations:translate-snippets-json --plugin=SwagBasicExample --from=de-DE --to=en-GB
bin/console topdata:machine-translations:translate-snippets-json --all-plugins --from=de-DE --to=en-GB --to=cs-CZ --dry-run
bin/console topdata:machine-translations:translate-snippets-json --plugin=MyPlugin --from=de-DE --to=en-GB --force
```

## 7. Documentation Updates

- Added English manual page: `manual/36-snippet-translation.en.md`
- Added German manual page: `manual/36-snippet-translation.de.md`
- Updated `README.md`:
  - feature list includes snippet JSON translation
  - documentation link to new manual page
  - quick-start command example for snippets

## 8. Next Steps

- Run command-level validation in a Shopware runtime with real plugin snippet files and a valid DeepL API key.
- Optionally add integration/functional tests if a test harness for console commands is introduced.
