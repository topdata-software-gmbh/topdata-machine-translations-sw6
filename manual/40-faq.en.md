---
title: FAQ
---

## General Questions

### Q: Where are backups stored?
Backups are created in `/tmp/database-backups` by default. This is temporary storage - move backups to persistent storage for production use.

### Q: How do I translate multiple tables?
Use multiple --table options:
```bash
bin/console topdata:translate --table=table1 --table=table2
```

## API Key Issues

### Q: "DEEPL_FREE_API_KEY is missing" error
1. Verify the key is set in your environment
2. For CLI usage, prepend commands with `DEEPL_FREE_API_KEY=your-key`
3. Ensure no typos in the key

### Q: Difference between Free and Pro API keys?
- Free keys: `api-free.deepl.com`
- Pro keys: `api.deepl.com`
Update the API URL in `DeeplTranslator.php` for Pro accounts

## Language Support

### Q: Which language codes are supported?
Use 2-letter codes (de, en) or 4-letter codes (de-DE, en-GB). The plugin automatically converts to 2-letter format for DeepL API.

### Q: Why are some columns not translated?
We skip columns:
- Ending with `_id` or `_config`
- Containing timestamps
- With existing translations

## Troubleshooting

### Q: "Backup file was not created" error
1. Verify mysqldump is installed
2. Check directory permissions for `/tmp`
3. Ensure sufficient disk space

### Q: Translations not appearing in Shopware
1. Clear Shopware cache
2. Verify language IDs exist in `language` table
3. Check translation table relationships