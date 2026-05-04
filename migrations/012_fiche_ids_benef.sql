-- ============================================================
-- Migration 012 — etab_beneficiaire_fiche et dept_beneficiaire_fiche
-- Passage de VARCHAR (noms) à INT UNSIGNED (IDs)
-- Compatible MySQL 5.7+ / MariaDB 10.3+
-- ============================================================
SET NAMES utf8mb4;

-- 1. Modifier les colonnes
ALTER TABLE `fiches`
    MODIFY COLUMN `etab_beneficiaire_fiche` INT UNSIGNED NOT NULL DEFAULT 0,
    MODIFY COLUMN `dept_beneficiaire_fiche` INT UNSIGNED NOT NULL DEFAULT 0;

-- 2. Ajouter les clés étrangères (optionnel mais conseillé)
-- ALTER TABLE `fiches`
--   ADD CONSTRAINT `fk_fiche_etab_benef` FOREIGN KEY (`etab_beneficiaire_fiche`)
--       REFERENCES `etablissements`(`id`) ON DELETE SET DEFAULT ON UPDATE CASCADE,
--   ADD CONSTRAINT `fk_fiche_dept_benef` FOREIGN KEY (`dept_beneficiaire_fiche`)
--       REFERENCES `departements`(`id`) ON DELETE SET DEFAULT ON UPDATE CASCADE;

-- 3. Ajouter les index pour les jointures
ALTER TABLE `fiches`
    ADD INDEX `idx_etab_benef_fiche` (`etab_beneficiaire_fiche`),
    ADD INDEX `idx_dept_benef_fiche` (`dept_beneficiaire_fiche`);

-- 4. Mettre à jour utilisateurs : departement_id depuis la colonne texte
-- (si la colonne departement correspond à "nom (sigle)" dans la table departements)
UPDATE `utilisateurs` u
JOIN `departements` d ON CONCAT(d.nom, IF(d.sigle != '', CONCAT(' (', d.sigle, ')'), '')) = u.departement
SET u.departement_id = d.id
WHERE u.departement_id IS NULL AND u.departement != '';

-- 5. Mettre à jour utilisateurs : etablissement_id depuis le JSON
-- (à faire manuellement via admin_etabs.php car le JSON peut contenir plusieurs étabs)
