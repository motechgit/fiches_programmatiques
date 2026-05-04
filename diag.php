<?php
// Diagnostic PHP - accessible via http://127.0.0.1/fiches_programmatiques/diag.php
// SUPPRIMER après vérification
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<!DOCTYPE html><html><body><pre>\n";
echo "PHP version: " . PHP_VERSION . "\n";
echo "SAPI: " . php_sapi_name() . "\n\n";

$steps = [
    'config/database.php' => function() {
        $c = require __DIR__ . '/config/database.php';
        return "host={$c['host']} db={$c['dbname']}";
    },
    'config/security.php' => function() {
        $c = require __DIR__ . '/config/security.php';
        return count($c) . " clés";
    },
    'src/Database.php' => function() {
        require_once __DIR__ . '/src/Database.php';
        $pdo = Database::getInstance();
        $v = $pdo->query('SELECT VERSION()')->fetchColumn();
        return 'MySQL ' . $v;
    },
    'src/Security.php' => function() {
        require_once __DIR__ . '/src/Security.php';
        return "OK";
    },
    'src/App.php' => function() {
        require_once __DIR__ . '/src/App.php';
        return "baseUrl=" . App::baseUrl();
    },
    'src/FicheRepository.php' => function() {
        require_once __DIR__ . '/src/FicheRepository.php';
        new FicheRepository();
        return "OK";
    },
    'src/Mailer.php' => function() {
        require_once __DIR__ . '/src/Mailer.php';
        return "OK";
    },
    'templates/form.php (syntaxe)' => function() {
        $ok = file_exists(__DIR__ . '/templates/form.php');
        return $ok ? filesize(__DIR__ . '/templates/form.php') . " bytes" : "MANQUANT";
    },
];

foreach ($steps as $name => $fn) {
    try {
        $result = $fn();
        echo "✓ $name : $result\n";
    } catch (Throwable $e) {
        echo "✗ $name : [" . get_class($e) . "] " . $e->getMessage() . "\n";
        echo "  Fichier: " . $e->getFile() . " L" . $e->getLine() . "\n";
    }
}

echo "\n<strong>Diagnostic terminé.</strong>\n";
echo "</pre></body></html>\n";
