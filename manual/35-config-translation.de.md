# Konfigurations-XML Übersetzungsbefehl

Der Befehl `topdata:machine-translations:translate-config-xml` übersetzt Konfigurations-XML-Dateien mit dem DeepL-Übersetzungsdienst. Dieser Befehl wurde entwickelt, um bestimmte Textelemente in Shopware 6 Konfigurations-XML-Dateien automatisch in verschiedene Zielsprachen zu übersetzen.

## Zweck

Dieser Befehl verarbeitet Shopware 6 Konfigurations-XML-Dateien und übersetzt übersetzbare Textelemente in die angegebenen Zielsprachen. Er unterstützt die Übersetzung von:
- `<title>` Elementen
- `<label>` Elementen
- `<helpText>` Elementen
- `<resetOption>` Elementen

## Voraussetzungen

Bevor Sie diesen Befehl verwenden, stellen Sie sicher:
- DeepL API-Schlüssel ist als `DEEPL_FREE_API_KEY` Umgebungsvariable konfiguriert
- Die XML-Datei existiert und ist gültig
- Quell- und Zielsprachcodes sind korrekt formatiert (z.B. `de-DE`, `cs-CZ`, `en-GB`)

## Verwendung

```bash
bin/console topdata:machine-translations:translate-config-xml <datei> --from=<quelle> --to=<ziel1> [--to=<ziel2> ...] [optionen]
```

## Argumente

| Argument | Erforderlich | Beschreibung |
|----------|--------------|--------------|
| `file` | Ja | Pfad zur zu übersetzenden XML-Datei |

## Optionen

| Option | Kurzform | Erforderlich | Beschreibung |
|--------|----------|--------------|--------------|
| `--from` | `-f` | Ja | Quellsprachcode (z.B. `de-DE`) |
| `--to` | `-t` | Ja | Zielsprachcode(n). Kann mehrfach angegeben werden (z.B. `--to=cs-CZ --to=sk-SK`) |
| `--force` | - | Nein | Übersetzung erzwingen, auch wenn Übersetzung bereits existiert |
| `--no-backup` | - | Nein | Keine Sicherungskopie der Originaldatei erstellen |

## Beispiele

### Grundlegende Übersetzung
Eine deutsche Konfigurationsdatei ins Tschechische übersetzen:
```bash
bin/console topdata:machine-translations:translate-config-xml config.xml --from=de-DE --to=cs-CZ
```

### Mehrere Zielsprachen
Auf einmal in mehrere Sprachen übersetzen:
```bash
bin/console topdata:machine-translations:translate-config-xml config.xml --from=de-DE --to=cs-CZ --to=sk-SK --to=en-GB
```

### Bestehende Übersetzungen überschreiben
Bestehende Übersetzungen für angegebene Sprachen überschreiben:
```bash
bin/console topdata:machine-translations:translate-config-xml config.xml --from=de-DE --to=cs-CZ --force
```

### Ohne Sicherungskopie
Ohne Erstellung einer Sicherungsdatei übersetzen:
```bash
bin/console topdata:machine-translations:translate-config-xml config.xml --from=de-DE --to=cs-CZ --no-backup
```

## Verhaltensdetails

### Sicherungskopie-Erstellung
Standardmäßig erstellt der Befehl eine Sicherungskopie der Originaldatei vor Änderungen. Die Sicherungsdatei folgt dem Muster:
```
<dateiname>.backup.JJJJ-MM-TT_HH-MM-SS
```

### Übersetzungslogik
1. **Quelltext-Erkennung**: Der Befehl sucht zuerst nach untergeordneten `<text>` Elementen mit der angegebenen Quellsprache (`--from`). Falls keine gefunden werden, wird der Textinhalt des übergeordneten Elements verwendet.
2. **Bestehende Übersetzungsprüfung**: Vor der Übersetzung wird geprüft, ob bereits eine Übersetzung für die Zielsprache existiert. Bestehende Übersetzungen werden übersprungen.