<?php
declare(strict_types=1);
$e = function($v) { return Security::e($v); };
$token   = $enseignant['token'];
$modeEdit = $modeEdit ?? false;

$statutLabel = [
    'en_attente' => ['label'=>'En attente de validation', 'class'=>'alert-warn'],
    'validee'    => ['label'=>'Fiche validée',             'class'=>'alert-success'],
    'rejetee'    => ['label'=>'Fiche rejetée',             'class'=>'alert-danger'],
];
$st = $statutLabel[$fiche['statut']] ?? ['label'=>$fiche['statut'],'class'=>'alert-warn'];

$stEtape = [
    'en_attente' => ['bg'=>'#FAEEDA','txt'=>'#633806','label'=>'En attente'],
    'valide'     => ['bg'=>'#EAF3DE','txt'=>'#27500A','label'=>'Validée'],
    'rejete'     => ['bg'=>'#FCEBEB','txt'=>'#791F1F','label'=>'Rejetée'],
];
$sc = function($s) use ($stEtape) { return isset($stEtape[$s]) ? $stEtape[$s] : ['bg'=>'#eee','txt'=>'#555','label'=>$s]; };
$mimeIcon = function($m) {
    if (strpos($m,'pdf')   !== false) return '📄';
    if (strpos($m,'word')  !== false) return '📝';
    if (strpos($m,'image') !== false) return '🖼';
    return '📎';
};
?>

<div class="breadcrumb">
  <a href="dashboard.php?token=<?= urlencode($token) ?>">Tableau de bord</a>
  <span class="breadcrumb-sep">›</span>
  <span>Fiche de suivi #<?= (int)$fiche['id'] ?></span>
</div>

<div class="page-hero" style="margin-bottom:1.25rem">
  <div>
    <h1><?= $e($fiche['cours']) ?></h1>
    <div class="subtitle">
      Fiche #<?= (int)$fiche['id'] ?> ·
      déposée le <?= $e(date('d/m/Y à H:i', strtotime($fiche['submitted_at']))) ?>
    </div>
  </div>
</div>

<div class="alert <?= $e($st['class']) ?>"><?= $e($st['label']) ?></div>

<!-- Progression 4 étapes -->
<div class="card" style="margin-bottom:1rem">
  <div class="card-header"><div class="card-title">Progression de la validation</div></div>
  <div class="validation-steps">
  <?php foreach ([
    'Chef de département' => $fiche['statut_chef']    ?? 'en_attente',
    'Directeur adjoint'   => $fiche['statut_dir_adj'] ?? 'en_attente',
    'Directeur'           => $fiche['statut_dir']     ?? 'en_attente',
    'DEI'                 => $fiche['statut_dei']      ?? 'en_attente',
  ] as $label => $statut):
    $s = $sc($statut);
  ?>
  <div class="vstep vstep-<?= $statut ?>">
    <div class="vstep-label"><?= $label ?></div>
    <span class="vstep-badge"><?= $s['label'] ?></span>
  </div>
  <?php endforeach; ?>
  </div>
</div>

<!-- Programme -->
<div class="card">
  <div class="card-header"><div class="card-title">Programme</div></div>
  <table class="recap">
    <tr><td>UE / ECUE</td><td><strong><?= $e($fiche['cours']) ?></strong></td></tr>
    <?php if (!empty($fiche['code'])): ?><tr><td>Code UE</td><td><?= $e($fiche['code']) ?></td></tr><?php endif; ?>
    <?php if (!empty($fiche['parcours'])): ?><tr><td>Parcours</td><td><?= $e($fiche['parcours']) ?></td></tr><?php endif; ?>
    <tr><td>Niveau / Semestre</td><td><?= $e($fiche['niveau']) ?> · <?= $e($fiche['semestre']) ?></td></tr>
    <?php if (!empty($fiche['ntc'])): ?><tr><td>NTC</td><td><?= $e($fiche['ntc']) ?></td></tr><?php endif; ?>
    <tr><td>CM prévus / TD prévus</td><td><?= (int)$fiche['volume_cm'] ?>h / <?= (int)$fiche['volume_td'] ?>h</td></tr>
    <tr><td>Évaluation</td><td><?= $e($fiche['evaluation']) ?></td></tr>
  </table>
</div>

<!-- Justificatifs -->
<div class="card" style="margin-top:1rem" id="justificatifs">
  <div class="card-header">
    <div class="card-title">Justificatifs d'exécution (<?= count($preuves) ?>)</div>
    <div style="display:flex;gap:6px;align-items:center">
      <?php if (!empty($preuves)): ?>
      <a href="dashboard.php?token=<?= urlencode($token) ?>&suivi=<?= (int)$fiche['id'] ?>"
         class="btn btn-sm btn-outline-green"
         title="Voir la fiche semestrielle de suivi">
        📋 Fiche de suivi S<?= $fiche['semestre']==="S1"?"1":"2" ?>
      </a>
      <?php endif; ?>
      <?php if (count($preuves) < 10): ?>
      <button type="button" class="btn btn-sm btn-primary"
              onclick="document.getElementById('form-justif').style.display=document.getElementById('form-justif').style.display==='none'?'block':'none'">
        + Ajouter un justificatif
      </button>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($uploadOk)): ?>
  <div class="alert alert-success" style="margin-bottom:.75rem">Justificatif ajouté avec succès.</div>
  <?php endif; ?>
  <?php if (!empty($uploadError)): ?>
  <div class="alert alert-danger" style="margin-bottom:.75rem"><?= $e($uploadError) ?></div>
  <?php endif; ?>

  <!-- Formulaire d'ajout justificatif -->
  <div id="form-justif" style="display:none;margin-bottom:1rem;padding:1.25rem;background:var(--gray-50);border:1px solid var(--gray-200);border-radius:8px">
    <form method="POST" action="upload_preuve.php" enctype="multipart/form-data" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
      <input type="hidden" name="token"     value="<?= $e($token) ?>">
      <input type="hidden" name="fiche_id"  value="<?= (int)$fiche['id'] ?>">
      <input type="hidden" name="action"    value="upload">

      <div class="grid2">
        <div>
          <label>Volume CM effectué (h)</label>
          <input type="number" name="volume_cm_effectue" min="0" max="500" placeholder="Ex : 15">
        </div>
        <div>
          <label>Volume TD / TP effectué (h)</label>
          <input type="number" name="volume_td_effectue" min="0" max="500" placeholder="Ex : 6">
        </div>
      </div>
      <label>Commentaire <small style="font-weight:400;color:var(--gray-600)">(optionnel)</small></label>
      <input type="text" name="commentaire" maxlength="500" placeholder="Ex : Cours effectués du 15/01 au 28/02">

      <label>Pièce justificative <span style="color:var(--danger)">*</span></label>
      <div style="font-size:12px;color:var(--gray-600);margin-bottom:.5rem">
        PDF, Word (.doc, .docx), images (JPEG, PNG, GIF, WebP). Max 10 Mo.
      </div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input type="file" name="preuve" required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp"
               style="flex:1;min-width:200px;font-size:13px">
        <button type="submit" class="btn btn-sm btn-primary">Envoyer</button>
        <button type="button" class="btn btn-sm" onclick="document.getElementById('form-justif').style.display='none'">Annuler</button>
      </div>
    </form>
  </div>

  <!-- Liste des justificatifs -->
  <?php if (empty($preuves)): ?>
  <div style="padding:.5rem 0;color:var(--gray-400);font-size:13px">Aucun justificatif déposé pour cette fiche.</div>
  <?php else: ?>
  <div style="display:flex;flex-direction:column;gap:8px">
  <?php foreach ($preuves as $p): ?>
  <div style="display:flex;align-items:flex-start;justify-content:space-between;padding:10px 14px;background:var(--gray-50);border:1px solid var(--gray-200);border-radius:8px;gap:12px;flex-wrap:wrap">
    <div style="display:flex;align-items:flex-start;gap:10px;font-size:13px;flex:1">
      <span style="font-size:18px;flex-shrink:0"><?= $mimeIcon($p['type_mime']) ?></span>
      <div>
        <div style="font-weight:500"><?= $e(mb_substr($p['nom_original'],0,55)) ?></div>
        <div style="font-size:11px;color:var(--gray-600);margin-top:2px">
          <?= round($p['taille']/1024,1) ?> Ko · <?= $e(date('d/m/Y H:i', strtotime($p['uploaded_at']))) ?>
          <?php if (isset($p['volume_cm_effectue']) && $p['volume_cm_effectue'] !== null): ?>
          · CM : <strong><?= (int)$p['volume_cm_effectue'] ?>h</strong>
          <?php endif; ?>
          <?php if (isset($p['volume_td_effectue']) && $p['volume_td_effectue'] !== null): ?>
          / TD : <strong><?= (int)$p['volume_td_effectue'] ?>h</strong>
          <?php endif; ?>
          <?php if (!empty($p['commentaire'])): ?>
          · <em><?= $e($p['commentaire']) ?></em>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div style="display:flex;gap:6px;flex-shrink:0">
      <a href="voir_preuve.php?preuve=<?= (int)$p['id'] ?>&token=<?= urlencode($token) ?>"
         target="_blank" class="btn btn-sm btn-outline-green">Ouvrir</a>
      <form method="POST" action="upload_preuve.php"
            onsubmit="return confirm('Supprimer ce justificatif ?')">
        <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
        <input type="hidden" name="token"     value="<?= $e($token) ?>">
        <input type="hidden" name="fiche_id"  value="<?= (int)$fiche['id'] ?>">
        <input type="hidden" name="preuve_id" value="<?= (int)$p['id'] ?>">
        <input type="hidden" name="action"    value="supprimer">
        <button type="submit" class="btn btn-sm btn-danger">Supprimer</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Historique des validations -->
<?php if (!empty($historique)): ?>
<div class="card" style="margin-top:1rem">
  <div class="card-header"><div class="card-title">Historique des décisions</div></div>
  <table class="table-ujkz">
    <thead><tr>
      <th>Date</th><th>Valideur</th><th>Rôle</th>
      <th style="text-align:center">Décision</th><th>Motif</th>
    </tr></thead>
    <tbody>
    <?php foreach ($historique as $h): ?>
    <?php $hc = $h['decision']==='valide' ? 'badge-green' : 'badge-red'; ?>
    <tr>
      <td style="white-space:nowrap;color:var(--gray-600)"><?= date('d/m/Y H:i', strtotime($h['created_at'])) ?></td>
      <td style="font-weight:500"><?= $e($h['valideur_nom']) ?></td>
      <?php $hRole = $h['etape_role'] ?? $h['valideur_role'] ?? $h['role'] ?? ''; ?>
      <td style="color:var(--gray-600)"><?= $e(class_exists('Auth') && $hRole ? Auth::roleLabel($hRole) : ucfirst(str_replace('_',' ',$hRole))) ?></td>
      <td style="text-align:center">
        <span class="badge <?= $hc ?>"><?= $h['decision']==='valide'?'✓ Validée':'✕ Rejetée' ?></span>
      </td>
      <td style="color:var(--gray-600);font-style:italic"><?= !empty($h['motif_rejet'])?$e($h['motif_rejet']):'—' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div style="text-align:center;margin-top:1.5rem">
  <a href="dashboard.php?token=<?= urlencode($token) ?>" class="btn">← Tableau de bord</a>
</div>
