<?php
require_once __DIR__ . '/src/Database.php';
$pdo = Database::getInstance();
header('Content-Type: text/plain; charset=utf-8');

echo "=== FICHES (IDs bénéficiaires) ===\n";
$rows = $pdo->query("SELECT f.id, f.cours, f.type_workflow, f.etab_beneficiaire_fiche, f.dept_beneficiaire_fiche, f.statut, e.nom AS ens_nom FROM fiches f JOIN enseignants e ON e.id=f.enseignant_id ORDER BY f.id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) printf("  [%d] %s | wf=%s | etab_id=%s | dept_id=%s | statut=%s\n", $r['id'], $r['cours'], $r['type_workflow'], $r['etab_beneficiaire_fiche'], $r['dept_beneficiaire_fiche'], $r['statut']);

echo "\n=== ETABLISSEMENTS ===\n";
foreach ($pdo->query("SELECT id,sigle,nom FROM etablissements ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r)
    printf("  [%d] %s — %s\n", $r['id'], $r['sigle'], $r['nom']);

echo "\n=== DEPARTEMENTS ===\n";
foreach ($pdo->query("SELECT d.id,d.nom,d.sigle,e.sigle AS es FROM departements d JOIN etablissements e ON e.id=d.etablissement_id ORDER BY d.id")->fetchAll(PDO::FETCH_ASSOC) as $r)
    printf("  [%d] %s (%s) — étab: %s\n", $r['id'], $r['nom'], $r['sigle'], $r['es']);

echo "\n=== UTILISATEURS ===\n";
foreach ($pdo->query("SELECT id,nom,role,departement,departement_id,etablissement FROM utilisateurs WHERE actif=1 ORDER BY role,nom")->fetchAll(PDO::FETCH_ASSOC) as $u) {
    $etabs = $u['etablissement'] ? json_decode($u['etablissement'],true) : [];
    printf("  [%d] %s (%s) | dept=[%s] dept_id=%s | etabs=[%s]\n", $u['id'], $u['nom'], $u['role'], $u['departement'], $u['departement_id'], is_array($etabs)?implode('|',$etabs):$u['etablissement']);
}

echo "\n=== CORRESPONDANCES (par IDs) ===\n";
$fiches2 = array_filter($rows, fn($r)=>$r['type_workflow']!=='IESR_UJKZ');
$users = $pdo->query("SELECT id,nom,role,departement_id,etablissement FROM utilisateurs WHERE actif=1")->fetchAll(PDO::FETCH_ASSOC);
foreach ($users as $u) {
    $etabIds = [];
    if ($u['etablissement']) { $dec=json_decode($u['etablissement'],true); if(!is_array($dec)) $dec=[$u['etablissement']]; foreach($dec as $en){ $r=$pdo->prepare("SELECT id FROM etablissements WHERE nom=?"); $r->execute([$en]); $row=$r->fetch(); if($row) $etabIds[]=(int)$row['id']; } }
    foreach ($fiches2 as $f) {
        if (in_array($u['role'],['chef_dept'])) {
            $m = ($u['departement_id'] && (int)$u['departement_id']===(int)$f['dept_beneficiaire_fiche']) ? 'MATCH ✓' : 'NO MATCH ✗';
            printf("  Chef[%s] dept_id=%s vs fiche[%d] dept_id=%s → %s\n",$u['nom'],$u['departement_id'],$f['id'],$f['dept_beneficiaire_fiche'],$m);
        }
        if (in_array($u['role'],['directeur','directeur_adjoint'])) {
            $m = (!empty($etabIds) && in_array((int)$f['etab_beneficiaire_fiche'],$etabIds)) ? 'MATCH ✓' : 'NO MATCH ✗';
            printf("  Dir[%s] etab_ids=[%s] vs fiche[%d] etab_id=%s → %s\n",$u['nom'],implode(',',$etabIds),$f['id'],$f['etab_beneficiaire_fiche'],$m);
        }
    }
}
