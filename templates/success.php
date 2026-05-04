<?php
declare(strict_types=1);
$e = function($v) { return Security::e($v); };
$modeEdit = $modeEdit ?? false;
$nbCours  = $nbCours  ?? 1;
$tokenFromLink = '';
if (!empty($accessLink)) {
    parse_str(parse_url($accessLink, PHP_URL_QUERY) ?? '', $qs);
    $tokenFromLink = $qs['token'] ?? '';
}
?>

<div class="page-hero" style="margin-bottom:1.5rem">
  <div>
    <h1>✅ Fiche programmatique enregistrée</h1>
    <div class="subtitle"><?= $e($matricule) ?> — Année <?= $e($config['annee_academique'] ?? '') ?></div>
  </div>

</div>

<div class="alert alert-success">
  La fiche programmatique a été enregistrée et transmise à votre chef de département.
</div>

<div class="card">
  <div class="card-header">
    <div class="card-title">Votre lien personnel d'accès</div>
  </div>
  <p style="font-size:13px;color:var(--gray-600);margin-bottom:.875rem">
    Ce lien est unique et personnel. Il vous permet d'accéder à vos fiches et de suivre les validations <strong>sans mot de passe</strong>.
    Conservez-le précieusement.
  </p>
  <div class="link-box" id="access-link"><?= $e($accessLink) ?></div>
  <div class="btn-group" style="margin-top:.875rem">
    <button class="btn btn-primary" onclick="copyLink()">📋 Copier le lien</button>
    <a href="<?= $e($accessLink) ?>" class="btn btn-outline-green">Accéder à mon tableau de bord →</a>
  </div>
  <div class="alert alert-warn" style="margin-top:1rem">
    <strong>Important :</strong> ce lien est strictement personnel. Ne le partagez pas.
  </div>
</div>

<?php if (!$modeEdit): ?>
<div class="card">
  <div class="card-title" style="margin-bottom:.75rem">Que se passe-t-il ensuite ?</div>
  <div style="display:flex;flex-direction:column;gap:12px">
    <?php foreach ([
      ['🏢','Chef de département', 'Votre fiche est d\'abord examinée par votre chef de département.'],
      ['👔','Directeur adjoint',   'Après validation, elle est transmise au directeur adjoint de l\'UFR.'],
      ['🎓','Directeur',          'Le directeur de l\'UFR confirme à son tour.'],
      ['📋','DEI',                'La Direction des Enseignements et des Inscriptions valide définitivement.'],
    ] as [$icon,$step,$desc]): ?>
    <div style="display:flex;align-items:flex-start;gap:12px;padding:10px 12px;background:var(--gray-50);border-radius:8px;border:1px solid var(--gray-200)">
      <span style="font-size:20px"><?= $icon ?></span>
      <div>
        <div style="font-weight:600;font-size:13px"><?= $step ?></div>
        <div style="font-size:12px;color:var(--gray-600)"><?= $desc ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div style="text-align:center;margin-top:.5rem">
  <a href="index.php?token=<?= urlencode($tokenFromLink) ?>" class="btn btn-primary">
    + Déposer une autre fiche
  </a>
</div>

<script>
function copyLink() {
  const txt = document.getElementById('access-link').textContent.trim();
  navigator.clipboard.writeText(txt).then(() => {
    const btn = event.target; const orig = btn.textContent;
    btn.textContent = '✓ Copié !';
    setTimeout(() => btn.textContent = orig, 2200);
  }).catch(() => prompt('Copiez ce lien :', txt));
}
</script>
