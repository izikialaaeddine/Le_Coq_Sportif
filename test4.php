<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
echo "TEST 4: Début<br>";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
echo "TEST 4: Session OK<br>";
try {
    require_once __DIR__ . '/config/error_config.php';
    echo "TEST 4: error_config.php OK<br>";
} catch (Exception $e) {
    die("ERREUR error_config: " . $e->getMessage());
}
ini_set('display_errors', 1);
echo "TEST 4: Tentative connexion DB...<br>";
try {
    require_once __DIR__ . '/config/db.php';
    echo "TEST 4: db.php chargé<br>";
    if (isset($conn)) {
        echo "TEST 4: \$conn existe<br>";
    } else {
        die("TEST 4: ERREUR - \$conn n'existe pas");
    }
} catch (Exception $e) {
    die("TEST 4: ERREUR DB - " . $e->getMessage());
} catch (Error $e) {
    die("TEST 4: ERREUR FATALE - " . $e->getMessage());
}
echo "TEST 4: SUCCÈS";
?>

