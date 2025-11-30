# 🚀 Déploiement sur Vercel

## Méthode 1: Via l'interface Vercel (Recommandé)

1. **Allez sur [vercel.com](https://vercel.com)**
   - Connectez-vous avec GitHub
   - Autorisez l'accès à votre repository

2. **Importez votre projet:**
   - Cliquez "Add New Project"
   - Sélectionnez le repository `Le_Coq_Sportif`
   - Vercel détectera automatiquement le fichier `vercel.json`

3. **Configurez les variables d'environnement:**
   - Dans les settings du projet, allez dans "Environment Variables"
   - Ajoutez:
     ```
     DB_HOST=votre_host_supabase
     DB_NAME=postgres
     DB_USER=votre_user_supabase
     DB_PASS=votre_password_supabase
     DB_PORT=5432
     ```

4. **Déployez:**
   - Cliquez "Deploy"
   - Vercel déploiera automatiquement votre projet

## Méthode 2: Via CLI

1. **Installez Vercel CLI:**
   ```bash
   npm i -g vercel
   ```

2. **Connectez-vous:**
   ```bash
   vercel login
   ```

3. **Déployez:**
   ```bash
   vercel
   ```

4. **Ajoutez les variables d'environnement:**
   ```bash
   vercel env add DB_HOST
   vercel env add DB_NAME
   vercel env add DB_USER
   vercel env add DB_PASS
   vercel env add DB_PORT
   ```

## Configuration Vercel pour PHP

Le fichier `vercel.json` est déjà configuré pour PHP. Vercel utilisera le runtime PHP 8.1.

## Points importants

⚠️ **Note:** Vercel supporte PHP via serverless functions, mais certaines limitations peuvent s'appliquer:
- Les sessions PHP peuvent nécessiter une configuration spéciale
- Les uploads de fichiers peuvent nécessiter un stockage externe (Supabase Storage)
- Certaines extensions PHP peuvent ne pas être disponibles

## Alternative: Utiliser Supabase pour le stockage

Pour les images dans le dossier `photos/`, utilisez Supabase Storage:
1. Créez un bucket dans Supabase Storage
2. Uploadez les images via l'API Supabase
3. Modifiez les chemins dans votre code pour pointer vers Supabase Storage

## URLs après déploiement

Votre site sera accessible sur:
- Production: `https://votre-projet.vercel.app`
- Preview: `https://votre-projet-git-main.vercel.app`

## Mise à jour continue

À chaque push sur GitHub, Vercel redéploiera automatiquement votre application!

