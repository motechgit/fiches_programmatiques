-- ============================================================
-- Migration 010 — Ajout colonne etab_administratif
-- Établissement de rattachement administratif UJKZ
-- (distinct de etab_rattachement = IESR université nationale)
-- ============================================================

ALTER TABLE `enseignants`
    ADD COLUMN `etab_administratif` VARCHAR(200) NOT NULL DEFAULT ''
    AFTER `etab_rattachement`;
