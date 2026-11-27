<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

require_once __DIR__ . '/auditsanteTools.class.php';
require_once __DIR__ . '/auditsanteIndex.class.php';
require_once __DIR__ . '/auditsanteRecovery.class.php';

/**
 * Catalogue des contrôles de santé.
 *
 * Chaque contrôle <id> est décrit dans definitions() et implémenté par check_<id>(), qui retourne
 * un résultat {status, detail, items} :
 *   - pass : rien à signaler (tous les points) ;
 *   - warn : à vérifier (la moitié des points) ;
 *   - fail : à corriger (aucun point) ;
 *   - info : information, hors note ;
 *   - skip : non vérifiable sur cette installation, hors note.
 * Les éléments (items) sont des {text, url} : l'url ouvre la page Jeedom concernée.
 *
 * Une correction automatique éventuelle est implémentée par fix_<id>() : elle retourne un message
 * de succès ou lève une exception. Elles ne suppriment que des données que plus rien n'utilise
 * (commandes sans équipement, historique de commandes supprimées...).
 */
class auditsanteChecks {

  /** Nombre maximal d'éléments gardés par contrôle (le détail donne toujours le total) */
  const MAX_ITEMS = 200;

  /** Taille au-delà de laquelle un log de scénario est signalé */
  const SCENARIO_LOG_MAX = 5242880;

  /* ************************************************************************************ */
  /*                                   Catalogue                                          */
  /* ************************************************************************************ */

  public static function categories() {
    return array(
      'broken' => array('name' => __('Références cassées', __FILE__), 'icon' => 'fas fa-unlink'),
      'scenarios' => array('name' => __('Scénarios', __FILE__), 'icon' => 'fas fa-cogs'),
      'equipments' => array('name' => __('Équipements', __FILE__), 'icon' => 'fas fa-microchip'),
      'commands' => array('name' => __('Commandes et historique', __FILE__), 'icon' => 'fas fa-terminal'),
      'interface' => array('name' => __('Objets, vues et designs', __FILE__), 'icon' => 'fas fa-object-group'),
      'plugins' => array('name' => __('Plugins et tâches', __FILE__), 'icon' => 'fas fa-puzzle-piece'),
      'data' => array('name' => __('Base de données et fichiers', __FILE__), 'icon' => 'fas fa-database'),
    );
  }

  public static function definitions() {
    $linkScenarios = array('type' => 'link', 'label' => __('Scénarios', __FILE__), 'url' => 'index.php?v=d&p=scenario');
    $linkPlugins = array('type' => 'link', 'label' => __('Gestion des plugins', __FILE__), 'url' => 'index.php?v=d&p=plugin');
    $linkHealth = array('type' => 'link', 'label' => __('Santé de Jeedom', __FILE__), 'url' => 'index.php?v=d&p=health');
    $linkEqAnalysis = array('type' => 'link', 'label' => __('Analyse des équipements', __FILE__), 'url' => 'index.php?v=d&p=eqAnalyse');
    $linkObjects = array('type' => 'link', 'label' => __('Objets', __FILE__), 'url' => 'index.php?v=d&p=object');
    $linkCron = array('type' => 'link', 'label' => __('Moteur de tâches', __FILE__), 'url' => 'index.php?v=d&p=cron');
    $linkLogs = array('type' => 'link', 'label' => __('Logs', __FILE__), 'url' => 'index.php?v=d&p=log');
    $linkPluginConfig = array('type' => 'link', 'label' => __('Seuils du plugin', __FILE__), 'url' => 'index.php?v=d&p=plugin&id=auditsante');

    return array(
      /* ------------------------- Références cassées ------------------------- */
      'broken_scenarios' => array(
        'category' => 'broken',
        'severity' => 'high',
        'title' => __('Scénarios qui utilisent des éléments supprimés', __FILE__),
        'description' => __('Quand une commande, un équipement ou un scénario est supprimé, les scénarios qui l\'utilisaient gardent une référence vers un numéro qui n\'existe plus (#1234#). Le bloc concerné échoue en silence ou s\'arrête en erreur.', __FILE__),
        'recommendation' => __('Ouvrez chaque scénario listé : les références cassées apparaissent sous forme de numéro (#1234#). Remplacez-les par la nouvelle commande ou supprimez le bloc.', __FILE__),
        'fix' => $linkScenarios,
      ),
      'broken_equipments' => array(
        'category' => 'broken',
        'severity' => 'high',
        'title' => __('Équipements et commandes liés à des éléments supprimés', __FILE__),
        'description' => __('Virtuels, actions sur valeur, pré et post exécution, commandes liées (état d\'un interrupteur...) : un équipement ou une commande qui pointe vers une commande supprimée ne fonctionne plus correctement.', __FILE__),
        'recommendation' => __('Cliquez sur l\'élément pour ouvrir l\'équipement ou la configuration avancée de la commande, à l\'emplacement indiqué, et remplacez la référence introuvable. Si elle ne sert plus, le bouton rouge « Supprimer… orpheline » retire l\'action qui l\'utilise (ou vide le paramètre) et enregistre via Jeedom.', __FILE__),
        'fix' => null,
      ),
      'broken_interface' => array(
        'category' => 'broken',
        'severity' => 'medium',
        'title' => __('Vues, designs et résumés liés à des éléments supprimés', __FILE__),
        'description' => __('Les vues, designs, designs 3D et résumés d\'objets gardent les éléments supprimés : ils s\'affichent vides ou faussent les résumés (température moyenne, nombre de lumières allumées...).', __FILE__),
        'recommendation' => __('Éditez la vue, le design ou l\'objet listé et retirez les éléments introuvables.', __FILE__),
        'fix' => null,
      ),
      'broken_interactions' => array(
        'category' => 'broken',
        'severity' => 'medium',
        'title' => __('Interactions liées à des éléments supprimés', __FILE__),
        'description' => __('Une interaction dont la réponse ou les actions utilisent une commande supprimée répond mal ou ne fait plus rien.', __FILE__),
        'recommendation' => __('Corrigez ou supprimez les interactions listées.', __FILE__),
        'fix' => array('type' => 'link', 'label' => __('Interactions', __FILE__), 'url' => 'index.php?v=d&p=interact'),
      ),
      'broken_config' => array(
        'category' => 'broken',
        'severity' => 'medium',
        'title' => __('Configuration et utilisateurs liés à des commandes supprimées', __FILE__),
        'description' => __('Commandes d\'alerte (timeout, batterie, danger...), commande de retour des interactions ou commande de notification d\'un utilisateur : si elle a été supprimée, Jeedom ne vous prévient plus.', __FILE__),
        'recommendation' => __('Choisissez une nouvelle commande dans Réglages > Système > Configuration (onglets Logs et Interactions) ou dans le profil de l\'utilisateur.', __FILE__),
        'fix' => array('type' => 'link', 'label' => __('Configuration', __FILE__), 'url' => 'index.php?v=d&p=administration'),
      ),

      /* ------------------------------ Scénarios ------------------------------ */
      'scenario_errors' => array(
        'category' => 'scenarios',
        'severity' => 'high',
        'title' => __('Scénarios en erreur', __FILE__),
        'description' => __('Le dernier lancement de ces scénarios s\'est terminé en erreur. Ils peuvent ne plus faire ce que vous attendez sans que vous le remarquiez.', __FILE__),
        'recommendation' => __('Ouvrez le log du scénario pour trouver le bloc en cause, corrigez-le puis relancez le scénario.', __FILE__),
        'fix' => $linkScenarios,
      ),
      'scenario_dead_triggers' => array(
        'category' => 'scenarios',
        'severity' => 'high',
        'title' => __('Scénarios déclenchés par un équipement désactivé', __FILE__),
        'description' => __('Un équipement désactivé ne remonte plus aucune valeur : les scénarios qu\'il déclenche ne se lancent plus. Jeedom ne le signale nulle part.', __FILE__),
        'recommendation' => __('Réactivez l\'équipement, remplacez le déclencheur par une commande d\'un équipement actif, ou désactivez le scénario s\'il n\'a plus d\'utilité. Si l\'équipement est désactivé volontairement (il se réactive selon certains événements), cliquez sur « Exclure ».', __FILE__),
        'fix' => $linkScenarios,
        // Éléments que l'utilisateur peut marquer comme voulus : affichés à part, plus comptés dans la note
        'exclude' => 'voluntary',
      ),
      'scenario_unreachable' => array(
        'category' => 'scenarios',
        'severity' => 'medium',
        'title' => __('Scénarios actifs qui ne peuvent jamais se lancer', __FILE__),
        'description' => __('Ces scénarios sont actifs mais n\'ont ni déclencheur ni programmation, ne sont appelés par aucun autre scénario, équipement, interaction, vue ou design, et ne sont pas affichés sur le dashboard. Ils ne se lancent donc jamais, sauf à la main ou par l\'API.', __FILE__),
        'recommendation' => __('Ajoutez le déclencheur ou la programmation manquante, ou supprimez le scénario s\'il ne sert plus. S\'il est lancé par une URL, l\'API ou un autre appareil, cliquez sur « Exclure » : il reste affiché mais ne compte plus dans la note.', __FILE__),
        'fix' => $linkScenarios,
        // Éléments que l'utilisateur peut marquer comme voulus : affichés à part, plus comptés dans la note
        'exclude' => 'voluntary',
      ),
      'scenario_disabled_refs' => array(
        'category' => 'scenarios',
        'severity' => 'medium',
        'title' => __('Scénarios qui utilisent des éléments désactivés', __FILE__),
        'description' => __('Ces scénarios actifs lisent ou pilotent la commande d\'un équipement désactivé, ou lancent un scénario désactivé : ces actions n\'ont aucun effet et les conditions lisent une valeur figée.', __FILE__),
        'recommendation' => __('Réactivez l\'élément concerné ou retirez-le du scénario. Si l\'élément est désactivé volontairement (il se réactive selon certains événements), cliquez sur « Exclure ».', __FILE__),
        'fix' => $linkScenarios,
        // Éléments que l'utilisateur peut marquer comme voulus : affichés à part, plus comptés dans la note
        'exclude' => 'voluntary',
      ),
      'scenario_stale' => array(
        'category' => 'scenarios',
        'severity' => 'low',
        'title' => __('Scénarios qui ne se lancent plus', __FILE__),
        'description' => __('Ces scénarios actifs ont un déclencheur ou une programmation, mais ne se sont pas lancés depuis longtemps (ou jamais). La condition de déclenchement n\'arrive peut-être plus : capteur remplacé, mode supprimé...', __FILE__),
        'recommendation' => __('Vérifiez le déclencheur de chaque scénario listé. S\'il ne sert plus, désactivez-le ou supprimez-le. Le délai se règle dans la configuration du plugin. Un scénario qui ne se lance que rarement par nature (alarme intrusion, détection de fuite...) : cliquez sur « Exclure ».', __FILE__),
        'fix' => $linkPluginConfig,
        // Éléments que l'utilisateur peut marquer comme voulus : affichés à part, plus comptés dans la note
        'exclude' => 'voluntary',
      ),
      'scenario_inactive' => array(
        'category' => 'scenarios',
        'severity' => 'low',
        'title' => __('Scénarios désactivés oubliés', __FILE__),
        'description' => __('Scénarios désactivés et qu\'aucun autre élément n\'active ni ne lance. Ils encombrent la liste et peuvent être réactivés par erreur.', __FILE__),
        'recommendation' => __('Supprimez ceux qui ne servent plus (exportez-les avant si vous voulez les garder). Ceux qui sont activés par un autre scénario ne sont pas listés. Ceux que vous gardez désactivés volontairement : cliquez sur « Exclure ».', __FILE__),
        'fix' => $linkScenarios,
        // Éléments que l'utilisateur peut marquer comme voulus : affichés à part, plus comptés dans la note
        'exclude' => 'voluntary',
      ),
      'scenario_empty' => array(
        'category' => 'scenarios',
        'severity' => 'low',
        'title' => __('Scénarios vides', __FILE__),
        'description' => __('Scénarios sans aucun bloc : ils se lancent pour rien ou ont été créés puis oubliés.', __FILE__),
        'recommendation' => __('Complétez-les ou supprimez-les.', __FILE__),
        'fix' => $linkScenarios,
      ),
      'scenario_logs' => array(
        'category' => 'scenarios',
        'severity' => 'low',
        'title' => __('Logs des scénarios', __FILE__),
        'description' => __('Un scénario en log temps réel ou très bavard peut produire un fichier de plusieurs dizaines de Mo. Les logs des scénarios supprimés restent aussi sur le disque.', __FILE__),
        'recommendation' => __('Repassez les scénarios concernés en log par défaut (onglet Général du scénario) et videz leur log. Les logs des scénarios supprimés peuvent être effacés automatiquement.', __FILE__),
        'fix' => array('type' => 'auto', 'label' => __('Supprimer les logs des scénarios supprimés', __FILE__), 'confirm' => __('Les fichiers log/scenarioLog/scenarioN.log dont le scénario n\'existe plus vont être supprimés. Les logs des scénarios existants ne sont pas touchés.', __FILE__)),
      ),
      'scenario_every_minute' => array(
        'category' => 'scenarios',
        'severity' => 'info',
        'title' => __('Scénarios programmés toutes les minutes', __FILE__),
        'description' => __('Un scénario programmé toutes les minutes se lance 1 440 fois par jour. Souvent, un déclencheur sur la commande surveillée fait la même chose sans charger la box.', __FILE__),
        'recommendation' => __('Remplacez la programmation par un déclencheur quand c\'est possible, ou espacez-la (toutes les 5 minutes...).', __FILE__),
        'fix' => $linkScenarios,
      ),

      /* ----------------------------- Équipements ----------------------------- */
      'eq_plugin_missing' => array(
        'category' => 'equipments',
        'severity' => 'high',
        'title' => __('Équipements fantômes (plugin supprimé ou désactivé)', __FILE__),
        'description' => __('Quand un plugin est supprimé ou désactivé, ses équipements restent dans la base : ils n\'apparaissent plus nulle part, mais les scénarios, vues et designs qui les utilisent ne fonctionnent plus.', __FILE__),
        'recommendation' => __('Réinstallez ou réactivez le plugin si vous en avez encore besoin. Sinon, remplacez ces équipements dans vos scénarios puis supprimez-les. Les équipements d\'un plugin qui n\'est plus installé peuvent être supprimés automatiquement ; pour un plugin désactivé, réactivez-le temporairement et supprimez-les depuis son interface.', __FILE__),
        'fix' => array('type' => 'auto', 'label' => __('Supprimer les équipements des plugins non installés', __FILE__), 'confirm' => __('Les équipements des plugins qui ne sont plus installés vont être supprimés définitivement, avec leurs commandes et leur historique. Ceux des plugins désactivés ne sont pas touchés. Les scénarios, vues et designs qui les utilisaient encore afficheront une référence introuvable, avec l\'ancien nom de l\'équipement.', __FILE__)),
      ),
      'eq_timeout' => array(
        'category' => 'equipments',
        'severity' => 'medium',
        'title' => __('Équipements en timeout', __FILE__),
        'description' => __('Ces équipements n\'ont pas communiqué dans le délai d\'alerte configuré : pile vide, module hors de portée, appareil débranché...', __FILE__),
        'recommendation' => __('Vérifiez l\'alimentation et la portée de l\'appareil. S\'il a été retiré de la maison, supprimez son équipement. S\'il est débranché volontairement (appareil saisonnier : piscine, chauffage...), cliquez sur « Exclure ».', __FILE__),
        'fix' => $linkEqAnalysis,
        // Éléments que l'utilisateur peut marquer comme voulus : affichés à part, plus comptés dans la note
        'exclude' => 'voluntary',
      ),
      'eq_battery' => array(
        'category' => 'equipments',
        'severity' => 'medium',
        'title' => __('Piles faibles', __FILE__),
        'description' => __('Le niveau de pile de ces équipements est sous le seuil d\'alerte (Réglages > Système > Configuration > Équipements, ou seuil propre à l\'équipement).', __FILE__),
        'recommendation' => __('Remplacez les piles avant que les modules ne décrochent.', __FILE__),
        'fix' => array('type' => 'link', 'label' => __('Analyse des batteries', __FILE__), 'url' => 'index.php?v=d&p=eqAnalyse'),
      ),
      'eq_silent' => array(
        'category' => 'equipments',
        'severity' => 'low',
        'title' => __('Équipements muets', __FILE__),
        'description' => __('Équipements actifs dont aucune commande info n\'a reçu de valeur depuis longtemps, et sans délai d\'alerte (timeout) configuré : Jeedom ne les signale donc jamais. Ce sont souvent des modules morts ou retirés de la maison.', __FILE__),
        'recommendation' => __('Vérifiez chaque équipement. S\'il fonctionne, configurez un délai d\'alerte dans sa configuration avancée ; sinon, désactivez-le ou supprimez-le. Le délai se règle dans la configuration du plugin. Un capteur qui change rarement par nature (porte de cave, détecteur de fuite, bouton d\'urgence...) : cliquez sur « Exclure ».', __FILE__),
        'fix' => $linkPluginConfig,
        // Éléments que l'utilisateur peut marquer comme voulus : affichés à part, plus comptés dans la note
        'exclude' => 'voluntary',
      ),
      'eq_unused' => array(
        'category' => 'equipments',
        'severity' => 'low',
        'title' => __('Équipements orphelins', __FILE__),
        'description' => __('Équipements actifs mais masqués, dont aucune commande n\'est historisée ni utilisée ailleurs (scénario, autre équipement, interaction, vue, design, résumé). Ils ne servent visiblement à rien. Les commandes utilisées seulement dans un bloc code d\'un scénario, par leur nom, ne sont pas détectées.', __FILE__),
        'recommendation' => __('Supprimez ou désactivez les équipements dont vous n\'avez plus besoin. S\'il est lu par un programme externe, l\'API ou un autre Jeedom, cliquez sur « Exclure ».', __FILE__),
        'fix' => null,
        // Éléments que l'utilisateur peut marquer comme voulus : affichés à part, plus comptés dans la note
        'exclude' => 'voluntary',
      ),
      'eq_disabled' => array(
        'category' => 'equipments',
        'severity' => 'low',
        'title' => __('Équipements désactivés', __FILE__),
        'description' => __('Un équipement désactivé ne remonte plus rien mais garde ses commandes, son historique et ses liens. Souvent, il correspond à un appareil qui n\'existe plus.', __FILE__),
        'recommendation' => __('Supprimez les équipements qui ne reviendront pas. Ceux qui sont encore utilisés par des scénarios sont signalés dans « Scénarios qui utilisent des éléments désactivés ». Ceux que vous gardez désactivés volontairement : cliquez sur « Exclure ».', __FILE__),
        'fix' => null,
        // Éléments que l'utilisateur peut marquer comme voulus : affichés à part, plus comptés dans la note
        'exclude' => 'voluntary',
      ),
      'eq_no_object' => array(
        'category' => 'equipments',
        'severity' => 'low',
        'title' => __('Équipements visibles sans objet parent', __FILE__),
        'description' => __('Un équipement sans objet parent n\'apparaît sur aucun dashboard, même s\'il est coché « Visible ».', __FILE__),
        'recommendation' => __('Rangez ces équipements dans un objet (pièce), ou décochez « Visible » s\'ils n\'ont pas à être affichés.', __FILE__),
        'fix' => null,
      ),
      'eq_no_cmd' => array(
        'category' => 'equipments',
        'severity' => 'low',
        'title' => __('Équipements sans commande', __FILE__),
        'description' => __('Équipements qui n\'ont aucune commande : création inachevée, inclusion ratée ou reste d\'un ancien appareil.', __FILE__),
        'recommendation' => __('Terminez leur configuration (souvent : sauvegarder l\'équipement ou relancer la synchronisation du plugin) ou supprimez-les.', __FILE__),
        'fix' => null,
      ),

      /* ----------------------- Commandes et historique ----------------------- */
      'cmd_orphan' => array(
        'category' => 'commands',
        'severity' => 'medium',
        'title' => __('Commandes sans équipement', __FILE__),
        'description' => __('Commandes dont l\'équipement n\'existe plus. Invisibles dans l\'interface, elles restent dans la base avec leur historique.', __FILE__),
        'recommendation' => __('Supprimez-les : elles ne peuvent plus servir.', __FILE__),
        'fix' => array('type' => 'auto', 'label' => __('Supprimer les commandes sans équipement', __FILE__), 'confirm' => __('Les commandes listées et leur historique vont être supprimés définitivement. Elles n\'ont plus d\'équipement et ne sont plus visibles nulle part.', __FILE__)),
      ),
      'history_orphan' => array(
        'category' => 'commands',
        'severity' => 'medium',
        'title' => __('Historique de commandes supprimées', __FILE__),
        'description' => __('Valeurs historisées de commandes qui n\'existent plus. Elles occupent de la place dans la base et ralentissent les sauvegardes, sans être consultables.', __FILE__),
        'recommendation' => __('Supprimez cet historique.', __FILE__),
        'fix' => array('type' => 'auto', 'label' => __('Supprimer l\'historique orphelin', __FILE__), 'confirm' => __('Les valeurs historisées des commandes qui n\'existent plus vont être supprimées définitivement (tables history et historyArch). L\'historique des commandes existantes n\'est pas touché.', __FILE__)),
      ),
      'history_volume' => array(
        'category' => 'commands',
        'severity' => 'info',
        'title' => __('Volume de l\'historique', __FILE__),
        'description' => __('Les commandes historisées sans purge, ou qui enregistrent une valeur toutes les secondes, font grossir la base, allongent les sauvegardes et ralentissent les graphiques.', __FILE__),
        'recommendation' => __('Pour les commandes les plus lourdes, réglez une purge (configuration avancée de la commande > Historique) et un lissage. Retirez l\'historisation des commandes d\'équipements désactivés.', __FILE__),
        'fix' => null,
      ),
      'cmd_unused' => array(
        'category' => 'commands',
        'severity' => 'info',
        'title' => __('Commandes inutilisées', __FILE__),
        'description' => __('Commandes masquées, non historisées et utilisées nulle part (scénario, équipement, interaction, vue, design, résumé). Elles ne gênent pas, mais signalent souvent des équipements à faire le ménage.', __FILE__),
        'recommendation' => __('Rien d\'obligatoire. Si un plugin crée beaucoup de commandes inutiles, regardez s\'il permet de ne pas les créer.', __FILE__),
        'fix' => null,
      ),
      'cmd_generic_type' => array(
        'category' => 'commands',
        'severity' => 'info',
        'title' => __('Commandes sans type générique', __FILE__),
        'description' => __('Le type générique indique à l\'application mobile, aux assistants vocaux et à certains plugins ce que fait une commande (lumière, prise, température...). Sans lui, la commande n\'y apparaît pas ou mal.', __FILE__),
        'recommendation' => __('Renseignez les types génériques des commandes affichées, en masse depuis Outils > Types génériques.', __FILE__),
        'fix' => array('type' => 'link', 'label' => __('Types génériques', __FILE__), 'url' => 'index.php?v=d&p=types'),
      ),

      /* ----------------------- Objets, vues et designs ----------------------- */
      'object_empty' => array(
        'category' => 'interface',
        'severity' => 'low',
        'title' => __('Objets vides', __FILE__),
        'description' => __('Objets (pièces) sans équipement, sans objet enfant et sans résumé : ils encombrent le dashboard et les listes de choix.', __FILE__),
        'recommendation' => __('Supprimez-les ou rangez-y les équipements concernés. Un objet préparé à l\'avance ou qui ne sert qu\'à regrouper : cliquez sur « Exclure ».', __FILE__),
        'fix' => $linkObjects,
        // Éléments que l'utilisateur peut marquer comme voulus : affichés à part, plus comptés dans la note
        'exclude' => 'voluntary',
      ),
      'view_empty' => array(
        'category' => 'interface',
        'severity' => 'low',
        'title' => __('Vues et designs vides', __FILE__),
        'description' => __('Vues sans aucun élément et designs sans aucun élément posé : création inachevée ou reste d\'une ancienne organisation.', __FILE__),
        'recommendation' => __('Complétez-les ou supprimez-les. Une vue ou un design préparé à l\'avance : cliquez sur « Exclure ».', __FILE__),
        'fix' => null,
        // Éléments que l'utilisateur peut marquer comme voulus : affichés à part, plus comptés dans la note
        'exclude' => 'voluntary',
      ),
      'widget_unused' => array(
        'category' => 'interface',
        'severity' => 'low',
        'title' => __('Widgets personnalisés inutilisés', __FILE__),
        'description' => __('Widgets créés dans Outils > Widgets qu\'aucune commande n\'utilise.', __FILE__),
        'recommendation' => __('Supprimez ceux qui ne servent plus.', __FILE__),
        'fix' => array('type' => 'link', 'label' => __('Widgets', __FILE__), 'url' => 'index.php?v=d&p=widgets'),
      ),

      /* -------------------------- Plugins et tâches -------------------------- */
      'plugin_daemons' => array(
        'category' => 'plugins',
        'severity' => 'high',
        'title' => __('Démons et dépendances en échec', __FILE__),
        'description' => __('Un plugin dont le démon est arrêté ou dont les dépendances ne sont pas installées ne communique plus avec ses équipements.', __FILE__),
        'recommendation' => __('Ouvrez la configuration du plugin, relancez l\'installation des dépendances puis le démon, et consultez son log en cas d\'échec.', __FILE__),
        'fix' => $linkHealth,
      ),
      'cron_errors' => array(
        'category' => 'plugins',
        'severity' => 'medium',
        'title' => __('Tâches planifiées en erreur', __FILE__),
        'description' => __('Les tâches du moteur de tâches (crons du core et des plugins) en erreur ne s\'exécutent plus correctement.', __FILE__),
        'recommendation' => __('Consultez le log du plugin concerné, puis relancez la tâche depuis le moteur de tâches.', __FILE__),
        'fix' => $linkCron,
      ),
      'cron_orphan' => array(
        'category' => 'plugins',
        'severity' => 'medium',
        'title' => __('Tâches et écouteurs orphelins', __FILE__),
        'description' => __('Tâches planifiées et écouteurs (listeners) qui appellent une classe ou une fonction qui n\'existe plus, en général celle d\'un plugin supprimé. Ils tournent dans le vide et remplissent les logs d\'erreurs.', __FILE__),
        'recommendation' => __('Supprimez-les.', __FILE__),
        'fix' => array('type' => 'auto', 'label' => __('Supprimer les tâches et écouteurs orphelins', __FILE__), 'confirm' => __('Les tâches planifiées et écouteurs listés vont être supprimés. Ils appellent du code qui n\'existe plus.', __FILE__)),
      ),
      'plugin_disabled' => array(
        'category' => 'plugins',
        'severity' => 'low',
        'title' => __('Plugins désactivés', __FILE__),
        'description' => __('Un plugin désactivé reste installé : il occupe de la place, apparaît dans le centre de mises à jour et ses équipements deviennent des fantômes.', __FILE__),
        'recommendation' => __('Supprimez les plugins dont vous ne vous servez plus, après avoir supprimé leurs équipements. Ceux que vous gardez désactivés volontairement (plugin saisonnier, en attente de réutilisation...) : cliquez sur « Exclure ».', __FILE__),
        'fix' => $linkPlugins,
        // Éléments que l'utilisateur peut marquer comme voulus : affichés à part, plus comptés dans la note
        'exclude' => 'voluntary',
      ),
      'plugin_no_eq' => array(
        'category' => 'plugins',
        'severity' => 'info',
        'title' => __('Plugins actifs sans équipement', __FILE__),
        'description' => __('Plugins activés qui n\'ont aucun équipement. Certains plugins fonctionnent sans équipement (outils, thèmes, passerelles) ; les autres sont peut-être inutiles.', __FILE__),
        'recommendation' => __('Désactivez puis supprimez ceux qui ne servent plus.', __FILE__),
        'fix' => $linkPlugins,
      ),
      'plugin_leftovers' => array(
        'category' => 'plugins',
        'severity' => 'low',
        'title' => __('Restes de plugins supprimés', __FILE__),
        'description' => __('Configuration et entrées du centre de mises à jour de plugins qui ne sont plus installés. Elles ne gênent pas le fonctionnement mais réapparaissent si le plugin est réinstallé.', __FILE__),
        'recommendation' => __('Rien d\'urgent. Si vous ne réinstallerez pas ces plugins, ces restes peuvent être nettoyés par le support ou en base de données.', __FILE__),
        'fix' => null,
      ),

      /* -------------------- Base de données et fichiers ---------------------- */
      'variables_unused' => array(
        'category' => 'data',
        'severity' => 'low',
        'title' => __('Variables inutilisées', __FILE__),
        'description' => __('Variables de scénario qu\'aucun scénario (y compris les blocs code), équipement, interaction, vue ou design ne lit ni n\'écrit. Ce sont souvent des restes de scénarios supprimés. Celles utilisées seulement par un programme externe (API) ou un plugin qui garde sa propre configuration apparaissent aussi.', __FILE__),
        'recommendation' => __('Supprimez-les depuis Outils > Variables (bouton « Supprimer » ou action remove_variable). Si une variable sert sans que l\'audit le voie (programme externe, nom construit autrement), excluez-la de l\'audit avec le bouton à droite de son nom.', __FILE__),
        'fix' => array('type' => 'link', 'label' => __('Variables', __FILE__), 'url' => 'index.php?v=d&modal=dataStore.management&type=scenario'),
        // Éléments que l'utilisateur peut exclure un par un (data/excluded.json)
        'exclude' => true,
      ),
      'datastore_orphan' => array(
        'category' => 'data',
        'severity' => 'low',
        'title' => __('Variables d\'éléments supprimés', __FILE__),
        'description' => __('Variables rattachées à un scénario ou un équipement qui n\'existe plus.', __FILE__),
        'recommendation' => __('Supprimez-les.', __FILE__),
        'fix' => array('type' => 'auto', 'label' => __('Supprimer ces variables', __FILE__), 'confirm' => __('Les variables listées vont être supprimées. Elles appartiennent à des scénarios ou équipements supprimés.', __FILE__)),
      ),
      'scenario_db_orphans' => array(
        'category' => 'data',
        'severity' => 'low',
        'title' => __('Blocs de scénarios orphelins', __FILE__),
        'description' => __('Blocs, sous-blocs et expressions de scénarios qui ne sont plus rattachés à aucun scénario (restes de suppressions ou de copies interrompues). Ils occupent la base sans servir.', __FILE__),
        'recommendation' => __('Lancez le nettoyage de la base de Jeedom (Réglages > Système > Configuration > onglet OS/DB > Nettoyage) après une sauvegarde.', __FILE__),
        'fix' => null,
      ),
      'db_size' => array(
        'category' => 'data',
        'severity' => 'info',
        'title' => __('Taille de la base de données', __FILE__),
        'description' => __('Les tables les plus volumineuses de la base Jeedom. L\'historique en représente presque toujours l\'essentiel.', __FILE__),
        'recommendation' => __('Si la base dépasse quelques centaines de Mo, réduisez la durée de conservation de l\'historique (Réglages > Système > Configuration > Commandes) et la purge des commandes les plus bavardes.', __FILE__),
        'fix' => null,
      ),
      'log_size' => array(
        'category' => 'data',
        'severity' => 'low',
        'title' => __('Fichiers de log volumineux', __FILE__),
        'description' => __('Un log volumineux signale souvent un plugin resté en Debug ou une erreur qui se répète. Il use la carte SD et remplit le disque.', __FILE__),
        'recommendation' => __('Repassez les logs concernés au niveau par défaut, corrigez l\'erreur répétée et videz le fichier. Le seuil se règle dans la configuration du plugin.', __FILE__),
        'fix' => $linkLogs,
      ),
      'messages' => array(
        'category' => 'data',
        'severity' => 'low',
        'title' => __('Centre de messages', __FILE__),
        'description' => __('Des dizaines de messages non traités noient les alertes importantes : on ne les lit plus.', __FILE__),
        'recommendation' => __('Traitez la cause des messages répétés (plugin, équipement), puis videz le centre de messages.', __FILE__),
        'fix' => array('type' => 'link', 'label' => __('Centre de messages', __FILE__), 'url' => 'index.php?v=d&p=message'),
      ),
    );
  }

  /* ************************************************************************************ */
  /*                                   Exécution                                          */
  /* ************************************************************************************ */

  /** Lance un contrôle et retourne son résultat brut {status, detail, items}. */
  public static function run($_id) {
    $method = 'check_' . $_id;
    if (!method_exists(__CLASS__, $method)) {
      return self::skip(__('Contrôle inconnu', __FILE__));
    }
    try {
      $result = self::$method();
    } catch (Throwable $e) {
      log::add('auditsante', 'error', 'Contrôle ' . $_id . ' : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
      return self::skip(__('Erreur pendant le contrôle :', __FILE__) . ' ' . $e->getMessage());
    }
    return $result;
  }

  /** Vrai si le contrôle dispose d'une correction automatique. */
  public static function hasAutoFix($_id) {
    return method_exists(__CLASS__, 'fix_' . $_id);
  }

  /** Applique la correction automatique d'un contrôle. Retourne un message. */
  public static function fix($_id) {
    $method = 'fix_' . $_id;
    if (!method_exists(__CLASS__, $method)) {
      throw new Exception(__('Aucune correction automatique pour ce contrôle', __FILE__));
    }
    auditsanteIndex::reset();
    log::add('auditsante', 'info', 'Correction demandée : ' . $_id);
    $message = self::$method();
    log::add('auditsante', 'info', 'Correction ' . $_id . ' : ' . $message);
    auditsanteIndex::reset();
    return $message;
  }

  /** Résultat brut. Les éléments au-delà de MAX_ITEMS sont résumés en une ligne. */
  private static function result($_status, $_detail, $_items = array(), $_extra = array()) {
    $items = array_values($_items);
    if (count($items) > self::MAX_ITEMS) {
      $more = count($items) - self::MAX_ITEMS;
      $items = array_slice($items, 0, self::MAX_ITEMS);
      $items[] = auditsanteTools::item(sprintf(__('... et %d autre(s)', __FILE__), $more));
    }
    return array_merge(array('status' => $_status, 'detail' => $_detail, 'items' => $items), $_extra);
  }

  private static function pass($_detail, $_items = array()) {
    return self::result('pass', $_detail, $_items);
  }

  private static function warn($_detail, $_items = array()) {
    return self::result('warn', $_detail, $_items);
  }

  private static function fail($_detail, $_items = array()) {
    return self::result('fail', $_detail, $_items);
  }

  private static function info($_detail, $_items = array()) {
    return self::result('info', $_detail, $_items);
  }

  private static function skip($_detail) {
    return self::result('skip', $_detail);
  }

  /**
   * Ajoute au résultat le nombre d'éléments supprimés sans nom : jamais cherchés (unnamed) et déjà cherchés
   * sans succès dans les sauvegardes (notFound). $_refs : {"type:id" => [type, id]}.
   */
  private static function withUnnamed($_result, $_refs) {
    $unnamed = 0;
    $notFound = 0;
    foreach ($_refs as $ref) {
      if (auditsanteRecovery::lookup($ref[0], $ref[1]) !== null) {
        continue;
      }
      if (auditsanteRecovery::wasSearched($ref[0], $ref[1])) {
        $notFound++;
      } else {
        $unnamed++;
      }
    }
    $_result['unnamed'] = $unnamed;
    $_result['notFound'] = $notFound;
    return $_result;
  }

  /** Résultat sans correction automatique possible, même si le contrôle en propose une. */
  private static function noFix($_result) {
    $_result['noFix'] = true;
    return $_result;
  }

  /* ************************************************************************************ */
  /*                                   Utilitaires                                        */
  /* ************************************************************************************ */

  private static function item($_text, $_url = null) {
    return auditsanteTools::item($_text, $_url);
  }

  /** Seuil réglable dans la configuration du plugin (entier positif, valeur par défaut sinon). */
  private static function setting($_key, $_default) {
    $value = (int) config::byKey($_key, 'auditsante', $_default);
    return ($value > 0) ? $value : $_default;
  }

  private static function query($_sql, $_values = array()) {
    $rows = DB::Prepare($_sql, $_values, DB::FETCH_TYPE_ALL);
    return is_array($rows) ? $rows : array();
  }

  /** Libellé d'une référence introuvable : « commande #123# introuvable (anciennement [Salon][Lampe][Etat]...) ». */
  private static function deadLabel($_type, $_id) {
    switch ($_type) {
      case 'cmd':
        $label = sprintf(__('commande #%d# introuvable', __FILE__), $_id);
        break;
      case 'eqLogic':
        $label = sprintf(__('équipement #eqLogic%d# introuvable', __FILE__), $_id);
        break;
      case 'scenario':
        $label = sprintf(__('scénario #scenario%d# introuvable', __FILE__), $_id);
        break;
      default:
        return $_type . ' ' . $_id;
    }
    return $label . self::recovered($_type, $_id);
  }

  /**
   * « (anciennement [Salon][Lampe], supprimé entre le ... et le ...) » si le nom a été retrouvé,
   * « (nom absent de l'archive des noms) » si toutes les sauvegardes ont été lues sans le trouver, sinon rien.
   */
  private static function recovered($_type, $_id) {
    $text = auditsanteRecovery::describe($_type, $_id);
    if ($text === '' && auditsanteRecovery::wasSearched($_type, $_id)) {
      $text = __('nom absent de l\'archive des noms', __FILE__);
    }
    return ($text === '') ? '' : ' (' . $text . ')';
  }

  /** Nom d'un consommateur : « Scénario [Groupe][Nom] » (la configuration porte déjà son type dans son nom). */
  private static function consumerName($_consumer) {
    return ($_consumer['kind'] == 'config') ? $_consumer['label'] : auditsanteIndex::kindName($_consumer['kind']) . ' ' . $_consumer['label'];
  }

  /** « dernier lancement le 12/03/2026 » ou « jamais lancé ». */
  private static function lastLaunch($_scenario) {
    $date = $_scenario->getLastLaunch();
    return (auditsanteTools::daysSince($date) === null) ? __('jamais lancé', __FILE__) : sprintf(__('dernier lancement le %s', __FILE__), auditsanteTools::formatDay($date));
  }

  /** Résumé des utilisateurs d'un élément : « Scénario [a], Vue [b] (+2) ». */
  private static function usersSummary($_consumers, $_max = 3) {
    $labels = array();
    foreach ($_consumers as $consumer) {
      $labels[] = self::consumerName($consumer);
    }
    $more = count($labels) - $_max;
    return implode(', ', array_slice($labels, 0, $_max)) . ($more > 0 ? ' (+' . $more . ')' : '');
  }

  /**
   * Contrôle commun des références cassées pour certains types de consommateurs.
   * $_failIfActive : une référence cassée dans un élément actif est « à corriger », sinon « à vérifier ».
   */
  private static function brokenFor($_kinds, $_failIfActive = true) {
    $consumers = auditsanteIndex::consumers();
    $items = array();
    $owners = array();
    $refs = array();
    $active = false;
    foreach (auditsanteIndex::deadRefs() as $dead) {
      $consumer = $consumers[$dead['consumer']];
      if (!in_array($consumer['kind'], $_kinds)) {
        continue;
      }
      $owners[$dead['consumer']] = true;
      $active = $active || $consumer['active'];
      $refs[$dead['type'] . ':' . $dead['id']] = array($dead['type'], $dead['id']);
      $place = '';
      $url = $consumer['url'];
      $repairable = false;
      if ($consumer['kind'] == 'scenario') {
        $place = auditsanteIndex::scenarioRefPlace($consumer['id'], $dead['type'], $dead['id']);
      } elseif (in_array($consumer['kind'], self::REPAIR_KINDS)) {
        $place = auditsanteIndex::configRefPlace($consumer['kind'], $consumer['id'], $dead['type'], $dead['id']);
        // Configuration avancée de la commande : c'est là que se trouvent les actions avant/après exécution
        if ($consumer['kind'] == 'cmd') {
          $url = 'index.php?v=d&modal=cmd.configure&cmd_id=' . $consumer['id'];
        }
        $repairable = self::repairDeadRef($consumer['kind'], $consumer['id'], $dead['type'], $dead['id'], true) > 0;
      }
      $item = self::item(self::consumerName($consumer) . ($consumer['active'] ? '' : ' (' . __('désactivé', __FILE__) . ')')
        . ' : ' . self::deadLabel($dead['type'], $dead['id'])
        . ($place === '' ? '' : ' - ' . sprintf(__('emplacement : %s', __FILE__), $place)), $url);
      if ($repairable) {
        $item['repair'] = $dead['consumer'] . '|' . $dead['type'] . '|' . $dead['id'];
        $cmds = auditsanteIndex::cmds();
        $isInfo = ($consumer['kind'] == 'cmd') && isset($cmds[$consumer['id']]) && $cmds[$consumer['id']]['type'] == 'info';
        $item['repairLabel'] = $isInfo ? __('Supprimer la valeur info orpheline', __FILE__) : __('Supprimer la référence orpheline', __FILE__);
      }
      $items[] = $item;
    }
    if (count($items) == 0) {
      return self::pass(__('Aucune référence cassée.', __FILE__));
    }
    $detail = sprintf(__('%d référence(s) introuvable(s) dans %d élément(s).', __FILE__), count($items), count($owners));
    return self::withUnnamed(($active && $_failIfActive) ? self::fail($detail, $items) : self::warn($detail, $items), $refs);
  }

  /** Consommateurs dont une référence introuvable peut être retirée automatiquement. */
  const REPAIR_KINDS = array('cmd', 'eqLogic');

  /**
   * Retire une référence introuvable de la configuration d'une commande ou d'un équipement :
   * - dans une liste d'actions (avant/après exécution, actions sur valeur...), l'action qui l'utilise est supprimée ;
   * - un paramètre qui ne contient que cette référence est vidé (de même pour la valeur liée d'une commande).
   * Une référence prise dans une formule plus longue n'est pas touchée : elle reste à corriger à la main.
   * $_dryRun : compte seulement ce qui serait retiré. Retourne le nombre d'éléments retirés.
   */
  public static function repairDeadRef($_kind, $_id, $_type, $_refId, $_dryRun = false) {
    if (!in_array($_kind, self::REPAIR_KINDS) || !in_array($_type, array('cmd', 'eqLogic', 'scenario'))) {
      return 0;
    }
    if ($_dryRun) {
      $index = ($_kind == 'cmd') ? auditsanteIndex::cmds() : auditsanteIndex::eqLogics();
      if (!isset($index[$_id])) {
        return 0;
      }
      $configuration = json_decode((string) $index[$_id]['configuration'], true);
      $value = ($_kind == 'cmd') ? (string) $index[$_id]['value'] : '';
    } else {
      $object = ($_kind == 'cmd') ? cmd::byId($_id) : eqLogic::byId($_id);
      if (!is_object($object)) {
        throw new Exception(__('Élément introuvable', __FILE__) . ' : ' . $_kind . ' ' . $_id);
      }
      $configuration = $object->getConfiguration();
      $value = ($_kind == 'cmd') ? (string) $object->getValue() : '';
    }
    $removed = 0;
    $changed = array();
    if (is_array($configuration)) {
      foreach ($configuration as $key => $param) {
        $count = 0;
        $clean = self::stripRef($param, $_type, $_refId, $count);
        if ($count > 0) {
          $removed += $count;
          $changed[$key] = $clean;
        }
      }
    }
    $clearValue = ($_kind == 'cmd') && (($_type == 'cmd' && trim($value) === (string) $_refId) || self::isOnlyRef($value, $_type, $_refId));
    if ($clearValue) {
      $removed++;
    }
    if ($_dryRun || $removed == 0) {
      return $removed;
    }
    log::add('auditsante', 'info', sprintf('Retrait de la référence %s %d dans %s %d. Avant : configuration %s, valeur « %s »',
      $_type, $_refId, $_kind, $_id, json_encode(array_intersect_key($configuration, $changed)), $value));
    foreach ($changed as $key => $clean) {
      $object->setConfiguration($key, $clean);
    }
    if ($clearValue) {
      $object->setValue('');
    }
    $object->save();
    return $removed;
  }

  /** Vrai si un texte ne contient que la référence (#123#, #eqLogic123#, #scenario123#). */
  private static function isOnlyRef($_text, $_type, $_refId) {
    $prefix = array('cmd' => '', 'eqLogic' => 'eqLogic', 'scenario' => 'scenario');
    return trim((string) $_text) === '#' . $prefix[$_type] . (int) $_refId . '#';
  }

  /** Retire la référence d'une valeur de configuration (voir repairDeadRef) ; $_count reçoit le nombre de retraits. */
  private static function stripRef($_value, $_type, $_refId, &$_count) {
    if (!is_array($_value)) {
      if (is_string($_value) && self::isOnlyRef($_value, $_type, $_refId)) {
        $_count++;
        return '';
      }
      return $_value;
    }
    $isList = array_keys($_value) === range(0, count($_value) - 1);
    $return = array();
    foreach ($_value as $key => $element) {
      // Liste d'actions : l'action entière (commande + options) part avec la référence
      if ($isList && is_array($element) && isset($element['cmd']) && auditsanteIndex::hasRef($element, $_type, $_refId)) {
        $_count++;
        continue;
      }
      $return[$key] = self::stripRef($element, $_type, $_refId, $_count);
    }
    return $isList ? array_values($return) : $return;
  }

  /** Statut décodé d'un équipement (colonne status, JSON). */
  private static function eqStatus($_eqLogic, $_key, $_default = null) {
    $status = is_array($_eqLogic['status']) ? $_eqLogic['status'] : json_decode((string) $_eqLogic['status'], true);
    return (is_array($status) && isset($status[$_key]) && $status[$_key] !== '') ? $status[$_key] : $_default;
  }

  /** Configuration décodée d'un équipement ou d'une commande (colonne configuration, JSON). */
  private static function configuration($_row, $_key, $_default = null) {
    $configuration = json_decode((string) $_row['configuration'], true);
    return (is_array($configuration) && isset($configuration[$_key]) && $configuration[$_key] !== '') ? $configuration[$_key] : $_default;
  }

  /** Équipement du plugin lui-même : jamais signalé. */
  private static function isSelf($_eqLogic) {
    return $_eqLogic['eqType_name'] == 'auditsante';
  }

  /* ************************************************************************************ */
  /*                                   Références cassées                                 */
  /* ************************************************************************************ */

  private static function check_broken_scenarios() {
    return self::brokenFor(array('scenario'));
  }

  private static function check_broken_equipments() {
    return self::brokenFor(array('eqLogic', 'cmd'));
  }

  private static function check_broken_interface() {
    return self::brokenFor(array('view', 'plan', 'plan3d', 'object'), false);
  }

  private static function check_broken_interactions() {
    return self::brokenFor(array('interact'));
  }

  private static function check_broken_config() {
    return self::brokenFor(array('config', 'user'));
  }

  /* ************************************************************************************ */
  /*                                   Scénarios                                          */
  /* ************************************************************************************ */

  private static function check_scenario_errors() {
    $items = array();
    foreach (auditsanteIndex::scenarios() as $id => $scenario) {
      if ($scenario->getState() != 'error') {
        continue;
      }
      $items[] = self::item(auditsanteIndex::scenarioName($id) . ' : ' . self::lastLaunch($scenario)
        . ($scenario->getIsActive() == 1 ? '' : ' (' . __('désactivé', __FILE__) . ')'), auditsanteIndex::scenarioUrl($id));
    }
    if (count($items) == 0) {
      return self::pass(__('Aucun scénario en erreur.', __FILE__));
    }
    return self::fail(sprintf(__('%d scénario(s) en erreur.', __FILE__), count($items)), $items);
  }

  private static function check_scenario_dead_triggers() {
    $cmds = auditsanteIndex::cmds();
    $eqLogics = auditsanteIndex::eqLogics();
    $keys = self::excludedKeys('scenario_dead_triggers');
    $voluntary = array();
    $items = array();
    $blocked = 0;
    foreach (auditsanteIndex::scenarios() as $id => $scenario) {
      if ($scenario->getIsActive() != 1 || !in_array($scenario->getMode(), array('provoke', 'all'))) {
        continue;
      }
      $triggers = auditsanteIndex::scenarioTriggers($scenario);
      $disabled = array();
      $valid = 0;
      foreach ($triggers as $trigger) {
        if (!preg_match('/^#(\d+)#$/', $trigger, $match)) {
          $valid++; // #start#, #variable(...)#, #genericType(...)#... : toujours valables
          continue;
        }
        $cmdId = (int) $match[1];
        if (!isset($cmds[$cmdId])) {
          continue; // Signalé par « Scénarios qui utilisent des éléments supprimés »
        }
        $eqLogicId = $cmds[$cmdId]['eqLogic_id'];
        if (isset($eqLogics[$eqLogicId]) && $eqLogics[$eqLogicId]['isEnable'] != 1) {
          $disabled[] = auditsanteIndex::cmdName($cmdId);
          continue;
        }
        $valid++;
      }
      if (count($disabled) == 0) {
        continue;
      }
      $never = ($valid == 0 && !($scenario->getMode() == 'all' && count(auditsanteIndex::scenarioSchedules($scenario)) > 0));
      $item = self::item(auditsanteIndex::scenarioName($id) . ' : ' . implode(', ', $disabled)
        . ($never ? ' - ' . __('ne peut plus se déclencher', __FILE__) : ''), auditsanteIndex::scenarioUrl($id));
      if (self::isVoluntary($item, 'scenario:' . $id, $keys, $voluntary)) {
        continue;
      }
      if ($never) {
        $blocked++;
      }
      $items[] = $item;
    }
    if (count($items) == 0) {
      return self::withVoluntary(self::pass(__('Aucun déclencheur sur un équipement désactivé.', __FILE__)), $voluntary);
    }
    $detail = sprintf(__('%d scénario(s) concerné(s), dont %d qui ne peuvent plus se déclencher.', __FILE__), count($items), $blocked);
    return self::withVoluntary(($blocked > 0) ? self::fail($detail, $items) : self::warn($detail, $items), $voluntary);
  }

  private static function check_scenario_unreachable() {
    $keys = self::excludedKeys('scenario_unreachable');
    $voluntary = array();
    $items = array();
    foreach (auditsanteIndex::scenarios() as $id => $scenario) {
      if ($scenario->getIsActive() != 1 || auditsanteIndex::scenarioHasAutoStart($scenario)) {
        continue;
      }
      // Affiché sur le dashboard d'un objet : peut être lancé à la main
      if ($scenario->getIsVisible() == 1 && $scenario->getObject_id() != '') {
        continue;
      }
      if (count(auditsanteIndex::usersOf('scenario', $id)) > 0) {
        continue;
      }
      $item = self::item(auditsanteIndex::scenarioName($id) . ' : ' . self::lastLaunch($scenario), auditsanteIndex::scenarioUrl($id));
      if (!self::isVoluntary($item, 'scenario:' . $id, $keys, $voluntary)) {
        $items[] = $item;
      }
    }
    if (count($items) == 0) {
      return self::withVoluntary(self::pass(__('Tous les scénarios actifs ont un moyen de se lancer.', __FILE__)), $voluntary);
    }
    return self::withVoluntary(self::warn(sprintf(__('%d scénario(s) actif(s) sans aucun moyen de se lancer.', __FILE__), count($items)), $items), $voluntary);
  }

  private static function check_scenario_disabled_refs() {
    $consumers = auditsanteIndex::consumers();
    $cmds = auditsanteIndex::cmds();
    $eqLogics = auditsanteIndex::eqLogics();
    $scenarios = auditsanteIndex::scenarios();
    $keys = self::excludedKeys('scenario_disabled_refs');
    $voluntary = array();
    $items = array();
    foreach ($scenarios as $id => $scenario) {
      if ($scenario->getIsActive() != 1 || !isset($consumers['scenario:' . $id])) {
        continue;
      }
      // Les déclencheurs sont traités par « Scénarios déclenchés par un équipement désactivé »
      $triggers = implode(' ', auditsanteIndex::scenarioTriggers($scenario));
      $refs = $consumers['scenario:' . $id]['refs'];
      $found = array();
      foreach ($refs['cmd'] as $cmdId) {
        if (strpos($triggers, '#' . $cmdId . '#') !== false || !isset($cmds[$cmdId])) {
          continue;
        }
        $eqLogicId = $cmds[$cmdId]['eqLogic_id'];
        if (isset($eqLogics[$eqLogicId]) && $eqLogics[$eqLogicId]['isEnable'] != 1) {
          $found[] = auditsanteIndex::cmdName($cmdId);
        }
      }
      foreach ($refs['eqLogic'] as $eqLogicId) {
        if (isset($eqLogics[$eqLogicId]) && $eqLogics[$eqLogicId]['isEnable'] != 1) {
          $found[] = auditsanteIndex::eqLogicName($eqLogicId);
        }
      }
      foreach ($refs['scenario'] as $scenarioId) {
        if ($scenarioId != $id && isset($scenarios[$scenarioId]) && $scenarios[$scenarioId]->getIsActive() != 1) {
          $found[] = __('scénario', __FILE__) . ' ' . auditsanteIndex::scenarioName($scenarioId);
        }
      }
      if (count($found) == 0) {
        continue;
      }
      $item = self::item(auditsanteIndex::scenarioName($id) . ' : ' . implode(', ', array_unique($found)), auditsanteIndex::scenarioUrl($id));
      if (!self::isVoluntary($item, 'scenario:' . $id, $keys, $voluntary)) {
        $items[] = $item;
      }
    }
    if (count($items) == 0) {
      return self::withVoluntary(self::pass(__('Aucun scénario actif n\'utilise d\'élément désactivé.', __FILE__)), $voluntary);
    }
    return self::withVoluntary(self::warn(sprintf(__('%d scénario(s) actif(s) utilisent des éléments désactivés.', __FILE__), count($items)), $items), $voluntary);
  }

  private static function check_scenario_stale() {
    $days = self::setting('staleDays', 180);
    $keys = self::excludedKeys('scenario_stale');
    $voluntary = array();
    $items = array();
    foreach (auditsanteIndex::scenarios() as $id => $scenario) {
      if ($scenario->getIsActive() != 1 || !auditsanteIndex::scenarioHasAutoStart($scenario)) {
        continue;
      }
      $since = auditsanteTools::daysSince($scenario->getLastLaunch());
      if ($since !== null && $since < $days) {
        continue;
      }
      $item = self::item(auditsanteIndex::scenarioName($id) . ' : ' . ($since === null
        ? __('jamais lancé', __FILE__)
        : sprintf(__('dernier lancement le %s (il y a %d jours)', __FILE__), auditsanteTools::formatDay($scenario->getLastLaunch()), $since)), auditsanteIndex::scenarioUrl($id));
      if (!self::isVoluntary($item, 'scenario:' . $id, $keys, $voluntary)) {
        $items[] = $item;
      }
    }
    if (count($items) == 0) {
      return self::withVoluntary(self::pass(sprintf(__('Tous les scénarios actifs se sont lancés depuis moins de %d jours.', __FILE__), $days)), $voluntary);
    }
    return self::withVoluntary(self::warn(sprintf(__('%d scénario(s) actif(s) non lancé(s) depuis plus de %d jours.', __FILE__), count($items), $days), $items), $voluntary);
  }

  private static function check_scenario_inactive() {
    $keys = self::excludedKeys('scenario_inactive');
    $voluntary = array();
    $items = array();
    foreach (auditsanteIndex::scenarios() as $id => $scenario) {
      if ($scenario->getIsActive() == 1 || count(auditsanteIndex::usersOf('scenario', $id)) > 0) {
        continue;
      }
      $item = self::item(auditsanteIndex::scenarioName($id) . ' : ' . self::lastLaunch($scenario), auditsanteIndex::scenarioUrl($id));
      if (!self::isVoluntary($item, 'scenario:' . $id, $keys, $voluntary)) {
        $items[] = $item;
      }
    }
    if (count($items) == 0) {
      return self::withVoluntary(self::pass(__('Aucun scénario désactivé oublié.', __FILE__)), $voluntary);
    }
    return self::withVoluntary(self::warn(sprintf(__('%d scénario(s) désactivé(s) que rien n\'utilise.', __FILE__), count($items)), $items), $voluntary);
  }

  private static function check_scenario_empty() {
    $content = auditsanteIndex::scenarioContent();
    $items = array();
    foreach (auditsanteIndex::scenarios() as $id => $scenario) {
      if (!empty($content['elementCount'][$id])) {
        continue;
      }
      $items[] = self::item(auditsanteIndex::scenarioName($id) . ($scenario->getIsActive() == 1 ? '' : ' (' . __('désactivé', __FILE__) . ')'), auditsanteIndex::scenarioUrl($id));
    }
    if (count($items) == 0) {
      return self::pass(__('Aucun scénario vide.', __FILE__));
    }
    return self::warn(sprintf(__('%d scénario(s) vide(s).', __FILE__), count($items)), $items);
  }

  /** Logs des scénarios : {orphans: {fichier => octets}, big: {scenario_id => octets}, total} */
  private static function scenarioLogs() {
    $scenarios = auditsanteIndex::scenarios();
    $return = array('orphans' => array(), 'big' => array(), 'total' => 0);
    foreach (auditsanteTools::filesIn(auditsanteTools::jeedomRoot() . '/log/scenarioLog') as $name => $size) {
      $return['total'] += $size;
      if (!preg_match('/^scenario(\d+)\.log$/', $name, $match)) {
        continue;
      }
      if (!isset($scenarios[(int) $match[1]])) {
        $return['orphans'][$name] = $size;
      } elseif ($size > self::SCENARIO_LOG_MAX) {
        $return['big'][(int) $match[1]] = $size;
      }
    }
    return $return;
  }

  private static function check_scenario_logs() {
    $logs = self::scenarioLogs();
    $items = array();
    arsort($logs['big']);
    foreach ($logs['big'] as $id => $size) {
      $scenarios = auditsanteIndex::scenarios();
      $items[] = self::item(auditsanteIndex::scenarioName($id) . ' : ' . auditsanteTools::formatBytes($size) . ' (' . sprintf(__('log %s', __FILE__), $scenarios[$id]->getConfiguration('logmode', 'default')) . ')', auditsanteIndex::scenarioUrl($id));
    }
    $orphanSize = array_sum($logs['orphans']);
    if (count($logs['orphans']) > 0) {
      $items[] = self::item(sprintf(__('%d log(s) de scénarios supprimés : %s', __FILE__), count($logs['orphans']), auditsanteTools::formatBytes($orphanSize)));
    }
    $detail = sprintf(__('Logs des scénarios : %s au total.', __FILE__), auditsanteTools::formatBytes($logs['total']));
    if (count($logs['big']) == 0 && count($logs['orphans']) == 0) {
      return self::noFix(self::pass($detail));
    }
    $detail .= ' ' . sprintf(__('%d log(s) de plus de %s, %d log(s) de scénarios supprimés.', __FILE__), count($logs['big']), auditsanteTools::formatBytes(self::SCENARIO_LOG_MAX), count($logs['orphans']));
    $result = self::warn($detail, $items);
    return (count($logs['orphans']) == 0) ? self::noFix($result) : $result;
  }

  private static function fix_scenario_logs() {
    $logs = self::scenarioLogs();
    $dir = auditsanteTools::jeedomRoot() . '/log/scenarioLog/';
    $count = 0;
    foreach (array_keys($logs['orphans']) as $name) {
      if (@unlink($dir . $name)) {
        $count++;
      }
    }
    return sprintf(__('%d log(s) supprimé(s).', __FILE__), $count);
  }

  private static function check_scenario_every_minute() {
    $items = array();
    foreach (auditsanteIndex::scenarios() as $id => $scenario) {
      if ($scenario->getIsActive() != 1 || !in_array($scenario->getMode(), array('schedule', 'all'))) {
        continue;
      }
      foreach (auditsanteIndex::scenarioSchedules($scenario) as $schedule) {
        if (preg_match('/^\*\s+\*\s+\*\s+\*\s+\*$/', $schedule)) {
          $items[] = self::item(auditsanteIndex::scenarioName($id), auditsanteIndex::scenarioUrl($id));
          break;
        }
      }
    }
    if (count($items) == 0) {
      return self::pass(__('Aucun scénario programmé toutes les minutes.', __FILE__));
    }
    return self::info(sprintf(__('%d scénario(s) programmé(s) toutes les minutes.', __FILE__), count($items)), $items);
  }

  /* ************************************************************************************ */
  /*                                   Équipements                                        */
  /* ************************************************************************************ */

  /** Équipements dont le plugin est désactivé ou non installé : {plugin => [eqLogic ids]} */
  private static function ghostEqLogics() {
    $plugins = auditsanteIndex::plugins();
    $byPlugin = array();
    foreach (auditsanteIndex::eqLogics() as $id => $eqLogic) {
      $type = $eqLogic['eqType_name'];
      if (isset($plugins[$type]) && $plugins[$type]['active']) {
        continue;
      }
      $byPlugin[$type][] = $id;
    }
    return $byPlugin;
  }

  private static function check_eq_plugin_missing() {
    $plugins = auditsanteIndex::plugins();
    $byPlugin = self::ghostEqLogics();
    if (count($byPlugin) == 0) {
      return self::pass(__('Tous les équipements appartiennent à un plugin actif.', __FILE__));
    }
    $items = array();
    $missing = false;
    $total = 0;
    foreach ($byPlugin as $type => $ids) {
      $installed = isset($plugins[$type]);
      $missing = $missing || !$installed;
      $total += count($ids);
      $names = array_map(array('auditsanteIndex', 'eqLogicName'), array_slice($ids, 0, 5));
      $used = 0;
      foreach ($ids as $id) {
        if (count(auditsanteIndex::externalUsersOfEqLogic($id)) > 0) {
          $used++;
        }
      }
      $items[] = self::item(sprintf(__('Plugin %s (%s) : %d équipement(s), dont %d encore utilisé(s) ailleurs', __FILE__), $type,
        $installed ? __('désactivé', __FILE__) : __('non installé', __FILE__), count($ids), $used)
        . ' - ' . implode(', ', $names) . (count($ids) > 5 ? '...' : ''), $installed ? 'index.php?v=d&p=plugin&id=' . urlencode($type) : null);
    }
    $detail = sprintf(__('%d équipement(s) de %d plugin(s) supprimé(s) ou désactivé(s).', __FILE__), $total, count($byPlugin));
    // Correction automatique réservée aux plugins non installés : un plugin désactivé peut être réactivé
    return $missing ? self::fail($detail, $items) : self::noFix(self::warn($detail, $items));
  }

  private static function fix_eq_plugin_missing() {
    $plugins = auditsanteIndex::plugins();
    $count = 0;
    $errors = 0;
    foreach (self::ghostEqLogics() as $type => $ids) {
      // Plugin installé (désactivé), ou classe chargée par un autre plugin : pas touché
      if (isset($plugins[$type]) || $type == 'auditsante' || class_exists($type, false)) {
        continue;
      }
      foreach ($ids as $id) {
        $eqLogic = eqLogic::byId($id);
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != $type) {
          continue;
        }
        // Sans la classe du plugin, Jeedom charge un eqLogic de base : remove() supprime ses commandes,
        // leur historique et leurs liens, et inscrit les noms dans l'historique des suppressions.
        try {
          $eqLogic->remove();
          $count++;
        } catch (Throwable $e) {
          $errors++;
          log::add('auditsante', 'error', 'Suppression de l\'équipement ' . $id . ' (' . $type . ') : ' . $e->getMessage());
        }
      }
    }
    $message = sprintf(__('%d équipement(s) supprimé(s) avec leurs commandes.', __FILE__), $count);
    if ($errors > 0) {
      $message .= ' ' . sprintf(__('%d échec(s), voir le log du plugin.', __FILE__), $errors);
    }
    return $message;
  }

  private static function check_eq_timeout() {
    $keys = self::excludedKeys('eq_timeout');
    $voluntary = array();
    $items = array();
    foreach (auditsanteIndex::eqLogics() as $id => $eqLogic) {
      if ($eqLogic['isEnable'] != 1 || self::eqStatus($eqLogic, 'timeout', 0) != 1) {
        continue;
      }
      $item = self::item(auditsanteIndex::eqLogicName($id) . ' (' . $eqLogic['eqType_name'] . ') : '
        . sprintf(__('dernière communication le %s', __FILE__), auditsanteTools::formatDay(self::eqStatus($eqLogic, 'lastCommunication', ''))), auditsanteIndex::eqLogicUrl($id));
      if (!self::isVoluntary($item, 'eqLogic:' . $id, $keys, $voluntary)) {
        $items[] = $item;
      }
    }
    if (count($items) == 0) {
      return self::withVoluntary(self::pass(__('Aucun équipement en timeout.', __FILE__)), $voluntary);
    }
    return self::withVoluntary(self::fail(sprintf(__('%d équipement(s) en timeout.', __FILE__), count($items)), $items), $voluntary);
  }

  private static function check_eq_battery() {
    $danger = (int) config::byKey('battery::danger', 'core', 5);
    $warning = (int) config::byKey('battery::warning', 'core', 30);
    $items = array();
    $critical = 0;
    $levels = array();
    foreach (auditsanteIndex::eqLogics() as $id => $eqLogic) {
      $battery = self::eqStatus($eqLogic, 'battery');
      if ($eqLogic['isEnable'] != 1 || $battery === null || !is_numeric($battery)) {
        continue;
      }
      $eqDanger = (int) self::configuration($eqLogic, 'battery_danger_threshold', $danger);
      $eqWarning = (int) self::configuration($eqLogic, 'battery_warning_threshold', $warning);
      if ($battery > $eqWarning) {
        continue;
      }
      $isDanger = ($battery <= $eqDanger);
      if ($isDanger) {
        $critical++;
      }
      $levels[$id] = (float) $battery;
      $items[$id] = self::item(auditsanteIndex::eqLogicName($id) . ' : ' . $battery . ' %' . ($isDanger ? ' (' . __('critique', __FILE__) . ')' : ''), auditsanteIndex::eqLogicUrl($id));
    }
    if (count($items) == 0) {
      return self::pass(__('Aucune pile faible.', __FILE__));
    }
    asort($levels);
    $sorted = array();
    foreach (array_keys($levels) as $id) {
      $sorted[] = $items[$id];
    }
    $detail = sprintf(__('%d pile(s) faible(s), dont %d sous le seuil critique.', __FILE__), count($items), $critical);
    return ($critical > 0) ? self::fail($detail, $sorted) : self::warn($detail, $sorted);
  }

  private static function check_eq_silent() {
    $days = self::setting('silentDays', 30);
    $limit = time() - $days * 86400;
    $cmds = auditsanteIndex::cmds();
    $keys = self::excludedKeys('eq_silent');
    $voluntary = array();
    $items = array();
    $ages = array();
    foreach (auditsanteIndex::eqLogics() as $id => $eqLogic) {
      if ($eqLogic['isEnable'] != 1 || self::isSelf($eqLogic) || (int) $eqLogic['timeout'] > 0 || self::eqStatus($eqLogic, 'timeout', 0) == 1) {
        continue;
      }
      $byEq = auditsanteIndex::cmdsByEqLogic();
      $latest = 0;
      $infos = 0;
      foreach ((isset($byEq[$id]) ? $byEq[$id] : array()) as $cmdId) {
        if ($cmds[$cmdId]['type'] != 'info') {
          continue;
        }
        $infos++;
        $cache = cache::byKey('cmdCacheAttr' . $cmdId)->getValue();
        $date = utils::getJsonAttr($cache, 'collectDate', '');
        $time = ($date == '') ? 0 : (int) strtotime($date);
        $latest = max($latest, $time);
      }
      $lastCommunication = self::eqStatus($eqLogic, 'lastCommunication', '');
      if ($lastCommunication != '') {
        $latest = max($latest, (int) strtotime($lastCommunication));
      }
      if ($infos == 0 || $latest >= $limit) {
        continue;
      }
      $item = self::item(auditsanteIndex::eqLogicName($id) . ' (' . $eqLogic['eqType_name'] . ') : '
        . ($latest == 0 ? __('aucune valeur reçue', __FILE__) : sprintf(__('dernière valeur le %s', __FILE__), date('d/m/Y', $latest))), auditsanteIndex::eqLogicUrl($id));
      if (self::isVoluntary($item, 'eqLogic:' . $id, $keys, $voluntary)) {
        continue;
      }
      $ages[$id] = $latest;
      $items[$id] = $item;
    }
    if (count($items) == 0) {
      return self::withVoluntary(self::pass(sprintf(__('Tous les équipements actifs ont reçu une valeur depuis moins de %d jours.', __FILE__), $days)), $voluntary);
    }
    asort($ages);
    $sorted = array();
    foreach (array_keys($ages) as $id) {
      $sorted[] = $items[$id];
    }
    return self::withVoluntary(self::warn(sprintf(__('%d équipement(s) sans valeur reçue depuis plus de %d jours.', __FILE__), count($items), $days), $sorted), $voluntary);
  }

  private static function check_eq_unused() {
    $cmds = auditsanteIndex::cmds();
    $byEq = auditsanteIndex::cmdsByEqLogic();
    $keys = self::excludedKeys('eq_unused');
    $voluntary = array();
    $items = array();
    foreach (auditsanteIndex::eqLogics() as $id => $eqLogic) {
      if ($eqLogic['isEnable'] != 1 || $eqLogic['isVisible'] == 1 || self::isSelf($eqLogic)) {
        continue;
      }
      $historized = false;
      foreach ((isset($byEq[$id]) ? $byEq[$id] : array()) as $cmdId) {
        if ($cmds[$cmdId]['isHistorized'] == 1) {
          $historized = true;
          break;
        }
      }
      if ($historized || count(auditsanteIndex::externalUsersOfEqLogic($id)) > 0) {
        continue;
      }
      $item = self::item(auditsanteIndex::eqLogicName($id) . ' (' . $eqLogic['eqType_name'] . ')', auditsanteIndex::eqLogicUrl($id));
      if (!self::isVoluntary($item, 'eqLogic:' . $id, $keys, $voluntary)) {
        $items[] = $item;
      }
    }
    if (count($items) == 0) {
      return self::withVoluntary(self::pass(__('Aucun équipement orphelin.', __FILE__)), $voluntary);
    }
    return self::withVoluntary(self::warn(sprintf(__('%d équipement(s) actif(s), masqué(s) et utilisé(s) nulle part.', __FILE__), count($items)), $items), $voluntary);
  }

  private static function check_eq_disabled() {
    $keys = self::excludedKeys('eq_disabled');
    $voluntary = array();
    $items = array();
    foreach (auditsanteIndex::eqLogics() as $id => $eqLogic) {
      if ($eqLogic['isEnable'] == 1) {
        continue;
      }
      $users = auditsanteIndex::externalUsersOfEqLogic($id);
      $item = self::item(auditsanteIndex::eqLogicName($id) . ' (' . $eqLogic['eqType_name'] . ')'
        . (count($users) > 0 ? ' - ' . __('encore utilisé par', __FILE__) . ' ' . self::usersSummary($users) : ''), auditsanteIndex::eqLogicUrl($id));
      if (!self::isVoluntary($item, 'eqLogic:' . $id, $keys, $voluntary)) {
        $items[] = $item;
      }
    }
    if (count($items) == 0) {
      return self::withVoluntary(self::pass(__('Aucun équipement désactivé.', __FILE__)), $voluntary);
    }
    return self::withVoluntary(self::warn(sprintf(__('%d équipement(s) désactivé(s).', __FILE__), count($items)), $items), $voluntary);
  }

  private static function check_eq_no_object() {
    $objects = auditsanteIndex::objects();
    $items = array();
    foreach (auditsanteIndex::eqLogics() as $id => $eqLogic) {
      if ($eqLogic['isEnable'] != 1 || $eqLogic['isVisible'] != 1 || self::isSelf($eqLogic) || ($eqLogic['object_id'] !== null && isset($objects[$eqLogic['object_id']]))) {
        continue;
      }
      $items[] = self::item(auditsanteIndex::eqLogicName($id) . ' (' . $eqLogic['eqType_name'] . ')', auditsanteIndex::eqLogicUrl($id));
    }
    if (count($items) == 0) {
      return self::pass(__('Tous les équipements visibles sont rangés dans un objet.', __FILE__));
    }
    return self::warn(sprintf(__('%d équipement(s) visible(s) sans objet parent.', __FILE__), count($items)), $items);
  }

  private static function check_eq_no_cmd() {
    $byEq = auditsanteIndex::cmdsByEqLogic();
    $items = array();
    foreach (auditsanteIndex::eqLogics() as $id => $eqLogic) {
      if (!empty($byEq[$id]) || self::isSelf($eqLogic)) {
        continue;
      }
      $items[] = self::item(auditsanteIndex::eqLogicName($id) . ' (' . $eqLogic['eqType_name'] . ')' . ($eqLogic['isEnable'] == 1 ? '' : ' (' . __('désactivé', __FILE__) . ')'), auditsanteIndex::eqLogicUrl($id));
    }
    if (count($items) == 0) {
      return self::pass(__('Tous les équipements ont au moins une commande.', __FILE__));
    }
    return self::warn(sprintf(__('%d équipement(s) sans commande.', __FILE__), count($items)), $items);
  }

  /* ************************************************************************************ */
  /*                                   Commandes et historique                            */
  /* ************************************************************************************ */

  /** Commandes dont l'équipement n'existe plus : id => ligne */
  private static function orphanCmds() {
    $eqLogics = auditsanteIndex::eqLogics();
    $return = array();
    foreach (auditsanteIndex::cmds() as $id => $cmd) {
      if (!isset($eqLogics[$cmd['eqLogic_id']])) {
        $return[$id] = $cmd;
      }
    }
    return $return;
  }

  private static function check_cmd_orphan() {
    $items = array();
    $refs = array();
    foreach (self::orphanCmds() as $id => $cmd) {
      $refs['eqLogic:' . $cmd['eqLogic_id']] = array('eqLogic', $cmd['eqLogic_id']);
      $items[] = self::item(sprintf(__('#%d# %s (%s, équipement #eqLogic%d# supprimé)', __FILE__), $id, $cmd['name'], $cmd['eqType'], $cmd['eqLogic_id']) . self::recovered('eqLogic', $cmd['eqLogic_id']));
    }
    if (count($items) == 0) {
      return self::pass(__('Toutes les commandes ont un équipement.', __FILE__));
    }
    return self::withUnnamed(self::fail(sprintf(__('%d commande(s) sans équipement.', __FILE__), count($items)), $items), $refs);
  }

  private static function fix_cmd_orphan() {
    $count = 0;
    foreach (array_keys(self::orphanCmds()) as $id) {
      // Suppression directe : cmd::remove() a besoin de l'équipement, qui n'existe plus.
      // La condition sur l'équipement est revérifiée dans la requête.
      DB::Prepare('DELETE FROM cmd WHERE id = :id AND eqLogic_id NOT IN (SELECT id FROM eqLogic)', array('id' => $id), DB::FETCH_TYPE_ROW);
      DB::Prepare('DELETE FROM history WHERE cmd_id = :id', array('id' => $id), DB::FETCH_TYPE_ROW);
      DB::Prepare('DELETE FROM historyArch WHERE cmd_id = :id', array('id' => $id), DB::FETCH_TYPE_ROW);
      cache::delete('cmdCacheAttr' . $id);
      $count++;
    }
    return sprintf(__('%d commande(s) supprimée(s) avec leur historique.', __FILE__), $count);
  }

  /** Historique des commandes supprimées : {cmd_id => nombre de valeurs} */
  private static function orphanHistory() {
    $return = array();
    foreach (array('history', 'historyArch') as $table) {
      foreach (self::query('SELECT h.cmd_id, COUNT(*) AS n FROM ' . $table . ' h LEFT JOIN cmd c ON c.id = h.cmd_id WHERE c.id IS NULL GROUP BY h.cmd_id') as $row) {
        $return[$row['cmd_id']] = (isset($return[$row['cmd_id']]) ? $return[$row['cmd_id']] : 0) + (int) $row['n'];
      }
    }
    arsort($return);
    return $return;
  }

  private static function check_history_orphan() {
    $orphans = self::orphanHistory();
    if (count($orphans) == 0) {
      return self::pass(__('Aucun historique orphelin.', __FILE__));
    }
    $items = array();
    $refs = array();
    foreach ($orphans as $cmdId => $count) {
      $refs['cmd:' . $cmdId] = array('cmd', $cmdId);
      $items[] = self::item(sprintf(__('Commande #%d# : %s valeur(s)', __FILE__), $cmdId, auditsanteTools::formatNumber($count)) . self::recovered('cmd', $cmdId));
    }
    return self::withUnnamed(self::warn(sprintf(__('%s valeur(s) historisée(s) de %d commande(s) supprimée(s).', __FILE__), auditsanteTools::formatNumber(array_sum($orphans)), count($orphans)), $items), $refs);
  }

  private static function fix_history_orphan() {
    $total = 0;
    foreach (array('history', 'historyArch') as $table) {
      $before = self::query('SELECT COUNT(*) AS n FROM ' . $table);
      DB::Prepare('DELETE h FROM ' . $table . ' h LEFT JOIN cmd c ON c.id = h.cmd_id WHERE c.id IS NULL', array(), DB::FETCH_TYPE_ROW);
      $after = self::query('SELECT COUNT(*) AS n FROM ' . $table);
      $total += max(0, (int) $before[0]['n'] - (int) $after[0]['n']);
    }
    return sprintf(__('%s valeur(s) supprimée(s).', __FILE__), auditsanteTools::formatNumber($total));
  }

  private static function check_history_volume() {
    $cmds = auditsanteIndex::cmds();
    $eqLogics = auditsanteIndex::eqLogics();
    $items = array();
    $counts = array();
    foreach (array('history', 'historyArch') as $table) {
      foreach (self::query('SELECT cmd_id, COUNT(*) AS n FROM ' . $table . ' GROUP BY cmd_id ORDER BY n DESC LIMIT 15') as $row) {
        $counts[$row['cmd_id']] = (isset($counts[$row['cmd_id']]) ? $counts[$row['cmd_id']] : 0) + (int) $row['n'];
      }
    }
    arsort($counts);
    $total = self::query('SELECT (SELECT COUNT(*) FROM history) + (SELECT COUNT(*) FROM historyArch) AS n');
    $globalPurge = config::byKey('historyPurge', 'core', '');
    foreach (array_slice($counts, 0, 10, true) as $cmdId => $count) {
      if (!isset($cmds[$cmdId])) {
        continue; // Historique orphelin : contrôle dédié
      }
      $purge = self::configuration($cmds[$cmdId], 'historyPurge', '');
      $purge = ($purge === '') ? (($globalPurge == '' || $globalPurge == 'never') ? __('jamais', __FILE__) : $globalPurge) : (($purge == 'never') ? __('jamais', __FILE__) : $purge);
      $items[] = self::item(auditsanteIndex::cmdName($cmdId) . ' : ' . sprintf(__('%s valeur(s), purge : %s', __FILE__), auditsanteTools::formatNumber($count), $purge), auditsanteIndex::cmdUrl($cmdId));
    }
    $disabled = 0;
    foreach ($cmds as $cmd) {
      if ($cmd['isHistorized'] == 1 && isset($eqLogics[$cmd['eqLogic_id']]) && $eqLogics[$cmd['eqLogic_id']]['isEnable'] != 1) {
        $disabled++;
      }
    }
    if ($disabled > 0) {
      $items[] = self::item(sprintf(__('%d commande(s) historisée(s) appartiennent à des équipements désactivés.', __FILE__), $disabled));
    }
    $historized = count(array_filter($cmds, function ($_cmd) {
      return $_cmd['isHistorized'] == 1;
    }));
    return self::info(sprintf(__('%s valeur(s) pour %d commande(s) historisée(s). Commandes les plus lourdes :', __FILE__),
      auditsanteTools::formatNumber(isset($total[0]['n']) ? $total[0]['n'] : 0), $historized), $items);
  }

  private static function check_cmd_unused() {
    $eqLogics = auditsanteIndex::eqLogics();
    $usedBy = auditsanteIndex::usedBy();
    $byPlugin = array();
    $total = 0;
    foreach (auditsanteIndex::cmds() as $id => $cmd) {
      if (!isset($eqLogics[$cmd['eqLogic_id']]) || $eqLogics[$cmd['eqLogic_id']]['isEnable'] != 1) {
        continue;
      }
      if ($cmd['isVisible'] == 1 || $cmd['isHistorized'] == 1 || !empty($usedBy['cmd'][$id])) {
        continue;
      }
      $plugin = $eqLogics[$cmd['eqLogic_id']]['eqType_name'];
      $byPlugin[$plugin] = (isset($byPlugin[$plugin]) ? $byPlugin[$plugin] : 0) + 1;
      $total++;
    }
    if ($total == 0) {
      return self::pass(__('Aucune commande inutilisée.', __FILE__));
    }
    arsort($byPlugin);
    $items = array();
    foreach ($byPlugin as $plugin => $count) {
      $items[] = self::item(sprintf(__('Plugin %s : %d commande(s)', __FILE__), $plugin, $count));
    }
    return self::info(sprintf(__('%d commande(s) masquée(s), non historisée(s) et utilisée(s) nulle part.', __FILE__), $total), $items);
  }

  private static function check_cmd_generic_type() {
    $eqLogics = auditsanteIndex::eqLogics();
    $byPlugin = array();
    $total = 0;
    foreach (auditsanteIndex::cmds() as $cmd) {
      $eqLogic = isset($eqLogics[$cmd['eqLogic_id']]) ? $eqLogics[$cmd['eqLogic_id']] : null;
      if ($eqLogic === null || $eqLogic['isEnable'] != 1 || $eqLogic['isVisible'] != 1 || $cmd['isVisible'] != 1 || self::isSelf($eqLogic)) {
        continue;
      }
      if (trim((string) $cmd['generic_type']) !== '') {
        continue;
      }
      $byPlugin[$eqLogic['eqType_name']] = (isset($byPlugin[$eqLogic['eqType_name']]) ? $byPlugin[$eqLogic['eqType_name']] : 0) + 1;
      $total++;
    }
    if ($total == 0) {
      return self::pass(__('Toutes les commandes affichées ont un type générique.', __FILE__));
    }
    arsort($byPlugin);
    $items = array();
    foreach ($byPlugin as $plugin => $count) {
      $items[] = self::item(sprintf(__('Plugin %s : %d commande(s)', __FILE__), $plugin, $count));
    }
    return self::info(sprintf(__('%d commande(s) affichée(s) sans type générique.', __FILE__), $total), $items);
  }

  /* ************************************************************************************ */
  /*                                   Objets, vues et designs                            */
  /* ************************************************************************************ */

  private static function check_object_empty() {
    $objects = auditsanteIndex::objects();
    $hasEq = array();
    foreach (auditsanteIndex::eqLogics() as $eqLogic) {
      if ($eqLogic['object_id'] !== null) {
        $hasEq[$eqLogic['object_id']] = true;
      }
    }
    $hasChild = array();
    foreach ($objects as $object) {
      if ($object['father_id'] != '') {
        $hasChild[$object['father_id']] = true;
      }
    }
    $consumers = auditsanteIndex::consumers();
    $keys = self::excludedKeys('object_empty');
    $voluntary = array();
    $items = array();
    foreach ($objects as $id => $object) {
      if (isset($hasEq[$id]) || isset($hasChild[$id])) {
        continue;
      }
      if (isset($consumers['object:' . $id]) && count($consumers['object:' . $id]['refs']['cmd']) > 0) {
        continue; // Résumé configuré
      }
      $item = self::item('[' . $object['name'] . ']', 'index.php?v=d&p=object&id=' . $id);
      if (!self::isVoluntary($item, 'object:' . $id, $keys, $voluntary)) {
        $items[] = $item;
      }
    }
    if (count($items) == 0) {
      return self::withVoluntary(self::pass(__('Aucun objet vide.', __FILE__)), $voluntary);
    }
    return self::withVoluntary(self::warn(sprintf(__('%d objet(s) vide(s).', __FILE__), count($items)), $items), $voluntary);
  }

  private static function check_view_empty() {
    $keys = self::excludedKeys('view_empty');
    $voluntary = array();
    $items = array();
    foreach (self::query('SELECT v.id, v.name FROM view v WHERE NOT EXISTS (SELECT 1 FROM viewZone z JOIN viewData d ON d.viewZone_id = z.id WHERE z.view_id = v.id)') as $row) {
      $item = self::item(__('Vue', __FILE__) . ' [' . $row['name'] . ']', 'index.php?v=d&p=view&view_id=' . $row['id']);
      if (!self::isVoluntary($item, 'view:' . $row['id'], $keys, $voluntary)) {
        $items[] = $item;
      }
    }
    foreach (array('plan' => array('planHeader', 'planHeader_id', 'plan_id', __('Design', __FILE__)), 'plan3d' => array('plan3dHeader', 'plan3dHeader_id', 'plan3d_id', __('Design 3D', __FILE__))) as $table => $def) {
      if (!auditsanteIndex::tableExists($table) || !auditsanteIndex::tableExists($def[0])) {
        continue;
      }
      foreach (self::query('SELECT h.id, h.name FROM ' . $def[0] . ' h WHERE NOT EXISTS (SELECT 1 FROM ' . $table . ' p WHERE p.' . $def[1] . ' = h.id)') as $row) {
        $item = self::item($def[3] . ' [' . $row['name'] . ']', 'index.php?v=d&p=' . $table . '&' . $def[2] . '=' . $row['id']);
        if (!self::isVoluntary($item, $table . ':' . $row['id'], $keys, $voluntary)) {
          $items[] = $item;
        }
      }
    }
    if (count($items) == 0) {
      return self::withVoluntary(self::pass(__('Aucune vue ni aucun design vide.', __FILE__)), $voluntary);
    }
    return self::withVoluntary(self::warn(sprintf(__('%d vue(s) ou design(s) vide(s).', __FILE__), count($items)), $items), $voluntary);
  }

  private static function check_widget_unused() {
    if (!auditsanteIndex::tableExists('widgets')) {
      return self::skip(__('Widgets personnalisés non disponibles sur cette version de Jeedom.', __FILE__));
    }
    $templates = '';
    foreach (auditsanteIndex::cmds() as $cmd) {
      if (strpos((string) $cmd['template'], 'custom::') !== false) {
        $templates .= $cmd['template'];
      }
    }
    $items = array();
    foreach (self::query('SELECT id, name, type, subtype FROM widgets') as $row) {
      if (strpos($templates, 'custom::' . $row['name'] . '"') !== false) {
        continue;
      }
      $items[] = self::item($row['name'] . ' (' . $row['type'] . ' / ' . $row['subtype'] . ')', 'index.php?v=d&p=widgets&id=' . $row['id']);
    }
    if (count($items) == 0) {
      return self::pass(__('Tous les widgets personnalisés sont utilisés.', __FILE__));
    }
    return self::warn(sprintf(__('%d widget(s) personnalisé(s) inutilisé(s).', __FILE__), count($items)), $items);
  }

  /* ************************************************************************************ */
  /*                                   Plugins et tâches                                  */
  /* ************************************************************************************ */

  private static function check_plugin_daemons() {
    $items = array();
    foreach (auditsanteIndex::plugins() as $id => $info) {
      if (!$info['active']) {
        continue;
      }
      $plugin = $info['plugin'];
      try {
        if ($plugin->getHasDependency() == 1) {
          $dependancy = $plugin->dependancy_info();
          if (isset($dependancy['state']) && $dependancy['state'] == 'nok') {
            $items[] = self::item(sprintf(__('%s : dépendances non installées', __FILE__), $info['name']), 'index.php?v=d&p=plugin&id=' . urlencode($id));
            continue;
          }
        }
        if ($plugin->getHasOwnDeamon() == 1) {
          $deamon = $plugin->deamon_info();
          if (isset($deamon['auto']) && $deamon['auto'] == 1 && isset($deamon['state']) && $deamon['state'] != 'ok') {
            $message = (isset($deamon['launchable']) && $deamon['launchable'] != 'ok' && !empty($deamon['launchable_message'])) ? $deamon['launchable_message'] : __('démon arrêté', __FILE__);
            $items[] = self::item($info['name'] . ' : ' . $message, 'index.php?v=d&p=plugin&id=' . urlencode($id));
          }
        }
      } catch (Throwable $e) {
        $items[] = self::item($info['name'] . ' : ' . __('état illisible', __FILE__) . ' (' . $e->getMessage() . ')', 'index.php?v=d&p=plugin&id=' . urlencode($id));
      }
    }
    if (count($items) == 0) {
      return self::pass(__('Tous les démons en gestion automatique tournent et les dépendances sont installées.', __FILE__));
    }
    return self::fail(sprintf(__('%d plugin(s) en échec.', __FILE__), count($items)), $items);
  }

  private static function check_cron_errors() {
    $items = array();
    foreach (cron::all() as $cron) {
      if ($cron->getEnable() != 1 || $cron->getState() != 'error') {
        continue;
      }
      $items[] = self::item(($cron->getClass() != '' ? $cron->getClass() . '::' : '') . $cron->getFunction() . '()' . ' : '
        . sprintf(__('dernière exécution le %s', __FILE__), auditsanteTools::formatDateTime($cron->getLastRun())));
    }
    if (count($items) == 0) {
      return self::pass(__('Aucune tâche en erreur.', __FILE__));
    }
    return self::fail(sprintf(__('%d tâche(s) en erreur.', __FILE__), count($items)), $items);
  }

  /** Vrai si la classe et la méthode (ou la fonction) appelées existent. */
  private static function callableExists($_class, $_function) {
    if ($_class == '') {
      return $_function == '' || function_exists($_function);
    }
    try {
      return class_exists($_class) && ($_function == '' || method_exists($_class, $_function));
    } catch (Throwable $e) {
      return false;
    }
  }

  /** Tâches et écouteurs orphelins : {crons: [cron], listeners: [listener]} */
  private static function orphanTasks() {
    $return = array('crons' => array(), 'listeners' => array());
    foreach (cron::all() as $cron) {
      if (!self::callableExists($cron->getClass(), $cron->getFunction())) {
        $return['crons'][] = $cron;
      }
    }
    foreach (listener::all() as $listener) {
      if (!self::callableExists($listener->getClass(), $listener->getFunction())) {
        $return['listeners'][] = $listener;
      }
    }
    return $return;
  }

  private static function check_cron_orphan() {
    $orphans = self::orphanTasks();
    $items = array();
    foreach ($orphans['crons'] as $cron) {
      $items[] = self::item(__('Tâche', __FILE__) . ' ' . ($cron->getClass() != '' ? $cron->getClass() . '::' : '') . $cron->getFunction() . '()');
    }
    foreach ($orphans['listeners'] as $listener) {
      $items[] = self::item(__('Écouteur', __FILE__) . ' ' . ($listener->getClass() != '' ? $listener->getClass() . '::' : '') . $listener->getFunction() . '()');
    }
    if (count($items) == 0) {
      return self::pass(__('Aucune tâche ni aucun écouteur orphelin.', __FILE__));
    }
    return self::warn(sprintf(__('%d tâche(s) et %d écouteur(s) orphelin(s).', __FILE__), count($orphans['crons']), count($orphans['listeners'])), $items);
  }

  private static function fix_cron_orphan() {
    $orphans = self::orphanTasks();
    foreach ($orphans['crons'] as $cron) {
      $cron->remove(false);
    }
    foreach ($orphans['listeners'] as $listener) {
      $listener->remove();
    }
    return sprintf(__('%d tâche(s) et %d écouteur(s) supprimé(s).', __FILE__), count($orphans['crons']), count($orphans['listeners']));
  }

  private static function check_plugin_disabled() {
    $counts = array();
    foreach (auditsanteIndex::eqLogics() as $eqLogic) {
      $counts[$eqLogic['eqType_name']] = (isset($counts[$eqLogic['eqType_name']]) ? $counts[$eqLogic['eqType_name']] : 0) + 1;
    }
    $keys = self::excludedKeys('plugin_disabled');
    $voluntary = array();
    $items = array();
    foreach (auditsanteIndex::plugins() as $id => $info) {
      if ($info['active']) {
        continue;
      }
      $item = self::item($info['name'] . ' (' . $id . ') : ' . sprintf(__('%d équipement(s)', __FILE__), isset($counts[$id]) ? $counts[$id] : 0), 'index.php?v=d&p=plugin&id=' . urlencode($id));
      if (!self::isVoluntary($item, 'plugin:' . $id, $keys, $voluntary)) {
        $items[] = $item;
      }
    }
    if (count($items) == 0) {
      return self::withVoluntary(self::pass(__('Aucun plugin désactivé.', __FILE__)), $voluntary);
    }
    return self::withVoluntary(self::warn(sprintf(__('%d plugin(s) installé(s) mais désactivé(s).', __FILE__), count($items)), $items), $voluntary);
  }

  private static function check_plugin_no_eq() {
    $counts = array();
    foreach (auditsanteIndex::eqLogics() as $eqLogic) {
      $counts[$eqLogic['eqType_name']] = true;
    }
    $items = array();
    $tools = 0;
    foreach (auditsanteIndex::plugins() as $id => $info) {
      if (!$info['active'] || isset($counts[$id])) {
        continue;
      }
      if (!self::pluginHasEquipments($id)) {
        $tools++;
        continue;
      }
      $items[] = self::item($info['name'] . ' (' . $id . ')', 'index.php?v=d&p=plugin&id=' . urlencode($id));
    }
    $toolsDetail = ($tools > 0) ? ' ' . sprintf(__('%d plugin(s) qui ne gèrent pas d\'équipement (outils, thèmes) ne sont pas comptés.', __FILE__), $tools) : '';
    if (count($items) == 0) {
      return self::pass(__('Tous les plugins actifs ont au moins un équipement.', __FILE__) . $toolsDetail);
    }
    return self::info(sprintf(__('%d plugin(s) actif(s) sans équipement.', __FILE__), count($items)) . $toolsDetail, $items);
  }

  /**
   * Un plugin gère des équipements si sa page de configuration affiche la grille des équipements
   * (eqLogicThumbnailContainer) ou un bouton d'ajout (data-action="add"). Les plugins outils (assistants, thèmes,
   * tableaux de bord) n'ont ni l'un ni l'autre, ou pas de page du tout. Page illisible : on suppose que oui.
   */
  private static function pluginHasEquipments($_id) {
    if (class_exists($_id) && !is_subclass_of($_id, 'eqLogic')) {
      return false;
    }
    $file = auditsanteTools::jeedomRoot() . '/plugins/' . $_id . '/desktop/php/' . $_id . '.php';
    if (!file_exists($file)) {
      return false;
    }
    $page = @file_get_contents($file);
    if ($page === false) {
      return true;
    }
    return stripos($page, 'eqLogicThumbnailContainer') !== false || preg_match('/data-action\s*=\s*["\']add["\']/i', $page) === 1;
  }

  /**
   * Libellé d'une configuration rangée sous un plugin absent. Certains plugins défectueux enregistrent leur
   * configuration sous un chemin de fichier (…/plugins/<id>/plugin_info/info.json) ou sous « false », « null »,
   * une chaîne vide... au lieu de leur identifiant.
   */
  private static function leftoverLabel($_plugin, $_plugins) {
    if (preg_match('#plugins/([^/]+)/plugin_info#', $_plugin, $match)) {
      $id = $match[1];
      if ($id === 'false' || $id === 'null' || $id === '') {
        return sprintf(__('Configuration enregistrée sous un chemin de fichier sans nom de plugin (%s)', __FILE__), $_plugin);
      }
      return sprintf(__('Configuration du plugin %s, enregistrée sous son chemin de fichier', __FILE__), $id)
        . (isset($_plugins[$id]) ? ' (' . __('plugin installé, mais cette copie ne sert plus', __FILE__) . ')' : '');
    }
    if (in_array(strtolower(trim($_plugin)), array('', 'false', 'null', 'undefined', '0'))) {
      return sprintf(__('Configuration sans nom de plugin (« %s »), écrite par un plugin défectueux', __FILE__), $_plugin);
    }
    return sprintf(__('Configuration du plugin %s', __FILE__), $_plugin);
  }

  private static function check_plugin_leftovers() {
    $plugins = auditsanteIndex::plugins();
    $items = array();
    $byPlugin = array();
    foreach (self::query("SELECT plugin, `key` FROM config WHERE plugin <> 'core'") as $row) {
      if (!isset($plugins[$row['plugin']])) {
        $byPlugin[(string) $row['plugin']][] = (string) $row['key'];
      }
    }
    foreach ($byPlugin as $plugin => $keys) {
      $text = self::leftoverLabel($plugin, $plugins) . ' : ' . sprintf(__('%d paramètre(s)', __FILE__), count($keys))
        . ' (' . implode(', ', array_slice($keys, 0, 3)) . (count($keys) > 3 ? '...' : '') . ')';
      $items[] = self::item($text);
    }
    foreach (self::query("SELECT logicalId, name FROM `update` WHERE type = 'plugin'") as $row) {
      if (isset($plugins[$row['logicalId']])) {
        continue;
      }
      $items[] = self::item(sprintf(__('Centre de mises à jour : %s, fichiers absents', __FILE__), $row['logicalId']), 'index.php?v=d&p=update');
    }
    if (count($items) == 0) {
      return self::pass(__('Aucun reste de plugin supprimé.', __FILE__));
    }
    return self::warn(sprintf(__('%d reste(s) de plugins qui ne sont plus installés.', __FILE__), count($items)), $items);
  }

  /* ************************************************************************************ */
  /*                                   Base de données et fichiers                        */
  /* ************************************************************************************ */

  private static function check_variables_unused() {
    $corpus = auditsanteIndex::corpus() . auditsanteIndex::interactQueryActions();
    $content = auditsanteIndex::scenarioContent();
    // Code libre (blocs code des scénarios, HTML ou JS des designs et vues) : il peut citer la variable par son seul
    // nom, entre guillemets, par exemple dans un tableau parcouru ensuite avec getData($nom)
    $code = $content['codes'];
    foreach (auditsanteIndex::consumers() as $consumer) {
      if (in_array($consumer['kind'], array('plan', 'plan3d', 'view'))) {
        $code .= "\n" . $consumer['text'];
      }
    }
    // Guillemet tel qu'il peut être stocké : brut, échappé en JSON (\" , \\\"), ou en entité HTML (&quot; &#39;)
    $quote = '(?:\\\\*["\']|&quot;|&#0?34;|&#0?39;|&apos;)';
    $dynamic = self::dynamicVariableNames($code, $corpus . $content['variableOptions'], $quote);
    $excludedKeys = self::excludedKeys('variables_unused');
    $excluded = array();
    $items = array();
    $total = 0;
    $built = 0;
    foreach (self::query("SELECT id, `key`, `value` FROM dataStore WHERE type = 'scenario' AND link_id = -1") as $row) {
      $total++;
      $name = (string) $row['key'];
      if ($name === '') {
        continue;
      }
      if (isset($excludedKeys[$name])) {
        $excluded[] = $name;
        continue;
      }
      if (self::variableUsed($name, $corpus, $code, $content['variableOptions'], $quote)) {
        continue;
      }
      if (self::matchesDynamicName($name, $dynamic)) {
        $built++;
        continue;
      }
      $value = (string) $row['value'];
      if (function_exists('mb_strlen') ? mb_strlen($value) > 40 : strlen($value) > 40) {
        $value = (function_exists('mb_substr') ? mb_substr($value, 0, 40) : substr($value, 0, 40)) . '...';
      }
      $item = self::item($name . ' = ' . $value, 'index.php?v=d&modal=dataStore.management&type=scenario');
      $item['exclude'] = $name;
      $items[] = $item;
    }
    $detail = ($built > 0) ? ' ' . sprintf(__('%d variable(s) dont le nom est construit dans un bloc code ou avec un tag sont considérées comme utilisées.', __FILE__), $built) : '';
    if (count($excluded) > 0) {
      $detail .= ' ' . sprintf(__('%d variable(s) exclue(s) de l\'audit.', __FILE__), count($excluded));
    }
    sort($excluded, SORT_NATURAL | SORT_FLAG_CASE);
    if (count($items) == 0) {
      return self::result('pass', sprintf(__('Les %d variable(s) sont utilisées.', __FILE__), $total) . $detail, array(), array('excluded' => $excluded));
    }
    return self::result('warn', sprintf(__('%d variable(s) sur %d utilisée(s) nulle part.', __FILE__), count($items), $total) . $detail, $items, array('excluded' => $excluded));
  }

  /**
   * Élément qu'on peut marquer comme voulu : il reçoit sa clé ; s'il est déjà marqué, il passe dans $_voluntary
   * (affiché à part, plus compté) et la fonction retourne vrai.
   */
  private static function isVoluntary(&$_item, $_key, $_keys, &$_voluntary) {
    $_item['exclude'] = $_key;
    if (!isset($_keys[$_key])) {
      return false;
    }
    $_voluntary[] = $_item;
    return true;
  }

  /** Ajoute au résultat les éléments marqués comme voulus, et leur nombre au détail. */
  private static function withVoluntary($_result, $_voluntary) {
    if (count($_voluntary) > 0) {
      $_result['detail'] .= ' ' . sprintf(__('%d exclu(s) de l\'audit, affiché(s) sans compter dans la note.', __FILE__), count($_voluntary));
      $_result['excluded'] = $_voluntary;
    }
    return $_result;
  }

  /** Éléments exclus d'un contrôle par l'utilisateur (data/excluded.json) : {clé => {since, by}}. */
  public static function excludedKeys($_id) {
    $excluded = auditsanteStore::read('excluded');
    return (isset($excluded[$_id]) && is_array($excluded[$_id])) ? $excluded[$_id] : array();
  }

  /**
   * Morceaux de noms de variables construits dynamiquement : dans le code libre, texte entre guillemets collé à une
   * concaténation ('tps_' . $eq, $eq . '_jour', 'a' + x en JS), à une interpolation ("tps_{$eq}_jour", `x_${i}`)
   * ou à un format sprintf ('tps_%s_jour') ; dans les expressions, variable(tps_#tag#) ou "name":"tps_#tag#".
   * Retourne {prefixes: [], suffixes: [], regex: []}.
   */
  private static function dynamicVariableNames($_code, $_corpus, $_quote) {
    $prefixes = array();
    $suffixes = array();
    $regex = array();
    $literal = '(["\'])((?:(?!\1)[^\\\\\n]|\\\\.){1,100})\1';
    if ($_code !== '') {
      if (preg_match_all('/' . $literal . '\s*[.+](?![.\d=+])/', $_code, $matches)) {
        $prefixes = array_merge($prefixes, $matches[2]);
      }
      if (preg_match_all('/(?:[.+]=?)(?<![.+][.+])\s*' . $literal . '/', $_code, $matches)) {
        $suffixes = array_merge($suffixes, $matches[2]);
      }
      // Interpolation et formats : le texte avant le premier morceau variable, et après le dernier
      // (entre apostrophes, PHP n'interpole pas : seuls les formats sprintf comptent)
      if (preg_match_all('/(["\'`])((?:(?!\1)[^\\\\\n]|\\\\.){1,100})\1/', $_code, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
          $text = $match[2];
          $format = '%(?:\d+\$)?[sd]';
          $parts = preg_split('/' . ($match[1] == "'" ? $format : '\{?\$\{?[A-Za-z_][\w\[\]\'"\->]*\}?|' . $format) . '/', $text);
          if (count($parts) > 1) {
            $prefixes[] = $parts[0];
            $suffixes[] = end($parts);
          }
        }
      }
      foreach ($prefixes as $text) {
        $parts = preg_split('/%(?:\d+\$)?[sd]/', $text);
        if (count($parts) > 1) {
          $suffixes[] = end($parts);
        }
      }
    }
    // Tags Jeedom dans le nom : variable(tps_#trigger_name#) ou action variable dont le nom contient un tag
    if (preg_match_all('/variable\s*\(\s*' . $_quote . '?([^,()"\']*#[^,()"\']*?)' . $_quote . '?\s*[,)]/i', $_corpus, $m1)
      | preg_match_all('/' . $_quote . '(?:name|variable)' . $_quote . '\s*:\s*' . $_quote . '([^"\'\\\\&]*#[^"\'\\\\&]*)' . $_quote . '/i', $_corpus, $m2)) {
      foreach (array_merge(isset($m1[1]) ? $m1[1] : array(), isset($m2[1]) ? $m2[1] : array()) as $text) {
        $parts = preg_split('/#[^#]+#/', trim($text));
        if (count($parts) < 2) {
          continue;
        }
        $regex[] = '/^' . implode('.+', array_map(function ($_part) {
          return preg_quote($_part, '/');
        }, $parts)) . '$/i';
      }
    }
    // Un morceau trop court ou sans lettre (« _ », « - », « 1 ») rapprocherait n'importe quel nom
    $keep = function ($_list) {
      $return = array();
      foreach ($_list as $text) {
        $text = stripslashes($text);
        if (strlen($text) >= 3 && preg_match('/[a-z]/i', $text)) {
          $return[strtolower($text)] = true;
        }
      }
      return array_keys($return);
    };
    return array('prefixes' => $keep($prefixes), 'suffixes' => $keep($suffixes), 'regex' => array_unique($regex));
  }

  private static function matchesDynamicName($_name, $_dynamic) {
    $name = strtolower($_name);
    $length = strlen($name);
    foreach ($_dynamic['prefixes'] as $prefix) {
      if ($length > strlen($prefix) && strpos($name, $prefix) === 0) {
        return true;
      }
    }
    foreach ($_dynamic['suffixes'] as $suffix) {
      if ($length > strlen($suffix) && substr($name, -strlen($suffix)) === $suffix) {
        return true;
      }
    }
    foreach ($_dynamic['regex'] as $regex) {
      if (preg_match($regex, $_name)) {
        return true;
      }
    }
    return false;
  }

  /**
   * Une variable est utilisée si un élément la lit ou l'écrit : variable(nom) ou variable("nom", ...), champ
   * « name » ou « variable » d'une action (JSON), setData/getData/removeData, ou son nom entre guillemets
   * dans un bloc code de scénario ou dans le code d'un design ou d'une vue. Comme dataStore::getUsedBy() du core,
   * accepte aussi le nom comme valeur de n'importe quel champ d'une action « variable » de scénario.
   */
  private static function variableUsed($_name, $_corpus, $_code, $_variableOptions, $_quote) {
    if (stripos($_corpus, $_name) === false) {
      return false;
    }
    $name = preg_quote($_name, '/');
    if (preg_match('/variable\s*\(\s*' . $_quote . '?\s*' . $name . '\s*' . $_quote . '?\s*[,)]/i', $_corpus)) {
      return true;
    }
    if (preg_match('/' . $_quote . '(?:name|variable)' . $_quote . '\s*:\s*' . $_quote . $name . $_quote . '/i', $_corpus)) {
      return true;
    }
    if (preg_match('/:\s*' . $_quote . $name . $_quote . '/i', $_variableOptions)) {
      return true;
    }
    // Bloc code d'un scénario : $scenario->setData('nom', ...), getData, removeData, scenario::setData, dataStore::byTypeLinkIdKey('scenario', -1, 'nom')
    if (preg_match('/(?:(?:set|get|remove)Data|byTypeLinkIdKey\s*\([^)]*?,)\s*\(?\s*' . $_quote . $name . $_quote . '/i', $_corpus)) {
      return true;
    }
    return $_code !== '' && stripos($_code, $_name) !== false && preg_match('/' . $_quote . $name . $_quote . '/i', $_code) === 1;
  }

  /** Variables rattachées à un scénario ou un équipement supprimé : [ligne] */
  private static function orphanDataStore() {
    $scenarios = auditsanteIndex::scenarios();
    $eqLogics = auditsanteIndex::eqLogics();
    $return = array();
    foreach (self::query("SELECT id, type, link_id, `key` FROM dataStore WHERE link_id <> -1") as $row) {
      if (($row['type'] == 'scenario' && !isset($scenarios[$row['link_id']])) || ($row['type'] == 'eqLogic' && !isset($eqLogics[$row['link_id']]))) {
        $return[] = $row;
      }
    }
    return $return;
  }

  private static function check_datastore_orphan() {
    $items = array();
    foreach (self::orphanDataStore() as $row) {
      $items[] = self::item(sprintf(__('%s (%s #%d supprimé)', __FILE__), $row['key'], $row['type'], $row['link_id']));
    }
    if (count($items) == 0) {
      return self::pass(__('Aucune variable d\'élément supprimé.', __FILE__));
    }
    return self::warn(sprintf(__('%d variable(s) d\'éléments supprimés.', __FILE__), count($items)), $items);
  }

  private static function fix_datastore_orphan() {
    $count = 0;
    foreach (self::orphanDataStore() as $row) {
      $dataStore = dataStore::byId($row['id']);
      if (is_object($dataStore)) {
        $dataStore->remove();
        $count++;
      }
    }
    return sprintf(__('%d variable(s) supprimée(s).', __FILE__), $count);
  }

  private static function check_scenario_db_orphans() {
    $orphans = auditsanteIndex::scenarioContent()['orphans'];
    $total = array_sum($orphans);
    if ($total == 0) {
      return self::pass(__('Tous les blocs de scénarios sont rattachés à un scénario.', __FILE__));
    }
    return self::warn(sprintf(__('%d bloc(s), %d sous-bloc(s) et %d expression(s) orphelins.', __FILE__), $orphans['elements'], $orphans['subElements'], $orphans['expressions']));
  }

  private static function check_db_size() {
    $rows = self::query('SELECT table_name AS name, data_length + index_length AS size, table_rows AS nb FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY size DESC');
    if (count($rows) == 0) {
      return self::skip(__('Taille des tables illisible.', __FILE__));
    }
    $total = 0;
    foreach ($rows as $row) {
      $total += (int) $row['size'];
    }
    $items = array();
    foreach (array_slice($rows, 0, 8) as $row) {
      $items[] = self::item(sprintf(__('%s : %s (environ %s lignes)', __FILE__), $row['name'], auditsanteTools::formatBytes($row['size']), auditsanteTools::formatNumber($row['nb'])));
    }
    return self::info(sprintf(__('Base de données : %s.', __FILE__), auditsanteTools::formatBytes($total)), $items);
  }

  private static function check_log_size() {
    $max = self::setting('logMaxMb', 20) * 1048576;
    $dir = auditsanteTools::jeedomRoot() . '/log';
    $files = auditsanteTools::filesIn($dir);
    $total = auditsanteTools::dirSize($dir);
    arsort($files);
    $items = array();
    foreach ($files as $name => $size) {
      if ($size <= $max) {
        break;
      }
      $items[] = self::item($name . ' : ' . auditsanteTools::formatBytes($size), 'index.php?v=d&p=log&logfile=' . urlencode($name));
    }
    $detail = sprintf(__('Dossier des logs : %s.', __FILE__), auditsanteTools::formatBytes($total));
    if (count($items) == 0) {
      return self::pass($detail . ' ' . sprintf(__('Aucun fichier de plus de %s.', __FILE__), auditsanteTools::formatBytes($max)));
    }
    return self::warn($detail . ' ' . sprintf(__('%d fichier(s) de plus de %s.', __FILE__), count($items), auditsanteTools::formatBytes($max)), $items);
  }

  private static function check_messages() {
    $max = self::setting('messageMax', 50);
    $rows = self::query('SELECT plugin, COUNT(*) AS n FROM message GROUP BY plugin ORDER BY n DESC');
    $total = 0;
    $items = array();
    foreach ($rows as $row) {
      $total += (int) $row['n'];
      $items[] = self::item(sprintf(__('%s : %d message(s)', __FILE__), ($row['plugin'] == '' ? 'core' : $row['plugin']), $row['n']));
    }
    if ($total <= $max) {
      return self::pass(sprintf(__('%d message(s) dans le centre de messages.', __FILE__), $total));
    }
    return self::warn(sprintf(__('%d message(s) dans le centre de messages (seuil : %d).', __FILE__), $total, $max), $items);
  }
}
