<?php
echo "DEBUG VACATAIRE DOSSIER<br>";
echo "======================<br>";
echo "Fichier actuel : " . __FILE__ . "<br>";
echo "Dernière modif : " . date('d/m/Y H:i:s', filemtime(__FILE__)) . "<br>";
echo "<hr>";

// Vérifier si vacataire_dossier.php existe
$fichier = dirname(__FILE__) . '/vacataire_dossier.php';
if (file_exists($fichier)) {
    echo "✅ vacataire_dossier.php EXISTE<br>";
    echo "Taille : " . filesize($fichier) . " bytes<br>";
    echo "Dernière modif : " . date('d/m/Y H:i:s', filemtime($fichier)) . "<br>";
    echo "<hr>";
    
    // Lire le contenu et chercher les modifications
    $content = file_get_contents($fichier);
    
    $checks = [
        'tab-info' => strpos($content, 'tab-info') !== false,
        'Demande de vacation' => strpos($content, 'Demande de vacation') !== false,
        'Actions DEI' => strpos($content, "Actions DEI") !== false,
        'tab-acte' => strpos($content, 'tab-acte') !== false,
        'statut_dossier' => strpos($content, 'statut_dossier') !== false,
    ];
    
    foreach ($checks as $name => $found) {
        echo ($found ? "✅" : "❌") . " " . $name . "<br>";
    }
    
} else {
    echo "❌ vacataire_dossier.php N'EXISTE PAS !<br>";
}
?>