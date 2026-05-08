<?php
// ============================================================
// admin_acte_modeles.php — Gestion des modèles d'acte
// Permet de modifier le modèle d'acte de nomination via un éditeur enrichi
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';
if (!class_exists('App')) require_once __DIR__ . '/src/App.php';
App::configureErrorDisplay();

$config   = require __DIR__ . '/config/security.php';
$security = new Security($config);
$security->sendSecurityHeaders();
$security->startSecureSession();

$csrfToken = $security->generateCsrfToken();

// Vérification d'authentification admin
$isAuth = !empty($_SESSION['admin_authenticated']);
if (!$isAuth) {
    header('Location: login.php');
    exit;
}

// Renouveler le timeout
if (isset($_SESSION['admin_since'])) {
    if (time() - $_SESSION['admin_since'] > 1800) {
        unset($_SESSION['admin_authenticated'], $_SESSION['admin_user'], $_SESSION['admin_since']);
        header('Location: login.php');
        exit;
    }
    $_SESSION['admin_since'] = time();
}

$message = '';
$messageType = '';
$pdo = Database::getInstance();

// ══════════════════════════════════════════════════════════════
// TRAITEMENT DU FORMULAIRE
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$security->verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Token CSRF invalide.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save_acte') {
        $modele_html = $_POST['modele_html'] ?? '';

        if (empty($modele_html)) {
            $message = '❌ Le modèle HTML ne peut pas être vide.';
            $messageType = 'error';
        } else {
            try {
                // Vérifier si un enregistrement existe
                $stmt = $pdo->prepare("SELECT id FROM parametres WHERE cle = 'acte_modele'");
                $stmt->execute();
                $existe = $stmt->fetch();

                if ($existe) {
                    // Mettre à jour
                    $updateStmt = $pdo->prepare(
                        "UPDATE parametres SET valeur = ?, updated_at = NOW() WHERE cle = 'acte_modele'"
                    );
                    $updateStmt->execute([$modele_html]);
                } else {
                    // Insérer
                    $insertStmt = $pdo->prepare(
                        "INSERT INTO parametres (cle, valeur, created_at, updated_at) VALUES ('acte_modele', ?, NOW(), NOW())"
                    );
                    $insertStmt->execute([$modele_html]);
                }

                $security->audit('admin_acte_modele_save', null, 'Modèle d\'acte sauvegardé');
                $message = '✅ Modèle d\'acte de nomination mis à jour avec succès.';
                $messageType = 'success';

                // Invalider et régénérer le CSRF
                unset($_SESSION['csrf_token']);
                $csrfToken = $security->generateCsrfToken();
            } catch (Exception $e) {
                $message = '❌ Erreur lors de la sauvegarde : ' . htmlspecialchars($e->getMessage());
                $messageType = 'error';
            }
        }
    } elseif ($action === 'reset_acte') {
        try {
            // Supprimer pour revenir au modèle par défaut
            $stmt = $pdo->prepare("DELETE FROM parametres WHERE cle = 'acte_modele'");
            $stmt->execute();

            $security->audit('admin_acte_modele_reset', null, 'Modèle d\'acte réinitialisé');
            $message = '✅ Modèle d\'acte réinitialisé au modèle par défaut.';
            $messageType = 'success';

            // Invalider et régénérer le CSRF
            unset($_SESSION['csrf_token']);
            $csrfToken = $security->generateCsrfToken();
        } catch (Exception $e) {
            $message = '❌ Erreur lors de la réinitialisation : ' . htmlspecialchars($e->getMessage());
            $messageType = 'error';
        }
    }
}

// ══════════════════════════════════════════════════════════════
// RÉCUPÉRER LE MODÈLE ACTUEL
// ══════════════════════════════════════════════════════════════
$modeleActuel = '';
try {
    $stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'acte_modele'");
    $stmt->execute();
    $result = $stmt->fetch();
    if ($result) {
        $modeleActuel = $result['valeur'];
    }
} catch (Exception $e) {
    // La table n'existe pas encore, ignoré
}

// ══════════════════════════════════════════════════════════════
// AFFICHER LA PAGE
// ══════════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion Modèle Acte de Nomination</title>
    <link rel="stylesheet" href="styles.css">
    <!-- TinyMCE pour l'éditeur enrichi -->
    <script src="https://cdn.tiny.cloud/1/no-api-key/tinymce/6/tinymce.min.js"></script>
    <script>
        tinymce.init({
            selector: '#modele_html',
            height: 600,
            plugins: 'link image table lists code help wordcount',
            toolbar: 'undo redo | formatselect | bold italic underline strikethrough | alignleft aligncenter alignright | bullist numlist | link image table | code help',
            language: 'fr_FR',
            content_css: [],
            body_class: '',
            relative_urls: false,
            remove_script_host: false,
            convert_urls: false,
            setup: function(editor) {
                editor.on('init', function() {
                    // Redimensionner l'iframe après l'initialisation
                    var iframe = document.querySelector('#modele_html_ifr');
                    if (iframe) {
                        iframe.style.height = '600px';
                    }
                });
            }
        });
    </script>
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
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 30px;
        }
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
        }
        .subtitle {
            color: #666;
            font-size: 14px;
            margin-bottom: 30px;
        }
        .alert {
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            border-left: 4px solid;
        }
        .alert.success {
            background: #d4edda;
            color: #155724;
            border-color: #28a745;
        }
        .alert.error {
            background: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: background-color 0.2s;
            text-decoration: none;
        }
        .btn-primary {
            background: #007bff;
            color: white;
        }
        .btn-primary:hover {
            background: #0056b3;
        }
        .btn-danger {
            background: #dc3545;
            color: white;
            margin-left: 10px;
        }
        .btn-danger:hover {
            background: #c82333;
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        .form-actions {
            margin-top: 30px;
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid #2196F3;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            font-size: 13px;
            color: #0c5460;
        }
        .header-bar {
            background: #2c3e50;
            color: white;
            padding: 20px;
            margin: -30px -30px 30px -30px;
            border-radius: 8px 8px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header-bar a {
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            font-size: 13px;
            margin-left: 15px;
        }
        .header-bar a:hover {
            color: white;
        }
        .tox-tinymce {
            border: 1px solid #ddd !important;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header-bar">
        <h1 style="margin: 0; color: white;">⚙️ Gestion Modèle d'Acte de Nomination</h1>
        <div>
            <a href="admin.php">Dashboard</a>
            <a href="admin.php?logout=1">Déconnexion</a>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="alert <?= $messageType ?>">
        <?= $message ?>
    </div>
    <?php endif; ?>

    <div class="subtitle">
        Modifiez le modèle HTML de l'acte de nomination qui est utilisé pour tous les nouveaux actes générés.
    </div>

    <div class="info-box">
        <strong>ℹ️ Information :</strong><br>
        Ce modèle est utilisé pour générer les actes de nomination des vacataires. 
        Les modifications s'appliqueront à tous les nouveaux actes générés après la sauvegarde.
        Les actes déjà générés ne seront pas affectés.
    </div>

    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
        <input type="hidden" name="action" value="save_acte">

        <div class="form-group">
            <label for="modele_html">Modèle HTML de l'Acte</label>
            <textarea id="modele_html" name="modele_html" class="form-control"><?= htmlspecialchars($modeleActuel) ?></textarea>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">💾 Enregistrer le modèle</button>
            <button type="button" class="btn btn-danger" onclick="confirmReset()">🔄 Réinitialiser au modèle par défaut</button>
        </div>
    </form>

    <!-- Formulaire caché pour la réinitialisation -->
    <form id="resetForm" method="POST" action="" style="display: none;">
        <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
        <input type="hidden" name="action" value="reset_acte">
    </form>

    <script>
        function confirmReset() {
            if (confirm('⚠️ Êtes-vous sûr de vouloir réinitialiser le modèle au modèle par défaut ?\n\nCette action ne peut pas être annulée.')) {
                document.getElementById('resetForm').submit();
            }
        }
    </script>
</div>

</body>
</html>
<?php
require_once __DIR__ . '/templates/layout.php';
?>
