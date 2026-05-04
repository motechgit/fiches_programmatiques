<?php
// ============================================================
// index.php — Formulaire de soumission / modification de fiche
// Accès : index.php                    → nouvelle fiche (vierge)
//         index.php?token=XXX          → nouvelle fiche pré-remplie
//         index.php?token=XXX&edit=ID  → modifier une fiche existante
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';
if (!class_exists('App')) require_once __DIR__ . '/src/App.php';
App::configureErrorDisplay();
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/FicheRepository.php';
require_once __DIR__ . '/src/EtabRepository.php';
require_once __DIR__ . '/src/Mailer.php';
require_once __DIR__ . '/src/App.php';

$config   = require __DIR__ . '/config/security.php';
$security = new Security($config);
$repo     = new FicheRepository();
$mailer   = new Mailer();
$etabRepo = new EtabRepository();

// ── Charger établissements et départements depuis la BD ──────
// Remplace les tableaux statiques de config/security.php
$_etabsDB    = $etabRepo->getListeEtabs(true);          // [{id, sigle, nom}, ...]
$_deptsDB    = $etabRepo->getListeDepts(true);           // [{id, nom, sigle, etablissement_id, etab_sigle}, ...]

// Tableau plat des noms d'établissements (pour les selects/datalists)
$etablissementsDB = array_column($_etabsDB, 'nom');

// Map etab_nom => [dept_nom, ...] (pour le JS ETAB_DEPT)
$etabDepartementsDB = [];
foreach ($_deptsDB as $d) {
    $etabNom = '';
    foreach ($_etabsDB as $e) {
        if ((int)$e['id'] === (int)$d['etablissement_id']) { $etabNom = $e['nom']; break; }
    }
    if ($etabNom) $etabDepartementsDB[$etabNom][] = $d['nom'] . (!empty($d['sigle']) ? ' ('.$d['sigle'].')' : '');
}

// Fallback sur config statique si la BD est vide (première installation avant migration)
$etablissementsFinaux   = !empty($etablissementsDB)   ? $etablissementsDB   : ($config['etablissements']   ?? []);
$etabDepartementsFinaux = !empty($etabDepartementsDB) ? $etabDepartementsDB : ($config['etab_departements'] ?? []);

$security->sendSecurityHeaders();
$security->startSecureSession();
$csrfToken = $security->generateCsrfToken();

$step      = 1;
$errors    = [];
$old       = [];
$modeEdit  = false;    // true = modification d'une fiche existante
$ficheId   = 0;        // ID de la fiche en cours de modification
$enseignant = null;

// ── Pré-remplissage depuis token (nouvelle fiche ou édition) ──
$tokenParam = $_GET['token'] ?? '';
$editParam  = (int) ($_GET['edit'] ?? 0);

if ($tokenParam && preg_match('/^[a-f0-9]{64}$/', $tokenParam)) {
    $enseignant = $repo->findByToken($tokenParam);

    if ($enseignant) {
        // ── Pré-remplir identification complète ─────────────
        $old = array_merge($old, [
            'matricule'          => $enseignant['matricule'],
            'nom'                => $enseignant['nom'],
            'prenom'             => $enseignant['prenom']             ?? '',
            'diplome'            => $enseignant['diplome']            ?? '',
            'departement'        => $enseignant['departement'],
            'email'              => $enseignant['email'],
            'type_enseignant'    => $enseignant['type_enseignant']    ?? 'permanent',
            'grade'              => $enseignant['grade']              ?? '',
            'date_nomination'    => $enseignant['date_nomination']    ?? '',
            'volume_statutaire'  => $enseignant['volume_statutaire']  ?? '',
            'abattement'         => $enseignant['abattement']         ?? '',
            'motif_abattement'   => $enseignant['motif_abattement']   ?? '',
            'volume_apres_abatt' => $enseignant['volume_apres_abatt'] ?? '',
            'etab_rattachement'  => $enseignant['etab_rattachement']  ?? '',
            'etab_administratif' => $enseignant['etab_administratif'] ?? '',
            'etab_beneficiaire'  => $enseignant['etab_beneficiaire']  ?? '',
            'mois_execution'     => $enseignant['mois_execution']     ?? '',
            'fichier_diplome'    => $enseignant['fichier_diplome']    ?? '',
            'fichier_nomination' => $enseignant['fichier_nomination'] ?? '',
        ]);

        // ── Charger TOUTES les fiches de l'enseignant comme lignes ──
        // (avec filtre optionnel sur l'année académique)
        $anneeAcad = $config['annee_academique'] ?? '';
        $pdo = Database::getInstance();
        // Charger toutes les fiches de l'enseignant (sans filtre d'année)
        // pour garantir l'affichage même si annee_academique diffère
        // Si &nouveau=1, on ignore les fiches existantes (nouvelle fiche vierge)
        $nouveauMode = isset($_GET['nouveau']) && $_GET['nouveau'] === '1';
        $stmtF = $pdo->prepare(
            "SELECT * FROM fiches
             WHERE enseignant_id = ?
             ORDER BY annee_academique DESC, semestre ASC, submitted_at ASC"
        );
        $stmtF->execute([(int)$enseignant['id']]);
        $fichesExistantes = $nouveauMode ? [] : $stmtF->fetchAll();

        if (!empty($fichesExistantes)) {
            $lignesPre = [];
            foreach ($fichesExistantes as $fe) {
                $isEnc = !empty($fe['is_encadrement']) || ($fe['semestre'] ?? '') === 'ENC';
                $lignesPre[] = [
                    'semestre'               => ($isEnc ? 'ENC' : ($fe['semestre'] ?? 'S1')),
                    'code'                   => $fe['code_ue']        ?? ($fe['code'] ?? ''),
                    'parcours'               => $fe['parcours']        ?? '',
                    'cours'                  => $fe['cours']           ?? '',
                    'ntc'                    => $fe['ntc']             ?? '',
                    'volume_cm'              => (string)($fe['volume_cm'] ?? 0),
                    'volume_td'              => (string)($fe['volume_td'] ?? 0),
                    'volume_tp'              => (string)($fe['volume_tp'] ?? 0),
                    'niveau'                 => $fe['niveau']          ?? '',
                    'is_encadrement'         => $isEnc,
                    'etab_beneficiaire_fiche'=> $isEnc ? 0 : (int)($fe['etab_beneficiaire_fiche'] ?? 0),
                    'dept_beneficiaire_fiche'=> $isEnc ? 0 : (int)($fe['dept_beneficiaire_fiche'] ?? 0),
                    '_fiche_id'              => (int)$fe['id'],
                    '_statut'                => $fe['statut']          ?? 'en_attente',
                ];
            }
            $old['lignes'] = $lignesPre;
            // Activer le mode édition dès qu'il y a des fiches existantes
            $modeEdit = true;
            if ($editParam > 0) {
                $ficheId = $editParam;
            }
        }
        $step = 1; // Toujours étape 1 (formulaire unifié)
    }
}

// ── Traitement POST ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';


    // Vérification CSRF
    if (!$security->verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $security->audit('csrf_fail');
        http_response_code(403);
        die('Requête invalide. Veuillez recharger la page.');
    }

    // Mode édition transmis via champ caché
    $modeEdit = (bool) ($_POST['mode_edit'] ?? false);
    $ficheId  = (int)  ($_POST['fiche_id']  ?? 0);

    // Navigation retour
    if ($action === 'back1') { $step = 1; $old = collectOld(); goto render; }
    if ($action === 'back2') { $step = 1; $old = collectOld(); goto render; }

    // ── Étape 1 ──────────────────────────────────────────────
    if ($action === 'step1') {
        $old = collectOld();

        $type_temp = Security::sanitizeText($_POST['type_enseignant'] ?? '', 20);
        $matricule = Security::sanitizeText(strtoupper(trim($_POST['matricule'] ?? '')), 20);

        // Générer un matricule unique pour les vacataires si non renseigné
        if ($type_temp === 'vacataire' && empty($matricule)) {
            $matricule = $security->generateMatriculeVacataire();
        }
        // Pour les vacataires, forcer le matricule fourni (non modifiable)
        $_POST['matricule'] = $matricule;
        $nom                = Security::sanitizeText($_POST['nom']              ?? '', 100);
        $prenom             = Security::sanitizeText($_POST['prenom']            ?? '', 100);
        $diplome            = Security::sanitizeText($_POST['diplome']           ?? '', 150);
        $mois_execution     = Security::sanitizeText($_POST['mois_execution']    ?? '', 100);
        $departement        = Security::sanitizeText($_POST['departement']      ?? '', 100);
        $email              = Security::sanitizeText($_POST['email']            ?? '', 150);
        $type_enseignant    = Security::sanitizeText($_POST['type_enseignant']  ?? '', 20);
        $grade              = Security::sanitizeText($_POST['grade']            ?? '', 100);
        $date_nomination    = Security::sanitizeText($_POST['date_nomination']  ?? '', 10);
        $volume_statutaire  = $_POST['volume_statutaire'] !== '' ? max(0, min(9999, (int)$_POST['volume_statutaire'])) : null;
        $abattement         = $_POST['abattement']         !== '' ? max(0, min(9999, (int)$_POST['abattement']))       : null;
        $motif_abattement   = Security::sanitizeText($_POST['motif_abattement']  ?? '', 255);
        $volume_apres_abatt = $_POST['volume_apres_abatt'] !== '' ? max(0, min(9999, (int)$_POST['volume_apres_abatt'])) : null;
        $etab_rattachement  = Security::sanitizeText($_POST['etab_rattachement']  ?? '', 150);
        $etab_administratif = Security::sanitizeText($_POST['etab_administratif'] ?? '', 200);
        $etab_beneficiaire  = Security::sanitizeText($_POST['etab_beneficiaire']  ?? '', 150);

        // ── Traiter les uploads AVANT la validation (step1) ──────
        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        foreach (['fichier_diplome_upload'=>'fichier_diplome',
                  'fichier_nomination_upload'=>'fichier_nomination'] as $_upInput=>$_upField) {
            if (!empty($_FILES[$_upInput]['tmp_name']) && $_FILES[$_upInput]['error']===UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES[$_upInput]['name'], PATHINFO_EXTENSION));
                if (in_array($ext,['pdf','jpg','jpeg','png'],true) && $_FILES[$_upInput]['size']<=5*1024*1024) {
                    $old_f = $old[$_upField] ?? '';
                    if ($old_f && file_exists($uploadDir.$old_f)) @unlink($uploadDir.$old_f);
                    $newNm = preg_replace('/[^a-zA-Z0-9._-]/','_',
                        'vac_'.strtoupper(trim($_POST['matricule']??'tmp')).'_'.$_upField.'_'.time().'.'.$ext);
                    if (move_uploaded_file($_FILES[$_upInput]['tmp_name'],$uploadDir.$newNm)) {
                        $old[$_upField] = $newNm;
                        $_POST[$_upField] = $newNm;
                    }
                }
            }
        }


        if (!$security->validateMatricule($matricule)) {
            $errors['matricule'] = 'Format invalide. Le matricule doit contenir au moins 5 chiffres et se terminer par une lettre majuscule (ex: 123456A).';
        }
        if (empty($nom) || mb_strlen($nom) < 2) {
            $errors['nom'] = 'Le nom complet est requis (2 caractères minimum).';
        }
        // Département requis uniquement pour IESR UJKZ
        $_rvDept = strtolower(trim($etab_rattachement ?? ''));
        $_isUJKZ_dept = ($_rvDept === '' || strpos($_rvDept,'ujkz')!==false
                      || strpos($_rvDept,'ki-zerbo')!==false || strpos($_rvDept,'ki zerbo')!==false);
        if ($_isUJKZ_dept && $type_enseignant !== 'vacataire'
            && !Security::inWhitelist($departement, $config['departements'])) {
            $errors['departement'] = 'Veuillez sélectionner un département valide.';
        }
        if (!Security::inWhitelist($type_enseignant, ['permanent','vacataire'])) {
            $errors['type_enseignant'] = 'Type invalide.';
        }
        if (empty($grade)) {
            $errors['grade'] = 'Le grade est requis.';
        }
        // etab_beneficiaire global supprimé : l'étab est maintenant par ligne de cours
        // Pour les IESR (permanent) : l'étab de rattachement UJKZ est requis
        // Pour les IESR UJKZ uniquement : l'étab administratif est requis
        if ($type_enseignant === 'permanent') {
            $rv = strtolower(trim($etab_rattachement));
            $isUJKZ_submit = ($rv === '' || strpos($rv,'ujkz')!==false
                           || strpos($rv,'ki-zerbo')!==false || strpos($rv,'ki zerbo')!==false);
            if ($isUJKZ_submit && empty($etab_administratif)) {
                $errors['etab_administratif'] = 'Veuillez sélectionner l\'établissement de rattachement administratif (UJKZ).';
            }
        }
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Adresse email invalide.';
        }
        if (!empty($date_nomination) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_nomination)) {
            $errors['date_nomination'] = 'Format de date invalide.';
        }

        // ── Traiter aussi les cours (formulaire unique) ─────────
        $lCours    = $_POST['l_cours']     ?? [];
        $lCode     = $_POST['l_code']      ?? [];
        $lParcours = $_POST['l_parcours']  ?? [];
        $lNtc      = $_POST['l_ntc']       ?? [];
        $lNiveau   = $_POST['l_niveau']    ?? [];
        $lSemestre = $_POST['l_semestre']  ?? [];
        $lCm       = $_POST['l_cm']        ?? [];
        $lTd       = $_POST['l_td']        ?? [];
        $lTp       = $_POST['l_tp']        ?? [];
        $encSem    = $_POST['enc_semestre'] ?? 'S1';
        $encCm     = max(0, min(500, (int)($_POST['encadrement_cm'] ?? 0)));

        $lFicheId   = $_POST['l_fiche_id']   ?? [];
        $lEnc       = $_POST['l_enc']        ?? [];
        $lStatut    = $_POST['l_statut']     ?? [];
        $lEtabBenef = $_POST['l_etab_benef'] ?? [];
        $lDeptBenef = $_POST['l_dept_benef'] ?? [];
        $lignes = [];
        foreach ($lCours as $i => $cv) {
            $cv = Security::sanitizeText($cv, 150);
            if (empty($cv)) continue;
            $semTmp = in_array($lSemestre[$i] ?? '', ['S1','S2','ENC']) ? $lSemestre[$i] : 'S1';
            $encTmp = !empty($lEnc[$i]) || $semTmp === 'ENC';
            $lignes[] = [
                'cours'          => $cv,
                'code'           => Security::sanitizeText($lCode[$i]     ?? '', 20),
                'parcours'       => Security::sanitizeText($lParcours[$i] ?? '', 100),
                'ntc'            => Security::sanitizeText($lNtc[$i]      ?? '', 10),
                'niveau'         => Security::sanitizeText($lNiveau[$i]   ?? ($config['niveaux'][0] ?? ''), 50),
                'semestre'       => $encTmp ? 'ENC' : $semTmp,
                'volume_cm'      => max(0, min(500, (int)($lCm[$i] ?? 0))),
                'volume_td'      => max(0, min(500, (int)($lTd[$i] ?? 0))),
                'volume_tp'      => max(0, min(500, (int)($lTp[$i] ?? 0))),
                'is_encadrement'          => $encTmp,
                'etab_beneficiaire_fiche' => $encTmp ? 0 : (int)($lEtabBenef[$i] ?? 0),
                'dept_beneficiaire_fiche' => $encTmp ? 0 : (int)($lDeptBenef[$i] ?? 0),
                '_fiche_id'               => (int)($lFicheId[$i] ?? 0),
                '_statut'                 => $lStatut[$i] ?? '',
            ];
        }
        if (empty($lignes)) {
            $errors['lignes'] = 'Veuillez saisir au moins un cours.';
        }

        if (empty($errors)) {
            $step = 2;  // Aperçu
            $old['matricule']          = $matricule;
            $old['nom']                = $nom;
            $old['prenom']             = $prenom;
            $old['diplome']            = $diplome;
            $old['departement']        = $departement;
            $old['email']              = $email;
            $old['type_enseignant']    = $type_enseignant;
            $old['grade']              = $grade;
            $old['date_nomination']    = $date_nomination;
            $old['volume_statutaire']  = $volume_statutaire ?? '';
            $old['abattement']         = $abattement ?? '';
            $old['motif_abattement']   = $motif_abattement;
            $old['volume_apres_abatt'] = $volume_apres_abatt ?? '';
            $old['etab_rattachement']  = $etab_rattachement;
            $old['etab_administratif'] = $etab_administratif;
            $old['etab_beneficiaire']  = $etab_beneficiaire;
            $old['mois_execution']     = $mois_execution;
            $old['lignes']             = $lignes;
        } else {
            $step = 1;
        }
        goto render;
    }

    // ── Étape 2 — Validation tableau multi-cours ─────────────
    if ($action === 'step2') {
        $old = collectOld();

        // Lire et valider les lignes du tableau
        $lCours    = $_POST['l_cours']    ?? [];
        $lCode     = $_POST['l_code']     ?? [];
        $lParcours = $_POST['l_parcours'] ?? [];
        $lNtc      = $_POST['l_ntc']      ?? [];
        $lNiveau   = $_POST['l_niveau']   ?? [];
        $lSemestre = $_POST['l_semestre'] ?? [];
        $lCm       = $_POST['l_cm']       ?? [];
        $lTd       = $_POST['l_td']       ?? [];
        $lTp       = $_POST['l_tp']       ?? [];
        $encSem    = $_POST['enc_semestre'] ?? 'S1';
        $encCm     = max(0, min(500, (int)($_POST['encadrement_cm'] ?? 0)));

        if (empty($lCours)) {
            $errors['lignes'] = 'Le tableau doit contenir au moins un cours.';
        }

        $lFicheId2 = $_POST['l_fiche_id'] ?? [];
        $lEtabBenef2 = $_POST['l_etab_benef'] ?? [];
        $lDeptBenef2 = $_POST['l_dept_benef'] ?? [];
        $lEnc2     = $_POST['l_enc']      ?? [];
        $lStatut2  = $_POST['l_statut']   ?? [];
        $lignes = [];
        foreach ($lCours as $i => $cv) {
            $cv = Security::sanitizeText($cv, 150);
            if (empty($cv)) continue;
            $semTmp2 = in_array($lSemestre[$i] ?? '', ['S1','S2','ENC']) ? $lSemestre[$i] : 'S1';
            $encTmp2 = !empty($lEnc2[$i]) || $semTmp2 === 'ENC';
            $lignes[] = [
                'cours'          => $cv,
                'code'           => Security::sanitizeText($lCode[$i]     ?? '', 20),
                'parcours'       => Security::sanitizeText($lParcours[$i] ?? '', 100),
                'ntc'            => Security::sanitizeText($lNtc[$i]      ?? '', 10),
                'niveau'         => Security::sanitizeText($lNiveau[$i]   ?? ($config['niveaux'][0] ?? ''), 50),
                'semestre'       => $encTmp2 ? 'ENC' : $semTmp2,
                'volume_cm'      => max(0, min(500, (int)($lCm[$i] ?? 0))),
                'volume_td'      => max(0, min(500, (int)($lTd[$i] ?? 0))),
                'volume_tp'      => max(0, min(500, (int)($lTp[$i] ?? 0))),
                'is_encadrement'          => $encTmp2,
                'etab_beneficiaire_fiche' => $encTmp2 ? 0 : (int)($_POST['l_etab_benef'][$i] ?? 0),
                'dept_beneficiaire_fiche' => $encTmp2 ? 0 : (int)($_POST['l_dept_benef'][$i] ?? 0),
                '_fiche_id'               => (int)($lFicheId2[$i] ?? 0),
                '_statut'                 => $lStatut2[$i] ?? '',
            ];
        }

        if (empty($lignes)) {
            $errors['lignes'] = 'Veuillez saisir au moins un cours avec son intitulé.';
        }

        if (empty($errors)) {
            $step = 2;
            $old['lignes'] = $lignes;
        } else {
            $step = 2;
        }
        goto render;
    }

    // ── Soumission / Modification finale ─────────────────────
    if ($action === 'submit') {

        if (!$modeEdit && !$security->checkRateLimit('soumettre')) {
            $errors['global'] = 'Trop de soumissions. Veuillez patienter.';
            $step = 1; $old = collectOld();
            goto render;
        }

        $old = collectOld();

        // Re-validation serveur
        $matricule          = Security::sanitizeText($old['matricule']          ?? '', 20);
        $nom                = Security::sanitizeText($old['nom']                ?? '', 100);
        $departement        = Security::sanitizeText($old['departement']        ?? '', 100);
        $email              = Security::sanitizeText($old['email']              ?? '', 150);
        $type_enseignant    = Security::sanitizeText($old['type_enseignant']    ?? '', 20);
        $grade              = Security::sanitizeText($old['grade']              ?? '', 100);
        $date_nomination    = Security::sanitizeText($old['date_nomination']    ?? '', 10);
        $volume_statutaire  = $old['volume_statutaire']  !== '' ? max(0,(int)$old['volume_statutaire'])  : null;
        $abattement         = $old['abattement']         !== '' ? max(0,(int)$old['abattement'])         : null;
        $motif_abattement   = Security::sanitizeText($old['motif_abattement']   ?? '', 255);
        $volume_apres_abatt = $old['volume_apres_abatt'] !== '' ? max(0,(int)$old['volume_apres_abatt']) : null;
        $etab_rattachement  = Security::sanitizeText($old['etab_rattachement']  ?? '', 150);
        $etab_administratif = Security::sanitizeText($old['etab_administratif'] ?? '', 200);
        $etab_beneficiaire  = Security::sanitizeText($old['etab_beneficiaire']  ?? '', 150);
        $prenom             = Security::sanitizeText($old['prenom']             ?? '', 100);
        $diplome            = Security::sanitizeText($old['diplome']            ?? '', 150);
        $mois_execution     = Security::sanitizeText($old['mois_execution']     ?? '', 100);
        // Récupérer les lignes transmises en hidden (avec _fiche_id pour update sans doublon)
        $lignes = [];
        if (!empty($_POST['l_cours']) && is_array($_POST['l_cours'])) {
            foreach ($_POST['l_cours'] as $i => $cv) {
                $cv = Security::sanitizeText($cv, 150);
                if (empty($cv)) continue;
                $semTmpS = in_array($_POST['l_semestre'][$i] ?? '', ['S1','S2','ENC'])
                           ? $_POST['l_semestre'][$i] : 'S1';
                $encTmpS = !empty($_POST['l_enc'][$i]) || $semTmpS === 'ENC';
                $lignes[] = [
                    'cours'                   => $cv,
                    'code'                    => Security::sanitizeText($_POST['l_code'][$i]      ?? '', 20),
                    'parcours'                => Security::sanitizeText($_POST['l_parcours'][$i]  ?? '', 100),
                    'ntc'                     => Security::sanitizeText($_POST['l_ntc'][$i]       ?? '', 10),
                    'niveau'                  => Security::sanitizeText($_POST['l_niveau'][$i]    ?? ($config['niveaux'][0] ?? ''), 50),
                    'semestre'                => $encTmpS ? 'ENC' : $semTmpS,
                    'volume_cm'               => max(0, min(500, (int)($_POST['l_cm'][$i] ?? 0))),
                    'volume_td'               => max(0, min(500, (int)($_POST['l_td'][$i] ?? 0))),
                    'volume_tp'               => max(0, min(500, (int)($_POST['l_tp'][$i] ?? 0))),
                    'is_encadrement'          => $encTmpS,
                    'etab_beneficiaire_fiche' => $encTmpS ? 0 : (int)($_POST['l_etab_benef'][$i] ?? 0),
                    'dept_beneficiaire_fiche' => $encTmpS ? 0 : (int)($_POST['l_dept_benef'][$i] ?? 0),
                    '_fiche_id'               => (int)($_POST['l_fiche_id'][$i] ?? 0),
                    '_statut'                 => $_POST['l_statut'][$i] ?? '',
                ];
            }
        }

        // Fallback depuis $old si hidden fields manquants
        if (empty($lignes) && !empty($old['lignes'])) {
            $lignes = $old['lignes'];
        }

        $cours = !empty($lignes) ? $lignes[0]['cours'] : '—';

        // Calculer isUJKZ pour le submit (hors scope step1)
        $_rvS = strtolower(trim($etab_rattachement ?? ''));
        $_isUJKZ_submit = ($_rvS === '' || strpos($_rvS,'ujkz')!==false
                        || strpos($_rvS,'ki-zerbo')!==false || strpos($_rvS,'ki zerbo')!==false);

        // etab_beneficiaire est maintenant par ligne — vérifier qu'au moins une ligne existe
        $valid = $security->validateMatricule($matricule)
            && !empty($nom)
            && (
                // Département obligatoire seulement pour IESR UJKZ
                !$_isUJKZ_submit || $type_enseignant === 'vacataire'
                || Security::inWhitelist($departement, $config['departements'])
               )
            && Security::inWhitelist($type_enseignant, ['permanent','vacataire'])
            && !empty($grade)
            && !empty($lignes);

        if (!$valid) {
            $errors['global'] = 'Données invalides. Veuillez recommencer.';
            $step = 1; $old = []; goto render;
        }

        $token              = $security->deriveAccessToken($matricule);
        // Fichiers uploadés en step1 et propagés via hidden fields
        $fichier_diplome    = $old['fichier_diplome']    ?? '';
        $fichier_nomination = $old['fichier_nomination'] ?? '';
        $enseignantId = $repo->upsertEnseignant(
            strtoupper(trim($matricule)), $nom, $departement, $email, $token,
            compact('type_enseignant','grade','date_nomination','volume_statutaire',
                    'abattement','motif_abattement','volume_apres_abatt',
                    'etab_rattachement','etab_administratif','etab_beneficiaire',
                    'prenom','diplome','mois_execution',
                    'fichier_diplome','fichier_nomination')
        );

        // Déterminer le type de workflow selon le profil de l'enseignant
        $typeWorkflow = Auth::typeWorkflow([
            'type_enseignant'  => $type_enseignant,
            'etab_rattachement'=> $etab_rattachement,
        ]);

        // Pour VACATAIRE : statut_vp_eip doit démarrer à 'en_attente' à la fin du circuit
        // (initialisé à 'non_requis', passé à 'en_attente' automatiquement après validation DEI)

        $accessLink = App::url('dashboard.php?token=' . urlencode($token));

        if ($modeEdit && !empty($lignes)) {
            // ── Mode modification : upsert par _fiche_id ──────────────────
            // 1. Collecter les _fiche_id conservés dans le formulaire soumis
            $ficheIdsGardes = [];
            foreach ($lignes as $ligne) {
                $fid = (int)($ligne['_fiche_id'] ?? 0);
                if ($fid > 0) $ficheIdsGardes[] = $fid;
            }

            // 2. Supprimer les fiches orphelines (lignes supprimées par l'enseignant)
            $pdo2 = Database::getInstance();
            if (!empty($ficheIdsGardes)) {
                $placeholders = implode(',', array_fill(0, count($ficheIdsGardes), '?'));
                $stmtDel = $pdo2->prepare(
                    "DELETE FROM fiches WHERE enseignant_id = ? AND id NOT IN ($placeholders)"
                );
                $stmtDel->execute(array_merge([$enseignantId], $ficheIdsGardes));
            } else {
                // Aucune ligne existante conservée : supprimer toutes les anciennes
                $pdo2->prepare("DELETE FROM fiches WHERE enseignant_id = ?")
                     ->execute([$enseignantId]);
            }

            // 3. Update ou create chaque ligne restante
            $dernierId = 0;
            foreach ($lignes as $ligne) {
                $isEnc = !empty($ligne['is_encadrement']) || ($ligne['semestre'] ?? '') === 'ENC';
                // Encadrement uniquement autorisé pour IESR_UJKZ
                if ($isEnc && $typeWorkflow !== 'IESR_UJKZ') { continue; }
                $ficheData = [
                    'cours'            => $ligne['cours'],
                    'code'             => $ligne['code']      ?? '',
                    'code_ue'          => $ligne['code']      ?? '',
                    'parcours'         => $ligne['parcours']  ?? '',
                    'ntc'              => $ligne['ntc']        ?? '',
                    'niveau'           => $ligne['niveau']    ?? ($config['niveaux'][0] ?? ''),
                    'semestre'         => $isEnc ? 'ENC' : ($ligne['semestre'] ?? 'S1'),
                    'volume_cm'        => $ligne['volume_cm'] ?? 0,
                    'volume_td'        => $ligne['volume_td'] ?? 0,
                    'volume_tp'        => $ligne['volume_tp'] ?? 0,
                    'is_encadrement'          => $isEnc ? 1 : 0,
                    'etab_beneficiaire_fiche'  => $isEnc ? 0 : (int)($ligne['etab_beneficiaire_fiche'] ?? 0),
                    'dept_beneficiaire_fiche'  => $isEnc ? 0 : (int)($ligne['dept_beneficiaire_fiche'] ?? 0),
                    'objectifs'               => '',
                    'evaluation'              => $config['evaluations'][1] ?? 'Contrôle continu + examen final',
                    'annee_academique'        => $config['annee_academique'],
                    'type_workflow'           => $typeWorkflow,
                ];
                $existingId = (int)($ligne['_fiche_id'] ?? 0);
                if ($existingId > 0) {
                    $repo->updateFiche($existingId, $enseignantId, $ficheData);
                    $dernierId = $existingId;
                } else {
                    $dernierId = $repo->createFiche($enseignantId, $ficheData);
                }
            }
            $security->audit('fiche_modified', strtoupper(trim($matricule)),
                count($lignes).' cours mis à jour');
            unset($_SESSION['csrf_token']);
            $cours = $lignes[0]['cours'] ?? 'Fiche programmatique';
            $bodyContent = renderTemplate('success', [
                'accessLink' => $accessLink,
                'matricule'  => strtoupper(trim($matricule)),
                'cours'      => $cours,
                'modeEdit'   => true,
                'nbCours'    => count($lignes),
                'config'     => $config,
            ]);

        } else {
            // ── Mode nouvelle fiche : créer N fiches ──────────────────────
            $dernierId = 0;
            foreach ($lignes as $ligne) {
                $isEnc2 = !empty($ligne['is_encadrement']) || ($ligne['semestre'] ?? '') === 'ENC';
                // Encadrement uniquement autorisé pour IESR_UJKZ
                if ($isEnc2 && $typeWorkflow !== 'IESR_UJKZ') { continue; }
                $ficheData = [
                    'cours'            => $ligne['cours'],
                    'code'             => $ligne['code']      ?? '',
                    'code_ue'          => $ligne['code']      ?? '',
                    'parcours'         => $ligne['parcours']  ?? '',
                    'ntc'              => $ligne['ntc']        ?? '',
                    'niveau'           => $ligne['niveau']    ?? ($config['niveaux'][0] ?? ''),
                    'semestre'         => $isEnc2 ? 'ENC' : ($ligne['semestre'] ?? 'S1'),
                    'volume_cm'        => $ligne['volume_cm'] ?? 0,
                    'volume_td'        => $ligne['volume_td'] ?? 0,
                    'volume_tp'        => $ligne['volume_tp'] ?? 0,
                    'is_encadrement'   => $isEnc2 ? 1 : 0,
                    'objectifs'        => '',
                    'evaluation'       => $config['evaluations'][1] ?? 'Contrôle continu + examen final',
                    'annee_academique' => $config['annee_academique'],
                    'type_workflow'    => $typeWorkflow,
                ];
                $existingId = (int)($ligne['_fiche_id'] ?? 0);
                if ($existingId > 0) {
                    $repo->updateFiche($existingId, $enseignantId, $ficheData);
                    $dernierId = $existingId;
                } else {
                    $dernierId = $repo->createFiche($enseignantId, $ficheData);
                }
            }
            $security->audit('fiche_submitted', strtoupper(trim($matricule)),
                count($lignes).' cours soumis');
            unset($_SESSION['csrf_token']);
            if (!empty($email)) {
                $mailer->sendConfirmationSoumission(
                    $email, $nom, $cours,
                    strtoupper(trim($matricule)),
                    $accessLink,
                    $config['annee_academique']
                );
            }
            $bodyContent = renderTemplate('success', [
                'accessLink' => $accessLink,
                'matricule'  => strtoupper(trim($matricule)),
                'cours'      => $cours,
                'modeEdit'   => false,
                'nbCours'    => count($lignes),
                'config'     => $config,
            ]);
        }

        echo renderLayout($modeEdit ? 'Fiche modifiée' : 'Fiche soumise', $bodyContent, $csrfToken);
        exit;
    }
}

render:
$bodyContent = renderTemplate('form', [
    'config'           => $config,
    'csrfToken'        => $csrfToken,
    'errors'           => $errors,
    'old'              => $old,
    'step'             => $step,
    'modeEdit'         => $modeEdit,
    'ficheId'          => $ficheId,
    'nouveauMode'      => $nouveauMode ?? false,
    // Données depuis la BD (avec fallback config statique)
    'etablissements'   => $etablissementsFinaux,
    'etabDepartements' => $etabDepartementsFinaux,
    'departements'     => $config['departements']     ?? [],
    'niveaux'          => $config['niveaux']           ?? [],
    'annee'            => $config['annee_academique']  ?? '',
    // Listes BD pour les selects ID-basés
    'etabsDB'          => $_etabsDB  ?? [],
    'deptsDB'          => $_deptsDB  ?? [],
]);
echo renderLayout(
    $modeEdit ? 'Modifier la fiche' : 'Dépôt de fiche programmatique',
    $bodyContent, $csrfToken
);

function renderTemplate(string $name, array $vars = []): string
{
    extract($vars);
    ob_start();
    require __DIR__ . '/templates/' . $name . '.php';
    return ob_get_clean();
}

function renderLayout(string $title, string $bodyContent, string $csrfToken): string
{
    ob_start();
    require __DIR__ . '/templates/layout.php';
    return ob_get_clean();
}

function collectOld(): array
{
    $keys = [
        'matricule','nom','prenom','diplome','departement','email',
        'type_enseignant','grade','date_nomination',
        'volume_statutaire','abattement','motif_abattement',
        'volume_apres_abatt','etab_rattachement','etab_administratif','etab_beneficiaire',
        'mois_execution','fichier_diplome','fichier_nomination',
        // Rétro-compatibilité champ unique
        'cours','code','parcours','ntc',
        'niveau','semestre','volume_cm','volume_td','objectifs','evaluation',
    ];
    $out = [];
    foreach ($keys as $k) {
        $out[$k] = $_POST[$k] ?? '';
    }
    // Récupérer le tableau multi-cours si présent
    if (!empty($_POST['l_cours']) && is_array($_POST['l_cours'])) {
        $lignes = [];
        foreach ($_POST['l_cours'] as $i => $cv) {
            if (trim((string)$cv) === '') continue;
            $semTmp = $_POST['l_semestre'][$i] ?? 'S1';
            $encTmp = !empty($_POST['l_enc'][$i]) || $semTmp === 'ENC';
            $lignes[] = [
                'cours'                  => $cv,
                'code'                   => $_POST['l_code'][$i]     ?? '',
                'parcours'               => $_POST['l_parcours'][$i] ?? '',
                'ntc'                    => $_POST['l_ntc'][$i]      ?? '',
                'niveau'                 => $_POST['l_niveau'][$i]   ?? '',
                'semestre'               => $encTmp ? 'ENC' : $semTmp,
                'volume_cm'              => $_POST['l_cm'][$i]       ?? '0',
                'volume_td'              => $_POST['l_td'][$i]       ?? '0',
                'volume_tp'              => $_POST['l_tp'][$i]       ?? '0',
                'is_encadrement'         => $encTmp,
                'etab_beneficiaire_fiche'=> $encTmp ? 0 : (int)($_POST['l_etab_benef'][$i] ?? 0),
                'dept_beneficiaire_fiche'=> $encTmp ? 0 : (int)($_POST['l_dept_benef'][$i] ?? 0),
                '_fiche_id'              => (int)($_POST['l_fiche_id'][$i] ?? 0),
                '_statut'                => $_POST['l_statut'][$i] ?? '',
            ];
        }
        if (!empty($lignes)) $out['lignes'] = $lignes;
    } elseif (isset($_POST['lignes_json'])) {
        $decoded = json_decode($_POST['lignes_json'] ?? '[]', true);
        if (is_array($decoded)) $out['lignes'] = $decoded;
    }
    return $out;
}
