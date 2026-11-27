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

/* Page du plugin Audit Santé : affichage du rapport, lancement de l'audit, corrections.
 * Écrit sans jQuery (compatible avec le mode « jqueryless » du core) et encapsulé pour supporter
 * le rechargement de la page par le core. */
(function () {
  var AJAX_URL = 'plugins/auditsante/core/ajax/auditsante.ajax.php'
  var GAUGE_LENGTH = 2 * Math.PI * 52

  var STATUS = {
    fail: { icon: 'fas fa-times', label: '{{À corriger}}' },
    warn: { icon: 'fas fa-exclamation', label: '{{À vérifier}}' },
    pass: { icon: 'fas fa-check', label: '{{Rien à signaler}}' },
    info: { icon: 'fas fa-info', label: '{{Information}}' },
    skip: { icon: 'fas fa-minus', label: '{{Non vérifiable}}' },
    pending: { icon: 'far fa-circle', label: '{{Non vérifié}}' }
  }
  var SEVERITY = {
    critical: '{{Critique}}',
    high: '{{Élevée}}',
    medium: '{{Moyenne}}',
    low: '{{Faible}}',
    info: '{{Info}}'
  }
  var VERDICT = {
    A: '{{En pleine forme}}',
    B: '{{Bon état général}}',
    C: '{{Un peu de ménage à faire}}',
    D: '{{Entretien nécessaire}}',
    E: '{{Mauvais état}}',
    F: '{{État critique}}'
  }
  var STATUS_ORDER = { fail: 0, warn: 1, skip: 2, pending: 3, info: 4, pass: 5 }
  var SEVERITY_ORDER = { critical: 0, high: 1, medium: 2, low: 3, info: 4 }
  var GRADE_COLORS = { A: '#22a861', B: '#7cb342', C: '#e0a800', D: '#f08c2a', E: '#e5532d', F: '#d32f2f' }
  // Durées proposées pour ignorer un contrôle, en jours (0 = sans limite) : identiques à auditsante::IGNORE_DAYS
  var IGNORE_DURATIONS = [
    [7, '{{7 jours}}'],
    [30, '{{30 jours}}'],
    [90, '{{3 mois}}'],
    [365, '{{1 an}}'],
    [0, '{{Sans limite}}']
  ]
  var COMMENT_MAX = 1000

  var state = {
    report: (typeof saReport !== 'undefined') ? saReport : null,
    filter: 'todo',
    category: '',
    search: '',
    open: {},
    // Formulaire ouvert dans une carte : {id, type: 'comment' | 'ignore'}
    editing: null,
    // Recherche des noms dans les sauvegardes : état côté serveur, préparation en cours, suivi
    job: (typeof saReport !== 'undefined' && saReport) ? saReport.recoverJob : null,
    preparing: false,
    pollTimer: null,
    reportTimer: null,
    jobDismissed: null,
    running: false
  }

  /* ----------------------------- Utilitaires ----------------------------- */

  function esc(_text) {
    return String(_text === null || _text === undefined ? '' : _text)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;')
  }

  function alertMessage(_message, _level) {
    if (typeof jeedomUtils !== 'undefined' && typeof jeedomUtils.showAlert === 'function') {
      jeedomUtils.showAlert({ message: _message, level: _level || 'danger' })
    } else {
      window.alert(_message)
    }
  }

  function confirmDialog(_title, _message, _callback) {
    if (typeof jeeDialog !== 'undefined' && typeof jeeDialog.confirm === 'function') {
      jeeDialog.confirm({ title: _title, message: _message, callback: function (result) { if (result) _callback() } })
    } else if (window.confirm(_title + '\n\n' + _message)) {
      _callback()
    }
  }

  function request(_action, _data, _success, _always) {
    var body = new URLSearchParams()
    body.append('action', _action)
    Object.keys(_data || {}).forEach(function (key) { body.append(key, _data[key]) })
    fetch(AJAX_URL, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (response) { return response.json() })
      .then(function (data) {
        if (data.state != 'ok') {
          alertMessage(data.result, 'danger')
          return
        }
        _success(data.result)
      })
      .catch(function (error) { alertMessage('{{Erreur de communication :}} ' + error, 'danger') })
      .finally(function () { if (_always) _always() })
  }

  function formatDate(_date) {
    if (!_date) return ''
    var d = new Date(_date.replace(' ', 'T'))
    if (isNaN(d.getTime())) return _date
    return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  }

  function formatDay(_date) {
    if (!_date) return ''
    var d = new Date(_date.replace(' ', 'T'))
    return isNaN(d.getTime()) ? _date : d.toLocaleDateString()
  }

  function gradeFor(_score) {
    if (_score >= 90) return 'A'
    if (_score >= 80) return 'B'
    if (_score >= 65) return 'C'
    if (_score >= 50) return 'D'
    if (_score >= 35) return 'E'
    return 'F'
  }

  /** Texte d'un élément de liste : ancien format (chaîne) ou {text, url}. */
  function itemText(_item) {
    return (_item && typeof _item === 'object') ? (_item.text || '') : String(_item)
  }

  /** Icône d'un élément de liste, d'après la page Jeedom vers laquelle il pointe. */
  function itemIcon(_url) {
    if (!_url) return 'fas fa-cube'
    var match = /[?&]p=([A-Za-z0-9_]+)/.exec(_url)
    var icons = {
      scenario: 'fas fa-cogs', object: 'far fa-object-group', view: 'far fa-image', plan: 'fas fa-paint-brush', planHeader: 'fas fa-paint-brush',
      plan3d: 'fas fa-cubes', plan3dHeader: 'fas fa-cubes', interact: 'far fa-comments', plugin: 'fas fa-puzzle-piece', log: 'far fa-file-alt', widgets: 'fas fa-camera-retro',
      dataStore: 'fas fa-code', cron: 'far fa-clock', administration: 'fas fa-wrench', user: 'far fa-user', update: 'fas fa-sync-alt'
    }
    if (match && icons[match[1]]) return icons[match[1]]
    if (/[?&]modal=dataStore\./.test(_url)) return icons.dataStore
    if (/[?&]modal=cmd\./.test(_url)) return 'fas fa-microchip'
    return /[?&]m=/.test(_url) ? 'fas fa-microchip' : 'fas fa-link'
  }

  /** Texte enrichi : [Objet][Équipement] en fil d'Ariane, références #1234# en pastille. */
  function richText(_text) {
    return esc(_text)
      .replace(/(?:\[[^\[\]]+\])+/g, function (path) {
        var names = path.slice(1, -1).split('][')
        var last = names.pop()
        return '<span class="auditsante-path">'
          + names.map(function (name) { return '<span>' + name + '</span><i class="fas fa-chevron-right"></i>' }).join('')
          + '<b>' + last + '</b></span>'
      })
      .replace(/#[A-Za-z]*\d+#/g, '<code class="auditsante-ref">$&</code>')
  }

  /** Coupe « nom : détail » au premier deux-points situé hors des crochets et des parenthèses. */
  function splitItem(_text) {
    var depth = 0
    for (var i = 0; i < _text.length; i++) {
      var c = _text[i]
      if (c === '[' || c === '(') depth++
      else if (c === ']' || c === ')') depth = Math.max(0, depth - 1)
      else if (c === ':' && depth === 0 && i > 0 && _text[i + 1] === ' ') return [_text.slice(0, i).trim(), _text.slice(i + 1).trim()]
    }
    return [_text, '']
  }

  /** Dernière parenthèse d'un texte : {text, note}, note vide s'il ne se termine pas par « (...) ». */
  function splitNote(_text) {
    if (_text.slice(-1) !== ')') return { text: _text, note: '' }
    var depth = 0
    for (var i = _text.length - 1; i > 0; i--) {
      if (_text[i] === ')') depth++
      else if (_text[i] === '(' && --depth === 0) {
        return (_text[i - 1] === ' ') ? { text: _text.slice(0, i).trim(), note: _text.slice(i + 1, -1) } : { text: _text, note: '' }
      }
    }
    return { text: _text, note: '' }
  }

  /** Une parenthèse longue est une explication (seconde ligne), une courte un simple qualificatif. */
  function isLongNote(_note) {
    return _note.length > 24 || _note.indexOf('[') !== -1
  }

  /** Découpe un élément de liste : nom, qualificatifs, détail et explication. */
  function parseItem(_item) {
    var parts = splitItem(itemText(_item))
    var isObject = (_item && typeof _item === 'object')
    var parsed = { head: parts[0], url: (isObject && _item.url) || '', exclude: (isObject && _item.exclude) || '', repair: (isObject && _item.repair) || '', repairLabel: (isObject && _item.repairLabel) || '', tags: [], text: parts[1], note: '' }
    var cut
    while ((cut = splitNote(parsed.head)).note !== '') {
      parsed.head = cut.text
      if (!isLongNote(cut.note)) parsed.tags.unshift(cut.note)
      else parsed.note = cut.note + (parsed.note ? ' · ' + parsed.note : '')
    }
    cut = splitNote(parsed.text)
    if (cut.note !== '' && isLongNote(cut.note)) {
      parsed.text = cut.text
      parsed.note = cut.note + (parsed.note ? ' · ' + parsed.note : '')
    }
    return parsed
  }

  /**
   * Liste des éléments d'un contrôle : les lignes consécutives d'un même élément sont regroupées.
   * _excludeMode : bouton des éléments qui portent une clé d'exclusion. « unused » : « Exclure » (variable utilisée
   * sans que l'audit le voie) ; « voluntary » : « Exclure » (élément voulu, affiché à part) ; « include » : « Réintégrer » ; vide : aucun bouton.
   */
  function renderItems(_items, _excludeMode) {
    var groups = []
    _items.forEach(function (item) {
      var parsed = parseItem(item)
      var key = parsed.head + '|' + parsed.tags.join('|') + '|' + parsed.url + '|' + parsed.exclude + '|' + parsed.repair
      var last = groups[groups.length - 1]
      if (!last || last.key !== key || parsed.text === '') {
        last = { key: key, head: parsed.head, tags: parsed.tags, url: parsed.url, exclude: _excludeMode ? parsed.exclude : '', excludeMode: _excludeMode || '', repair: parsed.repair, repairLabel: parsed.repairLabel, lines: [] }
        groups.push(last)
      }
      if (parsed.text !== '' || parsed.note !== '') last.lines.push(parsed)
    })
    return '<ul class="auditsante-items">' + groups.map(renderItemGroup).join('') + '</ul>'
  }

  function renderItemGroup(_group) {
    // « ... et 12 autre(s) »
    if (_group.head.indexOf('... ') === 0) return '<li class="auditsante-item-more">' + esc(_group.head) + '</li>'
    var lines = _group.lines
    // Un seul détail court : à droite du nom, sur la même ligne
    var inline = (lines.length == 1 && lines[0].note === '' && lines[0].text.length <= 60)
    // « Scénario [Groupe][Nom] » : le type est séparé du nom
    var kind = /^([^\[\]#]+) (\[.*\])$/.exec(_group.head)
    var html = '<span class="auditsante-item-icon"><i class="' + itemIcon(_group.url) + '"></i></span>'
    html += '<span class="auditsante-item-main"><span class="auditsante-item-head">'
      + (kind ? '<span class="auditsante-item-kind">' + esc(kind[1]) + '</span>' : '')
      + '<span class="auditsante-item-name">' + richText(kind ? kind[2] : _group.head) + '</span>'
    _group.tags.forEach(function (tag) { html += '<span class="auditsante-item-tag">' + esc(tag) + '</span>' })
    if (inline) html += '<span class="auditsante-item-value">' + richText(lines[0].text) + '</span>'
    html += '</span>'
    if (!inline) {
      lines.forEach(function (line) {
        html += '<span class="auditsante-item-line">'
        if (line.text !== '') html += '<span class="auditsante-item-text">' + richText(line.text) + '</span>'
        if (line.note !== '') html += '<span class="auditsante-item-note">' + richText(line.note) + '</span>'
        html += '</span>'
      })
    }
    html += '</span>'
    var title = /modal=cmd\.configure/.test(_group.url) ? '{{Configuration avancée de la commande}}' : '{{Ouvrir}}'
    var row = _group.url
      ? '<a class="auditsante-item" href="' + esc(_group.url) + '" target="_blank" rel="noopener" title="' + title + '">' + html + '<i class="fas fa-external-link-alt auditsante-item-open"></i></a>'
      : '<span class="auditsante-item">' + html + '</span>'
    // Retrait de l'action orpheline (commande ou équipement), via Jeedom
    if (_group.repair) {
      return '<li class="auditsante-item-row">' + row
        + '<button type="button" class="auditsante-item-repair auditsante-bt-repair" data-key="' + esc(_group.repair) + '" title="{{L\'action ou le paramètre qui utilise cet élément supprimé est retiré, puis la commande est enregistrée par Jeedom}}"><i class="fas fa-trash-alt"></i> ' + esc(_group.repairLabel || '{{Supprimer la référence orpheline}}') + '</button></li>'
    }
    if (!_group.exclude) return '<li>' + row + '</li>'
    // Bouton hors du lien : un clic dessus ne doit pas ouvrir la page de l'élément
    return '<li class="auditsante-item-row">' + row + excludeButton(_group.exclude, _group.excludeMode) + '</li>'
  }

  /** Bouton d'exclusion d'un élément, selon le contrôle (voir renderItems). */
  function excludeButton(_key, _mode) {
    var buttons = {
      unused: ['auditsante-bt-exclude auditsante-item-excludable', 'fas fa-eye-slash', '{{Exclure}}', '{{Exclure de l\'audit : il sert, mais l\'audit ne le voit pas}}'],
      voluntary: ['auditsante-bt-exclude auditsante-item-excludable', 'fas fa-eye-slash', '{{Exclure}}', '{{Exclure de l\'audit : l\'élément reste affiché, mais ne compte plus dans la note}}'],
      include: ['auditsante-bt-include auditsante-item-excludable', 'fas fa-undo', '{{Réintégrer}}', '{{Réintégrer dans l\'audit : l\'élément compte de nouveau dans la note}}']
    }
    var b = buttons[_mode] || buttons.unused
    return '<button type="button" class="auditsante-item-exclude ' + b[0] + '" data-key="' + esc(_key) + '" title="' + b[3] + '"><i class="' + b[1] + '"></i> ' + b[2] + '</button>'
  }

  /**
   * Éléments que l'utilisateur a exclus du contrôle, chacun avec un bouton pour le réintégrer.
   * Éléments complets (marqués comme voulus) ou simples clés (variables).
   */
  function renderExcluded(_check) {
    var voluntary = (_check.excludeMode == 'voluntary')
    var html = '<div class="auditsante-excluded"><h4><i class="fas ' + 'fa-eye-slash' + '"></i> '
      + (voluntary ? '{{Exclus de l\'audit : affichés pour information, ils ne comptent pas dans la note}}' : '{{Exclus de l\'audit}}')
      + ' (' + _check.excluded.length + ')</h4>'
    if (_check.excluded.length > 0 && typeof _check.excluded[0] === 'object') {
      return html + renderItems(_check.excluded, _check.ignored ? '' : 'include') + '</div>'
    }
    html += '<ul class="auditsante-items">'
    _check.excluded.forEach(function (key) {
      html += '<li class="auditsante-item-row"><span class="auditsante-item"><span class="auditsante-item-main"><span class="auditsante-item-head"><span class="auditsante-item-name">' + esc(key) + '</span></span></span></span>'
        + excludeButton(key, 'include') + '</li>'
    })
    return html + '</ul></div>'
  }

  /* --------------- Recherche des noms des éléments supprimés --------------- */

  /** Lecture des sauvegardes en cours, ou rapport en cours de mise à jour juste après (« auditing »). */
  function jobRunning() {
    return !!(state.job && ['running', 'auditing'].indexOf(state.job.status) >= 0)
  }

  /** Lecture des sauvegardes en cours alors qu'aucun audit n'a encore été fait : l'en-tête du rapport la montre. */
  function scanFirst() {
    return jobRunning() && !(state.report && state.report.date)
  }

  /** Durée lisible : « 45 s », « 3 min 12 s ». */
  function formatDuration(_seconds) {
    var seconds = Math.max(0, Math.round(_seconds || 0))
    if (seconds < 60) return seconds + ' s'
    return Math.floor(seconds / 60) + ' min ' + (seconds % 60 < 10 ? '0' : '') + (seconds % 60) + ' s'
  }

  /** Étape en cours : « Sauvegarde 2/5 », « Relevé et timeline »... */
  function jobStep(_job) {
    if (_job.status == 'auditing' || _job.step == 'report') return '{{Mise à jour du rapport}}'
    if (_job.step == 'start') return '{{Démarrage}}'
    if (_job.step == 'timeline' || !_job.total) return '{{Relevé et timeline}}'
    return '{{Sauvegarde}} ' + ((_job.index || 0) + 1) + '/' + _job.total
  }

  /** Détail de l'étape en cours, sur une ligne. */
  function jobDetail(_job) {
    var parts = []
    if (_job.backup && _job.step == 'backup') parts.push(esc(_job.backup) + (_job.size ? ' (' + esc(_job.size) + ')' : ''))
    if (_job.elapsed !== null && _job.elapsed !== undefined) parts.push('{{depuis}} ' + formatDuration(_job.elapsed))
    parts.push(_job.found + ' {{nom(s) retrouvé(s)}}')
    return parts.join(' · ')
  }

  /** Avancement en pourcentage : sauvegardes lues, plus une demi-sauvegarde pour celle en cours. */
  function jobPercent(_job) {
    if (_job.step == 'report') return 98
    if (!_job.total) return 50
    return Math.min(95, Math.round(100 * ((_job.index || 0) + 0.5) / _job.total))
  }

  /** Vrai si le contrôle a des éléments supprimés à nommer, une recherche en cours ou un résultat de recherche à montrer. */
  function canRecover(_check) {
    if (!_check.recover || _check.ignored || ['fail', 'warn'].indexOf(_check.status) < 0) return false
    return _check.unnamed !== 0 || _check.notFound > 0 || jobRunning()
  }

  /** Vrai s'il reste des éléments jamais cherchés (null : rapport d'une version précédente, inconnu). */
  function hasUnnamed(_check) {
    return _check.unnamed === null || _check.unnamed === undefined || _check.unnamed > 0
  }

  /** Archive des noms : sauvegardes encore à lire, taille, date où elle remonte... (voir auditsanteRecovery::archiveInfo()). */
  function archiveInfo() {
    return (state.report && state.report.namesArchive) || {}
  }

  /** Seule action de l'archive des noms : premier scan des sauvegardes, ou lecture des nouvelles. */
  function recoverButton() {
    var archive = archiveInfo()
    var busy = state.preparing || jobRunning()
    var label = state.preparing ? '{{Préparation...}}'
      : (archive.initialized ? '{{Lire}} ' + archive.pending + ' ' + (archive.pending > 1 ? '{{nouvelles sauvegardes}}' : '{{nouvelle sauvegarde}}') : '{{Lancer le premier scan}}')
    return '<a class="auditsante-btn auditsante-btn-primary auditsante-bt-recover' + (busy ? ' is-disabled' : '') + '">'
      + '<i class="' + (busy ? 'fas fa-circle-notch fa-spin' : 'fas fa-play') + '"></i> '
      + '<span>' + esc(label) + '</span></a>'
  }

  /**
   * Encadré d'information en tête du détail (sans bouton : la seule action est dans le bandeau en haut du rapport) :
   * lecture en cours, éléments que le scan des sauvegardes pourra nommer, ou éléments absents de l'archive.
   */
  function renderRecoverCallout(_check) {
    var archive = archiveInfo()
    if (jobRunning()) {
      return '<div class="auditsante-recover is-running">'
        + '<span class="auditsante-recover-icon"><i class="fas fa-circle-notch fa-spin"></i></span>'
        + '<span class="auditsante-recover-text"><b>{{Lecture des sauvegardes en cours}} : ' + esc(jobStep(state.job)) + '</b>'
        + '<span>' + jobDetail(state.job) + '</span>'
        + '<span class="auditsante-recover-bar"><span style="width:' + jobPercent(state.job) + '%"></span></span></span>'
        + '</div>'
    }
    if (hasUnnamed(_check)) {
      var count = _check.unnamed
      var title = (count === null || count === undefined)
        ? '{{Ces éléments supprimés ne sont plus que des numéros}}'
        : count + ' ' + (count > 1 ? '{{éléments supprimés ne sont plus que des numéros}}' : '{{élément supprimé n\'est plus qu\'un numéro}}')
      return '<div class="auditsante-recover is-info">'
        + '<span class="auditsante-recover-icon"><i class="fas fa-history"></i></span>'
        + '<span class="auditsante-recover-text"><b>' + esc(title) + '</b>'
        + '<span>' + (archive.initialized
          ? '{{Leur nom sera recherché dans les nouvelles sauvegardes, lues cette nuit ou tout de suite depuis le bandeau en haut du rapport.}}'
          : '{{Leur nom sera retrouvé par le premier scan des sauvegardes : lancez-le depuis le bandeau en haut du rapport.}}')
        + '</span></span>'
        + '<a class="auditsante-btn auditsante-btn-sm auditsante-btn-ghost auditsante-bt-goto-job"><i class="fas fa-arrow-up"></i> <span>{{Voir}}</span></a>'
        + '</div>'
    }
    // Toutes les sauvegardes ont été lues : ces éléments ont été supprimés avant la date où remonte l'archive
    var since = archive.since ? ' {{remonte au}} ' + esc(formatDay(archive.since)) : ''
    return '<div class="auditsante-recover is-muted">'
      + '<span class="auditsante-recover-icon"><i class="fas fa-search-minus"></i></span>'
      + '<span class="auditsante-recover-text"><b>' + _check.notFound + ' ' + (_check.notFound > 1 ? '{{éléments absents de l\'archive des noms}}' : '{{élément absent de l\'archive des noms}}') + '</b>'
      + '<span>{{L\'archive des noms}}' + since + ' (' + (archive.backups || 0) + ' {{sauvegarde(s) lue(s), puis un relevé toutes les heures}}). '
      + '{{Ces éléments ont été supprimés avant. Pour remonter plus loin, ajoutez dans la configuration du plugin le dossier où sont copiées vos anciennes sauvegardes (NAS, cloud).}}</span></span>'
      + '<a class="auditsante-btn auditsante-btn-sm auditsante-btn-ghost" href="index.php?v=d&p=plugin&id=auditsante" target="_blank" rel="noopener"><i class="fas fa-folder-plus"></i> <span>{{Ajouter un dossier de sauvegardes}}</span></a>'
      + '</div>'
  }

  /**
   * Bandeau de l'archive des noms, en haut du rapport : seul endroit où l'on agit sur elle.
   * Lecture en cours, échec, premier scan à lancer, ou nouvelles sauvegardes à lire ; rien sinon.
   */
  function renderJob() {
    var strip = document.getElementById('sa_job')
    if (!strip) return
    var job = state.job
    var archive = archiveInfo()
    var hasReport = !!(state.report && state.report.date)
    if (scanFirst()) {
      // L'en-tête du rapport montre déjà le premier scan
      strip.style.display = 'none'
      strip.innerHTML = ''
      return
    }
    if (jobRunning()) {
      strip.style.display = ''
      strip.className = 'auditsante-job'
      strip.innerHTML = '<span class="auditsante-job-icon"><i class="fas fa-circle-notch fa-spin"></i></span>'
        + '<span class="auditsante-job-text"><b>{{Lecture des sauvegardes pour l\'archive des noms}} : ' + esc(jobStep(job)) + '</b>'
        + '<span>' + jobDetail(job) + '</span>'
        + '<span class="auditsante-recover-bar"><span style="width:' + jobPercent(job) + '%"></span></span>'
        + '<small>{{Vous pouvez quitter cette page : la lecture continue en tâche de fond. Chaque sauvegarde n\'est lue qu\'une fois.}}</small></span>'
      return
    }
    if (job && job.status == 'error' && state.jobDismissed != job.finished) {
      strip.style.display = ''
      strip.className = 'auditsante-job is-error'
      strip.innerHTML = '<span class="auditsante-job-icon"><i class="fas fa-exclamation-triangle"></i></span>'
        + '<span class="auditsante-job-text"><b>{{La lecture des sauvegardes a échoué}}</b><span>' + esc(job.message) + '</span></span>'
        + recoverButton()
        + '<a class="auditsante-btn auditsante-btn-sm auditsante-btn-ghost auditsante-bt-job-dismiss">{{Fermer}}</a>'
      return
    }
    if (archive.pending > 0 && (archive.initialized || hasReport)) {
      var size = archive.pendingSize ? ' (' + esc(archive.pendingSize) + ')' : ''
      strip.style.display = ''
      strip.className = 'auditsante-job is-pending'
      strip.innerHTML = '<span class="auditsante-job-icon"><i class="fas fa-history"></i></span>'
        + (archive.initialized
          ? '<span class="auditsante-job-text"><b>' + archive.pending + ' ' + (archive.pending > 1 ? '{{nouvelles sauvegardes à lire}}' : '{{nouvelle sauvegarde à lire}}') + size + '</b>'
            + '<span>{{Elles complètent l\'archive des noms avec des suppressions plus anciennes. Elles seront lues cette nuit, ou tout de suite.}}</span></span>'
          : '<span class="auditsante-job-text"><b>{{Initialiser l\'archive des noms}}</b>'
            + '<span>{{Le plugin note toutes les heures le nom de chaque élément, pour nommer plus tard les références vers des éléments supprimés. Le premier scan lit une fois vos}} '
            + archive.pending + ' {{sauvegarde(s)}}' + size + ' {{pour retrouver aussi les éléments supprimés avant son installation. Sinon, il se lancera cette nuit.}}</span></span>')
        + recoverButton()
      return
    }
    strip.style.display = 'none'
    strip.innerHTML = ''
  }

  /** Met à jour l'archive affichée sans redessiner toute la page (un commentaire en cours de saisie est préservé). */
  function refreshRecover() {
    renderJob()
    document.querySelectorAll('#sa_checks .auditsante-check').forEach(function (row) {
      var box = row.querySelector('.auditsante-recover')
      var check = findCheck(row.getAttribute('data-id'))
      if (box && check && canRecover(check)) box.outerHTML = renderRecoverCallout(check)
    })
  }

  function isTodo(_check) {
    return !_check.ignored && ['fail', 'warn'].indexOf(_check.status) >= 0
  }

  /** Vrai si le contrôle correspond au filtre, à la catégorie et à la recherche courants. */
  function matches(_check, _ignoreFilter) {
    if (state.category != '' && _check.category != state.category) return false
    if (!_ignoreFilter) {
      var filter = state.filter
      if (filter == 'ignored' && !_check.ignored) return false
      if (filter != 'ignored' && filter != 'all' && _check.ignored) return false
      if (filter == 'todo' && !isTodo(_check)) return false
      if (filter == 'pass' && _check.status != 'pass') return false
      // Le filtre Informations regroupe aussi les contrôles non vérifiables
      if (filter == 'info' && ['info', 'skip'].indexOf(_check.status) < 0) return false
    }
    if (state.search != '') {
      var text = (_check.title + ' ' + _check.detail + ' ' + _check.description + ' ' + (_check.items || []).map(itemText).join(' ') + ' ' + (_check.comment ? _check.comment.text : '')).toLowerCase()
      if (text.indexOf(state.search) < 0) return false
    }
    return true
  }

  function byPriority(a, b) {
    return (STATUS_ORDER[a.status] - STATUS_ORDER[b.status]) || (SEVERITY_ORDER[a.severity] - SEVERITY_ORDER[b.severity])
  }

  function ring(_score, _size, _stroke) {
    var r = (_size - _stroke) / 2
    var length = 2 * Math.PI * r
    var color = (_score === null) ? 'transparent' : GRADE_COLORS[gradeFor(_score)]
    var offset = (_score === null) ? length : length * (1 - _score / 100)
    return '<svg class="auditsante-ring" width="' + _size + '" height="' + _size + '" viewBox="0 0 ' + _size + ' ' + _size + '" aria-hidden="true">'
      + '<circle class="auditsante-ring-track" cx="' + (_size / 2) + '" cy="' + (_size / 2) + '" r="' + r + '" stroke-width="' + _stroke + '"></circle>'
      + '<circle class="auditsante-ring-value" cx="' + (_size / 2) + '" cy="' + (_size / 2) + '" r="' + r + '" stroke-width="' + _stroke + '" stroke="' + color + '"'
      + ' stroke-dasharray="' + length.toFixed(2) + '" stroke-dashoffset="' + offset.toFixed(2) + '" transform="rotate(-90 ' + (_size / 2) + ' ' + (_size / 2) + ')"></circle>'
      + '</svg>'
  }

  /* ------------------------------- Rendu -------------------------------- */

  /**
   * Audit en cours côté serveur mais pas lancé depuis cette page (tâche planifiée, scénario, fin du premier scan,
   * page rechargée) : on suit son avancement toutes les 3 secondes et on affiche le rapport à la fin.
   */
  function pollReport() {
    if (state.reportTimer || state.running) return
    state.reportTimer = setTimeout(function () {
      state.reportTimer = null
      if (!document.getElementById('div_saReport')) return // Page quittée
      var wasRunning = state.report && state.report.running
      request('getReport', {}, function (report) {
        setReport(report)
        if (wasRunning && !report.running && report.date && !jobRunning()) {
          alertMessage('{{Audit terminé :}} ' + report.score + ' % (' + report.grade + ')', 'success')
        }
      }, function () {
        if (state.report && state.report.running) pollReport()
      })
    }, 3000)
  }

  function render() {
    var report = state.report
    if (!report || !document.getElementById('div_saReport')) return
    if (report.running && !state.running && !jobRunning()) pollReport()
    renderHero(report)
    renderJob()
    renderPriorities(report)
    renderCategories(report)
    renderChecks(report)
  }

  function renderHero(_report) {
    var color = GRADE_COLORS[_report.grade] || '#8a949e'
    var hero = document.getElementById('sa_hero')
    hero.style.setProperty('--auditsante-grade-color', color)
    hero.classList.toggle('is-empty', !_report.date)

    document.getElementById('sa_grade').textContent = _report.grade || '?'
    document.getElementById('sa_score').textContent = (_report.score === null) ? '-' : _report.score
    var gauge = document.getElementById('sa_gauge_value')
    gauge.style.strokeDasharray = GAUGE_LENGTH.toFixed(2)
    // Animation : la jauge part de zéro au premier affichage
    requestAnimationFrame(function () {
      gauge.style.strokeDashoffset = (GAUGE_LENGTH * (1 - (_report.score || 0) / 100)).toFixed(2)
    })

    var verdict = '{{Pas encore évalué}}'
    var archive = archiveInfo()
    // Avant le premier audit : étape 1, lire les sauvegardes (archive des noms) ; étape 2, lancer l'audit
    var firstStep = !_report.date && !archive.initialized
    var meta = archive.initialized
      ? '{{Sauvegardes lues : l\'archive des noms est prête. Étape 2 : lancez l\'audit.}}'
      : '{{Étape 1 : lire vos sauvegardes Jeedom, pour que le rapport puisse nommer les éléments supprimés. Vous lancerez ensuite l\'audit.}}'
        + (archive.pending ? ' ' + archive.pending + ' {{sauvegarde(s)}} (' + esc(archive.pendingSize) + ').' : '')
    var scanning = scanFirst()
    if (scanning) {
      verdict = '{{Lecture des sauvegardes...}}'
      meta = '<b>' + esc(jobStep(state.job)) + '</b> · ' + jobDetail(state.job)
        + '<span class="auditsante-recover-bar"><span style="width:' + jobPercent(state.job) + '%"></span></span>'
        + '<small class="auditsante-hero-note">{{Étape 1 sur 2. Vous pourrez lancer l\'audit à la fin de la lecture. Vous pouvez quitter cette page.}}</small>'
    } else if (state.running || _report.running) {
      verdict = '{{Audit en cours...}}'
      meta = '{{Les contrôles prennent de quelques secondes à une minute.}}'
    } else if (_report.date) {
      verdict = VERDICT[_report.grade] || ''
      meta = '<i class="far fa-clock"></i> {{Dernier audit le}} ' + esc(formatDate(_report.date)) + ' · ' + esc(_report.duration) + ' s'
    }
    document.getElementById('sa_verdict').textContent = verdict
    document.getElementById('sa_meta').innerHTML = meta

    var button = document.getElementById('bt_saRunAudit')
    // Audit lancé ailleurs (tâche planifiée, scénario, fin du premier scan) : le bouton est aussi désactivé
    var busy = state.running || scanning || !!_report.running
    button.classList.toggle('is-disabled', busy)
    button.querySelector('i').className = busy ? 'fas fa-circle-notch fa-spin' : (_report.date ? 'fas fa-redo' : (firstStep ? 'fas fa-history' : 'fas fa-play'))
    button.querySelector('span').textContent = scanning ? '{{Lecture des sauvegardes...}}' : ((state.running || _report.running) ? '{{Audit en cours...}}'
      : firstStep ? '{{Lire les sauvegardes}}' : (_report.date ? '{{Relancer l\'audit}}' : '{{Lancer l\'audit}}'))
    hero.classList.toggle('is-running', state.running || _report.running || scanning)

    var stats = [
      ['fail', 'fas fa-times-circle', '{{À corriger}}', 'todo'],
      ['warn', 'fas fa-exclamation-circle', '{{À vérifier}}', 'todo'],
      ['pass', 'fas fa-check-circle', '{{Rien à signaler}}', 'pass'],
      ['info', 'fas fa-info-circle', '{{Informations}}', 'info']
    ]
    var html = ''
    stats.forEach(function (stat) {
      var value = _report.date ? (_report.counts[stat[0]] || 0) : '-'
      html += '<a class="auditsante-stat auditsante-st-' + stat[0] + (value === 0 ? ' is-zero' : '') + '" data-filter="' + stat[3] + '">'
        + '<i class="' + stat[1] + '"></i><b>' + value + '</b><span>' + stat[2] + '</span></a>'
    })
    document.getElementById('sa_stats').innerHTML = html
  }

  function renderPriorities(_report) {
    var section = document.getElementById('sa_priorities_section')
    var list = _report.checks.filter(function (check) {
      return !check.ignored && (check.status == 'fail' || (check.status == 'warn' && SEVERITY_ORDER[check.severity] <= 1))
    }).sort(function (a, b) {
      return (SEVERITY_ORDER[a.severity] - SEVERITY_ORDER[b.severity]) || (STATUS_ORDER[a.status] - STATUS_ORDER[b.status])
    }).slice(0, 3)
    if (list.length == 0 || state.running) {
      section.style.display = 'none'
      return
    }
    section.style.display = ''
    var html = ''
    list.forEach(function (check) {
      html += '<div class="auditsante-priority auditsante-st-' + esc(check.status) + '" data-id="' + esc(check.id) + '">'
        + '<div class="auditsante-priority-top"><span class="auditsante-sev auditsante-sev-' + esc(check.severity) + '">' + esc(SEVERITY[check.severity]) + '</span>'
        + '<span class="auditsante-priority-cat">' + esc(_report.categories[check.category].name) + '</span></div>'
        + '<div class="auditsante-priority-title">' + esc(check.title) + '</div>'
        + '<div class="auditsante-priority-detail">' + esc(check.detail) + '</div>'
        + '<div class="auditsante-priority-actions">'
      if (check.fix && check.fix.type == 'auto') {
        html += '<a class="auditsante-btn auditsante-btn-sm auditsante-btn-accent auditsante-bt-fix" title="' + esc(check.fix.label) + '"><i class="fas fa-magic"></i> {{Corriger}}</a>'
      } else if (check.fix && check.fix.type == 'link') {
        html += '<a class="auditsante-btn auditsante-btn-sm auditsante-btn-ghost" href="' + esc(check.fix.url) + '" target="_blank" rel="noopener" title="' + esc(check.fix.label) + '"><i class="fas fa-external-link-alt"></i> {{Ouvrir}}</a>'
      }
      html += '<a class="auditsante-btn auditsante-btn-sm auditsante-btn-ghost auditsante-bt-show"><i class="fas fa-search"></i> {{Détails}}</a></div></div>'
    })
    document.getElementById('sa_priorities').innerHTML = html
  }

  function renderCategories(_report) {
    var html = ''
    Object.keys(_report.categories).forEach(function (id) {
      var category = _report.categories[id]
      var checks = _report.checks.filter(function (check) { return check.category == id })
      var todo = checks.filter(isTodo).length
      var sub = !_report.date ? checks.length + ' {{contrôles}}' : (todo > 0 ? todo + ' {{à traiter}}' : '{{Rien à signaler}}')
      html += '<a class="auditsante-category' + (state.category == id ? ' is-active' : '') + (todo > 0 ? ' has-todo' : '') + '" data-category="' + esc(id) + '">'
        + '<span class="auditsante-category-ring">' + ring(category.score, 46, 5) + '<i class="' + esc(category.icon) + '"></i></span>'
        + '<span class="auditsante-category-text"><b title="' + esc(category.name) + '">' + esc(category.name) + '</b><span>' + sub + '</span></span>'
        + '<span class="auditsante-category-score">' + (category.score === null ? '-' : category.score + '<small>%</small>') + '</span>'
        + '</a>'
    })
    document.getElementById('sa_categories').innerHTML = html
  }

  function renderChecks(_report) {
    // Compteurs des filtres (dans la catégorie et la recherche courantes)
    var scoped = _report.checks.filter(function (check) { return matches(check, true) })
    var counts = { todo: 0, pass: 0, info: 0, ignored: 0, all: scoped.length }
    scoped.forEach(function (check) {
      if (check.ignored) { counts.ignored++; return }
      if (isTodo(check)) counts.todo++
      if (check.status == 'pass') counts.pass++
      if (check.status == 'info' || check.status == 'skip') counts.info++
    })
    document.querySelectorAll('#sa_filters [data-count]').forEach(function (badge) {
      badge.textContent = counts[badge.getAttribute('data-count')]
    })
    document.querySelectorAll('#sa_filters [data-filter]').forEach(function (button) {
      button.classList.toggle('is-active', button.getAttribute('data-filter') == state.filter)
    })

    var chip = document.getElementById('sa_active_category')
    if (state.category != '' && _report.categories[state.category]) {
      chip.style.display = ''
      chip.innerHTML = '<span class="auditsante-chip"><i class="' + esc(_report.categories[state.category].icon) + '"></i> '
        + esc(_report.categories[state.category].name) + ' <a class="auditsante-chip-close" title="{{Toutes les catégories}}"><i class="fas fa-times"></i></a></span>'
    } else {
      chip.style.display = 'none'
    }

    var checks = _report.checks.filter(function (check) { return matches(check, false) }).sort(byPriority)
    var html = ''
    Object.keys(_report.categories).forEach(function (categoryId) {
      var list = checks.filter(function (check) { return check.category == categoryId })
      if (list.length == 0) return
      var category = _report.categories[categoryId]
      html += '<div class="auditsante-group"><div class="auditsante-group-title"><i class="' + esc(category.icon) + '"></i> ' + esc(category.name) + ' <span>' + list.length + '</span></div>'
      list.forEach(function (check) { html += renderCheck(check) })
      html += '</div>'
    })
    document.getElementById('sa_checks').innerHTML = html

    var empty = document.getElementById('sa_empty')
    if (checks.length > 0) {
      empty.style.display = 'none'
      return
    }
    empty.style.display = ''
    if (!_report.date) {
      empty.innerHTML = '<i class="fas fa-heartbeat"></i><b>{{Aucun audit pour le moment}}</b><span>{{Lancez un premier audit pour obtenir la note de votre installation.}}</span>'
    } else if (state.filter == 'todo' && state.search == '') {
      empty.innerHTML = '<i class="fas fa-trophy"></i><b>{{Rien à traiter : bravo !}}</b><span>{{Tous les contrôles de cette sélection ne signalent rien.}}</span>'
    } else {
      empty.innerHTML = '<i class="fas fa-search"></i><b>{{Aucun contrôle ne correspond.}}</b>'
    }
  }

  function renderCheck(_check) {
    var status = STATUS[_check.status] || STATUS.pending
    var isOpen = !!state.open[_check.id]
    var needsAction = ['fail', 'warn'].indexOf(_check.status) >= 0
    // Une information peut garder sa correction automatique
    var canFix = _check.fix && _check.fix.type == 'auto' && !_check.ignored && (needsAction || _check.status == 'info')
    var html = '<article class="auditsante-check auditsante-st-' + esc(_check.status) + (_check.ignored ? ' is-ignored' : '') + (isOpen ? ' is-open' : '') + '" data-id="' + esc(_check.id) + '">'
    html += '<header class="auditsante-check-head">'
    html += '<span class="auditsante-status" title="' + esc(status.label) + '"><i class="' + status.icon + '"></i></span>'
    html += '<span class="auditsante-check-title"><b>' + esc(_check.title) + '</b>'
    if (_check.detail) html += '<span>' + esc(_check.detail) + '</span>'
    html += '</span><span class="auditsante-check-tags">'
    if (_check.comment) html += '<span class="auditsante-tag auditsante-tag-comment" title="' + esc(_check.comment.text) + '"><i class="far fa-comment-dots"></i></span>'
    if (_check.ignored) {
      html += '<span class="auditsante-tag"><i class="fas fa-eye-slash"></i> '
        + (_check.ignoredUntil ? '{{Ignoré jusqu\'au}} ' + esc(formatDay(_check.ignoredUntil)) : '{{Ignoré}}') + '</span>'
    }
    if (canFix && needsAction) html += '<span class="auditsante-tag auditsante-tag-fix" title="{{Correction automatique disponible}}"><i class="fas fa-magic"></i></span>'
    // La couleur de la gravité n'est utile que s'il y a quelque chose à traiter : sinon badge neutre
    var sevClass = 'auditsante-sev auditsante-sev-' + esc(_check.severity) + ((needsAction && !_check.ignored) ? '' : ' is-muted')
    html += '<span class="' + sevClass + '" title="{{Importance du problème signalé par ce contrôle}}">' + esc(SEVERITY[_check.severity] || _check.severity) + '</span>'
    html += '</span><i class="fas fa-chevron-down auditsante-chevron"></i></header>'

    html += '<div class="auditsante-check-body"><div class="auditsante-check-inner">'
    if (canRecover(_check)) html += renderRecoverCallout(_check)
    if (_check.items && _check.items.length > 0) {
      html += renderItems(_check.items, (_check.excludable && !_check.ignored) ? (_check.excludeMode || 'unused') : '')
    }
    if (_check.excluded && _check.excluded.length > 0) html += renderExcluded(_check)
    html += '<div class="auditsante-explain">'
    html += '<div><h4><i class="far fa-lightbulb"></i> {{Pourquoi}}</h4><p>' + esc(_check.description) + '</p></div>'
    html += '<div><h4><i class="fas fa-route"></i> {{Que faire}}</h4><p>' + esc(_check.recommendation) + '</p></div>'
    html += '</div>'
    var editing = (state.editing && state.editing.id == _check.id) ? state.editing.type : null
    if (editing) {
      html += renderForm(_check, editing)
    } else if (_check.comment) {
      html += '<div class="auditsante-comment"><h4><i class="far fa-comment-dots"></i> {{Commentaire}}</h4><p>' + esc(_check.comment.text) + '</p>'
        + '<span class="auditsante-comment-meta">' + (_check.comment.by ? esc(_check.comment.by) + ', ' : '') + esc(formatDate(_check.comment.date)) + '</span></div>'
    }
    if (_check.ignored && !editing) {
      html += '<p class="auditsante-ignored-note"><i class="fas fa-eye-slash"></i> '
        + (_check.ignoredUntil
          ? '{{Ignoré depuis le}} ' + esc(formatDay(_check.ignoredSince)) + ' {{jusqu\'au}} ' + esc(formatDate(_check.ignoredUntil)) + '. {{Il comptera de nouveau dans la note ensuite.}}'
          : '{{Ignoré sans limite de durée depuis le}} ' + esc(formatDay(_check.ignoredSince)) + '.')
        + '</p>'
    }
    html += '<footer class="auditsante-actions">'
    if (canFix) {
      html += '<a class="auditsante-btn auditsante-btn-sm auditsante-btn-accent auditsante-bt-fix"><i class="fas fa-magic"></i> ' + esc(_check.fix.label) + '</a>'
    }
    if (_check.fix && _check.fix.type == 'link') {
      html += '<a class="auditsante-btn auditsante-btn-sm auditsante-btn-ghost" href="' + esc(_check.fix.url) + '" target="_blank" rel="noopener"><i class="fas fa-external-link-alt"></i> ' + esc(_check.fix.label) + '</a>'
    }
    html += '<span class="auditsante-spacer"></span>'
    html += '<a class="auditsante-btn auditsante-btn-sm auditsante-btn-ghost auditsante-bt-rerun"><i class="fas fa-redo"></i> {{Revérifier}}</a>'
    html += '<a class="auditsante-btn auditsante-btn-sm auditsante-btn-ghost auditsante-bt-edit" data-type="comment"><i class="far fa-comment-dots"></i> ' + (_check.comment ? '{{Modifier le commentaire}}' : '{{Commenter}}') + '</a>'
    if (_check.ignored) {
      html += '<a class="auditsante-btn auditsante-btn-sm auditsante-btn-ghost auditsante-bt-edit" data-type="ignore"><i class="far fa-clock"></i> {{Changer la durée}}</a>'
      html += '<a class="auditsante-btn auditsante-btn-sm auditsante-btn-ghost auditsante-bt-unignore"><i class="fas fa-eye"></i> {{Réintégrer dans la note}}</a>'
    } else if (_check.status != 'pass' && _check.severity != 'info') {
      html += '<a class="auditsante-btn auditsante-btn-sm auditsante-btn-ghost auditsante-bt-edit" data-type="ignore" title="{{Choix assumé : le contrôle ne compte plus dans la note}}"><i class="fas fa-eye-slash"></i> {{Ignorer}}</a>'
    }
    html += '</footer></div></div></article>'
    return html
  }

  /** Formulaire d'un contrôle : commentaire, ou durée d'ignorance avec sa raison. */
  function renderForm(_check, _type) {
    var text = _check.comment ? _check.comment.text : ''
    var html = '<div class="auditsante-form">'
    if (_type == 'ignore') {
      html += '<h4><i class="fas fa-eye-slash"></i> {{Ignorer ce contrôle}}</h4>'
        + '<p class="auditsante-form-help">{{Le contrôle ne compte plus dans la note pendant la durée choisie, puis il est de nouveau pris en compte.}}</p>'
        + '<label class="auditsante-form-row"><span>{{Durée}}</span><select class="auditsante-in-days">'
      IGNORE_DURATIONS.forEach(function (duration) {
        html += '<option value="' + duration[0] + '"' + (duration[0] == 30 ? ' selected' : '') + '>' + esc(duration[1]) + '</option>'
      })
      html += '</select></label>'
        + '<label class="auditsante-form-row auditsante-form-col"><span>{{Raison (facultatif, enregistrée comme commentaire)}}</span>'
    } else {
      html += '<h4><i class="far fa-comment-dots"></i> {{Commentaire}}</h4><label class="auditsante-form-row auditsante-form-col">'
    }
    html += '<textarea class="auditsante-in-comment" rows="3" maxlength="' + COMMENT_MAX + '" placeholder="{{Ex. : équipement de secours, gardé volontairement désactivé}}">' + esc(text) + '</textarea></label>'
    html += '<div class="auditsante-form-actions">'
    if (_type == 'ignore') {
      html += '<a class="auditsante-btn auditsante-btn-sm auditsante-btn-primary auditsante-bt-save-ignore"><i class="fas fa-eye-slash"></i> ' + (_check.ignored ? '{{Appliquer}}' : '{{Ignorer}}') + '</a>'
    } else {
      html += '<a class="auditsante-btn auditsante-btn-sm auditsante-btn-primary auditsante-bt-save-comment"><i class="fas fa-check"></i> {{Enregistrer}}</a>'
      if (_check.comment) html += '<a class="auditsante-btn auditsante-btn-sm auditsante-btn-ghost auditsante-bt-del-comment"><i class="far fa-trash-alt"></i> {{Supprimer}}</a>'
    }
    html += '<a class="auditsante-btn auditsante-btn-sm auditsante-btn-ghost auditsante-bt-cancel">{{Annuler}}</a></div></div>'
    return html
  }

  /* ------------------------------ Actions ------------------------------- */

  function setReport(_report) {
    // Un rapport sans l'état de l'archive garde celui déjà connu (toutes les réponses l'incluent normalement)
    if (!_report.namesArchive && state.report && state.report.namesArchive) _report.namesArchive = state.report.namesArchive
    state.report = _report
    if (_report.recoverJob) state.job = _report.recoverJob
    render()
  }

  function findCheck(_id) {
    var found = null
    state.report.checks.forEach(function (check) { if (check.id == _id) found = check })
    return found
  }

  function runAudit() {
    if (state.running || scanFirst()) return
    // Étape 1 avant le premier audit : lecture des sauvegardes (jamais d'audit automatique ensuite)
    if (!(state.report && state.report.date) && !archiveInfo().initialized) {
      recoverNames()
      return
    }
    state.running = true
    render()
    request('runAudit', {}, function (result) {
      state.running = false
      setReport(result)
      alertMessage('{{Audit terminé :}} ' + result.score + ' % (' + result.grade + ')', 'success')
    }, function () {
      if (state.running) {
        state.running = false
        render()
      }
    })
  }

  function busy(_id, _busy) {
    document.querySelectorAll('[data-id="' + _id + '"]').forEach(function (el) { el.classList.toggle('is-busy', _busy) })
  }

  function applyFix(_id) {
    var check = findCheck(_id)
    if (!check || !check.fix) return
    confirmDialog(check.fix.label, esc(check.fix.confirm || '') + '<br><br>{{Continuer ?}}', function () {
      busy(_id, true)
      request('applyFix', { id: _id }, function (result) {
        alertMessage(result.message, 'success')
        setReport(result.report)
      }, function () { busy(_id, false) })
    })
  }

  /** Retire une référence introuvable d'une commande ou d'un équipement, après confirmation. */
  function repairRef(_id, _key, _label, _title) {
    confirmDialog(_title || '{{Supprimer la référence orpheline}}', '{{L\'action ou le paramètre qui utilise cet élément supprimé va être retiré de la configuration, puis enregistré par Jeedom. L\'ancienne configuration est notée dans le log du plugin.}}'
      + (_label ? '<br><br><b>' + esc(_label) + '</b>' : '') + '<br><br>{{Continuer ?}}', function () {
      busy(_id, true)
      request('repairRef', { id: _id, key: _key }, function (result) {
        alertMessage(result.message, 'success')
        setReport(result.report)
      }, function () { busy(_id, false) })
    })
  }

  /** Lance la lecture des sauvegardes en tâche de fond, après confirmation. */
  function recoverNames() {
    if (state.preparing || jobRunning()) return
    state.preparing = true
    refreshRecover()
    request('recoverStatus', {}, function (status) {
      state.preparing = false
      state.job = status.job
      if (state.report) state.report.namesArchive = status.archive
      refreshRecover()
      var start = function () {
        request('recoverStart', {}, function (job) {
          state.job = job
          render()
          pollJob()
        })
      }
      if (status.pending.length == 0) {
        if (!status.archive.initialized) {
          // Aucune sauvegarde sur la box : relevé et timeline seulement, quelques secondes, sans confirmation
          start()
          return
        }
        alertMessage('{{Toutes les sauvegardes disponibles ont déjà été lues. Ajoutez un dossier de sauvegardes plus anciennes dans la configuration du plugin pour remonter plus loin.}}', 'success')
        return
      }
      var total = status.pending.length
      var size = status.pending.map(function (file) { return esc(file.name) + ' (' + esc(file.size) + ')' })
      var message = (status.unknown > 0 ? status.unknown + ' {{élément(s) supprimé(s) encore sans nom.}}<br>' : '')
        + total + ' {{sauvegarde(s) jamais lue(s), de la plus récente à la plus ancienne}} :<br><small>' + size.slice(0, 5).join('<br>') + (total > 5 ? '<br>...' : '') + '</small><br><br>'
        + '{{Chaque sauvegarde n\'est lue qu\'une fois et peut prendre une à plusieurs minutes. La lecture tourne en tâche de fond : vous pouvez quitter la page. Rien n\'est modifié dans Jeedom.}}'
      confirmDialog(archiveInfo().initialized ? '{{Lire les nouvelles sauvegardes}}' : '{{Premier scan des sauvegardes}}', message, start)
    }, function () {
      if (state.preparing) {
        state.preparing = false
        refreshRecover()
      }
    })
  }

  /** Suit la recherche en tâche de fond toutes les 3 secondes, puis recharge le rapport quand elle est terminée. */
  function pollJob() {
    if (state.pollTimer) return
    state.pollTimer = setTimeout(function () {
      state.pollTimer = null
      if (!document.getElementById('div_saReport')) return // Page quittée
      var received = false
      request('recoverJob', {}, function (job) {
        received = true
        state.job = job
        if (job.status == 'running' || job.status == 'auditing') {
          refreshRecover()
          if (scanFirst() && state.report) renderHero(state.report)
          pollJob()
          return
        }
        request('getReport', {}, function (report) {
          setReport(report)
          if (job.status == 'done' && !report.date) {
            alertMessage('{{Sauvegardes lues. Vous pouvez maintenant lancer l\'audit.}}', 'success')
          } else if (job.status == 'done') {
            alertMessage(job.found + ' {{nom(s) retrouvé(s).}}' + (job.unknown > 0 ? ' ' + job.unknown + ' {{élément(s) absent(s) de l\'archive des noms.}}' : ''), job.unknown > 0 ? 'warning' : 'success')
          }
        })
      }, function () {
        // Erreur passagère (réseau, serveur occupé) : on continue de suivre la recherche
        if (!received) pollJob()
      })
    }, 3000)
  }

  function rerun(_id) {
    busy(_id, true)
    request('rerunCheck', { id: _id }, setReport, function () { busy(_id, false) })
  }

  /** Exclut un élément du contrôle (_excluded true) ou le réintègre, puis le contrôle est revérifié. */
  function setExcluded(_id, _key, _excluded) {
    busy(_id, true)
    request('setExcluded', { id: _id, key: _key, excluded: _excluded ? 1 : 0 }, setReport, function () { busy(_id, false) })
  }

  function closeForm() {
    state.editing = null
  }

  function editCheck(_id, _type) {
    state.editing = { id: _id, type: _type }
    state.open[_id] = true
    render()
    var field = document.querySelector('#sa_checks .auditsante-check[data-id="' + _id + '"] .auditsante-in-comment')
    if (field) {
      field.focus()
      field.setSelectionRange(field.value.length, field.value.length)
    }
  }

  function formValue(_row, _selector) {
    var field = _row.querySelector(_selector)
    return field ? field.value : ''
  }

  function saveIgnore(_id, _row) {
    busy(_id, true)
    request('setIgnored', { id: _id, ignored: 1, days: formValue(_row, '.auditsante-in-days'), comment: formValue(_row, '.auditsante-in-comment') }, function (report) {
      closeForm()
      setReport(report)
    }, function () { busy(_id, false) })
  }

  function unignore(_id) {
    busy(_id, true)
    request('setIgnored', { id: _id, ignored: 0 }, setReport, function () { busy(_id, false) })
  }

  function saveComment(_id, _text) {
    busy(_id, true)
    request('setComment', { id: _id, text: _text }, function (report) {
      closeForm()
      setReport(report)
    }, function () { busy(_id, false) })
  }

  /** Affiche un contrôle déplié dans la liste (depuis une carte de priorité). */
  function showCheck(_id) {
    state.filter = 'all'
    state.category = ''
    state.search = ''
    document.getElementById('in_saSearch').value = ''
    state.open[_id] = true
    render()
    var row = document.querySelector('#sa_checks .auditsante-check[data-id="' + _id + '"]')
    if (row) {
      row.scrollIntoView({ behavior: 'smooth', block: 'center' })
      row.classList.add('is-flash')
      setTimeout(function () { row.classList.remove('is-flash') }, 1600)
    }
  }

  /* ----------------------------- Événements ----------------------------- */

  /** Ouvre l'équipement de l'audit (même mécanisme que les cartes d'équipement du core). */
  function openEquipment() {
    if (typeof saEqLogicId === 'undefined') return
    if (typeof jeeFrontEnd !== 'undefined' && jeeFrontEnd.pluginTemplate && typeof jeeFrontEnd.pluginTemplate.displayEqlogic === 'function') {
      jeeFrontEnd.pluginTemplate.displayEqlogic('auditsante', saEqLogicId)
    } else {
      window.location.href = 'index.php?v=d&m=auditsante&p=auditsante&id=' + saEqLogicId
    }
  }

  /**
   * Rapport de diagnostic : la fenêtre s'ouvre tout de suite et déroule les étapes avec une barre de progression,
   * pour voir exactement où le processus s'arrête. Le rapport s'affiche ensuite, à copier ou télécharger
   * (lien réel, aucun clic simulé que le navigateur ou Jeedom pourraient intercepter).
   */
  var DIAGNOSTIC_STEPS = [
    '{{Envoi de la demande au serveur}}',
    '{{Préparation du rapport par le serveur}}',
    '{{Réception de la réponse}}',
    '{{Affichage du rapport}}'
  ]

  function downloadDiagnostic() {
    var button = document.getElementById('bt_saDiagnostic')
    if (button.classList.contains('is-disabled')) return
    button.classList.add('is-disabled')
    var ui = openDiagnosticModal()
    var started = Date.now()
    var elapsed = function () { return (Date.now() - started) + ' ms' }
    var current = 0
    var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null
    var timer = setTimeout(function () { if (controller) controller.abort() }, 90000)
    var finish = function () {
      clearTimeout(timer)
      button.classList.remove('is-disabled')
    }

    ui.step(0, 'running')
    var body = new URLSearchParams()
    body.append('action', 'getDiagnostic')
    fetch(AJAX_URL, { method: 'POST', body: body, credentials: 'same-origin', signal: controller ? controller.signal : undefined })
      .then(function (response) {
        ui.step(0, 'done', elapsed())
        current = 1
        ui.step(1, 'running')
        return response.text().then(function (raw) {
          var data = null
          try {
            data = JSON.parse(raw)
          } catch (e) {
            console.error('[auditsante] Réponse non JSON du diagnostic (HTTP ' + response.status + ') :', raw.slice(0, 2000))
            throw new Error('{{Réponse invalide du serveur}} (HTTP ' + response.status + ', ' + raw.length + ' {{octets}})')
          }
          if (data.state != 'ok') {
            throw new Error(String(data.result))
          }
          // Compatibilité : ancienne réponse en texte seul, nouvelle avec les étapes du serveur
          var result = (typeof data.result === 'string') ? { text: data.result, steps: [] } : data.result
          var serverSteps = result.steps || []
          var serverMs = serverSteps.length > 0 ? serverSteps[serverSteps.length - 1].ms + ' ms {{sur le serveur}}' : ''
          ui.step(1, 'done', serverMs, serverSteps)
          current = 2
          ui.step(2, 'done', raw.length + ' {{octets}}')
          return result.text || ''
        })
      })
      .then(function (text) {
        current = 3
        ui.step(3, 'running')
        if (String(text).trim() === '') {
          throw new Error('{{Le rapport de diagnostic est vide : passez le log du plugin en Debug, réessayez, puis consultez le log auditsante.}}')
        }
        ui.showReport(String(text))
        ui.step(3, 'done', elapsed())
      })
      .catch(function (error) {
        console.error('[auditsante] Diagnostic en échec à l\'étape ' + (current + 1) + ' :', error)
        var message = (error && error.name === 'AbortError')
          ? '{{Le diagnostic ne répond pas après 90 secondes. Passez le log du plugin en Debug, réessayez, puis consultez le log auditsante.}}'
          : (error && error.message ? error.message : String(error))
        ui.step(current, 'failed', message)
      })
      .finally(finish)
  }

  /** Ouvre la fenêtre du diagnostic et retourne ses commandes : step(), showReport(). */
  function openDiagnosticModal() {
    var old = document.getElementById('sa_diagnostic_modal')
    if (old) old.remove()
    var modal = document.createElement('div')
    modal.id = 'sa_diagnostic_modal'
    modal.className = 'auditsante-modal'
    var steps = ''
    DIAGNOSTIC_STEPS.forEach(function (label, index) {
      steps += '<li class="auditsante-step" data-step="' + index + '"><span class="auditsante-step-icon"><i class="far fa-circle"></i></span>'
        + '<span class="auditsante-step-body"><span class="auditsante-step-label">' + esc(label) + '</span><span class="auditsante-step-detail"></span>'
        + '<ul class="auditsante-step-sub"></ul></span></li>'
    })
    modal.innerHTML = '<div class="auditsante-modal-box" role="dialog" aria-modal="true">'
      + '<div class="auditsante-modal-head"><b><i class="fas fa-stethoscope"></i> {{Rapport de diagnostic}}</b><a class="auditsante-modal-close" title="{{Fermer}}"><i class="fas fa-times"></i></a></div>'
      + '<div class="auditsante-progressbar"><span></span></div>'
      + '<ol class="auditsante-steps">' + steps + '</ol>'
      + '<div class="auditsante-modal-report" style="display:none;">'
      + '<p class="auditsante-modal-help">{{Relisez-le avant de le partager : les adresses publiques, le domaine externe, les comptes et les e-mails sont masqués.}}</p>'
      + '<textarea class="auditsante-modal-text" readonly spellcheck="false"></textarea>'
      + '</div>'
      + '<div class="auditsante-modal-actions">'
      + '<a class="auditsante-btn auditsante-btn-sm auditsante-btn-primary auditsante-bt-copy" style="display:none;"><i class="far fa-copy"></i> <span>{{Copier}}</span></a>'
      + '<a class="auditsante-btn auditsante-btn-sm auditsante-btn-ghost auditsante-bt-download" style="display:none;" target="_blank" rel="noopener"><i class="fas fa-download"></i> {{Télécharger}}</a>'
      + '<span class="auditsante-spacer"></span>'
      + '<a class="auditsante-btn auditsante-btn-sm auditsante-btn-ghost auditsante-modal-close">{{Fermer}}</a>'
      + '</div></div>'
    document.body.appendChild(modal)

    var url = null
    var text = ''
    var bar = modal.querySelector('.auditsante-progressbar span')
    function close() {
      modal.remove()
      if (url) URL.revokeObjectURL(url)
      document.removeEventListener('keydown', onKey)
    }
    function onKey(event) {
      if (event.key === 'Escape') close()
    }
    document.addEventListener('keydown', onKey)
    modal.addEventListener('click', function (event) {
      // Le clic ne doit pas remonter jusqu'aux gestionnaires de la page Jeedom
      event.stopPropagation()
      if (event.target === modal || event.target.closest('.auditsante-modal-close')) {
        close()
        return
      }
      var copy = event.target.closest('.auditsante-bt-copy')
      if (copy) {
        var area = modal.querySelector('.auditsante-modal-text')
        var done = function () { copy.querySelector('span').textContent = '{{Copié}}' }
        if (navigator.clipboard && window.isSecureContext) {
          navigator.clipboard.writeText(text).then(done, function () { area.select(); document.execCommand('copy'); done() })
        } else {
          area.select()
          document.execCommand('copy')
          done()
        }
      }
    })

    return {
      /** État d'une étape : running, done ou failed, avec un détail et les sous-étapes du serveur. */
      step: function (_index, _state, _detail, _sub) {
        var item = modal.querySelector('.auditsante-step[data-step="' + _index + '"]')
        if (!item) return
        item.className = 'auditsante-step is-' + _state
        var icons = { running: 'fas fa-circle-notch fa-spin', done: 'fas fa-check', failed: 'fas fa-times' }
        item.querySelector('.auditsante-step-icon i').className = icons[_state] || 'far fa-circle'
        item.querySelector('.auditsante-step-detail').textContent = _detail ? (_state == 'failed' ? _detail : ' · ' + _detail) : ''
        if (_sub && _sub.length > 0) {
          var html = ''
          _sub.forEach(function (sub) { html += '<li>' + esc(sub.label) + ' <span>' + esc(sub.ms) + ' ms</span></li>' })
          item.querySelector('.auditsante-step-sub').innerHTML = html
        }
        var doneCount = modal.querySelectorAll('.auditsante-step.is-done').length
        bar.style.width = Math.round(100 * (doneCount + (_state == 'running' ? 0.5 : 0)) / DIAGNOSTIC_STEPS.length) + '%'
        bar.parentNode.classList.toggle('is-failed', _state == 'failed')
        bar.parentNode.classList.toggle('is-done', doneCount == DIAGNOSTIC_STEPS.length)
      },
      /** Affiche le rapport et active la copie et le téléchargement. */
      showReport: function (_text) {
        text = _text
        url = URL.createObjectURL(new Blob([_text], { type: 'text/plain;charset=utf-8' }))
        var area = modal.querySelector('.auditsante-modal-text')
        area.value = _text
        modal.querySelector('.auditsante-modal-report').style.display = ''
        // Le rapport s'ouvre sur son début (environnement, contrôles), pas sur la fin du log
        area.scrollTop = 0
        area.setSelectionRange(0, 0)
        var download = modal.querySelector('.auditsante-bt-download')
        download.href = url
        download.setAttribute('download', 'auditsante-diagnostic-' + new Date().toISOString().slice(0, 10) + '.txt')
        download.style.display = ''
        modal.querySelector('.auditsante-bt-copy').style.display = ''
      }
    }
  }

  /** Affiche le log du plugin dans la fenêtre standard de Jeedom. */
  function showLogs() {
    if (typeof jeeDialog !== 'undefined' && typeof jeeDialog.dialog === 'function') {
      jeeDialog.dialog({ id: 'jee_modal', title: '{{Log auditsante}}', contentUrl: 'index.php?v=d&modal=log.display&log=auditsante' })
    } else {
      window.open('index.php?v=d&p=log&logfile=auditsante', '_blank')
    }
  }

  if (document.getElementById('div_saReport')) {
    document.getElementById('bt_saRunAudit').addEventListener('click', runAudit)
    document.getElementById('bt_saEquipment').addEventListener('click', openEquipment)
    document.getElementById('bt_saDiagnostic').addEventListener('click', downloadDiagnostic)
    if (document.getElementById('bt_saLogs')) {
      document.getElementById('bt_saLogs').addEventListener('click', showLogs)
    }

    /* Les modales du core (ex. variables) ne sont pas des pages : on les ouvre dans une fenêtre Jeedom. */
    document.getElementById('div_saReport').addEventListener('click', function (event) {
      var link = event.target.closest('a[href*="modal="], a[href*="p=dataStore"]')
      if (!link || typeof jeeDialog === 'undefined' || typeof jeeDialog.dialog !== 'function') return
      event.preventDefault()
      var url = link.getAttribute('href')
      // Rapports enregistrés avant la correction : la page p=dataStore n'existe pas dans le core
      if (/[?&]p=dataStore\b/.test(url)) url = 'index.php?v=d&modal=dataStore.management&type=scenario'
      var title = /modal=dataStore\./.test(url) ? '{{Variables}}' : (link.getAttribute('title') || '')
      jeeDialog.dialog({ id: 'jee_modal', title: title, contentUrl: url })
    })

    function setFilter(_filter) {
      state.filter = _filter
      render()
    }

    document.getElementById('sa_filters').addEventListener('click', function (event) {
      var button = event.target.closest('[data-filter]')
      if (button) setFilter(button.getAttribute('data-filter'))
    })

    document.getElementById('sa_stats').addEventListener('click', function (event) {
      var stat = event.target.closest('[data-filter]')
      if (!stat) return
      state.category = ''
      setFilter(stat.getAttribute('data-filter'))
      document.getElementById('sa_filters').scrollIntoView({ behavior: 'smooth', block: 'center' })
    })

    document.getElementById('in_saSearch').addEventListener('input', function () {
      state.search = this.value.trim().toLowerCase()
      render()
    })

    document.getElementById('sa_categories').addEventListener('click', function (event) {
      var card = event.target.closest('[data-category]')
      if (!card) return
      var id = card.getAttribute('data-category')
      state.category = (state.category == id) ? '' : id
      if (state.category != '') state.filter = 'all'
      render()
    })

    document.getElementById('sa_active_category').addEventListener('click', function (event) {
      if (event.target.closest('.auditsante-chip-close')) {
        state.category = ''
        render()
      }
    })

    document.getElementById('sa_priorities').addEventListener('click', function (event) {
      var card = event.target.closest('[data-id]')
      if (!card) return
      var id = card.getAttribute('data-id')
      if (event.target.closest('.auditsante-bt-fix')) {
        applyFix(id)
      } else if (event.target.closest('.auditsante-bt-show')) {
        showCheck(id)
      }
    })

    document.getElementById('sa_checks').addEventListener('click', function (event) {
      var row = event.target.closest('.auditsante-check')
      if (!row) return
      var id = row.getAttribute('data-id')
      var exclude = event.target.closest('.auditsante-bt-exclude, .auditsante-bt-include')
      var repair = event.target.closest('.auditsante-bt-repair')
      if (repair) {
        var item = repair.parentNode.querySelector('.auditsante-item-main')
        repairRef(id, repair.getAttribute('data-key'), item ? item.textContent.replace(/\s+/g, ' ').trim() : '', repair.textContent.trim())
      } else if (exclude) {
        setExcluded(id, exclude.getAttribute('data-key'), exclude.classList.contains('auditsante-bt-exclude'))
      } else if (event.target.closest('.auditsante-bt-fix')) {
        applyFix(id)
      } else if (event.target.closest('.auditsante-bt-goto-job')) {
        document.getElementById('sa_job').scrollIntoView({ behavior: 'smooth', block: 'center' })
      } else if (event.target.closest('.auditsante-bt-rerun')) {
        rerun(id)
      } else if (event.target.closest('.auditsante-bt-edit')) {
        editCheck(id, event.target.closest('.auditsante-bt-edit').getAttribute('data-type'))
      } else if (event.target.closest('.auditsante-bt-cancel')) {
        closeForm()
        render()
      } else if (event.target.closest('.auditsante-bt-save-ignore')) {
        saveIgnore(id, row)
      } else if (event.target.closest('.auditsante-bt-unignore')) {
        unignore(id)
      } else if (event.target.closest('.auditsante-bt-save-comment')) {
        saveComment(id, formValue(row, '.auditsante-in-comment'))
      } else if (event.target.closest('.auditsante-bt-del-comment')) {
        saveComment(id, '')
      } else if (event.target.closest('.auditsante-check-head')) {
        state.open[id] = !state.open[id]
        row.classList.toggle('is-open', state.open[id])
      }
    })

    document.getElementById('sa_job').addEventListener('click', function (event) {
      if (event.target.closest('.auditsante-bt-recover')) {
        recoverNames()
      } else if (event.target.closest('.auditsante-bt-job-dismiss')) {
        state.jobDismissed = state.job ? state.job.finished : null
        renderJob()
      }
    })

    render()
    // Une recherche lancée avant d'avoir quitté la page : on reprend son suivi
    if (jobRunning()) pollJob()
  }
})()
