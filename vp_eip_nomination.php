<?php
// ============================================================
// vp_eip_nomination.php — Validation nomination par VP EIP
// + Génération de l'acte de nomination en PDF
// ============================================================
declare(strict_types=1);
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';
if (!class_exists('App')) require_once __DIR__ . '/src/App.php';
App::configureErrorDisplay();
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/FicheRepository.php';
require_once __DIR__ . '/src/ValidationRepository.php';

$config   = require __DIR__ . '/config/security.php';
$security = new Security($config);
$security->sendSecurityHeaders();
$security->startSecureSession();

if (!Auth::check()) { header('Location: login.php'); exit; }
if (!in_array(Auth::userRole(), ['vp_eip','dei'], true)) {
    header('Location: portail.php?error=acces_refuse'); exit;
}

$pdo       = Database::getInstance();
$repo      = new FicheRepository();
$valRepo   = new ValidationRepository();
$csrfToken = $security->generateCsrfToken();
$role      = Auth::userRole();
$e         = fn($v) => Security::e((string)$v);
$msg       = '';
$msgType   = 'success';

// ── Traitement POST ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$security->verifyCsrfToken($_POST['csrf_token'] ?? ''))
        die('Token CSRF invalide.');

    $action   = $_POST['action']   ?? '';
    $ensId    = (int)($_POST['ens_id']    ?? 0);
    $annee    = Security::sanitizeText($_POST['annee'] ?? $config['annee_academique'], 10);
    $decision = $_POST['decision'] ?? '';
    $motif    = Security::sanitizeText($_POST['motif_rejet'] ?? '', 500);

    if ($action === 'valider_nomination' && $ensId > 0) {
        // Récupérer toutes les fiches VACATAIRE de cet enseignant
        [$sw, $sp] = Auth::ficheScope();
        $stFiches = $pdo->prepare(
            "SELECT * FROM fiches f
             WHERE f.enseignant_id=? AND f.annee_academique=?
               AND f.type_workflow='VACATAIRE' AND f.statut_dei='valide'
               AND f.statut_vp_eip='en_attente' $sw"
        );
        $stFiches->execute(array_merge([$ensId, $annee], $sp));
        $fiches = $stFiches->fetchAll();

        if (empty($fiches)) {
            $msg = 'Aucune fiche en attente de validation VP EIP.';
            $msgType = 'error';
        } else {
            foreach ($fiches as $fiche) {
                $ficheId = (int)$fiche['id'];
                if ($decision === 'valide') {
                    $pdo->prepare("UPDATE fiches SET statut_vp_eip='valide', statut='validee' WHERE id=?")
                        ->execute([$ficheId]);
                } else {
                    $pdo->prepare("UPDATE fiches SET statut_vp_eip='rejete', statut='rejetee' WHERE id=?")
                        ->execute([$ficheId]);
                }
                // Enregistrer dans validations_fiche
                $pdo->prepare(
                    "INSERT INTO validations_fiche (fiche_id, utilisateur_id, role, decision, motif_rejet)
                     VALUES (?, ?, 'vp_eip', ?, ?)"
                )->execute([$ficheId, Auth::userId(), $decision, $decision==='rejete' ? $motif : '']);
            }
            // Créer la nomination si validé
            if ($decision === 'valide') {
                // Vérifier si une nomination existe déjà
                $stNom = $pdo->prepare(
                    "SELECT id FROM nominations WHERE enseignant_id=? AND annee_academique=? LIMIT 1"
                );
                $stNom->execute([$ensId, $annee]);
                if (!$stNom->fetch()) {
                    $pdo->prepare(
                        "INSERT INTO nominations (enseignant_id, annee_academique, statut, valide_par, valide_le)
                         VALUES (?, ?, 'valide', ?, NOW())"
                    )->execute([$ensId, $annee, Auth::userId()]);
                }
                $msg = "Nomination validée. L'acte peut maintenant être généré.";
            } else {
                $msg = "Nomination rejetée.";
                $msgType = 'error';
            }
        }
    }

    header('Location: vp_eip_nomination.php?msg='.urlencode($msg).'&type='.$msgType);
    exit;
}

if (!$msg && !empty($_GET['msg'])) {
    $msg = Security::sanitizeText($_GET['msg'] ?? '', 300);
    $msgType = Security::sanitizeText($_GET['type'] ?? 'success', 10);
}

// ── Charger les enseignants vacataires en attente VP EIP ───
[$sw, $sp] = Auth::ficheScope();
$stEns = $pdo->prepare(
    "SELECT DISTINCT e.*, COUNT(f.id) AS nb_fiches
     FROM enseignants e
     JOIN fiches f ON f.enseignant_id = e.id
     WHERE f.type_workflow = 'VACATAIRE'
       AND f.statut_dei = 'valide'
       AND f.statut_vp_eip = 'en_attente'
       AND f.annee_academique = ?
       $sw
     GROUP BY e.id
     ORDER BY e.nom"
);
$stEns->execute(array_merge([$config['annee_academique']], $sp));
$ensAttente = $stEns->fetchAll();

// Enseignants dont la nomination a été validée (acte à générer)
$stNom = $pdo->prepare(
    "SELECT n.*, e.nom, e.prenom, e.matricule, e.grade, e.diplome,
            e.etab_rattachement, e.type_enseignant,
            e.fichier_diplome, e.fichier_nomination,
            COUNT(f.id) AS nb_cours
     FROM nominations n
     JOIN enseignants e ON e.id = n.enseignant_id
     LEFT JOIN fiches f ON f.enseignant_id = e.id
         AND f.annee_academique = n.annee_academique
         AND f.type_workflow = 'VACATAIRE'
     WHERE n.annee_academique = ? AND n.statut = 'valide'
     GROUP BY n.id
     ORDER BY n.valide_le DESC"
);
$stNom->execute([$config['annee_academique']]);
$nominations = $stNom->fetchAll();

// Rendu
ob_start();
?>

<div class="breadcrumb">
  <a href="portail.php">Portail</a>
  <span class="breadcrumb-sep">›</span>
  <span>Nominations Vacataires — VP EIP</span>
</div>

<div class="page-hero">
  <div>
    <h1>📋 Nominations Vacataires</h1>
    <div class="subtitle">Validation et génération des actes de nomination — VP EIP</div>
  </div>
  <div style="display:flex;gap:10px">
    <a href="portail.php" class="btn btn-gold">← Portail</a>
  </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType==='error'?'danger':'success' ?>"><?= $e($msg) ?></div>
<?php endif; ?>

<!-- ═══ EN ATTENTE DE VALIDATION ═══ -->
<div class="card">
  <div class="card-header">
    <div class="card-title">⏳ En attente de validation VP EIP</div>
    <span class="badge badge-or"><?= count($ensAttente) ?> enseignant(s)</span>
  </div>

  <?php if (empty($ensAttente)): ?>
  <div style="text-align:center;padding:2rem;color:var(--gray-400)">
    <div style="font-size:2rem;margin-bottom:.5rem">✅</div>
    <div>Aucune nomination en attente</div>
  </div>
  <?php else: ?>
  <?php foreach ($ensAttente as $ens):
    // Charger les fiches de cet enseignant
    $stF = $pdo->prepare("SELECT * FROM fiches WHERE enseignant_id=? AND annee_academique=? AND type_workflow='VACATAIRE' ORDER BY semestre");
    $stF->execute([$ens['id'], $config['annee_academique']]);
    $fichesEns = $stF->fetchAll();
  ?>
  <div style="border:1px solid var(--gray-200);border-radius:var(--radius-sm);
              padding:16px;margin-bottom:12px;background:var(--gray-50)">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
      <div>
        <div style="font-weight:700;font-size:14px;color:var(--ujkz-vert-dk)">
          <?= $e($ens['nom']) ?> <?= $e($ens['prenom']) ?>
        </div>
        <div style="font-size:12px;color:var(--gray-600);margin-top:2px">
          Grade : <?= $e($ens['grade']) ?> &nbsp;|&nbsp;
          Matricule : <?= $e($ens['matricule']) ?> &nbsp;|&nbsp;
          <?= (int)$ens['nb_fiches'] ?> cours
        </div>
        <!-- Documents justificatifs + bouton dossier -->
        <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          <a href="dossier_vacataire.php?ens_id=<?= $ens['id'] ?>&annee=<?= urlencode($config['annee_academique']) ?>"
             class="btn btn-sm btn-primary" style="font-weight:700">
            📁 Afficher le dossier
          </a>
          <?php if ($ens['fichier_diplome']): ?>
          <a href="uploads/<?= $e($ens['fichier_diplome']) ?>" target="_blank"
             class="btn btn-sm btn-outline-green">📄 Diplôme</a>
          <?php else: ?>
          <span class="badge badge-or">⚠️ Diplôme manquant</span>
          <?php endif; ?>
          <?php if ($ens['fichier_nomination']): ?>
          <a href="uploads/<?= $e($ens['fichier_nomination']) ?>" target="_blank"
             class="btn btn-sm" style="border-color:var(--info);color:var(--info)">📋 Ancienne nomination</a>
          <?php endif; ?>
        </div>
        <!-- Liste des cours -->
        <?php if (!empty($fichesEns)): ?>
        <div style="margin-top:10px">
          <table style="font-size:11px;border-collapse:collapse">
            <thead>
              <tr style="background:var(--gray-200)">
                <th style="padding:4px 8px;text-align:left">Cours</th>
                <th style="padding:4px 8px;text-align:left">Sem.</th>
                <th style="padding:4px 8px;text-align:left">Étab. bénéficiaire</th>
                <th style="padding:4px 8px;text-align:center">CT</th>
                <th style="padding:4px 8px;text-align:center">TD</th>
                <th style="padding:4px 8px;text-align:center">TP</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($fichesEns as $fi):
                $etabNomFi = '';
                if ((int)$fi['etab_beneficiaire_fiche'] > 0) {
                    $stEtF = $pdo->prepare("SELECT nom FROM etablissements WHERE id=?");
                    $stEtF->execute([(int)$fi['etab_beneficiaire_fiche']]);
                    $etabNomFi = $stEtF->fetchColumn() ?: '';
                }
              ?>
              <tr style="border-bottom:1px solid var(--gray-200)">
                <td style="padding:3px 8px"><?= $e($fi['cours']) ?></td>
                <td style="padding:3px 8px"><?= $e($fi['semestre']) ?></td>
                <td style="padding:3px 8px"><?= $e($etabNomFi) ?></td>
                <td style="padding:3px 8px;text-align:center"><?= (int)$fi['volume_cm'] ?>h</td>
                <td style="padding:3px 8px;text-align:center"><?= (int)$fi['volume_td'] ?>h</td>
                <td style="padding:3px 8px;text-align:center"><?= (int)$fi['volume_tp'] ?>h</td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

      <!-- Formulaire de décision -->
      <?php if ($role === 'vp_eip'): ?>
      <form method="POST" style="min-width:220px">
        <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
        <input type="hidden" name="action"     value="valider_nomination">
        <input type="hidden" name="ens_id"     value="<?= $ens['id'] ?>">
        <input type="hidden" name="annee"      value="<?= $e($config['annee_academique']) ?>">
        <div style="background:var(--white);border:1px solid var(--gray-200);border-radius:var(--radius-sm);padding:12px">
          <div style="font-size:12px;font-weight:600;color:var(--gray-600);margin-bottom:8px">Décision VP EIP</div>
          <div id="motif-box-<?= $ens['id'] ?>" style="display:none;margin-bottom:8px">
            <label style="font-size:11px;margin:0">Motif du rejet :</label>
            <textarea name="motif_rejet" rows="3" style="font-size:12px;margin-top:4px"
                      placeholder="Motif obligatoire…"></textarea>
          </div>
          <div style="display:flex;gap:6px">
            <button type="submit" name="decision" value="valide"
                    class="btn btn-sm btn-primary"
                    onclick="return confirm('Valider la nomination de <?= addslashes($e($ens['nom'])) ?> ?')">
              ✅ Valider
            </button>
            <button type="submit" name="decision" value="rejete"
                    class="btn btn-sm btn-danger"
                    onclick="document.getElementById('motif-box-<?= $ens['id'] ?>').style.display='block';return confirm('Rejeter la nomination ?')">
              ❌ Rejeter
            </button>
          </div>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- ═══ NOMINATIONS VALIDÉES — ACTES À GÉNÉRER ═══ -->
<?php if (!empty($nominations)): ?>
<div class="card">
  <div class="card-header">
    <div class="card-title">✅ Nominations validées</div>
    <span class="badge badge-green"><?= count($nominations) ?> acte(s)</span>
  </div>
  <table style="width:100%;border-collapse:collapse;font-size:13px">
    <thead>
      <tr style="background:var(--gray-50);border-bottom:2px solid var(--gray-200)">
        <th style="padding:10px 12px;text-align:left">Enseignant</th>
        <th style="padding:10px 12px;text-align:left">Grade</th>
        <th style="padding:10px 12px;text-align:center">Cours</th>
        <th style="padding:10px 12px;text-align:center">Validé le</th>
        <th style="padding:10px 12px;text-align:right">Acte</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($nominations as $nom): ?>
      <tr style="border-bottom:1px solid var(--gray-200)">
        <td style="padding:10px 12px;font-weight:600">
          <?= $e($nom['nom']) ?> <?= $e($nom['prenom']) ?>
          <div style="font-size:11px;font-weight:400;color:var(--gray-600)"><?= $e($nom['matricule']) ?></div>
        </td>
        <td style="padding:10px 12px"><?= $e($nom['grade']) ?></td>
        <td style="padding:10px 12px;text-align:center">
          <span class="badge badge-info"><?= (int)$nom['nb_cours'] ?></span>
        </td>
        <td style="padding:10px 12px;text-align:center;font-size:12px">
          <?= $nom['valide_le'] ? date('d/m/Y', strtotime($nom['valide_le'])) : '—' ?>
        </td>
        <td style="padding:10px 12px;text-align:right">
          <div style="display:flex;gap:6px;justify-content:flex-end">
            <a href="dossier_vacataire.php?ens_id=<?= $nom['enseignant_id'] ?>&annee=<?= urlencode($nom['annee_academique']) ?>"
               class="btn btn-sm btn-primary">📁 Dossier</a>
            <a href="generer_nomination.php?ens_id=<?= $nom['enseignant_id'] ?>&annee=<?= urlencode($nom['annee_academique']) ?>"
               target="_blank" class="btn btn-sm btn-gold">📄 Acte PDF</a>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php
$bodyContent = ob_get_clean();
$title = 'Nominations Vacataires — VP EIP';
ob_start();
require __DIR__ . '/templates/layout.php';
echo ob_get_clean();
