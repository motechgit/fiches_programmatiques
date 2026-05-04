-- ============================================================
-- Migration 013 — Correction : insérer les validations manquantes
-- Pour les fiches dont statut_dei=valide mais sans entrée dans validations_fiche
-- ============================================================

-- Insérer les validations DEI manquantes
-- Utilise l'utilisateur DEI existant (id=1 ou le premier trouvé)
INSERT INTO `validations_fiche` (fiche_id, utilisateur_id, role, decision, created_at)
SELECT
    f.id,
    (SELECT id FROM utilisateurs WHERE role = 'dei' LIMIT 1),
    'dei',
    'valide',
    f.updated_at
FROM fiches f
WHERE f.statut_dei = 'valide'
  AND NOT EXISTS (
    SELECT 1 FROM validations_fiche v
    WHERE v.fiche_id = f.id AND v.role = 'dei'
  );

-- Insérer les validations directeur manquantes
INSERT INTO `validations_fiche` (fiche_id, utilisateur_id, role, decision, created_at)
SELECT
    f.id,
    (SELECT id FROM utilisateurs WHERE role = 'directeur' LIMIT 1),
    'directeur',
    'valide',
    f.updated_at
FROM fiches f
WHERE f.statut_dir = 'valide'
  AND NOT EXISTS (
    SELECT 1 FROM validations_fiche v
    WHERE v.fiche_id = f.id AND v.role = 'directeur'
  );

-- Insérer les validations directeur_adjoint manquantes
INSERT INTO `validations_fiche` (fiche_id, utilisateur_id, role, decision, created_at)
SELECT
    f.id,
    (SELECT id FROM utilisateurs WHERE role = 'directeur_adjoint' LIMIT 1),
    'directeur_adjoint',
    'valide',
    f.updated_at
FROM fiches f
WHERE f.statut_dir_adj = 'valide'
  AND NOT EXISTS (
    SELECT 1 FROM validations_fiche v
    WHERE v.fiche_id = f.id AND v.role = 'directeur_adjoint'
  );
