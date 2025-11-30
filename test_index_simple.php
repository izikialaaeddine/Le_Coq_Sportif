<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

echo "=== TEST INDEX SIMPLE ===<br><br>";

echo "1. PHP fonctionne<br>";
flush();

echo "2. Test session...<br>";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
echo "   Session démarrée<br>";
flush();

echo "3. Test error_config...<br>";
try {
    require_once __DIR__ . '/config/error_config.php';
    echo "   error_config.php chargé<br>";
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
} catch (Exception $e) {
    die("   ERREUR error_config: " . $e->getMessage());
} catch (Error $e) {
    die("   ERREUR FATALE error_config: " . $e->getMessage());
}
flush();

echo "4. Test db.php...<br>";
try {
    require_once __DIR__ . '/config/db.php';
    echo "   db.php chargé<br>";
    if (isset($conn)) {
        echo "   \$conn existe<br>";
    } else {
        die("   ERREUR: \$conn n'existe pas");
    }
} catch (Exception $e) {
    die("   ERREUR DB: " . $e->getMessage() . "<br>Trace: " . $e->getTraceAsString());
} catch (Error $e) {
    die("   ERREUR FATALE DB: " . $e->getMessage());
}
flush();

echo "5. Test requête utilisateurs...<br>";
try {
    $users_query = $conn->query("SELECT u.idutilisateur as idUtilisateur, u.nom as Nom, u.prenom as Prenom, u.identifiant as Identifiant, r.role as Role FROM Utilisateur u LEFT JOIN Role r ON u.idrole = r.idrole WHERE u.identifiant IS NOT NULL AND u.identifiant != '' ORDER BY u.nom, u.prenom");
    if ($users_query) {
        echo "   Requête exécutée<br>";
        $count = 0;
        if (method_exists($users_query, 'fetch_all')) {
            $all_users = $users_query->fetch_all(MYSQLI_ASSOC);
            $count = count($all_users);
        } else {
            while ($row = $users_query->fetch_assoc()) {
                $count++;
            }
        }
        echo "   Nombre d'utilisateurs trouvés: $count<br>";
    } else {
        die("   ERREUR: Requête échouée");
    }
} catch (Exception $e) {
    die("   ERREUR requête: " . $e->getMessage());
}
flush();

echo "<br><strong>✅ TOUS LES TESTS SONT PASSÉS!</strong><br>";
echo "<a href='index.php'>Tester index.php maintenant</a>";
?>

