-- ============================================================
-- Migration 003 — Utilisateurs multi-rôles, validations et preuves
-- Compatible MySQL 5.7+ / MariaDB 10.3+
-- ============================================================
SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ── Table utilisateurs (portal admin multi-rôles) ────────────
-- Rôles : dei (super admin), directeur, directeur_adjoint, chef_dept
CREATE TABLE IF NOT EXISTS `utilisateurs` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `nom`         VARCHAR(100)  NOT NULL,
    `login`       VARCHAR(60)   NOT NULL,
    `password`    VARCHAR(255)  NOT NULL,
    `role`        ENUM('dei','directeur','directeur_adjoint','chef_dept')
                                NOT NULL DEFAULT 'chef_dept',
    -- Scope : département (chef_dept) ou établissement (directeur/directeur_adjoint)
    -- NULL pour DEI (accès global)
    `departement` VARCHAR(100)  DEFAULT NULL,
    `etablissement` VARCHAR(150) DEFAULT NULL,
    `actif`       TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_login` (`login`),
    KEY `idx_role` (`role`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Table validations_fiche ──────────────────────────────────
-- Trace chaque étape de validation d'une fiche
CREATE TABLE IF NOT EXISTS `validations_fiche` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `fiche_id`        INT UNSIGNED NOT NULL,
    `utilisateur_id`  INT UNSIGNED NOT NULL,
    `role`            ENUM('chef_dept','directeur_adjoint','directeur','dei') NOT NULL,
    `decision`        ENUM('valide','rejete')  NOT NULL,
    `motif_rejet`     TEXT                     DEFAULT NULL,
    `created_at`      DATETIME                 NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_fiche`     (`fiche_id`),
    KEY `idx_util`      (`utilisateur_id`),
    KEY `idx_role_dec`  (`role`, `decision`),

    CONSTRAINT `fk_val_fiche` FOREIGN KEY (`fiche_id`)
        REFERENCES `fiches`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_val_util`  FOREIGN KEY (`utilisateur_id`)
        REFERENCES `utilisateurs`(`id`) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Nouvelle colonne statut détaillé sur fiches ──────────────
ALTER TABLE `fiches`
    ADD COLUMN `statut_chef`     ENUM('en_attente','valide','rejete') NOT NULL DEFAULT 'en_attente' AFTER `statut`,
    ADD COLUMN `statut_dir_adj`  ENUM('en_attente','valide','rejete') NOT NULL DEFAULT 'en_attente' AFTER `statut_chef`,
    ADD COLUMN `statut_dir`      ENUM('en_attente','valide','rejete') NOT NULL DEFAULT 'en_attente' AFTER `statut_dir_adj`,
    ADD COLUMN `statut_dei`      ENUM('en_attente','valide','rejete') NOT NULL DEFAULT 'en_attente' AFTER `statut_dir`;

-- ── Table preuves ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `preuves` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `fiche_id`    INT UNSIGNED NOT NULL,
    `nom_original` VARCHAR(255) NOT NULL,
    `nom_stockage` VARCHAR(100) NOT NULL,
    `type_mime`   VARCHAR(100) NOT NULL,
    `taille`      INT UNSIGNED NOT NULL DEFAULT 0,
    `uploaded_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_preuve_fiche` (`fiche_id`),

    CONSTRAINT `fk_preuve_fiche` FOREIGN KEY (`fiche_id`)
        REFERENCES `fiches`(`id`) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
