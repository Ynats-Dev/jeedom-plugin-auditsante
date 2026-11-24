---
layout: default
lang: en_US
---

# Audit Santé changelog

# 24/11/2026 - 0.1.0

- First version: 42 checks in 7 categories (broken references, scenarios, devices, commands and history, objects/views/designs, plugins and tasks, database and files).
- “Who uses what” index built in a single read of the database.
- Links to the Jeedom page of each reported item.
- Name archive: hourly snapshot of the name of each item, backups (and additional folders) read only once for older deletions, deletion period. Background reading with progress tracking, started at installation, before the first audit (which the user starts).
- Automatic fixes limited to items that nothing can use any more.
- Plugin data stored in JSON files (`data/`), independent of the Jeedom database.
- Score, grade, checks ignored for a set time, comments, diagnostic report, device usable in scenarios.
- Interface and illustrated documentation in French, English, German, Spanish and Italian.
