<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user']) || !isset($_SESSION['user']['id'])) {
    echo json_encode(['success' => false, 'message' => 'Utilisateur non connecté']);
    exit;
}

$user_id = $_SESSION['user']['id'];

// Get JSON data from request
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['type']) || !isset($data['echantillon_id']) || !isset($data['quantite'])) {
    echo json_encode(['success' => false, 'message' => 'Données manquantes']);
    exit;
}

$type = $data['type'];
$echantillon_id = intval($data['echantillon_id']);
$quantite = intval($data['quantite']);

if ($quantite < 1) {
    echo json_encode(['success' => false, 'message' => 'La quantité doit être supérieure à 0']);
    exit;
}

try {
    // Start transaction
    $conn->begin_transaction();

    if ($type === 'demande') {
        // Check if sample exists and has enough quantity
        $check_query = "SELECT quantite FROM echantillons WHERE id = $echantillon_id";
        $result = $conn->query($check_query);
        
        if (!$result || $result->num_rows === 0) {
            throw new Exception('Échantillon non trouvé');
        }
        
        $row = $result->fetch_assoc();
        if ($row['quantite'] < $quantite) {
            throw new Exception('Quantité insuffisante');
        }

        // Create new request
        $date = date('Y-m-d H:i:s');
        $insert_query = "INSERT INTO demandes (user_id, echantillon_id, quantite, date_demande, statut, quantite_retournee) 
                        VALUES ($user_id, $echantillon_id, $quantite, '$date', 'En attente', 0)";
        
        if (!$conn->query($insert_query)) {
            throw new Exception('Erreur lors de la création de la demande');
        }

        // Add to history
        $history_query = "INSERT INTO historique (user_id, action, echantillon_id, quantite, date_action) 
                         VALUES ($user_id, 'Nouvelle Demande', $echantillon_id, $quantite, '$date')";
        
        if (!$conn->query($history_query)) {
            throw new Exception('Erreur lors de l\'ajout à l\'historique');
        }

    } elseif ($type === 'retour') {
        // Check if user has borrowed this sample and hasn't returned all
        $check_query = "SELECT d.id, d.quantite, d.quantite_retournee 
                       FROM demandes d
                       WHERE d.user_id = $user_id 
                       AND d.echantillon_id = $echantillon_id 
                       AND d.statut = 'Validée'
                       AND d.quantite_retournee < d.quantite
                       ORDER BY d.date_demande DESC
                       LIMIT 1";
        
        $result = $conn->query($check_query);
        if (!$result || $result->num_rows === 0) {
            throw new Exception('Aucune demande valide trouvée pour cet échantillon');
        }
        
        $demande = $result->fetch_assoc();
        $remaining = $demande['quantite'] - $demande['quantite_retournee'];
        
        if ($quantite > $remaining) {
            throw new Exception('La quantité à retourner ne peut pas dépasser ' . $remaining);
        }

        // Update returned quantity
        $update_query = "UPDATE demandes 
                        SET quantite_retournee = quantite_retournee + $quantite
                        WHERE id = " . $demande['id'];
        
        if (!$conn->query($update_query)) {
            throw new Exception('Erreur lors de la mise à jour de la demande');
        }

        // Update sample quantity
        $update_sample = "UPDATE echantillons 
                         SET quantite = quantite + $quantite
                         WHERE id = $echantillon_id";
        
        if (!$conn->query($update_sample)) {
            throw new Exception('Erreur lors de la mise à jour de l\'échantillon');
        }

        // Add to history
        $date = date('Y-m-d H:i:s');
        $history_query = "INSERT INTO historique (user_id, action, echantillon_id, quantite, date_action) 
                         VALUES ($user_id, 'Retour', $echantillon_id, $quantite, '$date')";
        
        if (!$conn->query($history_query)) {
            throw new Exception('Erreur lors de l\'ajout à l\'historique');
        }
    } else {
        throw new Exception('Type d\'opération invalide');
    }

    // Commit transaction
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => $type === 'demande' ? 'Demande créée avec succès' : 'Retour enregistré avec succès'
    ]);

} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?> 