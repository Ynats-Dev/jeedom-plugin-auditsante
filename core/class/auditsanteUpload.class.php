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

/**
 * Sauvegardes envoyées depuis le navigateur (configuration du plugin), pour les lire sans donner accès à un dossier.
 *
 * Envoi par morceaux (aucune dépendance à upload_max_filesize) dans data/tmp/<identifiant aléatoire>/ :
 *   meta.json   {name, size, modified}
 *   upload.part fichier en cours d'envoi, renommé à son vrai nom une fois complet et vérifié
 * data/ est interdit d'accès depuis le web et les dossiers « tmp » sont exclus des sauvegardes Jeedom.
 * Le fichier est supprimé dès qu'il a été lu ; un envoi abandonné l'est au bout d'un jour.
 */
class auditsanteUpload {

  /** Espace disque à laisser libre (octets) */
  const FREE_MARGIN = 209715200;

  /** Délai de conservation d'un envoi abandonné (secondes) */
  const STALE = 86400;

  private static function root() {
    return auditsanteStore::dir() . '/tmp';
  }

  /** Format d'une archive d'après son nom : tar, zip ou null. */
  public static function format($_name) {
    if (preg_match('/\.(tar\.gz|tgz|tar)$/i', $_name)) {
      return 'tar';
    }
    return preg_match('/\.zip$/i', $_name) ? 'zip' : null;
  }

  /** Commence un envoi. Retourne {id, chunk (taille maximale d'un morceau)}. */
  public static function start($_name, $_size, $_modified) {
    // Nom de fichier seul, sans caractère de contrôle ni séparateur
    $name = basename(str_replace('\\', '/', auditsanteTools::utf8($_name)));
    $name = ltrim((string) preg_replace('/[^\p{L}\p{N}._() +-]/u', '_', $name), '.-');
    $size = (int) $_size;
    if (self::format($name) === null || strlen($name) > 200) {
      throw new Exception(__('Format non reconnu : envoyez une sauvegarde Jeedom (.tar.gz, .tgz, .tar ou .zip).', __FILE__));
    }
    if ($size <= 0) {
      throw new Exception(__('Ce fichier est vide.', __FILE__));
    }
    self::clean();
    $data = auditsanteStore::ensureDir();
    $free = @disk_free_space($data);
    if ($free !== false && $free < $size + self::FREE_MARGIN) {
      throw new Exception(sprintf(__('Espace disque insuffisant sur la box : %s libres pour un fichier de %s.', __FILE__), auditsanteTools::formatBytes($free), auditsanteTools::formatBytes($size)));
    }
    $id = bin2hex(random_bytes(16));
    $dir = self::root() . '/' . $id;
    if (!@mkdir($dir, 0770, true)) {
      throw new Exception(sprintf(__('Écriture impossible dans %s', __FILE__), self::root()));
    }
    $meta = array('name' => $name, 'size' => $size, 'modified' => (int) $_modified);
    if (@file_put_contents($dir . '/meta.json', json_encode($meta)) === false || @touch($dir . '/upload.part') === false) {
      self::remove($dir);
      throw new Exception(sprintf(__('Écriture impossible dans %s', __FILE__), $dir));
    }
    return array('id' => $id, 'chunk' => self::chunkSize());
  }

  /** Taille maximale d'un morceau, selon les limites de PHP (8 Mo au plus). */
  private static function chunkSize() {
    $chunk = 8388608;
    foreach (array('upload_max_filesize' => 0, 'post_max_size' => 65536) as $key => $overhead) {
      $value = trim((string) ini_get($key));
      $bytes = (float) $value;
      $unit = strtolower(substr($value, -1));
      $bytes *= ($unit == 'g') ? 1073741824 : (($unit == 'm') ? 1048576 : (($unit == 'k') ? 1024 : 1));
      if ($bytes > 0) {
        $chunk = min($chunk, $bytes - $overhead);
      }
    }
    return (int) max(65536, $chunk);
  }

  /** Dossier et informations d'un envoi en cours. */
  private static function open($_id) {
    $dir = self::root() . '/' . $_id;
    if (!is_string($_id) || !preg_match('/^[a-f0-9]{32}$/', $_id) || !is_file($dir . '/upload.part')) {
      throw new Exception(__('Envoi inconnu ou expiré : recommencez.', __FILE__));
    }
    $meta = json_decode((string) @file_get_contents($dir . '/meta.json'), true);
    if (!is_array($meta)) {
      throw new Exception(__('Envoi inconnu ou expiré : recommencez.', __FILE__));
    }
    return array($dir, $meta);
  }

  /**
   * Ajoute un morceau ($_file : entrée de $_FILES). Un morceau déjà reçu (réponse perdue) est ignoré : la page reprend
   * à la position retournée. Retourne {received}.
   */
  public static function chunk($_id, $_offset, $_file) {
    list($dir, $meta) = self::open($_id);
    if (!is_array($_file) || !isset($_file['error']) || $_file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_file['tmp_name'])) {
      throw new Exception(__('Morceau de fichier non reçu.', __FILE__));
    }
    return array('received' => self::append($dir, $meta, (int) $_offset, $_file['tmp_name']));
  }

  private static function append($_dir, $_meta, $_offset, $_source) {
    $handle = @fopen($_dir . '/upload.part', 'r+b');
    if ($handle === false || !flock($handle, LOCK_EX)) {
      throw new Exception(sprintf(__('Écriture impossible dans %s', __FILE__), $_dir));
    }
    try {
      $received = (int) fstat($handle)['size'];
      if ($_offset !== $received) {
        return $received;
      }
      $length = (int) filesize($_source);
      if ($received + $length > $_meta['size']) {
        throw new Exception(__('Le fichier reçu est plus gros qu\'annoncé.', __FILE__));
      }
      $input = fopen($_source, 'rb');
      fseek($handle, $received);
      $written = ($input === false) ? false : stream_copy_to_stream($input, $handle);
      if (is_resource($input)) {
        fclose($input);
      }
      if ($written !== $length || !fflush($handle)) {
        // Disque plein : on revient à l'état précédent
        ftruncate($handle, $received);
        throw new Exception(sprintf(__('Écriture impossible dans %s', __FILE__), $_dir));
      }
      return $received + $length;
    } finally {
      flock($handle, LOCK_UN);
      fclose($handle);
    }
  }

  /**
   * Fin d'un envoi : vérifie l'archive, la date, puis la met en attente de lecture.
   * Retourne {status (pending, known, useless), name, date}. Le fichier n'est gardé que s'il est à lire.
   */
  public static function finish($_id) {
    list($dir, $meta) = self::open($_id);
    clearstatcache();
    if ((int) filesize($dir . '/upload.part') !== (int) $meta['size']) {
      throw new Exception(__('Envoi incomplet : recommencez.', __FILE__));
    }
    $path = $dir . '/' . $meta['name'];
    $keep = false;
    try {
      $info = self::inspect($dir . '/upload.part', $meta['name']);
      if (!$info['ok']) {
        throw new Exception(__('DB_backup.sql introuvable : ce n\'est pas une sauvegarde Jeedom, ou l\'archive est abîmée.', __FILE__));
      }
      // Date : nom du fichier, sinon date de la base dans l'archive, sinon date du fichier sur l'ordinateur
      $time = ($info['date'] !== null) ? strtotime($info['date']) : (int) ($meta['modified'] / 1000);
      if ($time <= 0 || $time > time()) {
        $time = time();
      }
      @touch($dir . '/upload.part', $time);
      clearstatcache();
      $date = auditsanteRecovery::backupDate($meta['name'], $time);
      $status = auditsanteRecovery::uploadStatus($meta['name'] . '|' . $meta['size'], $date);
      if ($status == 'pending') {
        if (!@rename($dir . '/upload.part', $path)) {
          throw new Exception(sprintf(__('Écriture impossible dans %s', __FILE__), $dir));
        }
        $keep = true;
      }
      return array('status' => $status, 'name' => $meta['name'], 'date' => auditsanteTools::formatDateTime($date));
    } finally {
      if (!$keep) {
        self::remove($dir);
      }
    }
  }

  /** Annule un envoi en cours. */
  public static function cancel($_id) {
    list($dir) = self::open($_id);
    self::remove($dir);
  }

  /** Sauvegardes envoyées et complètes : [chemin] */
  public static function files() {
    $files = array();
    foreach ((array) glob(self::root() . '/*/*') as $path) {
      if (is_file($path) && self::format(basename($path)) !== null && !is_file(dirname($path) . '/upload.part')) {
        $files[] = $path;
      }
    }
    return $files;
  }

  /** Supprime les sauvegardes envoyées déjà traitées ($_indexed : clés « nom|taille ») et les envois abandonnés. */
  public static function clean($_indexed = array()) {
    foreach (self::files() as $path) {
      if (isset($_indexed[basename($path) . '|' . (int) filesize($path)])) {
        self::remove(dirname($path));
      }
    }
    foreach ((array) glob(self::root() . '/*', GLOB_ONLYDIR) as $dir) {
      $part = $dir . '/upload.part';
      $complete = !is_file($part) && count((array) glob($dir . '/*')) > 1;
      if (!$complete && time() - (int) @filemtime($dir) > self::STALE) {
        self::remove($dir);
      }
    }
  }

  private static function remove($_dir) {
    foreach ((array) glob($_dir . '/*') as $path) {
      @unlink($path);
    }
    @rmdir($_dir);
  }

  /* ************************************************************************************ */
  /*                                   Contenu des archives                               */
  /* ************************************************************************************ */

  /**
   * Cherche DB_backup.sql dans une archive, sans l'extraire : {ok, date (de la base dans l'archive, ou null)}. Une
   * archive trop longue à parcourir est acceptée : la lecture en tâche de fond tranchera.
   */
  private static function inspect($_path, $_name) {
    $file = escapeshellarg($_path);
    $grep = ' 2>/dev/null | grep -m1 ' . escapeshellarg('DB_backup\.sql$');
    $commands = array('LC_ALL=C timeout 20 tar -tvf ' . $file . $grep);
    if (self::format($_name) == 'zip') {
      $commands = array(
        'LC_ALL=C timeout 20 unzip -l ' . $file . $grep,
        // Zip qui contient une sauvegarde .tar.gz
        'LC_ALL=C timeout 20 unzip -p ' . $file . self::nested() . ' 2>/dev/null | LC_ALL=C timeout 20 tar -tvzf -' . $grep,
      );
    }
    foreach ($commands as $command) {
      $output = array();
      exec('bash -c ' . escapeshellarg($command . '; echo "status ${PIPESTATUS[*]}"'), $output);
      $line = isset($output[0]) ? $output[0] : '';
      if (strpos($line, 'DB_backup.sql') !== false) {
        return array('ok' => true, 'date' => preg_match('/(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})/', $line, $match) ? $match[1] . ' ' . $match[2] . ':00' : null);
      }
      if (preg_match('/^status .*\b124\b/', $line)) {
        return array('ok' => true, 'date' => null);
      }
    }
    return array('ok' => false, 'date' => null);
  }

  private static function nested() {
    return ' ' . escapeshellarg('*.tar.gz') . ' ' . escapeshellarg('*.tgz') . ' -x ' . escapeshellarg('__MACOSX/*');
  }

  /** Commandes qui écrivent DB_backup.sql sur la sortie standard, pour un .zip ou un .tar (essayées dans l'ordre). */
  public static function readCommands($_path) {
    $file = escapeshellarg($_path);
    $first = ' --wildcards --occurrence=1 ' . escapeshellarg('*DB_backup.sql');
    if (self::format($_path) == 'zip') {
      $nested = 'unzip -p ' . $file . self::nested() . ' | tar -xzOf -';
      return array('unzip -p ' . $file . ' ' . escapeshellarg('*DB_backup.sql') . ' -x ' . escapeshellarg('__MACOSX/*'), $nested . $first, $nested . ' ./DB_backup.sql');
    }
    return array('tar -xOf ' . $file . $first, 'tar -xOf ' . $file . ' ./DB_backup.sql');
  }
}
