<?php
// ============================================================
// generer_matricule.php — Génère un matricule vacataire unique
// Endpoint AJAX appelé depuis le formulaire (form.php)
// ============================================================
declare(strict_types=1);

// Pas d'affichage d'erreur — on répond toujours en JSON
ini_set('display_errors', '0');
error_reporting(0);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Content-Type-Options: nosniff');

try {
    require_once __DIR__ . '/src/Database.php';
    $cfg = require __DIR__ . '/config/database.php';

    // Connexion PDO directe (sans passer par Security ou session)
    $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']};charset=utf8mb4";
    $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // Générer un matricule unique format V + 6 chiffres
    $stmt = $pdo->prepare("SELECT id FROM enseignants WHERE matricule = ? LIMIT 1");
    $tentatives = 0;
    do {
        $matricule = 'V' . str_pad((string)random_int(1000, 999999), 6, '0', STR_PAD_LEFT);
        $stmt->execute([$matricule]);
        $tentatives++;
    } while ($stmt->fetch() && $tentatives < 20);

    echo json_encode(['matricule' => $matricule, 'ok' => true]);

} catch (Throwable $e) {
    // En cas d'erreur DB, générer un matricule côté PHP sans vérification d'unicité
    // (la vérification se fera côté serveur lors de la soumission)
    $matricule = 'V' . str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    echo json_encode(['matricule' => $matricule, 'ok' => true, 'fallback' => true]);
}
