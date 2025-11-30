<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
echo "TEST 3: Début<br>";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
echo "TEST 3: Session OK<br>";
try {
    require_once __DIR__ . '/config/error_config.php';
    echo "TEST 3: error_config.php chargé<br>";
} catch (Exception $e) {
    die("ERREUR test3: " . $e->getMessage());
}
echo "TEST 3: SUCCÈS";
?>

