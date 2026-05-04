# Fiches Programmatiques — Université Joseph KI-ZERBO

Système de gestion numérique des fiches programmatiques d'enseignement.

## Fonctionnalités

- Dépôt de fiches par les enseignants (formulaire sécurisé, accès par token)
- Workflow de validation en 4 étapes : Chef de département → Directeur adjoint → Directeur → DEI
- Upload de fichiers de preuves (PDF, Word, images)
- Génération automatique de la fiche programmatique DOCX (format officiel)
- Tableau de bord enseignant avec suivi des validations et volumes horaires
- Portail de validation multi-rôles
- Administration DEI (gestion des utilisateurs, export CSV, audit)
- Envoi de mails de confirmation (SMTP ou mail() natif)

## Stack technique

- **Backend** : PHP 8.1+ (sans framework)
- **Base de données** : MySQL 5.7+ / MariaDB 10.3+
- **Frontend** : HTML/CSS natif (charte UJKZ), aucune dépendance JS externe
- **Documents** : ZipArchive + XML OOXML (génération DOCX sans dépendance)

## Installation

Voir [DEPLOIEMENT.md](DEPLOIEMENT.md) pour le guide complet.

```
https://votre-domaine.bf/install.php
```

## Accès

| Interface | URL |
|-----------|-----|
| Formulaire enseignant | `/` |
| Tableau de bord enseignant | `/dashboard.php?token=<TOKEN>` |
| Connexion admin / portail | `/login.php` |
| Administration DEI | `/admin.php` |
| Portail validations | `/portail.php` |

## Structure

```
├── index.php              Formulaire de dépôt
├── dashboard.php          Tableau de bord enseignant
├── login.php              Connexion unifiée
├── portail.php            Portail de validation
├── admin.php              Interface DEI
├── config/                Configuration (DB, mail, sécurité)
├── src/                   Classes PHP (Database, Security, Auth, …)
├── templates/             Templates HTML (layout, form, dashboard)
├── migrations/            Scripts SQL (001, 002, 003)
├── data/uploads/          Fichiers de preuves (protégé)
├── data/backups/          Sauvegardes MySQL
├── logs/                  Logs PHP
└── DEPLOIEMENT.md         Guide de déploiement
```

## Sécurité

- Tokens HMAC-SHA256 pour les accès enseignants
- CSRF sur tous les formulaires POST
- Rate limiting sur les soumissions
- Headers HTTP de sécurité (CSP, X-Frame-Options, …)
- Mots de passe Argon2id
- Requêtes PDO préparées (aucune injection SQL possible)
- Dossiers sensibles inaccessibles via .htaccess
