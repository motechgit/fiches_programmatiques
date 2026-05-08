<?php
// ============================================================
// portail.php — Portail de validation : liste enseignants
// ============================================================
declare(strict_types=1);
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/ValidationRepository.php';
require_once __DIR__ . '/src/FicheRepository.php';
require_once __DIR__ . '/src/EtabRepository.php';
if (!class_exists('App')) require_once __DIR__ . '/src/App.php';
App::configureErrorDisplay();

$config   = require __DIR__ . '/config/security.php';

// ── Fonctions utilitaires globales ───────────────────────────
if (!function_exists('resolveEtabDept')) {
    function resolveEtabDept(PDO $pdo): array {
        $etabById = [];
        $deptById = [];
        foreach ($pdo->query("SELECT id, nom FROM etablissements WHERE actif=1")->fetchAll() as $r) {
            $etabById[(int)$r['id']] = $r['nom'];
        }
        foreach ($pdo->query("SELECT id, nom, sigle FROM departements WHERE actif=1")->fetchAll() as $r) {
            $deptById[(int)$r['id']] = $r['nom'] . (!empty($r['sigle']) ? ' ('.$r['sigle'].')' : '');
        }
        return [$etabById, $deptById];
    }
}

if (!function_exists('dansPerietre')) {
    /**
     * Indique si une fiche individuelle est dans le périmètre du validateur connecté.
     * Pour IESR_UJKZ : toujours vrai (périmètre = tout l'étab de rattachement).
     * Pour IESR_HORS / VACATAIRE : vérifie etab_id ou dept_id selon le rôle.
     */
    function dansPerietre(array $f): bool {
        $role = Auth::userRole();
        if (in_array($role, ['dei','vp_eip'], true)) return true;
        $wf = $f['type_workflow'] ?? 'IESR_UJKZ';
        if ($wf === 'IESR_UJKZ') return true;

        $ficheDeptId = (int)($f['dept_beneficiaire_fiche'] ?? 0);
        $ficheEtabId = (int)($f['etab_beneficiaire_fiche'] ?? 0);

        // Si les IDs de la fiche sont 0 (anciennes fiches) → autoriser par sécurité
        if ($ficheDeptId === 0 && $ficheEtabId === 0) return true;

        if ($role === 'chef_dept') {
            $deptId = Auth::userDeptId();
            // Si dept_id utilisateur non résolu → fallback par nom de département
            if ($deptId === 0) return true; // sera corrigé à la prochaine connexion
            return $ficheDeptId > 0 && $ficheDeptId === $deptId;
        }
        if ($role === 'directeur_adjoint' || $role === 'directeur') {
            $etabIds = Auth::userEtabIds();
            // Si etab_ids non résolus → fallback permissif
            if (empty($etabIds)) return true;
            return $ficheEtabId > 0 && in_array($ficheEtabId, $etabIds, true);
        }
        return true;
    }
}

if (!function_exists('filterFichesParPerimetre')) {
    /**
     * Filtre un tableau de fiches selon le périmètre du validateur connecté.
     * Pour IESR_UJKZ : pas de filtre supplémentaire (tout l'étab de rattachement).
     * Pour IESR_HORS / VACATAIRE :
     *   - chef_dept     : seulement les fiches dont dept_beneficiaire_fiche = son dept_id
     *   - directeur/adj : seulement les fiches dont etab_beneficiaire_fiche ∈ ses etab_ids
     */
    function filterFichesParPerimetre(array $fiches): array {
        $role = Auth::userRole();
        // DEI et VP EIP voient tout
        if (in_array($role, ['dei', 'vp_eip'], true)) return $fiches;

        return array_values(array_filter($fiches, function($f) use ($role) {
            $wf = $f['type_workflow'] ?? 'IESR_UJKZ';
            // IESR_UJKZ : pas de filtre par ligne (tout l'étab de rattachement)
            if ($wf === 'IESR_UJKZ') return true;

            // IESR_HORS / VACATAIRE : filtrer par périmètre bénéficiaire
            if ($role === 'chef_dept') {
                $deptId = Auth::userDeptId();
                return $deptId > 0 && (int)($f['dept_beneficiaire_fiche'] ?? 0) === $deptId;
            }
            if ($role === 'directeur_adjoint' || $role === 'directeur') {
                $etabIds = Auth::userEtabIds();
                return !empty($etabIds) && in_array((int)($f['etab_beneficiaire_fiche'] ?? 0), $etabIds, true);
            }
            return true;
        }));
    }
}
$security = new Security($config);
$security->sendSecurityHeaders();
$security->startSecureSession();
$csrfToken = $security->generateCsrfToken();

if (!Auth::check()) {
    if (!empty($_SESSION['admin_authenticated'])) {
        $_SESSION['user_role']  = 'dei';
        $_SESSION['user_nom']   = $_SESSION['admin_user'] ?? 'Admin';
        $_SESSION['user_id']    = 0;
        $_SESSION['user_dept']  = null;
        $_SESSION['user_etab']  = null;
        $_SESSION['user_since'] = time();
    } else { header('Location: login.php'); exit; }
}
if (isset($_GET['logout'])) {
    Auth::logout();
    unset($_SESSION['admin_authenticated'],$_SESSION['admin_user'],$_SESSION['admin_since']);
    header('Location: login.php'); exit;
}

$repo    = new ValidationRepository();
$ficheRepo = new FicheRepository();
$etabRepo  = new EtabRepository();
// Données BD avec fallback config statique
$_etabsP = $etabRepo->getListeEtabs(true);
$_deptsP = $etabRepo->getListeDepts(true);
$etablissementsPortail = !empty($_etabsP) ? array_column($_etabsP, 'nom') : ($etablissementsPortail ?? []);
$etabDepartementsPortail = [];
foreach ($_deptsP as $d) {
    foreach ($_etabsP as $e) {
        if ((int)$e['id'] === (int)$d['etablissement_id']) {
            $etabDepartementsPortail[$e['nom']][] = $d['nom'] . (!empty($d['sigle']) ? ' ('.$d['sigle'].')' : '');
            break;
        }
    }
}
if (empty($etabDepartementsPortail)) $etabDepartementsPortail = $config['etab_departements'] ?? [];
$role    = Auth::userRole();
$roleLabel  = Auth::roleLabel($role);
$roleColors = [
    'dei'               => ['bg'=>'#FEECEC','txt'=>'#C62828'],
    'directeur'         => ['bg'=>'#E8F5E9','txt'=>'#2E7D32'],
    'directeur_adjoint' => ['bg'=>'#E3F2FD','txt'=>'#0277BD'],
    'chef_dept'         => ['bg'=>'#FFF8E1','txt'=>'#E65100'],
    'vp_eip'            => ['bg'=>'#F3E5F5','txt'=>'#6A1B9A'],
];
$rc = $roleColors[$role] ?? ['bg'=>'#f0f0f0','txt'=>'#333'];
$stats = $repo->getStats();
$viewOnly = false; // Par défaut, mode édition

// ── Vue enseignant individuel ───────────────────────────────
if (isset($_GET['ens'])) {
    $ensId = (int)$_GET['ens'];
    $viewOnly = isset($_GET['view']) && $_GET['view'] === '1'; // Mode visualisation (masquer les boutons d'édition)
    $pdo   = Database::getInstance();
    $stmt  = $pdo->prepare("SELECT * FROM enseignants WHERE id = ? LIMIT 1");
    $stmt->execute([$ensId]);
    $ens   = $stmt->fetch();
    if (!$ens) { header('Location: portail.php'); exit; }

    $fiches     = $repo->getFichesEnseignantPortail($ensId);
    $historique = [];
    foreach ($fiches as $f) {
        $historique[$f['id']] = $repo->getHistorique((int)$f['id']);
    }

    // Charger les preuves et grouper par semestre pour fiches de suivi
    $preuvesByFiche = [];
    $fichesAvecPreuves = [];
    if (!empty($fiches)) {
        $ids = array_column($fiches, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $stP = $pdo->prepare("SELECT * FROM preuves WHERE fiche_id IN ($ph) ORDER BY uploaded_at ASC");
        $stP->execute($ids);
        foreach ($stP->fetchAll() as $prow) {
            $preuvesByFiche[(int)$prow['fiche_id']][] = $prow;
        }
        foreach ($fiches as $fiche) {
            $fid = (int)$fiche['id'];
            if (!empty($preuvesByFiche[$fid])) {
                $sem = $fiche['semestre'] ?? 'S1';
                $fichesAvecPreuves[$sem][] = array_merge($fiche, ['preuves' => $preuvesByFiche[$fid]]);
            }
        }
    }

    $annee      = !empty($fiches) ? ($fiches[0]['annee_academique'] ?? '2024-2025') : ($config['annee_academique'] ?? '2024-2025');
    $stCls      = ['en_attente'=>'badge-or','valide'=>'badge-green','rejete'=>'badge-red'];
    $stIcons    = ['en_attente'=>'⏳','valide'=>'✓','rejete'=>'✕'];
    $stCols     = ['statut_chef','statut_dir_adj','statut_dir','statut_dei','statut_vp_eip'];

    ob_start();
    ?>

    <!-- Breadcrumb + hero -->
    <div class="breadcrumb">
      <a href="portail.php">Portail de validation</a>
      <span class="breadcrumb-sep">›</span>
      <span><?= Security::e(trim($ens['nom'].' '.($ens['prenom']??''))) ?></span>
    </div>

    <div class="page-hero" style="margin-bottom:1rem">
      <div>
        <h1>📋 Fiche de <?= Security::e(trim($ens['nom'].' '.($ens['prenom']??''))) ?></h1>
        <div class="subtitle">
          <?= Security::e($ens['matricule']) ?> · <?= Security::e($ens['grade'] ?? '') ?>
          · <?= Security::e($ens['departement'] ?? '') ?>
        </div>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <span class="badge" style="background:<?= $rc['bg'] ?>;color:<?= $rc['txt'] ?>;font-size:12px;padding:5px 12px">
          <?= Security::e($roleLabel) ?>
        </span>
        <button onclick="window.print()" class="btn btn-sm no-print">🖨 Imprimer</button>
        <a href="portail.php" class="btn btn-sm no-print">← Retour liste</a>
      </div>
    </div>

    <!-- Message de succès après validation -->
    <?php if (!empty($_GET['success'])): ?>
    <div class="alert alert-success no-print" style="margin-bottom:12px;padding:10px 16px;background:#e8f5e9;border:1px solid #a5d6a7;border-radius:6px;color:#1b5e20">
      <?php $nb = (int)$_GET['success']; ?>
      <?php if ($nb > 1): ?>
        ✅ <strong><?= $nb ?> enseignements</strong> traités avec succès.
      <?php else: ?>
        ✅ Décision enregistrée avec succès.
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger no-print" style="margin-bottom:12px;padding:10px 16px;background:#ffebee;border:1px solid #ef9a9a;border-radius:6px;color:#b71c1c">
      <?php if ($_GET['error'] === 'motif_requis'): ?>
        ❌ Le motif de rejet est obligatoire pour rejeter une fiche.
      <?php else: ?>
        ❌ Une erreur s'est produite. Veuillez réessayer.
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Styles impression -->
    <style>
    @media print {
      .no-print, .site-header, .site-subnav, .breadcrumb,
      .page-hero, nav, footer, .btn, .btn-group,
      .validation-actions { display:none !important; }
      body { background:#fff !important; }
      .fiche-print-wrapper { box-shadow:none !important; border:none !important; }
      @page { size:A4 portrait; margin:15mm 12mm; }
    }
    .fiche-print-wrapper {
      background:#fff; max-width:880px; margin:0 auto 1.5rem;
      padding:20px 24px;
      font-family:Arial,Helvetica,sans-serif; font-size:10.5pt; color:#000;
      box-shadow:0 2px 10px rgba(0,0,0,.08);
      border:1.5px solid #ccc; border-radius:4px;
    }
    .ft { width:100%; border-collapse:collapse; font-size:9.5pt; }
    .ft th, .ft td { border:1px solid #000; padding:4px 3px; }
    .ft th { background:#e0e0e0; text-align:center; font-weight:700; }
    .sem-h td { background:#f0f0f0; font-weight:700; text-align:center; font-style:italic; }
    .tot-row td { background:#d0d0d0; font-weight:700; text-align:center; }
    .grand-tot td { background:#b0b0b0; font-weight:700; text-align:center; }
    .val-row { background:#f9fdf9; }
    </style>

    <div class="fiche-print-wrapper">

      <!-- En-tête officiel -->
      <table style="width:100%;border:none;border-collapse:collapse;margin-bottom:10px">
        <tr>
          <td style="width:36%;vertical-align:top;text-align:center;font-size:8.5pt;font-weight:700;line-height:1.55">
            MINISTÈRE DE L'ENSEIGNEMENT<br>SUPÉRIEUR, DE LA RECHERCHE<br>ET DE L'INNOVATION<br>
            <span style="font-weight:400">-----------</span><br>SECRÉTARIAT GÉNÉRAL<br>
            <span style="font-weight:400">-----------</span><br>
            <strong>UNIVERSITÉ JOSEPH KI-ZERBO</strong><br>
            <span style="font-weight:400">-----------</span><br>PRÉSIDENCE<br>
            <span style="font-weight:400">-------------</span>
          </td>
          <td style="width:24%;text-align:center;vertical-align:middle">
            <img src="logo_ujkz.jpg" alt="Logo UJKZ" style="width:68px;height:68px;object-fit:contain">
          </td>
          <td style="width:36%;vertical-align:top;text-align:right;font-size:8.5pt;line-height:1.7">
            <strong><em>BURKINA FASO</em></strong><br>
            <em>La Patrie ou la Mort, nous Vaincrons</em><br>
            <span style="letter-spacing:2px;font-size:7.5pt">·········································</span><br>
            <?php 
            // ✓ Utiliser l'année de la fiche BD (source de vérité = données saisies)
            $yearDisplay = !empty($fiches) ? ($fiches[0]['annee_academique'] ?? '2024-2025') : ($annee ?? '2024-2025');
            ?>
            Année universitaire <strong><?= Security::e($yearDisplay) ?></strong>
          </td>
        </tr>
      </table>

      <!-- Numéro de fiche et QR code -->
      <?php
      if (!empty($fiches)) {
          $fichePrincipale = $fiches[0];
          $numeroFiche = $fichePrincipale['numero_fiche'] ?? '';
          $qrcodeToken = $fichePrincipale['qrcode_token'] ?? '';
          $qrcodeBase64 = '';
          
          // Générer le numéro si absent
          if (!$numeroFiche) {
              $anneeNum = explode('-', $yearDisplay ?? '2024-2025')[0];
              $numeroFiche = 'FP-' . $anneeNum . '-' . str_pad((string)$fichePrincipale['id'], 4, '0', STR_PAD_LEFT);
          }
          
          // Générer le token QR si absent
          if (!$qrcodeToken) {
              $qrcodeToken = bin2hex(random_bytes(16));
          }
          
          // Générer un vrai QR code via API gratuite
          // URL de vérification - adapter le domaine selon l'environnement
          $verificationUrl = 'http://127.0.0.1/fiches_programmatiques/verifier-fiche.php?token=' . urlencode($qrcodeToken);
          $qrcodeBase64 = 'https://api.qrserver.com/v1/create-qr-code/?size=60x60&data=' . urlencode($verificationUrl);
      ?>
      <table style="width:100%;border:none;border-collapse:collapse;margin-bottom:4px">
          <tr>
              <td style="width:70%;vertical-align:top;font-size:9pt;padding:2px">
                  <strong>Numéro : <?= Security::e($numeroFiche) ?></strong><br>
                  <span style="font-size:8pt;color:#666">Vérification</span>
              </td>
              <td style="width:30%;text-align:right;vertical-align:top;padding:2px">
                  <img src="<?= $qrcodeBase64 ?>" alt="QR Code" style="width:60px;height:60px;border:1px solid #999">
              </td>
          </tr>
      </table>
      <?php } ?>

      <!-- Titre -->
      <div style="border:1.5px solid #000;background:#e0e0e0;text-align:center;padding:4px;margin-bottom:2px">
        <span style="font-size:11pt;font-weight:700">FICHE PROGRAMMATIQUE</span>
      </div>
      <div style="text-align:center;font-size:9pt;font-weight:700;text-decoration:underline;margin-bottom:3px">
        Pour enseignant <?= Security::e($ens['type_enseignant'] ?? 'permanent') ?>
      </div>

      <!-- Infos enseignant -->
      <?php
      $nom     = $ens['nom'] ?? '';
      $prenom  = $ens['prenom'] ?? '';
      $diplome = $ens['diplome'] ?? '';
      $grade   = $ens['grade'] ?? '';
      $dateN  = !empty($ens['date_nomination']) ? date('d/m/Y', strtotime($ens['date_nomination'])) : '';
      $vs     = $ens['volume_statutaire'] ?? '';
      $ab     = $ens['abattement'] ?? '';
      $mot    = $ens['motif_abattement'] ?? '';
      $va     = $ens['volume_apres_abatt'] ?? '';
      $er     = $ens['etab_rattachement']  ?? '';
      $ea     = $ens['etab_administratif'] ?? '';
      $eb     = $ens['etab_beneficiaire']  ?? '';
      ?>
      <div style="font-size:8.5pt;line-height:1.4;margin-bottom:3px">
        <div>
          Nom : <strong><?= Security::e($nom) ?></strong>
          <?php if($prenom): ?>&nbsp;&nbsp; Prénom(s) : <strong><?= Security::e($prenom) ?></strong><?php endif; ?>
          <?php if($diplome): ?>&nbsp;&nbsp; Diplôme(s) : <strong><?= Security::e($diplome) ?></strong><?php endif; ?>
        </div>
        <div>
          <?php if ($grade): ?>Grade : <strong><?= Security::e($grade) ?></strong>&nbsp;&nbsp;<?php endif; ?>
          <?php if ($dateN): ?>Nomination : <strong><?= Security::e($dateN) ?></strong><?php endif; ?>
        </div>
        <?php if ($vs !== ''): ?>
        <div>Vol. statutaire : <strong><?= Security::e($vs) ?>h</strong>
          &nbsp; Abattement : <strong><?= Security::e($ab) ?>h</strong>
          <?php if ($mot): ?>&nbsp; Motif : <strong><?= Security::e($mot) ?></strong><?php endif; ?>
        </div>
        <div>Volume obligatoire après abattement : <strong><?= Security::e($va) ?>h</strong></div>
        <?php endif; ?>
        <?php if ($er): ?><div>IESR de rattachement : <strong><?= Security::e($er) ?></strong></div><?php endif; ?>
        <?php if ($ea): ?><div>Établissement de rattachement administratif : <strong><?= Security::e($ea) ?></strong></div><?php endif; ?>
        <?php
        // Map étab → liste de cours (IDs → noms)
        [$_etabByIdP, ] = resolveEtabDept($pdo);
        $ebMapPort = [];
        foreach ($fiches as $_f) {
            if (!empty($_f['is_encadrement'])) continue;
            $ebIdP = (int)($_f['etab_beneficiaire_fiche'] ?? 0);
            if ($ebIdP === 0) continue;
            $ebNomP = $_etabByIdP[$ebIdP] ?? "Étab.#$ebIdP";
            $cL = trim($_f['cours'] ?? '');
            if (!isset($ebMapPort[$ebNomP])) $ebMapPort[$ebNomP] = [];
            if ($cL !== '' && !in_array($cL, $ebMapPort[$ebNomP], true)) $ebMapPort[$ebNomP][] = $cL;
        }
        ?>
        <?php if (!empty($ebMapPort)): ?>
        <div style="line-height:1.8">
          <span style="font-weight:700">Établissement bénéficiaire des enseignements :</span>
          <?php foreach ($ebMapPort as $etabNomPort => $coursListPort): ?>
          <div style="margin-left:12px">
            — <strong><?= Security::e($etabNomPort) ?></strong>
            <?php if (!empty($coursListPort)): ?>
            <span style="font-weight:400;color:#333"> : <?= Security::e(implode(', ', $coursListPort)) ?></span>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php elseif ($eb): ?>
        <div>Établissement bénéficiaire des enseignements : <strong><?= Security::e($eb) ?></strong></div>
        <?php endif; ?>
      </div>

      <!-- Titre tableau -->
      <div style="text-align:center;font-size:9.5pt;margin:6px 0 4px">
        Tableau descriptif des enseignements confiés en réunion de département
      </div>

      <!-- Action globale : valider/rejeter tous les enseignements en une fois -->
      <?php
      // Vérifier si au moins une fiche est validable par cet utilisateur
      $auMoinsUneValidable = false;
      foreach ($fiches as $_fv) {
          if (Auth::peutValider($role, $_fv)) { $auMoinsUneValidable = true; break; }
      }
      $ensIdPourRetour = (int)($ens['id'] ?? 0);
      // Détecter si la fiche contient des cours hors périmètre (calculé ici pour être disponible)
      // ficheEstMixte : parmi les cours non-encadrement, y en a-t-il hors périmètre ?
      $tousLesCoursPrim = array_values(array_filter($fiches, function($f){
          return empty($f['is_encadrement']) && ($f['semestre']??'') !== 'ENC';
      }));
      $coursEnPerimetre = array_values(array_filter($tousLesCoursPrim, function($f){ return dansPerietre($f); }));
      $ficheEstMixte    = count($tousLesCoursPrim) > count($coursEnPerimetre);
      ?>
      <?php if ($auMoinsUneValidable && !$ficheEstMixte && !($viewOnly ?? false)): ?>
      <div class="no-print" style="margin:10px 0 14px;display:flex;gap:10px;align-items:center">
        <form method="POST" action="valider_fiche_global.php" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
          <input type="hidden" name="ens_id"     value="<?= $ensIdPourRetour ?>">
          <input type="hidden" name="annee"      value="<?= Security::e($annee) ?>">
          <button type="submit" name="decision" value="valide"
                  class="btn btn-primary"
                  onclick="return confirm('Valider tous les enseignements de cette fiche ?')"
                  style="background:#1a6b1a;padding:9px 20px">
            ✅ Valider tous les enseignements
          </button>
          <button type="submit" name="decision" value="rejete"
                  class="btn"
                  onclick="return confirm('Rejeter tous les enseignements ?')"
                  style="background:#b00;color:#fff;padding:9px 20px">
            ❌ Rejeter tout
          </button>
        </form>
      </div>
      <?php elseif ($auMoinsUneValidable && $ficheEstMixte): ?>
      <div class="no-print alert" style="margin:10px 0 14px;padding:10px 16px;
           background:#fff8e1;border:1px solid #f59e0b;border-radius:6px;
           color:#7a5800;font-size:12px">
        ⚠️ Cette fiche contient des enseignements relevant de plusieurs établissements ou départements.
        Validez chaque enseignement individuellement via le bouton <strong>Décider</strong>.
      </div>
      <?php endif; ?>

      <!-- Tableau des enseignements avec actions de validation -->
      <?php
      $s1Fiches  = array_values(array_filter($fiches, function($f){
          return ($f['semestre']??'')==='S1' && empty($f['is_encadrement']);
      }));
      $s2Fiches  = array_values(array_filter($fiches, function($f){
          return ($f['semestre']??'')==='S2' && empty($f['is_encadrement']);
      }));
      // Encadrement : visible uniquement pour IESR_UJKZ
      $wfEnc    = $fiches[0]['type_workflow'] ?? 'IESR_UJKZ';
      $encFiches = ($wfEnc === 'IESR_UJKZ') ? array_values(array_filter($fiches, function($f){
          return !empty($f['is_encadrement']) || ($f['semestre']??'')==='ENC';
      })) : [];
      $tS1cm = array_sum(array_column($s1Fiches,'volume_cm'));
      $tS1td = array_sum(array_column($s1Fiches,'volume_td'));
      $tS1tp = array_sum(array_column($s1Fiches,'volume_tp'));
      $tS2cm = array_sum(array_column($s2Fiches,'volume_cm'));
      $tS2td = array_sum(array_column($s2Fiches,'volume_td'));
      $tS2tp = array_sum(array_column($s2Fiches,'volume_tp'));
      $tEncCm = array_sum(array_column($encFiches,'volume_cm'));
      $tEncTd = array_sum(array_column($encFiches,'volume_td'));
      $tCm = $tS1cm+$tS2cm+$tEncCm; $tTd = $tS1td+$tS2td+$tEncTd;

      $stColMap = [
          'chef_dept'         => 'statut_chef',
          'directeur_adjoint' => 'statut_dir_adj',
          'directeur'         => 'statut_dir',
          'dei'               => 'statut_dei',
          'vp_eip'            => 'statut_vp_eip',
      ];
      $myStatutCol = $stColMap[$role] ?? 'statut';
      ?>
      <table class="ft">
        <thead>
          <tr>
            <th rowspan="2" style="width:4%">N°</th>
            <th rowspan="2" style="width:11%">CODE</th>
            <th rowspan="2" style="width:16%">PARCOURS</th>
            <th rowspan="2">UE ou ECUE</th>
            <th rowspan="2" style="width:5%">NTC</th>
            <th colspan="3" style="width:16%">Volume horaire<sup>1</sup></th>
            <th rowspan="2" style="width:10%;font-size:9pt">Statut</th>
            <th rowspan="2" class="no-print" style="width:14%;font-size:9pt">Action</th>
          </tr>
          <tr>
            <th style="width:5%">CT</th>
            <th style="width:5%">TD</th>
            <th style="width:5%">TP</th>
          </tr>
        </thead>
        <tbody>
        <?php
        if (!function_exists('statutEtapeLabel')) {
        function statutEtapeLabel(array $f): array {
            if (($f['statut'] ?? '') === 'rejetee') {
                if (($f['statut_dei']     ?? '') === 'rejete') return ['label'=>'Rejetée — DEI',         'class'=>'badge-red'];
                if (($f['statut_vp_eip']  ?? '') === 'rejete') return ['label'=>'Rejetée — VP EIP',       'class'=>'badge-red'];
                if (($f['statut_dir']     ?? '') === 'rejete') return ['label'=>'Rejetée — Directeur',    'class'=>'badge-red'];
                if (($f['statut_dir_adj'] ?? '') === 'rejete') return ['label'=>'Rejetée — Dir. adjoint', 'class'=>'badge-red'];
                if (($f['statut_chef']    ?? '') === 'rejete') return ['label'=>'Rejetée — Chef dép.',    'class'=>'badge-red'];
                return ['label'=>'Rejetée', 'class'=>'badge-red'];
            }
            if (($f['statut'] ?? '') === 'validee') return ['label'=>'Validée ✓', 'class'=>'badge-green'];
            $wf = $f['type_workflow'] ?? 'IESR_UJKZ';
            if (($f['statut_chef']    ?? 'en_attente') === 'en_attente') return ['label'=>'En attente — Chef de dép.', 'class'=>'badge-or'];
            if (($f['statut_dir_adj'] ?? 'en_attente') === 'en_attente') return ['label'=>'En attente — Dir. adjoint', 'class'=>'badge-or'];
            if (($f['statut_dir']     ?? 'en_attente') === 'en_attente') return ['label'=>'En attente — Directeur',    'class'=>'badge-or'];
            if (($f['statut_dei']     ?? 'en_attente') === 'en_attente') return ['label'=>'En attente — DEI',          'class'=>'badge-or'];
            // NOTE : VP_EIP n'est PAS utilisé pour VACATAIRE
            return ['label'=>'En attente', 'class'=>'badge-or'];
        }
        }

        function renderSemBlock(array $fiches, string $titre, array $hist,
            string $role, string $myCol, array $stCls, array $stIcons,
            int &$totCm, int &$totTd, callable $e): string
        {
            if (empty($fiches)) return '';
            $html = '<tr class="sem-h"><td colspan="10">'.$titre.'</td></tr>';
            $cnt = 0;
            foreach ($fiches as $f) {
                $cnt++;
                $fid = (int)$f['id'];
                $cm = (int)($f['volume_cm']??0);
                $td = (int)($f['volume_td']??0);
                $tp = (int)($f['volume_tp']??0);
                $totCm += $cm; $totTd += $td;
                $st    = $f[$myCol] ?? 'en_attente';
                $stGlo = $f['statut'] ?? 'en_attente';
                $code  = $f['code_ue'] ?: ($f['code'] ?: '');
                $peutValider = Auth::peutValider($role, $f);

                // Badge statut enrichi : étape courante du circuit
                $etapeInfo = statutEtapeLabel($f);
                if ($st === 'valide' && $stGlo !== 'validee') {
                    // Ce validateur a validé, mais le circuit continue
                    $stBadge = '<span class="badge badge-green" style="font-size:10px">✓ Validé</span>'
                             . '<br><span style="font-size:9px;color:#888">'.$etapeInfo['label'].'</span>';
                } elseif ($st === 'rejete') {
                    $stBadge = '<span class="badge badge-red" style="font-size:10px">✕ Rejeté</span>';
                } elseif ($stGlo === 'validee') {
                    $stBadge = '<span class="badge badge-green" style="font-size:10px">✓ Validée</span>';
                } elseif ($stGlo === 'rejetee') {
                    $stBadge = '<span class="badge badge-red" style="font-size:10px">✕ Rejetée</span>';
                } else {
                    $stBadge = '<span class="badge '.$etapeInfo['class'].'" style="font-size:10px">'
                             . Security::e($etapeInfo['label']).'</span>';
                }

                // Action : bouton Décider uniquement si dans le périmètre
                $actionHtml = '';
                $dansPerim  = dansPerietre($f);
                if ($peutValider && $dansPerim && !($viewOnly ?? false)) {
                    $actionHtml = '<div style="display:flex;gap:4px;flex-wrap:wrap;">'
                        .'<a href="valider_fiche.php?id='.$fid.'&from_ens='.(int)($f['enseignant_id']??0).'"'
                        .' class="btn btn-xs btn-primary">Décider</a>'
                        .'</div>';
                } elseif (!$dansPerim && !in_array($role, ['dei','vp_eip'], true)) {
                    $actionHtml = '<span style="font-size:10px;color:#aaa;font-style:italic">Hors périmètre</span>';
                }

                $html .= '<tr>'
                    .'<td style="text-align:center">'.$cnt.'</td>'
                    .'<td style="text-align:center">'.$e($code).'</td>'
                    .'<td>'.$e($f['parcours']??'').'</td>'
                    .'<td>'.$e($f['cours']??'').'</td>'
                    .'<td style="text-align:center">'.$e($f['ntc']??'').'</td>'
                    .'<td style="text-align:center">'.($cm?:'-').'</td>'
                    .'<td style="text-align:center">'.($td?:'-').'</td>'
                    .'<td style="text-align:center">'.($tp?:'-').'</td>'
                    .'<td style="text-align:center">'.$stBadge.'</td>'
                    .'<td class="no-print validation-actions">'.$actionHtml.'</td>'
                    .'</tr>';
            }
            $totS1cm = array_sum(array_column($fiches,'volume_cm'));
            $totS1td = array_sum(array_column($fiches,'volume_td'));
            $html .= '<tr class="tot-row"><td colspan="5">TOTAL — '.$titre.'</td>'
                .'<td>'.($totS1cm?:'').'</td><td>'.($totS1td?:'').'</td><td></td>'
                .'<td colspan="2"></td></tr>';
            return $html;
        }

        $totCm1=0; $totTd1=0; $totCm2=0; $totTd2=0; $totCmEnc=0; $totTdEnc=0;
        echo renderSemBlock($s1Fiches, "Premier semestre de l'année",
            $historique, $role, $myStatutCol, $stCls, $stIcons, $totCm1, $totTd1,
            function($v){ return Security::e($v); });
        echo renderSemBlock($s2Fiches, "Deuxième semestre de l'année",
            $historique, $role, $myStatutCol, $stCls, $stIcons, $totCm2, $totTd2,
            function($v){ return Security::e($v); });
        if (!empty($encFiches)) {
            echo renderSemBlock($encFiches, "Encadrement",
                $historique, $role, $myStatutCol, $stCls, $stIcons, $totCmEnc, $totTdEnc,
                function($v){ return Security::e($v); });
        }
        ?>
        <tr class="grand-tot">
          <td colspan="5">TOTAUX</td>
          <td><?= $tCm?:'' ?></td><td><?= $tTd?:'' ?></td><td></td>
          <td colspan="2"></td>
        </tr>
        </tbody>
      </table>

      <!-- Zone signatures / validations -->
      <div style="margin-top:18px">
        <div style="text-align:right;font-size:9pt;margin-bottom:6px">
          Ouagadougou, le <?= date('d/m/Y à H:i') ?>
        </div>
        <table style="width:100%;border-collapse:collapse">
          <tr>
          <?php
          // VP EIP uniquement visible pour les fiches VACATAIRE
          $wfFiche = $fiches[0]['type_workflow'] ?? 'IESR_UJKZ';
          $sigActors = [
              ['role'=>'chef_dept',         'titre'=>'Le Chef de Département'],
              ['role'=>'directeur_adjoint', 'titre'=>'Le Directeur Adjoint'],
              ['role'=>'directeur',         'titre'=>'Le Directeur'],
              ['role'=>'dei',               'titre'=>'La DEI'],
          ];
          if ($wfFiche === 'VACATAIRE') {
              // VP EIP ne signe pas la fiche programmatique
          }
          // Construire un map global de validations (première fiche pour la signature)
          $globalValMap = [];
          foreach ($historique as $fid => $hist) {
              foreach ($hist as $h) {
                  $r = $h['etape_role'] ?? $h['role'] ?? '';
                  if (!isset($globalValMap[$r]) || $h['decision'] === 'valide') {
                      $globalValMap[$r] = $h;
                  }
              }
          }
          foreach ($sigActors as $actor):
              $v   = $globalValMap[$actor['role']] ?? null;
              $dec = $v['decision'] ?? 'en_attente';
              $nomV = $v['valideur_nom'] ?? '—';
              $dateV = !empty($v['created_at'])
                       ? date('d/m/Y', strtotime($v['created_at'])) : '—';
          ?>
          <td style="width:25%;text-align:center;vertical-align:top;padding:4px">
            <div style="font-weight:700;font-size:9pt;text-decoration:underline;margin-bottom:6px">
              <?= Security::e($actor['titre']) ?>
            </div>
            <?php if ($dec === 'valide'): ?>
            <div style="color:#1a6b1a;font-size:9pt">
              ✔ Validé par <strong><?= Security::e($nomV) ?></strong><br>
              <span style="font-size:8.5pt">Le <?= Security::e($dateV) ?></span>
            </div>
            <?php elseif ($dec === 'rejete'): ?>
            <div style="color:#b00;font-size:9pt">
              ✖ Rejeté — <?= Security::e($dateV) ?>
            </div>
            <?php else: ?>
            <div style="color:#888;font-size:9pt;font-style:italic;margin-bottom:4px">En attente</div>
            <div style="border-bottom:1px solid #000;margin:20px 8px 2px"></div>
            <div style="font-size:8pt;color:#666">Signature &amp; cachet</div>
            <?php endif; ?>
          </td>
          <?php endforeach; ?>
          </tr>
        </table>
      </div>

      <!-- Notes officielles -->
      <div style="margin-top:12px;font-size:7.5pt;border-top:1px solid #aaa;padding-top:4px;color:#555">
        <sup>1</sup> Établir une fiche de suivi par établissement. <sup>2</sup> Calculer sans convertir TD/TP en CM.
        NTC = nombre total de crédits. Imprimé le <?= date('d/m/Y à H:i') ?>.
      </div>

    </div><!-- /fiche-print-wrapper -->

    <?php foreach (['S1','S2'] as $semSuivi):
      // Filtrer les cours selon le périmètre du validateur (chef dept, directeur, etc.)
      $fichesSuiviFiltrees = isset($fichesAvecPreuves[$semSuivi])
          ? filterFichesParPerimetre($fichesAvecPreuves[$semSuivi])
          : [];
      if (empty($fichesSuiviFiltrees)) continue;
      // Calculer totaux consolidés du semestre
      $tCmC = 0; $tTdC = 0; $tTpC = 0;
      $tCmE = 0; $tTdE = 0;
      $tousComm = [];
      foreach ($fichesSuiviFiltrees as $fS) {
          $tCmC += (int)($fS['volume_cm'] ?? 0);
          $tTdC += (int)($fS['volume_td'] ?? 0);
          $tTpC += (int)($fS['volume_tp'] ?? 0);
          foreach ($fS['preuves'] ?? [] as $pS) {
              $tCmE += (int)($pS['volume_cm_effectue'] ?? 0);
              $tTdE += (int)($pS['volume_td_effectue'] ?? 0);
              if (!empty($pS['commentaire'])) $tousComm[] = trim($pS['commentaire']);
          }
      }
      $moisSuivi = implode(' — ', array_unique($tousComm)) ?: ($ens['mois_execution'] ?? '');
    ?>

    <!-- ════ FICHE SEMESTRIELLE DE SUIVI <?= $semSuivi ?> ════ -->
    <div class="fiche-print-wrapper" style="margin-top:2rem;page-break-before:always">

      <!-- En-tête officiel -->
      <table style="width:100%;border:none;border-collapse:collapse;margin-bottom:10px">
        <tr>
          <td style="width:36%;vertical-align:top;text-align:center;font-size:8.5pt;font-weight:700;line-height:1.55">
            MINISTÈRE DE L'ENSEIGNEMENT<br>SUPÉRIEUR, DE LA RECHERCHE<br>SCIENTIFIQUE ET DE L'INNOVATION<br>
            <span style="font-weight:400">-----------</span><br>SECRÉTARIAT GÉNÉRAL<br>
            <span style="font-weight:400">-----------</span><br>
            <strong>UNIVERSITÉ JOSEPH KI-ZERBO</strong><br>
            <span style="font-weight:400">-----------</span><br>PRÉSIDENCE<br>
            <span style="font-weight:400">-------------</span>
          </td>
          <td style="width:24%;text-align:center;vertical-align:middle">
            <img src="logo_ujkz.jpg" alt="Logo UJKZ" style="width:68px;height:68px;object-fit:contain">
          </td>
          <td style="width:36%;vertical-align:top;text-align:right;font-size:8.5pt;line-height:1.7">
            <strong><em>BURKINA FASO</em></strong><br>
            <em>La Patrie ou la Mort, nous Vaincrons</em><br>
            <span style="letter-spacing:2px;font-size:7.5pt">·········································</span><br>
            <?php 
            // ✓ Utiliser l'année de la fiche BD pour le semestre courant
            $yearSuivi = '2024-2025';
            if (!empty($fichesSuiviFiltrees) && !empty($fichesSuiviFiltrees[0])) {
              $yearSuivi = $fichesSuiviFiltrees[0]['annee_academique'] ?? '2024-2025';
            }
            ?>
            Année universitaire <strong><?= Security::e($yearSuivi) ?></strong>
          </td>
        </tr>
      </table>

      <!-- Titre -->
      <div style="border:1.5px solid #000;background:#e0e0e0;text-align:center;padding:7px;margin-bottom:3px">
        <span style="font-size:12pt;font-weight:700">FICHE SEMESTRIELLE DE SUIVI DES HEURES EFFECTUÉES</span>
      </div>
      <div style="text-align:center;font-size:10.5pt;font-weight:700;text-decoration:underline;margin-bottom:5px">
        Pour enseignant <?= Security::e($ens['type_enseignant'] ?? 'permanent') ?>
      </div>

      <!-- Semestre coché -->
      <div style="font-size:10pt;margin-bottom:5px">
        Semestre :
        <span style="display:inline-block;border:1.5px solid #000;width:14px;height:14px;
                     text-align:center;line-height:12px;margin:0 4px;vertical-align:middle">
          <?= $semSuivi==='S1'?'✓':'' ?>
        </span> S1 &nbsp;&nbsp;
        <span style="display:inline-block;border:1.5px solid #000;width:14px;height:14px;
                     text-align:center;line-height:12px;margin:0 4px;vertical-align:middle">
          <?= $semSuivi==='S2'?'✓':'' ?>
        </span> S2
      </div>

      <!-- Infos enseignant -->
      <div style="font-size:10pt;line-height:1.85;margin-bottom:5px">
        <div>
          Nom : <strong><?= Security::e($ens['nom']) ?></strong>
          <?php if(!empty($ens['prenom'])): ?>&nbsp;&nbsp; Prénom(s) : <strong><?= Security::e($ens['prenom']) ?></strong><?php endif; ?>
          <?php if(!empty($ens['diplome'])): ?>&nbsp;&nbsp; Diplôme(s) : <strong><?= Security::e($ens['diplome']) ?></strong><?php endif; ?>
        </div>
        <div>
          Grade : <strong><?= Security::e($ens['grade'] ?? '') ?></strong>
          <?php if(!empty($ens['date_nomination'])): ?>&nbsp;&nbsp; Nomination : <strong><?= Security::e(date('d/m/Y', strtotime($ens['date_nomination']))) ?></strong><?php endif; ?>
        </div>
        <?php if (!empty($ens['volume_statutaire'])): ?>
        <div>Vol. statutaire : <strong><?= Security::e($ens['volume_statutaire']) ?>h</strong>
          &nbsp; Abattement : <strong><?= Security::e($ens['abattement'] ?? '') ?></strong>
        </div>
        <div>Volume obligatoire après abattement : <strong><?= Security::e($ens['volume_apres_abatt'] ?? '') ?>h</strong></div>
        <?php endif; ?>
        <?php if (!empty($ens['etab_rattachement'])): ?>
        <div>IESR de rattachement : <strong><?= Security::e($ens['etab_rattachement']) ?></strong></div>
        <?php endif; ?>
        <?php if (!empty($ens['etab_administratif'])): ?>
        <div>Établissement de rattachement administratif : <strong><?= Security::e($ens['etab_administratif']) ?></strong></div>
        <?php endif; ?>
        <div>Établissement bénéficiaire : <strong><?= Security::e($ens['etab_beneficiaire'] ?? '') ?></strong></div>
        <div>Mois et semaines d'exécution des heures :
          <strong><?= $moisSuivi ? Security::e($moisSuivi) : str_repeat('.', 30) ?></strong>
        </div>
      </div>

      <!-- Titre tableau -->
      <div style="text-align:center;font-size:9.5pt;margin:5px 0 4px">
        Tableau descriptif des enseignements confiés et effectués
      </div>

      <!-- Tableau : tous les cours avec justificatifs -->
      <table class="ft">
        <thead>
          <tr>
            <th rowspan="2" style="width:4%">N°</th>
            <th rowspan="2" style="width:10%">CODE</th>
            <th rowspan="2" style="width:15%">PARCOURS</th>
            <th rowspan="2">UE ou ECUE</th>
            <th rowspan="2" style="width:5%">NTC</th>
            <th colspan="3" style="width:18%">Vol. horaire confié</th>
            <th colspan="3" style="width:18%">Vol. horaire effectué</th>
          </tr>
          <tr>
            <th style="width:6%">CT</th><th style="width:6%">TD</th><th style="width:6%">TP</th>
            <th style="width:6%">CT</th><th style="width:6%">TD</th><th style="width:6%">TP</th>
          </tr>
        </thead>
        <tbody>
          <?php $num=0; foreach ($fichesSuiviFiltrees as $fS):
            $num++;
            $codeS = $fS['code_ue'] ?: ($fS['code'] ?? '');
            $cmCS  = (int)($fS['volume_cm'] ?? 0);
            $tdCS  = (int)($fS['volume_td'] ?? 0);
            $tpCS  = (int)($fS['volume_tp'] ?? 0);
            $cmES=0; $tdES=0;
            foreach ($fS['preuves'] ?? [] as $pFS) {
                $cmES += (int)($pFS['volume_cm_effectue'] ?? 0);
                $tdES += (int)($pFS['volume_td_effectue'] ?? 0);
            }
          ?>
          <tr>
            <td style="text-align:center"><?= $num ?></td>
            <td style="text-align:center"><?= Security::e($codeS) ?></td>
            <td><?= Security::e($fS['parcours'] ?? '') ?></td>
            <td><?= Security::e($fS['cours'] ?? '') ?></td>
            <td style="text-align:center"><?= Security::e($fS['ntc'] ?? '') ?></td>
            <td style="text-align:center"><?= $cmCS ?: '-' ?></td>
            <td style="text-align:center"><?= $tdCS ?: '-' ?></td>
            <td style="text-align:center"><?= $tpCS ?: '-' ?></td>
            <td style="text-align:center"><?= $cmES ?: '-' ?></td>
            <td style="text-align:center"><?= $tdES ?: '-' ?></td>
            <td style="text-align:center">-</td>
          </tr>
          <?php endforeach; ?>
          <tr class="tot-row">
            <td colspan="5">TOTAL</td>
            <td><?= $tCmC ?: '' ?></td><td><?= $tTdC ?: '' ?></td><td><?= $tTpC ?: '' ?></td>
            <td><?= $tCmE ?: '' ?></td><td><?= $tTdE ?: '' ?></td><td></td>
          </tr>
        </tbody>
      </table>

      <!-- Signatures -->
      <div style="margin-top:16px">
        <div style="display:flex;justify-content:space-between;margin-bottom:6px;font-size:9pt">
          <span>Ouagadougou, le <?= date('d/m/Y') ?></span>
          <span style="font-weight:600">Vu et approuvé par</span>
        </div>
        <table style="width:100%;border-collapse:collapse">
          <tr>
            <?php
            // Construire un index rôle → validation en cherchant dans TOUTES les fiches
            $sigHistoMap = [];
            foreach ($fiches as $_sf) {
                foreach ($historique[(int)$_sf['id']] ?? [] as $_hh) {
                    $r = $_hh['etape_role'] ?? $_hh['role'] ?? '';
                    if ($r && ($_hh['decision']??'') === 'valide' && !isset($sigHistoMap[$r])) {
                        $sigHistoMap[$r] = $_hh;
                    }
                }
            }
            foreach ($sigActors as $actor):
              $vSig = $sigHistoMap[$actor['role']] ?? null;
            ?>
            <td style="width:25%;text-align:center;vertical-align:top;padding:6px 8px">
              <div style="font-weight:700;font-size:9.5pt;text-decoration:underline;margin-bottom:8px">
                <?= Security::e($actor['titre']) ?>
              </div>
              <?php if ($vSig): ?>
                <div style="color:#1a6b1a;font-size:9pt;text-align:center">
                  ✔ <?= Security::e($vSig['valideur_nom'] ?? '') ?><br>
                  <span style="font-size:8pt">Le <?= date('d/m/Y', strtotime($vSig['created_at'])) ?></span>
                </div>
              <?php else: ?>
                <div style="border-bottom:1px solid #000;margin:22px 10px 3px"></div>
                <div style="text-align:center;font-size:8pt;color:#666">Signature &amp; cachet</div>
              <?php endif; ?>
            </td>
            <?php endforeach; ?>
          </tr>
        </table>
      </div>

      <!-- Notes -->
      <div style="margin-top:12px;font-size:7.5pt;border-top:1px solid #aaa;padding-top:4px;color:#555;line-height:1.6">
        Calculer le volume horaire sans convertir les TD et les TP en heures de cours théoriques.
        NTC = nombre total de crédits.
      </div>

    </div><!-- /fiche-print-wrapper suivi <?= $semSuivi ?> -->

    <?php endforeach; // foreach semSuivi ?>

    <?php
    $bodyContent = ob_get_clean();
    $title = 'Fiche — ' . $ens['nom'];
    ob_start(); require __DIR__ . '/templates/layout.php'; echo ob_get_clean();
    exit;
}

// ── Vue principale : liste des enseignants ──────────────────
$filtreStatut = in_array($_GET['statut'] ?? '', ['en_attente','valide','rejete','tous'], true)
    ? $_GET['statut'] : 'en_attente';
$filtreDept = Security::sanitizeText($_GET['dept'] ?? '', 100);
$filtreEtab = Security::sanitizeText($_GET['etab'] ?? '', 150);
$filtreQ    = Security::sanitizeText($_GET['q']    ?? '', 100);

$filters = [
    'statut'      => $filtreStatut,
    'departement' => $filtreDept,
    'etab'        => $filtreEtab,
    'q'           => $filtreQ,
];
$enseignants = $repo->getEnseignantsPourPortail($filters);
$total = count($enseignants);

// Stats
$statsVol = method_exists($repo, 'getStatsVolumes')
    ? $repo->getStatsVolumes() : ['dept'=>[],'etab'=>[]];

ob_start();
?>

<!-- Hero -->
<div class="page-hero">
  <div>
    <h1>Portail de validation</h1>
    <div class="subtitle">
      <strong><?= Security::e(Auth::userNom()) ?></strong>
      <?php if (Auth::userDept()): ?> · <?= Security::e(Auth::userDept()) ?>
      <?php elseif (Auth::userEtab()): ?> · <?= Security::e(Auth::userEtab()) ?>
      <?php endif; ?>
    </div>
  </div>
  <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
    <span class="badge" style="background:<?= $rc['bg'] ?>;color:<?= $rc['txt'] ?>;font-size:12px;padding:5px 12px">
      <?= Security::e($roleLabel) ?>
    </span>
    <?php if ($role === 'dei'): ?>
    <a href="admin.php" class="btn btn-sm" style="background:rgba(255,255,255,.15);color:#fff;border-color:rgba(255,255,255,.4)">Administration</a>
    <a href="admin_etabs.php" style="color:#fff;opacity:.85;font-size:11px">🏛️ Étab./Dép.</a>
    <a href="admin_utilisateurs.php" class="btn btn-sm" style="background:rgba(255,255,255,.15);color:#fff;border-color:rgba(255,255,255,.4)">Utilisateurs</a>
    <a href="vp_eip_nomination.php" class="btn btn-sm" style="background:rgba(255,255,255,.15);color:#fff;border-color:rgba(255,255,255,.4)">📋 Nominations</a>
    <?php endif; ?>
    <a href="portail.php?logout=1" class="btn btn-sm" style="background:rgba(255,255,255,.15);color:#fff;border-color:rgba(255,255,255,.4)">Déconnexion</a>
  </div>
</div>

<!-- Stats globales (masquées pour VP EIP) -->
<?php if ($role !== 'vp_eip'): ?>
<div class="stat-grid">
  <div class="stat"><div class="stat-label">Enseignants (périmètre)</div><div class="stat-val"><?= $total ?></div></div>
  <div class="stat"><div class="stat-label">À traiter</div><div class="stat-val" style="color:var(--warn)"><?= (int)($stats['a_traiter']??0) ?></div></div>
  <div class="stat"><div class="stat-label">Validées (mon étape)</div><div class="stat-val" style="color:var(--ujkz-vert)"><?= (int)($stats['validees']??0) ?></div></div>
  <div class="stat"><div class="stat-label">Complètement validées</div><div class="stat-val" style="color:var(--ujkz-vert)"><?= (int)($stats['completement_validees']??0) ?></div></div>
</div>
<?php endif; ?>

<!-- ══ SECTION VP EIP : FICHES VACATAIRES VALIDÉES ══ -->
<?php if ($role === 'vp_eip'): ?>
<div class="card" style="margin-bottom:1.5rem">
  <div style="background:#F3E5F5;padding:15px;border-radius:8px 8px 0 0;border-bottom:2px solid #6A1B9A">
    <h2 style="font-size:16px;font-weight:700;color:#6A1B9A;margin:0">📋 Fiches Programmatiques de Vacataires Validées</h2>
    <div style="font-size:12px;color:#6A1B9A;margin-top:5px">Consultation et impression uniquement (aucune validation à faire)</div>
  </div>
  
  <?php
  // Fonction helper pour l'échappement HTML
  $e = function($v){ return Security::e($v); };
  
  // Récupérer les enseignants avec fiches vacataires validées par DEI
  $pdo = Database::getInstance();
  $stmtVacataires = $pdo->prepare(
    "SELECT DISTINCT 
        e.id, e.nom, e.prenom, e.matricule, e.grade, e.etab_beneficiaire,
        COUNT(f.id) AS nb_fiches,
        MAX(f.annee_academique) AS derniere_annee
     FROM enseignants e
     JOIN fiches f ON f.enseignant_id = e.id
     WHERE f.type_workflow = 'VACATAIRE' 
       AND f.statut_dei = 'valide'
       AND f.statut = 'validee'
     GROUP BY e.id
     ORDER BY e.nom, e.prenom"
  );
  $stmtVacataires->execute();
  $vacataires = $stmtVacataires->fetchAll();
  
  if (empty($vacataires)): ?>
  <div style="padding:30px;text-align:center;color:#999">
    <div style="font-size:48px;margin-bottom:15px">📭</div>
    <div style="font-size:14px">Aucune fiche de vacataire validée pour le moment</div>
  </div>
  
  <?php else: ?>
  <div style="padding:15px">
    <?php foreach ($vacataires as $vac): 
      $estabNom = '';
      if (!empty($vac['etab_beneficiaire'])) {
        $stEtab = $pdo->prepare("SELECT nom FROM etablissements WHERE id = ? LIMIT 1");
        $stEtab->execute([$vac['etab_beneficiaire']]);
        $etab = $stEtab->fetch();
        $estabNom = $etab['nom'] ?? '';
      }
    ?>
    <div style="border:1px solid #ddd;border-radius:6px;padding:15px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center">
      <div style="flex:1">
        <div style="font-weight:600;font-size:14px;color:#333;margin-bottom:5px">
          <?= $e(strtoupper($vac['nom'])) ?> <?= $e($vac['prenom']) ?>
        </div>
        <div style="font-size:12px;color:#666;margin-bottom:3px">
          <strong>Grade :</strong> <?= $e($vac['grade'] ?? '—') ?> | 
          <strong>Matricule :</strong> <?= $e($vac['matricule']) ?>
        </div>
        <div style="font-size:12px;color:#666;margin-bottom:3px">
          <strong>Établissement :</strong> <?= $e($estabNom) ?>
        </div>
        <div style="font-size:11px;color:#999">
          <?= $vac['nb_fiches'] ?> fiche(s) validée(s) — Année : <?= $e($vac['derniere_annee']) ?>
        </div>
      </div>
      <div style="display:flex;gap:8px;margin-left:15px">
        <a href="portail.php?ens=<?= (int)$vac['id'] ?>&view=1" 
           class="btn btn-sm" style="background:#17a2b8;color:white;padding:6px 12px;text-decoration:none">
          📋 Voir fiche
        </a>
        <a href="dossier_vacataire.php?ens_id=<?= (int)$vac['id'] ?>&annee=<?= urlencode($vac['derniere_annee']) ?>" 
           class="btn btn-sm" style="background:#6A1B9A;color:white;padding:6px 12px;text-decoration:none">
          📁 Dossier
        </a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Export Excel (DEI seulement) -->
<?php if (($authRole ?? '') === 'dei'): ?>
<div style="margin-bottom:1rem;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
  <strong style="font-size:13px">Export Excel :</strong>
  <a href="export_excel.php" class="btn btn-sm btn-outline-green" target="_blank">
    📥 Tous les enseignants
  </a>
  <?php foreach ($etablissementsPortail as $etabEx): ?>
  <a href="export_excel.php?etab=<?= urlencode($etabEx) ?>" class="btn btn-sm"
     style="font-size:11px" target="_blank">
    <?= Security::e(explode(' —', $etabEx)[0]) ?>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Filtres et Liste enseignants (masqués pour VP EIP) -->
<?php if ($role !== 'vp_eip'): ?>

<!-- Filtres -->
<div class="card" style="padding:1rem 1.5rem;margin-bottom:1rem">
  <!-- Boutons statut : liens simples (pas de form) pour éviter conflit hidden statut -->
  <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:.75rem">
    <span style="font-size:12px;font-weight:600;color:var(--gray-600)">Statut :</span>
    <?php
    foreach (['en_attente'=>'À traiter','valide'=>'Validées','rejete'=>'Rejetées','tous'=>'Toutes'] as $v=>$l):
      $extra = $filtreDept ? '&dept='.urlencode($filtreDept) : '';
      $extra .= $filtreEtab ? '&etab='.urlencode($filtreEtab) : '';
      $extra .= $filtreQ    ? '&q='.urlencode($filtreQ)       : '';
      $cls = $filtreStatut===$v ? 'btn btn-sm btn-primary' : 'btn btn-sm';
    ?>
    <a href="portail.php?statut=<?= $v ?><?= $extra ?>" class="<?= $cls ?>"><?= $l ?></a>
    <?php endforeach; ?>
  </div>

  <!-- Formulaire filtres dept/etab/q — statut transmis via hidden sans conflit -->
  <form method="GET">
    <input type="hidden" name="statut" value="<?= Security::e($filtreStatut) ?>">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <div>
        <label style="font-size:11px;font-weight:600;margin-bottom:3px;display:block">Département</label>
        <input type="text" name="dept" list="dl-dept-portail" value="<?= Security::e($filtreDept) ?>"
               placeholder="Tous" style="width:200px;font-size:13px;padding:6px 10px" autocomplete="off">
        <datalist id="dl-dept-portail">
          <?php foreach ($config['departements'] ?? [] as $dp): ?>
          <option value="<?= Security::e($dp) ?>">
          <?php endforeach; ?>
        </datalist>
      </div>
      <div>
        <label style="font-size:11px;font-weight:600;margin-bottom:3px;display:block">Établissement</label>
        <input type="text" name="etab" list="dl-etab-portail" value="<?= Security::e($filtreEtab) ?>"
               placeholder="Tous" style="width:220px;font-size:13px;padding:6px 10px" autocomplete="off">
        <datalist id="dl-etab-portail">
          <?php foreach ($etablissementsPortail ?? [] as $et): ?>
          <option value="<?= Security::e($et) ?>">
          <?php endforeach; ?>
        </datalist>
      </div>
      <div>
        <label style="font-size:11px;font-weight:600;margin-bottom:3px;display:block">Recherche</label>
        <input type="text" name="q" value="<?= Security::e($filtreQ) ?>"
               placeholder="Nom, matricule…" style="width:170px;font-size:13px;padding:6px 10px">
      </div>
      <div style="display:flex;gap:6px;align-items:flex-end">
        <button type="submit" class="btn btn-sm btn-primary">🔍 Filtrer</button>
        <?php if ($filtreDept || $filtreEtab || $filtreQ): ?>
        <a href="portail.php?statut=<?= $filtreStatut ?>" class="btn btn-sm">✕ Effacer</a>
        <?php endif; ?>
      </div>
    </div>
  </form>
  <div style="margin-top:.6rem;font-size:12px;color:var(--gray-600)">
    <?= $total ?> enseignant(s) trouvé(s)
  </div>
</div>

<!-- Liste enseignants -->
<div class="card" style="padding:0;overflow-x:auto">
<?php if (empty($enseignants)): ?>
<div style="padding:3rem;text-align:center;color:var(--gray-400)">
  <div style="font-size:2.5rem;margin-bottom:.75rem">✅</div>
  <div style="font-weight:600;font-size:15px">Aucun enseignant dans cette catégorie</div>
</div>
<?php else: ?>
<table class="table-ujkz">
  <thead>
    <tr>
      <th style="width:210px">Enseignant</th>
      <th>Établissement / Département</th>
      <th style="width:80px;text-align:center">Cours</th>
      <th style="width:80px;text-align:center">Attente</th>
      <th style="width:80px;text-align:center;font-size:11px">Chef Dép.</th>
      <th style="width:80px;text-align:center;font-size:11px">Dir. Adj.</th>
      <th style="width:80px;text-align:center;font-size:11px">Directeur</th>
      <th style="width:68px;text-align:center;font-size:11px">DEI</th>
      <th style="width:140px;text-align:center;font-size:11px">État</th>
      <th style="width:130px">Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php
  $stCls   = ['en_attente'=>'badge-or','valide'=>'badge-green','rejete'=>'badge-red'];
  $stIcons = ['en_attente'=>'⏳','valide'=>'✓','rejete'=>'✕'];

  // Statuts d'étapes déjà intégrés dans la requête (pas de N+1)
  foreach ($enseignants as $ens):
    // Priorité: rejete > en_attente > valide
    $etapeStatuts = [
        'statut_chef'    => $ens['st_chef']     ?? 'en_attente',
        'statut_dir_adj' => $ens['st_dir_adj']  ?? 'en_attente',
        'statut_dir'     => $ens['st_dir']      ?? 'en_attente',
        'statut_dei'     => $ens['st_dei']      ?? 'en_attente',
    ];
  ?>
  <tr>
    <td>
      <div style="font-weight:600;font-size:13px"><?= Security::e(trim($ens['nom'].' '.($ens['prenom']??''))) ?></div>
      <div style="font-size:11px;color:var(--gray-600)"><?= Security::e($ens['matricule']) ?></div>
      <div style="font-size:11px;color:var(--gray-600)"><?= Security::e($ens['grade']??'') ?></div>
    </td>
    <td>
      <div style="font-size:13px;color:var(--ujkz-vert-dk);font-weight:500">
        <?= Security::e($ens['etab_beneficiaire']??'—') ?>
      </div>
      <div style="font-size:11px;color:var(--gray-600)"><?= Security::e($ens['departement']??'') ?></div>
    </td>
    <td style="text-align:center">
      <span class="badge badge-info"><?= (int)$ens['nb_fiches'] ?></span>
    </td>
    <td style="text-align:center">
      <?php if ((int)$ens['nb_attente'] > 0): ?>
      <span class="badge badge-or"><?= (int)$ens['nb_attente'] ?></span>
      <?php else: ?>
      <span style="color:var(--gray-400)">—</span>
      <?php endif; ?>
    </td>
    <?php foreach (['statut_chef','statut_dir_adj','statut_dir','statut_dei'] as $col): ?>
    <td style="text-align:center">
      <span class="badge <?= $stCls[$etapeStatuts[$col]] ?? 'badge-gray' ?>" style="font-size:10px">
        <?= $stIcons[$etapeStatuts[$col]] ?? '?' ?>
      </span>
    </td>
    <?php endforeach; ?>
    <td style="text-align:center">
      <?php
      // Construire un pseudo-fiche pour statutEtapeLabel depuis les statuts agrégés
      $pseudoFiche = [
          'statut'        => $ens['statut_global'] ?? 'en_attente',
          'type_workflow' => $ens['type_workflow']  ?? 'IESR_UJKZ',
          'statut_chef'    => $ens['st_chef']    ?? 'en_attente',
          'statut_dir_adj' => $ens['st_dir_adj'] ?? 'en_attente',
          'statut_dir'     => $ens['st_dir']     ?? 'en_attente',
          'statut_dei'     => $ens['st_dei']     ?? 'en_attente',
          'statut_vp_eip'  => $ens['st_vp_eip'] ?? 'non_requis',
      ];
      if (!function_exists('statutEtapeLabel')) {
      function statutEtapeLabel(array $f): array {
          if (($f['statut'] ?? '') === 'rejetee') {
              if (($f['statut_dei']     ?? '') === 'rejete') return ['label'=>'Rejetée — DEI',         'class'=>'badge-red'];
              // NOTE : VP_EIP n'est PAS utilisé pour VACATAIRE
              if (($f['statut_dir']     ?? '') === 'rejete') return ['label'=>'Rejetée — Directeur',    'class'=>'badge-red'];
              if (($f['statut_dir_adj'] ?? '') === 'rejete') return ['label'=>'Rejetée — Dir. adjoint', 'class'=>'badge-red'];
              if (($f['statut_chef']    ?? '') === 'rejete') return ['label'=>'Rejetée — Chef dép.',    'class'=>'badge-red'];
              return ['label'=>'Rejetée', 'class'=>'badge-red'];
          }
          if (($f['statut'] ?? '') === 'validee') return ['label'=>'Validée ✓', 'class'=>'badge-green'];
          if (($f['statut_chef']    ?? 'en_attente') === 'en_attente') return ['label'=>'En attente — Chef', 'class'=>'badge-or'];
          if (($f['statut_dir_adj'] ?? 'en_attente') === 'en_attente') return ['label'=>'En attente — Dir.adj.', 'class'=>'badge-or'];
          if (($f['statut_dir']     ?? 'en_attente') === 'en_attente') return ['label'=>'En attente — Dir.', 'class'=>'badge-or'];
          if (($f['statut_dei']     ?? 'en_attente') === 'en_attente') return ['label'=>'En attente — DEI', 'class'=>'badge-or'];
          return ['label'=>'En attente', 'class'=>'badge-or'];
      }
      }
      $etapeEns = statutEtapeLabel($pseudoFiche);
      ?>
      <span class="badge <?= $etapeEns['class'] ?>" style="font-size:10px;white-space:nowrap">
        <?= Security::e($etapeEns['label']) ?>
      </span>
    </td>
    <td>
      <div class="btn-group">
        <a href="portail.php?ens=<?= (int)$ens['id'] ?>"
           class="btn btn-sm btn-primary">Voir fiche</a>
        <?php if (($ens['type_enseignant']??'') === 'vacataire'): ?>
        <a href="dossier_vacataire.php?ens_id=<?= (int)$ens['id'] ?>&annee=<?= urlencode($ens['annee_academique'] ?? $config['annee_academique']) ?>"
           class="btn btn-sm btn-gold" title="Dossier complet (fiche + documents + nomination)">
          📁 Dossier
        </a>
        <?php if (in_array($role, ['dei','vp_eip'], true)): ?>
          <?php
          // Vérifier si nomination validée (seulement pour DEI et VP EIP)
          $_pdoN = Database::getInstance();
          $stNomCheck = $_pdoN->prepare(
              "SELECT id FROM nominations WHERE enseignant_id=? AND annee_academique=? AND statut='valide' LIMIT 1"
          );
          $stNomCheck->execute([(int)$ens['id'], $ens['annee_academique'] ?? $config['annee_academique']]);
          $hasNomination = (bool)$stNomCheck->fetch();
          if ($hasNomination):
          ?>
          <a href="generer_nomination.php?ens_id=<?= (int)$ens['id'] ?>&annee=<?= urlencode($ens['annee_academique'] ?? $config['annee_academique']) ?>"
             target="_blank" class="btn btn-sm" style="background:#FFB300;color:#000;font-weight:600"
             title="Acte de nomination">
            📄 Acte
          </a>
          <?php endif; ?>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
</div>

<?php endif; // Fermeture du bloc if ($role !== 'vp_eip') ?>

<?php
$bodyContent = ob_get_clean();
$title = 'Portail de validation';
ob_start(); require __DIR__ . '/templates/layout.php'; echo ob_get_clean();
