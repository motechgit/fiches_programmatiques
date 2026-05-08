-- ════════════════════════════════════════════════════════════
-- Migration 022 : Ajouter colonnes specialite et telephone
-- ════════════════════════════════════════════════════════════

ALTER TABLE `enseignants` 
ADD COLUMN IF NOT EXISTS `telephone` VARCHAR(20) 
COMMENT 'Téléphone de l\'enseignant (surtout pour VACATAIRE)' 
AFTER `email`;

ALTER TABLE `enseignants` 
ADD COLUMN IF NOT EXISTS `specialite` VARCHAR(255) 
COMMENT 'Spécialité de l\'enseignant (remplie pour VACATAIRE)' 
AFTER `telephone`;

-- Index pour recherche rapide
CREATE INDEX IF NOT EXISTS idx_enseignant_telephone ON `enseignants`(`telephone`);
