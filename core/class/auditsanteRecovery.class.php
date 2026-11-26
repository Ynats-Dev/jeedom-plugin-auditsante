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
require_once __DIR__ . '/auditsanteStore.class.php';
require_once __DIR__ . '/auditsanteIndex.class.php';
require_once __DIR__ . '/auditsanteUpload.class.php';

/**
 * Archive des noms : garde le nom de chaque commande, équipement, objet et scénario, pour nommer plus tard
 * les références vers des éléments supprimés (#1234#, #scenario250#...) et dater leur suppression.
 *
 * Fichier data/names.json : {"type:id" => {type, id, name, first_seen, last_seen, gone_seen, live, source}}
 *   - live = 1 : l'élément existe dans Jeedom (vu au dernier relevé) ;
 *   - last_seen : dernière date où il a été vu (renseignée quand il disparaît, ou par une sauvegarde) ;
 *   - gone_seen : première date connue où il n'existait plus (relevé ou sauvegarde plus récente).
 * Fichier data/archive-state.json : {snapshot, firstSnapshot, indexed: {clé de sauvegarde => {...}}, job: {...}}
 *
 * Alimentation :
 *   1. relevé de la base en direct, toutes les heures (snapshot()) : le fichier n'est réécrit que si un nom change ;
 *   2. timeline : nom des scénarios et commandes qui y écrivaient, supprimés avant l'installation du plugin ;
 *   3. sauvegardes Jeedom (dossier des sauvegardes et dossiers supplémentaires de la configuration) : chacune
 *      n'est lue qu'une fois. Seules les tables cmd, eqLogic, object et scenario de DB_backup.sql sont lues,
 *      en flux. Les sauvegardes postérieures au premier relevé sont ignorées : le relevé horaire en dit plus.
 *
 * La lecture des sauvegardes tourne en tâche de fond (tâche ponctuelle du moteur de tâches) : elle continue
 * si l'on quitte la page.
 */
class auditsanteRecovery {

  /** Tables lues (en direct ou dans les sauvegardes) et colonnes gardées */
  const TABLES = array(
    'cmd' => array('id', 'eqLogic_id', 'name'),
    'eqLogic' => array('id', 'name', 'object_id', 'eqType_name'),
    'object' => array('id', 'name'),
    'scenario' => array('id', 'name', 'group'),
  );

  /** Contrôles dont les éléments peuvent être nommés grâce à l'archive */
  const CHECKS = array('broken_scenarios', 'broken_equipments', 'broken_interface', 'broken_interactions', 'broken_config', 'cmd_orphan', 'history_orphan');

  /** Délai sans nouvelles au-delà duquel une lecture dont la tâche ne tourne plus est déclarée interrompue */
  const JOB_STALE = 120;

  /* ************************************************************************************ */
  /*                                   Fichiers                                           */
  /* ************************************************************************************ */

  /** État de l'archive : {snapshot, firstSnapshot, indexed, job} */
  private static function state() {
    return array_merge(array('snapshot' => null, 'firstSnapshot' => null, 'indexed' => array(), 'job' => array()), auditsanteStore::read('archive-state'));
  }

  /** Modifie l'état sous verrou : $_callback reçoit l'état et le retourne modifié. */
  private static function updateState($_callback) {
    self::$complete = null;
    return auditsanteStore::update('archive-state', function ($_state) use ($_callback) {
      return $_callback(array_merge(array('snapshot' => null, 'firstSnapshot' => null, 'indexed' => array(), 'job' => array()), $_state));
    });
  }

  /**
   * Intègre des tables à l'archive sous verrou : le fichier n'est réécrit que si quelque chose change.
   * Retourne le nombre d'éléments modifiés.
   */
  private static function applyToArchive($_tables, $_date, $_live, $_source, $_previous = null) {
    $writes = 0;
    auditsanteStore::update('names', function ($_names) use ($_tables, $_date, $_live, $_source, $_previous, &$writes) {
      $writes = self::applyTables($_names, $_tables, $_date, $_live, $_source, $_previous);
      return ($writes > 0) ? $_names : null;
    });
    self::$lookup = null;
    return $writes;
  }

  /* ************************************************************************************ */
  /*                                   Alimentation                                       */
  /* ************************************************************************************ */

  private static function query($_sql, $_values = array()) {
    $rows = DB::Prepare($_sql, $_values, DB::FETCH_TYPE_ALL);
    return is_array($rows) ? $rows : array();
  }

  /** Tables utiles de la base en direct, au même format que celles lues dans une sauvegarde. */
  private static function liveTables() {
    $tables = array();
    foreach (self::TABLES as $table => $columns) {
      $tables[$table] = array();
      foreach (self::query('SELECT `' . implode('`, `', $columns) . '` FROM `' . $table . '`') as $row) {
        $tables[$table][(int) $row['id']] = $row;
      }
    }
    return $tables;
  }

  /**
   * Intègre un état de la base à une date donnée : éléments présents (nom, dates) et absents (date de disparition).
   * $_live : relevé de la base en direct (sinon : sauvegarde). Modifie $_names, retourne le nombre de changements.
   */
  private static function applyTables(&$_names, $_tables, $_date, $_live, $_source, $_previous = null) {
    $present = array();
    $writes = 0;
    foreach (array_keys(self::TABLES) as $type) {
      foreach (array_keys($_tables[$type]) as $id) {
        $key = $type . ':' . $id;
        $present[$key] = true;
        $name = self::nameIn($_tables, $type, $id);
        if ($name === null) {
          continue;
        }
        if (!isset($_names[$key])) {
          $_names[$key] = array('type' => $type, 'id' => (int) $id, 'name' => $name, 'first_seen' => $_date, 'last_seen' => $_date, 'gone_seen' => null, 'live' => $_live ? 1 : 0, 'source' => $_source);
          $writes++;
          continue;
        }
        $row = $_names[$key];
        if ($_live) {
          // En direct : nom courant, élément vivant. last_seen n'est pas réécrit à chaque relevé
          $row['name'] = $name;
          $row['live'] = 1;
          $row['gone_seen'] = null;
        } else {
          // Sauvegarde : le nom le plus récent l'emporte pour un élément disparu ; les dates s'élargissent
          if ($row['live'] != 1 && ($row['last_seen'] === null || $_date > $row['last_seen'])) {
            $row['last_seen'] = $_date;
            $row['name'] = $name;
          }
          if ($row['first_seen'] === null || $_date < $row['first_seen']) {
            $row['first_seen'] = $_date;
          }
          if ($row['gone_seen'] !== null && $row['gone_seen'] <= $_date) {
            $row['gone_seen'] = null;
          }
        }
        if ($row != $_names[$key]) {
          $_names[$key] = $row;
          $writes++;
        }
      }
    }
    // Éléments connus absents de cet état
    foreach ($_names as $key => $row) {
      if (isset($present[$key])) {
        continue;
      }
      if ($_live) {
        if ($row['live'] == 1) {
          $last = ($_previous !== null && $_previous > (string) $row['last_seen']) ? $_previous : $row['last_seen'];
          $_names[$key] = array_merge($row, array('live' => 0, 'gone_seen' => $_date, 'last_seen' => $last));
          $writes++;
        }
      } elseif ($row['live'] != 1 && $row['last_seen'] !== null && $row['last_seen'] < $_date && ($row['gone_seen'] === null || $row['gone_seen'] > $_date)) {
        $_names[$key]['gone_seen'] = $_date;
        $writes++;
      }
    }
    return $writes;
  }

  /** Relevé de la base en direct (toutes les heures et à chaque audit). */
  public static function snapshot() {
    $now = date('Y-m-d H:i:s');
    $state = self::state();
    $writes = self::applyToArchive(self::liveTables(), $now, true, 'jeedom', $state['snapshot']);
    self::updateState(function ($_state) use ($now) {
      $_state['snapshot'] = $now;
      if (empty($_state['firstSnapshot'])) {
        $_state['firstSnapshot'] = $now;
      }
      return $_state;
    });
    log::add('auditsante', 'debug', 'Archive des noms : relevé, ' . $writes . ' élément(s) modifié(s)');
    return $writes;
  }

  /** Noms de la timeline pour les scénarios et commandes absents de l'archive. Retourne le nombre ajouté. */
  public static function importTimeline() {
    if (!auditsanteIndex::tableExists('timeline')) {
      return 0;
    }
    $rows = self::query("SELECT `type`, `link_id`, `name`, `datetime` FROM `timeline` WHERE `type` IN ('cmd', 'scenario') ORDER BY `datetime` DESC");
    $added = 0;
    auditsanteStore::update('names', function ($_names) use ($rows, &$added) {
      foreach ($rows as $row) {
        $key = $row['type'] . ':' . $row['link_id'];
        $name = trim(html_entity_decode(strip_tags((string) $row['name']), ENT_QUOTES, 'UTF-8'));
        if (isset($_names[$key]) || $name === '') {
          continue;
        }
        $_names[$key] = array('type' => $row['type'], 'id' => (int) $row['link_id'], 'name' => $name, 'first_seen' => null, 'last_seen' => $row['datetime'], 'gone_seen' => null, 'live' => 0, 'source' => 'timeline');
        $added++;
      }
      return ($added > 0) ? $_names : null;
    });
    self::$lookup = null;
    return $added;
  }

  /* ************************************************************************************ */
  /*                                   Sauvegardes                                        */
  /* ************************************************************************************ */

  /** Dossiers de sauvegardes supplémentaires (configuration du plugin, un par ligne). */
  public static function extraDirs() {
    $dirs = array();
    foreach (preg_split('/[\r\n]+/', (string) config::byKey('extraBackupDirs', 'auditsante', '')) as $dir) {
      $dir = rtrim(trim($dir), '/');
      if ($dir !== '') {
        $dirs[] = $dir;
      }
    }
    return $dirs;
  }

  /**
   * Teste un dossier de sauvegardes avant de l'ajouter : accès, sauvegardes trouvées et contenu de la plus récente.
   * Retourne {dir, ok, level (success|warning|danger), message, user, count, unreadable, size, newest, known, read,
   * duplicates, archive (ok|missing|timeout|unchecked)}.
   */
  public static function testBackupDir($_dir) {
    $dir = rtrim(trim((string) $_dir), '/');
    $user = function_exists('posix_geteuid') && function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : get_current_user();
    $return = array('dir' => $dir, 'ok' => false, 'level' => 'danger', 'message' => '', 'user' => $user, 'count' => 0, 'unreadable' => 0,
      'size' => null, 'newest' => null, 'known' => 0, 'read' => 0, 'duplicates' => 0, 'archive' => 'unchecked');
    if ($dir === '' || $dir[0] !== '/') {
      $return['message'] = __('Indiquez un chemin absolu, qui commence par /.', __FILE__);
      return $return;
    }
    if (!file_exists($dir)) {
      $return['message'] = __('Ce dossier n\'existe pas sur la box. Pour un partage NAS, vérifiez qu\'il est bien monté.', __FILE__);
      return $return;
    }
    if (!is_dir($dir)) {
      $return['message'] = __('Ce chemin désigne un fichier, pas un dossier.', __FILE__);
      return $return;
    }
    if (!is_readable($dir) || @opendir($dir) === false) {
      $return['message'] = sprintf(__('Jeedom (utilisateur %s) n\'a pas le droit de lire ce dossier.', __FILE__), $user);
      return $return;
    }
    $state = self::state();
    $jeedom = array();
    foreach (array_keys(jeedom::listBackup()) as $path) {
      $jeedom[basename($path) . '|' . (int) @filesize($path)] = true;
    }
    $files = array();
    $size = 0;
    foreach ((array) glob($dir . '/*.tar.gz') as $path) {
      if (!is_file($path)) {
        continue;
      }
      if (!is_readable($path)) {
        $return['unreadable']++;
        continue;
      }
      $fileSize = (int) filesize($path);
      $key = basename($path) . '|' . $fileSize;
      $size += $fileSize;
      $files[] = array('path' => $path, 'name' => basename($path), 'size' => $fileSize, 'date' => self::backupDate($path));
      if (isset($state['indexed'][$key])) {
        $return['known']++;
        if (!empty($state['indexed'][$key]['read'])) {
          $return['read']++;
        }
      }
      if (isset($jeedom[$key])) {
        $return['duplicates']++;
      }
    }
    $return['count'] = count($files);
    $return['size'] = auditsanteTools::formatBytes($size);
    if (count($files) == 0) {
      $return['level'] = 'warning';
      $return['message'] = $return['unreadable'] > 0
        ? sprintf(__('%d sauvegarde(s) trouvée(s), mais Jeedom (utilisateur %s) ne peut pas les lire.', __FILE__), $return['unreadable'], $user)
        : __('Dossier accessible, mais il ne contient aucune sauvegarde (.tar.gz). Les sous-dossiers ne sont pas parcourus.', __FILE__);
      return $return;
    }
    usort($files, function ($_a, $_b) {
      return strcmp($_b['date'], $_a['date']);
    });
    $newest = $files[0];
    $return['newest'] = array('name' => $newest['name'], 'date' => $newest['date'], 'size' => auditsanteTools::formatBytes($newest['size']));
    $return['archive'] = self::probeArchive($newest['path']);
    $return['ok'] = true;
    $return['level'] = ($return['archive'] == 'missing' || $return['unreadable'] > 0) ? 'warning' : 'success';
    $return['message'] = sprintf(__('%d sauvegarde(s) lisible(s), %s au total.', __FILE__), $return['count'], $return['size']);
    return $return;
  }

  /**
   * Depuis la configuration : enregistre la liste des dossiers (sans attendre le bouton de sauvegarde de Jeedom) puis
   * lance la lecture des sauvegardes jamais lues. Retourne {dirs, pending, size, job} ; job vaut null s'il n'y a rien à lire.
   */
  public static function readBackupDirs($_dirs) {
    $dirs = array();
    foreach (preg_split('/[\r\n]+/', (string) $_dirs) as $dir) {
      $dir = rtrim(trim($dir), '/');
      if ($dir !== '' && !in_array($dir, $dirs)) {
        $dirs[] = $dir;
      }
    }
    config::save('extraBackupDirs', implode("\n", $dirs), 'auditsante');
    $job = self::job();
    if (in_array($job['status'], array('running', 'auditing'))) {
      return array('dirs' => $dirs, 'pending' => (int) $job['total'], 'size' => null, 'job' => $job);
    }
    $pending = self::pendingBackups();
    $size = 0;
    foreach ($pending as $file) {
      $size += $file['size'];
    }
    return array('dirs' => $dirs, 'pending' => count($pending), 'size' => auditsanteTools::formatBytes($size),
      'job' => count($pending) > 0 ? self::startJob('config') : null);
  }

  /**
   * Vérifie qu'une archive contient la base de Jeedom, sans l'extraire. La liste s'arrête dès que DB_backup.sql est
   * trouvé, avec une limite de temps pour une archive volumineuse sur un partage lent : ok, missing ou timeout.
   */
  private static function probeArchive($_path) {
    $command = 'timeout 20 tar -tzf ' . escapeshellarg($_path) . ' 2>/dev/null | grep -m1 -c ' . escapeshellarg('DB_backup\.sql$');
    $output = array();
    // Deux lignes : le nombre de DB_backup.sql trouvés (0 ou 1), puis le code de sortie de timeout (124 = délai dépassé)
    exec('bash -c ' . escapeshellarg($command . '; echo "${PIPESTATUS[0]}"'), $output);
    $found = isset($output[0]) && (int) $output[0] > 0;
    if ($found) {
      return 'ok';
    }
    $tarStatus = isset($output[1]) ? (int) $output[1] : 0;
    return ($tarStatus == 124) ? 'timeout' : 'missing';
  }

  /**
   * Dossiers proposés : partages réseau montés sur la box (CIFS, NFS, SSHFS...) et dossiers usuels qui contiennent
   * des sauvegardes. Retourne [{dir, label, count}].
   */
  public static function suggestBackupDirs() {
    $candidates = array();
    $mountPoints = array();
    $mounts = @file('/proc/mounts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ((array) $mounts as $line) {
      $parts = preg_split('/\s+/', $line);
      if (count($parts) < 3 || !preg_match('/^(cifs|smb3|nfs4?|fuse\.sshfs|fuse\.rclone|davfs)$/', $parts[2])) {
        continue;
      }
      // /proc/mounts échappe les espaces en \040
      $mount = rtrim(str_replace('\040', ' ', $parts[1]), '/');
      $label = $parts[2] . ' ' . $parts[0];
      $mountPoints[$mount] = true;
      $candidates[$mount] = $label;
      foreach ((array) glob($mount . '/*', GLOB_ONLYDIR) as $sub) {
        $candidates[$sub] = $label;
      }
    }
    foreach (array('/mnt/*', '/media/*', '/media/*/*') as $pattern) {
      foreach ((array) glob($pattern, GLOB_ONLYDIR) as $dir) {
        if (!isset($candidates[$dir])) {
          $candidates[$dir] = '';
        }
      }
    }
    $configured = array_flip(self::extraDirs());
    $return = array();
    foreach ($candidates as $dir => $label) {
      if (isset($configured[$dir]) || !is_readable($dir)) {
        continue;
      }
      $count = count((array) glob($dir . '/*.tar.gz'));
      // Un point de montage réseau est proposé même vide : les sauvegardes sont peut-être dans un sous-dossier
      if ($count > 0 || isset($mountPoints[$dir])) {
        $return[] = array('dir' => $dir, 'label' => $label, 'count' => $count);
      }
    }
    usort($return, function ($_a, $_b) {
      return $_b['count'] - $_a['count'];
    });
    return array_slice($return, 0, 20);
  }

  /**
   * Date d'une sauvegarde : celle de son nom (backup-...-2026-11-20-09h28.tar.gz), plus fiable que la date du fichier
   * pour une copie (NAS, cloud), sinon la date du fichier.
   */
  public static function backupDate($_path, $_time = null) {
    if (preg_match('/(\d{4}-\d{2}-\d{2})-(\d{2})h(\d{2})/', basename($_path), $match)) {
      return $match[1] . ' ' . $match[2] . ':' . $match[3] . ':00';
    }
    return date('Y-m-d H:i:s', ($_time !== null) ? (int) $_time : (int) filemtime($_path));
  }

  /** Sauvegardes trouvées, de la plus récente à la plus ancienne : [{path, name, size, date, key, dir}] */
  public static function backupFiles() {
    $paths = array_keys(jeedom::listBackup());
    foreach (self::extraDirs() as $dir) {
      foreach ((array) glob($dir . '/*.tar.gz') as $path) {
        $paths[] = $path;
      }
    }
    // Sauvegardes envoyées depuis la page de configuration
    $paths = array_merge($paths, auditsanteUpload::files());
    $return = array();
    $seen = array();
    foreach ($paths as $path) {
      if (!is_file($path) || !is_readable($path)) {
        continue;
      }
      $size = (int) filesize($path);
      // Une même sauvegarde présente à deux endroits (box et NAS) n'est lue qu'une fois
      $key = basename($path) . '|' . $size;
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = true;
      $return[] = array('path' => $path, 'name' => basename($path), 'size' => $size, 'date' => self::backupDate($path), 'key' => $key, 'dir' => dirname($path));
    }
    usort($return, function ($_a, $_b) {
      return strcmp($_b['date'], $_a['date']);
    });
    return $return;
  }

  private static function markIndexed($_file, $_read, $_writes, $_seconds = null) {
    self::updateState(function ($_state) use ($_file, $_read, $_writes, $_seconds) {
      $_state['indexed'][$_file['key']] = array('date' => $_file['date'], 'read' => $_read, 'writes' => $_writes, 'seconds' => $_seconds, 'size' => $_file['size'], 'at' => date('Y-m-d H:i:s'));
      return $_state;
    });
  }

  /**
   * Sort d'une sauvegarde envoyée : known (déjà lue), useless (postérieure au premier relevé) ou pending (à lire).
   * Une sauvegarde dont la lecture avait échoué peut être renvoyée : elle redevient à lire.
   */
  public static function uploadStatus($_key, $_date) {
    $state = self::state();
    if (!empty($state['indexed'][$_key]['read'])) {
      return 'known';
    }
    if (!empty($state['firstSnapshot']) && $_date >= $state['firstSnapshot']) {
      return 'useless';
    }
    if (isset($state['indexed'][$_key])) {
      self::updateState(function ($_state) use ($_key) {
        unset($_state['indexed'][$_key]);
        return $_state;
      });
    }
    return 'pending';
  }

  /**
   * Sauvegardes à lire. Celles qui datent d'après le premier relevé n'apportent rien : elles sont notées comme
   * traitées sans être lues.
   */
  public static function pendingBackups() {
    $state = self::state();
    $first = (string) $state['firstSnapshot'];
    $pending = array();
    $skipped = array();
    foreach (self::backupFiles() as $file) {
      if (isset($state['indexed'][$file['key']])) {
        continue;
      }
      if ($first != '' && $file['date'] >= $first) {
        $skipped[] = $file;
        continue;
      }
      $pending[] = $file;
    }
    if (count($skipped) > 0) {
      self::updateState(function ($_state) use ($skipped) {
        foreach ($skipped as $file) {
          $_state['indexed'][$file['key']] = array('date' => $file['date'], 'read' => false, 'writes' => 0, 'at' => date('Y-m-d H:i:s'));
        }
        return $_state;
      });
    }
    return $pending;
  }

  /** Lit une sauvegarde et l'intègre à l'archive. Retourne le nombre d'éléments modifiés. */
  private static function indexBackup($_file) {
    log::add('auditsante', 'info', 'Archive des noms : lecture de ' . $_file['path'] . ' (' . auditsanteTools::formatBytes($_file['size']) . ')');
    // Lecture longue hors verrou ; l'intégration, rapide, se fait sous verrou
    $start = microtime(true);
    $tables = self::readBackupTables($_file['path']);
    if ($tables === null) {
      throw new Exception(sprintf(__('Impossible de lire la base de données dans la sauvegarde %s', __FILE__), $_file['name']));
    }
    $read = round(microtime(true) - $start, 1);
    $writes = self::applyToArchive($tables, $_file['date'], false, $_file['name']);
    $seconds = round(microtime(true) - $start, 1);
    self::markIndexed($_file, true, $writes, $seconds);
    log::add('auditsante', 'info', 'Archive des noms : ' . $_file['name'] . ' lue en ' . $seconds . ' s (décompression ' . $read . ' s), ' . $writes . ' élément(s) modifié(s)');
    return $writes;
  }

  /**
   * Date de disparition des éléments connus par les sauvegardes, quel que soit l'ordre de lecture : première date
   * connue après leur dernière apparition (sauvegarde lue ou premier relevé du plugin, où ils n'existaient plus).
   */
  private static function finalizeGone() {
    $state = self::state();
    $dates = array();
    foreach ($state['indexed'] as $entry) {
      if (!empty($entry['read'])) {
        $dates[] = $entry['date'];
      }
    }
    if (!empty($state['firstSnapshot'])) {
      $dates[] = $state['firstSnapshot'];
    }
    sort($dates);
    $writes = 0;
    auditsanteStore::update('names', function ($_names) use ($dates, &$writes) {
      foreach ($_names as $key => $row) {
        if ($row['live'] == 1 || $row['source'] == 'timeline' || $row['last_seen'] === null) {
          continue;
        }
        $candidate = null;
        foreach ($dates as $date) {
          if ($date > $row['last_seen']) {
            $candidate = $date;
            break;
          }
        }
        if ($candidate !== null && ($row['gone_seen'] === null || $row['gone_seen'] > $candidate)) {
          $_names[$key]['gone_seen'] = $candidate;
          $writes++;
        }
      }
      return ($writes > 0) ? $_names : null;
    });
    self::$lookup = null;
    return $writes;
  }

  /* ************************************************************************************ */
  /*                                   Consultation                                       */
  /* ************************************************************************************ */

  /** Éléments disparus de l'archive : {"type:id" => ligne}, chargés une fois par requête. */
  private static $lookup = null;

  public static function lookup($_type, $_id) {
    if (self::$lookup === null) {
      self::$lookup = array();
      try {
        foreach (auditsanteStore::read('names') as $key => $row) {
          if (empty($row['live'])) {
            self::$lookup[$key] = $row;
          }
        }
      } catch (Throwable $e) {
        log::add('auditsante', 'error', 'Archive des noms illisible : ' . $e->getMessage());
      }
    }
    $key = $_type . ':' . $_id;
    return isset(self::$lookup[$key]) ? self::$lookup[$key] : null;
  }

  /** Complément de libellé : « anciennement [Salon][Lampe], supprimé entre le 10/11/2026 et le 17/11/2026 ». */
  public static function describe($_type, $_id) {
    $row = self::lookup($_type, $_id);
    if ($row === null) {
      return '';
    }
    $text = sprintf(__('anciennement %s', __FILE__), $row['name']);
    if ($row['source'] == 'timeline') {
      return $text . ', ' . sprintf(__('dernière trace dans la timeline le %s', __FILE__), auditsanteTools::formatDay($row['last_seen']));
    }
    if (!empty($row['last_seen']) && !empty($row['gone_seen'])) {
      $same = substr($row['last_seen'], 0, 10) == substr($row['gone_seen'], 0, 10);
      return $text . ', ' . ($same
        ? sprintf(__('supprimé le %s', __FILE__), auditsanteTools::formatDay($row['gone_seen']))
        : sprintf(__('supprimé entre le %s et le %s', __FILE__), auditsanteTools::formatDay($row['last_seen']), auditsanteTools::formatDay($row['gone_seen'])));
    }
    if (!empty($row['last_seen'])) {
      return $text . ', ' . sprintf(__('supprimé après le %s', __FILE__), auditsanteTools::formatDay($row['last_seen']));
    }
    return $text;
  }

  /** Vrai si toutes les sauvegardes disponibles ont été lues : un élément absent de l'archive y restera absent. */
  private static $complete = null;

  public static function archiveComplete() {
    if (self::$complete === null) {
      try {
        $job = self::job();
        self::$complete = ($job['status'] != 'running') && count(self::pendingBackups()) == 0;
      } catch (Throwable $e) {
        // Sauvegardes illisibles : l'archive est considérée incomplète, les contrôles continuent
        log::add('auditsante', 'warning', 'Archive des noms : sauvegardes illisibles (' . $e->getMessage() . ')');
        self::$complete = false;
      }
    }
    return self::$complete;
  }

  /**
   * Vrai une fois le premier scan des sauvegardes terminé (même sans aucune sauvegarde à lire). Tant que ce n'est
   * pas le cas, l'audit commence par ce scan, pour que le rapport puisse nommer les éléments supprimés.
   */
  public static function isInitialized() {
    $state = self::state();
    return !empty($state['initializedAt']) || count($state['indexed']) > 0;
  }

  /** Vrai si l'élément est absent de l'archive alors que toutes les sauvegardes ont été lues. */
  public static function wasSearched($_type, $_id) {
    return self::lookup($_type, $_id) === null && self::archiveComplete();
  }

  /** Résumé de l'archive : {since, count, backups (lues), initialized, pending, pendingSize, extraDirs, file, fileSize} */
  public static function archiveInfo() {
    $since = null;
    $count = 0;
    try {
      foreach (auditsanteStore::read('names') as $row) {
        $count++;
        if (!empty($row['first_seen']) && ($since === null || $row['first_seen'] < $since)) {
          $since = $row['first_seen'];
        }
      }
    } catch (Throwable $e) {
    }
    $state = self::state();
    $read = 0;
    foreach ($state['indexed'] as $entry) {
      if (!empty($entry['read'])) {
        $read++;
      }
    }
    $pending = array();
    $pendingSize = 0;
    try {
      $pending = self::pendingBackups();
      foreach ($pending as $file) {
        $pendingSize += $file['size'];
      }
    } catch (Throwable $e) {
      log::add('auditsante', 'warning', 'Archive des noms : sauvegardes illisibles (' . $e->getMessage() . ')');
    }
    $file = auditsanteStore::dir() . '/names.json';
    return array(
      'since' => $since,
      'count' => $count,
      'backups' => $read,
      // Vrai une fois le premier scan terminé
      'initialized' => !empty($state['initializedAt']) || count($state['indexed']) > 0,
      'pending' => count($pending),
      'pendingSize' => auditsanteTools::formatBytes($pendingSize),
      'extraDirs' => count(self::extraDirs()),
      'file' => $file,
      'fileSize' => file_exists($file) ? auditsanteTools::formatBytes(filesize($file)) : null,
    );
  }

  /* ************************************************************************************ */
  /*                                   Éléments à nommer                                  */
  /* ************************************************************************************ */

  /**
   * Éléments supprimés encore référencés : {"type:id" => {type, id}}. Références cassées, équipements des
   * commandes sans équipement et commandes de l'historique orphelin.
   */
  private static $missing = null;

  public static function missing() {
    // Une seule fois par requête ou par lecture des sauvegardes : les requêtes sur l'historique sont coûteuses
    if (self::$missing !== null) {
      return self::$missing;
    }
    $return = array();
    $add = function ($_type, $_id) use (&$return) {
      $return[$_type . ':' . (int) $_id] = array('type' => $_type, 'id' => (int) $_id);
    };
    foreach (auditsanteIndex::deadRefs() as $dead) {
      $add($dead['type'], $dead['id']);
    }
    $eqLogics = auditsanteIndex::eqLogics();
    foreach (auditsanteIndex::cmds() as $cmd) {
      if (!isset($eqLogics[$cmd['eqLogic_id']])) {
        $add('eqLogic', $cmd['eqLogic_id']);
      }
    }
    foreach (array('history', 'historyArch') as $table) {
      foreach (self::query('SELECT DISTINCT h.cmd_id FROM ' . $table . ' h LEFT JOIN cmd c ON c.id = h.cmd_id WHERE c.id IS NULL') as $row) {
        $add('cmd', $row['cmd_id']);
      }
    }
    self::$missing = $return;
    return $return;
  }

  /** Éléments supprimés dont le nom n'est pas dans l'archive. */
  public static function unknown() {
    $return = array();
    foreach (self::missing() as $key => $ref) {
      if (self::lookup($ref['type'], $ref['id']) === null) {
        $return[$key] = $ref;
      }
    }
    return $return;
  }

  /** État complet, pour la confirmation avant lecture des sauvegardes. */
  public static function status() {
    auditsanteIndex::reset();
    self::$lookup = null;
    self::$missing = null;
    $missing = self::missing();
    $unknown = self::unknown();
    $pending = array();
    foreach (self::pendingBackups() as $file) {
      $pending[] = array('name' => $file['name'], 'size' => auditsanteTools::formatBytes($file['size']), 'date' => $file['date'], 'dir' => $file['dir']);
    }
    return array(
      'missing' => count($missing),
      'unknown' => count($unknown),
      'pending' => $pending,
      'archive' => self::archiveInfo(),
      'job' => self::job(),
    );
  }

  /* ************************************************************************************ */
  /*                                   Tâche de fond                                      */
  /* ************************************************************************************ */

  /**
   * État de la lecture des sauvegardes : {status (idle, running, auditing, done, error), started, updated, finished, step
   * (start, timeline, backup, report), index, total, backup, size, found, unknown, message, elapsed}.
   */
  public static function job() {
    $state = self::state();
    $job = array_merge(array(
      'status' => 'idle', 'started' => null, 'updated' => null, 'finished' => null, 'step' => null, 'stepStarted' => null,
      'index' => null, 'total' => 0, 'backup' => null, 'size' => null, 'found' => 0, 'unknown' => 0, 'message' => '',
    ), is_array($state['job']) ? $state['job'] : array());
    // auditing : sauvegardes lues, rapport en cours de mise à jour (ou premier audit en cours)
    if (in_array($job['status'], array('running', 'auditing')) && time() - strtotime((string) $job['updated']) > self::JOB_STALE && !self::cronRunning()) {
      $job['status'] = 'error';
      $job['message'] = __('La lecture des sauvegardes a été interrompue (redémarrage de Jeedom ou délai dépassé). Relancez-la.', __FILE__);
      $job['finished'] = date('Y-m-d H:i:s');
      self::saveJob($job);
    }
    $job['elapsed'] = ($job['status'] == 'running' && !empty($job['stepStarted'])) ? max(0, time() - strtotime($job['stepStarted'])) : null;
    return $job;
  }

  private static function saveJob($_job) {
    unset($_job['elapsed']);
    $_job['updated'] = date('Y-m-d H:i:s');
    self::updateState(function ($_state) use ($_job) {
      $_state['job'] = $_job;
      return $_state;
    });
  }

  private static function cronRunning() {
    $cron = cron::byClassAndFunction('auditsante', 'recoverJob');
    return is_object($cron) && $cron->running();
  }

  /**
   * Lance la lecture des sauvegardes en tâche de fond. Elle ne lance jamais d'audit : à la fin, seul un rapport
   * existant est mis à jour (noms retrouvés). Retourne l'état de la lecture.
   */
  public static function startJob($_source = 'manual') {
    auditsanteStore::ensureDir();
    $job = self::job();
    if (in_array($job['status'], array('running', 'auditing'))) {
      throw new Exception(__('La lecture des sauvegardes est déjà en cours.', __FILE__));
    }
    self::saveJob(array(
      'status' => 'running', 'started' => date('Y-m-d H:i:s'), 'finished' => null, 'step' => 'start', 'stepStarted' => date('Y-m-d H:i:s'),
      'index' => null, 'total' => count(self::pendingBackups()), 'backup' => null, 'size' => null, 'found' => 0, 'unknown' => 0, 'message' => '', 'source' => $_source,
    ));
    $cron = cron::byClassAndFunction('auditsante', 'recoverJob');
    if (!is_object($cron)) {
      $cron = new cron();
    }
    $cron->setClass('auditsante');
    $cron->setFunction('recoverJob');
    $cron->setOnce(1);
    $cron->setEnable(1);
    // Une première lecture de nombreuses sauvegardes volumineuses peut être longue
    $cron->setTimeout(720);
    $cron->setSchedule(cron::convertDateToCron(strtotime('+1 minute')));
    $cron->save();
    // Lancement immédiat ; à défaut, le moteur de tâches le lancera à la minute suivante
    $cron->run();
    log::add('auditsante', 'info', 'Lecture des sauvegardes lancée en tâche de fond (' . $_source . ')');
    return self::job();
  }

  /** Corps de la tâche de fond : relevé, timeline, sauvegardes non lues (de la plus récente à la plus ancienne), rapport. */
  public static function runJob() {
    $job = self::job();
    if ($job['status'] != 'running') {
      return;
    }
    try {
      auditsanteIndex::reset();
      self::$missing = null;
      $before = count(self::unknown());
      $job['step'] = 'timeline';
      $job['stepStarted'] = date('Y-m-d H:i:s');
      self::saveJob($job);
      self::snapshot();
      self::importTimeline();
      $pending = self::pendingBackups();
      $job['total'] = count($pending);
      foreach ($pending as $index => $file) {
        $job['step'] = 'backup';
        $job['index'] = $index;
        $job['backup'] = $file['name'];
        $job['size'] = auditsanteTools::formatBytes($file['size']);
        $job['stepStarted'] = date('Y-m-d H:i:s');
        $job['unknown'] = count(self::unknown());
        $job['found'] = max(0, $before - $job['unknown']);
        self::saveJob($job);
        try {
          self::indexBackup($file);
        } catch (Throwable $e) {
          // Sauvegarde illisible (corrompue, format inattendu) : notée pour ne pas la relire, la lecture continue
          log::add('auditsante', 'warning', $e->getMessage());
          self::markIndexed($file, false, 0);
        }
      }
      self::finalizeGone();
      // Sauvegardes envoyées depuis la configuration : supprimées une fois lues
      auditsanteUpload::clean(self::state()['indexed']);
      $job['unknown'] = count(self::unknown());
      $job['found'] = max(0, $before - $job['unknown']);
      self::updateState(function ($_state) {
        if (empty($_state['initializedAt'])) {
          $_state['initializedAt'] = date('Y-m-d H:i:s');
        }
        return $_state;
      });
      // Lecture terminée (les contrôles doivent voir l'archive comme complète), rapport existant mis à jour : « auditing »
      $job['status'] = 'auditing';
      $job['step'] = 'report';
      $job['stepStarted'] = date('Y-m-d H:i:s');
      self::saveJob($job);
      auditsante::recoverFinish();
      $job['status'] = 'done';
      $job['finished'] = date('Y-m-d H:i:s');
      self::saveJob($job);
      log::add('auditsante', 'info', 'Lecture des sauvegardes terminée : ' . $job['found'] . ' nom(s) retrouvé(s), ' . $job['unknown'] . ' absent(s) de l\'archive');
    } catch (Throwable $e) {
      $job['status'] = 'error';
      $job['message'] = $e->getMessage();
      $job['finished'] = date('Y-m-d H:i:s');
      self::saveJob($job);
      log::add('auditsante', 'error', 'Lecture des sauvegardes en échec : ' . $e->getMessage());
    }
  }

  /* ************************************************************************************ */
  /*                                   Migration                                          */
  /* ************************************************************************************ */

  /**
   * Reprise des données des versions précédentes, stockées dans la base Jeedom : rapport, contrôles ignorés,
   * commentaires et état (configuration du plugin), archive des noms (table auditsante_names), noms retrouvés
   * (clé « recovered »). Les anciennes données ne sont supprimées qu'une fois les fichiers écrits.
   */
  public static function migrateFromDatabase() {
    auditsanteStore::ensureDir();
    $configValue = function ($_key) {
      $value = config::byKey($_key, 'auditsante', '');
      if (is_string($value) && $value !== '') {
        $value = json_decode($value, true);
      }
      return is_array($value) ? $value : null;
    };
    // Rapport, contrôles ignorés, commentaires
    foreach (array('report', 'ignored', 'comments') as $key) {
      $value = $configValue($key);
      if ($value !== null && count(auditsanteStore::read($key)) == 0) {
        auditsanteStore::write($key, $value);
      }
      config::remove($key, 'auditsante');
    }
    // Archive des noms
    $names = auditsanteStore::read('names');
    if (auditsanteIndex::tableExists('auditsante_names')) {
      foreach (self::query('SELECT `type`, `id`, `name`, `first_seen`, `last_seen`, `gone_seen`, `live`, `source` FROM `auditsante_names`') as $row) {
        $key = $row['type'] . ':' . $row['id'];
        if (!isset($names[$key])) {
          $row['id'] = (int) $row['id'];
          $row['live'] = (int) $row['live'];
          $names[$key] = $row;
        }
      }
    }
    $recovered = $configValue('recovered');
    foreach (($recovered === null ? array() : $recovered) as $key => $entry) {
      if (isset($names[$key]) || strpos($key, ':') === false || !isset($entry['name'])) {
        continue;
      }
      list($type, $id) = explode(':', $key, 2);
      $names[$key] = array('type' => $type, 'id' => (int) $id, 'name' => $entry['name'], 'first_seen' => null,
        'last_seen' => isset($entry['seen']) ? $entry['seen'] : null, 'gone_seen' => isset($entry['missing']) ? $entry['missing'] : null,
        'live' => 0, 'source' => isset($entry['source']) ? $entry['source'] : 'backup');
    }
    auditsanteStore::write('names', $names);
    // État de l'archive
    $indexed = $configValue('namesIndexed');
    $snapshot = config::byKey('namesSnapshot', 'auditsante', '');
    $first = config::byKey('namesFirstSnapshot', 'auditsante', '');
    self::updateState(function ($_state) use ($indexed, $snapshot, $first) {
      if ($indexed !== null) {
        $_state['indexed'] = array_merge($indexed, $_state['indexed']);
      }
      if (empty($_state['snapshot']) && $snapshot != '') {
        $_state['snapshot'] = $snapshot;
      }
      if (empty($_state['firstSnapshot']) && $first != '') {
        $_state['firstSnapshot'] = $first;
      }
      return $_state;
    });
    // Fichiers écrits : suppression des anciennes données
    if (auditsanteIndex::tableExists('auditsante_names')) {
      DB::Prepare('DROP TABLE IF EXISTS `auditsante_names`', array(), DB::FETCH_TYPE_ROW);
    }
    foreach (array('recovered', 'recoverSearched', 'recoverJob', 'namesIndexed', 'namesSnapshot', 'namesFirstSnapshot') as $key) {
      config::remove($key, 'auditsante');
    }
    self::$lookup = null;
  }

  /** Nom lisible d'un élément dans des tables lues (base en direct ou sauvegarde). */
  public static function nameIn($_tables, $_type, $_id) {
    $object = function ($_objectId) use ($_tables) {
      return ($_objectId !== null && isset($_tables['object'][$_objectId])) ? $_tables['object'][$_objectId]['name'] : __('Aucun', __FILE__);
    };
    switch ($_type) {
      case 'object':
        return isset($_tables['object'][$_id]) ? '[' . $_tables['object'][$_id]['name'] . ']' : null;
      case 'scenario':
        if (!isset($_tables['scenario'][$_id])) {
          return null;
        }
        $scenario = $_tables['scenario'][$_id];
        return ((string) $scenario['group'] !== '' ? '[' . $scenario['group'] . ']' : '') . '[' . $scenario['name'] . ']';
      case 'eqLogic':
        if (!isset($_tables['eqLogic'][$_id])) {
          return null;
        }
        $eqLogic = $_tables['eqLogic'][$_id];
        return '[' . $object($eqLogic['object_id']) . '][' . $eqLogic['name'] . '] (' . $eqLogic['eqType_name'] . ')';
      case 'cmd':
        if (!isset($_tables['cmd'][$_id])) {
          return null;
        }
        $cmd = $_tables['cmd'][$_id];
        $eqLogic = isset($_tables['eqLogic'][$cmd['eqLogic_id']]) ? $_tables['eqLogic'][$cmd['eqLogic_id']] : null;
        return ($eqLogic === null)
          ? '[?][#eqLogic' . $cmd['eqLogic_id'] . '#][' . $cmd['name'] . ']'
          : '[' . $object($eqLogic['object_id']) . '][' . $eqLogic['name'] . '][' . $cmd['name'] . ']';
    }
    return null;
  }

  /* ************************************************************************************ */
  /*                                   Lecture d'une sauvegarde                           */
  /* ************************************************************************************ */

  /**
   * Lit les tables utiles du DB_backup.sql d'une sauvegarde, en flux (tar | lecture ligne à ligne).
   * Retourne {table => {id => {colonne => valeur}}}, ou null si la base est introuvable dans l'archive.
   * La lecture s'arrête dès que les quatre tables ont été lues.
   */
  public static function readBackupTables($_path) {
    if (!preg_match('/\.tar\.gz$/', $_path)) {
      $commands = auditsanteUpload::readCommands($_path);
    } else {
      // GNU tar : --occurrence arrête la lecture de l'archive au premier fichier trouvé. Sinon, tar standard.
      $commands = array(
        'tar -xzOf ' . escapeshellarg($_path) . ' --wildcards --occurrence=1 ' . escapeshellarg('*DB_backup.sql'),
        'tar -xzOf ' . escapeshellarg($_path) . ' ./DB_backup.sql',
      );
    }
    foreach ($commands as $command) {
      $tables = self::readDump($command);
      if ($tables !== null) {
        return $tables;
      }
    }
    return null;
  }

  /** Lit un dump SQL produit par une commande. null si aucune donnée n'a été lue. */
  public static function readDump($_command) {
    $process = proc_open($_command, array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    if (!is_resource($process)) {
      return null;
    }
    stream_set_blocking($pipes[2], false);
    $tables = array();
    foreach (array_keys(self::TABLES) as $table) {
      $tables[$table] = array();
    }
    $columns = array();
    $current = null; // table dont on lit le CREATE TABLE
    $lines = 0;
    while (($line = fgets($pipes[1])) !== false) {
      $lines++;
      if ($current !== null) {
        if (preg_match('/^\s*`([^`]+)`\s/', $line, $match)) {
          $columns[$current][] = $match[1];
        } elseif (strpos($line, ')') === 0) {
          $current = null;
        }
        continue;
      }
      if (strncmp($line, 'CREATE TABLE `', 14) === 0 && preg_match('/^CREATE TABLE `([^`]+)`/', $line, $match)) {
        if (isset(self::TABLES[$match[1]])) {
          $current = $match[1];
          $columns[$current] = array();
        } elseif (count($columns) == count(self::TABLES)) {
          // mysqldump écrit chaque table (structure puis données) l'une après l'autre : les quatre sont lues
          break;
        }
        continue;
      }
      if (strncmp($line, 'INSERT INTO `', 13) !== 0) {
        continue;
      }
      if (!preg_match('/^INSERT INTO `([^`]+)`/', $line, $match)) {
        continue;
      }
      $table = $match[1];
      if (!isset(self::TABLES[$table]) || empty($columns[$table])) {
        continue;
      }
      foreach (self::parseInsert($line) as $values) {
        $row = array();
        foreach (self::TABLES[$table] as $column) {
          $position = array_search($column, $columns[$table]);
          $row[$column] = ($position === false || !array_key_exists($position, $values)) ? null : $values[$position];
        }
        if ($row['id'] !== null) {
          $tables[$table][(int) $row['id']] = $row;
        }
      }
    }
    // Fermeture anticipée : on arrête tar (et gzip) sans attendre la fin de l'archive
    $status = proc_get_status($process);
    if ($status['running']) {
      proc_terminate($process);
    }
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    if ($lines == 0 || count($columns) == 0) {
      log::add('auditsante', 'debug', 'Lecture du dump impossible (' . $_command . ') : ' . trim((string) $errors));
      return null;
    }
    log::add('auditsante', 'debug', 'Dump lu : ' . $lines . ' lignes, ' . count($tables['cmd']) . ' commandes, ' . count($tables['eqLogic']) . ' équipements, ' . count($tables['scenario']) . ' scénarios');
    return $tables;
  }

  /**
   * Valeurs d'une instruction INSERT de mysqldump : liste de lignes, chaque ligne étant la liste des valeurs
   * (chaînes décodées, null pour NULL, nombres en chaîne).
   */
  public static function parseInsert($_line) {
    $start = strpos($_line, ' VALUES ');
    if ($start === false) {
      return array();
    }
    $rows = array();
    $row = null;
    // Jetons : ( ) , chaîne entre apostrophes (avec échappements \x) ou valeur nue (nombre, NULL)
    preg_match_all("/\\(|\\)|'(?:[^'\\\\]++|\\\\.)*+'|[^,()'\\s;]++/s", substr($_line, $start + 8), $tokens);
    foreach ($tokens[0] as $token) {
      if ($token === '(') {
        $row = array();
      } elseif ($token === ')') {
        if ($row !== null) {
          $rows[] = $row;
        }
        $row = null;
      } elseif ($row !== null) {
        if ($token[0] === "'") {
          $row[] = strtr(substr($token, 1, -1), array('\\0' => "\0", '\\n' => "\n", '\\r' => "\r", '\\Z' => "\x1a", "\\'" => "'", '\\"' => '"', '\\\\' => '\\'));
        } else {
          $row[] = (strtoupper($token) === 'NULL') ? null : $token;
        }
      }
    }
    return $rows;
  }
}
