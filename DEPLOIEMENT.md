# Guide de déploiement — Fiches Programmatiques UJKZ

## Prérequis serveur

| Élément | Version minimale |
|---------|-----------------|
| PHP | 8.1+ |
| MySQL / MariaDB | 5.7+ / 10.3+ |
| Extensions PHP | pdo_mysql, mbstring, zip, fileinfo, openssl |
| Apache | 2.4+ avec mod_rewrite, mod_headers |

---

## Étape 1 — Préparer les fichiers

1. Téléversez **tous les fichiers** dans le dossier cible de votre hébergeur
   - Ex. : `public_html/` (racine) ou `public_html/fiches/` (sous-dossier)
2. Vérifiez que le dossier `data/` est présent avec ses sous-dossiers `uploads/` et `backups/`
3. Vérifiez les permissions :
   ```
   chmod 755 data/
   chmod 755 data/uploads/
   chmod 755 data/backups/
   chmod 755 logs/
   ```

---

## Étape 2 — Créer la base de données

Dans **cPanel → MySQL Databases** (ou phpMyAdmin) :

1. Créer une base : `prefixe_fiches`
2. Créer un utilisateur : `prefixe_user` avec un mot de passe fort
3. Accorder **tous les privilèges** à cet utilisateur sur cette base

---

## Étape 3 — Lancer l'installateur

Ouvrir dans un navigateur :
```
https://votre-domaine.bf/install.php
```

Renseigner :
- **Hôte MySQL** : `localhost` (presque toujours)
- **Nom de la base** : celui créé à l'étape 2
- **Utilisateur / Mot de passe** : ceux créés à l'étape 2
- **Mot de passe administrateur** : choisir un mot de passe fort (≥ 8 caractères)
- **URL de base** : votre URL complète (ex. `https://fiches.ujkz.bf`)
- **Email expéditeur** : adresse officielle de l'université
- **SMTP** : paramètres fournis par votre hébergeur ou DSI

L'installateur va :
- Tester la connexion MySQL
- Créer toutes les tables (migrations 001 + 002 + 003)
- Générer `config/security.php`, `config/app.php`, `config/mail.php`

---

## Étape 4 — Sécuriser après installation

**⚠️ Obligatoire :** supprimer ou bloquer `install.php` :

```bash
rm install.php
```

Ou dans `.htaccess`, décommenter la ligne :
```apache
RewriteRule ^install\.php$ - [F,L]
```

---

## Étape 5 — Activer HTTPS

1. Dans **cPanel → SSL/TLS** : installer un certificat Let's Encrypt (gratuit)
2. Dans `.htaccess`, décommenter les lignes de redirection HTTPS :
   ```apache
   RewriteCond %{HTTPS} off
   RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
   ```
   Et le header HSTS :
   ```apache
   Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
   ```

---

## Étape 6 — Configurer l'email (SMTP)

Si l'envoi de mails ne fonctionne pas avec `mail()` natif :

**Option A — PHPMailer (recommandé)** :
```bash
# Via Composer (si disponible)
composer require phpmailer/phpmailer

# OU télécharger manuellement :
# https://github.com/PHPMailer/PHPMailer/releases
# Extraire dans lib/PHPMailer/src/
```

Puis dans `config/mail.php`, activer SMTP :
```php
'smtp_enabled' => true,
'smtp_host'    => 'mail.ujkz.bf',
'smtp_port'    => 587,
'smtp_user'    => 'noreply@ujkz.bf',
'smtp_pass'    => 'votre_mot_de_passe',
'smtp_secure'  => 'tls',
```

**Option B — Configuration php.ini** (hébergement mutualisé) :
Via cPanel → **PHP Configuration** ou `.user.ini` :
```ini
SMTP = mail.ujkz.bf
smtp_port = 587
sendmail_from = noreply@ujkz.bf
```

---

## Accès après déploiement

| Page | URL |
|------|-----|
| Formulaire enseignant | `https://votre-domaine.bf/` |
| Connexion admin/portail | `https://votre-domaine.bf/login.php` |
| Administration DEI | `https://votre-domaine.bf/admin.php` |
| Portail de validation | `https://votre-domaine.bf/portail.php` |

---

## Sauvegarde automatique (cron)

Dans **cPanel → Cron Jobs** :
```
0 2 * * * /usr/bin/php /home/COMPTE/public_html/scripts/backup.php >> /home/COMPTE/logs/backup.log 2>&1
```
(Remplacer `COMPTE` par votre nom de compte cPanel)

---

## Dépannage fréquent

| Problème | Solution |
|----------|----------|
| Erreur 500 | Vérifier `logs/php_errors.log`, activer temporairement `display_errors = On` dans `.htaccess` |
| Upload impossible | Vérifier permissions de `data/uploads/` (755) et `upload_max_filesize` dans phpMyAdmin |
| Mails non reçus | Tester avec PHPMailer SMTP, vérifier les logs spam |
| Session expirée | Augmenter `session_lifetime` dans `config/security.php` |
| `.htaccess` ignoré | Vérifier que `AllowOverride All` est activé dans la config Apache |

