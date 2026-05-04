#!/usr/bin/env php
<?php
// ============================================================
// scripts/backup.php — Sauvegarde MySQL (Linux/cPanel)
//   Usage : php scripts/backup.php
// ============================================================
declare(strict_types=1);
require_once __DIR__ . '/../src/Database.php';

$cfg = require __DIR__ . '/../config/database.php';

// Trouver mysqldump (Linux/cPanel)
$mysqldump = trim(shell_exec('which mysqldump 2>/dev/null') ?: '');
if (empty($mysqldump) || !is_executable($mysqldump)) {
    // Chemins communs sur hébergement mutualisé
    foreach (['/usr/bin/mysqldump', '/usr/local/bin/mysqldump', '/usr/local/mysql/bin/mysqldump'] as $p) {
        if (is_executable($p)) { $mysqldump = $p; break; }
    }
}
if (empty($mysqldump)) {
    echo "mysqldump introuvable. Vérifiez le PATH ou contactez votre hébergeur.\n";
    exit(1);
}

$dataDir = __DIR__ . '/../data/backups';
if (!is_dir($dataDir)) mkdir($dataDir, 0750, true);

$filename = 'backup_' . date('Ymd_His') . '.sql';
$path     = $dataDir . '/' . $filename;

$cmd = sprintf(
    '%s --host=%s --port=%s --user=%s --password=%s %s > %s 2>&1',
    escapeshellarg($mysqldump),
    escapeshellarg($cfg['host']),
    escapeshellarg($cfg['port']),
    escapeshellarg($cfg['username']),
    escapeshellarg($cfg['password']),
    escapeshellarg($cfg['dbname']),
    escapeshellarg($path)
);

exec($cmd, $output, $code);

if ($code === 0 && file_exists($path) && filesize($path) > 100) {
    echo "Sauvegarde : $path (" . round(filesize($path)/1024,1) . " Ko)\n";
} else {
    echo "Erreur lors de la sauvegarde (code $code).\n";
    if (file_exists($path)) echo file_get_contents($path);
    exit(1);
}
