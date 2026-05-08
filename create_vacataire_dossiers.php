<?php
/**
 * create_vacataire_dossiers.php
 * Créer les dossiers VACATAIRE pour les fiches validées par DEI
 */

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/VacataireDossierRepository.php';

$pdo = Database::getInstance();

echo "<h1>Création dossiers VACATAIRE</h1>";
echo "==========================<br><br>";

// Récupérer toutes les fiches VACATAIRE validées par DEI SANS dossier
$stmt = $pdo->query(
    "SELECT f.id, f.enseignant_id, f.type_workflow, f.annee_academique, f.numero_fiche
     FROM fiches f
     LEFT JOIN vacataire_dossier d ON f.id = d.fiche_id
     WHERE f.type_workflow = 'VACATAIRE'
       AND f.statut_dei = 'valide'
       AND d.id IS NULL
     ORDER BY f.id DESC"
);

$fiches = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($fiches)) {
    echo "✅ Aucune fiche VACATAIRE sans dossier<br>";
    echo "(Ou aucune fiche VACATAIRE validée trouvée)";
    exit;
}

echo "📋 Trouvé " . count($fiches) . " fiche(s) VACATAIRE à traiter<br><br>";

$repo = new VacataireDossierRepository($pdo);
$created = 0;
$failed = 0;

foreach ($fiches as $fiche) {
    echo "<div style='border: 1px solid #ddd; padding: 10px; margin: 10px 0;'>";
    echo "📄 Fiche ID: <strong>" . $fiche['id'] . "</strong> | ";
    echo "Numéro: " . ($fiche['numero_fiche'] ?? 'N/A') . "<br>";
    
    try {
        $dossierId = $repo->createDossierAfterValidation(
            (int)$fiche['id'],
            (int)$fiche['enseignant_id'],
            $fiche['annee_academique']
        );
        
        echo "✅ <strong style='color: green;'>Dossier créé : ID = $dossierId</strong>";
        $created++;
    } catch (Exception $e) {
        echo "❌ <strong style='color: red;'>Erreur : " . htmlspecialchars($e->getMessage()) . "</strong>";
        $failed++;
    }
    
    echo "</div>";
}

echo "<br><br>";
echo "<h3>Résumé</h3>";
echo "✅ Créés : $created<br>";
echo "❌ Erreurs : $failed<br>";
echo "<br>";

// Vérifier que les dossiers sont bien créés
echo "<h3>Vérification finale</h3>";
$checkStmt = $pdo->query(
    "SELECT COUNT(*) as nombre FROM vacataire_dossier"
);
$result = $checkStmt->fetch();
echo "Total dossiers en BD : <strong>" . $result['nombre'] . "</strong><br>";

if ($result['nombre'] > 0) {
    echo "<br>Détails :<br>";
    $detailStmt = $pdo->query(
        "SELECT id, fiche_id, enseignant_id, statut_dossier, statut_dei 
         FROM vacataire_dossier 
         ORDER BY id DESC LIMIT 10"
    );
    $dossiers = $detailStmt->fetchAll();
    
    foreach ($dossiers as $d) {
        echo "- ID: " . $d['id'] . " | Fiche: " . $d['fiche_id'] . " | Statut: " . $d['statut_dossier'] . " | DEI: " . $d['statut_dei'] . "<br>";
    }
}
?>
