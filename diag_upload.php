<?php
require_once __DIR__ . '/src/Database.php';
$pdo = Database::getInstance();
header('Content-Type: text/plain; charset=utf-8');
echo "=== ENSEIGNANTS vacataires ===\n";
$rows = $pdo->query("SELECT id, nom, matricule, type_enseignant, fichier_diplome, fichier_nomination FROM enseignants WHERE type_enseignant='vacataire'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "[{$r['id']}] {$r['nom']} ({$r['matricule']}) | diplome=[{$r['fichier_diplome']}] | nomination=[{$r['fichier_nomination']}]\n";
}
echo "\n=== FICHIERS dans uploads/ ===\n";
$files = glob(__DIR__.'/uploads/*');
foreach ($files as $f) {
    if (basename($f) !== 'index.php') echo "  ".basename($f)."\n";
}
