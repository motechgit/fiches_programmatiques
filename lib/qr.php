<?php
// ============================================================
// lib/qr.php — Générateur QR Code PHP pur, sans dépendance
// QR Code version 3 (29×29), ECC Level M
// PNG compatible FPDF (filtre Sub, Predictor 15)
// ============================================================
declare(strict_types=1);

class QrCode
{
    // ── Point d'entrée ────────────────────────────────────────
    public static function png(string $data, int $pixelSize = 3): string
    {
        $matrix = self::buildMatrix($data);
        return function_exists('imagecreatetruecolor')
            ? self::withGD($matrix, $pixelSize)
            : self::withoutGD($matrix, $pixelSize);
    }

    // ── Rendu GD ─────────────────────────────────────────────
    private static function withGD(array $matrix, int $px): string
    {
        $n = count($matrix);
        $q = 4;
        $sz = ($n + $q * 2) * $px;
        $im = imagecreatetruecolor($sz, $sz);
        $w  = imagecolorallocate($im, 255, 255, 255);
        $b  = imagecolorallocate($im, 0, 0, 0);
        imagefill($im, 0, 0, $w);
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if ($matrix[$r][$c]) {
                    imagefilledrectangle($im,
                        ($q+$c)*$px, ($q+$r)*$px,
                        ($q+$c+1)*$px-1, ($q+$r+1)*$px-1, $b);
                }
            }
        }
        ob_start(); imagepng($im); $out = ob_get_clean();
        imagedestroy($im);
        return $out;
    }

    // ── Rendu PNG pur PHP — filtre Sub (Predictor 15 compatible) ──
    private static function withoutGD(array $matrix, int $px): string
    {
        $n  = count($matrix);
        $q  = 4;
        $W  = ($n + $q * 2) * $px;  // largeur image

        // Construire les scanlines avec filtre Sub (1)
        // filtre Sub : byte[i] = raw[i] - raw[i-1]
        // Pour la 1ère ligne et les pixels, c'est simple
        $scanlines = '';
        for ($row = 0; $row < ($n + $q * 2); $row++) {
            $rowPixels = '';
            for ($col = 0; $col < ($n + $q * 2); $col++) {
                $mr = $row - $q;
                $mc = $col - $q;
                $dark = ($mr >= 0 && $mr < $n && $mc >= 0 && $mc < $n && $matrix[$mr][$mc]);
                $byte = $dark ? "\x00" : "\xFF";
                for ($p = 0; $p < $px; $p++) {
                    $rowPixels .= $byte;
                }
            }
            // Appliquer filtre Sub (1)
            $filtered = "\x01";  // type de filtre = Sub
            $prev = 0;
            for ($i = 0; $i < strlen($rowPixels); $i++) {
                $cur   = ord($rowPixels[$i]);
                $diff  = ($cur - $prev + 256) % 256;
                $filtered .= chr($diff);
                $prev  = $cur;
            }
            for ($p = 1; $p < $px; $p++) {
                $scanlines .= $filtered;
            }
            $scanlines .= $filtered;
        }

        $idat = gzcompress($scanlines, 9);

        // Chunks PNG
        $sig  = "\x89PNG\r\n\x1a\n";
        $ihdr = self::chunk('IHDR',
            pack('NNCCCCC', $W, $W, 8, 0, 0, 0, 0));
        $idatC = self::chunk('IDAT', $idat);
        $iend  = self::chunk('IEND', '');

        return $sig . $ihdr . $idatC . $iend;
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data))
             . $type . $data
             . pack('N', crc32($type . $data));
    }

    // ── Constructeur de matrice QR version 3 (29×29) ECC M ───
    public static function buildMatrix(string $data): array
    {
        $n = 29;
        $m = array_fill(0, $n, array_fill(0, $n, -1));

        self::addFinder($m, 0, 0);
        self::addFinder($m, 0, $n - 7);
        self::addFinder($m, $n - 7, 0);

        // Séparateurs
        for ($i = 0; $i < 8; $i++) {
            self::ms($m, $i, 7, 0);        self::ms($m, 7, $i, 0);
            self::ms($m, $i, $n-8, 0);     self::ms($m, 7, $n-1-$i, 0);
            self::ms($m, $n-1-$i, 7, 0);   self::ms($m, $n-8, $i, 0);
        }

        // Timing
        for ($i = 8; $i < $n - 8; $i++) {
            self::ms($m, 6, $i, $i % 2 === 0 ? 1 : 0);
            self::ms($m, $i, 6, $i % 2 === 0 ? 1 : 0);
        }

        // Dark module
        self::ms($m, $n - 8, 8, 1);

        // Alignment (version 3 : centre à 22,22)
        self::addAlignment($m, 22, 22);

        // Réserver format info
        self::reserveFormat($m, $n);

        // Encoder données + ECC
        $codewords = self::encodeData($data);

        // Placer les données
        self::placeData($m, $codewords, $n);

        // Choisir le meilleur masque
        $bestMask = 0; $bestScore = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $copy  = self::applyMask($m, $mask, $n);
            $score = self::penalty($copy, $n);
            if ($score < $bestScore) { $bestScore = $score; $bestMask = $mask; }
        }

        $m = self::applyMask($m, $bestMask, $n);
        self::placeFormatInfo($m, $bestMask, $n);

        // Remplacer -1 par 0
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if ($m[$r][$c] < 0) $m[$r][$c] = 0;
            }
        }
        return $m;
    }

    // ── Helpers matrice ───────────────────────────────────────
    private static function ms(array &$m, int $r, int $c, int $v): void
    {
        if ($r >= 0 && $r < count($m) && $c >= 0 && $c < count($m[0]))
            $m[$r][$c] = $v;
    }

    private static function addFinder(array &$m, int $row, int $col): void
    {
        static $p = [
            [1,1,1,1,1,1,1],[1,0,0,0,0,0,1],[1,0,1,1,1,0,1],
            [1,0,1,1,1,0,1],[1,0,1,1,1,0,1],[1,0,0,0,0,0,1],[1,1,1,1,1,1,1]
        ];
        for ($r = 0; $r < 7; $r++)
            for ($c = 0; $c < 7; $c++)
                self::ms($m, $row+$r, $col+$c, $p[$r][$c]);
    }

    private static function addAlignment(array &$m, int $row, int $col): void
    {
        static $p = [
            [1,1,1,1,1],[1,0,0,0,1],[1,0,1,0,1],[1,0,0,0,1],[1,1,1,1,1]
        ];
        for ($r = 0; $r < 5; $r++)
            for ($c = 0; $c < 5; $c++)
                self::ms($m, $row-2+$r, $col-2+$c, $p[$r][$c]);
    }

    private static function reserveFormat(array &$m, int $n): void
    {
        for ($i = 0; $i < 9; $i++) {
            if ($m[8][$i] < 0)      self::ms($m, 8, $i, 0);
            if ($m[$i][8] < 0)      self::ms($m, $i, 8, 0);
        }
        for ($i = 0; $i < 8; $i++) {
            if ($m[8][$n-1-$i] < 0) self::ms($m, 8, $n-1-$i, 0);
            if ($m[$n-1-$i][8] < 0) self::ms($m, $n-1-$i, 8, 0);
        }
    }

    private static function isFunctionModule(array $m, int $r, int $c, int $n): bool
    {
        // Finder + separators
        if ($r < 9 && $c < 9)       return true;
        if ($r < 9 && $c >= $n-8)   return true;
        if ($r >= $n-8 && $c < 9)   return true;
        // Timing
        if ($r === 6 || $c === 6)    return true;
        // Alignment version 3
        if ($r >= 20 && $r <= 24 && $c >= 20 && $c <= 24) return true;
        // Dark module
        if ($r === $n-8 && $c === 8) return true;
        // Format info
        if ($r === 8 && ($c <= 8 || $c >= $n-8)) return true;
        if ($c === 8 && ($r <= 8 || $r >= $n-8)) return true;
        return false;
    }

    // ── Encodage données + ECC ────────────────────────────────
    private static function encodeData(string $data): array
    {
        // Mode Byte, version 3, ECC M : 28 data codewords, 26 ECC codewords
        if (strlen($data) > 28) $data = substr($data, 0, 28);
        $len = strlen($data);

        $bits = '0100' . sprintf('%08b', $len);
        for ($i = 0; $i < $len; $i++) {
            $bits .= sprintf('%08b', ord($data[$i]));
        }
        $bits .= '0000';
        while (strlen($bits) % 8 !== 0) $bits .= '0';
        $pads = ['11101100','00010001']; $pi = 0;
        while (strlen($bits) < 224) { $bits .= $pads[$pi % 2]; $pi++; }

        $bytes = [];
        for ($i = 0; $i < strlen($bits); $i += 8) {
            $bytes[] = (int)bindec(substr($bits, $i, 8));
        }

        // ECC version 3 ECC M — polynôme générateur degré 26
        $gen = [1,58,183,44,93,202,117,50,199,40,
                68,184,233,163,35,14,166,179,23,185,
                79,33,229,228,179,169,88];
        $ecc = array_fill(0, 26, 0);
        foreach ($bytes as $byte) {
            $c = $byte ^ $ecc[0];
            $ecc = array_slice($ecc, 1);
            $ecc[] = 0;
            for ($i = 0; $i < 26; $i++) {
                $ecc[$i] ^= self::gfMul($gen[$i], $c);
            }
        }
        return array_merge($bytes, $ecc);
    }

    private static function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) return 0;
        static $log = null, $exp = null;
        if ($log === null) {
            $exp = []; $log = array_fill(0, 256, 0);
            $x = 1;
            for ($i = 0; $i < 255; $i++) {
                $exp[$i] = $x;
                $log[$x] = $i;
                $x <<= 1;
                if ($x & 256) $x ^= 0x11D;
                $x &= 0xFF;
            }
            $exp[255] = $exp[0];
        }
        return $exp[($log[$a] + $log[$b]) % 255];
    }

    // ── Placement des données (zigzag) ────────────────────────
    private static function placeData(array &$m, array $codewords, int $n): void
    {
        $bits = '';
        foreach ($codewords as $b) $bits .= sprintf('%08b', $b);
        $bi  = 0;
        $up  = true;
        for ($col = $n-1; $col >= 1; $col -= 2) {
            if ($col === 6) $col--;
            for ($ri = 0; $ri < $n; $ri++) {
                $row = $up ? $n-1-$ri : $ri;
                foreach ([$col, $col-1] as $c) {
                    if ($m[$row][$c] < 0) {
                        $m[$row][$c] = $bi < strlen($bits)
                            ? (int)$bits[$bi++] : 0;
                    }
                }
            }
            $up = !$up;
        }
    }

    // ── Masque ────────────────────────────────────────────────
    private static function applyMask(array $m, int $mask, int $n): array
    {
        $r = $m;
        for ($row = 0; $row < $n; $row++) {
            for ($col = 0; $col < $n; $col++) {
                if ($r[$row][$col] < 0) continue;
                if (self::isFunctionModule($r, $row, $col, $n)) continue;
                $inv = match($mask) {
                    0 => ($row+$col)%2 === 0,
                    1 => $row%2 === 0,
                    2 => $col%3 === 0,
                    3 => ($row+$col)%3 === 0,
                    4 => (intdiv($row,2)+intdiv($col,3))%2 === 0,
                    5 => ($row*$col)%2 + ($row*$col)%3 === 0,
                    6 => (($row*$col)%2 + ($row*$col)%3)%2 === 0,
                    7 => (($row+$col)%2 + ($row*$col)%3)%2 === 0,
                    default => false,
                };
                if ($inv) $r[$row][$col] ^= 1;
            }
        }
        return $r;
    }

    private static function penalty(array $m, int $n): int
    {
        $score = 0;
        // Règle 1 : 5+ consécutifs
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if ($c === 0) { $run = 1; continue; }
                if ($m[$r][$c] === $m[$r][$c-1]) {
                    $run++;
                    if ($run === 5) $score += 3;
                    elseif ($run > 5) $score++;
                } else { $run = 1; }
            }
        }
        // Règle 4 : ratio dark/total
        $dark = 0;
        for ($r = 0; $r < $n; $r++) for ($c = 0; $c < $n; $c++) if ($m[$r][$c] > 0) $dark++;
        $pct = $dark * 100 / ($n * $n);
        $score += abs((int)($pct/5)*5 - 50) / 5 * 10;
        return (int)$score;
    }

    // ── Format info (ECC M + masque) ─────────────────────────
    private static function placeFormatInfo(array &$m, int $mask, int $n): void
    {
        // ECC M = 01, masque = $mask → 5 bits = 01 << 3 | mask
        $data = (0b01 << 3) | $mask;
        // BCH(15,5) avec poly 10100110111
        $g = 0b10100110111;
        $b = $data << 10;
        for ($i = 4; $i >= 0; $i--) {
            if ($b & (1 << ($i+10))) $b ^= ($g << $i);
        }
        $fmt = (($data << 10) | ($b & 0x3FF)) ^ 0b101010000010010;

        $bits = [];
        for ($i = 14; $i >= 0; $i--) $bits[] = ($fmt >> $i) & 1;

        // Autour finder haut-gauche
        $pos = 0;
        for ($c = 0; $c <= 5; $c++) self::ms($m, 8, $c, $bits[$pos++]);
        self::ms($m, 8, 7, $bits[$pos++]);
        self::ms($m, 8, 8, $bits[$pos++]);
        self::ms($m, 7, 8, $bits[$pos++]);
        for ($r = 5; $r >= 0; $r--) self::ms($m, $r, 8, $bits[$pos++]);

        // Copies haut-droit et bas-gauche
        $pos = 0;
        for ($c = $n-1; $c >= $n-8; $c--) self::ms($m, 8, $c, $bits[$pos++]);
        for ($r = $n-7; $r <= $n-1; $r++) self::ms($m, $r, 8, $bits[$pos++]);
    }
}
