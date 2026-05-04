<?php
// ============================================================
// src/Security.php — Fonctions de sécurité centralisées
// ============================================================
declare(strict_types=1);

class Security
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    // ── Headers HTTP de sécurité ─────────────────────────────
    public function sendSecurityHeaders(): void
    {
        $isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)
                   || (($_SERVER['HTTP_HOST'] ?? '') === 'localhost');
        if (!$isLocal) {
            header('Content-Security-Policy: ' . $this->config['csp']);
            header('X-Frame-Options: DENY');
        }
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header_remove('X-Powered-By');
    }

    // ── Session sécurisée ────────────────────────────────────
    public function startSecureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $lifetime = $this->config['session_lifetime'];
            session_set_cookie_params([
                'lifetime' => $lifetime,
                'path'     => '/',
                'secure'   => isset($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            session_start();

            // Régénérer l'ID de session périodiquement (protection fixation)
            if (!isset($_SESSION['_created'])) {
                $_SESSION['_created'] = time();
            } elseif (time() - $_SESSION['_created'] > 1800) {
                session_regenerate_id(true);
                $_SESSION['_created'] = time();
            }
        }
    }

    // ── Jeton CSRF ───────────────────────────────────────────
    public function generateCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public function verifyCsrfToken(string $token): bool
    {
        if (empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    // ── Dérivation de token d'accès depuis le matricule ──────
    // HMAC-SHA256 avec sel applicatif — non réversible

    // ── Générer un matricule vacataire unique ────────────────
    public function generateMatriculeVacataire(): string
    {
        try {
            $pdo  = Database::getInstance();
            $stmt = $pdo->prepare("SELECT id FROM enseignants WHERE matricule = ? LIMIT 1");
            $tentatives = 0;
            do {
                $mat = 'V' . str_pad((string)random_int(1000, 999999), 6, '0', STR_PAD_LEFT);
                $stmt->execute([$mat]);
                $tentatives++;
            } while ($stmt->fetch() && $tentatives < 20);
        } catch (\Throwable) {
            $mat = 'V' . str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        }
        return $mat;
    }

    public function deriveAccessToken(string $matricule): string
    {
        return hash_hmac('sha256', strtoupper(trim($matricule)), $this->config['app_secret']);
    }

    // ── Validation du matricule ──────────────────────────────
    public function validateMatricule(string $matricule): bool
    {
        // Normaliser en majuscules avant validation (l'utilisateur peut saisir en minuscules)
        $m = strtoupper(trim($matricule));
        if (preg_match('/^[A-Z]\d{4,}$/', $m)) return true;
        return preg_match('/^[A-Z0-9\-]{5,20}$/', $m) && preg_match('/\d{5,}/', $m) && preg_match('/[A-Z]$/', $m);
    }

    // ── Échappement HTML ─────────────────────────────────────
    public static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // ── Nettoyage entrées texte ──────────────────────────────
    public static function sanitizeText(string $input, int $maxLen = 255): string
    {
        $clean = trim(strip_tags($input));
        return mb_substr($clean, 0, $maxLen);
    }

    // ── Validation d'une valeur dans une liste blanche ───────
    public static function inWhitelist(string $value, array $list): bool
    {
        return in_array($value, $list, true);
    }

    // ── Rate Limiting ────────────────────────────────────────
    public function checkRateLimit(string $action): bool
    {
        if (!isset($this->config['rate_limit'][$action])) {
            return true;
        }

        $cfg = $this->config['rate_limit'][$action];
        $ip  = $this->getClientIp();
        $pdo = Database::getInstance();

        // Purger les entrées expirées (MySQL TIMESTAMPDIFF en secondes)
        $pdo->prepare(
            "DELETE FROM rate_limit
             WHERE ip_address = ? AND action = ?
               AND TIMESTAMPDIFF(SECOND, window_start, NOW()) > ?"
        )->execute([$ip, $action, $cfg['window']]);

        // Lire l'entrée courante
        $stmt = $pdo->prepare(
            "SELECT attempts, window_start FROM rate_limit
             WHERE ip_address = ? AND action = ? LIMIT 1"
        );
        $stmt->execute([$ip, $action]);
        $row = $stmt->fetch();

        if (!$row) {
            // Première tentative — INSERT ON DUPLICATE KEY pour l'atomicité
            $pdo->prepare(
                "INSERT INTO rate_limit (ip_address, action, attempts, window_start)
                 VALUES (?, ?, 1, NOW())
                 ON DUPLICATE KEY UPDATE attempts = 1, window_start = NOW()"
            )->execute([$ip, $action]);
            return true;
        }

        // Vérifier si la fenêtre est expirée (double vérification en PHP)
        $elapsed = time() - strtotime($row['window_start']);
        if ($elapsed > $cfg['window']) {
            $pdo->prepare(
                "UPDATE rate_limit SET attempts = 1, window_start = NOW()
                 WHERE ip_address = ? AND action = ?"
            )->execute([$ip, $action]);
            return true;
        }

        if ((int) $row['attempts'] >= $cfg['max']) {
            return false; // Limite atteinte
        }

        $pdo->prepare(
            "UPDATE rate_limit SET attempts = attempts + 1
             WHERE ip_address = ? AND action = ?"
        )->execute([$ip, $action]);
        return true;
    }

    // ── Journal d'audit ──────────────────────────────────────
    public function audit(string $action, ?string $matricule = null, ?string $detail = null): void
    {
        try {
            $pdo = Database::getInstance();
            $pdo->prepare(
                "INSERT INTO audit_log(action,matricule,ip_address,user_agent,detail)
                 VALUES(?,?,?,?,?)"
            )->execute([
                $action,
                $matricule,
                $this->getClientIp(),
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
                $detail,
            ]);
        } catch (Throwable) {
            // Silencieux : ne pas bloquer l'application sur une erreur de log
        }
    }

    // ── IP client (compatible proxy) ─────────────────────────
    public function getClientIp(): string
    {
        // En prod derrière un reverse proxy de confiance, utiliser X-Forwarded-For
        // Ici on reste conservateur
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
