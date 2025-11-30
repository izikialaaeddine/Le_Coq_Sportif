<?php
// Script de test de connexion
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Test de Connexion</h1>";

require_once __DIR__ . '/config/db.php';

$test_users = [
    ['identifiant' => 'admin', 'password' => 'admin123'],
    ['identifiant' => 'stock', 'password' => 'stock123'],
    ['identifiant' => 'groupe', 'password' => 'groupe123'],
    ['identifiant' => 'reception', 'password' => 'reception123'],
];

foreach ($test_users as $test) {
    echo "<h2>Test: " . htmlspecialchars($test['identifiant']) . "</h2>";
    
    try {
        $stmt = $conn->prepare('SELECT u.idutilisateur as idUtilisateur, u.idrole as idRole, u.identifiant as Identifiant, u.motdepasse as MotDePasse, u.nom as Nom, u.prenom as Prenom, r.role as Role FROM Utilisateur u JOIN Role r ON u.idrole = r.idrole WHERE u.identifiant = ?');
        
        if ($stmt) {
            $stmt->bind_param('s', $test['identifiant']);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result) {
                $user = $result->fetch_assoc();
                
                if ($user) {
                    echo "✅ Utilisateur trouvé!<br>";
                    echo "Données brutes:<br>";
                    echo "<pre>";
                    print_r($user);
                    echo "</pre>";
                    
                    // Tester les deux cas
                    $motDePasse = $user['MotDePasse'] ?? $user['motdepasse'] ?? '';
                    echo "Mot de passe hash: " . (strlen($motDePasse) > 0 ? "✅ Trouvé (" . strlen($motDePasse) . " caractères)" : "❌ Non trouvé") . "<br>";
                    
                    if ($motDePasse) {
                        $verify = password_verify($test['password'], $motDePasse);
                        echo "Vérification: " . ($verify ? "✅ CORRECT" : "❌ INCORRECT") . "<br>";
                    }
                } else {
                    echo "❌ Utilisateur non trouvé<br>";
                }
            } else {
                echo "❌ Erreur get_result()<br>";
            }
        } else {
            echo "❌ Erreur prepare()<br>";
        }
    } catch (Exception $e) {
        echo "❌ Exception: " . htmlspecialchars($e->getMessage()) . "<br>";
    }
    
    echo "<hr>";
}

echo "<p><strong>Note:</strong> Supprimez ce fichier après le test!</p>";
?>

