<?php
// ============================================================
// admin_utilisateurs.php — Gestion des utilisateurs multi-rôles
// Accessible DEI uniquement
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/EtabRepository.php';

$config   = require __DIR__ . '/config/security.php';
$etabRepo  = new EtabRepository();
$listeEtabs = $etabRepo->getListeEtabs();
$listeDepts = $etabRepo->getListeDepts();
$security = new Security($config);
$security->sendSecurityHeaders();
$security->startSecureSession();
$csrfToken = $security->generateCsrfToken();

// Auth DEI uniquement
if (!Auth::check() && empty($_SESSION['admin_authenticated'])) {
    header("Location: login.php"); exit;
}
if (!Auth::check()) {
    $_SESSION['user_role'] = 'dei';
    $_SESSION['user_since']= time();
    $_SESSION['user_id']   = 0;
    $_SESSION['user_dept'] = null;
    $_SESSION['user_etab'] = null;
    $_SESSION['user_nom']  = $_SESSION['admin_user'] ?? 'Admin';
}
if (Auth::userRole() !== 'dei') { http_response_code(403); die('Accès DEI uniquement.'); }

$message = '';
$error   = '';

// ── Actions POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$security->verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403); die('Token CSRF invalide.');
    }

    $action = $_POST['action'] ?? '';
    $pdo    = Database::getInstance();

    // Créer / Modifier
    if ($action === 'creer' || $action === 'modifier') {
        $nom   = Security::sanitizeText($_POST['nom']           ?? '', 100);
        $login = Security::sanitizeText($_POST['login']         ?? '', 60);
        $role  = Security::sanitizeText($_POST['role']          ?? '', 30);
        $dept      = Security::sanitizeText($_POST['departement'] ?? '', 100) ?: null;
        // Multi-établissements : tableau de checkboxes → JSON
        $etabsRaw  = $_POST['etablissements'] ?? [];
        if (!is_array($etabsRaw)) $etabsRaw = [];
        $etabsClean = [];
        foreach ($etabsRaw as $ev) {
            $ev = Security::sanitizeText($ev, 150);
            if ($ev !== '') $etabsClean[] = $ev;
        }
        $etab = !empty($etabsClean) ? json_encode($etabsClean, JSON_UNESCAPED_UNICODE) : null;
        $pwd   = $_POST['password'] ?? '';
        $actif = isset($_POST['actif']) ? 1 : 0;

        if (!in_array($role, ['dei','directeur','directeur_adjoint','chef_dept','vp_eip'], true)) {
            $error = 'Rôle invalide.';
        } elseif (empty($nom) || empty($login)) {
            $error = 'Nom et identifiant obligatoires.';
        } else {
            if ($action === 'creer') {
                if (strlen($pwd) < 6) {
                    $error = 'Mot de passe : 6 caractères minimum.';
                } else {
                    try {
                        Auth::createUser([
                            'nom' => $nom, 'login' => $login, 'password' => $pwd,
                            'role' => $role, 'departement' => $dept, 'etablissement' => $etab,
                            'departement_id' => (int)($_POST['departement_id'] ?? 0) ?: null,
                            'etablissement_id' => (int)($_POST['etablissement_id'] ?? 0) ?: null,
                        ]);
                        $message = "Utilisateur « $login » créé avec succès.";
                        $security->audit('user_created', null, "login=$login role=$role");
                    } catch (PDOException $ex) {
                        $error = (strpos($ex->getMessage(),'Duplicate') !== false) ? "L'identifiant « $login » est déjà utilisé." : 'Erreur DB: ' . $ex->getMessage();
                    }
                }
            } else {
                // Modifier
                $uid = (int)($_POST['user_id'] ?? 0);
                $sets = "nom=?, login=?, role=?, departement=?, departement_id=?, etablissement=?, etablissement_id=?, actif=?";
                $deptIdUpd = (int)($_POST['departement_id'] ?? 0) ?: null;
                $etabIdUpd = (int)($_POST['etablissement_id'] ?? 0) ?: null;
                $params = [$nom, $login, $role, $dept, $deptIdUpd, $etab, $etabIdUpd, $actif];
                if (!empty($pwd)) {
                    if (strlen($pwd) < 6) { $error = 'Mot de passe : 6 caractères minimum.'; goto render; }
                    $hash = password_hash($pwd, PASSWORD_ARGON2ID, ['memory_cost'=>65536,'time_cost'=>4,'threads'=>1]);
                    $sets .= ", password=?";
                    $params[] = $hash;
                }
                $params[] = $uid;
                $pdo->prepare("UPDATE utilisateurs SET $sets WHERE id=?")->execute($params);
                $message = "Utilisateur modifié.";
                $security->audit('user_updated', null, "id=$uid login=$login");
            }
        }
    }

    // Supprimer
    if ($action === 'supprimer') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $pdo->prepare("DELETE FROM utilisateurs WHERE id=?")->execute([$uid]);
        $message = "Utilisateur supprimé.";
        $security->audit('user_deleted', null, "id=$uid");
    }

    // Toggle actif
    if ($action === 'toggle') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $pdo->prepare("UPDATE utilisateurs SET actif = 1 - actif WHERE id=?")->execute([$uid]);
        $message = "Statut mis à jour.";
    }
}

render:
$utilisateurs = Auth::listUsers();
$editUser     = null;
if (isset($_GET['edit'])) {
    $pdo      = Database::getInstance();
    $stmt     = $pdo->prepare("SELECT * FROM utilisateurs WHERE id=? LIMIT 1");
    $stmt->execute([(int)$_GET['edit']]);
    $editUser = $stmt->fetch() ?: null;
}

$rolesDisp = [
    'chef_dept'         => ['label'=>'Chef de département', 'color'=>'#0C447C', 'hint'=>'Lié à un département'],
    'directeur_adjoint' => ['label'=>'Directeur adjoint',   'color'=>'#633806', 'hint'=>'Lié à un ou plusieurs établissements'],
    'directeur'         => ['label'=>'Directeur',           'color'=>'#27500A', 'hint'=>'Lié à un ou plusieurs établissements'],
    'dei'               => ['label'=>'DEI (Admin général)', 'color'=>'#791F1F', 'hint'=>'Accès à toutes les fiches'],
    'vp_eip'            => ['label'=>'VP EIP',              'color'=>'#6A1B9A', 'hint'=>'Validation finale vacataires — accès global'],
];

ob_start();
?>
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:10px">
  <div>
    <h1>Gestion des utilisateurs</h1>
    <div style="font-size:13px;color:var(--muted)">Chef de département · Directeur adjoint · Directeur · DEI</div>
  </div>
  <div style="display:flex;gap:8px">
    <a href="portail.php" class="btn btn-sm">← Portail</a>
    <a href="admin_utilisateurs.php?new=1" class="btn btn-sm btn-primary">+ Nouvel utilisateur</a>
  </div>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= Security::e($message) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger"><?= Security::e($error) ?></div><?php endif; ?>

<!-- Formulaire création / modification -->
<?php if (isset($_GET['new']) || $editUser): ?>
<div class="card" style="margin-bottom:1.5rem">
  <div class="card-title"><?= $editUser ? 'Modifier un utilisateur' : 'Créer un utilisateur' ?></div>
  <form method="POST" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
    <input type="hidden" name="action" value="<?= $editUser ? 'modifier' : 'creer' ?>">
    <?php if ($editUser): ?>
    <input type="hidden" name="user_id" value="<?= (int)$editUser['id'] ?>">
    <?php endif; ?>

    <div class="grid2">
      <div>
        <label>Nom complet <span style="color:var(--danger)">*</span></label>
        <input type="text" name="nom" required maxlength="100"
               value="<?= Security::e($editUser['nom'] ?? '') ?>" placeholder="OUEDRAOGO Jean">
      </div>
      <div>
        <label>Identifiant de connexion <span style="color:var(--danger)">*</span></label>
        <input type="text" name="login" required maxlength="60" autocomplete="off"
               value="<?= Security::e($editUser['login'] ?? '') ?>" placeholder="jean.ouedraogo">
      </div>
    </div>

    <div class="grid2">
      <div>
        <label>Rôle <span style="color:var(--danger)">*</span></label>
        <select name="role" required onchange="updateScopeFields(this.value)">
          <?php foreach ($rolesDisp as $rv => $rd): ?>
          <option value="<?= $rv ?>" <?= ($editUser['role']??'')===$rv?'selected':'' ?>>
            <?= $rd['label'] ?> — <?= $rd['hint'] ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Mot de passe <?= $editUser ? '(laisser vide = inchangé)' : '<span style="color:var(--danger)">*</span>' ?></label>
        <input type="password" name="password" autocomplete="new-password" maxlength="100"
               <?= $editUser ? '' : 'required' ?> placeholder="6 caractères minimum">
      </div>
    </div>

    <!-- Scope -->
    <div id="scope-dept" style="<?= !$editUser || ($editUser['role']??'')==='chef_dept'?'':'display:none' ?>">
      <label>Département <small style="color:var(--muted)">(requis pour Chef de département)</small></label>
      <?php
      // Charger les départements depuis la BD pour correspondre exactement
      // aux valeurs saisies dans les fiches (même format nom + sigle)
      $listeDeptAdmin = $etabRepo->getListeDepts(true);
      $deptActuel = $editUser['departement'] ?? '';
      ?>
      <select name="departement" style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:5px;font-size:12px;font-family:var(--font)">
        <option value="">— Sélectionner le département —</option>
        <?php foreach ($listeDeptAdmin as $dpt):
            $dVal = $dpt['nom'] . (!empty($dpt['sigle']) ? ' (' . $dpt['sigle'] . ')' : '');
        ?>
        <option value="<?= Security::e($dVal) ?>"
                <?= $deptActuel === $dVal ? 'selected' : '' ?>>
          <?= Security::e($dpt['etab_sigle'] ?? '') ?> — <?= Security::e($dVal) ?>
        </option>
        <?php endforeach; ?>
      </select>
      <input type="hidden" name="departement_id" value="<?= (int)($editUser['departement_id'] ?? 0) ?>">
      <div style="font-size:11px;color:var(--muted);margin-top:3px">
        Le département doit correspondre exactement à celui sélectionné dans les fiches.
      </div>
    </div>

    <div id="scope-etab" style="<?= $editUser && in_array($editUser['role']??'',['directeur','directeur_adjoint'],true)?'':'display:none' ?>">
      <label>Établissements rattachés <small style="color:var(--muted)">(bénéficiaires et/ou de rattachement — requis pour Directeur / Directeur adjoint)</small></label>
      <?php
      // Décoder les établissements actuels (JSON array ou ancienne valeur string)
      $etabsActuels = [];
      if (!empty($editUser['etablissement'])) {
          $decoded = json_decode($editUser['etablissement'], true);
          if (is_array($decoded)) {
              $etabsActuels = $decoded;
          } else {
              $etabsActuels = [$editUser['etablissement']]; // rétrocompat ancienne valeur
          }
      }
      ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:6px;margin-top:6px;padding:10px;background:var(--bg);border:1px solid var(--border);border-radius:6px;max-height:220px;overflow-y:auto">
        <?php
        // Utiliser les données BD si disponibles, sinon fallback config
        $etabsAdminListe = !empty($listeEtabs) ? array_column($listeEtabs, 'nom') : ($config['etablissements'] ?? []);
        foreach ($etabsAdminListe as $et):
        ?>
        <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-size:12.5px;font-weight:400;padding:3px 0">
          <input type="checkbox" name="etablissements[]"
                 value="<?= Security::e($et) ?>"
                 <?= in_array($et, $etabsActuels, true) ? 'checked' : '' ?>
                 style="width:auto;margin-top:2px;accent-color:var(--ujkz-bleu)">
          <span><?= Security::e($et) ?></span>
        </label>
        <?php endforeach; ?>
      </div>
      <div style="font-size:11px;color:var(--muted);margin-top:4px">
        Cocher tous les établissements dont ce directeur est responsable (rattachement administratif et/ou bénéficiaires d'enseignements).
      </div>
    </div>

    <?php if ($editUser): ?>
    <div style="margin-top:.75rem">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
        <input type="checkbox" name="actif" value="1" <?= $editUser['actif']?'checked':'' ?> style="width:auto">
        Compte actif
      </label>
    </div>
    <?php endif; ?>

    <div style="display:flex;gap:10px;margin-top:1.25rem">
      <button type="submit" class="btn btn-primary"><?= $editUser ? 'Enregistrer' : 'Créer' ?></button>
      <a href="admin_utilisateurs.php" class="btn">Annuler</a>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- Liste des utilisateurs -->
<div class="card" style="padding:0;overflow-x:auto">
<table style="width:100%;border-collapse:collapse;font-size:13px">
  <thead>
    <tr style="background:var(--bg);border-bottom:1px solid var(--border)">
      <th style="padding:9px 12px;text-align:left;font-weight:600">Nom / Login</th>
      <th style="padding:9px 12px;text-align:left;font-weight:600">Rôle</th>
      <th style="padding:9px 12px;text-align:left;font-weight:600">Périmètre</th>
      <th style="padding:9px 12px;text-align:center;font-weight:600">Statut</th>
      <th style="padding:9px 12px;text-align:left;font-weight:600">Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($utilisateurs)): ?>
  <tr><td colspan="5" style="padding:2rem;text-align:center;color:var(--muted)">Aucun utilisateur créé.</td></tr>
  <?php endif; ?>
  <?php foreach ($utilisateurs as $u): ?>
  <?php $rd = $rolesDisp[$u['role']] ?? ['label'=>$u['role'],'color'=>'#333']; ?>
  <tr style="border-bottom:1px solid var(--border)">
    <td style="padding:9px 12px">
      <div style="font-weight:500"><?= Security::e($u['nom']) ?></div>
      <div style="font-size:11px;color:var(--muted)"><?= Security::e($u['login']) ?></div>
    </td>
    <td style="padding:9px 12px">
      <span style="color:<?= $rd['color'] ?>;font-weight:500"><?= $rd['label'] ?></span>
    </td>
    <td style="padding:9px 12px;font-size:12px;color:var(--muted)">
      <?php if ($u['departement']): ?>
        Dép. : <?= Security::e($u['departement']) ?>
      <?php elseif (!empty($u['etablissement'])): ?>
        <?php
        $etabDec = json_decode($u['etablissement'], true);
        if (is_array($etabDec)) {
            // Afficher les noms courts (avant le premier " — ")
            $courts = array_map(function($e){ $p = strpos($e,' — '); return $p!==false?substr($e,0,$p):$e; }, $etabDec);
            echo 'Étab. : ' . Security::e(implode(', ', $courts));
        } else {
            echo 'Étab. : ' . Security::e($u['etablissement']);
        }
        ?>
      <?php else: ?>
        <em>Global</em>
      <?php endif; ?>
    </td>
    <td style="padding:9px 12px;text-align:center">
      <form method="POST" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
        <input type="hidden" name="action"  value="toggle">
        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0"
                title="Cliquer pour basculer">
          <?php if ($u['actif']): ?>
          <span class="badge badge-success">Actif</span>
          <?php else: ?>
          <span class="badge badge-danger">Inactif</span>
          <?php endif; ?>
        </button>
      </form>
    </td>
    <td style="padding:9px 12px">
      <div style="display:flex;gap:5px">
        <a href="admin_utilisateurs.php?edit=<?= (int)$u['id'] ?>" class="btn btn-sm">Modifier</a>
        <form method="POST" style="display:inline"
              onsubmit="return confirm('Supprimer « <?= Security::e($u['login']) ?> » ?')">
          <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
          <input type="hidden" name="action"  value="supprimer">
          <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
          <button type="submit" class="btn btn-sm" style="color:var(--danger)">Supprimer</button>
        </form>
      </div>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<script>
function updateScopeFields(role) {
    document.getElementById('scope-dept').style.display = role === 'chef_dept' ? '' : 'none';
    document.getElementById('scope-etab').style.display = ['directeur','directeur_adjoint'].includes(role) ? '' : 'none';
    // VP EIP et DEI : pas de scope géographique
}
// Init
updateScopeFields(document.querySelector('[name="role"]')?.value || 'chef_dept');
</script>
<?php
$bodyContent = ob_get_clean();
$title = 'Gestion des utilisateurs';
ob_start(); require __DIR__ . '/templates/layout.php'; echo ob_get_clean();
