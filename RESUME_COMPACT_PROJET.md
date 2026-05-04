# Contexte projet — Fiches Programmatiques UJKZ (résumé compact)

## Stack & environnement
PHP 7.1+ / MySQL 5.7 / WampServer local. **Jamais** : `match()`, `fn()`, `str_contains()`, `ADD COLUMN IF NOT EXISTS`. Dossier : `/home/claude/fiches_prod/fiches_prod/`. ZIP : `/mnt/user-data/outputs/fiches_programmatiques_PRODUCTION.zip`. Année : `2024-2025`.

## Tables BD clés

**`fiches`** : `id, enseignant_id, cours, semestre, volume_cm/td/tp, is_encadrement, statut, statut_chef, statut_dir_adj, statut_dir, statut_dei, statut_vp_eip, type_workflow ENUM('IESR_UJKZ','IESR_HORS','VACATAIRE'), etab_beneficiaire_fiche INT, dept_beneficiaire_fiche INT, annee_academique`

**`enseignants`** : `id, matricule UNIQUE, nom, prenom, grade, type_enseignant ENUM('permanent','vacataire'), volume_statutaire, etab_rattachement, fichier_diplome VARCHAR(255), fichier_nomination VARCHAR(255), token CHAR(64)`

**`validations_fiche`** : `id, fiche_id, utilisateur_id, role ENUM(...), decision, created_at` → colonne clé = `v.role AS etape_role` (jamais `u.role AS valideur_role`)

**`nominations`** : `id, enseignant_id, annee_academique, statut, valide_par, valide_le`

**`etablissements`** / **`departements`** : `id, nom, sigle, (etablissement_id pour depts)`

**`utilisateurs`** : `id, login, nom, role ENUM('chef_dept','directeur_adjoint','directeur','dei','vp_eip'), departement_id INT, etablissement_id INT` — **pas de colonne `prenom`**

## Workflows validation
- `IESR_UJKZ` : chef_dept → dir_adj → dir → DEI
- `IESR_HORS` : chef_dept(bénéf.) → dir_adj(bénéf.) → dir(bénéf.) → DEI
- `VACATAIRE` : chef_dept(bénéf.) → dir_adj(bénéf.) → dir(bénéf.) → DEI → VP EIP → nomination

## Périmètre (ficheScope dans Auth.php)
- `chef_dept` : IESR_UJKZ → `e.departement=nom` ; autres → `f.dept_beneficiaire_fiche=dept_id`
- `directeur/adj` : IESR_UJKZ → `e.etab_rattachement IN(noms)` ; autres → `f.etab_beneficiaire_fiche IN(ids)`
- `dei/vp_eip` : scope vide (tout)
- **Résolution auto des IDs** dans `ficheScope()` si `dept_id=0` ou `etab_ids=[]`

## Règles critiques

**Upload fichiers vacataires** : traité en **step1** (pas submit, `$_FILES` vide en step2). `fichier_diplome`/`fichier_nomination` doivent être dans : (1) `$keys` de `collectOld()`, (2) foreach step2 form.php, (3) `compact()` du submit, (4) UPDATE `upsertEnseignant()` avec `IF(?<>'',?,col)`.

**Matricule vacataire** : JS ne régénère pas si `champ.value !== ''` (modification).

**`$modeEdit`** : `true` si fiches en BD. `_fiche_id` propagé step1→step2→submit. `nouveau=1` → force nouvelle fiche. `upsertEnseignant()` UPDATE inclut `token=?`.

**Historique** : `v.role AS etape_role` — toujours `$h['etape_role'] ?? $h['role'] ?? ''`.

**Auth::check()** : timeout 8h. Si `user_id` absent mais `user_role` présent → restaure `user_id` depuis BD via `user_login`.

**`$isUJKZ`** : calculé **une seule fois** en haut de form.php.

**`resolveEtabDept()`** : définie au niveau **global** de portail.php (pas dans `renderSemBlock`).

**Filtrage portail** : onglets statut = liens `<a href>` (pas boutons form). HAVING par étape rôle.

**`u.prenom`** n'existe pas dans `utilisateurs` — utiliser `u.nom` uniquement.

## Fichiers principaux
```
index.php (705L)          # Contrôleur form step1→step2→submit
templates/form.php (1569L) # Formulaire unifié
templates/dashboard.php (1125L) # Fiche programmatique enseignant
portail.php (1128L)        # Portail validateurs
src/Auth.php (427L)        # Auth + ficheScope + peutValider
src/FicheRepository.php    # upsertEnseignant (UPDATE inclut fichier_diplome)
src/ValidationRepository.php # getEnseignantsPourPortail (HAVING) + getHistorique (etape_role)
vp_eip_nomination.php      # Portail VP EIP nominations vacataires
generer_nomination.php     # Acte PDF officiel UJKZ (logo base64, 25 Vu, 4 articles)
dossier_vacataire.php      # Dossier complet vacataire
admin_etabs.php            # Gestion étab/dept (thème UJKZ via layout.php)
```

## Accès pages sensibles
- `generer_nomination.php` : `vp_eip`, `dei` (vérification souple — pas `Auth::check()` strict)
- `dossier_vacataire.php` : tous validateurs (vérification souple)
- `admin_etabs.php` : `dei` uniquement

## Fonctions globales portail.php
```php
dansPerietre(array $f): bool       // IESR_HORS/VAC : vérifie etab/dept bénéficiaire
filterFichesParPerimetre(array $f) // Filtre fiche de suivi par périmètre
resolveEtabDept(PDO $pdo): array   // [etabById, deptById]
```

## Affichage conditionnel
- Vacataire : masquer vol.statutaire, abattement, étab.admin, heures_sup, Nouvelle fiche, encadrement, signature VP EIP fiche programmatique
- IESR hors UJKZ : masquer vol.statutaire, abattement, étab.admin, heures_sup
- Bouton "Décider" : conditionné par `dansPerietre($fiche)`
- Fiche mixte (cours multi-périmètres) : masquer "Valider tous / Rejeter tout"
