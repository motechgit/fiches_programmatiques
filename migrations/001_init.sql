-- ============================================================
-- Fiche Programmatique — Schéma MySQL/MariaDB sécurisé
-- Encodage : UTF-8mb4 (support complet Unicode + emojis)
-- ============================================================
-- Exécution : depuis phpMyAdmin ou ligne de commande :
--   mysql -u root -p fiches_programmatiques < migrations/001_init.sql
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;

-- ── Table enseignants ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `enseignants` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `matricule`   VARCHAR(20)     NOT NULL,
    `nom`         VARCHAR(100)    NOT NULL,
    `departement` VARCHAR(100)    NOT NULL,
    `email`       VARCHAR(150)    NOT NULL DEFAULT '',
    -- Token HMAC-SHA256 (64 hex chars) — accès tableau de bord sans mot de passe
    `token`       CHAR(64)        NOT NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_matricule` (`matricule`),
    UNIQUE KEY `uq_token`     (`token`)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ── Table fiches ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `fiches` (
    `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `enseignant_id`    INT UNSIGNED    NOT NULL,
    `cours`            VARCHAR(150)    NOT NULL,
    `code_ue`          VARCHAR(20)     NOT NULL DEFAULT '',
    `niveau`           VARCHAR(50)     NOT NULL,
    `semestre`         VARCHAR(5)      NOT NULL,
    `volume_cm`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `volume_td`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `objectifs`        TEXT            NOT NULL,
    `evaluation`       VARCHAR(100)    NOT NULL,
    `statut`           ENUM('en_attente','validee','rejetee')
                                       NOT NULL DEFAULT 'en_attente',
    `annee_academique` VARCHAR(10)     NOT NULL,
    `submitted_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_enseignant` (`enseignant_id`),
    KEY `idx_statut`     (`statut`),

    CONSTRAINT `fk_fiche_enseignant`
        FOREIGN KEY (`enseignant_id`)
        REFERENCES `enseignants` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ── Table audit_log ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `audit_log` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `action`     VARCHAR(60)  NOT NULL,
    `matricule`  VARCHAR(20)  DEFAULT NULL,
    `ip_address` VARCHAR(45)  DEFAULT NULL,
    `user_agent` VARCHAR(200) DEFAULT NULL,
    `detail`     VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_audit_action`    (`action`),
    KEY `idx_audit_matricule` (`matricule`),
    KEY `idx_audit_created`   (`created_at`)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ── Table rate_limit ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `rate_limit` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ip_address`   VARCHAR(45)  NOT NULL,
    `action`       VARCHAR(30)  NOT NULL,
    `attempts`     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `window_start` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ip_action` (`ip_address`, `action`),
    KEY `idx_window` (`window_start`)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
