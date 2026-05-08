<?php
/**
 * debug_vacataire_creation.php
 * Tracer la création du dossier VACATAIRE
 */

require_once __DIR__ . '/src/Database.php';

$pdo = Database::getInstance();

echo "<h1>DEBUG VACATAIRE DOSSIER</h1>";
echo "======================<br><br>";

// 1️⃣ Vérifier les fiches VACATAIRE
echo "<h3>1️⃣ Fiches VACATAIRE en BD</h3>";
try {
    $stmt = $pdo->query(
        "SELECT id, enseignant_id, type_workflow,
                statut_chef, statut_dir_adj, statut_dir, statut_dei, 
                numero_fiche, annee_academique
         FROM fiches 
         WHERE type_workflow = 'VACATAIRE'
         ORDER BY id DESC LIMIT 5"
    );
    $vacFiches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    echo "❌ Erreur SQL : " . $e->getMessage() . "<br>";
    echo "<br><strong>Colonnes disponibles dans fiches :</strong><br>";
    $colStmt = $pdo->query(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
         WHERE TABLE_NAME = 'fiches' ORDER BY ORDINAL_POSITION"
    );
    $cols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($cols as $col) {
        echo "- " . $col . "<br>";
    }
    $vacFiches = [];
}

if (empty($vacFiches)) {
    echo "❌ AUCUNE fiche VACATAIRE trouvée<br>";
} else {
    echo "✅ " . count($vacFiches) . " fiche(s) VACATAIRE trouvée(s)<br><br>";
    foreach ($vacFiches as $f) {
        echo "<div style='border: 1px solid #ddd; padding: 10px; margin: 10px 0;'>";
        echo "📋 Fiche ID: <strong>" . $f['id'] . "</strong><br>";
        echo "📄 Numéro: " . ($f['numero_fiche'] ?? 'N/A') . "<br>";
        echo "👤 Enseignant ID: " . $f['enseignant_id'] . "<br>";
        echo "🔷 Type: " . $f['type_workflow'] . " / " . $f['type_enseignant'] . "<br>";
        echo "✅ Statut Chef: " . $f['statut_chef'] . "<br>";
        echo "✅ Statut Dir Adj: " . $f['statut_dir_adj'] . "<br>";
        echo "✅ Statut Dir: " . $f['statut_dir'] . "<br>";
        echo "✅ Statut DEI: " . $f['statut_dei'] . "<br>";
        echo "📅 Année: " . $f['annee_academique'] . "<br>";
        
        // Vérifier si dossier existe
        $stmtDoc = $pdo->prepare("SELECT id FROM vacataire_dossier WHERE fiche_id = ?");
        $stmtDoc->execute([$f['id']]);
        $dossier = $stmtDoc->fetch();
        
        if ($dossier) {
            echo "📁 <strong style='color: green;'>✅ Dossier EXISTS : ID=" . $dossier['id'] . "</strong><br>";
        } else {
            echo "📁 <strong style='color: red;'>❌ Dossier MANQUANT</strong><br>";
        }
        echo "</div>";
    }
}

// 2️⃣ Vérifier les dossiers VACATAIRE
echo "<br><h3>2️⃣ Dossiers VACATAIRE en BD</h3>";
$stmt2 = $pdo->query(
    "SELECT d.id, d.fiche_id, d.enseignant_id, d.statut_dossier, d.statut_dei, d.created_at
     FROM vacataire_dossier d
     ORDER BY id DESC LIMIT 5"
);
$dossiers = $stmt2->fetchAll(PDO::FETCH_ASSOC);

if (empty($dossiers)) {
    echo "❌ AUCUN dossier VACATAIRE en BD<br>";
} else {
    echo "✅ " . count($dossiers) . " dossier(s) trouvé(s)<br><br>";
    foreach ($dossiers as $d) {
        echo "<div style='border: 1px solid green; padding: 10px; margin: 10px 0;'>";
        echo "📁 Dossier ID: <strong>" . $d['id'] . "</strong><br>";
        echo "📋 Fiche ID: " . $d['fiche_id'] . "<br>";
        echo "👤 Enseignant ID: " . $d['enseignant_id'] . "<br>";
        echo "📊 Statut Dossier: " . $d['statut_dossier'] . "<br>";
        echo "✅ Statut DEI: " . $d['statut_dei'] . "<br>";
        echo "📅 Créé: " . $d['created_at'] . "<br>";
        echo "</div>";
    }
}

// 3️⃣ Vérifier les logs
echo "<br><h3>3️⃣ Logs PHP (dernier 50 lignes)</h3>";
$logFile = ini_get('error_log');
if ($logFile && file_exists($logFile)) {
    $lines = file($logFile);
    $lastLines = array_slice($lines, -50);
    
    // Filtrer les lignes contenant "Dossier VACATAIRE" ou "Erreur"
    $relevant = array_filter($lastLines, function($line) {
        return stripos($line, 'dossier') !== false || 
               stripos($line, 'vacataire') !== false ||
               stripos($line, 'erreur') !== false ||
               stripos($line, 'exception') !== false;
    });
    
    if (!empty($relevant)) {
        echo "<pre style='background: #f0f0f0; padding: 10px; border-radius: 4px;'>";
        foreach ($relevant as $line) {
            echo htmlspecialchars(trim($line)) . "\n";
        }
        echo "</pre>";
    } else {
        echo "<p style='color: #999;'>Aucune ligne pertinente dans les logs</p>";
    }
} else {
    echo "<p style='color: #999;'>Fichier log non trouvé ou non accessible</p>";
}

// 4️⃣ Vérifier la table vacataire_dossier
echo "<br><h3>4️⃣ Structure table vacataire_dossier</h3>";
$stmt3 = $pdo->query(
    "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
     FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_NAME = 'vacataire_dossier'
     ORDER BY ORDINAL_POSITION"
);
$columns = $stmt3->fetchAll(PDO::FETCH_ASSOC);

if (empty($columns)) {
    echo "❌ Table vacataire_dossier N'EXISTE PAS !<br>";
} else {
    echo "✅ Table exists avec " . count($columns) . " colonnes<br><br>";
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>Colonne</th><th>Type</th><th>Nullable</th><th>Défaut</th></tr>";
    foreach ($columns as $col) {
        echo "<tr>";
        echo "<td>" . $col['COLUMN_NAME'] . "</td>";
        echo "<td>" . $col['COLUMN_TYPE'] . "</td>";
        echo "<td>" . $col['IS_NULLABLE'] . "</td>";
        echo "<td>" . ($col['COLUMN_DEFAULT'] ?? '-') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "<hr style='margin-top: 40px;'>";
echo "<p style='color: #666; font-size: 12px;'>";
echo "Généré le " . date('d/m/Y H:i:s') . "<br>";
echo "Base de données : " . getenv('DB_NAME') . " (ou par défaut fiches_ujkz)";
echo "</p>";
?>
