<?php
// Script de debug pour vérifier les utilisateurs
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Debug Utilisateurs</h1>";

require_once __DIR__ . '/config/db.php';

echo "<h2>Test de Connexion:</h2>";
if (isset($conn)) {
    echo "✅ Connexion OK<br><br>";
} else {
    echo "❌ Pas de connexion<br>";
    die();
}

echo "<h2>Liste des Utilisateurs:</h2>";

try {
    $query = "SELECT u.idUtilisateur, u.Nom, u.Prenom, u.Identifiant, r.Role 
              FROM Utilisateur u 
              LEFT JOIN Role r ON u.idRole = r.idRole 
              WHERE u.Identifiant IS NOT NULL AND u.Identifiant != '' 
              ORDER BY u.Nom, u.Prenom";
    
    echo "Requête SQL: <code>" . htmlspecialchars($query) . "</code><br><br>";
    
    $result = $conn->query($query);
    
    if ($result) {
        $users = [];
        if (method_exists($result, 'fetch_all')) {
            $users = $result->fetch_all(MYSQLI_ASSOC);
        } else {
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
        }
        
        echo "<strong>Nombre d'utilisateurs trouvés: " . count($users) . "</strong><br><br>";
        
        if (count($users) > 0) {
            echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
            echo "<tr><th>ID</th><th>Nom</th><th>Prénom</th><th>Identifiant</th><th>Rôle</th></tr>";
            foreach ($users as $user) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($user['idUtilisateur'] ?? 'N/A') . "</td>";
                echo "<td>" . htmlspecialchars($user['Nom'] ?? 'N/A') . "</td>";
                echo "<td>" . htmlspecialchars($user['Prenom'] ?? 'N/A') . "</td>";
                echo "<td>" . htmlspecialchars($user['Identifiant'] ?? 'N/A') . "</td>";
                echo "<td>" . htmlspecialchars($user['Role'] ?? 'N/A') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "❌ Aucun utilisateur trouvé!<br>";
            echo "Vérifiez que vous avez bien exécuté le script SQL dans Supabase.<br>";
        }
    } else {
        echo "❌ Erreur lors de l'exécution de la requête<br>";
        if (method_exists($conn, 'error')) {
            echo "Erreur: " . htmlspecialchars($conn->error) . "<br>";
        }
    }
} catch (Exception $e) {
    echo "❌ Exception: " . htmlspecialchars($e->getMessage()) . "<br>";
}

echo "<hr>";
echo "<h2>Mots de passe mappés:</h2>";
$passwords_map = [
    'admin' => 'admin123',
    'stock' => 'stock123',
    'groupe' => 'groupe123',
    'reception' => 'reception123'
];
echo "<pre>";
print_r($passwords_map);
echo "</pre>";

echo "<hr>";
echo "<p><strong>Note:</strong> Supprimez ce fichier après le debug pour la sécurité!</p>";
?>

