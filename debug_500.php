<?php
// ── Script de diagnostic HTTP 500 ──
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

echo "<pre>\n";
echo "PHP version: " . PHP_VERSION . "\n";
echo "PHP_MAJOR_VERSION: " . PHP_MAJOR_VERSION . "\n\n";

$files = [
    'src/Database.php',
    'src/Security.php',
    'src/App.php',
    'src/FicheRepository.php',
    'src/Mailer.php',
    'config/security.php',
    'config/database.php',
];

foreach ($files as $f) {
    $path = __DIR__ . '/' . $f;
    if (file_exists($path)) {
        echo "✓ $f (" . filesize($path) . " bytes)\n";
    } else {
        echo "✗ MANQUANT: $f\n";
    }
}

echo "\n--- Test chargement ---\n";
try {
    require_once __DIR__ . '/src/Database.php';
    echo "✓ Database.php chargé\n";
} catch (Throwable $e) {
    echo "✗ Database.php: " . $e->getMessage() . "\n";
}

try {
    require_once __DIR__ . '/src/Security.php';
    echo "✓ Security.php chargé\n";
} catch (Throwable $e) {
    echo "✗ Security.php: " . $e->getMessage() . "\n";
}

try {
    require_once __DIR__ . '/src/App.php';
    echo "✓ App.php chargé\n";
} catch (Throwable $e) {
    echo "✗ App.php: " . $e->getMessage() . "\n";
}

try {
    $config = require __DIR__ . '/config/security.php';
    echo "✓ config/security.php chargé - clés: " . implode(', ', array_keys($config)) . "\n";
} catch (Throwable $e) {
    echo "✗ config/security.php: " . $e->getMessage() . "\n";
}

try {
    require_once __DIR__ . '/src/FicheRepository.php';
    echo "✓ FicheRepository.php chargé\n";
    $repo = new FicheRepository();
    echo "✓ FicheRepository instancié\n";
} catch (Throwable $e) {
    echo "✗ FicheRepository: " . $e->getMessage() . "\n";
}

try {
    require_once __DIR__ . '/src/Mailer.php';
    echo "✓ Mailer.php chargé\n";
} catch (Throwable $e) {
    echo "✗ Mailer: " . $e->getMessage() . "\n";
}

echo "\n--- Test config form.php dependencies ---\n";
// Tester l'inclusion du template form.php partiellement
try {
    if (isset($config)) {
        $niveaux = $config['niveaux'] ?? [];
        echo "✓ niveaux: " . implode(', ', $niveaux) . "\n";
        $etab_depts = $config['etab_departements'] ?? null;
        echo "✓ etab_departements: " . ($etab_depts ? count($etab_depts) . " entrées" : "ABSENT") . "\n";
        $grades_vols = $config['grades_volumes'] ?? null;
        echo "✓ grades_volumes: " . ($grades_vols ? count($grades_vols) . " entrées" : "ABSENT") . "\n";
    }
} catch (Throwable $e) {
    echo "✗ Config test: " . $e->getMessage() . "\n";
}

echo "\n--- App::url() test ---\n";
try {
    $url = App::url('index.php');
    echo "✓ App::url() = $url\n";
} catch (Throwable $e) {
    echo "✗ App::url(): " . $e->getMessage() . "\n";
}

echo "\nDiagnostic terminé.\n</pre>\n";
