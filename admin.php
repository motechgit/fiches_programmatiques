<?php
// ============================================================
// public/admin.php — Interface d'administration
//   Protégé par mot de passe (HTTP Basic Auth + secret PHP)
//   Accès : admin.php  →  login par formulaire POST sécurisé
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';
if (!class_exists('App')) require_once __DIR__ . '/src/App.php';
App::configureErrorDisplay();
require_once __DIR__ . '/src/FicheRepository.php';

$config   = require __DIR__ . '/config/security.php';
$security = new Security($config);

$security->sendSecurityHeaders();
$security->startSecureSession();
$csrfToken = $security->generateCsrfToken();

// ── Déconnexion ──────────────────────────────────────────────
if (isset($_GET['logout'])) {
    unset($_SESSION['admin_authenticated'], $_SESSION['admin_user'], $_SESSION['admin_since']);
    session_regenerate_id(true);
    header('Location: login.php');
    exit;
}

// ── Authentification : déléguer à login.php ──────────────────
$isAuth = !empty($_SESSION['admin_authenticated']);

// Expiration de session après 30 min d'inactivité
if ($isAuth && isset($_SESSION['admin_since'])) {
    if (time() - $_SESSION['admin_since'] > 1800) {
        unset($_SESSION['admin_authenticated'], $_SESSION['admin_user'], $_SESSION['admin_since']);
        $isAuth = false;
    } else {
        $_SESSION['admin_since'] = time();
    }
}

// ── Actions admin (si authentifié) ──────────────────────────
$message = '';
if ($isAuth && $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['admin_login'])) {

    if (!$security->verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Token CSRF invalide.');
    }

    $pdo      = Database::getInstance();
    $ficheId  = (int) ($_POST['fiche_id'] ?? 0);
    $action   = $_POST['action'] ?? '';
    $statuts  = ['valider' => 'validee', 'rejeter' => 'rejetee', 'remettre' => 'en_attente'];

    if ($ficheId > 0 && isset($statuts[$action])) {
        $newStatut = $statuts[$action];
        // updated_at est géré automatiquement par MySQL (ON UPDATE CURRENT_TIMESTAMP)
        $pdo->prepare(
            "UPDATE fiches SET statut = ? WHERE id = ?"
        )->execute([$newStatut, $ficheId]);

        $security->audit('admin_fiche_' . $action, null, "Fiche ID $ficheId → $newStatut");
        $message = "Fiche #$ficheId mise à jour : $newStatut.";
        // Invalider le CSRF et en générer un nouveau
        unset($_SESSION['csrf_token']);
        $csrfToken = $security->generateCsrfToken();
    }
}

// ── Formulaire de login ──────────────────────────────────────
if (!$isAuth) {
    header('Location: login.php');
    exit;
    echo renderLayout('Administration', $body, $csrfToken);
    exit;
}

// ── Export CSV (GET, admin authentifié) ──────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $pdoExp = Database::getInstance();
    $statutExp = Security::sanitizeText($_GET['statut'] ?? '', 20);
    $whereExp  = '';
    $paramsExp = [];
    $statutsValides = ['en_attente', 'validee', 'rejetee'];
    if (in_array($statutExp, $statutsValides, true)) {
        $whereExp  = 'WHERE f.statut = ?';
        $paramsExp = [$statutExp];
    }

    $stmtExp = $pdoExp->prepare(
        "SELECT f.id, e.matricule, e.nom, e.type_enseignant, e.grade,
                e.date_nomination, e.departement, e.email,
                e.etab_beneficiaire, e.etab_rattachement,
                e.volume_statutaire, e.abattement, e.motif_abattement, e.volume_apres_abatt,
                f.cours, f.code_ue, f.code, f.parcours, f.ntc,
                f.niveau, f.semestre, f.volume_cm, f.volume_td,
                (f.volume_cm + f.volume_td) AS volume_total,
                f.evaluation, f.objectifs, f.statut, f.annee_academique,
                f.submitted_at, f.modifie_le, f.nb_modifications
         FROM fiches f
         JOIN enseignants e ON e.id = f.enseignant_id
         $whereExp
         ORDER BY e.nom ASC, f.semestre ASC, f.submitted_at ASC"
    );
    $stmtExp->execute($paramsExp);
    $fichesExp = $stmtExp->fetchAll();

    $filename = 'fiches_' . ($statutExp ?: 'toutes') . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'ID','Matricule','Nom','Type','Grade','Date nomination',
        'Département','Email','Établ. bénéficiaire','Établ. rattachement',
        'Vol. stat.(h)','Abatt.(h)','Motif abatt.','Vol. après abatt.(h)',
        'UE/ECUE','Code UE','Code','Parcours','NTC',
        'Niveau','Semestre','CM(h)','TD/TP(h)','Total(h)',
        'Évaluation','Objectifs','Statut','Année acad.',
        'Date soumission','Date modification','Nb modifs'
    ], ';');
    foreach ($fichesExp as $r) {
        fputcsv($out, array_values($r), ';');
    }
    fclose($out);

    $security->audit('admin_export_csv', null, count($fichesExp) . ' fiches');
    exit;
}

// ── Dashboard admin ──────────────────────────────────────────
$pdo = Database::getInstance();

// Statistiques globales
$statsGlobal = $pdo->query(
    "SELECT
       COUNT(*) AS total,
       SUM(CASE WHEN statut='en_attente' THEN 1 ELSE 0 END) AS en_attente,
       SUM(CASE WHEN statut='validee'    THEN 1 ELSE 0 END) AS validee,
       SUM(CASE WHEN statut='rejetee'    THEN 1 ELSE 0 END) AS rejetee
     FROM fiches"
)->fetch();

// Volumes globaux validés
$volsGlobal = $pdo->query(
    "SELECT
       SUM(CASE WHEN statut='validee' THEN volume_cm ELSE 0 END) AS total_cm,
       SUM(CASE WHEN statut='validee' THEN volume_td ELSE 0 END) AS total_td
     FROM fiches"
)->fetch();

// Résumé par enseignant (fiches validées uniquement)
$ensStats = $pdo->query(
    "SELECT e.id, e.nom, e.matricule, e.grade, e.departement,
            e.type_enseignant, e.etab_beneficiaire, e.token,
            COUNT(f.id) AS nb_validee,
            SUM(f.volume_cm) AS total_cm,
            SUM(f.volume_td) AS total_td,
            e.volume_apres_abatt
     FROM enseignants e
     JOIN fiches f ON f.enseignant_id = e.id AND f.statut = 'validee'
     GROUP BY e.id
     ORDER BY e.nom ASC"
)->fetchAll();

// Stats CM/TD par département (toutes fiches soumises vs validées)
$statsByDept = $pdo->query(
    "SELECT e.departement,
            SUM(f.volume_cm) AS cm_soumis,
            SUM(f.volume_td) AS td_soumis,
            SUM(CASE WHEN f.statut='validee' THEN f.volume_cm ELSE 0 END) AS cm_valide,
            SUM(CASE WHEN f.statut='validee' THEN f.volume_td ELSE 0 END) AS td_valide,
            COUNT(*) AS nb_fiches
     FROM fiches f JOIN enseignants e ON e.id = f.enseignant_id
     GROUP BY e.departement ORDER BY e.departement ASC"
)->fetchAll();

// Stats CM/TD par établissement bénéficiaire
$statsByEtab = $pdo->query(
    "SELECT e.etab_beneficiaire,
            SUM(f.volume_cm) AS cm_soumis,
            SUM(f.volume_td) AS td_soumis,
            SUM(CASE WHEN f.statut='validee' THEN f.volume_cm ELSE 0 END) AS cm_valide,
            SUM(CASE WHEN f.statut='validee' THEN f.volume_td ELSE 0 END) AS td_valide,
            COUNT(*) AS nb_fiches
     FROM fiches f JOIN enseignants e ON e.id = f.enseignant_id
     GROUP BY e.etab_beneficiaire ORDER BY e.etab_beneficiaire ASC"
)->fetchAll();

// Filtre statut
$filtreStatut = $_GET['statut'] ?? 'en_attente';
$statutsValides = ['en_attente', 'validee', 'rejetee', 'tous'];
if (!in_array($filtreStatut, $statutsValides, true)) {
    $filtreStatut = 'en_attente';
}

// Pagination simple
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

// WHERE clause avec paramètre préparé (protection injection SQL)
$whereParams = [];
$whereClause = '';
if ($filtreStatut !== 'tous') {
    $whereClause = 'WHERE f.statut = ?';
    $whereParams[] = $filtreStatut;
}
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM fiches f $whereClause");
$countStmt->execute($whereParams);
$totalFiches = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalFiches / $perPage));

$fiches = $pdo->prepare(
    "SELECT f.*, e.nom AS ens_nom, e.matricule, e.departement, e.email
     FROM fiches f
     JOIN enseignants e ON e.id = f.enseignant_id
     $whereClause
     ORDER BY f.submitted_at DESC
     LIMIT ? OFFSET ?"
);
$fiches->execute(array_merge($whereParams, [$perPage, $offset]));
$fiches = $fiches->fetchAll();

ob_start();
?>
<div class="page-hero">
  <div>
    <h1>🗂 Administration DEI</h1>
    <div class="subtitle">Connecté : <strong><?= Security::e($_SESSION['admin_user'] ?? '') ?></strong></div>
  </div>
  <div class="btn-group">
    <a href="portail.php"            class="btn btn-sm" style="background:rgba(255,255,255,.15);color:#fff;border-color:rgba(255,255,255,.4)">Portail</a>
    <a href="admin_utilisateurs.php" class="btn btn-sm" style="background:rgba(255,255,255,.15);color:#fff;border-color:rgba(255,255,255,.4)">Utilisateurs</a>
    <a href="admin_enseignants.php"  class="btn btn-sm" style="background:rgba(255,255,255,.15);color:#fff;border-color:rgba(255,255,255,.4)">Enseignants</a>
    <a href="admin.php?export=csv"   class="btn btn-sm btn-gold">⬇ CSV</a>
    <a href="admin_audit.php"        class="btn btn-sm" style="background:rgba(255,255,255,.15);color:#fff;border-color:rgba(255,255,255,.4)">Audit</a>
    <a href="admin_password.php"     class="btn btn-sm" style="background:rgba(255,255,255,.15);color:#fff;border-color:rgba(255,255,255,.4)">🔑</a>
    <a href="admin.php?logout=1"     class="btn btn-sm" style="background:rgba(255,255,255,.15);color:#fff;border-color:rgba(255,255,255,.4)">Déconnexion</a>
  </div>
</div>

<?php if ($message): ?>
<div class="alert alert-success"><?= Security::e($message) ?></div>
<?php endif; ?>

<!-- Statistiques globales -->
<div class="stat-grid" style="grid-template-columns:repeat(4,1fr)">
    <div class="stat"><div class="stat-label">Total fiches</div><div class="stat-val"><?= (int)$statsGlobal['total'] ?></div></div>
    <div class="stat"><div class="stat-label">En attente</div><div class="stat-val" style="color:var(--warn)"><?= (int)$statsGlobal['en_attente'] ?></div></div>
    <div class="stat"><div class="stat-label">Validées</div><div class="stat-val" style="color:var(--ujkz-vert)"><?= (int)$statsGlobal['validee'] ?></div></div>
    <div class="stat"><div class="stat-label">Rejetées</div><div class="stat-val" style="color:var(--danger)"><?= (int)$statsGlobal['rejetee'] ?></div></div>
</div>

<!-- Volumes horaires globaux validés -->
<?php if (!empty($volsGlobal['total_cm']) || !empty($volsGlobal['total_td'])): ?>
<div class="stat-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:1rem">
    <div class="stat">
        <div class="stat-label">CM validés (global)</div>
        <div class="stat-val" style="color:var(--ujkz-vert)"><?= (int)$volsGlobal['total_cm'] ?><span style="font-size:13px;color:var(--gray-600)">h</span></div>
    </div>
    <div class="stat">
        <div class="stat-label">TD/TP validés (global)</div>
        <div class="stat-val" style="color:var(--ujkz-vert)"><?= (int)$volsGlobal['total_td'] ?><span style="font-size:13px;color:var(--gray-600)">h</span></div>
    </div>

</div>
<?php endif; ?>

<!-- Stats par département -->
<?php if (!empty($statsByDept)): ?>
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ujkz-vert-dk);margin-bottom:.5rem;display:flex;align-items:center;justify-content:space-between">
  <span>📊 Volumes horaires par département</span>
</div>
<div class="card" style="padding:0;overflow-x:auto;margin-bottom:1.25rem">
<table class="table-ujkz">
  <thead><tr>
    <th>Département</th>
    <th style="text-align:center">Fiches</th>
    <th style="text-align:center">CM soumis</th>
    <th style="text-align:center">CM validés</th>
    <th style="text-align:center">TD/TP soumis</th>
    <th style="text-align:center">TD/TP validés</th>
  </tr></thead>
  <tbody>
  <?php foreach ($statsByDept as $d): ?>
  <tr>
    <td style="font-weight:500"><?= Security::e($d['departement'] ?: '—') ?></td>
    <td style="text-align:center"><?= (int)$d['nb_fiches'] ?></td>
    <td style="text-align:center"><?= (int)$d['cm_soumis'] ?>h</td>
    <td style="text-align:center;font-weight:600;color:var(--ujkz-vert)"><?= (int)$d['cm_valide'] ?>h
      <?php if ($d['cm_soumis'] > 0): ?>
      <span style="font-size:10px;color:var(--gray-600);font-weight:400">(<?= round((int)$d['cm_valide']/(int)$d['cm_soumis']*100) ?>%)</span>
      <?php endif; ?>
    </td>
    <td style="text-align:center"><?= (int)$d['td_soumis'] ?>h</td>
    <td style="text-align:center;font-weight:600;color:var(--ujkz-vert)"><?= (int)$d['td_valide'] ?>h
      <?php if ($d['td_soumis'] > 0): ?>
      <span style="font-size:10px;color:var(--gray-600);font-weight:400">(<?= round((int)$d['td_valide']/(int)$d['td_soumis']*100) ?>%)</span>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<!-- Stats par établissement bénéficiaire -->
<?php if (!empty($statsByEtab)): ?>
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ujkz-vert-dk);margin-bottom:.5rem">
  📊 Volumes horaires par établissement bénéficiaire
</div>
<div class="card" style="padding:0;overflow-x:auto;margin-bottom:1.25rem">
<table class="table-ujkz">
  <thead><tr>
    <th>Établissement bénéficiaire</th>
    <th style="text-align:center">Fiches</th>
    <th style="text-align:center">CM soumis</th>
    <th style="text-align:center">CM validés</th>
    <th style="text-align:center">TD/TP soumis</th>
    <th style="text-align:center">TD/TP validés</th>
  </tr></thead>
  <tbody>
  <?php foreach ($statsByEtab as $et): ?>
  <tr>
    <td style="font-weight:500"><?= Security::e($et['etab_beneficiaire'] ?: '—') ?></td>
    <td style="text-align:center"><?= (int)$et['nb_fiches'] ?></td>
    <td style="text-align:center"><?= (int)$et['cm_soumis'] ?>h</td>
    <td style="text-align:center;font-weight:600;color:var(--ujkz-vert)"><?= (int)$et['cm_valide'] ?>h
      <?php if ($et['cm_soumis'] > 0): ?>
      <span style="font-size:10px;color:var(--gray-600);font-weight:400">(<?= round((int)$et['cm_valide']/(int)$et['cm_soumis']*100) ?>%)</span>
      <?php endif; ?>
    </td>
    <td style="text-align:center"><?= (int)$et['td_soumis'] ?>h</td>
    <td style="text-align:center;font-weight:600;color:var(--ujkz-vert)"><?= (int)$et['td_valide'] ?>h
      <?php if ($et['td_soumis'] > 0): ?>
      <span style="font-size:10px;color:var(--gray-600);font-weight:400">(<?= round((int)$et['td_valide']/(int)$et['td_soumis']*100) ?>%)</span>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<!-- Synthèse par enseignant -->
<?php if (!empty($ensStats)): ?>
<div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--gray-600);margin-bottom:.75rem">
    Synthèse par enseignant — fiches validées
</div>
<div class="card" style="padding:0;overflow-x:auto;margin-bottom:1.25rem">
    <table class="table-ujkz">
      <thead><tr>
        <th>Enseignant</th>
        <th>Grade / Département</th>
        <th style="text-align:center">Fiches val.</th>
                <th style="text-align:center">CM (h)</th>
                <th style="text-align:center">TD/TP (h)</th>
                <th style="text-align:center">Total (h)</th>
                <th style="text-align:center">Solde</th>
                <th>Fiche</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($ensStats as $es): ?>
        <?php
            $tcm   = (int)$es['total_cm'];
            $ttd   = (int)$es['total_td'];
            $tot   = $tcm + $ttd;
            $volAp = (int)($es['volume_apres_abatt'] ?? 0);
            $solde = $volAp > 0 ? $volAp - $tcm : null;
            $soldeCls = $solde === null ? '' : ($solde >= 0 ? 'color:var(--ujkz-vert)' : 'color:var(--danger)');
        ?>
        <tr style="border-bottom:1px solid var(--gray-200)">
            <td style="padding:9px 12px">
                <div style="font-weight:500"><?= Security::e($es['nom']) ?></div>
                <div style="font-size:11px;color:var(--gray-600)"><?= Security::e($es['matricule']) ?></div>
            </td>
            <td style="padding:9px 12px;color:var(--gray-600);font-size:12px">
                <?= Security::e($es['grade'] ?? '—') ?><br>
                <?= Security::e($es['departement']) ?>
            </td>
            <td style="padding:9px 12px;text-align:center"><?= (int)$es['nb_validee'] ?></td>
            <td style="padding:9px 12px;text-align:center;font-weight:500;color:var(--ujkz-vert)"><?= $tcm ?></td>
            <td style="padding:9px 12px;text-align:center"><?= $ttd ?></td>
            <td style="padding:9px 12px;text-align:center;font-weight:500"><?= $tot ?></td>
            <td style="padding:9px 12px;text-align:center;<?= $soldeCls ?>;font-weight:500">
                <?= $solde === null ? '—' : ($solde >= 0 ? '+' : '') . $solde . 'h' ?>
            </td>
            <td style="padding:9px 12px">
                <a href="generer_fiche.php?type=programmatique&ens_id=<?= (int)$es['id'] ?>"
                   class="btn btn-sm btn-primary" target="_blank"
                   title="Télécharger la fiche programmatique de <?= Security::e($es['nom']) ?>">
                    &#8595; DOCX
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Filtres -->
<div style="display:flex;gap:8px;margin-bottom:1rem;flex-wrap:wrap">
    <?php foreach (['en_attente'=>'En attente','validee'=>'Validées','rejetee'=>'Rejetées','tous'=>'Toutes'] as $val=>$label): ?>
    <a href="admin.php?statut=<?= $val ?>"
       class="btn btn-sm <?= $filtreStatut===$val?'btn-primary':'' ?>"><?= $label ?></a>
    <?php endforeach; ?>
</div>

<!-- Table des fiches -->
<div class="card" style="padding:0;overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead>
            <tr style="background:var(--gray-50);border-bottom:1px solid var(--gray-200)">
                <th>#</th><th>Enseignant</th><th>Cours</th><th>Niveau</th><th>Statut</th><th>Date</th><th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($fiches)): ?>
        <tr><td colspan="7" style="padding:2rem;text-align:center;color:var(--gray-600)">Aucune fiche dans cette catégorie.</td></tr>
        <?php endif; ?>
        <?php foreach ($fiches as $f): ?>
        <?php
            if ($f['statut'] === 'validee') { $badgeClass = 'badge-green'; $statutLabel = 'Validée'; }
            elseif ($f['statut'] === 'rejetee') { $badgeClass = 'badge-danger'; $statutLabel = 'Rejetée'; }
            else { $badgeClass = 'badge-or'; $statutLabel = 'En attente'; }
        ?>
        <tr style="border-bottom:1px solid var(--gray-200)">
            <td style="padding:10px 14px;color:var(--gray-600)"><?= (int)$f['id'] ?></td>
            <td style="padding:10px 14px">
                <div style="font-weight:500"><?= Security::e($f['ens_nom']) ?></div>
                <div style="font-size:11px;color:var(--gray-600)"><?= Security::e($f['matricule']) ?></div>
            </td>
            <td style="padding:10px 14px">
                <div><?= Security::e($f['cours']) ?></div>
                <div style="font-size:11px;color:var(--gray-600)"><?= Security::e($f['code_ue']?:'—') ?></div>
            </td>
            <td style="padding:10px 14px"><?= Security::e($f['niveau']) ?> &middot; <?= Security::e($f['semestre']) ?></td>
            <td style="padding:10px 14px"><span class="badge <?= $badgeClass ?>"><?= $statutLabel ?></span></td>
            <td style="padding:10px 14px;color:var(--gray-600)"><?= Security::e(date('d/m/Y', strtotime($f['submitted_at']))) ?></td>
            <td style="padding:10px 14px">
                <div style="display:flex;gap:6px;flex-wrap:wrap">
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                        <input type="hidden" name="fiche_id"   value="<?= (int)$f['id'] ?>">
                        <?php if ($f['statut'] !== 'validee'): ?>
                        <button type="submit" name="action" value="valider"
                                class="btn btn-sm" style="color:var(--ujkz-vert);border-color:#c0dd97"
                                onclick="return confirm('Valider cette fiche ?')">✓ Valider</button>
                        <?php endif; ?>
                        <?php if ($f['statut'] !== 'rejetee'): ?>
                        <button type="submit" name="action" value="rejeter"
                                class="btn btn-sm" style="color:var(--danger);border-color:#f09595"
                                onclick="return confirm('Rejeter cette fiche ?')">✗ Rejeter</button>
                        <?php endif; ?>
                        <?php if ($f['statut'] !== 'en_attente'): ?>
                        <button type="submit" name="action" value="remettre"
                                class="btn btn-sm"
                                onclick="return confirm('Remettre en attente ?')">↺</button>
                        <?php endif; ?>
                    </form>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div style="display:flex;justify-content:center;gap:6px;margin-top:1rem">
    <?php for ($i=1; $i<=$totalPages; $i++): ?>
    <a href="admin.php?statut=<?= Security::e($filtreStatut) ?>&page=<?= $i ?>"
       class="btn btn-sm <?= $i===$page?'btn-primary':'' ?>"><?= $i ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<!-- Lien vers le journal d'audit complet -->
<div style="text-align:right;margin-top:.5rem">
  <a href="admin_audit.php" class="btn btn-sm btn-outline-green">📋 Voir le journal d'audit complet →</a>
</div>
<?php

$body = ob_get_clean();
echo renderLayout('Administration', $body, $csrfToken);

function renderTemplate(string $name, array $vars = []): string
{
    extract($vars);
    ob_start();
    require __DIR__ . '/templates/' . $name . '.php';
    return ob_get_clean();
}

function renderLayout(string $title, string $bodyContent, string $csrfToken): string
{
    ob_start();
    require __DIR__ . '/templates/layout.php';
    return ob_get_clean();
}
