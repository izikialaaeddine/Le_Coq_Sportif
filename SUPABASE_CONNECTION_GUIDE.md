# 🔍 Guide Complet : Trouver les Informations de Connexion Supabase

## 📋 Informations dont vous avez besoin

Vous devez trouver ces 5 informations pour Railway :
- `DB_HOST` - L'adresse du serveur de base de données
- `DB_NAME` - Le nom de la base de données
- `DB_USER` - Le nom d'utilisateur
- `DB_PASS` - Le mot de passe
- `DB_PORT` - Le port (généralement 5432)

---

## 🚀 Étape 1 : Se connecter à Supabase

1. **Allez sur** : https://supabase.com
2. **Cliquez sur "Sign In"** (en haut à droite)
3. **Connectez-vous** avec votre compte (Google, GitHub, ou email)

---

## 🏠 Étape 2 : Accéder à votre Projet

1. **Une fois connecté**, vous verrez votre **Dashboard Supabase**
2. **Cliquez sur votre projet** (celui que vous avez créé)
   - Si vous n'avez pas encore de projet, créez-en un nouveau

---

## ⚙️ Étape 3 : Accéder aux Paramètres de la Base de Données

### Méthode 1 : Via le Menu Latéral (Recommandé)

1. **Dans le menu de gauche**, cherchez l'icône **"Settings"** (⚙️)
   - C'est généralement en bas du menu
   - Ou utilisez le raccourci : cliquez sur l'icône de votre profil en bas à gauche

2. **Cliquez sur "Settings"**

3. **Dans le sous-menu qui apparaît**, cliquez sur **"Database"**
   - Vous verrez : Settings → Database

### Méthode 2 : Via l'URL Directe

1. **Regardez l'URL** de votre navigateur
2. Elle devrait ressembler à : `https://app.supabase.com/project/xxxxx/settings/database`
3. Si vous êtes déjà dans Settings, cliquez sur **"Database"** dans le menu

---

## 📊 Étape 4 : Trouver les Informations de Connexion

Une fois dans **Settings → Database**, vous verrez plusieurs sections. Voici où trouver chaque information :

### Section 1 : "Connection string" ou "Connection pooling"

**C'est la section la plus importante !**

Vous verrez quelque chose comme :

```
postgresql://postgres:[YOUR-PASSWORD]@db.abcdefghijklmnop.supabase.co:5432/postgres
```

Ou vous verrez un formulaire avec des champs séparés.

### Section 2 : "Connection info" ou "Database settings"

Vous verrez des informations comme :
- **Host** : `db.xxxxx.supabase.co`
- **Database name** : `postgres`
- **Port** : `5432`
- **User** : `postgres`
- **Password** : (masqué ou visible)

---

## 🔑 Étape 5 : Extraire Chaque Information

### A. DB_HOST (Host)

1. **Cherchez le champ "Host"** ou regardez dans la connection string
2. **Vous verrez quelque chose comme** : `db.abcdefghijklmnop.supabase.co`
3. **C'est votre DB_HOST** ✅
   - Exemple : `db.abcdefghijklmnop.supabase.co`

**⚠️ Important** : Ne mettez PAS `https://` ou `http://` devant, juste l'adresse !

### B. DB_NAME (Database name)

1. **Cherchez le champ "Database name"** ou "Database"
2. **C'est presque toujours** : `postgres` ✅
3. **Copiez cette valeur**

### C. DB_USER (User)

1. **Cherchez le champ "User"** ou "Username"
2. **C'est presque toujours** : `postgres` ✅
3. **Copiez cette valeur**

### D. DB_PORT (Port)

1. **Cherchez le champ "Port"**
2. **C'est presque toujours** : `5432` ✅
   - C'est le port par défaut de PostgreSQL
3. **Copiez cette valeur**

### E. DB_PASS (Password) - Le Plus Important !

**⚠️ ATTENTION** : Le mot de passe peut être masqué ou visible selon votre configuration.

#### Si le mot de passe est visible :

1. **Cherchez le champ "Password"**
2. **Cliquez sur l'icône "👁️" (œil)** pour révéler le mot de passe
3. **Copiez le mot de passe** ✅

#### Si le mot de passe est masqué ou oublié :

1. **Cherchez un bouton "Reset database password"** ou "Reset password"
2. **Cliquez dessus**
3. **Supabase va générer un nouveau mot de passe**
4. **⚠️ IMPORTANT** : Copiez-le immédiatement ! Vous ne pourrez plus le voir après
5. **Sauvegardez-le dans un endroit sûr**

**💡 Astuce** : Si vous avez créé le projet récemment, le mot de passe peut être dans l'email de confirmation Supabase.

---

## 📝 Étape 6 : Vérifier la Connection String (Optionnel mais Recommandé)

Si vous voyez une **"Connection string"**, vous pouvez extraire toutes les informations d'un coup :

### Format de la Connection String :

```
postgresql://[USER]:[PASSWORD]@[HOST]:[PORT]/[DATABASE]
```

### Exemple :

```
postgresql://postgres:MonMotDePasse123!@db.abcdefghijklmnop.supabase.co:5432/postgres
```

### Comment extraire :

- **USER** : `postgres` (avant le `:`)
- **PASSWORD** : `MonMotDePasse123!` (entre `:` et `@`)
- **HOST** : `db.abcdefghijklmnop.supabase.co` (entre `@` et `:`)
- **PORT** : `5432` (entre `:` et `/`)
- **DATABASE** : `postgres` (après le `/`)

---

## ✅ Étape 7 : Résumé de vos Informations

Une fois que vous avez tout trouvé, vous devriez avoir quelque chose comme :

```
DB_HOST = db.abcdefghijklmnop.supabase.co
DB_NAME = postgres
DB_USER = postgres
DB_PASS = MonMotDePasse123!
DB_PORT = 5432
DB_TYPE = postgres
```

---

## 🎯 Étape 8 : Où Trouver dans l'Interface Supabase (Chemin Complet)

**Chemin exact dans Supabase :**

1. **Dashboard Supabase** → Cliquez sur votre projet
2. **Menu de gauche** → Cliquez sur **"Settings"** (⚙️)
3. **Sous-menu** → Cliquez sur **"Database"**
4. **Section** → "Connection string" ou "Connection info"
5. **Voilà !** Toutes les informations sont là

---

## 🔒 Sécurité : Connection Pooling (Optionnel)

Supabase propose aussi **"Connection pooling"** avec un port différent (généralement 6543).

**Pour Railway, utilisez la connexion DIRECTE (port 5432), pas le pooling.**

---

## 📸 Emplacement Visuel dans l'Interface

```
┌─────────────────────────────────────┐
│  Supabase Dashboard                │
│                                     │
│  [Votre Projet] ← Cliquez ici      │
│                                     │
│  Menu Gauche:                       │
│  ├─ Table Editor                    │
│  ├─ SQL Editor                      │
│  ├─ Authentication                  │
│  ├─ Storage                         │
│  └─ ⚙️ Settings ← Cliquez ici       │
│      ├─ General                     │
│      ├─ Database ← Cliquez ici ✅   │
│      ├─ API                         │
│      └─ ...                         │
│                                     │
│  Section Database:                  │
│  ┌─────────────────────────────┐   │
│  │ Connection string:          │   │
│  │ postgresql://postgres:...   │   │
│  │                             │   │
│  │ Host: db.xxxxx.supabase.co  │   │
│  │ Database: postgres          │   │
│  │ Port: 5432                  │   │
│  │ User: postgres              │   │
│  │ Password: [👁️] ********    │   │
│  └─────────────────────────────┘   │
└─────────────────────────────────────┘
```

---

## ❓ Problèmes Courants

### "Je ne vois pas Settings"
- Vérifiez que vous êtes bien connecté à votre compte
- Vérifiez que vous avez bien sélectionné votre projet
- Le menu Settings peut être en bas du menu latéral

### "Le mot de passe est masqué et je ne peux pas le révéler"
- Utilisez le bouton "Reset database password"
- Le nouveau mot de passe sera généré et affiché une seule fois
- Sauvegardez-le immédiatement !

### "Je ne trouve pas la section Database"
- Assurez-vous d'être dans Settings (pas dans un autre onglet)
- Cherchez dans le sous-menu de Settings
- L'URL devrait contenir `/settings/database`

### "La connection string ne s'affiche pas"
- Certains projets peuvent avoir une interface légèrement différente
- Cherchez "Connection info" ou "Database settings"
- Toutes les informations sont toujours disponibles, juste présentées différemment

---

## 🎉 C'est Tout !

Une fois que vous avez ces 5 informations, vous pouvez les ajouter dans Railway comme variables d'environnement.

**Besoin d'aide ?** Si vous êtes bloqué à une étape, dites-moi exactement où vous êtes et ce que vous voyez, et je vous aiderai ! 🚀

