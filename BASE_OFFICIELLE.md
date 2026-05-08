# 📋 BASE OFFICIELLE — Fiches Programmatiques UJKZ

## 📦 Version source
**Source** : `/home/claude/fiches_prod_complete/`
**ZIP de production** : `/mnt/user-data/outputs/fiches_programmatiques_PRODUCTION.zip`

---

## 🎯 État du projet — COMPLET ET OPÉRATIONNEL

### ✅ Fonctionnalités implémentées

1. **Numéro de fiche unique + QR code de vérification**
   - Format : `FP-YYYYMMDD-XXXX` (ex: FP-20242025-0001)
   - QR code scannable en haut à droite de chaque fiche
   - Token unique pour vérification

2. **Séparation des fiches par établissement (VACATAIRE + IESR NON UJKZ)**
   - Une fiche par établissement bénéficiaire
   - Courses groupés par établissement automatiquement
   - Validations indépendantes par établissement

3. **1 seule fiche (IESR UJKZ)**
   - Tous les courses mélangés (peu importe les établissements)
   - Affichage unifié au dashboard

4. **Validations indépendantes par établissement**
   - Chaque fiche affiche UNIQUEMENT les validations de son établissement
   - Chef FSTB valide fiches FSTB uniquement
   - Chef CUP valide fiches CUP uniquement

5. **Signatures visibles et filtrées**
   - Tableau des signataires par établissement
   - Affichage correct après détachement des fiches

6. **Lignes des cours NON fusionnées**
   - Chaque fiche affiche uniquement ses courses
   - Tableau des courses correctement filtré par établissement

---

## 📁 Structure du projet

```
fiches_prod_complete/
├── index.php                    # Formulaire création/modification fiches
├── dashboard.php                # Contrôleur tableau de bord
├── portail.php                  # Page d'accueil
├── admin.php, admin_*.php       # Interface admin
│
├── src/
│   ├── Database.php             # Connexion BD
│   ├── FicheRepository.php      # ✅ Génération numéro + QR code
│   ├── ValidationRepository.php # Gestion validations
│   ├── Auth.php                 # Authentification
│   └── Security.php             # CSRF, sanitization
│
├── templates/
│   ├── dashboard.php            # ✅ Filtrage courses + signatures
│   ├── form.php                 # Formulaire multi-step
│   ├── layout.php               # Template de base
│   ├── fiche_suivi.php          # Fiches de suivi
│   └── ...
│
├── migrations/
│   ├── 001_init.sql             # Structure BD
│   ├── 020_add_numero_fiche_qrcode.sql  # ✅ Migration numéro/QR
│   └── ...
│
├── config/
│   ├── config.php               # Configuration globale
│   └── database.php             # BD
│
└── README.md, DEPLOIEMENT.md, etc.
```

---

## 🔑 Points clés à retenir

### 1. **Détection du type d'enseignant**
```php
// dashboard.php ligne 106-115
$typeWorkflowFromDB = null;
if (!empty($fiches)) {
    $typeWorkflowFromDB = $fiches[0]['type_workflow'] ?? null;
}

// Détacher UNIQUEMENT si ce n'est pas IESR UJKZ
if ($typeWorkflowFromDB !== 'IESR_UJKZ') {
    $fiches = detacherFichesParlEtablissement($fiches);
}
```

### 2. **Filtrage des courses par fiche**
```php
// templates/dashboard.php ligne 259+ (INSIDE foreach loop)
$ficheEtab = (int)($f['etab_beneficiaire_fiche'] ?? 0);

// Filtrer S1 pour cette fiche uniquement
$s1Fiches = array_filter($fiches, function($ficheTmp) use ($ficheEtab) {
    if (($ficheTmp['semestre'] ?? '') !== 'S1') return false;
    if ($ficheEtab > 0) {
        return (int)($ficheTmp['etab_beneficiaire_fiche'] ?? 0) === $ficheEtab;
    }
    return true;
});
```

### 3. **Génération numéro et QR code**
```php
// src/FicheRepository.php createFiche()
$numeroFiche = 'FP-' . str_replace('-', '', $annee) . '-' . str_pad((string)$ficheId, 4, '0', STR_PAD_LEFT);
$qrToken = hash('sha256', (string)$ficheId . $annee . microtime(true));
```

### 4. **Validations par fiche**
```php
// dashboard.php ligne 118-145
$historiqueParFiche = [];  // [fiche_id => [role => validation]]
// Charger les validations PAR FICHE, pas globalement
```

---

## 🚀 Déploiement

### Production
```bash
unzip /mnt/user-data/outputs/fiches_programmatiques_PRODUCTION.zip -d C:\wamp64\www\fiches_prod\
mysql -u root fiches_ujkz < migrations/020_add_numero_fiche_qrcode.sql
```

### Vérification
- ✅ IESR UJKZ : 1 seule fiche au dashboard
- ✅ IESR NON UJKZ : plusieurs fiches par établissement
- ✅ VACATAIRE : plusieurs fiches par établissement
- ✅ Lignes des courses non fusionnées
- ✅ Validations filtrées par établissement

---

## 📝 Pour les FUTURES modifications

1. **Modifier le source** : `/home/claude/fiches_prod_complete/`
2. **Tester localement** : WampServer PHP 7.1+
3. **Créer le ZIP** :
   ```bash
   cd /home/claude/fiches_prod_complete && \
   zip -r /mnt/user-data/outputs/fiches_programmatiques_PRODUCTION.zip . \
     --exclude "*.log" --exclude "*.jpg" --exclude "diag*.php" \
     --exclude "test_*.php" --exclude "debug_*.php" \
     --exclude "\.env*" --exclude "\.git*" \
     --exclude "uploads/*" --exclude "data/*"
   ```
4. **Deployer** : Extraire le ZIP au serveur

---

## ⚠️ Contraintes critiques

- **PHP 7.1+ OBLIGATOIRE** : Pas de `match()`, `fn()`, `str_contains()`, `??` dans les strings
- **Syntaxe ternaire** : Utiliser `isset() ? ... : ...` au lieu de `??`
- **Conversions de types** : `str_pad((string)$id, ...)` au lieu de `str_pad($id, ...)`
- **WampServer** : Test local sur le même serveur que la prod

---

## 🔄 Cycle de modification

1. Extraire le ZIP dans `/home/claude/fiches_prod_complete/`
2. Modifier les fichiers source
3. Vérifier la syntaxe PHP : `php -l fichier.php`
4. Recréer le ZIP de production
5. Deployer et tester

---

## 📞 Contacts clés

**Université** : Université Joseph KI-ZERBO (UJKZ), Burkina Faso
**Application** : Fiches Programmatiques — Gestion des enseignements
**Année académique** : 2024-2025
**Stack** : PHP 7.1+ / MySQL 5.7 / WampServer

---

**DOCUMENT DE RÉFÉRENCE — À CONSERVER**
**Dernière mise à jour** : 6 mai 2026 22:50 UTC
