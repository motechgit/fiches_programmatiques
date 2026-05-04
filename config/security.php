<?php
// ============================================================
// config/security.php — Configuration sécurité + listes UJKZ
// ============================================================
declare(strict_types=1);

return [
    // Généré par install.php — NE PAS modifier manuellement
    'app_secret'  => '3688db8c2094954bf9c74450671f71773fc109a4548de599dc0843efe81beb74',
    'admin_user'  => 'admin',
    'admin_hash'  => '$argon2id$v=19$m=65536,t=4,p=1$WGNELlkuLjBTWFJ4WXBhOA$W7a3EABngLBfgJirLUXEJeVruufG8i+frPAdT3my85E',

    'annee_academique'  => '2024-2025',
    'matricule_pattern' => '/^ENS-\d{4}-\d{5}$/',

    'rate_limit' => [
        'soumettre'      => ['max' => 10,  'window' => 3600],
        'dashboard'      => ['max' => 120, 'window' => 3600],
        'matricule_gen'  => ['max' => 30,  'window' => 3600],
        'login'          => ['max' => 10,  'window' => 900],   // 10 essais / 15 min
    ],

    'csp'              => "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none';",
    'session_lifetime' => 3600,

    'niveaux'   => ['Licence 1','Licence 2','Licence 3','Master 1','Master 2','Doctorat'],
    'semestres'          => ['S1','S2','ENC'],

    // Départements de l'Université Joseph KI-ZERBO (UJKZ)
    'departements' => [
        // UFR/SVT
        'Biologie et Physiologie Végétales (BPV)',
        'Biologie et Physiologie Animales (BPA)',
        'Biochimie-Microbiologie (BCM)',
        'Géologie (GEO)',
        'Mathématiques et Informatique (MI)',
        'Physique (PHY)',
        'Chimie (CHI)',
        // UFR/SEA
        'Économie et Gestion (ECOG)',
        'Sciences Agronomiques',
        'Gestion des Ressources Naturelles',
        // UFR/SH
        'Philosophie',
        'Psychologie',
        'Sociologie',
        'Histoire et Archéologie',
        'Géographie',
        // UFR/LAC
        'Langues et Littératures Africaines (LLA)',
        'Langues Vivantes Étrangères (LVE)',
        'Sciences du Langage (SDL)',
        // UFR/SJP
        'Droit Public',
        'Droit Privé',
        'Sciences Politiques',
        // UFR/SDS
        'Éducation et Formation',
        'Sciences et Techniques des Activités Physiques et Sportives (STAPS)',
        // UFR/CIT
        'Informatique et Systèmes',
        'Réseaux et Télécommunications',
        'Génie Logiciel',
        // ISSS
        'Santé Publique',
        // Autres
        'Autre département',
    ],

    // Mapping établissement → départements associés
    'etab_departements' => [
        'UFR/SVT — Sciences de la Vie et de la Terre' => [
            'Biologie et Physiologie Végétales (BPV)',
            'Biologie et Physiologie Animales (BPA)',
            'Biochimie-Microbiologie (BCM)',
            'Géologie (GEO)',
            'Mathématiques et Informatique (MI)',
            'Physique (PHY)',
            'Chimie (CHI)',
        ],
        'UFR/SEA — Sciences Économiques et de Gestion' => [
            'Économie et Gestion (ECOG)',
            'Sciences Agronomiques',
            'Gestion des Ressources Naturelles',
        ],
        'UFR/SH — Sciences Humaines' => [
            'Philosophie',
            'Psychologie',
            'Sociologie',
            'Histoire et Archéologie',
            'Géographie',
        ],
        'UFR/LAC — Langues, Arts et Communication' => [
            'Langues et Littératures Africaines (LLA)',
            'Langues Vivantes Étrangères (LVE)',
            'Sciences du Langage (SDL)',
        ],
        'UFR/SJP — Sciences Juridiques et Politiques' => [
            'Droit Public',
            'Droit Privé',
            'Sciences Politiques',
        ],
        'UFR/SDS — Sciences du Sport' => [
            'Éducation et Formation',
            'Sciences et Techniques des Activités Physiques et Sportives (STAPS)',
        ],
        'UFR/CIT — Communication, Informatique et Téléinformatique' => [
            'Informatique et Systèmes',
            'Réseaux et Télécommunications',
            'Génie Logiciel',
        ],
        'ISSS — Institut Supérieur des Sciences de la Santé' => [
            'Santé Publique',
        ],
    ],

    // Volumes horaires statutaires par grade

    'grades_volumes' => [
        'Professeur titulaire'                       => 100,
        'Professeur agrégé'                          => 100,
        'Maître de conférences'                      => 125,
        'Maître-assistant / Maître-assistant HU'     => 150,
        'Assistant / Assistant HU'                   => 175,
        'Chargé de cours'                            => 0,
        'Doctorant enseignant'                       => 0,
        'ETP'                                        => 0,
        'Autre'                                      => 0,
    ],

    'evaluations' => [
        'Examen final uniquement',
        'Contrôle continu + examen final',
        'Contrôle continu uniquement',
        'Mémoire / Projet',
        'Soutenance / Stage',
    ],

    // UFR, Instituts et Centres de l'UJKZ
    'etablissements' => [
        // UFR
        'UFR/SVT — Sciences de la Vie et de la Terre',
        'UFR/SEA — Sciences Économiques et de Gestion',
        'UFR/SH — Sciences Humaines',
        'UFR/LAC — Langues, Arts et Communication',
        'UFR/SJP — Sciences Juridiques et Politiques',
        'UFR/SDS — Sciences du Sport',
        'UFR/CIT — Communication, Informatique et Téléinformatique',
        // Instituts
        'ISSS — Institut Supérieur des Sciences de la Santé',
        'IAI — Institut Africain d\'Informatique',
        'INSS — Institut des Sciences des Sociétés',
        'IECC — Institut d\'Études et de Recherches sur les Cultures',
        // Centres et structures
        'École Doctorale',
        'DFR — Direction de la Formation et de la Recherche',
        // Autres
        'Autre UFR / Institut',
    ],
];
