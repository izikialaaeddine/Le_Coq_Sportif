# 🔄 Mise à Jour des Variables Railway - Session Pooler

## ✅ Nouvelles Informations de Connexion

Vous utilisez maintenant le **Session Pooler** de Supabase au lieu de la connexion directe.

## 🔧 Variables à Mettre à Jour dans Railway

Allez dans **Railway** → Votre Service → **Variables** et mettez à jour :

### Variable 1 : DB_HOST
- **Ancienne valeur :** `db.tanhjilciixbyjtcfdng.supabase.co`
- **Nouvelle valeur :** `aws-1-eu-central-1.pooler.supabase.com`
- **Action :** Modifiez la variable `DB_HOST`

### Variable 2 : DB_USER
- **Ancienne valeur :** `postgres`
- **Nouvelle valeur :** `postgres.tanhjilciixbyjtcfdng`
- **Action :** Modifiez la variable `DB_USER`

### Variable 3 : DB_PASS
- **Valeur :** `lv7V2nrEb5ru3rsd` (le même)
- **Action :** Vérifiez que c'est toujours correct

### Variable 4 : DB_PORT
- **Valeur :** `5432` (reste le même)
- **Action :** Vérifiez que c'est toujours `5432`

### Variable 5 : DB_NAME
- **Valeur :** `postgres` (reste le même)
- **Action :** Vérifiez que c'est toujours `postgres`

### Variable 6 : DB_TYPE
- **Valeur :** `postgres` (reste le même)
- **Action :** Vérifiez que c'est toujours `postgres`

## 📋 Liste Complète des Variables (Après Mise à Jour)

| Variable | Valeur |
|----------|--------|
| `DB_HOST` | `aws-1-eu-central-1.pooler.supabase.com` |
| `DB_NAME` | `postgres` |
| `DB_USER` | `postgres.tanhjilciixbyjtcfdng` |
| `DB_PASS` | `lv7V2nrEb5ru3rsd` |
| `DB_PORT` | `5432` |
| `DB_TYPE` | `postgres` |

## 🚀 Après la Mise à Jour

1. **Railway redéploiera automatiquement** (2-3 minutes)
2. **Testez** : `https://lecoqsportif-production.up.railway.app/test_connection.php`
3. **Vous devriez voir** : "✅ Connexion PDO réussie!"

## ✅ Avantages du Session Pooler

- ✅ **Gère automatiquement** IPv4/IPv6
- ✅ **Meilleure gestion** des connexions
- ✅ **Plus stable** pour les applications en production
- ✅ **Pas de problème** de résolution DNS

## 🔍 Vérification

Après le redéploiement, dans le script de test, vous devriez voir :

```
DSN: pgsql:host=aws-1-eu-central-1.pooler.supabase.com;port=5432;dbname=postgres;...
✅ Connexion PDO réussie!
```

---

**Mettez à jour les variables dans Railway maintenant ! 🚀**

