-- ════════════════════════════════════════════════════════════
-- Migration 021 : Gestion dossier VACATAIRE
-- WORKFLOW CORRECT : Fiche validée → Demande vacation → Dossier créé
-- ════════════════════════════════════════════════════════════

-- Table : Modèles de documents pour VACATAIRES
CREATE TABLE IF NOT EXISTS `vacation_modeles` (
    `id` INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    `type` ENUM('demande_vacation', 'acte_nomination') NOT NULL,
    `titre` VARCHAR(255) NOT NULL,
    `contenu_html` LONGTEXT NOT NULL COMMENT 'HTML du modèle avec placeholders {nom}, {prenom}, etc.',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by` INT UNSIGNED,
    FOREIGN KEY (`updated_by`) REFERENCES `utilisateurs`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Modèles de documents (Demande vacation, Acte nomination) - Modifiables par DEI';

-- Table : Dossier VACATAIRE (ensemble complet)
-- Créé APRÈS validation fiche programmatique avec statut 'complet'
CREATE TABLE IF NOT EXISTS `vacataire_dossier` (
    `id` INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    `fiche_id` INT UNSIGNED NOT NULL UNIQUE COMMENT 'Fiche programmatique validée du vacataire',
    `enseignant_id` INT UNSIGNED NOT NULL,
    `annee_academique` VARCHAR(20) NOT NULL,
    
    -- Documents
    `demande_vacation_html` LONGTEXT COMMENT 'Demande de vacation générée (HTML)',
    `acte_nomination_html` LONGTEXT COMMENT 'Acte de nomination générée (HTML) - Généré par DEI',
    
    -- Statuts
    `statut_dossier` ENUM('complet', 'validee_dei', 'rejetee') DEFAULT 'complet'
        COMMENT 'complet=Crée (docs uploadés au formulaire), validee_dei=Validée+Acte, rejetee=Rejetée DEI',
    `statut_dei` ENUM('en_attente', 'valide', 'rejete') DEFAULT 'en_attente' COMMENT 'Validation DEI',
    
    -- Traces
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Créé après validation fiche',
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `submitted_to_dei_at` DATETIME COMMENT 'Quand soumis au DEI (optionnel)',
    `validated_by_dei_at` DATETIME COMMENT 'Quand validé par DEI',
    `validated_by_dei_user_id` INT UNSIGNED COMMENT 'Utilisateur DEI qui a validé',
    
    FOREIGN KEY (`fiche_id`) REFERENCES `fiches`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`enseignant_id`) REFERENCES `enseignants`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`validated_by_dei_user_id`) REFERENCES `utilisateurs`(`id`) ON DELETE SET NULL,
    
    INDEX idx_enseignant (`enseignant_id`),
    INDEX idx_fiche (`fiche_id`),
    INDEX idx_statut_dossier (`statut_dossier`),
    INDEX idx_statut_dei (`statut_dei`),
    INDEX idx_annee (`annee_academique`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Dossier complet VACATAIRE : Fiche validée + Demande vacation + Acte nomination + Pièces jointes';

-- Table : Pièces jointes du dossier (Diplôme, nomination précédente, etc.)
CREATE TABLE IF NOT EXISTS `vacataire_dossier_documents` (
    `id` INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    `dossier_id` INT UNSIGNED NOT NULL,
    `type` ENUM('diplome', 'nomination_precedente', 'autre') NOT NULL,
    `nom_fichier` VARCHAR(255) NOT NULL,
    `chemin_fichier` VARCHAR(500) NOT NULL,
    `taille_ko` INT UNSIGNED,
    `type_mime` VARCHAR(100),
    `uploaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `uploaded_by` INT UNSIGNED,
    
    FOREIGN KEY (`dossier_id`) REFERENCES `vacataire_dossier`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`uploaded_by`) REFERENCES `utilisateurs`(`id`) ON DELETE SET NULL,
    
    INDEX idx_dossier (`dossier_id`),
    INDEX idx_type (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Pièces jointes du dossier VACATAIRE (Diplôme, ancienne nomination, etc.)';

-- Ajouter colonne specialite si elle n'existe pas
ALTER TABLE `enseignants` ADD COLUMN IF NOT EXISTS `specialite` VARCHAR(255)
    COMMENT 'Spécialité du vacataire (remplie uniquement pour type VACATAIRE)';

-- Insérer les modèles par défaut
INSERT INTO `vacation_modeles` (`type`, `titre`, `contenu_html`) VALUES 
('demande_vacation', 'Demande de vacation - Défaut', 
'<div style="font-family:Arial,sans-serif;max-width:800px;margin:0 auto;padding:20px">
  <p><strong>FICHE DE DEMANDE DE VACATION</strong></p>
  <p><strong>Nom :</strong> {nom}</p>
  <p><strong>Prénom(s) :</strong> {prenom}</p>
  <p><strong>Titre :</strong> {grade}</p>
  <p><strong>Diplôme(s) :</strong> {diplome}</p>
  <p><strong>Spécialités :</strong> {specialite}</p>
  <p><strong>Tél :</strong> {telephone}</p>
  <p><strong>Établissement de vacation :</strong> {etablissement}</p>
  <p><strong>Année académique :</strong> {annee_academique}</p>
  <p style="margin-top:40px"><em>Ouagadougou, le {date_jour}/{date_mois}/{date_annee}</em></p>
  <p style="margin-top:60px"><strong>Signature du demandeur</strong></p>
</div>'),

('acte_nomination', 'Acte de nomination - Défaut', 
'<div style="font-family:Arial,sans-serif;max-width:900px;margin:0 auto;padding:20px">
  <p style="text-align:center"><strong>ARRÊTÉ N° {annee_academique}-{numero_acte}</strong></p>
  <p style="text-align:center"><strong>Portant nomination d\'un vacataire</strong></p>
  <p style="text-align:center">Année académique {annee_academique}</p>
  
  <p style="margin-top:30px"><strong>Le Président de l\'Université Joseph KI-ZERBO,</strong></p>
  
  <p>Vu le décret N° fixant l\'organisation et le fonctionnement de l\'Université Joseph KI-ZERBO ;</p>
  <p>Vu le décret N° fixant les conditions de recrutement du personnel enseignant ;</p>
  
  <p style="margin-top:30px"><strong>ARRÊTE :</strong></p>
  
  <p><strong>Article 1 :</strong> Par les présentes, M./Mme {nom} {prenom}, titulaire d\'un {diplome}, 
  est nommé(e) à titre de vacataire pour l\'année académique {annee_academique}, 
  pour assurer les enseignements en {specialite} à l\'établissement {etablissement}.</p>
  
  <p><strong>Article 2 :</strong> Le présent arrêté prend effet à la date de sa signature.</p>
  
  <p style="margin-top:30px">Ouagadougou, le {date_jour}/{date_mois}/{date_annee}</p>
  <p style="margin-top:60px">Le Président,</p>
  <p style="margin-top:100px">_______________________</p>
</div>');

-- Index pour recherches rapides
CREATE INDEX idx_vacation_type ON `vacation_modeles`(`type`);
CREATE INDEX idx_dossier_enseignant ON `vacataire_dossier`(`enseignant_id`);
CREATE INDEX idx_dossier_statut_dei ON `vacataire_dossier`(`statut_dei`);
