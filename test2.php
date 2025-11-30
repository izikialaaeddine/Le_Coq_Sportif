<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
echo "TEST 2: Affichage erreurs activé<br>";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
echo "TEST 2: Session démarrée";
?>

