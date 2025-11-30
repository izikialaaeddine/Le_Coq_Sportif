<?php
// Activer l'affichage des erreurs temporairement pour déboguer
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Démarrer la session AVANT tout
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Configuration d'erreurs et performance
require_once __DIR__ . '/config/error_config.php';
// Réactiver display_errors après error_config pour voir les erreurs
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

try {
    require_once __DIR__ . '/config/db.php';
} catch (Exception $e) {
    die("Erreur de connexion DB: " . $e->getMessage());
} catch (Error $e) {
    die("Erreur fatale DB: " . $e->getMessage());
}

// Fetch all users with their credentials for display
$all_users = [];
try {
    // PostgreSQL convertit les noms de colonnes en minuscules sauf si entre guillemets
    // Utiliser des alias en minuscules pour compatibilité
    $users_query = $conn->query("SELECT u.idutilisateur as idUtilisateur, u.nom as Nom, u.prenom as Prenom, u.identifiant as Identifiant, r.role as Role FROM Utilisateur u LEFT JOIN Role r ON u.idrole = r.idrole WHERE u.identifiant IS NOT NULL AND u.identifiant != '' ORDER BY u.nom, u.prenom");
    if ($users_query) {
        // Compatibilité avec PDO et MySQLi
        if (method_exists($users_query, 'fetch_all')) {
            $all_users = $users_query->fetch_all(MYSQLI_ASSOC);
        } else {
            // Pour PDO wrapper
            while ($row = $users_query->fetch_assoc()) {
                $all_users[] = $row;
            }
        }
    }
} catch (Exception $e) {
    error_log("Error fetching users: " . $e->getMessage());
    $all_users = [];
}

// Mots de passe pour affichage (mapping des identifiants aux mots de passe)
$passwords_map = [
    'admin' => 'admin123',
    'stock' => 'stock123',
    'groupe' => 'groupe123',
    'reception' => 'reception123'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifiant = trim($_POST['identifiant'] ?? '');
    $mdp = trim($_POST['mdp'] ?? '');
    if ($identifiant && $mdp) {
        try {
            // PostgreSQL: utiliser des noms de colonnes en minuscules avec alias
            $stmt = $conn->prepare('SELECT u.idutilisateur as idUtilisateur, u.idrole as idRole, u.identifiant as Identifiant, u.motdepasse as MotDePasse, u.nom as Nom, u.prenom as Prenom, r.role as Role FROM Utilisateur u JOIN Role r ON u.idrole = r.idrole WHERE u.identifiant = ?');
            if ($stmt) {
                $stmt->bind_param('s', $identifiant);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result) {
                    $user = $result->fetch_assoc();
                    if ($user) {
                        // Gérer les deux cas : majuscules (alias) et minuscules (PostgreSQL)
                        $motDePasse = $user['MotDePasse'] ?? $user['motdepasse'] ?? '';
                        $idUtilisateur = $user['idUtilisateur'] ?? $user['idutilisateur'] ?? 0;
                        $idRole = $user['idRole'] ?? $user['idrole'] ?? 0;
                        $nom = $user['Nom'] ?? $user['nom'] ?? '';
                        $prenom = $user['Prenom'] ?? $user['prenom'] ?? '';
                        $role = $user['Role'] ?? $user['role'] ?? '';
                        $identifiantUser = $user['Identifiant'] ?? $user['identifiant'] ?? '';
                        
                        if ($motDePasse && password_verify($mdp, $motDePasse)) {
                            // Sauvegarder la session
                            $_SESSION['user'] = [
                                'id'    => (int)$idUtilisateur,
                                'Nom'   => $nom,
                                'Prenom'=> $prenom,
                                'Role'  => $role,
                                'idRole'=> (int)$idRole,
                                'Identifiant' => $identifiantUser
                            ];
                            
                            // Redirection selon le rôle
                            $redirect = 'index.php';
                            if ($idRole == 1) {
                                $redirect = 'dashboard_stock.php';
                            } else if ($idRole == 2) {
                                $redirect = 'dashboard_groupe.php';
                            } else if ($idRole == 3) {
                                $redirect = 'dashboard_reception.php';
                            } else if ($idRole == 4) {
                                $redirect = 'dashboard_admin.php';
                            }
                            
                            // Redirection immédiate (session sera sauvegardée automatiquement)
                            header('Location: ' . $redirect, true, 302);
                            exit;
                        } else {
                            $error_message = "Identifiant ou mot de passe incorrect.";
                        }
                    } else {
                        $error_message = "Identifiant ou mot de passe incorrect.";
                    }
                } else {
                    $error_message = "Erreur lors de la vérification des identifiants.";
                }
            } else {
                $error_message = "Erreur de préparation de la requête.";
            }
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            $error_message = "Erreur de connexion. Veuillez réessayer.";
        }
    } else {
        $error_message = "Veuillez remplir tous les champs.";
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - Le Coq Sportif</title>
    <link rel="icon" type="image/png" href="photos/logo.png">
    <link rel="shortcut icon" type="image/png" href="photos/logo.png">
    <link rel="apple-touch-icon" href="photos/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }
        .glassmorphism {
            background: #fff !important;
            color: #111 !important;
            border: 1px solid #bbb !important;
            box-shadow: 0 4px 24px 0 rgba(0,0,0,0.08);
            box-sizing: border-box;
        }
        .glassmorphism label {
            color: #000 !important;
        }
        .glassmorphism input {
            background: #fff !important;
            color: #000 !important;
            border: 1.5px solid #000 !important;
            box-shadow: none !important;
            transition: border 0.2s, box-shadow 0.2s;
        }
        .glassmorphism input:focus {
            border-color: #000 !important;
            box-shadow: 0 0 0 2px #0002 !important;
            background: #fff !important;
            color: #000 !important;
        }
        .glassmorphism input::placeholder {
            color: #000 !important;
            opacity: 1 !important;
        }
        .glassmorphism button {
            background: #000 !important;
            color: #fff !important;
            border: 1.5px solid #000 !important;
            transition: background 0.2s, color 0.2s, border 0.2s;
        }
        .glassmorphism button:hover {
            background: #222 !important;
            color: #fff !important;
            border-color: #000 !important;
        }
        /* Si tu veux garder le flou sur le body, laisse-le sur body uniquement, pas sur le container */
        .gradient-bg { background: transparent; }
        .fade-in { animation: fadeIn 0.5s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        body {
            background-image: url('photos/image.jpg');
            background-size: cover;
            background-repeat: no-repeat;
            background-position: center center;
            z-index: 10;
            overflow-y: auto;
        }
        
        .main-container {
            max-height: 100vh;
            overflow-y: auto;
            padding: 20px;
        }

        .input-wrapper {
            position: relative;
            width: 100%;
        }

        #passwordInput {
            width: 100%;
            box-sizing: border-box;
        }

        #togglePassword {
            position: absolute;
            top: 0;
            right: 16px;
            height: 100%;
            display: flex;
            align-items: center;
            cursor: pointer;
            z-index: 2;
        }

        #togglePassword i {
            font-size: 1.2em;
            pointer-events: none;
        }

        .users-card {
            background: #f8f9fa !important;
            border: 1px solid #dee2e6 !important;
            border-radius: 12px;
            max-height: 180px;
            overflow-y: auto;
        }

        .users-card::-webkit-scrollbar {
            width: 6px;
        }

        .users-card::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        .users-card::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 10px;
        }

        .users-card::-webkit-scrollbar-thumb:hover {
            background: #555;
        }

        .user-item {
            transition: background 0.2s;
            cursor: pointer;
            padding: 8px 4px !important;
            margin-bottom: 4px !important;
        }

        .user-item:hover {
            background: #e9ecef !important;
        }

        .role-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .role-admin { background: #dc3545; color: white; }
        .role-stock { background: #007bff; color: white; }
        .role-groupe { background: #28a745; color: white; }
        .role-reception { background: #ffc107; color: #000; }

    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="min-h-screen flex items-center justify-center main-container">
        <div class="glassmorphism p-6 rounded-2xl shadow-2xl w-full max-w-md fade-in" style="max-height: 95vh; overflow-y: auto;">
            <div class="text-center mb-6">
                <img src="photos/logo.png" alt="Le Coq Sportif" class="mx-auto mb-3" style="max-width:80px;">
                <h1 class="text-2xl font-bold mb-1">Gestion d'Échantillons</h1>
                <p class="opacity-80 text-sm">Plateforme de Gestion developpée par<br>IZIKI Alaa Eddine - HAFIT Rabii</p>
            </div>
            <?php if (isset($error_message)): ?>
            <div class="mb-4 p-3 bg-red-100 border border-red-400 text-red-700 rounded-lg text-sm">
                <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error_message) ?>
            </div>
            <?php endif; ?>
            <form method="post" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-2"><i class="fas fa-user mr-2"></i>Identifiant</label>
                    <input type="text" name="identifiant" class="w-full px-4 py-3 rounded-lg" placeholder="Votre identifiant" 
                    required autocomplete="username" value="<?= htmlspecialchars($_POST['identifiant'] ?? '') ?>">
                </div>
                <div class="relative">
                    <label class="block text-sm font-medium mb-2"><i class="fas fa-lock mr-2"></i>Mot de passe</label>
                    <div class="input-wrapper" style="position: relative;">
                        <input
                            type="password"
                            name="mdp"
                            id="passwordInput"
                            class="w-full px-4 py-3 pr-12 rounded-lg"
                            placeholder="Votre mot de passe"
                            required
                            autocomplete="current-password"
                        >
                        <span id="togglePassword">
                            <i class="fas fa-eye"></i>
                        </span>
                    </div>
                </div>
                <button type="submit" class="w-full font-medium py-3 px-6 rounded-lg flex items-center justify-center">
                    <span>Se connecter</span>
                </button>
            </form>
            
            <div class="mt-4 pt-4 border-t border-gray-300">
                <div class="mb-2">
                    <h3 class="text-xs font-semibold text-gray-700 mb-1 flex items-center">
                        <i class="fas fa-users mr-1"></i>Comptes disponibles
                    </h3>
                </div>
                <?php if (!empty($all_users)): ?>
                <div class="users-card p-2">
                    <?php foreach ($all_users as $user): ?>
                        <?php
                        // Gérer les deux cas : majuscules (alias) et minuscules (PostgreSQL)
                        $role = $user['Role'] ?? $user['role'] ?? '';
                        $nom = $user['Nom'] ?? $user['nom'] ?? '';
                        $prenom = $user['Prenom'] ?? $user['prenom'] ?? '';
                        $identifiant = $user['Identifiant'] ?? $user['identifiant'] ?? '';
                        
                        $roleClass = '';
                        if (stripos($role, 'Admin') !== false) $roleClass = 'role-admin';
                        else if (stripos($role, 'Stock') !== false) $roleClass = 'role-stock';
                        else if (stripos($role, 'Groupe') !== false) $roleClass = 'role-groupe';
                        else if (stripos($role, 'Réception') !== false || stripos($role, 'Reception') !== false) $roleClass = 'role-reception';
                        
                        $password = $passwords_map[$identifiant] ?? 'N/A';
                        ?>
                        <div class="user-item rounded-lg" onclick="fillCredentials('<?= htmlspecialchars($identifiant) ?>', '<?= htmlspecialchars($password) ?>')">
                            <div class="flex items-center justify-between">
                                <div class="flex-1">
                                    <div class="font-medium text-xs text-gray-800">
                                        <?= htmlspecialchars(trim($nom . ' ' . $prenom)) ?>
                                    </div>
                                    <div class="text-xs text-gray-600">
                                        <i class="fas fa-user-circle mr-1"></i>
                                        <strong>ID:</strong> <span class="font-mono"><?= htmlspecialchars($identifiant) ?></span>
                                        <span class="mx-2">|</span>
                                        <i class="fas fa-key mr-1"></i>
                                        <strong>MDP:</strong> <span class="font-mono"><?= htmlspecialchars($password) ?></span>
                                    </div>
                                </div>
                                <div class="ml-2">
                                    <span class="role-badge <?= $roleClass ?>" style="font-size: 0.7rem; padding: 1px 6px;">
                                        <?= htmlspecialchars($role) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="text-xs text-gray-500 mt-2 text-center">
                    <i class="fas fa-mouse-pointer mr-1"></i>Cliquez sur un utilisateur pour remplir les identifiants
                </p>
                <?php else: ?>
                <div class="text-center py-4 text-xs text-gray-500">
                    <i class="fas fa-info-circle mr-1"></i>Aucun utilisateur trouvé dans la base de données
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
        const passwordInput = document.getElementById('passwordInput');
        const togglePassword = document.getElementById('togglePassword');
        togglePassword.addEventListener('click', function() {
            const isPwd = passwordInput.type === 'password';
            passwordInput.type = isPwd ? 'text' : 'password';
            this.innerHTML = isPwd ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
        });

        function fillCredentials(identifiant, password) {
            document.querySelector('input[name="identifiant"]').value = identifiant;
            document.querySelector('input[name="mdp"]').value = password;
            document.querySelector('input[name="identifiant"]').focus();
        }
    </script>
</body>
</html> 