<?php
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/EtabRepository.php';
header('Content-Type: text/plain; charset=utf-8');
$repo = new EtabRepository();
$etabsDB = $repo->getListeEtabs(true);
$deptsDB = $repo->getListeDepts(true);
echo "=== etabsDB (" . count($etabsDB) . ") ===\n";
foreach ($etabsDB as $e) echo "  [{$e['id']}] {$e['nom']}\n";
echo "\n=== deptsDB (" . count($deptsDB) . ") ===\n";
foreach ($deptsDB as $d) echo "  [{$d['id']}] etab_id={$d['etablissement_id']} {$d['nom']} ({$d['sigle']})\n";
echo "\n=== etabDeptIds ===\n";
$etabDeptIds = [];
foreach ($deptsDB as $d) $etabDeptIds[(int)$d['etablissement_id']][] = $d;
foreach ($etabDeptIds as $eid => $depts) echo "  etab_id=$eid : " . implode(', ', array_column($depts,'nom')) . "\n";
