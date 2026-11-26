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

/**
 * Données du plugin en fichiers JSON, dans plugins/auditsante/data/ : indépendantes de la base Jeedom.
 *
 *   report.json        dernier rapport d'audit (résultats bruts des contrôles)
 *   ignored.json       contrôles ignorés {id => {since, until, by}}
 *   comments.json      commentaires {id => {text, date, by}}
 *   excluded.json      éléments exclus d'un contrôle par l'utilisateur {id => {clé => {since, by}}}
 *   names.json         archive des noms {"type:id" => {type, id, name, first_seen, last_seen, gone_seen, live, source}}
 *   archive-state.json état de l'archive {snapshot, firstSnapshot, indexed, job}
 *
 * La mise à jour d'un plugin par Jeedom copie les nouveaux fichiers sans vider le dossier : data/ est conservé.
 * Il est inclus dans les sauvegardes Jeedom et supprimé avec le plugin. Seuls les réglages de la page de
 * configuration restent dans la configuration Jeedom (mécanisme standard de cette page).
 *
 * Écritures concurrentes (page, relevé horaire, lecture des sauvegardes en tâche de fond) : verrou exclusif par
 * fichier (flock sur un fichier .lock) et écriture atomique (fichier temporaire puis rename). Une coupure en
 * pleine écriture laisse l'ancien fichier intact.
 */
class auditsanteStore {

  /** Dossier des données. */
  public static function dir() {
    return realpath(__DIR__ . '/../..') . '/data';
  }

  /** Crée le dossier des données (et sa protection web) si besoin. Lève une exception s'il n'est pas inscriptible. */
  public static function ensureDir() {
    $dir = self::dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
      throw new Exception(sprintf(__('Impossible de créer le dossier des données du plugin : %s', __FILE__), $dir));
    }
    // Le dossier plugins/ est servi par Apache : on interdit la lecture des données depuis le web
    if (!file_exists($dir . '/.htaccess')) {
      @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    if (!is_writable($dir)) {
      throw new Exception(sprintf(__('Le dossier des données du plugin n\'est pas accessible en écriture : %s (droits de www-data)', __FILE__), $dir));
    }
    return $dir;
  }

  private static function path($_name) {
    if (!preg_match('/^[a-z0-9-]+$/', $_name)) {
      throw new Exception('Nom de fichier de données invalide : ' . $_name);
    }
    return self::dir() . '/' . $_name . '.json';
  }

  /** Lit un fichier de données. Fichier absent : $_default. Fichier illisible : mis de côté (.bad) et $_default. */
  public static function read($_name, $_default = array()) {
    $path = self::path($_name);
    if (!file_exists($path)) {
      return $_default;
    }
    $content = @file_get_contents($path);
    if ($content === false || trim($content) === '') {
      return $_default;
    }
    $data = json_decode($content, true);
    if (!is_array($data)) {
      // Ne devrait pas arriver (écriture atomique) : on garde le fichier pour diagnostic et on repart de zéro
      @rename($path, $path . '.bad');
      log::add('auditsante', 'error', 'Fichier de données illisible, mis de côté : ' . $path . '.bad');
      return $_default;
    }
    return $data;
  }

  /** Écrit un fichier de données (écriture atomique, sous verrou). */
  public static function write($_name, $_data) {
    $lock = self::lock($_name);
    try {
      self::writeUnlocked($_name, $_data);
    } finally {
      self::unlock($lock);
    }
  }

  /**
   * Lecture, modification et écriture sous un même verrou : $_callback reçoit les données et retourne les nouvelles
   * (ou null pour ne rien écrire). Retourne les données finales.
   */
  public static function update($_name, $_callback, $_default = array()) {
    $lock = self::lock($_name);
    try {
      $data = self::read($_name, $_default);
      $new = $_callback($data);
      if ($new !== null) {
        self::writeUnlocked($_name, $new);
        return $new;
      }
      return $data;
    } finally {
      self::unlock($lock);
    }
  }

  /** Supprime un fichier de données. */
  public static function delete($_name) {
    $path = self::path($_name);
    if (file_exists($path)) {
      @unlink($path);
    }
  }

  private static function writeUnlocked($_name, $_data) {
    $path = self::path($_name);
    $json = json_encode($_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
      throw new Exception('Données non encodables en JSON (' . $_name . ') : ' . json_last_error_msg());
    }
    $tmp = $path . '.tmp' . getmypid();
    if (@file_put_contents($tmp, $json . "\n") === false) {
      @unlink($tmp);
      throw new Exception(sprintf(__('Écriture impossible dans %s', __FILE__), $tmp));
    }
    @chmod($tmp, 0664);
    if (!@rename($tmp, $path)) {
      @unlink($tmp);
      throw new Exception(sprintf(__('Écriture impossible dans %s', __FILE__), $path));
    }
  }

  /** Verrou exclusif sur un fichier de données (attente maximale d'environ 30 secondes). */
  private static function lock($_name) {
    $dir = self::ensureDir();
    $handle = @fopen($dir . '/.' . $_name . '.lock', 'c');
    if ($handle === false) {
      throw new Exception(sprintf(__('Verrou impossible sur %s', __FILE__), $_name));
    }
    for ($i = 0; $i < 300; $i++) {
      if (flock($handle, LOCK_EX | LOCK_NB)) {
        return $handle;
      }
      usleep(100000);
    }
    fclose($handle);
    throw new Exception(sprintf(__('Fichier de données occupé : %s', __FILE__), $_name));
  }

  private static function unlock($_handle) {
    flock($_handle, LOCK_UN);
    fclose($_handle);
  }
}
