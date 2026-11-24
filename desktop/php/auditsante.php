<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
// Déclaration des variables obligatoires
$plugin = plugin::byId('auditsante');
sendVarToJS('eqType', $plugin->getId());
$auditEqLogic = auditsante::getAuditEqLogic();
sendVarToJS('saReport', auditsante::getFullReport());
sendVarToJS('saEqLogicId', $auditEqLogic->getId());
?>

<div class="row row-overflow">
	<!-- Page d'accueil du plugin : rapport d'audit -->
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<!-- Barre d'en-tête : remplace les tuiles « Gestion » et la carte d'équipement des plugins classiques -->
		<div class="auditsante-appbar">
			<img class="auditsante-appbar-icon" src="<?php echo $plugin->getPathImgIcon(); ?>" alt="">
			<div class="auditsante-appbar-title">
				<b><?php echo $plugin->getName(); ?></b>
				<span>{{Orphelins, références cassées et éléments oubliés de votre installation Jeedom}}</span>
			</div>
			<?php if (auditsante::isDebug()) { ?>
			<a class="auditsante-debug-badge" id="bt_saLogs" title="{{Afficher le log du plugin}}"><i class="fas fa-bug"></i> {{Mode debug actif}}</a>
			<?php } ?>
			<div class="auditsante-spacer"></div>
			<div class="auditsante-appbar-actions">
				<a class="auditsante-btn auditsante-btn-ghost auditsante-btn-sm" id="bt_saEquipment" title="{{Équipement de l'audit : commandes, widget, historique}}"><i class="fas fa-cube"></i> <span>{{Équipement}}</span></a>
				<a class="auditsante-btn auditsante-btn-ghost auditsante-btn-sm" id="bt_saDiagnostic" title="{{Télécharger un rapport de diagnostic à joindre à une demande d'aide (données personnelles masquées)}}"><i class="fas fa-stethoscope"></i> <span>{{Diagnostic}}</span></a>
				<a class="auditsante-btn auditsante-btn-ghost auditsante-btn-sm eqLogicAction" data-action="gotoPluginConf" title="{{Configuration du plugin}}"><i class="fas fa-wrench"></i> <span>{{Configuration}}</span></a>
			</div>
		</div><!-- /.auditsante-appbar -->

		<div id="div_saReport" class="auditsante-app">
			<!-- Synthèse : jauge, verdict, compteurs -->
			<section class="auditsante-hero" id="sa_hero">
				<div class="auditsante-gauge" id="sa_gauge">
					<svg viewBox="0 0 120 120" aria-hidden="true">
						<circle class="auditsante-gauge-track" cx="60" cy="60" r="52"></circle>
						<circle class="auditsante-gauge-value" id="sa_gauge_value" cx="60" cy="60" r="52"></circle>
					</svg>
					<div class="auditsante-gauge-center">
						<span class="auditsante-gauge-grade" id="sa_grade">?</span>
						<span class="auditsante-gauge-score"><b id="sa_score">-</b>%</span>
					</div>
				</div>
				<div class="auditsante-hero-body">
					<div class="auditsante-eyebrow"><i class="fas fa-heartbeat"></i> {{État de santé}}</div>
					<h2 class="auditsante-verdict" id="sa_verdict">{{Pas encore évalué}}</h2>
					<p class="auditsante-meta" id="sa_meta"></p>
					<div class="auditsante-hero-actions">
						<a class="auditsante-btn auditsante-btn-primary" id="bt_saRunAudit"><i class="fas fa-play"></i> <span>{{Lancer l'audit}}</span></a>
					</div>
				</div>
				<div class="auditsante-stats" id="sa_stats"></div>
				<div class="auditsante-progress" id="sa_progress"><span></span></div>
			</section>

			<!-- Recherche des noms dans les sauvegardes, en tâche de fond -->
			<div class="auditsante-job" id="sa_job" style="display:none;"></div>

			<!-- Priorités -->
			<section class="auditsante-section" id="sa_priorities_section" style="display:none;">
				<h3 class="auditsante-section-title"><i class="fas fa-bolt"></i> {{À traiter en priorité}}</h3>
				<div class="auditsante-priorities" id="sa_priorities"></div>
			</section>

			<!-- Note par catégorie -->
			<section class="auditsante-section">
				<h3 class="auditsante-section-title"><i class="fas fa-th-large"></i> {{Par catégorie}}</h3>
				<div class="auditsante-categories" id="sa_categories"></div>
			</section>

			<!-- Contrôles -->
			<section class="auditsante-section">
				<div class="auditsante-toolbar">
					<h3 class="auditsante-section-title"><i class="fas fa-list-ul"></i> {{Contrôles}}</h3>
					<div class="auditsante-spacer"></div>
					<div class="auditsante-segmented" id="sa_filters">
						<a data-filter="todo">{{À traiter}} <span class="auditsante-badge" data-count="todo"></span></a>
						<a data-filter="pass">{{Rien à signaler}} <span class="auditsante-badge" data-count="pass"></span></a>
						<a data-filter="info">{{Informations}} <span class="auditsante-badge" data-count="info"></span></a>
						<a data-filter="ignored">{{Ignorés}} <span class="auditsante-badge" data-count="ignored"></span></a>
						<a data-filter="all">{{Tous}} <span class="auditsante-badge" data-count="all"></span></a>
					</div>
					<label class="auditsante-search">
						<i class="fas fa-search"></i>
						<input id="in_saSearch" type="search" placeholder="{{Rechercher}}">
					</label>
				</div>
				<div class="auditsante-active-category" id="sa_active_category" style="display:none;"></div>
				<div id="sa_checks"></div>
				<div class="auditsante-empty" id="sa_empty" style="display:none;"></div>
			</section>
		</div>

	</div> <!-- /.eqLogicThumbnailDisplay -->

	<!-- Page de l'équipement -->
	<div class="col-xs-12 eqLogic" style="display: none;">
		<div class="input-group pull-right" style="display:inline-flex;">
			<span class="input-group-btn">
				<!-- Les balises <a></a> sont volontairement fermées à la ligne suivante pour éviter les espaces entre les boutons. Ne pas modifier -->
				<a class="btn btn-sm btn-default eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span>
				</a><a class="btn btn-sm btn-success eqLogicAction roundedRight" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
			</span>
		</div>
		<!-- Onglets -->
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i> {{Équipement}}</a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i> {{Commandes}}</a></li>
		</ul>
		<div class="tab-content">
			<!-- Onglet de configuration de l'équipement -->
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<form class="form-horizontal">
					<fieldset>
						<div class="col-lg-6">
							<legend><i class="fas fa-wrench"></i> {{Paramètres généraux}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Nom de l'équipement}}</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Nom de l'équipement}}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Objet parent}}</label>
								<div class="col-sm-6">
									<select id="sel_object" class="eqLogicAttr form-control" data-l1key="object_id">
										<option value="">{{Aucun}}</option>
										<?php
										$options = '';
										foreach ((jeeObject::buildTree(null, false)) as $object) {
											$options .= '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
										}
										echo $options;
										?>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Catégorie}}</label>
								<div class="col-sm-6">
									<?php
									foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
										echo '<label class="checkbox-inline">';
										echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '" >' . $value['name'];
										echo '</label>';
									}
									?>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Options}}</label>
								<div class="col-sm-6">
									<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>{{Activer}}</label>
									<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>{{Visible}}</label>
								</div>
							</div>
						</div>
						<div class="col-lg-6">
							<legend><i class="fas fa-info"></i> {{Informations}}</legend>
							<div class="form-group">
								<div class="col-sm-10 col-sm-offset-1">
									<div class="auditsante-callout">
										<i class="fas fa-info-circle"></i>
										<div>{{Cet équipement porte la note de l'audit de santé. Utilisez ses commandes dans vos scénarios, par exemple pour être prévenu quand la note baisse ou quand un problème important apparaît.}}</div>
									</div>
								</div>
							</div>
						</div>
					</fieldset>
				</form>
			</div><!-- /.tabpanel #eqlogictab-->

			<!-- Onglet des commandes de l'équipement -->
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<br>
				<div class="table-responsive">
					<table id="table_cmd" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th class="hidden-xs" style="min-width:50px;width:70px;">ID</th>
								<th style="min-width:200px;width:350px;">{{Nom}}</th>
								<th>{{Type}}</th>
								<th style="min-width:260px;">{{Options}}</th>
								<th>{{Valeur}}</th>
								<th style="min-width:80px;width:200px;">{{Actions}}</th>
							</tr>
						</thead>
						<tbody>
						</tbody>
					</table>
				</div>
			</div><!-- /.tabpanel #commandtab-->
		</div><!-- /.tab-content -->
	</div><!-- /.eqLogic -->
</div><!-- /.row row-overflow -->

<?php include_file('desktop', 'auditsante', 'css', 'auditsante'); ?>
<?php include_file('desktop', 'auditsante', 'js', 'auditsante'); ?>
<!-- Inclusion du fichier javascript du core - NE PAS MODIFIER NI SUPPRIMER -->
<?php include_file('core', 'plugin.template', 'js'); ?>
