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

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
// L'autoload de Jeedom ne charge que la classe principale d'un plugin actif : elle charge les classes annexes
require_once dirname(__FILE__) . '/../core/class/auditsante.class.php';

// Les données du plugin (rapport, archive des noms...) sont des fichiers JSON dans plugins/auditsante/data/ :
// voir core/class/auditsanteStore.class.php. Seuls les réglages restent dans la configuration Jeedom.

// Fonction exécutée automatiquement après l'installation du plugin
function auditsante_install() {
  config::save('autoAudit', 'weekly', 'auditsante');
  config::save('notifyNew', 1, 'auditsante');
  config::save('staleDays', 180, 'auditsante');
  config::save('silentDays', 30, 'auditsante');
  config::save('logMaxMb', 20, 'auditsante');
  config::save('messageMax', 50, 'auditsante');
  auditsanteStore::ensureDir();
  auditsante::getAuditEqLogic();
  // Première étape : lecture des sauvegardes (archive des noms) en tâche de fond. L'audit reste à lancer par l'utilisateur
  try {
    auditsanteRecovery::startJob('install');
  } catch (Throwable $e) {
    log::add('auditsante', 'error', 'Premier scan des sauvegardes non lancé : ' . $e->getMessage());
  }
}

// Fonction exécutée automatiquement après la mise à jour du plugin
function auditsante_update() {
  auditsanteStore::ensureDir();
  // Recrée l'équipement s'il a été supprimé et les commandes ajoutées dans une nouvelle version
  auditsante::getAuditEqLogic()->save();
  // Versions précédentes : données reprises de la base Jeedom vers les fichiers, puis supprimées de la base
  auditsanteRecovery::migrateFromDatabase();
  auditsanteRecovery::snapshot();
  // Archive jamais initialisée (version précédente sans scan) : premier scan tout de suite, rapport existant mis à jour ensuite
  try {
    if (!auditsanteRecovery::isInitialized() && !in_array(auditsanteRecovery::job()['status'], array('running', 'auditing'))) {
      auditsanteRecovery::startJob('update');
    }
  } catch (Throwable $e) {
    log::add('auditsante', 'error', 'Premier scan des sauvegardes non lancé : ' . $e->getMessage());
  }
}

// Fonction exécutée automatiquement après la suppression du plugin (le dossier data/ part avec le plugin)
function auditsante_remove() {
  // Restes éventuels de versions précédentes, qui stockaient leurs données dans la base Jeedom
  foreach (array('report', 'ignored', 'comments', 'recoverJob', 'namesSnapshot', 'namesFirstSnapshot', 'namesIndexed', 'recovered', 'recoverSearched') as $key) {
    config::remove($key, 'auditsante');
  }
  DB::Prepare('DROP TABLE IF EXISTS `auditsante_names`', array(), DB::FETCH_TYPE_ROW);
  $cron = cron::byClassAndFunction('auditsante', 'recoverJob');
  if (is_object($cron)) {
    $cron->remove();
  }
}
