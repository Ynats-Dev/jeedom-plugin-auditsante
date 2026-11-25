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

/**
 * Inventaire de l'installation et index des références (« qui utilise quoi »).
 *
 * Tout est lu une seule fois par audit, directement en SQL pour rester rapide sur les grosses installations,
 * puis gardé en mémoire (reset() au début de chaque audit).
 *
 * Un « consommateur » est un élément qui peut faire référence à des commandes, équipements ou scénarios :
 * scénario, équipement (configuration), commande (configuration, valeur liée), interaction, vue, design,
 * design 3D, objet (résumés), configuration du core ou d'un plugin, utilisateur. Chaque consommateur est décrit par :
 *   kind, id, label, url, active (bool), eqLogic_id (équipement propriétaire, pour les commandes et équipements),
 *   deadCheck (bool : ses références introuvables sont signalées), text (contenu analysé),
 *   refs {cmd: [ids], eqLogic: [ids], scenario: [ids]}.
 *
 * Références reconnues : #123# (commande), #eqLogic123#, "eqLogic":"123", #scenario123#, "scenario_id":"123",
 * ainsi que les liens directs des vues et des designs (type + identifiant).
 */
class auditsanteIndex {

  private static $memo = array();

  /** Vide le cache (début d'un audit ou d'une revérification). */
  public static function reset() {
    self::$memo = array();
  }

  private static function memo($_key, $_builder) {
    if (!array_key_exists($_key, self::$memo)) {
      self::$memo[$_key] = $_builder();
    }
    return self::$memo[$_key];
  }

  private static function query($_sql, $_values = array()) {
    $rows = DB::Prepare($_sql, $_values, DB::FETCH_TYPE_ALL);
    return is_array($rows) ? $rows : array();
  }

  /** Vrai si la table existe (les tables 3D, par exemple, peuvent manquer sur une vieille installation). */
  public static function tableExists($_table) {
    return self::memo('table::' . $_table, function () use ($_table) {
      $rows = self::query('SHOW TABLES LIKE :table', array('table' => $_table));
      return count($rows) > 0;
    });
  }

  /* ************************************************************************************ */
  /*                                   Inventaire                                         */
  /* ************************************************************************************ */

  /** Objets : id => {id, name, father_id, isVisible, configuration} */
  public static function objects() {
    return self::memo('objects', function () {
      $return = array();
      foreach (self::query('SELECT id, name, father_id, isVisible, configuration FROM object') as $row) {
        $return[$row['id']] = $row;
      }
      return $return;
    });
  }

  /** Équipements : id => {id, name, logicalId, object_id, eqType_name, isEnable, isVisible, configuration, status, timeout} */
  public static function eqLogics() {
    return self::memo('eqLogics', function () {
      $return = array();
      foreach (self::query('SELECT id, name, logicalId, object_id, eqType_name, isEnable, isVisible, configuration, status, timeout FROM eqLogic') as $row) {
        $return[$row['id']] = $row;
      }
      return $return;
    });
  }

  /** Commandes : id => {id, eqLogic_id, eqType, logicalId, name, type, subType, isHistorized, isVisible, value, configuration, template, generic_type} */
  public static function cmds() {
    return self::memo('cmds', function () {
      $return = array();
      foreach (self::query('SELECT id, eqLogic_id, eqType, logicalId, name, type, subType, isHistorized, isVisible, value, configuration, template, generic_type FROM cmd') as $row) {
        $return[$row['id']] = $row;
      }
      return $return;
    });
  }

  /** Identifiants des commandes de chaque équipement : eqLogic_id => [cmd ids] */
  public static function cmdsByEqLogic() {
    return self::memo('cmdsByEqLogic', function () {
      $return = array();
      foreach (self::cmds() as $id => $cmd) {
        $return[$cmd['eqLogic_id']][] = $id;
      }
      return $return;
    });
  }

  /** Scénarios (objets du core) : id => scenario */
  public static function scenarios() {
    return self::memo('scenarios', function () {
      $return = array();
      foreach (scenario::all() as $scenario) {
        $return[$scenario->getId()] = $scenario;
      }
      return $return;
    });
  }

  /** Plugins installés : id => {name, active} (y compris les plugins désactivés). */
  public static function plugins() {
    return self::memo('plugins', function () {
      $return = array();
      $active = array();
      foreach (self::query("SELECT plugin FROM config WHERE `key`='active' AND `value`='1'") as $row) {
        $active[$row['plugin']] = true;
      }
      foreach (plugin::listPlugin(false, false, true) as $plugin) {
        $return[$plugin->getId()] = array('name' => $plugin->getName(), 'active' => isset($active[$plugin->getId()]), 'plugin' => $plugin);
      }
      return $return;
    });
  }

  /* ************************************************************************************ */
  /*                                   Noms et liens                                      */
  /* ************************************************************************************ */

  public static function objectName($_id) {
    $objects = self::objects();
    return ($_id !== null && isset($objects[$_id])) ? $objects[$_id]['name'] : '';
  }

  /** [Objet][Équipement] */
  public static function eqLogicName($_id) {
    $eqLogics = self::eqLogics();
    if (!isset($eqLogics[$_id])) {
      return '#eqLogic' . $_id . '#';
    }
    $object = self::objectName($eqLogics[$_id]['object_id']);
    return '[' . ($object === '' ? __('Aucun', __FILE__) : $object) . '][' . $eqLogics[$_id]['name'] . ']';
  }

  /** [Objet][Équipement][Commande] */
  public static function cmdName($_id) {
    $cmds = self::cmds();
    if (!isset($cmds[$_id])) {
      return '#' . $_id . '#';
    }
    return self::eqLogicName($cmds[$_id]['eqLogic_id']) . '[' . $cmds[$_id]['name'] . ']';
  }

  /** [Groupe][Scénario] */
  public static function scenarioName($_id) {
    $scenarios = self::scenarios();
    if (!isset($scenarios[$_id])) {
      return '#scenario' . $_id . '#';
    }
    $scenario = $scenarios[$_id];
    return ($scenario->getGroup() != '' ? '[' . $scenario->getGroup() . ']' : '') . '[' . $scenario->getName() . ']';
  }

  public static function eqLogicUrl($_id) {
    $eqLogics = self::eqLogics();
    if (!isset($eqLogics[$_id])) {
      return null;
    }
    $type = $eqLogics[$_id]['eqType_name'];
    return 'index.php?v=d&m=' . urlencode($type) . '&p=' . urlencode($type) . '&id=' . $_id;
  }

  public static function cmdUrl($_id) {
    $cmds = self::cmds();
    return isset($cmds[$_id]) ? self::eqLogicUrl($cmds[$_id]['eqLogic_id']) : null;
  }

  public static function scenarioUrl($_id) {
    return 'index.php?v=d&p=scenario&id=' . $_id;
  }

  /** Nom lisible d'une référence : commande, équipement ou scénario. */
  public static function refName($_type, $_id) {
    switch ($_type) {
      case 'cmd':
        return self::cmdName($_id);
      case 'eqLogic':
        return self::eqLogicName($_id);
      case 'scenario':
        return self::scenarioName($_id);
    }
    return $_type . ' ' . $_id;
  }

  /* ************************************************************************************ */
  /*                                   Blocs des scénarios                                */
  /* ************************************************************************************ */

  /**
   * Contenu des scénarios lu en SQL : {texts: {scenario_id => texte}, elementCount: {scenario_id => n},
   * orphans: {elements, subElements, expressions}}.
   * Les blocs imbriqués (SI dans un SI...) sont rattachés à leur scénario via les expressions de type « element ».
   */
  public static function scenarioContent() {
    return self::memo('scenarioContent', function () {
      $elementScenario = array();
      $elementCount = array();
      foreach (self::scenarios() as $id => $scenario) {
        $elementCount[$id] = 0;
        $elements = $scenario->getScenarioElement();
        if (!is_array($elements)) {
          $elements = ($elements !== '' && $elements !== null && is_numeric($elements)) ? array($elements) : array();
        }
        foreach ($elements as $elementId) {
          $elementScenario[(int) $elementId] = $id;
          $elementCount[$id]++;
        }
      }
      $elements = array();
      foreach (self::query('SELECT id FROM scenarioElement') as $row) {
        $elements[(int) $row['id']] = true;
      }
      $subElements = array();
      foreach (self::query('SELECT id, scenarioElement_id FROM scenarioSubElement') as $row) {
        $subElements[(int) $row['id']] = (int) $row['scenarioElement_id'];
      }
      $expressions = self::query('SELECT id, scenarioSubElement_id, type, expression, options FROM scenarioExpression');
      // Blocs imbriqués : élément enfant => élément parent
      $parent = array();
      foreach ($expressions as $row) {
        if ($row['type'] == 'element' && is_numeric($row['expression']) && isset($subElements[(int) $row['scenarioSubElement_id']])) {
          $parent[(int) $row['expression']] = $subElements[(int) $row['scenarioSubElement_id']];
        }
      }
      // Propagation du scénario aux blocs imbriqués (profondeur quelconque)
      $changed = true;
      while ($changed) {
        $changed = false;
        foreach ($parent as $child => $father) {
          if (!isset($elementScenario[$child]) && isset($elementScenario[$father])) {
            $elementScenario[$child] = $elementScenario[$father];
            $changed = true;
          }
        }
      }
      $texts = array();
      $codes = '';
      $orphanExpressions = 0;
      foreach ($expressions as $row) {
        $subElement = (int) $row['scenarioSubElement_id'];
        $element = isset($subElements[$subElement]) ? $subElements[$subElement] : null;
        if ($element === null || !isset($elementScenario[$element])) {
          $orphanExpressions++;
          continue;
        }
        $scenarioId = $elementScenario[$element];
        if (!isset($texts[$scenarioId])) {
          $texts[$scenarioId] = '';
        }
        $texts[$scenarioId] .= ' ' . $row['expression'] . ' ' . $row['options'];
        if ($row['type'] == 'code') {
          $codes .= "\n" . $row['expression'];
        }
      }
      $orphanElements = 0;
      foreach ($elements as $id => $true) {
        if (!isset($elementScenario[$id])) {
          $orphanElements++;
        }
      }
      $orphanSubElements = 0;
      foreach ($subElements as $id => $element) {
        if (!isset($elementScenario[$element])) {
          $orphanSubElements++;
        }
      }
      return array(
        'texts' => $texts,
        'codes' => $codes,
        'elementCount' => $elementCount,
        'orphans' => array('elements' => $orphanElements, 'subElements' => $orphanSubElements, 'expressions' => $orphanExpressions),
      );
    });
  }

  /** Déclencheurs d'un scénario (liste de chaînes non vides). */
  public static function scenarioTriggers($_scenario) {
    $triggers = $_scenario->getTrigger();
    if (!is_array($triggers)) {
      $triggers = ($triggers === '' || $triggers === null) ? array() : array($triggers);
    }
    return array_values(array_filter(array_map('trim', array_map('strval', $triggers)), 'strlen'));
  }

  /** Programmations d'un scénario (liste de chaînes cron non vides). */
  public static function scenarioSchedules($_scenario) {
    $schedules = $_scenario->getSchedule();
    if (!is_array($schedules)) {
      $schedules = ($schedules === '' || $schedules === null) ? array() : array($schedules);
    }
    return array_values(array_filter(array_map('trim', array_map('strval', $schedules)), 'strlen'));
  }

  /** Vrai si le scénario peut se lancer tout seul (déclencheur ou programmation selon son mode). */
  public static function scenarioHasAutoStart($_scenario) {
    $mode = $_scenario->getMode();
    $hasTrigger = in_array($mode, array('provoke', 'all')) && count(self::scenarioTriggers($_scenario)) > 0;
    $hasSchedule = in_array($mode, array('schedule', 'all')) && count(self::scenarioSchedules($_scenario)) > 0;
    return $hasTrigger || $hasSchedule;
  }

  /* ************************************************************************************ */
  /*                                   Consommateurs                                      */
  /* ************************************************************************************ */

  /** Extrait les références d'un texte : {cmd: [ids], eqLogic: [ids], scenario: [ids]}. */
  public static function extract($_text) {
    $refs = array('cmd' => array(), 'eqLogic' => array(), 'scenario' => array());
    $text = (string) $_text;
    if ($text === '') {
      return $refs;
    }
    if (strpos($text, '#') !== false) {
      if (preg_match_all('/#(\d+)#/', $text, $matches)) {
        $refs['cmd'] = $matches[1];
      }
      if (preg_match_all('/#eqLogic(\d+)#/', $text, $matches)) {
        $refs['eqLogic'] = $matches[1];
      }
      if (preg_match_all('/#scenario(\d+)#/', $text, $matches)) {
        $refs['scenario'] = $matches[1];
      }
    }
    // Options JSON, éventuellement encodées deux fois (guillemets échappés)
    if (strpos($text, 'scenario_id') !== false && preg_match_all('/scenario_id\\\\?"\s*:\s*\\\\?"?(\d+)/', $text, $matches)) {
      $refs['scenario'] = array_merge($refs['scenario'], $matches[1]);
    }
    if (strpos($text, '"eqLogic') !== false && preg_match_all('/"eqLogic\\\\?"\s*:\s*\\\\?"?(\d+)/', $text, $matches)) {
      $refs['eqLogic'] = array_merge($refs['eqLogic'], $matches[1]);
    }
    foreach ($refs as $type => $ids) {
      $ids = array_unique(array_map('intval', $ids));
      $refs[$type] = array_values(array_filter($ids, function ($_id) {
        return $_id > 0;
      }));
    }
    return $refs;
  }

  /** Vrai si un texte peut contenir une référence ou une variable (évite d'indexer les configurations sans intérêt). */
  private static function mayReference($_text) {
    $text = (string) $_text;
    return strpos($text, '#') !== false || strpos($text, '_id') !== false || strpos($text, 'variable') !== false;
  }

  private static function mergeRefs($_a, $_b) {
    foreach ($_b as $type => $ids) {
      $_a[$type] = array_values(array_unique(array_merge(isset($_a[$type]) ? $_a[$type] : array(), $ids)));
    }
    return $_a;
  }

  private static function consumer($_kind, $_id, $_label, $_url, $_active, $_text, $_deadCheck = true, $_eqLogicId = null, $_direct = null) {
    $refs = self::extract($_text);
    if ($_direct !== null) {
      $refs = self::mergeRefs($refs, $_direct);
    }
    return array(
      'kind' => $_kind,
      'id' => $_id,
      'label' => $_label,
      'url' => $_url,
      'active' => (bool) $_active,
      'eqLogic_id' => $_eqLogicId,
      'deadCheck' => $_deadCheck,
      'text' => $_text,
      'refs' => $refs,
    );
  }

  /** Libellé d'un type de consommateur. */
  public static function kindName($_kind) {
    $names = array(
      'scenario' => __('Scénario', __FILE__),
      'eqLogic' => __('Équipement', __FILE__),
      'cmd' => __('Commande', __FILE__),
      'interact' => __('Interaction', __FILE__),
      'view' => __('Vue', __FILE__),
      'plan' => __('Design', __FILE__),
      'plan3d' => __('Design 3D', __FILE__),
      'object' => __('Objet', __FILE__),
      'config' => __('Configuration', __FILE__),
      'user' => __('Utilisateur', __FILE__),
    );
    return isset($names[$_kind]) ? $names[$_kind] : $_kind;
  }

  /** Tous les consommateurs : clé « kind:id » => consommateur. */
  public static function consumers() {
    return self::memo('consumers', function () {
      $consumers = array();
      $add = function ($_consumer) use (&$consumers) {
        $consumers[$_consumer['kind'] . ':' . $_consumer['id']] = $_consumer;
      };

      // Scénarios : déclencheurs + contenu des blocs
      $content = self::scenarioContent();
      foreach (self::scenarios() as $id => $scenario) {
        $text = implode(' ', self::scenarioTriggers($scenario)) . ' ' . (isset($content['texts'][$id]) ? $content['texts'][$id] : '');
        $add(self::consumer('scenario', $id, self::scenarioName($id), self::scenarioUrl($id), $scenario->getIsActive() == 1, $text));
      }

      // Équipements : configuration
      foreach (self::eqLogics() as $id => $eqLogic) {
        if (!self::mayReference($eqLogic['configuration'])) {
          continue;
        }
        $add(self::consumer('eqLogic', $id, self::eqLogicName($id), self::eqLogicUrl($id), $eqLogic['isEnable'] == 1, $eqLogic['configuration'], true, $id));
      }

      // Commandes : configuration (actions sur valeur, pré/post exécution, calculs) et valeur liée
      foreach (self::cmds() as $id => $cmd) {
        $text = (string) $cmd['configuration'];
        $direct = array('cmd' => array());
        $value = trim((string) $cmd['value']);
        if ($value !== '') {
          if (is_numeric($value)) {
            $direct['cmd'][] = (int) $value;
          } else {
            $text .= ' ' . $value;
          }
        }
        if (!self::mayReference($text) && count($direct['cmd']) == 0) {
          continue;
        }
        $eqLogics = self::eqLogics();
        $active = isset($eqLogics[$cmd['eqLogic_id']]) && $eqLogics[$cmd['eqLogic_id']]['isEnable'] == 1;
        $add(self::consumer('cmd', $id, self::cmdName($id), self::cmdUrl($id), $active, $text, true, $cmd['eqLogic_id'], $direct));
      }

      // Interactions
      foreach (self::query('SELECT id, name, `group`, enable, query, reply, actions, filtres, options FROM interactDef') as $row) {
        $label = ($row['group'] != '' ? '[' . $row['group'] . ']' : '') . '[' . ($row['name'] != '' ? $row['name'] : $row['query']) . ']';
        $text = $row['query'] . ' ' . $row['reply'] . ' ' . $row['actions'] . ' ' . $row['filtres'] . ' ' . $row['options'];
        $add(self::consumer('interact', $row['id'], $label, 'index.php?v=d&p=interact&id=' . $row['id'], $row['enable'] == 1, $text));
      }

      // Vues : liens directs + configuration des éléments et des zones
      $views = array();
      foreach (self::query('SELECT id, name FROM view') as $row) {
        $views[$row['id']] = array('name' => $row['name'], 'text' => '', 'direct' => array('cmd' => array(), 'eqLogic' => array(), 'scenario' => array()));
      }
      foreach (self::query('SELECT z.view_id, z.configuration AS zconf, d.type, d.link_id, d.configuration FROM viewZone z LEFT JOIN viewData d ON d.viewZone_id = z.id') as $row) {
        if (!isset($views[$row['view_id']])) {
          continue;
        }
        $views[$row['view_id']]['text'] .= ' ' . $row['configuration'] . ' ' . $row['zconf'];
        if (isset($views[$row['view_id']]['direct'][$row['type']]) && is_numeric($row['link_id'])) {
          $views[$row['view_id']]['direct'][$row['type']][] = (int) $row['link_id'];
        }
      }
      foreach ($views as $id => $view) {
        $add(self::consumer('view', $id, '[' . $view['name'] . ']', 'index.php?v=d&p=view&view_id=' . $id, true, $view['text'], true, null, $view['direct']));
      }

      // Designs et designs 3D
      foreach (array('plan' => array('planHeader', 'planHeader_id', 'plan_id'), 'plan3d' => array('plan3dHeader', 'plan3dHeader_id', 'plan3d_id')) as $table => $def) {
        if (!self::tableExists($table) || !self::tableExists($def[0])) {
          continue;
        }
        $headers = array();
        foreach (self::query('SELECT id, name FROM ' . $def[0]) as $row) {
          $headers[$row['id']] = array('name' => $row['name'], 'text' => '', 'direct' => array('cmd' => array(), 'eqLogic' => array(), 'scenario' => array()));
        }
        foreach (self::query('SELECT ' . $def[1] . ' AS header_id, link_type, link_id, display, configuration FROM ' . $table) as $row) {
          if (!isset($headers[$row['header_id']])) {
            continue;
          }
          $headers[$row['header_id']]['text'] .= ' ' . $row['display'] . ' ' . $row['configuration'];
          if (isset($headers[$row['header_id']]['direct'][$row['link_type']]) && is_numeric($row['link_id'])) {
            $headers[$row['header_id']]['direct'][$row['link_type']][] = (int) $row['link_id'];
          }
        }
        foreach ($headers as $id => $header) {
          $add(self::consumer($table, $id, '[' . $header['name'] . ']', 'index.php?v=d&p=' . $table . '&' . $def[2] . '=' . $id, true, $header['text'], true, null, $header['direct']));
        }
      }

      // Objets : résumés
      foreach (self::objects() as $id => $object) {
        if (strpos((string) $object['configuration'], '#') === false) {
          continue;
        }
        $add(self::consumer('object', $id, '[' . $object['name'] . ']', 'index.php?v=d&p=object&id=' . $id, true, $object['configuration']));
      }

      // Configuration du core (alertes, retour des interactions...) et des plugins
      $configs = array();
      foreach (self::query("SELECT plugin, `key`, `value` FROM config WHERE (`value` LIKE '%#%' OR `value` LIKE '%variable%') AND plugin NOT IN ('auditsante', 'auditsecurity')") as $row) {
        // Seules les clés du core qui désignent des commandes sont vérifiées (comme le fait le core) :
        // les autres valeurs peuvent contenir des # sans rapport avec une commande
        $group = ($row['plugin'] == 'core' && preg_match('/(cmd|Cmd)/', $row['key'])) ? 'core' : 'other:' . $row['plugin'];
        if (!isset($configs[$group])) {
          $configs[$group] = array('plugin' => $row['plugin'], 'text' => '');
        }
        $configs[$group]['text'] .= ' ' . $row['value'];
      }
      foreach ($configs as $group => $config) {
        $plugin = $config['plugin'];
        $label = ($plugin == 'core') ? __('Configuration de Jeedom', __FILE__) : sprintf(__('Configuration du plugin %s', __FILE__), $plugin);
        $url = ($plugin == 'core') ? 'index.php?v=d&p=administration' : 'index.php?v=d&p=plugin&id=' . urlencode($plugin);
        $add(self::consumer('config', $group, $label, $url, true, $config['text'], $group == 'core'));
      }

      // Utilisateurs (commandes de notification)
      foreach (self::query('SELECT id, login, options FROM user') as $row) {
        if (strpos((string) $row['options'], '#') === false) {
          continue;
        }
        $add(self::consumer('user', $row['id'], '[' . $row['login'] . ']', 'index.php?v=d&p=user', true, $row['options']));
      }

      return $consumers;
    });
  }

  /**
   * Index inverse : {cmd: {id => [clés de consommateurs]}, eqLogic: {...}, scenario: {...}}.
   * Un consommateur ne se référence pas lui-même.
   */
  public static function usedBy() {
    return self::memo('usedBy', function () {
      $index = array('cmd' => array(), 'eqLogic' => array(), 'scenario' => array());
      foreach (self::consumers() as $key => $consumer) {
        foreach ($consumer['refs'] as $type => $ids) {
          foreach ($ids as $id) {
            if ($type == $consumer['kind'] && $id == $consumer['id']) {
              continue;
            }
            $index[$type][$id][] = $key;
          }
        }
      }
      return $index;
    });
  }

  /** Consommateurs qui utilisent une référence. */
  public static function usersOf($_type, $_id) {
    $usedBy = self::usedBy();
    $consumers = self::consumers();
    $return = array();
    if (isset($usedBy[$_type][$_id])) {
      foreach (array_unique($usedBy[$_type][$_id]) as $key) {
        $return[$key] = $consumers[$key];
      }
    }
    return $return;
  }

  /**
   * Consommateurs extérieurs à un équipement qui utilisent l'équipement ou l'une de ses commandes
   * (la configuration de l'équipement lui-même et de ses propres commandes n'est pas comptée).
   */
  public static function externalUsersOfEqLogic($_eqLogicId) {
    $return = array();
    $byEq = self::cmdsByEqLogic();
    $lists = array(self::usersOf('eqLogic', $_eqLogicId));
    foreach ((isset($byEq[$_eqLogicId]) ? $byEq[$_eqLogicId] : array()) as $cmdId) {
      $lists[] = self::usersOf('cmd', $cmdId);
    }
    foreach ($lists as $list) {
      foreach ($list as $key => $consumer) {
        if ($consumer['eqLogic_id'] !== null && $consumer['eqLogic_id'] == $_eqLogicId) {
          continue;
        }
        $return[$key] = $consumer;
      }
    }
    return $return;
  }

  /** Vrai si la référence existe. */
  public static function exists($_type, $_id) {
    switch ($_type) {
      case 'cmd':
        $list = self::cmds();
        break;
      case 'eqLogic':
        $list = self::eqLogics();
        break;
      case 'scenario':
        $list = self::scenarios();
        break;
      default:
        return true;
    }
    return isset($list[$_id]);
  }

  /** Références introuvables : liste de {consumer (clé), type, id}. */
  public static function deadRefs() {
    return self::memo('deadRefs', function () {
      $return = array();
      foreach (self::consumers() as $key => $consumer) {
        if (!$consumer['deadCheck']) {
          continue;
        }
        foreach ($consumer['refs'] as $type => $ids) {
          foreach ($ids as $id) {
            if (!self::exists($type, $id)) {
              $return[] = array('consumer' => $key, 'type' => $type, 'id' => $id);
            }
          }
        }
      }
      return $return;
    });
  }

  /** Texte de tous les consommateurs, pour rechercher l'usage d'une variable. */
  public static function corpus() {
    return self::memo('corpus', function () {
      $text = '';
      foreach (self::consumers() as $consumer) {
        $text .= "\n" . $consumer['text'];
      }
      return $text;
    });
  }
}
