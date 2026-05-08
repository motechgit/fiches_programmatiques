<?php
declare(strict_types=1);
$e = function($v) { return Security::e($v); };
$old      = $old     ?? [];
$errors   = $errors  ?? [];
$step     = $step    ?? 1;
$isVac    = ($old['type_enseignant'] ?? 'permanent') === 'vacataire';
$modeEdit = $modeEdit ?? false;
$ficheId  = $ficheId  ?? 0;

// Détecter si IESR UJKZ — calculé UNE SEULE FOIS ici, valable pour step1 ET step2
$_rattachVal = $old['etab_rattachement'] ?? '';
if ($_rattachVal === '') {
    $isUJKZ = true; // vide = UJKZ par défaut
} elseif ($isVac) {
    $isUJKZ = false; // vacataire = jamais UJKZ-only
} else {
    $_rv    = strtolower($_rattachVal);
    $isUJKZ = strpos($_rv,'ujkz')!==false
           || strpos($_rv,'ki-zerbo')!==false
           || strpos($_rv,'ki zerbo')!==false;
}

$grades = ['Assistant / Assistant HU','Maître-assistant / Maître-assistant HU','Maître de conférences',
           'Professeur titulaire','Professeur agrégé',
           'Chargé de cours','Doctorant enseignant','ETP','Autre'];
$departements     = $departements     ?? ($config['departements']      ?? []);
$etablissements   = $etablissements   ?? ($config['etablissements']    ?? []);
$etabDepartements = $etabDepartements ?? ($config['etab_departements'] ?? []);
$niveaux          = $niveaux          ?? ($config['niveaux']           ?? []);
$annee            = $annee            ?? ($config['annee_academique']  ?? '2024-2025');

// Maps id → nom pour résoudre les IDs en affichage
// Variables reçues depuis index.php (déjà chargées depuis la BD)
// IMPORTANT : ne pas utiliser $e comme variable de boucle (réservée à la closure Security::e)
$etabsDB  = $etabsDB  ?? [];
$deptsDB  = $deptsDB  ?? [];
$etabById = [];
foreach ($etabsDB as $_etRow) { $etabById[(int)$_etRow['id']] = $_etRow['nom']; }
$deptById = [];
foreach ($deptsDB as $_dpRow) {
    $deptById[(int)$_dpRow['id']] = $_dpRow['nom'] . (!empty($_dpRow['sigle']) ? ' ('.$_dpRow['sigle'].')' : '');
}
// Map etab_id → [{id, nom}, ...] pour les selects dynamiques
$etabDeptIds = [];
foreach ($deptsDB as $_dpRow2) {
    $etabDeptIds[(int)$_dpRow2['etablissement_id']][] = [
        'id'  => (int)$_dpRow2['id'],
        'nom' => $deptById[(int)$_dpRow2['id']],
    ];
}
// Si etabsDB vide (ne devrait pas arriver), charger depuis BD directement
if (empty($etabsDB) || empty($deptsDB)) {
    try {
        $pdoFrm = \Database::getInstance();
        if (empty($etabsDB)) {
            $etabsDB = $pdoFrm->query("SELECT id,nom,sigle FROM etablissements WHERE actif=1 ORDER BY ordre,nom")->fetchAll();
            foreach ($etabsDB as $_er) $etabById[(int)$_er['id']] = $_er['nom'];
        }
        if (empty($deptsDB)) {
            $deptsDB = $pdoFrm->query("SELECT id,nom,sigle,etablissement_id FROM departements WHERE actif=1 ORDER BY ordre,nom")->fetchAll();
            foreach ($deptsDB as $_dr) {
                $deptById[(int)$_dr['id']] = $_dr['nom'] . (!empty($_dr['sigle']) ? ' ('.$_dr['sigle'].')' : '');
                $etabDeptIds[(int)$_dr['etablissement_id']][] = ['id'=>(int)$_dr['id'],'nom'=>$deptById[(int)$_dr['id']]];
            }
        }
    } catch (\Exception $ignored) {}
}

// Lignes du tableau multi-cours
$lignes = [];
if (!empty($old['lignes']) && is_array($old['lignes'])) {
    $lignes = $old['lignes'];
}
if (isset($_POST['l_cours']) && is_array($_POST['l_cours'])) {
    $lignes = [];
    foreach ($_POST['l_cours'] as $i => $v) {
        $semTmp = in_array($_POST['l_semestre'][$i] ?? '', ['S1','S2','ENC'])
                  ? $_POST['l_semestre'][$i] : 'S1';
        $encTmp = !empty($_POST['l_enc'][$i]) || $semTmp === 'ENC';
        $lignes[] = [
            'semestre'               => $encTmp ? 'ENC' : $semTmp,
            'code'                   => $_POST['l_code'][$i]      ?? '',
            'parcours'               => $_POST['l_parcours'][$i]  ?? '',
            'cours'                  => $_POST['l_cours'][$i]     ?? '',
            'ntc'                    => $_POST['l_ntc'][$i]       ?? '',
            'volume_cm'              => $_POST['l_cm'][$i]        ?? '0',
            'volume_td'              => $_POST['l_td'][$i]        ?? '0',
            'volume_tp'              => $_POST['l_tp'][$i]        ?? '0',
            'niveau'                 => $_POST['l_niveau'][$i]    ?? ($niveaux[0] ?? ''),
            'is_encadrement'         => $encTmp,
            'etab_beneficiaire_fiche'=> $encTmp ? 0 : (int)($_POST['l_etab_benef'][$i] ?? 0),
            'dept_beneficiaire_fiche'=> $encTmp ? 0 : (int)($_POST['l_dept_benef'][$i] ?? 0),
            '_fiche_id'              => (int)($_POST['l_fiche_id'][$i] ?? 0),
            '_statut'                => $_POST['l_statut'][$i] ?? '',
        ];
    }
}
if (empty($lignes)) {
    $lignes = [[
        'semestre'=>'S1','code'=>'','parcours'=>'','cours'=>'',
        'ntc'=>'','volume_cm'=>'0','volume_td'=>'0','volume_tp'=>'0',
        'niveau'=>($niveaux[0]??''),'is_encadrement'=>false,
    ]];
}

// Séparer encadrement des cours normaux pour l'affichage
$lignesNorm = array_values(array_filter($lignes, function($l){ return !($l['is_encadrement']??false) && ($l['semestre']??'S1') !== 'ENC'; }));
$ligneEnc   = array_values(array_filter($lignes, function($l){ return ($l['is_encadrement']??false) || ($l['semestre']??'') === 'ENC'; }));
$encData    = $ligneEnc[0] ?? ['volume_cm'=>0,'semestre'=>'S1'];
?>

<!-- Datalists -->
<datalist id="dl-iesr">
  <option value="Université Joseph KI-ZERBO (UJKZ)">
  <option value="Université Nazi BONI (UNB)">
  <option value="Université Thomas SANKARA (UTS)">
  <option value="Université Norbert ZONGO (UNZ)">
  <option value="Université de Dédougou (UDDG)">
  <option value="Institut Universitaire de Technologie (IUT)">
  <option value="École Normale Supérieure (ENS)">
  <option value="Institut de Formation Ouverte à Distance (IFOAD)">
</datalist>
<datalist id="dl-etab">
  <?php foreach ($etablissements as $et): ?>
  <option value="<?= $e($et) ?>">
  <?php endforeach; ?>
</datalist>
<datalist id="dl-dept">
  <?php foreach ($departements as $dp): ?>
  <option value="<?= $e($dp) ?>">
  <?php endforeach; ?>
</datalist>
<datalist id="dl-grade-local">
  <?php foreach ($grades as $g): ?>
  <option value="<?= $e($g) ?>">
  <?php endforeach; ?>
</datalist>

<!-- Hero -->
<div class="page-hero">
  <div>
    <h1><?= $modeEdit ? '✏️ Modifier la fiche' : '📋 Fiche programmatique' ?></h1>
    <div class="subtitle">Année académique <?= $e($annee) ?></div>
  </div>
</div>

<!-- Stepper 2 étapes -->
<div class="steps">
  <div class="step <?= $step<=2?($step>1?'done':'active'):($step>=2?'done':'') ?>">
    <span class="step-num"><?= $step>1?'✓':'1' ?></span> Saisie
  </div>
  <div class="step-sep"></div>
  <div class="step <?= $step>=2?'active':'' ?>">
    <span class="step-num">2</span> Aperçu &amp; Validation
  </div>
</div>

<?php if (!empty($errors['global'])): ?>
<div class="alert alert-danger"><?= $e($errors['global']) ?></div>
<?php endif; ?>

<form method="POST" action="index.php" novalidate autocomplete="off" id="main-form" enctype="multipart/form-data">
  <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
  <input type="hidden" name="step"       value="<?= (int)$step ?>">
  <input type="hidden" name="mode_edit"  value="<?= $modeEdit?'1':'0' ?>">
  <input type="hidden" name="fiche_id"   value="<?= (int)$ficheId ?>">

<?php /* ══════════════════════════════════════════ ÉTAPE 1 — FICHE COMPLÈTE */ ?>
<?php if ($step === 1): ?>

<!-- ════════════ APERÇU/SAISIE STYLE FICHE OFFICIELLE ════════════ -->
<div style="background:#fff;border:1.5px solid #bbb;border-radius:6px;
            padding:20px 24px;margin-bottom:1.25rem;
            font-family:Arial,Helvetica,sans-serif;font-size:11pt;
            box-shadow:0 2px 10px rgba(0,0,0,.07)">

  <!-- En-tête 3 colonnes style fiche officielle -->
  <table style="width:100%;border:none;border-collapse:collapse;margin-bottom:12px">
    <tr>
      <td style="width:36%;vertical-align:top;text-align:center;font-size:8.5pt;font-weight:700;line-height:1.6">
        MINISTÈRE DE L'ENSEIGNEMENT<br>
        SUPÉRIEUR, DE LA RECHERCHE<br>
        ET DE L'INNOVATION<br>
        <span style="font-weight:400">- - - - - - - - - - - - - -</span><br>
        SECRÉTARIAT GÉNÉRAL<br>
        <span style="font-weight:400">- - - - - - - - - - - - - -</span><br>
        <strong>UNIVERSITÉ JOSEPH KI-ZERBO</strong><br>
        <span style="font-weight:400">- - - - - - - - - - - - - -</span><br>
        PRÉSIDENCE<br>
        <span style="font-weight:400">- - - - - - - - - - - - - - -</span>
      </td>
      <td style="width:24%;text-align:center;vertical-align:middle">
        <img src="logo_ujkz.jpg" alt="Logo UJKZ" style="width:72px;height:72px;object-fit:contain">
      </td>
      <td style="width:36%;vertical-align:top;text-align:right;font-size:8.5pt;line-height:1.7">
        <strong><em>BURKINA FASO</em></strong><br>
        <em>La Patrie ou la Mort, nous Vaincrons</em><br>
        <span style="letter-spacing:2px;font-size:7.5pt">·············································</span><br>
        Année universitaire <strong><?= $e($annee) ?></strong>
      </td>
    </tr>
  </table>

  <!-- Titre fiche -->
  <div style="border:1.5px solid #000;background:#e8e8e8;
              text-align:center;padding:8px 4px;margin-bottom:3px">
    <span style="font-size:14pt;font-weight:700">FICHE PROGRAMMATIQUE</span>
  </div>

  <!-- Choix type enseignant — sélecteur visuel -->
  <div style="display:flex;justify-content:center;gap:12px;margin-bottom:14px">
    <?php
    $isPerm = ($old['type_enseignant']??'permanent') === 'permanent';
    $isVacS = ($old['type_enseignant']??'') === 'vacataire';
    ?>
    <label id="lbl-perm" style="
        cursor:pointer;display:flex;align-items:center;gap:10px;
        padding:10px 22px;border-radius:8px;border:2.5px solid;
        font-size:12pt;font-weight:700;transition:all .15s;user-select:none;
        border-color:<?= $isPerm ? 'var(--ujkz-vert)' : '#ccc' ?>;
        background:<?= $isPerm ? '#e8f5ee' : '#f9f9f9' ?>;
        color:<?= $isPerm ? 'var(--ujkz-vert-dk)' : '#888' ?>">
      <input type="radio" name="type_enseignant" value="permanent" id="type_perm"
             <?= $isPerm ? 'checked' : '' ?>
             onchange="toggleTypeEns(this.value)"
             style="width:18px;height:18px;accent-color:var(--ujkz-vert);cursor:pointer">
      <span>🏛️ IESR de rattachement</span>
    </label>
    <label id="lbl-vac" style="
        cursor:pointer;display:flex;align-items:center;gap:10px;
        padding:10px 22px;border-radius:8px;border:2.5px solid;
        font-size:12pt;font-weight:700;transition:all .15s;user-select:none;
        border-color:<?= $isVacS ? 'var(--ujkz-or)' : '#ccc' ?>;
        background:<?= $isVacS ? '#fff8e1' : '#f9f9f9' ?>;
        color:<?= $isVacS ? '#7a5800' : '#888' ?>">
      <input type="radio" name="type_enseignant" value="vacataire" id="type_vac"
             <?= $isVacS ? 'checked' : '' ?>
             onchange="toggleTypeEns(this.value)"
             style="width:18px;height:18px;accent-color:var(--ujkz-or);cursor:pointer">
      <span>🧑‍💼 Non IESR (vacataire)</span>
    </label>
    <?php if (isset($errors['type_enseignant'])): ?>
    <div class="err-text"><?= $e($errors['type_enseignant']) ?></div>
    <?php endif; ?>
  </div>

  <!-- ── Zone infos enseignant style fiche ── -->
  <div style="font-size:10pt;margin-bottom:8px">

    <!-- IESR de rattachement + Établissement de rattachement administratif -->
    <!-- Grille 2 colonnes : label fixe à gauche, champ à droite sur la même ligne -->
    <div id="bloc_etab_rattach" style="display:<?= ($isVac?'none':'grid') ?>;grid-template-columns:auto 1fr;gap:5px 8px;
         align-items:center;margin-bottom:5px">

      <!-- Ligne 1 : IESR de rattachement -->
      <label style="font-weight:700;white-space:nowrap;margin:0;font-size:10pt">
        IESR de rattachement :
      </label>
      <input type="text" name="etab_rattachement" list="dl-iesr"
             value="<?= $e($old['etab_rattachement']??'') ?>"
             placeholder="Ex : Université Joseph KI-ZERBO (UJKZ)" maxlength="150"
             style="border:none;border-bottom:1px solid #999;
                    font-size:10pt;padding:1px 4px;width:100%;outline:none;font-weight:700"
             autocomplete="off"
             onchange="onIesrChange(this.value)">

      <!-- Ligne 2 : Établissement de rattachement administratif (UJKZ uniquement) -->
      <div id="bloc_etab_admin" style="display:contents">
        <label style="font-weight:700;white-space:nowrap;margin:0;font-size:10pt"
               id="lbl_etab_admin">
          Établissement de rattachement administratif :
        </label>
        <div>
          <select name="etab_administratif"
                  style="border:none;border-bottom:1px solid #999;
                         font-size:10pt;padding:1px 4px;width:100%;outline:none;
                         background:transparent;font-weight:700;cursor:pointer"
                  class="<?= isset($errors['etab_administratif'])?'error':'' ?>">
            <option value="">— Sélectionner l'établissement UJKZ —</option>
            <?php foreach ($etablissements as $et): ?>
            <option value="<?= $e($et) ?>"
                    <?= ($old['etab_administratif']??'')===$et ? 'selected' : '' ?>>
              <?= $e($et) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($errors['etab_administratif'])): ?>
          <div class="err-text"><?= $e($errors['etab_administratif']) ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Nom | Prénoms | Diplôme -->
    <div style="display:flex;flex-wrap:wrap;gap:4px 16px;margin-bottom:5px;align-items:baseline">
      <div style="display:flex;align-items:center;gap:4px;min-width:200px">
        <label style="font-weight:700;white-space:nowrap;margin:0">Nom :</label>
        <input type="text" name="nom" value="<?= $e($old['nom']??'') ?>"
               placeholder="NOM" maxlength="100"
               style="border:none;border-bottom:1px solid #999;
                      font-size:10pt;padding:1px 4px;width:130px;outline:none;font-weight:700"
               class="<?= isset($errors['nom'])?'error':'' ?>" required>
      </div>
      <div style="display:flex;align-items:center;gap:4px;flex:1;min-width:160px">
        <label style="font-weight:700;white-space:nowrap;margin:0">Prénom(s) :</label>
        <input type="text" name="prenom" value="<?= $e($old['prenom']??'') ?>"
               placeholder="Prénom(s)" maxlength="100"
               style="border:none;border-bottom:1px solid #999;
                      font-size:10pt;padding:1px 4px;flex:1;outline:none">
      </div>
      <div style="display:flex;align-items:center;gap:4px;flex:1;min-width:180px">
        <label style="font-weight:700;white-space:nowrap;margin:0">Diplôme(s) :</label>
        <input type="text" name="diplome" value="<?= $e($old['diplome']??'') ?>"
               placeholder="Ex : Doctorat Unique + HDR" maxlength="150"
               style="border:none;border-bottom:1px solid #999;
                      font-size:10pt;padding:1px 4px;flex:1;outline:none">
      </div>
    </div>

    <!-- Grade | Date de nomination | Année académique -->
    <div style="display:flex;flex-wrap:wrap;gap:4px 16px;margin-bottom:5px;align-items:baseline">
      <div style="display:flex;align-items:center;gap:4px;min-width:260px">
        <label style="font-weight:700;white-space:nowrap;margin:0">Grade :</label>
        <input type="text" name="grade" list="dl-grade-local"
               value="<?= $e($old['grade']??'') ?>"
               placeholder="Ex : Professeur titulaire" maxlength="100"
               style="border:none;border-bottom:1px solid #999;
                      font-size:10pt;padding:1px 4px;width:200px;outline:none;font-weight:700"
               class="<?= isset($errors['grade'])?'error':'' ?>" required autocomplete="off">
        <?php if (isset($errors['grade'])): ?>
        <div class="err-text"><?= $e($errors['grade']) ?></div>
        <?php endif; ?>
      </div>
      <div style="display:flex;align-items:center;gap:4px;flex:1">
        <label style="font-weight:700;white-space:nowrap;margin:0">Date de Nomination :</label>
        <input type="date" name="date_nomination"
               value="<?= $e($old['date_nomination']??'') ?>"
               style="border:none;border-bottom:1px solid #999;
                      font-size:10pt;padding:1px 4px;outline:none">
      </div>
      <div style="display:flex;align-items:center;gap:4px;min-width:220px">
        <label style="font-weight:700;white-space:nowrap;margin:0">Année académique :</label>
        <input type="text" name="annee_academique"
               value="<?= $e($old['annee_academique'] ?? $config['annee_academique'] ?? '2024-2025') ?>"
               placeholder="ex: 2024-2025"
               style="border:none;border-bottom:1px solid #999;
                      font-size:10pt;padding:1px 4px;width:120px;outline:none;font-weight:700"
               required>
      </div>
    </div>

    <!-- Téléphone — uniquement pour VACATAIRE -->
    <div id="bloc_vacataire_telephone" style="<?= !$isVac ? 'display:none' : '' ?>">
      <div style="display:flex;flex-wrap:wrap;gap:4px 16px;margin-bottom:5px;align-items:baseline">
        <div style="display:flex;align-items:center;gap:4px;flex:1;min-width:300px">
          <label style="font-weight:700;white-space:nowrap;margin:0">Téléphone :</label>
          <input type="tel" name="telephone"
                 value="<?= $e($old['telephone']??'') ?>"
                 placeholder="Ex : +226 xx xx xx xx" maxlength="20"
                 style="border:none;border-bottom:1px solid #999;
                        font-size:10pt;padding:1px 4px;flex:1;outline:none">
        </div>
      </div>
    </div>

    <!-- Spécialité — uniquement pour VACATAIRE -->
    <div id="bloc_vacataire_specialite" style="<?= !$isVac ? 'display:none' : '' ?>">
      <div style="display:flex;flex-wrap:wrap;gap:4px 16px;margin-bottom:5px;align-items:baseline">
        <div style="display:flex;align-items:center;gap:4px;flex:1;min-width:300px">
          <label style="font-weight:700;white-space:nowrap;margin:0">Spécialité de l'enseignant :</label>
          <input type="text" name="specialite"
                 value="<?= $e($old['specialite']??'') ?>"
                 placeholder="Ex : Mathématiques, Informatique, Physique" maxlength="255"
                 style="border:none;border-bottom:1px solid #999;
                        font-size:10pt;padding:1px 4px;flex:1;outline:none">
        </div>
      </div>
    </div>

    <!-- Vol. statutaire | Abattement | Motif — masqué si IESR hors UJKZ ou vacataire -->
    <div id="bloc_permanent" style="<?= ($isVac || !$isUJKZ) ?'display:none':'' ?>">
      <div style="display:flex;flex-wrap:wrap;gap:4px 16px;margin-bottom:5px;align-items:baseline">
        <div style="display:flex;align-items:center;gap:4px">
          <label style="font-weight:700;white-space:nowrap;margin:0">Volume horaire statutaire :</label>
          <input type="number" name="volume_statutaire"
                 value="<?= $e($old['volume_statutaire']??'') ?>"
                 min="0" max="9999" placeholder="192"
                 style="border:none;border-bottom:1px solid #999;
                        font-size:10pt;padding:1px 4px;width:60px;outline:none;font-weight:700"
                 oninput="calcApres()">
        </div>
        <div style="display:flex;align-items:center;gap:4px">
          <label style="font-weight:700;white-space:nowrap;margin:0">Abattement :</label>
          <input type="number" name="abattement"
                 value="<?= $e($old['abattement']??'') ?>"
                 min="0" max="9999" placeholder="32"
                 style="border:none;border-bottom:1px solid #999;
                        font-size:10pt;padding:1px 4px;width:60px;outline:none;font-weight:700"
                 oninput="calcApres()">
        </div>
        <div style="display:flex;align-items:center;gap:4px;flex:1;min-width:200px">
          <label style="font-weight:700;white-space:nowrap;margin:0">Motif de l'abattement :</label>
          <input type="text" name="motif_abattement"
                 value="<?= $e($old['motif_abattement']??'') ?>"
                 placeholder="Ex : DG Recherche &amp; Innovation" maxlength="255"
                 style="border:none;border-bottom:1px solid #999;
                        font-size:10pt;padding:1px 4px;flex:1;outline:none;font-weight:700">
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:4px;margin-bottom:5px">
        <label style="font-weight:700;white-space:nowrap;margin:0">Volume horaire obligatoire après abattement :</label>
        <input type="number" name="volume_apres_abatt" oninput="majTotaux()" id="vol_apres"
               value="<?= $e($old['volume_apres_abatt']??'') ?>"
               min="0" max="9999" placeholder="Auto"
               style="border:none;border-bottom:1px solid #999;
                      font-size:10pt;padding:1px 4px;width:60px;outline:none;font-weight:700">
      </div>
    </div>

    <!-- Matricule (discret, mais obligatoire) -->
    <div style="display:flex;align-items:center;gap:4px;margin-bottom:5px">
      <label style="font-weight:700;white-space:nowrap;margin:0">Matricule :</label>
      <input type="text" name="matricule" id="champ-matricule"
             value="<?= $e($old['matricule']??'') ?>"
             placeholder="Ex : 123456A" maxlength="20"
             style="border:none;border-bottom:1px solid #999;
                    font-size:10pt;padding:1px 4px;width:130px;outline:none;font-weight:700"
             class="<?= isset($errors['matricule'])?'error':'' ?>"
             required autocomplete="off"
             <?= $isVac ? 'readonly' : '' ?>>
      <span id="hint-matricule" style="font-size:11px;color:#888;margin-left:4px">
        <?= $isVac ? '(généré automatiquement)' : '5 chiffres minimum + Une lettre Majuscule' ?>
      </span>
      <?php if (isset($errors['matricule'])): ?>
      <div class="err-text"><?= $e($errors['matricule']) ?></div>
      <?php endif; ?>
    </div>

    <!-- Email -->
    <div style="display:flex;align-items:center;gap:4px;margin-bottom:5px">
      <label style="font-weight:700;white-space:nowrap;margin:0">Email :</label>
      <input type="email" name="email" value="<?= $e($old['email']??'') ?>"
             placeholder="prenom.nom@ujkz.bf" maxlength="150"
             style="border:none;border-bottom:1px solid #999;
                    font-size:10pt;padding:1px 4px;width:260px;outline:none">
    </div>



    <!-- Upload diplôme / ancienne nomination (vacataires uniquement) -->
    <div id="bloc_upload_diplome" style="display:<?= $isVac?'block':'none' ?>;
         background:#fff8e1;border:1px solid #ffcc80;border-radius:6px;
         padding:8px 12px;margin-bottom:6px">
      <div style="font-weight:700;font-size:10pt;color:#7a5800;margin-bottom:6px">
        📎 Chargement du diplôme ou d'une ancienne nomination
        <span style="font-weight:400;font-size:9pt"> (obligatoire pour les vacataires)</span>
      </div>
      <?php
      $ficherDiplome = $old['fichier_diplome'] ?? '';
      $ficheNomination = $old['fichier_nomination'] ?? '';
      ?>
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <div>
          <label style="font-size:9.5pt;font-weight:600;color:#555;margin:0">Diplôme (PDF/JPG/PNG) :</label>
          <input type="file" name="fichier_diplome_upload" accept=".pdf,.jpg,.jpeg,.png"
                 style="font-size:10pt;margin-top:2px">
          <?php if ($ficherDiplome): ?>
          <div style="font-size:9pt;color:#2e7d32;margin-top:2px">
            ✓ Fichier existant :
            <a href="uploads/<?= $e($ficherDiplome) ?>" target="_blank" style="color:#1a6b1a">
              <?= $e($ficherDiplome) ?>
            </a>
          </div>
          <?php endif; ?>
          <input type="hidden" name="fichier_diplome" value="<?= $e($ficherDiplome) ?>">
        </div>
        <div style="color:#aaa;font-size:12px;align-self:center">— ou —</div>
        <div>
          <label style="font-size:9.5pt;font-weight:600;color:#555;margin:0">Ancienne nomination (PDF) :</label>
          <input type="file" name="fichier_nomination_upload" accept=".pdf,.jpg,.jpeg,.png"
                 style="font-size:10pt;margin-top:2px">
          <?php if ($ficheNomination): ?>
          <div style="font-size:9pt;color:#2e7d32;margin-top:2px">
            ✓ Fichier existant :
            <a href="uploads/<?= $e($ficheNomination) ?>" target="_blank" style="color:#1a6b1a">
              <?= $e($ficheNomination) ?>
            </a>
          </div>
          <?php endif; ?>
          <input type="hidden" name="fichier_nomination" value="<?= $e($ficheNomination) ?>">
        </div>
      </div>
    </div>

    <!-- Département de rattachement administratif (AVANT établissement bénéficiaire) -->
    <div id="bloc_dept_admin" style="display:<?= ($isVac || !$isUJKZ) ?'none':'flex' ?>;align-items:center;gap:4px;margin-bottom:5px">
      <label style="font-weight:700;white-space:nowrap;margin:0">Département de rattachement administratif :</label>
      <input type="text" name="departement" list="dl-dept"
             value="<?= $e($old['departement']??'') ?>"
             placeholder="Département de rattachement" maxlength="100"
             style="border:none;border-bottom:1px solid #999;
                    font-size:10pt;padding:1px 4px;flex:1;min-width:180px;outline:none"
             class="<?= isset($errors['departement'])?'error':'' ?>"
             <?= ($isUJKZ && !$isVac) ? 'required' : '' ?> autocomplete="off">
      <?php if (isset($errors['departement'])): ?>
      <div class="err-text"><?= $e($errors['departement']) ?></div>
      <?php endif; ?>
    </div>


    <!-- Mois d'exécution -->
    <div style="display:flex;align-items:center;gap:4px;margin-bottom:4px">
      <label style="font-weight:700;white-space:nowrap;margin:0">Mois et semaines d'exécution des heures :</label>
      <input type="text" name="mois_execution"
             value="<?= $e($old['mois_execution']??'') ?>"
             placeholder="Oct–Déc sem. 1–14 …" maxlength="100"
             style="border:none;border-bottom:1px dotted #aaa;
                    font-size:10pt;padding:1px 4px;flex:1;outline:none">
    </div>
  </div><!-- /infos enseignant -->

  <!-- ════════ SÉPARATEUR ════════ -->
  <div style="border-top:1.5px solid #555;margin:10px 0 8px"></div>

  <!-- Titre tableau -->
  <div style="text-align:center;font-size:10pt;font-weight:700;margin-bottom:6px">
    Tableau descriptif des enseignements confiés en réunion de département
  </div>

  <?php if (!empty($errors['lignes'])): ?>
  <div class="alert alert-danger" style="margin-bottom:8px"><?= $e($errors['lignes']) ?></div>
  <?php endif; ?>

  <!-- ════════ TABLEAU MULTI-COURS ════════ -->
  <div style="overflow-x:auto;margin-bottom:6px">
    <table id="tbl-cours" style="width:100%;border-collapse:collapse;font-size:11.5px;min-width:640px">
      <thead>
        <tr style="background:#d0d0d0">
          <th rowspan="2" style="border:1px solid #555;padding:5px 3px;text-align:center;width:30px">N°</th>
          <th rowspan="2" style="border:1px solid #555;padding:5px 3px;text-align:center;width:130px">Étab. bénéficiaire</th>
          <th rowspan="2" style="border:1px solid #555;padding:5px 3px;text-align:center;width:120px">Département</th>
          <th rowspan="2" style="border:1px solid #555;padding:5px 3px;text-align:center;width:80px">CODE</th>
          <th rowspan="2" style="border:1px solid #555;padding:5px 3px;text-align:center;width:110px">PARCOURS</th>
          <th rowspan="2" style="border:1px solid #555;padding:5px 3px;text-align:center">ECUE</th>
          <th rowspan="2" style="border:1px solid #555;padding:5px 3px;text-align:center;width:38px">NTC</th>
          <th colspan="3" style="border:1px solid #555;padding:5px 3px;text-align:center">
            Volume horaire<sup>2</sup>
          </th>
          <th rowspan="2" style="border:1px solid #555;padding:4px 2px;text-align:center;width:28px"></th>
        </tr>
        <tr style="background:#d0d0d0">
          <th style="border:1px solid #555;padding:4px 3px;text-align:center;width:44px">CT (h)</th>
          <th style="border:1px solid #555;padding:4px 3px;text-align:center;width:44px">TD (h)</th>
          <th style="border:1px solid #555;padding:4px 3px;text-align:center;width:44px">TP (h)</th>
        </tr>
      </thead>

      <tbody id="tbody-cours">

        <!-- ─── Bloc Semestre 1 ──────────────────────────── -->
        <tr id="sep-s1" style="background:#eef5ee">
          <td colspan="11" style="border:1px solid #aaa;padding:4px 8px;font-weight:700;
                                  font-style:italic;font-size:11px;color:var(--ujkz-vert-dk)">
            ▼ Premier semestre de l'année
            <span style="float:right;display:flex;gap:4px">
              <button type="button" onclick="ajouterLigne('S1')"
                      style="background:var(--ujkz-vert);color:#fff;border:none;
                             border-radius:4px;padding:1px 8px;font-size:11px;cursor:pointer">
                + Ligne S1
              </button>

            </span>
          </td>
        </tr>

        <?php foreach ($lignesNorm as $i => $ligne): ?>
        <?php if (($ligne['semestre']??'S1') === 'S1'): ?>
        <?php
          $stLigne = $ligne['_statut'] ?? '';
          if ($stLigne === 'validee') {
              $stDot = '<span title="Validée" style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#1a7a1a;vertical-align:middle;margin-left:2px"></span>';
          } elseif ($stLigne === 'rejetee') {
              $stDot = '<span title="Rejetée" style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#c00;vertical-align:middle;margin-left:2px"></span>';
          } elseif ($stLigne === 'en_attente') {
              $stDot = '<span title="En attente" style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#f59e0b;vertical-align:middle;margin-left:2px"></span>';
          } else {
              $stDot = '';
          }
        ?>
        <tr class="cours-row cours-s1">
          <td style="border:1px solid #ccc;padding:2px;text-align:center;font-weight:600;color:#555;font-size:11px" class="row-num"><?= $i+1 ?><?= $stDot ?></td>
          <td style="border:1px solid #ccc;padding:2px">
            <?php $ebId = (int)($ligne['etab_beneficiaire_fiche'] ?? 0); ?>
            <select name="l_etab_benef[]" onchange="onLigneEtabChange(this)"
                    style="width:100%;border:none;padding:2px 2px;font-size:10.5px;background:transparent;outline:none;cursor:pointer">
              <option value="0">— Étab. —</option>
              <?php foreach ($etabsDB as $etOpt): ?>
              <option value="<?= (int)$etOpt['id'] ?>" <?= $ebId===(int)$etOpt['id']?'selected':'' ?>><?= $e($etOpt['nom']) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td style="border:1px solid #ccc;padding:2px">
            <?php
            $dbId = (int)($ligne['dept_beneficiaire_fiche'] ?? 0);
            $dpsL = $etabDeptIds[$ebId] ?? [];
            ?>
            <select name="l_dept_benef[]"
                    style="width:100%;border:none;padding:2px 2px;font-size:10.5px;background:transparent;outline:none;cursor:pointer">
              <option value="0">— Dép. —</option>
              <?php foreach ($dpsL as $dpOpt): ?>
              <option value="<?= (int)$dpOpt['id'] ?>" <?= $dbId===(int)$dpOpt['id']?'selected':'' ?>><?= $e($dpOpt['nom']) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td style="border:1px solid #ccc;padding:2px">
            <input type="text" name="l_code[]" value="<?= $e($ligne['code']??'') ?>"
                   placeholder="CODE" maxlength="20"
                   style="width:100%;border:none;padding:3px 4px;font-size:11.5px;background:transparent;outline:none">
          </td>
          <input type="hidden" name="l_semestre[]" value="S1">
          <td style="border:1px solid #ccc;padding:2px">
            <input type="text" name="l_parcours[]" value="<?= $e($ligne['parcours']??'') ?>"
                   placeholder="Parcours" maxlength="100"
                   style="width:100%;border:none;padding:3px 4px;font-size:11.5px;background:transparent;outline:none">
          </td>
          <td style="border:1px solid #ccc;padding:2px">
            <input type="text" name="l_cours[]" value="<?= $e($ligne['cours']??'') ?>"
                   placeholder="Intitulé de l'UE ou ECUE" maxlength="150"
                   style="width:100%;border:none;padding:3px 4px;font-size:11.5px;background:transparent;outline:none" required>
          </td>
          <td style="border:1px solid #ccc;padding:2px">
            <input type="text" name="l_ntc[]" value="<?= $e($ligne['ntc']??'') ?>"
                   placeholder="-" maxlength="10"
                   style="width:100%;border:none;padding:3px 4px;font-size:11.5px;background:transparent;outline:none;text-align:center">
          </td>
          <td style="border:1px solid #ccc;padding:2px">
            <input type="number" name="l_cm[]" value="<?= $e($ligne['volume_cm']??'0') ?>"
                   min="0" max="500" style="width:100%;border:none;padding:3px 4px;font-size:11.5px;background:transparent;outline:none;text-align:center" oninput="majTotaux()">
          </td>
          <td style="border:1px solid #ccc;padding:2px">
            <input type="number" name="l_td[]" value="<?= $e($ligne['volume_td']??'0') ?>"
                   min="0" max="500" style="width:100%;border:none;padding:3px 4px;font-size:11.5px;background:transparent;outline:none;text-align:center" oninput="majTotaux()">
          </td>
          <td style="border:1px solid #ccc;padding:2px">
            <input type="number" name="l_tp[]" value="<?= $e($ligne['volume_tp']??'0') ?>"
                   min="0" max="500" style="width:100%;border:none;padding:3px 4px;font-size:11.5px;background:transparent;outline:none;text-align:center" oninput="majTotaux()">
          </td>
          <input type="hidden" name="l_enc[]" value="0">
          <input type="hidden" name="l_fiche_id[]" value="<?= (int)($ligne['_fiche_id']??0) ?>">
          <td style="border:1px solid #ccc;padding:2px;text-align:center">
            <button type="button" onclick="supprimerLigne(this)"
                    style="background:none;border:none;cursor:pointer;color:#c00;font-size:14px;padding:1px 3px" title="Supprimer">✕</button>
          </td>
        </tr>
        <?php endif; endforeach; ?>



        <!-- Total S1 -->
        <tr id="tr-tot-s1" style="background:#d8d8d8">
          <td colspan="6" style="border:1px solid #aaa;padding:4px;text-align:center;font-weight:700;font-size:11px">TOTAL DU SEMESTRE<sup>1</sup></td>
          <td style="border:1px solid #aaa;padding:4px;text-align:center;font-weight:700" id="tot-s1-ntc"></td>
          <td style="border:1px solid #aaa;padding:4px;text-align:center;font-weight:700" id="tot-s1-cm">0</td>
          <td style="border:1px solid #aaa;padding:4px;text-align:center;font-weight:700" id="tot-s1-td">0</td>
          <td style="border:1px solid #aaa;padding:4px;text-align:center;font-weight:700" id="tot-s1-tp">0</td>
          <td style="border:1px solid #aaa" colspan="2"></td>
          <td style="border:1px solid #aaa"></td>
        </tr>

        <!-- ─── Bloc Semestre 2 ──────────────────────────── -->
        <tr id="sep-s2" style="background:#eef0fb">
          <td colspan="11" style="border:1px solid #aaa;padding:4px 8px;font-weight:700;
                                  font-style:italic;font-size:11px;color:#3a4a8f">
            ▼ Deuxième semestre de l'année
            <span style="float:right;display:flex;gap:4px">
              <button type="button" onclick="ajouterLigne('S2')"
                      style="background:#3a5fa0;color:#fff;border:none;
                             border-radius:4px;padding:1px 8px;font-size:11px;cursor:pointer">
                + Ligne S2
              </button>

            </span>
          </td>
        </tr>

        <?php foreach ($lignesNorm as $i => $ligne): ?>
        <?php if (($ligne['semestre']??'S1') === 'S2'): ?>
        <?php
          $stLigne = $ligne['_statut'] ?? '';
          if ($stLigne === 'validee') {
              $stDot = '<span title="Validée" style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#1a7a1a;vertical-align:middle;margin-left:2px"></span>';
          } elseif ($stLigne === 'rejetee') {
              $stDot = '<span title="Rejetée" style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#c00;vertical-align:middle;margin-left:2px"></span>';
          } elseif ($stLigne === 'en_attente') {
              $stDot = '<span title="En attente" style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#f59e0b;vertical-align:middle;margin-left:2px"></span>';
          } else {
              $stDot = '';
          }
        ?>
        <tr class="cours-row cours-s2">
          <td style="border:1px solid #ccc;padding:2px;text-align:center;font-weight:600;color:#555;font-size:11px" class="row-num">—<?= $stDot ?></td>
          <td style="border:1px solid #ccc;padding:2px">
            <?php $ebId = (int)($ligne['etab_beneficiaire_fiche'] ?? 0); ?>
            <select name="l_etab_benef[]" onchange="onLigneEtabChange(this)"
                    style="width:100%;border:none;padding:2px 2px;font-size:10.5px;background:transparent;outline:none;cursor:pointer">
              <option value="0">— Étab. —</option>
              <?php foreach ($etabsDB as $etOpt): ?>
              <option value="<?= (int)$etOpt['id'] ?>" <?= $ebId===(int)$etOpt['id']?'selected':'' ?>><?= $e($etOpt['nom']) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td style="border:1px solid #ccc;padding:2px">
            <?php
            $dbId = (int)($ligne['dept_beneficiaire_fiche'] ?? 0);
            $dpsL = $etabDeptIds[$ebId] ?? [];
            ?>
            <select name="l_dept_benef[]"
                    style="width:100%;border:none;padding:2px 2px;font-size:10.5px;background:transparent;outline:none;cursor:pointer">
              <option value="0">— Dép. —</option>
              <?php foreach ($dpsL as $dpOpt): ?>
              <option value="<?= (int)$dpOpt['id'] ?>" <?= $dbId===(int)$dpOpt['id']?'selected':'' ?>><?= $e($dpOpt['nom']) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td style="border:1px solid #ccc;padding:2px">
            <input type="text" name="l_code[]" value="<?= $e($ligne['code']??'') ?>"
                   placeholder="CODE" maxlength="20"
                   style="width:100%;border:none;padding:3px 4px;font-size:11.5px;background:transparent;outline:none">
          </td>
          <input type="hidden" name="l_semestre[]" value="S2">
          <td style="border:1px solid #ccc;padding:2px">
            <input type="text" name="l_parcours[]" value="<?= $e($ligne['parcours']??'') ?>"
                   placeholder="Parcours" maxlength="100"
                   style="width:100%;border:none;padding:3px 4px;font-size:11.5px;background:transparent;outline:none">
          </td>
          <td style="border:1px solid #ccc;padding:2px">
            <input type="text" name="l_cours[]" value="<?= $e($ligne['cours']??'') ?>"
                   placeholder="Intitulé de l'UE ou ECUE" maxlength="150"
                   style="width:100%;border:none;padding:3px 4px;font-size:11.5px;background:transparent;outline:none" required>
          </td>
          <td style="border:1px solid #ccc;padding:2px">
            <input type="text" name="l_ntc[]" value="<?= $e($ligne['ntc']??'') ?>"
                   placeholder="-" maxlength="10"
                   style="width:100%;border:none;padding:3px 4px;font-size:11.5px;background:transparent;outline:none;text-align:center">
          </td>
          <td style="border:1px solid #ccc;padding:2px">
            <input type="number" name="l_cm[]" value="<?= $e($ligne['volume_cm']??'0') ?>"
                   min="0" max="500" style="width:100%;border:none;padding:3px 4px;font-size:11.5px;background:transparent;outline:none;text-align:center" oninput="majTotaux()">
          </td>
          <td style="border:1px solid #ccc;padding:2px">
            <input type="number" name="l_td[]" value="<?= $e($ligne['volume_td']??'0') ?>"
                   min="0" max="500" style="width:100%;border:none;padding:3px 4px;font-size:11.5px;background:transparent;outline:none;text-align:center" oninput="majTotaux()">
          </td>
          <td style="border:1px solid #ccc;padding:2px">
            <input type="number" name="l_tp[]" value="<?= $e($ligne['volume_tp']??'0') ?>"
                   min="0" max="500" style="width:100%;border:none;padding:3px 4px;font-size:11.5px;background:transparent;outline:none;text-align:center" oninput="majTotaux()">
          </td>
          <input type="hidden" name="l_enc[]" value="0">
          <input type="hidden" name="l_fiche_id[]" value="<?= (int)($ligne['_fiche_id']??0) ?>">
          <td style="border:1px solid #ccc;padding:2px;text-align:center">
            <button type="button" onclick="supprimerLigne(this)"
                    style="background:none;border:none;cursor:pointer;color:#c00;font-size:14px;padding:1px 3px" title="Supprimer">✕</button>
          </td>
        </tr>
        <?php endif; endforeach; ?>



        <!-- Total S2 -->
        <tr id="tr-tot-s2" style="background:#d8d8d8">
          <td colspan="6" style="border:1px solid #aaa;padding:4px;text-align:center;font-weight:700;font-size:11px">TOTAL DU SEMESTRE<sup>2</sup></td>
          <td style="border:1px solid #aaa;padding:4px;text-align:center;font-weight:700" id="tot-s2-ntc"></td>
          <td style="border:1px solid #aaa;padding:4px;text-align:center;font-weight:700" id="tot-s2-cm">0</td>
          <td style="border:1px solid #aaa;padding:4px;text-align:center;font-weight:700" id="tot-s2-td">0</td>
          <td style="border:1px solid #aaa;padding:4px;text-align:center;font-weight:700" id="tot-s2-tp">0</td>
          <td style="border:1px solid #aaa" colspan="2"></td>
          <td style="border:1px solid #aaa"></td>
        </tr>

        <!-- ─── Encadrement (doctorat/thèses) — après totaux S1+S2 ─── -->
        <tr id="sep-enc" style="background:#f0eefc">
          <td colspan="11" style="border:1px solid #aaa;padding:4px 8px;font-weight:700;
                                  font-style:italic;font-size:11px;color:#4a3a8f">
            ▼ Encadrement
            <span style="float:right;display:flex;gap:4px">
              <button type="button" onclick="ajouterEncadrement()" id="btn-enc"
                      style="background:#4a3a8f;color:#fff;border:none;
                             border-radius:4px;padding:1px 8px;font-size:11px;cursor:pointer">
                + Ajouter encadrement
              </button>
            </span>
          </td>
        </tr>
        <?php foreach ($ligneEnc as $le): ?>
        <tr class="cours-row enc-row">
          <td style="border:1px solid #ccc;padding:2px;text-align:center;font-size:10px;color:#666;font-style:italic" class="row-num">Enc.</td>
          <td style="border:1px solid #ccc;padding:2px;text-align:center;color:#aaa;font-size:10px" colspan="2">—</td>
          <input type="hidden" name="l_etab_benef[]" value="">
          <input type="hidden" name="l_dept_benef[]" value="">
          <td style="border:1px solid #ccc;padding:2px">
            <input type="text" name="l_code[]" value="<?= $e($le['code']??'') ?>"
                   placeholder="code" maxlength="20"
                   style="width:100%;border:none;padding:3px 4px;font-size:11.5px;background:transparent;outline:none;font-style:italic">
          </td>
          <input type="hidden" name="l_semestre[]" value="ENC">
          <td style="border:1px solid #ccc;padding:2px" colspan="2">
            <input type="text" name="l_cours[]"
                   value="<?= $e($le['cours']??'Encadrement') ?>"
                   placeholder="Encadrement" maxlength="150"
                   style="width:100%;border:none;padding:3px 4px;font-size:11.5px;background:transparent;outline:none;font-style:italic;color:#444">
          </td>
          <td style="border:1px solid #ccc;padding:2px;text-align:center;color:#aaa">—</td>
          <td style="border:1px solid #ccc;padding:2px">
            <input type="number" name="l_cm[]" value="<?= $e($le['volume_cm']??'0') ?>"
                   min="0" max="500" style="width:100%;border:none;padding:3px 4px;font-size:11.5px;background:transparent;outline:none;text-align:center" oninput="majTotaux()">
          </td>
          <td style="border:1px solid #ccc;padding:2px">
            <input type="number" name="l_td[]" value="<?= $e($le['volume_td']??'0') ?>"
                   min="0" max="500" style="width:100%;border:none;padding:3px 4px;font-size:11.5px;background:transparent;outline:none;text-align:center" oninput="majTotaux()">
          </td>
          <td style="border:1px solid #ccc;padding:2px">
            <input type="number" name="l_tp[]" value="<?= $e($le['volume_tp']??'0') ?>"
                   min="0" max="500" style="width:100%;border:none;padding:3px 4px;font-size:11.5px;background:transparent;outline:none;text-align:center" oninput="majTotaux()">
          </td>
          <input type="hidden" name="l_enc[]" value="1">
          <input type="hidden" name="l_ntc[]" value="">
          <input type="hidden" name="l_parcours[]" value="">
          <input type="hidden" name="l_niveau[]" value="">
          <input type="hidden" name="l_statut[]" value="<?= $e($le['_statut']??'') ?>">
          <input type="hidden" name="l_fiche_id[]" value="<?= (int)($le['_fiche_id']??0) ?>">
          <td style="border:1px solid #ccc;padding:2px;text-align:center">
            <button type="button" onclick="supprimerLigne(this)"
                    style="background:none;border:none;cursor:pointer;color:#c00;font-size:14px;padding:1px 3px" title="Supprimer">✕</button>
          </td>
        </tr>
        <?php endforeach; ?>

        <!-- Total encadrement -->
        <tr id="tr-tot-enc" style="background:#dddaf5">
          <td colspan="6" style="border:1px solid #aaa;padding:4px;text-align:center;font-weight:700;font-size:11px">TOTAL ENCADREMENT</td>
          <td style="border:1px solid #aaa;padding:4px"></td>
          <td style="border:1px solid #aaa;padding:4px;text-align:center;font-weight:700" id="tot-enc-cm">0</td>
          <td style="border:1px solid #aaa;padding:4px;text-align:center;font-weight:700" id="tot-enc-td">0</td>
          <td style="border:1px solid #aaa;padding:4px;text-align:center;font-weight:700" id="tot-enc-tp">0</td>
          <td style="border:1px solid #aaa" colspan="2"></td>
          <td style="border:1px solid #aaa"></td>
        </tr>

      </tbody>

      <!-- Grand total -->
      <tfoot>
        <tr style="background:#a0a0a0">
          <td colspan="6" style="border:1px solid #888;padding:4px;text-align:center;font-weight:700;font-size:11px">
            TOTAL SEMESTRES 1 ET 2 + ENCADREMENT
          </td>
          <td style="border:1px solid #888;padding:4px;text-align:center;font-weight:700" id="tot-ntc"></td>
          <td style="border:1px solid #888;padding:4px;text-align:center;font-weight:700" id="tot-cm">0</td>
          <td style="border:1px solid #888;padding:4px;text-align:center;font-weight:700" id="tot-td">0</td>
          <td style="border:1px solid #888;padding:4px;text-align:center;font-weight:700" id="tot-tp">0</td>
          <td style="border:1px solid #888;padding:4px"></td>
        </tr>
        <!-- Heures supplémentaires prévisionnelles — UJKZ uniquement -->
        <tr id="tr-heures-sup" style="background:#fff9e6;display:<?= ($isUJKZ && !$isVac) ? '' : 'none' ?>">
          <td colspan="5" style="border:1px solid #aaa;padding:5px 6px;font-size:10.5px;font-style:italic;color:#555">
            Heures supplémentaires prévisionnelles =
            Total Encadrement + Total CT(S1+S2) + 0,75 × (Total TD(S1+S2) + Total TP(S1+S2)) − Volume obligatoire après abattement
          </td>
          <td colspan="4" style="border:1px solid #aaa;padding:5px;text-align:center;font-weight:700;font-size:11px;color:#7a3a00" id="heures-sup">—</td>
        </tr>
      </tfoot>
    </table>
  </div>

  <!-- Notes bas de page style officiel -->
  <div style="font-size:8.5pt;color:#555;border-top:1px solid #bbb;padding-top:5px;line-height:1.6">
    <sup>1</sup> Établir une fiche de suivi par établissement (CUP, UFR ou Institut) où intervient l'enseignant.<br>
    <sup>2</sup> Calculer le volume horaire sans convertir les TD et les TP en heures de cours théoriques.<br>
    NB : NTC = nombre total de crédits. Ne remplir qu'une seule fiche pour toutes les interventions sur le campus.<br>
    Ces fiches doivent être impérativement déposées par tout enseignant après la réunion d'attribution des heures.
  </div>

</div><!-- /fiche-style -->

<div class="btn-group" style="justify-content:flex-end;margin-top:1rem">
  <button type="submit" name="action" value="step1" class="btn btn-primary"
          onclick="return validerFormulaire()" style="font-size:14px;padding:10px 22px">
    👁 Aperçu &amp; Validation →
  </button>
</div>

<?php /* ══════════════════════════════════════════ ÉTAPE 2 — APERÇU */ ?>
<?php elseif ($step === 2): ?>

<?php
// Conserver champs identification
foreach (['matricule','nom','prenom','diplome','departement','email','type_enseignant','grade',
          'date_nomination','volume_statutaire','abattement','motif_abattement',
          'volume_apres_abatt','etab_rattachement','etab_administratif','etab_beneficiaire',
          'mois_execution','fichier_diplome','fichier_nomination',
          'annee_academique','telephone','specialite'] as $k):
?>
<input type="hidden" name="<?= $k ?>" value="<?= $e($old[$k]??'') ?>">
<?php endforeach; ?>

<?php
// Conserver les lignes (y compris encadrement)
foreach ($lignes as $idx => $ligne):
    foreach (['semestre','code','parcours','cours','ntc','volume_cm','volume_td','volume_tp',
              'niveau','is_encadrement','_fiche_id','_statut',
              'etab_beneficiaire_fiche','dept_beneficiaire_fiche'] as $f):
        if ($f === 'volume_cm') { $nm = 'l_cm'; }
        elseif ($f === 'volume_td') { $nm = 'l_td'; }
        elseif ($f === 'volume_tp') { $nm = 'l_tp'; }
        elseif ($f === 'is_encadrement') { $nm = 'l_enc'; }
        elseif ($f === '_fiche_id') { $nm = 'l_fiche_id'; }
        elseif ($f === '_statut') { $nm = 'l_statut'; }
        elseif ($f === 'etab_beneficiaire_fiche') { $nm = 'l_etab_benef'; }
        elseif ($f === 'dept_beneficiaire_fiche') { $nm = 'l_dept_benef'; }
        else { $nm = 'l_'.$f; }
        $val = $ligne[$f] ?? '';
        if ($f === 'is_encadrement') $val = $val ? '1' : '0';
?>
<input type="hidden" name="<?= $nm ?>[]" value="<?= $e((string)$val) ?>">
<?php   endforeach; endforeach; ?>

<?php
// Totaux pour aperçu
$s1   = array_values(array_filter($lignes, function($l){ return ($l['semestre']??'')==='S1'; }));
$s2   = array_values(array_filter($lignes, function($l){ return ($l['semestre']??'')==='S2'; }));
$sEnc = array_values(array_filter($lignes, function($l){
    $s = $l['semestre'] ?? '';
    return !($s === 'S1' || $s === 'S2') && !empty($l['is_encadrement']);
}));
$tS1cm = array_sum(array_column($s1,'volume_cm'));
$tS1td = array_sum(array_column($s1,'volume_td'));
$tS1tp = array_sum(array_column($s1,'volume_tp'));
$tS2cm = array_sum(array_column($s2,'volume_cm'));
$tS2td = array_sum(array_column($s2,'volume_td'));
$tS2tp = array_sum(array_column($s2,'volume_tp'));
$tEncCm = array_sum(array_column($sEnc,'volume_cm'));
$tEncTd = array_sum(array_column($sEnc,'volume_td'));
$tEncTp = array_sum(array_column($sEnc,'volume_tp'));
$tCm = $tS1cm+$tS2cm+$tEncCm; $tTd = $tS1td+$tS2td+$tEncTd; $tTp = $tS1tp+$tS2tp+$tEncTp;
// Heures supplémentaires prévisionnelles — UJKZ uniquement
$vaNum = (float)($old['volume_apres_abatt'] ?? 0);
$hSup  = ($isUJKZ && !$isVac && $vaNum > 0)
    ? ($tEncCm + ($tS1cm+$tS2cm) + 0.75*($tS1td+$tS2td+$tS1tp+$tS2tp)) - $vaNum
    : null;

$nom    = $old['nom']    ?? '';
$prenom = $old['prenom'] ?? '';
$nomComplet = trim($nom . ' ' . $prenom);
$diplome = $old['diplome'] ?? '';
$grade   = $old['grade']   ?? '';
$dateN   = !empty($old['date_nomination']) ? date('d/m/Y', strtotime($old['date_nomination'])) : '';
$vs  = $old['volume_statutaire']  ?? '';
$ab  = $old['abattement']         ?? '';
$mot = $old['motif_abattement']   ?? '';
$va  = $old['volume_apres_abatt'] ?? '';
$er  = $old['etab_rattachement']  ?? '';
$ea  = $old['etab_administratif'] ?? '';
// Construire la liste des étab bénéficiaires distincts depuis les lignes
// Map étab → liste de cours pour l'aperçu (résoudre IDs → noms)
$_ebMap = [];
foreach ($lignes as $_l) {
    if (!empty($_l['is_encadrement'])) continue;
    $ebId = (int)($_l['etab_beneficiaire_fiche'] ?? 0);
    if ($ebId === 0) continue;
    $ebNom = $etabById[$ebId] ?? "Étab.#$ebId";
    $cv    = trim($_l['cours'] ?? '');
    if (!isset($_ebMap[$ebNom])) $_ebMap[$ebNom] = [];
    if ($cv !== '' && !in_array($cv, $_ebMap[$ebNom], true)) $_ebMap[$ebNom][] = $cv;
}
$eb = implode(', ', array_keys($_ebMap));
$mois = $old['mois_execution']    ?? '';
$typeLbl = ($old['type_enseignant']??'permanent')==='vacataire'
           ? 'Pour enseignant vacataire' : 'Pour enseignant permanent';
?>

<!-- Bannière aperçu -->
<div style="background:linear-gradient(135deg,#fff8e1,#fff3cd);border:2px solid var(--ujkz-or);
            border-radius:8px;padding:12px 16px;display:flex;align-items:center;gap:12px;
            margin-bottom:1.25rem;font-size:13px">
  <span style="font-size:24px">👁</span>
  <div>
    <strong style="color:var(--ujkz-vert-dk)">Aperçu du document officiel</strong><br>
    <span style="color:#666">Vérifiez attentivement avant de soumettre.</span>
  </div>
</div>

<!-- ════════════ APERÇU FICHE ════════════ -->
<div id="apercu-fiche" style="background:#fff;border:1.5px solid #ccc;border-radius:6px;
     padding:20px 28px;max-width:880px;margin:0 auto 1.5rem;
     font-family:Arial,Helvetica,sans-serif;font-size:10pt;color:#000;
     box-shadow:0 2px 12px rgba(0,0,0,.08)">

  <!-- En-tête -->
  <table style="width:100%;border:none;border-collapse:collapse;margin-bottom:10px">
    <tr>
      <td style="width:36%;vertical-align:top;text-align:center;font-size:8.5pt;font-weight:700;line-height:1.55">
        MINISTÈRE DE L'ENSEIGNEMENT<br>SUPÉRIEUR, DE LA RECHERCHE<br>ET DE L'INNOVATION<br>
        <span style="font-weight:400">---------------</span><br>
        SECRÉTARIAT GÉNÉRAL<br><span style="font-weight:400">---------------</span><br>
        <strong>UNIVERSITÉ JOSEPH KI-ZERBO</strong><br>
        <span style="font-weight:400">---------------</span><br>PRÉSIDENCE<br>
        <span style="font-weight:400">----------------</span>
      </td>
      <td style="width:24%;text-align:center;vertical-align:middle">
        <img src="logo_ujkz.jpg" alt="Logo UJKZ" style="width:70px;height:70px;object-fit:contain">
      </td>
      <td style="width:36%;vertical-align:top;text-align:right;font-size:8.5pt;line-height:1.7">
        <strong><em>BURKINA FASO</em></strong><br>
        <em>La Patrie ou la Mort, nous Vaincrons</em><br>
        <span style="letter-spacing:2px;font-size:8pt">··············································</span><br>
        Année universitaire <strong><?= $e($annee) ?></strong>
      </td>
    </tr>
  </table>

  <!-- Titre -->
  <div style="border:1.5px solid #000;background:#e8e8e8;text-align:center;padding:7px;margin:8px 0 3px">
    <span style="font-size:13pt;font-weight:700">FICHE PROGRAMMATIQUE</span>
  </div>
  <div style="text-align:center;font-size:10.5pt;font-weight:700;text-decoration:underline;margin-bottom:8px">
    <?= $e($typeLbl) ?>
  </div>

  <!-- Infos enseignant -->
  <div style="font-size:10pt;line-height:1.9;margin-bottom:6px">
    <div>Nom : <strong><?= $e($nom) ?></strong>
      <?php if($prenom): ?>&nbsp;&nbsp; Prénom(s) : <strong><?= $e($prenom) ?></strong><?php endif; ?>
      <?php if($diplome): ?>&nbsp;&nbsp; Diplôme(s) : <strong><?= $e($diplome) ?></strong><?php endif; ?>
    </div>
    <div>Grade : <strong><?= $e($grade) ?></strong>
      <?php if($dateN): ?>&nbsp;&nbsp;&nbsp; Date de Nomination : <strong><?= $e($dateN) ?></strong><?php endif; ?>
    </div>
    <?php if ($vs !== '' && $isUJKZ && !$isVac): ?>
    <div>
      Volume horaire statutaire : <strong><?= $e($vs) ?>h</strong>
      &nbsp;&nbsp; Abattement : <strong><?= $e($ab) ?>h</strong>
      <?php if($mot): ?>&nbsp;&nbsp; Motif de l'abattement : <strong><?= $e($mot) ?></strong><?php endif; ?>
    </div>
    <div>Volume horaire obligatoire après abattement : <strong><?= $e($va) ?>h</strong></div>
    <?php endif; ?>
    <!-- Rattachement AVANT bénéficiaire -->
    <?php if ($er): ?>
    <div>IESR de rattachement : <strong><?= $e($er) ?></strong></div>
    <?php endif; ?>
    <?php if ($ea && $isUJKZ && !$isVac): ?>
    <div>Établissement de rattachement administratif : <strong><?= $e($ea) ?></strong></div>
    <?php endif; ?>
    <?php if (!empty($_ebMap)): ?>
    <div style="line-height:1.8">
      <span style="font-weight:700">Établissement bénéficiaire des enseignements :</span>
      <?php foreach ($_ebMap as $_etabNom => $_coursList): ?>
      <div style="margin-left:12px">
        — <strong><?= $e($_etabNom) ?></strong>
        <?php if (!empty($_coursList)): ?>
        <span style="font-weight:400;color:#333"> : <?= $e(implode(', ', $_coursList)) ?></span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div>Mois et semaines d'exécution des heures : <?= $mois ? '<strong>'.$e($mois).'</strong>' : str_repeat('.',40) ?>.</div>
  </div>

  <!-- Titre tableau -->
  <div style="text-align:center;font-size:9.5pt;margin:6px 0 4px">
    Tableau descriptif des enseignements confiés en réunion de département
  </div>

  <!-- Tableau aperçu avec CT TD TP -->
  <table style="width:100%;border-collapse:collapse;font-size:9pt">
    <thead>
      <tr style="background:#e0e0e0">
        <th style="border:1px solid #000;padding:4px 3px;text-align:center;width:4%">N°</th>
        <th style="border:1px solid #000;padding:4px 3px;text-align:center;width:11%">CODE</th>
        <th style="border:1px solid #000;padding:4px 3px;text-align:center;width:18%">PARCOURS</th>
        <th style="border:1px solid #000;padding:4px 3px;text-align:center">ECUE</th>
        <th style="border:1px solid #000;padding:4px 3px;text-align:center;width:5%">NTC</th>
        <th colspan="3" style="border:1px solid #000;padding:4px 3px;text-align:center;width:18%">
          Volume horaire<sup>1</sup>
        </th>
      </tr>
      <tr style="background:#e0e0e0">
        <th colspan="5" style="border:1px solid #000;padding:2px"></th>
        <th style="border:1px solid #000;padding:3px;text-align:center;width:6%">CT</th>
        <th style="border:1px solid #000;padding:3px;text-align:center;width:6%">TD</th>
        <th style="border:1px solid #000;padding:3px;text-align:center;width:6%">TP</th>
      </tr>
    </thead>
    <tbody>
<?php
// Fonction d'affichage d'une ligne aperçu
$tdB  = 'border:1px solid #000;padding:4px 3px';
$tdBC = $tdB.';text-align:center';
$renderLigne = function(int $num, array $l) use ($tdB, $tdBC, $e): string {
    $cm  = (int)($l['volume_cm']??0);
    $td  = (int)($l['volume_td']??0);
    $tp  = (int)($l['volume_tp']??0);
    $enc = !empty($l['is_encadrement']);
    $code_v = $enc ? ($l['code'] ?: '—') : $e($l['code']??'');
    $ue_v   = $enc
        ? '<em style="color:#555">'.$e($l['cours']??'Encadrement').'</em>'
        : $e($l['cours']??'');
    $num_v  = $enc ? '<em style="font-size:10px;color:#666">Enc.</em>' : (string)$num;
    $ntc_v  = $enc ? '—' : $e($l['ntc']??'');
    return '<tr>'
        .'<td style="'.$tdBC.'">'.$num_v.'</td>'
        .'<td style="'.$tdBC.'">'.($code_v).'</td>'
        .'<td style="'.$tdB.'">'.($e($l['parcours']??'')).'</td>'
        .'<td style="'.$tdB.'">'.($ue_v).'</td>'
        .'<td style="'.$tdBC.'">'.($ntc_v).'</td>'
        .'<td style="'.$tdBC.'">'.(($cm)?:'-').'</td>'
        .'<td style="'.$tdBC.'">'.(($td)?:'-').'</td>'
        .'<td style="'.$tdBC.'">'.(($tp)?:'-').'</td>'
        .'</tr>';
};
?>
      <?php if ($s1): ?>
      <tr style="background:#f2f2f2">
        <td colspan="8" style="border:1px solid #000;padding:4px;text-align:center;font-weight:700">
          Premier semestre de l'année
        </td>
      </tr>
      <?php $cnt=0; foreach ($s1 as $l): echo $renderLigne(++$cnt, $l); endforeach; ?>
      <tr style="background:#d0d0d0">
        <td colspan="5" style="border:1px solid #000;padding:4px;text-align:center;font-weight:700">
          TOTAL DU SEMESTRE<sup>1</sup>
        </td>
        <td style="border:1px solid #000;padding:4px;text-align:center;font-weight:700"><?= $tS1cm?:'' ?></td>
        <td style="border:1px solid #000;padding:4px;text-align:center;font-weight:700"><?= $tS1td?:'' ?></td>
        <td style="border:1px solid #000;padding:4px;text-align:center;font-weight:700"><?= $tS1tp?:'' ?></td>
      </tr>
      <?php endif; ?>

      <?php if ($s2): ?>
      <tr style="background:#f2f2f2">
        <td colspan="8" style="border:1px solid #000;padding:4px;text-align:center;font-weight:700">
          Deuxième semestre de l'année
        </td>
      </tr>
      <?php $cnt=0; foreach ($s2 as $l): echo $renderLigne(++$cnt, $l); endforeach; ?>
      <tr style="background:#d0d0d0">
        <td colspan="5" style="border:1px solid #000;padding:4px;text-align:center;font-weight:700">
          TOTAL DU SEMESTRE<sup>2</sup>
        </td>
        <td style="border:1px solid #000;padding:4px;text-align:center;font-weight:700"><?= $tS2cm?:'' ?></td>
        <td style="border:1px solid #000;padding:4px;text-align:center;font-weight:700"><?= $tS2td?:'' ?></td>
        <td style="border:1px solid #000;padding:4px;text-align:center;font-weight:700"><?= $tS2tp?:'' ?></td>
      </tr>
      <?php endif; ?>

      <?php if ($sEnc): ?>
      <tr style="background:#f0eefc">
        <td colspan="8" style="border:1px solid #000;padding:4px;text-align:center;font-weight:700;font-style:italic;font-size:9pt">
          Encadrement
        </td>
      </tr>
      <?php $cnt=0; foreach ($sEnc as $l): echo $renderLigne(++$cnt, $l); endforeach; ?>
      <tr style="background:#dddaf5">
        <td colspan="5" style="border:1px solid #000;padding:4px;text-align:center;font-weight:700;font-size:9pt">TOTAL ENCADREMENT</td>
        <td style="border:1px solid #000;padding:4px;text-align:center;font-weight:700"><?= $tEncCm?:'' ?></td>
        <td style="border:1px solid #000;padding:4px;text-align:center;font-weight:700"><?= $tEncTd?:'' ?></td>
        <td style="border:1px solid #000;padding:4px;text-align:center;font-weight:700"><?= $tEncTp?:'' ?></td>
      </tr>
      <?php endif; ?>

      <!-- Grand total -->
      <tr style="background:#b0b0b0">
        <td colspan="5" style="border:1px solid #000;padding:4px;text-align:center;font-weight:700">TOTAL S1 ET S2 + ENCADREMENT</td>
        <td style="border:1px solid #000;padding:4px;text-align:center;font-weight:700"><?= $tCm?:'' ?></td>
        <td style="border:1px solid #000;padding:4px;text-align:center;font-weight:700"><?= $tTd?:'' ?></td>
        <td style="border:1px solid #000;padding:4px;text-align:center;font-weight:700"><?= $tTp?:'' ?></td>
      </tr>
      <?php if ($hSup !== null): ?>
      <tr style="background:#fff9e6">
        <td colspan="5" style="border:1px solid #aaa;padding:4px 5px;font-size:8.5pt;font-style:italic;color:#555">
          Heures sup. prévisionnelles = Enc.CT + CT(S1+S2) + 0,75&times;(TD+TP total) &minus; Vol. obligatoire
        </td>
        <td colspan="3" style="border:1px solid #aaa;padding:4px;text-align:center;font-weight:700;font-size:10pt;color:<?= $hSup >= 0 ? '#1a4a1a' : '#b00' ?>">
          <?= number_format($hSup, 1) ?>h
        </td>
      </tr>
      <?php endif; ?>
    </tbody>
  </table>

  <!-- Notes officielles -->
  <div style="margin-top:20px;font-size:8pt;border-top:1px solid #aaa;padding-top:5px;color:#333;line-height:1.6">
    <sup>1</sup> Établir une fiche de suivi par établissement (CUP, UFR ou Institut) où intervient l'enseignant<br>
    <sup>2</sup> Calculer le volume horaire sans convertir les TD et les TP en heures de cours théoriques<br>
    NB : NTIC = nombre total de crédits. Ne remplir qu'une seule fiche pour toutes les interventions sur le campus.<br>
    Ces fiches doivent être impérativement déposées par tout enseignant après la réunion d'attribution des heures.
  </div>
</div><!-- /apercu -->

<!-- Résumé -->
<div class="card" style="max-width:880px;margin:0 auto 1.25rem">
  <div class="card-header">
    <div class="card-title">Résumé — <?= count($lignes) ?> cours à soumettre</div>
  </div>
  <table class="recap" style="font-size:13px">
    <tr><td>Enseignant</td><td><strong><?= $e($nomComplet) ?></strong> — <?= $e($grade) ?></td></tr>
    <tr><td>Matricule</td><td><strong><?= $e($old['matricule']??'') ?></strong></td></tr>
    <tr><td>Établissement bénéficiaire</td><td>
      <?php if (!empty($_ebMap)): ?>
        <?php foreach (array_keys($_ebMap) as $_etabR): ?>
          <?= $e($_etabR) ?><br>
        <?php endforeach; ?>
      <?php else: ?>—<?php endif; ?>
    </td></tr>
    <tr><td>Nombre de cours</td><td><strong><?= count($lignes) ?></strong> UE/ECUE/Encadrement</td></tr>
    <tr><td>Volume total</td>
        <td>CT : <strong><?= $tCm ?>h</strong> — TD : <strong><?= $tTd ?>h</strong> — TP : <strong><?= $tTp ?>h</strong>
            — Total : <strong><?= $tCm+$tTd+$tTp ?>h</strong></td></tr>
    <?php if ($hSup !== null): ?>
  <tr style="background:#fff9e6">
    <td style="font-weight:600">Heures sup. prévisionnelles</td>
    <td><strong style="color:<?= $hSup >= 0 ? '#1a4a1a' : '#b00' ?>"><?= number_format($hSup,1) ?>h</strong>
      <span style="font-size:11px;color:#888"> = Enc.CT + CT(S1+S2) + 0,75×(TD+TP) − Vol. obligatoire</span>
    </td>
  </tr>
  <?php endif; ?>
</table>
</div>

<div class="alert alert-info" style="max-width:880px;margin:0 auto 1rem">
  Une fois soumis, chaque cours sera transmis à votre chef de département pour validation.
</div>

<div class="btn-group" style="justify-content:space-between;max-width:880px;margin:0 auto">
  <button type="submit" name="action" value="back1" class="btn">← Modifier la fiche</button>
  <button type="submit" name="action" value="submit" class="btn btn-gold" style="font-size:15px;padding:10px 24px">
    <?= $modeEdit ? '✓ Enregistrer les modifications' : '✓ Soumettre la fiche programmatique' ?>
  </button>
</div>

<?php endif; ?>
</form>

<script>
/* ── Tableau de bord enseignant — scripts formulaire fiche ── */
const MATRICULE_ENDPOINT = '<?= rtrim(dirname($_SERVER["SCRIPT_NAME"]??"/"),"/") ?>/generer_matricule.php';
let _vacMat = "";
// Indique si l'IESR de rattachement est l'UJKZ (détermine l'affichage des heures sup)
var IS_UJKZ = <?= $isUJKZ ? 'true' : 'false' ?>;

// Données établissements/départements pour les selects des lignes de cours
// ETAB_LISTE et ETAB_DEPT avec IDs pour les nouvelles lignes dynamiques
var ETAB_LISTE = <?php
  $etabOpts2 = [['v'=>0,'l'=>'— Étab. —']];
  foreach ($etabsDB as $etO) { $etabOpts2[] = ['v'=>(int)$etO['id'],'l'=>$etO['nom']]; }
  echo json_encode($etabOpts2, JSON_UNESCAPED_UNICODE);
?>;
var ETAB_DEPT = <?php
  $jsMap = [];
  foreach ($etabDeptIds as $eid => $depts) {
      $jsMap[$eid] = array_map(function($d){ return ['v'=>$d['id'],'l'=>$d['nom']]; }, $depts);
  }
  echo json_encode($jsMap, JSON_UNESCAPED_UNICODE);
?>;

function _esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
function buildEtabSelect(valEtabId) {
    var html = '';
    for (var i=0;i<ETAB_LISTE.length;i++) {
        var sel = ETAB_LISTE[i].v == valEtabId ? ' selected' : '';
        html += "<option value='" + _esc(ETAB_LISTE[i].v) + "'" + sel + ">" + _esc(ETAB_LISTE[i].l) + "</option>";
    }
    return html;
}
function buildDeptSelect(valEtabId, valDeptId) {
    var html = "<option value='0'>— Dép. —</option>";
    if (valEtabId && ETAB_DEPT[valEtabId]) {
        var depts = ETAB_DEPT[valEtabId];
        for (var j=0;j<depts.length;j++) {
            var sel = depts[j].v == valDeptId ? ' selected' : '';
            html += "<option value='" + _esc(depts[j].v) + "'" + sel + ">" + _esc(depts[j].l) + "</option>";
        }
    }
    return html;
}
function onLigneEtabChange(sel) {
    var tr = sel.closest('tr');
    var ds = tr.querySelector("select[name='l_dept_benef[]']");
    if (ds) ds.innerHTML = buildDeptSelect(sel.value, 0);
}

// Détecte si un IESR est l'UJKZ
function _isUJKZ(val) {
    var v = val.toLowerCase();
    return v === '' || v.indexOf('ujkz') !== -1 || v.indexOf('ki-zerbo') !== -1 || v.indexOf('ki zerbo') !== -1;
}

// Appelé quand l'IESR de rattachement change
function onIesrChange(val) {
    var ujkz = _isUJKZ(val);
    var bp   = document.getElementById("bloc_permanent");
    var ba   = document.getElementById("bloc_etab_admin");   // display:contents dans la grille
    var lba  = document.getElementById("lbl_etab_admin");
    var bd   = document.getElementById("bloc_dept_admin");

    // Champs UJKZ-only : masqués si IESR hors UJKZ
    // Mettre à jour la variable globale IS_UJKZ
    IS_UJKZ = ujkz;
    // Ligne heures sup
    var trHs = document.getElementById("tr-heures-sup");
    if (trHs) trHs.style.display = ujkz ? "" : "none";
    if (bp)  bp.style.display  = ujkz ? "" : "none";
    if (bd)  bd.style.display  = ujkz ? "flex" : "none";
    // Étab admin : dans une grille display:contents — on masque le label et le wrapper
    if (lba) lba.style.display = ujkz ? "" : "none";
    if (ba) {
        // Masquer chaque enfant direct du bloc_etab_admin (label + div select)
        var children = ba.parentElement ? ba.parentElement.querySelectorAll('#lbl_etab_admin, #bloc_etab_admin > div') : [];
        for (var i=0; i<children.length; i++) {
            children[i].style.display = ujkz ? '' : 'none';
        }
    }
}

function toggleTypeEns(val) {
    var vac = (val === "vacataire");
    // Mettre à jour le style des cartes
    var lblPerm = document.getElementById("lbl-perm");
    var lblVac  = document.getElementById("lbl-vac");
    if (lblPerm) {
        lblPerm.style.borderColor  = vac ? "#ccc" : "var(--ujkz-vert)";
        lblPerm.style.background   = vac ? "#f9f9f9" : "#e8f5ee";
        lblPerm.style.color        = vac ? "#888" : "var(--ujkz-vert-dk)";
    }
    if (lblVac) {
        lblVac.style.borderColor   = vac ? "var(--ujkz-or)" : "#ccc";
        lblVac.style.background    = vac ? "#fff8e1" : "#f9f9f9";
        lblVac.style.color         = vac ? "#7a5800" : "#888";
    }
    var bp  = document.getElementById("bloc_permanent");
    if (bp) bp.style.display = vac ? "none" : "";
    // Masquer les deux champs rattachement pour les vacataires
    var br = document.getElementById("bloc_etab_rattach");
    if (br) br.style.display = vac ? "none" : "grid";
    var bu = document.getElementById("bloc_upload_diplome");
    if (bu) bu.style.display = vac ? "block" : "none";
    // Masquer département admin si vacataire
    var bdAdmin = document.getElementById("bloc_dept_admin");
    if (bdAdmin) bdAdmin.style.display = vac ? "none" : (IS_UJKZ ? "flex" : "none");
    // Masquer ligne heures sup si vacataire
    var trHsVac = document.getElementById("tr-heures-sup");
    if (trHsVac) trHsVac.style.display = vac ? "none" : (IS_UJKZ ? "" : "none");
    // ✅ Afficher/masquer champs Téléphone et Spécialité pour vacataire
    var bvt = document.getElementById("bloc_vacataire_telephone");
    if (bvt) bvt.style.display = vac ? "" : "none";
    var bvs = document.getElementById("bloc_vacataire_specialite");
    if (bvs) bvs.style.display = vac ? "" : "none";
    var champ = document.getElementById("champ-matricule");
    var hint  = document.getElementById("hint-matricule");
    if (!champ) return;
    if (vac) {
        champ.readOnly   = true;
        champ.style.color = "#888";
        if (hint) hint.textContent = "(généré automatiquement)";
        // En modification : conserver le matricule existant
        if (champ.value && champ.value.trim() !== '') {
            _vacMat = champ.value; // mémoriser pour ne pas l'effacer si on rebascule
            return;
        }
        if (_vacMat) { champ.value = _vacMat; return; }
        fetch(MATRICULE_ENDPOINT, {cache: "no-store"})
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.matricule) {
                    _vacMat = d.matricule;
                    champ.value = d.matricule;
                    if (hint) hint.textContent = "✓ " + d.matricule;
                }
            })
            .catch(function() { if (hint) hint.textContent = "Erreur"; });
    } else {
        champ.readOnly    = false;
        champ.style.color = "";
        if (champ.value === _vacMat) champ.value = "";
        _vacMat = "";
        if (hint) hint.textContent = "5+ chiffres + majuscule finale";
    }
}

function calcApres() {
    var s = parseInt(document.querySelector("[name=volume_statutaire]") && document.querySelector("[name=volume_statutaire]").value) || 0;
    var a = parseInt(document.querySelector("[name=abattement]") && document.querySelector("[name=abattement]").value) || 0;
    var el = document.getElementById("vol_apres");
    if (el && s > 0) el.value = Math.max(0, s - a);
}
var vsEl = document.querySelector("[name=volume_statutaire]");
if (vsEl) vsEl.addEventListener("input", calcApres);

/* ── Grade → Volume horaire statutaire ── */
var GRADE_VOLUMES = <?= json_encode($config['grades_volumes'] ?? []) ?>;
function onGradeChange() {
    var grade = document.querySelector("[name=grade]");
    var vol   = document.querySelector("[name=volume_statutaire]");
    if (!grade || !vol) return;
    var v = GRADE_VOLUMES[grade.value];
    if (v !== undefined && v > 0) {
        vol.value = v;
        calcApres();
    }
}
var gradeEl = document.querySelector("[name=grade]");
if (gradeEl) gradeEl.addEventListener("change", onGradeChange);

/* ── Styles pour les cellules du tableau ── */
var S   = "width:100%;border:none;padding:3px 4px;font-size:11.5px;background:transparent;outline:none";
var SC  = S + ";text-align:center";
var TD  = "border:1px solid #ccc;padding:2px";
var TDC = TD + ";text-align:center";

function ajouterLigne(sem) {
    var tbody  = document.getElementById("tbody-cours");
    var totRow = document.getElementById(sem === "S1" ? "tr-tot-s1" : "tr-tot-s2");
    var tr = document.createElement("tr");
    tr.className = "cours-row cours-" + sem.toLowerCase();
    tr.innerHTML =
        "<td style='" + TDC + ";font-weight:600;color:#555;font-size:11px' class='row-num'>—</td>" +
        "<td style='" + TD + "'><select name='l_etab_benef[]' onchange='onLigneEtabChange(this)' style='" + S + "'>" + buildEtabSelect('') + "</select></td>" +
        "<td style='" + TD + "'><select name='l_dept_benef[]' style='" + S + "'>" + buildDeptSelect('','') + "</select></td>" +
        "<td style='" + TD + "'><input type='text' name='l_code[]' placeholder='CODE' maxlength='20' style='" + S + "'></td>" +
        "<input type='hidden' name='l_semestre[]' value='" + sem + "'>" +
        "<td style='" + TD + "'><input type='text' name='l_parcours[]' placeholder='Parcours' maxlength='100' style='" + S + "'></td>" +
        "<td style='" + TD + "'><input type='text' name='l_cours[]' placeholder='Intitulé UE ou ECUE' maxlength='150' style='" + S + "' required></td>" +
        "<td style='" + TD + "'><input type='text' name='l_ntc[]' placeholder='-' maxlength='10' style='" + SC + "'></td>" +
        "<td style='" + TD + "'><input type='number' name='l_cm[]' value='0' min='0' max='500' style='" + SC + "' oninput='majTotaux()'></td>" +
        "<td style='" + TD + "'><input type='number' name='l_td[]' value='0' min='0' max='500' style='" + SC + "' oninput='majTotaux()'></td>" +
        "<td style='" + TD + "'><input type='number' name='l_tp[]' value='0' min='0' max='500' style='" + SC + "' oninput='majTotaux()'></td>" +
        "<input type='hidden' name='l_enc[]' value='0'>" +
        "<input type='hidden' name='l_niveau[]' value=''>" +
        "<input type='hidden' name='l_statut[]' value=''>" +
        "<input type='hidden' name='l_fiche_id[]' value='0'>" +
        "<td style='" + TDC + "'><button type='button' onclick='supprimerLigne(this)' style='background:none;border:none;cursor:pointer;color:#c00;font-size:14px;padding:1px 3px'>✕</button></td>";
    tbody.insertBefore(tr, totRow);
    numeroterlLignes();
    majTotaux();
    var inp = tr.querySelector("input[name='l_cours[]']");
    if (inp) inp.focus();
}

function ajouterEncadrement() {
    // Vérifier qu'il n'y a pas déjà une ligne d'encadrement
    var existing = document.querySelectorAll("#tbody-cours .enc-row");
    if (existing.length > 0) { alert("Une ligne d'encadrement existe déjà."); return; }
    var tbody  = document.getElementById("tbody-cours");
    // Insérer avant tr-tot-enc (section Encadrement)
    var totRow = document.getElementById("tr-tot-enc");
    var tr = document.createElement("tr");
    tr.className = "cours-row enc-row";
    tr.innerHTML =
        "<td style='" + TDC + ";font-size:10px;color:#666;font-style:italic' class='row-num'>Enc.</td>" +
        "<td style='" + TDC + ";color:#aaa;font-size:10px' colspan='2'>—</td>" +
        "<input type='hidden' name='l_etab_benef[]' value=''>" +
        "<input type='hidden' name='l_dept_benef[]' value=''>" +
        "<td style='" + TD + "'><input type='text' name='l_code[]' placeholder='code opt.' maxlength='20' style='" + S + ";font-style:italic'></td>" +
        "<input type='hidden' name='l_semestre[]' value='ENC'>" +
        "<td style='" + TD + "' colspan='2'><input type='text' name='l_cours[]' value='Encadrement' placeholder='Encadrement' maxlength='150' style='" + S + ";font-style:italic;color:#444'></td>" +
        "<td style='" + TDC + ";color:#aaa'>—</td>" +
        "<td style='" + TD + "'><input type='number' name='l_cm[]' value='0' min='0' max='500' style='" + SC + "' oninput='majTotaux()'></td>" +
        "<td style='" + TD + "'><input type='number' name='l_td[]' value='0' min='0' max='500' style='" + SC + "' oninput='majTotaux()'></td>" +
        "<td style='" + TD + "'><input type='number' name='l_tp[]' value='0' min='0' max='500' style='" + SC + "' oninput='majTotaux()'></td>" +
        "<input type='hidden' name='l_enc[]' value='1'>" +
        "<input type='hidden' name='l_ntc[]' value=''>" +
        "<input type='hidden' name='l_parcours[]' value=''>" +
        "<input type='hidden' name='l_niveau[]' value=''>" +
        "<input type='hidden' name='l_statut[]' value=''>" +
        "<input type='hidden' name='l_fiche_id[]' value='0'>" +
        "<td style='" + TDC + "'><button type='button' onclick='supprimerLigne(this)' style='background:none;border:none;cursor:pointer;color:#c00;font-size:14px;padding:1px 3px'>✕</button></td>";
    tbody.insertBefore(tr, totRow);
    majTotaux();
    var cm = tr.querySelector("input[name='l_cm[]']");
    if (cm) cm.focus();
}

function supprimerLigne(btn) {
    var tbody      = document.getElementById("tbody-cours");
    var normalRows = tbody.querySelectorAll(".cours-row:not(.enc-row)");
    var tr = btn.closest("tr");
    if (normalRows.length <= 1 && !tr.classList.contains("enc-row")) {
        alert("La fiche doit contenir au moins une ligne de cours.");
        return;
    }
    tr.remove();
    numeroterlLignes();
    majTotaux();
}

function numeroterlLignes() {
    var ns1 = 1, ns2 = 1;
    var rows = document.querySelectorAll("#tbody-cours .cours-row");
    for (var i = 0; i < rows.length; i++) {
        if (rows[i].classList.contains("enc-row")) continue;
        var semInp = rows[i].querySelector("input[name='l_semestre[]']");
        var sem    = semInp ? semInp.value : "S1";
        var td     = rows[i].querySelector(".row-num");
        if (td) td.textContent = (sem === "S1") ? ns1++ : ns2++;
    }
}

function majTotaux() {
    var s1cm = 0, s1td = 0, s1tp = 0, s2cm = 0, s2td = 0, s2tp = 0;
    var s1ntc = 0, s2ntc = 0;
    var enccm = 0, enctd = 0, enctp = 0;
    var rows = document.querySelectorAll("#tbody-cours .cours-row");
    for (var i = 0; i < rows.length; i++) {
        var semInp = rows[i].querySelector("input[name='l_semestre[]']");
        var sem    = semInp ? semInp.value : "S1";
        var cmInp  = rows[i].querySelector("input[name='l_cm[]']");
        var tdInp  = rows[i].querySelector("input[name='l_td[]']");
        var tpInp  = rows[i].querySelector("input[name='l_tp[]']");
        var ntcInp = rows[i].querySelector("input[name='l_ntc[]']");
        var cm  = parseInt(cmInp  ? cmInp.value  : 0) || 0;
        var td  = parseInt(tdInp  ? tdInp.value  : 0) || 0;
        var tp  = parseInt(tpInp  ? tpInp.value  : 0) || 0;
        var ntc = parseFloat(ntcInp ? ntcInp.value : 0) || 0;
        if (sem === "S1")  { s1cm += cm; s1td += td; s1tp += tp; s1ntc += ntc; }
        else if (sem === "S2") { s2cm += cm; s2td += td; s2tp += tp; s2ntc += ntc; }
        else               { enccm += cm; enctd += td; enctp += tp; } // ENC
    }
    function setEl(id, v) {
        var el = document.getElementById(id);
        if (el) el.textContent = (v === "" || v === null || v === undefined) ? "" : v;
    }
    setEl("tot-s1-ntc", s1ntc || ""); setEl("tot-s1-cm", s1cm); setEl("tot-s1-td", s1td); setEl("tot-s1-tp", s1tp);
    setEl("tot-s2-ntc", s2ntc || ""); setEl("tot-s2-cm", s2cm); setEl("tot-s2-td", s2td); setEl("tot-s2-tp", s2tp);
    setEl("tot-enc-cm", enccm); setEl("tot-enc-td", enctd); setEl("tot-enc-tp", enctp);
    var totalNtc = s1ntc + s2ntc;
    setEl("tot-ntc", totalNtc || "");
    var totalCm = s1cm + s2cm + enccm;
    var totalTd = s1td + s2td + enctd;
    var totalTp = s1tp + s2tp + enctp;
    setEl("tot-cm", totalCm); setEl("tot-td", totalTd); setEl("tot-tp", totalTp);
    // Heures supplémentaires prévisionnelles
    var volOblig = parseFloat((document.querySelector("[name=volume_apres_abatt]") || {value:"0"}).value) || 0;
    if (volOblig > 0) {
        var hSup = enccm + (s1cm + s2cm) + 0.75 * (s1td + s2td + s1tp + s2tp) - volOblig;
        var trHS = document.getElementById("tr-heures-sup");
        if (IS_UJKZ) {
            setEl("heures-sup", hSup.toFixed(1));
            if (trHS) trHS.style.display = "";
        } else {
            if (trHS) trHS.style.display = "none";
        }
    } else {
        var trHS2 = document.getElementById("tr-heures-sup");
        if (!IS_UJKZ && trHS2) trHS2.style.display = "none";
        setEl("heures-sup", "—");
    }
}

function validerFormulaire() {
    var ok   = true;
    var inps = document.querySelectorAll("#tbody-cours .cours-row:not(.enc-row) input[name='l_cours[]']");
    for (var i = 0; i < inps.length; i++) {
        if (!inps[i].value.trim()) { inps[i].style.border = "1.5px solid red"; ok = false; }
        else inps[i].style.border = "none";
    }
    if (!ok) { alert("Veuillez remplir l'intitulé de chaque UE ou ECUE."); return false; }
    var form = document.getElementById("main-form");
    var rows = document.querySelectorAll("#tbody-cours .cours-row:not(.enc-row)");
    for (var j = 0; j < rows.length; j++) {
        var inp  = document.createElement("input");
        inp.type = "hidden";
        inp.name = "l_niveau[]";
        inp.value = <?= json_encode($niveaux[0] ?? 'Licence 1') ?>;
        form.appendChild(inp);
    }
    return true;
}

/* -- Étab bénéficiaire -> filtre datalist département -- */
var ETAB_DEPTS = <?= json_encode($config['etab_departements'] ?? []) ?>;
function onEtabChange() {
    var etab = document.querySelector("[name=etab_beneficiaire]");
    if (!etab) return;
    var d = ETAB_DEPTS[etab.value];
    if (!d) return;
    var dl = document.getElementById('dl-dept');
    if (!dl) return;
    dl.innerHTML = '';
    d.forEach(function(dep) {
        var opt = document.createElement('option');
        opt.value = dep;
        dl.appendChild(opt);
    });
}

document.addEventListener("DOMContentLoaded", function() {
    var sel = document.querySelector("input[name='type_enseignant']:checked");
    if (sel) toggleTypeEns(sel.value);
    // Appliquer l'état IESR au chargement (si déjà renseigné)
    var iesrInp = document.querySelector("input[name='etab_rattachement']");
    if (iesrInp && iesrInp.value !== '') onIesrChange(iesrInp.value);
    majTotaux();
    numeroterlLignes();
    // Lier grade et étab au chargement
    var gradeEl2 = document.querySelector("[name=grade]");
    if (gradeEl2) gradeEl2.addEventListener("change", onGradeChange);
    onEtabChange();
});
</script>
