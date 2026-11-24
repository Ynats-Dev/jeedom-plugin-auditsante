# Audit Santé — plugin Jeedom

Audit de santé d'une installation Jeedom : orphelins, références vers des éléments supprimés, éléments oubliés ou désactivés, historique et logs encombrants. Chaque problème est listé avec un lien vers la page Jeedom où le corriger, et l'ensemble donne une note de A à F.

- Identifiant du plugin : `auditsante`
- Jeedom requis : 4.4 minimum
- Documentation : [Français](docs/fr_FR/index.md) · [English](docs/en_US/index.md) · [Deutsch](docs/de_DE/index.md) · [Español](docs/es_ES/index.md) · [Italiano](docs/it_IT/index.md)

## Organisation du code

- `core/class/auditsanteIndex.class.php` : inventaire de l'installation (lu en SQL une fois par audit) et index « qui utilise quoi ».
- `core/class/auditsanteChecks.class.php` : catalogue des contrôles (`definitions()`), un `check_<id>()` par contrôle et les éventuels `fix_<id>()`.
- `core/class/auditsanteRecovery.class.php` : archive des noms (relevé horaire, lecture unique des sauvegardes).
- `core/class/auditsanteStore.class.php` : fichiers JSON de `data/` (verrou, écriture atomique).
- `core/class/auditsante.class.php` : lancement de l'audit, rapport, note, contrôles ignorés, commentaires, diagnostic, équipement et widget.
- `desktop/` : page du plugin (rapport interactif, sans jQuery).

Ajouter un contrôle : le décrire dans `definitions()` puis écrire `check_<id>()`, qui retourne `pass`, `warn`, `fail`, `info` ou `skip` avec un détail et une liste d'éléments `auditsanteTools::item($texte, $url)`.

## Installation pour le développement

Copier (ou lier) ce dossier dans `/var/www/html/plugins/auditsante` sur l'installation Jeedom.
