# Topdata Machine Translations Plugin for Shopware 6

[![License](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

Shopware 6 plugin providing automated machine translations using DeepL API. Designed for translating database content between languages while maintaining data integrity.

## Key Features

- 🚀 Automated translation of database content
- 🔐 DeepL API integration (Free and Pro plans supported)
- ⚙️ Configurable table selection (`--table` option)
- 💾 Automatic backup system before translation
- 🔄 Update existing translations or insert new ones
- 🌍 Supports all DeepL target languages
- 🧩 Translate plugin snippet JSON files (`src/Resources/snippet/*.json`)

## Documentation

For complete documentation see the [manual directory](manual/):
- [Installation Guide](manual/10-installation.en.md)
- [Configuration Reference](manual/30-settings.en.md)
- [Config XML Translation](manual/35-config-translation.en.md)
- [Snippet JSON Translation](manual/36-snippet-translation.en.md)
- [Frequently Asked Questions](manual/40-faq.en.md)

## Requirements

- Shopware 6.4+
- PHP 8.0+
- Valid [DeepL API Key](https://www.deepl.com/pro#developer)
- MySQL/MariaDB database

## Quick Start

1. Install plugin via CLI:
```bash
bin/console plugin:refresh
bin/console plugin:install --activate TopdataMachineTranslationsSW6
```

2. Set DeepL API key:
```bash
export DEEPL_FREE_API_KEY=your-api-key-here
```

3. Translate all supported tables:
```bash
bin/console topdata:translate --from=de-DE --to=cs-CZ
```

4. Translate snippet JSON files for one plugin:
```bash
bin/console topdata:machine-translations:translate-snippets-json --plugin=SwagBasicExample --from=de-DE --to=en-GB
```

## Support
Report issues through [GitHub Issues](https://github.com/your-repo/TopdataMachineTranslationsSW6/issues) or contact Topdata support.

## License
MIT License - See [LICENSE](LICENSE) for details.
