-- Migration : Ajouter numéro de fiche unique et QR code
-- Date : 2026-05-05

ALTER TABLE fiches 
ADD COLUMN `numero_fiche` VARCHAR(50) UNIQUE NULL COMMENT 'Numéro unique de fiche (ex: FP-2024-2025-001)',
ADD COLUMN `qrcode_token` VARCHAR(100) UNIQUE NULL COMMENT 'Token pour QR code de vérification',
ADD INDEX idx_numero_fiche (`numero_fiche`),
ADD INDEX idx_qrcode_token (`qrcode_token`);

-- Générer les numéros pour les fiches existantes (optionnel)
-- UPDATE fiches SET numero_fiche = CONCAT('FP-', (SELECT annee_academique FROM fiches f2 WHERE f2.id = fiches.id), '-', LPAD(id, 4, '0')) WHERE numero_fiche IS NULL;
