# 🗄️ Configuration Supabase pour Vercel

## Étape 1: Créer un projet Supabase

1. Allez sur [https://supabase.com](https://supabase.com)
2. Créez un compte (gratuit)
3. Créez un nouveau projet
4. Notez vos identifiants de connexion

## Étape 2: Créer les tables dans Supabase

Supabase utilise PostgreSQL, donc la syntaxe SQL est légèrement différente de MySQL.

### Script de migration pour Supabase

Exécutez ce script dans l'éditeur SQL de Supabase:

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

-- Insérer les utilisateurs par défaut (mots de passe hashés)
-- Note: Vous devrez générer les hash avec password_hash() en PHP
INSERT INTO Utilisateur (idRole, Identifiant, MotDePasse, Nom, Prenom) VALUES
    (4, 'admin', '$2y$10$...', 'Admin', 'User'),
    (1, 'stock', '$2y$10$...', 'Stock', 'Manager'),
    (2, 'groupe', '$2y$10$...', 'Groupe', 'Chef'),
    (3, 'reception', '$2y$10$...', 'Réception', 'Agent')
ON CONFLICT (Identifiant) DO NOTHING;
```

## Étape 3: Configurer les variables d'environnement dans Vercel

Dans votre projet Vercel, allez dans Settings → Environment Variables et ajoutez:

- `DB_HOST`: L'adresse de votre base Supabase (ex: `db.xxxxx.supabase.co`)
- `DB_NAME`: `postgres`
- `DB_USER`: Votre utilisateur Supabase
- `DB_PASS`: Votre mot de passe Supabase
- `DB_PORT`: `5432` (ou le port fourni par Supabase)

## Étape 4: Adapter le code pour PostgreSQL

Le code actuel utilise MySQLi. Pour Supabase (PostgreSQL), vous devrez:

1. Utiliser PDO au lieu de MySQLi
2. Adapter certaines requêtes SQL (syntaxe différente)
3. Utiliser `config/db_supabase.php` au lieu de `config/db.php`

## Notes importantes

- PostgreSQL utilise `SERIAL` au lieu de `AUTO_INCREMENT`
- Les guillemets sont différents pour les identifiants
- Certaines fonctions MySQL n'existent pas dans PostgreSQL
- Les types de données peuvent différer

