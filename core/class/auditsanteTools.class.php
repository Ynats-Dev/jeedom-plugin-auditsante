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

/** Petits utilitaires partagés par les classes du plugin. */
class auditsanteTools {

  /** Racine de l'installation Jeedom. */
  public static function jeedomRoot() {
    return realpath(__DIR__ . '/../../../..');
  }

  /** Élément de liste d'un contrôle : texte, avec un lien facultatif vers la page Jeedom concernée. */
  public static function item($_text, $_url = null) {
    $item = array('text' => (string) $_text);
    if ($_url !== null && $_url !== '') {
      $item['url'] = $_url;
    }
    return $item;
  }

  /** Texte d'un élément de liste (ancien format texte ou nouveau format {text, url}). */
  public static function itemText($_item) {
    return is_array($_item) ? (isset($_item['text']) ? $_item['text'] : '') : (string) $_item;
  }

  /** Taille lisible : « 12,3 Mo ». */
  public static function formatBytes($_bytes) {
    $units = array(__('o', __FILE__), __('Ko', __FILE__), __('Mo', __FILE__), __('Go', __FILE__), __('To', __FILE__));
    $bytes = max(0, (float) $_bytes);
    $index = 0;
    while ($bytes >= 1024 && $index < count($units) - 1) {
      $bytes /= 1024;
      $index++;
    }
    return str_replace('.', ',', (string) round($bytes, ($index == 0) ? 0 : 1)) . ' ' . $units[$index];
  }

  /** Nombre lisible : « 12 345 ». */
  public static function formatNumber($_number) {
    return number_format((float) $_number, 0, ',', ' ');
  }

  /** Nombre de jours écoulés depuis une date (null si la date est vide ou invalide). */
  public static function daysSince($_date) {
    if ($_date === null || $_date === '' || $_date === false) {
      return null;
    }
    $time = strtotime($_date);
    if ($time === false || $time <= 0) {
      return null;
    }
    return (int) floor((time() - $time) / 86400);
  }

  /** Date et heure (jj/mm/aaaa à hh:mm) ou « jamais ». */
  public static function formatDateTime($_date) {
    $time = ($_date === null || $_date === '') ? false : strtotime($_date);
    return ($time === false || $time <= 0) ? __('jamais', __FILE__) : sprintf(__('%s à %s', __FILE__), date('d/m/Y', $time), date('H:i', $time));
  }

  /** Date courte (jj/mm/aaaa) ou « jamais ». */
  public static function formatDay($_date) {
    $time = ($_date === null || $_date === '') ? false : strtotime($_date);
    return ($time === false || $time <= 0) ? __('jamais', __FILE__) : date('d/m/Y', $time);
  }

  /** Garantit une chaîne UTF-8 valide (les expressions régulières en mode /u échouent sinon). */
  public static function utf8($_text) {
    $text = (string) $_text;
    if (function_exists('mb_check_encoding') && !mb_check_encoding($text, 'UTF-8')) {
      $text = function_exists('mb_convert_encoding') ? mb_convert_encoding($text, 'UTF-8', 'UTF-8') : utf8_encode($text);
    }
    return $text;
  }

  /** Vrai pour une adresse IPv4 privée, locale ou réservée. */
  public static function isNonPublicIp($_ip) {
    return filter_var($_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
  }

  /** Taille des fichiers d'un dossier (non récursif) : {nom => octets}. */
  public static function filesIn($_dir) {
    $files = array();
    if (!is_dir($_dir)) {
      return $files;
    }
    foreach ((array) @scandir($_dir) as $name) {
      $path = $_dir . '/' . $name;
      if ($name === '.' || $name === '..' || !is_file($path)) {
        continue;
      }
      $files[$name] = (int) @filesize($path);
    }
    return $files;
  }

  /** Taille totale d'un dossier, sous-dossiers compris. */
  public static function dirSize($_dir) {
    $size = 0;
    if (!is_dir($_dir)) {
      return 0;
    }
    try {
      $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($_dir, FilesystemIterator::SKIP_DOTS));
      foreach ($iterator as $file) {
        if ($file->isFile()) {
          $size += $file->getSize();
        }
      }
    } catch (Throwable $e) {
      // Dossier illisible : taille partielle
    }
    return $size;
  }
}
