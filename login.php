<?php
declare(strict_types=1);
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';
if (!class_exists('App')) require_once __DIR__ . '/src/App.php';
App::configureErrorDisplay();
require_once __DIR__ . '/src/Auth.php';
$config   = require __DIR__ . '/config/security.php';
$security = new Security($config);
$security->sendSecurityHeaders();
$security->startSecureSession();
$csrfToken = $security->generateCsrfToken();

if (Auth::check()) { header('Location: portail.php'); exit; }
if (!empty($_SESSION['admin_authenticated'])) { header('Location: portail.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$security->verifyCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('CSRF invalide.'); }
    // Protection brute-force
    if (!$security->checkRateLimit('login')) {
        sleep(3);
        $error = 'Trop de tentatives. Veuillez patienter quelques minutes.';
    } else {
    $login    = Security::sanitizeText($_POST['login'] ?? '', 60);
    $password = $_POST['password'] ?? '';
    $user = Auth::login($login, $password);
    if ($user) {
        Auth::startSession($user);
        $security->audit('login_ok', null, "role={$user['role']} login=$login");
        header('Location: portail.php'); exit;
    }
    if ($login === $config['admin_user'] && password_verify($password, $config['admin_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin_authenticated'] = true;
        $_SESSION['admin_user']   = $login;
        $_SESSION['admin_since']  = time();
        $_SESSION['user_id']      = 0;
        $_SESSION['user_login']   = $login;
        $_SESSION['user_nom']     = 'Administrateur DEI';
        $_SESSION['user_role']    = 'dei';
        $_SESSION['user_dept']    = null;
        $_SESSION['user_etab']    = null;
        $_SESSION['user_since']   = time();
        $security->audit('login_ok', null, "role=dei (config) login=$login");
        header('Location: portail.php'); exit;
    }
    password_verify('dummy', '$argon2id$v=19$m=65536,t=4,p=1$ZHVtbXk$dummydummydummydummydummydum');
    $error = 'Identifiants incorrects. Veuillez réessayer.';
    $security->audit('login_fail', null, "login=$login");
    sleep(1);
    } // end rate limit check
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Connexion — Fiches Programmatiques UJKZ</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,sans-serif;background:linear-gradient(135deg,#004D27 0%,#006837 60%,#1a7a45 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1rem}
.container{width:100%;max-width:420px}
.card{background:#fff;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden}
.card-header{background:linear-gradient(135deg,#004D27,#006837);padding:2rem;text-align:center}
.logo-badge{width:64px;height:64px;background:#FFB300;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:900;color:#004D27;margin:0 auto 1rem;letter-spacing:-1px}
.logo-title{color:#fff;font-size:18px;font-weight:700;margin-bottom:4px}
.logo-sub{color:rgba(255,255,255,.75);font-size:13px}
.card-body{padding:2rem}
.alert-err{background:#FFF3F3;border:1px solid #FFCDD2;color:#C62828;padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:1.25rem;display:flex;gap:8px;align-items:center}
label{display:block;font-size:13px;font-weight:600;color:#1A2E1A;margin-bottom:5px;margin-top:16px}
input{width:100%;padding:11px 14px;border:1.5px solid #E2E6E2;border-radius:8px;font-size:14px;font-family:inherit;outline:none;transition:border-color .15s,box-shadow .15s}
input:focus{border-color:#006837;box-shadow:0 0 0 3px rgba(0,104,55,.12)}
.btn-login{width:100%;margin-top:1.75rem;padding:13px;background:#006837;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;transition:background .15s;font-family:inherit}
.btn-login:hover{background:#004D27}
.card-footer{background:#F8FAF8;border-top:1px solid #E2E6E2;padding:1rem 2rem;text-align:center}
.card-footer a{color:#006837;text-decoration:none;font-size:13px;font-weight:500}
.card-footer a:hover{text-decoration:underline}
.ujkz-bar{text-align:center;color:rgba(255,255,255,.65);font-size:12px;margin-top:1.25rem}
.ujkz-bar strong{color:#FFB300}
</style>
</head>
<body>
<div class="container">
  <div class="card">
    <div class="card-header">
      <div class="logo-badge">UJKZ</div>
      <div class="logo-title">Fiches Programmatiques</div>
      <div class="logo-sub">Université Joseph KI-ZERBO — Portail de gestion</div>
    </div>
    <div class="card-body">
      <?php if ($error): ?>
      <div class="alert-err">⚠ <?= Security::e($error) ?></div>
      <?php endif; ?>
      <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
        <label for="login">Identifiant</label>
        <input type="text" id="login" name="login" required autofocus autocomplete="off" maxlength="60" placeholder="votre.identifiant">
        <label for="password">Mot de passe</label>
        <input type="password" id="password" name="password" required autocomplete="off" placeholder="••••••••">
        <button type="submit" class="btn-login">Se connecter</button>
      </form>
    </div>
    <div class="card-footer">
      <a href="index.php">← Formulaire de dépôt des fiches (enseignants)</a>
    </div>
  </div>

</div>
</body></html>
