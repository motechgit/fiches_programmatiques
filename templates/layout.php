<?php
// ============================================================
// templates/layout.php — Charte graphique UJKZ
// Couleurs : Vert UJKZ #006837 | Or #FFB300 | Blanc #FFFFFF
// ============================================================
declare(strict_types=1);
$e = function($v) { return Security::e($v); };
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $e($title ?? 'Fiches Programmatiques') ?> — UJKZ</title>
<style>
/* ═══════════════════════════════════════════════
   UJKZ — Charte graphique
   Vert institutionnel : #006837
   Or / accent         : #FFB300
   Vert sombre         : #004D27
   Vert clair          : #E8F5EE
   ═══════════════════════════════════════════════ */
:root {
  --ujkz-vert:    #006837;
  --ujkz-vert-dk: #004D27;
  --ujkz-vert-lt: #E8F5EE;
  --ujkz-or:      #FFB300;
  --ujkz-or-lt:   #FFF8E1;
  --white:        #FFFFFF;
  --gray-50:      #F8F9FA;
  --gray-100:     #F0F2F0;
  --gray-200:     #E2E6E2;
  --gray-400:     #9CA89C;
  --gray-600:     #5A6A5A;
  --gray-800:     #1A2E1A;
  --danger:       #C62828;
  --danger-lt:    #FEECEC;
  --warn:         #E65100;
  --warn-lt:      #FFF3E0;
  --success:      #2E7D32;
  --success-lt:   #E8F5E9;
  --info:         #0277BD;
  --info-lt:      #E3F2FD;
  --shadow-sm:    0 1px 3px rgba(0,104,55,.10), 0 1px 2px rgba(0,0,0,.06);
  --shadow:       0 4px 12px rgba(0,104,55,.12), 0 2px 4px rgba(0,0,0,.08);
  --radius:       10px;
  --radius-sm:    6px;
  --font:         'Segoe UI', system-ui, -apple-system, sans-serif;
  /* ── Alias rétro-compatibilité ── */
  --muted:        var(--gray-400);
  --bg:           var(--gray-50);
  --border:       var(--gray-200);
  --text:         var(--gray-800);
  --accent:       var(--ujkz-vert);
}
*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
html { scroll-behavior:smooth; }
body {
  font-family: var(--font);
  font-size: 14px;
  line-height: 1.6;
  color: var(--gray-800);
  background: var(--gray-100);
  min-height: 100vh;
}

/* ─── TOPBAR UJKZ ─── */
.site-header {
  background: var(--ujkz-vert);
  box-shadow: 0 2px 8px rgba(0,0,0,.25);
  position: sticky; top:0; z-index:100;
}
.site-header-inner {
  max-width: 1100px; margin:0 auto;
  display:flex; align-items:center; gap:16px;
  padding: 0 1.5rem;
  height: 56px;
}
.site-logo {
  display:flex; align-items:center; gap:10px;
  text-decoration:none; flex-shrink:0;
}
.site-logo-badge {
  width:38px; height:38px;
  background: var(--ujkz-or);
  border-radius:8px;
  display:flex; align-items:center; justify-content:center;
  font-weight:800; font-size:16px; color:var(--ujkz-vert-dk);
  letter-spacing:-.5px; flex-shrink:0;
}
.site-logo-text { color:var(--white); line-height:1.2; }
.site-logo-text strong { font-size:13px; font-weight:700; display:block; }
.site-logo-text small { font-size:11px; opacity:.8; font-weight:400; }
.site-header-spacer { flex:1; }
.site-header-meta {
  font-size:12px; color:rgba(255,255,255,.75);
  text-align:right; line-height:1.3;
}
.site-header-meta strong { color:var(--ujkz-or); display:block; }

/* ─── BANDEAU SOUS L'EN-TÊTE ─── */
.site-subbar {
  background: var(--ujkz-vert-dk);
  border-bottom: 2px solid var(--ujkz-or);
}
.site-subbar-inner {
  max-width:1100px; margin:0 auto;
  padding: 0 1.5rem;
  display:flex; gap:2px; align-items:stretch;
  height: 38px;
}
.subnav-link {
  display:flex; align-items:center;
  padding: 0 14px;
  font-size:12px; font-weight:500;
  color: rgba(255,255,255,.80);
  text-decoration:none;
  border-bottom: 2px solid transparent;
  transition: color .15s, border-color .15s;
  white-space:nowrap;
}
.subnav-link:hover { color:var(--white); border-color:var(--ujkz-or); }
.subnav-link.active { color:var(--ujkz-or); border-color:var(--ujkz-or); }

/* ─── LAYOUT PRINCIPAL ─── */
.site-main { max-width:1100px; margin:0 auto; padding:1.5rem; }

/* ─── BREADCRUMB ─── */
.breadcrumb {
  font-size:12px; color:var(--gray-600);
  display:flex; gap:6px; align-items:center;
  margin-bottom:1.25rem;
}
.breadcrumb a { color:var(--ujkz-vert); text-decoration:none; }
.breadcrumb a:hover { text-decoration:underline; }
.breadcrumb-sep { color:var(--gray-400); }

/* ─── HERO PAGE ─── */
.page-hero {
  background: linear-gradient(135deg, var(--ujkz-vert) 0%, var(--ujkz-vert-dk) 100%);
  border-radius: var(--radius);
  padding: 1.5rem 2rem;
  margin-bottom: 1.5rem;
  color: var(--white);
  display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;
  box-shadow: var(--shadow);
}
.page-hero h1 { font-size:20px; font-weight:700; margin-bottom:2px; }
.page-hero .subtitle { font-size:13px; opacity:.80; }
.page-hero-badge {
  background: rgba(255,179,0,.20);
  border:1px solid rgba(255,179,0,.50);
  color: var(--ujkz-or);
  padding: 5px 14px; border-radius:20px;
  font-size:12px; font-weight:600;
  white-space:nowrap;
}

/* ─── CARDS ─── */
.card {
  background: var(--white);
  border: 1px solid var(--gray-200);
  border-radius: var(--radius);
  padding: 1.5rem;
  margin-bottom: 1.25rem;
  box-shadow: var(--shadow-sm);
}
.card-header {
  display:flex; align-items:center; justify-content:space-between;
  gap:12px; flex-wrap:wrap;
  padding-bottom: .875rem;
  margin-bottom: 1rem;
  border-bottom: 1px solid var(--gray-200);
}
.card-title {
  font-size:15px; font-weight:600; color:var(--ujkz-vert-dk);
  display:flex; align-items:center; gap:8px;
}
.card-title::before {
  content:''; display:inline-block;
  width:3px; height:18px;
  background:var(--ujkz-or); border-radius:2px;
}
.card-sub { font-size:13px; color:var(--gray-600); margin-top:4px; }

/* ─── STATS GRID ─── */
.stat-grid {
  display:grid;
  grid-template-columns: repeat(auto-fit, minmax(120px,1fr));
  gap: 12px;
  margin-bottom: 1.25rem;
}
.stat {
  background: var(--white);
  border: 1px solid var(--gray-200);
  border-radius: var(--radius);
  padding: 1rem 1.25rem;
  text-align:center;
  box-shadow: var(--shadow-sm);
  transition: transform .15s;
}
.stat:hover { transform:translateY(-2px); box-shadow:var(--shadow); }
.stat-label { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:var(--gray-600); margin-bottom:4px; }
.stat-val { font-size:26px; font-weight:700; color:var(--ujkz-vert); line-height:1; }

/* ─── FORMULAIRES ─── */
label {
  display:block; font-size:13px; font-weight:600;
  color:var(--gray-800); margin-bottom:5px; margin-top:14px;
}
input[type=text], input[type=email], input[type=password],
input[type=number], input[type=date], input[type=file],
select, textarea {
  width:100%; padding:9px 12px;
  border:1.5px solid var(--gray-200);
  border-radius:var(--radius-sm);
  font-size:14px; font-family:var(--font);
  color:var(--gray-800);
  background:var(--white);
  transition:border-color .15s, box-shadow .15s;
  outline:none;
}
input:focus, select:focus, textarea:focus {
  border-color:var(--ujkz-vert);
  box-shadow:0 0 0 3px rgba(0,104,55,.12);
}
input.error, select.error { border-color:var(--danger); background:var(--danger-lt); }
.err-text { font-size:12px; color:var(--danger); margin-top:3px; }
.hint-text { font-size:12px; color:var(--gray-600); margin-top:3px; }
textarea { resize:vertical; min-height:80px; }
.grid2 { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
@media(max-width:580px){ .grid2 { grid-template-columns:1fr; } }
.form-section {
  background:var(--gray-50);
  border:1px solid var(--gray-200);
  border-radius:var(--radius);
  padding:1.25rem 1.5rem;
  margin:1rem 0;
}
.form-section-title {
  font-size:13px; font-weight:700;
  color:var(--ujkz-vert-dk);
  text-transform:uppercase; letter-spacing:.05em;
  margin-bottom:1rem;
  display:flex; align-items:center; gap:6px;
}
.form-section-title::before {
  content:''; display:inline-block;
  width:14px; height:3px;
  background:var(--ujkz-or); border-radius:2px;
}

/* ─── BOUTONS ─── */
.btn {
  display:inline-flex; align-items:center; gap:6px;
  padding:8px 18px;
  border:1.5px solid var(--gray-200);
  border-radius:var(--radius-sm);
  font-size:13px; font-weight:500;
  font-family:var(--font);
  color:var(--gray-800);
  background:var(--white);
  cursor:pointer; text-decoration:none;
  transition:all .15s; white-space:nowrap;
}
.btn:hover { background:var(--gray-100); border-color:var(--gray-400); }
.btn-primary {
  background:var(--ujkz-vert); color:var(--white);
  border-color:var(--ujkz-vert);
}
.btn-primary:hover { background:var(--ujkz-vert-dk); border-color:var(--ujkz-vert-dk); color:var(--white); }
.btn-gold {
  background:var(--ujkz-or); color:var(--ujkz-vert-dk);
  border-color:var(--ujkz-or); font-weight:700;
}
.btn-gold:hover { background:#E6A000; border-color:#E6A000; color:var(--ujkz-vert-dk); }
.btn-danger { background:var(--danger); color:var(--white); border-color:var(--danger); }
.btn-danger:hover { background:#A51E1E; color:var(--white); }
.btn-sm { padding:5px 12px; font-size:12px; border-radius:5px; }
.btn-xs { padding:3px 9px; font-size:11px; border-radius:4px; }
.btn-outline-green { border-color:var(--ujkz-vert); color:var(--ujkz-vert); background:transparent; }
.btn-outline-green:hover { background:var(--ujkz-vert-lt); }
.btn-group { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }

/* ─── BADGES ─── */
.badge {
  display:inline-flex; align-items:center;
  padding:3px 10px; border-radius:20px;
  font-size:11px; font-weight:600; white-space:nowrap;
}
.badge-green   { background:var(--ujkz-vert-lt); color:var(--ujkz-vert-dk); border:1px solid #A8D5BC; }
.badge-or      { background:var(--ujkz-or-lt);   color:#7A5100;             border:1px solid #FFCC80; }
.badge-red     { background:var(--danger-lt);     color:var(--danger);       border:1px solid #FFCDD2; }
.badge-gray    { background:var(--gray-100);      color:var(--gray-600);     border:1px solid var(--gray-200); }
.badge-info    { background:var(--info-lt);       color:var(--info);         border:1px solid #BBDEFB; }
/* Compat ancien code */
.badge-success { background:var(--ujkz-vert-lt); color:var(--ujkz-vert-dk); }
.badge-warn    { background:var(--warn-lt);       color:var(--warn); }
.badge-danger  { background:var(--danger-lt);     color:var(--danger); }
.badge-info    { background:var(--info-lt);       color:var(--info); }

/* ─── ALERTES ─── */
.alert {
  padding:.875rem 1.125rem; border-radius:var(--radius-sm);
  font-size:13px; margin-bottom:1rem;
  display:flex; align-items:flex-start; gap:10px;
  border:1px solid transparent;
}
.alert::before { font-size:16px; flex-shrink:0; line-height:1.4; }
.alert-success { background:var(--success-lt); color:var(--success); border-color:#A5D6A7; }
.alert-success::before { content:'✓'; }
.alert-danger  { background:var(--danger-lt);  color:var(--danger);  border-color:#FFCDD2; }
.alert-danger::before  { content:'✕'; }
.alert-warn    { background:var(--warn-lt);    color:var(--warn);    border-color:#FFCC80; }
.alert-warn::before    { content:'⚠'; }
.alert-info    { background:var(--info-lt);    color:var(--info);    border-color:#BBDEFB; }
.alert-info::before    { content:'ℹ'; }

/* ─── STEPPER ─── */
.steps {
  display:flex; align-items:center; gap:0;
  margin-bottom:1.5rem;
  background:var(--white);
  border:1px solid var(--gray-200);
  border-radius:var(--radius); padding:.875rem 1.25rem;
  box-shadow:var(--shadow-sm);
}
.step {
  display:flex; align-items:center; gap:8px;
  font-size:13px; font-weight:500; color:var(--gray-400);
}
.step.active { color:var(--ujkz-vert); }
.step.done   { color:var(--ujkz-vert); }
.step-num {
  width:26px; height:26px; border-radius:50%;
  display:flex; align-items:center; justify-content:center;
  font-size:12px; font-weight:700;
  background:var(--gray-200); color:var(--gray-600);
  flex-shrink:0;
}
.step.active .step-num { background:var(--ujkz-vert); color:var(--white); }
.step.done   .step-num { background:var(--ujkz-or); color:var(--ujkz-vert-dk); }
.step-sep { flex:1; height:2px; background:var(--gray-200); margin:0 12px; min-width:20px; }
.step.done ~ .step-sep { background:var(--ujkz-or); }

/* ─── TABLEAU FICHES ─── */
.fiche-row {
  display:flex; align-items:center; gap:12px;
  padding:12px 1.5rem;
  border-bottom:1px solid var(--gray-200);
  transition:background .12s;
}
.fiche-row:last-child { border-bottom:none; }
.fiche-row:hover { background:var(--ujkz-vert-lt); }
.fiche-title { font-weight:600; color:var(--ujkz-vert-dk); font-size:14px; }
.fiche-meta  { font-size:12px; color:var(--gray-600); margin-top:2px; line-height:1.5; }

/* ─── TABLES ─── */
.table-ujkz {
  width:100%; border-collapse:collapse; font-size:13px;
}
.table-ujkz th {
  padding:10px 14px; text-align:left; font-weight:600;
  background:var(--ujkz-vert-lt);
  color:var(--ujkz-vert-dk);
  border-bottom:2px solid var(--ujkz-vert);
  white-space:nowrap;
}
.table-ujkz td {
  padding:10px 14px;
  border-bottom:1px solid var(--gray-200);
  vertical-align:middle;
}
.table-ujkz tr:hover td { background:var(--gray-50); }
.table-ujkz tr:last-child td { border-bottom:none; }

/* ─── RECAP TABLE ─── */
.recap { width:100%; border-collapse:collapse; font-size:13px; }
.recap td { padding:6px 0; border-bottom:1px solid var(--gray-200); vertical-align:top; }
.recap td:first-child { width:200px; font-weight:500; color:var(--gray-600); padding-right:16px; }
.recap tr:last-child td { border-bottom:none; }

/* ─── AVATAR ─── */
.avatar {
  width:42px; height:42px; border-radius:50%;
  background:var(--ujkz-or); color:var(--ujkz-vert-dk);
  display:flex; align-items:center; justify-content:center;
  font-size:16px; font-weight:800; flex-shrink:0;
}

/* ─── TOPBAR PAGE ─── */
.topbar {
  display:flex; align-items:center; justify-content:space-between;
  gap:16px; flex-wrap:wrap;
  margin-bottom:1.5rem;
}
.topbar h1 { font-size:20px; font-weight:700; color:var(--ujkz-vert-dk); }

/* ─── LINK BOX ─── */
.link-box {
  display:block;
  background:var(--gray-50);
  border:1px solid var(--gray-200);
  border-radius:var(--radius-sm);
  padding:8px 12px;
  font-family:monospace; font-size:12px;
  color:var(--gray-600);
  word-break:break-all;
}

/* ─── PROGRESSION 4 ÉTAPES ─── */
.validation-steps {
  display:grid; grid-template-columns:repeat(4,1fr); gap:10px;
}
@media(max-width:650px){ .validation-steps { grid-template-columns:repeat(2,1fr); } }
.vstep {
  text-align:center; padding:.875rem .75rem;
  background:var(--gray-50);
  border:1px solid var(--gray-200);
  border-radius:var(--radius-sm);
}
.vstep-label { font-size:11px; color:var(--gray-600); margin-bottom:5px; font-weight:500; }
.vstep-badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:600; }
.vstep-en_attente .vstep-badge { background:var(--warn-lt);     color:var(--warn); }
.vstep-valide     .vstep-badge { background:var(--success-lt);  color:var(--success); }
.vstep-rejete     .vstep-badge { background:var(--danger-lt);   color:var(--danger); }

/* ─── FOOTER ─── */
.site-footer {
  margin-top:3rem;
  border-top:2px solid var(--ujkz-or);
  background:var(--ujkz-vert-dk);
  color:rgba(255,255,255,.70);
  font-size:12px; text-align:center;
  padding:.875rem 1.5rem;
}
.site-footer strong { color:var(--ujkz-or); }

/* ─── RESPONSIVE ─── */
@media(max-width:600px){
  .site-main { padding:1rem; }
  .card { padding:1.125rem; }
  .stat-val { font-size:22px; }
  .page-hero { padding:1.125rem 1.25rem; }
  .validation-steps { grid-template-columns:1fr 1fr; }
  .site-subbar-inner { gap:0; }
  .subnav-link { padding:0 10px; font-size:11px; }
}
</style>
</head>
<body>

<!-- ══ EN-TÊTE UJKZ ══ -->
<header class="site-header">
  <div class="site-header-inner">
    <a href="index.php" class="site-logo">
      <img src="logo_ujkz.jpg" alt="UJKZ" style="height:40px;width:40px;object-fit:contain;border-radius:4px">
      <div class="site-logo-text">
        <strong>Université Joseph KI-ZERBO</strong>
        <small>Fiches Programmatiques</small>
      </div>
    </a>
    <div class="site-header-spacer"></div>
    <div class="site-header-meta">
      <strong>Année académique</strong>
      <?php
        if (!isset($config)) {
            $cfgPath = __DIR__ . '/../config/security.php';
            $config  = file_exists($cfgPath) ? require $cfgPath : [];
        }
      ?><?= $e($config['annee_academique'] ?? '2024-2025') ?>
    </div>
  </div>
</header>

<!-- ══ CONTENU ══ -->
<main class="site-main">
<?= $bodyContent ?>
</main>

<!-- ══ PIED DE PAGE ══ -->
<footer class="site-footer">
  <strong>Université Joseph KI-ZERBO</strong> — Système de gestion des fiches programmatiques &nbsp;|&nbsp; <?= date('Y') ?>
</footer>

</body>
</html>
