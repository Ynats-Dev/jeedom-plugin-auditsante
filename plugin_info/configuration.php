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
        <sup><i class="fas fa-question-circle tooltips" title="{{Le plugin relève toutes les heures le nom de chaque élément, pour nommer plus tard ceux qui seront supprimés. Pour les suppressions plus anciennes, il lit une fois chaque sauvegarde Jeedom. Ajoutez ici les dossiers où sont copiées vos anciennes sauvegardes (partage NAS monté sur la box, dossier de synchronisation...), puis testez-les.}}"></i></sup>
      </label>
      <div class="col-md-6">
        <!-- Valeur enregistrée par Jeedom (un dossier par ligne), tenue à jour depuis la liste ci-dessous -->
        <textarea class="configKey form-control" data-l1key="extraBackupDirs" style="display:none;"></textarea>
        <div id="sa_backupDirs"></div>
        <div class="sa-dirs-actions">
          <a class="btn btn-sm btn-default" id="bt_saAddBackupDir"><i class="fas fa-plus"></i> {{Ajouter un dossier}}</a>
          <a class="btn btn-sm btn-default" id="bt_saTestAllBackupDirs" style="display:none;"><i class="fas fa-vial"></i> {{Tout tester}}</a>
          <a class="btn btn-sm btn-success" id="bt_saReadBackupDirs" title="{{Enregistre la liste des dossiers puis lit en tâche de fond les sauvegardes jamais lues}}"><i class="fas fa-download"></i> {{Lire les sauvegardes maintenant}}</a>
        </div>
        <div class="sa-dir-result" id="sa_backupJob" style="display:none;"></div>
        <div id="sa_backupSuggestions" style="display:none;">
          <div class="help-block">{{Dossiers repérés sur la box (partages réseau montés, dossiers contenant des sauvegardes) :}}</div>
          <div class="sa-suggestions"></div>
        </div>
        <div class="help-block">{{Chemins sur la box, lisibles par Jeedom. Seuls les fichiers .tar.gz placés directement dans le dossier sont lus, une seule fois, la nuit ou tout de suite depuis la page du plugin. Pensez à sauvegarder la configuration après un ajout.}}</div>
      </div>
    </div>
    <style>
      #sa_backupDirs .sa-dir { margin-bottom: 6px; }
      #sa_backupDirs .sa-dir-result, #sa_backupJob { margin: 4px 0 0; padding: 6px 10px; border-left: 3px solid #999; font-size: 12px; }
      #sa_backupDirs .sa-dir-result.is-success, #sa_backupJob.is-success { border-color: #30a46c; }
      #sa_backupDirs .sa-dir-result.is-warning, #sa_backupJob.is-warning { border-color: #f5a524; }
      #sa_backupDirs .sa-dir-result.is-danger, #sa_backupJob.is-danger { border-color: #e5484d; }
      #sa_backupJob { margin-bottom: 6px; }
      #sa_backupDirs .sa-dir-result ul { margin: 3px 0 0; padding-left: 18px; }
      .sa-dirs-actions { margin: 2px 0 6px; }
      .sa-suggestions .btn { margin: 0 4px 4px 0; }
    </style>
    <script>
      (function () {
        var T = <?php echo json_encode(array(
            'testTitle' => __('Vérifier l\'accès et les sauvegardes de ce dossier', __FILE__),
            'test' => __('Tester', __FILE__),
            'removeTitle' => __('Retirer ce dossier', __FILE__),
            'testing' => __('Test en cours (lecture de la sauvegarde la plus récente)...', __FILE__),
            'commError' => __('Erreur de communication', __FILE__),
            'newest' => __('Plus récente :', __FILE__),
            'archiveOk' => __('elle contient bien la base de Jeedom (DB_backup.sql).', __FILE__),
            'archiveMissing' => __('DB_backup.sql introuvable : ce n\'est peut-être pas une sauvegarde Jeedom, ou l\'archive est abîmée.', __FILE__),
            'archiveTimeout' => __('contenu non vérifié (archive trop longue à parcourir), elle sera lue normalement.', __FILE__),
            'content' => __('Contenu :', __FILE__),
            'unreadable' => __('sauvegarde(s) illisible(s) par l\'utilisateur', __FILE__),
            'ignored' => __('elles seront ignorées.', __FILE__),
            'alreadyRead' => __('déjà lue(s) par le plugin', __FILE__),
            'skipped' => __('sans intérêt (postérieure(s) au premier relevé)', __FILE__),
            'notRead' => __('Aucune encore lue : elles le seront après la sauvegarde de la configuration, la nuit ou depuis la page du plugin.', __FILE__),
            'duplicates' => __('copie(s) de sauvegardes déjà présentes sur la box : lues une seule fois.', __FILE__),
            'backups' => __('sauvegarde(s)', __FILE__),
            'readConfirm' => __('La liste des dossiers va être enregistrée, puis les sauvegardes jamais lues seront lues en tâche de fond (une à plusieurs minutes chacune). Rien n\'est modifié dans Jeedom.', __FILE__),
            'readTitle' => __('Lire les sauvegardes', __FILE__),
            'nothingToRead' => __('Dossiers enregistrés. Aucune sauvegarde à lire : toutes ont déjà été lues ou sont postérieures au premier relevé des noms.', __FILE__),
            'starting' => __('Lancement de la lecture...', __FILE__),
            'stepTimeline' => __('Relevé des noms actuels et de l\'historique...', __FILE__),
            'stepBackup' => __('Lecture de la sauvegarde', __FILE__),
            'stepReport' => __('Mise à jour du rapport d\'audit...', __FILE__),
            'found' => __('nom(s) retrouvé(s)', __FILE__),
            'done' => __('Lecture terminée :', __FILE__),
            'stillUnknown' => __('élément(s) supprimé(s) toujours sans nom.', __FILE__),
            'failed' => __('La lecture a échoué :', __FILE__),
            'background' => __('Elle continue si vous quittez la page.', __FILE__)
          ), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        var AJAX_URL = 'plugins/auditsante/core/ajax/auditsante.ajax.php'
        var list = document.getElementById('sa_backupDirs')
        var store = document.querySelector('.configKey[data-l1key="extraBackupDirs"]')
        if (!list || !store) return

        function esc(_text) {
          var div = document.createElement('div')
          div.textContent = _text == null ? '' : String(_text)
          return div.innerHTML
        }

        function call(_action, _data, _success) {
          var body = new FormData()
          body.append('action', _action)
          Object.keys(_data || {}).forEach(function (key) { body.append(key, _data[key]) })
          fetch(AJAX_URL, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (response) { return response.json() })
            .then(function (data) { _success(data.state == 'ok' ? data.result : null, data.state == 'ok' ? '' : data.result) })
            .catch(function (error) { _success(null, String(error)) })
        }

        /** Recopie la liste dans le champ enregistré par Jeedom (sans doublon ni ligne vide). */
        function sync() {
          var seen = {}
          var dirs = []
          list.querySelectorAll('.sa-dir-path').forEach(function (input) {
            var dir = input.value.trim().replace(/\/+$/, '')
            if (dir !== '' && !seen[dir]) {
              seen[dir] = true
              dirs.push(dir)
            }
          })
          if (store.value !== dirs.join('\n')) {
            store.value = dirs.join('\n')
            // Signale la modification au core (bouton de sauvegarde, avertissement en quittant la page)
            store.dispatchEvent(new Event('change', { bubbles: true }))
          }
          document.getElementById('bt_saTestAllBackupDirs').style.display = dirs.length > 1 ? '' : 'none'
        }

        function addRow(_dir, _test) {
          var row = document.createElement('div')
          row.className = 'sa-dir'
          row.innerHTML = '<div class="input-group">'
            + '<input type="text" class="form-control sa-dir-path" placeholder="/mnt/nas/jeedom-backups" value="' + esc(_dir || '') + '">'
            + '<span class="input-group-btn">'
            + '<a class="btn btn-default sa-dir-test" title="' + esc(T.testTitle) + '"><i class="fas fa-vial"></i> ' + T.test + '</a>'
            + '<a class="btn btn-danger sa-dir-remove" title="' + esc(T.removeTitle) + '"><i class="fas fa-trash"></i></a>'
            + '</span></div><div class="sa-dir-result" style="display:none;"></div>'
          list.appendChild(row)
          if (_test) testRow(row)
          return row
        }

        function testRow(_row) {
          var dir = _row.querySelector('.sa-dir-path').value.trim()
          var box = _row.querySelector('.sa-dir-result')
          var button = _row.querySelector('.sa-dir-test')
          if (dir === '') {
            box.style.display = 'none'
            return
          }
          box.className = 'sa-dir-result'
          box.style.display = ''
          box.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + T.testing
          button.classList.add('disabled')
          call('testBackupDir', { dir: dir }, function (_result, _error) {
            button.classList.remove('disabled')
            if (!_result) {
              box.className = 'sa-dir-result is-danger'
              box.innerHTML = '<i class="fas fa-times"></i> ' + esc(_error || T.commError)
              return
            }
            var icons = { success: 'fas fa-check', warning: 'fas fa-exclamation-triangle', danger: 'fas fa-times' }
            var html = '<b><i class="' + icons[_result.level] + '"></i> ' + esc(_result.message) + '</b>'
            var details = []
            if (_result.newest) {
              details.push(T.newest + ' ' + esc(_result.newest.name) + ' (' + esc(_result.newest.date) + ', ' + esc(_result.newest.size) + ')')
              var archive = {
                ok: '<i class="fas fa-check"></i> ' + T.archiveOk,
                missing: '<i class="fas fa-exclamation-triangle"></i> ' + T.archiveMissing,
                timeout: '<i class="fas fa-hourglass-half"></i> ' + T.archiveTimeout
              }
              if (archive[_result.archive]) details.push(T.content + ' ' + archive[_result.archive])
            }
            if (_result.unreadable > 0) {
              details.push(_result.unreadable + ' ' + T.unreadable + ' ' + esc(_result.user) + ' : ' + T.ignored)
            }
            if (_result.read > 0 || _result.known > 0) {
              details.push(_result.read + ' ' + T.alreadyRead + (_result.known > _result.read ? ', ' + (_result.known - _result.read) + ' ' + T.skipped : ''))
            } else if (_result.count > 0) {
              details.push(T.notRead)
            }
            if (_result.duplicates > 0) {
              details.push(_result.duplicates + ' ' + T.duplicates)
            }
            if (details.length) html += '<ul><li>' + details.join('</li><li>') + '</li></ul>'
            box.className = 'sa-dir-result is-' + _result.level
            box.innerHTML = html
          })
        }

        function renderSuggestions(_suggestions) {
          var wrap = document.getElementById('sa_backupSuggestions')
          var current = store.value.split('\n')
          var items = (_suggestions || []).filter(function (s) { return current.indexOf(s.dir) < 0 })
          wrap.style.display = items.length ? '' : 'none'
          wrap.querySelector('.sa-suggestions').innerHTML = items.map(function (s) {
            var title = (s.label ? s.label + ' - ' : '') + s.count + ' ' + T.backups
            return '<a class="btn btn-xs btn-default sa-suggestion" data-dir="' + esc(s.dir) + '" title="' + esc(title) + '">'
              + '<i class="fas ' + (s.label ? 'fa-network-wired' : 'fa-folder') + '"></i> ' + esc(s.dir)
              + (s.count > 0 ? ' <span class="label label-info">' + s.count + '</span>' : '') + '</a>'
          }).join('')
        }

        list.addEventListener('click', function (event) {
          var row = event.target.closest('.sa-dir')
          if (!row) return
          if (event.target.closest('.sa-dir-test') && !event.target.closest('.disabled')) testRow(row)
          if (event.target.closest('.sa-dir-remove')) {
            row.remove()
            sync()
          }
        })
        list.addEventListener('input', function (event) {
          if (event.target.classList.contains('sa-dir-path')) {
            event.target.closest('.sa-dir').querySelector('.sa-dir-result').style.display = 'none'
            sync()
          }
        })
        list.addEventListener('keydown', function (event) {
          if (event.key === 'Enter' && event.target.classList.contains('sa-dir-path')) {
            event.preventDefault()
            testRow(event.target.closest('.sa-dir'))
          }
        })
        document.getElementById('bt_saAddBackupDir').addEventListener('click', function () {
          addRow('', false).querySelector('.sa-dir-path').focus()
        })
        document.getElementById('bt_saTestAllBackupDirs').addEventListener('click', function () {
          list.querySelectorAll('.sa-dir').forEach(testRow)
        })
        document.getElementById('sa_backupSuggestions').addEventListener('click', function (event) {
          var link = event.target.closest('.sa-suggestion')
          if (!link) return
          var empty = Array.prototype.find.call(list.querySelectorAll('.sa-dir-path'), function (input) { return input.value.trim() === '' })
          var row = empty ? empty.closest('.sa-dir') : addRow('', false)
          row.querySelector('.sa-dir-path').value = link.getAttribute('data-dir')
          link.remove()
          sync()
          testRow(row)
        })

        /* ------------- Lecture des sauvegardes (tâche de fond du plugin) ------------- */
        var jobBox = document.getElementById('sa_backupJob')
        var readButton = document.getElementById('bt_saReadBackupDirs')
        var pollTimer = null

        function showJob(_job) {
          if (!_job) return
          var running = _job.status == 'running' || _job.status == 'auditing'
          readButton.classList.toggle('disabled', running)
          jobBox.style.display = ''
          if (running) {
            var text = T.starting
            if (_job.step == 'timeline') text = T.stepTimeline
            if (_job.step == 'backup') text = T.stepBackup + ' ' + ((_job.index || 0) + 1) + '/' + _job.total + ' : ' + esc(_job.backup) + (_job.size ? ' (' + esc(_job.size) + ')' : '')
            if (_job.step == 'report') text = T.stepReport
            jobBox.className = 'sa-dir-result'
            jobBox.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + text
              + (_job.found > 0 ? '<br>' + _job.found + ' ' + T.found : '') + '<br><small>' + T.background + '</small>'
            clearTimeout(pollTimer)
            pollTimer = setTimeout(function () {
              if (!document.body.contains(jobBox)) return // Page de configuration fermée
              call('recoverJob', {}, function (_next) { if (_next) showJob(_next) })
            }, 3000)
            return
          }
          if (_job.status == 'error') {
            jobBox.className = 'sa-dir-result is-danger'
            jobBox.innerHTML = '<i class="fas fa-times"></i> ' + T.failed + ' ' + esc(_job.message)
          } else if (_job.status == 'done') {
            jobBox.className = 'sa-dir-result is-success'
            jobBox.innerHTML = '<i class="fas fa-check"></i> ' + T.done + ' ' + _job.found + ' ' + T.found
              + (_job.unknown > 0 ? ', ' + _job.unknown + ' ' + T.stillUnknown : '.')
          }
        }

        function readBackups() {
          sync()
          var run = function () {
            readButton.classList.add('disabled')
            jobBox.className = 'sa-dir-result'
            jobBox.style.display = ''
            jobBox.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + T.starting
            call('readBackupDirs', { dirs: store.value }, function (_result, _error) {
              readButton.classList.remove('disabled')
              if (!_result) {
                jobBox.className = 'sa-dir-result is-danger'
                jobBox.innerHTML = '<i class="fas fa-times"></i> ' + esc(_error || T.commError)
                return
              }
              // La valeur est enregistrée côté serveur : plus de modification en attente pour ce champ
              store.value = _result.dirs.join('\n')
              if (!_result.job) {
                jobBox.className = 'sa-dir-result is-success'
                jobBox.innerHTML = '<i class="fas fa-check"></i> ' + T.nothingToRead
                return
              }
              showJob(_result.job)
            })
          }
          if (typeof jeeDialog !== 'undefined' && typeof jeeDialog.confirm === 'function') {
            jeeDialog.confirm({ title: T.readTitle, message: T.readConfirm, callback: function (_ok) { if (_ok) run() } })
          } else if (window.confirm(T.readConfirm)) {
            run()
          }
        }

        readButton.addEventListener('click', function () {
          if (!readButton.classList.contains('disabled')) readBackups()
        })

        // Lecture déjà en cours (lancée depuis la page du plugin ou la nuit) : on l'affiche
        call('recoverJob', {}, function (_job) {
          if (_job && (_job.status == 'running' || _job.status == 'auditing')) showJob(_job)
        })

        call('backupDirs', {}, function (_result) {
          var dirs = _result ? _result.dirs : store.value.split('\n').filter(function (d) { return d.trim() !== '' })
          dirs.forEach(function (dir) { addRow(dir, false) })
          if (dirs.length == 0) addRow('', false)
          sync()
          if (_result) renderSuggestions(_result.suggestions)
        })
      })()
    </script>
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
