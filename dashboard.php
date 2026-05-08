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
require_once __DIR__ . '/src/VacataireDossierRepository.php';
require_once __DIR__ . '/src/DemandeVacationGenerator.php';
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

// ── DÉTACHER les fiches par établissement bénéficiaire ─────────
// Si une fiche a des cours de différents établissements,
// créer une fiche logique par établissement pour l'affichage
$fiches = detacherFichesParlEtablissement($fiches);

// Historique de validation PAR FICHE
// Important : après détachement, charger les validations par fiche individuelle
$historiqueParFiche = [];  // [fiche_id => [role => validation]]
$pdo = Database::getInstance();
if (!empty($fiches)) {
    // Récupérer les IDs réels (y compris les fiches détachées qui partagent le même ID)
    $idsUniques = array_unique(array_column($fiches, 'id'));
    $ph  = implode(',', array_fill(0, count($idsUniques), '?'));
    $stH = $pdo->prepare(
        "SELECT v.fiche_id, v.role AS etape_role, v.decision, v.motif_rejet, v.created_at,
                u.nom AS valideur_nom
         FROM validations_fiche v
         JOIN utilisateurs u ON u.id = v.utilisateur_id
         WHERE v.fiche_id IN ($ph)
         ORDER BY v.fiche_id ASC, v.created_at ASC"
    );
    $stH->execute($idsUniques);
    foreach ($stH->fetchAll() as $h) {
        $fid = (int)$h['fiche_id'];
        $r = $h['etape_role'] ?? '';
        
        // Initialiser le fiche si nécessaire
        if (!isset($historiqueParFiche[$fid])) {
            $historiqueParFiche[$fid] = [];
        }
        
        // Garder la décision la plus récente par étape et fiche
        if (!isset($historiqueParFiche[$fid][$r]) || $h['decision'] === 'valide') {
            $historiqueParFiche[$fid][$r] = $h;
        }
    }
}

// Pour compatibilité avec le template, créer aussi $historiqueGlobal
// (certains templates peuvent encore l'utiliser)
$historiqueGlobal = [];
if (!empty($fiches)) {
    $ficheId = (int)($fiches[0]['id'] ?? 0);
    $historiqueGlobal = $historiqueParFiche[$ficheId] ?? [];
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
    // Fiches validées par DEI groupées par semestre (avec ou sans preuves)
    // ATTENTION: La fiche s'affiche en suivi seulement si elle est validée par DEI
    foreach ($fiches as $f) {
        $fid = (int)$f['id'];
        // Vérifier que la fiche est validée par le DEI
        $isDeiValidee = ($f['statut_dei'] ?? '') === 'validee';
        if (!$isDeiValidee) {
            // Fiche pas validée par DEI → pas d'onglet suivi
            continue;
        }
        $sem = $f['semestre'] ?? 'S1';
        if (!isset($fichesAvecPreuves[$sem])) $fichesAvecPreuves[$sem] = [];
        // Ajouter les preuves si elles existent
        $fDataWithProofs = $f;
        if (!empty($preuvesByFiche[$fid])) {
            $fDataWithProofs['preuves'] = $preuvesByFiche[$fid];
        } else {
            $fDataWithProofs['preuves'] = [];  // Pas de preuves, mais onglet visible
        }
        $fichesAvecPreuves[$sem][] = $fDataWithProofs;
    }
}

// Vue active : 'fiche' (défaut), 'cours', 'suivi_s1', 'suivi_s2', ou 'vacataire'
$vue = in_array($_GET['vue'] ?? '', ['fiche','cours','suivi_s1','suivi_s2','vacataire']) ? $_GET['vue'] : 'fiche';

// ── Récupérer les dossiers VACATAIRE ────────────────────────
$dossiersVacataire = [];
$pdo = Database::getInstance();
$vacatRepo = new VacataireDossierRepository($pdo);

// DEI : voir TOUS les dossiers en attente
// Enseignant : voir ses propres dossiers
if (Auth::isDei()) {
    $dossiersVacataire = $vacatRepo->getDossiersPendingDEI();
} else {
    $dossiersVacataire = $vacatRepo->getDossiersByEnseignant((int)$enseignant['id']);
}

// ── Générer les demandes de vacation pour TOUTES les fiches VACATAIRE ──
$demandesVacation = [];
$generateur = new DemandeVacationGenerator();
foreach ($fiches as $fiche) {
    if (($fiche['type_workflow'] ?? '') === 'VACATAIRE') {
        try {
            $html = $generateur->genererFicheHTML((int)$fiche['id'], (int)$enseignant['id']);
            $demandesVacation[(int)$fiche['id']] = $html;
        } catch (Exception $e) {
            error_log("⚠️ Erreur génération demande vacation fiche {$fiche['id']} : " . $e->getMessage());
            $demandesVacation[(int)$fiche['id']] = '<p style="color:red;">Erreur génération demande</p>';
        }
    }
}

$bodyContent = renderTemplate('dashboard', [
    'enseignant'         => $enseignant,
    'fiches'             => $fiches,
    'stats'              => $stats,
    'accessLink'         => $accessLink,
    'csrfToken'          => $csrfToken,
    'preuvesCounts'      => $preuvesCounts,
    'preuvesByFiche'     => $preuvesByFiche,
    'fichesAvecPreuves'  => $fichesAvecPreuves,
    'historiqueGlobal'   => $historiqueGlobal,
    'annee'              => $annee,
    'vue'                => $vue,
    'rawToken'           => $rawToken,
    'dossiersVacataire'  => $dossiersVacataire,
    'demandesVacation'   => $demandesVacation,
]);
echo renderLayout('Mon tableau de bord', $bodyContent, $csrfToken);

// ── Fonction de détachement des fiches par établissement ──────
function detacherFichesParlEtablissement(array $fiches): array
{
    /**
     * LOGIQUE : Si une fiche a des cours de différents établissements bénéficiaires,
     * créer une "fiche logique" par établissement pour l'affichage au tableau de bord.
     * 
     * Exemple :
     *   Fiche ID 5 : CM FSTB (Informatique) + TD CUP (Chimie)
     *   Après détachement : 
     *     - Fiche 5a (virtuelle) : CM FSTB (etab_id=2)
     *     - Fiche 5b (virtuelle) : TD CUP (etab_id=3)
     *
     * Les fiches virtuelles gardent l'ID original mais sont groupées par établissement.
     * Cela permet à l'enseignant de voir ses fiches organisées par lieu de dispensation.
     */
    if (empty($fiches)) return [];
    
    $pdo = Database::getInstance();
    $fichesDetachees = [];
    
    foreach ($fiches as $fiche) {
        $ficheId = (int)$fiche['id'];
        
        // Récupérer TOUS les établissements bénéficiaires de cette fiche
        $stmt = $pdo->prepare(
            "SELECT DISTINCT etab_beneficiaire_fiche 
             FROM fiches 
             WHERE id = ? AND etab_beneficiaire_fiche > 0"
        );
        $stmt->execute([$ficheId]);
        $etablissements = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        
        // Si une seule établissement (ou aucun) → afficher normalement
        if (count($etablissements) <= 1) {
            $fichesDetachees[] = $fiche;
            continue;
        }
        
        // Si plusieurs établissements → créer une "fiche virtuelle" par établissement
        // mais affichée avec l'ID original (la BD reste inchangée)
        foreach ($etablissements as $etabId) {
            $ficheVirtuelle = $fiche;
            $ficheVirtuelle['etab_beneficiaire_fiche'] = (int)$etabId;
            $ficheVirtuelle['est_detachee'] = true;  // Flag pour le template
            $ficheVirtuelle['source_fiche_id'] = $ficheId;  // Traçabilité
            $fichesDetachees[] = $ficheVirtuelle;
        }
    }
    
    return $fichesDetachees;
}

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
