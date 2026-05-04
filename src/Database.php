<?php
// ============================================================
// src/Database.php — Connexion PDO MySQL sécurisée (Singleton)
// ============================================================
declare(strict_types=1);

class Database
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $cfg = require __DIR__ . '/../config/database.php';

            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $cfg['host'],
                $cfg['port'],
                $cfg['dbname'],
                $cfg['charset']
            );

            try {
                $pdo = new PDO(
                    $dsn,
                    $cfg['username'],
                    $cfg['password'],
                    $cfg['options']
                );

                // Forcer le mode strict MySQL : rejette les valeurs tronquées silencieusement
                $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO'");

                self::$instance = $pdo;

            } catch (PDOException $e) {
                // Ne jamais exposer les identifiants dans les messages d'erreur
                throw new RuntimeException(
                    'Impossible de se connecter à la base de données. ' .
                    'Vérifiez la configuration dans config/database.php.',
                    (int) $e->getCode()
                );
            }
        }

        return self::$instance;
    }

    // Empêcher le clonage (pattern Singleton)
    private function __clone() {}
    private function __construct() {}
}
