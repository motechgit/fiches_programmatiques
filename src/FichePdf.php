<?php
// ============================================================
// src/FichePdf.php — Génération PDF fiches UJKZ
// Utilise FPDF (lib/fpdf.php) — PHP pur, 0 dépendance
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/../lib/fpdf.php';
require_once __DIR__ . '/../lib/qr.php';

class FichePdf
{
    // ── Accès DB ──────────────────────────────────────────────
    public static function getValidations(int $ficheId, $pdo): array
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT vf.role, vf.decision, vf.created_at, u.nom AS valideur_nom
                 FROM validations_fiche vf
                 JOIN utilisateurs u ON u.id = vf.utilisateur_id
                 WHERE vf.fiche_id = ? ORDER BY vf.created_at ASC"
            );
            $stmt->execute([$ficheId]);
            $out = [];
            foreach ($stmt->fetchAll() as $r) {
                $out[$r['role']] = [
                    'nom'      => $r['valideur_nom'],
                    'date'     => date('d/m/Y', strtotime($r['created_at'])),
                    'decision' => $r['decision'],
                ];
            }
            return $out;
        } catch (Throwable) { return []; }
    }

    public static function getJustificatifs(int $ficheId, $pdo): array
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT volume_cm_effectue, volume_td_effectue, commentaire
                 FROM preuves WHERE fiche_id = ?"
            );
            $stmt->execute([$ficheId]);
            return $stmt->fetchAll();
        } catch (Throwable) { return []; }
    }

    // ── Point d'entrée ────────────────────────────────────────
    public static function generer(
        array $enseignant, array $fiches, string $annee,
        string $type, $pdo
    ): string {
        $fichesE = [];
        foreach ($fiches as $f) {
            $fid = (int)$f['id'];
            $f['justificatifs'] = self::getJustificatifs($fid, $pdo);
            $fichesE[] = $f;
        }

        // URL de vérification pour le QR code → page lecture seule
        $matricule = $enseignant['matricule'] ?? '';
        $hash      = strtoupper(substr(hash('sha256', $matricule . $annee . $type), 0, 12));
        $baseUrl   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
                   . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                   . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\') . '/';
        $qrUrl = $baseUrl . 'voir_fiche_qr.php?h=' . $hash
               . '&m=' . urlencode($matricule)
               . '&a=' . urlencode($annee)
               . '&t=' . $type;

        $gen = new FicheBuilder();
        if ($type === 'suivi') {
            return $gen->buildSuivi($enseignant, $fichesE, $annee, $qrUrl);
        }
        return $gen->buildProgrammatique($enseignant, $fichesE, $annee, $qrUrl);
    }
}

// ============================================================
// FicheBuilder — constructeur des fiches avec FPDF
// ============================================================
class FicheBuilder extends FPDF
{
    private string $logoPath = '';
    private string $genDate  = '';

    function __construct()
    {
        parent::__construct('P', 'mm', 'A4');
        $this->logoPath = __DIR__ . '/../logo_ujkz.jpg';
        $this->genDate  = date('d/m/Y à H:i');
        $this->SetMargins(14, 10, 14);
        $this->SetAutoPageBreak(true, 15);
    }

    // ── Helpers ───────────────────────────────────────────────
    private static function abrev(string $n): string
    {
        $nn = trim($n);
        $mp = ['Licence 1'=>'L1','Licence 2'=>'L2','Licence 3'=>'L3','Master 1'=>'M1','Master 2'=>'M2'];
        return isset($mp[$nn]) ? $mp[$nn] : $n;
    }
    private static function parc(array $f): string
    {
        $a = self::abrev($f['niveau'] ?? '');
        $s = $f['semestre'] ?? '';
        $p = $f['parcours'] ?? '';
        return $a . $s . ($p ? ' - ' . mb_substr($p, 0, 18) : '');
    }
    private static function cp(string $s): string
    {
        return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
    }

    // Ligne label (gras) + valeur (normal) sur la même base Y
    private function infoLine(float $w, float $h, array $parts, bool $newline=true): void
    {
        $y = $this->GetY();
        foreach ($parts as [$label, $val]) {
            $x = $this->GetX();
            $this->SetFont('Helvetica','B',8.5);
            $lw = $this->GetStringWidth(self::cp($label));
            $this->SetXY($x, $y);
            $this->Cell($lw + 1, $h, self::cp($label), 0, 0, 'L');
            $this->SetFont('Helvetica','',8.5);
            $vw = $this->GetStringWidth(self::cp((string)$val)) + 2;
            $this->SetXY($x + $lw + 1, $y);
            $this->Cell($vw, $h, self::cp((string)$val), 0, 0, 'L');
            $this->SetXY($x + $lw + 1 + $vw, $y);
        }
        if ($newline) $this->SetXY(14, $y + $h);
    }

    // ── En-tête institutionnel ────────────────────────────────
    private function drawHeader(string $titre, array $ens, string $annee): void
    {
        $this->AddPage();
        $W  = $this->GetPageWidth() - 28;
        $y0 = 10.0;

        // Colonne gauche — Ministère
        $colW = $W * 0.36;
        $this->SetFont('Helvetica','B', 7.0);
        $leftLines = [
            'MINISTERE DE L\'ENSEIGNEMENT',
            'SUPERIEUR, DE LA RECHERCHE',
            'ET DE L\'INNOVATION',
            '- - - - - - - - - - - - - -',
            'SECRETARIAT GENERAL',
            '- - - - - - - - - - - - - -',
            'UNIVERSITE JOSEPH KI-ZERBO',
            '- - - - - - - - - - - - - -',
            'PRESIDENCE',
        ];
        $ly = $y0;
        foreach ($leftLines as $line) {
            $this->SetXY(14, $ly);
            $this->Cell($colW, 4.5, self::cp($line), 0, 0, 'C');
            $ly += 4.5;
        }

        // Logo centre
        $logoW = 32.0; $logoH = 32.0;
        $lx = 14 + $colW + ($W * 0.28 - $logoW) / 2;
        if (file_exists($this->logoPath)) {
            $this->Image($this->logoPath, $lx, $y0, $logoW, $logoH, 'JPEG');
        }

        // Colonne droite — Burkina Faso
        $rx = 14 + $colW + $W * 0.28;
        $rw = $W * 0.36;
        $this->SetFont('Helvetica','BI', 10.0);
        $this->SetXY($rx, $y0);
        $this->Cell($rw, 6.0, self::cp('BURKINA FASO'), 0, 0, 'R');
        $this->SetFont('Helvetica','I', 7.5);
        $this->SetXY($rx, $y0 + 6.5);
        $this->Cell($rw, 4.5, self::cp('La Patrie ou la mort, Nous vaincrons'), 0, 0, 'R');
        $this->SetFont('Helvetica','', 7.0);
        $this->SetXY($rx, $y0 + 17);
        $this->Cell($rw, 4.5, self::cp('Annee universitaire ' . $annee), 0, 0, 'R');

        // Titre — après les 9 lignes du bloc gauche (9×4.5=40.5mm) + marge
        $this->SetY($y0 + 48);
        $this->SetFillColor(225, 225, 225);
        $this->SetFont('Helvetica','B', 12.0);
        $this->Cell($W, 10.0, self::cp($titre), 1, 1, 'C', true);
        $this->SetFillColor(255,255,255);

        $typeLabel = ($ens['type_enseignant'] ?? 'permanent') === 'vacataire'
            ? 'Pour enseignant vacataire' : 'Pour enseignant permanent';
        $this->SetFont('Helvetica','I', 9.0);
        $this->Cell($W, 6.0, self::cp($typeLabel), 0, 1, 'C');
        $this->Ln(3);
    }

    // ── Infos enseignant ──────────────────────────────────────
    private function drawInfosEns(array $ens): void
    {
        $W  = $this->GetPageWidth() - 28;
        $lh = 5.5;

        $nom   = $ens['nom']   ?? '';
        $grade = $ens['grade'] ?? '';
        $dateN = !empty($ens['date_nomination'])
            ? date('d/m/Y', strtotime($ens['date_nomination'])) : '..........';
        $vs  = ($ens['volume_statutaire']  ?? '') !== '' ? $ens['volume_statutaire'].'h'  : '..........';
        $ab  = ($ens['abattement']         ?? '') !== '' ? $ens['abattement'].'%'          : '..........';
        $mot = (string)($ens['motif_abattement'] ?? '..........') ?: '..........';
        $va  = ($ens['volume_apres_abatt'] ?? '') !== '' ? $ens['volume_apres_abatt'].'h'  : '..........';
        $er  = (string)($ens['etab_rattachement'] ?? '');
        $eb  = (string)($ens['etab_beneficiaire'] ?? '');

        $this->infoLine($W, $lh, [['Nom : ', $nom]]);
        $hw = $W / 2;
        $y = $this->GetY();
        $this->infoLine($hw, $lh, [['Grade : ', $grade]], false);
        $this->SetXY(14 + $hw, $y);
        $this->infoLine($hw, $lh, [['Date de Nomination : ', $dateN]]);
        $this->infoLine($W, $lh, [
            ['Volume horaire statutaire : ', $vs],
            ['   Abattement : ', $ab],
            ['   Motif : ', $mot],
        ]);
        // Volume obligatoire : une seule Cell pour garantir alignement
        $y = $this->GetY();
        $this->SetFont('Helvetica','B',8.5);
        $lbl = 'Volume horaire obligatoire apres abattement : ';
        $lw  = $this->GetStringWidth(self::cp($lbl));
        $this->SetXY(14, $y);
        $this->Cell($lw + 1, $lh, self::cp($lbl), 0, 0, 'L');
        $this->SetFont('Helvetica','',8.5);
        $this->SetXY(14 + $lw + 1, $y);
        $this->Cell($W - $lw - 1, $lh, self::cp($va), 0, 0, 'L');
        $this->SetXY(14, $y + $lh);
        if ($er) {
            $this->infoLine($W, $lh, [['Etab. rattachement administratif : ', $er]]);
        }
        $this->infoLine($W, $lh, [['Etab. beneficiaire des enseignements : ', $eb]]);
        $this->Ln(2);
    }

    // ── Tableau programme ─────────────────────────────────────
    private function drawTableauProg(array $fiches): void
    {
        $W  = $this->GetPageWidth() - 28;
        $cw = [$W*.04, $W*.10, $W*.18, $W*.33, $W*.06, $W*.11, $W*.09, $W*.09];

        $this->SetFont('Helvetica','B',8.0);
        $this->Cell($W, 5.5, self::cp('Tableau descriptif des enseignements confies en reunion de departement'), 0, 1, 'C');
        $this->Ln(1);

        $this->SetFillColor(208,208,208);
        $this->SetFont('Helvetica','B',7.5);
        $hdrs = ['N@','CODE','PARCOURS','UE ou ECUE','NTC','CT (h)','TD (h)','TP'];
        $this->SetX(14);
        foreach ($hdrs as $i => $h) {
            $this->Cell($cw[$i], 10.0, $h, 1, 0, 'C', true);
        }
        $this->Ln();
        $this->SetFillColor(255,255,255);

        $s1 = array_values(array_filter($fiches, function($f){ return ($f['semestre']??'')==='S1'; }));
        $s2 = array_values(array_filter($fiches, function($f){ return ($f['semestre']??'')==='S2'; }));

        $this->drawSemRows($cw, $s1, "Premier semestre de l'annee", false);
        $this->drawTotalProg($cw, 'TOTAL DU SEMESTRE 1', count($s1),
            (int)array_sum(array_column($s1,'volume_cm')),
            (int)array_sum(array_column($s1,'volume_td')));
        $this->drawSemRows($cw, $s2, "Deuxieme semestre de l'annee", false);
        $this->drawTotalProg($cw, 'TOTAL DU SEMESTRE 2', count($s2),
            (int)array_sum(array_column($s2,'volume_cm')),
            (int)array_sum(array_column($s2,'volume_td')));
        $this->drawTotalProg($cw,'TOTAUX',count($fiches),
            (int)array_sum(array_column($fiches,'volume_cm')),
            (int)array_sum(array_column($fiches,'volume_td')),true);
    }

    // ── Tableau suivi ─────────────────────────────────────────
    private function drawTableauSuivi(array $fiches): void
    {
        $W  = $this->GetPageWidth() - 28;
        $cw = [$W*.04,$W*.09,$W*.14,$W*.27,$W*.05, $W*.08,$W*.07,$W*.07, $W*.08,$W*.07,$W*.07];

        $this->SetFont('Helvetica','B',8.0);
        $this->Cell($W, 5.5, self::cp('Tableau descriptif des enseignements confies et effectues'), 0, 1, 'C');
        $this->Ln(1);

        $this->SetFillColor(208,208,208);
        $this->SetFont('Helvetica','B',6.5);
        $h1 = 5.0; $this->SetX(14);
        $grps = [
            ['', $cw[0]+$cw[1]+$cw[2]+$cw[3]+$cw[4]],
            ['Vol. total confie', $cw[5]+$cw[6]+$cw[7]],
            ['Vol. effectue',     $cw[8]+$cw[9]+$cw[10]],
        ];
        foreach ($grps as [$lbl,$w]) $this->Cell($w,$h1,self::cp($lbl),1,0,'C',true);
        $this->Ln(); $this->SetX(14);
        foreach (['N@','CODE','PARCOURS','UE ECUE','NTC','CT','TD','TP','CT','TD','TP'] as $i=>$h)
            $this->Cell($cw[$i],$h1,$h,1,0,'C',true);
        $this->Ln(); $this->SetFillColor(255,255,255);

        $s1 = array_values(array_filter($fiches, function($f){ return ($f['semestre']??'')==='S1'; }));
        $s2 = array_values(array_filter($fiches, function($f){ return ($f['semestre']??'')==='S2'; }));

        $this->drawSemRows($cw,$s1,"Premier semestre de l'annee",true);
        [$ecm1,$etd1]=$this->effTotaux($s1);
        $this->drawTotalSuivi($cw,'TOTAL SEMESTRE 1',count($s1),
            (int)array_sum(array_column($s1,'volume_cm')),
            (int)array_sum(array_column($s1,'volume_td')),$ecm1,$etd1);
        $this->drawSemRows($cw,$s2,"Deuxieme semestre de l'annee",true);
        [$ecm2,$etd2]=$this->effTotaux($s2);
        $this->drawTotalSuivi($cw,'TOTAL SEMESTRE 2',count($s2),
            (int)array_sum(array_column($s2,'volume_cm')),
            (int)array_sum(array_column($s2,'volume_td')),$ecm2,$etd2);
        [$ecmT,$etdT]=$this->effTotaux($fiches);
        $this->drawTotalSuivi($cw,'TOTAUX',count($fiches),
            (int)array_sum(array_column($fiches,'volume_cm')),
            (int)array_sum(array_column($fiches,'volume_td')),$ecmT,$etdT,true);
    }

    private function drawSemRows(array $cw, array $fiches, string $semLabel, bool $isSuivi): void
    {
        $W = array_sum($cw);
        $this->SetFillColor(232,232,232);
        $this->SetFont('Helvetica','BI',7.5);
        $this->SetX(14);
        $this->Cell($W, 6.5, self::cp($semLabel), 1, 1, 'C', true);
        $this->SetFillColor(255,255,255);
        $this->SetFont('Helvetica','',7.5);
        foreach ($fiches as $i => $f) {
            $this->SetX(14);
            $isSuivi ? $this->drawRowSuivi($cw, $i+1, $f) : $this->drawRowProg($cw, $i+1, $f);
        }
    }

    private function drawRowProg(array $cw, int $no, array $f): void
    {
        $h  = 6.5;
        $cm = (int)($f['volume_cm'] ?? 0);
        $td = (int)($f['volume_td'] ?? 0);
        $aligns = ['C','C','L','L','C','C','C','C'];
        $vals = [
            (string)$no,
            $f['code'] ?? '',
            self::parc($f),
            mb_substr($f['cours'] ?? '', 0, 45),
            $f['ntc'] ?? '',
            $cm > 0 ? (string)$cm : '',
            $td > 0 ? (string)$td : '',
            '',
        ];
        foreach ($vals as $i => $v) {
            $this->Cell($cw[$i], $h, self::cp($v), 1, 0, $aligns[$i]);
        }
        $this->Ln();
        $this->SetX(14);
    }

    private function drawRowSuivi(array $cw, int $no, array $f): void
    {
        $h   = 6.5;
        $cm  = (int)($f['volume_cm'] ?? 0);
        $td  = (int)($f['volume_td'] ?? 0);
        $js  = $f['justificatifs'] ?? [];
        $ecm = (int)array_sum(array_column($js, 'volume_cm_effectue'));
        $etd = (int)array_sum(array_column($js, 'volume_td_effectue'));
        $aligns = ['C','C','L','L','C','C','C','C','C','C','C'];
        $vals = [
            (string)$no,
            $f['code'] ?? '',
            self::parc($f),
            mb_substr($f['cours'] ?? '', 0, 35),
            $f['ntc'] ?? '',
            $cm  > 0 ? (string)$cm  : '',
            $td  > 0 ? (string)$td  : '',
            '',
            $ecm > 0 ? (string)$ecm : '',
            $etd > 0 ? (string)$etd : '',
            '',
        ];
        foreach ($vals as $i => $v) {
            $this->Cell($cw[$i], $h, self::cp($v), 1, 0, $aligns[$i]);
        }
        $this->Ln();
        $this->SetX(14);
    }

    private function drawTotalProg(array $cw, string $lbl, int $n,
        int $cm, int $td, bool $grand=false): void
    {
        $fill=$grand?[160,160,160]:[192,192,192];
        $this->SetFillColor(...$fill);
        $this->SetFont('Helvetica','B',7.5);
        $lw=$cw[0]+$cw[1]+$cw[2]+$cw[3]; $this->SetX(14);
        $this->Cell($lw,6.5,self::cp($lbl),1,0,'C',true);
        $this->Cell($cw[4],6.5,$n > 0 ? (string)$n : '',1,0,'C',true);
        $this->Cell($cw[5],6.5,$cm > 0 ? (string)$cm : '',1,0,'C',true);
        $this->Cell($cw[6],6.5,$td > 0 ? (string)$td : '',1,0,'C',true);
        $this->Cell($cw[7],6.5,'',1,0,'C',true);
        $this->Ln(); $this->SetFillColor(255,255,255);
    }

    private function drawTotalSuivi(array $cw, string $lbl, int $n,
        int $cm, int $td, int $ecm, int $etd, bool $grand=false): void
    {
        $fill=$grand?[160,160,160]:[192,192,192];
        $this->SetFillColor(...$fill); $this->SetFont('Helvetica','B',7.0);
        $lw=$cw[0]+$cw[1]+$cw[2]+$cw[3]; $this->SetX(14);
        $this->Cell($lw,6.5,self::cp($lbl),1,0,'C',true);
        $this->Cell($cw[4],6.5,$n > 0 ? (string)$n : '',1,0,'C',true);
        $this->Cell($cw[5],6.5,$cm > 0 ? (string)$cm : '',1,0,'C',true);
        $this->Cell($cw[6],6.5,$td > 0 ? (string)$td : '',1,0,'C',true);
        $this->Cell($cw[7],6.5,'',1,0,'C',true);
        $this->Cell($cw[8],6.5,$ecm > 0 ? (string)$ecm : '',1,0,'C',true);
        $this->Cell($cw[9],6.5,$etd > 0 ? (string)$etd : '',1,0,'C',true);
        $this->Cell($cw[10],6.5,'',1,0,'C',true);
        $this->Ln(); $this->SetFillColor(255,255,255);
    }

    private function effTotaux(array $list): array
    {
        return [
            (int)array_sum(array_map(function($f){ return array_sum(array_column($f['justificatifs']??[],'volume_cm_effectue')); },$list)),
            (int)array_sum(array_map(function($f){ return array_sum(array_column($f['justificatifs']??[],'volume_td_effectue')); },$list)),
        ];
    }

    // ── QR Code + Date (remplace signatures) ─────────────────
    private function drawQrSection(string $qrUrl): void
    {
        $W = $this->GetPageWidth() - 28;
        $this->Ln(6);

        // Date de génération alignée à droite
        $this->SetFont('Helvetica','B',9.0);
        $this->Cell($W, 6.0, self::cp('Ouagadougou, le ' . $this->genDate), 0, 1, 'R');
        $this->Ln(4);

        // Zone QR centré sur toute la largeur
        $qrSize = 35.0; // 35mm
        $qrX    = 14 + ($W - $qrSize) / 2;
        $yStart = $this->GetY();

        // Générer et intégrer le QR PNG
        $qrPng = QrCode::png($qrUrl, 3);
        if (strlen($qrPng) > 200) {
            $tmpQr = tempnam(sys_get_temp_dir(), 'qr_') . '.png';
            file_put_contents($tmpQr, $qrPng);
            if (file_exists($tmpQr) && filesize($tmpQr) > 200) {
                $this->Image($tmpQr, $qrX, $yStart, $qrSize, $qrSize, 'PNG');
                @unlink($tmpQr);
            }
        }

        // Légende sous le QR
        $this->SetXY(14, $yStart + $qrSize + 2);
        $this->SetFont('Helvetica','B',7.5);
        $this->Cell($W, 5.0, self::cp('Scannez pour consulter ce document en ligne'), 0, 1, 'C');
        $this->SetFont('Helvetica','I',6.0);
        $this->SetTextColor(100,100,100);
        $maxUrl = mb_strlen($qrUrl) > 80 ? mb_substr($qrUrl,0,77).'...' : $qrUrl;
        $this->Cell($W, 4.0, self::cp($maxUrl), 0, 1, 'C');
        $this->SetTextColor(0,0,0);
        $this->Ln(3);
    }

    // ── Pied de page ─────────────────────────────────────────
    function Footer()
    {
        $this->SetY(-14);
        $W = $this->GetPageWidth() - 28;
        $this->SetDrawColor(128,128,128);
        $this->Line(14, $this->GetY(), 14+$W, $this->GetY());
        $this->SetDrawColor(0,0,0);
        $this->Ln(1);
        $this->SetFont('Helvetica','I',6.5);
        $this->SetTextColor(85,85,85);
        $this->Cell($W, 4.5, self::cp(
            'Genere par le Systeme de gestion des fiches programmatiques de l\'UJKZ — '
            . date('d/m/Y a H:i')
        ), 0, 1, 'C');
        $this->SetTextColor(0,0,0);
    }

    // ── Notes bas de page ─────────────────────────────────────
    private function drawNotes(string $type): void
    {
        $W = $this->GetPageWidth() - 28;
        $this->SetFont('Helvetica','I',6.5);
        $this->SetTextColor(85,85,85);
        $notes = $type === 'programmatique'
            ? '1 Etablir une fiche par etablissement. 2 Calculer sans convertir TD/TP en CM. NTC = nombre total de credits.'
            : '1 Cocher le semestre. 2 Fiche par etablissement. 3 Calculer sans convertir TD/TP. NTC = nombre total de credits.';
        $this->Cell($W, 4.5, self::cp($notes), 0, 1, 'L');
        $this->SetTextColor(0,0,0);
    }

    // ── Constructeurs principaux ──────────────────────────────
    public function buildProgrammatique(
        array $ens, array $fiches, string $annee, string $qrUrl
    ): string {
        $this->drawHeader('FICHE PROGRAMMATIQUE', $ens, $annee);
        $this->drawInfosEns($ens);
        $this->drawTableauProg($fiches);
        $this->drawQrSection($qrUrl);
        $this->drawNotes('programmatique');
        return $this->Output('S', 'fiche.pdf');
    }

    public function buildSuivi(
        array $ens, array $fiches, string $annee, string $qrUrl
    ): string {
        $this->drawHeader('FICHE SEMESTRIELLE DE SUIVI DES HEURES EFFECTUEES', $ens, $annee);
        $this->drawInfosEns($ens);
        $this->drawTableauSuivi($fiches);
        $this->drawQrSection($qrUrl);
        $this->drawNotes('suivi');
        return $this->Output('S', 'fiche.pdf');
    }
}
