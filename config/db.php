<?php
// Configuration de la base de données
// Utilise les variables d'environnement pour Railway/Supabase

$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: 'le_coq_sportif';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$port = getenv('DB_PORT') ?: '3306';

// Détecter si on utilise PostgreSQL (Supabase) ou MySQL
$isPostgres = getenv('DB_TYPE') === 'postgres' || 
              strpos($host, 'supabase') !== false || 
              strpos($host, 'railway') !== false ||
              $port === '5432';

// Définir les constantes MySQLi pour compatibilité
if (!defined('MYSQLI_ASSOC')) {
    define('MYSQLI_ASSOC', 1);
    define('MYSQLI_NUM', 2);
    define('MYSQLI_BOTH', 3);
}

if ($isPostgres) {
    // Connexion PostgreSQL pour Supabase
    try {
        // Forcer IPv4 en résolvant d'abord le hostname en IPv4
        // Utiliser l'option 'hostaddr' si disponible, sinon forcer IPv4 via DNS
        $host_ip = gethostbyname($host);
        if ($host_ip === $host) {
            // Si la résolution échoue, utiliser le hostname directement mais forcer IPv4
            $dsn = "pgsql:host=$host;port=$port;dbname=$db";
        } else {
            // Utiliser l'IP résolue
            $dsn = "pgsql:host=$host_ip;port=$port;dbname=$db";
        }
        
        // Ajouter les options
        $dsn .= ";options='--client_encoding=UTF8'";
        
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10,
        ]);
        
        // Créer une classe wrapper pour compatibilité avec mysqli
        class DBWrapper {
            private $pdo;
            public $connect_error;
            
            public function __construct($pdo) {
                $this->pdo = $pdo;
            }
            
            public function query($sql) {
                try {
                    $result = $this->pdo->query($sql);
                    return new PDOStatementWrapper($result);
                } catch (PDOException $e) {
                    error_log("DB Query Error: " . $e->getMessage());
                    return false;
                }
            }
            
            public function prepare($sql) {
                try {
                    $stmt = $this->pdo->prepare($sql);
                    return new PDOStatementWrapper($stmt);
                } catch (PDOException $e) {
                    error_log("DB Prepare Error: " . $e->getMessage());
                    return false;
                }
            }
            
            public function real_escape_string($str) {
                return substr($this->pdo->quote($str), 1, -1);
            }
        }
        
        // Wrapper pour PDOStatement pour compatibilité mysqli
        class PDOStatementWrapper {
            private $stmt;
            private $params = [];
            private $types = [];
            private $executed = false;
            
            public function __construct($stmt) {
                $this->stmt = $stmt;
            }
            
            public function bind_param($types, ...$params) {
                $this->types = str_split($types);
                $this->params = $params;
                return true;
            }
            
            public function execute() {
                if (!empty($this->params)) {
                    foreach ($this->params as $index => $param) {
                        $type = $this->types[$index] ?? 's';
                        $pdoType = PDO::PARAM_STR;
                        if ($type === 'i') $pdoType = PDO::PARAM_INT;
                        elseif ($type === 'd') $pdoType = PDO::PARAM_STR; // float as string
                        $this->stmt->bindValue($index + 1, $param, $pdoType);
                    }
                }
                $this->executed = $this->stmt->execute();
                return $this->executed;
            }
            
            public function get_result() {
                return $this;
            }
            
            public function fetch_assoc() {
                return $this->stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            public function fetch_all($mode = null) {
                // Ignorer le mode, toujours retourner FETCH_ASSOC
                return $this->stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            public function fetch_array() {
                return $this->stmt->fetch(PDO::FETCH_BOTH);
            }
            
            public function num_rows() {
                return $this->stmt->rowCount();
            }
            
            public function close() {
                $this->stmt = null;
                return true;
            }
            
            public function error() {
                $error = $this->stmt->errorInfo();
                return $error[2] ?? '';
            }
        }
        
        $conn = new DBWrapper($pdo);
    } catch (PDOException $e) {
        error_log("PostgreSQL Connection Error: " . $e->getMessage());
        error_log("Host: $host, Port: $port, DB: $db, User: $user");
        // Ne pas afficher l'erreur directement, la logger seulement
        http_response_code(500);
        die('Erreur de connexion à la base de données. Vérifiez les logs.');
    }
} else {
    // Connexion MySQL (local)
    $conn = new mysqli($host, $user, $pass, $db);
    if ($conn->connect_error) {
        die('Erreur de connexion MySQL: ' . $conn->connect_error);
    }
}
?>
