<?php
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';
if (!class_exists('App')) require_once __DIR__ . '/src/App.php';
App::configureErrorDisplay();
require_once __DIR__ . '/src/Auth.php';
$config = require __DIR__ . '/config/security.php';
$security = new Security($config);
$security->startSecureSession();
echo "Auth::check = " . (Auth::check() ? 'true' : 'false') . "\n";
echo "Role = " . Auth::userRole() . "\n";
echo "ens_id = " . (int)($_GET['ens_id'] ?? 0) . "\n";
$pdo = Database::getInstance();
$ens = $pdo->prepare("SELECT id, nom, type_enseignant FROM enseignants WHERE id=? LIMIT 1");
$ens->execute([(int)($_GET['ens_id'] ?? 0)]);
$row = $ens->fetch();
echo "Enseignant = "; var_dump($row);
