<?php
// ============================================================
// voir_fiche_qr.php — Consultation publique via QR code
// Accès : voir_fiche_qr.php?h=HASH&m=MATRICULE&a=ANNEE&t=TYPE
// Lecture seule, pas de modification possible
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';
if (!class_exists('App')) require_once __DIR__ . '/src/App.php';
App::configureErrorDisplay();
require_once __DIR__ . '/src/FicheRepository.php';
require_once __DIR__ . '/src/ValidationRepository.php';

$config   = require __DIR__ . '/config/security.php';
$security = new Security($config);
$security->sendSecurityHeaders();

// ── Paramètres QR ─────────────────────────────────────────────
$hash      = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $_GET['h'] ?? ''));
$matricule = preg_replace('/[^A-Za-z0-9]/', '', $_GET['m'] ?? '');
$annee     = preg_replace('/[^0-9\-]/', '', $_GET['a'] ?? '');
$type      = in_array($_GET['t'] ?? '', ['programmatique','suivi']) ? $_GET['t'] : 'programmatique';

if (!$hash || !$matricule || !$annee) {
    http_response_code(400);
    die(self_error('Lien invalide', 'Le QR code est illisible ou incomplet.'));
}

// ── Vérifier le hash ──────────────────────────────────────────
$expected = strtoupper(substr(hash('sha256', $matricule . $annee . $type), 0, 12));
if (!hash_equals($expected, $hash)) {
    http_response_code(403);
    die(self_error('Document invalide', 'Ce document ne peut pas être authentifié.'));
}

// ── Charger l'enseignant et ses fiches ────────────────────────
$pdo  = Database::getInstance();
$repo = new FicheRepository();
$valRepo = new ValidationRepository();

$stmt = $pdo->prepare("SELECT * FROM enseignants WHERE matricule = ? LIMIT 1");
$stmt->execute([$matricule]);
$enseignant = $stmt->fetch();

if (!$enseignant) {
    http_response_code(404);
    die(self_error('Introuvable', 'Aucun enseignant trouvé pour ce document.'));
}

// Fiches validées de l'enseignant pour l'année
$stmt = $pdo->prepare(
    "SELECT * FROM fiches
     WHERE enseignant_id = ? AND statut = 'validee'
     ORDER BY semestre, submitted_at"
);
$stmt->execute([(int)$enseignant['id']]);
$fiches = $stmt->fetchAll();

$security->audit('qr_scan', $matricule, "type=$type annee=$annee");

// ── Affichage lecture seule ───────────────────────────────────
function self_error(string $titre, string $msg): string {
    return '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>'.$titre.' — UJKZ</title>
    <style>body{font-family:system-ui,sans-serif;background:#f0f2f0;display:flex;
    align-items:center;justify-content:center;min-height:100vh;margin:0}
    .box{background:#fff;border-radius:12px;padding:2rem;text-align:center;
    box-shadow:0 4px 20px rgba(0,104,55,.1);max-width:400px}
    h2{color:#c00}.icon{font-size:3rem}</style></head><body>
    <div class="box"><div class="icon">⚠️</div><h2>'.$titre.'</h2><p>'.$msg.'</p></div>
    </body></html>';
}

function v(mixed $s): string {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}
function fmt_date(string $d): string {
    return $d ? date('d/m/Y', strtotime($d)) : '—';
}

// Regrouper les validations par fiche
$allValidations = [];
$fichesAvecDetails = [];
foreach ($fiches as $f) {
    $fid = (int)$f['id'];
    $hist = $valRepo->getHistorique($fid);
    $preuves = $valRepo->getPreuves($fid);
    $vals = [];
    foreach ($hist as $h) {
        $vals[$h['role']] = [
            'nom'      => $h['valideur_nom'] ?? ($h['nom'] ?? ''),
            'date'     => fmt_date($h['created_at'] ?? ''),
            'decision' => $h['decision'],
        ];
        if (!isset($allValidations[$h['role']]) || $h['decision'] === 'valide') {
            $allValidations[$h['role']] = $vals[$h['role']];
        }
    }
    $fichesAvecDetails[] = array_merge($f, [
        'validations' => $vals,
        'preuves'     => $preuves,
    ]);
}

$nomEns   = v($enseignant['nom']);
$grade    = v($enseignant['grade'] ?? '');
$typeLabel = $type === 'suivi'
    ? 'Fiche Semestrielle de Suivi' : 'Fiche Programmatique';

$s1 = array_filter($fichesAvecDetails, function($f){ return ($f['semestre']??'')==='S1'; });
$s2 = array_filter($fichesAvecDetails, function($f){ return ($f['semestre']??'')==='S2'; });

$roleLabels = [
    'chef_dept'         => 'Chef de Département',
    'directeur_adjoint' => 'Directeur Adjoint',
    'directeur'         => 'Directeur',
    'dei'               => 'DEI',
];

function badge(array $val): string {
    $dec = $val['decision'] ?? '';
    if ($dec === 'valide') {
        return '<span class="badge ok">✓ Validé par ' . v($val['nom']) . ' — ' . $val['date'] . '</span>';
    }
    if ($dec === 'rejete') {
        return '<span class="badge ko">✕ Rejeté — ' . $val['date'] . '</span>';
    }
    return '<span class="badge att">En attente</span>';
}

function rowProg(array $f): string {
    $cm = (int)($f['volume_cm']??0);
    $td = (int)($f['volume_td']??0);
    return '<tr>
        <td class="c">'.v($f['code']??'').'</td>
        <td>'.v(($f['niveau']??'').''.($f['semestre']??'').' '.($f['parcours']??'')).'</td>
        <td>'.v($f['cours']??'').'</td>
        <td class="c">'.v($f['ntc']??'').'</td>
        <td class="c">'.($cm?:'-').'</td>
        <td class="c">'.($td?:'-').'</td>
    </tr>';
}

function rowSuivi(array $f): string {
    $cm = (int)($f['volume_cm']??0);
    $td = (int)($f['volume_td']??0);
    $preuves = $f['preuves'] ?? [];
    $ecm = (int)array_sum(array_column($preuves,'volume_cm_effectue'));
    $etd = (int)array_sum(array_column($preuves,'volume_td_effectue'));
    return '<tr>
        <td class="c">'.v($f['code']??'').'</td>
        <td>'.v(($f['niveau']??'').''.($f['semestre']??'').' '.($f['parcours']??'')).'</td>
        <td>'.v($f['cours']??'').'</td>
        <td class="c">'.v($f['ntc']??'').'</td>
        <td class="c">'.($cm?:'-').'</td>
        <td class="c">'.($td?:'-').'</td>
        <td class="c">'.($ecm?:'-').'</td>
        <td class="c">'.($etd?:'-').'</td>
    </tr>';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= v($typeLabel) ?> — <?= $nomEns ?> — UJKZ</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,Arial,sans-serif;background:#f0f2f0;color:#1a2e1a;font-size:14px}
.page{max-width:860px;margin:20px auto;background:#fff;border-radius:8px;
      box-shadow:0 2px 12px rgba(0,104,55,.1);overflow:hidden}
/* En-tête */
.entete{display:grid;grid-template-columns:1fr auto 1fr;gap:8px;padding:16px 20px;
        border-bottom:3px solid #006837}
.entete-left{font-size:11px;font-weight:700;line-height:1.6;text-align:center}
.entete-right{font-size:11px;text-align:right;line-height:1.8}
.entete-right b{font-size:13px;font-style:italic}
.logo{width:70px;height:70px;object-fit:contain}
/* Titre */
.titre-fiche{background:#e8e8e8;text-align:center;padding:12px;border-bottom:1px solid #ccc}
.titre-fiche h1{font-size:16px;font-weight:800;text-transform:uppercase;color:#1a2e1a}
.titre-fiche p{font-size:12px;font-style:italic;color:#555;margin-top:2px}
/* Corps */
.corps{padding:16px 20px}
.infos{display:grid;gap:4px;margin-bottom:14px;font-size:13px}
.infos .row{display:flex;gap:4px;flex-wrap:wrap}
.infos label{font-weight:700;white-space:nowrap}
/* Tableau */
.section-titre{font-weight:700;font-size:13px;text-align:center;
               text-decoration:underline;margin:14px 0 6px}
table{width:100%;border-collapse:collapse;font-size:12px;margin-bottom:10px}
th{background:#d0d0d0;font-weight:700;padding:5px 4px;border:1px solid #999;text-align:center}
td{padding:4px 5px;border:1px solid #ccc;vertical-align:middle}
td.c{text-align:center}
.sem-header{background:#e8e8e8;font-style:italic;font-weight:700;
            text-align:center;padding:5px}
.total-row td{background:#c0c0c0;font-weight:700;text-align:center}
.grand-total td{background:#a0a0a0;font-weight:700;text-align:center}
/* Validations */
.validations{margin-top:16px;padding:14px;background:#f9fdf9;
             border:1px solid #c8e6c9;border-radius:6px}
.validations h3{font-size:13px;font-weight:700;margin-bottom:10px;color:#006837}
.val-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.val-card{padding:10px;background:#fff;border:1px solid #ddd;border-radius:4px;font-size:12px}
.val-card strong{display:block;margin-bottom:4px;font-size:12px;color:#333}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600}
.badge.ok{background:#e8f5e9;color:#1b5e20}
.badge.ko{background:#ffebee;color:#b71c1c}
.badge.att{background:#fff8e1;color:#e65100}
/* Readonly notice */
.readonly-banner{background:#fff3e0;border-left:4px solid #ff9800;
                 padding:10px 16px;font-size:12px;color:#e65100;
                 display:flex;align-items:center;gap:8px}
/* Pied */
.pied{background:#f5f5f5;border-top:1px solid #ddd;padding:10px 20px;
      font-size:11px;color:#888;text-align:center}
.annee-badge{display:inline-block;background:#006837;color:#fff;
             padding:2px 10px;border-radius:10px;font-weight:700;font-size:12px}
</style>
</head>
<body>
<div class="page">

  <!-- En-tête -->
  <div class="entete">
    <div class="entete-left">
      MINISTÈRE DE L'ENSEIGNEMENT<br>
      SUPÉRIEUR, DE LA RECHERCHE<br>
      ET DE L'INNOVATION<br>
      ———<br>
      SECRÉTARIAT GÉNÉRAL<br>
      ———<br>
      <strong>UNIVERSITÉ JOSEPH KI-ZERBO</strong><br>
      ———<br>
      PRÉSIDENCE
    </div>
    <div style="text-align:center">
      <img src="logo_ujkz.jpg" class="logo" alt="Logo UJKZ">
    </div>
    <div class="entete-right">
      <b><i>BURKINA FASO</i></b><br>
      <i>La Patrie ou la mort, Nous vaincrons</i><br><br>
      <span class="annee-badge"><?= v($annee) ?></span>
    </div>
  </div>

  <!-- Titre -->
  <div class="titre-fiche">
    <h1><?= v($typeLabel) ?></h1>
    <p>Pour enseignant <?= v($enseignant['type_enseignant'] ?? 'permanent') ?></p>
  </div>

  <!-- Bandeau lecture seule -->
  <div class="readonly-banner">
    🔒 <strong>Document officiel — Consultation uniquement.</strong>
    Toute modification est impossible depuis cette interface.
  </div>

  <div class="corps">

    <!-- Infos enseignant -->
    <div class="infos">
      <div class="row"><label>Nom :</label> <?= $nomEns ?></div>
      <div class="row">
        <label>Grade :</label> <?= $grade ?>
        &nbsp;&nbsp;
        <label>Date de Nomination :</label>
        <?= v(!empty($enseignant['date_nomination']) ? fmt_date($enseignant['date_nomination']) : '—') ?>
      </div>
      <div class="row">
        <label>Volume horaire statutaire :</label>
        <?= v($enseignant['volume_statutaire'] ?? '—') ?>h
        &nbsp;&nbsp;
        <label>Abattement :</label>
        <?= v($enseignant['abattement'] ?? '—') ?>%
        &nbsp;&nbsp;
        <label>Motif :</label>
        <?= v($enseignant['motif_abattement'] ?? '—') ?>
      </div>
      <div class="row">
        <label>Volume horaire obligatoire après abattement :</label>
        <?= v($enseignant['volume_apres_abatt'] ?? '—') ?>h
      </div>
      <?php if ($enseignant['etab_rattachement'] ?? ''): ?>
      <div class="row">
        <label>Établissement de rattachement :</label>
        <?= v($enseignant['etab_rattachement']) ?>
      </div>
      <?php endif; ?>
      <div class="row">
        <label>Établissement bénéficiaire :</label>
        <?= v($enseignant['etab_beneficiaire'] ?? '—') ?>
      </div>
    </div>

    <!-- Tableau -->
    <?php if ($type === 'programmatique'): ?>
    <div class="section-titre">
      Tableau descriptif des enseignements confiés en réunion de département
    </div>
    <table>
      <thead>
        <tr>
          <th>CODE</th><th>PARCOURS</th><th>UE ou ECUE</th>
          <th>NTC</th><th>CT (h)</th><th>TD (h)</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($s1): ?>
        <tr><td class="sem-header" colspan="6">Premier semestre</td></tr>
        <?php foreach ($s1 as $f) echo rowProg($f); ?>
        <tr class="total-row">
          <td colspan="4">TOTAL S1</td>
          <td><?= (int)array_sum(array_column(array_values($s1),'volume_cm')) ?></td>
          <td><?= (int)array_sum(array_column(array_values($s1),'volume_td')) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($s2): ?>
        <tr><td class="sem-header" colspan="6">Deuxième semestre</td></tr>
        <?php foreach ($s2 as $f) echo rowProg($f); ?>
        <tr class="total-row">
          <td colspan="4">TOTAL S2</td>
          <td><?= (int)array_sum(array_column(array_values($s2),'volume_cm')) ?></td>
          <td><?= (int)array_sum(array_column(array_values($s2),'volume_td')) ?></td>
        </tr>
        <?php endif; ?>
        <tr class="grand-total">
          <td colspan="4">TOTAUX</td>
          <td><?= (int)array_sum(array_column($fichesAvecDetails,'volume_cm')) ?></td>
          <td><?= (int)array_sum(array_column($fichesAvecDetails,'volume_td')) ?></td>
        </tr>
      </tbody>
    </table>

    <?php else: // suivi ?>
    <div class="section-titre">
      Tableau descriptif des enseignements confiés et effectués
    </div>
    <table>
      <thead>
        <tr>
          <th rowspan="2">CODE</th><th rowspan="2">PARCOURS</th>
          <th rowspan="2">UE ou ECUE</th><th rowspan="2">NTC</th>
          <th colspan="2">Volume confié</th>
          <th colspan="2">Volume effectué</th>
        </tr>
        <tr><th>CT</th><th>TD</th><th>CT</th><th>TD</th></tr>
      </thead>
      <tbody>
        <?php if ($s1): ?>
        <tr><td class="sem-header" colspan="8">Premier semestre</td></tr>
        <?php foreach ($s1 as $f) echo rowSuivi($f); ?>
        <?php
        $ecm1s = array_sum(array_map(function($f){ return array_sum(array_column($f['preuves'],'volume_cm_effectue')); },array_values($s1)));
        $etd1s = array_sum(array_map(function($f){ return array_sum(array_column($f['preuves'],'volume_td_effectue')); },array_values($s1)));
        ?>
        <tr class="total-row">
          <td colspan="4">TOTAL S1</td>
          <td><?= (int)array_sum(array_column(array_values($s1),'volume_cm')) ?></td>
          <td><?= (int)array_sum(array_column(array_values($s1),'volume_td')) ?></td>
          <td><?= (int)$ecm1s ?></td>
          <td><?= (int)$etd1s ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($s2): ?>
        <tr><td class="sem-header" colspan="8">Deuxième semestre</td></tr>
        <?php foreach ($s2 as $f) echo rowSuivi($f); ?>
        <?php
        $ecm2s = array_sum(array_map(function($f){ return array_sum(array_column($f['preuves'],'volume_cm_effectue')); },array_values($s2)));
        $etd2s = array_sum(array_map(function($f){ return array_sum(array_column($f['preuves'],'volume_td_effectue')); },array_values($s2)));
        ?>
        <tr class="total-row">
          <td colspan="4">TOTAL S2</td>
          <td><?= (int)array_sum(array_column(array_values($s2),'volume_cm')) ?></td>
          <td><?= (int)array_sum(array_column(array_values($s2),'volume_td')) ?></td>
          <td><?= (int)$ecm2s ?></td>
          <td><?= (int)$etd2s ?></td>
        </tr>
        <?php endif; ?>
        <?php
        $ecmTs=array_sum(array_map(function($f){ return array_sum(array_column($f['preuves'],'volume_cm_effectue')); },$fichesAvecDetails));
        $etdTs=array_sum(array_map(function($f){ return array_sum(array_column($f['preuves'],'volume_td_effectue')); },$fichesAvecDetails));
        ?>
        <tr class="grand-total">
          <td colspan="4">TOTAUX</td>
          <td><?= (int)array_sum(array_column($fichesAvecDetails,'volume_cm')) ?></td>
          <td><?= (int)array_sum(array_column($fichesAvecDetails,'volume_td')) ?></td>
          <td><?= (int)$ecmTs ?></td>
          <td><?= (int)$etdTs ?></td>
        </tr>
      </tbody>
    </table>
    <?php endif; ?>

    <!-- Validations -->
    <div class="validations">
      <h3>🔏 Validations officielles</h3>
      <div class="val-grid">
        <?php foreach ($roleLabels as $role => $label): ?>
          <?php if (isset($allValidations[$role])): ?>
          <div class="val-card">
            <strong><?= v($label) ?></strong>
            <?= badge($allValidations[$role]) ?>
          </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>

  </div><!-- /corps -->

  <div class="pied">
    Document généré par le Système de gestion des fiches programmatiques de l'UJKZ<br>
    Authentifié • <?= date('d/m/Y à H:i') ?> •
    Matricule : <?= v($matricule) ?> • Année : <?= v($annee) ?>
  </div>

</div><!-- /page -->
</body>
</html>
