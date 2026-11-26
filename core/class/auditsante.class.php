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

require_once __DIR__ . '/../../../../core/php/core.inc.php';
require_once __DIR__ . '/auditsanteTools.class.php';
require_once __DIR__ . '/auditsanteStore.class.php';
require_once __DIR__ . '/auditsanteIndex.class.php';
require_once __DIR__ . '/auditsanteChecks.class.php';
require_once __DIR__ . '/auditsanteRecovery.class.php';
require_once __DIR__ . '/auditsanteUpload.class.php';

/**
 * Audit de santé de l'installation Jeedom : orphelins, références cassées, éléments oubliés, ressources.
 *
 * Le dernier rapport est stocké dans data/report.json (voir auditsanteStore) sous forme brute :
 * {date, duration, results: {<id>: {status, detail, items: [{text, url}], noFix}}}. La note est recalculée à chaque
 * lecture, ce qui permet d'ignorer un contrôle (data/ignored.json) sans relancer l'audit.
 * Un contrôle peut être ignoré pour une durée limitée ({<id>: {since, until, by}}, until null = sans limite)
 * et porter un commentaire (data/comments.json : {<id>: {text, date, by}}).
 *
 * Un équipement unique (logicalId « audit ») porte les commandes utilisables dans les scénarios :
 * note, lettre, nombre de problèmes, date du dernier audit et action « Lancer l'audit ».
 */
class auditsante extends eqLogic {

  const LOGICAL_ID = 'audit';

  /** Poids de chaque sévérité dans la note */
  const WEIGHTS = array('critical' => 20, 'high' => 10, 'medium' => 5, 'low' => 2, 'info' => 0);

  /** Seuils des lettres (note minimale) */
  const GRADES = array('A' => 90, 'B' => 80, 'C' => 65, 'D' => 50, 'E' => 35, 'F' => 0);

  public static $_widgetPossibility = array('custom' => true);

  /* ************************************************************************************ */
  /*                                   Cron                                               */
  /* ************************************************************************************ */

  /** Relevé horaire des noms (archive des noms des éléments, pour nommer plus tard ceux qui seront supprimés). */
  public static function cronHourly() {
    try {
      auditsanteRecovery::snapshot();
    } catch (Throwable $e) {
      log::add(__CLASS__, 'error', __('Relevé des noms en échec :', __FILE__) . ' ' . $e->getMessage());
    }
  }

  public static function cronDaily() {
    auditsanteUpload::clean();
    // Sauvegardes pas encore lues (premières sauvegardes, dossier ajouté) : lecture en tâche de fond
    try {
      if (!in_array(auditsanteRecovery::job()['status'], array('running', 'auditing')) && count(auditsanteRecovery::pendingBackups()) > 0) {
        auditsanteRecovery::startJob('cron');
      }
    } catch (Throwable $e) {
      log::add(__CLASS__, 'error', __('Lecture des sauvegardes en échec :', __FILE__) . ' ' . $e->getMessage());
    }
    // Contrôles dont la période d'ignorance est terminée : ils reviennent dans la note
    if (self::purgeExpiredIgnored() > 0) {
      self::updateCommands(self::getReport());
    }
    $frequency = config::byKey('autoAudit', __CLASS__, 'weekly');
    if ($frequency == 'daily' || ($frequency == 'weekly' && date('N') == 1)) {
      try {
        self::runAudit('cron');
      } catch (Throwable $e) {
        log::add(__CLASS__, 'error', __('Audit automatique en échec :', __FILE__) . ' ' . $e->getMessage());
      }
    }
  }

  /* ************************************************************************************ */
  /*                                   Audit                                              */
  /* ************************************************************************************ */

  /** Lance tous les contrôles, enregistre le rapport et met à jour les commandes. */
  public static function runAudit($_source = 'manual') {
    if (cache::byKey('auditsante::running')->getValue(0) == 1) {
      throw new Exception(__('Un audit est déjà en cours, patientez quelques secondes.', __FILE__));
    }
    cache::set('auditsante::running', 1, 300);
    try {
      $start = microtime(true);
      auditsanteIndex::reset();
      log::add(__CLASS__, 'info', 'Début de l\'audit (' . $_source . ')');
      try {
        auditsanteRecovery::snapshot();
      } catch (Throwable $e) {
        log::add(__CLASS__, 'error', 'Relevé des noms en échec : ' . $e->getMessage());
      }
      self::purgeExpiredIgnored();
      $previous = self::getReport();
      $results = array();
      if (self::isDebug()) {
        log::add(__CLASS__, 'debug', 'Environnement : ' . self::environmentSummary());
      }
      @set_time_limit(600);
      foreach (array_keys(auditsanteChecks::definitions()) as $id) {
        $results[$id] = self::runCheck($id);
      }
      $raw = array(
        'date' => date('Y-m-d H:i:s'),
        'duration' => round(microtime(true) - $start, 1),
        'results' => $results,
      );
      auditsanteStore::write('report', $raw);
      $report = self::getReport();
      // Le verrou n'est levé que dans le finally : le rapport renvoyé ne doit pas se dire « en cours »
      $report['running'] = false;
      log::add(__CLASS__, 'info', 'Audit terminé en ' . $raw['duration'] . ' s : ' . $report['score'] . ' % (' . $report['grade'] . ')');
      self::notifyChanges($previous, $report);
      self::updateCommands($report);
      return $report;
    } finally {
      cache::set('auditsante::running', 0);
    }
  }

  /** Exécute un contrôle en mesurant sa durée (visible dans le diagnostic et le log debug). */
  private static function runCheck($_id) {
    $start = microtime(true);
    log::add(__CLASS__, 'debug', '[' . $_id . '] début');
    $result = auditsanteChecks::run($_id);
    $result['ms'] = (int) round((microtime(true) - $start) * 1000);
    log::add(__CLASS__, 'debug', '[' . $_id . '] ' . $result['status'] . ' en ' . $result['ms'] . ' ms : ' . $result['detail']
      . (count($result['items']) > 0 ? ' | ' . implode(' ; ', array_map(array('auditsanteTools', 'itemText'), array_slice($result['items'], 0, 20))) : ''));
    return $result;
  }

  /* ************************************************************************************ */
  /*                                   Mode debug et diagnostic                           */
  /* ************************************************************************************ */

  /** Vrai si le log du plugin est en niveau Debug (réglage « Logs et surveillance » de la configuration). */
  public static function isDebug() {
    return intval(log::getLogLevel(__CLASS__)) == 100;
  }

  /** Version du plugin, lue dans plugin_info/info.json. */
  private static function pluginVersion() {
    $info = json_decode((string) @file_get_contents(__DIR__ . '/../../plugin_info/info.json'), true);
    return isset($info['pluginVersion']) ? $info['pluginVersion'] : '?';
  }

  /** Résumé de l'environnement d'exécution (une ligne). */
  private static function environmentSummary() {
    $volumes = array();
    foreach (array('object', 'eqLogic', 'cmd', 'scenario', 'interactDef', 'view', 'planHeader', 'dataStore') as $table) {
      $row = DB::Prepare('SELECT COUNT(*) AS n FROM `' . $table . '`', array(), DB::FETCH_TYPE_ROW);
      $volumes[] = $table . ' ' . (isset($row['n']) ? $row['n'] : '?');
    }
    return 'plugin ' . self::pluginVersion() . ', Jeedom ' . jeedom::version() . ' (' . jeedom::getHardwareName() . ')'
      . ', ' . php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m')
      . ', PHP ' . PHP_VERSION . ' (' . PHP_SAPI . '), mémoire ' . ini_get('memory_limit')
      . ', volumes : ' . implode(', ', $volumes);
  }

  /**
   * Rapport de diagnostic à joindre à une demande d'aide : environnement, options, résultat brut de chaque
   * contrôle et fin du log du plugin. Les données personnelles sont masquées (IP publiques, domaine externe,
   * comptes, adresses e-mail).
   */
  public static function getDiagnostic() {
    $start = microtime(true);
    $steps = array();
    // Chaque étape est écrite dans le log (mode debug) et renvoyée à la page pour la barre de progression
    $step = function ($_label) use ($start, &$steps) {
      $ms = (int) round((microtime(true) - $start) * 1000);
      $steps[] = array('label' => $_label, 'ms' => $ms);
      log::add('auditsante', 'debug', 'Diagnostic : ' . $_label . ' (' . $ms . ' ms)');
    };
    $step('début');
    $raw = self::getRawReport();
    $report = self::getReport();
    $step('rapport chargé (' . ($raw === null ? 'aucun audit' : count($report['checks']) . ' contrôles') . ')');
    $lines = array();
    $lines[] = '=== Audit Santé - rapport de diagnostic ===';
    $lines[] = 'Généré le ' . date('Y-m-d H:i:s');
    $lines[] = 'Environnement : ' . self::environmentSummary();
    $lines[] = 'Options : audit automatique ' . config::byKey('autoAudit', __CLASS__, 'weekly')
      . ', notifications ' . config::byKey('notifyNew', __CLASS__, 1)
      . ', seuils scénarios ' . config::byKey('staleDays', __CLASS__, 180) . ' j, équipements ' . config::byKey('silentDays', __CLASS__, 30) . ' j'
      . ', logs ' . config::byKey('logMaxMb', __CLASS__, 20) . ' Mo, messages ' . config::byKey('messageMax', __CLASS__, 50)
      . ', debug ' . (self::isDebug() ? 'oui' : 'non');
    $dataDir = auditsanteStore::dir();
    $files = array();
    foreach ((array) glob($dataDir . '/*.json') as $file) {
      $files[] = basename($file) . ' ' . auditsanteTools::formatBytes(filesize($file));
    }
    $archive = auditsanteRecovery::archiveInfo();
    $lines[] = 'Données : ' . (is_dir($dataDir) ? (is_writable($dataDir) ? 'dossier inscriptible' : 'dossier NON inscriptible') : 'dossier absent')
      . (count($files) > 0 ? ' (' . implode(', ', $files) . ')' : '')
      . ' ; archive des noms : ' . $archive['count'] . ' élément(s), ' . $archive['backups'] . ' sauvegarde(s) lue(s), ' . $archive['pending'] . ' à lire'
      . ', ' . $archive['extraDirs'] . ' dossier(s) supplémentaire(s)';
    foreach (auditsanteStore::read('archive-state')['indexed'] ?? array() as $key => $entry) {
      if (!empty($entry['read'])) {
        $lines[] = '  sauvegarde ' . explode('|', $key)[0] . ' : ' . (isset($entry['size']) ? auditsanteTools::formatBytes($entry['size']) . ', ' : '')
          . (isset($entry['seconds']) ? 'lue en ' . $entry['seconds'] . ' s' : 'durée inconnue') . ', ' . $entry['writes'] . ' élément(s) ajouté(s) ou modifié(s)';
      }
    }
    $step('environnement décrit');
    if ($raw === null) {
      $lines[] = 'Aucun audit enregistré.';
    } else {
      $lines[] = 'Dernier audit : ' . $raw['date'] . ' en ' . $raw['duration'] . ' s, note ' . $report['score'] . ' % (' . $report['grade'] . ')';
      $lines[] = '';
      $lines[] = '=== Contrôles ===';
      foreach ($report['checks'] as $check) {
        $result = isset($raw['results'][$check['id']]) ? $raw['results'][$check['id']] : array();
        $lines[] = sprintf('[%s] %s%s%s : %s', $check['id'], $check['status'], $check['ignored'] ? ' (ignoré)' : '',
          isset($result['ms']) ? ' ' . $result['ms'] . ' ms' : '', $check['detail']);
        foreach ($check['items'] as $item) {
          $lines[] = '    - ' . auditsanteTools::itemText($item);
        }
      }
    }
    $lines[] = '';
    $lines[] = '=== Fin du log auditsante ===';
    $log = log::getPathToLog(__CLASS__);
    if (file_exists($log)) {
      $content = (string) @file_get_contents($log);
      $tail = array_slice(explode("\n", rtrim($content)), -300);
      $lines = array_merge($lines, $tail);
      $step('log lu (' . strlen($content) . ' octets, ' . count($tail) . ' lignes gardées)');
    } else {
      $lines[] = 'Log absent (passez le log du plugin en Debug puis relancez l\'audit pour obtenir un log détaillé).';
      $step('log absent');
    }
    $text = self::redact(implode("\n", $lines)) . "\n";
    $step('données personnelles masquées');
    $step('terminé, ' . strlen($text) . ' octets renvoyés');
    return array('text' => $text, 'steps' => $steps);
  }

  /** Masque les données personnelles d'un texte destiné à être partagé. */
  private static function redact($_text) {
    // UTF-8 valide d'abord : sinon les expressions en mode /u renvoient null et le texte serait perdu
    $text = auditsanteTools::utf8($_text);
    // Applique une expression ; en cas d'échec (erreur PCRE), garde le texte précédent et le signale dans le log
    $apply = function ($_text, $_pattern, $_replacement) {
      $result = is_callable($_replacement) ? preg_replace_callback($_pattern, $_replacement, $_text) : preg_replace($_pattern, $_replacement, $_text);
      if ($result === null) {
        log::add('auditsante', 'error', 'Diagnostic : masquage impossible (' . (function_exists('preg_last_error_msg') ? preg_last_error_msg() : preg_last_error()) . ')');
        return $_text;
      }
      return $result;
    };
    // Domaine de l'accès externe
    $external = preg_replace('#[/:].*$#', '', trim(config::byKey('externalAddr')));
    if ($external != '') {
      $text = str_ireplace($external, '[domaine-externe]', $text);
    }
    // Identifiants des comptes Jeedom (les comptes génériques restent visibles, ils aident au diagnostic)
    $number = 0;
    foreach (user::all() as $user) {
      $login = $user->getLogin();
      if (strlen($login) < 3 || in_array(strtolower($login), array('admin', 'root', 'jeedom', 'pi', 'jeedom_support', 'internal_report'))) {
        continue;
      }
      $number++;
      $text = $apply($text, '/(?<![\w.-])' . preg_quote($login, '/') . '(?![\w-])/u', '[compte-' . $number . ']');
    }
    // Adresses e-mail (quantificateurs possessifs : pas de retour arrière coûteux sur les longs mots)
    $text = $apply($text, '/[\w.+-]++@[\w-]++(?:\.[\w-]++)+/u', '[email]');
    // Adresses IPv4 publiques (les adresses locales restent utiles au diagnostic)
    $text = $apply($text, '/\b(\d{1,3}(?:\.\d{1,3}){3})\b/', function ($_m) {
      return (filter_var($_m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !auditsanteTools::isNonPublicIp($_m[1])) ? '[ip-publique]' : $_m[1];
    });
    return $text;
  }

  /** Relance un seul contrôle (après une correction) et met à jour le rapport. */
  public static function rerunCheck($_id) {
    self::assertCheck($_id);
    if (self::getRawReport() === null) {
      throw new Exception(__('Aucun rapport : lancez d\'abord l\'audit.', __FILE__));
    }
    auditsanteIndex::reset();
    self::mergeResults(array($_id => self::runCheck($_id)));
    $report = self::getReport();
    self::updateCommands($report);
    return $report;
  }

  /**
   * Remplace des résultats dans le rapport enregistré, sous verrou : un audit complet écrit en même temps
   * (tâche planifiée, autre onglet) n'est pas écrasé par un rapport relu avant lui.
   */
  private static function mergeResults($_results) {
    auditsanteStore::update('report', function ($_raw) use ($_results) {
      if (!isset($_raw['results'])) {
        return null;
      }
      foreach ($_results as $id => $result) {
        $_raw['results'][$id] = $result;
      }
      return $_raw;
    });
  }

  /** Tâche de fond de la lecture des sauvegardes (lancée par auditsanteRecovery::startJob()). */
  public static function recoverJob($_options = null) {
    auditsanteRecovery::runJob();
  }

  /**
   * Fin de la recherche des noms dans les sauvegardes : revérifie les contrôles qui les affichent.
   * Retourne {report, known, unknown}.
   */
  public static function recoverFinish() {
    $raw = self::getRawReport();
    if ($raw === null) {
      // Aucun rapport à mettre à jour : l'audit n'est jamais lancé automatiquement, l'utilisateur le lance
      return array('report' => self::getReport());
    }
    auditsanteIndex::reset();
    $results = array();
    foreach (auditsanteRecovery::CHECKS as $id) {
      $results[$id] = self::runCheck($id);
    }
    self::mergeResults($results);
    $report = self::getReport();
    self::updateCommands($report);
    return array('report' => $report);
  }

  /** Applique la correction automatique d'un contrôle puis le revérifie. */
  public static function applyFix($_id) {
    $definitions = auditsanteChecks::definitions();
    if (!isset($definitions[$_id]) || !is_array($definitions[$_id]['fix']) || $definitions[$_id]['fix']['type'] != 'auto') {
      throw new Exception(__('Aucune correction automatique pour ce contrôle', __FILE__) . ' : ' . $_id);
    }
    $message = auditsanteChecks::fix($_id);
    return array('message' => $message, 'report' => self::rerunCheck($_id));
  }

  /** Durées proposées pour ignorer un contrôle, en jours (0 = sans limite). */
  const IGNORE_DAYS = array(7, 30, 90, 365, 0);

  /** Longueur maximale d'un commentaire */
  const COMMENT_MAX = 1000;

  /**
   * Ignore (choix assumé) ou réintègre un contrôle dans la note.
   * $_days : durée en jours (0 = sans limite). $_comment : raison facultative, enregistrée comme commentaire
   * (null = commentaire inchangé).
   */
  public static function setIgnored($_id, $_ignored, $_days = 0, $_comment = null, $_by = '') {
    self::assertCheck($_id);
    $days = (int) $_days;
    if ($_ignored && !in_array($days, self::IGNORE_DAYS, true)) {
      throw new Exception(__('Durée invalide', __FILE__) . ' : ' . $_days);
    }
    auditsanteStore::update('ignored', function ($_ignoredList) use ($_id, $_ignored, $days, $_by) {
      if ($_ignored) {
        $_ignoredList[$_id] = array(
          'since' => date('Y-m-d H:i:s'),
          'until' => ($days > 0) ? date('Y-m-d H:i:s', strtotime('+' . $days . ' days')) : null,
          'by' => (string) $_by,
        );
      } else {
        unset($_ignoredList[$_id]);
      }
      return $_ignoredList;
    });
    if ($_ignored && $_comment !== null) {
      self::saveComment($_id, $_comment, $_by);
    }
    $report = self::getReport();
    self::updateCommands($report);
    return $report;
  }

  /** Longueur maximale de la clé d'un élément exclu (nom de variable : 255 caractères dans la base Jeedom) */
  const EXCLUDE_KEY_MAX = 255;

  /**
   * Exclut un élément d'un contrôle (l'utilisateur sait qu'il sert, l'audit ne le voit pas) ou le réintègre,
   * puis revérifie ce contrôle. Seuls les contrôles dont la définition porte « exclude » l'acceptent.
   */
  public static function setExcluded($_id, $_key, $_excluded, $_by = '') {
    self::assertCheck($_id);
    $definitions = auditsanteChecks::definitions();
    if (empty($definitions[$_id]['exclude'])) {
      throw new Exception(__('Ce contrôle ne permet pas d\'exclure un élément', __FILE__) . ' : ' . $_id);
    }
    $key = (string) $_key;
    if ($key === '' || strlen($key) > self::EXCLUDE_KEY_MAX) {
      throw new Exception(__('Élément invalide', __FILE__));
    }
    auditsanteStore::update('excluded', function ($_list) use ($_id, $key, $_excluded, $_by) {
      if ($_excluded) {
        $_list[$_id][$key] = array('since' => date('Y-m-d H:i:s'), 'by' => (string) $_by);
      } else {
        unset($_list[$_id][$key]);
        if (empty($_list[$_id])) {
          unset($_list[$_id]);
        }
      }
      return $_list;
    });
    return self::rerunCheck($_id);
  }

  /** Enregistre (ou supprime si vide) le commentaire d'un contrôle (nom distinct de eqLogic::setComment, méthode d'instance du core). */
  public static function setCheckComment($_id, $_text, $_by = '') {
    self::assertCheck($_id);
    self::saveComment($_id, $_text, $_by);
    return self::getReport();
  }

  private static function saveComment($_id, $_text, $_by) {
    $text = trim(str_replace("\r\n", "\n", (string) $_text));
    if (function_exists('mb_substr')) {
      $text = mb_substr($text, 0, self::COMMENT_MAX);
    } else {
      $text = substr($text, 0, self::COMMENT_MAX);
    }
    auditsanteStore::update('comments', function ($_comments) use ($_id, $text, $_by) {
      if ($text === '') {
        unset($_comments[$_id]);
      } else {
        $_comments[$_id] = array('text' => $text, 'date' => date('Y-m-d H:i:s'), 'by' => (string) $_by);
      }
      return $_comments;
    });
  }

  private static function assertCheck($_id) {
    if (!array_key_exists($_id, auditsanteChecks::definitions())) {
      throw new Exception(__('Contrôle inconnu', __FILE__) . ' : ' . $_id);
    }
  }

  /** Contrôles ignorés, tous (y compris expirés), au format {since, until, by}. */
  private static function readIgnored() {
    $ignored = array();
    foreach (self::configArray('ignored') as $id => $entry) {
      // Anciennes versions : seule la date du jour où le contrôle a été ignoré, sans limite
      if (!is_array($entry)) {
        $entry = array('since' => (string) $entry, 'until' => null, 'by' => '');
      }
      $ignored[$id] = array_merge(array('since' => '', 'until' => null, 'by' => ''), $entry);
    }
    return $ignored;
  }

  private static function isExpired($_entry) {
    return !empty($_entry['until']) && strtotime($_entry['until']) <= time();
  }

  /** Contrôles ignorés dont la période n'est pas terminée. */
  private static function getIgnored() {
    return array_filter(self::readIgnored(), function ($_entry) {
      return !self::isExpired($_entry);
    });
  }

  /** Retire les périodes d'ignorance terminées et le signale. Retourne le nombre de contrôles réintégrés. */
  public static function purgeExpiredIgnored() {
    $ignored = self::readIgnored();
    $definitions = auditsanteChecks::definitions();
    $titles = array();
    foreach ($ignored as $id => $entry) {
      if (self::isExpired($entry)) {
        unset($ignored[$id]);
        if (isset($definitions[$id])) {
          $titles[] = $definitions[$id]['title'];
        }
      }
    }
    if (count($titles) > 0) {
      $expired = array_keys(array_diff_key(self::readIgnored(), $ignored));
      auditsanteStore::update('ignored', function ($_ignoredList) use ($expired) {
        foreach ($expired as $id) {
          unset($_ignoredList[$id]);
        }
        return $_ignoredList;
      });
      message::add(__CLASS__, sprintf(__('Audit de santé : fin de la période d\'ignorance, contrôle(s) de nouveau pris en compte dans la note : %s', __FILE__), implode(', ', $titles)), '', 'auditsante::ignoreExpired');
    }
    return count($titles);
  }

  /** Lit un fichier de données du plugin (report, ignored, comments) : voir auditsanteStore. */
  private static function configArray($_key) {
    return auditsanteStore::read($_key);
  }

  private static function getRawReport() {
    $raw = self::configArray('report');
    return isset($raw['results']) ? $raw : null;
  }

  /**
   * Rapport pour la page du plugin : rapport, plus l'état de la lecture des sauvegardes et de l'archive des noms.
   * Plus coûteux (lecture de l'archive, liste des sauvegardes) : réservé à la page, pas au widget ni aux tâches.
   */
  public static function getFullReport($_report = null) {
    $report = ($_report === null) ? self::getReport() : $_report;
    $report['recoverJob'] = auditsanteRecovery::job();
    $report['namesArchive'] = auditsanteRecovery::archiveInfo();
    return $report;
  }

  /**
   * Rapport : contrôles (définition + résultat), note, lettre, compteurs.
   * Retourne un rapport vide (date null) si aucun audit n'a encore été lancé.
   */
  public static function getReport() {
    $raw = self::getRawReport();
    $ignored = self::getIgnored();
    $comments = self::configArray('comments');
    $categories = auditsanteChecks::categories();
    $checks = array();
    $counts = array('pass' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0, 'skip' => 0, 'ignored' => 0);
    $earned = 0;
    $possible = 0;
    $serious = 0;
    $criticalFail = false;
    $catScores = array();

    foreach (auditsanteChecks::definitions() as $id => $def) {
      $result = ($raw !== null && isset($raw['results'][$id])) ? $raw['results'][$id] : null;
      $status = ($result === null) ? 'pending' : $result['status'];
      $isIgnored = isset($ignored[$id]);
      $fix = $def['fix'];
      if (is_array($fix) && $fix['type'] == 'auto' && (!auditsanteChecks::hasAutoFix($id) || !empty($result['noFix']))) {
        $fix = null;
      }
      $checks[] = array(
        'id' => $id,
        'category' => $def['category'],
        'severity' => $def['severity'],
        'title' => $def['title'],
        'description' => $def['description'],
        'recommendation' => $def['recommendation'],
        'fix' => $fix,
        'recover' => in_array($id, auditsanteRecovery::CHECKS),
        // Éléments supprimés encore sans nom (null : rapport d'une version précédente, inconnu)
        'unnamed' => ($result !== null && isset($result['unnamed'])) ? (int) $result['unnamed'] : null,
        'notFound' => ($result !== null && isset($result['notFound'])) ? (int) $result['notFound'] : 0,
        'status' => $status,
        'detail' => ($result === null) ? '' : $result['detail'],
        'items' => ($result === null) ? array() : $result['items'],
        'excludable' => !empty($def['exclude']),
        'excluded' => ($result !== null && isset($result['excluded'])) ? $result['excluded'] : array(),
        'ignored' => $isIgnored,
        'ignoredSince' => $isIgnored ? $ignored[$id]['since'] : null,
        'ignoredUntil' => $isIgnored ? $ignored[$id]['until'] : null,
        'comment' => (isset($comments[$id]['text']) && $comments[$id]['text'] !== '') ? $comments[$id] : null,
      );
      if ($result === null) {
        continue;
      }
      if ($isIgnored) {
        $counts['ignored']++;
        continue;
      }
      $counts[$status]++;
      $weight = self::WEIGHTS[$def['severity']];
      if ($weight == 0 || !in_array($status, array('pass', 'warn', 'fail'))) {
        continue;
      }
      $gain = ($status == 'pass') ? $weight : (($status == 'warn') ? $weight / 2 : 0);
      $possible += $weight;
      $earned += $gain;
      if (!isset($catScores[$def['category']])) {
        $catScores[$def['category']] = array('earned' => 0, 'possible' => 0);
      }
      $catScores[$def['category']]['possible'] += $weight;
      $catScores[$def['category']]['earned'] += $gain;
      if ($status == 'fail' && in_array($def['severity'], array('critical', 'high'))) {
        $serious++;
      }
      if ($status == 'fail' && $def['severity'] == 'critical') {
        $criticalFail = true;
      }
    }

    $score = ($possible > 0) ? (int) round(100 * $earned / $possible) : 100;
    $grade = self::gradeFor($score);
    // Une faille critique plafonne la lettre à D
    if ($criticalFail && in_array($grade, array('A', 'B', 'C'))) {
      $grade = 'D';
    }
    foreach ($categories as $id => &$category) {
      $category['score'] = isset($catScores[$id]) ? (int) round(100 * $catScores[$id]['earned'] / $catScores[$id]['possible']) : null;
    }
    unset($category);

    return array(
      'date' => ($raw === null) ? null : $raw['date'],
      'duration' => ($raw === null) ? null : $raw['duration'],
      'score' => ($raw === null) ? null : $score,
      'grade' => ($raw === null) ? null : $grade,
      'criticalCap' => $criticalFail,
      'serious' => $serious,
      'counts' => $counts,
      'categories' => $categories,
      'checks' => $checks,
      'running' => (cache::byKey('auditsante::running')->getValue(0) == 1),
    );
  }

  public static function gradeFor($_score) {
    foreach (self::GRADES as $grade => $min) {
      if ($_score >= $min) {
        return $grade;
      }
    }
    return 'F';
  }

  /** Verdict en clair associé à une lettre. */
  public static function verdict($_grade) {
    $verdicts = array(
      'A' => __('En pleine forme', __FILE__),
      'B' => __('Bon état général', __FILE__),
      'C' => __('Un peu de ménage à faire', __FILE__),
      'D' => __('Entretien nécessaire', __FILE__),
      'E' => __('Mauvais état', __FILE__),
      'F' => __('État critique', __FILE__),
    );
    return isset($verdicts[$_grade]) ? $verdicts[$_grade] : __('Pas encore évalué', __FILE__);
  }

  /** Date courte et lisible : « Aujourd'hui à 00:45 », « Hier à 18:10 » ou « 21/11/2026 18:10 ». */
  private static function relativeDate($_date) {
    $time = strtotime($_date);
    if (date('Y-m-d', $time) == date('Y-m-d')) {
      return sprintf(__('Aujourd\'hui à %s', __FILE__), date('H:i', $time));
    }
    if (date('Y-m-d', $time) == date('Y-m-d', strtotime('-1 day'))) {
      return sprintf(__('Hier à %s', __FILE__), date('H:i', $time));
    }
    return date('d/m/Y H:i', $time);
  }

  public static function gradeColor($_grade) {
    // Mêmes couleurs que la page du plugin (desktop/js/auditsante.js)
    $colors = array('A' => '#22a861', 'B' => '#7cb342', 'C' => '#e0a800', 'D' => '#f08c2a', 'E' => '#e5532d', 'F' => '#d32f2f');
    return isset($colors[$_grade]) ? $colors[$_grade] : '#8a8a8a';
  }

  /** Message Jeedom quand des problèmes apparaissent par rapport à l'audit précédent. */
  private static function notifyChanges($_previous, $_report) {
    if (config::byKey('notifyNew', __CLASS__, 1) != 1 || $_previous['date'] === null) {
      return;
    }
    $before = array();
    foreach ($_previous['checks'] as $check) {
      $before[$check['id']] = $check['status'];
    }
    $new = array();
    foreach ($_report['checks'] as $check) {
      if ($check['ignored'] || !in_array($check['status'], array('fail', 'warn'))) {
        continue;
      }
      $old = isset($before[$check['id']]) ? $before[$check['id']] : 'pending';
      if ($old == 'pass' || ($old == 'warn' && $check['status'] == 'fail')) {
        $new[] = $check['title'];
      }
    }
    if (count($new) > 0) {
      message::add(__CLASS__, sprintf(__('Audit de santé : note %d %% (%s). Nouveau(x) problème(s) : %s', __FILE__), $_report['score'], $_report['grade'], implode(', ', $new)), '', 'auditsante::new');
    }
  }

  /* ************************************************************************************ */
  /*                                   Équipement                                         */
  /* ************************************************************************************ */

  /** Retourne l'équipement de l'audit, en le créant s'il n'existe pas. */
  public static function getAuditEqLogic() {
    $eqLogic = self::byLogicalId(self::LOGICAL_ID, __CLASS__);
    if (!is_object($eqLogic)) {
      $eqLogic = new self();
      $eqLogic->setName(__('Audit santé', __FILE__));
      $eqLogic->setLogicalId(self::LOGICAL_ID);
      $eqLogic->setEqType_name(__CLASS__);
      $eqLogic->setIsEnable(1);
      $eqLogic->setIsVisible(1);
      $eqLogic->setCategory('default', 1);
      $eqLogic->save();
    }
    return $eqLogic;
  }

  /** Commandes de l'équipement : logicalId => {nom, type, sous-type, options} */
  private static function commandDefinitions() {
    return array(
      'score' => array('name' => __('Note', __FILE__), 'type' => 'info', 'subType' => 'numeric', 'unite' => '%', 'historized' => 1, 'min' => 0, 'max' => 100),
      'grade' => array('name' => __('Lettre', __FILE__), 'type' => 'info', 'subType' => 'string'),
      'serious' => array('name' => __('Problèmes graves', __FILE__), 'type' => 'info', 'subType' => 'numeric', 'historized' => 1),
      'failures' => array('name' => __('À corriger', __FILE__), 'type' => 'info', 'subType' => 'numeric'),
      'warnings' => array('name' => __('À vérifier', __FILE__), 'type' => 'info', 'subType' => 'numeric'),
      'lastAudit' => array('name' => __('Dernier audit', __FILE__), 'type' => 'info', 'subType' => 'string'),
      'summary' => array('name' => __('Résumé', __FILE__), 'type' => 'info', 'subType' => 'string'),
      'refresh' => array('name' => __('Lancer l\'audit', __FILE__), 'type' => 'action', 'subType' => 'other'),
    );
  }

  public function postSave() {
    $order = 0;
    foreach (self::commandDefinitions() as $logicalId => $def) {
      $cmd = $this->getCmd(null, $logicalId);
      if (!is_object($cmd)) {
        $cmd = new auditsanteCmd();
        $cmd->setLogicalId($logicalId);
        $cmd->setEqLogic_id($this->getId());
        $cmd->setName($def['name']);
        $cmd->setType($def['type']);
        $cmd->setSubType($def['subType']);
        $cmd->setOrder($order);
        $cmd->setIsVisible(1);
        if (isset($def['unite'])) {
          $cmd->setUnite($def['unite']);
        }
        if (isset($def['historized'])) {
          $cmd->setIsHistorized($def['historized']);
        }
        if (isset($def['min'])) {
          $cmd->setConfiguration('minValue', $def['min']);
          $cmd->setConfiguration('maxValue', $def['max']);
        }
        $cmd->save();
      } elseif (isset($def['unite']) && $cmd->getUnite() == '/100') {
        // Migration : la note était exprimée sur 100, elle l'est désormais en pourcentage
        $cmd->setUnite($def['unite']);
        $cmd->save();
      }
      $order++;
    }
  }

  /** Met à jour les commandes info de l'équipement à partir du rapport. */
  public static function updateCommands($_report) {
    if ($_report['date'] === null) {
      return;
    }
    $eqLogic = self::getAuditEqLogic();
    $summary = sprintf(__('%d %% (%s) : %d à corriger, %d à vérifier', __FILE__), $_report['score'], $_report['grade'], $_report['counts']['fail'], $_report['counts']['warn']);
    $values = array(
      'score' => $_report['score'],
      'grade' => $_report['grade'],
      'serious' => $_report['serious'],
      'failures' => $_report['counts']['fail'],
      'warnings' => $_report['counts']['warn'],
      'lastAudit' => $_report['date'],
      'summary' => $summary,
    );
    foreach ($values as $logicalId => $value) {
      $eqLogic->checkAndUpdateCmd($logicalId, $value);
    }
    $eqLogic->refreshWidget();
  }

  public function toHtml($_version = 'dashboard') {
    $replace = $this->preToHtml($_version);
    if (!is_array($replace)) {
      return $replace;
    }
    $version = jeedom::versionAlias($_version);
    $report = self::getReport();
    $refresh = $this->getCmd('action', 'refresh');
    $replace['#refresh_id#'] = is_object($refresh) ? $refresh->getId() : '';
    $replace['#grade#'] = ($report['grade'] === null) ? '?' : $report['grade'];
    $replace['#grade_color#'] = self::gradeColor($report['grade']);
    $replace['#score#'] = ($report['score'] === null) ? '-' : $report['score'];
    // Anneau de la jauge : circonférence 2πr avec r = 26
    $circumference = 2 * M_PI * 26;
    $replace['#ring_length#'] = round($circumference, 2);
    $replace['#ring_offset#'] = round($circumference * (1 - (($report['score'] === null) ? 0 : $report['score']) / 100), 2);
    $replace['#verdict#'] = self::verdict($report['grade']);
    foreach (array('fail', 'warn', 'pass') as $status) {
      $replace['#' . $status . '#'] = $report['counts'][$status];
      $replace['#' . $status . '_class#'] = ($report['counts'][$status] == 0) ? 'is-zero' : '';
    }
    $replace['#date#'] = ($report['date'] === null) ? __('Jamais lancé', __FILE__) : self::relativeDate($report['date']);
    $replace['#plugin_link#'] = 'index.php?v=d&m=auditsante&p=auditsante';
    // Le core ne traduit pas les templates d'équipement : traduction explicite des {{...}}
    $template = translate::exec(getTemplate('core', $version, 'auditsante', __CLASS__), 'plugins/auditsante/core/template/' . $version . '/auditsante.html');
    return $this->postToHtml($_version, template_replace($replace, $template));
  }
}

class auditsanteCmd extends cmd {

  public static $_widgetPossibility = array('custom' => false);

  public function dontRemoveCmd() {
    return true;
  }

  public function execute($_options = array()) {
    if ($this->getLogicalId() == 'refresh') {
      auditsante::runAudit('cmd');
    }
  }
}
