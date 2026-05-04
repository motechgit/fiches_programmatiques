-- ============================================================
-- Migration 009 — Multi-établissements pour directeurs
-- La colonne etablissement passe à TEXT pour stocker un JSON
-- array des établissements rattachés (bénéficiaires + rattach.)
-- ============================================================

ALTER TABLE `utilisateurs`
    MODIFY COLUMN `etablissement` TEXT DEFAULT NULL;
