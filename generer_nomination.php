<?php
// ============================================================
// generer_nomination.php — Acte de nomination vacataire
// Format officiel UJKZ — Impression navigateur → PDF
// ============================================================
declare(strict_types=1);
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';
if (!class_exists('App')) require_once __DIR__ . '/src/App.php';
App::configureErrorDisplay();
require_once __DIR__ . '/src/Auth.php';

$config  = require __DIR__ . '/config/security.php';
$security = new Security($config);
$security->sendSecurityHeaders();
$security->startSecureSession();

$_roleNom = Auth::userRole();
if (empty($_roleNom)) { header('Location: login.php'); exit; }
if (!in_array($_roleNom, ['vp_eip','dei'], true)) die('Accès refusé.');
// Renouveler user_since si besoin (compatibilité timeout)
if (empty($_SESSION['user_id']) && !empty($_SESSION['user_role'])) {
    $_SESSION['user_since'] = time();
}

$pdo     = Database::getInstance();
$ensId   = (int)($_GET['ens_id'] ?? 0);
$annee   = Security::sanitizeText($_GET['annee'] ?? $config['annee_academique'], 10);
$e       = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

// Charger enseignant
$stEns = $pdo->prepare("SELECT * FROM enseignants WHERE id=? LIMIT 1");
$stEns->execute([$ensId]);
$ens = $stEns->fetch();
if (!$ens) die('Enseignant introuvable.');

// Charger nomination + nom VP EIP
$stNom = $pdo->prepare(
    "SELECT n.*, u.nom AS vp_nom
     FROM nominations n
     LEFT JOIN utilisateurs u ON u.id = n.valide_par
     WHERE n.enseignant_id=? AND n.annee_academique=? AND n.statut='valide' LIMIT 1"
);
$stNom->execute([$ensId, $annee]);
$nomRow = $stNom->fetch();

// Charger les fiches VACATAIRE validées avec étab/dept
$stF = $pdo->prepare(
    "SELECT f.*, et.nom AS etab_nom, et.sigle AS etab_sigle,
            d.nom AS dept_nom, d.sigle AS dept_sigle
     FROM fiches f
     LEFT JOIN etablissements et ON et.id = f.etab_beneficiaire_fiche
     LEFT JOIN departements d ON d.id = f.dept_beneficiaire_fiche
     WHERE f.enseignant_id=? AND f.annee_academique=?
       AND f.type_workflow='VACATAIRE' AND f.statut='validee'
       AND f.is_encadrement = 0
     ORDER BY f.semestre, f.cours"
);
$stF->execute([$ensId, $annee]);
$fiches = $stF->fetchAll();

// Totaux
$totCm = array_sum(array_column($fiches, 'volume_cm'));
$totTd = array_sum(array_column($fiches, 'volume_td'));
$totTp = array_sum(array_column($fiches, 'volume_tp'));
// Total heures : CT + 0,5*TD + 0,5*TP selon le document de référence
$totH  = $totCm + $totTd + $totTp;

// Grouper par étab bénéficiaire pour le titre
$etablissementsBenef = [];
foreach ($fiches as $f) {
    $k = $f['etab_sigle'] ?: $f['etab_nom'] ?: '—';
    if (!in_array($k, $etablissementsBenef, true)) $etablissementsBenef[] = $k;
}

// Premier département mentionné (pour le titre de la décision)
$premierDept = $fiches[0]['dept_nom'] ?? '';
$premierEtab = $fiches[0]['etab_nom'] ?? '';
$premierEtabSigle = $fiches[0]['etab_sigle'] ?? '';

// Numéro de décision (année en cours)
$anneeNum  = date('Y');
$dateActe  = $nomRow && $nomRow['valide_le']
    ? date('d/m/Y', strtotime($nomRow['valide_le'])) : date('d/m/Y');
$dateActeJ = $nomRow && $nomRow['valide_le']
    ? date('d', strtotime($nomRow['valide_le'])) : date('d');
$dateActeM = $nomRow && $nomRow['valide_le']
    ? strftime_fr(date('m', strtotime($nomRow['valide_le']))) : strftime_fr(date('m'));
$dateActeA = $nomRow && $nomRow['valide_le']
    ? date('Y', strtotime($nomRow['valide_le'])) : date('Y');

// Convertir numéro de mois en nom
function strftime_fr(string $m): string {
    $mois = ['01'=>'janvier','02'=>'février','03'=>'mars','04'=>'avril','05'=>'mai',
             '06'=>'juin','07'=>'juillet','08'=>'août','09'=>'septembre',
             '10'=>'octobre','11'=>'novembre','12'=>'décembre'];
    return $mois[$m] ?? $m;
}

$nomComplet  = strtoupper($ens['nom']) . ' ' . $ens['prenom'];
$gradeEns    = $ens['grade'] ?? '';
$diplomeEns  = $ens['diplome'] ?? '';
$matriculeEns = $ens['matricule'] ?? '';

// VP EIP signataire
$vpNom = trim($nomRow['vp_nom'] ?? '');
if (!$vpNom) $vpNom = 'Pr …………………………';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Décision de nomination — <?= $e($nomComplet) ?></title>
<style>
  @page { size: A4; margin: 2cm 2cm 2cm 3cm; }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: 'Times New Roman', Times, serif;
    font-size: 12pt;
    color: #000;
    background: #fff;
    line-height: 1.4;
  }

  /* ── Barre de contrôle (non imprimée) ── */
  .ctrl-bar {
    background: #004D27;
    color: #fff;
    padding: 10px 20px;
    display: flex;
    gap: 12px;
    align-items: center;
    font-family: Arial, sans-serif;
    font-size: 13px;
    position: sticky;
    top: 0;
    z-index: 100;
  }
  .ctrl-bar button {
    background: #FFB300;
    color: #000;
    border: none;
    padding: 7px 18px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 700;
  }
  .ctrl-bar a { color: rgba(255,255,255,.8); text-decoration: none; font-size: 12px; }

  /* ── Page ── */
  .page {
    width: 21cm;
    min-height: 29.7cm;
    margin: 10px auto;
    padding: 2cm 2cm 2cm 3cm;
    background: #fff;
    box-shadow: 0 2px 8px rgba(0,0,0,.15);
  }

  /* ── En-tête ── */
  .entete {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 8pt;
  }
  .entete-gauche {
    font-size: 10pt;
    text-align: left;
    line-height: 1.5;
    max-width: 45%;
  }
  .entete-gauche .ministere {
    font-size: 9.5pt;
    text-transform: uppercase;
    font-weight: bold;
    border-bottom: none;
  }
  .entete-gauche .trait { border-bottom: 1px solid #000; margin: 2pt 0; }
  .entete-gauche .bold { font-weight: bold; }

  .entete-droite {
    font-size: 10pt;
    text-align: center;
    line-height: 1.6;
    min-width: 35%;
  }
  .entete-logo {
    text-align: center;
    padding: 0 10pt;
  }
  .entete-logo img.logo-img {
    width: 72pt;
    height: auto;
    display: block;
    margin: 0 auto;
  }

  .devise-droite { font-size: 10pt; }
  .etoiles { letter-spacing: 3px; font-size: 9pt; }

  /* ── Coordonnées ── */
  .coords {
    font-size: 8.5pt;
    margin-top: 4pt;
    line-height: 1.4;
  }

  /* ── Objet de la décision ── */
  .objet-decision {
    margin-top: 18pt;
    margin-left: 40%;
    font-size: 11pt;
    line-height: 1.5;
    text-align: left;
  }
  .num-decision { font-weight: normal; }

  /* ── Visa ── */
  .visa {
    margin: 16pt 0 10pt;
    font-size: 11pt;
    text-align: center;
  }

  /* ── Titre principal ── */
  .titre-principal {
    font-weight: bold;
    font-size: 12pt;
    text-align: center;
    text-decoration: underline;
    margin: 12pt 0 10pt;
    text-transform: uppercase;
  }

  /* ── Articles Vu ── */
  .vu-block { margin-bottom: 3pt; font-size: 11pt; }
  .vu-label { font-weight: bold; float: left; margin-right: 8pt; min-width: 20pt; }
  .vu-text { overflow: hidden; }

  /* ── Sur proposition ── */
  .sur-proposition {
    margin-top: 6pt;
    margin-bottom: 10pt;
    font-size: 11pt;
    text-indent: 0;
  }

  /* ── DECIDE ── */
  .decide-titre {
    text-align: center;
    font-weight: bold;
    font-size: 13pt;
    text-decoration: underline;
    margin: 14pt 0 10pt;
    text-transform: uppercase;
  }

  /* ── Articles ── */
  .article { margin-bottom: 8pt; font-size: 11pt; line-height: 1.5; }
  .article-label {
    font-weight: bold;
    float: left;
    min-width: 60pt;
    margin-right: 6pt;
  }
  .article-text { overflow: hidden; }

  /* ── Signatures ── */
  .signatures-block {
    margin-top: 24pt;
    text-align: right;
    font-size: 11pt;
  }
  .ville-date { margin-bottom: 16pt; }
  .delegataire { font-size: 10.5pt; line-height: 1.5; margin-bottom: 4pt; }
  .signataire { margin-top: 36pt; font-size: 11pt; }
  .signataire strong { font-weight: bold; }
  .signataire em { font-style: italic; }

  /* ── Ampliation ── */
  .ampliation {
    margin-top: 20pt;
    font-size: 10pt;
    line-height: 1.6;
  }
  .ampliation div { margin-left: 4pt; }

  /* ── Séparateur ── */
  hr.trait { border: none; border-top: 1px solid #000; margin: 6pt 0; }

  @media print {
    .ctrl-bar { display: none !important; }
    .page { box-shadow: none; margin: 0; padding: 2cm 2cm 2cm 3cm; }
    body { background: #fff; }
  }
</style>
</head>
<body>

<!-- Barre de contrôle -->
<div class="ctrl-bar no-print">
  <button onclick="window.print()">🖨️ Imprimer / Enregistrer en PDF</button>
  <a href="vp_eip_nomination.php">← Retour Nominations</a>
  <span style="margin-left:auto;opacity:.7">Acte de nomination — <?= $e($nomComplet) ?> — <?= $e($annee) ?></span>
</div>

<div class="page">

  <!-- ══ EN-TÊTE ══ -->
  <div class="entete">
    <!-- Gauche : hiérarchie institutionnelle -->
    <div class="entete-gauche">
      <div class="ministere">MINISTERE DE L'ENSEIGNEMENT<br>SUPERIEUR, DE LA RECHERCHE<br>ET DE L'INNOVATION</div>
      <hr class="trait">
      <div class="bold">SECRETARIAT GENERAL</div>
      <hr class="trait">
      <div class="bold">UNIVERSITE JOSEPH KI-ZERBO</div>
      <hr class="trait">
      <div class="bold">PRESIDENCE</div>
      <hr class="trait">
      <div class="coords">
        03 BP 7021 OUAGADOUGOU 03<br>
        Tel. : 25 30 70 64/65<br>
        Fax : (226) 25 30 72 42<br>
        Télex : 5270 BF
      </div>
    </div>

    <!-- Centre : logo officiel UJKZ -->
    <div class="entete-logo">
      <img src="data:image/jpeg;base64,/9j/4AAQSkZJRgABAQEAeAB4AAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAD+APsDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD36iiigAooooAKKKKACiiigAoprukaF3YKqjJJOABWFd+KrSLUbCws1a9uL8O0JhYeWVT7x39OOOBzzQBtyTxQlBJIqF22oGYDcfQep4rNv/ENhY6Z9v8APikg84Q7hKoUNu2nknHBBz9DXn83inWtZtrq0ksZYtYgH22yhS2XhoT88e7exYkHaDtXqadH4H1F57iCztzbWyX41O1mmmI3749rwvg71YFnw1AHp8U8VxAk0MiyROAyOhyGHqDXGWfjS8+ztbSWS3eqtqtzYRQxsIlIjJYMWOcDZg9856VrWHhkReD00G4mZAFwXtHaMod24bSSSMH16/pTYvBOlLprWc/2m4Zrg3TXMkxE3mkYLh1wVOOOMUAYV78SJIba2uY9KaKBhILiScuVhkjkKOhaNWA6E7jgY71aXxzNN4mewttOM1lHcpavMu8tuZA28YXZtG4dWz3xWy3g3w+9rBbNpkRigRkQZPKscsGOfmBPJBzk1Zk8OaO+orqX9nW/21QNsoTkEDAOOmQOAeuKAOXtPH088EE9zpqW9tdwXUtvKtxvOYAS25cDAIHFaHg3xPcatb21lqVrNFqP9nw3byNt2yB+42njkHg4p+k+ANE0zTPsrW4uJWt2t5Z2yrOrfewAflz3x1rYs9EsrHUJL6CNlme3itjlsgRx52gf99GgDn7j4g2Vtr+paSbeSe6tpY4beC2+eW4coGbC9FC5GWJArorPWLW9vrmxjLi7tVQ3ERX/AFe8ZAz0Jx6Guam8BrBcx6jp8sX9qrqpv3uZUw0iNkNESOdu04H0qOG11zw3DqWsuttJ9ovpbq9iCNJI0IwqLHt/iCr0IxzQB24dWzgg44NOryWyvEvJ7vxsl5HpVhbBppbCzuS8ty3YTqDtVicDAGeetb/hTxfqd9atNqx09ooYTLdyQyGKSz4J2yxMM9vvDrjpQB3dFUdL1jT9aslu9Ou4rmBv4o2zg+h9D7Gr1ABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRSMwVSTngdhQAtYmu68NEEdxKIjZBglw5J3RFiAhOBwuepPSsTUfFcxtYdd0x/tWhxh4tQiRNtxbEHmTB5+XupHTmszw/wCEZ7id5rkqZN7Jc3hUPHq9rINw3jP3hkDPbHHFAEeo32q+KtVu/DNzHLpk02nMwicB4vNSVWV1cD50YcH6Hitbw94KktxHPqXlQ3EN4LuIWjk4fbtcklQMNnlQAAAMV1thYw6dYW9nBv8AKgjEab3LNtAwMk8mrOcUAVrfTbG0mlmtrSCGWY7pHjjCs59yBzVkcdKxtc8VaP4fhLaheIjkfLEp3O30Uc15lrXxivJi0ej2aQJ0E053MfovQfrWc6sI7s9DCZXisXrSjp3eiPZSwUZJAHvWRe+K9C04lbrVbWNh1XzAT+Qr521LxJrWrsft2pXMyn+DeQo/4COKzYUWSVEeRY1Y4LsCQPyrnlirbI9+lwtZXr1PuX+f+R9AT/FLwrD92+klx/zzgb+oFRXfxV0GyuGgnivVkXGR5PqMjv6V55pPw7uJprW7e+tJrTerN5ZJ3DPI6Vu+LPA8uuait7aXEMP7sLJ5meSOh4Hp/KvGqcQUY1lT5lbW77My/s/Ko1FFzbWt3f8A4B0kfxb8MOQGkukz3aE8flWna/EPwtdnCatCjekoZP5ivn7UdMNjeNbxTpd7eskIJXPse9UijL95SPqK9WGLlJJo645Dl1eN6NV/ej6ttdQtL2MSWtzDOh/ijcMP0qfqa+ToLia2kEkEskTjo0bFT+ldbpHxM8SaWwV7oXkQ6pcjcf8AvrrW0cUvtI5MRwtWjrRmpeuh7dqvhfR9Zgmju7NA0oXfLF8kh2ncvzDk4IB59KwtX8JapqWmx6RJqzTWc8/+mXLoq3BgUZWPIGGy3Unt681U0D4r6LqRWLUFbT5zxlzujJ/3u3413kU8U8SyQyK8bDKspyD+NdEZxktD53EYSvhpctaLR47aarfaFYPrUk4k16+V7CK3S02KiwyMGnlVeWwADnA4IFeg+EPEp8QQ3cchgkms3WN7i1bdBNlchkPb3B6Va1nw3b6rcxX0U81jqUKlIry3IDhT1Ug8Mp9DXEXeladpOsWeg61q0troNtZm4iaSfyVu7hpGL7mGOVyCEH96qOc9SorlfANxcXWgzSu88ln9rlXT5LjPmPbDGwknk98E84xXVUAFFFFABRRRQAUUUUAFFFFABRRRQAUcGua8Z+M9P8GaO17eEPM/y29up+aVv6AdzVnwfql5rfhPTtTv4o47m6i81kjBCgHlcZ9sUAU9T8D6bqeozXfm3dsLpQt7DbS7I7sDs4HX0yMEiukjjSKNI0UKqAKoA4AHSpKydf1+w8O6c97fy7UHCqPvO3oBSbSV2VCEqklCCu2Xry9trC1kubudIYYxlnc4AryDxX8WJ7hpLTQAYYeQbpx87f7o7fXr9K5HxV4w1HxReb7h/LtVP7q2U/KvufU+9YUUDSnPRfU1xVa7lpHY+xweTYbBUvrOPa077L17sSaea6naaaV5ZXOWd2JJP1NPS1dsFvlHv1qzHEkQ+Xr696kGSQByewrDlb3PAzXju37rLo6fzP8ARf5/cRLbRIORuPuakAAGAAPoMV02keA9e1cK6Wn2eFuktwdg/LqfyrsrD4RWqhTqOoyyN3WBQo/M5rWNGT6HyFbFZtmDvVnJp+dl92i/A5vwNdLYQ397d3RitIwqbWbgseeB3OB+tbd3rNn4o0O9tLGZ4rraSsTHaz4549Qa6qD4d+G4ECNZvMoOR5kzHn6AgVej8HeHoWVo9Jt1ZTkNt5H415lbIVVrOu5WldNdlY76EcTToqi7ctnfe+vmfPPvSgnpzXv8/gXw1cEl9Ki3E5JVmX+RrIvfhXoM6kwPdWzdtsgYfkRXr/V5WPJeV4iDvB/jY8TaKNx8yA+44qF7RT9xsexr0nU/hRqduGfT7mK7XsjfI/8Ah+orir/S77S5vJvrSWB/R1xn6HofwrKVJrdHdhs7zjLXpN8vZ6r+vRmG8bx8MuP5Gtzw94w1fw1KDZXJaHPz28hyh/Dt+FVDgjBAOe1VpbXPzR9e6/4Vmrxd0fc5XxhgsyX1fHxUJPv8L/y/rU+gPCnjzTPFCCJW+z3wGWt5DyfdT3FdNPa210my4gimUHOJEDDP418oxySW8yvG7RyIcqynBU17H4E+Ja3xj0vW5FS6PyxXJ4Ens3offoa66WIvpIea5A6KdbDax7dv80eoKqqAFAAHQCue8QeMNO8NavpdlqReKPUS6pcH/Vxsu3hvTO7r7V0IOQDXnfxo0E6z4DmuYk3T6e/2kY67AMP+nP4V1HzJ6IGDAEEEEZBFLXzt8MPiy2ieTomvyl9O+7BcnloPY+q/y+lfQsM0dxCk0MiyRuoZXQ5DD1BoAkooooAKKKKACiiigArmfGfjXTfBmk/a71t8z5EFsp+aU/0Hqa1dd1m00DRrnVL2TZb26F29T6Ae5PFfI3izxPfeLdem1O9Y/MdsUWeIk7KP88mgC5d6lq/xE8ZWwupDJcXcywxRr92JCeijsAMmvreyto7KxgtIV2xQRrGg9ABgV4D8BfDhvNcuvEEyZhs1MMLEdZGHP5Kf/Hq+gpJUhjaSRgqKCzMTwAKAKGt6zZ6Dpct/eybY4xwO7nso9zXzp4l8SX3ifVHu7tsIDiKEH5Y19B7+prT8e+LpPE+sMsLkWFuxWBem71Y/X+VczBB5h3OPlH6159arzuy2PtsBhaGU4WWNxejt93kvNiwW+/5n+5296uDAGAMAdBR9K77wT4BfVwmo6mpSx4McXQzf4L/OphTbdkfmub5zi88xFlpBbLovN+ZheG/B+peJJQYF8q1Bw1xIPlH09TXrugeCdI0BFeOET3Q63Eqgt+Hp+FdBbwRW0CQwxpHGgwqKMACpa7YUoxNsNgKdBX3fcO1FFFancFFMkkWJGd22qoyT7VlwayrapJZylVkVEkaPHzRq5IUnnnJUjjocDnrQBr0UCigAqteWNrqEDQXcEc8TdUkUEVZooE0mrM8t8TfC4BXutCc56m1kP/oLf0P515lPbzWs7wXETxSocMjrgg+9fT+M1zPirwdZeJLZmIEN6o/dzgfo3qK56lFPWJ5OLyyMlzUdH2Pn6aISjnhuxqiytG2DkEelb2qaXd6PqEllexGOVD+DDsQe4rPliEi4/iH3TXHKNj3OGOKKmDqLB413p7Jv7P8AwPyPU/ht49N35eiatNm4AxbTO3+sH90+/p616fLDHcwSRSoHjkUqynoQeCK+T1Z4ZQ6lkdDkEHBBFfQPw98XjxLo/k3Dj+0LYBZh/fHZh9e/vXVh61/dZ9Hn2VRpf7VQXuvfy8/RnzX4z8PyeF/Fd/pTg7IpN0LH+KM8qfyOPqDXdfCL4jvoV5HoOrTZ0ydsQSO3/Hu5/wDZSfy6+td98Y/Ax8RaINXsYt2pWKklVHMsXUj6jqPxr5qGa6z5c+5AcjIpa84+DfiqXxF4SNrdOz3emsIWc9XQj5CffAI/Cu7utUsbK5gt7m7himnOIkd8F/pSE2krsuUUDpRQMgurqKztZbmdwkUSl3Y9gK4jwv8AEQ694hksJreOCGQE2xydxx2btnFXPibfm08ISRK2GupFi/Dqf0FeJ21xLaXUVzA5SWJw6MOxHIrnqVXGSSPIx2NlRrRjHZbnSfH/AF+4a90/QUJW3WP7TL6OxJC/lg/nXkGk6Xd61qtvptjEZLm4cIij+Z9h1r6J1vQNE+KOiabfTzGzvI1YSTpj92B95SD1GcY+tQ+ENO8E+BtRSCyvX1LVblxD9o2htmT0BHAHryTW/MrXPS9vT5VJvc73wt4dtfC3h200q05WFfnfHMjn7zH6muL+LXif7Fp6aJavie6G6cjqsfp+P8ga9GubmK0tJriZtsUSF2Y9gBk18w6/q0uua7d6jMTmaQlVP8K9h+ArDET5Y2XU+l4ewKxGI9pNe7DX59ChFGZJNo/E+laCgKAoHA4FRW0eyPcfvN/KtfQtIn13WLewgyGkb5mx91R1P5VxwV2fP8ZZvLG4z6lSfuwdvWX/AANjpPAPg/8Aty6+33qH7BA3Qj/WsO30HevbERUUKqgKowAB0qtp1hb6ZYQ2VqgSGFQqgfzPvVuvRhBRVjlwmGjh6duvUKKKKs6gopMimedGP41/OgCK8G/yIz91pRn8AT/MCvJ/D19qepfHfxFBcxH7Mls1u654SMFdh+pzn/gRr1mZY7qPYsgDDlWB5Ujoa5yw0SWHxTrOo+T5UmoRwI8o6DYCDtPXJ+X6fhQB0to7SWcLscsyAk+tTVCrxQoI16KMAKM4H4UC6hJxv/MGgCaimq6t91gfoadmgAooooA5vxf4Wt/EummMgJdxAmCX0PofY14LdWs1lcyW1xG0c0bFXRuoNfT1eafFDwystsNctk/eRALcBR95ezfh/L6VhWp3V0eRmeEU4+1hutzx+5i3DzFHI+97irfhrXZ/DuuW+owklUbEiD+ND1H+famfWs6aLy5Cvbt9K4fhd0fc8G5qsdhZZfiNXFaecf8AgH1XZXUV/ZQ3UDh4ZkDow7g15L4x+B0OrapJqGhXsViZm3SW8qnZuPUqR0+mK1fhBr5utNn0WZ8yWp8yHJ/5Zk8j8Cf1rO1vxp4g0jxlf2ltMJYhMFSCRAw5AwB3r0FWXKpM8DNUstrOlU72/wAjrPh34PtvBWhXFr53nXTSlrmYjAJA4wOwxz+Jry/xlrx13xHPcxsfIj/dQ/7o7/icn8a3Na+Il5d6ddWLWJtLyVPJmIY8AHngjIOCRWBrvha70Gw0+6uGDLeR7iAP9W3XafwP86yrT5laJ89mGJ9tG1LZas9i8Day+teF7aaZ91xFmKU9yR0P4jFdJXlfwhv/AN5qOnk9Qs6j9D/7LXqXPrW9OV4pnqYOq6lCMnueW/F6650y0B/vykfkB/Wqfww0G31I6jd3kKyxBBAoYZ+9978cY/OqXxSufO8XCLtBbon4kk/1r0PwjZQeGvBUMt1IkK+Wbq4dzgLkZ5+gwPwrFLmqtnnU4e2x0pPaJ5bp/ha91DxRc6FE7rBDM3nPnhUBxnHqRjFOttIWz+JEGmWxZ0hvVCluuAQefwr2LQ00u4hl1jS3SWPUWExmX+PjH9P5159oMX2j4wX8hGfKeZ/p2/rQ6aVvUmrgo0nBdXL8Dc+K2sHTvCZtI22y3riLj+6OW/oPxrwiJDJIqe/P0r0H4w6ibjxNb2KtlLWAZHozHJ/TFcJZj5nb0GPzrnry5pn6nhqiyzJJ4nrZv5vRfoW+306CvX/hXoYttLl1aVP3t0SsRPaMH+p/lXkttbvdXUVvEMySuEUe5OBX0pp1nHp+nW9pEMRwxhB+ArahHW5+U5XTdWtKtPW35stCiio5BIw/duFPqVzXWfQBJKsY56+lUHuHZ/mkK+gXIqZrOSRtzyox/wBqPI/LNKLRwMB4gPaED+tAFNiXbO4N/vyY/nScIOfL/MnH5Yq99jOMGbAPXEa/4UfYR/z3k/74T/4mgCiHJxwpGeMHH6U77TKSY+SMdM8/j7VleLtD1W701To+p3FvcxkkbQu1/ZgB0PTI5GfrXGz+K9dv0Oj6Z4P1Wy1o4R7uQ5jj/wBosVO4fXHtQB6Lkn+FQe3/AOunEPjLOFHso/rSaPpVzBpVvHqN5Jc3gX97IAFBb2A4FXhYxg5EkoPs1AFD5O7E/wDAQP5CpI5BEcoxH9au/ZR/z2m/77pfsq95Zj/20NABDcpKducN6VPVf7HHnO+f/v8AOP61MqhFCjOB6nJoAdUVxBHc28kEqB45FKsp6EGpaKAaufN2v6U+ia5d6e+cRPhGP8S9QfyrGukLRh+6fyr1T4t6WFuLHVEX74MMh+nK/wAzXmLLuUr/AHhivOqxs2jx8txLyvNoVFsn+D3LvgrWDoni2xui2I2fypf91uD/AEP4V2fj21XTviBZ3v8AyznMUxPurYP/AKCPzry3kH0Ir1LxpO2q+GPDOtHkvFskb0bA/qG/KnSd4NH2/HeFTpQxC/r+tTqvGfgtdXu7XUrOMC4EqLOoH303Dn6j+VX/AB9pP9p+ErhIkzLbYmjA/wBnqB+Ga27q5kTQprqLBkW2aRPchciuF+Evji48Y6Pd22qOsmoWjDewAHmRtnBwOOMEH8PWu3kVn5ny7w0Gp2XxbnJ/De8+yeM7ZScCdHiP5ZH6gV7rXgF9b/8ACMePSv3UtrtZF/3CQR+hr37NZ0dnHsceVtxhKm+jPCtZjOufEya36rLeiLr/AAqQD+gNdB8dNbOmeD7bSoW2Pfy7WA/55pgkfmVp+g6V/wAXe1FiPltmkmH/AAMcf+h1558dNXF945SxRsx2FuqED++3zH9CtVRT1bNMvptc83u2z2X4VqV+GeiA/wDPJj/4+1Z/gjT2l8XeIdXb7one3T3JbJ/kPzrW+GYA+G2hAf8APsD+prR8N2QsdI5XDzzSTufXc5I/TFXJXaOqrT56kG+l2eB+Obs3vjbVZc8Ccxj6L8v9KzbUfuc55LHNLq5e416+bG5nuX4HfLGrkYWzhWMqrzqMMSMhOeg9T+lea9ZH1vGFWNDJoYdOzlyq3kl/wxveAbMXnjOwBGVjYy/98jI/XFe/DpXhPgHVp7bxdaIR5q3BMLZGSoPcenQfhXuo6V20Lcp8JlNvYu3cWiiitz1QqhrGsWOg6XNqOozrDbQjLMf0AHcn0q/Wdrmi2XiHSZ9M1CES20y4YdwexB7EUAR+HvEWm+J9Ji1LTJvMgfgg8Mjd1YdiK1a+Zt2vfBbxoQA1xp0/rwlzHn9HH6fQ19CeHvEOneJtIh1LTZxJDIOR/Ejd1YdiKALdy8nnRRK/lhwfmxkkjsP1/Kqdvf213awzwaozpM5jjyE5cZyuNucjByO2DUHijWbfRtIub24IMdpH57j1x91fqzYH514/4T8M69pVxaePdQENzp8wlu7q23FXhWQEM4XoTtOfXHFAHu1pI0tsrtgkkjI6HBIyPr1qes/TblJIkRJVkj2B4ZFORJGehz/P8+9YvjvxpaeDNBe8kKvdyZS1gJ/1j+p/2R1NAFvUdfUeIrPw/ZEPfTAzzkc+RAvVj7k4UfXNb46V5Z8INE1KQah4v1tma91bGzfwfLB647A8YHoBXqdABRRRQAUUVHJIkMbSSuERRksxwAKAOK+KFzYJ4bFtdOwnklDQKgySQeT9MH9a8dECTD/R2Yyd42HJ+nrXfeOpLTxZcG50K7F7Jp8ZFxFGp4Un7yn+L3x7V51yCOTXFWleR87m0KtPE2qRttb8zNnXbM44+90HavXPCtp/wkPwjubHG6W2kk8r1DD5x/PH415lfRfaE+1qMyA4lAHX0b/H8PWvV/gy3/Ei1CFuP9J3BT7qB/SoofHY/TcwxNHMsjhUg77X9dmdxpjC98LWjdprNf1SvnD4T6v/AGF8SreFnxDds1mwzxkn5f8Ax4D86+m7G1FnZx2w+5HlU9lzwPyxXxjPcSWmtyXMLbZIrkuh9CGyDXoLax8lBNRSZ718WtP8rV7O/UYWeMxsf9pf/rH9K9S03edLtDLkyeSm4++BmuN8Xw/8JP4B0/UI1w7eTOMdt4AI/wDHv0ru1G1AoHAGKzjG02clGjyYipJdbGPY6SbfxNqmolR/pMcSqfoDn+lfKnjid7jx5r0jnJ+3zAfQOQP0FfYtfHHjVDF4719DnI1Gf/0Ya0SsdcIqKsj6S+E1yt18MtHZTnYjxn6q7CoviJ4wl8L6dBaWKKLu6B2OekSjqcevPFcr8AdaE+iajozt81tKJ4x/stwfyI/Wug+KnhW71uyttQsIzLPa7leIdWQ85HqRjpWdbm5XynpZXGhLFwWI+H+rHlEDW0zy3kUoF3IT+7dgNhPJIzwfQd+fYULZXLuqLBJknAyprGZHjdkdSrqeQRgikyfU15ydj6DN+EYZnWVaVeS8rJ2Xlt+p0Dam+iqy2MpivCVAnRsOo6nHoOg55POfSvUPhj4x1DxALqw1JhLNboHSbGCwJxg47jivD1UuwVQSxOAAOTXuPws8K3OiWE+oX0ZiubsALG3VUHr7mtqDk56DzHLcBl2XexhFJ9O9+p6LRRRXefHBRRRQBi+KPDGneK9Fl07UI8qwzHIB80TdmH+ea8b0TSNb+Gmqyi0aW4ukLNPYscR6hbjnzIf+mijOV5I9xXv1cv41bSf7IkfVpWgt7YC4NxH/AKyAg4VkI5DFunrg0Aea+Mdfh8e3Hh7QNIkZotXuBPdMODGinaEPuoBJHrg969nn063m0mTTdgW2eAwbQOApXbj8q8Hs5xYeKJPEWgLBqF3ZswuY4k2xXyFQWkh/uyBSNyjvkjIyK9m0Lxdo/iHQzqtldKLeNczCQ7WhwOQw7YpgedaNr8fw91HUfCmqXc09vpsK3NjcsnzMrAZiwPUnA9/wrjNJe++J3xdj/tm3kFtA7NJaNnEMafwH6nAPrmpPGvi241bxfoniEWZTRI7pVtTsG+5WJwWYjuPmIA/+vXovw0XS2/tbXrUxtdahfSfbUHJtiWJVM+nzcnoSfQUAelJGkaKqKFVRgADAAp1HaikAUUUUAFcT8VGuF8DXBgLAGRBLt/uZ/wAcV21Vr6xg1GymtLlBJDMpR1PcGpkrxaN8LVVGvCo1dJpny/pmrXekXMktq5TzYmhkAPDIwwR/9ep5NStpQrNbzeYOGPmj5/cnb1/nXSeLfhtfeH1mvbaaKfTlb7zuFdMnABzweorjfsUwzkIoHUtIoH868ySlB2Z99WwuWZxTU6iUl9zLa6okO4w2wyQVPmSFhg/QCul+HOv3Vt43tlcl0u1MDqo4A5IIA9D/ADNcqmmnrJcQp6gEsf04/WvW/hV4c05bSTWfKd7pZWiikkxwuBkgDoeSO9aUoylNHi18RkWCozw2F5XKSastfvfQ9D1S9TT9JvL2Q4S3geVj6BVJr4plkMszyHGXYsfxr6h+Mms/2T8PbuJGxNfMtsmPQ8t/46D+dfLVekj5Q+vfAca3fw30JJxvVrKPIPfiuoxXOfD+MxfD7QUPUWUf8q6SkAhr5J+KFubb4k64uMB5/MH/AAIA/wBa+ldM8baFq9z9mtr0LMThUlUpu+metcr4/wDhNZeLbibVbO5e11VkGd3zRyYGACO3TqPypJp7EQqQmrwdzyP4Oahc2PxGsY7dGdbpXhmUf3MZz+BANfU5GRXkPwQ8J2llpd1rU8ZOpmeS0YN/yyCnBAHqT1r1+mWeF/EWB7TxjciRVeOZUlQSICMEYOM9OQelcbcNEsYYWkHXB4YfyNerfFzTSyWOpqudpMDn0HVf615TIm+Jl7kcfWvPrRtJnLluaV8FmsITqP2bequ7WehPoesHTddsbsJHHHHOrPsQfdyMjJ56e9fTkZVowynKkZBr5L719F/DzXP7b8I2rO2Z7ceRL9V6fmMVphZauJ93xRhFywrxW2jOsooFFdh8cFFFFADXYIjMxwqjJPpXjvxCGoeNPENl4N0okFyLvUZT0hT/AJZhvoDnHqRXpXiPWLfSNMnuro/6PbxGeb3A+6v1ZsD864z4TaRrAfWvE2txGO51mVJI0f7wQbj07A7hj2AoA6iw8FaRpnhiHQoIikMWHWZTiQS/89A3Zs9/w6V4pbaMl54z8YQw6nb3DQW+5FQiC3uZNygK4yF+9xjoWr1fxb4kkm1+w8H6WxN9esGu5UODb2/VjkdGI4B7Z+lLp3wp8LaVcXUttazNHdQGCaCWUujKSD35zlQc57UwPPfhrYW2v3dpoviBY4ZdASVYLCUFZHeQksxB7KAMD15rB0q9ufhR8S7vTrzc2lTP5cwI4eFj8r/UA/zFbfxG8P3PhK1jvHeS4jiYLpupJLsurZhyIpD/AMtEwDg9RUWpXUnjXT7Xw/4mto4PEZtVuNJ1GMDZdqy7lU+m7p9fToQD3SwuFdFQSiRSgeKQNkSIeh/z7HvV6vEvg94umuIG8Kag5j1CyLNZNKcEgfeiPfjn8M+le0W8yzwrIuRnqD1B7g+9ICWiiigAooooA81+MWpC38PW2nhsNdTbiP8AZXn+ZFeKIu90Xnk4rsfibrY1jxdNFG2YLMeQuOm4H5j+fH4VylouZC390V5taXNNn32FayzJ5Vp7pOXze36F0nmve/ANn9i8G6eCMNKplP8AwI5H6YrwqytZL6+gtI/9ZM6ov1JxX0RfX1n4b0E3EuRb2saqFA5PQAD3rooK12z8iyrWc60zj/i/4QuvFXhiOSwVpLywcypCp/1ikYYD34BH0x3r5u0rQtT1vUBYadYzXFyTgoi/d/3uwH1r6l0H4haTrTmGQmzuB91JSMP9D6+1Uvh9YQ6B4Vu9Uv4hZy3t1NdTGVdpVS52g57YwfxrpjJNXR7dOtTqR5ou6Or8P2T6Z4c0yxmAEtvaxxuAc4YKAf1rQ3L61494o+JV3fO1roxa2ts4M/8Ay0f6f3R+tcrFqGvSRh477UGQ5wRO3+NZOvFOy1PPqZrTjLlirlbVbNtN1i7s2BUwTMg57A8V2Phn4i3ekL9j1iOW5gUfI/8Ay0T8+opvxQ0s2XiOLUUX93dICeP414P6Yru7rw/o/jTRbW8kiVJJYVZJ4uGXjp74PY1lGElJ8pxUMPVhWmqUrNdO6POn8bHTzqcXh9JYY9QumuWefaWjZgA2wD1IzzmpfCWvaloHiiODV3uEhusLKs5OQW+6/P8AnmmrZ6L4A8eWNr4huPNimj822mCfIhzgFx26e4HWux+Ifhsa3pKarYgSXNumfk58yPrx646j8avlm/fZs6OKa9vN+8unkdH4o0ga54bvLIDMjpuj/wB5eR+o/WvndgyOVYFWU4I9DXt3w98Tf25o4tLh83toArZPLp2b+h+nvXBfEbw+dJ19ruJMWt6TIuBwH/iH9fxqayUoqaJzGKrU44mB5/cpslzj5W5Fdl8MfEo0PxD9luH22d7hGJPCv/Cf6fjXLzR+dHt7jlaz8kN16VyRbjK5+p5Fjqed5V7Oo/eS5ZevR/r959bKcjNLXA/Dbxkuu6WNPvJf+JjargljzKg6N9fX/wCvXeivTjJSV0fIYnD1MNVdKotULTXcIpZjhQMk+gp1BAIwRVGB5x4ug1bWNf0LTYNPnbT3vI7vUJyvyqqnKRn8iSPU10/izxGvhzSN8MJuNQuD5Nlary00p6DHoOpPpW/isufQLO7vZby6VppnjMSMWIMSHqEI5XPcjk/hQB83eJdbuvDly0NnqryeIbpzNq19byY2t2gRh2HfHcD0rmW8WeJLhwG1zUnYnj/SX69u9fT3/CsPBZOT4ftST7t/jU1t8O/CFpLHLB4fslkjYOjFckEdDzTA81j+GXjHWTZ6n4ivLTVXEfNje3Ei+X7ZQYz61oWnw58Tap4907W9bbTrSy01ovs9tZsSFSM5RFBHTPUmvYcUtIDyL4i/DjUZ9ct/FHhGPbqqyq0sKuqbmHRwWIGeOR3r0XSX1B7W1ub6z+y3M8Y+1W4YMI5B3BBII4x19PetjA9KTgdv0oAWiiigA7VzXjXxGnhvw5Pchh9pkHl26nu57/QDmt+5uYbS3knuJVjijUs7scAAV85+NvFMnijW2mQstnDlLdD6f3j7n/Csa1Tkj5nr5NlzxmIXMvcjv/kc2zMzs7EszHJJ7mr8EflxAHqeTVW3i3vuP3V6+9X1VndVUEsxwABya4IrUfHebKMI5dSer1l+i/U7n4X6Ob3xA+oSLmGzTKk/89DwP0zWl8TNYfUNStvDtj87K4aQL3kP3V/XP41v2Kw+AfAXnTBftTDey9N8rdF/Dp+FYXgbRvLa48X69KsaANJHJOcDnrIc9vT/APVXao2ioLqfGRpSjSjho7y1fkjN8T/Dx9F0SPULWdpTEg+1IxHB7svtntWDo2i654lZbW2Mz2ykZeRz5Sfnx+Aqh458eX/xA8QW+gaGZE01p1iiVcgzvnAZvYdQPxr6JsLRNP063tVACwxqmQMZwMZqnQVzeeV03O6dl2PD/GXheDwvNYwRXTzyyxFpNwAAIPYen+Fel+E/DdrH4W04XMWZmi3tkf3iW/rXA6tIfGnxGW2iJa28wQqQeka/eP48/nXtQAQBVXCgYAAqacFKTfQzwNCEq1ScV7uyOd8caCdd8NzRRLuuoT5sPuR1H4jI/KuW+FmvZSbQrhiGjJkgz6fxL+B5/E16cRkV5f418MXOj6mvibRVIMb+ZPGo+63dvoe4q5pp86OjFQlTqLEQ6b+n/AMP9oPTC9no2qqOI3e3c/7wDL/6C1Znwf8AiM1pPF4Z1ebNrIdtnM5/1bf3CfQ9vQ8fT0LXUg+I/wANLtLRQbkx70jzykyc7fx6Z9DXyyytG7KwIZTggjkGtU7o7oSjON49T6c1vw7c+GdcXxNoMReBSWurVf7p+8R7e3Yiui1S0sfG/hT9xICsq74JO6OOmf5GvEPBnxn1XQY47HWI21KxXhXLfvox9T94ex5969O8N6/pOpXFxqvhSdpoWIa/01l2uhP8aD19cZB+tQ429DldBQukvdluv1R5Td2k1jdS21whSaJijqexFZ9zDkmRe/Uf1r2fx14Xi8Q2K65pOJLhUy6oP9ag/wDZh6fhXkWCCQRjsc1xVKbizly3MK2R41VY6we67r/NFXTtQudLv4b20lMU8TblYf56V9CeDfGdp4psRgrFfRKPOgJ6H+8PUV883FvtO5AdvcelLY391pl5Hd2czQzxnKup/wA8VNKq6bP1avh8LnmFjXoS16P9H/Wh9W5oY7Rk8AV554O+J1lqyx2eqlLS+6BycRyH69j7Gu5vrSLU9OuLOR3WKeMxs0bYbBGDg9q9CM1JXR8PicLWw0/Z1Y2ZyGnfE/S7u8kFxGbawaeSG2vzIrRybASd2DlOASMjkV28ciSxrIjBlYBlYHgg9DXkmu/DG4tYYbqF/wC0orcRW/2eK1jjna2VwWBYYDtgAduM+tZOqTa7c+MLzWHt9Vs7SC0e4a3huhbSRW0Y2oecrkne209ePxo5z3SivG/DHjnxRd6nHok8tu885iWK9uYj5a/uhI6/LgvIQw4yOhq1ZfFbUPJklvrG2ERsnlhnTcF83dJsVsk4DLGce/1oA9aozXnen/ESW48bwaTcLapYzxxxLIrfvBctGJMHn7uDgcde9Z4l1+88TeJLeaS9uI7FpQo+1rFDHFJETH+6Vcu3XknHHrQB3OteJ7TRZYYGgury5mRpEt7OLzHKLjc3sBkfnXDTeLNalsNV8RwatHEumXZjfRpYVUNECANzEbw7A5HOO2Kw/D3gzXr3SNPim0eymsGhWS2a/uHY24IUujBSp2ufmC/NtxyK73w58OdD0aCKW5sLG51BZWm89bcKsZJztQHJCjtkmgDsI33xq+CNwBwetEsqQxPJI6oiDLMxwAKz9X1zTtBsmutQuUhjHQE/Mx9AO5rw/wAZfEC98TO9rbhrbTgf9WD80nux/p/OsqlWMEenl2VV8dP3VaPV/wBbl34h+PTr0p0zTXK6fG3zuOPOI/8AZf51wCI0jbVHJoVGkYBRkmr8UQiXA5Y9TXnyk5yuz6bNMywvD2D5Kes3surfd+X/AAwsaLGgVen86774c+HVuLttdvwqWVp8yF+AXHf6LXPeF/Ddz4k1RbePKW6fNPNjhF/xPavY4dJhvbeCxjj8rRbcALGOPtBHr/sf+hdenXpo0+p+UUI1cZXliq2rbv6v/JHK6/qNlfRnxH4gdofDtm2LS3I+e7f1x74OB6e2a8b8a/EbVfGEphJ+yaWh/dWcRwMDoW9T+ldL8bvFdnq+q2ej6bMktvYBjK0ZynmHAwPoP54rymNGkkVEUszHCgdSa64xse1TpKF29W9z2v4EeE0mluPE90mfKYwWgI43Y+dvyIH516X4+8RjQ9AeKJ8Xl0DHFjqoxy34fzNWPDNjb+DvAljbXJEa2lsHuGP98jLfqTXM6Lo9x408QN4j1aMpp6HFpA/8YHT8O59TUzb2Rhiqkrezp/E/wXctfDTwu2nWjavdoVuLlcRKw5WPrn6nj8MV6D+dAGBgdqWnGKirI1oUY0aaggproroysoZWGCCOtOoqjY4GbRrjwXrD6tpaNJpM5/0y0UZMY/vr6gfp/LmPiH8KoPESN4g8MmMXcq+ZJbg4S4/2l7Bv0NeyFQetVLKwisfMSAbIWO4R9kJ649AfSpirPQxp0vZO0dn07HxVPBNazyQTxvFLGxV43GGUjqCK2vBniWfwp4ns9UiZvLRws6A/fjP3h+XI9xXufxX+Gf8AwksJ1jR4gNViXEkQ4+0KP/Zh+vT0r51exu47lrZ7WZZ1ba0ZjO4H0x1rQ3Psy1jikVL2wZTDcgSMoPyuCMhh6H+dcF478AtM0mraRFmQ/NPbr3/2l9/UVJ8HvEYu/CdvpF9J5d/aZjRJOC8fVSPXAOPwr0nA71nKKkrHLVoUsRBxf/DHy+QQeeD3BFVJrbPzR/iv+Fe5eMPh7b6yXvdNCwXx5Zf4Jfr6H3ryC/0+60y6e2vIHgmXgq4/l61w1KTiziwOPx2R1uek7xe66P8A4Jhcg45rrvDfxE1vw+Fh837XaD/ljOxOB/st1H8qwniSQfMOfUdaqvauo+X5vp1rJOUXdH6TgeI8qzemqVe0Zdpfo/8Ahme76L8UfD+qbUuZWsJz1Wf7v4N0/PFdcHsdStGAMF1byqUYcOrqeoPYivlPkHB4qe2vbuyk8y1upoH/AL0blf5V0RxTW6LxHC9Kb5qE7eT1R9Kz+FdGniSM2EcYS5F2piJQrKBgMCCOccVR/wCEA8O/2Xcab9jY2s8CQOhkJ+VCWXB6ggsea8WtfiD4ptQAmrzOB2kVX/UjNdF4c8f+K9Z1u2sTdxbHJMh8lchQMmqnjadODnLZHlVuG8VSi5OUbL1/yPS4PAvh2C3eIacjlpxcGVyTIHBBBD9QBgcZxW7HaW0VzNcpBEs8wUSyhRucLnAJ6nGTj614PqfxD8X217cWcmoLHJC5QlIVHQ+4rn73xTr2ogi61a7kB6r5hUfkMU1i4SV4mlLhjEzScppJ/M+h9V8UaJoqt9u1CCJh/wAs92XP/ARzXnWvfGPIaHQ7Qgnj7RcD+S/4/lXk2SSSTzT0ieT7q59+1ZTxE3toetR4fwWFXtMRK9u+iLOpapf6vdNc39zLcSnu56ew9B9KrxxPMeBhR1J6VYjtVXlzuPoOlWB0wBgelYpN7nj5vxrhcJD2OAXNLv8AZX+f5DI41iXC9+p9a2/Dvh298R6gLa1XEY5lmI+WMf4+1a3hbwHf6+y3E4a1sOvmMPmf/dH9a9m0vS7LR7JLSxhWKJfTqx9Se5rppUW9XsfARpYnMKzxGLk3f8f+AV9I8P2WjaUmn2seI+sjd5D3LfWqnja5ksPAuuTwMUljspSjDgqdpAIq5rfiCw0CzNzezBeuyMcs59AK84mh1z4otPE7Pp+i7WUFe5wcf7xz17V08yi0kenKtTpSVOKu+yPnbrXuPwf+G2fJ8T6zDjHzWVu46/8ATQ/0H4+ldB4Y+B+i6LeLeancvqcqHKRugSMH1I5z+ePavU1RQoAGAOgrS50nP6hpsniO9SC43LpNu2XTOPtLjt/uD9T9K344kijVI1VUUYVVGABTgAOlLU26kRgk2+rCiiimWFFFFABRRRQAVF5MXmmXYm8jBbaM/nSXduLu1lgZ5EEi7d0bFWHuCK8T8XaN4i0CcmfULy5sXPyT+cxH0bng1E5uKvY5cViJUI83LdHX+LvAlvO7ano0qWd8p3mMNsVz6g/wtWf4b+I8tnMNO8RK25Dt+045H+8P6ivO7KP7dexW894IFkOPNlJKqfeu3b4S6j5ReLUrVzjKjawB/GudSk3zQR5EK1WpP2uGhbvro/keuQTxXMKzQSJJE4yro2QR7GszXNH0rV7Mx6pFGUA4kYhSn0PavIrDVPEfgG+ENxDILdm+aCTmN/dW7H6VR8W+JpfEmqGdTLHaqqiOF24Xjk8cZzWjrK2q1OqpmVP2TU4+92Zq6x8PpI5Hk0O+g1GIc+Usi+av5HB/zxXHT201rK0VxDJDKvVJFKkfga6ew8AeIruzt761jjVZUDp++2sAehrYN1qehiOz8ZaWL/T34WdgHeP6OOv0zmsXC+trHnToKXvOLh+R50wVhhlDfUdKjNrEezD6GvXm+HPh/W7Vb3Rb+SKKTldp8xPpzyPzrCu/hTrcRJtri0uFHQbijfkRj9al0Jdj0cPis3wS/wBnqO3k7r7mcJYLDaXaSzQR3UX8ccgIBHsQeteq+FoNBnjOoaTYi3kGYnznI6Ejk/SuOm8A+JoDg6W7j1R1P9a2ra38S6VoMen6dot4k5JeacxZwSei/hjmvFzbL6uIglSvzPTd2t5o9OlxDmNWb+uNuKXRWv8AdoXfFy6Jpqfa7nSobm7uMhCycEjuTXl8sKSzPIQF3HIWNQqj2A5r0e4sfEmvaG9jqGiXP2qMh4Z9oUEjswJGMjPIrLtvhn4luCN9vDAD3llHH5Zq8pwNWjScaifN66W8ia+e5pCVsHJqLXbVeWv6HGLDGvRAfrzUmcdq9OsfhFJlW1DU1A7rbpn9T/hU+p2fhXwWFit7Aajq7gCKOQmQg9iR0H4DJr2FRa1eh4+JWPxPv4yo7ebf5HnVpo15dRichbe2z/x8TnYn4E9foMmur0H/AIQjR5klv7yTULkHr9nbykPsO/1NaK+A9e8SsL/W79LV2+5Bs3eWvpgYC/SsPxZ4MtvDVlHKmqpcTs+DCVCtt9QMk1XK4q9jnVCpRXtVDTu/8j2TS9TsdUsxPp88c0PTKHofQjsa5rxZ4/s9CV7Wz2XV/jG0H5Y/94+vtXlmg6rrltFcado3m77sjcIly3Hp6fWuy8PfC15GW516QgHn7NG3J/3m7fh+daKpKStFHdHGVsRBRox16vojjYNWt9T1z7f4klurmPqUj7/7PX5V9hXpFr8T/DdrbpBFa3cMSDCokS4A/OqXi3SfCHhjTtw0uOS8kGIIjM/J9T83QV5zpOi3+vXwtrCAsxOWboqD1J7CovKDstWcnPXws/Zxacn82ey2HxE0TU7yK0tlu2mkbCr5JOfy7V1grmvCfg6z8M2+4YmvXGJJyP0X0FdNiumHNb3j3MP7XkvV3CiiiqNwooooAKKKQtigBaKajq67lII9Qc06gAqOaCK4haKaNZI3GGVhkEe9SUUA1c8u8TfC/O+60I4PU2rnj/gJPT6GqvhXxpc+HJP7G8QxzJFGcRu6HdH7Ed1+let1matoGm63B5V/apKB91sYZfoeorJ07O8Tz5YLkn7Sg7Pt0ZKv9n61p+f3F3aSj2ZWrkNW+FukXhaSxklspD0VTvT8jz+tUn8Ia/4VuGuvDV8biDOWtJjyw9PQ/Xg10Oh+NbTUZhY6hC+nakODb3Hy7j/sk9aNJaTQN06r5MRGz/rZnIrYeO/B/wDx6P8A2hZJ0QfvAB/u/eH4VpWPxK0q/RrLXrJrUsNsgZN8Z+oxkflXofUVl6r4a0nWlIvrOORv+egG1x/wIc0cjXwsf1WpTX7mWnZ6o5OLQpNOlbVvBV/FNC5zLYmQNG/sD2P1/Oun0PxFb6wrwlHtr6L/AF1rMMOn+I9xXG3vw2v9MnN34b1WSJxyIpG2n6bh1+hFY19r+s2E0SeJdLkM8R/c3sX7qVfowBVh7dDU8zhurGCrSw796PKvvX/APZRyaXArhPDvxG027xbahceTKB8s8q7Ff69gfxx9OldtDcQ3EayQSJKjdGRgQfxFaxkpbHoUq1OrG8HckwKTIHeqt9qllpsRlvbqGBMdZHAz/jXm/iT4kWlzI1pYrPJbDh2Q+WZvYN1UfQZPtRKajuTXxNOiryZ1Gqa/fahcyaV4cRZZ1O2e8cfuoPx/ib2FYsNz4W8EM81xdnUdYfJkkHzyEnr7L+JzWNZ2Xi3xPbJb20S6RpI4CIDEpH/oTV1mh/DfRtMCy3Sm/uB1aYfJ+C9PzzWV5Sd0cUZVq8ueEfm9l6L9TnW8SeLvFzGLRLI2doTgzdDj3c/0Ga0dJ+F1usn2nW7uS8nY5ZEJCk+56n9K9BSNYkCIoVQMBQMAVV1HVLPSrVrm9nSKJe7HqfQDuavkW8tTdYSHx13zPz2+4Wx0yx0yDybG1it4+4jXGfr61y/ib4gWejlrSwC3uoHjapyiH3I6n2FVruXxJ4wbyrFH0fSW4M8vEso9h1A/L61taB4L0jQArwxeddd7iUZb8PT8KG29I6DcqlRctFWXf/JHD6b4I1nxTftqviKV4Ek52Y+dh2AH8I+tem6XpNlpFmttY26wxDsByT6k9zV2lpxgomlDC06Oq1ffqFFFFWdIUUVRTVbduu9fqv8AhWVWvSpW9pJK/d2Got7F6ioY7qGY4jkRj6BqlyatTi1dMLMyr7UJFlMMLbdv3mxzn2rOd3kOXZm/3jmluoQt5OMnPmE5BxnPP9ahYMq580gDkkgcV+aZtj8RVxU4ObSTat009D0qNOKinY3tK/48VH+038zV3IrmLXUsR+VBdxtzk7CpNUpvEmnC8+xTazbC5J2+Q1yobPptz+lfR0OIKNKhCkoSlKKV7LyOaWHbk3c7J5Y4xl3VR/tHFV31K1T/AJabj/sgmvONQ8faLYXdzbql7dT2pIuBbWzP5WOu49Kfa+MIL3xJp+nQRq1pf2ZuYLndyxHVdvbABpVc8xvLzU6Fla+r7a+QKhDrI7xtXj/hif8AEgU3+1+P9R/4/wD/AFq8m1DXtZn0/wAaWRujFeaYwltpIhsIiI3dvYdfeu30O9/tHQbC9LbjNbo7HPcgZ/WuDF5vmdGn7RyjvbRd0mt/U0jRpydjof7Y/wCmA9/n/wDrVT1H7Bq8HlX+nRTL23NyPocZFcL4beSb4heLHZmMcZgjQZ4HynNdpXNis8zDDzUOdPRPZdVcFhqU46rQk0iO9tZlgtpXuLNeqXD5aIegfGT9D+ddJXMw3M1vN+6YDcOVIyDXNzfFTTIrmRGku/s8cnlPeLakwK3TBfHrXv5bnkKtFKalKa3sjCWG5HaOiPScjvVa+ezW2b7aYBBj5vOxt/WuO1HxxY6dqtlpt1fslxegGHCjaQTgEkcDJrmtX1nUpPF02jabpNpPeRQC4F1qFwWBU/3RjPB44Pat/wC3IT/h03tfW0VbvdkyoStui74gtfCN0HOn6Nd3M56PYoyJn6n5fyBrl9H0PxKLxjpouLFc/flm8vA9+mfyq1feM9RvfBdpq0JFncQaolvdrGQysoJBAJ7HK1a8b+JLvTPEelafb6zFpcMsLyzTSQiReuFyPqDXJ/aGLnVUHTSvzdX9n0X3HHUyqjOXO5NeiSMm/wDDmvQXn2rVLK5v4gcu8Uvmbh/vDJH5V1nhrxN4KsSqNph0+4HBkmQyc/73J/QVjXfi7UtH8KS6tJeaZrLNcJBbtaoVDE9Qwz19hVnU/E+kz2+hzNobanJqsTSIkSKzptAyOeuM469jWkczrRafsr3bWj7K73S6Gccq9lLmpz1/vK/4o9VstSsdQiElldQzr6xuDVvIxXiNh4g0R7+Q2vhXU4ZLWTbNKkWBCRz8xDcVuX/xIt7TwnZa+I7yWK9kMcUKvh8jdknnH8Jrq/teSai6Mrt26f5ndToyt77X4nptxII4WYOqkA4LdM1zUSRtdLezxJc3OPlkmBOz/dHRfwGagsL6DVbC3v7dxJDOgkRu+D/WrEf+rUegwa+bzHiOvN2oJwtvszqhhYP4tTQ/taVRzHH/ACp39sS/88kx9TXiniFbJ/iLq8Wo2ur3sZtonhi08uSrbQCSFPHSprptbtPh5oOl6lPPbT318ttNIzkSJCSSAT2OMD6V2KvmHLTar6ytvFdVfTvb5C5ad37p7INZZukaH6NUg1cfxQkD/ZbP+FeR6totn4M8Q+HZ9CElu13di2nhEpYTIerEE9R61z1/rEZ1fxPLLr2s215DculjBaM5jZl4wQAR1A7itMPisfVtOnVvFq+sdd7bL8xOEFuvxPoNdWtz94SL9Rn+VSrqNq3/AC2A/wB4EfzryRda1o6j4NsJZGiuLuJpr5doyyqoOCMcc1bufF9xaeIfEELRxNYaTZLMeocyEZAz6fhVQzfMIvlcYy0vpdfa5fxYOjT7s9WWeKTOyRG+jA1zZ61hnxJaQeFLfX9SX7LDJAkpjzvILAEKOBk81Q0/xrp93qNtZXOm32nyXf8Ax7NdwbFlPoD6+3vXl5pi8RmVJL2TXI3ezv69tjSlCNJ77nUmQKflY7x029RW9ZTtc2ccrYDMOR7jg1hAADAAA9q3dOULYxgDux/MmujhWvL2lSmnpa/4k4qKsmZV7/x/Tf739BWJ4lJXwrrBU4IsZiD/AMANdRd6bJJK0qOvzclW/wAaw9Rt4bmzuLK6BMU8bRuEPVWBB5rzsxwNahjpVpx93mv02uaU6kXDlR4jZaRe3Ph7R5/D2gXtpqUQ86TUy21JFAPTnnPHbt710Ol6N4evPhLJezQwG68iSSa6bHmrMCf4uo5xxXpWn2lvpumQWlsrC3gjCoCckAVhHwJ4YvdQa6bS18yRt7JvYIzepUHFdizb6zJws4pSumt3q9Hd/wDARLpcup594et9autZWxi1IWJ1rS4rmaR4vMaTAKHGehIBOa63VPB93pdp4fm8Phbi50ZmHlzPtM6N94Z7f/XNeiwaIHZJdsCFV2qwXJC+nbAq8mkwqPnd3/HA/Su76vmWKqKpCKjHqm1rpbW2u2hnzU4Kz3PMtJ8P6pfavrWr61BBYnULUWoto5PMwMY3MehNTaJ4CvLCO0jbxFqtxDakeXBCAkeAc4IGcj8a9RitIIjlIkBHfHNT13QyWs789WydtElpZWVm7vYh1l0RxFh4dj0vUNQvoYrjzb+QSS7lOAQMcccVfPy/e4+vFdRRXNX4WpVZc3tXfzs/8io4procoxwwIGflPT8K8Xm1G00zS71tH1kwqZm8zw/qdushZt3Kgc/X+tfQ13YR3C/IqpIDkMB+h9qwm06I3IleCAzJwJCoLD8cZrg+o1cqny254u3VLbunf71qX7RVV2PG9Ztb/wAT+JNTgtdG86SDT7e3UCVYxZvxJ39DkcVcGnXfi278PXl9p90ZYC9hqqqWjOFxhiRjgnJOK9bh01IpZHhihSSVsuyjBY+pOOavJpUzH5pEUe2T/hWkK2Nq2jRopWVk+bys+oNQWsmcVrXg60vvCMmgackVjEWVkIUkKQ2cnuTVDWvDmty+KE1rTbjTHdLQW3k3qtgjOSeM969Nj0qBCN+6Q/7R4/IVeSNEXaiKo9AMV1YLJcZBfvai69L72vr8iJ14P4UeP3eia1qK6DHdafYQrb6l9puRZNiPao+VsHkknP5Vn6B4c1HT/iU6SwSf2TZRzSWUm07B5pBKg+2W/KvbHtYJM7oUJPcrUD6bbHom0/7LGt6mU4uNOVOnONmmtmt+vX09Be2he7R5V4WglSXxkZInCy3shTKn5ht7etcjp9vq11p/g3TLK1iNxbrcXDJdqwjHzsBuwM+uK9+OkQn/AJayj6Ef4Uf2ND/z2m/Nf8KwhgMfTnKShF3tbXa0XHt5jdSm1a55l4E07W/D8t7omowB7NT59tcQnMY3feQZ54PT8a7eKKVkysUhGTyEJHWtY6RCP+Wsp/Ef4VeiiWGNUQYUDArJcPVcVVlVxbUb2+Hv31H9YUVaOpxUPhtYvEtxripdG4mt1t2Qodu0EHPTOeKm1rw5Dr+mSWF/aytCxDAqCGVh0IPY12dFdv8Aq3S54z9rK8bWemlvkR9Ze1jzTTvAMOn6pDqNzcanqFxbqVgN45cQg+nHX3q7ovh6HQVvdkkkpu7p7pzIoGGbt9K76inXyD2qadaWvp0+4FiLdDyzxDoOpXPiCw1/RJ7T7ZaRtC0N0W8t1Oe69DzXNav4Y12Dwnr880a3msavPGZY7IEhI1IOBnBPAx+Ve6PDHJ9+NG+ozVZ9Mtn6IVP+ycVnHKMVQjFUpxly23Vm0ndK6v1G60Zbo8S8RaqNZ8O2tnZ6Tqcculyw3Ettc2xQtGnBx1B69Kn1rX7HxrfaDYaF5txPDfR3U0nllRbovXcT3/wr2A6RHn5Zn/4EAaQaMg/5bEZ67VANYLLcVG3LSV43t72l5b301K9pDv8AgZZwBycAVuWWUs41YbTzwfrRBp8EDBgpdh0ZznFWue2K68lyWeB5p1JXk+i2RFat7TRH/9k=" alt="Logo UJKZ" class="logo-img">
    </div>

    <!-- Droite : devise nationale -->
    <div class="entete-droite">
      <div class="devise-droite"><strong>BURKINA FASO</strong></div>
      <div class="etoiles">**********</div>
      <div style="font-size:10pt;font-style:italic">La Patrie ou la Mort, nous Vaincrons</div>
    </div>
  </div>

  <!-- ══ RÉFÉRENCE ET OBJET ══ -->
  <div class="objet-decision">
    <span class="num-decision">
      Décision n°<?= $e($anneeNum) ?>___________/MESRI/SG/UJKZ/P
    </span><br>
    portant nomination d'un vacataire au Département
    <?php if ($premierDept): ?>
    de <?= $e($premierDept) ?>
    <?php endif; ?>
    <?php if ($premierEtab): ?>
    de l'<?= $e($premierEtab) ?>
    (<?= $e($premierEtabSigle) ?>)
    <?php endif; ?>
    au titre de l'année universitaire <?= $e($annee) ?>.
  </div>

  <!-- ══ VISA ══ -->
  <div class="visa">
    <u>Visa du DCMEF</u>
  </div>

  <!-- ══ AUTORITÉ SIGNATAIRE ══ -->
  <div class="titre-principal">LE PRESIDENT DE L'UNIVERSITE JOSEPH KI-ZERBO</div>

  <!-- ══ CONSIDÉRANTS (Vu) ══ -->
  <div class="vu-block"><span class="vu-label">Vu</span><span class="vu-text">la Constitution&nbsp;;</span></div>
  <div class="vu-block"><span class="vu-label">Vu</span><span class="vu-text">la Charte de la Transition du 14 octobre 2022 et son modificatif du 25 mai 2024&nbsp;;</span></div>
  <div class="vu-block"><span class="vu-label">Vu</span><span class="vu-text">le décret n°2024-1565/PRES du 07 décembre 2024 portant nomination d'un Premier Ministre&nbsp;;</span></div>
  <div class="vu-block"><span class="vu-label">Vu</span><span class="vu-text">le décret n°<?= $e($anneeNum) ?>-0006/PF/PRIM du 12 janvier <?= $e($anneeNum) ?> portant remaniement du Gouvernement&nbsp;;</span></div>
  <div class="vu-block"><span class="vu-label">Vu</span><span class="vu-text">le décret n°2024-1022/PRES/PM du 02 septembre 2024 portant attribution des membres du Gouvernement&nbsp;;</span></div>
  <div class="vu-block"><span class="vu-label">Vu</span><span class="vu-text">la loi n°025/2010/AN du 18 mai 2010 portant régime juridique applicable aux emplois des enseignants-chercheurs, des enseignants hospitalo-universitaires et des chercheurs au Burkina Faso et son modificatif la loi n°36/2016/AN du 20 novembre 2016&nbsp;;</span></div>
  <div class="vu-block"><span class="vu-label">Vu</span><span class="vu-text">la loi n°013-2007/AN du 30 juillet 2017 portant loi d'orientation de l'éducation&nbsp;;</span></div>
  <div class="vu-block"><span class="vu-label">Vu</span><span class="vu-text">la loi n°010/2013/AN du 30 avril 2010 portant création des catégories d'établissements publics&nbsp;;</span></div>
  <div class="vu-block"><span class="vu-label">Vu</span><span class="vu-text">la loi n°073/2015/CNT du 06 novembre 2015 relative aux lois de finances&nbsp;;</span></div>
  <div class="vu-block"><span class="vu-label">Vu</span><span class="vu-text">le décret n°2024-1226/PRES/PM/MESRI du 28 octobre 2024 portant organisation du ministère de l'enseignement supérieur, de la recherche et de l'innovation&nbsp;;</span></div>
  <div class="vu-block"><span class="vu-label">Vu</span><span class="vu-text">le décret n°2016-598/PRES/PM/MINEFID du 08 juillet 2016 portant règlement général sur la comptabilité publique&nbsp;;</span></div>
  <div class="vu-block"><span class="vu-label">Vu</span><span class="vu-text">le décret n°2017-0106/PRES/PM/MINEFID du 13 mars 2017 portant régime juridique des ordonnateurs de l'Etat et des autres organismes publics&nbsp;;</span></div>
  <div class="vu-block"><span class="vu-label">Vu</span><span class="vu-text">le décret n°2017-0182/PRES/PM/MINEFID du 10 avril 2017 portant modalités de contrôle des opérations financières de l'Etat et des autres organismes publics&nbsp;;</span></div>
  <div class="vu-block"><span class="vu-label">Vu</span><span class="vu-text">le décret n°2000-558/PRES/PM/MESSRS/MEF du 12 décembre 2000, portant érection de l'Université de Ouagadougou en Etablissement Public de l'Etat à caractère Scientifique, Culturel et Technique (EPSCT)&nbsp;;</span></div>
  <div class="vu-block"><span class="vu-label">Vu</span><span class="vu-text">le décret n°2019-782/PRES/PM/MINEFID du 18 juillet 2019, portant régime financier et comptable des Etablissements Publics de l'Etat du Burkina Faso&nbsp;;</span></div>
  <div class="vu-block"><span class="vu-label">Vu</span><span class="vu-text">le décret n°2025-004/MESRI/MEF du 14 janvier 2025 portant modalités et taux de prise en charge des prestations spécifiques des agents publics et des personnes-ressources&nbsp;;</span></div>
  <div class="vu-block"><span class="vu-label">Vu</span><span class="vu-text">le décret n°2014-612/PRES/PM/MEF du 24 juillet 2014, portant statut général des Etablissements Publics de l'Etat à caractère Scientifique, Culturel et Technique (EPSCT)&nbsp;;</span></div>
  <div class="vu-block"><span class="vu-label">Vu</span><span class="vu-text">le décret n°2025-1087/PF/PRIM/MESRI/MEF du 25 août 2025, portant approbation des statuts particuliers de de l'Université Joseph KI-ZERBO&nbsp;;</span></div>
  <div class="vu-block"><span class="vu-label">Vu</span><span class="vu-text">le décret n°2019-515/PRES/PM/MESRSI du 28 mai 2019 portant changement de dénomination de l'Université Ouaga I Professeur Joseph KI-ZERBO&nbsp;;</span></div>
  <div class="vu-block"><span class="vu-label">Vu</span><span class="vu-text">l'arrêté n°2004-044/MESSRS/SG/UO/P du 15 juillet 2004 portant modalités de recrutement et d'emploi d'enseignants vacataires à l'Université de Ouagadougou&nbsp;;</span></div>
  <div class="vu-block"><span class="vu-label">Vu</span><span class="vu-text">l'arrêté n°2020-169/MESRSI/SG/UJKZ/P du 19 mai 2020, portant création d'établissement d'enseignement à l'Université Joseph KI-ZERBO&nbsp;;</span></div>
  <div class="vu-block"><span class="vu-label">Vu</span><span class="vu-text">l'arrêté n°2020-301/MESRSI/SG/UJKZ du 21 août 2020 portant attributions, organisation et fonctionnement des établissements de l'Université Joseph KI-ZERBO&nbsp;;</span></div>
  <div class="vu-block"><span class="vu-label">Vu</span><span class="vu-text">l'arrêté n°2010-162/MESSRS/SG/UO du 26 mai 2010 portant mise en place de la reforme Licence-Master-Doctorat (LMD)&nbsp;;</span></div>
  <div class="vu-block"><span class="vu-label">Vu</span><span class="vu-text">l'arrêté n°2021-294/MESRSI/SG//DRH du 06 août 2021 portant nomination des directeurs et directeurs adjoints des établissements d'enseignement et de recherche de l'Université de Ouaga1 Pr Joseph KI-ZERBO&nbsp;;</span></div>
  <div class="vu-block"><span class="vu-label">Vu</span><span class="vu-text">la circulaire n°2017-00554/MINEFID/SG/DGI/DLC du 07 mars 2017 relative à l'application de la retenue à la source de 2% sur les frais de vacation dans les établissements d'enseignement et de formation publics et privés&nbsp;;</span></div>

  <div class="sur-proposition">
    Sur proposition du Directeur de l'<?= $e($premierEtab ?: 'Unité de Formation et de Recherche concernée') ?>
    (<?= $e($premierEtabSigle ?: '…') ?>).
  </div>

  <!-- ══ DÉCIDE ══ -->
  <div class="decide-titre">DECIDE</div>

  <!-- Article 1er -->
  <div class="article" style="overflow:hidden">
    <span class="article-label">Art. 1<sup>er</sup> :</span>
    <span class="article-text">
      <?php
      // Construire la description de l'enseignant
      $qualif = '';
      if ($gradeEns) $qualif = $gradeEns . ', ';
      if ($diplomeEns) $qualif .= 'titulaire ' . (strpos(strtolower($diplomeEns), 'd\'un') === false
          ? "d'un " : '') . $diplomeEns . ', ';
      $qualif = rtrim($qualif, ', ');
      ?>
      <?php if (preg_match('/^(Mme?|Madame|Monsieur)\b/i', $nomComplet)): ?>
        <?= $e($nomComplet) ?>
      <?php else: ?>
        <?php
        // Déterminer la civilité si possible
        $civilite = 'Monsieur';
        ?>
        <?= $civilite ?> <strong><?= $e($nomComplet) ?></strong>
      <?php endif; ?>
      <?php if ($qualif): ?>, <?= $e($qualif) ?>, <?php endif; ?>
      est nommé(e) vacataire. L'intéressé(e) dispensera
      <strong><?= (int)$totCm ?> heures de Cours Théoriques</strong>
      au titre de l'année universitaire <strong><?= $e($annee) ?></strong>
      <?php if ($premierDept): ?>
      au Département <?= $e($premierDept) ?>
      <?php endif; ?>
      <?php if ($premierEtab): ?>
      de l'<?= $e($premierEtab) ?>.
      <?php else: ?>.<?php endif; ?>
      <?php if (count($fiches) > 1): ?>
      Le détail des enseignements figure dans le tableau en annexe.
      <?php endif; ?>
    </span>
  </div>

  <!-- Article 2 -->
  <div class="article" style="overflow:hidden">
    <span class="article-label">Article 2 :</span>
    <span class="article-text">
      L'enseignant ainsi nommé est tenu de se conformer aux dispositions des textes
      régissant les personnels de l'Enseignement Supérieur et de la Recherche Scientifique.
    </span>
  </div>

  <!-- Article 3 -->
  <div class="article" style="overflow:hidden">
    <span class="article-label">Article 3 :</span>
    <span class="article-text">
      Conformément aux dispositions de la lettre circulaire n°2017-00554/MINEFID/SG/DGI/DLC
      du 07 mars 2017, l'intéressé(e) est informé(e) qu'il lui sera applicable une retenue
      à la source de 2% de ses frais de vacation.
    </span>
  </div>

  <!-- Article 4 -->
  <div class="article" style="overflow:hidden">
    <span class="article-label">Article 4 :</span>
    <span class="article-text">
      Le Vice-Président chargé des Enseignements, des Innovations pédagogiques et de la
      Professionnalisation, le Directeur de l'<?= $e($premierEtab ?: 'Unité de Formation et de Recherche') ?>
      (<?= $e($premierEtabSigle ?: '…') ?>), le Directeur de l'Administration et des Finances
      et l'Agent Comptable de l'Université Joseph KI-ZERBO sont chargés chacun en ce qui
      le concerne, de l'exécution de la présente décision qui sera enregistrée, publiée et
      communiquée partout où besoin sera.
    </span>
  </div>

  <!-- ══ SIGNATURES ══ -->
  <div class="signatures-block">
    <div class="ville-date">Ouagadougou, le <?= $e($dateActe) ?></div>
    <div class="delegataire">
      P/le Président et par délégation<br>
      Le Vice-Président chargé des Enseignements, des<br>
      Innovations pédagogiques et de la<br>
      Professionnalisation (VP-EIP)
    </div>
    <div class="signataire">
      <strong><?= $e($vpNom) ?></strong><br>
      <em>Chevalier de l'Ordre des Palmes académiques</em>
    </div>
  </div>

  <!-- ══ AMPLIATION ══ -->
  <div class="ampliation">
    - SG<br>
    - DAF<br>
    - AC<br>
    - DCMEF/UJKZ<br>
    - SSFI<br>
    - <?= $e($premierEtabSigle ?: '…') ?><br>
    - Intéressé(e)
  </div>

  <?php if (count($fiches) > 1): ?>
  <!-- ══ ANNEXE : détail des cours ══ -->
  <div style="margin-top:28pt;page-break-before:avoid">
    <div style="text-align:center;font-weight:bold;font-size:12pt;text-decoration:underline;margin-bottom:10pt">
      ANNEXE — Détail des enseignements
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:10pt">
      <thead>
        <tr style="background:#000;color:#fff">
          <th style="padding:5pt 6pt;text-align:left;border:1pt solid #000">Intitulé du cours</th>
          <th style="padding:5pt 6pt;text-align:left;border:1pt solid #000">Établissement</th>
          <th style="padding:5pt 6pt;text-align:left;border:1pt solid #000">Département</th>
          <th style="padding:5pt 6pt;text-align:center;border:1pt solid #000">Sem.</th>
          <th style="padding:5pt 6pt;text-align:center;border:1pt solid #000">CT (h)</th>
          <th style="padding:5pt 6pt;text-align:center;border:1pt solid #000">TD (h)</th>
          <th style="padding:5pt 6pt;text-align:center;border:1pt solid #000">TP (h)</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($fiches as $idx => $f): ?>
        <tr style="background:<?= $idx%2===0?'#fff':'#f5f5f5' ?>;border-bottom:1pt solid #ccc">
          <td style="padding:4pt 6pt;border:1pt solid #ccc"><?= $e($f['cours']) ?></td>
          <td style="padding:4pt 6pt;border:1pt solid #ccc"><?= $e($f['etab_sigle'] ?: $f['etab_nom'] ?: '—') ?></td>
          <td style="padding:4pt 6pt;border:1pt solid #ccc"><?= $e($f['dept_sigle'] ?: $f['dept_nom'] ?: '—') ?></td>
          <td style="padding:4pt 6pt;border:1pt solid #ccc;text-align:center"><?= $e($f['semestre']) ?></td>
          <td style="padding:4pt 6pt;border:1pt solid #ccc;text-align:center"><?= (int)$f['volume_cm'] ?></td>
          <td style="padding:4pt 6pt;border:1pt solid #ccc;text-align:center"><?= (int)$f['volume_td'] ?></td>
          <td style="padding:4pt 6pt;border:1pt solid #ccc;text-align:center"><?= (int)$f['volume_tp'] ?></td>
        </tr>
        <?php endforeach; ?>
        <tr style="font-weight:bold;background:#e8e8e8">
          <td colspan="4" style="padding:4pt 6pt;border:1pt solid #ccc;text-align:right">TOTAL</td>
          <td style="padding:4pt 6pt;border:1pt solid #ccc;text-align:center"><?= $totCm ?></td>
          <td style="padding:4pt 6pt;border:1pt solid #ccc;text-align:center"><?= $totTd ?></td>
          <td style="padding:4pt 6pt;border:1pt solid #ccc;text-align:center"><?= $totTp ?></td>
        </tr>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

</div><!-- /page -->
</body>
</html>
