<?php
// Script de test de connexion - À supprimer après test
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Test de Connexion à Supabase</h1>";

// Afficher les variables d'environnement (sans le mot de passe complet)
echo "<h2>Variables d'Environnement:</h2>";
$host = getenv('DB_HOST') ?: 'NON DÉFINI';
$db = getenv('DB_NAME') ?: 'NON DÉFINI';
$user = getenv('DB_USER') ?: 'NON DÉFINI';
$pass = getenv('DB_PASS') ?: '';
$port = getenv('DB_PORT') ?: 'NON DÉFINI';
$type = getenv('DB_TYPE') ?: 'NON DÉFINI';

echo "DB_HOST: " . htmlspecialchars($host) . "<br>";
echo "DB_NAME: " . htmlspecialchars($db) . "<br>";
echo "DB_USER: " . htmlspecialchars($user) . "<br>";
echo "DB_PASS: " . ($pass ? str_repeat('*', strlen($pass)) : 'NON DÉFINI') . "<br>";
echo "DB_PORT: " . htmlspecialchars($port) . "<br>";
echo "DB_TYPE: " . htmlspecialchars($type) . "<br>";

echo "<hr>";

// Tester la connexion directement avec PDO
echo "<h2>Test de Connexion Directe PDO:</h2>";

try {
    // Forcer IPv4 en résolvant le hostname
    echo "Résolution DNS...<br>";
    $host_ip = gethostbyname($host);
    echo "Host résolu: " . htmlspecialchars($host) . " → " . htmlspecialchars($host_ip) . "<br><br>";
    
    // Utiliser l'IP si résolue, sinon le hostname
    $connect_host = ($host_ip !== $host) ? $host_ip : $host;
    
    $dsn = "pgsql:host=$connect_host;port=$port;dbname=$db;options='--client_encoding=UTF8'";
    echo "DSN: " . htmlspecialchars(str_replace($pass, '***', $dsn)) . "<br><br>";
    
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 10,
    ]);
    
    echo "✅ Connexion PDO réussie!<br><br>";
    
    // Tester une requête simple
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM Utilisateur");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "✅ Requête réussie! Nombre d'utilisateurs: " . ($row['count'] ?? 'N/A') . "<br>";
    
    // Tester la liste des tables
    echo "<br><h3>Tables disponibles:</h3>";
    $stmt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "<ul>";
    foreach ($tables as $table) {
        echo "<li>" . htmlspecialchars($table) . "</li>";
    }
    echo "</ul>";
    
} catch (PDOException $e) {
    echo "❌ <strong>Erreur PDO:</strong><br>";
    echo "Code: " . $e->getCode() . "<br>";
    echo "Message: " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "<br><strong>Détails:</strong><br>";
    echo "Vérifiez que:<br>";
    echo "1. Le host est correct: " . htmlspecialchars($host) . "<br>";
    echo "2. Le port est correct: " . htmlspecialchars($port) . "<br>";
    echo "3. Le nom de la base est correct: " . htmlspecialchars($db) . "<br>";
    echo "4. L'utilisateur est correct: " . htmlspecialchars($user) . "<br>";
    echo "5. Le mot de passe est correct<br>";
    echo "6. Supabase autorise les connexions externes<br>";
}

echo "<hr>";

// Tester avec le wrapper
echo "<h2>Test avec le Wrapper (config/db.php):</h2>";
try {
    require_once __DIR__ . '/config/db.php';
    
    if (isset($conn)) {
        echo "✅ Wrapper créé avec succès!<br>";
        
        $result = $conn->query("SELECT COUNT(*) as count FROM Utilisateur");
        if ($result) {
            if (method_exists($result, 'fetch_assoc')) {
                $row = $result->fetch_assoc();
                echo "✅ Requête réussie! Nombre d'utilisateurs: " . ($row['count'] ?? 'N/A') . "<br>";
            } else {
                echo "⚠️ Connexion OK mais problème avec fetch_assoc<br>";
            }
        } else {
            echo "❌ Erreur lors de l'exécution de la requête<br>";
        }
    } else {
        echo "❌ Échec de la création du wrapper<br>";
    }
} catch (Exception $e) {
    echo "❌ Erreur: " . htmlspecialchars($e->getMessage()) . "<br>";
}

echo "<hr>";
echo "<p><strong>Note:</strong> Supprimez ce fichier après le test pour la sécurité!</p>";
?>

