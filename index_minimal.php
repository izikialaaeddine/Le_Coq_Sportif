<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

echo "INDEX MINIMAL TEST<br>";
echo "PHP Version: " . phpversion() . "<br>";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
echo "Session: " . (session_status() === PHP_SESSION_ACTIVE ? "Active" : "Inactive") . "<br>";

echo "Test error_config...<br>";
require_once __DIR__ . '/config/error_config.php';
ini_set('display_errors', 1);

echo "Test db.php...<br>";
try {
    require_once __DIR__ . '/config/db.php';
    echo "DB connectée<br>";
} catch (Exception $e) {
    echo "ERREUR DB: " . $e->getMessage() . "<br>";
    $conn = null;
}

if (isset($conn)) {
    echo "Test requête...<br>";
    try {
        $test = $conn->query("SELECT 1");
        echo "Requête OK<br>";
    } catch (Exception $e) {
        echo "ERREUR requête: " . $e->getMessage() . "<br>";
    }
}

echo "<br><strong>✅ INDEX MINIMAL FONCTIONNE</strong><br>";
echo "<a href='index.php'>Tester index.php complet</a>";
?>

