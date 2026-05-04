<?php
// ============================================================
// src/Mailer.php — Envoi de mails
// Utilise PHPMailer (SMTP) si disponible, sinon mail() natif
// ============================================================
declare(strict_types=1);

class Mailer
{
    private array $cfg;

    public function __construct()
    {
        $this->cfg = require __DIR__ . '/../config/mail.php';
    }

    // ── Confirmation de soumission ───────────────────────────
    public function sendConfirmationSoumission(
        string $toEmail, string $toNom, string $cours,
        string $matricule, string $accessLink, string $annee
    ): bool {
        if (empty($toEmail) || !$this->cfg['enabled']) return false;

        $subject = '[Fiche programmatique] Confirmation de dépôt — ' . $cours;
        $body = $this->wrapHtml('Votre fiche a bien été enregistrée.', '
            <p>Bonjour <strong>' . $this->e($toNom) . '</strong>,</p>
            <p>Votre fiche programmatique pour le cours <strong>' . $this->e($cours) . '</strong>
            (année académique ' . $this->e($annee) . ') a bien été déposée et est
            <strong>en attente de validation</strong> par votre chef de département.</p>
            <table style="width:100%;border-collapse:collapse;margin:1rem 0;font-size:14px;border:1px solid #E2E6E2;border-radius:6px">
              <tr style="background:#E8F5EE"><td style="padding:8px 14px;font-weight:600;color:#004D27;width:40%">Matricule</td><td style="padding:8px 14px"><strong>' . $this->e($matricule) . '</strong></td></tr>
              <tr><td style="padding:8px 14px;color:#5A6A5A">UE / ECUE</td><td style="padding:8px 14px">' . $this->e($cours) . '</td></tr>
            </table>
            <p style="text-align:center;margin:1.5rem 0">
              <a href="' . $this->e($accessLink) . '"
                 style="background:#006837;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:600;font-size:15px">
                Accéder à mon tableau de bord
              </a>
            </p>
            <p style="font-size:12px;color:#888">Ce lien est personnel. Ne le partagez pas.</p>
        ');
        return $this->send($toEmail, $subject, $body);
    }

    // ── Confirmation de modification ─────────────────────────
    public function sendConfirmationModification(
        string $toEmail, string $toNom, string $cours, string $accessLink
    ): bool {
        if (empty($toEmail) || !$this->cfg['enabled']) return false;

        $subject = '[Fiche programmatique] Fiche mise à jour — ' . $cours;
        $body = $this->wrapHtml('Votre fiche a été mise à jour.', '
            <p>Bonjour <strong>' . $this->e($toNom) . '</strong>,</p>
            <p>Votre fiche <strong>' . $this->e($cours) . '</strong> a été modifiée
            et est de nouveau en attente de validation.</p>
            <p style="text-align:center;margin:1.5rem 0">
              <a href="' . $this->e($accessLink) . '"
                 style="background:#006837;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:600">
                Voir ma fiche
              </a>
            </p>
        ');
        return $this->send($toEmail, $subject, $body);
    }

    // ── Notification de décision de validation ───────────────
    public function sendNotificationDecision(
        string $toEmail, string $toNom, string $cours,
        string $decision, string $motif, string $accessLink
    ): bool {
        if (empty($toEmail) || !$this->cfg['enabled']) return false;

        $isValide = $decision === 'valide';
        $subject  = '[Fiche programmatique] ' . ($isValide ? 'Fiche validée' : 'Fiche rejetée') . ' — ' . $cours;
        $color    = $isValide ? '#006837' : '#C62828';
        $icon     = $isValide ? '✅' : '❌';

        $motifHtml = '';
        if (!$isValide && !empty($motif)) {
            $motifHtml = '<div style="background:#FFF3F3;border:1px solid #FFCDD2;border-radius:6px;padding:12px 16px;margin:1rem 0;font-size:14px">
                <strong style="color:#C62828">Motif du rejet :</strong><br>
                <span style="color:#5A0000">' . $this->e($motif) . '</span>
            </div>';
        }

        $body = $this->wrapHtml(
            ($isValide ? 'Votre fiche a été validée.' : 'Votre fiche a été rejetée.'),
            '<p>Bonjour <strong>' . $this->e($toNom) . '</strong>,</p>
            <p>Votre fiche programmatique <strong>' . $this->e($cours) . '</strong> a reçu une décision :</p>
            <div style="text-align:center;padding:16px;background:' . ($isValide ? '#E8F5E9' : '#FFF3F3') . ';border-radius:8px;margin:1rem 0;font-size:18px;font-weight:700;color:' . $color . '">
                ' . $icon . ' ' . ($isValide ? 'Validée à cette étape' : 'Rejetée') . '
            </div>
            ' . $motifHtml . '
            <p style="text-align:center;margin:1.5rem 0">
              <a href="' . $this->e($accessLink) . '"
                 style="background:#006837;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:600">
                Voir le détail
              </a>
            </p>'
        );
        return $this->send($toEmail, $subject, $body);
    }

    // ── Envoi (SMTP via PHPMailer ou mail() natif) ───────────
    private function send(string $to, string $subject, string $htmlBody): bool
    {
        // Utiliser PHPMailer si disponible et SMTP configuré
        if (!empty($this->cfg['smtp_enabled']) && $this->loadPHPMailer()) {
            return $this->sendSmtp($to, $subject, $htmlBody);
        }
        return $this->sendNative($to, $subject, $htmlBody);
    }

    private function sendNative(string $to, string $subject, string $htmlBody): bool
    {
        $from = $this->cfg['from_address'];
        $name = $this->cfg['from_name'];

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: =?UTF-8?B?" . base64_encode($name) . "?= <$from>\r\n";
        $headers .= "Reply-To: $from\r\n";
        $headers .= "X-Mailer: UJKZ-Fiches/1.0\r\n";

        $sub = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        try {
            return mail($to, $sub, $htmlBody, $headers);
        } catch (Throwable) {
            return false;
        }
    }

    private function loadPHPMailer(): bool
    {
        // Chercher PHPMailer dans vendor/ (Composer) ou lib/
        $paths = [
            __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php',
            __DIR__ . '/../lib/PHPMailer/src/PHPMailer.php',
        ];
        foreach ($paths as $p) {
            if (file_exists($p)) {
                require_once $p;
                require_once dirname($p) . '/SMTP.php';
                require_once dirname($p) . '/Exception.php';
                return true;
            }
        }
        return false;
    }

    private function sendSmtp(string $to, string $subject, string $htmlBody): bool
    {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $this->cfg['smtp_host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->cfg['smtp_user'];
            $mail->Password   = $this->cfg['smtp_pass'];
            $mail->SMTPSecure = $this->cfg['smtp_secure'] === 'ssl'
                ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int)$this->cfg['smtp_port'];
            $mail->CharSet    = 'UTF-8';
            $mail->setFrom($this->cfg['from_address'], $this->cfg['from_name']);
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            return $mail->send();
        } catch (Throwable) {
            return $this->sendNative($to, $subject, $htmlBody);
        }
    }

    private function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }

    private function wrapHtml(string $preheader, string $content): string
    {
        $app = $this->e($this->cfg['app_name']);
        return '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>' . $app . '</title></head>
        <body style="margin:0;padding:0;background:#F0F2F0;font-family:Segoe UI,Arial,sans-serif">
        <div style="max-width:560px;margin:2rem auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,104,55,.12)">
          <div style="background:linear-gradient(135deg,#004D27,#006837);padding:1.5rem 2rem">
            <div style="color:#FFB300;font-size:20px;font-weight:800;letter-spacing:-.5px">UJKZ</div>
            <div style="color:#fff;font-size:16px;font-weight:600;margin-top:2px">' . $app . '</div>
            <div style="color:rgba(255,255,255,.75);font-size:13px;margin-top:3px">' . $this->e($preheader) . '</div>
          </div>
          <div style="padding:2rem;font-size:15px;line-height:1.7;color:#1A2E1A">
            ' . $content . '
          </div>
          <div style="background:#F8FAF8;padding:.875rem 2rem;font-size:12px;color:#888;border-top:1px solid #E2E6E2;text-align:center">
            Université Joseph KI-ZERBO — Message automatique. Ne pas répondre.
          </div>
        </div></body></html>';
    }
}
