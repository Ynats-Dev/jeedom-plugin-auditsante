---
layout: default
lang: it_IT
---

# Changelog Audit Santé

# 24/11/2026 - 0.1.0

- Prima versione: 42 controlli in 7 categorie (riferimenti interrotti, scenari, dispositivi, comandi e cronologia, oggetti/viste/design, plugin e attività, database e file).
- Indice «chi usa cosa» creato con una sola lettura del database.
- Link alla pagina Jeedom di ogni elemento segnalato.
- Archivio dei nomi: rilevazione oraria del nome di ogni elemento, lettura unica dei backup (e di cartelle aggiuntive) per le eliminazioni più vecchie, periodo di eliminazione. Lettura in background con avanzamento, avviata all'installazione, prima del primo audit (che viene avviato dall'utente).
- Correzioni automatiche limitate agli elementi che nulla può più usare.
- Dati del plugin in file JSON (`data/`), indipendenti dal database di Jeedom.
- Punteggio, lettera, controlli ignorati per una durata, commenti, rapporto di diagnostica, dispositivo utilizzabile negli scenari.
- Interfaccia e documentazione illustrata in francese, inglese, tedesco, spagnolo e italiano.
- Invio di un backup dal computer (.tar.gz, .tgz, .tar o .zip), senza dare accesso a una cartella.
