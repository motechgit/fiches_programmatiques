<?php
// ============================================================
// vp_eip_dossiers.php — Affichage des fiches validées pour VP EIP
// Permet d'accéder aux dossiers vacataires et documents
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';
if (!class_exists('App')) require_once __DIR__ . '/src/App.php';
App::configureErrorDisplay();
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/ValidationRepository.php';

$config   = require __DIR__ . '/config/security.php';
$security = new Security($config);
$security->sendSecurityHeaders();
$security->startSecureSession();

if (!Auth::check()) { 
    header('Location: login.php'); 
    exit; 
}

if (!in_array(Auth::userRole(), ['vp_eip'], true)) {
    header('Location: portail.php?error=acces_refuse'); 
    exit;
}

$pdo       = Database::getInstance();
$valRepo   = new ValidationRepository();
$role      = Auth::userRole();
$e         = fn($v) => Security::e((string)$v);

// ══════════════════════════════════════════════════════════════
// RÉCUPÉRER LES ENSEIGNANTS AVEC FICHES VALIDÉES
// ══════════════════════════════════════════════════════════════

// Récupérer les enseignants avec au moins une fiche VACATAIRE validée
$stmt = $pdo->prepare(
    "SELECT DISTINCT 
        e.id, e.nom, e.prenom, e.matricule, e.grade, e.etab_beneficiaire,
        COUNT(f.id) AS nb_fiches_validees,
        MAX(f.annee_academique) AS derniere_annee
     FROM enseignants e
     JOIN fiches f ON f.enseignant_id = e.id
     WHERE f.type_workflow = 'VACATAIRE' 
       AND f.statut = 'validee'
       AND f.statut_vp_eip = 'valide'
     GROUP BY e.id
     ORDER BY e.etab_beneficiaire, e.nom, e.prenom"
);
$stmt->execute();
$enseignants = $stmt->fetchAll();

// Récupérer les fiches par enseignant
$fichesByEns = [];
foreach ($enseignants as $ens) {
    $stmtFiches = $pdo->prepare(
        "SELECT f.*, 
                (SELECT COUNT(*) FROM vacataire_dossier WHERE fiche_id = f.id) AS has_dossier
         FROM fiches f
         WHERE f.enseignant_id = ? 
           AND f.type_workflow = 'VACATAIRE'
           AND f.statut = 'validee'
         ORDER BY f.annee_academique DESC, f.id DESC"
    );
    $stmtFiches->execute([$ens['id']]);
    $fichesByEns[$ens['id']] = $stmtFiches->fetchAll();
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VP EIP - Dossiers Vacataires</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 8px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 {
            font-size: 28px;
            margin-bottom: 5px;
        }
        .header-nav a {
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            font-size: 13px;
            margin-left: 20px;
            transition: color 0.2s;
        }
        .header-nav a:hover {
            color: white;
        }
        .enseignant-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            overflow: hidden;
        }
        .enseignant-header {
            background: #f8f9fa;
            padding: 20px;
            border-bottom: 2px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .enseignant-info h3 {
            font-size: 18px;
            color: #333;
            margin-bottom: 5px;
        }
        .enseignant-info p {
            font-size: 13px;
            color: #666;
            margin: 2px 0;
        }
        .enseignant-meta {
            background: #e3f2fd;
            color: #1976d2;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .fiches-list {
            padding: 20px;
        }
        .fiche-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            border: 1px solid #e9ecef;
            border-radius: 4px;
            margin-bottom: 10px;
            transition: background-color 0.2s;
        }
        .fiche-row:hover {
            background-color: #f8f9fa;
        }
        .fiche-info {
            flex: 1;
        }
        .fiche-info strong {
            display: block;
            color: #333;
            margin-bottom: 5px;
        }
        .fiche-meta {
            font-size: 12px;
            color: #666;
        }
        .fiche-actions {
            display: flex;
            gap: 10px;
            margin-left: 20px;
        }
        .btn {
            display: inline-block;
            padding: 8px 14px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            font-size: 12px;
            text-decoration: none;
            transition: background-color 0.2s;
            white-space: nowrap;
        }
        .btn-primary {
            background: #007bff;
            color: white;
        }
        .btn-primary:hover {
            background: #0056b3;
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
        .btn-sm {
            padding: 6px 10px;
            font-size: 11px;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        .empty-state svg {
            font-size: 48px;
            margin-bottom: 20px;
        }
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            margin: 2px;
        }
        .badge-success {
            background: #d4edda;
            color: #155724;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <div>
            <h1>📋 Dossiers Vacataires Validés</h1>
            <p style="font-size: 13px; margin-top: 5px; opacity: 0.9;">Fiches programmatiques validées par le DEI</p>
        </div>
        <div class="header-nav">
            <a href="portail.php">← Retour au Portail</a>
        </div>
    </div>

    <?php if (empty($enseignants)): ?>
    
    <div class="empty-state">
        <div style="font-size: 48px; margin-bottom: 20px;">📭</div>
        <h2 style="font-size: 20px; color: #666; margin-bottom: 10px;">Aucun dossier validé</h2>
        <p>Il n'y a actuellement aucun dossier de vacataire validé par le DEI.</p>
    </div>

    <?php else: ?>

    <?php foreach ($enseignants as $ens): 
        $fiches = $fichesByEns[$ens['id']] ?? [];
    ?>
    
    <div class="enseignant-card">
        <div class="enseignant-header">
            <div class="enseignant-info">
                <h3>
                    <?= $e($ens['nom']) ?> <?= $e($ens['prenom']) ?>
                </h3>
                <p>
                    <strong>Grade :</strong> <?= $e($ens['grade']) ?> | 
                    <strong>Matricule :</strong> <?= $e($ens['matricule']) ?>
                </p>
                <p>
                    <strong>Établissement :</strong> <?= $e($ens['etab_beneficiaire'] ?? '—') ?>
                </p>
            </div>
            <div class="enseignant-meta">
                <?= count($fiches) ?> fiche(s) validée(s)
            </div>
        </div>

        <div class="fiches-list">
            <?php foreach ($fiches as $fiche): ?>
            <div class="fiche-row">
                <div class="fiche-info">
                    <strong><?= $e($fiche['cours']) ?></strong>
                    <div class="fiche-meta">
                        Année : <?= $e($fiche['annee_academique']) ?> | 
                        Semestre : <?= $e($fiche['semestre'] ?? '—') ?> |
                        Numéro : <?= $e($fiche['numero_fiche'] ?? '—') ?>
                    </div>
                    <?php if ($fiche['has_dossier']): ?>
                    <div style="margin-top: 5px;">
                        <span class="badge badge-success">✓ Dossier créé</span>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="fiche-actions">
                    <?php if ($fiche['has_dossier']): ?>
                    <a href="dossier_vacataire.php?ens_id=<?= (int)$ens['id'] ?>&annee=<?= urlencode($fiche['annee_academique']) ?>" 
                       class="btn btn-primary btn-sm" target="_blank">
                        📁 Dossier
                    </a>
                    <a href="dossier_vacataire.php?ens_id=<?= (int)$ens['id'] ?>&annee=<?= urlencode($fiche['annee_academique']) ?>&action=get_fiche" 
                       class="btn btn-secondary btn-sm" target="_blank">
                        📝 Demande
                    </a>
                    <a href="dossier_vacataire.php?ens_id=<?= (int)$ens['id'] ?>&annee=<?= urlencode($fiche['annee_academique']) ?>&action=get_acte" 
                       class="btn btn-secondary btn-sm" target="_blank">
                        📜 Acte
                    </a>
                    <?php else: ?>
                    <span style="color: #999; font-size: 12px;">Dossier en cours de création...</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php endforeach; ?>

    <?php endif; ?>

</div>

</body>
</html>
