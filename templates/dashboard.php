<?php
declare(strict_types=1);
$e = function($v) { return Security::e($v); };
$tok = $rawToken ?? $enseignant['token'] ?? '';

$annee          = $annee ?? '2024-2025';
$vue            = $vue   ?? 'fiche';
$fichesAvecPreuves = $fichesAvecPreuves ?? [];
$preuvesByFiche    = $preuvesByFiche    ?? [];
$historiqueGlobal = $historiqueGlobal ?? [];

$statutLabel = [
    'en_attente' => ['label'=>'En attente', 'class'=>'badge-or'],
    'validee'    => ['label'=>'Validée',    'class'=>'badge-green'],
    'rejetee'    => ['label'=>'Rejetée',    'class'=>'badge-red'],
];

// Détermine le libellé d'étape précis selon les statuts intermédiaires
function statutEtapeLabel(array $f): array {
    if (($f['statut'] ?? '') === 'rejetee') {
        // Identifier qui a rejeté
        if (($f['statut_dei']     ?? '') === 'rejete') return ['label'=>'Rejetée — DEI',               'class'=>'badge-red'];
        if (($f['statut_vp_eip']  ?? '') === 'rejete') return ['label'=>'Rejetée — VP EIP',             'class'=>'badge-red'];
        if (($f['statut_dir']     ?? '') === 'rejete') return ['label'=>'Rejetée — Directeur',          'class'=>'badge-red'];
        if (($f['statut_dir_adj'] ?? '') === 'rejete') return ['label'=>'Rejetée — Dir. adjoint',       'class'=>'badge-red'];
        if (($f['statut_chef']    ?? '') === 'rejete') return ['label'=>'Rejetée — Chef de dép.',       'class'=>'badge-red'];
        return ['label'=>'Rejetée', 'class'=>'badge-red'];
    }
    if (($f['statut'] ?? '') === 'validee') {
        return ['label'=>'Validée ✓', 'class'=>'badge-green'];
    }
    // En cours : identifier l'étape courante
    $wf = $f['type_workflow'] ?? 'IESR_UJKZ';
    if (($f['statut_chef'] ?? 'en_attente') === 'en_attente')
        return ['label'=>'En attente — Chef de dép.', 'class'=>'badge-or'];
    if (($f['statut_dir_adj'] ?? 'en_attente') === 'en_attente')
        return ['label'=>'En attente — Dir. adjoint', 'class'=>'badge-or'];
    if (($f['statut_dir'] ?? 'en_attente') === 'en_attente')
        return ['label'=>'En attente — Directeur',    'class'=>'badge-or'];
    if (($f['statut_dei'] ?? 'en_attente') === 'en_attente')
        return ['label'=>'En attente — DEI',          'class'=>'badge-or'];
    // NOTE : VP_EIP n'est PAS utilisé pour VACATAIRE
    return ['label'=>'En attente', 'class'=>'badge-or'];
}

$initiales = implode('', array_map(
    function($p){ return mb_strtoupper(mb_substr($p, 0, 1)); },
    array_slice(explode(' ', $enseignant['nom']), 0, 2)
));

// Calculs volumes
$totalCmValide = 0; $totalTdValide = 0; $hasValidee = false;
foreach ($fiches as $f) {
    if ($f['statut'] === 'validee') {
        $hasValidee = true;
        $totalCmValide += (int)$f['volume_cm'];
        $totalTdValide += (int)$f['volume_td'];
    }
}
$isPermanent   = ($enseignant['type_enseignant'] ?? 'permanent') === 'permanent';
$volStatutaire = (int)($enseignant['volume_statutaire'] ?? 0);
$abattement    = (int)($enseignant['abattement']        ?? 0);
$volAEffectuer = ($isPermanent && $volStatutaire > 0) ? max(0, $volStatutaire - $abattement) : null;

// Fiches par semestre
$s1Fiches  = array_values(array_filter($fiches, function($f){ return ($f['semestre']??'')==='S1' && empty($f['is_encadrement']); }));
$s2Fiches  = array_values(array_filter($fiches, function($f){ return ($f['semestre']??'')==='S2' && empty($f['is_encadrement']); }));
$encFiches = array_values(array_filter($fiches, function($f){ return !empty($f['is_encadrement']) || ($f['semestre']??'')==='ENC'; }));
$tS1cm  = array_sum(array_column($s1Fiches,'volume_cm'));
$tS1td  = array_sum(array_column($s1Fiches,'volume_td'));
$tS1tp  = array_sum(array_column($s1Fiches,'volume_tp'));
$tS2cm  = array_sum(array_column($s2Fiches,'volume_cm'));
$tS2td  = array_sum(array_column($s2Fiches,'volume_td'));
$tS2tp  = array_sum(array_column($s2Fiches,'volume_tp'));
$tEncCm = array_sum(array_column($encFiches,'volume_cm'));
$tEncTd = array_sum(array_column($encFiches,'volume_td'));
$tEncTp = array_sum(array_column($encFiches,'volume_tp'));
$tCm = $tS1cm+$tS2cm+$tEncCm; $tTd = $tS1td+$tS2td+$tEncTd; $tTp = $tS1tp+$tS2tp+$tEncTp;

$dateImpression = date('d/m/Y à H:i');

// Acteurs de validation
// VP EIP uniquement pour les fiches VACATAIRE
        $sigActors = [
            ['role'=>'chef_dept',         'titre'=>'Le Chef de Département'],
            ['role'=>'directeur_adjoint', 'titre'=>'Le Directeur Adjoint'],
            ['role'=>'directeur',         'titre'=>'Le Directeur'],
            ['role'=>'dei',               'titre'=>'La DEI'],
        ];
        // VP EIP ne signe PAS la fiche programmatique (seulement l'acte de nomination)
        // Donc sigActors ne contient jamais vp_eip ici
?>
<style>
/* ── Styles fiche programmatique ── */
.fp-wrapper {
  background:#fff; border:1.5px solid #ccc; border-radius:6px;
  padding:12px 14px; margin-bottom:0.8rem;
  font-family:Arial,Helvetica,sans-serif; font-size:9pt; color:#000;
  box-shadow:0 2px 10px rgba(0,0,0,.07);
}
.fp-table { width:100%; border-collapse:collapse; font-size:8.5pt; }
.fp-table th, .fp-table td { border:1px solid #000; padding:2px 2px; }
.fp-table th { background:#e0e0e0; text-align:center; font-weight:700; }
.fp-sem-h { background:#f0f0f0; text-align:center; font-weight:700; font-style:italic; }
.fp-tot td { background:#d0d0d0; font-weight:700; text-align:center; }
.fp-grand td { background:#b0b0b0; font-weight:700; text-align:center; }
.fp-sig { width:100%; border-collapse:collapse; margin-top:6px; }
.fp-sig td { width:25%; text-align:center; vertical-align:top; padding:3px 4px; }
.fp-sig-titre { font-weight:700; font-size:8pt; text-decoration:underline; margin-bottom:3px; }
.fp-sig-line { border-bottom:1px solid #000; margin:8px 5px 2px; }
/* Onglets */
.db-tabs { display:flex; gap:0; margin-bottom:0; border-bottom:2px solid var(--ujkz-vert); }
.db-tab {
  padding:8px 20px; font-size:13px; font-weight:600; cursor:pointer;
  border:1.5px solid transparent; border-bottom:none; border-radius:6px 6px 0 0;
  background:#f5f5f5; color:var(--gray-600); text-decoration:none;
  transition:all .15s;
}
.db-tab:hover { background:#e8f5ee; color:var(--ujkz-vert); }
.db-tab.active {
  background:#fff; color:var(--ujkz-vert);
  border-color:var(--ujkz-vert); border-bottom-color:#fff;
  margin-bottom:-2px; z-index:1;
}
.db-tab-content { display:none; }
.db-tab-content.active { display:block; }
/* Barre actions */
.fp-actions {
  background:#f8f8f8; border:1px solid #ddd; border-radius:0 0 4px 4px;
  padding:8px 14px; display:flex; gap:10px; align-items:center;
  flex-wrap:wrap; margin-bottom:1rem;
}
@media print {
  .no-print, .site-header, .site-subnav, .breadcrumb,
  nav, footer, .btn, .btn-group, .db-tabs, .fp-actions,
  .stat-grid, .page-hero, .card-lien { display:none !important; }
  body { background:#fff !important; }
  .fp-wrapper {
    box-shadow:none !important; border:none !important;
    padding:0 !important; margin:0 !important; max-width:none !important;
  }
  .db-tab-content { display:block !important; }
  @page { size:A4 portrait; margin:15mm 12mm; }
}
</style>

<!-- Hero -->
<div class="page-hero no-print">
  <div style="display:flex;align-items:center;gap:14px">
    <div class="avatar" style="width:50px;height:50px;font-size:18px"><?= $e($initiales) ?></div>
    <div>
      <div style="font-size:17px;font-weight:700"><?= $e($enseignant['nom']) ?></div>
      <div style="font-size:13px;opacity:.85">
        <?= $e($enseignant['matricule']) ?> &nbsp;·&nbsp;
        <?= $e(ucfirst($enseignant['type_enseignant'] ?? '')) ?>
        <?= !empty($enseignant['grade']) ? ' · ' . $e($enseignant['grade']) : '' ?>
      </div>
      <div style="font-size:12px;opacity:.70;margin-top:2px">
        <?= $e($enseignant['etab_beneficiaire'] ?? $enseignant['departement'] ?? '') ?>
      </div>
    </div>
  </div>
  <a href="index.php?token=<?= urlencode($tok) ?>" class="btn btn-gold">+ Nouveau cours</a>
</div>

<!-- Stats -->
<div class="stat-grid no-print">
  <div class="stat"><div class="stat-label">Cours déposés</div><div class="stat-val"><?= (int)$stats['total'] ?></div></div>
  <div class="stat"><div class="stat-label">En attente</div><div class="stat-val" style="color:var(--warn)"><?= (int)$stats['en_attente'] ?></div></div>
  <div class="stat"><div class="stat-label">Validés</div><div class="stat-val" style="color:var(--ujkz-vert)"><?= (int)$stats['validee'] ?></div></div>
  <?php if ($hasValidee): ?>
  <div class="stat"><div class="stat-label">CM validés</div><div class="stat-val" style="color:var(--ujkz-vert)"><?= $totalCmValide ?><span style="font-size:13px">h</span></div></div>
  <div class="stat"><div class="stat-label">TD/TP validés</div><div class="stat-val"><?= $totalTdValide ?><span style="font-size:13px">h</span></div></div>
  <?php endif; ?>
  <?php if ($volAEffectuer !== null): ?>
  <div class="stat"><div class="stat-label">Vol. à effectuer</div><div class="stat-val" style="color:var(--ujkz-or)"><?= $volAEffectuer ?><span style="font-size:13px">h</span></div></div>
  <?php endif; ?>
</div>

<!-- Onglets -->
<div class="db-tabs no-print">
  <a href="dashboard.php?token=<?= urlencode($tok) ?>&vue=fiche"
     class="db-tab <?= $vue==='fiche'?'active':'' ?>">📋 Fiche programmatique</a>
  <a href="dashboard.php?token=<?= urlencode($tok) ?>&vue=cours"
     class="db-tab <?= $vue==='cours'?'active':'' ?>">📚 Mes cours (<?= count($fiches) ?>)</a>
  <?php if (!empty($fichesAvecPreuves['S1'])): ?>
  <a href="dashboard.php?token=<?= urlencode($tok) ?>&vue=suivi_s1"
     class="db-tab <?= $vue==='suivi_s1'?'active':'' ?>"
     style="<?= $vue==='suivi_s1'?'':'color:#e65100;border-color:transparent' ?>">
    📑 Fiche de suivi S1
  </a>
  <?php endif; ?>
  <?php if (!empty($fichesAvecPreuves['S2'])): ?>
  <a href="dashboard.php?token=<?= urlencode($tok) ?>&vue=suivi_s2"
     class="db-tab <?= $vue==='suivi_s2'?'active':'' ?>"
     style="<?= $vue==='suivi_s2'?'':'color:#e65100;border-color:transparent' ?>">
    📑 Fiche de suivi S2
  </a>
  <?php endif; ?>
</div>

<?php 
  // Vérifier s'il existe des fiches avec preuves mais non validées par DEI
  $fiches_avec_preuves_non_dei = [];
  foreach ($fiches as $f) {
    $fid = (int)$f['id'];
    if (!empty($preuvesByFiche[$fid])) {
      $isDeiValidee = ($f['statut_dei'] ?? '') === 'validee';
      if (!$isDeiValidee) {
        $fiches_avec_preuves_non_dei[] = $f;
      }
    }
  }
  
  // Afficher une alerte si des fiches ont des preuves mais ne sont pas validées par DEI
  if (!empty($fiches_avec_preuves_non_dei)): 
?>
<div style="margin-bottom: 1.5rem; padding: 15px 20px; background-color: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; color: #856404;">
  <div style="font-weight: 600; margin-bottom: 8px;">ℹ️ Fiches de suivi en attente</div>
  <p style="margin: 0; font-size: 14px;">
    Les fiches de suivi seront disponibles une fois que vos fiches programmatiques auront été <strong>validées par la DEI</strong>. 
    Actuellement, <?= count($fiches_avec_preuves_non_dei) ?> fiche(s) avec preuve(s) en attente de validation DEI.
  </p>
</div>
<?php endif; ?>


<!-- ════════ ONGLET FICHE PROGRAMMATIQUE ════════ -->
<div class="db-tab-content <?= $vue==='fiche'?'active':'' ?>">

  <!-- Barre d'actions -->
  <div class="fp-actions no-print">
    <button onclick="window.print()" class="btn btn-sm btn-primary">🖨 Imprimer / PDF</button>
    <?php if (($enseignant['type_enseignant']??'permanent') !== 'permanent'): // Masqué pour IESR UJKZ permanent ?>
    <a href="index.php?token=<?= urlencode($tok) ?>&nouveau=1"
       class="btn btn-sm btn-primary" style="background:var(--ujkz-vert,#2d6a2d)">
      + Nouvelle fiche
    </a>
    <?php endif; ?>
    <a href="index.php?token=<?= urlencode($tok) ?>"
       class="btn btn-sm btn-gold">✏ Modifier fiches existantes</a>
    <span style="font-size:11px;color:#888;margin-left:auto">
      Imprimé le <strong><?= $dateImpression ?></strong>
    </span>
  </div>

  <?php if (empty($fiches)): ?>
  <div class="fp-wrapper" style="text-align:center;padding:3rem">
    <div style="font-size:3rem;margin-bottom:1rem">📄</div>
    <div style="font-size:15px;font-weight:600;margin-bottom:.5rem">Aucun cours déposé</div>
    <div style="color:#666;margin-bottom:1.5rem">Commencez par déposer vos cours pour générer votre fiche programmatique.</div>
    <a href="index.php?token=<?= urlencode($tok) ?>" class="btn btn-primary">+ Déposer mes cours</a>
  </div>
  <?php else: ?>

  <!-- ════ FICHE STYLE OFFICIEL ════ -->
  <div class="fp-wrapper">

    <!-- En-tête 3 colonnes -->
    <table style="width:100%;border:none;border-collapse:collapse;margin-bottom:10px">
      <tr>
        <td style="width:36%;vertical-align:top;text-align:center;font-size:8.5pt;font-weight:700;line-height:1.55">
          MINISTÈRE DE L'ENSEIGNEMENT<br>SUPÉRIEUR, DE LA RECHERCHE<br>ET DE L'INNOVATION<br>
          <span style="font-weight:400">-----------</span><br>
          SECRÉTARIAT GÉNÉRAL<br><span style="font-weight:400">-----------</span><br>
          <strong>UNIVERSITÉ JOSEPH KI-ZERBO</strong><br>
          <span style="font-weight:400">-----------</span><br>PRÉSIDENCE<br>
          <span style="font-weight:400">-------------</span>
        </td>
        <td style="width:24%;text-align:center;vertical-align:middle">
          <img src="logo_ujkz.jpg" alt="Logo UJKZ" style="width:68px;height:68px;object-fit:contain">
        </td>
        <td style="width:36%;vertical-align:top;text-align:right;font-size:8.5pt;line-height:1.7">
          <strong><em>BURKINA FASO</em></strong><br>
          <em>La Patrie ou la Mort, nous Vaincrons</em><br>
          <span style="letter-spacing:2px;font-size:7.5pt">·········································</span><br>
          <?php 
          // ✓ Utiliser l'année de la fiche BD (source de vérité = données saisies)
          $yearDisplay = !empty($fiches) ? ($fiches[0]['annee_academique'] ?? '2024-2025') : ($annee ?? '2024-2025');
          ?>
          Année universitaire <strong><?= $e($yearDisplay) ?></strong>
        </td>
      </tr>
    </table>

    <!-- Numéro de fiche et QR code -->
    <?php
    if (!empty($fiches)) {
        $fichePrincipale = $fiches[0];
        $numeroFiche = $fichePrincipale['numero_fiche'] ?? '';
        $qrcodeToken = $fichePrincipale['qrcode_token'] ?? '';
        $qrcodeBase64 = '';
        
        // Générer le numéro si absent
        if (!$numeroFiche) {
            $anneeNum = explode('-', $yearDisplay ?? '2024-2025')[0];
            $numeroFiche = 'FP-' . $anneeNum . '-' . str_pad((string)$fichePrincipale['id'], 4, '0', STR_PAD_LEFT);
        }
        
        // Générer le token QR si absent
        if (!$qrcodeToken) {
            $qrcodeToken = bin2hex(random_bytes(16));
        }
        
        // Générer un vrai QR code via API gratuite
        // URL de vérification - adapter le domaine selon l'environnement
        $verificationUrl = 'http://127.0.0.1/fiches_programmatiques/verifier-fiche.php?token=' . urlencode($qrcodeToken);
        $qrcodeBase64 = 'https://api.qrserver.com/v1/create-qr-code/?size=60x60&data=' . urlencode($verificationUrl);
    ?>
    <table style="width:100%;border:none;border-collapse:collapse;margin-bottom:4px">
        <tr>
            <td style="width:70%;vertical-align:top;font-size:9pt;padding:2px">
                <strong>Numéro : <?= $e($numeroFiche) ?></strong><br>
                <span style="font-size:8pt;color:#666">Vérification</span>
            </td>
            <td style="width:30%;text-align:right;vertical-align:top;padding:2px">
                <img src="<?= $qrcodeBase64 ?>" alt="QR Code" style="width:60px;height:60px;border:1px solid #999">
            </td>
        </tr>
    </table>
    <?php } ?>

    <!-- Titre -->
    <div style="border:1.5px solid #000;background:#e0e0e0;text-align:center;padding:4px;margin-bottom:2px">
      <span style="font-size:11pt;font-weight:700">FICHE PROGRAMMATIQUE</span>
    </div>
    <div style="text-align:center;font-size:9pt;font-weight:700;text-decoration:underline;margin-bottom:3px">
      Pour enseignant <?= $e($enseignant['type_enseignant'] ?? 'permanent') ?>
    </div>

    <!-- Informations enseignant -->
    <?php
    $nom    = trim($enseignant['nom'] ?? '');
    $prenom = $enseignant['prenom'] ?? '';
    $grade  = $enseignant['grade']  ?? '';
    $dateN  = !empty($enseignant['date_nomination'])
              ? date('d/m/Y', strtotime($enseignant['date_nomination'])) : '';
    $vs     = $enseignant['volume_statutaire']  ?? '';
    $ab     = $enseignant['abattement']         ?? '';
    $mot    = $enseignant['motif_abattement']   ?? '';
    $va     = $enseignant['volume_apres_abatt'] ?? '';
    $er     = $enseignant['etab_rattachement']  ?? '';
    $ea     = $enseignant['etab_administratif'] ?? '';
    $eb     = $enseignant['etab_beneficiaire']  ?? '';
    $diplome= $enseignant['diplome'] ?? '';
    $mois   = $enseignant['mois_execution'] ?? '';
    // Détecter IESR UJKZ pour affichage conditionnel des volumes
    $_rvD = strtolower(trim($er ?? ''));
    $showVolD = (($_rvD === '' || strpos($_rvD,'ujkz')!==false
              || strpos($_rvD,'ki-zerbo')!==false || strpos($_rvD,'ki zerbo')!==false)
              && ($enseignant['type_enseignant'] ?? 'permanent') !== 'vacataire');
    ?>
    <div style="font-size:8.5pt;line-height:1.4;margin-bottom:3px">
      <div>
        Nom : <strong><?= $e($nom) ?></strong>
        <?php if($prenom): ?>&nbsp;&nbsp; Prénom(s) : <strong><?= $e($prenom) ?></strong><?php endif; ?>
        <?php if($diplome): ?>&nbsp;&nbsp; Diplôme(s) : <strong><?= $e($diplome) ?></strong><?php endif; ?>
      </div>
      <div>
        Grade : <strong><?= $e($grade) ?></strong>
        <?php if($dateN): ?>&nbsp;&nbsp;&nbsp; Date de Nomination : <strong><?= $e($dateN) ?></strong><?php endif; ?>
      </div>
      <?php if ($vs !== '' && $showVolD): ?>
      <div>
        Volume horaire statutaire : <strong><?= $e($vs) ?>h</strong>
        &nbsp;&nbsp; Abattement : <strong><?= $e($ab) ?>h</strong>
        <?php if($mot): ?>&nbsp;&nbsp; Motif : <strong><?= $e($mot) ?></strong><?php endif; ?>
      </div>
      <div>Volume horaire obligatoire après abattement : <strong><?= $e($va) ?>h</strong></div>
      <?php endif; ?>
      <?php if ($er): ?>
      <div>IESR de rattachement : <strong><?= $e($er) ?></strong></div>
      <?php if ($ea && $showVolD): ?>
      <div>Établissement de rattachement administratif : <strong><?= $e($ea) ?></strong></div>
      <?php endif; ?>
      <?php endif; ?>
      <?php
      // Map étab → liste de cours (résoudre IDs en noms)
      static $_etabByIdD = null; static $_deptByIdD = null;
      if ($_etabByIdD === null) {
          $_etabByIdD = []; $_deptByIdD = [];
          $pdoD = Database::getInstance();
          foreach ($pdoD->query("SELECT id,nom FROM etablissements WHERE actif=1")->fetchAll() as $r)
              $_etabByIdD[(int)$r['id']] = $r['nom'];
          foreach ($pdoD->query("SELECT id,nom,sigle FROM departements WHERE actif=1")->fetchAll() as $r)
              $_deptByIdD[(int)$r['id']] = $r['nom'].(!empty($r['sigle'])?' ('.$r['sigle'].')':'');
      }
      $ebMapD = [];
      foreach ($fiches as $_fl) {
          if (!empty($_fl['is_encadrement'])) continue;
          $ebIdD = (int)($_fl['etab_beneficiaire_fiche'] ?? 0);
          if ($ebIdD === 0) continue;
          $ebNomD = $_etabByIdD[$ebIdD] ?? "Étab.#$ebIdD";
          $cv     = trim($_fl['cours'] ?? '');
          if (!isset($ebMapD[$ebNomD])) $ebMapD[$ebNomD] = [];
          if ($cv !== '' && !in_array($cv, $ebMapD[$ebNomD], true)) $ebMapD[$ebNomD][] = $cv;
      }
      ?>
      <?php if (!empty($ebMapD)): ?>
      <div style="line-height:1.8">
        <span style="font-weight:700">Établissement bénéficiaire des enseignements :</span>
        <?php foreach ($ebMapD as $etabNomD => $coursListD): ?>
        <div style="margin-left:12px">
          — <strong><?= $e($etabNomD) ?></strong>
          <?php if (!empty($coursListD)): ?>
          <span style="font-weight:400;color:#333"> : <?= $e(implode(', ', $coursListD)) ?></span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php elseif ($eb): ?>
      <div>Établissement bénéficiaire des enseignements : <strong><?= $e($eb) ?></strong></div>
      <?php endif; ?>
      <div>Mois et semaines d'exécution des heures :
        <?= $mois ? '<strong>'.$e($mois).'</strong>' : '<span style="color:#999">'.str_repeat('.', 30).'</span>' ?>
      </div>
    </div>

    <!-- Titre tableau -->
    <div style="text-align:center;font-size:8.5pt;margin:2px 0 2px">
      Tableau descriptif des enseignements
    </div>

    <!-- Tableau -->
    <?php
    function fpRow(int $n, array $f, callable $e): string {
        $cm = (int)($f['volume_cm']??0);
        $td = (int)($f['volume_td']??0);
        $tp = (int)($f['volume_tp']??0);
        $code = $f['code_ue'] ?: ($f['code'] ?: '');
        return '<tr>'
            .'<td style="border:1px solid #000;padding:2px;text-align:center">'.$n.'</td>'
            .'<td style="border:1px solid #000;padding:2px;text-align:center">'.$e($code).'</td>'
            .'<td style="border:1px solid #000;padding:2px">'.$e($f['parcours']??'').'</td>'
            .'<td style="border:1px solid #000;padding:2px">'.$e($f['cours']??'').'</td>'
            .'<td style="border:1px solid #000;padding:2px;text-align:center">'.$e($f['ntc']??'').'</td>'
            .'<td style="border:1px solid #000;padding:2px;text-align:center">'.($cm?:'-').'</td>'
            .'<td style="border:1px solid #000;padding:2px;text-align:center">'.($td?:'-').'</td>'
            .'<td style="border:1px solid #000;padding:4px;text-align:center">'.($tp?:'-').'</td>'
            .'</tr>';
    }
    ?>
    <table class="fp-table">
      <thead>
        <tr>
          <th rowspan="2" style="width:4%">N°</th>
          <th rowspan="2" style="width:11%">CODE</th>
          <th rowspan="2" style="width:18%">PARCOURS</th>
          <th rowspan="2">UE ou ECUE</th>
          <th rowspan="2" style="width:5%">NTC</th>
          <th colspan="3">Volume horaire<sup>1</sup></th>
        </tr>
        <tr>
          <th style="width:6%">CT</th>
          <th style="width:6%">TD</th>
          <th style="width:6%">TP</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($s1Fiches): ?>
        <tr><td colspan="8" class="fp-sem-h">Premier semestre de l'année</td></tr>
        <?php $cnt=0; foreach ($s1Fiches as $f): echo fpRow(++$cnt,$f,$e); endforeach; ?>
        <tr class="fp-tot">
          <td colspan="5">TOTAL DU SEMESTRE<sup>1</sup></td>
          <td><?= $tS1cm?:'' ?></td><td><?= $tS1td?:'' ?></td><td><?= $tS1tp?:'' ?></td>
        </tr>
        <?php endif; ?>

        <?php if ($s2Fiches): ?>
        <tr><td colspan="8" class="fp-sem-h">Deuxième semestre de l'année</td></tr>
        <?php $cnt=0; foreach ($s2Fiches as $f): echo fpRow(++$cnt,$f,$e); endforeach; ?>
        <tr class="fp-tot">
          <td colspan="5">TOTAL DU SEMESTRE<sup>2</sup></td>
          <td><?= $tS2cm?:'' ?></td><td><?= $tS2td?:'' ?></td><td><?= $tS2tp?:'' ?></td>
        </tr>
        <?php endif; ?>

        <?php if ($encFiches): ?>
        <tr><td colspan="8" style="border:1px solid #000;padding:4px;text-align:center;font-weight:700;font-style:italic;font-size:9pt;background:#f0eefc">Encadrement</td></tr>
        <?php $cnt=0; foreach ($encFiches as $f): echo fpRow(++$cnt,$f,$e); endforeach; ?>
        <tr style="background:#dddaf5;font-weight:700">
          <td colspan="5" style="border:1px solid #000;padding:4px;text-align:center;font-weight:700;font-size:9pt">TOTAL ENCADREMENT</td>
          <td style="border:1px solid #000;padding:4px;text-align:center"><?= $tEncCm?:'' ?></td>
          <td style="border:1px solid #000;padding:4px;text-align:center"><?= $tEncTd?:'' ?></td>
          <td style="border:1px solid #000;padding:4px;text-align:center"><?= $tEncTp?:'' ?></td>
        </tr>
        <?php endif; ?>

        <tr class="fp-grand">
          <td colspan="5">TOTAL S1 ET S2 + ENCADREMENT</td>
          <td><?= $tCm?:'' ?></td><td><?= $tTd?:'' ?></td><td><?= $tTp?:'' ?></td>
        </tr>
        <?php
        // Heures sup : IESR UJKZ uniquement
        $vaDb   = (float)($enseignant['volume_apres_abatt'] ?? 0);
        $rattachDb = strtolower(trim($enseignant['etab_rattachement'] ?? ''));
        $isUJKZdb  = ($rattachDb === '' || strpos($rattachDb,'ujkz')!==false
                   || strpos($rattachDb,'ki-zerbo')!==false || strpos($rattachDb,'ki zerbo')!==false);
        $typeEnsDb = $enseignant['type_enseignant'] ?? 'permanent';
        $hSupDb = null;
        if ($isUJKZdb && $typeEnsDb !== 'vacataire' && $vaDb > 0) {
            $hSupDb = $tEncCm + ($tS1cm + $tS2cm) + 0.75*($tS1td+$tS2td+$tS1tp+$tS2tp) - $vaDb;
        }
        ?>
        <?php if ($hSupDb !== null): ?>
        <tr style="background:#fff9e6">
          <td colspan="5" style="border:1px solid #aaa;padding:4px 5px;font-size:8pt;font-style:italic;color:#555">
            Heures supplémentaires prévisionnelles
          </td>
          <td colspan="3" style="border:1px solid #aaa;padding:4px;text-align:center;font-weight:700;color:<?= $hSupDb >= 0 ? '#1a4a1a' : '#b00' ?>">
            <?= number_format($hSupDb, 1) ?>h
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>

    <!-- Signatures / validations -->
    <div style="margin-top:16px">
      <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px">
        <div style="font-size:9.5pt;font-weight:600">Vu et approuvé par</div>
        <div style="font-size:9pt;color:#555">
          Ouagadougou, le <strong><?= $dateImpression ?></strong>
          <span class="no-print" style="font-size:10px;color:#aaa;margin-left:4px">(date d'impression)</span>
        </div>
      </div>

      <table class="fp-sig">
        <tr>
          <?php foreach ($sigActors as $actor):
            $v     = $historiqueGlobal[$actor['role']] ?? null;
            $dec   = $v['decision'] ?? '';
            $nomV  = $v['valideur_nom'] ?? '';
            $dateV = !empty($v['created_at']) ? date('d/m/Y', strtotime($v['created_at'])) : '';
          ?>
          <td>
            <div class="fp-sig-titre"><?= $e($actor['titre']) ?></div>
            <?php if ($dec === 'valide'): ?>
              <div style="color:#1a6b1a;font-size:9pt;text-align:center">
                ✔ Validé par <strong><?= $e($nomV) ?></strong><br>
                <span style="font-size:8.5pt">Le <?= $e($dateV) ?></span>
              </div>
            <?php elseif ($dec === 'rejete'): ?>
              <div style="color:#b00;font-size:9pt;text-align:center">
                ✖ Rejeté — <?= $e($dateV) ?>
                <?php if (!empty($v['motif_rejet'])): ?>
                <br><span style="font-size:8pt;font-style:italic"><?= $e(mb_substr($v['motif_rejet'],0,50)) ?>…</span>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <div style="color:#aaa;font-size:9pt;font-style:italic;text-align:center;margin-bottom:4px">
                En attente de signature
              </div>
              <div class="fp-sig-line"></div>
              <div style="text-align:center;font-size:8pt;color:#888">Signature &amp; cachet</div>
            <?php endif; ?>
          </td>
          <?php endforeach; ?>
        </tr>
      </table>
    </div>

    <!-- Notes officielles -->
    <div style="margin-top:14px;font-size:7.5pt;border-top:1px solid #aaa;padding-top:4px;color:#555;line-height:1.6">
      <sup>1</sup> Établir une fiche de suivi par établissement (CUP, UFR ou Institut) où intervient l'enseignant.<br>
      <sup>2</sup> Calculer le volume horaire sans convertir les TD et les TP en heures de cours théoriques.<br>
      NB : NTC = nombre total de crédits. Ne remplir qu'une seule fiche pour toutes les interventions sur le campus.<br>
      Ces fiches doivent être impérativement déposées après la réunion d'attribution des heures.
    </div>

    <!-- Pied de page -->
    <div style="margin-top:8px;font-size:7pt;color:#bbb;text-align:center;border-top:1px solid #eee;padding-top:4px">
      Système de gestion des fiches programmatiques de l'UJKZ — <?= $dateImpression ?>
    </div>

  </div><!-- /fp-wrapper -->
  
  <!-- ════════ DEMANDE DE VACATION (si VACATAIRE) ════════ -->
  <?php if (!empty($fiches) && !empty($demandesVacation)): ?>
    <?php foreach ($fiches as $fiche): ?>
      <?php if (($fiche['type_workflow'] ?? '') === 'VACATAIRE' && isset($demandesVacation[(int)$fiche['id']])): ?>
      
      <div style="page-break-before: always; margin-top: 40px; border-top: 3px solid #00a651; padding-top: 40px;">
        <div id="demande-vacation-<?= (int)$fiche['id'] ?>" style="background: white; padding: 0;">
          <?= $demandesVacation[(int)$fiche['id']] ?>
        </div>
      </div>
      
      <?php endif; ?>
    <?php endforeach; ?>
  <?php endif; ?>
  
  <?php endif; // fiches non vides ?>

</div><!-- /onglet fiche -->

<!-- ════════ ONGLET MES COURS ════════ -->
<div class="db-tab-content <?= $vue==='cours'?'active':'' ?>">

  <div class="card" style="padding:0;margin-bottom:1.25rem">
    <div class="card-header" style="padding:1.25rem 1.5rem .875rem">
      <div class="card-title">Mes cours déposés</div>
      <div style="display:flex;gap:8px;align-items:center">
        <span style="font-size:12px;color:var(--gray-600)"><?= count($fiches) ?> cours</span>
        <a href="index.php?token=<?= urlencode($tok) ?>" class="btn btn-sm btn-primary">+ Ajouter</a>
      </div>
    </div>
    <?php if (empty($fiches)): ?>
    <div style="padding:2.5rem;text-align:center;color:var(--gray-400)">
      <div style="font-size:2rem;margin-bottom:.5rem">📄</div>
      <div style="font-weight:500">Aucun cours déposé</div>
    </div>
    <?php else: ?>
    <?php foreach ($fiches as $f): ?>
    <?php
      $st    = statutEtapeLabel($f);
      $nbPrv = $preuvesCounts[(int)$f['id']] ?? 0;
      $code  = $f['code_ue'] ?? $f['code'] ?? '';
    ?>
    <div class="fiche-row">
      <div style="flex:1;min-width:0">
        <div class="fiche-title"><?= $e($f['cours']) ?></div>
        <div class="fiche-meta">
          <?= $code ? $e($code).' · ' : '' ?>
          <?= $e($f['semestre']) ?> ·
          CM&nbsp;<?= (int)$f['volume_cm'] ?>h
          <?= (int)($f['volume_td']??0) ? ' / TD&nbsp;'.(int)$f['volume_td'].'h' : '' ?>
          <?= (int)($f['volume_tp']??0) ? ' / TP&nbsp;'.(int)$f['volume_tp'].'h' : '' ?>
          · <?= $e(date('d/m/Y', strtotime($f['submitted_at']))) ?>
          <?php if ($nbPrv > 0): ?>
          · <span style="color:var(--ujkz-vert)">📎 <?= $nbPrv ?> justif.</span>
          <?php endif; ?>
          <?php if (!empty($f['modifie_le'])): ?>
          · <span style="color:var(--warn)">Modifié <?= $e(date('d/m/Y', strtotime($f['modifie_le']))) ?></span>
          <?php endif; ?>
          <?php if (!empty($f['est_detachee'])): ?>
          · <span style="color:#ff9800;font-weight:600">🏛️ Détachée par établissement</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="btn-group">
        <span class="badge <?= $e($st['class']) ?>"><?= $e($st['label']) ?></span>
        <a href="dashboard.php?token=<?= urlencode($tok) ?>&fiche=<?= (int)$f['id'] ?>"
           class="btn btn-sm btn-outline-green">Détail &amp; suivi</a>
        <?php if ($f['statut'] === 'en_attente'): ?>
        <a href="index.php?token=<?= urlencode($tok) ?>&edit=<?= (int)$f['id'] ?>"
           class="btn btn-sm" style="color:var(--warn);border-color:var(--warn)">Modifier</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div><!-- /onglet cours -->

<!-- ════════ ONGLETS FICHE DE SUIVI ════════ -->
<?php foreach (['S1'=>'suivi_s1','S2'=>'suivi_s2'] as $semSuivi => $vueSuivi): ?>
<?php if (!empty($fichesAvecPreuves[$semSuivi])): ?>
<div class="db-tab-content <?= $vue===$vueSuivi?'active':'' ?>">

  <!-- Barre d'actions -->
  <div class="fp-actions no-print">
    <button onclick="window.print()" class="btn btn-sm btn-primary">🖨 Imprimer / PDF</button>
    <span style="font-size:11px;color:#888;margin-left:auto">
      Fiche semestrielle de suivi <?= $semSuivi ?> — <?= $dateImpression ?>
    </span>
  </div>

  <?php
  // ── Consolider TOUS les cours du semestre avec leurs preuves ──
  $toutCmC = 0; $toutTdC = 0; $toutTpC = 0;
  $toutCmE = 0; $toutTdE = 0;
  $tousCommentaires = [];
  foreach ($fichesAvecPreuves[$semSuivi] as $fS) {
      $toutCmC += (int)($fS['volume_cm'] ?? 0);
      $toutTdC += (int)($fS['volume_td'] ?? 0);
      $toutTpC += (int)($fS['volume_tp'] ?? 0);
      foreach ($fS['preuves'] ?? [] as $pS) {
          $toutCmE += (int)($pS['volume_cm_effectue'] ?? 0);
          $toutTdE += (int)($pS['volume_td_effectue'] ?? 0);
          if (!empty($pS['commentaire'])) $tousCommentaires[] = trim($pS['commentaire']);
      }
  }
  $moisSuivi = implode(' — ', array_unique($tousCommentaires));
  if (!$moisSuivi) $moisSuivi = $enseignant['mois_execution'] ?? '';

  // Infos enseignant
  $nomE     = trim($enseignant['nom'] ?? '');
  $prenomE  = $enseignant['prenom'] ?? '';
  $gradeE   = $enseignant['grade']  ?? '';
  $dateNE   = !empty($enseignant['date_nomination']) ? date('d/m/Y', strtotime($enseignant['date_nomination'])) : '';
  $vsE      = $enseignant['volume_statutaire']  ?? '';
  $abE      = $enseignant['abattement']         ?? '';
  $motE     = $enseignant['motif_abattement']   ?? '';
  $vaE      = $enseignant['volume_apres_abatt'] ?? '';
  $erE      = $enseignant['etab_rattachement']  ?? '';
  $ebE      = $enseignant['etab_beneficiaire']  ?? '';
  $diplomeE = $enseignant['diplome'] ?? '';
  // Détecter IESR UJKZ pour fiche de suivi
  $_rvS = strtolower(trim($erE ?? ''));
  $showVolS = (($_rvS === '' || strpos($_rvS,'ujkz')!==false
             || strpos($_rvS,'ki-zerbo')!==false || strpos($_rvS,'ki zerbo')!==false)
             && ($enseignant['type_enseignant'] ?? 'permanent') !== 'vacataire');
  ?>

  <div class="fp-wrapper">

    <!-- En-tête 3 colonnes -->
    <table style="width:100%;border:none;border-collapse:collapse;margin-bottom:10px">
      <tr>
        <td style="width:36%;vertical-align:top;text-align:center;font-size:8.5pt;font-weight:700;line-height:1.55">
          MINISTÈRE DE L'ENSEIGNEMENT<br>SUPÉRIEUR, DE LA RECHERCHE<br>
          SCIENTIFIQUE ET DE L'INNOVATION<br>
          <span style="font-weight:400">-----------</span><br>
          SECRÉTARIAT GÉNÉRAL<br><span style="font-weight:400">-----------</span><br>
          <strong>UNIVERSITÉ JOSEPH KI-ZERBO</strong><br>
          <span style="font-weight:400">-----------</span><br>PRÉSIDENCE<br>
          <span style="font-weight:400">-------------</span>
        </td>
        <td style="width:24%;text-align:center;vertical-align:middle">
          <img src="logo_ujkz.jpg" alt="Logo UJKZ" style="width:68px;height:68px;object-fit:contain">
        </td>
        <td style="width:36%;vertical-align:top;text-align:right;font-size:8.5pt;line-height:1.7">
          <strong><em>BURKINA FASO</em></strong><br>
          <em>La Patrie ou la Mort, nous Vaincrons</em><br>
          <span style="letter-spacing:2px;font-size:7.5pt">·········································</span><br>
          <?php 
          // ✓ Utiliser l'année de la fiche BD (source de vérité = données saisies)
          $yearSuivi = '2024-2025';
          if (!empty($fichesAvecPreuves[$semSuivi]) && !empty($fichesAvecPreuves[$semSuivi][0])) {
            $yearSuivi = $fichesAvecPreuves[$semSuivi][0]['annee_academique'] ?? '2024-2025';
          }
          ?>
          Année universitaire <strong><?= $e($yearSuivi) ?></strong>
        </td>
      </tr>
    </table>

    <!-- Titre -->
    <div style="border:1.5px solid #000;background:#e0e0e0;text-align:center;padding:7px;margin-bottom:3px">
      <span style="font-size:12pt;font-weight:700">FICHE SEMESTRIELLE DE SUIVI DES HEURES EFFECTUÉES</span>
    </div>
    <div style="text-align:center;font-size:10.5pt;font-weight:700;text-decoration:underline;margin-bottom:5px">
      Pour enseignant <?= $e($enseignant['type_enseignant'] ?? 'permanent') ?>
    </div>

    <!-- Semestre coché -->
    <div style="font-size:10pt;margin-bottom:5px">
      Semestre :
      <span style="display:inline-block;border:1.5px solid #000;width:14px;height:14px;
                   text-align:center;line-height:12px;margin:0 4px;vertical-align:middle">
        <?= $semSuivi==='S1' ? '✓' : '' ?>
      </span> S1 &nbsp;&nbsp;
      <span style="display:inline-block;border:1.5px solid #000;width:14px;height:14px;
                   text-align:center;line-height:12px;margin:0 4px;vertical-align:middle">
        <?= $semSuivi==='S2' ? '✓' : '' ?>
      </span> S2
    </div>

    <!-- Infos enseignant -->
    <div style="font-size:10pt;line-height:1.85;margin-bottom:5px">
      <div>
        Nom : <strong><?= $e($nomE) ?></strong>
        <?php if($prenomE): ?>&nbsp;&nbsp; Prénom(s) : <strong><?= $e($prenomE) ?></strong><?php endif; ?>
        <?php if($diplomeE): ?>&nbsp;&nbsp; Diplôme(s) : <strong><?= $e($diplomeE) ?></strong><?php endif; ?>
      </div>
      <div>
        Grade : <strong><?= $e($gradeE) ?></strong>
        <?php if($dateNE): ?>&nbsp;&nbsp; Date de Nomination : <strong><?= $e($dateNE) ?></strong><?php endif; ?>
      </div>
      <?php if ($vsE !== '' && $showVolS): ?>
      <div>
        Vol. horaire statutaire : <strong><?= $e($vsE) ?>h</strong>
        &nbsp; Abattement : <strong><?= $e($abE) ?></strong>
        <?php if($motE): ?>&nbsp; Motif : <strong><?= $e($motE) ?></strong><?php endif; ?>
      </div>
      <div>Volume obligatoire après abattement : <strong><?= $e($vaE) ?>h</strong></div>
      <?php endif; ?>
      <?php if($erE): ?>
      <div>Rattachement administratif : <strong><?= $e($erE) ?></strong></div>
      <?php endif; ?>
      <div>Établissement bénéficiaire : <strong><?= $e($ebE) ?></strong></div>
      <div>Mois et semaines d'exécution des heures :
        <strong><?= $moisSuivi ? $e($moisSuivi) : str_repeat('.', 30) ?></strong>
      </div>
    </div>

    <!-- Titre tableau -->
    <div style="text-align:center;font-size:9.5pt;margin:5px 0 4px">
      Tableau descriptif des enseignements confiés et effectués
    </div>

    <!-- Tableau : TOUS les cours du semestre avec justificatifs -->
    <table class="fp-table" style="font-size:9.5pt">
      <thead>
        <tr>
          <th rowspan="2" style="width:4%">N°</th>
          <th rowspan="2" style="width:9%">CODE</th>
          <th rowspan="2" style="width:13%">PARCOURS</th>
          <th rowspan="2">ECUE</th>
          <th rowspan="2" style="width:5%">NTC</th>
          <th colspan="3" style="width:16%">Vol. horaire confié<sup>2</sup></th>
          <th colspan="3" style="width:16%">Vol. horaire effectué<sup>3</sup></th>
          <th rowspan="2" style="width:16%;text-align:center;font-size:8pt" class="no-print">Actions</th>
        </tr>
        <tr>
          <th style="width:5%">CT</th><th style="width:5%">TD</th><th style="width:5%">TP</th>
          <th style="width:5%">CT</th><th style="width:5%">TD</th><th style="width:5%">TP</th>
        </tr>
      </thead>
      <tbody>
        <?php $num = 0; ?>
        <?php foreach ($fichesAvecPreuves[$semSuivi] as $fS):
          $num++;
          $codeS = $fS['code_ue'] ?: ($fS['code'] ?? '');
          $cmCS  = (int)($fS['volume_cm'] ?? 0);
          $tdCS  = (int)($fS['volume_td'] ?? 0);
          $tpCS  = (int)($fS['volume_tp'] ?? 0);
          // Volumes effectués = somme des preuves de ce cours
          $cmES = 0; $tdES = 0;
          foreach ($fS['preuves'] ?? [] as $pFS) {
              $cmES += (int)($pFS['volume_cm_effectue'] ?? 0);
              $tdES += (int)($pFS['volume_td_effectue'] ?? 0);
          }
        ?>
        <tr id="suivi-row-<?= (int)$fS['id'] ?>">
          <td style="text-align:center"><?= $num ?></td>
          <td style="text-align:center"><?= $e($codeS) ?></td>
          <td><?= $e($fS['parcours'] ?? '') ?></td>
          <td><?= $e($fS['cours'] ?? '') ?></td>
          <td style="text-align:center"><?= $e($fS['ntc'] ?? '') ?></td>
          <td style="text-align:center"><?= $cmCS ?: '-' ?></td>
          <td style="text-align:center"><?= $tdCS ?: '-' ?></td>
          <td style="text-align:center"><?= $tpCS ?: '-' ?></td>
          <td style="text-align:center" id="db-cm-eff-<?= (int)$fS['id'] ?>"><?= $cmES ?: '-' ?></td>
          <td style="text-align:center" id="db-td-eff-<?= (int)$fS['id'] ?>"><?= $tdES ?: '-' ?></td>
          <td style="text-align:center">-</td>
          <td class="no-print" style="text-align:center;padding:3px 4px;white-space:nowrap">
            <button type="button"
                    onclick="ouvrirModalSuivi(<?= (int)$fS['id'] ?>, '<?= $e(addslashes($fS['cours'] ?? '')) ?>')"
                    style="background:var(--ujkz-vert,#2d6a2d);color:#fff;border:none;border-radius:4px;
                           padding:3px 7px;font-size:10px;cursor:pointer;margin-bottom:3px;display:block;width:100%">
              📎 Ajouter preuve
            </button>
            <button type="button"
                    onclick="voirPreuvesSuivi(<?= (int)$fS['id'] ?>)"
                    style="background:#3a5fa0;color:#fff;border:none;border-radius:4px;
                           padding:3px 7px;font-size:10px;cursor:pointer;display:block;width:100%">
              🗂️ Voir
              <span id="db-nb-preuves-<?= (int)$fS['id'] ?>"
                    style="background:rgba(255,255,255,.3);border-radius:10px;padding:0 4px">
                <?= count($fS['preuves'] ?? []) ?>
              </span>
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
        <!-- Ligne TOTAL -->
        <tr class="fp-tot">
          <td colspan="5">TOTAL<sup>3</sup></td>
          <td><?= $toutCmC ?: '' ?></td>
          <td><?= $toutTdC ?: '' ?></td>
          <td><?= $toutTpC ?: '' ?></td>
          <td><?= $toutCmE ?: '' ?></td>
          <td><?= $toutTdE ?: '' ?></td>
          <td></td>
          <td class="no-print"></td>
        </tr>
      </tbody>
    </table>

    <!-- Signatures -->
    <div style="margin-top:16px">
      <div style="display:flex;justify-content:space-between;margin-bottom:6px;font-size:9pt">
        <span>Ouagadougou, le <?= $dateImpression ?></span>
        <span style="font-weight:600">Vu et approuvé par</span>
      </div>
      <table class="fp-sig">
        <tr>
          <?php foreach ($sigActors as $actor):
            $vS   = $historiqueGlobal[$actor['role']] ?? null;
            $decS = $vS['decision'] ?? '';
            $nomVS  = $vS['valideur_nom'] ?? '';
            $dateVS = !empty($vS['created_at']) ? date('d/m/Y', strtotime($vS['created_at'])) : '';
          ?>
          <td>
            <div class="fp-sig-titre"><?= $e($actor['titre']) ?></div>
            <?php if ($decS === 'valide'): ?>
              <div style="color:#1a6b1a;font-size:9pt;text-align:center">
                ✔ Validé par <strong><?= $e($nomVS) ?></strong><br>
                <span style="font-size:8.5pt">Le <?= $e($dateVS) ?></span>
              </div>
            <?php elseif ($decS === 'rejete'): ?>
              <div style="color:#b00;font-size:9pt;text-align:center">✖ Rejeté — <?= $e($dateVS) ?></div>
            <?php else: ?>
              <div class="fp-sig-line"></div>
              <div style="text-align:center;font-size:8pt;color:#666">Signature &amp; cachet</div>
            <?php endif; ?>
          </td>
          <?php endforeach; ?>
        </tr>
      </table>
    </div>

    <!-- Notes -->
    <div style="margin-top:12px;font-size:7.5pt;border-top:1px solid #aaa;padding-top:4px;color:#555;line-height:1.6">
      <sup>1</sup> Cochez le semestre d'activité.
      <sup>2</sup> Établir une fiche par établissement où intervient l'enseignant.
      <sup>3</sup> Calculer le volume horaire sans convertir les TD et TP en heures de cours.
      NTC = nombre total de crédits. Imprimé le <?= $dateImpression ?>.
    </div>

  </div><!-- /fp-wrapper suivi -->
</div><!-- /onglet suivi -->
<?php endif; ?>
<?php endforeach; ?>

<!-- ════════════════════════════════════════════════════════════ -->
<!-- ONGLET : DOSSIER VACATION -->
<!-- ════════════════════════════════════════════════════════════ -->
<?php // ── Modaux upload/preuves pour onglets Suivi ─────────── ?>
<style>
.db-modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);
  z-index:9000;align-items:center;justify-content:center}
.db-modal-overlay.open{display:flex}
.db-modal-box{background:#fff;border-radius:10px;padding:22px 26px;width:min(480px,95vw);
  max-height:88vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,.25);position:relative}
.db-modal-box h3{margin:0 0 12px;font-size:13pt;color:#1a3a1a}
.db-modal-close{position:absolute;top:10px;right:14px;background:none;border:none;
  font-size:18px;cursor:pointer;color:#666}
.db-upload-zone{border:2px dashed #ccc;border-radius:8px;padding:14px;
  text-align:center;margin-bottom:10px;cursor:pointer;transition:.2s}
.db-upload-zone:hover,.db-upload-zone.drag{border-color:var(--ujkz-vert,#2d6a2d);background:#f0faf0}
.db-upload-zone input[type=file]{display:none}
.db-form-row{display:flex;gap:10px;margin-bottom:10px}
.db-form-row label{font-size:12px;font-weight:600;display:block;margin-bottom:3px}
.db-form-row input[type=number]{width:100%;border:1px solid #ccc;border-radius:4px;
  padding:4px 7px;font-size:13px}
.db-progress{height:5px;background:#e5e5e5;border-radius:3px;margin-bottom:8px;display:none}
.db-progress div{height:100%;background:var(--ujkz-vert,#2d6a2d);border-radius:3px;
  width:0;transition:width .3s}
.db-preuve-card{display:flex;align-items:center;gap:9px;padding:7px 9px;
  border:1px solid #ddd;border-radius:6px;margin-bottom:7px;background:#fafafa}
.db-preuve-card .pi{flex:1;min-width:0}
.db-preuve-card .pi strong{display:block;font-size:11.5px;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.db-preuve-card .pi span{font-size:10.5px;color:#777}
@media print{.db-modal-overlay,.no-print{display:none!important}}
</style>

<!-- Modal upload preuve (suivi dashboard) -->
<div class="db-modal-overlay" id="db-modal-upload">
  <div class="db-modal-box">
    <button class="db-modal-close" onclick="dbFermerModal('db-modal-upload')">✕</button>
    <h3>📎 Ajouter une preuve</h3>
    <p id="db-cours-nom" style="font-size:12px;color:#555;margin-bottom:12px"></p>
    <div class="db-upload-zone" id="db-upload-zone"
         onclick="document.getElementById('db-fichier').click()">
      <input type="file" id="db-fichier" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp"
             onchange="dbFichierChoisi(this)">
      <div id="db-zone-text">
        <span style="font-size:22px">📄</span><br>
        <strong>Cliquez ou glissez un fichier ici</strong><br>
        <span style="font-size:11px;color:#888">PDF, Word, Image — max 10 Mo</span>
      </div>
    </div>
    <div class="db-progress" id="db-progress"><div id="db-progress-fill"></div></div>
    <div class="db-form-row">
      <div style="flex:1"><label>Volume CT effectué (h)</label>
        <input type="number" id="db-vol-cm" min="0" max="999" placeholder="0"></div>
      <div style="flex:1"><label>Volume TD effectué (h)</label>
        <input type="number" id="db-vol-td" min="0" max="999" placeholder="0"></div>
    </div>
    <div style="margin-bottom:10px">
      <label style="font-size:12px;font-weight:600;display:block;margin-bottom:3px">
        Commentaire (mois / semaines)
      </label>
      <input type="text" id="db-commentaire" maxlength="500"
             placeholder="Ex : Octobre–Décembre, sem. 1–14"
             style="width:100%;border:1px solid #ccc;border-radius:4px;
                    padding:4px 7px;font-size:13px">
    </div>
    <div id="db-upload-msg" style="margin-bottom:8px;font-size:12px"></div>
    <div style="display:flex;justify-content:flex-end;gap:10px">
      <button onclick="dbFermerModal('db-modal-upload')"
              style="background:#f0f0f0;border:1px solid #ccc;border-radius:4px;
                     padding:5px 14px;cursor:pointer;font-size:13px">Annuler</button>
      <button id="db-btn-submit"
              onclick="dbSoumettre()"
              style="background:var(--ujkz-vert,#2d6a2d);color:#fff;border:none;
                     border-radius:4px;padding:5px 16px;cursor:pointer;font-size:13px">
        📤 Enregistrer
      </button>
    </div>
  </div>
</div>

<!-- Modal voir preuves (suivi dashboard) -->
<div class="db-modal-overlay" id="db-modal-preuves">
  <div class="db-modal-box">
    <button class="db-modal-close" onclick="dbFermerModal('db-modal-preuves')">✕</button>
    <h3>🗂️ Preuves enregistrées</h3>
    <div id="db-liste-preuves" style="min-height:50px"></div>
    <div style="text-align:right;margin-top:10px">
      <button onclick="dbFermerModal('db-modal-preuves');ouvrirModalSuivi(dbCurId,dbCurNom)"
              style="background:var(--ujkz-vert,#2d6a2d);color:#fff;border:none;
                     border-radius:4px;padding:5px 14px;cursor:pointer;font-size:12px">
        + Ajouter une preuve
      </button>
    </div>
  </div>
</div>

<script>
var dbCurId  = 0;
var dbCurNom = '';
var dbTok    = '<?= Security::e($rawToken ?? '') ?>';
var dbCsrf   = '<?= Security::e($csrfToken ?? '') ?>';

function ouvrirModalSuivi(ficheId, coursNom) {
    dbCurId  = ficheId;
    dbCurNom = coursNom;
    document.getElementById('db-cours-nom').textContent = coursNom;
    document.getElementById('db-fichier').value = '';
    document.getElementById('db-zone-text').innerHTML =
        '<span style="font-size:22px">📄</span><br>' +
        '<strong>Cliquez ou glissez un fichier ici</strong><br>' +
        '<span style="font-size:11px;color:#888">PDF, Word, Image — max 10 Mo</span>';
    document.getElementById('db-vol-cm').value = '';
    document.getElementById('db-vol-td').value = '';
    document.getElementById('db-commentaire').value = '';
    document.getElementById('db-upload-msg').textContent = '';
    document.getElementById('db-progress').style.display = 'none';
    document.getElementById('db-progress-fill').style.width = '0';
    document.getElementById('db-modal-upload').classList.add('open');
}

function voirPreuvesSuivi(ficheId) {
    dbCurId = ficheId;
    document.getElementById('db-liste-preuves').innerHTML =
        '<div style="text-align:center;padding:14px;color:#888">Chargement…</div>';
    document.getElementById('db-modal-preuves').classList.add('open');
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'voir_preuve.php?ajax=1&fiche_id=' + ficheId + '&token=' + encodeURIComponent(dbTok));
    xhr.onload = function() {
        try {
            var data = JSON.parse(xhr.responseText);
            dbAfficherPreuves(data.preuves || []);
        } catch(e) {
            document.getElementById('db-liste-preuves').innerHTML =
                '<div style="color:#c00">Erreur de chargement.</div>';
        }
    };
    xhr.send();
}

function dbAfficherPreuves(preuves) {
    var el = document.getElementById('db-liste-preuves');
    if (!preuves.length) {
        el.innerHTML = '<div style="text-align:center;padding:18px;color:#888">' +
            'Aucune preuve enregistrée.</div>';
        return;
    }
    var html = '';
    for (var i = 0; i < preuves.length; i++) {
        var p = preuves[i];
        var icon = p.mime && (strpos2(p.mime,'pdf') >= 0) ? '📄' :
                   p.mime && (strpos2(p.mime,'image') >= 0) ? '🖼️' : '📎';
        var vols = [];
        if (p.volume_cm_effectue) vols.push('CT: ' + p.volume_cm_effectue + 'h');
        if (p.volume_td_effectue) vols.push('TD: ' + p.volume_td_effectue + 'h');
        html += '<div class="db-preuve-card">' +
            '<span style="font-size:20px">' + icon + '</span>' +
            '<div class="pi"><strong>' + dbEsc(p.nom_original) + '</strong>' +
            '<span>' + (vols.join(' · ') || '') + (p.commentaire ? ' — ' + dbEsc(p.commentaire) : '') + '</span></div>' +
            '<div style="display:flex;flex-direction:column;gap:3px">' +
            '<a href="voir_preuve.php?id=' + p.id + '&token=' + encodeURIComponent(dbTok) +
               '" target="_blank" style="background:#e8f5ee;border:1px solid var(--ujkz-vert,#2d6a2d);' +
               'color:var(--ujkz-vert-dk,#1a4a1a);border-radius:4px;padding:2px 7px;font-size:11px;' +
               'text-decoration:none;text-align:center">👁 Voir</a>' +
            '<button onclick="dbSupprimerPreuve(' + p.id + ',' + dbCurId + ')"' +
               ' style="background:#fff;border:1px solid #c00;color:#c00;border-radius:4px;' +
               'padding:2px 7px;font-size:11px;cursor:pointer">🗑</button>' +
            '</div></div>';
    }
    el.innerHTML = html;
}

function strpos2(str, needle) {
    return String(str).indexOf(needle);
}

function dbFichierChoisi(input) {
    if (input.files && input.files[0]) {
        document.getElementById('db-zone-text').innerHTML =
            '<span style="font-size:22px">✅</span><br>' +
            '<strong>' + dbEsc(input.files[0].name) + '</strong><br>' +
            '<span style="font-size:11px;color:#888">Cliquez pour changer</span>';
    }
}

// Drag & drop
(function() {
    var uz = document.getElementById('db-upload-zone');
    if (!uz) return;
    uz.addEventListener('dragover', function(e) { e.preventDefault(); uz.classList.add('drag'); });
    uz.addEventListener('dragleave', function() { uz.classList.remove('drag'); });
    uz.addEventListener('drop', function(e) {
        e.preventDefault(); uz.classList.remove('drag');
        if (e.dataTransfer && e.dataTransfer.files.length) {
            document.getElementById('db-fichier').files = e.dataTransfer.files;
            dbFichierChoisi(document.getElementById('db-fichier'));
        }
    });
})();

function dbSoumettre() {
    var fi = document.getElementById('db-fichier');
    var msg = document.getElementById('db-upload-msg');
    if (!fi.files || !fi.files[0]) {
        msg.innerHTML = '<span style="color:#c00">⚠ Choisissez un fichier.</span>';
        return;
    }
    var fd = new FormData();
    fd.append('preuve',             fi.files[0]);
    fd.append('fiche_id',           dbCurId);
    fd.append('token',              dbTok);
    fd.append('csrf_token',         dbCsrf);
    fd.append('volume_cm_effectue', document.getElementById('db-vol-cm').value);
    fd.append('volume_td_effectue', document.getElementById('db-vol-td').value);
    fd.append('commentaire',        document.getElementById('db-commentaire').value);
    fd.append('source',             'suivi');
    var btn  = document.getElementById('db-btn-submit');
    var prog = document.getElementById('db-progress');
    var fill = document.getElementById('db-progress-fill');
    btn.disabled = true;
    prog.style.display = 'block';
    msg.textContent = '';
    var xhr = new XMLHttpRequest();
    xhr.upload.onprogress = function(e) {
        if (e.lengthComputable) fill.style.width = Math.round(e.loaded/e.total*100) + '%';
    };
    xhr.open('POST', 'upload_preuve.php');
    xhr.onload = function() {
        btn.disabled = false;
        try {
            var resp = JSON.parse(xhr.responseText);
            if (resp.ok) {
                msg.innerHTML = '<span style="color:green">✓ Preuve enregistrée !</span>';
                // Mettre à jour compteur
                var nbEl = document.getElementById('db-nb-preuves-' + dbCurId);
                if (nbEl) nbEl.textContent = parseInt(nbEl.textContent || '0') + 1;
                // Mettre à jour volumes effectués
                if (resp.cmEff) {
                    var ce = document.getElementById('db-cm-eff-' + dbCurId);
                    if (ce) ce.textContent = resp.cmEff;
                }
                if (resp.tdEff) {
                    var te = document.getElementById('db-td-eff-' + dbCurId);
                    if (te) te.textContent = resp.tdEff;
                }
                setTimeout(function() { dbFermerModal('db-modal-upload'); }, 1200);
            } else {
                msg.innerHTML = '<span style="color:#c00">✗ ' + dbEsc(resp.error || 'Erreur') + '</span>';
            }
        } catch(e) {
            msg.innerHTML = '<span style="color:green">✓ Preuve enregistrée !</span>';
            setTimeout(function() { dbFermerModal('db-modal-upload'); }, 1200);
        }
    };
    xhr.onerror = function() {
        btn.disabled = false;
        msg.innerHTML = '<span style="color:#c00">✗ Erreur réseau.</span>';
    };
    xhr.send(fd);
}

function dbSupprimerPreuve(preuveId, ficheId) {
    if (!confirm('Supprimer cette preuve ?')) return;
    var fd = new FormData();
    fd.append('action',    'supprimer');
    fd.append('preuve_id', preuveId);
    fd.append('fiche_id',  ficheId);
    fd.append('token',     dbTok);
    fd.append('csrf_token',dbCsrf);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'upload_preuve.php');
    xhr.onload = function() {
        voirPreuvesSuivi(ficheId);
        var nbEl = document.getElementById('db-nb-preuves-' + ficheId);
        if (nbEl) { var n = parseInt(nbEl.textContent||'0')-1; nbEl.textContent = n<0?0:n; }
    };
    xhr.send(fd);
}

function dbFermerModal(id) {
    document.getElementById(id).classList.remove('open');
}

function dbEsc(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

document.querySelectorAll('.db-modal-overlay').forEach(function(el) {
    el.addEventListener('click', function(e) { if (e.target===el) dbFermerModal(el.id); });
});
</script>

<!-- Lien d'accès -->
<div class="card card-lien no-print" style="background:var(--ujkz-vert-lt);border-color:#A8D5BC">
  <div style="font-size:13px;font-weight:600;color:var(--ujkz-vert-dk);margin-bottom:.5rem">
    🔗 Votre lien d'accès personnel
  </div>
  <div class="link-box"><?= $e($accessLink) ?></div>
  <div style="font-size:12px;color:var(--gray-600);margin-top:.5rem">
    Conservez ce lien — il vous permet d'accéder à votre tableau de bord sans mot de passe.
  </div>
</div>
