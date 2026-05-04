<?php
// ============================================================
// src/MiniPdf.php — Générateur PDF minimal inspiré de FPDF
// PHP pur, zéro dépendance, zéro extension requise
// Supporte : texte multipolice, rectangles, image JPEG native
// ============================================================
declare(strict_types=1);

class MiniPdf
{
    // ── Constantes page ──────────────────────────────────────
    private float $pw   = 595.28;  // A4 largeur
    private float $ph   = 841.89;  // A4 hauteur
    private float $ml   = 42.52;   // marge gauche  (1.5cm)
    private float $mr   = 42.52;   // marge droite
    private float $mt   = 34.02;   // marge top     (1.2cm)
    private float $mb   = 42.52;   // marge bas

    // ── État interne ──────────────────────────────────────────
    private string $buf     = '';    // contenu page (ops PDF)
    private float  $x      = 0.0;
    private float  $y      = 0.0;   // position courante (depuis le HAUT)
    private float  $lineH  = 5.0;   // hauteur de ligne courante
    private string $font   = 'F';   // F=Helvetica, FB=Helvetica-Bold, FI=Helvetica-Oblique
    private float  $sz     = 10.0;
    private string $fillC  = '1 1 1';   // RVB fill (blanc)
    private string $strokeC= '0 0 0';   // RVB stroke (noir)
    private string $textC  = '0 0 0';   // RVB texte
    private float  $lw     = 0.3;        // épaisseur trait

    // Images JPEG embarquées
    private array  $images = [];   // [{data, w, h, id}]

    // ── Widths approximatifs par caractère (Helvetica) ───────
    // Source : Adobe AFM pour Helvetica, normalisé à 1000 unités
    private static array $CW = [
        ' '=>278,'!'=>278,'"'=>355,'#'=>556,'$'=>556,'%'=>889,'&'=>667,"'"=>191,
        '('=>333,')'=>333,'*'=>389,'+'=>584,','=>278,'-'=>333,'.'=>278,'/'=>278,
        '0'=>556,'1'=>556,'2'=>556,'3'=>556,'4'=>556,'5'=>556,'6'=>556,'7'=>556,
        '8'=>556,'9'=>556,':'=>278,';'=>278,'<'=>584,'='=>584,'>'=>584,'?'=>556,
        '@'=>1015,'A'=>667,'B'=>667,'C'=>722,'D'=>722,'E'=>667,'F'=>611,'G'=>778,
        'H'=>722,'I'=>278,'J'=>500,'K'=>667,'L'=>556,'M'=>833,'N'=>722,'O'=>778,
        'P'=>667,'Q'=>778,'R'=>722,'S'=>667,'T'=>611,'U'=>722,'V'=>667,'W'=>944,
        'X'=>667,'Y'=>611,'Z'=>611,'['=>278,'\\'=>278,']'=>278,'^'=>469,'_'=>556,
        '`'=>333,'a'=>556,'b'=>556,'c'=>500,'d'=>556,'e'=>556,'f'=>278,'g'=>556,
        'h'=>556,'i'=>222,'j'=>222,'k'=>500,'l'=>222,'m'=>833,'n'=>556,'o'=>556,
        'p'=>556,'q'=>556,'r'=>333,'s'=>500,'t'=>278,'u'=>556,'v'=>500,'w'=>722,
        'x'=>500,'y'=>500,'z'=>444,'{'=>334,'|'=>260,'}'=>334,'~'=>584,
    ];

    // Largeur d'une chaîne en pts pour une taille donnée
    public function strW(string $s, float $sz): float
    {
        $w = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            $w += self::$CW[$c] ?? 556;  // défaut : largeur moyenne
        }
        return $w * $sz / 1000.0;
    }

    // ── Démarrage ─────────────────────────────────────────────
    public function __construct()
    {
        $this->x = $this->ml;
        $this->y = $this->mt;
    }

    // ── Couleurs ──────────────────────────────────────────────
    public function setFillColor(float $r, float $g, float $b): void
    {
        $this->fillC = sprintf('%.3f %.3f %.3f', $r, $g, $b);
        $this->buf  .= $this->fillC . " rg\n";
    }
    public function setDrawColor(float $r, float $g, float $b): void
    {
        $this->strokeC = sprintf('%.3f %.3f %.3f', $r, $g, $b);
        $this->buf    .= $this->strokeC . " RG\n";
    }
    public function setTextColor(float $r, float $g, float $b): void
    {
        $this->textC = sprintf('%.3f %.3f %.3f', $r, $g, $b);
    }
    public function resetColors(): void
    {
        $this->setFillColor(1,1,1);
        $this->setDrawColor(0,0,0);
        $this->setTextColor(0,0,0);
    }
    public function setLineWidth(float $w): void
    {
        $this->lw   = $w;
        $this->buf .= sprintf("%.3f w\n", $w);
    }

    // ── Police ────────────────────────────────────────────────
    // $family : 'helvetica' | 'helvetica-bold' | 'helvetica-oblique'
    public function setFont(string $family, float $sz): void
    {
        $this->sz = $sz;
        $map = [
            'helvetica'         => 'F',
            'helvetica-bold'    => 'FB',
            'helvetica-oblique' => 'FI',
            'helvetica-boldoblique' => 'FBO',
        ];
        $this->font   = $map[strtolower($family)] ?? 'F';
        $this->lineH  = $sz * 1.4;
    }

    // ── Position ──────────────────────────────────────────────
    public function setXY(float $x, float $y): void { $this->x = $x; $this->y = $y; }
    public function setX(float $x): void { $this->x = $x; }
    public function getX(): float { return $this->x; }
    public function getY(): float { return $this->y; }
    public function ln(float $h = -1): void { $this->y += ($h < 0 ? $this->lineH : $h); $this->x = $this->ml; }

    // Largeur utile
    public function getW(): float { return $this->pw - $this->ml - $this->mr; }

    // ── Rectangle ─────────────────────────────────────────────
    // $style : 'F'=fill, 'D'=stroke, 'FD'=fill+stroke
    public function rect(float $x, float $y, float $w, float $h, string $style = 'D'): void
    {
        // Convertir coordonnées top→bottom en bottom→top (PDF)
        $py = $this->ph - $y - $h;
        if ($style === 'F') { $op = 'f'; }
        elseif ($style === 'FD' || $style === 'DF') { $op = 'B'; }
        else { $op = 'S'; }
        $this->buf .= sprintf("%.3f %.3f %.3f %.3f re %s\n", $x, $py, $w, $h, $op);
    }

    // ── Ligne ─────────────────────────────────────────────────
    public function line(float $x1, float $y1, float $x2, float $y2): void
    {
        $py1 = $this->ph - $y1;
        $py2 = $this->ph - $y2;
        $this->buf .= sprintf("%.3f %.3f m %.3f %.3f l S\n", $x1, $py1, $x2, $py2);
    }

    // ── Texte ─────────────────────────────────────────────────
    // Affiche une cellule de texte à la position courante
    // $align : 'L','C','R'
    // $border : true/false
    // $fill : true = remplir avec fillColor
    public function cell(
        float $w, float $h, string $txt,
        bool $border = false, int $ln = 0,
        string $align = 'L', bool $fill = false,
        float $padX = 1.5
    ): void {
        $x = $this->x; $y = $this->y;

        if ($fill) {
            $fc = $this->fillC;
            $py = $this->ph - $y - $h;
            $this->buf .= "$fc rg\n";
            $this->buf .= sprintf("%.3f %.3f %.3f %.3f re f\n", $x, $py, $w, $h);
            $this->buf .= "1 1 1 rg\n"; // reset blanc
        }
        if ($border) {
            $py = $this->ph - $y - $h;
            $this->buf .= $this->strokeC . " RG\n";
            $this->buf .= sprintf("%.3f w\n", $this->lw);
            $this->buf .= sprintf("%.3f %.3f %.3f %.3f re S\n", $x, $py, $w, $h);
        }

        if ($txt !== '') {
            // Calculer la position X selon l'alignement
            $tw = $this->strW($txt, $this->sz);
            if ($align === 'C') { $tx = $x + ($w - $tw) / 2; }
            elseif ($align === 'R') { $tx = $x + $w - $tw - $padX; }
            else { $tx = $x + $padX; }
            // Baseline Y en coordonnées PDF (depuis le bas)
            $ty = $this->ph - $y - $h + ($h - $this->sz) / 2 + $this->sz * 0.25;
            $tc = $this->textC;
            $escaped = $this->escapeStr($txt);
            $this->buf .= sprintf(
                "BT %s rg /%s %.3f Tf %.3f %.3f Td (%s) Tj ET\n",
                $tc, $this->font, $this->sz, $tx, $ty, $escaped
            );
        }

        $this->x += $w;
        if ($ln === 1) { $this->y += $h; $this->x = $this->ml; }
        elseif ($ln === 2) { $this->x = $this->ml; }
    }

    // Cellule multiligne (word-wrap)
    public function multiCell(float $w, float $h, string $txt, bool $border = false, string $align = 'L', bool $fill = false): void
    {
        // Découper le texte en lignes selon la largeur
        $lines = $this->splitText($txt, $w - 3.0);
        $x0 = $this->x;
        foreach ($lines as $line) {
            $this->cell($w, $h, $line, $border, 1, $align, $fill);
            $this->x = $x0;
        }
    }

    private function splitText(string $txt, float $maxW): array
    {
        $words = explode(' ', $txt);
        $lines = []; $cur = '';
        foreach ($words as $word) {
            $test = $cur === '' ? $word : $cur . ' ' . $word;
            if ($this->strW($test, $this->sz) <= $maxW || $cur === '') {
                $cur = $test;
            } else {
                $lines[] = $cur;
                $cur = $word;
            }
        }
        if ($cur !== '') $lines[] = $cur;
        return $lines ?: [''];
    }

    // ── Image JPEG ────────────────────────────────────────────
    public function image(string $path, float $x, float $y, float $w, float $h): void
    {
        if (!file_exists($path)) return;
        $data = file_get_contents($path);
        if (!$data) return;
        $info = @getimagesize($path);
        if (!$info) return;

        $id = 'I' . (count($this->images) + 1);
        $this->images[] = [
            'id'   => $id,
            'data' => $data,
            'w'    => $info[0],
            'h'    => $info[1],
            'mime' => $info['mime'],
        ];

        $py = $this->ph - $y - $h;
        $this->buf .= sprintf(
            "q %.3f 0 0 %.3f %.3f %.3f cm /%s Do Q\n",
            $w, $h, $x, $py, $id
        );
    }

    // ── Encodage texte ────────────────────────────────────────
    private function escapeStr(string $s): string
    {
        // Convertir UTF-8 → cp1252 pour Helvetica standard
        $s = mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
        return str_replace(['\\','(',')'],['\\\\',' \\(','\\)'], $s);
    }

    // ── Génération du fichier PDF ─────────────────────────────
    public function output(): string
    {
        $objects = [];

        // Ressources font
        $fontRes = '/Font <</F <</Type /Font /Subtype /Type1 /BaseFont /Helvetica '
                 . '/Encoding /WinAnsiEncoding>> '
                 . '/FB <</Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold '
                 . '/Encoding /WinAnsiEncoding>> '
                 . '/FI <</Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique '
                 . '/Encoding /WinAnsiEncoding>> '
                 . '/FBO <</Type /Font /Subtype /Type1 /BaseFont /Helvetica-BoldOblique '
                 . '/Encoding /WinAnsiEncoding>>'
                 . '>>';

        // Ressources images
        $xObjRes = '';
        $imgObjs = [];
        if (!empty($this->images)) {
            $imgRefs = [];
            foreach ($this->images as $idx => $img) {
                $objId   = 10 + $idx;  // IDs 10, 11, 12...
                $imgRefs[] = '/' . $img['id'] . ' ' . $objId . ' 0 R';
                $imgObjs[$objId] = $img;
            }
            $xObjRes = '/XObject <<' . implode(' ', $imgRefs) . '>>';
        }

        // Compresser le stream de page
        $pageStream = $this->buf;
        $compressed = gzcompress($pageStream, 6);
        $useFlate   = ($compressed !== false && strlen($compressed) < strlen($pageStream));
        $streamData = $useFlate ? $compressed : $pageStream;
        $filter     = $useFlate ? '/Filter /FlateDecode ' : '';
        $streamLen  = strlen($streamData);

        // Construction des objets PDF
        // 1: Catalogue
        // 2: Pages
        // 3: Page
        // 4: Stream contenu
        $objs = [];
        $objs[1] = "<</Type /Catalog /Pages 2 0 R>>";
        $objs[2] = "<</Type /Pages /Kids [3 0 R] /Count 1>>";
        $objs[3] = sprintf(
            "<</Type /Page /Parent 2 0 R /MediaBox [0 0 %.3f %.3f] /Contents 4 0 R /Resources <<%s %s>>>>",
            $this->pw, $this->ph, $fontRes, $xObjRes
        );
        $objs[4] = sprintf(
            "<<%s/Length %d>>\nstream\n%s\nendstream",
            $filter, $streamLen, $streamData
        );

        // Objets images
        foreach ($imgObjs as $objId => $img) {
            $cs      = (strpos($img['mime'],'jpeg') !== false) ? '/DeviceRGB' : '/DeviceRGB';
            $imgLen  = strlen($img['data']);
            $objs[$objId] = sprintf(
                "<</Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s "
                . "/BitsPerComponent 8 /Filter /DCTDecode /Length %d>>\nstream\n%s\nendstream",
                $img['w'], $img['h'], $cs, $imgLen, $img['data']
            );
        }

        // Assemblage avec xref correct
        $out  = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";  // header + commentaire binaire
        $offsets = [];

        foreach ($objs as $id => $content) {
            $offsets[$id] = strlen($out);
            $out .= "$id 0 obj\n$content\nendobj\n";
        }

        // Cross-reference table
        $xrefOffset = strlen($out);
        $maxId = max(array_keys($objs));
        $out .= "xref\n0 " . ($maxId + 1) . "\n";
        $out .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxId; $i++) {
            if (isset($offsets[$i])) {
                $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
            } else {
                $out .= "0000000000 65535 f \n";
            }
        }
        $out .= "trailer\n<</Size " . ($maxId + 1) . " /Root 1 0 R>>\n";
        $out .= "startxref\n$xrefOffset\n%%EOF\n";

        return $out;
    }
}
