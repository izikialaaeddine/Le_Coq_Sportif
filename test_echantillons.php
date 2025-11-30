<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
require_once __DIR__ . '/config/error_config.php';
ini_set('display_errors', 1);
require_once __DIR__ . '/config/db.php';

echo "<h1>Test Échantillons</h1>";

// Test 1: Requête simple
echo "<h2>Test 1: Requête simple</h2>";
$test1 = $conn->query("SELECT COUNT(*) as count FROM Echantillon");
if ($test1) {
    $row = $test1->fetch_assoc();
    echo "Nombre total d'échantillons: " . ($row['count'] ?? 'N/A') . "<br>";
} else {
    echo "ERREUR: Requête COUNT échouée<br>";
}

// Test 2: Requête avec alias
echo "<h2>Test 2: Requête avec alias</h2>";
$test2 = $conn->query("SELECT e.refechantillon AS RefEchantillon, e.famille AS Famille, e.couleur AS Couleur, e.taille AS Taille, e.qte AS Qte, e.statut AS Statut FROM Echantillon e LIMIT 5");
if ($test2) {
    echo "Requête réussie<br>";
    $count = 0;
    if (method_exists($test2, 'fetch_all')) {
        $rows = $test2->fetch_all(MYSQLI_ASSOC);
        $count = count($rows);
        echo "Nombre de lignes (fetch_all): $count<br>";
        echo "<pre>";
        print_r($rows);
        echo "</pre>";
    } else {
        while ($row = $test2->fetch_assoc()) {
            $count++;
            echo "Ligne $count:<br>";
            echo "<pre>";
            print_r($row);
            echo "</pre>";
        }
    }
} else {
    echo "ERREUR: Requête avec alias échouée<br>";
    if (method_exists($conn, 'error')) {
        echo "Erreur: " . $conn->error . "<br>";
    }
}

// Test 3: Requête complète comme dans dashboard_stock
echo "<h2>Test 3: Requête complète (comme dashboard_stock)</h2>";
$test3 = $conn->query("SELECT e.*, e.refechantillon AS RefEchantillon, e.famille AS Famille, e.couleur AS Couleur, e.taille AS Taille, e.qte AS Qte, e.statut AS Statut, e.description AS Description, e.datecreation AS DateCreation, e.idutilisateur AS idUtilisateur FROM Echantillon e ORDER BY e.datecreation DESC LIMIT 10");
if ($test3) {
    echo "Requête réussie<br>";
    $echantillons = [];
    if (method_exists($test3, 'fetch_all')) {
        $echantillons = $test3->fetch_all(MYSQLI_ASSOC);
    } else {
        while ($row = $test3->fetch_assoc()) {
            $echantillons[] = $row;
        }
    }
    echo "Nombre d'échantillons récupérés: " . count($echantillons) . "<br>";
    if (count($echantillons) > 0) {
        echo "<h3>Premier échantillon:</h3>";
        echo "<pre>";
        print_r($echantillons[0]);
        echo "</pre>";
        echo "<h3>Clés disponibles:</h3>";
        echo "<pre>";
        print_r(array_keys($echantillons[0]));
        echo "</pre>";
    } else {
        echo "⚠️ Aucun échantillon trouvé!<br>";
    }
} else {
    echo "ERREUR: Requête complète échouée<br>";
    if (method_exists($conn, 'error')) {
        echo "Erreur: " . $conn->error . "<br>";
    }
}

echo "<br><a href='dashboard_stock.php'>Retour au dashboard</a>";
?>

