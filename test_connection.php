<?php
// Script de test de connexion - À supprimer après test
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Test de Connexion à Supabase</h1>";

// Afficher les variables d'environnement (sans le mot de passe complet)
echo "<h2>Variables d'Environnement:</h2>";
echo "DB_HOST: " . (getenv('DB_HOST') ?: 'NON DÉFINI') . "<br>";
echo "DB_NAME: " . (getenv('DB_NAME') ?: 'NON DÉFINI') . "<br>";
echo "DB_USER: " . (getenv('DB_USER') ?: 'NON DÉFINI') . "<br>";
echo "DB_PASS: " . (getenv('DB_PASS') ? str_repeat('*', strlen(getenv('DB_PASS'))) : 'NON DÉFINI') . "<br>";
echo "DB_PORT: " . (getenv('DB_PORT') ?: 'NON DÉFINI') . "<br>";
echo "DB_TYPE: " . (getenv('DB_TYPE') ?: 'NON DÉFINI') . "<br>";

echo "<hr>";

// Tester la connexion
require_once __DIR__ . '/config/db.php';

echo "<h2>Test de Connexion:</h2>";

if (isset($conn)) {
    echo "✅ Connexion créée avec succès!<br>";
    
    // Tester une requête simple
    try {
        $result = $conn->query("SELECT COUNT(*) as count FROM Utilisateur");
        if ($result) {
            if (method_exists($result, 'fetch_assoc')) {
                $row = $result->fetch_assoc();
                echo "✅ Requête réussie! Nombre d'utilisateurs: " . ($row['count'] ?? 'N/A') . "<br>";
            } else {
                echo "⚠️ Connexion OK mais problème avec fetch_assoc<br>";
            }
        } else {
            echo "❌ Erreur lors de l'exécution de la requête<br>";
        }
    } catch (Exception $e) {
        echo "❌ Erreur: " . $e->getMessage() . "<br>";
    }
} else {
    echo "❌ Échec de la connexion<br>";
}

echo "<hr>";
echo "<p><strong>Note:</strong> Supprimez ce fichier après le test pour la sécurité!</p>";
?>

