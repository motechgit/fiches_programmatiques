<?php
// ============================================================
// generer_fiche.php — Génération fiche programmatique / suivi PDF
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';
if (!class_exists('App')) require_once __DIR__ . '/src/App.php';
App::configureErrorDisplay();
require_once __DIR__ . '/src/FicheRepository.php';
require_once __DIR__ . '/src/FichePdf.php';
require_once __DIR__ . '/src/FicheDocx.php'; // fallback Word

$config   = require __DIR__ . '/config/security.php';
$security = new Security($config);
$repo     = new FicheRepository();
$security->sendSecurityHeaders();
$security->startSecureSession();
$pdo = Database::getInstance();

$docType = in_array($_GET['type'] ?? 'programmatique', ['programmatique','suivi'], true)
           ? ($_GET['type'] ?? 'programmatique') : 'programmatique';

// ── Auth ─────────────────────────────────────────────────────
$enseignant = null;
$fiches     = [];

if (!empty($_GET['token'])) {
    $token = $_GET['token'];
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        http_response_code(403); die('Accès refusé.');
    }
    $enseignant = $repo->findByToken($token);
    if (!$enseignant) { http_response_code(404); die('Enseignant introuvable.'); }

    if (!empty($_GET['fiche_id'])) {
        $fid = (int)$_GET['fiche_id'];
        $stmt = $pdo->prepare("SELECT * FROM fiches WHERE id=? AND enseignant_id=? AND statut='validee' LIMIT 1");
        $stmt->execute([$fid, (int)$enseignant['id']]);
        $f = $stmt->fetch();
        $fiches = $f ? [$f] : [];
    } else {
        $stmt = $pdo->prepare("SELECT * FROM fiches WHERE enseignant_id=? AND statut='validee' ORDER BY semestre, submitted_at");
        $stmt->execute([(int)$enseignant['id']]);
        $fiches = $stmt->fetchAll();
    }
    $security->audit('fiche_pdf', $enseignant['matricule'], "type=$docType");

} elseif (!empty($_GET['ens_id'])) {
    if (empty($_SESSION['admin_authenticated']) && empty($_SESSION['user_role'])) {
        http_response_code(403); die('Accès réservé.');
    }
    if (isset($_SESSION['admin_since']) && time() - $_SESSION['admin_since'] > 1800) {
        http_response_code(403); die('Session expirée.');
    }
    $_SESSION['admin_since'] = time();
    $ensId = (int)$_GET['ens_id'];
    $stmt  = $pdo->prepare("SELECT * FROM enseignants WHERE id=? LIMIT 1");
    $stmt->execute([$ensId]);
    $enseignant = $stmt->fetch() ?: null;
    if (!$enseignant) { http_response_code(404); die('Enseignant introuvable.'); }

    $stmt = $pdo->prepare("SELECT * FROM fiches WHERE enseignant_id=? AND statut='validee' ORDER BY semestre, submitted_at");
    $stmt->execute([$ensId]);
    $fiches = $stmt->fetchAll();
    $security->audit('fiche_pdf_admin', $enseignant['matricule'], "type=$docType");
} else {
    http_response_code(400); die('Paramètre manquant.');
}

if (empty($fiches)) {
    http_response_code(404);
    echo '<p style="font-family:sans-serif;padding:2rem;color:#c00">Aucune fiche validée.<br>
    <a href="javascript:history.back()">← Retour</a></p>';
    exit;
}

$nom  = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $enseignant['matricule']);

// ── Essai PDF Python → fallback DOCX ─────────────────────────
try {
    $pdfContent = FichePdf::generer($enseignant, $fiches, $config['annee_academique'], $docType, $pdo);
    $filename   = 'fiche_' . ($docType === 'suivi' ? 'suivi' : 'programmatique') . '_' . $nom . '_' . date('Ymd') . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfContent));
    header('Cache-Control: no-cache, no-store');
    echo $pdfContent;
} catch (Throwable $e) {
    // Fallback DOCX si Python absent
    $docxContent = FicheDocx::generer($enseignant, $fiches, $config['annee_academique']);
    $filename    = 'fiche_' . $nom . '_' . date('Ymd') . '.docx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($docxContent));
    header('Cache-Control: no-cache');
    echo $docxContent;
}
