<?php
// ============================================================
// export_excel.php — Export données enseignants par UFR (DEI uniquement)
// ============================================================
declare(strict_types=1);
require_once __DIR__.'/src/Database.php';
require_once __DIR__.'/src/Security.php';
require_once __DIR__.'/src/Auth.php';
require_once __DIR__.'/src/FicheRepository.php';
require_once __DIR__.'/src/ValidationRepository.php';

$config   = require __DIR__.'/config/security.php';
$security = new Security($config);
$pdo      = Database::getInstance($config);
$auth     = Auth::fromSession();

// Seuls les DEI peuvent exporter
if (!$auth || $auth->role !== 'dei') {
    http_response_code(403);
    echo json_encode(['error' => 'Accès réservé à la DEI']);
    exit;
}

$ufrFilter = $_GET['etab'] ?? 'tous';

// Requête : tous les enseignants + leurs fiches + volumes
$whereSql  = '';
$params    = [];
if ($ufrFilter !== 'tous' && $ufrFilter !== '') {
    $whereSql = 'AND e.etab_beneficiaire LIKE ?';
    $params[] = '%'.$ufrFilter.'%';
}

$stmt = $pdo->prepare("
    SELECT
        e.matricule,
        e.nom,
        e.prenom,
        e.diplome,
        e.grade,
        e.type_enseignant,
        e.departement,
        e.etab_rattachement,
        e.etab_beneficiaire,
        e.volume_statutaire,
        e.abattement,
        e.volume_apres_abatt,
        COUNT(f.id)                                           AS nb_fiches,
        SUM(f.volume_cm)                                      AS total_cm,
        SUM(f.volume_td)                                      AS total_td,
        SUM(f.volume_tp)                                      AS total_tp,
        SUM(CASE WHEN f.semestre='S1' THEN f.volume_cm ELSE 0 END) AS cm_s1,
        SUM(CASE WHEN f.semestre='S1' THEN f.volume_td ELSE 0 END) AS td_s1,
        SUM(CASE WHEN f.semestre='S2' THEN f.volume_cm ELSE 0 END) AS cm_s2,
        SUM(CASE WHEN f.semestre='S2' THEN f.volume_td ELSE 0 END) AS td_s2,
        SUM(CASE WHEN f.statut='validee'    THEN 1 ELSE 0 END) AS nb_validees,
        SUM(CASE WHEN f.statut='en_attente' THEN 1 ELSE 0 END) AS nb_attente,
        SUM(CASE WHEN f.statut='rejetee'    THEN 1 ELSE 0 END) AS nb_rejetees
    FROM enseignants e
    LEFT JOIN fiches f ON f.enseignant_id = e.id
        AND f.annee_academique = ?
    WHERE 1=1 $whereSql
    GROUP BY e.id
    ORDER BY e.etab_beneficiaire, e.nom, e.prenom
");
$params = array_merge([$config['annee_academique']], $params);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Générer CSV (compatible Excel via BOM UTF-8)
$annee = $config['annee_academique'];
$label = $ufrFilter !== 'tous' ? urlencode($ufrFilter) : 'tous_UFR';
$filename = "export_enseignants_{$label}_{$annee}.csv";

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Cache-Control: no-cache');

$fp = fopen('php://output', 'w');
// BOM UTF-8 pour Excel
fwrite($fp, "\xEF\xBB\xBF");

// En-têtes
fputcsv($fp, [
    'Matricule','Nom','Prénom','Diplôme','Grade','Type','Département',
    'Étab. rattachement','Étab. bénéficiaire',
    'Vol. statutaire','Abattement','Vol. après abatt.',
    'Nb fiches','CT total','TD total','TP total',
    'CT S1','TD S1','CT S2','TD S2',
    'Nb validées','Nb en attente','Nb rejetées',
], ';');

foreach ($rows as $r) {
    fputcsv($fp, [
        $r['matricule'],
        $r['nom'],
        $r['prenom'] ?? '',
        $r['diplome'] ?? '',
        $r['grade'] ?? '',
        $r['type_enseignant'] ?? '',
        $r['departement'] ?? '',
        $r['etab_rattachement'] ?? '',
        $r['etab_beneficiaire'] ?? '',
        $r['volume_statutaire'] ?? '',
        $r['abattement'] ?? '',
        $r['volume_apres_abatt'] ?? '',
        (int)$r['nb_fiches'],
        (int)$r['total_cm'],
        (int)$r['total_td'],
        (int)$r['total_tp'],
        (int)$r['cm_s1'],
        (int)$r['td_s1'],
        (int)$r['cm_s2'],
        (int)$r['td_s2'],
        (int)$r['nb_validees'],
        (int)$r['nb_attente'],
        (int)$r['nb_rejetees'],
    ], ';');
}
fclose($fp);
