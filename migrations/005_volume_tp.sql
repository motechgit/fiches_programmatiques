-- ============================================================
-- Migration 005 — Colonne TP dans fiches et preuves
-- Compatible MySQL 5.7+ / MariaDB 10.3+
-- ============================================================
ALTER TABLE `fiches`
    ADD COLUMN `volume_tp` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `volume_td`;

ALTER TABLE `preuves`
    ADD COLUMN `volume_tp_effectue` SMALLINT UNSIGNED DEFAULT NULL AFTER `volume_td_effectue`;
