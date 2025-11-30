<?php
require_once 'config/db.php';
$users = $conn->query("SELECT idUtilisateur, MotDePasse FROM Utilisateur")->fetch_all(MYSQLI_ASSOC);
foreach ($users as $u) {
    $id = $u['idUtilisateur'];
    $plain = $u['MotDePasse'];
    // Si déjà hashé (commence par $2y$), on saute
    if (strpos($plain, '$2y$') === 0) continue;
    $hashed = password_hash($plain, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE Utilisateur SET MotDePasse=? WHERE idUtilisateur=?");
    $stmt->bind_param("si", $hashed, $id);
    $stmt->execute();
    echo "Utilisateur $id : mot de passe hashé<br>";
}
echo "Terminé.";
