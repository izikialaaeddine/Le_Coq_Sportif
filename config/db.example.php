<?php
// FICHIER D'EXEMPLE - Copiez ce fichier en db.php et modifiez les valeurs

// Configuration pour DÉPLOIEMENT EN LIGNE
// Remplacez ces valeurs par celles fournies par votre hébergeur

$host = 'localhost'; // Généralement 'localhost' ou une adresse IP fournie par l'hébergeur
$db   = 'nom_de_votre_base_de_donnees'; // Le nom de votre base de données MySQL
$user = 'votre_utilisateur_mysql'; // Votre nom d'utilisateur MySQL
$pass = 'votre_mot_de_passe_mysql'; // Votre mot de passe MySQL
$charset = 'utf8mb4';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die('Erreur de connexion: ' . $conn->connect_error);
}
?>

