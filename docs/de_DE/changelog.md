---
layout: default
lang: de_DE
---

# Changelog Audit Santé

# 24/11/2026 - 0.1.0

- Erste Version: 42 Prüfungen in 7 Kategorien (defekte Verweise, Szenarien, Geräte, Befehle und Verlauf, Objekte/Ansichten/Designs, Plugins und Aufgaben, Datenbank und Dateien).
- Index „Wer verwendet was“, in einem einzigen Lesevorgang der Datenbank erstellt.
- Links zur Jeedom-Seite jedes gemeldeten Elements.
- Namensarchiv: stündliche Erfassung des Namens jedes Elements, einmaliges Lesen der Sicherungen (und zusätzlicher Ordner) für ältere Löschungen, Löschzeitraum. Lesen im Hintergrund mit Fortschrittsanzeige, gestartet bei der Installation, vor dem ersten Audit (das der Benutzer selbst startet).
- Automatische Korrekturen nur für Elemente, die nichts mehr verwenden kann.
- Plugin-Daten in JSON-Dateien (`data/`), unabhängig von der Jeedom-Datenbank.
- Bewertung, Note, für eine bestimmte Dauer ignorierte Prüfungen, Kommentare, Diagnosebericht, in Szenarien nutzbares Gerät.
- Oberfläche und bebilderte Dokumentation auf Französisch, Englisch, Deutsch, Spanisch und Italienisch.
- Hochladen einer Sicherung vom Computer (.tar.gz, .tgz, .tar oder .zip), ohne Zugriff auf einen Ordner zu geben.
