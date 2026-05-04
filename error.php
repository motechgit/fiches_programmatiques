<?php
declare(strict_types=1);
$code = (int)($_SERVER['REDIRECT_STATUS'] ?? http_response_code() ?? 500);
$msgs = [403=>'Accès refusé',404=>'Page introuvable',500=>'Erreur serveur'];
$msg  = $msgs[$code] ?? 'Erreur';
http_response_code($code);
?><!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">
<title><?= $code ?> — UJKZ Fiches Programmatiques</title>
<style>
body{font-family:system-ui,sans-serif;background:#F0F2F0;display:flex;align-items:center;
     justify-content:center;min-height:100vh;margin:0}
.box{background:#fff;border-radius:12px;padding:2rem 2.5rem;text-align:center;
     box-shadow:0 4px 20px rgba(0,104,55,.12);max-width:420px}
.code{font-size:64px;font-weight:900;color:#006837;line-height:1}
h1{color:#1A2E1A;margin:.5rem 0}p{color:#5A6A5A;font-size:14px}
a{color:#006837;font-weight:500}
</style></head><body>
<div class="box">
  <div class="code"><?= $code ?></div>
  <h1><?= htmlspecialchars($msg) ?></h1>
  <p>Une erreur s'est produite. Veuillez réessayer ou contacter l'administrateur.</p>
  <p><a href="/">← Retour à l'accueil</a></p>
</div></body></html>
