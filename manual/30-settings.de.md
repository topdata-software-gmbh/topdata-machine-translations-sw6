---
title: Konfiguration
---

## Umgebungsvariablen

### `DEEPL_FREE_API_KEY`
- **Erforderlich**: Ja
- **Beschreibung**: DeepL API-Authentifizierungsschlüssel
- **Format**: Zeichenkette
- **Beispiel**: `export DEEPL_FREE_API_KEY=Ihr-Key-hier`

## Befehlsoptionen

### `--table`
- **Typ**: Mehrfachangaben möglich
- **Beschreibung**: Spezifische zu übersetzende Tabellen
- **Beispiel**: `--table=product_translation --table=category_translation`

### `--from`
- **Standard**: `de-DE`
- **Beschreibung**: Quellsprache (4-stelliger Code)
- **Unterstützte Formate**: `xx-XX` oder `xx`

### `--to`
- **Standard**: `cs-CZ`
- **Beschreibung**: Zielsprache (4-stelliger Code)
- **Hinweis**: Wird automatisch in 2-stelliges Format für DeepL API konvertiert

### `--no-backup`
- **Typ**: Flag
- **Beschreibung**: Deaktiviert automatische Backups
- **Risiko**: In Produktivumgebungen nicht empfohlen

## Backup-Einstellungen
- **Speicherort**: `/tmp/database-backups`
- **Aufbewahrung**: Manuelle Bereinigung erforderlich
- **Format**: SQL-Dump pro Tabelle mit Zeitstempel

## Übersetzungseinstellungen
- **Ausgenommene Spalten**:
  - Felder mit Endung `_id` oder `_config`
  - `created_at`/`updated_at` Zeitstempel
- **Batch-Größe**: Einzelne Zeilenverarbeitung
- **Fehlerbehandlung**: Überspringt fehlgeschlagene Übersetzungen, setzt mit nächster Spalte fort