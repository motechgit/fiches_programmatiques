<?php
// ============================================================
// config/mail.php — Configuration SMTP pour production
// ============================================================
declare(strict_types=1);

return [
    'enabled'      => true,

    // Adresse expéditeur officielle de l'université
    'from_address' => 'noreply@ujkz.bf',
    'from_name'    => 'Fiches Programmatiques UJKZ',
    'app_name'     => 'Fiches Programmatiques — UJKZ',

    // ── SMTP (recommandé en production) ──
    // Si votre hébergeur supporte SMTP natif via php.ini, laisser smtp_enabled à false.
    // Sinon, activer et renseigner les paramètres SMTP ci-dessous.
    'smtp_enabled' => false,
    'smtp_host'    => 'mail.ujkz.bf',       // serveur SMTP de l'université
    'smtp_port'    => 587,                   // 587 (TLS) ou 465 (SSL)
    'smtp_user'    => 'noreply@ujkz.bf',
    'smtp_pass'    => 'MOT_DE_PASSE_SMTP',
    'smtp_secure'  => 'tls',                 // 'tls' ou 'ssl'
];
