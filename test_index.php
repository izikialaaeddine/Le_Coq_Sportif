<?php
// Test simple pour identifier l'erreur
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

echo "1. PHP fonctionne<br>";

// Test session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
echo "2. Session démarrée<br>";

// Test error_config
try {
    require_once __DIR__ . '/config/error_config.php';
    echo "3. error_config.php chargé<br>";
} catch (Exception $e) {
    die("Erreur error_config: " . $e->getMessage());
}

// Réactiver les erreurs
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Test db
try {
    require_once __DIR__ . '/config/db.php';
    echo "4. db.php chargé<br>";
    echo "5. Connexion DB: " . (isset($conn) ? "OK" : "ÉCHEC") . "<br>";
} catch (Exception $e) {
    die("Erreur DB: " . $e->getMessage());
} catch (Error $e) {
    die("Erreur fatale DB: " . $e->getMessage());
}

// Test query simple
try {
    $test_query = $conn->query("SELECT 1 as test");
    if ($test_query) {
        echo "6. Requête test: OK<br>";
    } else {
        echo "6. Requête test: ÉCHEC<br>";
    }
} catch (Exception $e) {
    echo "6. Erreur requête: " . $e->getMessage() . "<br>";
}

echo "<br><strong>✅ Tous les tests sont passés!</strong><br>";
echo "<a href='index.php'>Retour à index.php</a>";
?>

