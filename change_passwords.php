<?php
require_once 'config/db.php';

// Fonction pour hacher un mot de passe
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

// Fonction pour vérifier un mot de passe
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Traitement du formulaire
$successMessage = '';
$errorMessage = '';

if ($_POST) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'hash_password') {
        $password = $_POST['password'] ?? '';
        if (!empty($password)) {
            $hashedPassword = hashPassword($password);
            $verification = verifyPassword($password, $hashedPassword) ? '✓ Valide' : '✗ Invalide';
        }
    }
    
    if ($action === 'update_user_password') {
        $userId = $_POST['user_id'] ?? '';
        $password = $_POST['user_password'] ?? '';
        
        if (!empty($userId) && !empty($password)) {
            $hashedPassword = hashPassword($password);
            
            // Vérifier si l'utilisateur existe
            $checkStmt = $conn->prepare("SELECT idUtilisateur, Identifiant, Nom, Prenom FROM Utilisateur WHERE idUtilisateur = ?");
            $checkStmt->bind_param("i", $userId);
            $checkStmt->execute();
            $user = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();
            
            if ($user) {
                // Mettre à jour le mot de passe
                $updateStmt = $conn->prepare("UPDATE Utilisateur SET MotDePasse = ? WHERE idUtilisateur = ?");
                $updateStmt->bind_param("si", $hashedPassword, $userId);
                
                if ($updateStmt->execute()) {
                    $successMessage = "Mot de passe mis à jour avec succès pour " . htmlspecialchars($user['Nom']) . " " . htmlspecialchars($user['Prenom']) . " (ID: $userId)";
                } else {
                    $errorMessage = "Erreur lors de la mise à jour du mot de passe";
                }
                $updateStmt->close();
            } else {
                $errorMessage = "Utilisateur avec l'ID $userId introuvable";
            }
        } else {
            $errorMessage = "Veuillez remplir tous les champs";
        }
    }
    
    if ($action === 'reset_all_passwords') {
        $defaultPassword = $_POST['default_password'] ?? '123456';
        $hashedPassword = hashPassword($defaultPassword);
        
        // Récupérer tous les utilisateurs
        $users = $conn->query("SELECT idUtilisateur, Identifiant, Nom, Prenom FROM Utilisateur")->fetch_all(MYSQLI_ASSOC);
        
        $successCount = 0;
        $errorCount = 0;
        
        $stmt = $conn->prepare("UPDATE Utilisateur SET MotDePasse = ? WHERE idUtilisateur = ?");
        
        foreach ($users as $user) {
            $stmt->bind_param("si", $hashedPassword, $user['idUtilisateur']);
            
            if ($stmt->execute()) {
                $successCount++;
            } else {
                $errorCount++;
            }
        }
        
        $stmt->close();
        
        if ($successCount > 0) {
            $successMessage = "Réinitialisation réussie ! $successCount utilisateur(s) mis à jour avec le mot de passe : " . htmlspecialchars($defaultPassword);
        }
        if ($errorCount > 0) {
            $errorMessage = "Erreurs : $errorCount utilisateur(s) n'ont pas pu être mis à jour";
        }
    }
}

// Récupérer la liste des utilisateurs
$users = $conn->query("SELECT idUtilisateur, Identifiant, Nom, Prenom FROM Utilisateur ORDER BY Nom, Prenom")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des mots de passe</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input[type="text"], input[type="password"], select, textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        button {
            background-color: #007bff;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-right: 10px;
            margin-bottom: 10px;
        }
        button:hover {
            background-color: #0056b3;
        }
        button.danger {
            background-color: #dc3545;
        }
        button.danger:hover {
            background-color: #c82333;
        }
        .success {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
        }
        .error {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
        }
        .hash-result {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            padding: 15px;
            border-radius: 4px;
            margin: 10px 0;
            word-break: break-all;
        }
        .users-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .users-table th, .users-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        .users-table th {
            background-color: #f2f2f2;
        }
        .users-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .three-columns {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
        }
        @media (max-width: 1024px) {
            .three-columns {
                grid-template-columns: 1fr 1fr;
            }
        }
        @media (max-width: 768px) {
            .three-columns {
                grid-template-columns: 1fr;
            }
        }
        .warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            padding: 15px;
            margin: 10px 0;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <h1>🔐 Gestion des mots de passe</h1>
    
    <?php if ($successMessage): ?>
        <div class="success"><?php echo $successMessage; ?></div>
    <?php endif; ?>
    
    <?php if ($errorMessage): ?>
        <div class="error"><?php echo $errorMessage; ?></div>
    <?php endif; ?>
    
    <div class="three-columns">
        <!-- Section 1: Hachage de mot de passe -->
        <div class="container">
            <h2>1. Hacher un mot de passe</h2>
            <p>Entrez un mot de passe pour obtenir son hash :</p>
            
            <form method="POST">
                <input type="hidden" name="action" value="hash_password">
                <div class="form-group">
                    <label for="password">Mot de passe :</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit">Hacher le mot de passe</button>
            </form>
            
            <?php if (isset($hashedPassword)): ?>
                <div class="hash-result">
                    <h4>Résultat du hachage :</h4>
                    <p><strong>Mot de passe original :</strong> <?php echo htmlspecialchars($password); ?></p>
                    <p><strong>Hash généré :</strong></p>
                    <textarea readonly rows="3" style="width: 100%; font-family: monospace;"><?php echo htmlspecialchars($hashedPassword); ?></textarea>
                    <p><strong>Vérification :</strong> <?php echo $verification; ?></p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Section 2: Mise à jour d'un utilisateur -->
        <div class="container">
            <h2>2. Changer un mot de passe</h2>
            <p>Sélectionnez un utilisateur et entrez son nouveau mot de passe :</p>
            
            <form method="POST">
                <input type="hidden" name="action" value="update_user_password">
                <div class="form-group">
                    <label for="user_id">Utilisateur :</label>
                    <select id="user_id" name="user_id" required>
                        <option value="">-- Sélectionner un utilisateur --</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['idUtilisateur']; ?>">
                                <?php echo htmlspecialchars($user['Nom'] . ' ' . $user['Prenom'] . ' (' . $user['Identifiant'] . ') - ID: ' . $user['idUtilisateur']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="user_password">Nouveau mot de passe :</label>
                    <input type="password" id="user_password" name="user_password" required>
                </div>
                <button type="submit">Changer le mot de passe</button>
            </form>
        </div>
        
        <!-- Section 3: Réinitialiser tous les mots de passe -->
        <div class="container">
            <h2>3. Réinitialiser tous</h2>
            <p>Remet tous les utilisateurs avec le même mot de passe :</p>
            
            <form method="POST" onsubmit="return confirm('Êtes-vous sûr de vouloir réinitialiser TOUS les mots de passe ?');">
                <input type="hidden" name="action" value="reset_all_passwords">
                <div class="form-group">
                    <label for="default_password">Mot de passe par défaut :</label>
                    <input type="password" id="default_password" name="default_password" value="123456" required>
                </div>
                <button type="submit" class="danger">Réinitialiser tous les mots de passe</button>
            </form>
            
            <div class="warning">
                <h4>⚠️ Attention</h4>
                <p>Cette action va changer le mot de passe de <strong>tous</strong> les utilisateurs !</p>
            </div>
        </div>
    </div>
    
    <!-- Section 4: Liste des utilisateurs -->
    <div class="container">
        <h2>4. Liste des utilisateurs</h2>
        <p>Voici tous les utilisateurs de la base de données :</p>
        
        <table class="users-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Identifiant</th>
                    <th>Nom</th>
                    <th>Prénom</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?php echo $user['idUtilisateur']; ?></td>
                        <td><?php echo htmlspecialchars($user['Identifiant']); ?></td>
                        <td><?php echo htmlspecialchars($user['Nom']); ?></td>
                        <td><?php echo htmlspecialchars($user['Prenom']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Instructions -->
    <div class="container">
        <h2>📋 Instructions d'utilisation</h2>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div>
                <h3>🔍 Hacher un mot de passe</h3>
                <ol>
                    <li>Entrez le mot de passe à hacher</li>
                    <li>Cliquez "Hacher le mot de passe"</li>
                    <li>Copiez le hash généré</li>
                </ol>
            </div>
            <div>
                <h3>👤 Changer un mot de passe</h3>
                <ol>
                    <li>Sélectionnez l'utilisateur</li>
                    <li>Entrez le nouveau mot de passe</li>
                    <li>Cliquez "Changer le mot de passe"</li>
                </ol>
            </div>
            <div>
                <h3>🔄 Réinitialiser tous</h3>
                <ol>
                    <li>Entrez le mot de passe par défaut</li>
                    <li>Cliquez "Réinitialiser tous"</li>
                    <li>Confirmez l'action</li>
                </ol>
            </div>
            <div>
                <h3>💡 Astuce</h3>
                <p>Tu peux aussi copier le hash généré et l'utiliser directement dans une requête SQL :</p>
                <code>UPDATE Utilisateur SET MotDePasse = 'HASH_ICI' WHERE idUtilisateur = ID_UTILISATEUR;</code>
            </div>
        </div>
    </div>
</body>
</html>
