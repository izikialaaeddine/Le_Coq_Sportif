<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Debug Session pour Dashboard Stock</h1>";

// Test 1: Session
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
echo "<h2>Test 1: Session</h2>";
echo "Session démarrée: " . (session_status() === PHP_SESSION_ACTIVE ? "OUI" : "NON") . "<br>";
echo "Session ID: " . session_id() . "<br>";

// Test 2: Vérifier $_SESSION
echo "<h2>Test 2: Contenu de \$_SESSION</h2>";
if (isset($_SESSION)) {
    echo "<pre>";
    print_r($_SESSION);
    echo "</pre>";
} else {
    echo "⚠️ \$_SESSION n'est pas défini<br>";
}

// Test 3: Vérifier $_SESSION['user']
echo "<h2>Test 3: \$_SESSION['user']</h2>";
if (isset($_SESSION['user'])) {
    echo "<pre>";
    print_r($_SESSION['user']);
    echo "</pre>";
    echo "idRole: " . ($_SESSION['user']['idRole'] ?? 'NON DÉFINI') . "<br>";
    echo "idRole == 1 (Chef de Stock): " . (($_SESSION['user']['idRole'] ?? 0) == 1 ? "OUI ✅" : "NON ❌") . "<br>";
} else {
    echo "⚠️ \$_SESSION['user'] n'est pas défini<br>";
    echo "<p style='color:red'>Vous devez vous connecter avec un compte Chef de Stock (idRole = 1)</p>";
}

// Test 4: Vérification comme dans dashboard_stock.php
echo "<h2>Test 4: Vérification (comme dashboard_stock.php ligne 336)</h2>";
if (!isset($_SESSION['user']) || $_SESSION['user']['idRole'] != 1) {
    echo "<p style='color:red'>❌ REDIRECTION VERS index.php</p>";
    echo "Raison: ";
    if (!isset($_SESSION['user'])) {
        echo "Session user non définie";
    } else {
        echo "idRole = " . ($_SESSION['user']['idRole'] ?? 'NON DÉFINI') . " (attendu: 1)";
    }
} else {
    echo "<p style='color:green'>✅ ACCÈS AUTORISÉ</p>";
}

echo "<br><a href='index.php'>Aller à la page de connexion</a> | <a href='dashboard_stock.php'>Essayer dashboard_stock.php</a>";
?>

