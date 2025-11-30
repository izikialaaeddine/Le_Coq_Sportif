<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
echo "TEST 5: Début<br>";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
echo "TEST 5: Session OK<br>";
try {
    require_once __DIR__ . '/config/error_config.php';
    ini_set('display_errors', 1);
    require_once __DIR__ . '/config/db.php';
    echo "TEST 5: DB connectée<br>";
    
    // Test requête simple
    $test = $conn->query("SELECT 1 as test");
    if ($test) {
        echo "TEST 5: Requête test OK<br>";
    } else {
        die("TEST 5: ERREUR - Requête échouée");
    }
    
    // Test requête Utilisateur
    $users = $conn->query("SELECT COUNT(*) as count FROM Utilisateur");
    if ($users) {
        $row = $users->fetch_assoc();
        echo "TEST 5: Nombre d'utilisateurs: " . ($row['count'] ?? 'N/A') . "<br>";
    }
    
} catch (Exception $e) {
    die("TEST 5: ERREUR - " . $e->getMessage() . " - " . $e->getTraceAsString());
} catch (Error $e) {
    die("TEST 5: ERREUR FATALE - " . $e->getMessage());
}
echo "TEST 5: SUCCÈS COMPLET";
?>

