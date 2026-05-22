# Snippet-JSON Übersetzungsbefehl

Der Befehl `topdata:machine-translations:translate-snippets-json` übersetzt Shopware-Plugin-Snippet-JSON-Dateien (`src/Resources/snippet/*.json`) mit DeepL. Er unterstützt die Verarbeitung eines einzelnen Plugins oder aller Plugins in einem Plugin-Root-Verzeichnis.

## Zweck

Mit diesem Befehl können Locale-Dateien aus einer Quell-Locale erstellt oder aktualisiert werden, wobei die Snippet-Struktur und bestehende Übersetzungen (ohne `--force`) erhalten bleiben.

## Voraussetzungen

- DeepL API-Schlüssel ist als `DEEPL_FREE_API_KEY` gesetzt
- Quell-Snippet-Datei existiert, z.B. `de-DE.json`
- Plugin-Snippet-Pfad folgt `src/Resources/snippet/`

## Verwendung

```bash
bin/console topdata:machine-translations:translate-snippets-json [--plugin=<name> ...|--all-plugins] --from=<quell-locale> --to=<ziel-locale> [--to=<ziel-locale> ...] [optionen]
```

## Optionen

| Option | Kurzform | Erforderlich | Beschreibung |
|--------|----------|--------------|--------------|
| `--plugin` | `-p` | Ja* | Technischer Plugin-Name. Wiederholbar, kommagetrennte Werte werden unterstützt |
| `--all-plugins` | - | Ja* | Alle Plugin-Verzeichnisse in `--plugins-root` verarbeiten |
| `--plugins-root` | - | Nein | Root-Verzeichnis mit Plugins (Standard: `/www/custom/plugins`) |
| `--from` | `-f` | Ja | Quell-Locale-Datei ohne Endung, z.B. `de-DE` |
| `--to` | `-t` | Ja | Ziel-Locale(s), mehrfach möglich |
| `--force` | - | Nein | Bestehende, nicht-leere Zielübersetzungen überschreiben |
| `--dry-run` | - | Nein | Verarbeitung nur anzeigen, keine Dateien schreiben |
| `--no-backup` | - | Nein | Sicherung für bestehende Zieldateien überspringen |
| `--skip-empty` | - | Nein | Leere Quell-Strings nicht übersetzen |

`*` Genau ein Auswahlmodus ist erforderlich: `--plugin` oder `--all-plugins`.

## Beispiele

### Ein Plugin übersetzen

```bash
bin/console topdata:machine-translations:translate-snippets-json --plugin=SwagBasicExample --from=de-DE --to=en-GB
```

### Ein Plugin in mehrere Locales übersetzen

```bash
bin/console topdata:machine-translations:translate-snippets-json --plugin=SwagBasicExample --from=de-DE --to=en-GB --to=cs-CZ
```

### Dry-Run über alle Plugins

```bash
bin/console topdata:machine-translations:translate-snippets-json --all-plugins --from=de-DE --to=en-GB --dry-run
```

### Bestehende Werte überschreiben

```bash
bin/console topdata:machine-translations:translate-snippets-json --plugin=MyPlugin --from=de-DE --to=en-GB --force
```

## Verhaltensdetails

- Quelldatei: `<plugin>/src/Resources/snippet/<from>.json`
- Zieldateien: `<plugin>/src/Resources/snippet/<to>.json`
- Sowohl das Bindestrich-Format (`de-DE`) als auch das Unterstrich-Format (`de_DE`) werden für die Parameter `--from` und `--to` unterstützt. Der Befehl mappt und konvertiert diese passend zu den Dateinamen und Ordnerstrukturen.
- Bestehende, nicht-leere Zielwerte bleiben standardmäßig erhalten
- Verschachtelte JSON-Key-Struktur wird rekursiv beibehalten
- Für bestehende Zieldateien werden Sicherungen erstellt, außer bei `--no-backup`
- Fehlt die Quell-Snippet-Datei, wird das Plugin übersprungen und in der Zusammenfassung angezeigt

## Exit-Verhalten

- `SUCCESS`, wenn mindestens ein Plugin erfolgreich verarbeitet wurde
- `FAILURE`, wenn alle Plugin-Jobs fehlschlagen oder übersprungen werden
