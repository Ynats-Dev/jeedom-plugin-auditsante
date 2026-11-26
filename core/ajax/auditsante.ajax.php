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

try {
  require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
  include_file('core', 'authentification', 'php');
  // L'autoload de Jeedom ne trouve que la classe principale du plugin : elle charge les classes annexes (Recovery, Checks...)
  require_once dirname(__FILE__) . '/../class/auditsante.class.php';

  if (!isConnect('admin')) {
    throw new Exception(__('401 - Accès non autorisé', __FILE__));
  }

  ajax::init();

  // Dernier rapport (sans relancer l'audit)
  if (init('action') == 'getReport') {
    ajax::success(auditsante::getFullReport());
  }

  // Lance un audit complet
  if (init('action') == 'runAudit') {
    @set_time_limit(300);
    ajax::success(auditsante::getFullReport(auditsante::runAudit('manual')));
  }

  // Relance un seul contrôle
  if (init('action') == 'rerunCheck') {
    ajax::success(auditsante::getFullReport(auditsante::rerunCheck(init('id'))));
  }

  // Recherche du nom des éléments supprimés : état, une étape (timeline puis une sauvegarde), fin
  if (init('action') == 'recoverStatus') {
    ajax::success(auditsanteRecovery::status());
  }

  // Lance la recherche en tâche de fond (elle continue si l'on quitte la page)
  if (init('action') == 'recoverStart') {
    ajax::success(auditsanteRecovery::startJob());
  }

  // État de la recherche en cours (appelé toutes les quelques secondes par la page)
  if (init('action') == 'recoverJob') {
    ajax::success(auditsanteRecovery::job());
  }

  // Configuration : teste un dossier de sauvegardes (accès, sauvegardes trouvées, contenu de la plus récente)
  if (init('action') == 'testBackupDir') {
    @set_time_limit(60);
    ajax::success(auditsanteRecovery::testBackupDir(init('dir')));
  }

  // Configuration : dossiers enregistrés et dossiers proposés (partages réseau montés, dossiers contenant des sauvegardes)
  if (init('action') == 'backupDirs') {
    ajax::success(array('dirs' => auditsanteRecovery::extraDirs(), 'suggestions' => auditsanteRecovery::suggestBackupDirs()));
  }

  // Configuration : enregistre les dossiers puis lit les sauvegardes jamais lues, en tâche de fond
  if (init('action') == 'readBackupDirs') {
    ajax::success(auditsanteRecovery::readBackupDirs(init('dirs')));
  }

  // Configuration : envoi d'une sauvegarde depuis le navigateur, par morceaux
  if (init('action') == 'uploadStart') {
    ajax::success(auditsanteUpload::start(init('name'), init('size'), init('modified')));
  }

  if (init('action') == 'uploadChunk') {
    ajax::success(auditsanteUpload::chunk(init('id'), init('offset'), isset($_FILES['chunk']) ? $_FILES['chunk'] : null));
  }

  if (init('action') == 'uploadFinish') {
    @set_time_limit(120);
    ajax::success(auditsanteUpload::finish(init('id')));
  }

  if (init('action') == 'uploadCancel') {
    ajax::success(auditsanteUpload::cancel(init('id')));
  }

  // Applique la correction automatique d'un contrôle
  if (init('action') == 'applyFix') {
    @set_time_limit(300);
    $result = auditsante::applyFix(init('id'));
    $result['report'] = auditsante::getFullReport($result['report']);
    ajax::success($result);
  }

  // Utilisateur connecté, conservé avec les commentaires et les contrôles ignorés
  $login = (isset($_SESSION['user']) && is_object($_SESSION['user'])) ? $_SESSION['user']->getLogin() : '';

  // Ignore (choix assumé, pour une durée en jours, 0 = sans limite) ou réintègre un contrôle
  if (init('action') == 'setIgnored') {
    $comment = isset($_POST['comment']) ? init('comment') : null;
    ajax::success(auditsante::getFullReport(auditsante::setIgnored(init('id'), init('ignored') == 1, init('days', 0), $comment, $login)));
  }

  // Exclut un élément d'un contrôle (il sert, l'audit ne le voit pas) ou le réintègre, puis revérifie le contrôle
  if (init('action') == 'setExcluded') {
    ajax::success(auditsante::getFullReport(auditsante::setExcluded(init('id'), init('key'), init('excluded') == 1, $login)));
  }

  // Commentaire d'un contrôle (texte vide = suppression)
  if (init('action') == 'setComment') {
    ajax::success(auditsante::getFullReport(auditsante::setCheckComment(init('id'), init('text'), $login)));
  }

  // Rapport de diagnostic (texte, données personnelles masquées) à joindre à une demande d'aide
  if (init('action') == 'getDiagnostic') {
    @set_time_limit(120);
    ajax::success(auditsante::getDiagnostic());
  }

  throw new Exception(__('Aucune méthode correspondante à', __FILE__) . ' : ' . init('action'));
} catch (Throwable $e) {
  // Throwable : une erreur PHP (TypeError...) doit aussi revenir en JSON, sinon le navigateur reçoit une réponse vide
  if (!($e instanceof Exception)) {
    log::add('auditsante', 'error', get_class($e) . ' : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
  }
  ajax::error(displayException($e), $e->getCode());
}
