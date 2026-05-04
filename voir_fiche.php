<?php
declare(strict_types=1);
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';
if (!class_exists('App')) require_once __DIR__ . '/src/App.php';
App::configureErrorDisplay();
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/ValidationRepository.php';
$config   = require __DIR__ . '/config/security.php';
$security = new Security($config);
$security->sendSecurityHeaders();
$security->startSecureSession();
$csrfToken = $security->generateCsrfToken();

if (!Auth::check()) {
    if (!empty($_SESSION['admin_authenticated'])) {
        $_SESSION['user_role']='dei';$_SESSION['user_since']=time();
        $_SESSION['user_id']=0;$_SESSION['user_dept']=null;$_SESSION['user_etab']=null;
    } else { header('Location: login.php'); exit; }
}

$repo    = new ValidationRepository();
$ficheId = (int)($_GET['id'] ?? 0);
if ($ficheId <= 0) { http_response_code(400); die('ID manquant.'); }

$fiche = $repo->getFicheComplete($ficheId);
if (!$fiche) { http_response_code(404); die('Fiche introuvable.'); }

[$sw,$sp] = Auth::ficheScope();
if ($sw !== '' && Auth::userRole() !== 'dei') {
    $col = (strpos($sw,'departement') !== false) ? 'departement' : 'etab_beneficiaire';
    if (($fiche[$col] ?? '') !== ($sp[0] ?? '')) { http_response_code(403); die('Accès refusé.'); }
}

$historique  = $repo->getHistorique($ficheId);
$preuves     = $repo->getPreuves($ficheId);
$peutValider = Auth::peutValider(Auth::userRole(), $fiche);
$role        = Auth::userRole();

$stCls   = ['en_attente'=>'vstep-en_attente','valide'=>'vstep-valide','rejete'=>'vstep-rejete'];
$stTxt   = ['en_attente'=>'En attente','valide'=>'Validée','rejete'=>'Rejetée'];
$decCls  = ['valide'=>'badge-green','rejete'=>'badge-red'];

if ($fiche['statut'] === 'validee') {
    $statutGlobal = ['class'=>'alert-success','label'=>'✓ Fiche complètement validée'];
} elseif ($fiche['statut'] === 'rejetee') {
    $statutGlobal = ['class'=>'alert-danger', 'label'=>'✕ Fiche rejetée'];
} else {
    $statutGlobal = ['class'=>'alert-warn',   'label'=>'⏳ En attente de validation'];
}

$mimeIcon = function($m) {
    if (strpos($m,'pdf')   !== false) return '📄';
    if (strpos($m,'word')  !== false) return '📝';
    if (strpos($m,'image') !== false) return '🖼';
    return '📎';
};

ob_start();
?>
<!-- Breadcrumb -->
<div class="breadcrumb">
  <a href="portail.php">Portail</a>
  <span class="breadcrumb-sep">›</span>
  <span>Fiche #<?= (int)$fiche['id'] ?></span>
</div>

<!-- Hero fiche -->
<div class="page-hero" style="margin-bottom:1.25rem">
  <div>
    <h1><?= Security::e($fiche['cours']) ?></h1>
    <div class="subtitle">
      <?= Security::e($fiche['ens_nom']) ?> · <?= Security::e($fiche['matricule']) ?> ·
      Déposée le <?= date('d/m/Y', strtotime($fiche['submitted_at'])) ?>
    </div>
  </div>
  <div class="btn-group">
    <?php if ($peutValider): ?>
    <a href="valider_fiche.php?id=<?= (int)$ficheId ?>" class="btn btn-gold">⚖ Donner mon avis</a>
    <?php endif; ?>
    <?php if ($role==='dei'): ?>
    <a href="generer_fiche.php?ens_id=<?= (int)$fiche['enseignant_id'] ?>" class="btn btn-sm" style="background:rgba(255,255,255,.2);color:#fff;border-color:rgba(255,255,255,.4)" target="_blank">↓ DOCX</a>
    <?php endif; ?>
  </div>
</div>

<div class="alert <?= $statutGlobal['class'] ?>"><?= $statutGlobal['label'] ?></div>

<!-- Progression 4 étapes -->
<div class="card" style="margin-bottom:1.25rem">
  <div class="card-header"><div class="card-title">Progression de la validation</div></div>
  <div class="validation-steps">
    <?php foreach ([
      'Chef de département' => ['col'=>'statut_chef',    'role'=>'chef_dept'],
      'Directeur adjoint'   => ['col'=>'statut_dir_adj', 'role'=>'directeur_adjoint'],
      'Directeur'           => ['col'=>'statut_dir',     'role'=>'directeur'],
      'DEI'                 => ['col'=>'statut_dei',     'role'=>'dei'],
    ] as $label => $meta):
      $st = $fiche[$meta['col']] ?? 'en_attente';
      $isCurrent = ($role === $meta['role']);
    ?>
    <div class="vstep vstep-<?= $st ?>" style="<?= $isCurrent ? 'border:2px solid var(--ujkz-or)' : '' ?>">
      <div class="vstep-label"><?= $label ?><?= $isCurrent ? ' ← vous' : '' ?></div>
      <span class="vstep-badge"><?= $stTxt[$st] ?? $st ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Infos enseignant + programme -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem">
  <div class="card" style="margin-bottom:0">
    <div class="card-header"><div class="card-title">Enseignant</div></div>
    <table class="recap">
      <tr><td>Matricule</td><td><strong><?= Security::e($fiche['matricule']) ?></strong></td></tr>
      <tr><td>Nom</td><td><?= Security::e($fiche['ens_nom']) ?></td></tr>
      <tr><td>Grade</td><td><?= Security::e($fiche['grade'] ?? '—') ?></td></tr>
      <tr><td>Type</td><td><?= Security::e(ucfirst($fiche['type_enseignant'] ?? '—')) ?></td></tr>
      <tr><td>Département</td><td><?= Security::e($fiche['departement']) ?></td></tr>
      <tr><td>Établissement</td><td><?= Security::e($fiche['etab_beneficiaire'] ?? '—') ?></td></tr>
    </table>
  </div>
  <div class="card" style="margin-bottom:0">
    <div class="card-header"><div class="card-title">Programme</div></div>
    <table class="recap">
      <tr><td>UE / ECUE</td><td><strong><?= Security::e($fiche['cours']) ?></strong></td></tr>
      <?php if (!empty($fiche['code_ue'])): ?><tr><td>Code</td><td><?= Security::e($fiche['code_ue']) ?></td></tr><?php endif; ?>
      <?php if (!empty($fiche['parcours'])): ?><tr><td>Parcours</td><td><?= Security::e($fiche['parcours']) ?></td></tr><?php endif; ?>
      <tr><td>Niveau / Sem.</td><td><?= Security::e($fiche['niveau']) ?> · <?= Security::e($fiche['semestre']) ?></td></tr>
      <?php if (!empty($fiche['ntc'])): ?><tr><td>NTC</td><td><?= Security::e($fiche['ntc']) ?></td></tr><?php endif; ?>
      <tr><td>CM / TD/TP</td><td><?= (int)$fiche['volume_cm'] ?>h / <?= (int)$fiche['volume_td'] ?>h</td></tr>
      <tr><td>Évaluation</td><td><?= Security::e($fiche['evaluation']) ?></td></tr>
    </table>
  </div>
</div>

<!-- Preuves -->
<div class="card" style="margin-bottom:1.25rem">
  <div class="card-header">
    <div class="card-title">Fichiers de preuves</div>
    <span class="badge badge-gray"><?= count($preuves) ?> fichier(s)</span>
  </div>
  <?php if (empty($preuves)): ?>
  <div style="padding:.5rem 0;color:var(--gray-400);font-size:13px">Aucune preuve déposée par l'enseignant.</div>
  <?php else: ?>
  <div style="display:flex;flex-wrap:wrap;gap:8px">
    <?php foreach ($preuves as $p): ?>
    <a href="voir_preuve.php?preuve=<?= (int)$p['id'] ?>" target="_blank"
       style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;background:var(--gray-50);border:1px solid var(--gray-200);border-radius:7px;text-decoration:none;font-size:13px;color:var(--gray-800);transition:background .12s"
       onmouseover="this.style.background='var(--ujkz-vert-lt)'" onmouseout="this.style.background='var(--gray-50)'">
      <span style="font-size:16px"><?= $mimeIcon($p['type_mime']) ?></span>
      <span style="font-weight:500"><?= Security::e(mb_substr($p['nom_original'],0,35)) ?></span>
      <span style="color:var(--gray-400);font-size:11px"><?= round($p['taille']/1024,1) ?> Ko</span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Historique -->
<?php if (!empty($historique)): ?>
<div class="card" style="margin-bottom:1.25rem">
  <div class="card-header"><div class="card-title">Historique des décisions</div></div>
  <table class="table-ujkz">
    <thead><tr>
      <th>Date</th><th>Valideur</th><th>Rôle</th>
      <th style="text-align:center">Décision</th><th>Motif de rejet</th>
    </tr></thead>
    <tbody>
    <?php foreach ($historique as $h): ?>
    <tr>
      <td style="white-space:nowrap;color:var(--gray-600)"><?= date('d/m/Y H:i', strtotime($h['created_at'])) ?></td>
      <td style="font-weight:500"><?= Security::e($h['valideur_nom']) ?></td>
      <td style="color:var(--gray-600)"><?= Security::e(Auth::roleLabel($h['valideur_role'])) ?></td>
      <td style="text-align:center">
        <span class="badge <?= $decCls[$h['decision']] ?? 'badge-gray' ?>">
          <?= $h['decision']==='valide' ? '✓ Validée' : '✕ Rejetée' ?>
        </span>
      </td>
      <td style="color:var(--gray-600);font-style:italic"><?= !empty($h['motif_rejet'])?Security::e($h['motif_rejet']):'—' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- CTA validation -->
<?php if ($peutValider): ?>
<div style="text-align:center;margin:1.5rem 0">
  <a href="valider_fiche.php?id=<?= (int)$ficheId ?>" class="btn btn-primary" style="padding:12px 2rem;font-size:15px">
    ⚖ Donner ma décision sur cette fiche
  </a>
</div>
<?php endif; ?>
<?php
$bodyContent = ob_get_clean();
$title = 'Fiche — ' . htmlspecialchars($fiche['cours'] ?? '', ENT_QUOTES, 'UTF-8');
ob_start(); require __DIR__ . '/templates/layout.php'; echo ob_get_clean();
