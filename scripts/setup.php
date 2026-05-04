#!/usr/bin/env php
<?php
// ============================================================
// scripts/setup.php — Configuration initiale MySQL + sécurité
//   Usage : php scripts\setup.php
// ============================================================
declare(strict_types=1);

echo "\n";
echo "╔══════════════════════════════════════════════════════╗\n";
echo "║      Fiches Programmatiques — Configuration         ║\n";
echo "╚══════════════════════════════════════════════════════╝\n\n";

// ── 1. Paramètres MySQL ──────────────────────────────────────
echo "1. CONNEXION MYSQL\n";
echo "   Hôte MySQL (défaut: 127.0.0.1) : ";
$dbHost = trim(fgets(STDIN) ?: '127.0.0.1') ?: '127.0.0.1';

echo "   Port MySQL (défaut: 3306) : ";
$dbPort = trim(fgets(STDIN) ?: '3306') ?: '3306';

echo "   Mot de passe root MySQL (laisser vide si absent) : ";
$rootPass = trim(fgets(STDIN) ?: '');

echo "   Nom de la base (défaut: fiches_programmatiques) : ";
$dbName = trim(fgets(STDIN) ?: 'fiches_programmatiques') ?: 'fiches_programmatiques';

echo "   Utilisateur dédié (défaut: fiches_user) : ";
$dbUser = trim(fgets(STDIN) ?: 'fiches_user') ?: 'fiches_user';

echo "   Mot de passe pour $dbUser (vide = généré auto) : ";
$dbPass = trim(fgets(STDIN) ?: '');
if (empty($dbPass)) {
    $dbPass = bin2hex(random_bytes(12));
    echo "   >> Mot de passe généré : $dbPass\n";
}

// ── 2. Connexion root ────────────────────────────────────────
echo "\n2. CRÉATION DE LA BASE ET DE L'UTILISATEUR\n";
try {
    $rootDsn = "mysql:host=$dbHost;port=$dbPort;charset=utf8mb4";
    $root = new PDO($rootDsn, 'root', $rootPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
    ]);
    echo "   ✓ Connexion root réussie\n";

    $root->exec("CREATE DATABASE IF NOT EXISTS `$dbName`
                 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "   ✓ Base `$dbName` prête\n";

    $root->exec("CREATE USER IF NOT EXISTS '$dbUser'@'localhost'
                 IDENTIFIED BY " . $root->quote($dbPass));
    $root->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON `$dbName`.*
                 TO '$dbUser'@'localhost'");
    $root->exec("FLUSH PRIVILEGES");
    echo "   ✓ Utilisateur `$dbUser` créé (droits: SELECT/INSERT/UPDATE/DELETE)\n";

} catch (PDOException $e) {
    echo "   ✗ Erreur : " . $e->getMessage() . "\n";
    echo "   >> Vérifiez que MySQL est démarré (icône WampServer verte).\n\n";
    exit(1);
}

// ── 3. Exécution du schéma SQL ───────────────────────────────
echo "\n3. CRÉATION DES TABLES\n";
try {
    $pdo = new PDO(
        "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4",
        $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $sql = file_get_contents(__DIR__ . '/../migrations/001_init.sql');
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if (!empty($stmt)) {
            try { $pdo->exec($stmt); } catch (PDOException $e) {
                if ($e->getCode() !== '42S01') throw $e;
            }
        }
    }

    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['enseignants','fiches','audit_log','rate_limit'] as $t) {
        echo "   " . (in_array($t, $tables) ? '✓' : '✗') . " Table `$t`\n";
    }

} catch (PDOException $e) {
    echo "   ✗ Erreur SQL : " . $e->getMessage() . "\n";
    exit(1);
}

// ── 4. Mise à jour de config/database.php ───────────────────
echo "\n4. MISE À JOUR DE config/database.php\n";
$configContent = "<?php\ndeclare(strict_types=1);\nreturn [\n"
    . "    'host'     => getenv('DB_HOST') ?: '$dbHost',\n"
    . "    'port'     => getenv('DB_PORT') ?: '$dbPort',\n"
    . "    'dbname'   => getenv('DB_NAME') ?: '$dbName',\n"
    . "    'username' => getenv('DB_USER') ?: '$dbUser',\n"
    . "    'password' => getenv('DB_PASS') ?: '$dbPass',\n"
    . "    'charset'  => 'utf8mb4',\n"
    . "    'options'  => [\n"
    . "        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,\n"
    . "        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,\n"
    . "        PDO::ATTR_EMULATE_PREPARES   => false,\n"
    . "        PDO::MYSQL_ATTR_INIT_COMMAND => \"SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci\",\n"
    . "    ],\n];\n";
file_put_contents(__DIR__ . '/../config/database.php', $configContent);
echo "   ✓ config/database.php mis à jour\n";

// ── 5. Secret applicatif ────────────────────────────────────
$appSecret = bin2hex(random_bytes(32));
$secPath    = __DIR__ . '/../config/security.php';
$secContent = file_get_contents($secPath);
$secContent = preg_replace(
    "/'app_secret' => getenv\('APP_SECRET'\) \?: '[^']*'/",
    "'app_secret' => getenv('APP_SECRET') ?: '$appSecret'",
    $secContent
);
file_put_contents($secPath, $secContent);

// ── 6. Hash mot de passe admin ──────────────────────────────
echo "\n5. MOT DE PASSE ADMINISTRATEUR\n";
echo "   Entrez le mot de passe admin : ";
$adminPass = trim(fgets(STDIN) ?: 'admin') ?: 'admin';
$adminHash = password_hash($adminPass, PASSWORD_ARGON2ID, [
    'memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1,
]);
echo "   Entrez l'identifiant admin (défaut: admin) : ";
$adminUser = trim(fgets(STDIN) ?: 'admin') ?: 'admin';

echo "\n══════════════════════════════════════════════════════\n";
echo "  RÉSUMÉ — À COLLER DANS httpd-vhosts.conf :\n";
echo "══════════════════════════════════════════════════════\n\n";
echo "  SetEnv APP_SECRET  \"$appSecret\"\n";
echo "  SetEnv DB_HOST     \"$dbHost\"\n";
echo "  SetEnv DB_PORT     \"$dbPort\"\n";
echo "  SetEnv DB_NAME     \"$dbName\"\n";
echo "  SetEnv DB_USER     \"$dbUser\"\n";
echo "  SetEnv DB_PASS     \"$dbPass\"\n";
echo "  SetEnv ADMIN_USER  \"$adminUser\"\n";
echo "  SetEnv ADMIN_HASH  \"$adminHash\"\n\n";
echo "  ✓ Installation terminée. Redémarrer Apache (WampServer).\n\n";
