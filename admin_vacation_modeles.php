<?php
/**
 * admin_vacation_modeles.php
 * Interface DEI : Édition des modèles (Demande vacation, Acte nomination)
 */

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/VacataireDossierRepository.php';

$auth = new Auth();
$auth->requireLogin();

$userRole = $_SESSION['user_role'] ?? '';

// Vérifier DEI
if ($userRole !== 'dei') {
    http_response_code(403);
    die('Accès refusé - DEI uniquement');
}

$db = Database::getInstance();
$repo = new VacataireDossierRepository($db);

$userId = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? '';
$modeleId = (int)($_GET['modele_id'] ?? 0);

// ════════════════════════════════════════════════════════════
// ACTION : Sauvegarder modèle
// ════════════════════════════════════════════════════════════
if ($action === 'save' && $_POST && $modeleId > 0) {
    $titre = $_POST['titre'] ?? '';
    $contenu = $_POST['contenu_html'] ?? '';
    
    if ($repo->updateModele($modeleId, $titre, $contenu, $userId)) {
        $_SESSION['message'] = 'Modèle mis à jour avec succès';
        header("Location: admin_vacation_modeles.php");
        exit;
    } else {
        $_SESSION['error'] = 'Erreur lors de la mise à jour';
    }
}

// Récupérer tous les modèles
$modeles = $repo->getAllModeles();

// Récupérer modèle à éditer
$modeleEdite = null;
if ($action === 'edit' && $modeleId > 0) {
    foreach ($modeles as $m) {
        if ($m['id'] === $modeleId) {
            $modeleEdite = $m;
            break;
        }
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Gestion modèles documents - Dossiers VACATAIRE</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { background: #fff; padding: 20px; border-radius: 4px; margin-bottom: 30px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .header h1 { margin: 0; color: #333; font-size: 28px; }
        .header p { margin: 10px 0 0 0; color: #666; font-size: 14px; }
        
        .grid { display: grid; grid-template-columns: 1fr 2fr; gap: 20px; }
        
        .sidebar { background: #fff; border-radius: 4px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); height: fit-content; }
        .sidebar h3 { margin: 0 0 15px 0; color: #333; font-size: 16px; border-bottom: 2px solid #00a651; padding-bottom: 10px; }
        .modele-item { padding: 12px; margin: 8px 0; border-left: 4px solid #ddd; background: #f9f9f9; cursor: pointer; border-radius: 2px; transition: all 0.2s; }
        .modele-item:hover { background: #f0f0f0; border-left-color: #00a651; }
        .modele-item.active { background: #e8f5ee; border-left-color: #00a651; font-weight: bold; }
        .modele-type { font-weight: bold; color: #333; display: block; }
        .modele-titre { font-size: 12px; color: #666; margin-top: 4px; }
        
        .editor { background: #fff; border-radius: 4px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .editor-header { margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #ddd; }
        .editor-header h2 { margin: 0; color: #333; font-size: 20px; }
        
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: bold; color: #333; }
        .form-group input[type="text"] { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
        .form-group textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-family: 'Monaco', 'Courier New', monospace; font-size: 13px; min-height: 600px; }
        
        .placeholders { background: #f9f9f9; padding: 15px; border-radius: 4px; margin-bottom: 20px; border-left: 4px solid #ffb81c; }
        .placeholders h4 { margin: 0 0 10px 0; color: #333; font-size: 14px; }
        .placeholder-list { list-style: none; padding: 0; margin: 0; font-size: 13px; }
        .placeholder-list li { padding: 4px 0; }
        .placeholder-code { background: #fff; padding: 2px 6px; border-radius: 2px; font-family: monospace; color: #d32f2f; }
        
        .buttons { display: flex; gap: 10px; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; }
        .btn { padding: 12px 24px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: bold; transition: all 0.2s; }
        .btn-primary { background: #00a651; color: white; }
        .btn-primary:hover { background: #008c3a; }
        .btn-secondary { background: #999; color: white; }
        .btn-secondary:hover { background: #777; }
        
        .message { padding: 12px 16px; border-radius: 4px; margin-bottom: 20px; }
        .message.success { background: #d1e7dd; color: #0f5132; border-left: 4px solid #198754; }
        .message.error { background: #f8d7da; color: #842029; border-left: 4px solid #dc3545; }
        
        .placeholder-hint { font-size: 12px; color: #666; margin-top: 10px; font-style: italic; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>🎓 Gestion modèles documents</h1>
        <p>Éditer les modèles de Demande vacation et Acte de nomination</p>
    </div>
    
    <?php if (isset($_SESSION['message'])): ?>
    <div class="message success"><?= htmlspecialchars($_SESSION['message']) ?></div>
    <?php unset($_SESSION['message']); endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
    <div class="message error"><?= htmlspecialchars($_SESSION['error']) ?></div>
    <?php unset($_SESSION['error']); endif; ?>
    
    <div class="grid">
        <!-- SIDEBAR : Liste modèles -->
        <div class="sidebar">
            <h3>📄 Modèles</h3>
            <?php foreach ($modeles as $m): ?>
            <div class="modele-item <?= ($modeleEdite && $modeleEdite['id'] === $m['id'] ? 'active' : '') ?>" 
                 onclick="window.location='?action=edit&modele_id=<?= $m['id'] ?>'">
                <span class="modele-type">
                    <?php if ($m['type'] === 'demande_vacation'): ?>
                        ✉️ Demande Vacation
                    <?php else: ?>
                        📋 Acte Nomination
                    <?php endif; ?>
                </span>
                <span class="modele-titre"><?= htmlspecialchars($m['titre']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- EDITOR : Édition modèle -->
        <div class="editor">
            <?php if ($modeleEdite): ?>
            <div class="editor-header">
                <h2><?= htmlspecialchars($modeleEdite['titre']) ?></h2>
                <p style="margin: 5px 0 0 0; color: #666; font-size: 13px;">
                    Modifié le <?= date('d/m/Y H:i', strtotime($modeleEdite['updated_at'])) ?>
                </p>
            </div>
            
            <div class="placeholders">
                <h4>📌 Placeholders disponibles</h4>
                <ul class="placeholder-list">
                    <li><span class="placeholder-code">{nom}</span> — Nom de l'enseignant</li>
                    <li><span class="placeholder-code">{prenom}</span> — Prénom</li>
                    <li><span class="placeholder-code">{grade}</span> — Grade / Titre</li>
                    <li><span class="placeholder-code">{diplome}</span> — Diplôme</li>
                    <li><span class="placeholder-code">{specialite}</span> — Spécialité</li>
                    <li><span class="placeholder-code">{telephone}</span> — Tél</li>
                    <li><span class="placeholder-code">{etablissement}</span> — Établissement de vacation</li>
                    <li><span class="placeholder-code">{annee_academique}</span> — Année académique</li>
                    <li><span class="placeholder-code">{date_jour}</span>, <span class="placeholder-code">{date_mois}</span>, <span class="placeholder-code">{date_annee}</span> — Composants date</li>
                    <li><span class="placeholder-code">{numero_acte}</span> — Numéro de l'acte (acte nomination seulement)</li>
                </ul>
                <p class="placeholder-hint">💡 Ces placeholders seront remplacés automatiquement avec les données réelles lors de la génération</p>
            </div>
            
            <form method="POST" action="?action=save&modele_id=<?= $modeleEdite['id'] ?>">
                <div class="form-group">
                    <label>Titre du modèle</label>
                    <input type="text" name="titre" value="<?= htmlspecialchars($modeleEdite['titre']) ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Contenu HTML</label>
                    <textarea name="contenu_html" required><?= htmlspecialchars($modeleEdite['contenu_html']) ?></textarea>
                </div>
                
                <div class="buttons">
                    <button type="submit" class="btn btn-primary">💾 Sauvegarder</button>
                    <a href="admin_vacation_modeles.php" class="btn btn-secondary">Annuler</a>
                </div>
            </form>
            
            <?php else: ?>
            <div style="color: #999; padding: 40px; text-align: center;">
                <p>Sélectionnez un modèle à éditer</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

</body>
</html>
