<?php
// ============================================================
// dashboard.php — Tableau de bord enseignant
// Vue par défaut : Fiche programmatique complète
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';
if (!class_exists('App')) require_once __DIR__ . '/src/App.php';
App::configureErrorDisplay();
require_once __DIR__ . '/src/FicheRepository.php';
require_once __DIR__ . '/src/ValidationRepository.php';
require_once __DIR__ . '/src/Auth.php';

$config   = require __DIR__ . '/config/security.php';
$security = new Security($config);
$repo     = new FicheRepository();

$security->sendSecurityHeaders();
$security->startSecureSession();
$csrfToken = $security->generateCsrfToken();

// ── Valider le token ─────────────────────────────────────────
$rawToken = $_GET['token'] ?? '';
if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
    http_response_code(403);
    renderError('Accès refusé', 'Le lien utilisé est invalide ou a expiré.');
    exit;
}
if (!$security->checkRateLimit('dashboard')) {
    http_response_code(429);
    renderError('Trop de requêtes', 'Veuillez patienter avant de réessayer.');
    exit;
}
$enseignant = $repo->findByToken($rawToken);
if (!$enseignant) {
    http_response_code(404);
    renderError('Tableau de bord introuvable', 'Aucun compte ne correspond à ce lien.');
    exit;
}
$security->audit('dashboard_access', $enseignant['matricule']);
$accessLink = App::url('dashboard.php?token=' . urlencode($rawToken));
$valRepo    = new ValidationRepository();
$annee      = $config['annee_academique'] ?? '2024-2025';

// ── Fiche semestrielle de suivi (impression) ────────────────
if (isset($_GET['suivi'])) {
    $ficheId = (int)$_GET['suivi'];
    if ($ficheId <= 0) {
        http_response_code(400); renderError('Fiche invalide', 'Identifiant incorrect.'); exit;
    }
    $fiche = $repo->getFicheByIdAndEnseignant($ficheId, (int)$enseignant['id']);
    if (!$fiche) {
        http_response_code(403); renderError('Accès refusé', 'Cette fiche ne vous appartient pas.'); exit;
    }
    $preuves    = $valRepo->getPreuves($ficheId);
    $historique = $valRepo->getHistorique($ficheId);
    $security->audit('fiche_suivi_viewed', $enseignant['matricule'], "Fiche $ficheId");
    $bodyContent = renderTemplate('fiche_suivi', [
        'fiche'      => $fiche,
        'enseignant' => $enseignant,
        'preuves'    => $preuves,
        'historique' => $historique,
        'config'     => $config,
        'annee'      => $annee,
        'token'      => $rawToken,
        'csrfToken'  => $csrfToken,
    ]);
    echo renderLayout('Fiche de suivi — ' . $fiche['cours'], $bodyContent, $csrfToken);
    exit;
}

// ── Vue détail d'une fiche (suivi) ───────────────────────────
if (isset($_GET['fiche'])) {
    $ficheId = (int)$_GET['fiche'];
    if ($ficheId <= 0) {
        http_response_code(400); renderError('Fiche invalide', 'Identifiant incorrect.'); exit;
    }
    $fiche = $repo->getFicheByIdAndEnseignant($ficheId, (int)$enseignant['id']);
    if (!$fiche) {
        http_response_code(403); renderError('Accès refusé', 'Cette fiche ne vous appartient pas.'); exit;
    }
    $uploadOk    = isset($_GET['upload_ok']);
    $uploadError = isset($_GET['upload_error']) ? urldecode($_GET['upload_error']) : '';
    $preuves     = $valRepo->getPreuves($ficheId);
    $historique  = $valRepo->getHistorique($ficheId);
    $security->audit('fiche_viewed', $enseignant['matricule'], "Fiche $ficheId");
    $bodyContent = renderTemplate('fiche_detail', [
        'fiche'       => $fiche, 'enseignant'  => $enseignant,
        'accessLink'  => $accessLink, 'csrfToken'   => $csrfToken,
        'preuves'     => $preuves, 'historique'  => $historique,
        'uploadOk'    => $uploadOk, 'uploadError' => $uploadError,
    ]);
    echo renderLayout('Fiche : ' . $fiche['cours'], $bodyContent, $csrfToken);
    exit;
}

// ── Charger toutes les fiches et l'historique global ─────────
$fiches = $repo->getFichesByEnseignant((int)$enseignant['id']);
$stats  = $repo->getStatsByEnseignant((int)$enseignant['id']);

// Historique de TOUTES les fiches pour les signatures
$historiqueGlobal = [];
$pdo = Database::getInstance();
if (!empty($fiches)) {
    $ids = array_column($fiches, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $stH = $pdo->prepare(
        "SELECT v.fiche_id, v.role AS etape_role, v.decision, v.motif_rejet, v.created_at,
                u.nom AS valideur_nom
         FROM validations_fiche v
         JOIN utilisateurs u ON u.id = v.utilisateur_id
         WHERE v.fiche_id IN ($ph)
         ORDER BY v.created_at ASC"
    );
    $stH->execute($ids);
    foreach ($stH->fetchAll() as $h) {
        // Utiliser v.role (étape de validation) comme clé, pas u.role
        $r = $h['etape_role'] ?? '';
        // Garder la décision la plus récente par étape
        if (!isset($historiqueGlobal[$r]) || $h['decision'] === 'valide') {
            $historiqueGlobal[$r] = $h;
        }
    }
}

// Nombre de preuves par fiche + données complètes pour onglets suivi
$preuvesCounts = [];
$preuvesByFiche = [];  // preuves détaillées par fiche_id
$fichesAvecPreuves = [];  // fiches qui ont au moins 1 preuve
if (!empty($fiches)) {
    $ids = array_column($fiches, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $stP = $pdo->prepare(
        "SELECT * FROM preuves WHERE fiche_id IN ($ph) ORDER BY uploaded_at ASC"
    );
    $stP->execute($ids);
    foreach ($stP->fetchAll() as $row) {
        $fid = (int)$row['fiche_id'];
        $preuvesCounts[$fid] = ($preuvesCounts[$fid] ?? 0) + 1;
        $preuvesByFiche[$fid][] = $row;
    }
    // Fiches avec preuves groupées par semestre
    foreach ($fiches as $f) {
        $fid = (int)$f['id'];
        if (!empty($preuvesByFiche[$fid])) {
            $sem = $f['semestre'] ?? 'S1';
            if (!isset($fichesAvecPreuves[$sem])) $fichesAvecPreuves[$sem] = [];
            $fichesAvecPreuves[$sem][] = array_merge($f, [
                'preuves' => $preuvesByFiche[$fid]
            ]);
        }
    }
}

// Vue active : 'fiche' (défaut) ou 'cours'
$vue = in_array($_GET['vue'] ?? '', ['fiche','cours','suivi_s1','suivi_s2']) ? $_GET['vue'] : 'fiche';

$bodyContent = renderTemplate('dashboard', [
    'enseignant'       => $enseignant,
    'fiches'           => $fiches,
    'stats'            => $stats,
    'accessLink'       => $accessLink,
    'csrfToken'        => $csrfToken,
    'preuvesCounts'     => $preuvesCounts,
    'preuvesByFiche'    => $preuvesByFiche,
    'fichesAvecPreuves' => $fichesAvecPreuves,
    'historiqueGlobal'  => $historiqueGlobal,
    'annee'            => $annee,
    'vue'              => $vue,
    'rawToken'         => $rawToken,
]);
echo renderLayout('Mon tableau de bord', $bodyContent, $csrfToken);

// ── Helpers ──────────────────────────────────────────────────
function renderTemplate(string $name, array $vars = []): string
{
    extract($vars);
    ob_start();
    require __DIR__ . '/templates/' . $name . '.php';
    return ob_get_clean();
}
function renderLayout(string $title, string $bodyContent, string $csrfToken): string
{
    ob_start(); require __DIR__ . '/templates/layout.php'; return ob_get_clean();
}
function renderError(string $titre, string $message): void
{
    $bodyContent = '<div style="padding:2rem 0;text-align:center">'
        . '<h1 style="color:var(--danger);margin-bottom:.5rem">' . htmlspecialchars($titre, ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<p style="color:var(--muted)">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p style="margin-top:1.5rem"><a href="index.php" style="color:var(--accent)">Déposer une nouvelle fiche</a></p>'
        . '</div>';
    $csrfToken = '';
    ob_start(); require __DIR__ . '/templates/layout.php'; echo ob_get_clean();
}
