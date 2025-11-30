<?php
// Configuration pour Supabase (PostgreSQL)
// Utilisez cette configuration pour Vercel + Supabase

// Récupérer les variables d'environnement de Vercel
$host = getenv('DB_HOST') ?: 'db.xxxxx.supabase.co';
$db   = getenv('DB_NAME') ?: 'postgres';
$user = getenv('DB_USER') ?: 'postgres';
$pass = getenv('DB_PASS') ?: '';
$port = getenv('DB_PORT') ?: '5432';

// Pour Supabase, on utilise PDO avec PostgreSQL
try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$db;options='--client_encoding=UTF8'";
    $conn = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    die('Erreur de connexion: ' . $e->getMessage());
}

// Fonction helper pour compatibilité avec mysqli
function query($sql) {
    global $conn;
    return $conn->query($sql);
}

function prepare($sql) {
    global $conn;
    return $conn->prepare($sql);
}

?>

