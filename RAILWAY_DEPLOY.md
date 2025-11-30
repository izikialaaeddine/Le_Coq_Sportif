# 🚂 Déploiement sur Railway (RECOMMANDÉ)

Railway est **parfait** pour déployer votre application PHP + Supabase!

## 🚀 Déploiement en 5 minutes

### Étape 1: Créer un compte Railway

1. Allez sur **[railway.app](https://railway.app)**
2. Cliquez "Start a New Project"
3. Connectez-vous avec GitHub
4. Autorisez l'accès à vos repositories

### Étape 2: Créer un nouveau projet

1. Cliquez "New Project"
2. Sélectionnez "Deploy from GitHub repo"
3. Choisissez votre repo: `Le_Coq_Sportif`
4. Railway détectera automatiquement que c'est du PHP

### Étape 3: Configurer le service

Railway va automatiquement:
- ✅ Détecter PHP
- ✅ Installer les dépendances
- ✅ Démarrer votre application

**Si besoin, configurez manuellement:**
- **Start Command:** `php -S 0.0.0.0:$PORT`
- **Build Command:** (laissez vide)

### Étape 4: Ajouter Supabase

**Option A: Utiliser Supabase externe**
1. Créez votre projet sur [supabase.com](https://supabase.com)
2. Notez vos identifiants de connexion
3. Dans Railway, allez dans "Variables"
4. Ajoutez:
   ```
   DB_HOST=votre_host_supabase
   DB_NAME=postgres
   DB_USER=postgres
   DB_PASS=votre_password
   DB_PORT=5432
   ```

**Option B: Utiliser PostgreSQL de Railway**
1. Dans votre projet Railway, cliquez "New"
2. Sélectionnez "Database" → "Add PostgreSQL"
3. Railway créera automatiquement une base PostgreSQL
4. Les variables d'environnement seront ajoutées automatiquement!

### Étape 5: Configurer config/db.php

Modifiez `config/db.php` pour utiliser les variables d'environnement:

```php
<?php
$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: 'le_coq_sportif';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$port = getenv('DB_PORT') ?: '3306';

// Pour PostgreSQL (Supabase)
if (getenv('DB_TYPE') === 'postgres' || strpos($host, 'supabase') !== false) {
    try {
        $dsn = "pgsql:host=$host;port=$port;dbname=$db";
        $conn = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        die('Erreur de connexion: ' . $e->getMessage());
    }
} else {
    // Pour MySQL
    $conn = new mysqli($host, $user, $pass, $db);
    if ($conn->connect_error) {
        die('Erreur de connexion: ' . $conn->connect_error);
    }
}
?>
```

### Étape 6: Déployer!

Railway déploiera automatiquement votre application!

Votre site sera accessible sur: `https://votre-projet.up.railway.app`

## 📝 Notes importantes

- Railway offre **500 heures gratuites** par mois
- SSL est automatique
- Déploiement automatique à chaque push sur GitHub
- Logs en temps réel dans le dashboard

## 🔧 Configuration avancée

Si vous avez besoin de personnaliser, créez un fichier `railway.json` (déjà créé pour vous).

## ✅ C'est tout!

Votre application PHP sera en ligne en quelques minutes!

