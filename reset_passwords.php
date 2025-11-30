<?php
require_once 'config/db.php';

// Mot de passe par défaut pour tous les utilisateurs
$defaultPassword = "123456"; // Tu peux changer ce mot de passe par défaut

echo "<h2>Réinitialisation des mots de passe de tous les utilisateurs</h2>";

try {
    // Récupérer tous les utilisateurs
    $users = $conn->query("SELECT idUtilisateur, Identifiant, Nom, Prenom FROM Utilisateur")->fetch_all(MYSQLI_ASSOC);
    
    if (empty($users)) {
        echo "<p style='color: red;'>Aucun utilisateur trouvé dans la base de données.</p>";
        exit;
    }
    
    echo "<p>Nombre d'utilisateurs trouvés : " . count($users) . "</p>";
    echo "<p>Mot de passe par défaut : <strong>" . htmlspecialchars($defaultPassword) . "</strong></p>";
    echo "<hr>";
    
    $successCount = 0;
    $errorCount = 0;
    
    // Hacher le mot de passe par défaut
    $hashedPassword = password_hash($defaultPassword, PASSWORD_DEFAULT);
    
    // Préparer la requête de mise à jour
    $stmt = $conn->prepare("UPDATE Utilisateur SET MotDePasse = ? WHERE idUtilisateur = ?");
    
    foreach ($users as $user) {
        $stmt->bind_param("si", $hashedPassword, $user['idUtilisateur']);
        
        if ($stmt->execute()) {
            echo "<p style='color: green;'>✓ " . htmlspecialchars($user['Nom']) . " " . htmlspecialchars($user['Prenom']) . " (ID: " . $user['idUtilisateur'] . ", Identifiant: " . htmlspecialchars($user['Identifiant']) . ") - Mot de passe réinitialisé</p>";
            $successCount++;
        } else {
            echo "<p style='color: red;'>✗ Erreur pour " . htmlspecialchars($user['Nom']) . " " . htmlspecialchars($user['Prenom']) . " (ID: " . $user['idUtilisateur'] . ")</p>";
            $errorCount++;
        }
    }
    
    $stmt->close();
    
    echo "<hr>";
    echo "<h3>Résumé :</h3>";
    echo "<p style='color: green;'>Mots de passe réinitialisés avec succès : " . $successCount . "</p>";
    echo "<p style='color: red;'>Erreurs : " . $errorCount . "</p>";
    
    if ($successCount > 0) {
        echo "<div style='background-color: #d4edda; border: 1px solid #c3e6cb; padding: 15px; margin: 10px 0; border-radius: 5px;'>";
        echo "<h4>Instructions importantes :</h4>";
        echo "<ul>";
        echo "<li>Tous les utilisateurs peuvent maintenant se connecter avec le mot de passe : <strong>" . htmlspecialchars($defaultPassword) . "</strong></li>";
        echo "<li>Il est fortement recommandé de demander à chaque utilisateur de changer son mot de passe lors de sa prochaine connexion</li>";
        echo "<li>Pour des raisons de sécurité, supprime ce fichier après utilisation</li>";
        echo "</ul>";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Erreur : " . htmlspecialchars($e->getMessage()) . "</p>";
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Réinitialisation des mots de passe</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            padding: 15px;
            margin: 10px 0;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="warning">
            <h3>⚠️ Attention Sécurité</h3>
            <p>Ce script réinitialise TOUS les mots de passe des utilisateurs. Utilisez-le uniquement en cas d'urgence.</p>
        </div>
    </div>
</body>
</html>
