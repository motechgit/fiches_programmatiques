<?php
// ============================================================
// FicheProgrammatiqueGenerator.php
// Génère l'HTML complet de la fiche programmatique
// Utilisé par dashboard et dossier_vacataire
// ============================================================
declare(strict_types=1);

class FicheProgrammatiqueGenerator {

    private $pdo;
    private $e; // fonction escape

    public function __construct(\PDO $pdo = null) {
        $this->pdo = $pdo ?? \Database::getInstance();
        $this->e = function($v) { return \Security::e((string)$v); };
    }

    /**
     * Génère l'HTML complet de la fiche programmatique
     */
    public function genererFiche(int $enseignantId, string $anneeAcademique = '2024-2025'): string {
        $e = $this->e;

        // Récupérer l'enseignant
        $ensStmt = $this->pdo->prepare(
            "SELECT * FROM enseignants WHERE id = ? LIMIT 1"
        );
        $ensStmt->execute([$enseignantId]);
        $enseignant = $ensStmt->fetch(\PDO::FETCH_ASSOC);

        if (!$enseignant) {
            return '<p style="color:red;">Enseignant non trouvé</p>';
        }

        // Récupérer toutes les fiches (tous workflows : IESR_UJKZ, IESR_HORS, VACATAIRE, etc)
        $ficheStmt = $this->pdo->prepare(
            "SELECT * FROM fiches 
             WHERE enseignant_id = ? AND annee_academique = ?
             ORDER BY is_encadrement ASC, semestre, id"
        );
        $ficheStmt->execute([$enseignantId, $anneeAcademique]);
        $fiches = $ficheStmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($fiches)) {
            return '<p style="color:red;">Aucune fiche programmatique trouvée</p>';
        }

        // Générer/récupérer le numéro de fiche et QR code (depuis la première fiche)
        $fichePrincipale = $fiches[0];
        $numeroFiche = $fichePrincipale['numero_fiche'] ?? '';
        $qrcodeToken = $fichePrincipale['qrcode_token'] ?? '';
        $qrcodeBase64 = '';

        // Si pas de numéro, le générer
        if (!$numeroFiche) {
            $numeroFiche = $this->genererNumeroFiche($fichePrincipale['id'], $anneeAcademique);
            // Sauvegarder
            $updateStmt = $this->pdo->prepare("UPDATE fiches SET numero_fiche = ? WHERE id = ?");
            $updateStmt->execute([$numeroFiche, $fichePrincipale['id']]);
        }

        // Si pas de token QR, le générer
        if (!$qrcodeToken) {
            $qrcodeToken = $this->genererTokenQRCode($fichePrincipale['id']);
            // Sauvegarder
            $updateStmt = $this->pdo->prepare("UPDATE fiches SET qrcode_token = ? WHERE id = ?");
            $updateStmt->execute([$qrcodeToken, $fichePrincipale['id']]);
        }

        // Générer le QR code en base64
        $qrcodeBase64 = $this->genererQRCode($qrcodeToken);

        // Récupérer les validations de ces fiches
        $ficheIds = array_column($fiches, 'id');
        $placeholders = implode(',', array_fill(0, count($ficheIds), '?'));
        
        $valStmt = $this->pdo->prepare(
            "SELECT vf.*, u.nom as valideur_nom 
             FROM validations_fiche vf
             LEFT JOIN utilisateurs u ON vf.utilisateur_id = u.id
             WHERE vf.fiche_id IN ($placeholders)
             ORDER BY vf.created_at DESC"
        );
        $valStmt->execute($ficheIds);
        $validations = $valStmt->fetchAll(\PDO::FETCH_ASSOC);

        // Construire map de validations (dernière validation par rôle)
        $historiqueGlobal = [];

        foreach ($validations as $v) {
            $role = $v['role'] ?? '';
            if ($role && (!isset($historiqueGlobal[$role]) || ($v['decision'] ?? '') === 'valide')) {
                $historiqueGlobal[$role] = $v;
            }
        }

        // Acteurs de signature
        $sigActors = [
            ['role'=>'chef_dept',         'titre'=>'Chef de Département'],
            ['role'=>'directeur_adjoint', 'titre'=>'Le Directeur Adjoint'],
            ['role'=>'directeur',         'titre'=>'Le Directeur'],
            ['role'=>'dei',               'titre'=>'Directeur de l\'Enseignement et des Innovations'],
        ];

        // Date d'impression
        $dateImpression = date('d/m/Y');

        // Préparation des données
        $nom    = trim($enseignant['nom'] ?? '');
        $prenom = $enseignant['prenom'] ?? '';
        $grade  = $enseignant['grade'] ?? '';
        $diplome = $enseignant['diplome'] ?? '';
        $specialite = $enseignant['specialite'] ?? '';
        $mois = $enseignant['mois_execution'] ?? '';
        $er = $enseignant['etab_rattachement'] ?? '';

        // Récupérer le nom de l'établissement bénéficiaire
        $estabNom = '';
        if (!empty($enseignant['etab_beneficiaire'])) {
            $estabStmt = $this->pdo->prepare(
                "SELECT nom FROM etablissements WHERE id = ? LIMIT 1"
            );
            $estabStmt->execute([$enseignant['etab_beneficiaire']]);
            $estab = $estabStmt->fetch(\PDO::FETCH_ASSOC);
            $estabNom = $estab['nom'] ?? '';
        }

        // HTML
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fiche Programmatique</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Times New Roman', Times, serif;
            line-height: 1.5;
            padding: 40px;
            background: white;
            color: #000;
        }
        .fiche-print-wrapper {
            max-width: 950px;
            margin: 0 auto;
            background: white;
            padding: 60px;
            border: 1px solid #ddd;
            min-height: 1200px;
        }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        td { padding: 5px; text-align: left; font-size: 10px; }
        .fp-sig { width: 100%; }
        .fp-sig td { width: 25%; text-align: center; padding: 10px 5px; border: 1px solid #ddd; }
        .fp-sig-titre { font-weight: 600; font-size: 8.5pt; margin-bottom: 8px; }
        .fp-sig-line { height: 1px; background: #000; margin: 8px 0; }
        .no-print { display: none; }
        .btn {
            display: inline-block;
            padding: 8px 16px;
            font-size: 13px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            background: #007bff;
            color: white;
            transition: background-color 0.2s;
        }
        .btn:hover { background: #0056b3; }
        .btn-actions {
            text-align: center;
            margin-top: 40px;
            padding: 20px;
            border-top: 1px solid #ddd;
        }
        @media print {
            body { padding: 0; background: none; }
            .fiche-print-wrapper { border: none; padding: 40px; }
            .btn-actions { display: none; }
        }
    </style>
</head>
<body>

<div class="fiche-print-wrapper">

<!-- En-tête 3 colonnes -->
<table style="width:100%;border:none;border-collapse:collapse;margin-bottom:10px">
    <tr>
        <td style="width:36%;vertical-align:top;text-align:center;font-size:8.5pt;font-weight:700;line-height:1.55">
            MINISTÈRE DE L'ENSEIGNEMENT<br>SUPÉRIEUR, DE LA RECHERCHE<br>ET DE L'INNOVATION<br>
            <span style="font-weight:400">-----------</span><br>
            SECRÉTARIAT GÉNÉRAL<br><span style="font-weight:400">-----------</span><br>
            <strong>UNIVERSITÉ JOSEPH KI-ZERBO</strong><br>
            <span style="font-weight:400">-----------</span><br>PRÉSIDENCE<br>
            <span style="font-weight:400">-------------</span>
        </td>
        <td style="width:24%;text-align:center;vertical-align:middle">
            <img src="logo_ujkz.jpg" alt="Logo UJKZ" style="width:68px;height:68px;object-fit:contain">
        </td>
        <td style="width:36%;vertical-align:top;text-align:right;font-size:8.5pt;line-height:1.7">
            <strong><em>BURKINA FASO</em></strong><br>
            <em>La Patrie ou la Mort, nous Vaincrons</em><br>
            <span style="letter-spacing:2px;font-size:7.5pt">·········································</span><br>
            Année universitaire <strong>HTML_ANNEE</strong>
        </td>
    </tr>
</table>

<!-- Numéro de fiche et QR code -->
<table style="width:100%;border:none;border-collapse:collapse;margin-bottom:8px">
    <tr>
        <td style="width:70%;vertical-align:top;font-size:10pt">
            <strong>Numéro de fiche : HTML_NUMERO_FICHE</strong><br>
            <span style="font-size:9pt;color:#666">Pour vérification de l'authenticité</span>
        </td>
        <td style="width:30%;text-align:right;vertical-align:top">
            <img src="HTML_QRCODE_BASE64" alt="QR Code" style="width:80px;height:80px;border:1px solid #999">
        </td>
    </tr>
</table>

<!-- Titre -->
<div style="border:1.5px solid #000;background:#e0e0e0;text-align:center;padding:7px;margin-bottom:3px">
    <span style="font-size:13pt;font-weight:700">FICHE PROGRAMMATIQUE</span>
</div>
<div style="text-align:center;font-size:10.5pt;font-weight:700;text-decoration:underline;margin-bottom:8px">
    Pour enseignant vacataire
</div>

<!-- Informations enseignant -->
<div style="font-size:10pt;line-height:1.9;margin-bottom:6px">
    <div>
        Nom : <strong>HTML_NOM</strong>
        &nbsp;&nbsp; Prénom(s) : <strong>HTML_PRENOM</strong>
        <span style="margin-left:20px">Diplôme(s) : <strong>HTML_DIPLOME</strong></span>
    </div>
    <div>
        Spécialité : <strong>HTML_SPECIALITE</strong>
    </div>
    <div>
        Grade : <strong>HTML_GRADE</strong>
    </div>
    <div>
        Établissement d'exercice : <strong>HTML_ETAB_BENEFICIAIRE</strong>
    </div>
    <div>
        Mois d'exécution des charges : <strong>HTML_MOIS</strong>
    </div>
</div>

<!-- Tableau des cours -->
<table border="1" style="font-size:9pt;margin:8px 0">
    <thead style="background:#f0f0f0">
        <tr>
            <td style="width:25%;font-weight:600">Cours / UE / ECUE</td>
            <td style="width:15%;font-weight:600">Semestre</td>
            <td style="width:12%;font-weight:600">CM</td>
            <td style="width:12%;font-weight:600">TD</td>
            <td style="width:12%;font-weight:600">TP</td>
            <td style="width:14%;font-weight:600">NTC</td>
        </tr>
    </thead>
    <tbody>
        HTML_FICHES_ROWS
    </tbody>
</table>

<!-- Signatures / validations -->
<div style="margin-top:16px">
    <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px">
        <div style="font-size:9.5pt;font-weight:600">Vu et approuvé par</div>
        <div style="font-size:9pt;color:#555">
            Ouagadougou, le <strong>HTML_DATE_IMPRESSION</strong>
        </div>
    </div>

    <table class="fp-sig">
        <tr>
            HTML_SIGNATURES
        </tr>
    </table>
</div>

<!-- Notes officielles -->
<div style="margin-top:14px;font-size:7.5pt;border-top:1px solid #aaa;padding-top:4px;color:#555;line-height:1.6">
    <sup>1</sup> Établir une fiche de suivi par établissement (CUP, UFR ou Institut) où intervient l'enseignant.<br>
    <sup>2</sup> Calculer le volume horaire sans convertir les TD et les TP en heures de cours théoriques.<br>
    NB : NTC = nombre total de crédits. Ne remplir qu'une seule fiche pour toutes les interventions sur le campus.<br>
    Ces fiches doivent être impérativement déposées après la réunion d'attribution des heures.
</div>

<!-- Pied de page -->
<div style="margin-top:8px;font-size:7pt;color:#bbb;text-align:center;border-top:1px solid #eee;padding-top:4px">
    Système de gestion des fiches programmatiques de l'UJKZ — HTML_DATE_IMPRESSION
</div>

</div>

<!-- Bouton Imprimer -->
<div class="btn-actions">
    <button onclick="window.print()" class="btn">🖨 Imprimer / PDF</button>
</div>

</body>
</html>
HTML;

        // Générer les lignes de fiches
        $fichesRows = '';
        foreach ($fiches as $fiche) {
            $cours = $e($fiche['cours'] ?? '');
            $semestre = $e($fiche['semestre'] ?? '');
            $cm = (int)($fiche['volume_cm'] ?? 0);
            $td = (int)($fiche['volume_td'] ?? 0);
            $tp = (int)($fiche['volume_tp'] ?? 0);
            $ntc = (int)($fiche['nb_credits'] ?? 0);

            $fichesRows .= "<tr>\n";
            $fichesRows .= "    <td>$cours</td>\n";
            $fichesRows .= "    <td>$semestre</td>\n";
            $fichesRows .= "    <td style=\"text-align:center\">$cm</td>\n";
            $fichesRows .= "    <td style=\"text-align:center\">$td</td>\n";
            $fichesRows .= "    <td style=\"text-align:center\">$tp</td>\n";
            $fichesRows .= "    <td style=\"text-align:center\">$ntc</td>\n";
            $fichesRows .= "</tr>\n";
        }

        // Générer les signatures
        $signaturesHtml = '';
        foreach ($sigActors as $actor) {
            $v = $historiqueGlobal[$actor['role']] ?? null;
            $dec = $v['decision'] ?? '';
            $nomV = $v['valideur_nom'] ?? '';
            $dateV = !empty($v['created_at']) ? date('d/m/Y', strtotime($v['created_at'])) : '';

            $signaturesHtml .= "<td>\n";
            $signaturesHtml .= "    <div class=\"fp-sig-titre\">" . $e($actor['titre']) . "</div>\n";

            if ($dec === 'valide') {
                $signaturesHtml .= "    <div style=\"color:#1a6b1a;font-size:9pt;text-align:center\">\n";
                $signaturesHtml .= "        ✔ Validé par <strong>" . $e($nomV) . "</strong><br>\n";
                $signaturesHtml .= "        <span style=\"font-size:8.5pt\">Le $dateV</span>\n";
                $signaturesHtml .= "    </div>\n";
            } elseif ($dec === 'rejete') {
                $signaturesHtml .= "    <div style=\"color:#b00;font-size:9pt;text-align:center\">\n";
                $signaturesHtml .= "        ✖ Rejeté — $dateV\n";
                $signaturesHtml .= "    </div>\n";
            } else {
                $signaturesHtml .= "    <div style=\"color:#aaa;font-size:9pt;font-style:italic;text-align:center;margin-bottom:4px\">\n";
                $signaturesHtml .= "        En attente de signature\n";
                $signaturesHtml .= "    </div>\n";
                $signaturesHtml .= "    <div class=\"fp-sig-line\"></div>\n";
                $signaturesHtml .= "    <div style=\"text-align:center;font-size:8pt;color:#888\">Signature &amp; cachet</div>\n";
            }

            $signaturesHtml .= "</td>\n";
        }

        // Remplacer les placeholders
        $html = str_replace('HTML_ANNEE', $e($anneeAcademique), $html);
        $html = str_replace('HTML_NUMERO_FICHE', $e($numeroFiche), $html);
        $html = str_replace('HTML_QRCODE_BASE64', $qrcodeBase64, $html);
        $html = str_replace('HTML_NOM', $e($nom), $html);
        $html = str_replace('HTML_PRENOM', $e($prenom), $html);
        $html = str_replace('HTML_DIPLOME', $e($diplome), $html);
        $html = str_replace('HTML_SPECIALITE', $e($specialite ?: '—'), $html);
        $html = str_replace('HTML_GRADE', $e($grade), $html);
        $html = str_replace('HTML_ETAB_BENEFICIAIRE', $e($estabNom), $html);
        $html = str_replace('HTML_MOIS', $e($mois), $html);
        $html = str_replace('HTML_FICHES_ROWS', $fichesRows, $html);
        $html = str_replace('HTML_DATE_IMPRESSION', $e($dateImpression), $html);
        $html = str_replace('HTML_SIGNATURES', $signaturesHtml, $html);

        return $html;
    }

    /**
     * Générer un numéro de fiche unique
     */
    private function genererNumeroFiche(int $ficheId, string $anneeAcademique): string {
        $annee = explode('-', $anneeAcademique)[0]; // Ex: 2024 de 2024-2025
        return 'FP-' . $annee . '-' . str_pad((string)$ficheId, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Générer un token QR code unique (UUID-like)
     */
    private function genererTokenQRCode(int $ficheId): string {
        return bin2hex(random_bytes(16));
    }

    /**
     * Générer une image QR code en base64
     * Utilise la librairie QR Code PHP
     */
    private function genererQRCode(string $token): string {
        // URL de vérification
        $verificationUrl = 'https://ujkz.edu.bf/verifier-fiche?token=' . urlencode($token);
        
        // Générer le QR code avec une simple API publique
        // Alternative: utiliser une librairie PHP locale (chillerlan/php-qrcode)
        try {
            // Utiliser la librairie si disponible
            if (class_exists('\chillerlan\QRCode\QRCode')) {
                $qrCode = new \chillerlan\QRCode\QRCode($verificationUrl);
                return 'data:image/svg+xml;base64,' . base64_encode($qrCode->render());
            }
        } catch (\Exception $e) {
            // Fallback: utiliser une API publique (à utiliser avec prudence)
        }
        
        // Fallback: QR code placeholder (code texte en base64)
        $placeholder = 'iVBORw0KGgoAAAANSUhEUgAAADIAAAAyCAYAAAAeP4ixAAAAYklEQVR4nO3TMQEAAADCoPVPbQhDoAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAHw1cMAAb66OgAAAAASUVORK5CYII=';
        return 'data:image/png;base64,' . $placeholder;
    }
