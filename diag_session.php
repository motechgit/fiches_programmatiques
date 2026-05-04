<?php
session_start();
header('Content-Type: text/plain; charset=utf-8');
echo "=== SESSION AUTH ===\n";
echo "user_id:       " . ($_SESSION['user_id'] ?? 'N/A') . "\n";
echo "user_nom:      " . ($_SESSION['user_nom'] ?? 'N/A') . "\n";
echo "user_role:     " . ($_SESSION['user_role'] ?? 'N/A') . "\n";
echo "user_dept:     [" . ($_SESSION['user_dept'] ?? '') . "]\n";
echo "user_dept_id:  " . ($_SESSION['user_dept_id'] ?? 'N/A') . "\n";
echo "user_etabs:    "; print_r($_SESSION['user_etabs'] ?? 'N/A');
echo "user_etab_ids: "; print_r($_SESSION['user_etab_ids'] ?? 'N/A');

require_once __DIR__ . '/src/Database.php';
$pdo = Database::getInstance();

echo "\n=== UTILISATEUR EN BD ===\n";
if (!empty($_SESSION['user_id'])) {
    $u = $pdo->prepare("SELECT id,nom,role,departement,departement_id,etablissement,etablissement_id FROM utilisateurs WHERE id=?");
    $u->execute([$_SESSION['user_id']]);
    $row = $u->fetch(PDO::FETCH_ASSOC);
    if ($row) { foreach ($row as $k=>$v) echo "$k: [$v]\n"; }
}

echo "\n=== ETABLISSEMENTS EN BD ===\n";
foreach ($pdo->query("SELECT id,nom FROM etablissements WHERE actif=1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r)
    echo "  [{$r['id']}] {$r['nom']}\n";

echo "\n=== DEPARTEMENTS EN BD ===\n";
foreach ($pdo->query("SELECT d.id,d.nom,d.sigle,e.sigle AS es FROM departements d JOIN etablissements e ON e.id=d.etablissement_id WHERE d.actif=1 ORDER BY d.id")->fetchAll(PDO::FETCH_ASSOC) as $r)
    echo "  [{$r['id']}] {$r['es']} — {$r['nom']} ({$r['sigle']})\n";

echo "\n=== FICHES HORS UJKZ ===\n";
foreach ($pdo->query("SELECT id,cours,type_workflow,etab_beneficiaire_fiche,dept_beneficiaire_fiche FROM fiches WHERE type_workflow != 'IESR_UJKZ' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r)
    echo "  [{$r['id']}] {$r['cours']} | wf={$r['type_workflow']} | etab_id={$r['etab_beneficiaire_fiche']} | dept_id={$r['dept_beneficiaire_fiche']}\n";
