<?php
// ============================================================
// admin_password.php — Changer le mot de passe admin
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';

$config   = require __DIR__ . '/config/security.php';
$security = new Security($config);

$security->sendSecurityHeaders();
$security->startSecureSession();
$csrfToken = $security->generateCsrfToken();

// Verifier session admin
if (empty($_SESSION['admin_authenticated'])) {
    header('Location: admin.php');
    exit;
}
if (isset($_SESSION['admin_since']) && time() - $_SESSION['admin_since'] > 1800) {
    unset($_SESSION['admin_authenticated'], $_SESSION['admin_user'], $_SESSION['admin_since']);
    header('Location: admin.php');
    exit;
}
$_SESSION['admin_since'] = time();

$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!$security->verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Token CSRF invalide.');
    }

    $current  = $_POST['current_pwd']  ?? '';
    $newPwd   = $_POST['new_pwd']      ?? '';
    $newPwd2  = $_POST['new_pwd2']     ?? '';

    if (!password_verify($current, $config['admin_hash'])) {
        $error = 'Mot de passe actuel incorrect.';
    } elseif (strlen($newPwd) < 6) {
        $error = 'Le nouveau mot de passe doit faire au moins 6 caracteres.';
    } elseif ($newPwd !== $newPwd2) {
        $error = 'Les deux nouveaux mots de passe ne correspondent pas.';
    } else {
        // Generer le nouveau hash et réécrire config/security.php
        $newHash   = password_hash($newPwd, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1,
        ]);
        $config['admin_hash'] = $newHash;
        $secContent = "<?php\ndeclare(strict_types=1);\nreturn " . var_export($config, true) . ";\n";
        file_put_contents(__DIR__ . '/config/security.php', $secContent);

        $security->audit('admin_password_changed');
        unset($_SESSION['csrf_token']);
        $csrfToken = $security->generateCsrfToken();
        $message = 'Mot de passe modifie avec succes.';
    }
}

ob_start();
?>
<div class="breadcrumb">
  <a href="admin.php">Administration</a>
  <span class="breadcrumb-sep">›</span>
  <span>Mot de passe</span>
</div>

<div class="page-hero" style="margin-bottom:1.5rem">
  <div>
    <h1>🔑 Changer le mot de passe</h1>
    <div class="subtitle">Compte administrateur — <?= Security::e($_SESSION['admin_user'] ?? '') ?></div>
  </div>
  <a href="admin.php" class="btn btn-sm" style="background:rgba(255,255,255,.15);color:#fff;border-color:rgba(255,255,255,.4)">← Administration</a>
</div>

<?php if ($message): ?>
<div class="alert alert-success"><?= Security::e($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger"><?= Security::e($error) ?></div>
<?php endif; ?>

<div class="card" style="max-width:480px;margin:0 auto">
  <div class="card-header"><div class="card-title">Modifier le mot de passe</div></div>
  <form method="POST" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
    <label>Mot de passe actuel <span style="color:var(--danger)">*</span></label>
    <input type="password" name="current_pwd" required autocomplete="current-password" placeholder="Votre mot de passe actuel">
    <label>Nouveau mot de passe <span style="color:var(--danger)">*</span></label>
    <input type="password" name="new_pwd" required minlength="6" autocomplete="new-password" placeholder="6 caractères minimum">
    <label>Confirmer le nouveau mot de passe <span style="color:var(--danger)">*</span></label>
    <input type="password" name="new_pwd2" required autocomplete="new-password" placeholder="Répéter le mot de passe">
    <div class="btn-group" style="margin-top:1.25rem">
      <button type="submit" class="btn btn-primary">Enregistrer</button>
      <a href="admin.php" class="btn">Annuler</a>
    </div>
  </form>
</div>
<?php

$body = ob_get_clean();

ob_start();
require __DIR__ . '/templates/layout.php';
echo ob_get_clean();
