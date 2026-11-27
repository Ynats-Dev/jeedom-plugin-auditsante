---
layout: default
lang: fr_FR
---

# Changelog Audit Santé

# 26/11/2026 - 0.1.1

- Bouton « Exclure » étendu aux plugins désactivés, aux équipements muets, orphelins ou en timeout, aux objets vides et aux vues et designs vides (capteur qui change rarement, appareil saisonnier, équipement lu par l'API, pièce préparée à l'avance...).
- Scénarios et équipements désactivés volontairement : bouton « Exclure » sur six contrôles (scénarios qui ne peuvent jamais se lancer, qui ne se lancent plus, qui utilisent des éléments désactivés, déclenchés par un équipement désactivé, scénarios désactivés oubliés, équipements désactivés). L'élément reste affiché à part, ne compte plus dans la note, et se réintègre d'un clic.
- Équipements et commandes liés à des éléments supprimés : emplacement de la référence (action avant ou après exécution, action sur valeur, valeur liée...), ouverture directe de la configuration avancée de la commande, et bouton qui retire l'action orpheline via Jeedom.
- Commande info : une action avant ou après exécution, que Jeedom n'affiche pas sur ce type de commande, est signalée comme masquée, avec la marche à suivre (sauvegarder la commande sans rien modifier, ou le bouton « Supprimer la valeur info orpheline »).
- Équipements fantômes : correction automatique qui supprime les équipements des plugins qui ne sont plus installés, avec leurs commandes et leur historique. Ceux des plugins désactivés ne sont pas touchés.
- Scénarios qui utilisent des éléments supprimés : chaque référence indique son emplacement (déclencheur, condition ou action d'un bloc, et « dans ses options » si elle est dans un champ de l'action).
- Les déclencheurs d'un scénario en mode Programmé, que Jeedom garde en base mais masque et n'utilise pas, ne sont plus contrôlés.
- Un numéro plus grand que le dernier identifiant attribué par Jeedom (identifiant de conversation Telegram, numéro de téléphone...) n'est plus signalé comme référence introuvable.
- Restes de plugins : noms des paramètres affichés, et configurations enregistrées sous un chemin de fichier ou sans nom de plugin (« false », « null ») expliquées.

# 24/11/2026 - 0.1.0

- Première version : 42 contrôles en 7 catégories (références cassées, scénarios, équipements, commandes et historique, objets/vues/designs, plugins et tâches, base de données et fichiers).
- Index « qui utilise quoi » construit en une seule lecture de la base.
- Liens vers la page Jeedom de chaque élément signalé.
- Archive des noms : relevé horaire du nom de chaque élément, lecture unique des sauvegardes (et de dossiers supplémentaires) pour les suppressions plus anciennes, période de suppression. Lecture en tâche de fond avec suivi de l'avancement, lancée dès l'installation, avant le premier audit (qui reste à lancer par l'utilisateur).
- Corrections automatiques limitées aux éléments que plus rien ne peut utiliser.
- Données du plugin en fichiers JSON (`data/`), indépendantes de la base Jeedom.
- Note, lettre, contrôles ignorés pour une durée, commentaires, rapport de diagnostic, équipement utilisable dans les scénarios.
- Interface et documentation illustrée en français, anglais, allemand, espagnol et italien.
- Envoi d'une sauvegarde depuis l'ordinateur (.tar.gz, .tgz, .tar ou .zip), sans donner accès à un dossier.
