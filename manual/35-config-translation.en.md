# Configuration XML Translation Command

The `topdata:machine-translations:translate-config-xml` command translates configuration XML files using DeepL translation service. This command is designed to automatically translate specific text elements in Shopware 6 configuration XML files to multiple target languages.

## Purpose

This command processes Shopware 6 configuration XML files and translates translatable text elements to specified target languages. It supports translating:
- `<title>` elements
- `<label>` elements  
- `<helpText>` elements
- `<resetOption>` elements

## Prerequisites

Before using this command, ensure:
- DeepL API key is configured as `DEEPL_FREE_API_KEY` environment variable
- The XML file exists and is valid
- Source and target language codes are properly formatted (e.g., `de-DE`, `cs-CZ`, `en-GB`)

## Usage

```bash
bin/console topdata:machine-translations:translate-config-xml <file> --from=<source> --to=<target1> [--to=<target2> ...] [options]
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `file` | Yes | Path to the XML file to translate |

## Options

| Option | Short | Required | Description |
|--------|-------|----------|-------------|
| `--from` | `-f` | Yes | Source language code (e.g., `de-DE`) |
| `--to` | `-t` | Yes | Target language code(s). Can be specified multiple times (e.g., `--to=cs-CZ --to=sk-SK`) |
| `--force` | - | No | Force translation even if translation already exists for target language |
| `--no-backup` | - | No | Do not create a backup of the original file |

## Examples

### Basic Translation
Translate a German configuration file to Czech:
```bash
bin/console topdata:machine-translations:translate-config-xml config.xml --from=de-DE --to=cs-CZ
```

### Multiple Target Languages
Translate to multiple languages at once:
```bash
bin/console topdata:machine-translations:translate-config-xml config.xml --from=de-DE --to=cs-CZ --to=sk-SK --to=en-GB
```

### Force Overwrite Existing Translations
Overwrite existing translations for specified languages:
```bash
bin/console topdata:machine-translations:translate-config-xml config.xml --from=de-DE --to=cs-CZ --force
```

### Skip Backup Creation
Translate without creating a backup file:
```bash
bin/console topdata:machine-translations:translate-config-xml config.xml --from=de-DE --to=cs-CZ --no-backup
```

## Behavior Details

### Backup Creation
By default, the command creates a backup of the original file before making changes. The backup file follows the pattern:
```
<filename>.backup.YYYY-MM-DD_HH-MM-SS
```

### Translation Logic
1. **Source Text Detection**: The command first looks for child `<text>` elements with the specified source language (`--from`). If none found, it uses the parent element's text content.
2. **Existing Translation Check**: Before translating, it checks if a translation already exists for the target language. Existing translations are skipped unless `--force` is used.
3. **Translation Storage**: Translations are added as new `<text lang="target-code">` child elements within the parent element.

### Error Handling
The command will fail with appropriate error messages for:
- Missing DEEPL_FREE_API_KEY environment variable
- Non-existent XML file
- Invalid XML format
- Failed backup creation
- Failed file save operations

### Output
The command provides detailed progress information:
- Number of translatable nodes found
- Processing status for each target language
- Number of translations added per language
- Backup file location (when created)
- Success/failure status

## XML Structure Requirements

The command expects XML files with the following structure for translatable elements:

```xml
<config>
    <title>
        <text lang="de-DE">German Title</text>
    </title>
    <label>
        <text lang="de-DE">German Label</text>
    </label>
    <helpText>
        <text lang="de-DE">German help text</text>
    </helpText>
</config>
```

After translation, the structure will include additional `<text>` elements:
```xml
<config>
    <title>
        <text lang="de-DE">German Title</text>
        <text lang="cs-CZ">Czech Title</text>
    </title>
</config>