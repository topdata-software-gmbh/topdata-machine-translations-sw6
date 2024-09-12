# Topdata Machine Translations Plugin for Shopware 6

This plugin provides machine translation capabilities for Shopware 6, allowing automatic translation of content from German to Czech using the DeepL API.

## Features

- Translates content from German to Czech
- Can translate all relevant tables or a specific table
- Uses DeepL API for high-quality translations
- Provides a command-line interface for easy translation management

## Requirements

- Shopware 6
- PHP 7.4 or higher
- DeepL API key (Free or Pro)

## Installation

1. Clone this repository into the `custom/plugins` directory of your Shopware 6 installation:
   ```
   git clone https://github.com/your-repo/TopdataMachineTranslationsSW6.git custom/plugins/TopdataMachineTranslationsSW6
   ```

2. Install the plugin via the Shopware CLI:
   ```
   bin/console plugin:refresh
   bin/console plugin:install --activate TopdataMachineTranslationsSW6
   ```

3. Install the required dependencies:
   ```
   composer require deeplcom/deepl-php
   ```

4. Clear the cache:
   ```
   bin/console cache:clear
   ```

## Configuration

1. Set your DeepL API key as an environment variable:
   ```
   export DEEPL_FREE_API_KEY=your-api-key-here
   ```
   For production use, consider adding this to your server's environment configuration.

## Usage

To translate all relevant tables:
```
bin/console topdata:translate
```

To translate a specific table:
```
bin/console topdata:translate --table=your_table_name
```

## Support

For support, please open an issue in the GitHub repository or contact Topdata support.

## License

This plugin is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.
