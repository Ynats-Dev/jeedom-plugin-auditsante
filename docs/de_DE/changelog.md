---
layout: default
lang: de_DE
---

# Changelog Audit Santé

# 26/11/2026 - 0.1.1

- Nicht gefundene Verweise: Eine Zahl, die direkt an Text grenzt oder mit einer Null beginnt (Hardwarekennung wie `0B11002F#ID#03020#slider#00` in den Befehlen eines Dimmers), wird nicht mehr für einen gelöschten Befehl gehalten.
- Schaltfläche „Ausschließen“ erweitert auf deaktivierte Plugins, stumme, verwaiste oder im Timeout befindliche Geräte, leere Objekte sowie leere Ansichten und Designs (selten wechselnder Sensor, saisonales Gerät, von der API gelesenes Gerät, im Voraus angelegter Raum...).
- Absichtlich deaktivierte Szenarien und Geräte: Schaltfläche „Ausschließen“ bei sechs Prüfungen (Szenarien, die nie starten können, nicht mehr starten, deaktivierte Elemente verwenden, von einem deaktivierten Gerät ausgelöst werden, vergessene deaktivierte Szenarien, deaktivierte Geräte). Das Element bleibt separat aufgeführt, zählt nicht mehr in der Bewertung und lässt sich mit einem Klick wieder aufnehmen.
- Geräte und Befehle, die mit gelöschten Elementen verknüpft sind: Stelle der Referenz (Aktion vor oder nach der Ausführung, Aktion bei Wert, verknüpfter Wert...), direktes Öffnen der erweiterten Befehlskonfiguration und eine Schaltfläche, die die verwaiste Aktion über Jeedom entfernt.
- Info-Befehl: Eine Aktion vor oder nach der Ausführung, die Jeedom bei diesem Befehlstyp nicht anzeigt, wird als ausgeblendet gemeldet, mit dem Vorgehen (Befehl ohne Änderung speichern oder Schaltfläche „Verwaisten Info-Wert löschen“).
- Geistergeräte: automatische Korrektur, die die Geräte nicht mehr installierter Plugins samt Befehlen und Verlauf löscht. Geräte deaktivierter Plugins bleiben unberührt.
- Szenarien, die gelöschte Elemente verwenden: Jeder Verweis zeigt seine Stelle an (Auslöser, Bedingung oder Aktion eines Blocks, und „in ihren Optionen“, wenn er in einem Feld der Aktion steht).
- Auslöser eines Szenarios im Modus Programmiert, die Jeedom in der Datenbank behält, aber ausblendet und nicht verwendet, werden nicht mehr geprüft.
- Eine Nummer, die größer ist als die letzte von Jeedom vergebene Kennung (Telegram-Chat-ID, Telefonnummer...), wird nicht mehr als nicht gefundener Verweis gemeldet.
- Plugin-Reste: Die Namen der Parameter werden angezeigt, und Konfigurationen unter einem Dateipfad oder ohne Plugin-Namen („false“, „null“) werden erklärt.

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
