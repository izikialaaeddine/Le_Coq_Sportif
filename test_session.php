<?php
// Test de session
session_start();
require_once __DIR__ . '/config/error_config.php';

echo "<h1>Test de Session</h1>";

echo "<h2>Session Status:</h2>";
echo "Session ID: " . session_id() . "<br>";
echo "Session Status: " . (session_status() === PHP_SESSION_ACTIVE ? "Active" : "Inactive") . "<br>";

echo "<h2>Session Data:</h2>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

if (isset($_SESSION['user'])) {
    echo "<h2>User Data:</h2>";
    echo "<pre>";
    print_r($_SESSION['user']);
    echo "</pre>";
    
    echo "<h2>Vérifications:</h2>";
    echo "idRole: " . ($_SESSION['user']['idRole'] ?? 'NON DÉFINI') . "<br>";
    echo "id: " . ($_SESSION['user']['id'] ?? 'NON DÉFINI') . "<br>";
    echo "Nom: " . ($_SESSION['user']['Nom'] ?? 'NON DÉFINI') . "<br>";
} else {
    echo "<p style='color:red;'>❌ Aucune session utilisateur trouvée!</p>";
    echo "<p>Connectez-vous d'abord sur <a href='index.php'>index.php</a></p>";
}

echo "<hr>";
echo "<p><a href='index.php'>Retour à la connexion</a></p>";
?>

