-- ============================================================
-- Migration 023 : Table parametres pour stocker les configurations
-- ============================================================

CREATE TABLE IF NOT EXISTS `parametres` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `cle` VARCHAR(100) NOT NULL UNIQUE COMMENT 'Clé unique du paramètre (ex: acte_modele)',
  `valeur` LONGTEXT COMMENT 'Valeur du paramètre (peut contenir du HTML)',
  `description` TEXT COMMENT 'Description du paramètre',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_cle` (`cle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
  COMMENT='Stockage des configurations et modèles (acte nomination, etc.)';
