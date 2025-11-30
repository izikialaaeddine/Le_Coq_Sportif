# ✅ Déploiement Réussi - Prochaines Étapes

Félicitations ! Votre application est déployée sur Railway. Voici ce qu'il faut faire maintenant :

## 🔧 Étape 1: Configurer Supabase

### 1.1 Créer un projet Supabase

1. Allez sur **[supabase.com](https://supabase.com)**
2. Créez un compte (gratuit)
3. Cliquez "New Project"
4. Remplissez:
   - **Name:** Le_Coq_Sportif (ou autre)
   - **Database Password:** Choisissez un mot de passe fort (⚠️ NOTEZ-LE!)
   - **Region:** Choisissez la région la plus proche
5. Cliquez "Create new project"
6. Attendez 2-3 minutes que le projet soit créé

### 1.2 Récupérer les identifiants

Une fois le projet créé:
1. Allez dans **Settings** → **Database**
2. Notez ces informations:
   - **Host:** `db.xxxxx.supabase.co`
   - **Database name:** `postgres`
   - **Port:** `5432`
   - **User:** `postgres`
   - **Password:** (celui que vous avez créé)

## 🗄️ Étape 2: Créer les tables dans Supabase

### 2.1 Ouvrir l'éditeur SQL

1. Dans Supabase, allez dans **SQL Editor**
2. Cliquez "New query"

### 2.2 Exécuter le script de migration

Copiez et exécutez ce script SQL (adapté pour PostgreSQL):

```sql
-- Table Role
CREATE TABLE IF NOT EXISTS Role (
    idRole SERIAL PRIMARY KEY,
    Role VARCHAR(100) NOT NULL
);

-- Table Utilisateur
CREATE TABLE IF NOT EXISTS Utilisateur (
    idUtilisateur SERIAL PRIMARY KEY,
    idRole INTEGER REFERENCES Role(idRole),
    Identifiant VARCHAR(100) UNIQUE,
    MotDePasse VARCHAR(255),
    Nom VARCHAR(100),
    Prenom VARCHAR(100)
);

-- Table Echantillon
CREATE TABLE IF NOT EXISTS Echantillon (
    RefEchantillon VARCHAR(100) PRIMARY KEY,
    Famille VARCHAR(100),
    Couleur VARCHAR(100),
    Taille VARCHAR(50),
    Qte INTEGER DEFAULT 0,
    Statut VARCHAR(50) DEFAULT 'disponible',
    Description TEXT,
    DateCreation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    idUtilisateur INTEGER REFERENCES Utilisateur(idUtilisateur)
);

-- Table Demande
CREATE TABLE IF NOT EXISTS Demande (
    idDemande SERIAL PRIMARY KEY,
    idUtilisateur INTEGER REFERENCES Utilisateur(idUtilisateur),
    DateDemande TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    Statut VARCHAR(50) DEFAULT 'En attente',
    Commentaire TEXT
);

-- Table DemandeEchantillon
CREATE TABLE IF NOT EXISTS DemandeEchantillon (
    idDemandeEchantillon SERIAL PRIMARY KEY,
    idDemande INTEGER REFERENCES Demande(idDemande),
    refEchantillon VARCHAR(100) REFERENCES Echantillon(RefEchantillon),
    qte INTEGER NOT NULL
);

-- Table Retour
CREATE TABLE IF NOT EXISTS Retour (
    idRetour SERIAL PRIMARY KEY,
    idDemande INTEGER REFERENCES Demande(idDemande),
    DateRetour TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    Statut VARCHAR(50) DEFAULT 'En attente'
);

-- Table RetourEchantillon
CREATE TABLE IF NOT EXISTS RetourEchantillon (
    idRetourEchantillon SERIAL PRIMARY KEY,
    idRetour INTEGER REFERENCES Retour(idRetour),
    RefEchantillon VARCHAR(100) REFERENCES Echantillon(RefEchantillon),
    qte INTEGER NOT NULL
);

-- Table Fabrication
CREATE TABLE IF NOT EXISTS Fabrication (
    idLot SERIAL PRIMARY KEY,
    DateFabrication TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    StatutFabrication VARCHAR(50) DEFAULT 'En cours'
);

-- Table FabricationEchantillon
CREATE TABLE IF NOT EXISTS FabricationEchantillon (
    idFabricationEchantillon SERIAL PRIMARY KEY,
    idLot INTEGER REFERENCES Fabrication(idLot),
    RefEchantillon VARCHAR(100) REFERENCES Echantillon(RefEchantillon),
    Qte INTEGER NOT NULL
);

-- Table Historique
CREATE TABLE IF NOT EXISTS Historique (
    idHistorique SERIAL PRIMARY KEY,
    idUtilisateur INTEGER REFERENCES Utilisateur(idUtilisateur),
    RefEchantillon VARCHAR(100),
    TypeAction VARCHAR(100),
    DateAction TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    Description TEXT
);

-- Insérer les rôles
INSERT INTO Role (Role) VALUES 
    ('Chef de Stock'),
    ('Chef de Groupe'),
    ('Réception'),
    ('Admin')
ON CONFLICT DO NOTHING;
```

### 2.3 Créer les utilisateurs par défaut

Vous devrez créer les utilisateurs avec des mots de passe hashés. Exécutez ce script PHP localement pour générer les hash:

```php
<?php
$users = [
    ['idRole' => 4, 'Identifiant' => 'admin', 'MotDePasse' => 'admin123', 'Nom' => 'Admin', 'Prenom' => 'User'],
    ['idRole' => 1, 'Identifiant' => 'stock', 'MotDePasse' => 'stock123', 'Nom' => 'Stock', 'Prenom' => 'Manager'],
    ['idRole' => 2, 'Identifiant' => 'groupe', 'MotDePasse' => 'groupe123', 'Nom' => 'Groupe', 'Prenom' => 'Chef'],
    ['idRole' => 3, 'Identifiant' => 'reception', 'MotDePasse' => 'reception123', 'Nom' => 'Réception', 'Prenom' => 'Agent']
];

foreach ($users as $user) {
    $hash = password_hash($user['MotDePasse'], PASSWORD_DEFAULT);
    echo "INSERT INTO Utilisateur (idRole, Identifiant, MotDePasse, Nom, Prenom) VALUES ";
    echo "({$user['idRole']}, '{$user['Identifiant']}', '$hash', '{$user['Nom']}', '{$user['Prenom']}');\n\n";
}
?>
```

Puis exécutez les INSERT dans Supabase SQL Editor.

## ⚙️ Étape 3: Configurer Railway

### 3.1 Ajouter les variables d'environnement

1. Dans Railway, allez dans votre projet
2. Cliquez sur votre service
3. Allez dans l'onglet **Variables**
4. Ajoutez ces variables:

```
DB_HOST=votre_host_supabase (ex: db.xxxxx.supabase.co)
DB_NAME=postgres
DB_USER=postgres
DB_PASS=votre_mot_de_passe_supabase
DB_PORT=5432
DB_TYPE=postgres
```

### 3.2 Redéployer

Railway redéploiera automatiquement avec les nouvelles variables.

## 🧪 Étape 4: Tester l'application

1. Allez sur l'URL de votre application Railway
2. Vous devriez voir la page de connexion
3. Connectez-vous avec: `admin` / `admin123`
4. Testez toutes les fonctionnalités

## 🔍 Étape 5: Vérifier les logs

Si quelque chose ne fonctionne pas:
1. Dans Railway, allez dans l'onglet **Deployments**
2. Cliquez sur le dernier déploiement
3. Regardez les logs pour voir les erreurs

## ⚠️ Notes importantes

- Le code actuel utilise MySQLi, mais `config/db.php` a été adapté pour supporter PostgreSQL
- Certaines requêtes SQL peuvent nécessiter des ajustements (syntaxe différente)
- Testez bien toutes les fonctionnalités après le déploiement

## 🎉 C'est tout!

Votre application devrait maintenant être fonctionnelle en ligne!

