<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/db.php';

echo "<h1>Test Demandes avec Échantillons</h1>";

// Test 1: Vérifier la structure de DemandeEchantillon
echo "<h2>Test 1: Structure de DemandeEchantillon</h2>";
$test1 = $conn->query("DESCRIBE DemandeEchantillon");
if ($test1) {
    echo "<table border='1'><tr><th>Colonne</th><th>Type</th></tr>";
    while ($row = $test1->fetch_assoc()) {
        echo "<tr><td>" . htmlspecialchars($row['Field']) . "</td><td>" . htmlspecialchars($row['Type']) . "</td></tr>";
    }
    echo "</table>";
} else {
    echo "ERREUR: " . $conn->error . "<br>";
}

// Test 2: Récupérer les demandes avec échantillons (comme dans dashboard_stock)
echo "<h2>Test 2: Demandes avec échantillons (requête dashboard_stock)</h2>";
$res = $conn->query("SELECT d.*, u.nom AS NomDemandeur, u.prenom AS PrenomDemandeur
                     FROM Demande d
                     JOIN Utilisateur u ON d.idUtilisateur = u.idUtilisateur
                     ORDER BY d.DateDemande DESC LIMIT 100");
if ($res) {
    $count = 0;
    while ($row = $res->fetch_assoc()) {
        $count++;
        echo "<h3>Demande $count (ID: " . $row['idDemande'] . "):</h3>";
        echo "<p>Demandeur: " . ($row['PrenomDemandeur'] ?? 'N/A') . " " . ($row['NomDemandeur'] ?? 'N/A') . "</p>";
        echo "<p>Statut: " . ($row['Statut'] ?? 'N/A') . "</p>";
        echo "<p>Type: " . ($row['TypeDemande'] ?? 'N/A') . "</p>";
        
        // Récupérer les échantillons
        $idDemande = $row['idDemande'];
        $res2 = $conn->query("SELECT * FROM DemandeEchantillon WHERE idDemande = " . intval($idDemande));
        if($res2) {
            echo "<p>Échantillons:</p><ul>";
            $echCount = 0;
            while ($e = $res2->fetch_assoc()) {
                $echCount++;
                echo "<li>Échantillon $echCount: ";
                echo "<pre>";
                print_r($e);
                echo "</pre>";
                echo "</li>";
            }
            echo "</ul>";
            if ($echCount === 0) {
                echo "<p style='color:red'>⚠️ Aucun échantillon trouvé pour cette demande!</p>";
            }
        } else {
            echo "<p style='color:red'>ERREUR lors de la récupération des échantillons: " . $conn->error . "</p>";
        }
    }
    if ($count === 0) {
        echo "<p style='color:red'>⚠️ Aucune demande trouvée!</p>";
    }
} else {
    echo "ERREUR: " . $conn->error . "<br>";
}

echo "<br><a href='dashboard_stock.php'>Retour au dashboard</a>";
?>

