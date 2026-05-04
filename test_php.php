<?php
// Test compatibilité PHP - supprimer après vérification
error_reporting(E_ALL);
ini_set('display_errors', '1');
echo "PHP " . PHP_VERSION . " - OK<br>\n";

// Test require config
try {
    $config = require __DIR__ . '/config/security.php';
    echo "config/security.php - OK (" . count($config) . " clés)<br>\n";
} catch (Throwable $e) {
    echo "ERREUR config: " . htmlspecialchars($e->getMessage()) . "<br>\n";
}

// Test Database
try {
    require_once __DIR__ . '/src/Database.php';
    $pdo = Database::getInstance();
    echo "Database - OK (connecté)<br>\n";
} catch (Throwable $e) {
    echo "ERREUR Database: " . htmlspecialchars($e->getMessage()) . "<br>\n";
}

// Test Security
try {
    require_once __DIR__ . '/src/Security.php';
    echo "Security.php - OK<br>\n";
} catch (Throwable $e) {
    echo "ERREUR Security: " . htmlspecialchars($e->getMessage()) . "<br>\n";
}

// Test App
try {
    require_once __DIR__ . '/src/App.php';
    echo "App.php - OK<br>\n";
    echo "App::baseUrl() = " . App::baseUrl() . "<br>\n";
} catch (Throwable $e) {
    echo "ERREUR App: " . htmlspecialchars($e->getMessage()) . "<br>\n";
}

// Test FicheRepository
try {
    require_once __DIR__ . '/src/FicheRepository.php';
    $repo = new FicheRepository();
    echo "FicheRepository - OK<br>\n";
} catch (Throwable $e) {
    echo "ERREUR FicheRepository: " . htmlspecialchars($e->getMessage()) . "<br>\n";
}

echo "<br><strong>Diagnostic terminé.</strong><br>\n";
