<?php
// ============================================================
// dossier_vacataire.php — Dossier complet d'un vacataire
// Fiche programmatique + Diplôme/Nomination + Acte de nomination
// Accès : DEI et VP EIP
// ============================================================
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

// Vérification souple : user_id OU user_role présent en session (compatible avec timeout)
$roleUser = Auth::userRole();
$userId   = Auth::userId();
if (!$userId && empty($roleUser)) {
    header('Location: login.php'); exit;
}
if (!in_array($roleUser, ['vp_eip','dei','directeur','directeur_adjoint','chef_dept'], true)) {
    header('Location: portail.php?error=acces_refuse'); exit;
}
// Renouveler la session si user_id absent mais role présent (timeout récent)
if (!$userId) {
    // Récupérer user_id depuis la BD via le rôle et le nom
    $pdo2 = Database::getInstance();
    $stU  = $pdo2->prepare("SELECT id FROM utilisateurs WHERE role=? AND actif=1 LIMIT 1");
    $stU->execute([$roleUser]);
    $uRow = $stU->fetch();
    if ($uRow) $_SESSION['user_id'] = $uRow['id'];
}
$_SESSION['user_since'] = time(); // renouveler le timeout

$pdo   = Database::getInstance();
$ensId = (int)($_GET['ens_id'] ?? 0);
$annee = Security::sanitizeText($_GET['annee'] ?? $config['annee_academique'], 10);
$role  = Auth::userRole();
$e     = fn($v) => Security::e((string)$v);

if (!$ensId) { header('Location: portail.php'); exit; }

// Charger enseignant
$ens = $pdo->prepare("SELECT * FROM enseignants WHERE id=? LIMIT 1");
$ens->execute([$ensId]);
$ens = $ens->fetch();
if (!$ens) {
    header('Location: portail.php?error=enseignant_introuvable'); exit;
}

// Charger toutes les fiches VACATAIRE
$stF = $pdo->prepare(
    "SELECT f.*, et.nom AS etab_nom, et.sigle AS etab_sigle,
            d.nom AS dept_nom, d.sigle AS dept_sigle
     FROM fiches f
     LEFT JOIN etablissements et ON et.id = f.etab_beneficiaire_fiche
     LEFT JOIN departements d ON d.id = f.dept_beneficiaire_fiche
     WHERE f.enseignant_id=? AND f.annee_academique=?
       AND f.type_workflow='VACATAIRE'
       AND f.is_encadrement = 0
     ORDER BY f.semestre, f.cours"
);
$stF->execute([$ensId, $annee]);
$fiches = $stF->fetchAll();

// Charger historique des validations
$valRepo = new ValidationRepository();
$ficheIds = array_column($fiches, 'id');
$historiques = [];
foreach ($ficheIds as $fid) {
    $historiques[$fid] = $valRepo->getHistorique((int)$fid);
}

// Charger nomination
$stNom = $pdo->prepare(
    "SELECT n.*, u.nom AS vp_nom
     FROM nominations n
     LEFT JOIN utilisateurs u ON u.id = n.valide_par
     WHERE n.enseignant_id=? AND n.annee_academique=? LIMIT 1"
);
$stNom->execute([$ensId, $annee]);
$nomination = $stNom->fetch();

// Statuts
$statutGlobal = $fiches[0]['statut'] ?? 'en_attente';
$statutVpEip  = $fiches[0]['statut_vp_eip'] ?? 'non_requis';
$totCm = array_sum(array_column($fiches, 'volume_cm'));
$totTd = array_sum(array_column($fiches, 'volume_td'));
$totTp = array_sum(array_column($fiches, 'volume_tp'));

// Acteurs de validation pour la fiche
$sigActors = [
    ['role'=>'chef_dept',         'col'=>'statut_chef',    'titre'=>'Chef de Département'],
    ['role'=>'directeur_adjoint', 'col'=>'statut_dir_adj', 'titre'=>'Directeur Adjoint'],
    ['role'=>'directeur',         'col'=>'statut_dir',     'titre'=>'Directeur'],
    ['role'=>'dei',               'col'=>'statut_dei',     'titre'=>'DEI'],
];

// Map global des validations
$globalValMap = [];
foreach ($historiques as $hist) {
    foreach ($hist as $h) {
        $r = $h['etape_role'] ?? $h['role'] ?? '';
        if ($r && (!isset($globalValMap[$r]) || ($h['decision'] ?? '') === 'valide')) {
            $globalValMap[$r] = $h;
        }
    }
}

$badgeCls = ['valide'=>'badge-green','en_attente'=>'badge-or','rejete'=>'badge-red','non_requis'=>'badge-gray'];

ob_start();
?>

<div class="breadcrumb">
  <a href="portail.php">Portail</a>
  <span class="breadcrumb-sep">›</span>
  <?php if ($role === 'vp_eip'): ?>
  <a href="vp_eip_nomination.php">Nominations</a>
  <?php else: ?>
  <a href="vp_eip_nomination.php">Nominations</a>
  <?php endif; ?>
  <span class="breadcrumb-sep">›</span>
  <span>Dossier — <?= $e($ens['nom']) ?> <?= $e($ens['prenom']) ?></span>
</div>

<div class="page-hero">
  <div>
    <h1>📁 Dossier Vacataire</h1>
    <div class="subtitle">
      <?= $e(strtoupper($ens['nom'])) ?> <?= $e($ens['prenom']) ?> —
      <?= $e($ens['grade']) ?> — Année <?= $e($annee) ?>
    </div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($nomination && $nomination['statut'] === 'valide'): ?>
    <a href="generer_nomination.php?ens_id=<?= $ensId ?>&annee=<?= urlencode($annee) ?>"
       target="_blank" class="btn btn-gold">📄 Acte de nomination</a>
    <?php endif; ?>
    <a href="<?= $role === 'vp_eip' ? 'vp_eip_nomination.php' : 'portail.php' ?>" class="btn">← Retour</a>
  </div>
</div>

<!-- ══ FICHE D'IDENTITÉ ══ -->
<div class="card">
  <div class="card-header">
    <div class="card-title">👤 Identité de l'enseignant</div>
    <span class="badge <?= $e($badgeCls[$statutGlobal] ?? 'badge-gray') ?>">
      <?= $statutGlobal === 'validee' ? 'Fiche validée ✓' :
         ($statutGlobal === 'rejetee' ? 'Rejetée' : 'En cours') ?>
    </span>
  </div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:13px;padding:4px 0">
    <div><span style="color:var(--gray-500);font-size:11px">Nom complet</span><br>
      <strong><?= $e(strtoupper($ens['nom'])) ?> <?= $e($ens['prenom']) ?></strong></div>
    <div><span style="color:var(--gray-500);font-size:11px">Matricule</span><br>
      <strong><?= $e($ens['matricule']) ?></strong></div>
    <div><span style="color:var(--gray-500);font-size:11px">Grade / Titre</span><br>
      <?= $e($ens['grade']) ?: '—' ?></div>
    <div><span style="color:var(--gray-500);font-size:11px">Diplôme</span><br>
      <?= $e($ens['diplome']) ?: '—' ?></div>
    <div><span style="color:var(--gray-500);font-size:11px">Email</span><br>
      <?= $e($ens['email']) ?: '—' ?></div>
    <div><span style="color:var(--gray-500);font-size:11px">IESR de rattachement</span><br>
      <?= $e($ens['etab_rattachement']) ?: '—' ?></div>
  </div>
</div>

<!-- ══ DOCUMENTS JUSTIFICATIFS ══ -->
<div class="card">
  <div class="card-header">
    <div class="card-title">📎 Documents justificatifs</div>
  </div>
  <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;padding:4px 0">
    <?php if ($ens['fichier_diplome']): ?>
    <a href="uploads/<?= $e($ens['fichier_diplome']) ?>" target="_blank"
       class="btn btn-primary" style="display:flex;align-items:center;gap:6px">
      📄 Diplôme / Titre
    </a>
    <?php else: ?>
    <div style="display:flex;align-items:center;gap:8px;font-size:13px">
      <span style="font-size:20px;opacity:.4">📄</span>
      <div>
        <div style="font-weight:600;color:var(--gray-700)">Diplôme</div>
        <div style="font-size:11px;color:var(--gray-500)">
          Non chargé — l'enseignant doit mettre à jour sa fiche
        </div>
      </div>
      <span class="badge badge-or">⚠️ Manquant</span>
    </div>
    <?php endif; ?>

    <?php if ($ens['fichier_nomination']): ?>
    <a href="uploads/<?= $e($ens['fichier_nomination']) ?>" target="_blank"
       class="btn btn-outline-green" style="display:flex;align-items:center;gap:6px">
      📋 Ancienne nomination
    </a>
    <?php else: ?>
    <div style="display:flex;align-items:center;gap:8px;font-size:13px">
      <span style="font-size:20px;opacity:.4">📋</span>
      <div>
        <div style="font-weight:600;color:var(--gray-700)">Ancienne nomination</div>
        <div style="font-size:11px;color:var(--gray-500)">Non chargée</div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($nomination && $nomination['statut'] === 'valide'): ?>
    <a href="generer_nomination.php?ens_id=<?= $ensId ?>&annee=<?= urlencode($annee) ?>"
       target="_blank" class="btn btn-gold" style="display:flex;align-items:center;gap:6px">
      📜 Acte de nomination
      <span style="font-size:10px;font-weight:400">(validé le
        <?= $nomination['valide_le'] ? date('d/m/Y', strtotime($nomination['valide_le'])) : '—' ?>)
      </span>
    </a>
    <?php elseif ($nomination): ?>
    <div style="color:var(--gray-600);font-size:13px">
      <span class="badge badge-or">⏳ Nomination en attente VP EIP</span>
    </div>
    <?php else: ?>
    <div style="color:var(--gray-400);font-size:13px">
      <span class="badge badge-gray">Acte non généré</span>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ══ FICHE PROGRAMMATIQUE ══ -->
<div class="card">
  <div class="card-header">
    <div class="card-title">📊 Fiche programmatique validée</div>
    <div style="display:flex;gap:6px">
      <span class="badge badge-info"><?= count($fiches) ?> cours</span>
      <span class="badge badge-info">CT : <?= $totCm ?>h</span>
      <span class="badge badge-info">TD : <?= $totTd ?>h</span>
      <?php if ($totTp): ?><span class="badge badge-info">TP : <?= $totTp ?>h</span><?php endif; ?>
    </div>
  </div>

  <!-- Tableau des cours -->
  <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:12px">
      <thead>
        <tr style="background:var(--ujkz-vert);color:#fff">
          <th style="padding:8px 10px;text-align:left">Cours</th>
          <th style="padding:8px 10px;text-align:left">Établissement bénéficiaire</th>
          <th style="padding:8px 10px;text-align:left">Département</th>
          <th style="padding:8px 10px;text-align:center">Sem.</th>
          <th style="padding:8px 10px;text-align:center">CT</th>
          <th style="padding:8px 10px;text-align:center">TD</th>
          <th style="padding:8px 10px;text-align:center">TP</th>
          <th style="padding:8px 10px;text-align:center">Statut</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($fiches as $i => $f): ?>
        <tr style="background:<?= $i%2===0?'#fff':'var(--gray-50)' ?>;border-bottom:1px solid var(--gray-200)">
          <td style="padding:7px 10px;font-weight:600"><?= $e($f['cours']) ?>
            <?php if ($f['code']): ?><br><span style="font-size:10px;color:var(--gray-400)"><?= $e($f['code']) ?></span><?php endif; ?>
          </td>
          <td style="padding:7px 10px"><?= $e($f['etab_sigle'] ?: $f['etab_nom'] ?: '—') ?></td>
          <td style="padding:7px 10px"><?= $e($f['dept_sigle'] ? $f['dept_nom'].' ('.$f['dept_sigle'].')' : ($f['dept_nom'] ?: '—')) ?></td>
          <td style="padding:7px 10px;text-align:center"><?= $e($f['semestre']) ?></td>
          <td style="padding:7px 10px;text-align:center"><?= (int)$f['volume_cm'] ?>h</td>
          <td style="padding:7px 10px;text-align:center"><?= (int)$f['volume_td'] ?>h</td>
          <td style="padding:7px 10px;text-align:center"><?= (int)$f['volume_tp'] ?>h</td>
          <td style="padding:7px 10px;text-align:center">
            <span class="badge <?= $e($badgeCls[$f['statut']] ?? 'badge-gray') ?>" style="font-size:10px">
              <?= $f['statut'] === 'validee' ? '✓' : ($f['statut'] === 'rejetee' ? '✕' : '⏳') ?>
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="background:var(--gray-100);font-weight:700">
          <td colspan="4" style="padding:7px 10px;text-align:right">TOTAL</td>
          <td style="padding:7px 10px;text-align:center"><?= $totCm ?>h</td>
          <td style="padding:7px 10px;text-align:center"><?= $totTd ?>h</td>
          <td style="padding:7px 10px;text-align:center"><?= $totTp ?>h</td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <!-- Circuit de validation -->
  <div style="margin-top:16px;padding-top:12px;border-top:1px solid var(--gray-200)">
    <div style="font-size:11px;font-weight:700;color:var(--gray-500);margin-bottom:8px;text-transform:uppercase">
      Circuit de validation
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <?php foreach ($sigActors as $idx => $actor):
        $fiche0 = $fiches[0] ?? [];
        $stCol  = $fiche0[$actor['col']] ?? 'en_attente';
        $valH   = $globalValMap[$actor['role']] ?? null;
        $isLast = $idx === count($sigActors)-1;
      ?>
      <div style="text-align:center;min-width:110px">
        <div class="badge <?= $badgeCls[$stCol] ?? 'badge-gray' ?>" style="display:block;margin-bottom:4px">
          <?= $stCol === 'valide' ? '✓ Validé' : ($stCol === 'rejete' ? '✕ Rejeté' : '⏳ En attente') ?>
        </div>
        <div style="font-size:10px;font-weight:700;color:var(--gray-700)"><?= $e($actor['titre']) ?></div>
        <?php if ($valH): ?>
        <div style="font-size:9px;color:var(--gray-500)"><?= $e($valH['valideur_nom'] ?? '') ?></div>
        <div style="font-size:9px;color:var(--gray-400)">
          <?= $valH['created_at'] ? date('d/m/Y', strtotime($valH['created_at'])) : '' ?>
        </div>
        <?php endif; ?>
      </div>
      <?php if (!$isLast): ?>
      <span style="color:var(--gray-300);font-size:18px">→</span>
      <?php endif; ?>
      <?php endforeach; ?>

      <!-- VP EIP -->
      <?php if ($ens['type_enseignant'] === 'vacataire'): ?>
      <span style="color:var(--gray-300);font-size:18px">→</span>
      <div style="text-align:center;min-width:110px">
        <?php $stVp = $fiches[0]['statut_vp_eip'] ?? 'non_requis'; ?>
        <div class="badge <?= $badgeCls[$stVp] ?? 'badge-gray' ?>" style="display:block;margin-bottom:4px">
          <?= $stVp === 'valide' ? '✓ Validé' : ($stVp === 'rejete' ? '✕ Rejeté' :
             ($stVp === 'en_attente' ? '⏳ En attente' : '—')) ?>
        </div>
        <div style="font-size:10px;font-weight:700;color:var(--gray-700)">VP EIP</div>
        <?php if (isset($globalValMap['vp_eip'])): ?>
        <div style="font-size:9px;color:var(--gray-500)"><?= $e($globalValMap['vp_eip']['valideur_nom'] ?? '') ?></div>
        <div style="font-size:9px;color:var(--gray-400)">
          <?= $globalValMap['vp_eip']['created_at'] ? date('d/m/Y', strtotime($globalValMap['vp_eip']['created_at'])) : '' ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
$bodyContent = ob_get_clean();
$title = 'Dossier Vacataire — ' . $ens['nom'] . ' ' . $ens['prenom'];
ob_start();
require __DIR__ . '/templates/layout.php';
echo ob_get_clean();
