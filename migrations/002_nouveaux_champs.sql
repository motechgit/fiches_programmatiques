-- ============================================================
-- Migration 002 — Nouveaux champs enseignants, fiches et suivi
-- Compatible MySQL 5.7+ et MariaDB 10.3+
-- Exécuter dans phpMyAdmin : base fiches_programmatiques > Importer
-- ============================================================

-- ── Table enseignants ────────────────────────────────────────
ALTER TABLE `enseignants`
    ADD COLUMN `type_enseignant`    ENUM('permanent','vacataire') NOT NULL DEFAULT 'permanent'  AFTER `email`,
    ADD COLUMN `grade`              VARCHAR(100) NOT NULL DEFAULT ''   AFTER `type_enseignant`,
    ADD COLUMN `date_nomination`    DATE         DEFAULT NULL           AFTER `grade`,
    ADD COLUMN `volume_statutaire`  SMALLINT UNSIGNED DEFAULT NULL      AFTER `date_nomination`,
    ADD COLUMN `abattement`         SMALLINT UNSIGNED DEFAULT NULL      AFTER `volume_statutaire`,
    ADD COLUMN `motif_abattement`   VARCHAR(255) NOT NULL DEFAULT ''    AFTER `abattement`,
    ADD COLUMN `volume_apres_abatt` SMALLINT UNSIGNED DEFAULT NULL      AFTER `motif_abattement`,
    ADD COLUMN `etab_rattachement`  VARCHAR(150) NOT NULL DEFAULT ''    AFTER `volume_apres_abatt`,
    ADD COLUMN `etab_beneficiaire`  VARCHAR(150) NOT NULL DEFAULT ''    AFTER `etab_rattachement`;

-- ── Table fiches ─────────────────────────────────────────────
ALTER TABLE `fiches`
    ADD COLUMN `code`             VARCHAR(50)  NOT NULL DEFAULT '' AFTER `code_ue`,
    ADD COLUMN `parcours`         VARCHAR(150) NOT NULL DEFAULT '' AFTER `code`,
    ADD COLUMN `ntc`              VARCHAR(50)  NOT NULL DEFAULT '' AFTER `parcours`,
    ADD COLUMN `modifie_le`       DATETIME DEFAULT NULL            AFTER `updated_at`,
    ADD COLUMN `nb_modifications` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `modifie_le`;
