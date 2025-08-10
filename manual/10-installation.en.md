---
title: Installation
---

## System Requirements
- Shopware 6.4+
- PHP 8.0+
- MySQL/MariaDB 10.3+
- 500MB free disk space for backups
- Network access to api-free.deepl.com

## Installation Steps

1. **Install Plugin**
```bash
bin/console plugin:refresh
bin/console plugin:install --activate TopdataMachineTranslationsSW6
```

2. **Configure DeepL API Key**

Permanent configuration (recommended):
```bash
# Add to your environment configuration (e.g., .env)
DEEPL_FREE_API_KEY=your-api-key-here
```

Temporary usage:
```bash
DEEPL_FREE_API_KEY=your-key-here bin/console topdata:translate [...]
```

3. **Verify Installation**
```bash
bin/console topdata:translate --list-languages
```

## Post-Installation
- Backup directory: `/tmp/database-backups` (configurable via plugin settings)
- First-run recommendation: Use `--table` option to test specific tables