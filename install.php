<?php
// ============================================================
// install.php — Script d'installation en un clic
// Accessible via : https://votre-domaine.bf/install.php
// A SUPPRIMER apres installation reussie
// ============================================================
declare(strict_types=1);

// Securite : bloquer l'acces si deja installe
if (file_exists(__DIR__ . '/data/.installed')) {
    die('<h2 style="font-family:sans-serif;color:#c00;padding:2rem">
        Installation deja effectuee.<br>
        <small style="color:#666;font-size:14px">Supprimez install.php du serveur.</small>
    </h2>');
}

$step    = 'form';
$errors  = [];
$success = [];

// ── Traitement du formulaire ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $dbHost   = trim($_POST['db_host']   ?? 'localhost') ?: 'localhost';
    $dbPort   = trim($_POST['db_port']   ?? '3306')      ?: '3306';
    $dbUser   = trim($_POST['db_user']   ?? 'root');
    $dbPass   = $_POST['db_pass']        ?? '';
    $dbName   = trim($_POST['db_name']   ?? 'fiches_programmatiques') ?: 'fiches_programmatiques';
    $adminPwd = $_POST['admin_pwd']      ?? '';
    $adminPwd2    = $_POST['admin_pwd2']     ?? '';
    $annee        = trim($_POST['annee']     ?? '2024-2025') ?: '2024-2025';
    $mailEnabled  = isset($_POST['mail_enabled']);
    $mailFrom     = trim($_POST['mail_from']     ?? '') ?: 'noreply@univ-fiches.bf';
    $mailFromName = trim($_POST['mail_from_name'] ?? '') ?: 'Fiches Programmatiques';
    $mailAppName  = trim($_POST['mail_app_name']  ?? '') ?: 'Fiches Programmatiques — Université';

    // Validations
    if (strlen($adminPwd) < 6) {
        $errors[] = 'Le mot de passe admin doit faire au moins 6 caracteres.';
    }
    if ($adminPwd !== $adminPwd2) {
        $errors[] = 'Les deux mots de passe admin ne correspondent pas.';
    }

    if (empty($errors)) {

        // 1. Tester la connexion MySQL
        try {
            $dsn = "mysql:host=$dbHost;port=$dbPort;charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $success[] = 'Connexion MySQL reussie.';
        } catch (PDOException $e) {
            $errors[] = 'Connexion MySQL impossible : ' . htmlspecialchars($e->getMessage());
        }
    }

    if (empty($errors)) {

        // 2. Creer la base de donnees
        try {
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName`
                        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$dbName`");
            $success[] = "Base de donnees « $dbName » prete.";
        } catch (PDOException $e) {
            $errors[] = 'Impossible de creer la base : ' . htmlspecialchars($e->getMessage());
        }
    }

    if (empty($errors)) {

        // 3. Creer les tables
        try {
            // Exécuter les migrations dans l'ordre
            foreach (['001_init.sql', '002_nouveaux_champs.sql', '003_validations_preuves.sql', '004_preuves_volumes.sql'] as $migFile) {
                $migPath = __DIR__ . '/migrations/' . $migFile;
                if (!file_exists($migPath)) continue;
                $sql = file_get_contents($migPath);
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                    if (!empty($stmt) && !preg_match('/^--/', $stmt) && !preg_match('/^SET\s+(NAMES|time_zone|foreign_key)/i', $stmt)) {
                        try { $pdo->exec($stmt); } catch (PDOException $e) {
                            // Ignorer: table déjà existante ou colonne déjà présente
                            $msg = $e->getMessage();
                            if (strpos($msg, 'already exists') === false && strpos($msg, 'Duplicate column') === false) {
                                throw $e;
                            }
                        }
                    }
                }
            }
            $success[] = 'Tables et colonnes créées (migrations 001 + 002 + 003).';
        } catch (PDOException $e) {
            $errors[] = 'Erreur creation tables : ' . htmlspecialchars($e->getMessage());
        }
    }

    if (empty($errors)) {

        // 4. Mettre a jour config/database.php
        $dbConfig = "<?php\ndeclare(strict_types=1);\nreturn [\n"
            . "    'host'     => '$dbHost',\n"
            . "    'port'     => '$dbPort',\n"
            . "    'dbname'   => '$dbName',\n"
            . "    'username' => '$dbUser',\n"
            . "    'password' => " . var_export($dbPass, true) . ",\n"
            . "    'charset'  => 'utf8mb4',\n"
            . "    'options'  => [\n"
            . "        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,\n"
            . "        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,\n"
            . "        PDO::ATTR_EMULATE_PREPARES   => false,\n"
            . "        PDO::MYSQL_ATTR_INIT_COMMAND => \"SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci\",\n"
            . "    ],\n];\n";

        file_put_contents(__DIR__ . '/config/database.php', $dbConfig);
        $success[] = 'config/database.php mis a jour.';

        // 5. Mettre a jour config/security.php
        $secret    = bin2hex(random_bytes(32));
        $adminHash = password_hash($adminPwd, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1,
        ]);

        $secConfig = require __DIR__ . '/config/security.php';
        $secConfig['app_secret']        = $secret;
        $secConfig['admin_hash']        = $adminHash;
        $secConfig['annee_academique']  = $annee;

        // Reecrire le fichier
        $secContent = "<?php\ndeclare(strict_types=1);\nreturn " . var_export($secConfig, true) . ";\n";
        file_put_contents(__DIR__ . '/config/security.php', $secContent);
        $success[] = 'config/security.php mis a jour (secret et mot de passe admin).';

        // 5c. Générer config/app.php
        $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir     = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
        $baseUrl = $scheme . '://' . $host . $dir;
        // Retirer install.php du chemin si présent
        $baseUrl = preg_replace('#/install\.php$#', '', $baseUrl);

        $appConfig = "<?php\ndeclare(strict_types=1);\nreturn [\n"
            . "    'base_url' => " . var_export($baseUrl, true) . ",\n"
            . "    'env'      => 'production',\n"
            . "    'debug'    => false,\n"
            . "];\n";
        file_put_contents(__DIR__ . '/config/app.php', $appConfig);
        $success[] = 'config/app.php généré (URL de base : ' . htmlspecialchars($baseUrl) . ').';

        // 5b. Mettre a jour config/mail.php
        $mailConfig = "<?php\ndeclare(strict_types=1);\nreturn [\n"
            . "    'enabled'      => " . ($mailEnabled ? 'true' : 'false') . ",\n"
            . "    'from_address' => " . var_export($mailFrom,     true) . ",\n"
            . "    'from_name'    => " . var_export($mailFromName,  true) . ",\n"
            . "    'app_name'     => " . var_export($mailAppName,   true) . ",\n"
            . "];\n";
        file_put_contents(__DIR__ . '/config/mail.php', $mailConfig);
        $success[] = 'config/mail.php mis a jour.';

        // 6. Creer le dossier data/ si absent
        if (!is_dir(__DIR__ . '/data')) {
            mkdir(__DIR__ . '/data', 0755, true);
        }
        if (!is_dir(__DIR__ . '/data/backups')) {
            mkdir(__DIR__ . '/data/backups', 0755, true);
        }

        // 7. Marquer comme installe
        file_put_contents(__DIR__ . '/data/.installed', date('Y-m-d H:i:s'));
        $success[] = 'Installation terminee avec succes !';

        $step = 'done';
    }
}

// Detecter les extensions manquantes
$extMissing = [];
foreach (['pdo', 'pdo_mysql', 'mbstring', 'openssl'] as $ext) {
    if (!extension_loaded($ext)) $extMissing[] = $ext;
}

?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Installation — Fiches Programmatiques</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Segoe UI,sans-serif;background:#f5f4f1;color:#1a1a18;font-size:15px;padding:2rem 1rem}
.box{max-width:560px;margin:0 auto;background:#fff;border:1px solid #e2e0d8;border-radius:12px;padding:2rem}
h1{font-size:22px;font-weight:600;margin-bottom:.25rem}
.sub{font-size:13px;color:#666;margin-bottom:1.5rem}
label{display:block;font-size:13px;color:#555;margin:.9rem 0 .3rem;font-weight:500}
input{width:100%;padding:9px 12px;border:1px solid #d5d3cc;border-radius:7px;font-size:14px}
input:focus{outline:none;border-color:#185FA5;box-shadow:0 0 0 3px rgba(24,95,165,.12)}
.grid{display:grid;grid-template-columns:2fr 1fr;gap:10px}
.btn{width:100%;padding:11px;background:#185FA5;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:500;cursor:pointer;margin-top:1.5rem}
.btn:hover{background:#0C447C}
.alert{padding:10px 14px;border-radius:7px;margin:.5rem 0;font-size:13px}
.err{background:#FCEBEB;color:#791F1F;border:1px solid #f09595}
.ok{background:#EAF3DE;color:#27500A;border:1px solid #c0dd97}
.warn{background:#FAEEDA;color:#633806;border:1px solid #fac775;margin-bottom:1rem}
.section{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:#888;margin:1.25rem 0 .5rem}
hr{border:none;border-top:1px solid #e8e6de;margin:1.25rem 0}
.done-icon{font-size:48px;text-align:center;margin-bottom:1rem}
.url{font-family:monospace;font-size:13px;background:#f5f4f1;padding:8px 12px;border-radius:6px;border:1px solid #e2e0d8;word-break:break-all;margin:.4rem 0}
</style>
</head>
<body>

<div class="box">

<?php if ($step === 'done'): ?>

    <div class="done-icon">&#10003;</div>
    <h1 style="text-align:center">Installation reussie !</h1>
    <p class="sub" style="text-align:center">L'application est prete a l'emploi.</p>

    <?php foreach ($success as $s): ?>
    <div class="alert ok"><?= htmlspecialchars($s) ?></div>
    <?php endforeach; ?>

    <hr>

    <div class="section">Acces a l'application</div>
    <div class="url">https://votre-domaine.bf/</div>
    <div class="url">https://votre-domaine.bf/admin.php</div>

    <div class="alert warn" style="margin-top:1rem">
        <strong>Securite :</strong> supprimez ce fichier <code>install.php</code> maintenant qu'il n'est plus necessaire.
        Sinon, n'importe qui peut reinitialiser l'application.
    </div>

<?php else: ?>

    <h1>Installation</h1>
    <p class="sub">Fiches Programmatiques — Configuration initiale</p>

    <?php if (!empty($extMissing)): ?>
    <div class="alert err">
        <strong>Extensions PHP manquantes :</strong> <?= implode(', ', array_map('htmlspecialchars', $extMissing)) ?><br>
        <small>Activez-les dans php.ini (retirer le ; devant chaque extension=...) puis redemarrez votre hébergeur.</small>
    </div>
    <?php endif; ?>

    <?php foreach ($errors as $e): ?>
    <div class="alert err"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>

    <form method="POST" autocomplete="off">

        <div class="section">Base de donnees MySQL</div>

        <div class="grid">
            <div>
                <label>Hote</label>
                <input type="text" name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>">
            </div>
            <div>
                <label>Port</label>
                <input type="text" name="db_port" value="<?= htmlspecialchars($_POST['db_port'] ?? '3306') ?>">
            </div>
        </div>

        <label>Nom de la base de donnees</label>
        <input type="text" name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? 'fiches_programmatiques') ?>">

        <label>Utilisateur MySQL</label>
        <input type="text" name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? 'root') ?>">

        <label>Mot de passe MySQL <small style="color:#888;font-weight:400">(vide si votre hébergeur par defaut)</small></label>
        <input type="password" name="db_pass" value="">

        <hr>
        <div class="section">Compte administrateur</div>

        <label>Mot de passe admin <small style="color:#888;font-weight:400">(6 caracteres minimum)</small></label>
        <input type="password" name="admin_pwd" value="" placeholder="Choisir un mot de passe fort">

        <label>Confirmer le mot de passe</label>
        <input type="password" name="admin_pwd2" value="">

        <hr>
        <div class="section">Parametres generaux</div>

        <label>Annee academique</label>
        <input type="text" name="annee" value="<?= htmlspecialchars($_POST['annee'] ?? '2024-2025') ?>" placeholder="2024-2025">

        <hr>
        <div class="section">Configuration des e-mails</div>

        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="checkbox" name="mail_enabled" <?= isset($_POST['mail_enabled']) ? 'checked' : 'checked' ?> style="width:auto">
            <span>Activer l'envoi de mails de confirmation</span>
        </label>
        <p style="font-size:12px;color:#888;margin:.3rem 0 .6rem">
            Requiert un serveur SMTP configuré dans php.ini (votre hébergeur utilise sendmail par défaut).
        </p>

        <label>Adresse expéditeur</label>
        <input type="email" name="mail_from"
               value="<?= htmlspecialchars($_POST['mail_from'] ?? 'noreply@univ-fiches.bf') ?>">

        <label>Nom affiché de l'expéditeur</label>
        <input type="text" name="mail_from_name"
               value="<?= htmlspecialchars($_POST['mail_from_name'] ?? 'Fiches Programmatiques') ?>">

        <label>Nom de l'application (dans les mails)</label>
        <input type="text" name="mail_app_name"
               value="<?= htmlspecialchars($_POST['mail_app_name'] ?? 'Fiches Programmatiques — Université') ?>">

        <button type="submit" class="btn" <?= !empty($extMissing) ? 'disabled' : '' ?>>
            Installer l'application
        </button>

    </form>

<?php endif; ?>

</div>
</body>
</html>
