-- ============================================================
-- Migration 004 — Volumes effectués dans les justificatifs
-- Compatible MySQL 5.7+ / MariaDB 10.3+
-- ============================================================
ALTER TABLE `preuves`
    ADD COLUMN `volume_cm_effectue` SMALLINT UNSIGNED DEFAULT NULL AFTER `taille`,
    ADD COLUMN `volume_td_effectue` SMALLINT UNSIGNED DEFAULT NULL AFTER `volume_cm_effectue`,
    ADD COLUMN `commentaire`        VARCHAR(500) NOT NULL DEFAULT '' AFTER `volume_td_effectue`;
