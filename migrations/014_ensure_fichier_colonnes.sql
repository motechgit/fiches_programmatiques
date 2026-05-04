-- Migration 014 — Ajouter fichier_diplome et fichier_nomination si absentes
-- Compatible MySQL 5.7+
-- Si les colonnes existent déjà (erreur 1060), ignorer et continuer.

ALTER TABLE `enseignants`
    ADD COLUMN `fichier_diplome` VARCHAR(255) NOT NULL DEFAULT '' AFTER `mois_execution`;

ALTER TABLE `enseignants`
    ADD COLUMN `fichier_nomination` VARCHAR(255) NOT NULL DEFAULT '' AFTER `fichier_diplome`;
