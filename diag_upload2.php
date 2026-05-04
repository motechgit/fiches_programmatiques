<?php
require_once __DIR__ . '/src/Database.php';
$pdo = Database::getInstance();
header('Content-Type: text/plain; charset=utf-8');

echo "=== ENSEIGNANTS vacataires (colonnes fichiers) ===\n";
$rows = $pdo->query("SELECT id,nom,matricule,fichier_diplome,fichier_nomination FROM enseignants WHERE type_enseignant='vacataire'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "[{$r['id']}] {$r['nom']} ({$r['matricule']})\n";
    echo "   diplome=[{$r['fichier_diplome']}]\n";
    echo "   nomination=[{$r['fichier_nomination']}]\n";
}

echo "\n=== FICHIERS dans uploads/ ===\n";
foreach (glob(__DIR__.'/uploads/*') as $f) {
    if (basename($f) !== 'index.php')
        echo "  ".basename($f)." (".filesize($f)." octets)\n";
}
