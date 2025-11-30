<?php
// Configuration de la base de données MySQL (localhost uniquement)

$host = 'localhost';
$db   = 'le_coq_sportif';
$user = 'root';
$pass = '';
$port = '3306';

// Connexion MySQL
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die('Erreur de connexion : ' . $conn->connect_error);
}

// Définir l'encodage UTF-8
$conn->set_charset("utf8");
