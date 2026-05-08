<?php
// ============================================================
// fiche_suivi.php — Fiche semestrielle de suivi des heures effectuées
// Accès via dashboard.php?token=XXX&suivi=FICHE_ID
// Variables : $fiche, $enseignant, $preuves, $historique, $token, $config, $annee
// ============================================================
declare(strict_types=1);
$e = function($v) { return Security::e($v); };
$ens  = $enseignant;
$tok  = $token ?? $ens['token'] ?? '';
$annee = $annee ?? $config['annee_academique'] ?? '2024-2025';
$sem   = $fiche['semestre'] ?? 'S1';
$semLabel = ($sem === 'S1') ? '1' : '2';
$dateImpression = date('d/m/Y à H:i');

// Génération du numéro de fiche et QR code
$numeroFiche = $fiche['numero_fiche'] ?? '';
$qrcodeToken = $fiche['qrcode_token'] ?? '';
$qrcodeBase64 = '';

// Si pas de numéro, le générer
if (!$numeroFiche) {
    $anneeNum = explode('-', $annee)[0];
    $numeroFiche = 'FP-' . $anneeNum . '-' . str_pad((string)$fiche['id'], 4, '0', STR_PAD_LEFT);
    // Sauvegarder
    try {
        $updateStmt = Database::getInstance()->prepare("UPDATE fiches SET numero_fiche = ? WHERE id = ?");
        $updateStmt->execute([$numeroFiche, (int)$fiche['id']]);
    } catch (Exception $ex) {
        // Silencieusement ignorer l'erreur de sauvegarde
    }
}

// Si pas de token QR, le générer
if (!$qrcodeToken) {
    $qrcodeToken = bin2hex(random_bytes(16));
    // Sauvegarder
    try {
        $updateStmt = Database::getInstance()->prepare("UPDATE fiches SET qrcode_token = ? WHERE id = ?");
        $updateStmt->execute([$qrcodeToken, (int)$fiche['id']]);
    } catch (Exception $ex) {
        // Silencieusement ignorer l'erreur de sauvegarde
    }
}

// Générer le QR code en base64
$verificationUrl = 'https://ujkz.edu.bf/verifier-fiche?token=' . urlencode($qrcodeToken);
// Placeholder QR code (à remplacer par vraie génération si librairie disponible)
$qrcodeBase64 = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAADIAAAAyCAYAAAAeP4ixAAAAYklEQVR4nO3TMQEAAADCoPVPbQhDoAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAHw1cMAAb66OgAAAAASUVORK5CYII=';

// Calcul des volumes effectués depuis les preuves
$cmEffectue = 0; $tdEffectue = 0;
foreach ($preuves as $p) {
    $cmEffectue += (int)($p['volume_cm_effectue'] ?? 0);
    $tdEffectue += (int)($p['volume_td_effectue'] ?? 0);
}

// Infos enseignant
$nom    = trim($ens['nom'] ?? '');
$prenom = $ens['prenom'] ?? '';
$grade  = $ens['grade']  ?? '';
$dateN  = !empty($ens['date_nomination'])
          ? date('d/m/Y', strtotime($ens['date_nomination'])) : '';
$vs     = $ens['volume_statutaire']  ?? '';
$ab     = $ens['abattement']         ?? '';
$mot    = $ens['motif_abattement']   ?? '';
$va     = $ens['volume_apres_abatt'] ?? '';
$er     = $ens['etab_rattachement']  ?? '';
$eb     = $ens['etab_beneficiaire']  ?? '';
$diplome = $ens['diplome']           ?? '';
$moisBrut = $ens['mois_execution'] ?? '';
// Commentaires des justificatifs → enrichissent le champ Mois
$commentairesSuivi = [];
foreach ($preuves as $p) {
    if (!empty($p['commentaire'])) $commentairesSuivi[] = trim($p['commentaire']);
}
$moisJustif = implode(' — ', array_unique($commentairesSuivi));
$mois = $moisJustif ?: $moisBrut;

// Historique validations
$valMap = [];
foreach ($historique as $h) {
    $r = $h['valideur_role'] ?? $h['role'] ?? '';
    if (!isset($valMap[$r]) || $h['decision'] === 'valide') $valMap[$r] = $h;
}
$sigActors = [
    ['role'=>'chef_dept',         'titre'=>"Le Chef de Département"],
    ['role'=>'directeur_adjoint', 'titre'=>"Le Directeur Adjoint"],
    ['role'=>'directeur',         'titre'=>"Le Directeur"],
    ['role'=>'dei',               'titre'=>"La DEI"],
];
?>

<!-- Barre d'actions (non imprimée) -->
<div class="no-print" style="
  background:#f9f9f9;border-bottom:1px solid #ddd;
  padding:10px 20px;display:flex;align-items:center;gap:10px">
  <a href="dashboard.php?token=<?= $e($tok) ?>&fiche=<?= (int)$fiche['id'] ?>"
     class="btn btn-sm">← Retour fiche</a>
  <button onclick="window.print()" class="btn btn-sm btn-primary">
    🖨 Imprimer / PDF
  </button>
  <?php if (empty($preuves)): ?>
  <span style="font-size:12px;color:#e65100;margin-left:8px">
    ⚠ Aucun justificatif déposé — ajoutez des justificatifs pour générer cette fiche
  </span>
  <?php else: ?>
  <span style="font-size:12px;color:#666;margin-left:auto">
    Fiche générée depuis <?= count($preuves) ?> justificatif(s) · <?= $dateImpression ?>
  </span>
  <?php endif; ?>
</div>

<style>
@media print {
  .no-print, .site-header, .site-subnav, .breadcrumb,
  nav, footer, .btn, .btn-group, .page-hero { display:none !important; }
  body { background:#fff !important; }
  .fs-wrapper { box-shadow:none !important; border:none !important;
                padding:0 !important; margin:0 !important; max-width:none !important; }
  @page { size:A4 portrait; margin:15mm 12mm; }
}
.fs-wrapper {
  background:#fff; max-width:880px; margin:16px auto;
  padding:22px 28px;
  font-family:Arial,Helvetica,sans-serif; font-size:10.5pt; color:#000;
  box-shadow:0 2px 12px rgba(0,0,0,.08);
  border:1.5px solid #ccc; border-radius:4px;
}
.fs-table { width:100%; border-collapse:collapse; font-size:9.5pt; }
.fs-table th, .fs-table td { border:1px solid #000; padding:4px 3px; }
.fs-table th { background:#e0e0e0; text-align:center; font-weight:700; }
.fs-tot td { background:#d0d0d0; font-weight:700; text-align:center; }
.fs-sig { width:100%; border-collapse:collapse; margin-top:16px; }
.fs-sig td { width:25%; text-align:center; vertical-align:top; padding:6px 8px; }
.fs-sig-titre { font-weight:700; font-size:9.5pt; text-decoration:underline; margin-bottom:8px; }
.fs-sig-line { border-bottom:1px solid #000; margin:22px 10px 3px; }
</style>

<?php if (empty($preuves)): ?>
<!-- Aucun justificatif -->
<div style="max-width:880px;margin:2rem auto;text-align:center;padding:3rem;
            background:#fff;border:2px dashed #ddd;border-radius:8px">
  <div style="font-size:2.5rem;margin-bottom:.75rem">📭</div>
  <div style="font-size:15px;font-weight:600;margin-bottom:.5rem">
    Aucun justificatif pour ce cours
  </div>
  <div style="color:#666;margin-bottom:1.5rem">
    La fiche de suivi sera disponible après l'ajout d'au moins un justificatif d'exécution.
  </div>
  <a href="dashboard.php?token=<?= $e($tok) ?>&fiche=<?= (int)$fiche['id'] ?>#justificatifs"
     class="btn btn-primary">+ Ajouter un justificatif</a>
</div>
<?php else: ?>

<div class="fs-wrapper">

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
        <img src="logo_ujkz.jpg" alt="Logo UJKZ"
             style="width:68px;height:68px;object-fit:contain">
      </td>
      <td style="width:36%;vertical-align:top;text-align:right;font-size:8.5pt;line-height:1.7">
        <strong><em>BURKINA FASO</em></strong><br>
        <em>La Patrie ou la Mort, nous Vaincrons</em><br>
        <span style="letter-spacing:2px;font-size:7.5pt">·········································</span><br>
        Année universitaire <strong><?= $e($annee) ?></strong>
      </td>
    </tr>
  </table>

  <!-- Numéro de fiche et QR code -->
  <table style="width:100%;border:none;border-collapse:collapse;margin-bottom:8px">
    <tr>
      <td style="width:70%;vertical-align:top;font-size:10pt">
        <strong>Numéro de fiche : <?= $e($numeroFiche) ?></strong><br>
        <span style="font-size:9pt;color:#666">Pour vérification de l'authenticité</span>
      </td>
      <td style="width:30%;text-align:right;vertical-align:top">
        <img src="<?= $qrcodeBase64 ?>" alt="QR Code" style="width:80px;height:80px;border:1px solid #999">
      </td>
    </tr>
  </table>

  <!-- Titre -->
  <div style="border:1.5px solid #000;background:#e0e0e0;text-align:center;padding:7px;margin-bottom:3px">
    <span style="font-size:13pt;font-weight:700">FICHE SEMESTRIELLE DE SUIVI DES HEURES EFFECTUÉES</span>
  </div>
  <div style="text-align:center;font-size:10.5pt;font-weight:700;text-decoration:underline;margin-bottom:6px">
    Pour enseignant <?= $e($ens['type_enseignant'] ?? 'permanent') ?>
  </div>

  <!-- Semestre coché -->
  <div style="font-size:10pt;margin-bottom:6px">
    Semestre :
    <span style="display:inline-block;border:1.5px solid #000;width:14px;height:14px;
                 text-align:center;line-height:12px;margin:0 4px;vertical-align:middle;font-size:10pt">
      <?= ($sem === 'S1') ? '✓' : '' ?>
    </span> S1
    &nbsp;&nbsp;
    <span style="display:inline-block;border:1.5px solid #000;width:14px;height:14px;
                 text-align:center;line-height:12px;margin:0 4px;vertical-align:middle;font-size:10pt">
      <?= ($sem === 'S2') ? '✓' : '' ?>
    </span> S2
  </div>

  <!-- Informations enseignant -->
  <div style="font-size:10pt;line-height:1.9;margin-bottom:6px">
    <div>
      Nom : <strong><?= $e($nom) ?></strong>
      <?php if($prenom): ?>&nbsp;&nbsp; Prénom(s) : <strong><?= $e($prenom) ?></strong><?php endif; ?>
      <?php if($diplome): ?>&nbsp;&nbsp; Diplôme(s) : <strong><?= $e($diplome) ?></strong><?php endif; ?>
    </div>
    <div>
      Grade : <strong><?= $e($grade) ?></strong>
      <?php if($dateN): ?>&nbsp;&nbsp;&nbsp; Date de Nomination : <strong><?= $e($dateN) ?></strong><?php endif; ?>
    </div>
    <?php if ($vs !== ''): ?>
    <div>
      Volume horaire statutaire : <strong><?= $e($vs) ?>h</strong>
      &nbsp;&nbsp; Abattement : <strong><?= $e($ab) ?></strong>
      <?php if($mot): ?>&nbsp;&nbsp; Motif : <strong><?= $e($mot) ?></strong><?php endif; ?>
    </div>
    <div>Volume horaire obligatoire après abattement : <strong><?= $e($va) ?>h</strong></div>
    <?php endif; ?>
    <?php if ($er): ?>
    <div>Établissement de rattachement administratif : <strong><?= $e($er) ?></strong></div>
    <?php endif; ?>
    <div>Établissement bénéficiaire des enseignements : <strong><?= $e($eb) ?></strong></div>
    <div>Mois et semaines d'exécution des heures :
      <strong><?= $mois ? $e($mois) : str_repeat('.', 35) ?></strong>
    </div>
  </div>

  <!-- Titre tableau -->
  <div style="text-align:center;font-size:9.5pt;margin:6px 0 4px">
    Tableau descriptif des enseignements confiés et effectués
  </div>

  <!-- Tableau : colonnes Volume confié + Volume effectué -->
  <table class="fs-table">
    <thead>
      <tr>
        <th rowspan="2" style="width:4%">N°</th>
        <th rowspan="2" style="width:10%">CODE</th>
        <th rowspan="2" style="width:14%">PARCOURS</th>
        <th rowspan="2">ECUE</th>
        <th rowspan="2" style="width:5%">NTC</th>
        <th colspan="3" style="width:16%">Volume horaire total confié<sup>2</sup></th>
        <th colspan="3" style="width:16%">Volume horaire effectué<sup>3</sup></th>
        <th rowspan="2" style="width:18%;text-align:center;font-size:8.5pt" class="no-print">Actions</th>
      </tr>
      <tr>
        <th style="width:5%">CT</th>
        <th style="width:5%">TD</th>
        <th style="width:5%">TP</th>
        <th style="width:5%">CT</th>
        <th style="width:5%">TD</th>
        <th style="width:5%">TP</th>
      </tr>
    </thead>
    <tbody>
      <?php
      // Cours unique de cette fiche (avec ses preuves agrégées)
      $code = $fiche['code_ue'] ?: ($fiche['code'] ?? '');
      $cmC  = (int)($fiche['volume_cm'] ?? 0);
      $tdC  = (int)($fiche['volume_td'] ?? 0);
      $tpC  = (int)($fiche['volume_tp'] ?? 0);
      // Volumes effectués agrégés depuis toutes les preuves
      $cmE  = $cmEffectue;
      $tdE  = $tdEffectue;
      ?>
      <tr id="cours-row-<?= (int)$fiche['id'] ?>">
        <td style="text-align:center">1</td>
        <td style="text-align:center"><?= $e($code) ?></td>
        <td><?= $e($fiche['parcours'] ?? '') ?></td>
        <td><?= $e($fiche['cours'] ?? '') ?></td>
        <td style="text-align:center"><?= $e($fiche['ntc'] ?? '') ?></td>
        <td style="text-align:center"><?= $cmC ?: '-' ?></td>
        <td style="text-align:center"><?= $tdC ?: '-' ?></td>
        <td style="text-align:center"><?= $tpC ?: '-' ?></td>
        <td style="text-align:center" id="cm-eff-<?= (int)$fiche['id'] ?>"><?= $cmE ?: '-' ?></td>
        <td style="text-align:center" id="td-eff-<?= (int)$fiche['id'] ?>"><?= $tdE ?: '-' ?></td>
        <td style="text-align:center">-</td>
        <td class="no-print" style="text-align:center;padding:3px 4px;white-space:nowrap">
          <button type="button"
                  onclick="ouvrirModalPreuve(<?= (int)$fiche['id'] ?>, '<?= $e(addslashes($fiche['cours'])) ?>')"
                  style="background:var(--ujkz-vert,#2d6a2d);color:#fff;border:none;border-radius:4px;
                         padding:3px 8px;font-size:10px;cursor:pointer;margin-bottom:3px;display:block;width:100%">
            📎 Ajouter preuve
          </button>
          <button type="button"
                  onclick="voirPreuves(<?= (int)$fiche['id'] ?>)"
                  style="background:#3a5fa0;color:#fff;border:none;border-radius:4px;
                         padding:3px 8px;font-size:10px;cursor:pointer;display:block;width:100%">
            🗂️ Voir preuves <span id="nb-preuves-<?= (int)$fiche['id'] ?>"
              style="background:rgba(255,255,255,.3);border-radius:10px;padding:0 5px">
              <?= count($preuves) ?>
            </span>
          </button>
        </td>
      </tr>

      <!-- Total -->
      <tr class="fs-tot">
        <td colspan="5">TOTAL<sup>3</sup></td>
        <td><?= $cmC ?: '' ?></td>
        <td><?= $tdC ?: '' ?></td>
        <td><?= $tpC ?: '' ?></td>
        <td id="tot-cm-eff"><?= $cmE ?: '' ?></td>
        <td id="tot-td-eff"><?= $tdE ?: '' ?></td>
        <td></td>
        <td class="no-print"></td>
      </tr>
    </tbody>
  </table>

  <!-- Signatures -->
  <div style="margin-top:18px">
    <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:6px">
      <div style="font-size:9.5pt;font-weight:600">Ouagadougou, le <?= $dateImpression ?></div>
      <div style="font-size:9pt">Vu et approuvé par</div>
    </div>
    <table class="fs-sig">
      <tr>
        <!-- Enseignant en premier -->
        <td>
          <div class="fs-sig-titre">L'enseignant</div>
          <div class="fs-sig-line"></div>
          <div style="text-align:center;font-size:8pt;color:#666">Signature</div>
        </td>
        <?php foreach ($sigActors as $actor):
          $v    = $valMap[$actor['role']] ?? null;
          $dec  = $v['decision'] ?? '';
          $nomV = $v['valideur_nom'] ?? '';
          $dateV= !empty($v['created_at']) ? date('d/m/Y', strtotime($v['created_at'])) : '';
        ?>
        <td>
          <div class="fs-sig-titre"><?= $e($actor['titre']) ?></div>
          <?php if ($dec === 'valide'): ?>
            <div style="color:#1a6b1a;font-size:9pt;text-align:center">
              ✔ Validé par <strong><?= $e($nomV) ?></strong><br>
              <span style="font-size:8.5pt">Le <?= $e($dateV) ?></span>
            </div>
          <?php elseif ($dec === 'rejete'): ?>
            <div style="color:#b00;font-size:9pt;text-align:center">
              ✖ Rejeté — <?= $e($dateV) ?>
            </div>
          <?php else: ?>
            <div class="fs-sig-line"></div>
            <div style="text-align:center;font-size:8pt;color:#666">Signature &amp; cachet</div>
          <?php endif; ?>
        </td>
        <?php endforeach; ?>
      </tr>
    </table>
  </div>

  <!-- Notes officielles -->
  <div style="margin-top:14px;font-size:7.5pt;border-top:1px solid #aaa;padding-top:4px;color:#555;line-height:1.6">
    <sup>1</sup> Cochez le semestre d'activité.<br>
    <sup>2</sup> Établir une fiche de suivi par établissement (CUP, UFR ou Institut) où intervient l'enseignant.<br>
    <sup>3</sup> Calculer le volume horaire sans convertir les TD et les TP en heures de cours théoriques.<br>
    NTC = nombre total de crédits.
    Imprimé le <?= $dateImpression ?>.
  </div>

</div><!-- /fs-wrapper -->
<?php endif; ?>

<?php // ── Modal upload + affichage preuves (no-print) ─────────── ?>
<style>
.modal-overlay {
  display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);
  z-index:9000;align-items:center;justify-content:center;
}
.modal-overlay.open { display:flex; }
.modal-box {
  background:#fff;border-radius:10px;padding:24px 28px;width:min(500px,95vw);
  max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,.25);
  position:relative;
}
.modal-box h3 { margin:0 0 14px;font-size:14pt;color:#1a3a1a }
.modal-close {
  position:absolute;top:12px;right:16px;background:none;border:none;
  font-size:18px;cursor:pointer;color:#666;
}
.preuve-card {
  display:flex;align-items:center;gap:10px;padding:8px 10px;
  border:1px solid #ddd;border-radius:6px;margin-bottom:8px;background:#fafafa;
}
.preuve-card .preuve-info { flex:1;min-width:0 }
.preuve-card .preuve-info strong { display:block;font-size:12px;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis }
.preuve-card .preuve-info span { font-size:11px;color:#777 }
.btn-sm-red { background:#fff;border:1px solid #c00;color:#c00;border-radius:4px;
  padding:3px 8px;font-size:11px;cursor:pointer }
.btn-sm-green { background:var(--ujkz-vert,#2d6a2d);border:none;color:#fff;
  border-radius:4px;padding:4px 12px;font-size:12px;cursor:pointer }
.upload-zone { border:2px dashed #ccc;border-radius:8px;padding:16px;
  text-align:center;margin-bottom:12px;cursor:pointer;transition:.2s }
.upload-zone:hover,.upload-zone.drag { border-color:var(--ujkz-vert,#2d6a2d);background:#f0faf0 }
.upload-zone input[type=file] { display:none }
.form-row { display:flex;gap:10px;margin-bottom:10px }
.form-row label { font-size:12px;font-weight:600;display:block;margin-bottom:3px }
.form-row input { width:100%;border:1px solid #ccc;border-radius:4px;
  padding:5px 8px;font-size:13px }
.progress-bar { height:6px;background:#e5e5e5;border-radius:3px;margin-bottom:10px;display:none }
.progress-bar div { height:100%;background:var(--ujkz-vert,#2d6a2d);
  border-radius:3px;width:0;transition:width .3s }
@media print { .modal-overlay,.no-print { display:none!important } }
</style>

<!-- Modal : Ajouter une preuve -->
<div class="modal-overlay" id="modal-upload">
  <div class="modal-box">
    <button class="modal-close" onclick="fermerModal('modal-upload')">✕</button>
    <h3>📎 Ajouter une preuve</h3>
    <p id="modal-cours-nom" style="font-size:12px;color:#555;margin-bottom:14px"></p>

    <div class="upload-zone" id="upload-zone" onclick="document.getElementById('fichier-preuve').click()">
      <input type="file" id="fichier-preuve" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp"
             onchange="fichierChoisi(this)">
      <div id="upload-zone-text">
        <span style="font-size:24px">📄</span><br>
        <strong>Cliquez ou glissez un fichier ici</strong><br>
        <span style="font-size:11px;color:#888">PDF, Word, Image — max 10 Mo</span>
      </div>
    </div>

    <div class="progress-bar" id="upload-progress"><div id="upload-progress-fill"></div></div>

    <div class="form-row">
      <div style="flex:1">
        <label>Volume CT effectué (h)</label>
        <input type="number" id="vol-cm-eff" min="0" max="999" placeholder="0">
      </div>
      <div style="flex:1">
        <label>Volume TD effectué (h)</label>
        <input type="number" id="vol-td-eff" min="0" max="999" placeholder="0">
      </div>
    </div>
    <div style="margin-bottom:12px">
      <label style="font-size:12px;font-weight:600;display:block;margin-bottom:3px">
        Commentaire (mois / semaines d'exécution)
      </label>
      <input type="text" id="commentaire-preuve" maxlength="500" placeholder="Ex : Octobre–Décembre, sem. 1–14"
             style="width:100%;border:1px solid #ccc;border-radius:4px;padding:5px 8px;font-size:13px">
    </div>

    <div id="upload-msg" style="margin-bottom:10px;font-size:12px"></div>

    <div style="display:flex;justify-content:flex-end;gap:10px">
      <button onclick="fermerModal('modal-upload')"
              style="background:#f0f0f0;border:1px solid #ccc;border-radius:4px;
                     padding:6px 16px;cursor:pointer;font-size:13px">
        Annuler
      </button>
      <button class="btn-sm-green" id="btn-upload-submit" onclick="soumettreFichier()">
        📤 Enregistrer
      </button>
    </div>
  </div>
</div>

<!-- Modal : Voir les preuves -->
<div class="modal-overlay" id="modal-preuves">
  <div class="modal-box">
    <button class="modal-close" onclick="fermerModal('modal-preuves')">✕</button>
    <h3>🗂️ Preuves enregistrées</h3>
    <div id="liste-preuves" style="min-height:60px"></div>
    <div style="text-align:right;margin-top:12px">
      <button onclick="fermerModal('modal-preuves');ouvrirModalPreuve(currentFicheId, currentCoursNom)"
              class="btn-sm-green" style="font-size:12px">
        + Ajouter une preuve
      </button>
    </div>
  </div>
</div>

<script>
var currentFicheId   = 0;
var currentCoursNom  = '';
var csrfTokenSuivi   = '<?= Security::e($csrfToken ?? '') ?>';
var tokenSuivi       = '<?= Security::e($tok) ?>';
var uploadUrl        = 'upload_preuve.php';
var voirPreuveUrl    = 'voir_preuve.php';
var supprimerUrl     = 'upload_preuve.php';

function ouvrirModalPreuve(ficheId, coursNom) {
    currentFicheId  = ficheId;
    currentCoursNom = coursNom;
    document.getElementById('modal-cours-nom').textContent = coursNom;
    document.getElementById('fichier-preuve').value = '';
    document.getElementById('upload-zone-text').innerHTML =
        '<span style="font-size:24px">📄</span><br>' +
        '<strong>Cliquez ou glissez un fichier ici</strong><br>' +
        '<span style="font-size:11px;color:#888">PDF, Word, Image — max 10 Mo</span>';
    document.getElementById('vol-cm-eff').value = '';
    document.getElementById('vol-td-eff').value = '';
    document.getElementById('commentaire-preuve').value = '';
    document.getElementById('upload-msg').textContent = '';
    document.getElementById('upload-progress').style.display = 'none';
    document.getElementById('upload-progress-fill').style.width = '0';
    document.getElementById('modal-upload').classList.add('open');
}

function voirPreuves(ficheId) {
    currentFicheId = ficheId;
    document.getElementById('liste-preuves').innerHTML =
        '<div style="text-align:center;padding:16px;color:#888">Chargement…</div>';
    document.getElementById('modal-preuves').classList.add('open');

    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'voir_preuve.php?ajax=1&fiche_id=' + ficheId + '&token=' + encodeURIComponent(tokenSuivi));
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                var data = JSON.parse(xhr.responseText);
                afficherListePreuves(data.preuves || []);
            } catch(e) {
                document.getElementById('liste-preuves').innerHTML =
                    '<div style="color:#c00">Erreur de chargement.</div>';
            }
        }
    };
    xhr.send();
}

function afficherListePreuves(preuves) {
    var el = document.getElementById('liste-preuves');
    if (!preuves.length) {
        el.innerHTML = '<div style="text-align:center;padding:20px;color:#888">' +
            'Aucune preuve enregistrée pour ce cours.</div>';
        return;
    }
    var html = '';
    for (var i = 0; i < preuves.length; i++) {
        var p = preuves[i];
        var icon = p.mime && p.mime.indexOf('pdf') >= 0 ? '📄' :
                   p.mime && p.mime.indexOf('image') >= 0 ? '🖼️' : '📎';
        var vols = [];
        if (p.volume_cm_effectue) vols.push('CT : ' + p.volume_cm_effectue + 'h');
        if (p.volume_td_effectue) vols.push('TD : ' + p.volume_td_effectue + 'h');
        var volStr = vols.length ? vols.join(' · ') : '';
        html += '<div class="preuve-card">' +
            '<span style="font-size:22px">' + icon + '</span>' +
            '<div class="preuve-info">' +
                '<strong>' + htmlEsc(p.nom_original) + '</strong>' +
                '<span>' + (volStr ? volStr + ' · ' : '') + (p.commentaire ? htmlEsc(p.commentaire) : '') + '</span>' +
            '</div>' +
            '<div style="display:flex;flex-direction:column;gap:4px">' +
                '<a href="voir_preuve.php?id=' + p.id + '&token=' + encodeURIComponent(tokenSuivi) +
                   '" target="_blank" style="background:#e8f5ee;border:1px solid var(--ujkz-vert,#2d6a2d);' +
                   'color:var(--ujkz-vert,#2d6a2d);border-radius:4px;padding:2px 8px;font-size:11px;' +
                   'text-decoration:none;text-align:center">👁 Voir</a>' +
                '<button onclick="supprimerPreuve(' + p.id + ',' + currentFicheId + ')" ' +
                    'class="btn-sm-red">🗑 Suppr.</button>' +
            '</div>' +
            '</div>';
    }
    el.innerHTML = html;
}

function fichierChoisi(input) {
    if (input.files && input.files[0]) {
        var name = input.files[0].name;
        document.getElementById('upload-zone-text').innerHTML =
            '<span style="font-size:24px">✅</span><br>' +
            '<strong>' + htmlEsc(name) + '</strong><br>' +
            '<span style="font-size:11px;color:#888">Cliquez pour changer</span>';
    }
}

// Drag & drop
var uz = document.getElementById('upload-zone');
if (uz) {
    uz.addEventListener('dragover', function(e) {
        e.preventDefault(); uz.classList.add('drag');
    });
    uz.addEventListener('dragleave', function() { uz.classList.remove('drag'); });
    uz.addEventListener('drop', function(e) {
        e.preventDefault(); uz.classList.remove('drag');
        var dt = e.dataTransfer;
        if (dt && dt.files.length) {
            document.getElementById('fichier-preuve').files = dt.files;
            fichierChoisi(document.getElementById('fichier-preuve'));
        }
    });
}

function soumettreFichier() {
    var fileInput = document.getElementById('fichier-preuve');
    if (!fileInput.files || !fileInput.files[0]) {
        document.getElementById('upload-msg').innerHTML =
            '<span style="color:#c00">⚠ Veuillez choisir un fichier.</span>';
        return;
    }
    var fd = new FormData();
    fd.append('preuve',               fileInput.files[0]);
    fd.append('fiche_id',             currentFicheId);
    fd.append('token',                tokenSuivi);
    fd.append('csrf_token',           csrfTokenSuivi);
    fd.append('volume_cm_effectue',   document.getElementById('vol-cm-eff').value);
    fd.append('volume_td_effectue',   document.getElementById('vol-td-eff').value);
    fd.append('commentaire',          document.getElementById('commentaire-preuve').value);
    fd.append('source',               'suivi'); // retour vers suivi

    var btn  = document.getElementById('btn-upload-submit');
    var prog = document.getElementById('upload-progress');
    var fill = document.getElementById('upload-progress-fill');
    var msg  = document.getElementById('upload-msg');
    btn.disabled = true;
    prog.style.display = 'block';
    msg.textContent = '';

    var xhr = new XMLHttpRequest();
    xhr.upload.onprogress = function(e) {
        if (e.lengthComputable) {
            fill.style.width = Math.round(e.loaded / e.total * 100) + '%';
        }
    };
    xhr.open('POST', uploadUrl);
    xhr.onload = function() {
        btn.disabled = false;
        if (xhr.status === 200 || xhr.status === 302) {
            try {
                var resp = JSON.parse(xhr.responseText);
                if (resp.ok) {
                    msg.innerHTML = '<span style="color:green">✓ Preuve enregistrée !</span>';
                    // Mettre à jour le compteur
                    var nbEl = document.getElementById('nb-preuves-' + currentFicheId);
                    if (nbEl) nbEl.textContent = parseInt(nbEl.textContent || '0') + 1;
                    // Mettre à jour les volumes effectués affichés
                    if (resp.cmEff !== undefined) {
                        var cmEl = document.getElementById('cm-eff-' + currentFicheId);
                        var tdEl = document.getElementById('td-eff-' + currentFicheId);
                        var tcEl = document.getElementById('tot-cm-eff');
                        var ttEl = document.getElementById('tot-td-eff');
                        if (cmEl) cmEl.textContent = resp.cmEff || '-';
                        if (tdEl) tdEl.textContent = resp.tdEff || '-';
                        if (tcEl) tcEl.textContent = resp.cmEff || '';
                        if (ttEl) ttEl.textContent = resp.tdEff || '';
                    }
                    setTimeout(function() { fermerModal('modal-upload'); }, 1200);
                } else {
                    msg.innerHTML = '<span style="color:#c00">✗ ' + htmlEsc(resp.error || 'Erreur') + '</span>';
                }
            } catch(e) {
                // Réponse non-JSON (redirect normal) — succès présumé
                msg.innerHTML = '<span style="color:green">✓ Preuve enregistrée !</span>';
                var nbEl = document.getElementById('nb-preuves-' + currentFicheId);
                if (nbEl) nbEl.textContent = parseInt(nbEl.textContent || '0') + 1;
                setTimeout(function() { fermerModal('modal-upload'); }, 1200);
            }
        } else {
            msg.innerHTML = '<span style="color:#c00">✗ Erreur serveur (' + xhr.status + ')</span>';
        }
    };
    xhr.onerror = function() {
        btn.disabled = false;
        msg.innerHTML = '<span style="color:#c00">✗ Erreur réseau.</span>';
    };
    xhr.send(fd);
}

function supprimerPreuve(preuveId, ficheId) {
    if (!confirm('Supprimer cette preuve ?')) return;
    var fd = new FormData();
    fd.append('action',     'supprimer');
    fd.append('preuve_id',  preuveId);
    fd.append('fiche_id',   ficheId);
    fd.append('token',      tokenSuivi);
    fd.append('csrf_token', csrfTokenSuivi);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', supprimerUrl);
    xhr.onload = function() {
        voirPreuves(ficheId); // Rafraîchir la liste
        // Décrémenter compteur
        var nbEl = document.getElementById('nb-preuves-' + ficheId);
        if (nbEl) { var n = parseInt(nbEl.textContent||'0')-1; nbEl.textContent = n < 0 ? 0 : n; }
    };
    xhr.send(fd);
}

function fermerModal(id) {
    document.getElementById(id).classList.remove('open');
}

function htmlEsc(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Fermer en cliquant sur l'overlay
document.querySelectorAll('.modal-overlay').forEach(function(el) {
    el.addEventListener('click', function(e) {
        if (e.target === el) fermerModal(el.id);
    });
});
</script>
