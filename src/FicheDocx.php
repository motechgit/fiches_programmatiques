<?php
// ============================================================
// src/FicheDocx.php — Générateur de fiche programmatique DOCX
// XML valide conforme OOXML — ZipArchive natif PHP, 0 dépendance
// ============================================================
declare(strict_types=1);

class FicheDocx
{
    // Convertir niveau → abréviation
    private static function abrevNiveau(string $niveau): string
    {
        $n = trim($niveau);
        $map = ['Licence 1'=>'L1','Licence 2'=>'L2','Licence 3'=>'L3','Master 1'=>'M1','Master 2'=>'M2'];
        return isset($map[$n]) ? $map[$n] : $niveau;
    }

    // Construire la zone PARCOURS : abrév_niveau + semestre + ' — ' + parcours
    private static function buildParcours(string $niveau, string $semestre, string $parcours): string
    {
        $abrev = self::abrevNiveau($niveau);
        $base  = $abrev . $semestre; // ex: L1S1
        return $parcours !== '' ? $base . ' — ' . $parcours : $base;
    }

    public static function generer(array $enseignant, array $fiches, string $annee): string
    {
        $s1 = array_values(array_filter($fiches, function($f){ return $f['semestre']==='S1'; }));
        $s2 = array_values(array_filter($fiches, function($f){ return $f['semestre']==='S2'; }));

        $tcmS1 = (int)array_sum(array_column($s1, 'volume_cm'));
        $ttdS1 = (int)array_sum(array_column($s1, 'volume_td'));
        $tcmS2 = (int)array_sum(array_column($s2, 'volume_cm'));
        $ttdS2 = (int)array_sum(array_column($s2, 'volume_td'));

        $docXml = self::buildDocument($enseignant, $s1, $s2, $tcmS1, $ttdS1, $tcmS2, $ttdS2, $annee);
        return self::buildZip($docXml);
    }

    // ── Helpers XML ──────────────────────────────────────────
    private static function x(mixed $v): string
    {
        return htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function run(string $text, bool $bold = false, int $sz = 22): string
    {
        $b = $bold ? '<w:b/>' : '';
        return '<w:r><w:rPr>' . $b . '<w:sz w:val="' . $sz . '"/><w:szCs w:val="' . $sz . '"/></w:rPr>'
             . '<w:t xml:space="preserve">' . self::x($text) . '</w:t></w:r>';
    }

    private static function boldRun(string $text, int $sz = 22): string
    {
        return self::run($text, true, $sz);
    }

    private static function para(string $runs, string $jc = '', string $spacingBefore = '', string $spacingAfter = ''): string
    {
        $pPr = '';
        $parts = [];
        if ($spacingBefore !== '' || $spacingAfter !== '') {
            $b = $spacingBefore !== '' ? ' w:before="' . $spacingBefore . '"' : '';
            $a = $spacingAfter  !== '' ? ' w:after="'  . $spacingAfter  . '"' : '';
            $parts[] = '<w:spacing' . $b . $a . '/>';
        }
        if ($jc !== '') {
            $parts[] = '<w:jc w:val="' . $jc . '"/>';
        }
        if ($parts) {
            $pPr = '<w:pPr>' . implode('', $parts) . '</w:pPr>';
        }
        return '<w:p>' . $pPr . $runs . '</w:p>';
    }

    private static function cell(string $content, int $w, string $fill = '', int $span = 1, string $vAlign = ''): string
    {
        $shdAttr = $fill ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $fill . '"/>' : '';
        $spanAttr = $span > 1 ? '<w:gridSpan w:val="' . $span . '"/>' : '';
        $vAlignAttr = $vAlign ? '<w:vAlign w:val="' . $vAlign . '"/>' : '';
        return '<w:tc><w:tcPr>'
             . '<w:tcW w:w="' . $w . '" w:type="dxa"/>'
             . $spanAttr
             . $shdAttr
             . $vAlignAttr
             . '</w:tcPr>'
             . $content
             . '</w:tc>';
    }

    private static function tableRow(string $cells, string $fill = '', bool $header = false): string
    {
        $trPr = '';
        $parts = [];
        if ($header) $parts[] = '<w:tblHeader/>';
        // shd non valide dans trPr — couleur gérée par les cellules
        if ($parts)  $trPr = '<w:trPr>' . implode('', $parts) . '</w:trPr>';
        return '<w:tr>' . $trPr . $cells . '</w:tr>';
    }

    // ── Tableau principal des fiches ─────────────────────────
    private static function ficheRow(int $no, string $code, string $parcours, string $ue, string $ntc, int $cm, int $td): string
    {
        return self::tableRow(
            self::cell(self::para(self::run((string)$no), 'center'), 400) .
            self::cell(self::para(self::run($code), 'center'), 1200) .
            self::cell(self::para(self::run($parcours)), 1800) .
            self::cell(self::para(self::run($ue)), 3160) .
            self::cell(self::para(self::run($ntc), 'center'), 600) .
            self::cell(self::para(self::run($cm > 0 ? (string)$cm : ''), 'center'), 1100) .
            self::cell(self::para(self::run($td > 0 ? (string)$td : ''), 'center'), 600) .
            self::cell('<w:p/>', 500)
        );
    }

    private static function totalRow(string $label, int $cm, int $td, bool $grand = false): string
    {
        $fill = $grand ? 'C0C0C0' : 'D8D8D8';
        return self::tableRow(
            self::cell(self::para(self::boldRun($label), 'center'), 400, $fill, 4) .
            self::cell('<w:p/>', 600, $fill) .
            self::cell(self::para(self::boldRun($cm > 0 ? (string)$cm : ''), 'center'), 1100, $fill) .
            self::cell(self::para(self::boldRun($td > 0 ? (string)$td : ''), 'center'), 600, $fill) .
            self::cell('<w:p/>', 500, $fill),
            $fill
        );
    }

    private static function semestreHeaderRow(string $label): string
    {
        return self::tableRow(
            self::cell(
                self::para('<w:r><w:rPr><w:b/><w:i/><w:sz w:val="20"/></w:rPr><w:t>' . self::x($label) . '</w:t></w:r>', 'center'),
                400, 'E8E8E8', 8
            ),
            'E8E8E8'
        );
    }

    private static function headerRow(): string
    {
        $hcell = function($txt, $w) {
            return self::cell(self::para(self::boldRun($txt, 18), 'center'), $w, 'D0D0D0');
        };
        return self::tableRow(
            $hcell('N°', 400) .
            $hcell('CODE', 1200) .
            $hcell('PARCOURS', 1800) .
            $hcell('UE ou ECUE', 3160) .
            $hcell('NTC', 600) .
            $hcell('CT (h)', 1100) .
            $hcell('TD', 600) .
            $hcell('TP', 500),
            'D0D0D0', true
        );
    }

    // ── Construction document principal ─────────────────────
    private static function buildDocument(
        array $ens, array $s1, array $s2,
        int $tcmS1, int $ttdS1, int $tcmS2, int $ttdS2, string $annee
    ): string {
        $x = function($v) { return self::x($v); };
        $nom = $x($ens['nom'] ?? '');
        $grade = $x($ens['grade'] ?? '');
        $dateNom = !empty($ens['date_nomination'])
            ? $x(date('d/m/Y', strtotime($ens['date_nomination']))) : '...........';
        $volStat  = isset($ens['volume_statutaire']) && $ens['volume_statutaire'] !== null && $ens['volume_statutaire'] !== ''
            ? $x($ens['volume_statutaire']) . 'h' : '..........';
        $abatt    = isset($ens['abattement']) && $ens['abattement'] !== null && $ens['abattement'] !== ''
            ? $x($ens['abattement']) . 'h' : '..........';
        $motif    = $x($ens['motif_abattement'] ?? '');
        $volApres = isset($ens['volume_apres_abatt']) && $ens['volume_apres_abatt'] !== null && $ens['volume_apres_abatt'] !== ''
            ? $x($ens['volume_apres_abatt']) . 'h' : '..........';
        $etabRatt = $x($ens['etab_rattachement'] ?? '...........');
        $etabBen  = $x($ens['etab_beneficiaire'] ?? '...........');
        $typeLabel = (($ens['type_enseignant'] ?? 'permanent') === 'vacataire') ? 'vacataire' : 'permanent';

        // Lignes S1
        $rowsS1 = self::semestreHeaderRow("Premier semestre de l'ann\u{e9}e");
        foreach ($s1 as $i => $f) {
            $rowsS1 .= self::ficheRow(
                $i + 1, $f['code'] ?? '',
                self::buildParcours($f['niveau'] ?? '', $f['semestre'] ?? '', $f['parcours'] ?? ''),
                $f['cours'] ?? '', $f['ntc'] ?? '',
                (int)$f['volume_cm'], (int)$f['volume_td']
            );
        }
        $rowsS1 .= self::totalRow('TOTAL DU SEMESTRE 1', $tcmS1, $ttdS1);

        // Lignes S2
        $rowsS2 = self::semestreHeaderRow("Deuxi\u{e8}me semestre de l'ann\u{e9}e");
        foreach ($s2 as $i => $f) {
            $rowsS2 .= self::ficheRow(
                $i + 1, $f['code'] ?? '',
                self::buildParcours($f['niveau'] ?? '', $f['semestre'] ?? '', $f['parcours'] ?? ''),
                $f['cours'] ?? '', $f['ntc'] ?? '',
                (int)$f['volume_cm'], (int)$f['volume_td']
            );
        }
        $rowsS2 .= self::totalRow('TOTAL DU SEMESTRE 2', $tcmS2, $ttdS2);

        $rowTotaux = self::totalRow('TOTAUX', $tcmS1 + $tcmS2, $ttdS1 + $ttdS2, true);

        // ── Tableau d'en-tête institutionnel ─────────────────
        $borderNone = '<w:tblBorders>'
            . '<w:top w:val="none" w:sz="0" w:space="0" w:color="auto"/>'
            . '<w:start w:val="none" w:sz="0" w:space="0" w:color="auto"/>'
            . '<w:bottom w:val="none" w:sz="0" w:space="0" w:color="auto"/>'
            . '<w:end w:val="none" w:sz="0" w:space="0" w:color="auto"/>'
            . '<w:insideH w:val="none" w:sz="0" w:space="0" w:color="auto"/>'
            . '<w:insideV w:val="none" w:sz="0" w:space="0" w:color="auto"/>'
            . '</w:tblBorders>';

        $cellMar = '<w:tblCellMar>'
            . '<w:top w:w="60" w:type="dxa"/><w:start w:w="120" w:type="dxa"/>'
            . '<w:bottom w:w="60" w:type="dxa"/><w:end w:w="120" w:type="dxa"/>'
            . '</w:tblCellMar>';

        $entete = '<w:tbl>'
            . '<w:tblPr>'
            . '<w:tblW w:w="9360" w:type="dxa"/>'
            . $borderNone
            . '<w:tblLayout w:type="fixed"/>'
            . $cellMar
            . '</w:tblPr>'
            . '<w:tblGrid><w:gridCol w:w="4680"/><w:gridCol w:w="4680"/></w:tblGrid>'
            . '<w:tr>'
            . self::cell(
                self::para(self::boldRun('MINISTERE DE L\u2019ENSEIGNEMENT SUPERIEUR,', 20), 'center') .
                self::para(self::boldRun('DE LA RECHERCHE ET DE L\u2019INNOVATION', 20), 'center') .
                self::para(self::boldRun('---------------', 20), 'center') .
                self::para(self::boldRun('SECRETARIAT GENERAL', 20), 'center') .
                self::para(self::boldRun('---------------', 20), 'center') .
                self::para(self::boldRun($etabBen, 20), 'center') .
                self::para(self::boldRun('---------------', 20), 'center') .
                self::para(self::boldRun('PRESIDENCE', 20), 'center'),
                4680, '', 1, 'top'
            )
            . self::cell(
                self::para(self::boldRun('BURKINA FASO', 20), 'right') .
                self::para(self::run("Unit\u{e9}-Progr\u{e8}s-Justice", false, 20), 'right') .
                '<w:p/>' .
                self::para(self::run("Ann\u{e9}e universitaire " . $x($annee), false, 20), 'right'),
                4680, '', 1, 'top'
            )
            . '</w:tr>'
            . '</w:tbl>';

        // ── Tableau de signatures ────────────────────────────
        $signatures = '<w:tbl>'
            . '<w:tblPr>'
            . '<w:tblW w:w="9360" w:type="dxa"/>'
            . $borderNone
            . '<w:tblLayout w:type="fixed"/>'
            . '</w:tblPr>'
            . '<w:tblGrid><w:gridCol w:w="3120"/><w:gridCol w:w="3120"/><w:gridCol w:w="3120"/></w:tblGrid>'
            . '<w:tr>'
            . self::cell(self::para(self::boldRun("L\u2019enseignant", 20), 'center'), 3120)
            . self::cell(self::para(self::boldRun('Le Chef de D\u00e9partement', 20), 'center'), 3120)
            . self::cell(self::para(self::boldRun('Vu et approuv\u00e9 par', 20), 'center'), 3120)
            . '</w:tr>'
            . '<w:tr>'
            . self::cell('<w:p/>', 3120)
            . self::cell('<w:p/>', 3120)
            . self::cell(
                '<w:p><w:pPr><w:spacing w:before="480"/><w:jc w:val="center"/></w:pPr>'
                . self::run('Le Directeur', false, 20)
                . '</w:p>',
                3120
            )
            . '</w:tr>';

        // ── Tableau principal des enseignements ───────────────
        $borderSingle = '<w:tblBorders>'
            . '<w:top w:val="single" w:sz="4" w:space="0" w:color="000000"/>'
            . '<w:start w:val="single" w:sz="4" w:space="0" w:color="000000"/>'
            . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="000000"/>'
            . '<w:end w:val="single" w:sz="4" w:space="0" w:color="000000"/>'
            . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="000000"/>'
            . '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="000000"/>'
            . '</w:tblBorders>';

        $cellMarTable = '<w:tblCellMar>'
            . '<w:top w:w="80" w:type="dxa"/><w:start w:w="120" w:type="dxa"/>'
            . '<w:bottom w:w="80" w:type="dxa"/><w:end w:w="120" w:type="dxa"/>'
            . '</w:tblCellMar>';

        $tableau = '<w:tbl>'
            . '<w:tblPr>'
            . '<w:tblW w:w="9360" w:type="dxa"/>'
            . $borderSingle
            . '<w:tblLayout w:type="fixed"/>'
            . $cellMarTable
            . '</w:tblPr>'
            . '<w:tblGrid>'
            . '<w:gridCol w:w="400"/><w:gridCol w:w="1200"/><w:gridCol w:w="1800"/>'
            . '<w:gridCol w:w="3160"/><w:gridCol w:w="600"/><w:gridCol w:w="1100"/>'
            . '<w:gridCol w:w="600"/><w:gridCol w:w="500"/>'
            . '</w:tblGrid>'
            . self::headerRow()
            . $rowsS1
            . $rowsS2
            . $rowTotaux
            . '</w:tbl>';

        // ── Assemblage du document ────────────────────────────
        $body = $entete

            . self::para(self::boldRun('FICHE PROGRAMMATIQUE', 32) . '<w:r><w:rPr><w:b/><w:sz w:val="32"/><w:u w:val="single"/></w:rPr><w:t/></w:r>', 'center', '240', '60')

            . self::para('<w:r><w:rPr><w:i/><w:sz w:val="24"/></w:rPr><w:t>Pour enseignant ' . $x($typeLabel) . '</w:t></w:r>', 'center', '0', '120')

            . self::para(
                self::boldRun('Nom et pr\u00e9nom(s) : ') . self::run($nom) .
                self::run('     ') .
                self::boldRun('Grade : ') . self::run($grade),
                '', '60', '60'
            )
            . self::para(
                self::boldRun('Date de Nomination : ') . self::run($dateNom),
                '', '60', '60'
            )
            . self::para(
                self::boldRun('Volume horaire statutaire : ') . self::run($volStat) .
                self::run('   ') .
                self::boldRun('Abattement : ') . self::run($abatt) .
                self::run('   ') .
                self::boldRun("Motif de l\u2019abattement : ") . self::run($motif),
                '', '60', '60'
            )
            . self::para(
                self::boldRun("Volume horaire obligatoire apr\u{e8}s abattement : ") . self::run($volApres),
                '', '60', '60'
            )
            . self::para(
                self::boldRun('Etablissement de rattachement administratif : ') . self::run($etabRatt),
                '', '60', '60'
            )
            . self::para(
                self::boldRun("Etablissement b\u{e9}n\u{e9}ficiaire des enseignements : ") . self::run($etabBen),
                '', '60', '120'
            )
            . self::para(
                self::boldRun('Tableau descriptif des enseignements confi\u00e9s en r\u00e9union de d\u00e9partement', 22),
                'center', '120', '120'
            )

            . $tableau

            . '<w:p><w:pPr><w:spacing w:before="360" w:after="60"/></w:pPr>'
            . self::run("Ouagadougou, le ................................", false, 22)
            . '</w:p>'

            . $signatures

            . '</w:tbl>'

            . self::para(
                '<w:r><w:rPr><w:i/><w:sz w:val="18"/></w:rPr>'
                . '<w:t>NB : NTC = nombre total de cr\u00e9dits. Ne remplir qu\u2019une seule fiche pour toutes les interventions sur le campus.</w:t></w:r>',
                '', '360', '60'
            )
            . self::para(
                '<w:r><w:rPr><w:i/><w:sz w:val="18"/></w:rPr>'
                . '<w:t>Ces fiches doivent \u00eatre imp\u00e9rativement d\u00e9pos\u00e9es par tout enseignant apr\u00e8s la r\u00e9union d\u2019attribution des heures par le d\u00e9partement.</w:t></w:r>',
                '', '0', '60'
            );

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
            . ' xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>'
            . $body
            . '<w:sectPr>'
            . '<w:pgSz w:w="11906" w:h="16838"/>'
            . '<w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="900" w:header="709" w:footer="709" w:gutter="0"/>'
            . '</w:sectPr>'
            . '</w:body></w:document>';
    }

    // ── Construction du .docx (ZIP) ──────────────────────────
    private static function buildZip(string $docXml): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'fiche_') . '.docx';
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '</Types>'
        );

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>'
        );

        $zip->addFromString('word/_rels/document.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>'
        );

        $zip->addFromString('word/document.xml', $docXml);

        $zip->addFromString('word/styles.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:docDefaults><w:rPrDefault><w:rPr>'
            . '<w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>'
            . '<w:sz w:val="22"/><w:szCs w:val="22"/>'
            . '<w:lang w:val="fr-FR"/>'
            . '</w:rPr></w:rPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal">'
            . '<w:name w:val="Normal"/>'
            . '<w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr>'
            . '</w:style>'
            . '</w:styles>'
        );

        $zip->close();
        $content = file_get_contents($tmp);
        @unlink($tmp);
        return $content;
    }
}
