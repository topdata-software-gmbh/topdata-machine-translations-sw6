# Snippet JSON Translation Command

The `topdata:machine-translations:translate-snippets-json` command translates Shopware plugin snippet JSON files (`src/Resources/snippet/*.json`) with DeepL. It supports one plugin or all plugins in a plugins root directory.

## Purpose

Use this command to generate or update locale snippet files from a source locale file while preserving snippet structure and existing translations (unless `--force` is used).

## Prerequisites

- DeepL API key is configured as `DEEPL_FREE_API_KEY`
- Source snippet file exists, e.g. `de-DE.json`
- Plugin snippet path follows `src/Resources/snippet/`

## Usage

```bash
bin/console topdata:machine-translations:translate-snippets-json [--plugin=<name> ...|--all-plugins] --from=<source-locale> --to=<target-locale> [--to=<target-locale> ...] [options]
```

## Options

| Option | Short | Required | Description |
|--------|-------|----------|-------------|
| `--plugin` | `-p` | Yes* | Plugin technical name. Repeatable and comma-separated values are supported |
| `--all-plugins` | - | Yes* | Process all plugin directories in `--plugins-root` |
| `--plugins-root` | - | No | Root directory containing plugins (default: `/www/custom/plugins`) |
| `--from` | `-f` | Yes | Source locale file name without extension, e.g. `de-DE` |
| `--to` | `-t` | Yes | Target locale(s), repeatable |
| `--force` | - | No | Overwrite existing non-empty target translations |
| `--dry-run` | - | No | Preview processing without writing files |
| `--no-backup` | - | No | Skip backup creation for existing target files |
| `--skip-empty` | - | No | Skip translating empty source strings |

`*` Exactly one selection mode is required: use either `--plugin` or `--all-plugins`.

## Examples

### Translate One Plugin

```bash
bin/console topdata:machine-translations:translate-snippets-json --plugin=SwagBasicExample --from=de-DE --to=en-GB
```

### Translate One Plugin to Multiple Locales

```bash
bin/console topdata:machine-translations:translate-snippets-json --plugin=SwagBasicExample --from=de-DE --to=en-GB --to=cs-CZ
```

### Dry-Run All Plugins

```bash
bin/console topdata:machine-translations:translate-snippets-json --all-plugins --from=de-DE --to=en-GB --dry-run
```

### Force Overwrite Existing Values

```bash
bin/console topdata:machine-translations:translate-snippets-json --plugin=MyPlugin --from=de-DE --to=en-GB --force
```

## Behavior Details

- Source file: `<plugin>/src/Resources/snippet/<from>.json`
- Target files: `<plugin>/src/Resources/snippet/<to>.json`
- Both dash (`de-DE`) and underscore (`de_DE`) formats are supported for the `--from` and `--to` parameters. The command maps and converts them matching the filenames and directory structures accordingly.
- Existing non-empty target values are kept by default
- Nested JSON key structure is preserved recursively
- Backups are created for existing target files unless `--no-backup` is set
- If source snippet file is missing, the plugin is skipped and listed in summary

## Exit Behavior

- Returns `SUCCESS` when at least one plugin was processed successfully
- Returns `FAILURE` when all plugin jobs fail or are skipped
