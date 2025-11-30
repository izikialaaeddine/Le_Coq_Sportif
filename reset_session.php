<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
if (!isset($_SESSION['user']) || !is_array($_SESSION['user']) || !isset($_SESSION['user']['Nom']) || !isset($_SESSION['user']['Role'])) {
    session_unset();
    session_destroy();
    session_start();
    $_SESSION['user'] = ['id' => 2, 'Nom' => 'Chef Groupe', 'Role' => 'Chef de Groupe'];
}
$user = $_SESSION['user'];
if (!is_array($user) || !isset($user['Nom']) || !isset($user['Role'])) {
    $user = ['id' => 2, 'Nom' => 'Chef Groupe', 'Role' => 'Chef de Groupe'];
    $_SESSION['user'] = $user;
}
require_once 'config/db.php';
$echantillons = $conn->query("SELECT * FROM Echantillon")->fetch_all(MYSQLI_ASSOC);
?>
