<?php
// ============================================================
// upload_preuve.php — Gestion des fichiers de preuves
// POST depuis le dashboard enseignant (token requis)
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';
if (!class_exists('App')) require_once __DIR__ . '/src/App.php';
App::configureErrorDisplay();
require_once __DIR__ . '/src/FicheRepository.php';
require_once __DIR__ . '/src/ValidationRepository.php';

$config   = require __DIR__ . '/config/security.php';
$security = new Security($config);
$security->sendSecurityHeaders();
$security->startSecureSession();

// ── Auth enseignant via token ─────────────────────────────────
$token = $_POST['token'] ?? '';
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(403); die('Accès refusé.');
}

if (!$security->verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403); die('Token CSRF invalide.');
}

$repo       = new FicheRepository();
$valRepo    = new ValidationRepository();
$enseignant = $repo->findByToken($token);
if (!$enseignant) { http_response_code(403); die('Token invalide.'); }

$ficheId = (int)($_POST['fiche_id'] ?? 0);
$action  = $_POST['action'] ?? 'upload';

// Helper de redirection
$redir = function(string $param, string $val = '') use ($token, $ficheId): never {
    $base = 'dashboard.php?token=' . urlencode($token) . '&fiche=' . $ficheId;
    header('Location: ' . $base . ($val !== '' ? '&' . $param . '=' . urlencode($val) : '&' . $param . '=1'));
    exit;
};

// ── Suppression ───────────────────────────────────────────────
if ($action === 'supprimer') {
    $preuveId = (int)($_POST['preuve_id'] ?? 0);
    $preuve   = $valRepo->getPreuve($preuveId);
    if ($preuve) {
        $fiche = $repo->getFicheByIdAndEnseignant((int)$preuve['fiche_id'], (int)$enseignant['id']);
        if ($fiche) {
            $path = __DIR__ . '/data/uploads/' . $preuve['nom_stockage'];
            if (file_exists($path)) @unlink($path);
            $valRepo->deletePreuve($preuveId);
            $security->audit('preuve_deleted', $enseignant['matricule'], "preuve=$preuveId");
        }
    }
    $redir('upload_ok');
}

// ── Upload ────────────────────────────────────────────────────
if ($ficheId <= 0) {
    $redir('upload_error', 'Identifiant de fiche invalide.');
}

// Vérifier propriété de la fiche
$fiche = $repo->getFicheByIdAndEnseignant($ficheId, (int)$enseignant['id']);
if (!$fiche) { http_response_code(403); die('Fiche non autorisée.'); }

// Vérifier qu'un fichier a été envoyé
if (empty($_FILES['preuve']) || !isset($_FILES['preuve']['error'])) {
    $redir('upload_error', 'Aucun fichier reçu par le serveur.');
}

$file = $_FILES['preuve'];

// Vérifier l'erreur PHP
if ($file['error'] !== UPLOAD_ERR_OK) {
    $errCode = $file['error'];
    if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
        $err = 'Fichier trop volumineux (max 10 Mo).';
    } elseif ($errCode === UPLOAD_ERR_PARTIAL) {
        $err = 'Transfert interrompu, réessayez.';
    } elseif ($errCode === UPLOAD_ERR_NO_FILE) {
        $err = 'Aucun fichier sélectionné.';
    } elseif ($errCode === UPLOAD_ERR_NO_TMP_DIR) {
        $err = 'Répertoire temporaire manquant (contacter l\'admin).';
    } elseif ($errCode === UPLOAD_ERR_CANT_WRITE) {
        $err = 'Impossible d\'écrire le fichier (contacter l\'admin).';
    } else {
        $err = 'Erreur d\'envoi (code ' . $file['error'] . ').';
    }
    $redir('upload_error', $err);
}

// Taille max 10 Mo
$maxSize = 10 * 1024 * 1024;
if ($file['size'] > $maxSize) {
    $redir('upload_error', 'Fichier trop volumineux (max 10 Mo, reçu : ' . round($file['size']/1048576,1) . ' Mo).');
}

// Vérifier le type MIME réel
$mimeOk = [
    'application/pdf'       => 'pdf',
    'application/msword'    => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];

$finfo    = new finfo(FILEINFO_MIME_TYPE);
$realMime = $finfo->file($file['tmp_name']);
if (!array_key_exists($realMime, $mimeOk)) {
    $redir('upload_error', 'Type de fichier non autorisé : ' . $realMime . '. Formats acceptés : PDF, Word, JPEG, PNG, GIF, WebP.');
}

// Créer le dossier uploads si nécessaire
$uploadsDir = __DIR__ . '/data/uploads';
if (!is_dir($uploadsDir)) {
    if (!mkdir($uploadsDir, 0755, true)) {
        $redir('upload_error', 'Impossible de créer le dossier de stockage. Vérifiez les permissions du dossier data/.');
    }
}

// Générer un nom de fichier sécurisé
$ext         = $mimeOk[$realMime];
$nomStockage = bin2hex(random_bytes(16)) . '.' . $ext;
$destPath    = $uploadsDir . '/' . $nomStockage;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    $redir('upload_error', 'Échec de la sauvegarde. Vérifiez que le dossier data/uploads/ est accessible en écriture.');
}

// Volumes effectués (optionnels)
$volCm = isset($_POST['volume_cm_effectue']) && $_POST['volume_cm_effectue'] !== ''
    ? max(0, (int)$_POST['volume_cm_effectue']) : null;
$volTd = isset($_POST['volume_td_effectue']) && $_POST['volume_td_effectue'] !== ''
    ? max(0, (int)$_POST['volume_td_effectue']) : null;
$commentaire = Security::sanitizeText($_POST['commentaire'] ?? '', 500);

// Enregistrer en base
$valRepo->addPreuve($ficheId, $file['name'], $nomStockage, $realMime, (int)$file['size'], $volCm, $volTd, $commentaire);
$security->audit('preuve_uploaded', $enseignant['matricule'], "fiche=$ficheId taille=" . $file['size']);

// Si appelé en AJAX depuis fiche_suivi, répondre JSON
if (!empty($_POST['source']) && $_POST['source'] === 'suivi') {
    // Recalculer les volumes effectués agrégés
    $preuvesMaj = $valRepo->getPreuves($ficheId);
    $cmEff = 0; $tdEff = 0;
    foreach ($preuvesMaj as $pm) {
        $cmEff += (int)($pm['volume_cm_effectue'] ?? 0);
        $tdEff += (int)($pm['volume_td_effectue'] ?? 0);
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'cmEff' => $cmEff ?: null, 'tdEff' => $tdEff ?: null]);
    exit;
}
$redir('upload_ok');
