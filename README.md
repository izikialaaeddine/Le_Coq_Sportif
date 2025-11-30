# 🎯 Gestion d'Échantillons - Le Coq Sportif

Système de gestion d'échantillons développé par IZIKI Alaa Eddine et HAFIT Rabii.

## 🚀 Déploiement

### Déploiement sur Vercel + Supabase

1. **Cloner le repository:**
   ```bash
   git clone https://github.com/izikialaaeddine/Le_Coq_Sportif.git
   ```

2. **Configurer Supabase:**
   - Créez un projet sur [Supabase](https://supabase.com)
   - Importez le schéma de base de données (à adapter pour PostgreSQL)
   - Notez vos identifiants de connexion

3. **Configurer les variables d'environnement:**
   - Dans Vercel, ajoutez les variables d'environnement:
     - `DB_HOST`
     - `DB_NAME`
     - `DB_USER`
     - `DB_PASS`

4. **Déployer sur Vercel:**
   ```bash
   vercel
   ```

## 👤 Comptes Utilisateurs

| Rôle | Identifiant | Mot de passe |
|------|-------------|--------------|
| 🔴 Admin | `admin` | `admin123` |
| 🔵 Chef de Stock | `stock` | `stock123` |
| 🟢 Chef de Groupe | `groupe` | `groupe123` |
| 🟡 Réception | `reception` | `reception123` |

## 📋 Fonctionnalités

- ✅ Gestion des échantillons (CRUD)
- ✅ Système de demandes et approbations
- ✅ Gestion des retours
- ✅ Suivi des fabrications
- ✅ Historique complet des opérations
- ✅ Tableaux de bord par rôle
- ✅ Interface moderne et responsive

## 🛠️ Technologies

- PHP 8.1+
- Supabase (PostgreSQL)
- Tailwind CSS
- Font Awesome
- Chart.js

## 📝 Notes

- Le fichier `config/db.php` doit être créé à partir de `config/db.php.example`
- Configurez les variables d'environnement dans Vercel pour la production

