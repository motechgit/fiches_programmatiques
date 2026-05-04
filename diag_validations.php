<?php
require_once __DIR__ . '/src/Database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = Database::getInstance();

echo "=== TABLE validations_fiche ===\n";
$rows = $pdo->query(
    "SELECT v.*, u.nom, u.role
     FROM validations_fiche v
     JOIN utilisateurs u ON u.id = v.utilisateur_id
     ORDER BY v.created_at DESC LIMIT 30"
)->fetchAll(PDO::FETCH_ASSOC);
if (empty($rows)) echo "  (vide)\n";
foreach ($rows as $r) {
    printf("  fiche_id=%d | role=%s | nom=%s | decision=%s | etape=%s | created=%s\n",
        $r['fiche_id'], $r['role']??'N/A', $r['nom'], $r['decision'], $r['etape']??'N/A', $r['created_at']);
}

echo "\n=== COLONNES DE validations_fiche ===\n";
foreach ($pdo->query("DESCRIBE validations_fiche")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo "  {$c['Field']} — {$c['Type']}\n";
}

echo "\n=== STATUTS DES FICHES ===\n";
foreach ($pdo->query("SELECT id, cours, statut, statut_chef, statut_dir_adj, statut_dir, statut_dei FROM fiches ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $f) {
    printf("  [%d] %s | global=%s | chef=%s | dir_adj=%s | dir=%s | dei=%s\n",
        $f['id'], $f['cours'], $f['statut'], $f['statut_chef'], $f['statut_dir_adj'], $f['statut_dir'], $f['statut_dei']);
}
