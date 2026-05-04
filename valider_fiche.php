<?php
// ============================================================
// valider_fiche.php — Formulaire de validation / rejet
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';
if (!class_exists('App')) require_once __DIR__ . '/src/App.php';
App::configureErrorDisplay();
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/ValidationRepository.php';
require_once __DIR__ . '/src/Mailer.php';

$config   = require __DIR__ . '/config/security.php';
$security = new Security($config);
$security->sendSecurityHeaders();
$security->startSecureSession();
$csrfToken = $security->generateCsrfToken();

if (!Auth::check() && empty($_SESSION['admin_authenticated'])) {
    header('Location: login.php'); exit;
}
if (!Auth::check()) {
    $_SESSION['user_role'] = 'dei'; $_SESSION['user_since'] = time();
    $_SESSION['user_id'] = 0; $_SESSION['user_dept'] = null; $_SESSION['user_etab'] = null;
}

$repo    = new ValidationRepository();
$role    = Auth::userRole();
$ficheId = (int)($_GET['id'] ?? $_POST['fiche_id'] ?? 0);
if ($ficheId <= 0) { http_response_code(400); die('ID manquant.'); }

$fiche = $repo->getFicheComplete($ficheId);
if (!$fiche) { http_response_code(404); die('Fiche introuvable.'); }

$peutValider = Auth::peutValider($role, $fiche);
if (!$peutValider) {
    header('Location: voir_fiche.php?id=' . $ficheId . '&msg=non_autorise');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['decision'])) {
    if (!$security->verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403); die('Token CSRF invalide.');
    }

    $decision = $_POST['decision'] ?? '';
    $motif    = Security::sanitizeText($_POST['motif'] ?? '', 500);

    if (!in_array($decision, ['valide','rejete'], true)) {
        $error = 'Décision invalide.';
    } elseif ($decision === 'rejete' && empty($motif)) {
        $error = 'Le motif de rejet est obligatoire.';
    } else {
        $userId = Auth::userId();
        // Si userId=0 (admin config), créer une entrée fictive ou utiliser ID 0 via DEI
        if ($userId === 0) {
            // Trouver ou créer l'utilisateur DEI de config
            $pdo  = Database::getInstance();
            $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE role='dei' LIMIT 1");
            $stmt->execute();
            $deiUser = $stmt->fetch();
            $userId  = $deiUser ? (int)$deiUser['id'] : 1;
        }

        $repo->enregistrerDecision($ficheId, $userId, $role, $decision, $motif);
        $security->audit('fiche_' . $decision, $fiche['matricule'], "Fiche $ficheId par " . Auth::userNom());
        unset($_SESSION['csrf_token']);

        // Notifier l'enseignant par mail
        $ensStmt = Database::getInstance()->prepare(
            "SELECT email, token FROM enseignants WHERE id = ? LIMIT 1"
        );
        $ensStmt->execute([$fiche['enseignant_id']]);
        $ensRow = $ensStmt->fetch();
        if (!empty($ensRow['email'])) {
            require_once __DIR__ . '/src/App.php';
            $accessLink = App::url('dashboard.php?token=' . urlencode($ensRow['token'] ?? ''));
            (new Mailer())->sendNotificationDecision(
                $ensRow['email'], $fiche['ens_nom'], $fiche['cours'],
                $decision, $motif, $accessLink
            );
        }

        $fromEns = (int)($_GET['from_ens'] ?? $_POST['from_ens'] ?? 0);
        // Toujours revenir à la fiche de l'enseignant dans le portail (actualisée)
        if ($fromEns > 0) {
            header('Location: portail.php?ens=' . $fromEns . '&success=1#fiche-' . $ficheId); exit;
        }
        // Fallback : récupérer l'enseignant_id depuis la fiche
        $ensIdRedirect = (int)($fiche['enseignant_id'] ?? 0);
        if ($ensIdRedirect > 0) {
            header('Location: portail.php?ens=' . $ensIdRedirect . '&success=1'); exit;
        }
        header('Location: portail.php?success=1'); exit;
    }
}

$roleLabel = Auth::roleLabel($role);
$roleColors = [
    'dei'=>['bg'=>'#FEECEC','txt'=>'#C62828'],
    'directeur'=>['bg'=>'#E8F5E9','txt'=>'#2E7D32'],
    'directeur_adjoint'=>['bg'=>'#E3F2FD','txt'=>'#0277BD'],
    'chef_dept'=>['bg'=>'#FFF8E1','txt'=>'#E65100'],
];
$rc = $roleColors[$role] ?? ['bg'=>'#f0f0f0','txt'=>'#333'];
ob_start();
?>
<div class="breadcrumb">
  <a href="portail.php">Portail</a>
  <span class="breadcrumb-sep">›</span>
  <a href="voir_fiche.php?id=<?= $ficheId ?>">Fiche #<?= $ficheId ?></a>
  <span class="breadcrumb-sep">›</span>
  <span>Décision</span>
</div>

<div class="page-hero" style="margin-bottom:1.25rem">
  <div>
    <h1>⚖ Donner ma décision</h1>
    <div class="subtitle">
      <?= Security::e($fiche['cours']) ?> · <?= Security::e($fiche['ens_nom']) ?>
    </div>
  </div>
  <span class="badge" style="background:<?= $rc['bg'] ?>;color:<?= $rc['txt'] ?>;font-size:13px;padding:8px 16px">
    <?= Security::e($roleLabel) ?>
  </span>
</div>

<?php if ($error): ?>
<div class="alert alert-danger"><?= Security::e($error) ?></div>
<?php endif; ?>

<div class="card" style="max-width:580px;margin:0 auto">
  <div class="card-header">
    <div class="card-title">Récapitulatif de la fiche</div>
  </div>
  <table class="recap" style="margin-bottom:1.25rem">
    <tr><td>Enseignant</td><td><strong><?= Security::e($fiche['ens_nom']) ?></strong> — <?= Security::e($fiche['matricule']) ?></td></tr>
    <tr><td>UE / ECUE</td><td><?= Security::e($fiche['cours']) ?></td></tr>
    <tr><td>Niveau / Semestre</td><td><?= Security::e($fiche['niveau']) ?> · <?= Security::e($fiche['semestre']) ?></td></tr>
    <tr><td>Volume horaire</td><td>CM <?= (int)$fiche['volume_cm'] ?>h / TD <?= (int)$fiche['volume_td'] ?>h</td></tr>
  </table>

  <div class="card-header">
    <div class="card-title">Ma décision</div>
  </div>

  <form method="POST" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
    <input type="hidden" name="fiche_id"   value="<?= $ficheId ?>">
    <input type="hidden" name="from_ens"   value="<?= (int)($_GET['from_ens'] ?? $_GET['ens'] ?? 0) ?>">

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:1rem 0 1.25rem">
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:16px;border:2px solid var(--gray-200);border-radius:10px;background:var(--white);transition:all .15s"
             id="lbl-valide" onclick="selectDecision('valide')">
        <input type="radio" name="decision" value="valide" required onchange="toggleMotif(this.value)" style="width:auto;accent-color:var(--ujkz-vert)">
        <div>
          <div style="font-size:16px;margin-bottom:2px">✅</div>
          <div style="font-weight:700;color:var(--ujkz-vert)">Valider</div>
          <div style="font-size:11px;color:var(--gray-600)">Approuver cette fiche</div>
        </div>
      </label>
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:16px;border:2px solid var(--gray-200);border-radius:10px;background:var(--white);transition:all .15s"
             id="lbl-rejete" onclick="selectDecision('rejete')">
        <input type="radio" name="decision" value="rejete" onchange="toggleMotif(this.value)" style="width:auto;accent-color:var(--danger)">
        <div>
          <div style="font-size:16px;margin-bottom:2px">❌</div>
          <div style="font-weight:700;color:var(--danger)">Rejeter</div>
          <div style="font-size:11px;color:var(--gray-600)">Renvoyer pour correction</div>
        </div>
      </label>
    </div>

    <div id="bloc-motif" style="display:none;margin-bottom:1.25rem">
      <label>Motif du rejet <span style="color:var(--danger)">*</span></label>
      <textarea name="motif" rows="4" maxlength="500"
                placeholder="Expliquez précisément la raison du rejet afin que l'enseignant puisse corriger sa fiche..."><?= Security::e($_POST['motif'] ?? '') ?></textarea>
      <div class="hint-text">Ce motif sera visible par l'enseignant et enregistré dans l'historique.</div>
    </div>

    <div class="btn-group">
      <button type="submit" class="btn btn-primary" style="padding:12px 2rem">Enregistrer la décision</button>
      <a href="voir_fiche.php?id=<?= $ficheId ?>" class="btn">Annuler</a>
    </div>
  </form>
</div>

<script>
function toggleMotif(val) {
    const bloc = document.getElementById('bloc-motif');
    const ta   = document.querySelector('textarea[name="motif"]');
    bloc.style.display = val==='rejete' ? '' : 'none';
    ta.required = val==='rejete';
    document.getElementById('lbl-valide').style.borderColor = val==='valide' ? 'var(--ujkz-vert)' : 'var(--gray-200)';
    document.getElementById('lbl-rejete').style.borderColor = val==='rejete' ? 'var(--danger)' : 'var(--gray-200)';
}
function selectDecision(val) {
    const radio = document.querySelector('input[name="decision"][value="'+val+'"]');
    if(radio) { radio.checked=true; toggleMotif(val); }
}
</script>
<?php
$bodyContent = ob_get_clean();
$title = 'Valider la fiche';
ob_start(); require __DIR__ . '/templates/layout.php'; echo ob_get_clean();
