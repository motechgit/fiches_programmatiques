<?php
// ============================================================
// voir_preuve.php — Visualisation sécurisée d'une preuve
// Accessible enseignant (token) ou utilisateur portail (session)
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/FicheRepository.php';
require_once __DIR__ . '/src/ValidationRepository.php';

// ── Mode AJAX : liste des preuves d'une fiche ─────────────────
if (isset($_GET['ajax']) && isset($_GET['fiche_id'])) {
    $tokenAjax = $_GET['token'] ?? '';
    if (!preg_match('/^[a-f0-9]{64}$/', $tokenAjax)) {
        header('Content-Type: application/json');
        echo json_encode(['preuves'=>[]]);
        exit;
    }
    $repoAjax = new FicheRepository();
    $ensAjax  = $repoAjax->findByToken($tokenAjax);
    if (!$ensAjax) {
        header('Content-Type: application/json');
        echo json_encode(['preuves'=>[]]);
        exit;
    }
    $ficheIdAjax = (int)$_GET['fiche_id'];
    $ficheAjax   = $repoAjax->getFicheByIdAndEnseignant($ficheIdAjax, (int)$ensAjax['id']);
    if (!$ficheAjax) {
        header('Content-Type: application/json');
        echo json_encode(['preuves'=>[]]);
        exit;
    }
    $valRepoAjax = new ValidationRepository();
    $preuvesAjax = $valRepoAjax->getPreuves($ficheIdAjax);
    header('Content-Type: application/json');
    echo json_encode(['preuves' => $preuvesAjax]);
    exit;
}


$config   = require __DIR__ . '/config/security.php';
$security = new Security($config);
$security->startSecureSession();

$valRepo = new ValidationRepository();
$repo    = new FicheRepository();

$preuveId = (int)($_GET['preuve'] ?? 0);
$ficheId  = (int)($_GET['fiche']  ?? 0);

// ── Cas 1 : voir une preuve spécifique ───────────────────────
if ($preuveId > 0) {
    $preuve = $valRepo->getPreuve($preuveId);
    if (!$preuve) { http_response_code(404); die('Preuve introuvable.'); }

    // Auth : enseignant ou portail
    $token  = $_GET['token'] ?? '';
    $authed = false;

    if ($token && preg_match('/^[a-f0-9]{64}$/', $token)) {
        $ens = $repo->findByToken($token);
        if ($ens) {
            $f = $repo->getFicheByIdAndEnseignant((int)$preuve['fiche_id'], (int)$ens['id']);
            $authed = $f !== null;
        }
    }
    if (!$authed && (Auth::check() || !empty($_SESSION['admin_authenticated']))) {
        $authed = true;
    }
    if (!$authed) { http_response_code(403); die('Accès refusé.'); }

    $path = __DIR__ . '/data/uploads/' . basename($preuve['nom_stockage']);
    if (!file_exists($path)) { http_response_code(404); die('Fichier introuvable sur le serveur.'); }

    // Envoyer le fichier
    header('Content-Type: '     . $preuve['type_mime']);
    header('Content-Length: '   . filesize($path));
    header('Content-Disposition: inline; filename="' . addslashes($preuve['nom_original']) . '"');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

// ── Cas 2 : liste des preuves d'une fiche ────────────────────
if ($ficheId > 0 && (Auth::check() || !empty($_SESSION['admin_authenticated']))) {
    $preuves = $valRepo->getPreuves($ficheId);
    $fiche   = $valRepo->getFicheComplete($ficheId);
    if (!$fiche) { http_response_code(404); die('Fiche introuvable.'); }

    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">'
       . '<title>Preuves — ' . htmlspecialchars($fiche['cours'] ?? '', ENT_QUOTES, 'UTF-8') . '</title>'
       . '<style>body{font-family:Segoe UI,sans-serif;padding:2rem;max-width:700px;margin:0 auto}</style></head><body>';
    echo '<h2>Preuves — ' . htmlspecialchars($fiche['cours'] ?? '', ENT_QUOTES, 'UTF-8') . '</h2>';
    echo '<p style="color:#666;margin:.5rem 0 1.5rem">' . htmlspecialchars($fiche['ens_nom'] ?? '', ENT_QUOTES, 'UTF-8') . '</p>';

    if (empty($preuves)) {
        echo '<p style="color:#888">Aucune preuve déposée.</p>';
    } else {
        foreach ($preuves as $p) {
            $icon = (strpos($p['type_mime'],'pdf') !== false) ? '📄' : ((strpos($p['type_mime'],'image') !== false) ? '🖼' : '📝');
            echo '<div style="margin:.5rem 0;padding:.75rem 1rem;border:1px solid #e0e0e0;border-radius:8px;display:flex;align-items:center;justify-content:space-between">'
               . '<span>' . $icon . ' ' . htmlspecialchars($p['nom_original'], ENT_QUOTES, 'UTF-8') . ' <small style="color:#999">(' . round($p['taille']/1024,1) . ' Ko)</small></span>'
               . '<a href="voir_preuve.php?preuve=' . (int)$p['id'] . '" target="_blank" style="background:#185FA5;color:#fff;padding:5px 14px;border-radius:6px;text-decoration:none;font-size:13px">Ouvrir</a>'
               . '</div>';
        }
    }
    echo '<p style="margin-top:1.5rem"><a href="voir_fiche.php?id=' . $ficheId . '">← Retour à la fiche</a></p>';
    echo '</body></html>';
    exit;
}

http_response_code(400); die('Paramètres manquants.');
