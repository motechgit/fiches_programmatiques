<?php
// ============================================================
// src/App.php — Configuration et helpers globaux
// ============================================================
declare(strict_types=1);

class App
{
    private static ?array $cfg = null;

    public static function config(): array
    {
        if (self::$cfg === null) {
            $path = __DIR__ . '/../config/app.php';
            self::$cfg = file_exists($path) ? require $path : [];
        }
        return self::$cfg;
    }

    // ── URL de base — toujours auto-détectée depuis $_SERVER ──
    // config/app.php::base_url n'est utilisée QUE si elle
    // correspond à l'hôte réel (évite les erreurs en local)
    public static function baseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $dir    = rtrim(dirname($script), '/');

        $detected = $scheme . '://' . $host . $dir;

        // Utiliser base_url configurée seulement si l'hôte correspond
        $cfg = self::config();
        if (!empty($cfg['base_url'])) {
            $cfgHost = parse_url($cfg['base_url'], PHP_URL_HOST) ?? '';
            if ($cfgHost === $host) {
                return rtrim($cfg['base_url'], '/');
            }
        }

        return $detected;
    }

    public static function url(string $path = ''): string
    {
        return self::baseUrl() . '/' . ltrim($path, '/');
    }

    public static function isProduction(): bool
    {
        $cfg = self::config();
        return ($cfg['env'] ?? 'production') === 'production';
    }

    public static function configureErrorDisplay(): void
    {
        $isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)
                   || (($_SERVER['HTTP_HOST'] ?? '') === 'localhost');
        if (self::isProduction() && !$isLocal) {
            ini_set('display_errors', '0');
            ini_set('display_startup_errors', '0');
            error_reporting(E_ALL);
            ini_set('log_errors', '1');
        } else {
            // Dev ou localhost : afficher les erreurs
            ini_set('display_errors', '1');
            ini_set('display_startup_errors', '1');
            error_reporting(E_ALL);
        }
    }
}
