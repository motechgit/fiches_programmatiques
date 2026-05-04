# Fiche Détaillée — Projet Fiches Programmatiques UJKZ

## Contexte
Application web PHP/MySQL de gestion des déclarations de charges d'enseignement pour l'**Université Joseph KI-ZERBO (UJKZ)**, Burkina Faso. Interface en français. Développée pour KONATE.

- **Environnement** : WampServer, PHP 7.1+, MySQL 5.7
- **URL locale** : `http://127.0.0.1/fiches_programmatiques/`
- **Dossier source** : `/home/claude/fiches_prod/fiches_prod/`
- **ZIP déploiement** : `/mnt/user-data/outputs/fiches_programmatiques_PRODUCTION.zip`
- **Année académique courante** : `2024-2025` (dans `config/security.php`)

---

## Architecture des fichiers

```
index.php                    # Contrôleur formulaire step1→step2→submit
dashboard.php                # Tableau de bord enseignant (accès par token)
portail.php                  # Portail validateurs (chef_dept, dir_adj, dir, dei, vp_eip)
valider_fiche.php            # Validation/rejet individuelle d'une fiche
valider_fiche_global.php     # Validation/rejet de toutes les fiches d'un enseignant
vp_eip_nomination.php        # Portail VP EIP — liste vacataires en attente + validés
generer_nomination.php       # Acte de nomination PDF (HTML imprimable)
dossier_vacataire.php        # Dossier complet vacataire (fiche + docs + nomination)
admin_etabs.php              # Gestion établissements et départements (DEI)
admin_utilisateurs.php       # Gestion utilisateurs (DEI)
login.php                    # Connexion validateurs
src/Auth.php                 # Authentification, ficheScope(), peutValider(), startSession()
src/FicheRepository.php      # CRUD fiches et enseignants (upsertEnseignant)
src/ValidationRepository.php # Workflow validation, getHistorique(), getEnseignantsPourPortail()
src/EtabRepository.php       # CRUD établissements/départements
templates/form.php           # Formulaire unifié 2 étapes (step1 saisie, step2 aperçu)
templates/dashboard.php      # Fiche programmatique enseignant
templates/layout.php         # Thème UJKZ (CSS variables, header vert, footer)
templates/fiche_detail.php   # Détail fiche avec historique validation
templates/fiche_print.php    # Version imprimable
uploads/                     # Fichiers diplômes/nominations vacataires
logo_ujkz.jpg               # Logo officiel (intégré en base64 dans acte nomination)
migrations/001→014.sql       # Migrations SQL cumulatives
```

---

## Base de données

### Tables principales

**`enseignants`** — un enregistrement par enseignant identifié par matricule unique
```
id, matricule (UNIQUE), nom, prenom, diplome, departement, departement_id,
email, token (CHAR 64, accès dashboard sans mot de passe), type_enseignant
ENUM('permanent','vacataire'), grade, date_nomination, volume_statutaire,
abattement, motif_abattement, volume_apres_abatt, etab_rattachement,
etab_administratif, etab_beneficiaire, mois_execution,
fichier_diplome VARCHAR(255), fichier_nomination VARCHAR(255)
```

**`fiches`** — une ligne par cours déclaré
```
id, enseignant_id, cours, code, parcours, ntc, niveau, semestre ENUM('S1','S2','ENC'),
volume_cm, volume_td, volume_tp, is_encadrement TINYINT(1),
statut ENUM('en_attente','validee','rejetee'),
statut_chef, statut_dir_adj, statut_dir, statut_dei
  — tous ENUM('en_attente','valide','rejete'),
statut_vp_eip ENUM('non_requis','en_attente','valide','rejete'),
type_workflow ENUM('IESR_UJKZ','IESR_HORS','VACATAIRE'),
etab_beneficiaire_fiche INT UNSIGNED,   ← ID dans table etablissements
dept_beneficiaire_fiche INT UNSIGNED,   ← ID dans table departements
annee_academique, submitted_at, updated_at
```

**`validations_fiche`** — historique des décisions de validation
```
id, fiche_id, utilisateur_id, role ENUM('chef_dept','directeur_adjoint',
'directeur','dei','vp_eip'), decision ENUM('valide','rejete'),
motif_rejet TEXT, created_at
```
**Clé importante** : colonne `v.role AS etape_role` — toujours utiliser `etape_role` (pas `valideur_role` ni `u.role`) pour identifier l'étape.

**`nominations`** — une par vacataire par année
```
id, enseignant_id, annee_academique, statut ENUM('en_attente','valide','rejete'),
valide_par (utilisateur_id), valide_le, motif_rejet
```

**`etablissements`** — 13 établissements UJKZ (UFR/SVT, UFR/SEA, etc.)
```
id, nom, sigle, ordre, actif
```

**`departements`** — 24 départements
```
id, nom, sigle, etablissement_id (INT FK), ordre, actif
```

**`utilisateurs`** — validateurs
```
id, login, password_hash, nom, role ENUM('chef_dept','directeur_adjoint',
'directeur','dei','vp_eip'), departement, departement_id INT,
etablissement, etablissement_id INT, actif
```

---

## Types de workflow

| Type | Description | Circuit de validation |
|------|-------------|----------------------|
| `IESR_UJKZ` | Enseignant permanent UJKZ | chef_dept → dir_adj → dir → DEI |
| `IESR_HORS` | Enseignant IESR hors UJKZ | chef_dept(bénéf.) → dir_adj(bénéf.) → dir(bénéf.) → DEI |
| `VACATAIRE` | Enseignant vacataire | chef_dept(bénéf.) → dir_adj(bénéf.) → dir(bénéf.) → DEI → VP EIP → nomination |

**Périmètre par rôle (`ficheScope()` dans Auth.php)** :
- `chef_dept` : `(f.type_workflow='IESR_UJKZ' AND e.departement=dept_nom) OR (f.type_workflow!='IESR_UJKZ' AND f.dept_beneficiaire_fiche=dept_id)`
- `directeur/adj` : similaire avec `etab_beneficiaire_fiche IN (etab_ids)`
- `dei`, `vp_eip` : scope vide (tout visible)
- **Résolution auto** : si `dept_id=0` ou `etab_ids=[]` en session → requête BD dans `ficheScope()` pour résoudre sans reconnexion

---

## Règles critiques PHP

### Compatibilité PHP 7.1
- **Jamais** : `match()`, `fn()` (arrow functions), `str_contains()`, `ADD COLUMN IF NOT EXISTS` (MySQL 5.7)
- `$e = function($v){ return Security::e((string)$v); };` — ne jamais utiliser `$e` comme variable de boucle

### Propagation du matricule vacataire
- Upload fichiers traité en **step1** (pas submit) car `$_FILES` est vide en step2
- `fichier_diplome` et `fichier_nomination` doivent être dans :
  1. `$keys` de `collectOld()` dans index.php
  2. Le foreach step2 dans form.php (`'mois_execution','fichier_diplome','fichier_nomination'`)
  3. `compact(...)` dans le bloc submit
  4. UPDATE de `upsertEnseignant()` avec `IF(?<>'',?,fichier_diplome)`

### Session et authentification
- `Auth::check()` timeout = **28800s (8h)**
- Si `user_id` absent mais `user_role` présent → `Auth::check()` restaure `user_id` depuis BD via `user_login`
- `user_since` réinitialisé si absent (sessions anciennes)

### Historique des validations
- `getHistorique()` retourne `v.role AS etape_role` — utiliser `$h['etape_role'] ?? $h['role'] ?? ''`
- `$historiqueGlobal` indexé par `etape_role` dans dashboard.php
- `$sigHistoMap` dans portail.php construit sur toutes les fiches (pas seulement `fiches[0]`)

### `$modeEdit` et `_fiche_id`
- `$modeEdit = true` quand des fiches existent en BD
- `_fiche_id` propagé step1→step2→submit pour éviter la duplication
- `nouveau=1` GET parameter → force `$modeEdit = false`
- `upsertEnseignant()` UPDATE doit inclure `token=?`

---

## Formulaire (templates/form.php + index.php)

### Champs step1
- Sélecteur visuel type enseignant : `permanent` (IESR UJKZ/HORS) ou `vacataire`
- JS `toggleTypeEns(type)` masque/affiche :
  - `bloc_etab_rattach` (masqué si vacataire)
  - `bloc_dept_admin` (masqué si vacataire ou IESR hors UJKZ)
  - `bloc_upload_diplome` (visible si vacataire)
  - `tr-heures-sup` (masqué si vacataire ou IESR hors UJKZ)
- `IS_UJKZ` variable JS calculée depuis PHP
- `$isUJKZ` calculé **une seule fois** en haut de form.php
- Matricule vacataire : généré automatiquement par `generer_matricule.php`, **préservé en modification** (ne pas régénérer si `champ.value !== ''`)

### Tableau lignes de cours
- Colonnes : semestre, code, parcours, cours, ntc, CM, TD, TP, niveau, étab bénéficiaire (select ID), département (select ID dynamique JS)
- `etab_beneficiaire_fiche` et `dept_beneficiaire_fiche` = IDs entiers en BD
- Reconstruction `$lignes` depuis `$_POST` inclut `etab_beneficiaire_fiche` et `dept_beneficiaire_fiche`

### Aperçu step2
- Masquer si vacataire ou IESR hors UJKZ : `volume_statutaire`, `abattement`, `etab_administratif`, `heures_sup`
- Hidden fields step2 incluent `fichier_diplome` et `fichier_nomination`

---

## Portail validateurs (portail.php)

### Liste enseignants
- `getEnseignantsPourPortail($filters)` : requête avec `GROUP BY e.id`
- Filtrage statut via **HAVING** (pas WHERE) :
  - "À traiter" : `HAVING MAX(statut_chef='en_attente')=1` (selon rôle)
  - "Validées" : `HAVING MAX(statut_chef='valide')=1` (selon rôle — ce que le validateur a fait)
  - "Rejetées" : `HAVING SUM(statut='rejetee')>0`
- Onglets statut = **liens `<a href>`** (pas boutons dans form pour éviter conflit hidden `statut`)
- Boutons **📁 Dossier** et **📄 Acte** pour vacataires (DEI et VP EIP)

### Vue individuelle (portail.php?ens=X)
- `getFichesEnseignantPortail($ensId)` filtrée par `ficheScope()`
- `renderSemBlock()` : tableau des cours avec bouton "Décider"
- `dansPerietre($fiche)` : pour IESR_HORS/VACATAIRE, conditionne le bouton "Décider" selon étab/dept bénéficiaire
- `$ficheEstMixte` : si cours de plusieurs périmètres → masquer "Valider tous / Rejeter tout"
- Encadrement : affiché après S2, uniquement pour IESR_UJKZ
- Signatures : `$sigHistoMap` construit sur toutes les fiches, VP EIP exclu des signatures fiche programmatique

---

## Dashboard enseignant (dashboard.php + templates/dashboard.php)

- Accès par URL token : `dashboard.php?token=XXX`
- `$showVolD` / `$showVolP` : `false` si vacataire ou IESR hors UJKZ → masquer volumes statutaires
- Bouton "Nouvelle fiche" : masqué pour IESR UJKZ permanent
- Upload preuves AJAX inline (onglets suivi)

---

## VP EIP (vp_eip_nomination.php)

- Liste vacataires avec `statut_dei='valide'` et `statut_vp_eip='en_attente'`
- Décision → INSERT dans `nominations`, UPDATE `statut_vp_eip` et `statut`
- Après validation VP EIP : `statut='validee'`
- Boutons : **📁 Dossier** et **📄 Acte PDF** pour nominations validées

---

## Acte de nomination (generer_nomination.php)

- Format officiel UJKZ fidèle au document scanné
- En-tête 3 colonnes : hiérarchie institutionnelle + logo base64 + devise nationale
- 25 considérants "Vu" + "Sur proposition du Directeur de l'UFR"
- Articles 1-4 avec variables dynamiques
- Signataire : VP EIP (`u.nom` — pas `u.prenom`, colonne inexistante)
- Accessible par : `vp_eip` et `dei`

---

## Dossier vacataire (dossier_vacataire.php)

- Accès : tous validateurs (dei, vp_eip, directeur, directeur_adjoint, chef_dept)
- Vérification souple : `user_role` seul suffit si `user_id` absent
- Contenu : identité enseignant + documents (diplôme, nomination) + fiche programmatique + circuit validation
- Lien vers acte si `nominations.statut='valide'`

---

## Admin établissements (admin_etabs.php)

- Accessible DEI uniquement
- Thème UJKZ via `templates/layout.php`
- Onglets : Établissements / Départements / Vue d'ensemble
- Modals création/édition
- Lier directeurs aux établissements, chefs aux départements
- `resolveEtabDept()` définie au niveau **global** (pas dans `renderSemBlock`)

---

## Fonctions utilitaires globales (portail.php)

```php
dansPerietre(array $f): bool        // Fiche dans périmètre du validateur courant
filterFichesParPerimetre(array $f)  // Filtre tableau de fiches
resolveEtabDept(PDO $pdo): array    // Retourne [etabById, deptById]
```

---

## Bugs corrigés (historique session)

| Bug | Cause | Fix |
|-----|-------|-----|
| `etab/dept=0` après enregistrement | Bloc reconstruction `$_POST` dans form.php sans etab/dept | Ajout dans reconstruction |
| Nouveau enseignant créé à chaque modif vacataire | JS régénérait matricule même si champ non vide | `if (champ.value !== '') return` |
| Upload diplôme jamais sauvegardé | Upload en bloc submit (`$_FILES` vide) + `fichier_diplome` absent de `$keys` | Upload en step1 + ajout dans `$keys` |
| Fiche disparaît après validation | HAVING sur `statut_col='valide'` au lieu de `statut_col='en_attente'` | HAVING par étape de rôle |
| Onglets statut portail inefficaces | Double `name="statut"` dans form | Onglets → liens `<a href>` |
| DEI accès refusé (`check=false`) | Timeout 30min + `user_id` effacé après logout partiel | Timeout 8h + restauration `user_id` depuis BD |
| `u.prenom` inexistant | Colonne absente de `utilisateurs` | Supprimé de toutes les requêtes |
| `resolveEtabDept` undefined | Définie dans `renderSemBlock`, appelée avant | Déplacée au niveau global |

---

## Migrations à exécuter (ordre)

```
001 → 014 dans l'ordre
012 : etab/dept_beneficiaire_fiche → INT UNSIGNED
014 : ADD COLUMN fichier_diplome + fichier_nomination
      (exécuter chaque ALTER séparément, ignorer erreur 1060 si déjà existantes)
```
