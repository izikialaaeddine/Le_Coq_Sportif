<?php
require_once __DIR__ . '/config/error_config.php';
session_start();
require_once __DIR__ . '/config/db.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['idRole'] != 4) {
    header('Location: index.php');
    exit;
}

// Gestion des actions
if (isset($_POST['action'])) {
    if ($_POST['action'] == 'add') {
        try {
            $hashedPassword = password_hash($_POST['MotDePasse'], PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO Utilisateur (idrole, identifiant, motdepasse, nom, prenom) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("issss", $_POST['idRole'], $_POST['Identifiant'], $hashedPassword, $_POST['Nom'], $_POST['Prenom']);
            
            if ($stmt->execute()) {
                $_SESSION['notification'] = [
                    'type' => 'success',
                    'message' => "Utilisateur " . htmlspecialchars($_POST['Nom']) . " " . htmlspecialchars($_POST['Prenom']) . " ajouté avec succès"
                ];
            } else {
                $_SESSION['notification'] = [
                    'type' => 'error',
                    'message' => "Erreur : " . $stmt->error
                ];
            }
            $stmt->close();
        } catch (Exception $e) {
            $_SESSION['notification'] = [
                'type' => 'error',
                'message' => "Erreur : " . $e->getMessage()
            ];
        }
        header('Location: dashboard_admin.php');
        exit;
    }
    
    if ($_POST['action'] == 'edit') {
        try {
            $stmtOld = $conn->prepare("SELECT motdepasse as MotDePasse FROM Utilisateur WHERE idutilisateur=?");
            $stmtOld->bind_param("i", $_POST['idUtilisateur']);
            $stmtOld->execute();
            $resultOld = $stmtOld->get_result();
            $rowOld = $resultOld->fetch_assoc();
            $oldHash = $rowOld['MotDePasse'] ?? $rowOld['motdepasse'] ?? '';
            $stmtOld->close();
            
            $newPass = $_POST['MotDePasse'];
            if (strpos($newPass, '$2y$') === 0 && password_get_info($newPass)['algo'] !== 0) {
                $hashedPassword = $newPass;
            } elseif (password_verify($newPass, $oldHash)) {
                $hashedPassword = $oldHash;
            } else {
                $hashedPassword = password_hash($newPass, PASSWORD_DEFAULT);
            }
            
            $stmt = $conn->prepare("UPDATE Utilisateur SET idrole=?, identifiant=?, motdepasse=?, nom=?, prenom=? WHERE idutilisateur=?");
            $stmt->bind_param("issssi", $_POST['idRole'], $_POST['Identifiant'], $hashedPassword, $_POST['Nom'], $_POST['Prenom'], $_POST['idUtilisateur']);
            
            if ($stmt->execute()) {
                $_SESSION['notification'] = [
                    'type' => 'success',
                    'message' => "Utilisateur " . htmlspecialchars($_POST['Nom']) . " " . htmlspecialchars($_POST['Prenom']) . " modifié avec succès"
                ];
            } else {
                $_SESSION['notification'] = [
                    'type' => 'error',
                    'message' => "Erreur lors de la modification : " . $stmt->error
                ];
            }
            $stmt->close();
        } catch (Exception $e) {
            $_SESSION['notification'] = [
                'type' => 'error',
                'message' => "Erreur : " . $e->getMessage()
            ];
        }
        header('Location: dashboard_admin.php');
        exit;
    }
    
    if ($_POST['action'] == 'delete') {
        try {
            $stmt = $conn->prepare("DELETE FROM Utilisateur WHERE idutilisateur=?");
            $stmt->bind_param("i", $_POST['idUtilisateur']);
            
            if ($stmt->execute()) {
                $nom = isset($_POST['Nom']) ? htmlspecialchars($_POST['Nom']) : '';
                $prenom = isset($_POST['Prenom']) ? htmlspecialchars($_POST['Prenom']) : '';
                $_SESSION['notification'] = [
                    'type' => 'success',
                    'message' => "Utilisateur $nom $prenom supprimé avec succès"
                ];
            } else {
                $_SESSION['notification'] = [
                    'type' => 'error',
                    'message' => "Erreur lors de la suppression : " . $stmt->error
                ];
            }
            $stmt->close();
        } catch (Exception $e) {
            $_SESSION['notification'] = [
                'type' => 'error',
                'message' => "Erreur : " . $e->getMessage()
            ];
        }
        header('Location: dashboard_admin.php');
        exit;
    }
    
    if ($_POST['action'] == 'reset_password') {
        try {
            $id = $_POST['idUtilisateur'];
            $newPass = $_POST['newPassword'];
            
            $stmtOld = $conn->prepare("SELECT motdepasse as MotDePasse FROM Utilisateur WHERE idutilisateur=?");
            $stmtOld->bind_param("i", $id);
            $stmtOld->execute();
            $resultOld = $stmtOld->get_result();
            $rowOld = $resultOld->fetch_assoc();
            $oldHash = $rowOld['MotDePasse'] ?? $rowOld['motdepasse'] ?? '';
            $stmtOld->close();
            
            if (password_verify($newPass, $oldHash)) {
                $_SESSION['notification'] = [
                    'type' => 'error',
                    'message' => "Le nouveau mot de passe ne peut pas être identique à l'ancien."
                ];
            } else {
                $hashedPassword = password_hash($newPass, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE Utilisateur SET motdepasse=? WHERE idutilisateur=?");
                $stmt->bind_param("si", $hashedPassword, $id);
                
                if ($stmt->execute()) {
                    $_SESSION['notification'] = [
                        'type' => 'success',
                        'message' => "Mot de passe réinitialisé avec succès."
                    ];
                } else {
                    $_SESSION['notification'] = [
                        'type' => 'error',
                        'message' => "Erreur : " . $stmt->error
                    ];
                }
                $stmt->close();
            }
        } catch (Exception $e) {
            $_SESSION['notification'] = [
                'type' => 'error',
                'message' => "Erreur : " . $e->getMessage()
            ];
        }
        header('Location: dashboard_admin.php');
        exit;
    }
}

// PostgreSQL: utiliser des alias pour les noms de colonnes (OPTIMISÉ - une seule requête)
try {
    $users_query = $conn->query("SELECT u.idutilisateur as idUtilisateur, u.idrole as idRole, u.identifiant as Identifiant, u.motdepasse as MotDePasse, u.nom as Nom, u.prenom as Prenom FROM Utilisateur u ORDER BY u.nom, u.prenom");
    if ($users_query) {
        if (method_exists($users_query, 'fetch_all')) {
            $users = $users_query->fetch_all(MYSQLI_ASSOC);
        } else {
            $users = [];
            while ($row = $users_query->fetch_assoc()) {
                $users[] = $row;
            }
        }
    } else {
        $users = [];
    }
} catch (Exception $e) {
    error_log("Error fetching users: " . $e->getMessage());
    $users = [];
}

try {
    $roles_query = $conn->query("SELECT r.idrole as idRole, r.role as Role FROM Role r ORDER BY r.idrole");
    if ($roles_query) {
        if (method_exists($roles_query, 'fetch_all')) {
            $roles = $roles_query->fetch_all(MYSQLI_ASSOC);
        } else {
            $roles = [];
            while ($row = $roles_query->fetch_assoc()) {
                $roles[] = $row;
            }
        }
    } else {
        $roles = [];
    }
} catch (Exception $e) {
    error_log("Error fetching roles: " . $e->getMessage());
    $roles = [];
}

$search = trim($_GET['search_identifiant'] ?? '');
$showList = isset($_GET['search_identifiant']) && $_GET['search_identifiant'] !== '';

// Récupération de la notification
$notification = null;
if (isset($_SESSION['notification'])) {
    $notification = $_SESSION['notification'];
    unset($_SESSION['notification']);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Admin</title>
    <link rel="icon" type="image/png" href="photos/logo.png">
    <link rel="shortcut icon" type="image/png" href="photos/logo.png">
    <link rel="apple-touch-icon" href="photos/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
        .sidebar {
            background: linear-gradient(180deg, #1e293b 0%, #334155 100%);
        }
        .sidebar-item {
            transition: all 0.3s ease;
        }
        .sidebar-item:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateX(5px);
        }
        .sidebar-item.active {
            background: rgba(102, 126, 234, 0.2);
            border-right: 3px solid #667eea;
        }
        .notification-slide {
            animation: slideIn 0.3s ease-out;
        }
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        .fade-in {
            animation: fadeIn 0.2s ease-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <div id="notificationsContainer" class="fixed top-6 right-6 z-50 flex flex-col items-end space-y-2"></div>

    <!-- Modal d'explication -->
    <div id="infoModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center hidden">
        <div class="bg-white rounded-lg shadow-2xl max-w-md w-full mx-4 fade-in">
            <div class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-xl font-bold text-gray-800 flex items-center">
                        <i class="fas fa-info-circle text-blue-500 mr-2"></i>
                        À propos de ce tableau de bord
                    </h3>
                    <button onclick="closeInfoModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <div class="text-gray-700 space-y-3">
                    <p class="text-base leading-relaxed">
                        <strong>Bienvenue sur le tableau de bord Administrateur !</strong>
                    </p>
                    <p class="text-sm">
                        Ce tableau de bord vous permet de gérer tous les utilisateurs du système. Vous pouvez :
                    </p>
                    <ul class="list-disc list-inside text-sm space-y-2 ml-2">
                        <li><strong>Ajouter</strong> de nouveaux utilisateurs avec leurs rôles et permissions</li>
                        <li><strong>Modifier</strong> les informations des utilisateurs existants</li>
                        <li><strong>Supprimer</strong> des utilisateurs du système</li>
                        <li><strong>Rechercher</strong> des utilisateurs par identifiant</li>
                        <li><strong>Réinitialiser</strong> les mots de passe des utilisateurs</li>
                    </ul>
                    <p class="text-sm mt-4 text-gray-600">
                        <i class="fas fa-shield-alt mr-1"></i>
                        En tant qu'administrateur, vous avez un accès complet à toutes les fonctionnalités de gestion des utilisateurs.
                    </p>
                </div>
                <div class="mt-6 flex justify-end">
                    <button onclick="closeInfoModal()" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors">
                        Compris
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Header -->
    <header class="bg-white shadow-lg border-b border-gray-200">
        <div class="flex items-center justify-between px-6 py-4">
            <div class="flex items-center space-x-4">
                <img src="photos/logo.png" alt="Logo" class="h-10 w-10 object-contain" />
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">Gestion d'Échantillons</h1>
                    <p class="text-sm text-gray-600">Administrateur - Tableau de bord</p>
                </div>
            </div>
            <div class="flex items-center space-x-4">
                <button onclick="openInfoModal()" class="px-3 py-2 bg-blue-100 text-blue-600 rounded-lg hover:bg-blue-200 transition-colors" title="Aide">
                    <i class="fas fa-question-circle"></i>
                </button>
                <div class="flex items-center space-x-2">
                    <div class="w-8 h-8 bg-indigo-600 rounded-full flex items-center justify-center">
                        <i class="fas fa-user-shield text-white text-sm"></i>
                    </div>
                    <div>
                        <p class="font-medium text-gray-800"><?= htmlspecialchars($_SESSION['user']['Nom']) ?></p>
                        <p class="text-sm text-gray-600">Administrateur</p>
                    </div>
                </div>
                <a href="logout.php" class="px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 transition-colors">
                    <i class="fas fa-sign-out-alt mr-2"></i>Déconnexion
                </a>
            </div>
        </div>
    </header>

    <div class="flex">
        <!-- Sidebar -->
        <aside class="sidebar w-64 min-h-screen">
            <nav class="py-6">
                <div class="space-y-2 px-4">
                    <a href="#" id="menuAdd" class="sidebar-item <?= !$showList ? 'active' : '' ?> flex items-center px-4 py-2 rounded text-white font-semibold">
                        <i class="fas fa-user-plus mr-3"></i> Ajouter un utilisateur
                    </a>
                    <a href="#" id="menuList" class="sidebar-item <?= $showList ? 'active' : '' ?> flex items-center px-4 py-2 rounded text-white font-semibold">
                        <i class="fas fa-users mr-3"></i> Liste des utilisateurs
                    </a>
                </div>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 p-6 bg-gray-50">
            <div class="max-w-5xl mx-auto space-y-16">
                <!-- Section Ajout -->
                <section id="sectionAdd" class="bg-white p-8 rounded-xl shadow border-2 border-indigo-200<?= $showList ? ' hidden' : '' ?>">
                    <h3 class="text-2xl font-bold mb-6 text-indigo-700 flex items-center">
                        <i class="fas fa-user-plus mr-3"></i> Ajouter un utilisateur
                    </h3>
                    <form method="post" class="grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
                        <input type="hidden" name="action" value="add">
                        <div>
                            <label class="block text-sm font-medium mb-1">Nom</label>
                            <input type="text" name="Nom" class="w-full px-3 py-2 border rounded" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Prénom</label>
                            <input type="text" name="Prenom" class="w-full px-3 py-2 border rounded" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Identifiant</label>
                            <input type="text" name="Identifiant" class="w-full px-3 py-2 border rounded" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Mot de passe</label>
                            <input type="password" name="MotDePasse" class="w-full px-3 py-2 border rounded" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Rôle</label>
                            <select name="idRole" class="w-full px-3 py-2 border rounded" required>
                                <option value="">--Rôle--</option>
                                <?php foreach ($roles as $role): 
                                    $roleId = $role['idRole'] ?? $role['idrole'] ?? 0;
                                    $roleName = $role['Role'] ?? $role['role'] ?? '';
                                ?>
                                    <option value="<?= $roleId ?>"><?= htmlspecialchars($roleName) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <button class="w-full bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700" type="submit">Ajouter</button>
                        </div>
                    </form>
                </section>

                <!-- Section Tableau -->
                <section id="sectionList" class="bg-white p-8 rounded-xl shadow border-2 border-gray-200<?= $showList ? '' : ' hidden' ?>">
                    <h3 class="text-2xl font-bold mb-6 text-gray-700 flex items-center">
                        <i class="fas fa-users mr-3"></i> Liste des utilisateurs
                    </h3>
                    <!-- Barre de recherche -->
                    <form method="get" class="mb-6 flex items-center space-x-4 max-w-md mx-auto">
                        <input type="hidden" name="section" value="list">
                        <input type="text" name="search_identifiant" placeholder="Rechercher par identifiant..." value="<?= htmlspecialchars($_GET['search_identifiant'] ?? '') ?>" class="px-4 py-2 border rounded w-full">
                        <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">
                            <i class="fas fa-search"></i> Rechercher
                        </button>
                    </form>
                    <div class="overflow-x-auto max-w-4xl mx-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead>
                                <tr>
                                    <th class="px-4 py-2">ID</th>
                                    <th class="px-4 py-2">Nom</th>
                                    <th class="px-4 py-2">Prénom</th>
                                    <th class="px-4 py-2">Identifiant</th>
                                    <th class="px-4 py-2">Rôle</th>
                                    <th class="px-4 py-2">Actions</th>
                                    <th class="px-4 py-2">Réinitialiser MDP</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php
                            $filteredUsers = $users;
                            if ($search !== '') {
                                $filteredUsers = array_filter($users, function($u) use ($search) {
                                    $identifiant = $u['Identifiant'] ?? $u['identifiant'] ?? '';
                                    return stripos($identifiant, $search) !== false;
                                });
                            }
                            foreach ($filteredUsers as $u): 
                                // Gérer les deux cas : majuscules (alias) et minuscules (PostgreSQL)
                                $idUtilisateur = $u['idUtilisateur'] ?? $u['idutilisateur'] ?? 0;
                                $idRole = $u['idRole'] ?? $u['idrole'] ?? 0;
                                $nom = $u['Nom'] ?? $u['nom'] ?? '';
                                $prenom = $u['Prenom'] ?? $u['prenom'] ?? '';
                                $identifiant = $u['Identifiant'] ?? $u['identifiant'] ?? '';
                                $motDePasse = $u['MotDePasse'] ?? $u['motdepasse'] ?? '';
                            ?>
                                <tr>
                                    <form method="post">
                                        <td class="px-4 py-2"><?= $idUtilisateur ?></td>
                                        <td class="px-4 py-2"><input type="text" name="Nom" value="<?= htmlspecialchars($nom) ?>" class="border rounded px-2 py-1" required></td>
                                        <td class="px-4 py-2"><input type="text" name="Prenom" value="<?= htmlspecialchars($prenom) ?>" class="border rounded px-2 py-1" required></td>
                                        <td class="px-4 py-2"><input type="text" name="Identifiant" value="<?= htmlspecialchars($identifiant) ?>" class="border rounded px-2 py-1" required></td>
                                        <td class="px-4 py-2">
                                            <select name="idRole" class="border rounded px-2 py-1" required>
                                                <?php foreach ($roles as $role): 
                                                    $roleId = $role['idRole'] ?? $role['idrole'] ?? 0;
                                                    $roleName = $role['Role'] ?? $role['role'] ?? '';
                                                ?>
                                                    <option value="<?= $roleId ?>" <?= $idRole == $roleId ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($roleName) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td class="px-4 py-2">
                                            <input type="hidden" name="idUtilisateur" value="<?= $idUtilisateur ?>">
                                            <input type="hidden" name="MotDePasse" value="<?= htmlspecialchars($motDePasse) ?>">
                                            <button class="bg-yellow-400 text-white px-2 py-1 rounded hover:bg-yellow-500" type="submit" name="action" value="edit">Modifier</button>
                                            <button type="button" class="bg-red-500 text-white px-2 py-1 rounded hover:bg-red-600" onclick="openDeleteModal(<?= $idUtilisateur ?>, '<?= htmlspecialchars($nom, ENT_QUOTES) ?>', '<?= htmlspecialchars($prenom, ENT_QUOTES) ?>')">Supprimer</button>
                                        </td>
                                        <td class="px-4 py-2 text-center">
                                            <button type="button" class="bg-blue-500 text-white px-3 py-2 rounded-lg hover:bg-blue-600 transition flex items-center justify-center mx-auto" style="min-width:44px;" title="Réinitialiser le mot de passe" onclick="openResetPasswordModal(<?= $u['idUtilisateur'] ?>)">
                                                <i class="fas fa-key mr-2"></i> Réinitialiser
                                            </button>
                                        </td>
                                    </form>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </main>
    </div>

    <!-- Modal de confirmation de suppression -->
    <div id="deleteModal" class="fixed inset-0 bg-black bg-opacity-50 modal hidden flex items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-2xl w-full max-w-md m-4 fade-in">
            <div class="flex items-center justify-between p-6 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800">Supprimer l'utilisateur</h3>
                <button class="text-gray-400 hover:text-gray-600 close-modal" onclick="closeDeleteModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="deleteForm" class="p-6 space-y-4" method="post">
                <input type="hidden" name="idUtilisateur" id="deleteUserId" value="">
                <input type="hidden" name="Nom" id="deleteUserNom" value="">
                <input type="hidden" name="Prenom" id="deleteUserPrenom" value="">
                <input type="hidden" name="action" value="delete">
                <div class="mb-4">
                    <p class="text-gray-700">Voulez-vous vraiment supprimer cet utilisateur ? Cette action est <span class="font-semibold text-red-600">irréversible</span>.</p>
                </div>
                <div class="flex justify-end gap-4">
                    <button type="button" onclick="closeDeleteModal()" class="px-4 py-2 bg-gray-200 rounded hover:bg-gray-300 text-gray-700 font-semibold">Annuler</button>
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700 font-semibold">Supprimer</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modale de réinitialisation du mot de passe -->
    <div id="resetPasswordModal" class="fixed inset-0 bg-black bg-opacity-50 modal hidden flex items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-2xl w-full max-w-md m-4 fade-in">
            <div class="flex items-center justify-between p-6 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800">Réinitialiser le mot de passe</h3>
                <button class="text-gray-400 hover:text-gray-600 close-modal" onclick="closeResetPasswordModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="resetPasswordForm" class="p-6 space-y-4" method="post">
                <input type="hidden" name="idUtilisateur" id="resetUserId" value="">
                <input type="hidden" name="action" value="reset_password">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Nouveau mot de passe</label>
                    <input type="password" name="newPassword" id="newPasswordInput" class="w-full px-3 py-2 border rounded" required>
                </div>
                <div class="flex justify-end gap-4">
                    <button type="button" onclick="closeResetPasswordModal()" class="px-4 py-2 bg-gray-200 rounded hover:bg-gray-300 text-gray-700 font-semibold">Annuler</button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 font-semibold">Valider</button>
                </div>
            </form>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
    function showNotification(message, type = 'success') {
        const notification = document.createElement('div');
        notification.className = `notification-slide bg-white border-l-4 rounded-xl shadow-lg px-6 py-4 mb-2 text-base flex items-center transition-all duration-500 ease-in-out opacity-100
            ${type === 'success' ? 'border-green-500 text-green-700' : 'border-red-500 text-red-700'}`;
        notification.style.boxShadow = '0 8px 24px rgba(0,0,0,0.12)';
        notification.style.minWidth = '280px';
        notification.innerHTML = `
            <span class="font-bold mr-3 text-2xl">${type === 'success' ? '✔️' : '❌'}</span>
            <span class="flex-1">${message}</span>
            <button onclick="this.parentElement.remove()" class="ml-4 text-xl text-gray-400 hover:text-gray-700 focus:outline-none">&times;</button>
        `;
        document.getElementById('notificationsContainer').appendChild(notification);
        setTimeout(() => {
            notification.style.opacity = '0';
            notification.style.transform = 'translateY(-20px)';
            setTimeout(() => notification.remove(), 500);
        }, 6000);
    }

    // Menu navigation
    const menuAdd = document.getElementById('menuAdd');
    const menuList = document.getElementById('menuList');
    const sectionAdd = document.getElementById('sectionAdd');
    const sectionList = document.getElementById('sectionList');

    menuAdd.addEventListener('click', function(e) {
        e.preventDefault();
        menuAdd.classList.add('active');
        menuList.classList.remove('active');
        sectionAdd.classList.remove('hidden');
        sectionList.classList.add('hidden');
    });

    menuList.addEventListener('click', function(e) {
        e.preventDefault();
        menuList.classList.add('active');
        menuAdd.classList.remove('active');
        sectionList.classList.remove('hidden');
        sectionAdd.classList.add('hidden');
    });

    function openDeleteModal(userId, nom, prenom) {
        document.getElementById('deleteUserId').value = userId;
        document.getElementById('deleteUserNom').value = nom;
        document.getElementById('deleteUserPrenom').value = prenom;
        document.getElementById('deleteModal').classList.remove('hidden');
    }

    function closeDeleteModal() {
        document.getElementById('deleteModal').classList.add('hidden');
    }

    function openResetPasswordModal(userId) {
        document.getElementById('resetUserId').value = userId;
        document.getElementById('newPasswordInput').value = '';
        document.getElementById('resetPasswordModal').classList.remove('hidden');
    }

    function closeResetPasswordModal() {
        document.getElementById('resetPasswordModal').classList.add('hidden');
    }

    // Fonctions pour le modal d'information
    function openInfoModal() {
        document.getElementById('infoModal').classList.remove('hidden');
    }

    function closeInfoModal() {
        document.getElementById('infoModal').classList.add('hidden');
    }

    // Fermer le modal en cliquant en dehors et ouvrir automatiquement
    (function() {
        function initInfoModal() {
            const infoModal = document.getElementById('infoModal');
            if (infoModal) {
                infoModal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeInfoModal();
                    }
                });
                
                // Ouvrir automatiquement le modal au chargement de la page
                setTimeout(() => {
                    openInfoModal();
                }, 1000);
            } else {
                // Si le modal n'existe pas encore, réessayer
                setTimeout(initInfoModal, 100);
            }
        }
        
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initInfoModal);
        } else {
            initInfoModal();
        }
    })();

    // Afficher la notification au chargement si présente
    <?php if (isset($notification['message']) && $notification['message']): ?>
        document.addEventListener('DOMContentLoaded', function() {
            showNotification(<?= json_encode($notification['message']) ?>, <?= json_encode($notification['type'] ?? 'success') ?>);
        });
    <?php endif; ?>
    </script>
</body>
</html>