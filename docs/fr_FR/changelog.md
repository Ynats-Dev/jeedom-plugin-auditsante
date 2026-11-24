---
layout: default
lang: fr_FR
---

# Changelog Audit Santé

# 24/11/2026 - 0.1.0

- Première version : 42 contrôles en 7 catégories (références cassées, scénarios, équipements, commandes et historique, objets/vues/designs, plugins et tâches, base de données et fichiers).
- Index « qui utilise quoi » construit en une seule lecture de la base.
- Liens vers la page Jeedom de chaque élément signalé.
- Archive des noms : relevé horaire du nom de chaque élément, lecture unique des sauvegardes (et de dossiers supplémentaires) pour les suppressions plus anciennes, période de suppression. Lecture en tâche de fond avec suivi de l'avancement, lancée dès l'installation, avant le premier audit (qui reste à lancer par l'utilisateur).
- Corrections automatiques limitées aux éléments que plus rien ne peut utiliser.
- Données du plugin en fichiers JSON (`data/`), indépendantes de la base Jeedom.
- Note, lettre, contrôles ignorés pour une durée, commentaires, rapport de diagnostic, équipement utilisable dans les scénarios.
- Interface et documentation illustrée en français, anglais, allemand, espagnol et italien.
