-- ============================================================
-- Migration 011 — Tables etablissements et departements
-- Structure relationnelle complète :
--   etablissements ←── departements
--   utilisateurs.departement_id  → departements.id
--   utilisateurs.etablissement_id → etablissements.id (multi via JSON maintenu)
-- Compatible MySQL 5.7+ / MariaDB 10.3+
-- ============================================================
SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ── 1. Table établissements ───────────────────────────────────
CREATE TABLE IF NOT EXISTS `etablissements` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `sigle`      VARCHAR(30)   NOT NULL DEFAULT '',
    `nom`        VARCHAR(200)  NOT NULL,
    `actif`      TINYINT(1)    NOT NULL DEFAULT 1,
    `ordre`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nom` (`nom`),
    KEY `idx_actif` (`actif`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Table départements ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS `departements` (
    `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `etablissement_id` INT UNSIGNED NOT NULL,
    `nom`             VARCHAR(150)  NOT NULL,
    `sigle`           VARCHAR(30)   NOT NULL DEFAULT '',
    `actif`           TINYINT(1)    NOT NULL DEFAULT 1,
    `ordre`           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_etab`  (`etablissement_id`),
    KEY `idx_actif` (`actif`),

    CONSTRAINT `fk_dept_etab`
        FOREIGN KEY (`etablissement_id`)
        REFERENCES `etablissements`(`id`)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Colonnes FK dans utilisateurs ─────────────────────────
-- On ajoute departement_id et etablissement_id comme FK
-- Les colonnes texte (departement, etablissement) restent pour compatibilité
ALTER TABLE `utilisateurs`
    ADD COLUMN `departement_id`   INT UNSIGNED DEFAULT NULL AFTER `departement`,
    ADD COLUMN `etablissement_id` INT UNSIGNED DEFAULT NULL AFTER `etablissement`,
    ADD CONSTRAINT `fk_user_dept` FOREIGN KEY (`departement_id`)
        REFERENCES `departements`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_user_etab` FOREIGN KEY (`etablissement_id`)
        REFERENCES `etablissements`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- ── 4. Données initiales — Établissements UJKZ ───────────────
INSERT INTO `etablissements` (`sigle`, `nom`, `ordre`) VALUES
('UFR/SVT',  'UFR/SVT — Sciences de la Vie et de la Terre',                    1),
('UFR/SEA',  'UFR/SEA — Sciences Économiques et de Gestion',                   2),
('UFR/SH',   'UFR/SH — Sciences Humaines',                                     3),
('UFR/LAC',  'UFR/LAC — Langues, Arts et Communication',                        4),
('UFR/SJP',  'UFR/SJP — Sciences Juridiques et Politiques',                    5),
('UFR/SDS',  'UFR/SDS — Sciences du Sport',                                    6),
('UFR/CIT',  'UFR/CIT — Communication, Informatique et Téléinformatique',       7),
('ISSS',     'ISSS — Institut Supérieur des Sciences de la Santé',              8),
('IAI',      'IAI — Institut Africain d''Informatique',                         9),
('INSS',     'INSS — Institut des Sciences des Sociétés',                      10),
('IECC',     'IECC — Institut d''Études et de Recherches sur les Cultures',    11),
('ED',       'École Doctorale',                                                12),
('DFR',      'DFR — Direction de la Formation et de la Recherche',             13);

-- ── 5. Données initiales — Départements ──────────────────────
-- UFR/SVT (id=1)
INSERT INTO `departements` (`etablissement_id`, `nom`, `sigle`, `ordre`) VALUES
(1, 'Biologie et Physiologie Végétales',      'BPV',  1),
(1, 'Biologie et Physiologie Animales',       'BPA',  2),
(1, 'Biochimie-Microbiologie',                'BCM',  3),
(1, 'Géologie',                               'GEO',  4),
(1, 'Mathématiques et Informatique',          'MI',   5),
(1, 'Physique',                               'PHY',  6),
(1, 'Chimie',                                 'CHI',  7),
-- UFR/SEA (id=2)
(2, 'Économie et Gestion',                    'ECOG', 1),
(2, 'Sciences Agronomiques',                  'SA',   2),
(2, 'Gestion des Ressources Naturelles',      'GRN',  3),
-- UFR/SH (id=3)
(3, 'Philosophie',                            'PHI',  1),
(3, 'Psychologie',                            'PSY',  2),
(3, 'Sociologie',                             'SOC',  3),
(3, 'Histoire et Archéologie',                'HIA',  4),
(3, 'Géographie',                             'GEO',  5),
-- UFR/LAC (id=4)
(4, 'Langues et Littératures Africaines',     'LLA',  1),
(4, 'Langues Vivantes Étrangères',            'LVE',  2),
(4, 'Sciences du Langage',                    'SDL',  3),
-- UFR/SJP (id=5)
(5, 'Droit Public',                           'DP',   1),
(5, 'Droit Privé',                            'DPR',  2),
(5, 'Sciences Politiques',                    'SP',   3),
-- UFR/SDS (id=6)
(6, 'Éducation et Formation',                 'EF',   1),
(6, 'Sciences et Techniques des APS',         'STAPS',2),
-- UFR/CIT (id=7)
(7, 'Informatique et Systèmes',               'IS',   1),
(7, 'Réseaux et Télécommunications',          'RT',   2),
(7, 'Génie Logiciel',                         'GL',   3),
-- ISSS (id=8)
(8, 'Santé Publique',                         'SP',   1);

SET foreign_key_checks = 1;
