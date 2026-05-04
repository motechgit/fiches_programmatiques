<?php
// ============================================================
// admin_etabs.php — Gestion Établissements & Départements
// Accès : DEI uniquement
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';
if (!class_exists('App')) require_once __DIR__ . '/src/App.php';
App::configureErrorDisplay();
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/EtabRepository.php';

$config   = require __DIR__ . '/config/security.php';
$security = new Security($config);
$security->sendSecurityHeaders();
$security->startSecureSession();

if (!Auth::check() && empty($_SESSION['admin_authenticated'])) {
    header('Location: login.php'); exit;
}
if (Auth::check() && !Auth::isDei()) {
    header('Location: portail.php?error=acces_refuse'); exit;
}

$repo      = new EtabRepository();
$csrfToken = $security->generateCsrfToken();
$msg       = '';
$msgType   = 'success';

// ── Traitement POST ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$security->verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403); die('Token CSRF invalide.');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'etab_create') {
        $sigle = Security::sanitizeText($_POST['sigle'] ?? '', 30);
        $nom   = Security::sanitizeText($_POST['nom']   ?? '', 200);
        $ordre = (int)($_POST['ordre'] ?? 0);
        if (empty($nom)) { $msg = "Le nom de l'établissement est requis."; $msgType = 'error'; }
        else { $repo->createEtab($sigle, $nom, $ordre); $msg = "Établissement « $nom » créé."; }
    }
    elseif ($action === 'etab_update') {
        $id = (int)($_POST['id'] ?? 0); $sigle = Security::sanitizeText($_POST['sigle']??'',30);
        $nom = Security::sanitizeText($_POST['nom']??'',200); $ordre = (int)($_POST['ordre']??0);
        $actif = !empty($_POST['actif']);
        if ($id > 0 && !empty($nom)) { $repo->updateEtab($id, $sigle, $nom, $ordre, $actif); $msg = "Établissement mis à jour."; }
    }
    elseif ($action === 'etab_delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            if ($repo->deleteEtab($id)) $msg = "Établissement supprimé.";
            else { $msg = "Impossible : des départements sont liés à cet établissement."; $msgType = 'error'; }
        }
    }
    elseif ($action === 'etab_lier_user') {
        $etabId = (int)($_POST['etab_id']??0); $userId = (int)($_POST['user_id']??0);
        if ($etabId > 0 && $userId > 0) { $repo->lierUtilisateurEtab($userId, $etabId); $msg = "Utilisateur lié à l'établissement."; }
    }
    elseif ($action === 'etab_delier_user') {
        $etabId = (int)($_POST['etab_id']??0); $userId = (int)($_POST['user_id']??0);
        if ($etabId > 0 && $userId > 0) { $repo->delierUtilisateurEtab($userId, $etabId); $msg = "Utilisateur délié."; }
    }
    elseif ($action === 'dept_create') {
        $etabId = (int)($_POST['etablissement_id']??0);
        $nom   = Security::sanitizeText($_POST['nom']  ??'',150);
        $sigle = Security::sanitizeText($_POST['sigle']??'',30);
        $ordre = (int)($_POST['ordre']??0);
        if ($etabId <= 0 || empty($nom)) { $msg = "L'établissement et le nom sont requis."; $msgType = 'error'; }
        else { $repo->createDept($etabId, $nom, $sigle, $ordre); $msg = "Département « $nom » créé."; }
    }
    elseif ($action === 'dept_update') {
        $id = (int)($_POST['id']??0); $etabId = (int)($_POST['etablissement_id']??0);
        $nom = Security::sanitizeText($_POST['nom']??'',150); $sigle = Security::sanitizeText($_POST['sigle']??'',30);
        $ordre = (int)($_POST['ordre']??0); $actif = !empty($_POST['actif']);
        if ($id > 0 && $etabId > 0 && !empty($nom)) { $repo->updateDept($id, $etabId, $nom, $sigle, $ordre, $actif); $msg = "Département mis à jour."; }
    }
    elseif ($action === 'dept_delete') {
        $id = (int)($_POST['id']??0);
        if ($id > 0) {
            if ($repo->deleteDept($id)) $msg = "Département supprimé.";
            else { $msg = "Impossible : un chef de département est encore lié."; $msgType = 'error'; }
        }
    }
    elseif ($action === 'dept_lier_chef') {
        $deptId = (int)($_POST['dept_id']??0); $userId = (int)($_POST['user_id']??0);
        if ($deptId > 0 && $userId > 0) { $repo->lierUtilisateurDept($userId, $deptId); $msg = "Chef de département assigné."; }
    }

    if ($msg) $security->audit('admin_etabs_'.$action, Auth::userNom()??'admin', $msg);
    $anchor = strpos($action,'etab')!==false ? '#etabs' : '#departements';
    header('Location: '.App::url('admin_etabs.php?msg='.urlencode($msg).'&type='.$msgType.$anchor));
    exit;
}

if (!$msg && !empty($_GET['msg'])) {
    $msg = Security::sanitizeText($_GET['msg']??'',300);
    $msgType = Security::sanitizeText($_GET['type']??'success',10);
}

$arbre  = $repo->getArbreComplet();
$etabs  = $repo->getAllEtabs();
$depts  = $repo->getAllDepts();
$pdo    = Database::getInstance();
$tousDirecteurs = $pdo->query("SELECT id,nom,role FROM utilisateurs WHERE role IN ('directeur','directeur_adjoint') AND actif=1 ORDER BY role,nom")->fetchAll();
$tousChefs      = $pdo->query("SELECT id,nom FROM utilisateurs WHERE role='chef_dept' AND actif=1 ORDER BY nom")->fetchAll();

$e = function($v){ return Security::e((string)$v); };
$roleLabels = ['directeur'=>'Directeur','directeur_adjoint'=>'Dir. adjoint'];

// Rendu via le layout UJKZ
ob_start();
?>

<!-- Breadcrumb -->
<div class="breadcrumb">
  <a href="portail.php">Portail</a>
  <span class="breadcrumb-sep">›</span>
  <span>Établissements & Départements</span>
</div>

<!-- Hero -->
<div class="page-hero">
  <div>
    <h1>🏛️ Établissements & Départements</h1>
    <div class="subtitle">Université Joseph KI-ZERBO — Gestion du référentiel institutionnel</div>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <a href="portail.php" class="btn btn-gold">← Portail</a>
    <a href="admin_utilisateurs.php" class="btn" style="background:rgba(255,255,255,.15);color:#fff;border-color:rgba(255,255,255,.3)">👥 Utilisateurs</a>
  </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType==='error'?'danger':'success' ?>"><?= $e($msg) ?></div>
<?php endif; ?>

<!-- Onglets -->
<div style="display:flex;gap:0;border-bottom:2px solid var(--ujkz-vert);margin-bottom:1.5rem" id="tabs-wrap">
  <button class="btn btn-sm tab-btn active" data-tab="etabs"
          style="border-radius:6px 6px 0 0;border-bottom:2px solid var(--ujkz-vert);margin-bottom:-2px;background:var(--white);color:var(--ujkz-vert);font-weight:700">
    🏛️ Établissements
  </button>
  <button class="btn btn-sm tab-btn" data-tab="departements"
          style="border-radius:6px 6px 0 0;border-bottom:2px solid transparent;margin-bottom:-2px;color:var(--gray-600)">
    📂 Départements
  </button>
  <button class="btn btn-sm tab-btn" data-tab="arbre"
          style="border-radius:6px 6px 0 0;border-bottom:2px solid transparent;margin-bottom:-2px;color:var(--gray-600)">
    🌳 Vue d'ensemble
  </button>
</div>

<!-- ═══ ONGLET ÉTABLISSEMENTS ═══ -->
<div id="tab-etabs" class="tab-content">

  <!-- Formulaire création -->
  <div class="card">
    <div class="card-header">
      <div class="card-title">➕ Nouvel établissement</div>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
      <input type="hidden" name="action"     value="etab_create">
      <div class="grid2">
        <div><label>Sigle *</label><input type="text" name="sigle" placeholder="Ex : UFR/SVT" maxlength="30" required></div>
        <div><label>Ordre d'affichage</label><input type="number" name="ordre" value="0" min="0" max="999"></div>
      </div>
      <label>Nom complet *</label>
      <input type="text" name="nom" placeholder="Ex : UFR/SVT — Sciences de la Vie et de la Terre" maxlength="200" required>
      <div style="margin-top:1rem">
        <button type="submit" class="btn btn-primary">✚ Créer l'établissement</button>
      </div>
    </form>
  </div>

  <!-- Liste -->
  <div class="card">
    <div class="card-header"><div class="card-title">Liste des établissements</div></div>
    <table style="width:100%;border-collapse:collapse;font-size:13px">
      <thead>
        <tr style="background:var(--gray-50);border-bottom:2px solid var(--gray-200)">
          <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--gray-600)">Sigle</th>
          <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--gray-600)">Nom</th>
          <th style="padding:10px 12px;text-align:center;font-weight:600;color:var(--gray-600)">Dép.</th>
          <th style="padding:10px 12px;text-align:center;font-weight:600;color:var(--gray-600)">Statut</th>
          <th style="padding:10px 12px;text-align:right;font-weight:600;color:var(--gray-600)">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($etabs as $et): ?>
        <tr style="border-bottom:1px solid var(--gray-200)">
          <td style="padding:10px 12px;font-weight:700;color:var(--ujkz-vert-dk)"><?= $e($et['sigle']) ?></td>
          <td style="padding:10px 12px"><?= $e($et['nom']) ?></td>
          <td style="padding:10px 12px;text-align:center">
            <span class="badge badge-info"><?= (int)$et['nb_departements'] ?></span>
          </td>
          <td style="padding:10px 12px;text-align:center">
            <span class="badge <?= $et['actif']?'badge-green':'badge-gray' ?>">
              <?= $et['actif']?'Actif':'Inactif' ?>
            </span>
          </td>
          <td style="padding:10px 12px;text-align:right">
            <div class="btn-group" style="justify-content:flex-end">
              <button class="btn btn-sm btn-primary"
                      onclick="ouvrirEditEtab(<?= $et['id'] ?>,'<?= addslashes($e($et['sigle'])) ?>','<?= addslashes($e($et['nom'])) ?>',<?= (int)$et['ordre'] ?>,<?= $et['actif']?1:0 ?>)">
                ✏️
              </button>
              <button class="btn btn-sm" style="background:var(--info-lt);color:var(--info);border-color:#BBDEFB"
                      onclick="ouvrirLierUser(<?= $et['id'] ?>,'<?= addslashes($e($et['nom'])) ?>')">
                👤 Lier directeur
              </button>
              <button class="btn btn-sm btn-danger" <?= $et['nb_departements']>0?'disabled title="Départements liés"':'' ?>
                      onclick="supprimerEtab(<?= $et['id'] ?>,'<?= addslashes($e($et['nom'])) ?>')">🗑️</button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ═══ ONGLET DÉPARTEMENTS ═══ -->
<div id="tab-departements" class="tab-content" style="display:none">

  <!-- Formulaire création -->
  <div class="card">
    <div class="card-header"><div class="card-title">➕ Nouveau département</div></div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
      <input type="hidden" name="action"     value="dept_create">
      <div class="grid2">
        <div>
          <label>Établissement *</label>
          <select name="etablissement_id" required>
            <option value="">— Choisir —</option>
            <?php foreach ($etabs as $et): if (!$et['actif']) continue; ?>
            <option value="<?= $et['id'] ?>"><?= $e($et['sigle']) ?> — <?= $e($et['nom']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div><label>Sigle</label><input type="text" name="sigle" placeholder="Ex : BPV" maxlength="30"></div>
      </div>
      <label>Nom du département *</label>
      <input type="text" name="nom" placeholder="Ex : Biologie et Physiologie Végétales" maxlength="150" required>
      <div style="margin-top:1rem">
        <button type="submit" class="btn btn-primary">✚ Créer le département</button>
      </div>
    </form>
  </div>

  <!-- Liste groupée par étab -->
  <?php
  $deptsByEtab = [];
  foreach ($depts as $d) $deptsByEtab[$d['etab_nom']][] = $d;
  ?>
  <?php foreach ($deptsByEtab as $etabNom => $depsEt): ?>
  <div class="card" style="border-left:4px solid var(--ujkz-vert)">
    <div class="card-header">
      <div class="card-title"><?= $e($etabNom) ?></div>
      <span class="badge badge-info"><?= count($depsEt) ?> département(s)</span>
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:13px">
      <thead>
        <tr style="background:var(--gray-50);border-bottom:2px solid var(--gray-200)">
          <th style="padding:8px 12px;text-align:left;font-weight:600;color:var(--gray-600)">Sigle</th>
          <th style="padding:8px 12px;text-align:left;font-weight:600;color:var(--gray-600)">Nom</th>
          <th style="padding:8px 12px;text-align:left;font-weight:600;color:var(--gray-600)">Chef de département</th>
          <th style="padding:8px 12px;text-align:center;font-weight:600;color:var(--gray-600)">Statut</th>
          <th style="padding:8px 12px;text-align:right;font-weight:600;color:var(--gray-600)">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($depsEt as $d): $chef = $repo->getChefDept((int)$d['id']); ?>
        <tr style="border-bottom:1px solid var(--gray-200)">
          <td style="padding:8px 12px;font-weight:700;color:var(--ujkz-vert-dk)"><?= $e($d['sigle']) ?></td>
          <td style="padding:8px 12px"><?= $e($d['nom']) ?></td>
          <td style="padding:8px 12px">
            <?php if ($chef): ?>
              <span class="badge badge-green">👤 <?= $e($chef['nom']) ?></span>
              <button class="btn btn-xs btn-outline-green" style="margin-left:6px"
                      onclick="ouvrirLierChef(<?= $d['id'] ?>,'<?= addslashes($e($d['nom'])) ?>')">Changer</button>
            <?php else: ?>
              <span style="font-size:12px;color:var(--gray-400);font-style:italic">Non assigné</span>
              <button class="btn btn-xs btn-gold" style="margin-left:6px"
                      onclick="ouvrirLierChef(<?= $d['id'] ?>,'<?= addslashes($e($d['nom'])) ?>')">👤 Assigner</button>
            <?php endif; ?>
          </td>
          <td style="padding:8px 12px;text-align:center">
            <span class="badge <?= $d['actif']?'badge-green':'badge-gray' ?>"><?= $d['actif']?'Actif':'Inactif' ?></span>
          </td>
          <td style="padding:8px 12px;text-align:right">
            <div class="btn-group" style="justify-content:flex-end">
              <button class="btn btn-sm btn-primary"
                      onclick="ouvrirEditDept(<?= $d['id'] ?>,<?= $d['etablissement_id'] ?>,'<?= addslashes($e($d['nom'])) ?>','<?= addslashes($e($d['sigle'])) ?>',<?= (int)$d['ordre'] ?>,<?= $d['actif']?1:0 ?>)">✏️</button>
              <button class="btn btn-sm btn-danger" <?= $d['nb_utilisateurs']>0?'disabled title="Chef lié"':'' ?>
                      onclick="supprimerDept(<?= $d['id'] ?>,'<?= addslashes($e($d['nom'])) ?>')">🗑️</button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endforeach; ?>
</div>

<!-- ═══ ONGLET VUE D'ENSEMBLE ═══ -->
<div id="tab-arbre" class="tab-content" style="display:none">
  <?php foreach ($arbre as $et): ?>
  <div class="card" style="border-left:4px solid var(--ujkz-or)">
    <div class="card-header">
      <div>
        <div class="card-title">
          <?= $e($et['sigle']) ?> — <?= $e($et['nom']) ?>
          <span class="badge <?= $et['actif']?'badge-green':'badge-gray' ?>" style="margin-left:6px"><?= $et['actif']?'Actif':'Inactif' ?></span>
        </div>
      </div>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <?php foreach ($et['directeurs'] as $dir): ?>
        <span class="badge <?= $dir['role']==='directeur'?'badge-or':'badge-info' ?>">
          <?= $dir['role']==='directeur'?'🏛️':'🔹' ?> <?= $e($dir['nom']) ?>
        </span>
        <?php endforeach; ?>
        <?php if (empty($et['directeurs'])): ?>
        <span style="font-size:11px;color:var(--gray-400);font-style:italic">Aucun directeur assigné</span>
        <?php endif; ?>
      </div>
    </div>
    <?php if (empty($et['departements'])): ?>
      <p style="color:var(--gray-400);font-style:italic;font-size:12px">Aucun département</p>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:8px">
      <?php foreach ($et['departements'] as $dept): ?>
      <div style="background:var(--gray-50);border:1px solid var(--gray-200);border-radius:var(--radius-sm);
                  padding:10px 14px;<?= !$dept['actif']?'opacity:.55':'' ?>">
        <div style="font-weight:600;font-size:12px;color:var(--gray-800)">
          <?= $e($dept['nom']) ?>
          <?php if ($dept['sigle']): ?>
          <span style="font-weight:400;color:var(--gray-400)">(<?= $e($dept['sigle']) ?>)</span>
          <?php endif; ?>
        </div>
        <?php if ($dept['chef']): ?>
        <div style="margin-top:4px"><span class="badge badge-green" style="font-size:10px">👤 <?= $e($dept['chef']['nom']) ?></span></div>
        <?php else: ?>
        <div style="margin-top:4px;font-size:11px;color:var(--gray-400);font-style:italic">Pas de chef assigné</div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<!-- ═══ MODALS ═══ -->
<!-- Modal : modifier établissement -->
<div id="modal-edit-etab" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center">
  <div style="background:var(--white);border-radius:var(--radius);padding:24px;max-width:500px;width:96%;box-shadow:var(--shadow)">
    <div style="font-size:15px;font-weight:700;color:var(--ujkz-vert-dk);margin-bottom:16px">✏️ Modifier l'établissement</div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
      <input type="hidden" name="action" value="etab_update">
      <input type="hidden" name="id" id="edit-etab-id">
      <div class="grid2">
        <div><label>Sigle</label><input type="text" name="sigle" id="edit-etab-sigle" maxlength="30"></div>
        <div><label>Ordre</label><input type="number" name="ordre" id="edit-etab-ordre" min="0"></div>
      </div>
      <label>Nom complet *</label>
      <input type="text" name="nom" id="edit-etab-nom" maxlength="200" required>
      <div style="display:flex;align-items:center;gap:8px;margin-top:14px">
        <input type="checkbox" name="actif" id="edit-etab-actif" value="1" style="width:auto;accent-color:var(--ujkz-vert)">
        <label for="edit-etab-actif" style="margin:0;font-weight:400">Actif</label>
      </div>
      <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:20px">
        <button type="button" class="btn" onclick="fermerModal('modal-edit-etab')">Annuler</button>
        <button type="submit" class="btn btn-primary">Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal : modifier département -->
<div id="modal-edit-dept" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center">
  <div style="background:var(--white);border-radius:var(--radius);padding:24px;max-width:500px;width:96%;box-shadow:var(--shadow)">
    <div style="font-size:15px;font-weight:700;color:var(--ujkz-vert-dk);margin-bottom:16px">✏️ Modifier le département</div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
      <input type="hidden" name="action" value="dept_update">
      <input type="hidden" name="id" id="edit-dept-id">
      <label>Établissement *</label>
      <select name="etablissement_id" id="edit-dept-etab" required>
        <?php foreach ($etabs as $et): ?>
        <option value="<?= $et['id'] ?>"><?= $e($et['sigle']) ?> — <?= $e($et['nom']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="grid2">
        <div><label>Nom *</label><input type="text" name="nom" id="edit-dept-nom" maxlength="150" required></div>
        <div><label>Sigle</label><input type="text" name="sigle" id="edit-dept-sigle" maxlength="30"></div>
      </div>
      <label>Ordre</label><input type="number" name="ordre" id="edit-dept-ordre" min="0">
      <div style="display:flex;align-items:center;gap:8px;margin-top:14px">
        <input type="checkbox" name="actif" id="edit-dept-actif" value="1" style="width:auto;accent-color:var(--ujkz-vert)">
        <label for="edit-dept-actif" style="margin:0;font-weight:400">Actif</label>
      </div>
      <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:20px">
        <button type="button" class="btn" onclick="fermerModal('modal-edit-dept')">Annuler</button>
        <button type="submit" class="btn btn-primary">Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal : lier directeur -->
<div id="modal-lier-user" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center">
  <div style="background:var(--white);border-radius:var(--radius);padding:24px;max-width:440px;width:96%;box-shadow:var(--shadow)">
    <div style="font-size:15px;font-weight:700;color:var(--ujkz-vert-dk);margin-bottom:4px">👤 Lier un directeur</div>
    <p id="lier-etab-nom" style="color:var(--gray-600);font-size:12px;margin-bottom:16px"></p>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
      <input type="hidden" name="action" value="etab_lier_user">
      <input type="hidden" name="etab_id" id="lier-etab-id">
      <label>Directeur / Directeur adjoint</label>
      <select name="user_id" required>
        <option value="">— Choisir —</option>
        <?php foreach ($tousDirecteurs as $u): ?>
        <option value="<?= $u['id'] ?>">[<?= $e($roleLabels[$u['role']]??$u['role']) ?>] <?= $e($u['nom']) ?></option>
        <?php endforeach; ?>
      </select>
      <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:20px">
        <button type="button" class="btn" onclick="fermerModal('modal-lier-user')">Annuler</button>
        <button type="submit" class="btn btn-primary">Lier</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal : lier chef -->
<div id="modal-lier-chef" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center">
  <div style="background:var(--white);border-radius:var(--radius);padding:24px;max-width:440px;width:96%;box-shadow:var(--shadow)">
    <div style="font-size:15px;font-weight:700;color:var(--ujkz-vert-dk);margin-bottom:4px">👤 Assigner un chef</div>
    <p id="lier-dept-nom" style="color:var(--gray-600);font-size:12px;margin-bottom:16px"></p>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
      <input type="hidden" name="action" value="dept_lier_chef">
      <input type="hidden" name="dept_id" id="lier-dept-id">
      <label>Chef de département</label>
      <select name="user_id" required>
        <option value="">— Choisir —</option>
        <?php foreach ($tousChefs as $c): ?>
        <option value="<?= $c['id'] ?>"><?= $e($c['nom']) ?></option>
        <?php endforeach; ?>
      </select>
      <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:20px">
        <button type="button" class="btn" onclick="fermerModal('modal-lier-chef')">Annuler</button>
        <button type="submit" class="btn btn-primary">Assigner</button>
      </div>
    </form>
  </div>
</div>

<!-- Forms suppression -->
<form id="form-del-etab" method="POST" style="display:none"><input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="action" value="etab_delete"><input type="hidden" name="id" id="del-etab-id"></form>
<form id="form-del-dept" method="POST" style="display:none"><input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="action" value="dept_delete"><input type="hidden" name="id" id="del-dept-id"></form>

<script>
// ── Onglets
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.tab-btn').forEach(b => {
            b.style.borderBottom='2px solid transparent';
            b.style.background='transparent';
            b.style.color='var(--gray-600)';
        });
        document.querySelectorAll('.tab-content').forEach(t => t.style.display='none');
        this.style.borderBottom='2px solid var(--ujkz-vert)';
        this.style.background='var(--white)';
        this.style.color='var(--ujkz-vert)';
        document.getElementById('tab-'+this.dataset.tab).style.display='block';
    });
});
// Activer onglet selon ancre
(function(){
    var h = window.location.hash;
    if (h==='#departements') document.querySelectorAll('.tab-btn')[1].click();
    else if (h==='#arbre')   document.querySelectorAll('.tab-btn')[2].click();
})();

// ── Modals
function ouvrirModal(id) { var m=document.getElementById(id); m.style.display='flex'; }
function fermerModal(id) { document.getElementById(id).style.display='none'; }
document.querySelectorAll('[id^="modal-"]').forEach(m => {
    m.addEventListener('click', function(e){ if(e.target===this) fermerModal(this.id); });
});

function ouvrirEditEtab(id,sigle,nom,ordre,actif){
    document.getElementById('edit-etab-id').value=id;
    document.getElementById('edit-etab-sigle').value=sigle;
    document.getElementById('edit-etab-nom').value=nom;
    document.getElementById('edit-etab-ordre').value=ordre;
    document.getElementById('edit-etab-actif').checked=!!actif;
    ouvrirModal('modal-edit-etab');
}
function ouvrirEditDept(id,etabId,nom,sigle,ordre,actif){
    document.getElementById('edit-dept-id').value=id;
    document.getElementById('edit-dept-etab').value=etabId;
    document.getElementById('edit-dept-nom').value=nom;
    document.getElementById('edit-dept-sigle').value=sigle;
    document.getElementById('edit-dept-ordre').value=ordre;
    document.getElementById('edit-dept-actif').checked=!!actif;
    ouvrirModal('modal-edit-dept');
}
function ouvrirLierUser(etabId,etabNom){
    document.getElementById('lier-etab-id').value=etabId;
    document.getElementById('lier-etab-nom').textContent='Établissement : '+etabNom;
    ouvrirModal('modal-lier-user');
}
function ouvrirLierChef(deptId,deptNom){
    document.getElementById('lier-dept-id').value=deptId;
    document.getElementById('lier-dept-nom').textContent='Département : '+deptNom;
    ouvrirModal('modal-lier-chef');
}
function supprimerEtab(id,nom){
    if(!confirm("Supprimer l'établissement « "+nom+" » ?")) return;
    document.getElementById('del-etab-id').value=id;
    document.getElementById('form-del-etab').submit();
}
function supprimerDept(id,nom){
    if(!confirm("Supprimer le département « "+nom+" » ?")) return;
    document.getElementById('del-dept-id').value=id;
    document.getElementById('form-del-dept').submit();
}
</script>

<?php
$bodyContent = ob_get_clean();

// Injecter dans le layout UJKZ
$title = 'Établissements & Départements';
ob_start();
require __DIR__ . '/templates/layout.php';
echo ob_get_clean();
