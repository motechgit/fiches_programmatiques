-- ============================================================
-- Migration 008 — Workflow de validation conditionnel
-- IESR_UJKZ | IESR_HORS | VACATAIRE
-- Compatible MySQL 5.7+ / MariaDB 10.3+
-- ============================================================
SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ── 1. Rôle VP EIP dans la table utilisateurs ────────────────
ALTER TABLE `utilisateurs`
    MODIFY COLUMN `role`
        ENUM('dei','directeur','directeur_adjoint','chef_dept','vp_eip')
        NOT NULL DEFAULT 'chef_dept';

-- ── 2. Statut VP EIP sur chaque fiche ────────────────────────
ALTER TABLE `fiches`
    ADD COLUMN `statut_vp_eip` ENUM('non_requis','en_attente','valide','rejete')
        NOT NULL DEFAULT 'non_requis' AFTER `statut_dei`;

-- ── 3. Type de workflow (déterminé à la soumission) ──────────
--   IESR_UJKZ    : IESR rattaché UJKZ
--   IESR_HORS    : IESR hors UJKZ
--   VACATAIRE    : Non IESR / Vacataire
ALTER TABLE `fiches`
    ADD COLUMN `type_workflow`
        ENUM('IESR_UJKZ','IESR_HORS','VACATAIRE')
        NOT NULL DEFAULT 'IESR_UJKZ' AFTER `statut_vp_eip`;

-- ── 4. Établissement et département bénéficiaire par fiche ───
--   (remplace la colonne au niveau enseignant pour granularité par ligne)
ALTER TABLE `fiches`
    ADD COLUMN `etab_beneficiaire_fiche`  VARCHAR(150) NOT NULL DEFAULT '' AFTER `type_workflow`,
    ADD COLUMN `dept_beneficiaire_fiche`  VARCHAR(150) NOT NULL DEFAULT '' AFTER `etab_beneficiaire_fiche`;

-- ── 5. Colonne diplôme/nomination pour les vacataires ────────
ALTER TABLE `enseignants`
    ADD COLUMN `fichier_diplome`    VARCHAR(255) NOT NULL DEFAULT '' AFTER `mois_execution`,
    ADD COLUMN `fichier_nomination` VARCHAR(255) NOT NULL DEFAULT '' AFTER `fichier_diplome`;

-- ── 6. Table nominations (actes générés / validés par VP EIP) ─
CREATE TABLE IF NOT EXISTS `nominations` (
    `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `enseignant_id`   INT UNSIGNED  NOT NULL,
    `annee_academique` VARCHAR(10)  NOT NULL,
    `fichier_acte`    VARCHAR(255)  NOT NULL DEFAULT '',
    `statut`          ENUM('en_attente','valide','rejete') NOT NULL DEFAULT 'en_attente',
    `valide_par`      INT UNSIGNED  DEFAULT NULL,
    `valide_le`       DATETIME      DEFAULT NULL,
    `motif_rejet`     TEXT          DEFAULT NULL,
    `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_nom_ens`  (`enseignant_id`),
    KEY `idx_nom_stat` (`statut`),

    CONSTRAINT `fk_nom_enseignant` FOREIGN KEY (`enseignant_id`)
        REFERENCES `enseignants`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_nom_valideur`   FOREIGN KEY (`valide_par`)
        REFERENCES `utilisateurs`(`id`) ON DELETE SET NULL

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 7. Ajouter VP EIP dans la table validations_fiche ────────
ALTER TABLE `validations_fiche`
    MODIFY COLUMN `role`
        ENUM('chef_dept','directeur_adjoint','directeur','dei','vp_eip') NOT NULL;

-- ── 8. Mettre à jour les fiches existantes ───────────────────
--   Les fiches existantes sont supposées IESR_UJKZ (défaut)
UPDATE `fiches` SET `type_workflow` = 'IESR_UJKZ' WHERE `type_workflow` = 'IESR_UJKZ';
--   Marquer statut_vp_eip = non_requis pour toutes les fiches existantes
UPDATE `fiches` SET `statut_vp_eip` = 'non_requis' WHERE `type_workflow` != 'VACATAIRE';

SET foreign_key_checks = 1;
