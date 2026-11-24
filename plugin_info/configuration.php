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
include_file('core', 'authentification', 'php');
if (!isConnect('admin')) {
  include_file('desktop', '404', 'php');
  die();
}
?>
<form class="form-horizontal">
  <fieldset>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Audit automatique}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Relance l'audit en tâche de fond et met à jour la note de l'équipement. L'audit hebdomadaire a lieu le lundi.}}"></i></sup>
      </label>
      <div class="col-md-4">
        <select class="configKey form-control" data-l1key="autoAudit">
          <option value="daily">{{Tous les jours}}</option>
          <option value="weekly">{{Toutes les semaines}}</option>
          <option value="never">{{Jamais}}</option>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Prévenir des nouveaux problèmes}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Ajoute un message dans le centre de messages de Jeedom quand un contrôle qui ne signalait rien signale un problème.}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input type="checkbox" class="configKey" data-l1key="notifyNew" checked>
      </div>
    </div>
    <legend><i class="fas fa-sliders-h"></i> {{Seuils}}</legend>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Scénario qui ne se lance plus après}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Un scénario actif, avec déclencheur ou programmation, qui ne s'est pas lancé depuis ce nombre de jours est signalé.}}"></i></sup>
      </label>
      <div class="col-md-2">
        <div class="input-group">
          <input type="number" min="1" class="configKey form-control" data-l1key="staleDays" placeholder="180">
          <span class="input-group-addon">{{jours}}</span>
        </div>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Équipement muet après}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Un équipement actif sans délai d'alerte (timeout) dont aucune commande info n'a reçu de valeur depuis ce nombre de jours est signalé.}}"></i></sup>
      </label>
      <div class="col-md-2">
        <div class="input-group">
          <input type="number" min="1" class="configKey form-control" data-l1key="silentDays" placeholder="30">
          <span class="input-group-addon">{{jours}}</span>
        </div>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Fichier de log volumineux au-delà de}}</label>
      <div class="col-md-2">
        <div class="input-group">
          <input type="number" min="1" class="configKey form-control" data-l1key="logMaxMb" placeholder="20">
          <span class="input-group-addon">{{Mo}}</span>
        </div>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Centre de messages encombré au-delà de}}</label>
      <div class="col-md-2">
        <div class="input-group">
          <input type="number" min="1" class="configKey form-control" data-l1key="messageMax" placeholder="50">
          <span class="input-group-addon">{{messages}}</span>
        </div>
      </div>
    </div>
    <legend><i class="fas fa-history"></i> {{Archive des noms}}</legend>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Dossiers de sauvegardes supplémentaires}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Le plugin relève toutes les heures le nom de chaque élément, pour nommer plus tard ceux qui seront supprimés. Pour les suppressions plus anciennes, il lit une fois chaque sauvegarde Jeedom. Ajoutez ici les dossiers où sont copiées vos anciennes sauvegardes (partage NAS monté sur la box, dossier de synchronisation...), un par ligne.}}"></i></sup>
      </label>
      <div class="col-md-6">
        <textarea class="configKey form-control" data-l1key="extraBackupDirs" rows="3" placeholder="/mnt/nas/jeedom-backups"></textarea>
        <div class="help-block">{{Chemins sur la box, lisibles par Jeedom. Les nouvelles sauvegardes trouvées sont lues une seule fois, la nuit, ou tout de suite depuis la page du plugin.}}</div>
      </div>
    </div>
    <legend><i class="fas fa-life-ring"></i> {{Aide}}</legend>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Signaler un problème}}
        <sup><i class="fas fa-question-circle tooltips" title="{{En niveau de log Debug, le plugin écrit dans le log auditsante la durée et le résultat de chaque contrôle.}}"></i></sup>
      </label>
      <div class="col-md-6">
        <div class="help-block">{{Passez le niveau de log sur Debug (cadre Logs et surveillance ci-dessus), relancez l'audit, puis cliquez sur Diagnostic dans la page du plugin et joignez le fichier téléchargé à votre message sur le forum. Remettez ensuite le niveau de log par défaut.}}</div>
      </div>
    </div>
  </fieldset>
</form>
