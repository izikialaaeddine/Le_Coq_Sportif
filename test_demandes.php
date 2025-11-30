<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/db.php';

echo "<h1>Test Demandes</h1>";

// Test 1: Vérifier la structure de la table Demande
echo "<h2>Test 1: Structure de la table Demande</h2>";
$test1 = $conn->query("DESCRIBE Demande");
if ($test1) {
    echo "<table border='1'><tr><th>Colonne</th><th>Type</th></tr>";
    while ($row = $test1->fetch_assoc()) {
        echo "<tr><td>" . htmlspecialchars($row['Field']) . "</td><td>" . htmlspecialchars($row['Type']) . "</td></tr>";
    }
    echo "</table>";
} else {
    echo "ERREUR: " . $conn->error . "<br>";
}

// Test 2: Compter les demandes
echo "<h2>Test 2: Nombre de demandes</h2>";
$test2 = $conn->query("SELECT COUNT(*) as count FROM Demande");
if ($test2) {
    $row = $test2->fetch_assoc();
    echo "Nombre total de demandes: " . ($row['count'] ?? 'N/A') . "<br>";
} else {
    echo "ERREUR: " . $conn->error . "<br>";
}

// Test 3: Récupérer les demandes avec les colonnes exactes
echo "<h2>Test 3: Récupérer les demandes</h2>";
$test3 = $conn->query("SELECT * FROM Demande ORDER BY DateDemande DESC LIMIT 5");
if ($test3) {
    echo "Requête réussie<br>";
    $count = 0;
    while ($row = $test3->fetch_assoc()) {
        $count++;
        echo "<h3>Demande $count:</h3>";
        echo "<pre>";
        print_r($row);
        echo "</pre>";
        echo "<p>Clés disponibles: " . implode(', ', array_keys($row)) . "</p>";
    }
    if ($count === 0) {
        echo "⚠️ Aucune demande trouvée!<br>";
    }
} else {
    echo "ERREUR: " . $conn->error . "<br>";
}

// Test 4: Récupérer avec JOIN Utilisateur
echo "<h2>Test 4: Demandes avec Utilisateur</h2>";
$test4 = $conn->query("SELECT d.*, u.nom AS NomDemandeur, u.prenom AS PrenomDemandeur FROM Demande d JOIN Utilisateur u ON d.idUtilisateur = u.idUtilisateur ORDER BY d.DateDemande DESC LIMIT 5");
if ($test4) {
    echo "Requête réussie<br>";
    $count = 0;
    while ($row = $test4->fetch_assoc()) {
        $count++;
        echo "<h3>Demande $count:</h3>";
        echo "<p>ID: " . ($row['idDemande'] ?? 'N/A') . "</p>";
        echo "<p>Demandeur: " . ($row['PrenomDemandeur'] ?? 'N/A') . " " . ($row['NomDemandeur'] ?? 'N/A') . "</p>";
        echo "<p>Statut: " . ($row['Statut'] ?? 'N/A') . "</p>";
        echo "<p>Type: " . ($row['TypeDemande'] ?? 'N/A') . "</p>";
        echo "<p>Date: " . ($row['DateDemande'] ?? 'N/A') . "</p>";
    }
    if ($count === 0) {
        echo "⚠️ Aucune demande trouvée!<br>";
    }
} else {
    echo "ERREUR: " . $conn->error . "<br>";
}

// Test 5: Statistiques
echo "<h2>Test 5: Statistiques</h2>";
$test5 = $conn->query("SELECT Statut, TypeDemande, COUNT(*) as count FROM Demande GROUP BY Statut, TypeDemande");
if ($test5) {
    echo "<table border='1'><tr><th>Statut</th><th>Type</th><th>Count</th></tr>";
    while ($row = $test5->fetch_assoc()) {
        echo "<tr><td>" . htmlspecialchars($row['Statut'] ?? 'N/A') . "</td><td>" . htmlspecialchars($row['TypeDemande'] ?? 'N/A') . "</td><td>" . htmlspecialchars($row['count'] ?? '0') . "</td></tr>";
    }
    echo "</table>";
} else {
    echo "ERREUR: " . $conn->error . "<br>";
}

echo "<br><a href='dashboard_stock.php'>Retour au dashboard</a>";
?>

