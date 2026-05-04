-- ============================================================
-- Migration 006 — Champs complémentaires enseignants
-- prenom, diplome, mois_execution
-- Compatible MySQL 5.7+ / MariaDB 10.3+
-- ============================================================
ALTER TABLE `enseignants`
    ADD COLUMN `prenom`         VARCHAR(100) NOT NULL DEFAULT '' AFTER `nom`,
    ADD COLUMN `diplome`        VARCHAR(150) NOT NULL DEFAULT '' AFTER `prenom`,
    ADD COLUMN `mois_execution` VARCHAR(100) NOT NULL DEFAULT '' AFTER `etab_beneficiaire`;
