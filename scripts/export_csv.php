#!/usr/bin/env php
<?php
// ============================================================
// scripts/export_csv.php — Export CSV complet (MySQL)
//   Usage : php scripts\export_csv.php [statut]
//   Ex.   : php scripts\export_csv.php validee
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';

$statut = $argv[1] ?? null;
$statutsValides = ['en_attente', 'validee', 'rejetee'];

if ($statut && !in_array($statut, $statutsValides, true)) {
    echo "Statut invalide. Valeurs : " . implode(', ', $statutsValides) . "\n";
    exit(1);
}

$pdo   = Database::getInstance();
$where = $statut ? "WHERE f.statut = " . $pdo->quote($statut) : '';

$fiches = $pdo->query(
    "SELECT
       f.id,
       e.matricule,
       e.nom,
       e.type_enseignant,
       e.grade,
       e.date_nomination,
       e.departement,
       e.email,
       e.etab_beneficiaire,
       e.etab_rattachement,
       e.volume_statutaire,
       e.abattement,
       e.motif_abattement,
       e.volume_apres_abatt,
       f.cours,
       f.code_ue,
       f.code,
       f.parcours,
       f.ntc,
       f.niveau,
       f.semestre,
       f.volume_cm,
       f.volume_td,
       (f.volume_cm + f.volume_td) AS volume_total,
       f.evaluation,
       f.objectifs,
       f.statut,
       f.annee_academique,
       f.submitted_at,
       f.modifie_le,
       f.nb_modifications
     FROM fiches f
     JOIN enseignants e ON e.id = f.enseignant_id
     $where
     ORDER BY e.nom ASC, f.semestre ASC, f.submitted_at ASC"
)->fetchAll(PDO::FETCH_ASSOC);

if (empty($fiches)) {
    echo "Aucune fiche trouvée.\n";
    exit(0);
}

$dataDir = __DIR__ . '/../data';
if (!is_dir($dataDir)) { mkdir($dataDir, 0750, true); }

$filename = 'export_fiches_' . ($statut ?? 'toutes') . '_' . date('Ymd_His') . '.csv';
$path     = $dataDir . '/' . $filename;

$fh = fopen($path, 'w');
fwrite($fh, "\xEF\xBB\xBF"); // BOM UTF-8 pour Excel

fputcsv($fh, [
    'ID', 'Matricule', 'Nom', 'Type', 'Grade', 'Date nomination',
    'Département', 'Email', 'Établ. bénéficiaire', 'Établ. rattachement',
    'Vol. statutaire (h)', 'Abattement (h)', 'Motif abattement', 'Vol. après abatt. (h)',
    'UE/ECUE', 'Code UE', 'Code', 'Parcours', 'NTC',
    'Niveau', 'Semestre', 'CM (h)', 'TD/TP (h)', 'Total (h)',
    'Évaluation', 'Objectifs', 'Statut', 'Année académique',
    'Date soumission', 'Date modification', 'Nb modifications'
], ';');

foreach ($fiches as $f) {
    fputcsv($fh, array_values($f), ';');
}
fclose($fh);

echo "Export : $path\n";
echo count($fiches) . " fiche(s), " . count(array_unique(array_column($fiches, 'matricule'))) . " enseignant(s).\n";
