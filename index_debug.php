<?php
// Debug version of index.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

echo "1. Starting...<br>";

try {
    echo "2. Loading error_config...<br>";
    require_once __DIR__ . '/config/error_config.php';
    echo "3. error_config loaded<br>";
    
    echo "4. Starting session...<br>";
    session_start();
    echo "5. Session started: " . session_id() . "<br>";
    
    echo "6. Loading db.php...<br>";
    require_once __DIR__ . '/config/db.php';
    echo "7. db.php loaded<br>";
    
    echo "8. Testing connection...<br>";
    if (isset($conn)) {
        echo "9. Connection object exists<br>";
        if (method_exists($conn, 'query')) {
            echo "10. Connection has query method<br>";
            $test = $conn->query("SELECT 1");
            if ($test) {
                echo "11. ✅ Database connection works!<br>";
            } else {
                echo "11. ❌ Database query failed<br>";
            }
        } else {
            echo "10. ❌ Connection doesn't have query method<br>";
        }
    } else {
        echo "9. ❌ Connection object doesn't exist<br>";
    }
    
    echo "<hr>";
    echo "<h2>Session Data:</h2>";
    echo "<pre>";
    print_r($_SESSION);
    echo "</pre>";
    
    echo "<hr>";
    echo "<h2>POST Data:</h2>";
    echo "<pre>";
    print_r($_POST);
    echo "</pre>";
    
    echo "<hr>";
    echo "<h2>Environment:</h2>";
    echo "DB_HOST: " . getenv('DB_HOST') . "<br>";
    echo "DB_NAME: " . getenv('DB_NAME') . "<br>";
    echo "DB_USER: " . getenv('DB_USER') . "<br>";
    echo "DB_PORT: " . getenv('DB_PORT') . "<br>";
    echo "DB_TYPE: " . getenv('DB_TYPE') . "<br>";
    
} catch (Exception $e) {
    echo "<h1 style='color:red;'>ERROR:</h1>";
    echo "<pre>" . $e->getMessage() . "</pre>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
} catch (Error $e) {
    echo "<h1 style='color:red;'>FATAL ERROR:</h1>";
    echo "<pre>" . $e->getMessage() . "</pre>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>

