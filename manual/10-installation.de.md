---
title: Installation
---

## Systemanforderungen
- Shopware 6.4+
- PHP 8.0+
- MySQL/MariaDB 10.3+
- 500 MB freier Festplattenspeicher für Backups
- Netzwerkzugriff auf api-free.deepl.com

## Installationsschritte

1. **Plugin installieren**
```bash
bin/console plugin:refresh
bin/console plugin:install --activate TopdataMachineTranslationsSW6
```

2. **DeepL API-Key konfigurieren**

Dauerhafte Konfiguration (empfohlen):
```bash
# Zur Umgebungskonfiguration hinzufügen (z.B. .env)
DEEPL_FREE_API_KEY=Ihr-API-Key-hier
```

Temporäre Verwendung:
```bash
DEEPL_FREE_API_KEY=Ihr-Key-hier bin/console topdata:translate [...]
```

3. **Installation überprüfen**
```bash
bin/console topdata:translate --list-languages
```

## Nach der Installation
- Backup-Verzeichnis: `/tmp/database-backups` (über Plugin-Einstellungen konfigurierbar)
- Empfehlung für Erstinstallation: `--table`-Option zum Testen einzelner Tabellen verwenden