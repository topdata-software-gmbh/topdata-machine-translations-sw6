---
title: Configuration
---

## Environment Variables

### `DEEPL_FREE_API_KEY`
- **Required**: Yes
- **Description**: DeepL API authentication key
- **Format**: String
- **Example**: `export DEEPL_FREE_API_KEY=your-key-here`

## Command Options

### `--table`
- **Type**: Multiple values allowed
- **Description**: Specific tables to translate
- **Example**: `--table=product_translation --table=category_translation`

### `--from`
- **Default**: `de-DE`
- **Description**: Source language (4-letter code)
- **Supported Formats**: `xx-XX` or `xx`

### `--to` 
- **Default**: `cs-CZ`
- **Description**: Target language (4-letter code)
- **Note**: Actual code converted to 2-letter format for DeepL API

### `--no-backup`
- **Type**: Flag
- **Description**: Disable automatic backups
- **Risk**: Not recommended for production use

## Backup Configuration
- **Location**: `/tmp/database-backups`
- **Retention**: Manual cleanup required
- **Format**: SQL dump per table with timestamp

## Translation Settings
- **Excluded Columns**:
  - Fields ending with `_id` or `_config`
  - `created_at`/`updated_at` timestamps
- **Batch Size**: Individual row processing
- **Error Handling**: Skips failed translations, continues with next column