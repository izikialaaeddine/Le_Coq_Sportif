# 📥 Importer la Base de Données dans Supabase

## 🚀 Étapes Rapides

### 1. Ouvrir l'éditeur SQL dans Supabase

1. Allez sur votre projet Supabase
2. Cliquez sur **SQL Editor** dans le menu de gauche
3. Cliquez sur **New query**

### 2. Copier et exécuter le script

1. Ouvrez le fichier `supabase_migration.sql`
2. **Copiez TOUT le contenu**
3. **Collez-le** dans l'éditeur SQL de Supabase
4. Cliquez sur **Run** (ou appuyez sur Ctrl+Enter / Cmd+Enter)

### 3. Vérifier l'importation

1. Allez dans **Table Editor** dans Supabase
2. Vérifiez que toutes les tables sont créées:
   - ✅ Role
   - ✅ Utilisateur
   - ✅ Echantillon
   - ✅ Demande
   - ✅ DemandeEchantillon
   - ✅ Retour
   - ✅ RetourEchantillon
   - ✅ Fabrication
   - ✅ Historique
   - ✅ Couleur
   - ✅ Famille
   - Et les autres tables...

3. Vérifiez les données:
   - **Role:** 4 rôles (Chef de Stock, Chef de Groupe, Réception, Admin)
   - **Utilisateur:** 4 utilisateurs (admin, stock, groupe, reception)
   - **Couleur:** 22 couleurs
   - **Famille:** 25 familles
   - **Echantillon:** 1 échantillon (REF0192)

## ✅ Vérification des Utilisateurs

Testez la connexion avec:
- **Admin:** `admin` / `admin123`
- **Stock:** `stock` / `stock123`
- **Groupe:** `groupe` / `groupe123`
- **Réception:** `reception` / `reception123`

## 🔍 Si vous avez des erreurs

### Erreur: "relation already exists"
- Les tables existent déjà
- Solution: Supprimez les tables existantes ou utilisez `DROP TABLE IF EXISTS` avant de créer

### Erreur: "duplicate key value"
- Les données existent déjà
- Solution: Les `ON CONFLICT DO NOTHING` empêchent les doublons, c'est normal

### Erreur: "column does not exist"
- Vérifiez que toutes les tables sont créées dans le bon ordre
- Les tables avec FOREIGN KEY doivent être créées après les tables référencées

## 📝 Notes

- Le script utilise `CREATE TABLE IF NOT EXISTS` donc vous pouvez l'exécuter plusieurs fois
- Les `ON CONFLICT DO NOTHING` empêchent les doublons lors de ré-exécution
- Les séquences sont ajustées pour continuer à partir des IDs existants

## 🎉 C'est tout!

Une fois le script exécuté, votre base de données est prête dans Supabase!

