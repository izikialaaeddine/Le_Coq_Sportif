# 🔧 Résolution Erreur IPv6 "Network is unreachable"

## 🚨 Problème Identifié

L'erreur montre que Railway essaie de se connecter via **IPv6**, mais Supabase n'accepte peut-être que les connexions **IPv4**.

**Erreur :**
```
connection to server at "db.tanhjilciixbyjtcfdng.supabase.co" 
(2a05:d014:1c06:5f18:8a96:26a8:67ff:7171), port 5432 failed: 
Network is unreachable
```

L'adresse `2a05:d014:1c06:5f18:8a96:26a8:67ff:7171` est une **adresse IPv6**.

## ✅ Solution Appliquée

J'ai modifié le code pour **forcer l'utilisation d'IPv4** en résolvant le hostname en IPv4 avant la connexion.

### Changements dans `config/db.php` :

1. **Résolution DNS en IPv4** : `gethostbyname()` résout le hostname en IPv4
2. **Utilisation de l'IP résolue** dans le DSN PDO
3. **Timeout augmenté** à 10 secondes

## 🚀 Prochaines Étapes

### 1. Attendre le Redéploiement (2-3 minutes)

Railway va automatiquement redéployer avec les corrections.

### 2. Tester à Nouveau

Après le redéploiement :
- Testez : `https://lecoqsportif-production.up.railway.app/test_connection.php`
- Vous devriez voir "Host résolu" avec une adresse IPv4
- La connexion devrait fonctionner

### 3. Si ça ne Fonctionne Toujours Pas

#### Option A : Vérifier les Restrictions Supabase

1. Allez dans **Supabase** → **Settings** → **Database**
2. Cherchez **"Network restrictions"** ou **"IP allowlist"**
3. Vérifiez qu'il n'y a **pas de restrictions** qui bloquent Railway
4. Par défaut, Supabase autorise toutes les connexions

#### Option B : Utiliser Connection Pooler (Alternative)

Si IPv4 ne fonctionne toujours pas, essayez le **Connection Pooler** :

1. Dans Supabase → **Settings** → **Database**
2. Cherchez **"Connection pooling"**
3. Utilisez le port **6543** au lieu de **5432**
4. Dans Railway, changez `DB_PORT` à `6543`
5. Redéployez

**Note :** Le Connection Pooler a quelques limitations, mais devrait fonctionner.

## 📋 Checklist

- [ ] Railway a redéployé (statut "Success")
- [ ] J'ai testé `/test_connection.php`
- [ ] Je vois "Host résolu" avec une adresse IPv4 (ex: 54.xxx.xxx.xxx)
- [ ] La connexion fonctionne maintenant
- [ ] Si non, j'ai vérifié les restrictions Supabase

## 🔍 Vérification

Dans le script de test, vous devriez maintenant voir :

```
Résolution DNS...
Host résolu: db.tanhjilciixbyjtcfdng.supabase.co → 54.xxx.xxx.xxx
```

Au lieu d'une adresse IPv6.

---

**Les corrections sont poussées. Attendez 2-3 minutes et testez à nouveau ! 🚀**

