# 🌐 Déploiement en Ligne - Guide Rapide

## 🚀 Options Gratuites (Recommandées)

### Option 1: 000webhost (GRATUIT) ⭐ RECOMMANDÉ
**Avantages:** Gratuit, facile, base de données MySQL incluse

1. **Créer un compte:**
   - Allez sur: https://www.000webhost.com
   - Créez un compte gratuit
   - Choisissez un nom de domaine (ex: votrenom.000webhostapp.com)

2. **Uploader les fichiers:**
   - Connectez-vous au File Manager
   - Supprimez le fichier `index.html` par défaut
   - Uploadez TOUS vos fichiers PHP dans le dossier `public_html`

3. **Créer la base de données:**
   - Allez dans "Databases" dans le panneau
   - Créez une nouvelle base de données MySQL
   - Notez: nom de la DB, utilisateur, mot de passe, serveur (généralement `localhost`)

4. **Importer la base de données:**
   - Allez dans phpMyAdmin (via le panneau)
   - Sélectionnez votre base de données
   - Cliquez "Importer" → Choisissez `database_export.sql`

5. **Modifier config/db.php:**
   ```php
   $host = 'localhost'; // Ou l'adresse fournie par 000webhost
   $db   = 'votre_nom_db';
   $user = 'votre_utilisateur_db';
   $pass = 'votre_mot_de_passe_db';
   ```

6. **Accéder à votre site:**
   - Votre site sera accessible sur: `https://votrenom.000webhostapp.com`

---

### Option 2: InfinityFree (GRATUIT)
**Avantages:** Gratuit, illimité, sans publicité

1. **Créer un compte:**
   - Allez sur: https://www.infinityfree.net
   - Créez un compte gratuit
   - Choisissez un nom de domaine

2. **Uploader via FTP:**
   - Utilisez FileZilla (gratuit)
   - Hôte: `ftpupload.net`
   - Utilisateur/Mot de passe: fournis dans le panneau
   - Uploadez tous les fichiers dans `htdocs`

3. **Base de données:**
   - Créez la DB via le panneau InfinityFree
   - Importez via phpMyAdmin
   - Modifiez `config/db.php`

---

### Option 3: Freehostia (GRATUIT)
**Avantages:** Gratuit, 250MB d'espace, MySQL inclus

1. **Créer un compte:** https://www.freehostia.com
2. **Uploader les fichiers** via File Manager
3. **Créer et importer** la base de données
4. **Configurer** `config/db.php`

---

## 💰 Options Payantes (Plus Professionnelles)

### Option 4: Hostinger (À partir de 2.99€/mois)
**Avantages:** Rapide, support excellent, domaine gratuit

1. Allez sur: https://www.hostinger.fr
2. Choisissez un plan d'hébergement
3. Uploadez via FTP ou File Manager
4. Créez la base de données et importez

---

## 📦 Préparation des Fichiers pour Déploiement

### Fichiers à Uploader:
✅ Tous les fichiers `.php`
✅ Le dossier `config/`
✅ Le dossier `photos/` (avec les images)
✅ `database_export.sql` (pour l'import)

### Fichiers à NE PAS Uploader:
❌ `INSTALL.sh`, `INSTALL.bat` (scripts locaux)
❌ Fichiers de documentation (optionnel)

---

## 🔧 Configuration Après Déploiement

### 1. Modifier `config/db.php`:
```php
<?php
$host = 'localhost'; // Ou l'adresse MySQL du serveur
$db   = 'nom_de_votre_base';
$user = 'utilisateur_mysql';
$pass = 'mot_de_passe_mysql';
$charset = 'utf8mb4';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die('Erreur de connexion: ' . $conn->connect_error);
}
?>
```

### 2. Vérifier les Permissions:
- Le dossier `photos/` doit avoir les permissions 755 ou 777
- Les fichiers PHP doivent avoir les permissions 644

### 3. Tester:
- Accédez à votre site
- Connectez-vous avec un compte test
- Vérifiez que tout fonctionne

---

## 🚨 Problèmes Courants

### Erreur de connexion à la base de données:
- Vérifiez que MySQL est activé sur votre hébergement
- Vérifiez les identifiants dans `config/db.php`
- Certains hébergeurs utilisent `localhost` ou une adresse IP spécifique

### Erreur 500:
- Vérifiez les logs d'erreur dans le panneau d'hébergement
- Assurez-vous que PHP 7.4+ est activé
- Vérifiez que les extensions `mysqli` et `mbstring` sont activées

### Les images ne s'affichent pas:
- Vérifiez que le dossier `photos/` est bien uploadé
- Vérifiez les permissions du dossier (755 ou 777)
- Vérifiez les chemins dans le code (relatifs vs absolus)

---

## 📝 Checklist de Déploiement

- [ ] Compte créé sur l'hébergeur
- [ ] Tous les fichiers uploadés
- [ ] Base de données créée
- [ ] `database_export.sql` importé
- [ ] `config/db.php` modifié avec les bons identifiants
- [ ] Permissions des dossiers vérifiées (755 pour photos/)
- [ ] Site accessible via l'URL
- [ ] Connexion testée avec un compte utilisateur
- [ ] Toutes les fonctionnalités testées

---

## 🎯 Recommandation Finale

**Pour un déploiement rapide et gratuit:**
👉 **000webhost** est la meilleure option
- Gratuit
- Facile à utiliser
- Base de données MySQL incluse
- Pas de publicité intrusive
- Support communautaire

**Pour un site professionnel:**
👉 **Hostinger** (2.99€/mois)
- Performance excellente
- Support 24/7
- Domaine gratuit la première année
- SSL gratuit

---

## 🔗 Liens Utiles

- 000webhost: https://www.000webhost.com
- InfinityFree: https://www.infinityfree.net
- Freehostia: https://www.freehostia.com
- Hostinger: https://www.hostinger.fr
- FileZilla (FTP): https://filezilla-project.org

---

**Bon déploiement! 🚀**

