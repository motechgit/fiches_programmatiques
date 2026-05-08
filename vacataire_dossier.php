<?php
/**
 * vacataire_dossier.php
 * Gestion des dossiers VACATAIRE : Consultation et validation DEI
 * 
 * WORKFLOW :
 * 1. VACATAIRE soumet fiche (diplôme + nomination uploadés)
 * 2. Demande vacation générée automatiquement
 * 3. Fiche validée : Chef → Dir adj → Dir → DEI
 * 4. Dossier créé avec statut 'complet'
 * 5. DEI valide dossier + génère acte
 * 6. VP EIP accès lecture seule (PAS de validation)
 */

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/FicheRepository.php';
require_once __DIR__ . '/src/VacataireDossierRepository.php';
require_once __DIR__ . '/src/DemandeVacationGenerator.php';
require_once __DIR__ . '/src/ValidationRepository.php';

$config = require __DIR__ . '/config/security.php';
$security = new Security($config);
$security->sendSecurityHeaders();
$security->startSecureSession();

// Vérifier authentification : token OU session
$rawToken = $_GET['token'] ?? '';
$db = Database::getInstance();

if (!Auth::check() && empty($rawToken)) {
    http_response_code(403);
    die('❌ Accès refusé - Authentification requise');
}

// Si token fourni, vérifier qu'il appartient à un enseignant
$enseignant = null;
$userRole = $_SESSION['user_role'] ?? '';  // Récupérer le rôle de session d'abord
$userId = (int)($_SESSION['user_id'] ?? 0);

if (!empty($rawToken)) {
    if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
        http_response_code(403);
        die('❌ Token invalide');
    }
    $ficheRepo = new \FicheRepository();
    $enseignant = $ficheRepo->findByToken($rawToken);
    if (!$enseignant) {
        http_response_code(403);
        die('❌ Accès refusé - Token non reconnu');
    }
    // Si token enseignant : pas de rôle spécial
    $userId = (int)$enseignant['id'];
    $userRole = ''; // Token enseignant n'a pas de rôle DEI/VP_EIP
} else {
    // Session active
    $userId = Auth::userId();
    if ($userId <= 0) {
        http_response_code(403);
        die('❌ Accès refusé - Connexion requise');
    }
    // userRole déjà récupéré depuis $_SESSION
}

$db = Database::getInstance();
$repo = new VacataireDossierRepository($db);

// Récupérer l'action et le dossier ID
$action = $_GET['action'] ?? '';
$dossierId = (int)($_GET['dossier_id'] ?? 0);

// ════════════════════════════════════════════════════════════
// ACTION : Afficher le dossier
// ════════════════════════════════════════════════════════════
if ($action === 'view' && $dossierId > 0) {
    $dossier = $repo->getDossier($dossierId);
    if (!$dossier) {
        http_response_code(404);
        die('❌ Dossier non trouvé');
    }
    
    // Vérifier accès : Enseignant VACATAIRE ou DEI ou VP EIP (lecture seule)
    $hasAccess = false;
    $isDeI = false;
    if ($userRole === 'dei') {
        $hasAccess = true; // DEI : accès complet et validation
        $isDeI = true;
    } elseif ($userRole === 'vp_eip') {
        $hasAccess = true; // VP EIP : accès en lecture seule
    } elseif ($dossier['enseignant_id'] == $userId) {
        $hasAccess = true; // Enseignant : voir son dossier
    }
    
    if (!$hasAccess) {
        http_response_code(403);
        die('❌ Accès refusé');
    }
    
    $documents = $repo->getDocuments($dossierId);
    $demandeVacation = $dossier['demande_vacation_html'] ?: '';
    $acteNomination = $dossier['acte_nomination_html'] ?? '';
    
    // Générer fiche programmatique + demande vacation pour DEI
    $ficheProgrammatique = '';
    if ($isDeI) {
        try {
            $genDemande = new DemandeVacationGenerator();
            $ficheProgrammatique = $genDemande->genererFicheHTML(
                (int)$dossier['fiche_id'],
                (int)$dossier['enseignant_id']
            );
        } catch (Exception $e) {
            error_log("⚠️ Erreur génération fiche pour dossier {$dossierId} : " . $e->getMessage());
            $ficheProgrammatique = '<p style="color:red;">Erreur génération fiche</p>';
        }
    }
    
    // Onglet par défaut
    $onglet = $_GET['onglet'] ?? 'info';
    
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Dossier Vacation - <?= htmlspecialchars($dossier['nom'].' '.$dossier['prenom']) ?></title>
        <style>
            * { box-sizing: border-box; }
            body { font-family: Arial, sans-serif; max-width: 1200px; margin: 0 auto; padding: 20px; background: #f5f5f5; }
            .header { background: #fff; padding: 20px; border-radius: 4px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
            .header h1 { margin: 0 0 20px 0; color: #333; }
            .info-row { display: flex; gap: 20px; margin-bottom: 15px; }
            .info-item { flex: 1; min-width: 200px; }
            .info-item label { font-weight: bold; color: #333; display: block; margin-bottom: 5px; }
            .info-item div { color: #666; }
            
            .tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid #ddd; background: #fff; border-radius: 4px 4px 0 0; }
            .tab { padding: 12px 20px; cursor: pointer; border: none; background: none; font-size: 14px; color: #666; border-bottom: 3px solid transparent; transition: all 0.2s; }
            .tab:hover { color: #00a651; }
            .tab.active { color: #00a651; border-bottom-color: #00a651; font-weight: bold; }
            
            .tab-content { background: #fff; padding: 30px; border-radius: 0 4px 4px 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); display: none; }
            .tab-content.active { display: block; }
            .tab-content h3 { margin: 20px 0 15px 0; color: #333; font-size: 16px; }
            .tab-content hr { margin: 30px 0; border: none; border-top: 1px solid #ddd; }
            
            .statut { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; }
            .statut-complet { background: #cfe2ff; color: #084298; }
            .statut-validee_dei { background: #d1e7dd; color: #0f5132; }
            .statut-rejetee { background: #f8d7da; color: #842029; }
            .statut-en_attente { background: #fff3cd; color: #856404; }
            .statut-valide { background: #d1e7dd; color: #0f5132; }
            .statut-rejete { background: #f8d7da; color: #842029; }
            
            .btn { padding: 10px 20px; margin: 5px 0; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: bold; transition: all 0.2s; }
            .btn-primary { background: #00a651; color: white; }
            .btn-primary:hover { background: #008c3a; }
            .btn-secondary { background: #999; color: white; }
            .btn-secondary:hover { background: #777; }
            .btn-danger { background: #d32f2f; color: white; }
            .btn-danger:hover { background: #b71c1c; }
            
            .document-list { list-style: none; padding: 0; }
            .document-item { background: #f9f9f9; padding: 12px; margin: 8px 0; border-left: 4px solid #00a651; border-radius: 2px; }
            .document-item strong { color: #00a651; }
            .document-item small { color: #999; }
            
            .demande-vacation-box { padding: 20px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 15px; }
            .message-info { background: #cfe2ff; border: 1px solid #084298; color: #084298; padding: 12px; border-radius: 4px; margin-bottom: 15px; }
            
            .section-separator { margin: 30px 0; padding-bottom: 20px; border-bottom: 2px solid #ddd; }
        </style>
    </head>
    <body>

    <div class="header">
        <h1>📋 Dossier Vacation</h1>
        <div class="info-row">
            <div class="info-item">
                <label>Enseignant</label>
                <div><?= htmlspecialchars($dossier['nom'].' '.$dossier['prenom']) ?></div>
            </div>
            <div class="info-item">
                <label>Année académique</label>
                <div><?= htmlspecialchars($dossier['annee_academique']) ?></div>
            </div>
            <div class="info-item">
                <label>Statut dossier</label>
                <div><span class="statut statut-<?= $dossier['statut_dossier'] ?>"><?= ucfirst(str_replace('_', ' ', $dossier['statut_dossier'])) ?></span></div>
            </div>
        </div>
    </div>

    <!-- TABS NAVIGATION -->
    <div class="tabs">
        <button class="tab <?= ($onglet === 'info' ? 'active' : '') ?>" onclick="selectTab('info')">ℹ️ Info</button>
        <?php if ($isDeI && !empty($ficheProgrammatique)): ?>
        <button class="tab <?= ($onglet === 'fiche' ? 'active' : '') ?>" onclick="selectTab('fiche')">📋 Fiche programmatique</button>
        <?php endif; ?>
        <button class="tab <?= ($onglet === 'demande' ? 'active' : '') ?>" onclick="selectTab('demande')">📄 Demande vacation</button>
        <?php if ($acteNomination || $userRole === 'dei'): ?>
        <button class="tab <?= ($onglet === 'acte' ? 'active' : '') ?>" onclick="selectTab('acte')">📋 Acte nomination</button>
        <?php endif; ?>
        <button class="tab <?= ($onglet === 'documents' ? 'active' : '') ?>" onclick="selectTab('documents')">📁 Documents</button>
    </div>

    <!-- ════════════════════════════════════════════════════════════ -->
    <!-- TAB 1 : INFO -->
    <!-- ════════════════════════════════════════════════════════════ -->
    <div class="tab-content <?= ($onglet === 'info' ? 'active' : '') ?>" id="tab-info">
        <h3>👤 Informations enseignant</h3>
        <div class="info-row">
            <div class="info-item">
                <label>Nom</label>
                <div><?= htmlspecialchars($dossier['nom']) ?></div>
            </div>
            <div class="info-item">
                <label>Prénom(s)</label>
                <div><?= htmlspecialchars($dossier['prenom']) ?></div>
            </div>
            <div class="info-item">
                <label>Téléphone</label>
                <div><?= htmlspecialchars($dossier['telephone'] ?? 'Non renseigné') ?></div>
            </div>
        </div>

        <div class="info-row">
            <div class="info-item">
                <label>Diplôme</label>
                <div><?= htmlspecialchars($dossier['diplome'] ?? '') ?></div>
            </div>
            <div class="info-item">
                <label>Spécialité</label>
                <div><?= htmlspecialchars($dossier['specialite'] ?? 'Non renseignée') ?></div>
            </div>
            <div class="info-item">
                <label>Grade</label>
                <div><?= htmlspecialchars($dossier['grade'] ?? '') ?></div>
            </div>
        </div>

        <div class="section-separator">
            <h3>📚 Fiche programmatique</h3>
            <div class="info-row">
                <div class="info-item">
                    <label>Numéro fiche</label>
                    <div style="font-weight: bold; color: #00a651; font-size: 16px;"><?= htmlspecialchars($dossier['numero_fiche'] ?? 'N/A') ?></div>
                </div>
                <div class="info-item">
                    <label>Année académique</label>
                    <div><?= htmlspecialchars($dossier['annee_academique']) ?></div>
                </div>
            </div>

            <h4>Enseignements confiés</h4>
            <p><?= htmlspecialchars($dossier['cours']) ?></p>

            <div class="message-info">
                ℹ️ La fiche programmatique a été validée par : Chef département → Directeur adjoint → Directeur → DEI
            </div>
        </div>

        <div class="section-separator">
            <h3>✉️ Demande de vacation</h3>
            <p style="color: #666; font-size: 13px; margin-bottom: 15px;">
                Cette demande a été générée automatiquement à partir des informations de la fiche programmatique.
            </p>
            <button class="btn btn-primary" onclick="selectTab('demande')">📄 Consulter la demande</button>
        </div>

        <div>
            <h3>📊 Statut dossier</h3>
            <div class="info-row">
                <div class="info-item">
                    <label>Statut</label>
                    <div><span class="statut statut-<?= $dossier['statut_dossier'] ?>"><?= ucfirst(str_replace('_', ' ', $dossier['statut_dossier'])) ?></span></div>
                </div>
                <div class="info-item">
                    <label>Validation DEI</label>
                    <div><span class="statut statut-<?= $dossier['statut_dei'] ?>"><?= ucfirst(str_replace('_', ' ', $dossier['statut_dei'])) ?></span></div>
                </div>
            </div>
        </div>

        <!-- ACTIONS DEI -->
        <?php if ($userRole === 'dei' && $dossier['statut_dei'] === 'en_attente'): ?>
        <div style="margin-top: 40px; padding: 20px; background: #f0f8ff; border-left: 4px solid #00a651; border-radius: 4px;">
            <h3 style="margin-top: 0; color: #00a651;">⚙️ Actions DEI</h3>
            <p style="color: #666; font-size: 13px; margin-bottom: 20px;">
                ✓ Seul le DEI peut valider le dossier et générer l'acte de nomination du vacataire
            </p>
            
            <form method="POST" action="vacataire_dossier.php?action=valider&dossier_id=<?= $dossierId ?>" style="display: inline;">
                <button type="submit" class="btn btn-primary" onclick="return confirm('Valider ce dossier et générer l\'acte de nomination ?')">
                    ✓ Valider et générer acte de nomination
                </button>
            </form>
            
            <form method="POST" action="vacataire_dossier.php?action=rejeter&dossier_id=<?= $dossierId ?>" style="display: inline; margin-left: 10px;">
                <button type="submit" class="btn btn-danger" onclick="return confirm('Êtes-vous sûr de rejeter ce dossier ?')">
                    ✗ Rejeter dossier
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <!-- ════════════════════════════════════════════════════════════ -->
    <!-- TAB 1B : FICHE PROGRAMMATIQUE (DEI UNIQUEMENT) -->
    <!-- ════════════════════════════════════════════════════════════ -->
    <?php if ($isDeI && !empty($ficheProgrammatique)): ?>
    <div class="tab-content <?= ($onglet === 'fiche' ? 'active' : '') ?>" id="tab-fiche">
        <h3>📋 Fiche programmatique validée</h3>
        <p style="color: #666; font-size: 13px; margin-bottom: 20px;">
            Consulter la fiche programmatique + demande de vacation de cet enseignant
        </p>
        
        <div style="padding: 20px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 20px;">
            <?= $ficheProgrammatique ?>
        </div>
        
        <div style="text-align: center; margin-top: 20px;">
            <button onclick="window.print()" class="btn btn-primary">🖨️ Imprimer fiche + demande</button>
        </div>
    </div>
    <?php endif; ?>

    <!-- ════════════════════════════════════════════════════════════ -->
    <!-- TAB 2 : DEMANDE VACATION -->
    <!-- ════════════════════════════════════════════════════════════ -->
    <div class="tab-content <?= ($onglet === 'demande' ? 'active' : '') ?>" id="tab-demande">
        <h3>📄 Demande de vacation</h3>
        <p style="color: #666; font-size: 13px; margin-bottom: 20px;">
            Générée automatiquement le <?= date('d/m/Y H:i', strtotime($dossier['created_at'])) ?>
        </p>
        
        <div class="demande-vacation-box">
            <?= $demandeVacation ?: '<p style="color: #999;">Demande en cours de génération...</p>' ?>
        </div>
        
        <div style="margin-top: 20px;">
            <a href="javascript:window.print()" class="btn btn-primary">🖨️ Imprimer</a>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════ -->
    <!-- TAB 3 : ACTE NOMINATION (DEI et après validation) -->
    <!-- ════════════════════════════════════════════════════════════ -->
    <?php if ($acteNomination || $userRole === 'dei'): ?>
    <div class="tab-content <?= ($onglet === 'acte' ? 'active' : '') ?>" id="tab-acte">
        <h3>📋 Acte de nomination</h3>
        
        <?php if ($acteNomination): ?>
            <p style="color: #666; font-size: 13px; margin-bottom: 20px;">
                ✓ Généré par le DEI le <?= date('d/m/Y H:i', strtotime($dossier['validated_by_dei_at'])) ?>
            </p>
            
            <div class="demande-vacation-box">
                <?= $acteNomination ?>
            </div>
            
            <div style="margin-top: 20px;">
                <a href="javascript:window.print()" class="btn btn-primary">🖨️ Imprimer</a>
            </div>
        <?php else: ?>
            <div class="message-info">
                ℹ️ L'acte de nomination sera généré après validation du dossier par le DEI
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ════════════════════════════════════════════════════════════ -->
    <!-- TAB 4 : DOCUMENTS JUSTIFICATIFS -->
    <!-- ════════════════════════════════════════════════════════════ -->
    <div class="tab-content <?= ($onglet === 'documents' ? 'active' : '') ?>" id="tab-documents">
        <h3>📁 Pièces jointes</h3>
        
        <?php if ($documents): ?>
            <ul class="document-list">
                <?php foreach ($documents as $doc): ?>
                <li class="document-item">
                    <strong>
                        <?php 
                        $typeLabel = [
                            'diplome' => '🎓 Diplôme',
                            'nomination_precedente' => '📜 Nomination précédente',
                            'autre' => '📄 Autre document'
                        ];
                        echo $typeLabel[$doc['type']] ?? ucfirst($doc['type']);
                        ?>
                    </strong><br>
                    📎 <?= htmlspecialchars($doc['nom_fichier']) ?> (<?= number_format($doc['taille_ko']) ?> KB)<br>
                    <small>Uploadé le <?= date('d/m/Y H:i', strtotime($doc['uploaded_at'])) ?></small>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p style="color: #999;">Aucun document joint pour le moment</p>
        <?php endif; ?>
    </div>

    <script>
    function selectTab(name) {
        // Masquer tous les onglets
        document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab').forEach(el => el.classList.remove('active'));
        
        // Afficher l'onglet sélectionné
        const tabEl = document.getElementById('tab-' + name);
        if (tabEl) {
            tabEl.classList.add('active');
        }
        
        // Marquer le bouton comme actif
        event.target.classList.add('active');
        
        // Mettre à jour l'URL
        const dossierId = <?= $dossierId ?>;
        window.history.replaceState({}, '', `?action=view&dossier_id=${dossierId}&onglet=${name}`);
    }
    </script>

    </body>
    </html>
    <?php
    exit;
}

// ════════════════════════════════════════════════════════════
// ACTION : Valider dossier (DEI)
// ════════════════════════════════════════════════════════════
if ($action === 'valider' && $dossierId > 0 && $_POST) {
    if ($userRole !== 'dei') {
        http_response_code(403);
        die('❌ Accès refusé - DEI uniquement');
    }
    
    if ($repo->validerDEI($dossierId, $userId)) {
        $_SESSION['message'] = '✅ Dossier validé et acte de nomination généré avec succès';
        header("Location: vacataire_dossier.php?action=view&dossier_id=$dossierId&onglet=acte");
        exit;
    } else {
        $_SESSION['error'] = '❌ Erreur lors de la validation';
        header("Location: vacataire_dossier.php?action=view&dossier_id=$dossierId");
        exit;
    }
}

// ════════════════════════════════════════════════════════════
// ACTION : Rejeter dossier (DEI)
// ════════════════════════════════════════════════════════════
if ($action === 'rejeter' && $dossierId > 0 && $_POST) {
    if ($userRole !== 'dei') {
        http_response_code(403);
        die('❌ Accès refusé - DEI uniquement');
    }
    
    if ($repo->rejeterDEI($dossierId, $userId)) {
        $_SESSION['message'] = '❌ Dossier rejeté';
        header("Location: vacataire_dossier.php?action=view&dossier_id=$dossierId");
        exit;
    } else {
        $_SESSION['error'] = '❌ Erreur lors du rejet';
        header("Location: vacataire_dossier.php?action=view&dossier_id=$dossierId");
        exit;
    }
}

// Redirection par défaut
header('Location: dashboard.php');
exit;
