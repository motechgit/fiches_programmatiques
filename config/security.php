<?php
declare(strict_types=1);
return array (
  'app_secret' => 'f3193714f3ce55f18b94bfacc1a932f6b5ce41dcad551b9ec39838bb443845c6',
  'admin_user' => 'admin',
  'admin_hash' => '$argon2id$v=19$m=65536,t=4,p=1$LlhBWW92Z3VKNXZLalFaeg$HE6ejf3x1Xic3EB6GM3gXKEvAKQQEH18LKPPrBRxp1M',
  'annee_academique' => '2024-2025',
  'matricule_pattern' => '/^ENS-\\d{4}-\\d{5}$/',
  'rate_limit' => 
  array (
    'soumettre' => 
    array (
      'max' => 10,
      'window' => 3600,
    ),
    'dashboard' => 
    array (
      'max' => 120,
      'window' => 3600,
    ),
    'matricule_gen' => 
    array (
      'max' => 30,
      'window' => 3600,
    ),
    'login' => 
    array (
      'max' => 10,
      'window' => 900,
    ),
  ),
  'csp' => 'default-src \'self\'; script-src \'self\' \'unsafe-inline\'; style-src \'self\' \'unsafe-inline\'; img-src \'self\' data:; frame-ancestors \'none\';',
  'session_lifetime' => 3600,
  'niveaux' => 
  array (
    0 => 'Licence 1',
    1 => 'Licence 2',
    2 => 'Licence 3',
    3 => 'Master 1',
    4 => 'Master 2',
    5 => 'Doctorat',
  ),
  'semestres' => 
  array (
    0 => 'S1',
    1 => 'S2',
    2 => 'ENC',
  ),
  'departements' => 
  array (
    0 => 'Biologie et Physiologie Végétales (BPV)',
    1 => 'Biologie et Physiologie Animales (BPA)',
    2 => 'Biochimie-Microbiologie (BCM)',
    3 => 'Géologie (GEO)',
    4 => 'Mathématiques et Informatique (MI)',
    5 => 'Physique (PHY)',
    6 => 'Chimie (CHI)',
    7 => 'Économie et Gestion (ECOG)',
    8 => 'Sciences Agronomiques',
    9 => 'Gestion des Ressources Naturelles',
    10 => 'Philosophie',
    11 => 'Psychologie',
    12 => 'Sociologie',
    13 => 'Histoire et Archéologie',
    14 => 'Géographie',
    15 => 'Langues et Littératures Africaines (LLA)',
    16 => 'Langues Vivantes Étrangères (LVE)',
    17 => 'Sciences du Langage (SDL)',
    18 => 'Droit Public',
    19 => 'Droit Privé',
    20 => 'Sciences Politiques',
    21 => 'Éducation et Formation',
    22 => 'Sciences et Techniques des Activités Physiques et Sportives (STAPS)',
    23 => 'Informatique et Systèmes',
    24 => 'Réseaux et Télécommunications',
    25 => 'Génie Logiciel',
    26 => 'Santé Publique',
    27 => 'Autre département',
  ),
  'etab_departements' => 
  array (
    'UFR/SVT — Sciences de la Vie et de la Terre' => 
    array (
      0 => 'Biologie et Physiologie Végétales (BPV)',
      1 => 'Biologie et Physiologie Animales (BPA)',
      2 => 'Biochimie-Microbiologie (BCM)',
      3 => 'Géologie (GEO)',
      4 => 'Mathématiques et Informatique (MI)',
      5 => 'Physique (PHY)',
      6 => 'Chimie (CHI)',
    ),
    'UFR/SEA — Sciences Économiques et de Gestion' => 
    array (
      0 => 'Économie et Gestion (ECOG)',
      1 => 'Sciences Agronomiques',
      2 => 'Gestion des Ressources Naturelles',
    ),
    'UFR/SH — Sciences Humaines' => 
    array (
      0 => 'Philosophie',
      1 => 'Psychologie',
      2 => 'Sociologie',
      3 => 'Histoire et Archéologie',
      4 => 'Géographie',
    ),
    'UFR/LAC — Langues, Arts et Communication' => 
    array (
      0 => 'Langues et Littératures Africaines (LLA)',
      1 => 'Langues Vivantes Étrangères (LVE)',
      2 => 'Sciences du Langage (SDL)',
    ),
    'UFR/SJP — Sciences Juridiques et Politiques' => 
    array (
      0 => 'Droit Public',
      1 => 'Droit Privé',
      2 => 'Sciences Politiques',
    ),
    'UFR/SDS — Sciences du Sport' => 
    array (
      0 => 'Éducation et Formation',
      1 => 'Sciences et Techniques des Activités Physiques et Sportives (STAPS)',
    ),
    'UFR/CIT — Communication, Informatique et Téléinformatique' => 
    array (
      0 => 'Informatique et Systèmes',
      1 => 'Réseaux et Télécommunications',
      2 => 'Génie Logiciel',
    ),
    'ISSS — Institut Supérieur des Sciences de la Santé' => 
    array (
      0 => 'Santé Publique',
    ),
  ),
  'grades_volumes' => 
  array (
    'Professeur titulaire' => 100,
    'Professeur agrégé' => 100,
    'Maître de conférences' => 125,
    'Maître-assistant / Maître-assistant HU' => 150,
    'Assistant / Assistant HU' => 175,
    'Chargé de cours' => 0,
    'Doctorant enseignant' => 0,
    'ETP' => 0,
    'Autre' => 0,
  ),
  'evaluations' => 
  array (
    0 => 'Examen final uniquement',
    1 => 'Contrôle continu + examen final',
    2 => 'Contrôle continu uniquement',
    3 => 'Mémoire / Projet',
    4 => 'Soutenance / Stage',
  ),
  'etablissements' => 
  array (
    0 => 'UFR/SVT — Sciences de la Vie et de la Terre',
    1 => 'UFR/SEA — Sciences Économiques et de Gestion',
    2 => 'UFR/SH — Sciences Humaines',
    3 => 'UFR/LAC — Langues, Arts et Communication',
    4 => 'UFR/SJP — Sciences Juridiques et Politiques',
    5 => 'UFR/SDS — Sciences du Sport',
    6 => 'UFR/CIT — Communication, Informatique et Téléinformatique',
    7 => 'ISSS — Institut Supérieur des Sciences de la Santé',
    8 => 'IAI — Institut Africain d\'Informatique',
    9 => 'INSS — Institut des Sciences des Sociétés',
    10 => 'IECC — Institut d\'Études et de Recherches sur les Cultures',
    11 => 'École Doctorale',
    12 => 'DFR — Direction de la Formation et de la Recherche',
    13 => 'Autre UFR / Institut',
  ),
);
