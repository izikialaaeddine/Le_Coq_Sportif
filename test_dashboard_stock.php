<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Test Dashboard Stock</h1>";

// Test 1: Session
echo "<h2>Test 1: Session</h2>";
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
echo "Session démarrée: " . (session_status() === PHP_SESSION_ACTIVE ? "OUI" : "NON") . "<br>";

// Test 2: error_config.php
echo "<h2>Test 2: error_config.php</h2>";
try {
    require_once __DIR__ . '/config/error_config.php';
    echo "error_config.php chargé avec succès<br>";
} catch (Exception $e) {
    echo "ERREUR: " . $e->getMessage() . "<br>";
}

// Test 3: db.php
echo "<h2>Test 3: db.php</h2>";
try {
    require_once __DIR__ . '/config/db.php';
    echo "db.php chargé avec succès<br>";
    echo "Connexion DB: " . (isset($conn) ? "OUI" : "NON") . "<br>";
    if (isset($conn)) {
        echo "Test requête: ";
        $test = $conn->query("SELECT 1");
        echo ($test ? "OK" : "ERREUR: " . $conn->error) . "<br>";
    }
} catch (Exception $e) {
    echo "ERREUR: " . $e->getMessage() . "<br>";
} catch (Error $e) {
    echo "ERREUR FATALE: " . $e->getMessage() . "<br>";
}

echo "<br><a href='dashboard_stock.php'>Essayer dashboard_stock.php</a>";
?>

