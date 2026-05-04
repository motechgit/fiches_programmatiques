#!/usr/bin/env php
<?php
// ============================================================
// scripts/restore.php — Restauration d'une sauvegarde MySQL
//   Usage : php scripts\restore.php [fichier.sql.gz]
//   Sans argument : liste les sauvegardes disponibles
// ============================================================
declare(strict_types=1);

$cfg     = require __DIR__ . '/../config/database.php';
$backDir = __DIR__ . '/../data/backups';
$backups = glob($backDir . '/backup_*.sql.gz') ?: [];
sort($backups);

// ── Sans argument : lister les sauvegardes ───────────────────
if (empty($argv[1])) {
    echo "\n=== Sauvegardes disponibles ===\n\n";
    if (empty($backups)) {
        echo "  Aucune sauvegarde trouvée dans $backDir\n";
        echo "  Lancez d'abord : php scripts\\backup.php\n\n";
        exit(0);
    }
    foreach (array_reverse($backups) as $i => $b) {
        $size = round(filesize($b) / 1024, 1);
        $date = preg_replace('/backup_(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})\.sql\.gz/', '$1-$2-$3 $4:$5:$6', basename($b));
        echo sprintf("  [%d] %s  (%s Ko)  %s\n", count($backups) - $i, basename($b), $size, $date);
    }
    echo "\nUsage : php scripts\\restore.php nom_du_fichier.sql.gz\n\n";
    exit(0);
}

// ── Avec argument : restaurer ────────────────────────────────
$target = $argv[1];
// Accepter le nom court ou le chemin complet
if (!file_exists($target)) {
    $target = $backDir . '/' . $argv[1];
}
if (!file_exists($target)) {
    echo "ERREUR : Fichier introuvable : $argv[1]\n";
    exit(1);
}

echo "\n⚠  ATTENTION : Cette opération va ÉCRASER toutes les données actuelles.\n";
echo "   Base de données : {$cfg['dbname']}\n";
echo "   Fichier        : " . basename($target) . "\n\n";
echo "   Tapez 'OUI' pour confirmer : ";
$confirm = trim(fgets(STDIN) ?: '');
if ($confirm !== 'OUI') {
    echo "Restauration annulée.\n";
    exit(0);
}

// Trouver mysql.exe
$mysqlPaths = [
    ...glob('C:/wamp64/bin/mysql/mysql*/bin/mysql.exe') ?: [],
    ...glob('C:/wamp/bin/mysql/mysql*/bin/mysql.exe') ?: [],
    'C:/xampp/mysql/bin/mysql.exe',
    'mysql',
];
$mysql = null;
foreach ($mysqlPaths as $path) {
    if (is_file($path) || (strpos($path, '/') === false && !empty(shell_exec("where $path 2>nul")))) {
        $mysql = $path;
        break;
    }
}
if (!$mysql) { echo "ERREUR : mysql.exe introuvable.\n"; exit(1); }

// Décompresser
echo "Décompression...\n";
$sql = gzdecode(file_get_contents($target));
if ($sql === false) { echo "ERREUR : Impossible de décompresser le fichier.\n"; exit(1); }

$tmpSql = sys_get_temp_dir() . '/fiches_restore_' . getmypid() . '.sql';
file_put_contents($tmpSql, $sql);

// Fichier .cnf temporaire
$cnfPath = sys_get_temp_dir() . '/fiches_restore_' . getmypid() . '.cnf';
file_put_contents($cnfPath, "[client]\npassword=" . $cfg['password'] . "\n");

$cmd = sprintf(
    '"%s" --defaults-extra-file="%s" --host=%s --port=%d --user=%s %s < "%s"',
    str_replace('/', DIRECTORY_SEPARATOR, $mysql),
    $cnfPath,
    escapeshellarg($cfg['host']),
    (int) $cfg['port'],
    escapeshellarg($cfg['username']),
    escapeshellarg($cfg['dbname']),
    $tmpSql
);

exec($cmd . ' 2>&1', $output, $retCode);
@unlink($cnfPath);
@unlink($tmpSql);

if ($retCode !== 0) {
    echo "ERREUR : Restauration échouée.\n";
    echo implode("\n", $output) . "\n";
    exit(1);
}

echo "✓ Restauration réussie depuis : " . basename($target) . "\n\n";
