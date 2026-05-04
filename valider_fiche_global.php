<?php
// ============================================================
// valider_fiche_global.php — Validation globale de toute la fiche
// Valide / rejette TOUS les enseignements d'un enseignant
// en une seule action (workflow IESR_UJKZ et IESR_HORS)
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';
if (!class_exists('App')) require_once __DIR__ . '/src/App.php';
App::configureErrorDisplay();
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/ValidationRepository.php';
require_once __DIR__ . '/src/Mailer.php';

$config   = require __DIR__ . '/config/security.php';
$security = new Security($config);
$security->sendSecurityHeaders();
$security->startSecureSession();

if (!Auth::check() && empty($_SESSION['admin_authenticated'])) {
    header('Location: login.php'); exit;
}
if (!Auth::check()) {
    $_SESSION['user_role']  = 'dei';
    $_SESSION['user_since'] = time();
    $_SESSION['user_id']    = 0;
    $_SESSION['user_dept']  = null;
    $_SESSION['user_etab']  = null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: portail.php'); exit;
}

if (!$security->verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403); die('Token CSRF invalide.');
}

$role     = Auth::userRole();
$ensId    = (int)($_POST['ens_id'] ?? 0);
$annee    = Security::sanitizeText($_POST['annee'] ?? '', 10);
$decision = $_POST['decision'] ?? '';
$motif    = Security::sanitizeText($_POST['motif'] ?? '', 500);

if ($ensId <= 0 || !in_array($decision, ['valide', 'rejete'], true)) {
    header('Location: portail.php?error=params'); exit;
}
if ($decision === 'rejete' && empty($motif)) {
    // Si rejet sans motif, rediriger vers un formulaire de motif
    header('Location: portail.php?ens=' . $ensId . '&error=motif_requis'); exit;
}

$repo = new ValidationRepository();

// Charger toutes les fiches validables de cet enseignant pour cette année
$pdo  = Database::getInstance();
// Appliquer le scope de validation selon le rôle et le workflow
[$sw, $sp] = Auth::ficheScope();
$stmt = $pdo->prepare(
    "SELECT f.*, e.nom AS ens_nom, e.matricule, e.token, e.email,
            e.departement, e.etab_beneficiaire, e.etab_administratif,
            e.etab_rattachement, e.grade, e.type_enseignant,
            e.id AS enseignant_id
     FROM fiches f
     JOIN enseignants e ON e.id = f.enseignant_id
     WHERE f.enseignant_id = ? AND f.annee_academique = ?
       AND f.is_encadrement = 0
       $sw
     ORDER BY f.semestre, f.id"
);
$stmt->execute(array_merge([$ensId, $annee ?: $config['annee_academique']], $sp));
$fiches = $stmt->fetchAll();

if (empty($fiches)) {
    header('Location: portail.php?ens=' . $ensId . '&error=aucune_fiche'); exit;
}

$userId = Auth::userId();
if ($userId === 0) {
    $stmtU = $pdo->prepare("SELECT id FROM utilisateurs WHERE role='dei' LIMIT 1");
    $stmtU->execute();
    $deiUser = $stmtU->fetch();
    $userId  = $deiUser ? (int)$deiUser['id'] : 1;
}

$nbTraitees = 0;
$ensEmail   = '';
$ensNom     = '';
$ensToken   = '';

foreach ($fiches as $fiche) {
    // Ne traiter que les fiches dans le périmètre ET validables
    if (!Auth::peutValider($role, $fiche)) continue;
    // Vérifier le périmètre (étab/dept bénéficiaire)
    $wfG = $fiche['type_workflow'] ?? 'IESR_UJKZ';
    if ($wfG !== 'IESR_UJKZ' && !in_array($role, ['dei','vp_eip'], true)) {
        if ($role === 'chef_dept') {
            $deptId = Auth::userDeptId();
            if ($deptId > 0 && (int)($fiche['dept_beneficiaire_fiche'] ?? 0) !== $deptId) continue;
        } elseif ($role === 'directeur_adjoint' || $role === 'directeur') {
            $etabIds = Auth::userEtabIds();
            if (!empty($etabIds) && !in_array((int)($fiche['etab_beneficiaire_fiche'] ?? 0), $etabIds, true)) continue;
        }
    }

    $repo->enregistrerDecision((int)$fiche['id'], $userId, $role, $decision, $motif);
    $nbTraitees++;

    // Garder infos pour la notification mail (une seule fois)
    if (empty($ensEmail)) {
        $ensEmail = $fiche['email']      ?? '';
        $ensNom   = $fiche['ens_nom']    ?? '';
        $ensToken = $fiche['token']      ?? '';
    }
}

// Audit
$security->audit(
    'fiche_global_' . $decision,
    '', // pas de matricule unique ici
    "Validation globale de $nbTraitees fiches (ens=$ensId) par " . Auth::userNom()
);

// Notification mail groupée (un seul mail pour toutes les fiches)
if (!empty($ensEmail) && $nbTraitees > 0) {
    $accessLink = App::url('dashboard.php?token=' . urlencode($ensToken));
    $coursTitre = "Toute la fiche programmatique ($nbTraitees cours)";
    (new Mailer())->sendNotificationDecision(
        $ensEmail, $ensNom, $coursTitre,
        $decision, $motif, $accessLink
    );
}

// Retour à la fiche actualisée dans le portail
// Retourner à la vue individuelle actualisée pour voir les badges mis à jour
header('Location: portail.php?ens=' . $ensId . '&success=' . $nbTraitees . '&refresh=1');
exit;
