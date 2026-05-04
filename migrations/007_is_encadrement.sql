-- ============================================================
-- Migration 007 — Ajout colonne is_encadrement dans fiches
-- À exécuter une seule fois sur la base de données
-- ============================================================

-- Ajouter la colonne is_encadrement (compatible MySQL 5.x)
ALTER TABLE fiches
  ADD COLUMN is_encadrement TINYINT(1) NOT NULL DEFAULT 0;

-- Mettre à jour les lignes existantes dont semestre = 'ENC'
-- (pour rétrocompatibilité avec les données déjà saisies)
UPDATE fiches SET is_encadrement = 1 WHERE semestre = 'ENC';
