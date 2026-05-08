<?php
declare(strict_types=1);
$e = function($v) { return Security::e($v); };
$ens = $enseignant;
$token = $token ?? '';
$annee = $config['annee_academique'] ?? '2024-2025';

// Organiser l'historique en map role → dernière décision
$valMap = [];
foreach ($historique as $h) {
    $valMap[$h['valideur_role'] ?? $h['role']] = $h;
}
$roleLabels = [
    'chef_dept'         => 'Chef de Département',
    'directeur_adjoint' => 'Le Directeur Adjoint',
    'directeur'         => 'Le Directeur',
    'dei'               => 'La DEI',
];
$dateImpression = date('d/m/Y à H:i');

// Fiche unique pour l'impression — on l'emballe dans un faux tableau
$ficheRow = $fiche;
$cours = [
    array_merge($ficheRow, [
        'code'      => $ficheRow['code_ue'] ?? $ficheRow['code'] ?? '',
        'parcours'  => $ficheRow['parcours'] ?? '',
        'ntc'       => $ficheRow['ntc'] ?? '',
        'volume_tp' => $ficheRow['volume_tp'] ?? 0,
    ])
];
?>

<!-- Boutons actions (non imprimés) -->
<div class="no-print" style="
  background:#f9f9f9;border-bottom:1px solid #ddd;
  padding:10px 20px;display:flex;align-items:center;
  gap:10px;margin-bottom:0">
  <a href="dashboard.php?token=<?= $e($token) ?>"
     class="btn btn-sm">← Tableau de bord</a>
  <button onclick="window.print()" class="btn btn-sm btn-primary">
    🖨 Imprimer / Enregistrer en PDF
  </button>
  <span style="font-size:12px;color:#888;margin-left:auto">
    Le navigateur doit être en mode "Papier A4" lors de l'impression
  </span>
</div>

<style>
@media print {
  .no-print, .site-header, .site-subnav, .breadcrumb,
  .page-hero, nav, footer, .btn, .btn-group { display:none !important; }
  body { background:#fff !important; }
  .fiche-wrapper { box-shadow:none !important; border:none !important;
                   padding:0 !important; margin:0 !important; max-width:none !important; }
  @page { size:A4 portrait; margin:15mm 12mm; }
}
.fiche-wrapper {
  background:#fff; max-width:860px; margin:0 auto;
  padding:20px 28px;
  font-family: Arial, Helvetica, sans-serif;
  font-size:10.5pt; color:#000;
  box-shadow:0 2px 12px rgba(0,0,0,.08);
  border:1px solid #ccc; border-radius:4px;
}
.fiche-table { width:100%; border-collapse:collapse; font-size:9.5pt; }
.fiche-table th, .fiche-table td {
  border:1px solid #000; padding:4px 4px;
}
.fiche-table th { background:#e0e0e0; text-align:center; font-weight:700; }
.sem-header { background:#f0f0f0; text-align:center; font-weight:700; }
.total-row td { background:#d0d0d0; font-weight:700; text-align:center; }
.grand-total td { background:#b0b0b0; font-weight:700; text-align:center; }
.sig-table { width:100%; border-collapse:collapse; margin-top:20px; font-size:9.5pt; }
.sig-table td { padding:6px 8px; vertical-align:top; }
.sig-cell { text-align:center; width:25%; }
</style>

<div class="fiche-wrapper">

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
        <span style="letter-spacing:2px;font-size:7.5pt">········································</span><br>
        Année universitaire <strong><?= $e($annee) ?></strong>
      </td>
    </tr>
  </table>

  <!-- Titre -->
  <div style="border:1.5px solid #000;background:#e0e0e0;text-align:center;padding:7px;margin-bottom:3px">
    <span style="font-size:13pt;font-weight:700">FICHE PROGRAMMATIQUE</span>
  </div>
  <div style="text-align:center;font-size:10.5pt;font-weight:700;text-decoration:underline;margin-bottom:8px">
    Pour enseignant <?= $e(($ens['type_enseignant']??'permanent')) ?>
  </div>

  <!-- Informations enseignant -->
  <div style="font-size:10pt;line-height:1.9">
    <?php
    $nom    = trim(($ens['nom']??''));
    $prenom = $ens['prenom'] ?? '';
    $grade  = $ens['grade'] ?? '';
    $dateN  = !empty($ens['date_nomination'])
              ? date('d/m/Y', strtotime($ens['date_nomination'])) : '';
    $vs  = $ens['volume_statutaire']  ?? '';
    $ab  = $ens['abattement']         ?? '';
    $mot = $ens['motif_abattement']   ?? '';
    $va  = $ens['volume_apres_abatt'] ?? '';
    $er  = $ens['etab_rattachement']  ?? '';
    $ea  = $ens['etab_administratif'] ?? '';
    $eb  = $ens['etab_beneficiaire']  ?? '';
    $diplome = $ens['diplome']        ?? $ens['diplôme'] ?? '';
    $mois    = $ens['mois_execution'] ?? '';
    // Détecter IESR UJKZ pour affichage conditionnel des volumes
    $_rvP = strtolower(trim($er ?? ''));
    $showVolP = (($_rvP === '' || strpos($_rvP,'ujkz')!==false
               || strpos($_rvP,'ki-zerbo')!==false || strpos($_rvP,'ki zerbo')!==false)
               && ($ens['type_enseignant'] ?? 'permanent') !== 'vacataire');
    ?>
    <div>
      Nom : <strong><?= $e($nom) ?></strong>
      <?php if($prenom): ?>&nbsp;&nbsp; Prénom(s) : <strong><?= $e($prenom) ?></strong><?php endif; ?>
      <?php if($diplome): ?>&nbsp;&nbsp; Diplôme(s) : <strong><?= $e($diplome) ?></strong><?php endif; ?>
    </div>
    <div>
      Grade : <strong><?= $e($grade) ?></strong>
      <?php if($dateN): ?>&nbsp;&nbsp;&nbsp; Date de Nomination : <strong><?= $e($dateN) ?></strong><?php endif; ?>
    </div>
    <?php if ($vs !== '' && $showVolP): ?>
    <div>
      Volume horaire statutaire : <strong><?= $e($vs) ?>h</strong>
      &nbsp;&nbsp; Abattement : <strong><?= $e($ab) ?>h</strong>
      <?php if($mot): ?>&nbsp;&nbsp; Motif : <strong><?= $e($mot) ?></strong><?php endif; ?>
    </div>
    <div>Volume horaire obligatoire après abattement : <strong><?= $e($va) ?>h</strong></div>
    <?php endif; ?>
    <?php if ($er): ?>
    <div>IESR de rattachement : <strong><?= $e($er) ?></strong></div>
    <?php if ($ea && $showVolP): ?>
    <div>Établissement de rattachement administratif : <strong><?= $e($ea) ?></strong></div>
    <?php endif; ?>
    <?php endif; ?>
    <?php
  // Map étab → liste de cours pour l'impression (IDs → noms)
  $_etabByIdP = []; $_deptByIdP = [];
  $pdoP = Database::getInstance();
  foreach ($pdoP->query("SELECT id,nom FROM etablissements WHERE actif=1")->fetchAll() as $r)
      $_etabByIdP[(int)$r['id']] = $r['nom'];
  $ebMapP = [];
  foreach ($toutesLesFiches as $_flp) {
      if (!empty($_flp['is_encadrement'])) continue;
      $ebIdP = (int)($_flp['etab_beneficiaire_fiche'] ?? 0);
      if ($ebIdP === 0) continue;
      $ebNomP = $_etabByIdP[$ebIdP] ?? "Étab.#$ebIdP";
      $cvp    = trim($_flp['cours'] ?? '');
      if (!isset($ebMapP[$ebNomP])) $ebMapP[$ebNomP] = [];
      if ($cvp !== '' && !in_array($cvp, $ebMapP[$ebNomP], true)) $ebMapP[$ebNomP][] = $cvp;
  }
  ?>
  <?php if (!empty($ebMapP)): ?>
  <div style="line-height:1.8">
    <span style="font-weight:700">Établissement bénéficiaire des enseignements :</span>
    <?php foreach ($ebMapP as $etabNomP => $coursListP): ?>
    <div style="margin-left:12px">
      — <strong><?= $e($etabNomP) ?></strong>
      <?php if (!empty($coursListP)): ?>
      <span style="font-weight:400;color:#333"> : <?= $e(implode(', ', $coursListP)) ?></span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php elseif ($eb): ?>
  <div>Établissement bénéficiaire des enseignements : <strong><?= $e($eb) ?></strong></div>
  <?php endif; ?>
    <div>Mois et semaines d'exécution des heures :
      <strong><?= $mois ? $e($mois) : str_repeat('.', 35) ?></strong>
    </div>
  </div>

  <!-- Titre tableau -->
  <div style="text-align:center;font-size:9.5pt;margin:8px 0 4px">
    Tableau descriptif des enseignements confiés en réunion de département
  </div>

  <!-- Tableau enseignements (toutes les fiches de l'enseignant pour cette année) -->
  <?php
  // Charger toutes les fiches de l'enseignant pour l'année
  $pdo = Database::getInstance();
  $stmt = $pdo->prepare(
      "SELECT * FROM fiches WHERE enseignant_id = ? AND annee_academique = ?
       ORDER BY semestre, submitted_at"
  );
  $stmt->execute([(int)$ens['id'], $annee]);
  $toutesLesFiches = $stmt->fetchAll();

  $s1Fiches  = array_values(array_filter($toutesLesFiches, function($f){ return ($f['semestre']??'')==='S1' && empty($f['is_encadrement']); }));
  $s2Fiches  = array_values(array_filter($toutesLesFiches, function($f){ return ($f['semestre']??'')==='S2' && empty($f['is_encadrement']); }));
  $encFiches = array_values(array_filter($toutesLesFiches, function($f){ return !empty($f['is_encadrement']) || ($f['semestre']??'')==='ENC'; }));

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

  function renderFicheRow(int $n, array $f, callable $e): string {
      $cm = (int)($f['volume_cm']??0);
      $td = (int)($f['volume_td']??0);
      $tp = (int)($f['volume_tp']??0);
      $code = $f['code_ue'] ?: ($f['code'] ?: '');
      return '<tr>'
          .'<td style="border:1px solid #000;padding:4px;text-align:center">'.$n.'</td>'
          .'<td style="border:1px solid #000;padding:4px;text-align:center">'.$e($code).'</td>'
          .'<td style="border:1px solid #000;padding:4px">'.$e($f['parcours']??'').'</td>'
          .'<td style="border:1px solid #000;padding:4px">'.$e($f['cours']??'').'</td>'
          .'<td style="border:1px solid #000;padding:4px;text-align:center">'.$e($f['ntc']??'').'</td>'
          .'<td style="border:1px solid #000;padding:4px;text-align:center">'.($cm ?: '-').'</td>'
          .'<td style="border:1px solid #000;padding:4px;text-align:center">'.($td ?: '-').'</td>'
          .'<td style="border:1px solid #000;padding:4px;text-align:center">'.($tp ?: '-').'</td>'
          .'</tr>';
  }
  ?>
  <table class="fiche-table">
    <thead>
      <tr>
        <th rowspan="2" style="width:4%">N°</th>
        <th rowspan="2" style="width:11%">CODE</th>
        <th rowspan="2" style="width:18%">PARCOURS</th>
        <th rowspan="2">UE ou ECUE</th>
        <th rowspan="2" style="width:5%">NTC</th>
        <th colspan="3" style="width:18%">Volume horaire<sup>1</sup></th>
      </tr>
      <tr>
        <th style="width:6%">CT</th>
        <th style="width:6%">TD</th>
        <th style="width:6%">TP</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($s1Fiches): ?>
      <tr><td colspan="8" class="sem-header">Premier semestre de l'année</td></tr>
      <?php $cnt=0; foreach ($s1Fiches as $f): echo renderFicheRow(++$cnt, $f, $e); endforeach; ?>
      <tr class="total-row">
        <td colspan="5">TOTAL DU SEMESTRE<sup>1</sup></td>
        <td><?= $tS1cm ?: '' ?></td><td><?= $tS1td ?: '' ?></td><td></td>
      </tr>
      <?php endif; ?>

      <?php if ($s2Fiches): ?>
      <tr><td colspan="8" class="sem-header">Deuxième semestre de l'année</td></tr>
      <?php $cnt=0; foreach ($s2Fiches as $f): echo renderFicheRow(++$cnt, $f, $e); endforeach; ?>
      <tr class="total-row">
        <td colspan="5">TOTAL DU SEMESTRE<sup>2</sup></td>
        <td><?= $tS2cm ?: '' ?></td><td><?= $tS2td ?: '' ?></td><td></td>
      </tr>
      <?php endif; ?>

      <?php if ($encFiches): ?>
      <tr><td colspan="8" style="border:1px solid #000;padding:4px;text-align:center;font-weight:700;font-style:italic;font-size:9pt;background:#f0eefc">Encadrement</td></tr>
      <?php $cnt=0; foreach ($encFiches as $f): echo renderFicheRow(++$cnt, $f, $e); endforeach; ?>
      <tr style="background:#dddaf5">
        <td colspan="5" style="border:1px solid #000;padding:4px;text-align:center;font-weight:700;font-size:9pt">TOTAL ENCADREMENT</td>
        <td style="border:1px solid #000;padding:4px;text-align:center;font-weight:700"><?= $tEncCm ?: '' ?></td>
        <td style="border:1px solid #000;padding:4px;text-align:center;font-weight:700"><?= $tEncTd ?: '' ?></td>
        <td style="border:1px solid #000;padding:4px;text-align:center;font-weight:700"><?= $tEncTp ?: '' ?></td>
      </tr>
      <?php endif; ?>

      <tr class="grand-total">
        <td colspan="5">TOTAUX S1 + S2 + ENCADREMENT</td>
        <td><?= $tCm ?: '' ?></td><td><?= $tTd ?: '' ?></td><td><?= $tTp ?: '' ?></td>
      </tr>
    </tbody>
  </table>

  <!-- Zone signatures / validations -->
  <div style="margin-top:18px">
    <div style="text-align:right;font-size:9.5pt;margin-bottom:4px">
      Ouagadougou, le <?= $dateImpression ?> &nbsp;&nbsp;&nbsp; (date d'impression)
    </div>

    <!-- Ligne 1 : Chef Dept + DEI -->
    <table class="sig-table">
      <tr>
        <?php
        // VP EIP uniquement pour les fiches VACATAIRE
        $sigActors = [
            ['role'=>'chef_dept',         'titre'=>'Le Chef de Département'],
            ['role'=>'directeur_adjoint', 'titre'=>'Le Directeur Adjoint'],
            ['role'=>'directeur',         'titre'=>'Le Directeur'],
            ['role'=>'dei',               'titre'=>'La DEI'],
        ];
        if (isset($toutesLesFiches[0]) && ($toutesLesFiches[0]['type_workflow'] ?? '') === 'VACATAIRE') {
            $sigActors[] = ['role'=>'vp_eip', 'titre'=>'Le VP EIP'];
        }
        foreach ($sigActors as $actor):
            $v = $valMap[$actor['role']] ?? null;
            $dec = $v['decision'] ?? 'en_attente';
            $nom_v = $v['valideur_nom'] ?? '—';
            $date_v = !empty($v['created_at'])
                      ? date('d/m/Y', strtotime($v['created_at'])) : '—';
        ?>
        <td class="sig-cell" style="vertical-align:top">
          <div style="font-weight:700;font-size:9.5pt;text-align:center;
                      text-decoration:underline;margin-bottom:6px">
            <?= $e($actor['titre']) ?>
          </div>
          <?php if ($dec === 'valide'): ?>
          <div style="text-align:center;color:#1a6b1a;font-size:9pt">
            ✔ Validé par <strong><?= $e($nom_v) ?></strong><br>
            <span style="font-size:8.5pt">Le <?= $e($date_v) ?></span>
          </div>
          <?php elseif ($dec === 'rejete'): ?>
          <div style="text-align:center;color:#b00;font-size:9pt">
            ✖ Rejeté (<?= $e($date_v) ?>)<br>
            <?php if (!empty($v['motif_rejet'])): ?>
            <span style="font-size:8pt;font-style:italic"><?= $e(mb_substr($v['motif_rejet'],0,40)) ?>…</span>
            <?php endif; ?>
          </div>
          <?php else: ?>
          <div style="text-align:center;color:#888;font-size:9pt;font-style:italic">
            En attente
          </div>
          <div style="border-bottom:1px solid #000;margin:20px 10px 2px"></div>
          <div style="text-align:center;font-size:8pt;color:#666">Signature &amp; cachet</div>
          <?php endif; ?>
        </td>
        <?php endforeach; ?>
      </tr>
    </table>
  </div>

  <!-- Notes officielles -->
  <div style="margin-top:16px;font-size:7.5pt;border-top:1px solid #aaa;padding-top:4px;color:#444">
    <sup>1</sup> Établir une fiche de suivi par établissement (CUP, UFR ou Institut) où intervient l'enseignant.<br>
    <sup>2</sup> Calculer le volume horaire sans convertir les TD et les TP en heures de cours théoriques.<br>
    NB : NTC = nombre total de crédits. Ne remplir qu'une seule fiche pour toutes les interventions sur le campus.<br>
    Ces fiches doivent être impérativement déposées par tout enseignant après la réunion d'attribution des heures.
  </div>

  <!-- Pied de page système -->
  <div style="margin-top:10px;font-size:7pt;color:#aaa;text-align:center;border-top:1px solid #eee;padding-top:4px">
    Généré par le Système de gestion des fiches programmatiques de l'UJKZ —
    Imprimé le <?= $dateImpression ?>
  </div>

</div><!-- /fiche-wrapper -->
