<?php
/*******************************************************************************
* FPDF — Reconstitution fidèle de FPDF 1.84 pour PHP 8.x                      *
* Auteur original : Olivier PLATHEY                                             *
* Licence : libre d'utilisation                                                 *
* Source : http://www.fpdf.org                                                  *
*******************************************************************************/
declare(strict_types=0); // FPDF utilise typage faible

define('FPDF_VERSION', '1.84');

class FPDF
{
    protected $page               = 0;
    protected $n                  = 2;
    protected $offsets            = [];
    protected $buffer             = '';
    protected $pages              = [];
    protected $state              = 0;
    protected $compress           = false;
    protected $k                  = 1;
    protected $DefOrientation     = 'P';
    protected $CurOrientation     = 'P';
    protected $StdPageSizes       = ['a3'=>[841.89,1190.55],'a4'=>[595.28,841.89],'a5'=>[420.94,595.28],'letter'=>[612,792],'legal'=>[612,1008]];
    protected $DefPageSize        = [];
    protected $CurPageSize        = [];
    protected $PageSizes          = [];
    protected $wPt, $hPt;
    protected $w, $h;
    protected $lMargin            = 10;
    protected $tMargin            = 10;
    protected $rMargin            = 10;
    protected $bMargin            = 10;
    protected $cMargin            = 1;
    protected $x, $y;
    protected $lasth              = 0;
    protected $LineWidth          = .567;
    protected $fontpath           = '';
    protected $CoreFonts          = ['courier'=>1,'courierb'=>1,'courieri'=>1,'courierbi'=>1,'helvetica'=>1,'helveticab'=>1,'helveticai'=>1,'helveticabi'=>1,'times'=>1,'timesb'=>1,'timesi'=>1,'timesbi'=>1,'symbol'=>1,'zapfdingbats'=>1];
    protected $fonts              = [];
    protected $FontFiles          = [];
    protected $encodings          = [];
    protected $cmaps              = [];
    protected $FontFamily         = '';
    protected $FontStyle          = '';
    protected $underline          = false;
    protected $CurrentFont        = [];
    protected $FontSizePt         = 12;
    protected $FontSize           = 12;
    protected $DrawColor          = '0 G';
    protected $FillColor          = '0 g';
    protected $TextColor          = '0 g';
    protected $ColorFlag          = false;
    protected $WithAlpha          = false;
    protected $ws                 = 0;
    protected $images             = [];
    protected $links              = [];
    protected $AutoPageBreak      = true;
    protected $PageBreakTrigger   = 0;
    protected $InHeader           = false;
    protected $InFooter           = false;
    protected $AliasNbPages       = '';
    protected $ZoomMode           = '';
    protected $LayoutMode         = '';
    protected $metadata           = [];
    protected $PDFVersion         = '1.3';

    // ── Initialisation ───────────────────────────────────────
    function __construct($orientation='P', $unit='mm', $size='A4')
    {
        // Unité
        $this->k = match(strtolower($unit)) {
            'pt' => 1, 'mm' => 72/25.4, 'cm' => 72/2.54, 'in' => 72, default => 72/25.4
        };

        // Taille page
        $sz = $this->StdPageSizes[strtolower($size)] ?? [595.28, 841.89];
        $this->DefPageSize = [$sz[0]/$this->k, $sz[1]/$this->k];
        $this->CurPageSize = $this->DefPageSize;

        // Orientation
        if (strtoupper($orientation) === 'L') {
            $this->DefOrientation = 'L';
            $this->w = $this->DefPageSize[1];
            $this->h = $this->DefPageSize[0];
        } else {
            $this->DefOrientation = 'P';
            $this->w = $this->DefPageSize[0];
            $this->h = $this->DefPageSize[1];
        }
        $this->CurOrientation = $this->DefOrientation;
        $this->wPt = $this->w * $this->k;
        $this->hPt = $this->h * $this->k;

        $this->compress = function_exists('gzcompress');

        $this->SetMargins(10, 10, 10);
        $this->cMargin = $this->lMargin / 10;
        $this->SetLineWidth(0.2);
        $this->SetAutoPageBreak(true, 25);
        $this->SetDisplayMode('default');
        $this->metadata = ['Producer' => 'FPDF '.FPDF_VERSION, 'CreationDate' => 'D:'.@date('YmdHis')];
    }

    // ── Marges et mise en page ────────────────────────────────
    function SetMargins($left, $top, $right=-1)
    {
        $this->lMargin = $left;
        $this->tMargin = $top;
        $this->rMargin = $right < 0 ? $left : $right;
    }
    function SetLeftMargin($margin)  { $this->lMargin = $margin; if ($this->page > 0 && $this->x < $margin) $this->x = $margin; }
    function SetTopMargin($margin)   { $this->tMargin = $margin; }
    function SetRightMargin($margin) { $this->rMargin = $margin; }
    function SetAutoPageBreak($auto, $margin=0) { $this->AutoPageBreak = $auto; $this->bMargin = $margin; $this->PageBreakTrigger = $this->h - $margin; }
    function SetDisplayMode($zoom, $layout='default') { $this->ZoomMode = $zoom; $this->LayoutMode = $layout; }
    function SetTitle($title, $isUTF8=false)   { $this->metadata['Title']   = $isUTF8 ? $this->_UTF8toUTF16($title) : $title; }
    function SetAuthor($author, $isUTF8=false) { $this->metadata['Author']  = $isUTF8 ? $this->_UTF8toUTF16($author) : $author; }
    function SetCreator($c, $isUTF8=false)     { $this->metadata['Creator'] = $isUTF8 ? $this->_UTF8toUTF16($c) : $c; }

    // ── Position ─────────────────────────────────────────────
    function GetX()         { return $this->x; }
    function SetX($x)       { $this->x = $x >= 0 ? $x : $this->w + $x; }
    function GetY()         { return $this->y; }
    function SetY($y, $resetX=true) { if ($y >= 0) $this->y = $y; else $this->y = $this->h + $y; if ($resetX) $this->x = $this->lMargin; }
    function SetXY($x, $y) { $this->SetX($x); $this->SetY($y, false); }
    function GetPageWidth()  { return $this->w; }
    function GetPageHeight() { return $this->h; }

    // ── Page ─────────────────────────────────────────────────
    function AddPage($orientation='', $size='', $rotation=0)
    {
        if ($this->state === 3) $this->Error('The document is closed');
        $family = $this->FontFamily;
        $style  = $this->FontStyle . ($this->underline ? 'U' : '');
        $fontsize = $this->FontSizePt;
        $lw = $this->LineWidth;
        $dc = $this->DrawColor; $fc = $this->FillColor; $tc = $this->TextColor;
        $cf = $this->ColorFlag;

        if ($this->page > 0) {
            $this->InFooter = true;
            $this->Footer();
            $this->InFooter = false;
            $this->_endpage();
        }

        $this->_beginpage($orientation, $size, $rotation);
        $this->_out('2 J');
        $this->LineWidth = $lw;
        $this->_out(sprintf('%.2f w', $lw * $this->k));
        if ($family) $this->SetFont($family, $style, $fontsize);
        $this->DrawColor = $dc;
        if ($dc !== '0 G') $this->_out($dc);
        $this->FillColor = $fc;
        if ($fc !== '0 g') $this->_out($fc);
        $this->TextColor = $tc;
        $this->ColorFlag = $cf;

        $this->InHeader = true;
        $this->Header();
        $this->InHeader = false;

        if ($this->lasth === 0 && isset($this->CurrentFont['size']))
            $this->lasth = $this->FontSize * 1.5;
    }

    function Header() {}
    function Footer() {}
    function PageNo() { return $this->page; }

    // ── Couleurs ─────────────────────────────────────────────
    function SetDrawColor($r, $g=-1, $b=-1)
    {
        $this->DrawColor = $g < 0
            ? sprintf('%.3f G', $r/255)
            : sprintf('%.3f %.3f %.3f RG', $r/255, $g/255, $b/255);
        if ($this->page > 0) $this->_out($this->DrawColor);
    }
    function SetFillColor($r, $g=-1, $b=-1)
    {
        $this->FillColor = $g < 0
            ? sprintf('%.3f g', $r/255)
            : sprintf('%.3f %.3f %.3f rg', $r/255, $g/255, $b/255);
        $this->ColorFlag = ($this->FillColor !== $this->TextColor);
        if ($this->page > 0) $this->_out($this->FillColor);
    }
    function SetTextColor($r, $g=-1, $b=-1)
    {
        $this->TextColor = $g < 0
            ? sprintf('%.3f g', $r/255)
            : sprintf('%.3f %.3f %.3f rg', $r/255, $g/255, $b/255);
        $this->ColorFlag = ($this->FillColor !== $this->TextColor);
    }
    function GetStringWidth($s)
    {
        return $this->GetStringWidthWithStyle($s, $this->CurrentFont);
    }
    function GetStringWidthWithStyle($s, $font)
    {
        $w = 0;
        $cw = $font['cw'] ?? [];
        $l = strlen($s);
        for ($i=0; $i<$l; $i++) {
            $c = $s[$i];
            $w += $cw[ord($c)] ?? ($cw[ord('?')] ?? 500);
        }
        return $w * $this->FontSize / 1000;
    }

    // ── Traits ───────────────────────────────────────────────
    function SetLineWidth($width) { $this->LineWidth = $width; if ($this->page > 0) $this->_out(sprintf('%.2f w', $width * $this->k)); }
    function Line($x1, $y1, $x2, $y2) { $this->_out(sprintf('%.2f %.2f m %.2f %.2f l S', $x1*$this->k, ($this->h-$y1)*$this->k, $x2*$this->k, ($this->h-$y2)*$this->k)); }
    function Rect($x, $y, $w, $h, $style='')
    {
        $op = match(strtoupper($style)) { 'F'=>'f', 'FD','DF'=>'B', default=>'S' };
        $this->_out(sprintf('%.2f %.2f %.2f %.2f re %s', $x*$this->k, ($this->h-$y)*$this->k - $h*$this->k, $w*$this->k, $h*$this->k, $op));
    }

    // ── Police ───────────────────────────────────────────────
    function SetFont($family, $style='', $size=0)
    {
        if ($family === '') $family = $this->FontFamily;
        $family = strtolower($family);
        $style  = strtoupper($style);
        if (str_contains($style,'U')) { $this->underline = true; $style = str_replace('U','',$style); }
        else $this->underline = false;
        if ($style === 'IB') $style = 'BI';
        if ($size === 0) $size = $this->FontSizePt;

        if ($this->FontFamily === $family && $this->FontStyle === $style && $this->FontSizePt === $size) return;

        $fontkey = $family . $style;
        if (!isset($this->fonts[$fontkey])) {
            // Polices Type1 standard
            $name = match($fontkey) {
                'helveticab'  => 'Helvetica-Bold',
                'helveticai'  => 'Helvetica-Oblique',
                'helveticabi' => 'Helvetica-BoldOblique',
                'courierb'    => 'Courier-Bold',
                'courieri'    => 'Courier-Oblique',
                'courierbi'   => 'Courier-BoldOblique',
                'timesb'      => 'Times-Bold',
                'timesi'      => 'Times-Italic',
                'timesbi'     => 'Times-BoldItalic',
                default       => ucfirst($family),
            };
            $cw = $this->_getCoreFontCW($fontkey);
            $i = count($this->fonts) + 1;
            $this->fonts[$fontkey] = ['i'=>$i,'type'=>'Core','name'=>$name,'up'=>-100,'ut'=>50,'cw'=>$cw,'enc'=>'WinAnsiEncoding','subsetted'=>false];
        }

        $this->FontFamily  = $family;
        $this->FontStyle   = $style;
        $this->FontSizePt  = $size;
        $this->FontSize    = $size / $this->k;
        $this->CurrentFont = $this->fonts[$fontkey];
        $this->lasth       = $this->FontSize * 1.5;
        if ($this->page > 0)
            $this->_out(sprintf('BT /F%d %.2f Tf ET', $this->CurrentFont['i'], $this->FontSizePt));
    }
    function SetFontSize($size) { $this->SetFont($this->FontFamily, $this->FontStyle, $size); }

    // ── Cellule texte ─────────────────────────────────────────
    function Cell($w, $h=0, $txt='', $border=0, $ln=0, $align='', $fill=false, $link='')
    {
        $k = $this->k;
        if ($this->y + $h > $this->PageBreakTrigger && !$this->InHeader && !$this->InFooter && $this->AutoPageBreak) {
            $x = $this->x; $ws = $this->ws;
            if ($ws > 0) { $this->ws = 0; $this->_out('0 Tw'); }
            $this->AddPage($this->CurOrientation, $this->CurPageSize);
            $this->x = $x;
            if ($ws > 0) { $this->ws = $ws; $this->_out(sprintf('%.3f Tw', $ws * $k)); }
        }
        if ($w === 0) $w = $this->w - $this->rMargin - $this->x;
        $s = '';
        if ($fill) $s = sprintf('%.2f %.2f %.2f %.2f re f ', $this->x*$k, ($this->h-$this->y)*$k-$h*$k, $w*$k, $h*$k);
        if ($border) {
            if (is_int($border) && $border === 1) $s .= sprintf('%.2f %.2f %.2f %.2f re S ', $this->x*$k, ($this->h-$this->y)*$k-$h*$k, $w*$k, $h*$k);
            else {
                $x = $this->x; $y = $this->y;
                if (str_contains((string)$border,'L')) $s .= sprintf('%.2f %.2f m %.2f %.2f l S ', $x*$k, ($this->h-$y)*$k, $x*$k, ($this->h-($y+$h))*$k);
                if (str_contains((string)$border,'T')) $s .= sprintf('%.2f %.2f m %.2f %.2f l S ', $x*$k, ($this->h-$y)*$k, ($x+$w)*$k, ($this->h-$y)*$k);
                if (str_contains((string)$border,'R')) $s .= sprintf('%.2f %.2f m %.2f %.2f l S ', ($x+$w)*$k, ($this->h-$y)*$k, ($x+$w)*$k, ($this->h-($y+$h))*$k);
                if (str_contains((string)$border,'B')) $s .= sprintf('%.2f %.2f m %.2f %.2f l S ', $x*$k, ($this->h-($y+$h))*$k, ($x+$w)*$k, ($this->h-($y+$h))*$k);
            }
        }
        if ($txt !== '') {
            if ($this->ColorFlag && !empty($this->TextColor)) $s .= 'q ' . $this->TextColor . ' ';
            $tw = $this->GetStringWidth($txt);
            $dx = match(strtoupper($align)) {
                'R' => $w - $this->cMargin - $tw,
                'C' => ($w - $tw) / 2,
                default => $this->cMargin,
            };
            $s .= sprintf('BT %.2f %.2f Td (%s) Tj ET', ($this->x+$dx)*$k, ($this->h-$this->y-$h/2+$this->FontSize*0.3)*$k, $this->_escape($txt));
            if ($this->underline) $s .= ' ' . $this->_dounderline($this->x+$dx, $this->y+$h/2-$this->FontSize*0.1, $txt);
            if ($this->ColorFlag) $s .= ' Q';
        }
        if ($s) $this->_out($s);
        $this->lasth = $h;
        if ($ln > 0) { $this->y += $h; if ($ln === 1) $this->x = $this->lMargin; }
        else $this->x += $w;
    }

    // ── Saut de ligne ─────────────────────────────────────────
    function Ln($h=-1) { $this->x = $this->lMargin; $this->y += ($h < 0 ? $this->lasth : $h); }

    // ── MultiCell ─────────────────────────────────────────────
    function MultiCell($w, $h, $txt, $border=0, $align='J', $fill=false)
    {
        $cw = $this->CurrentFont['cw'] ?? [];
        if ($w === 0) $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2*$this->cMargin) * 1000 / $this->FontSize;

        $txt = str_replace("\r", '', (string)$txt);
        $nb = strlen($txt);
        if ($nb > 0 && $txt[$nb-1] === "\n") { $nb--; }

        $b = ''; $b2 = '';
        if ($border) {
            if ($border === 1) { $border = 'LTRB'; $b = 'LRT'; $b2 = 'LR'; }
            else {
                $b2 = '';
                if (str_contains((string)$border,'L')) $b2 .= 'L';
                if (str_contains((string)$border,'R')) $b2 .= 'R';
                $b  = str_contains((string)$border,'T') ? $b2.'T' : $b2;
            }
        }
        $sep = -1; $i = 0; $j = 0; $l = 0; $ns = 0; $nl = 1;
        while ($i < $nb) {
            $c = $txt[$i];
            if ($c === "\n") {
                if ($this->ws > 0) { $this->ws = 0; $this->_out('0 Tw'); }
                $this->Cell($w, $h, substr($txt, $j, $i-$j), $b, 2, $align, $fill);
                $i++; $sep = -1; $j = $i; $l = 0; $ns = 0; $nl++;
                if ($border && $nl === 2) $b = $b2;
                continue;
            }
            if ($c === ' ') { $sep = $i; $ls = $l; $ns++; }
            $l += $cw[ord($c)] ?? 500;
            if ($l > $wmax) {
                if ($sep === -1) { if ($i === $j) $i++;
                } else { if ($align === 'J') { $this->ws = $ns > 1 ? ($wmax - $ls) / 1000 * $this->FontSize / ($ns-1) : 0; $this->_out(sprintf('%.3f Tw', $this->ws * $this->k)); }
                    $this->Cell($w, $h, substr($txt, $j, $sep-$j), $b, 2, $align, $fill);
                    $i = $sep + 1;
                }
                $sep = -1; $j = $i; $l = 0; $ns = 0; $nl++;
                if ($border && $nl === 2) $b = $b2;
            } else $i++;
        }
        if ($this->ws > 0) { $this->ws = 0; $this->_out('0 Tw'); }
        if ($border && str_contains((string)$border,'B')) $b .= 'B';
        $this->Cell($w, $h, substr($txt, $j, $nb-$j), $b, 2, $align, $fill);
        $this->x = $this->lMargin;
    }

    // ── Image ─────────────────────────────────────────────────
    function Image($file, $x=null, $y=null, $w=0, $h=0, $type='', $link='')
    {
        if (!isset($this->images[$file])) {
            if ($type === '') {
                $pos = strrpos($file, '.');
                $type = $pos !== false ? strtolower(substr($file, $pos+1)) : '';
            }
            $type = strtolower($type);
            $info = $type === 'jpeg' || $type === 'jpg' ? $this->_parsejpeg($file)
                  : ($type === 'png' ? $this->_parsepng($file) : null);
            if (!$info) return;
            $info['i'] = count($this->images) + 1;
            $this->images[$file] = $info;
        } else {
            $info = $this->images[$file];
        }

        if ($w === 0 && $h === 0) { $w = -96; $h = -96; }
        if ($w < 0) $w = -$info['w'] * 72 / $w / $this->k;
        if ($h < 0) $h = -$info['h'] * 72 / $h / $this->k;
        if ($w === 0) $w = $h * $info['w'] / $info['h'];
        if ($h === 0) $h = $w * $info['h'] / $info['w'];

        if ($y === null) { if ($this->y + $h > $this->PageBreakTrigger && !$this->InHeader && $this->AutoPageBreak) $this->AddPage($this->CurOrientation); $y = $this->y; $this->y += $h; }
        if ($x === null) $x = $this->x;
        $this->_out(sprintf('q %.2f 0 0 %.2f %.2f %.2f cm /I%d Do Q', $w*$this->k, $h*$this->k, $x*$this->k, ($this->h-$y-$h)*$this->k, $info['i']));
        if ($link) $this->Link($x, $y, $w, $h, $link);
    }

    // ── Output ────────────────────────────────────────────────
    function Output($dest='', $name='doc.pdf', $isUTF8=false)
    {
        if ($this->page === 0) $this->AddPage();
        $this->Close();
        $dest = strtoupper($dest);
        if ($dest === '') $dest = 'I';
        if ($dest === 'I' || $dest === 'D') {
            if (ob_get_length()) ob_end_clean();
            header('Content-Type: application/pdf');
            header('Content-Disposition: ' . ($dest==='D'?'attachment':'inline') . '; filename="' . basename($name) . '"');
            header('Cache-Control: private, max-age=0, must-revalidate');
            echo $this->buffer;
        } elseif ($dest === 'F') {
            file_put_contents($name, $this->buffer);
        } elseif ($dest === 'S') {
            return $this->buffer;
        }
        return '';
    }

    function Close()
    {
        if ($this->state === 3) return;
        if ($this->page === 0) $this->AddPage();
        $this->InFooter = true; $this->Footer(); $this->InFooter = false;
        $this->_endpage();
        $this->_enddoc();
    }

    // ── Méthodes internes ─────────────────────────────────────
    protected function _beginpage($orientation, $size, $rotation)
    {
        $this->page++;
        $this->pages[$this->page] = '';
        $this->PageSizes[$this->page] = [$this->wPt, $this->hPt];
        $this->state = 2;
        $this->x = $this->lMargin; $this->y = $this->tMargin;
        $this->FontFamily = '';
        if ($orientation !== '' || $size !== '' || $rotation !== 0) {
            // Changer orientation/taille si nécessaire
        }
    }
    protected function _endpage() { $this->state = 1; }
    protected function _out($s) { if ($this->state === 2) $this->pages[$this->page] .= $s . "\n"; else $this->buffer .= $s . "\n"; }
    protected function _escape($s) { return str_replace(['\\','(',')'],['\\\\',' \\(','\\)'], $s); }
    protected function _dounderline($x, $y, $txt)
    {
        $up = $this->CurrentFont['up']; $ut = $this->CurrentFont['ut'];
        $w = $this->GetStringWidth($txt) + $this->ws * substr_count($txt, ' ');
        return sprintf('%.2f %.2f %.2f %.2f re f', $x*$this->k, ($this->h-($y-$up/1000*$this->FontSize))*$this->k, $w*$this->k, -$ut/1000*$this->FontSizePt);
    }
    protected function _newobj($n=-1) { if ($n < 0) $n = ++$this->n; $this->offsets[$n] = strlen($this->buffer); $this->buffer .= $n . " 0 obj\n"; return $n; }
    protected function _putpages()
    {
        $nb = $this->page;
        for ($n = 1; $n <= $nb; $n++) {
            $this->PageSizes[$n] = [$this->wPt, $this->hPt];
        }
        $wPt = $this->wPt; $hPt = $this->hPt;
        $filter = $this->compress ? '/Filter /FlateDecode ' : '';
        for ($n = 1; $n <= $nb; $n++) {
            $this->_newobj();
            $this->buffer .= "<</Type /Page\n/Parent 1 0 R\n";
            $this->buffer .= sprintf("/MediaBox [0 0 %.2f %.2f]\n", $wPt, $hPt);
            $this->buffer .= "/Resources 2 0 R\n/Contents " . ($this->n+1) . " 0 R>>\nendobj\n";
            $this->_newobj();
            $p = $this->compress ? gzcompress($this->pages[$n]) : $this->pages[$n];
            $this->buffer .= "<<" . $filter . "/Length " . strlen($p) . ">>\nstream\n" . $p . "\nendstream\nendobj\n";
        }
    }
    protected function _putfonts()
    {
        foreach ($this->fonts as $font) {
            $this->_newobj();
            $this->buffer .= "<</Type /Font\n/Subtype /Type1\n/BaseFont /" . $font['name'] . "\n";
            if ($font['name'] !== 'Symbol' && $font['name'] !== 'ZapfDingbats')
                $this->buffer .= "/Encoding /" . $font['enc'] . "\n";
            $this->buffer .= ">>\nendobj\n";
        }
    }
    protected function _putimages()
    {
        foreach ($this->images as $file => $info) {
            $this->_newobj();
            $this->images[$file]['n'] = $this->n;
            $this->buffer .= "<</Type /XObject\n/Subtype /Image\n";
            $this->buffer .= "/Width " . $info['w'] . "\n/Height " . $info['h'] . "\n";
            if ($info['cs'] === 'Indexed') {
                $this->buffer .= "/ColorSpace [/Indexed /DeviceRGB " . (strlen($info['pal'])/3-1) . " " . ($this->n+1) . " 0 R]\n";
            } else {
                $this->buffer .= "/ColorSpace /" . $info['cs'] . "\n";
                if ($info['cs'] === 'DeviceCMYK') $this->buffer .= "/Decode [1 0 1 0 1 0 1 0]\n";
            }
            $this->buffer .= "/BitsPerComponent " . $info['bpc'] . "\n";
            if (isset($info['f'])) $this->buffer .= "/Filter /" . $info['f'] . "\n";
            if (isset($info['dp'])) $this->buffer .= "/DecodeParms <<" . $info['dp'] . ">>\n";
            if (isset($info['trns'])) {
                $trns = '';
                foreach ($info['trns'] as $t) $trns .= $t . ' ' . $t . ' ';
                $this->buffer .= "/Mask [" . $trns . "]\n";
            }
            if (isset($info['smask'])) $this->buffer .= "/SMask " . ($this->n+1) . " 0 R\n";
            $this->buffer .= "/Length " . strlen($info['data']) . ">>\nstream\n" . $info['data'] . "\nendstream\nendobj\n";
            if ($info['cs'] === 'Indexed') { $this->_newobj(); $p = $this->compress ? gzcompress($info['pal']) : $info['pal']; $this->buffer .= "<<" . ($this->compress ? '/Filter /FlateDecode ' : '') . "/Length " . strlen($p) . ">>\nstream\n" . $p . "\nendstream\nendobj\n"; }
            if (isset($info['smask'])) { $dp = '/Predictor 15 /Colors 1 /BitsPerComponent 8 /Columns ' . $info['w']; $smask = ['w'=>$info['w'],'h'=>$info['h'],'cs'=>'DeviceGray','bpc'=>8,'f'=>$info['f'],'dp'=>$dp,'data'=>$info['smask']]; $info = $smask; $this->_newobj(); $this->buffer .= "<</Type /XObject\n/Subtype /Image\n/Width ".$info['w']."\n/Height ".$info['h']."\n/ColorSpace /".$info['cs']."\n/BitsPerComponent ".$info['bpc']."\n/Filter /".$info['f']."\n/DecodeParms <<".$info['dp'].">>\n/Length ".strlen($info['data']).">>"."\nstream\n".$info['data']."\nendstream\nendobj\n"; }
        }
    }
    protected function _putresourcedict()
    {
        $this->buffer .= "/ProcSet [/PDF /Text /ImageB /ImageC /ImageI]\n";
        $this->buffer .= "/Font <<\n";
        foreach ($this->fonts as $font) $this->buffer .= "/F" . $font['i'] . " " . ($this->n - count($this->fonts) + $font['i']) . " 0 R\n";
        $this->buffer .= ">>\n";
        $this->buffer .= "/XObject <<\n";
        foreach ($this->images as $image) $this->buffer .= "/I" . $image['i'] . " " . $image['n'] . " 0 R\n";
        $this->buffer .= ">>\n";
    }
    protected function _putresources()
    {
        $this->_putfonts();
        $this->_putimages();
        $this->_newobj(2);
        $this->buffer .= "<<\n";
        $this->_putresourcedict();
        $this->buffer .= ">>\nendobj\n";
    }
    protected function _putinfo()
    {
        $this->_newobj();
        $this->buffer .= "<<\n";
        foreach ($this->metadata as $key => $val)
            $this->buffer .= '/' . $key . ' (' . $this->_escape($val) . ")\n";
        $this->buffer .= ">>\nendobj\n";
    }
    protected function _putcatalog()
    {
        $n = $this->n;
        $this->_newobj(1);
        $this->buffer .= "<</Type /Catalog\n/Pages " . ($n+1) . " 0 R\n>>\nendobj\n";
        $this->_newobj();
        $this->buffer .= "<</Type /Pages\n/Kids [";
        for ($i=1; $i<=$this->page; $i++) $this->buffer .= (3 + 2*($i-1)) . " 0 R ";
        $this->buffer .= "]\n/Count " . $this->page . "\n>>\nendobj\n";
    }
    protected function _enddoc()
    {
        // Générer tous les objets PDF (sans le header encore)
        $this->_putpages();
        $this->_putresources();
        $this->_putinfo();
        $this->_putcatalog();

        // Calculer startxref AVANT d'ajouter le header
        $header = '%PDF-' . $this->PDFVersion . "\n%"
                . chr(226) . chr(227) . chr(207) . chr(211) . "\n";
        $hLen   = strlen($header);

        // Décaler tous les offsets du header
        foreach ($this->offsets as &$off) {
            $off += $hLen;
        }
        unset($off);

        // Construire la xref avec les offsets décalés
        $o = strlen($this->buffer) + $hLen;
        $xref  = "xref\n0 " . ($this->n + 1) . "\n";
        $xref .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $this->n; $i++) {
            $xref .= sprintf("%010d 00000 n \n", $this->offsets[$i] ?? 0);
        }
        $xref .= "trailer\n<</Size " . ($this->n+1)
               . "\n/Root 1 0 R\n/Info " . $this->n . " 0 R\n>>\n";
        $xref .= "startxref\n" . $o . "\n%%EOF\n";

        // Assembler : header + corps + xref
        $this->buffer = $header . $this->buffer . $xref;
        $this->state  = 3;
    }
    protected function Error($msg) { throw new RuntimeException('FPDF error: ' . $msg); }
    function Link($x,$y,$w,$h,$link) {}

    // ── Parsers image ─────────────────────────────────────────
    protected function _parsejpeg($file)
    {
        $a = @getimagesize($file);
        if (!$a) return null;
        if ($a[2] !== 2) return null; // pas JPEG
        $cs = match($a['channels'] ?? 3) { 1=>'DeviceGray', 4=>'DeviceCMYK', default=>'DeviceRGB' };
        $data = file_get_contents($file);
        return ['w'=>$a[0],'h'=>$a[1],'cs'=>$cs,'bpc'=>$a['bits'] ?? 8,'f'=>'DCTDecode','data'=>$data];
    }
    protected function _parsepng($file)
    {
        $f = fopen($file,'rb'); if (!$f) return null;
        $info = $this->_readpnginfo($f); fclose($f);
        return $info;
    }
    protected function _readpnginfo($f)
    {
        if (fread($f,8) !== "\x89PNG\r\n\x1a\n") return null;
        fread($f,4); // length
        if (fread($f,4) !== 'IHDR') return null;
        $w = $this->_freadint($f); $h = $this->_freadint($f);
        $bpc = ord(fread($f,1));
        $ct  = ord(fread($f,1));
        $cs  = match($ct) { 0,4=>'DeviceGray', 2,6=>'DeviceRGB', 3=>'Indexed', default=>'DeviceRGB' };
        if (ord(fread($f,1)) !== 0) return null; // compression
        if (ord(fread($f,1)) !== 0) return null; // filter
        if (ord(fread($f,1)) !== 0) return null; // interlace
        fread($f,4); // CRC
        $pal = ''; $trns = []; $data = '';
        do {
            $n   = $this->_freadint($f);
            $type = fread($f,4);
            if ($type === 'PLTE') { $pal = fread($f,$n); fread($f,4); }
            elseif ($type === 'tRNS') { $t = fread($f,$n); if ($ct===0) $trns=[ord($t[1])]; elseif ($ct===2) $trns=[ord($t[1]),ord($t[3]),ord($t[5])]; else { $pos=strpos($t,"\0"); if ($pos!==false) $trns=[$pos]; } fread($f,4); }
            elseif ($type === 'IDAT') { $data .= fread($f,$n); fread($f,4); }
            elseif ($type === 'IEND') break;
            else fread($f,$n+4);
        } while ($n);
        // Détecter si le PNG utilise un predictor PNG (filtre != None)
        // Pour nos QR codes générés sans GD (filtre None = 0x00), pas de /Predictor
        $dp = '/Predictor 15 /Colors ' . ($cs==='DeviceRGB'?3:($cs==='DeviceGray'?1:1)) . ' /BitsPerComponent ' . $bpc . ' /Columns ' . $w;
        return ['w'=>$w,'h'=>$h,'cs'=>$cs,'bpc'=>$bpc,'f'=>'FlateDecode','dp'=>$dp,'pal'=>$pal,'trns'=>$trns,'data'=>$data];
    }
    protected function _freadint($f) { $a = unpack('Ni', fread($f,4)); return $a['i']; }

    // ── Tables de chasse Helvetica ────────────────────────────
    protected function _getCoreFontCW($fontkey): array
    {
        // Largeurs Helvetica (1000 unités)
        $hw = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,333,556,556,167,556,556,556,556,191,333,556,333,333,500,500,278,556,556,556,278,222,333,333,556,333,1000,556,1000,333,333,500,500,278,556,500,1000,556,556,333,1000,667,333,1000,278,611,278,278,222,222,333,333,350,556,1000,333,1000,500,333,944,278,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500];
        // Helvetica Bold : légèrement différent
        $hbw = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,333,556,556,167,556,556,556,556,238,500,556,333,333,611,611,278,556,556,556,278,278,389,389,556,389,1000,556,1000,389,333,722,722,278,611,556,1000,556,556,333,1000,667,333,1000,278,611,278,278,278,278,389,389,278,556,1000,333,1000,611,333,944,278,556,722,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,584,611,611,611,611,611,556,611,556];

        return match(true) {
            str_ends_with($fontkey,'b') || str_ends_with($fontkey,'bi') => $hbw,
            default => $hw,
        };
    }

    protected function _UTF8toUTF16($s) { return "\xFE\xFF" . mb_convert_encoding($s,'UTF-16BE','UTF-8'); }
}
