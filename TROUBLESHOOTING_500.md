# 🔧 Résolution Erreur HTTP 500 - Guide Complet

## 🚨 Vous voyez "HTTP ERROR 500" - Voici comment résoudre :

### Étape 1 : Vérifier les Logs Railway (LE PLUS IMPORTANT)

1. **Dans Railway**, allez dans votre projet
2. **Cliquez sur votre service**
3. **Allez dans l'onglet "Deployments"**
4. **Cliquez sur le dernier déploiement** (celui en haut de la liste)
5. **Regardez les "Logs"** (onglet Logs ou cliquez sur "View Logs")
6. **Cherchez les erreurs en rouge** ou les messages d'erreur PHP

**⚠️ IMPORTANT :** Les logs vous diront exactement quel est le problème !

---

## 🔍 Causes Courantes et Solutions

### Cause 1 : Variables d'Environnement Manquantes ou Incorrectes

**Symptôme :** Erreur "Erreur de connexion PostgreSQL" dans les logs

**Solution :**
1. Allez dans **Variables** dans Railway
2. Vérifiez que vous avez **exactement** ces 6 variables :
   ```
   DB_HOST=db.tanhjilciixbyjtcfdng.supabase.co
   DB_NAME=postgres
   DB_USER=postgres
   DB_PASS=lv7V2nrEb5ru3rsd
   DB_PORT=5432
   DB_TYPE=postgres
   ```
3. **Vérifiez qu'il n'y a pas d'espaces** avant/après les valeurs
4. **Redéployez** après avoir corrigé

---

### Cause 2 : Erreur de Connexion à la Base de Données

**Symptôme :** "Erreur de connexion PostgreSQL" ou "Connection refused"

**Solutions :**

#### A. Vérifier que Supabase autorise les connexions externes

1. Allez dans **Supabase** → **Settings** → **Database**
2. Cherchez **"Connection pooling"** ou **"Network restrictions"**
3. Vérifiez que les connexions externes sont autorisées
4. Par défaut, Supabase autorise les connexions depuis n'importe où

#### B. Vérifier le mot de passe

1. Dans Supabase → **Settings** → **Database**
2. Si le mot de passe est masqué, **réinitialisez-le**
3. **Mettez à jour** la variable `DB_PASS` dans Railway
4. **Redéployez**

---

### Cause 3 : Erreur PHP (Syntaxe ou Extension Manquante)

**Symptôme :** Erreur PHP dans les logs (ex: "Call to undefined function")

**Solution :**
1. **Regardez les logs** pour voir l'erreur exacte
2. Vérifiez que toutes les extensions PHP sont installées dans le Dockerfile
3. Le Dockerfile devrait avoir `pdo_pgsql` installé

---

### Cause 4 : Fichier index.php Manquant ou Erreur de Chemin

**Symptôme :** "File not found" ou erreur de chemin

**Solution :**
1. Vérifiez que `index.php` existe à la racine du projet
2. Vérifiez que le Dockerfile copie bien tous les fichiers

---

## 🔧 Actions Immédiates à Faire

### 1. Vérifier les Logs (FAITES-LE MAINTENANT)

1. Railway → Votre Service → **Deployments**
2. Cliquez sur le **dernier déploiement**
3. **Onglet "Logs"**
4. **Copiez l'erreur complète** que vous voyez
5. **Envoyez-moi l'erreur** et je vous aiderai à la corriger

### 2. Vérifier les Variables d'Environnement

1. Railway → Votre Service → **Variables**
2. Vérifiez que vous avez bien les 6 variables
3. Vérifiez qu'il n'y a pas d'espaces ou de caractères invisibles

### 3. Vérifier le Dockerfile

Assurez-vous que le Dockerfile est correct (il devrait l'être déjà).

---

## 📋 Checklist de Diagnostic

- [ ] J'ai vérifié les logs Railway et copié l'erreur
- [ ] J'ai vérifié que les 6 variables d'environnement sont présentes
- [ ] J'ai vérifié qu'il n'y a pas d'espaces dans les valeurs
- [ ] J'ai vérifié que le mot de passe Supabase est correct
- [ ] J'ai redéployé après avoir corrigé les variables

---

## 🆘 Si Rien Ne Fonctionne

1. **Copiez l'erreur complète** des logs Railway
2. **Envoyez-moi l'erreur**
3. Je vous aiderai à la corriger spécifiquement

---

## 💡 Astuce

Les logs Railway sont **votre meilleur ami** pour déboguer ! Ils vous diront exactement ce qui ne va pas.

**Commencez par vérifier les logs maintenant !** 🔍

