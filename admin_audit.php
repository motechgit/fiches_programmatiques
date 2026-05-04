<?php
declare(strict_types=1);
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';

$config   = require __DIR__ . '/config/security.php';
$security = new Security($config);
$security->sendSecurityHeaders();
$security->startSecureSession();

if (empty($_SESSION['admin_authenticated'])) {
    header('Location: admin.php'); exit;
}

$pdo    = Database::getInstance();
$page   = max(1, (int)($_GET['page'] ?? 1));
$search = Security::sanitizeText($_GET['q'] ?? '', 60);
$perPage= 50;
$offset = ($page - 1) * $perPage;

$where  = $search ? "WHERE action LIKE ? OR matricule LIKE ? OR detail LIKE ?" : '';
$params = $search ? ["%$search%", "%$search%", "%$search%"] : [];

$total = (int)$pdo->prepare("SELECT COUNT(*) FROM audit_log $where")->execute($params) ? 0 : 0;
$stmt  = $pdo->prepare("SELECT COUNT(*) FROM audit_log $where");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$stmt2 = $pdo->prepare("SELECT * FROM audit_log $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$stmt2->execute($params);
$logs  = $stmt2->fetchAll();

ob_start(); ?>
<div class="page-hero">
  <div>
    <h1>📋 Journal d'audit</h1>
    <div class="subtitle"><?= $total ?> entrées enregistrées</div>
  </div>
  <a href="admin.php" class="btn btn-gold">← Administration</a>
</div>

<form method="GET" class="card" style="padding:1rem 1.5rem">
  <div style="display:flex;gap:10px">
    <input type="text" name="q" value="<?= Security::e($search) ?>"
           placeholder="Rechercher dans les logs (action, matricule, détail)…" style="flex:1">
    <button type="submit" class="btn btn-primary">Rechercher</button>
    <?php if ($search): ?><a href="admin_audit.php" class="btn">Effacer</a><?php endif; ?>
  </div>
</form>

<div class="card" style="padding:0;overflow-x:auto">
<table class="table-ujkz">
  <thead>
    <tr>
      <th style="width:160px">Date / Heure</th>
      <th>Action</th>
      <th>Matricule</th>
      <th>IP</th>
      <th>Détail</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($logs)): ?>
  <tr><td colspan="5" style="padding:2rem;text-align:center;color:var(--gray-400)">Aucune entrée.</td></tr>
  <?php endif; ?>
  <?php foreach ($logs as $log): ?>
  <?php
    if ((strpos($log['action'],'fail') !== false) || (strpos($log['action'],'error') !== false)) {
        $actionColor = 'var(--danger)';
    } elseif ((strpos($log['action'],'delete') !== false) || (strpos($log['action'],'suppr') !== false)) {
        $actionColor = 'var(--warn)';
    } elseif ((strpos($log['action'],'create') !== false) || (strpos($log['action'],'submit') !== false)) {
        $actionColor = 'var(--ujkz-vert)';
    } else {
        $actionColor = 'var(--gray-600)';
    }
  ?>
  <tr>
    <td style="font-size:12px;color:var(--gray-600);white-space:nowrap">
      <?= Security::e(date('d/m/Y H:i:s', strtotime($log['created_at']))) ?>
    </td>
    <td><code style="font-size:12px;color:<?= $actionColor ?>"><?= Security::e($log['action']) ?></code></td>
    <td style="font-size:12px"><?= Security::e($log['matricule'] ?? '—') ?></td>
    <td style="font-size:12px;color:var(--gray-600)"><?= Security::e($log['ip_address'] ?? '—') ?></td>
    <td style="font-size:12px;color:var(--gray-600)"><?= Security::e(mb_substr($log['detail'] ?? '', 0, 80)) ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div style="display:flex;gap:6px;justify-content:center;margin-top:1rem;flex-wrap:wrap">
  <?php for ($p = 1; $p <= $totalPages; $p++): ?>
  <a href="admin_audit.php?page=<?= $p ?><?= $search ? '&q='.urlencode($search) : '' ?>"
     class="btn btn-sm <?= $p === $page ? 'btn-primary' : '' ?>"><?= $p ?></a>
  <?php endfor; ?>
</div>
<?php endif; ?>

<?php
$bodyContent = ob_get_clean();
ob_start(); require __DIR__ . '/templates/layout.php'; echo ob_get_clean();
