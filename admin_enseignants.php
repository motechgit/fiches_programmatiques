<?php
// ============================================================
// public/admin_enseignants.php — Gestion des enseignants
//   Accessible depuis admin.php → lien "Enseignants"
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';
require_once __DIR__ . '/src/FicheRepository.php';
require_once __DIR__ . '/src/App.php';

$config   = require __DIR__ . '/config/security.php';
$security = new Security($config);

$security->sendSecurityHeaders();
$security->startSecureSession();
$csrfToken = $security->generateCsrfToken();

// ── Vérification session admin ───────────────────────────────
if (empty($_SESSION['admin_authenticated'])) {
    header('Location: admin.php');
    exit;
}
if (isset($_SESSION['admin_since']) && time() - $_SESSION['admin_since'] > 1800) {
    unset($_SESSION['admin_authenticated'], $_SESSION['admin_user'], $_SESSION['admin_since']);
    header('Location: admin.php');
    exit;
}
$_SESSION['admin_since'] = time();

$pdo     = Database::getInstance();
$message = '';
$error   = '';

// ── Actions POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!$security->verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Token CSRF invalide.');
    }

    $action = $_POST['action'] ?? '';
    $eid    = (int) ($_POST['enseignant_id'] ?? 0);

    // Régénérer le token d'accès d'un enseignant
    if ($action === 'regenerer_token' && $eid > 0) {
        $row = $pdo->prepare("SELECT matricule FROM enseignants WHERE id = ? LIMIT 1");
        $row->execute([$eid]);
        $ens = $row->fetch();

        if ($ens) {
            // Nouveau token basé sur matricule + timestamp (unicité garantie)
            $newToken = hash_hmac('sha256',
                strtoupper($ens['matricule']) . '|' . time(),
                $config['app_secret']
            );
            $pdo->prepare("UPDATE enseignants SET token = ? WHERE id = ?")
                ->execute([$newToken, $eid]);
            $security->audit('admin_token_regenerated', $ens['matricule'], "ID $eid");
            $message = "Lien d'accès régénéré pour " . Security::e($ens['matricule']) .
                       ". L'ancien lien est désormais invalide.";
            unset($_SESSION['csrf_token']);
            $csrfToken = $security->generateCsrfToken();
        }
    }

    // Supprimer un enseignant et toutes ses fiches (CASCADE)
    if ($action === 'supprimer' && $eid > 0) {
        $row = $pdo->prepare("SELECT matricule, nom FROM enseignants WHERE id = ? LIMIT 1");
        $row->execute([$eid]);
        $ens = $row->fetch();

        if ($ens) {
            $pdo->prepare("DELETE FROM enseignants WHERE id = ?")->execute([$eid]);
            $security->audit('admin_enseignant_deleted', $ens['matricule'],
                "Nom: " . $ens['nom']);
            $message = "Enseignant " . Security::e($ens['matricule']) .
                       " et ses fiches supprimés.";
            unset($_SESSION['csrf_token']);
            $csrfToken = $security->generateCsrfToken();
        }
    }

    // Modifier les informations d'un enseignant
    if ($action === 'modifier' && $eid > 0) {
        $nom         = Security::sanitizeText($_POST['nom']         ?? '', 100);
        $departement = Security::sanitizeText($_POST['departement'] ?? '', 100);
        $email       = Security::sanitizeText($_POST['email']       ?? '', 150);

        if (empty($nom)) {
            $error = 'Le nom est requis.';
        } elseif (!Security::inWhitelist($departement, $config['departements'])) {
            $error = 'Département invalide.';
        } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email invalide.';
        } else {
            $pdo->prepare(
                "UPDATE enseignants SET nom = ?, departement = ?, email = ? WHERE id = ?"
            )->execute([$nom, $departement, $email, $eid]);
            $security->audit('admin_enseignant_modified', null, "ID $eid");
            $message = 'Informations mises à jour.';
            unset($_SESSION['csrf_token']);
            $csrfToken = $security->generateCsrfToken();
        }
    }
}

// ── Données ──────────────────────────────────────────────────
$search  = Security::sanitizeText($_GET['q'] ?? '', 50);
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

$whereSearch = '';
$params      = [];
if (!empty($search)) {
    $whereSearch = "WHERE e.matricule LIKE ? OR e.nom LIKE ? OR e.departement LIKE ?";
    $s = '%' . $search . '%';
    $params = [$s, $s, $s];
}

$totalEns  = (int) $pdo->prepare(
    "SELECT COUNT(*) FROM enseignants e $whereSearch"
)->execute($params) ? $pdo->prepare(
    "SELECT COUNT(*) FROM enseignants e $whereSearch"
)->execute($params) : 0;

// Recompter proprement
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM enseignants e $whereSearch");
$countStmt->execute($params);
$totalEns  = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalEns / $perPage));

$stmt = $pdo->prepare(
    "SELECT e.*,
            COUNT(f.id) AS nb_fiches,
            SUM(CASE WHEN f.statut='en_attente' THEN 1 ELSE 0 END) AS nb_attente,
            SUM(CASE WHEN f.statut='validee'    THEN 1 ELSE 0 END) AS nb_validee
     FROM enseignants e
     LEFT JOIN fiches f ON f.enseignant_id = e.id
     $whereSearch
     GROUP BY e.id
     ORDER BY e.nom ASC
     LIMIT ? OFFSET ?"
);
$stmt->execute(array_merge($params, [$perPage, $offset]));
$enseignants = $stmt->fetchAll();

// Enseignant à éditer (modale simulée)
$editEns = null;
if (isset($_GET['edit'])) {
    $editStmt = $pdo->prepare("SELECT * FROM enseignants WHERE id = ? LIMIT 1");
    $editStmt->execute([(int) $_GET['edit']]);
    $editEns = $editStmt->fetch() ?: null;
}

// Construire le lien d'accès
$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
         . dirname($_SERVER['PHP_SELF']);

ob_start();
?>
<div class="breadcrumb">
  <a href="admin.php">Administration</a>
  <span class="breadcrumb-sep">›</span>
  <span>Enseignants</span>
</div>
<div class="page-hero" style="margin-bottom:1.25rem">
  <div>
    <h1>👥 Enseignants</h1>
    <div class="subtitle"><?= $totalEns ?> enseignant(s) enregistré(s)</div>
  </div>
  <a href="admin.php" class="btn btn-sm" style="background:rgba(255,255,255,.15);color:#fff;border-color:rgba(255,255,255,.4)">← Administration</a>
</div>

<?php if ($message): ?>
<div class="alert alert-success"><?= Security::e($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger"><?= Security::e($error) ?></div>
<?php endif; ?>

<?php if ($editEns): ?>
<!-- Formulaire d'édition inline -->
<div class="card" style="border-color:#378ADD">
    <div style="font-size:14px;font-weight:500;margin-bottom:1rem">
        Modifier : <?= Security::e($editEns['matricule']) ?>
    </div>
    <form method="POST">
        <input type="hidden" name="csrf_token"     value="<?= Security::e($csrfToken) ?>">
        <input type="hidden" name="action"         value="modifier">
        <input type="hidden" name="enseignant_id"  value="<?= (int)$editEns['id'] ?>">
        <div class="grid2">
            <div>
                <label>Nom complet</label>
                <input type="text" name="nom" value="<?= Security::e($editEns['nom']) ?>"
                       maxlength="100" required>
            </div>
            <div>
                <label>Département</label>
                <select name="departement">
                    <?php foreach ($config['departements'] as $d): ?>
                    <option value="<?= Security::e($d) ?>"
                        <?= $editEns['departement']===$d?'selected':'' ?>>
                        <?= Security::e($d) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <label>Email</label>
        <input type="email" name="email" value="<?= Security::e($editEns['email']) ?>"
               maxlength="150">
        <div style="display:flex;gap:8px;margin-top:1rem">
            <button type="submit" class="btn btn-primary btn-sm">Enregistrer</button>
            <a href="admin_enseignants.php" class="btn btn-sm">Annuler</a>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- Barre de recherche -->
<form method="GET" style="display:flex;gap:8px;margin-bottom:1rem">
    <input type="text" name="q" value="<?= Security::e($search) ?>"
           placeholder="Rechercher par matricule, nom ou département..."
           style="flex:1;max-width:400px">
    <button type="submit" class="btn btn-sm">Rechercher</button>
    <?php if ($search): ?>
    <a href="admin_enseignants.php" class="btn btn-sm">Effacer</a>
    <?php endif; ?>
</form>

<!-- Table enseignants -->
<div class="card" style="padding:0;overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead>
            <tr style="background:var(--bg);border-bottom:1px solid var(--border)">
                <th style="padding:10px 14px;text-align:left;font-weight:600">Matricule</th>
                <th style="padding:10px 14px;text-align:left;font-weight:600">Nom</th>
                <th style="padding:10px 14px;text-align:left;font-weight:600">Département</th>
                <th style="padding:10px 14px;text-align:left;font-weight:600">Fiches</th>
                <th style="padding:10px 14px;text-align:left;font-weight:600">Lien d'accès</th>
                <th style="padding:10px 14px;text-align:left;font-weight:600">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($enseignants)): ?>
        <tr><td colspan="6" style="padding:2rem;text-align:center;color:var(--muted)">
            Aucun enseignant trouvé.
        </td></tr>
        <?php endif; ?>
        <?php foreach ($enseignants as $e): ?>
        <?php $lien = $baseUrl . '/dashboard.php?token=' . urlencode($e['token']); ?>
        <tr style="border-bottom:1px solid var(--border)">
            <td style="padding:10px 14px;font-weight:500">
                <?= Security::e($e['matricule']) ?>
            </td>
            <td style="padding:10px 14px">
                <div><?= Security::e($e['nom']) ?></div>
                <div style="font-size:11px;color:var(--muted)"><?= Security::e($e['email']?:'—') ?></div>
            </td>
            <td style="padding:10px 14px;color:var(--muted)"><?= Security::e($e['departement']) ?></td>
            <td style="padding:10px 14px">
                <span class="badge badge-info"><?= (int)$e['nb_fiches'] ?> total</span>
                <?php if ((int)$e['nb_attente'] > 0): ?>
                <span class="badge badge-warn"><?= (int)$e['nb_attente'] ?> en attente</span>
                <?php endif; ?>
                <?php if ((int)$e['nb_validee'] > 0): ?>
                <span class="badge badge-success"><?= (int)$e['nb_validee'] ?> validée(s)</span>
                <?php endif; ?>
            </td>
            <td style="padding:10px 14px">
                <div style="display:flex;align-items:center;gap:6px">
                    <span style="font-family:monospace;font-size:11px;color:var(--muted);
                                 max-width:180px;overflow:hidden;text-overflow:ellipsis;
                                 white-space:nowrap"
                          title="<?= Security::e($lien) ?>">
                        …<?= Security::e(substr($e['token'], -8)) ?>
                    </span>
                    <button class="btn btn-sm"
                            onclick="copyLink('<?= Security::e($lien) ?>', this)"
                            title="Copier le lien complet">Copier</button>
                </div>
            </td>
            <td style="padding:10px 14px">
                <div style="display:flex;gap:6px;flex-wrap:wrap">
                    <a href="generer_fiche.php?ens_id=<?= (int)$e['id'] ?>"
                       class="btn btn-sm btn-primary" target="_blank"
                       title="Télécharger la fiche programmatique (fiches validées)">
                        &#8595; Fiche DOCX
                    </a>
                    <a href="admin_enseignants.php?edit=<?= (int)$e['id'] ?>#edit"
                       class="btn btn-sm">Modifier</a>

                    <form method="POST" style="display:inline"
                          onsubmit="return confirm('Régénérer le lien d\'accès de <?= Security::e(addslashes($e['matricule'])) ?> ? L\'ancien lien sera invalide.')">
                        <input type="hidden" name="csrf_token"    value="<?= Security::e($csrfToken) ?>">
                        <input type="hidden" name="action"        value="regenerer_token">
                        <input type="hidden" name="enseignant_id" value="<?= (int)$e['id'] ?>">
                        <button type="submit" class="btn btn-sm"
                                style="color:var(--warn)">Renouveler lien</button>
                    </form>

                    <form method="POST" style="display:inline"
                          onsubmit="return confirm('Supprimer <?= Security::e(addslashes($e['matricule'])) ?> et toutes ses fiches ? Cette action est irréversible.')">
                        <input type="hidden" name="csrf_token"    value="<?= Security::e($csrfToken) ?>">
                        <input type="hidden" name="action"        value="supprimer">
                        <input type="hidden" name="enseignant_id" value="<?= (int)$e['id'] ?>">
                        <button type="submit" class="btn btn-sm"
                                style="color:var(--danger)">Supprimer</button>
                    </form>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
<div style="display:flex;justify-content:center;gap:6px;margin-top:1rem">
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
    <a href="admin_enseignants.php?page=<?= $i ?><?= $search ? '&q='.urlencode($search) : '' ?>"
       class="btn btn-sm <?= $i === $page ? 'btn-primary' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<script>
function copyLink(url, btn) {
    navigator.clipboard.writeText(url).then(() => {
        const o = btn.textContent;
        btn.textContent = 'Copié !';
        setTimeout(() => btn.textContent = o, 1800);
    });
}
</script>
<?php

$body = ob_get_clean();
echo renderLayout('Gestion des enseignants', $body, $csrfToken);

function renderLayout(string $title, string $bodyContent, string $csrfToken): string
{
    ob_start();
    require __DIR__ . '/templates/layout.php';
    return ob_get_clean();
}
