# 🔍 DEBUG : Pourquoi une seule fiche s'affiche ?

## Symptôme
Enseignant IESR NON UJKZ soumet une fiche avec 2 cours dans 2 établissements différents → **UNE SEULE fiche** s'affiche au dashboard au lieu de 2.

## Cause probable
Les valeurs POST `l_etab_benef[]` et `l_dept_benef[]` ne sont **pas envoyées** ou sont **vides (0)** par le formulaire.

---

## 🔧 Vérification à faire

### Étape 1 : Ajouter du debug dans index.php

À la ligne 560, ajouter :
```php
error_log("DEBUG: Ligne $i - etab_beneficiaire_fiche = " . $ligne['etab_beneficiaire_fiche']);
```

Puis soumettre une fiche et vérifier les logs Apache.

### Étape 2 : Vérifier le POST

Ajouter dans le template `form.php` avant la fermeture `</form>` :
```html
<script>
document.getElementById('main-form').addEventListener('submit', function(e) {
    console.log('POST l_etab_benef[]:', this.querySelectorAll('[name="l_etab_benef[]"]'));
    console.log('Valeurs:', Array.from(this.querySelectorAll('[name="l_etab_benef[]"]')).map(el => el.value));
});
</script>
```

Soumettre et vérifier la console du navigateur.

### Étape 3 : Vérifier le HTML du formulaire

Ouvrir le formulaire IESR NON UJKZ dans le navigateur.
Faire clic-droit → "Inspecter l'élément"
Chercher `<select name="l_etab_benef[]">`
Vérifier que :
1. Le select existe
2. Il a des options `<option>`
3. L'une d'elles est `selected`

---

## ❌ Problème probable

Les selects pour établissement/département sont **vides** ou ont une `value="0"` par défaut.

### Raison
Quand `$etabsDB` est vide (pas chargé depuis BD), les selects n'ont pas d'options.

### Vérification dans index.php ligne 796
```php
'etabsDB'          => $_etabsDB  ?? [],
```

Si `$_etabsDB` est vide, alors le template ne peut pas afficher les options.

---

## ✅ Solution rapide

### Ajouter du debug dans index.php ligne 28
```php
$_etabsDB    = $etabRepo->getListeEtabs(true);
error_log("DEBUG: etabsDB chargé = " . count($_etabsDB) . " établissements");
foreach ($_etabsDB as $e) {
    error_log("  - ID=" . $e['id'] . " NOM=" . $e['nom']);
}
```

### Vérifier les logs
Si 0 établissements chargés → problème BD ou requête.
Si établissements chargés → problème formulaire.

---

## 🔧 Solution réelle

Si `$etabsDB` est chargé mais vide dans le formulaire, c'est que :

**OPTION 1 : Les selects sont masqués par CSS**
Chercher dans `form.php` s'il y a un `display:none` ou `visibility:hidden` sur les selects.

**OPTION 2 : Avant et Après étape 2 du formulaire**
Les selects sont dans le **step 2** du formulaire, donc ils ne sont visibles que si l'enseignant passe par step 1 → step 2.

**OPTION 3 : JavaScript qui vide les selects**
Vérifier s'il y a du JavaScript qui réinitialise `l_etab_benef[]` à 0.

---

## ⚡ Fix temporaire

Modifier `index.php` pour créer **une fiche par ligne** si aucun établissement détecté :

```php
// À la ligne 558-573, si $lignesParEtab est vide,
// créer 1 fiche par ligne à la place

if (empty($lignesParEtab)) {
    // Fallback : créer 1 fiche par ligne
    foreach ($lignes as $ligne) {
        // créer fiche...
    }
}
```

---

## 📋 Checklist debug

- [ ] Vérifier les logs Apache après soumission
- [ ] Vérifier la console du navigateur (F12)
- [ ] Inspecter l'HTML des selects
- [ ] Vérifier que `$_etabsDB` n'est pas vide
- [ ] Vérifier qu'il n'y a pas de CSS masquant les selects
- [ ] Vérifier le JavaScript qui pourrait réinitialiser les valeurs

---

## 🚀 Solution à long terme

**Implémenter un formulaire qui force le choix d'établissement pour IESR NON UJKZ :**

1. Si IESR_NON_UJKZ : afficher les selects établissement/département de manière **obligatoire et visible**
2. Validation JavaScript : refuser la soumission si établissement non sélectionné
3. Message clair : "Pour chaque cours, sélectionnez l'établissement où il est dispensé"

