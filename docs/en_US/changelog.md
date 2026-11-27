---
layout: default
lang: en_US
---

# Audit Santé changelog

# 26/11/2026 - 0.1.1

- Missing references: a number glued to text or starting with zero (hardware identifier such as `0B11002F#ID#03020#slider#00` in a dimmer's commands) is no longer mistaken for a deleted command.
- “Exclude” button extended to disabled plugins, silent, orphan or timed-out devices, empty objects and empty views and designs (sensor that rarely changes, seasonal device, device read by the API, room prepared in advance...).
- Scenarios and devices disabled on purpose: “Exclude” button on six checks (scenarios that can never run, that no longer run, that use disabled items, triggered by a disabled device, forgotten disabled scenarios, disabled devices). The item stays listed separately, no longer counts in the score, and can be restored in one click.
- Devices and commands linked to deleted items: location of the reference (action before or after execution, action on value, linked value...), direct opening of the command's advanced configuration, and a button that removes the orphan action through Jeedom.
- Info command: an action before or after execution, which Jeedom does not display on this type of command, is reported as hidden, with what to do (save the command without changing anything, or use the “Delete the orphan info value” button).
- Ghost devices: automatic fix that deletes the devices of plugins that are no longer installed, along with their commands and history. Devices of disabled plugins are not touched.
- Scenarios using deleted items: each reference shows its location (trigger, condition or action of a block, and "in its options" when it is in one of the action's fields).
- Triggers of a scenario in Scheduled mode, which Jeedom keeps in the database but hides and does not use, are no longer checked.
- A number greater than the last identifier assigned by Jeedom (Telegram chat ID, phone number...) is no longer reported as a missing reference.
- Plugin leftovers: setting names are shown, and configurations saved under a file path or with no plugin name ("false", "null") are explained.

# 24/11/2026 - 0.1.0

- First version: 42 checks in 7 categories (broken references, scenarios, devices, commands and history, objects/views/designs, plugins and tasks, database and files).
- “Who uses what” index built in a single read of the database.
- Links to the Jeedom page of each reported item.
- Name archive: hourly snapshot of the name of each item, backups (and additional folders) read only once for older deletions, deletion period. Background reading with progress tracking, started at installation, before the first audit (which the user starts).
- Automatic fixes limited to items that nothing can use any more.
- Plugin data stored in JSON files (`data/`), independent of the Jeedom database.
- Score, grade, checks ignored for a set time, comments, diagnostic report, device usable in scenarios.
- Interface and illustrated documentation in French, English, German, Spanish and Italian.
- Backup upload from the computer (.tar.gz, .tgz, .tar or .zip), without giving access to a folder.
