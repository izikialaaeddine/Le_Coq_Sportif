<?php



date_default_timezone_set('Europe/Paris');
$dateDemande = date('Y-m-d H:i:s', strtotime('-1 hour'));

session_start();

// La session est bonne, on la garde simplement
if (!isset($_SESSION['user']) || $_SESSION['user']['idRole'] != 2) { // 2 = Chef de Groupe
    header('Location: index.php');
    exit;
}

$currentUser = $_SESSION['user'];
require_once 'config/db.php';
$conn->query("SET time_zone = '+01:00'");

// Helper function to add history entries (copiée du dashboard stock)
function ajouterHistorique($conn, $idUtilisateur, $refEchantillon, $typeAction, $description, $dateAction = null) {
    if ($dateAction === null) {
        $dateAction = date('Y-m-d H:i:s');
    }
    $stmt = $conn->prepare("INSERT INTO Historique (idUtilisateur, RefEchantillon, TypeAction, DateAction, Description) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) { return false; }
    $stmt->bind_param("issss", $idUtilisateur, $refEchantillon, $typeAction, $dateAction, $description);
    if (!$stmt->execute()) { return false; }
    return true;
}

$echantillons = $conn->query("SELECT * FROM Echantillon")->fetch_all(MYSQLI_ASSOC);

// Récupère toutes les demandes avec leurs échantillons ET le nom du demandeur
$demandes = [];
$res = $conn->query("
    SELECT d.*, u.Nom as NomDemandeur, u.Prenom as PrenomDemandeur
    FROM Demande d
    JOIN Utilisateur u ON d.idUtilisateur = u.idUtilisateur
    ORDER BY d.DateDemande DESC
");
if (!$res) { die('Erreur SQL : ' . $conn->error); }
while ($row = $res->fetch_assoc()) {
    $demande = [
        'id' => $row['idDemande'],
        'date' => $row['DateDemande'],
        'Statut' => $row['Statut'],
        'commentaire' => $row['Commentaire'],
        'nom_demandeur' => $row['NomDemandeur'],
        'prenom_demandeur' => $row['PrenomDemandeur'],
        'echantillons' => []
    ];
    $idDemande = $row['idDemande'];
    $res2 = $conn->query("SELECT * FROM DemandeEchantillon WHERE idDemande = $idDemande");
    while ($e = $res2->fetch_assoc()) {
        $demande['echantillons'][] = [
            'ref' => $e['refEchantillon'],
            'famille' => $e['famille'],
            'couleur' => $e['couleur'],
            'qte' => $e['qte']
        ];
    }
    $demandes[] = $demande;
}

// Debug PHP
error_log('Nombre de demandes récupérées: ' . count($demandes));
if (count($demandes) > 0) {
    error_log('Première demande: ' . json_encode($demandes[0]));
}

$retours = [];
$res = $conn->query("
    SELECT r.*, u.Nom AS NomDemandeur, u.Prenom AS PrenomDemandeur
    FROM Retour r
    JOIN Utilisateur u ON r.idUtilisateur = u.idUtilisateur
    ORDER BY r.DateRetour DESC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $row['echantillons'] = [];
        $idRetour = $row['idRetour'];
        $res2 = $conn->query("SELECT * FROM RetourEchantillon WHERE idRetour = $idRetour");
        while ($e = $res2->fetch_assoc()) {
            $row['echantillons'][] = $e;
        }
        $retours[] = $row;
    }
}

// Debug PHP retours
error_log('Nombre de retours récupérés: ' . count($retours));
if (count($retours) > 0) {
    error_log('Premier retour: ' . json_encode($retours[0]));
}

error_log('PHP timezone: ' . date_default_timezone_get());
error_log('PHP date: ' . date('Y-m-d H:i:s'));

// Récupérer les emprunts (demandes validées/empruntées)
$emprunts = [];
$res = $conn->query("
    SELECT de.refEchantillon, de.famille, de.couleur, SUM(de.qte) as qte_empruntee
    FROM DemandeEchantillon de
    JOIN Demande d ON d.idDemande = de.idDemande
    WHERE (
        d.Statut = 'Validée'
        OR d.Statut = 'emprunte'
        OR d.Statut = 'Prêt pour retrait'
        OR d.Statut = 'En fabrication'
        OR d.Statut = 'Attente inter-service'
    ) AND d.idUtilisateur = " . intval($currentUser['id']) . "
    GROUP BY de.refEchantillon, de.famille, de.couleur
");
if (!$res) { die('Erreur SQL : ' . $conn->error); }
while ($row = $res->fetch_assoc()) {
    $emprunts[$row['refEchantillon'].'|'.$row['famille'].'|'.$row['couleur']] = $row;
}

// Récupérer les retours déjà faits - CORRECTION: utiliser Statut au lieu de StatutRetour
$retours_qte = [];
$res = $conn->query("
    SELECT re.refEchantillon, re.famille, re.couleur, SUM(re.qte) as qte_retournee
    FROM RetourEchantillon re
    JOIN Retour r ON r.idRetour = re.idRetour
    WHERE r.idUtilisateur = " . intval($currentUser['id']) . "
      AND r.Statut IN ('Validé', 'Approuvé', 'Retourné')
    GROUP BY re.refEchantillon, re.famille, re.couleur
");
if (!$res) { die('Erreur SQL : ' . $conn->error); }
while ($row = $res->fetch_assoc()) {
    $retours_qte[$row['refEchantillon'].'|'.$row['famille'].'|'.$row['couleur']] = $row['qte_retournee'];
}

// Calculer la quantité réellement empruntée (non retournée)
$echantillons_valides = [];
foreach ($emprunts as $key => $e) {
    $qte_retournee = isset($retours_qte[$key]) ? $retours_qte[$key] : 0;
    $qte_dispo = $e['qte_empruntee'] - $qte_retournee;
    if ($qte_dispo > 0) {
        $echantillons_valides[] = [
            'refEchantillon' => $e['refEchantillon'],
            'famille' => $e['famille'],
            'couleur' => $e['couleur'],
            'qte' => $qte_dispo
        ];
    }
}

$statuts = ["Approuvée", "Prêt pour retrait", "emprunte", "En fabrication"];
$statuts_sql = implode("','", $statuts);
$demandes_retour = [];
$res = $conn->query("
    SELECT d.idDemande, d.DateDemande, d.Statut
    FROM Demande d
    WHERE d.idUtilisateur = " . intval($currentUser['id']) . "
      AND d.Statut IN ('$statuts_sql')
    ORDER BY d.DateDemande DESC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $idDemande = $row['idDemande'];
        $row['echantillons'] = [];
        $res2 = $conn->query("SELECT * FROM DemandeEchantillon WHERE idDemande = $idDemande");
        while ($e = $res2->fetch_assoc()) {
            $row['echantillons'][] = $e;
        }
        $demandes_retour[] = $row;
    }
}
echo '<script>let demandesRetour = ' . json_encode($demandes_retour) . ';</script>';
?>
<?php
session_start();
require_once 'config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['demande'])) {
    error_log('POST reçu: ' . print_r($_POST, true));
    $idUtilisateur = $_SESSION['user']['id'];
    $typeDemande = 'Demande';
    $dateDemande = date('Y-m-d H:i:s', strtotime('-1 hour'));
    $statut = 'En attente';
    $qte = intval($_POST['qte']);
    $commentaire = $_POST['commentaire'] ?? '';

    if (!empty($_POST['edit_demande_id'])) {
        // Vérifier le statut actuel
        $res = $conn->query("SELECT Statut FROM Demande WHERE idDemande = " . intval($_POST['edit_demande_id']));
        $row = $res->fetch_assoc();
        if (strtolower(trim($row['Statut'])) !== 'en attente') {
            $_SESSION['notification'] = [
                'type' => 'error',
                'message' => 'Impossible de modifier une demande qui n\'est plus en attente.'
            ];
            header('Location: dashboard_groupe.php');
            exit;
        }
        // MODIFICATION
        $idDemande = intval($_POST['edit_demande_id']);
        $stmt = $conn->prepare("UPDATE Demande SET Qte=?, Commentaire=?, DateDemande=?, Statut=? WHERE idDemande=?");
        $stmt->bind_param("isssi", $qte, $commentaire, $dateDemande, $statut, $idDemande);
        if (!$stmt->execute()) {
            $_SESSION['notification'] = [
                'type' => 'error',
                'message' => 'Erreur lors de l\'enregistrement de la demande : ' . $stmt->error
            ];
            header('Location: dashboard_groupe.php');
            exit;
        }
        $stmt->close();

        // Supprimer les anciens échantillons
        $conn->query("DELETE FROM DemandeEchantillon WHERE idDemande = $idDemande");
    } else {
        // AJOUT
        $stmt = $conn->prepare("INSERT INTO Demande (idUtilisateur, TypeDemande, DateDemande, Statut, Qte, Commentaire) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssis", $idUtilisateur, $typeDemande, $dateDemande, $statut, $qte, $commentaire);
        if (!$stmt->execute()) {
            $_SESSION['notification'] = [
                'type' => 'error',
                'message' => 'Erreur lors de l\'enregistrement de la demande : ' . $stmt->error
            ];
            header('Location: dashboard_groupe.php');
            exit;
        }
        $idDemande = $stmt->insert_id;
        $stmt->close();
    }

    // (Commun aux deux cas)
    if (!empty($_POST['echantillons_json'])) {
        $echantillons = json_decode($_POST['echantillons_json'], true);
        if (empty($echantillons)) {
            $_SESSION['notification'] = [
                'type' => 'error',
                'message' => 'Vous devez ajouter au moins un échantillon à la demande.'
            ];
            header('Location: dashboard_groupe.php');
            exit;
        }
        
        // Préparer les détails pour l'historique et vérifier la disponibilité
        $details = [];
        $refs = [];
        foreach ($echantillons as $e) {
            $ref = isset($e['ref']) ? $e['ref'] : (isset($e['RefEchantillon']) ? $e['RefEchantillon'] : null);
            $famille = $e['famille'];
            $couleur = $e['couleur'];
            $qte = 1; // FORCER la quantité à 1
            
            if (empty($ref)) {
                $_SESSION['notification'] = [
                    'type' => 'error',
                    'message' => 'Référence d\'échantillon manquante.'
                ];
                header('Location: dashboard_groupe.php');
                exit;
            }
            
            // Vérifier la disponibilité de l'échantillon
            $resStock = $conn->query("SELECT Qte FROM Echantillon WHERE RefEchantillon = '" . $conn->real_escape_string($ref) . "'");
            if (!$resStock || $resStock->num_rows === 0) {
                $_SESSION['notification'] = [
                    'type' => 'error',
                    'message' => 'Échantillon non trouvé : ' . htmlspecialchars($ref)
                ];
                header('Location: dashboard_groupe.php');
                exit;
            }
            
            $stockRow = $resStock->fetch_assoc();
            $stockDisponible = (int)$stockRow['Qte'];
            
            // Calculer la quantité réellement disponible
            $resEmprunt = $conn->query("
                SELECT SUM(de.qte) as total
                FROM DemandeEchantillon de
                JOIN Demande d ON d.idDemande = de.idDemande
                WHERE de.refEchantillon = '" . $conn->real_escape_string($ref) . "'
                  AND d.Statut IN ('Approuvée', 'Validée', 'emprunte', 'Prêt pour retrait', 'En fabrication', 'Attente inter-service')
            ");
            $rowEmprunt = $resEmprunt ? $resEmprunt->fetch_assoc() : null;
            $qteEmpruntee = (int)($rowEmprunt['total'] ?? 0);
            
            $resRetour = $conn->query("
                SELECT SUM(re.qte) as total
                FROM RetourEchantillon re
                JOIN Retour r ON r.idRetour = re.idRetour
                WHERE re.RefEchantillon = '" . $conn->real_escape_string($ref) . "'
                  AND r.Statut IN ('Validé', 'Approuvé', 'Retourné')
            ");
            $rowRetour = $resRetour ? $resRetour->fetch_assoc() : null;
            $qteRetournee = (int)($rowRetour['total'] ?? 0);
            
            $qteReellementDisponible = max(0, $stockDisponible - ($qteEmpruntee - $qteRetournee));
            
            if ($qteReellementDisponible < 1) {
                $_SESSION['notification'] = [
                    'type' => 'error',
                    'message' => "Échantillon $ref non disponible. Stock : $stockDisponible, Emprunté : " . ($qteEmpruntee - $qteRetournee) . ", Disponible : $qteReellementDisponible"
                ];
                header('Location: dashboard_groupe.php');
                exit;
            }
            
            $refs[] = $ref;
            $details[] = "$ref (Famille : $famille, Couleur : $couleur)";
            
            $stmt2 = $conn->prepare("INSERT INTO DemandeEchantillon (idDemande, refEchantillon, famille, couleur, qte) VALUES (?, ?, ?, ?, ?)");
            $stmt2->bind_param("isssi", $idDemande, $ref, $famille, $couleur, $qte);
            if (!$stmt2->execute()) {
                $_SESSION['notification'] = [
                    'type' => 'error',
                    'message' => 'Erreur lors de l\'insertion d\'un échantillon : ' . $stmt2->error
                ];
                header('Location: dashboard_groupe.php');
                exit;
            }
            $stmt2->close();
        }
        
        // Ajouter à l'historique
        $detailsString = implode(', ', $details);
        $refsString = implode(', ', $refs);
        $actionType = !empty($_POST['edit_demande_id']) ? 'Modification' : 'Demande';
        $description = "M. {$currentUser['Prenom']} {$currentUser['Nom']} a " . (!empty($_POST['edit_demande_id']) ? 'modifié' : 'créé') . " une demande pour $detailsString.";
        ajouterHistorique($conn, $idUtilisateur, $refsString, $actionType, $description);
    }

    $_SESSION['notification'] = [
        'type' => 'success',
        'message' => !empty($_POST['edit_demande_id']) ? 'Demande modifiée avec succès !' : 'Demande envoyée avec succès !'
    ];
    header('Location: dashboard_groupe.php');
    exit;
}

if (isset($_POST['delete_demande_id'])) {
    $id = intval($_POST['delete_demande_id']);
    // Vérifier le statut actuel
    $res = $conn->query("SELECT Statut FROM Demande WHERE idDemande = $id");
    $row = $res->fetch_assoc();
    if (!$row || $row['Statut'] !== 'En attente') {
        $_SESSION['notification'] = [
            'type' => 'error',
            'message' => 'Impossible de supprimer une demande qui n\'est plus en attente.'
        ];
        header('Location: dashboard_groupe.php');
        exit;
    }
    
    // Récupérer les détails de la demande avant suppression pour l'historique
    $details = [];
    $refs = [];
    $resEch = $conn->query("SELECT refEchantillon, famille, couleur, qte FROM DemandeEchantillon WHERE idDemande = $id");
    while ($e = $resEch->fetch_assoc()) {
        $ref = $e['refEchantillon'];
        $famille = $e['famille'];
        $couleur = $e['couleur'];
        $qte = $e['qte'];
        $refs[] = $ref;
        $details[] = "$ref (Qté : $qte, Famille : $famille, Couleur : $couleur)";
    }
    
    $conn->query("DELETE FROM DemandeEchantillon WHERE idDemande = $id");
    $conn->query("DELETE FROM Demande WHERE idDemande = $id");
    
    // Ajouter à l'historique
    if (!empty($details)) {
        $detailsString = implode(', ', $details);
        $refsString = implode(', ', $refs);
        $description = "M. {$currentUser['Prenom']} {$currentUser['Nom']} a supprimé une demande pour $detailsString.";
        ajouterHistorique($conn, $currentUser['id'], $refsString, 'Suppression', $description);
    }
    
    $_SESSION['notification'] = [
        'type' => 'success',
        'message' => 'Demande supprimée avec succès !'
    ];
    header('Location: dashboard_groupe.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['retour'])) {
    $idUtilisateur = $_SESSION['user']['id'];
    $dateRetour = date('Y-m-d H:i:s', strtotime('-1 hour'));
    $statut = 'En attente';
    $qte = intval($_POST['qte']);
    $commentaire = $_POST['commentaire'] ?? '';

    if (!empty($_POST['edit_retour_id'])) {
        // Vérifier le statut actuel
        $res = $conn->query("SELECT Statut FROM Retour WHERE idRetour = " . intval($_POST['edit_retour_id']));
        $row = $res->fetch_assoc();
        if (!$row || strtolower(trim($row['Statut'])) !== 'en attente') {
            $_SESSION['notification'] = [
                'type' => 'error',
                'message' => 'Impossible de modifier un retour qui n\'est plus en attente.'
            ];
            header('Location: dashboard_groupe.php');
            exit;
        }
        // MODIFICATION
        $idRetour = intval($_POST['edit_retour_id']);
        $stmt = $conn->prepare("UPDATE Retour SET Qte=?, Commentaire=?, DateRetour=?, Statut=? WHERE idRetour=?");
        $stmt->bind_param("isssi", $qte, $commentaire, $dateRetour, $statut, $idRetour);
        $stmt->execute();
        $stmt->close();
        // Supprimer les anciens échantillons
        $conn->query("DELETE FROM RetourEchantillon WHERE idRetour = $idRetour");
    } else {
        // AJOUT
        $idDemande = intval($_POST['idDemande']);
        $stmt = $conn->prepare("INSERT INTO Retour (idDemande, idUtilisateur, DateRetour, Statut, qte, Commentaire) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iissis", $idDemande, $idUtilisateur, $dateRetour, $statut, $qte, $commentaire);
        $stmt->execute();
        $idRetour = $stmt->insert_id;
        $stmt->close();
    }
    // (Commun aux deux cas)
    if (!empty($_POST['echantillons_json'])) {
        $echantillons = json_decode($_POST['echantillons_json'], true);
        
        // Préparer les détails pour l'historique
        $details = [];
        $refs = [];
        foreach ($echantillons as $e) {
            $ref = isset($e['ref']) ? $e['ref'] : (isset($e['RefEchantillon']) ? $e['RefEchantillon'] : null);
            if (empty($ref)) continue; // Ignore les entrées vides
            
            $famille = $e['famille'];
            $couleur = $e['couleur'];
            $qte = $e['qte'];
            $refs[] = $ref;
            $details[] = "$ref (Qté : $qte, Famille : $famille, Couleur : $couleur)";
            
            $stmt2 = $conn->prepare("INSERT INTO RetourEchantillon (idRetour, RefEchantillon, famille, couleur, qte) VALUES (?, ?, ?, ?, ?)");
            $stmt2->bind_param("isssi", $idRetour, $ref, $famille, $couleur, $qte);
            $stmt2->execute();
            $stmt2->close();
        }
        
        // Ajouter à l'historique
        if (!empty($details)) {
            $detailsString = implode(', ', $details);
            $refsString = implode(', ', $refs);
            $actionType = !empty($_POST['edit_retour_id']) ? 'Modification' : 'Retour';
            $description = "M. {$currentUser['Prenom']} {$currentUser['Nom']} a " . (!empty($_POST['edit_retour_id']) ? 'modifié' : 'créé') . " un retour pour $detailsString.";
            ajouterHistorique($conn, $idUtilisateur, $refsString, $actionType, $description);
        }
    }
    $_SESSION['notification'] = [
        'type' => 'success',
        'message' => !empty($_POST['edit_retour_id']) ? 'Retour modifié avec succès !' : 'Retour envoyé avec succès !'
    ];
    header('Location: dashboard_groupe.php');
    exit;
}

if (isset($_POST['delete_retour_id'])) {
    $id = intval($_POST['delete_retour_id']);
    // Vérifier le statut actuel
    $res = $conn->query("SELECT Statut FROM Retour WHERE idRetour = $id");
    $row = $res->fetch_assoc();
    if (!$row || $row['Statut'] !== 'En attente') {
        $_SESSION['notification'] = [
            'type' => 'error',
            'message' => 'Impossible de supprimer un retour qui n\'est plus en attente.'
        ];
        header('Location: dashboard_groupe.php');
        exit;
    }
    
    // Récupérer les détails du retour avant suppression pour l'historique
    $details = [];
    $refs = [];
    $resEch = $conn->query("SELECT RefEchantillon, famille, couleur, qte FROM RetourEchantillon WHERE idRetour = $id");
    while ($e = $resEch->fetch_assoc()) {
        $ref = $e['RefEchantillon'];
        $famille = $e['famille'];
        $couleur = $e['couleur'];
        $qte = $e['qte'];
        $refs[] = $ref;
        $details[] = "$ref (Qté : $qte, Famille : $famille, Couleur : $couleur)";
    }
    
    $conn->query("DELETE FROM RetourEchantillon WHERE idRetour = $id");
    $conn->query("DELETE FROM Retour WHERE idRetour = $id");
    
    // Ajouter à l'historique
    if (!empty($details)) {
        $detailsString = implode(', ', $details);
        $refsString = implode(', ', $refs);
        $description = "M. {$currentUser['Prenom']} {$currentUser['Nom']} a supprimé un retour pour $detailsString.";
        ajouterHistorique($conn, $currentUser['id'], $refsString, 'Suppression', $description);
    }
    
    $_SESSION['notification'] = [
        'type' => 'success',
        'message' => 'Retour supprimé avec succès !'
    ];
    header('Location: dashboard_groupe.php');
    exit;
}

// Endpoint pour l'historique des actions JavaScript
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_historique'])) {
    $idUtilisateur = $_SESSION['user']['id'];
    $typeAction = $_POST['type_action'] ?? '';
    $refEchantillon = $_POST['ref_echantillon'] ?? '';
    $description = $_POST['description'] ?? '';
    
    if (!empty($typeAction) && !empty($description)) {
        ajouterHistorique($conn, $idUtilisateur, $refEchantillon, $typeAction, $description);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Données manquantes']);
    }
    exit;
}

// ... existing code ...
// if ($_SERVER['REQUEST_METHOD'] === 'POST') {
//     echo '<pre>';
//     print_r($_POST);
//     echo '</pre>';
//     exit;
// }
// ... existing code ...
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Chef de Groupe</title>
    <link rel="icon" type="image/png" href="photos/logo.png">
    <link rel="shortcut icon" type="image/png" href="photos/logo.png">
    <link rel="apple-touch-icon" href="photos/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Inter', sans-serif;
        }
        
        .glassmorphism {
            background: rgba(255, 255, 255, 0.25);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.18);
        }
        
        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .card-hover {
            transition: all 0.3s ease;
        }
        
        .card-hover:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.4);
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
            animation: fadeIn 0.5s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
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
        
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .modal {
            backdrop-filter: blur(5px);
        }
        
        .chart-container {
            position: relative;
            height: 400px;
            margin: 20px 0;
        }
        
        .search-highlight {
            background: rgba(255, 255, 0, 0.3);
            padding: 2px 4px;
            border-radius: 2px;
        }
        
        .pulse {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: .5; }
        }
        
        .role-chef_stock { 
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }
        
        .role-chef_groupe { 
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }
        
        .role-reception { 
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        }

        /* Additional styles from dashboard_groupe.php */
        .badge { display: inline-block; padding: 0.25em 0.75em; border-radius: 9999px; font-size: 0.85em; font-weight: 500; }
        .badge-demande { background: #dbeafe; color: #1e40af; }
        .badge-retour { background: #e0e7ff; color: #3730a3; }
        .badge-valide { background-color: #dcfce7; color: #166534; }
        .badge-attente { background-color: #fef9c3; color: #854d0e; }
        .badge-refuse { background-color: #fee2e2; color: #991b1b; }

        #notificationsContainer {
            position: fixed;
            top: 1.5rem;
            right: 1.5rem;
            z-index: 9999;
            width: 100%;
            max-width: 24rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        
        .notification {
            transition: all 0.5s ease-in-out;
            animation: slideInRight 0.5s forwards;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideOutRight {
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }

        /* Ajoute ce style CSS pour enlever la sélection noire sur le select multiple */
        #echantillonRetourSelect:disabled {
            background-color: #f3f4f6 !important;
            color: #6b7280 !important;
            border-color: #d1d5db !important;
        }
        #echantillonRetourSelect option:checked {
            background: #e5e7eb !important;
            color: #111827 !important;
        }
        #echantillonRetourSelect:focus {
            outline: none !important;
            box-shadow: none !important;
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
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
                        <strong>Bienvenue sur le tableau de bord Chef de Groupe !</strong>
                    </p>
                    <p class="text-sm">
                        Ce tableau de bord vous permet de gérer les demandes d'échantillons pour votre groupe. Vous pouvez :
                    </p>
                    <ul class="list-disc list-inside text-sm space-y-2 ml-2">
                        <li><strong>Créer</strong> de nouvelles demandes d'échantillons</li>
                        <li><strong>Suivre</strong> l'état de vos demandes (en attente, approuvée, rejetée)</li>
                        <li><strong>Gérer les retours</strong> d'échantillons empruntés</li>
                        <li><strong>Consulter</strong> l'historique de vos opérations</li>
                        <li><strong>Visualiser</strong> les échantillons disponibles en stock</li>
                    </ul>
                    <p class="text-sm mt-4 text-gray-600">
                        <i class="fas fa-users mr-1"></i>
                        En tant que chef de groupe, vous coordonnez les besoins en échantillons de votre équipe.
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

<div id="notificationsContainer"></div>
<?php
// Notification système (succès/erreur)
$notification = null;
if (isset($_SESSION['notification'])) {
    $notification = $_SESSION['notification'];
    unset($_SESSION['notification']);
}
?>
<?php if (
    isset(
        $notification['message']
    ) && $notification['message']
): ?>
    <div id="notification" class="fixed top-6 right-6 z-50 px-6 py-4 rounded-lg shadow-lg text-white <?php echo ($notification['type'] ?? 'success') === 'success' ? 'bg-green-500' : 'bg-red-500'; ?> notification-slide">
        <div class="flex items-center">
            <i class="fas <?php echo ($notification['type'] ?? 'success') === 'success' ? 'fa-check' : 'fa-exclamation-circle'; ?> mr-3"></i>
            <span><?php echo htmlspecialchars($notification['message']); ?></span>
            <button onclick="this.parentElement.parentElement.remove()" class="ml-auto text-white hover:text-gray-200">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>
<?php endif; ?>
    <!-- Main Application (Dashboard only, no login) -->
    <div id="mainApp">
        <!-- Header -->
        <header class="bg-white shadow-lg border-b border-gray-200">
            <div class="flex items-center justify-between px-6 py-4">
                <div class="flex items-center space-x-4">
                    <img src="photos/logo.png" alt="Logo" class="h-10 w-10 object-contain" />
                    <div>
                        <h1 class="text-2xl font-bold text-gray-800">Gestion d'Échantillons</h1>
                        <p class="text-sm text-gray-600" id="userRole">Chef de Groupe - Tableau de bord</p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-4">
                    <button onclick="openInfoModal()" class="px-3 py-2 bg-blue-100 text-blue-600 rounded-lg hover:bg-blue-200 transition-colors" title="Aide">
                        <i class="fas fa-question-circle"></i>
                    </button>
                    <div class="flex items-center space-x-2">
                        <div id="userAvatar" class="w-8 h-8 role-chef_groupe rounded-full flex items-center justify-center">
                            <i class="fas fa-user text-white text-sm"></i>
                        </div>
                        <div>
                        <p class="font-medium text-gray-800" id="userName"><?php echo htmlspecialchars($currentUser['Nom']); ?></p>
                        <p class="text-sm text-gray-600" id="userRoleText"><?php echo htmlspecialchars($currentUser['Role']); ?></p>
                        </div>
                    </div>
                    
                    <a href="logout.php" id="logoutBtn" class="px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 transition-colors">
                        <i class="fas fa-sign-out-alt mr-2"></i>Déconnexion
                    </a>
                </div>
            </div>
        </header>

        <div class="flex">
            <!-- Sidebar -->
            <aside class="sidebar w-64 min-h-screen">
                <nav class="py-6">
                    <div id="sidebarMenu" class="space-y-2 px-4">
                        <!-- Menu items will be dynamically generated -->
                    </div>
                </nav>
            </aside>

            <!-- Main Content -->
            <main class="flex-1 p-6 bg-gray-50">
                <!-- Dashboard Section -->
                <section id="dashboardSection" class="main-section space-y-6">
                    <!-- Titre et Cartes des Demandes -->
                    <div class="space-y-4">
                        <h2 class="text-2xl font-bold text-gray-800 flex items-center">
                            <i class="fas fa-paper-plane text-indigo-600 mr-3"></i>
                            Gestion des Demandes
                        </h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                            <div class="card-hover bg-white p-6 rounded-lg shadow-md">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-sm text-gray-600">Total Demandes</p>
                                        <p class="text-2xl font-bold text-gray-800" id="totalDemandes">0</p>
                    </div>
                                    <i class="fas fa-paper-plane text-3xl text-indigo-600"></i>
                                </div>
                            </div>
                            <div class="card-hover bg-white p-6 rounded-lg shadow-md">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-sm text-gray-600">Demandes en Attente</p>
                                        <p class="text-2xl font-bold text-yellow-600" id="demandesAttente">0</p>
                                    </div>
                                    <i class="fas fa-clock text-3xl text-yellow-600"></i>
                                </div>
                            </div>
                            <div class="card-hover bg-white p-6 rounded-lg shadow-md">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-sm text-gray-600">Demandes Validées</p>
                                        <p class="text-2xl font-bold text-green-600" id="demandesValidees">0</p>
                                    </div>
                                    <i class="fas fa-check-circle text-3xl text-green-600"></i>
                                </div>
                            </div>
                            <div class="card-hover bg-white p-6 rounded-lg shadow-md">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-sm text-gray-600">Demandes Refusées</p>
                                        <p class="text-2xl font-bold text-red-600" id="demandesRefusees">0</p>
                                    </div>
                                    <i class="fas fa-times-circle text-3xl text-red-600"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Titre et Cartes des Retours -->
                    <div class="space-y-4">
                        <h2 class="text-2xl font-bold text-gray-800 flex items-center">
                            <i class="fas fa-undo text-indigo-600 mr-3"></i>
                            Gestion des Retours
                        </h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                            <div class="card-hover bg-white p-6 rounded-lg shadow-md">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-sm text-gray-600">Total Retours</p>
                                        <p class="text-2xl font-bold text-gray-800" id="totalRetours">0</p>
                                    </div>
                                    <i class="fas fa-undo text-3xl text-indigo-600"></i>
                                </div>
                            </div>
                            <div class="card-hover bg-white p-6 rounded-lg shadow-md">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-sm text-gray-600">Retours en Attente</p>
                                        <p class="text-2xl font-bold text-yellow-600" id="retoursAttente">0</p>
                                    </div>
                                    <i class="fas fa-clock text-3xl text-yellow-600"></i>
                                </div>
                            </div>
                            <div class="card-hover bg-white p-6 rounded-lg shadow-md">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-sm text-gray-600">Retours Validés</p>
                                        <p class="text-2xl font-bold text-green-600" id="retoursValides">0</p>
                                    </div>
                                    <i class="fas fa-check-circle text-3xl text-green-600"></i>
                                </div>
                            </div>
                            <div class="card-hover bg-white p-6 rounded-lg shadow-md">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-sm text-gray-600">Retours Refusés</p>
                                        <p class="text-2xl font-bold text-red-600" id="retoursRefuses">0</p>
                                    </div>
                                    <i class="fas fa-times-circle text-3xl text-red-600"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Diagramme et Activité Récente -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div class="bg-white p-6 rounded-lg shadow-md">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Répartition des Demandes</h3>
                            <div class="chart-container" style="height: 400px;">
                                <canvas id="demandesChart"></canvas>
                            </div>
                        </div>
                        <div class="bg-white p-6 rounded-lg shadow-md">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Activité Récente</h3>
                            <div id="recentActivity" class="space-y-3">
                                <!-- Recent activity will be populated here -->
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Demandes Section -->
                <section id="demandesSection" class="main-section hidden space-y-6">
                    <div class="bg-white p-6 rounded-lg shadow-md">
                        <div class="flex items-center justify-between mb-6"><h2 class="text-xl font-semibold text-gray-800">Gestion des Demandes</h2><button onclick="openModal('demande')" class="bg-blue-600 text-white px-4 py-2 rounded-lg font-medium hover:bg-blue-700"><i class="fas fa-plus mr-2"></i>Nouvelle Demande</button></div>
                        <div class="mb-4 flex flex-wrap gap-4">
                            <input type="text" id="searchDemande" class="flex-1 min-w-64 px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" placeholder="Rechercher par référence, famille...">
                            <select id="filterDemandeStatus" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="">Tous les statuts</option>
                                <option value="En attente">En attente</option>
                                <option value="Validée">Validée</option>
                                <option value="Approuvée">Approuvée</option>
                                <option value="Refusée">Refusée</option>
                            </select>
                        </div>
                        <div class="overflow-x-auto mt-4"><table class="w-full table-auto"><thead class="bg-gray-50"><tr><th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Réf</th><th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Famille</th><th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Couleur</th><th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Qte</th><th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th><th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Statut</th><th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Action du Stock</th><th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th></tr></thead><tbody id="demandesTableBody"></tbody></table></div>
                    </div>
                </section>
                
                <!-- Retours Section -->
                <section id="retoursSection" class="main-section hidden space-y-6">
                    <div class="bg-white p-6 rounded-lg shadow-md">
                        <div class="flex items-center justify-between mb-6"><h2 class="text-xl font-semibold text-gray-800">Gestion des Retours</h2><button onclick="openModal('retour')" class="bg-green-600 text-white px-4 py-2 rounded-lg font-medium hover:bg-green-700"><i class="fas fa-undo mr-2"></i>Nouveau Retour</button></div>
                        <div class="mb-4 flex flex-wrap gap-4">
                            <input type="text" id="searchRetour" class="flex-1 min-w-64 px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" placeholder="Rechercher par référence, famille...">
                            <select id="filterRetourStatus" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="">Tous les statuts</option>
                                <option value="En attente">En attente</option>
                                <option value="Validé">Validé</option>
                                <option value="Approuvé">Approuvé</option>
                                <option value="Refusé">Refusé</option>
                            </select>
                        </div>
                        <div class="overflow-x-auto mt-4"><table class="w-full table-auto"><thead class="bg-gray-50"><tr><th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Réf</th><th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Famille</th><th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Couleur</th><th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Qte</th><th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th><th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Statut</th><th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th></tr></thead><tbody id="retoursTableBody"></tbody></table></div>
                    </div>
                </section>

                <!-- History Section -->
                <div id="historiqueSection" class="main-section hidden space-y-6">
                    <div class="bg-white p-6 rounded-lg shadow-md">
                        <h2 class="text-xl font-semibold text-gray-800 mb-6">Historique et Traçabilité</h2>
                        <div class="mb-4 flex flex-wrap gap-4">
                            <input type="date" id="historyDateStart" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <input type="date" id="historyDateEnd" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <select id="historyAction" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="">Toutes les actions</option>
                                <option value="Création">Création</option>
                                <option value="Demande">Demande</option>
                                <option value="Approbation">Approbation</option>
                                <option value="Rejet">Rejet</option>
                                <option value="Fabrication">Fabrication</option>
                                <option value="Retour">Retour</option>
                                <option value="Modification">Modification</option>
                                <option value="Suppression">Suppression</option>
                            </select>
                            <select id="historyUser" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="">Tous les utilisateurs</option>
                                <option value="Chef Groupe">Chef Groupe</option>
                                <option value="Réception">Réception</option>
                            </select>
                            <input type="text" id="historySearch" class="flex-1 min-w-64 px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" placeholder="Rechercher dans l'historique...">
                        </div>
                        <div class="overflow-x-auto">
                            <div style="max-height: 400px; overflow-y: auto;">
                                <table class="w-full table-auto">
                                    <thead>
                                        <tr class="bg-gray-50">
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Utilisateur</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Action</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Échantillon</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                                        </tr>
                                    </thead>
                                    <tbody id="historiqueTableBody">
                                        <!-- Table content will be populated here -->
                                    </tbody>
                                </table>
                    </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modals -->
    <div id="demandeModal" class="fixed inset-0 bg-black bg-opacity-50 modal hidden flex items-center justify-center z-50 overflow-y-auto py-8">
        <div class="bg-white rounded-lg shadow-2xl w-full max-w-2xl m-4 fade-in max-h-[90vh] flex flex-col">
            <div class="flex items-center justify-between p-6 border-b">
                <h3 id="demandeModalTitle" class="text-lg font-semibold">Nouvelle Demande</h3>
                <button onclick="closeModal('demande')" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="formDemande" method="POST" onsubmit="return envoyerDemandeServeur();">
                <input type="hidden" name="demande" value="1">
                <input type="hidden" name="qte" id="formQteDemande">
                <input type="hidden" name="commentaire" id="formCommentaireDemande">
                <input type="hidden" name="echantillons_json" id="echantillons_json">
                <input type="hidden" name="edit_demande_id" id="edit_demande_id">
                <div class="p-6 space-y-4 overflow-y-auto flex-1" style="max-height: 80vh;">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Sélectionner un échantillon</label>
                        <input type="text" id="searchEchantillonDemande" placeholder="Rechercher un échantillon..." class="w-full px-3 py-2 mb-2 border rounded">
                        <select id="echantillonSelect" class="w-full px-3 py-2 border rounded focus:ring-2 focus:ring-blue-500">
                            <option value="">Choisir un échantillon...</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Famille</label>
                            <input type="text" id="familleDemande" class="w-full px-3 py-2 border rounded bg-gray-100" readonly>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Couleur</label>
                            <input type="text" id="couleurDemande" class="w-full px-3 py-2 border rounded bg-gray-100" readonly>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Stock disponible</label>
                        <input id="qteDemande" type="number" class="w-full px-3 py-2 border rounded bg-gray-100" readonly value="1">
                    </div>
                    <div class="mt-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Détails de l'échantillon</label>
                        <div id="echantillonDetails" class="bg-gray-50 p-4 rounded">
                            <p class="text-sm text-gray-600">Sélectionnez un échantillon pour voir ses détails</p>
                        </div>
                    </div>
                    <div class="flex justify-end p-6 border-t space-x-3">
                        <button type="button" onclick="addDemandeTemp()" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                            <i class="fas fa-plus mr-2"></i>Ajouter à la demande
                        </button>
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                            <i class="fas fa-save mr-2"></i>Valider la demande
                        </button>
                        <button type="button" onclick="closeModal('demande')" class="px-4 py-2 text-gray-600 border rounded hover:bg-gray-50">
                            <i class="fas fa-times mr-2"></i>Annuler
                        </button>
                    </div>
                    <div class="overflow-x-auto mt-4">
                        <h3 class="text-lg font-semibold text-gray-800 mb-3">Échantillons sélectionnés</h3>
                        <table class="w-full table-auto">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Référence</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Famille</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Couleur</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Quantité</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="demandeTempTableBody">
                                <!-- Les échantillons sélectionnés seront affichés ici -->
                            </tbody>
                        </table>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Commentaire</label>
                        <textarea id="commentaireDemande" name="commentaire" class="w-full px-3 py-2 border rounded" rows="2" placeholder="Ajouter un commentaire..."></textarea>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Retour Modal -->
    <div id="retourModal" class="fixed inset-0 bg-black bg-opacity-50 modal hidden flex items-center justify-center z-50 overflow-y-auto py-8">
        <div class="bg-white rounded-lg shadow-2xl w-full max-w-2xl m-4 fade-in max-h-[90vh] flex flex-col">
            <div class="flex items-center justify-between p-6 border-b">
                <h3 id="retourModalTitle" class="text-lg font-semibold text-green-700">Nouveau Retour</h3>
                <button onclick="closeModal('retour')" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="formRetour" method="POST" onsubmit="return envoyerRetourServeur();">
                <input type="hidden" name="retour" value="1">
                <input type="hidden" name="qte" id="formQteRetour">
                <input type="hidden" name="commentaire" id="formCommentaireRetour">
                <input type="hidden" name="echantillons_json" id="echantillons_retour_json">
                <input type="hidden" name="edit_retour_id" id="edit_retour_id">
                <input type="hidden" name="idDemande" id="idDemandeRetour">
                <div class="p-6 space-y-4 overflow-y-auto flex-1" style="max-height: 80vh;">
                <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Sélectionner une demande</label>
                        <select id="demandeRetourSelect" class="w-full px-3 py-2 border rounded focus:ring-2 focus:ring-green-500">
                            <option value="">Choisir une demande...</option>
                    </select>
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Sélectionner un échantillon</label>
                        <select id="echantillonRetourSelect" class="w-full px-3 py-2 border rounded focus:ring-2 focus:ring-green-500" multiple disabled>
                            <option value="">Choisir un échantillon...</option>
                        </select>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Famille</label>
                            <input type="text" id="familleRetour" class="w-full px-3 py-2 border rounded bg-gray-100" readonly>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Couleur</label>
                            <input type="text" id="couleurRetour" class="w-full px-3 py-2 border rounded bg-gray-100" readonly>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Quantité à retourner</label>
                        <input id="qteRetour" type="number" class="w-full px-3 py-2 border rounded bg-gray-100" min="1" value="1">
                </div>
                <div class="mt-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Détails de l'échantillon</label>
                    <div id="echantillonRetourDetails" class="bg-gray-50 p-4 rounded">
                        <p class="text-sm text-gray-600">Sélectionnez un échantillon pour voir ses détails</p>
                    </div>
                </div>
                <div class="flex justify-end p-6 border-t space-x-3">
                        <button type="button" onclick="addRetourTemp()" class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                        <i class="fas fa-plus mr-2"></i>Ajouter au retour
                    </button>
                        <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                        <i class="fas fa-save mr-2"></i>Valider le retour
                    </button>
                        <button type="button" onclick="closeModal('retour')" class="px-4 py-2 text-gray-600 border rounded hover:bg-gray-50">
                        <i class="fas fa-times mr-2"></i>Annuler
                    </button>
                </div>
                <div class="overflow-x-auto mt-4">
                    <h3 class="text-lg font-semibold text-gray-800 mb-3">Échantillons sélectionnés</h3>
                    <table class="w-full table-auto">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Référence</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Famille</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Couleur</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Quantité</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="retourTempTableBody">
                            <!-- Les échantillons sélectionnés seront affichés ici -->
                        </tbody>
                    </table>
                </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Commentaire</label>
                        <textarea id="commentaireRetour" name="commentaire" class="w-full px-3 py-2 border rounded" rows="2" placeholder="Ajouter un commentaire..."></textarea>
            </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal de confirmation de suppression -->
    <div id="confirmDeleteModal" class="fixed inset-0 bg-black bg-opacity-50 modal hidden flex items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-2xl w-full max-w-md m-4 fade-in">
            <div class="flex items-center justify-between p-6 border-b">
                <h3 class="text-lg font-semibold">Confirmer la suppression</h3>
                <button onclick="closeConfirmDeleteModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-6">
                <p class="text-gray-700 mb-6">Voulez-vous vraiment supprimer cette demande ? Cette action est irréversible.</p>
                <div class="flex justify-end space-x-3">
                    <button onclick="closeConfirmDeleteModal()" class="px-4 py-2 text-gray-600 border rounded hover:bg-gray-50">
                        Annuler
                    </button>
                    <button id="confirmDeleteBtn" class="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700">
                        Supprimer
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="confirmDeleteRetourModal" class="fixed inset-0 bg-black bg-opacity-50 modal hidden flex items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-2xl w-full max-w-md m-4 fade-in">
            <div class="flex items-center justify-between p-6 border-b">
                <h3 class="text-lg font-semibold">Confirmer la suppression du retour</h3>
                <button onclick="closeConfirmDeleteRetourModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-6">
                <p class="text-gray-700 mb-6">Voulez-vous vraiment supprimer ce retour ? Cette action est irréversible.</p>
                <div class="flex justify-end space-x-3">
                    <button onclick="closeConfirmDeleteRetourModal()" class="px-4 py-2 text-gray-600 border rounded hover:bg-gray-50">
                        Annuler
                    </button>
                    <button id="confirmDeleteRetourBtn" class="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700">
                        Supprimer
                    </button>
                </div>
            </div>
        </div>
    </div>

<script>
let echantillons = <?php echo json_encode($echantillons); ?>;
let App = {
    currentUser: <?php echo json_encode($currentUser); ?>,
    demandes: <?php echo json_encode($demandes); ?>,
    retours: <?php echo json_encode($retours); ?>,
    nextDemandeId: 1,
    nextRetourId: 1,
    familles: ['Tissu', 'Cuir', 'Plastique', 'Metal'],
    couleurs: ['Rouge', 'Noir', 'Bleu', 'Vert', 'Marron', 'Blanc']
};



document.addEventListener('DOMContentLoaded', () => {
    // --- App State & Data ---
    // --- Utils ---
    const formatDate = (ds) => {
        const d = new Date(ds);
        return d.toLocaleDateString('fr-FR', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
    };
    const getBadgeClass = (status) => ({ 'Validée': 'badge-valide', 'En attente': 'badge-attente', 'Refusée': 'badge-refuse', 'Retourné': 'badge-retour' }[status] || 'bg-gray-200');

    // --- Navigation ---
    const sections = {
        dashboard: { title: 'Tableau de bord', icon: 'fa-tachometer-alt', label: 'Tableau de bord' },
        demandes:  { title: 'Gestion des Demandes', icon: 'fa-paper-plane', label: 'Demandes' },
        retours:   { title: 'Gestion des Retours', icon: 'fa-undo', label: 'Retours' },
        historique:{ title: 'Historique et Traçabilité', icon: 'fa-history', label: 'Historique' },
    };
    const navigate = (key) => {
        document.querySelectorAll('.main-section').forEach(s => s.classList.add('hidden'));
        document.getElementById(`${key}Section`).classList.remove('hidden', 'active'); // ensure clean state
        void document.getElementById(`${key}Section`).offsetWidth; // trigger reflow
        document.getElementById(`${key}Section`).classList.add('active');

        document.querySelectorAll('.sidebar-item').forEach(i => i.classList.remove('active'));
        document.querySelector(`.sidebar-item[data-section="${key}"]`).classList.add('active');
        // document.getElementById('pageTitle').textContent = sections[key].title; // This line was causing the error
        

    };

    // --- Rendering ---
    const renderSidebar = () => {
        document.getElementById('sidebarMenu').innerHTML = Object.keys(sections).map(key =>
            `<button class="sidebar-item w-full flex items-center px-4 py-3 text-left text-white rounded-lg" data-section="${key}">
                <i class="fas ${sections[key].icon} w-6 text-center mr-3"></i><span>${sections[key].label}</span>
            </button>`
        ).join('');
        document.querySelectorAll('.sidebar-item').forEach(b => b.onclick = () => navigate(b.dataset.section));
    };

    const renderDashboard = () => {
        
        // Vérifier si les données existent
        if (!App.demandes || App.demandes.length === 0) {
            console.log('AUCUNE DEMANDE TROUVÉE');
            document.getElementById('totalDemandes').textContent = '0';
            document.getElementById('demandesValidees').textContent = '0';
            document.getElementById('demandesAttente').textContent = '0';
            document.getElementById('demandesRefusees').textContent = '0';
        } else {
            // Statistiques des Demandes
        document.getElementById('totalDemandes').textContent = App.demandes.length;
            
            // Calcul des demandes validées (incluant les statuts spéciaux)
            const demandesValidees = App.demandes.filter(d => {
                const statut = d.Statut.toLowerCase();
                return statut === 'validée' || statut === 'approuvée' || 
                       statut === 'prêt pour retrait' || statut === 'en fabrication' || 
                       statut === 'attente inter-service';
            }).length;
            
            // Debug des statistiques
            console.log('=== DEBUG STATISTIQUES ===');
            console.log('Total demandes:', App.demandes.length);
            console.log('Demandes validées calculées:', demandesValidees);
            console.log('Détail des statuts:', App.demandes.map(d => ({ id: d.id, statut: d.Statut })));
            
            document.getElementById('demandesValidees').textContent = demandesValidees;
            
            document.getElementById('demandesAttente').textContent = App.demandes.filter(d => d.Statut === 'En attente').length;
            document.getElementById('demandesRefusees').textContent = App.demandes.filter(d => d.Statut === 'Refusée').length;
        }
        
        // Statistiques des Retours
        document.getElementById('totalRetours').textContent = App.retours.length;
        document.getElementById('retoursAttente').textContent = App.retours.filter(r => r.Statut === 'En attente').length;
        document.getElementById('retoursValides').textContent = App.retours.filter(r => r.Statut === 'Validé' || r.Statut === 'Approuvé').length;
        document.getElementById('retoursRefuses').textContent = App.retours.filter(r => r.Statut === 'Refusé').length;
        
        // Utiliser les données historiques de la base de données
        const activity = historiques.slice(0, 5).map(h => {
            let dateStr = '';
            if (h.DateAction) {
                let iso = h.DateAction.replace(' ', 'T');
                if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(iso)) {
                    iso += ':00';
                }
                // Traiter la date comme locale et soustraire 1 heure
                const date = new Date(iso);
                if (!isNaN(date)) {
                    // Soustraction d'1 heure
                    date.setHours(date.getHours() - 1);
                    dateStr = date.toLocaleDateString('fr-FR', {
                        day: '2-digit',
                        month: 'short',
                        year: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                } else {
                    dateStr = h.DateAction;
                }
            }
            const userName = (h.Prenom || h.Nom) ? `${h.Prenom || ''} ${h.Nom || ''}`.trim() : 'Inconnu';
            return `<div class="flex items-center space-x-3 p-2 bg-gray-50 rounded-lg">
                <div class="w-8 h-8 bg-gray-200 rounded-full flex items-center justify-center"><i class="fas fa-history text-gray-500"></i></div>
                <div><p class="text-sm font-medium">${h.TypeAction} - ${h.RefEchantillon}</p><p class="text-xs text-gray-500">${userName} | ${dateStr}</p></div>
            </div>`;
        }).join('');
        document.getElementById('recentActivity').innerHTML = activity;

        const ctx = document.getElementById('demandesChart').getContext('2d');
        if(window.chart) window.chart.destroy();
        // Calcul des données pour le graphique
        const demandesEnAttente = App.demandes.filter(d => d.Statut === 'En attente').length;
        const demandesValidees = App.demandes.filter(d => {
            const statut = d.Statut.toLowerCase();
            return statut === 'validée' || statut === 'approuvée' || 
                   statut === 'prêt pour retrait' || statut === 'en fabrication' || 
                   statut === 'attente inter-service';
        }).length;
        const demandesRefusees = App.demandes.filter(d => d.Statut === 'Refusée').length;
        
        window.chart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['En attente', 'Validée', 'Refusée'],
                datasets: [{
                    data: [demandesEnAttente, demandesValidees, demandesRefusees],
                    backgroundColor: ['#f59e0b', '#10b981', '#ef4444'],
                    borderWidth: 0
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
        });
    };

    const renderDemandes = () => {
        const query = document.getElementById('searchDemande').value.toLowerCase();
        const statusFilter = document.getElementById('filterDemandeStatus').value;
        
        // Debug temporaire
        console.log('=== DEBUG RENDER DEMANDES ===');
        console.log('Query:', query);
        console.log('StatusFilter:', statusFilter);
        console.log('App.demandes:', App.demandes);
        if (App.demandes && App.demandes.length > 0) {
            console.log('Statuts des demandes:', App.demandes.map(d => ({ id: d.id, statut: d.Statut })));
        }
        
        const filtered = App.demandes.filter(d => {
            // Filtre par statut
            if (statusFilter) {
                if (statusFilter === 'Validée') {
                    // Pour "Validée", inclure aussi les statuts spéciaux
                    const statut = d.Statut.toLowerCase();
                    if (!(statut === 'validée' || statut === 'approuvée' || 
                          statut === 'prêt pour retrait' || statut === 'en fabrication' || 
                          statut === 'attente inter-service')) {
                        return false;
                    }
                } else if (d.Statut !== statusFilter) {
                    return false;
                }
            }
            
            // Filtre par recherche
            if (!query) return true; // Si pas de recherche, afficher tout
            
            // Rechercher dans tous les champs des échantillons
            const searchText = d.echantillons.map(e => 
                `${e.ref || ''} ${e.famille || ''} ${e.couleur || ''} ${e.qte || ''}`
            ).join(' ').toLowerCase();
            
            // Rechercher aussi dans le statut et la date
            const demandeInfo = `${d.Statut || ''} ${d.date || ''}`.toLowerCase();
            
            return searchText.includes(query) || demandeInfo.includes(query);
        });
        
        console.log('Demandes filtrées:', filtered.length);
        console.log('=== FIN DEBUG ===');

        document.getElementById('demandesTableBody').innerHTML = filtered.map(d => {
            let statutPrincipal = d.Statut;
            let actionStock = '';
            const specialStatus = ['prêt pour retrait', 'en fabrication', 'attente inter-service'];

            if (specialStatus.includes(statutPrincipal.toLowerCase())) {
                actionStock = d.Statut;
                statutPrincipal = 'Validée'; // On considère que c'est un sous-statut de 'Validée'
            } else if (statutPrincipal.toLowerCase() === 'approuvée') {
                statutPrincipal = 'Validée';
            }

            return `<tr class="hover:bg-gray-50 border-b border-gray-200">
                <td class="px-4 py-3 align-top">${d.echantillons.map(e => `<div>${e.ref}</div>`).join('')}</td>
                <td class="px-4 py-3 align-top">${d.echantillons.map(e => `<div>${e.famille}</div>`).join('')}</td>
                <td class="px-4 py-3 align-top">${d.echantillons.map(e => `<div>${e.couleur}</div>`).join('')}</td>
                <td class="px-4 py-3 align-top">${d.echantillons.map(e => `<div>${e.qte}</div>`).join('')}</td>
                <td class="px-4 py-3 align-top">${formatDate(d.date)}</td>
                <td class="px-4 py-3 align-top"><span class="badge ${getBadgeClass(statutPrincipal)}">${statutPrincipal}</span></td>
                <td class="px-4 py-3 align-top">${actionStock}</td>
                <td class="px-4 py-3 align-top">
                    ${d.Statut && d.Statut.trim().toLowerCase() === 'en attente' ? `
                        <button onclick="editDemande(${d.id})" class="text-indigo-600 hover:text-indigo-900 transition-colors">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button 
                            onclick="openConfirmDeleteModal(${d.id})" 
                            class="text-red-600 hover:text-red-900 ml-2 transition-colors">
                            <i class="fas fa-trash"></i>
                        </button>
                    ` : ''}
                </td>
            </tr>`
        }).join('');
    };

    const renderRetours = () => {
        const query = document.getElementById('searchRetour').value.toLowerCase();
        const statusFilter = document.getElementById('filterRetourStatus').value;
        let rows = [];
        
        // Filtre par statut d'abord
        const filteredRetours = App.retours.filter(r => {
            if (statusFilter && r.Statut !== statusFilter) return false;
            return true;
        });
        
        filteredRetours.forEach(r => {
            if (!query) {
                // Si pas de recherche, afficher tous les échantillons
                const refs = r.echantillons.map(e => e.RefEchantillon || e.refEchantillon || e.ref || '').join('<br>');
                const familles = r.echantillons.map(e => e.famille || '').join('<br>');
                const couleurs = r.echantillons.map(e => e.couleur || '').join('<br>');
                const qtes = r.echantillons.map(e => e.qte || '').join('<br>');
                rows.push({
                    ref: refs,
                    famille: familles,
                    couleur: couleurs,
                    qte: qtes,
                    date: r.DateRetour,
                    statut: r.Statut,
                    id: r.idRetour
                });
                return;
            }
            
            // Filtrer les échantillons selon la recherche
            const filteredEch = r.echantillons.filter(e => {
                const searchText = `${e.RefEchantillon || e.refEchantillon || e.ref || ''} ${e.famille || ''} ${e.couleur || ''} ${e.qte || ''}`.toLowerCase();
                return searchText.includes(query);
            });
            
            if (filteredEch.length === 0) return;
            // Concatène les champs de tous les échantillons du retour, sautant une ligne entre chaque
            const refs = filteredEch.map(e => e.RefEchantillon || e.refEchantillon || e.ref || '').join('<br>');
            const familles = filteredEch.map(e => e.famille).join('<br>');
            const couleurs = filteredEch.map(e => e.couleur).join('<br>');
            const qtes = filteredEch.map(e => e.qte).join('<br>');
            rows.push({
                ref: refs,
                famille: familles,
                couleur: couleurs,
                qte: qtes,
                date: r.DateRetour,
                statut: r.Statut,
                id: r.idRetour
            });
        });
        document.getElementById('retoursTableBody').innerHTML = rows.map(r =>
            `<tr class="hover:bg-gray-50">
                <td class="px-4 py-3">${r.ref}</td>
                <td>${r.famille}</td>
                <td>${r.couleur}</td>
                <td>${r.qte}</td>
                <td>${formatDate(r.date)}</td>
                <td><span class="badge ${getBadgeClass(r.statut)}">${r.statut}</span></td>
                <td>
                    <button onclick="editRetour(${r.id})" class="text-indigo-600 hover:text-indigo-900"><i class="fas fa-edit"></i></button>
                    <button onclick="openConfirmDeleteRetourModal(${r.id})" class="text-red-600 hover:text-red-900 ml-2"><i class="fas fa-trash"></i></button>
                </td>
            </tr>`
        ).join('');
    };


    
    // --- Modals & CRUD ---
    window.openModal = (type, id = null) => {
        let modalId = type.endsWith('Modal') ? type : type + 'Modal';
        if (modalId === 'retourModal' && !id) {
            // On charge les échantillons validés SEULEMENT si ce n'est PAS une édition
            loadUserValidatedSamples();
        }
        const modalElem = document.getElementById(modalId);
        if (!modalElem) {
            alert('Erreur technique : modale introuvable (' + modalId + ')');
            return;
        }
        modalElem.classList.remove('hidden');
        


        if (type === 'demande') {
            loadAvailableSamples();
            // Remplir les listes déroulantes
            const familleSelect = document.getElementById('familleDemande');
            const couleurSelect = document.getElementById('couleurDemande');
            
            familleSelect.innerHTML = '<option value="">Choisir une famille...</option>' + 
                App.familles.map(f => `<option value="${f}">${f}</option>`).join('');
            
            couleurSelect.innerHTML = '<option value="">Choisir une couleur...</option>' + 
                App.couleurs.map(c => `<option value="${c}">${c}</option>`).join('');

            // Initialiser la quantité à 1
            document.getElementById('qteDemande').value = 1;

            if (id) {
                const demande = App.demandes.find(d => String(d.id) === String(id));
                if (demande) {
                    document.getElementById('refDemande').value = demande.ref;
                    document.getElementById('familleDemande').value = demande.famille;
                    document.getElementById('couleurDemande').value = demande.couleur;
                    document.getElementById('qteDemande').value = demande.qte;
                }
            }
        } else if (type === 'retour') {
            // SUPPRIME : loadUserBorrowedSamples();
            // Pour les retours, montrer seulement les échantillons que l'utilisateur a
            const familleSelect = document.getElementById('familleRetour');
            const couleurSelect = document.getElementById('couleurRetour');
            
            familleSelect.innerHTML = '<option value="">Choisir une famille...</option>' + 
                App.familles.map(f => `<option value="${f}">${f}</option>`).join('');
            
            couleurSelect.innerHTML = '<option value="">Choisir une couleur...</option>' + 
                App.couleurs.map(c => `<option value="${c}">${c}</option>`).join('');

            // Initialiser la quantité à 1
            document.getElementById('qteRetour').value = 1;

            if (id) {
                const retour = App.retours.find(r => String(r.id) === String(id));
                if (retour) {
                    document.getElementById('refRetour').value = retour.ref;
                    document.getElementById('familleRetour').value = retour.famille;
                    document.getElementById('couleurRetour').value = retour.couleur;
                    document.getElementById('qteRetour').value = retour.qte;
                }
            }
        }
    };
    window.closeModal = (type) => {
        const modalId = `${type}Modal`;
        const modalElem = document.getElementById(modalId);
        if (!modalElem) return;
        modalElem.classList.add('hidden');
        if (type === 'demande') resetDemandeModal();
        if (type === 'retour') resetRetourModal();
    };
    

    
    let editDemandeId = null;
    let editRetourId = null;

    window.saveDemande = () => {
        if (demandeTemp.length === 0) { 
            alert('Ajoutez au moins un échantillon'); 
            return; 
        }

        if (editDemandeId !== null) {
            // Mode édition : remplacer la demande existante
            App.demandes = App.demandes.filter(d => d.id !== editDemandeId);
            
            const newDemande = {
                id: editDemandeId,
                echantillons: demandeTemp.map(item => ({
                    ref: item.ref,
                    famille: item.famille,
                    couleur: item.couleur,
                    qte: item.qte
                })),
                date: new Date().toISOString(),
                statut: 'En attente'
            };
            
            App.demandes.unshift(newDemande);
            

            
            editDemandeId = null;
        } else {
            // Nouvelle demande
            const newDemande = {
                id: App.nextDemandeId++,
                echantillons: demandeTemp.map(item => ({
                    ref: item.ref,
                    famille: item.famille,
                    couleur: item.couleur,
                    qte: item.qte
                })),
                date: new Date().toISOString(),
                statut: 'En attente'
            };
            
            App.demandes.unshift(newDemande);
            

        }

        // Met à jour l'affichage
        renderDemandes();
        renderDashboard();
        closeModal('demande');
        resetDemandeModal();
    };
    window.editDemande = (id) => {
        const demande = App.demandes.find(d => String(d.id) === String(id));
        if (!demande) {
            showNotification("Demande introuvable.", "error");
            return;
        }
        if (demande.statut.trim().toLowerCase() !== 'en attente') {
            showNotification("Impossible de modifier une demande qui n'est plus en attente.", "error");
            return;
        }
        openModal('demande');

        // Remplit la liste temporaire avec tous les échantillons de la demande
        demandeTemp = demande.echantillons.map(e => ({
            ref: e.ref,
            famille: e.famille,
            couleur: e.couleur,
            qte: e.qte
        }));
        renderDemandeTempTable();

        // Pré-remplit les champs du premier échantillon (pour l'affichage)
        if (demande.echantillons.length > 0) {
            const firstSample = demande.echantillons[0];
            document.getElementById('echantillonSelect').value = firstSample.ref;
            document.getElementById('familleDemande').value = firstSample.famille;
            document.getElementById('couleurDemande').value = firstSample.couleur;
            document.getElementById('qteDemande').value = firstSample.qte;

            // Affiche les détails du premier échantillon
            const sample = echantillons.find(s => s.RefEchantillon === firstSample.ref);
            if (sample) {
                document.getElementById('echantillonDetails').innerHTML = `
                    <div class="space-y-2">
                        <p class="text-sm font-medium">Référence: ${sample.RefEchantillon}</p>
                        <p class="text-sm">Famille: ${sample.Famille}</p>
                        <p class="text-sm">Couleur: ${sample.Couleur}</p>
                        <p class="text-sm">Taille: ${sample.Taille}</p>
                        <p class="text-sm">Description: ${sample.Description}</p>
                        <div class="border-t pt-2">
                            <p class="text-sm font-semibold text-blue-600">Stock total: ${sample.Qte}</p>
                            <p class="text-sm font-semibold text-green-600">Disponible: ${sample.QteReellementDisponible || sample.Qte}</p>
                            ${sample.QteEmprunteeNonRetournee > 0 ? `<p class="text-sm font-semibold text-red-600">Emprunté: ${sample.QteEmprunteeNonRetournee}</p>` : ''}
                        </div>
                        <p class="text-sm">Statut: ${sample.Statut}</p>
                    </div>
                `;
            }
        }
        document.getElementById('edit_demande_id').value = demande.id;
        document.getElementById('commentaireDemande').value = demande.commentaire || '';
    };
    window.deleteDemande = (id) => {
        const demande = App.demandes.find(d => d.id === id);
        if (!demande) return;
        if (demande.statut.trim().toLowerCase() !== 'en attente') {
            showNotification("Impossible de supprimer une demande qui n'est plus en attente.", "error");
            return;
        }
        
        App.demandes = App.demandes.filter(d => d.id !== id);
        
        // Ajoute l'historique pour la suppression
        const echantillonsDesc = demande.echantillons.map(item => `${item.qte} ${item.ref}`).join(', ');
        App.historique.unshift({
            id: App.nextHistoriqueId++,
            user: App.currentUser.Nom,
            action: 'Suppression Demande',
            ref: demande.echantillons.map(e => e.ref).join(', '),
            desc: `Demande supprimée : ${echantillonsDesc}`,
            date: new Date().toISOString()
        });
        
        renderDemandes();
        renderDashboard();
    };
    
    window.saveRetour = () => {
        if (editRetourId !== null) {
            const retour = App.retours.find(r => String(r.id) === String(id));
            if (!retour) return;
            retour.echantillons = retourTemp.map(item => ({
                    ref: item.ref,
                    famille: item.famille,
                    couleur: item.couleur,
                qte: item.qte
            }));
            retour.commentaire = document.getElementById('commentaireRetour').value || '';
            retour.date = new Date().toISOString();

            renderRetours();
            renderDashboard();
            closeModal('retour');
            resetRetourModal();
            editRetourId = null;
            // Remettre le titre et le bouton à l'état "nouveau retour"
            document.getElementById('retourModalTitle').textContent = 'Nouveau Retour';
            const btn = document.querySelector('#formRetour button[type="submit"]');
            if (btn) btn.innerHTML = '<i class="fas fa-save mr-2"></i>Valider le retour';
            return;
        }
        const qte = parseInt(document.getElementById('qteRetour').value);

        if (!qte || qte < 1) {
            alert('Veuillez saisir une quantité valide');
            return;
        }

        if (retourTemp.length === 0) {
            alert('Ajoutez au moins un échantillon au retour.');
            return;
        }

        const demandeId = document.getElementById('demandeRetourSelect').value;
        console.log('demandeId:', demandeId);
        console.log('Résultat du test:', App.retours.some(r => String(r.idDemande) === String(demandeId) && r.Statut !== 'Refusé'));
        retourTemp.forEach(item => {
            const newRetour = {
                id: App.nextRetourId++,
                idDemande: demandeId, // <-- doit être bien renseigné
                echantillon_id: item.id,
                ref: item.ref,
                famille: item.famille,
                couleur: item.couleur,
                qte: qte,
                date: new Date().toISOString(),
                statut: 'Retourné'
            };
            App.retours.unshift(newRetour);

        });
        renderRetours();
        renderDashboard();
        closeModal('retour');
        resetRetourModal();
    };
    window.editRetour = (id) => {
        console.log('editRetour appelé avec id:', id);
        editRetourId = id;
        // Correction ici :
        const retour = App.retours.find(r => String(r.idRetour) === String(id));
        if (!retour) {
            alert('Retour introuvable');
            return;
        }
        document.getElementById('retourModal').classList.remove('hidden');
        retourTemp = Array.isArray(retour.echantillons) ? retour.echantillons.map(e => ({
            ref: e.RefEchantillon || e.refEchantillon || e.ref || '',
            famille: e.famille,
            couleur: e.couleur,
            qte: e.qte
        })) : [];
        if (typeof renderRetourTempTable === 'function') renderRetourTempTable();
        // Remplit le commentaire
        const commentaireInput = document.getElementById('commentaireRetour');
        if (commentaireInput) commentaireInput.value = retour.Commentaire || retour.commentaire || '';
        // Met l'id dans le champ caché
        const idInput = document.getElementById('edit_retour_id');
        if (idInput) idInput.value = retour.idRetour || retour.id;
        // Change le titre et le bouton
        const title = document.getElementById('retourModalTitle');
        if (title) title.textContent = 'Modifier Retour';
        const btn = document.querySelector('#formRetour button[type=\"submit\"]');
        if (btn) btn.innerHTML = '<i class=\"fas fa-save mr-2\"></i>Modifier le retour';

        // Pré-remplit les champs avec la concaténation de tous les échantillons
        if (retourTemp.length > 0) {
            // Familles et couleurs distinctes
            const familles = [...new Set(retourTemp.map(e => e.famille))].join(', ');
            const couleurs = [...new Set(retourTemp.map(e => e.couleur))].join(', ');
            // Quantités (somme ou liste)
            const qtes = retourTemp.map(e => `${e.ref}: ${e.qte}`).join(', ');

            document.getElementById('familleRetour').value = familles;
            document.getElementById('couleurRetour').value = couleurs;
            document.getElementById('qteRetour').value = 1; // ou somme totale si tu veux

            // Sélectionne la bonne demande dans le select
            const demandeSelect = document.getElementById('demandeRetourSelect');
            if (demandeSelect) {
                // On cherche une demande qui contient tous les échantillons du retour
                let demandeId = null;
                demandesRetour.forEach(demande => {
                    const refsDemande = demande.echantillons.map(e => e.refEchantillon).sort().join(',');
                    const refsRetour = retourTemp.map(e => e.ref).sort().join(',');
                    if (refsDemande === refsRetour) demandeId = demande.idDemande;
                });
                if (demandeId) {
                    demandeSelect.value = demandeId;
                } else {
                    demandeSelect.selectedIndex = 0;
                }
                demandeSelect.disabled = true;
            }
            // Remplit le select des échantillons
            const echantillonSelect = document.getElementById('echantillonRetourSelect');
            if (echantillonSelect) {
                echantillonSelect.innerHTML = '';
                retourTemp.forEach(e => {
                    const opt = document.createElement('option');
                    opt.value = e.ref;
                    opt.textContent = `${e.ref} - ${e.famille} - ${e.couleur} (Qte: ${e.qte})`;
                    opt.selected = true;
                    echantillonSelect.appendChild(opt);
                });
                echantillonSelect.disabled = true;
            }
            // Détails de tous les échantillons
            let details = '<ul>';
            retourTemp.forEach(e => {
                details += `<li><b>${e.ref}</b> — ${e.famille}, ${e.couleur}, Qte: ${e.qte}</li>`;
            });
            details += '</ul>';
            document.getElementById('echantillonRetourDetails').innerHTML = details;
        }
        document.getElementById('idDemandeRetour').value = demandeId;
    };
    window.deleteRetour = (id) => {
        const item = App.retours.find(i => String(i.idRetour || i.id) === String(id));
        if (!item) {
            alert('Retour introuvable');
            return;
        }
        App.retours = App.retours.filter(i => i.id !== id);
        addHistory('Suppression Retour', item.ref, `Retour ${item.ref} supprimé.`);
        renderRetours();
        renderDashboard();
    };

    // --- Init ---
    renderSidebar();
    renderDashboard();
    renderDemandes();
    renderRetours();
                    renderHistory();
    navigate('dashboard');
    
    // Listeners pour les recherches et filtres
    ['searchDemande', 'searchRetour', 'filterDemandeStatus', 'filterRetourStatus'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return; // Ignore si l'élément n'existe pas
        el.addEventListener('input', () => {
            if (id.includes('Demande')) {
                renderDemandes();
            }
            else if (id.includes('Retour')) {
                renderRetours();
            }
        });
        el.addEventListener('change', () => {
            if (id.includes('Demande')) {
                renderDemandes();
            }
            else if (id.includes('Retour')) {
                renderRetours();
            }
        });
    });

    if (document.getElementById('searchEchantillonDemande')) {
        document.getElementById('searchEchantillonDemande').addEventListener('input', filterEchantillonsDemande);
    }
    if (document.getElementById('searchEchantillonRetour')) {
        document.getElementById('searchEchantillonRetour').addEventListener('input', filterEchantillonsRetour);
    }

    // Event listeners pour l'historique
    const historyElements = ['historyDateStart', 'historyDateEnd', 'historyAction', 'historyUser', 'historySearch'];
    historyElements.forEach(id => {
        const element = document.getElementById(id);
        if (element) {
            element.addEventListener('change', filterHistory);
            element.addEventListener('input', filterHistory);
        }
    });
});

// Fonction pour charger les échantillons disponibles
function loadAvailableSamples() {
    filterEchantillonsDemande();
}

// Fonction pour charger les échantillons empruntés
function loadUserBorrowedSamples() {
    const select = document.getElementById('echantillonRetourSelect');
    select.innerHTML = '<option value=\"">Choisir un échantillon...</option>';
    // Ici, tu dois parcourir les retours validés ou les échantillons empruntés
    retours.forEach(retour => {
        if (retour.Statut === 'Validée' || retour.Statut === 'En attente') {
            retour.echantillons.forEach(e => {
                select.innerHTML += `<option value=\"${e.ref}\">${e.ref} - ${e.famille} - ${e.couleur}</option>`;
            });
        }
    });
}

// Gestionnaires d'événements pour les sélections d'échantillons
document.getElementById('echantillonSelect').addEventListener('change', function() {
    const selectedRef = this.value;
    
    if (selectedRef) {
        const sample = echantillons.find(s => s.RefEchantillon === selectedRef);
        
        if (sample) {
            // Remplir les champs automatiquement
            const familleInput = document.getElementById('familleDemande');
            const couleurInput = document.getElementById('couleurDemande');
            const qteInput = document.getElementById('qteDemande');
            
            if (familleInput && couleurInput && qteInput) {
                familleInput.value = sample.Famille;
                couleurInput.value = sample.Couleur;
                qteInput.value = sample.Qte;
                
            }
            

            
            // Afficher les détails complets
            const detailsDiv = document.getElementById('echantillonDetails');
            if (detailsDiv) {
                detailsDiv.innerHTML = `
                    <p class="text-sm font-medium">Référence: ${sample.RefEchantillon}</p>
                    <p class="text-sm">Famille: ${sample.Famille}</p>
                    <p class="text-sm">Couleur: ${sample.Couleur}</p>
                    <p class="text-sm">Taille: ${sample.Taille}</p>
                    <p class="text-sm">Description: ${sample.Description}</p>
                    <p class="text-sm">Stock disponible: ${sample.Qte}</p>
                    <p class="text-sm">Statut: ${sample.Statut}</p>
                `;
            }
        }
    } else {
        // Réinitialiser les champs
        const inputs = ['familleDemande', 'couleurDemande', 'qteDemande'];
        inputs.forEach(id => {
            const input = document.getElementById(id);
            if (input) input.value = '';
        });
        
        const detailsDiv = document.getElementById('echantillonDetails');
        if (detailsDiv) {
            detailsDiv.innerHTML = '<p class="text-sm text-gray-600">Sélectionnez un échantillon pour voir ses détails</p>';
        }
    }
});

document.getElementById('echantillonRetourSelect').addEventListener('change', function() {
    const demandeSelect = document.getElementById('demandeRetourSelect');
    const demande = demandesRetour.find(d => d.idDemande == demandeSelect.value);
    const selectedOptions = Array.from(this.selectedOptions);

    if (selectedOptions.length === 1 && demande) {
        const ref = selectedOptions[0].value;
        const echantillon = demande.echantillons.find(e => e.refEchantillon === ref);
        if (echantillon) {
            document.getElementById('familleRetour').value = echantillon.famille;
            document.getElementById('couleurRetour').value = echantillon.couleur;
        document.getElementById('qteRetour').value = 1;
            document.getElementById('qteRetour').setAttribute('max', echantillon.qte);
            document.getElementById('familleRetour').readOnly = true;
            document.getElementById('couleurRetour').readOnly = true;
            document.getElementById('qteRetour').readOnly = false;
            document.getElementById('qteRetour').disabled = false;
        document.getElementById('echantillonRetourDetails').innerHTML = `
                <p class="text-sm font-medium">Référence: ${echantillon.refEchantillon}</p>
                <p class="text-sm">Famille: ${echantillon.famille}</p>
                <p class="text-sm">Couleur: ${echantillon.couleur}</p>
                <p class="text-sm">Quantité empruntée: ${echantillon.qte}</p>
            `;
        }
    } else {
        // Plusieurs sélectionnés ou aucun : on vide les champs
        document.getElementById('familleRetour').value = '';
        document.getElementById('couleurRetour').value = '';
        document.getElementById('qteRetour').value = 1;
        document.getElementById('echantillonRetourDetails').innerHTML = '<p class="text-sm text-gray-600">Sélectionnez un échantillon pour voir ses détails</p>';
    }
    document.getElementById('idDemandeRetour').value = this.value;
});

// Modification des fonctions d'ouverture des modals
window.openModal = (type) => {
    let modalId = type.endsWith('Modal') ? type : type + 'Modal';
    if (modalId === 'retourModal' && !id) {
        // On charge les échantillons validés SEULEMENT si ce n'est PAS une édition
        loadUserValidatedSamples();
    }
    // ... reste du code ...
};

// Ajout de la logique pour demandes multiples
let demandeTemp = [];
let retourTemp = [];

function resetDemandeModal() {
    demandeTemp = [];
    renderDemandeTempTable();
    document.getElementById('echantillonSelect').selectedIndex = 0;
    document.getElementById('familleDemande').value = '';
    document.getElementById('couleurDemande').value = '';
    document.getElementById('qteDemande').value = 1;
    document.getElementById('echantillonDetails').innerHTML = '<p class="text-sm text-gray-600">Sélectionnez un échantillon pour voir ses détails</p>';
}

function resetRetourModal() {
    retourTemp = [];
    renderRetourTempTable();
    document.getElementById('echantillonRetourSelect').selectedIndex = 0;
    document.getElementById('familleRetour').value = '';
    document.getElementById('couleurRetour').value = '';
    document.getElementById('qteRetour').value = 1;
    document.getElementById('echantillonRetourDetails').innerHTML = '<p class="text-sm text-gray-600">Sélectionnez un échantillon pour voir ses détails</p>';
}

function renderDemandeTempTable() {
    const tbody = document.getElementById('demandeTempTableBody');
    if (!tbody) return;
    tbody.innerHTML = demandeTemp.map((item, idx) =>
        `<tr><td>${item.ref}</td><td>${item.famille}</td><td>${item.couleur}</td><td>${item.qte}</td><td><button onclick="removeDemandeTemp(${idx})" class="text-red-600">Supprimer</button></td></tr>`
    ).join('');
}
// ==================== FILTRAGE HISTORIQUE ====================
function applyAllHistoryFilters() {
    const startInput = document.getElementById('historyDateStart').value;
    const endInput = document.getElementById('historyDateEnd').value;
    const actionInput = document.getElementById('historyAction').value;
    const userInput = document.getElementById('historyUser') ? document.getElementById('historyUser').value : '';
    const searchInput = document.getElementById('historySearch').value.trim().toLowerCase();

    let startDate = startInput ? new Date(startInput) : null;
    let endDate = endInput ? new Date(endInput) : null;
    if (endDate) endDate.setHours(23, 59, 59, 999);

    let filtered = historiques.filter(row => {
        // Filtre date
        if (row.DateAction) {
            const rowDate = new Date(row.DateAction.replace(' ', 'T'));
            if (startDate && rowDate < startDate) return false;
            if (endDate && rowDate > endDate) return false;
        }
        // Filtre action
        if (actionInput && actionInput !== '' && row.TypeAction && row.TypeAction.toLowerCase() !== actionInput.toLowerCase()) return false;
        // Filtre utilisateur (par nom ou rôle)
        if (userInput && userInput !== '' && userInput !== 'Tous les utilisateurs') {
            const userFull = ((row.Prenom || '') + ' ' + (row.Nom || '')).toLowerCase();
            if (!userFull.includes(userInput.toLowerCase()) && (row.Role || '').toLowerCase() !== userInput.toLowerCase()) return false;
        }
        // Filtre recherche texte
        if (searchInput) {
            const values = [row.DateAction, row.Prenom, row.Nom, row.TypeAction, row.RefEchantillon, row.Description].map(x => (x || '').toLowerCase());
            if (!values.some(v => v.includes(searchInput))) return false;
        }
        return true;
    });
    renderHistory(filtered);
}

['historyDateStart','historyDateEnd','historyAction','historyUser','historySearch'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', applyAllHistoryFilters);
    if (el && (id === 'historyAction' || id === 'historyUser')) el.addEventListener('change', applyAllHistoryFilters);
});
// ================== FIN FILTRAGE HISTORIQUE ==================
function removeDemandeTemp(idx) {
    const item = demandeTemp[idx];
    demandeTemp.splice(idx, 1);
    renderDemandeTempTable();
    
    // Ajouter à l'historique
    if (item) {
        ajouterActionHistorique(
            'Suppression Demande',
            item.ref,
            `Échantillon ${item.ref} (Qté: ${item.qte}, Famille: ${item.famille}, Couleur: ${item.couleur}) supprimé de la demande temporaire`
        );
    }
}

function renderRetourTempTable() {
    const tbody = document.getElementById('retourTempTableBody');
    if (!tbody) return;
    tbody.innerHTML = retourTemp.map((item, idx) =>
        `<tr><td>${item.ref}</td><td>${item.famille}</td><td>${item.couleur}</td><td>${item.qte}</td><td></td></tr>`
    ).join('');
}
function removeRetourTemp(idx) {
    const item = retourTemp[idx];
    retourTemp.splice(idx, 1);
    renderRetourTempTable();
    
    // Ajouter à l'historique
    if (item) {
        ajouterActionHistorique(
            'Suppression Retour',
            item.ref,
            `Échantillon ${item.ref} (Qté: ${item.qte}, Famille: ${item.famille}, Couleur: ${item.couleur}) supprimé du retour temporaire`
        );
    }
}

function addDemandeTemp() {
    const echantillonSelect = document.getElementById('echantillonSelect');
    const qteDemande = parseInt(document.getElementById('qteDemande').value);
    
    if (!echantillonSelect.value) {
        showNotification('Veuillez sélectionner un échantillon', 'error');
        ajouterActionHistorique('Erreur Validation', '', 'Tentative d\'ajout d\'échantillon sans sélection');
        return;
    }
    
    if (!qteDemande || qteDemande < 1) {
        showNotification('La quantité doit être supérieure à 0', 'error');
        ajouterActionHistorique('Erreur Validation', '', 'Tentative d\'ajout avec quantité invalide');
        return;
    }
    
    const sample = echantillons.find(s => s.RefEchantillon === echantillonSelect.value);
    if (!sample) {
        showNotification('Échantillon non trouvé', 'error');
        return;
    }
    
    // Vérifier si l'échantillon est déjà dans une demande en attente
    const existingDemande = App.demandes.find(d => 
        d.Statut === 'En attente' && 
        d.echantillons.some(e => e.ref === sample.RefEchantillon)
    );
    
    if (existingDemande) {
        showNotification(`L'échantillon ${sample.RefEchantillon} est déjà dans une demande en attente`, 'error');
        ajouterActionHistorique('Erreur Validation', sample.RefEchantillon, `Tentative d'ajout de l'échantillon ${sample.RefEchantillon} déjà en demande`);
        return;
    }
    
    if (qteDemande > parseInt(sample.Qte)) {
        showNotification(`La quantité demandée (${qteDemande}) est supérieure au stock disponible (${sample.Qte})`, 'error');
        ajouterActionHistorique('Erreur Validation', sample.RefEchantillon, `Tentative d'ajout avec quantité ${qteDemande} supérieure au stock ${sample.Qte}`);
        return;
    }
    
    if (demandeTemp.some(d => d.ref === sample.RefEchantillon)) {
        showNotification(`L'échantillon ${sample.RefEchantillon} est déjà dans votre demande`, 'error');
        ajouterActionHistorique('Erreur Validation', sample.RefEchantillon, `Tentative d'ajout de l'échantillon ${sample.RefEchantillon} déjà dans la demande temporaire`);
        return;
    }
    
    demandeTemp.push({
        ref: sample.RefEchantillon,
        famille: sample.Famille,
        couleur: sample.Couleur,
        qte: qteDemande
    });
    
    renderDemandeTempTable();
    showNotification(`Échantillon ${sample.RefEchantillon} ajouté à la demande`, 'success');
    
    // Ajouter à l'historique
    ajouterActionHistorique(
        'Ajout Demande',
        sample.RefEchantillon,
        `Échantillon ${sample.RefEchantillon} (Qté: ${qteDemande}, Famille: ${sample.Famille}, Couleur: ${sample.Couleur}) ajouté à la demande temporaire`
    );
}

function addRetourTemp() {
    const demandeSelect = document.getElementById('demandeRetourSelect');
    const echantillonSelect = document.getElementById('echantillonRetourSelect');
    const qteRetour = parseInt(document.getElementById('qteRetour').value);

    if (!demandeSelect.value) {
        showNotification('Veuillez sélectionner une demande', 'error');
        ajouterActionHistorique('Erreur Validation', '', 'Tentative d\'ajout de retour sans sélection de demande');
        return;
    }
    const demande = demandesRetour.find(d => d.idDemande == demandeSelect.value);
    if (!demande) return;

    const selectedOptions = Array.from(echantillonSelect.selectedOptions);
    if (selectedOptions.length === 0) {
        showNotification('Veuillez sélectionner au moins un échantillon', 'error');
        ajouterActionHistorique('Erreur Validation', '', 'Tentative d\'ajout de retour sans sélection d\'échantillon');
        return;
    }

    selectedOptions.forEach(opt => {
        const echantillon = demande.echantillons.find(e => e.refEchantillon === opt.value);
        if (!echantillon) return;
        if (retourTemp.some(d => d.ref === echantillon.refEchantillon)) return; // éviter doublon
        retourTemp.push({
            ref: echantillon.refEchantillon,
            famille: echantillon.famille,
            couleur: echantillon.couleur,
            qte: qteRetour
        });
    });
    renderRetourTempTable();
    showNotification('Échantillon(s) ajouté(s) au retour', 'success');
    
    // Ajouter à l'historique
    selectedOptions.forEach(opt => {
        const echantillon = demande.echantillons.find(e => e.refEchantillon === opt.value);
        if (echantillon) {
            ajouterActionHistorique(
                'Ajout Retour',
                echantillon.refEchantillon,
                `Échantillon ${echantillon.refEchantillon} (Qté: ${qteRetour}, Famille: ${echantillon.famille}, Couleur: ${echantillon.couleur}) ajouté au retour temporaire`
            );
        }
    });
}

function saveDemande() {
    if (demandeTemp.length === 0) { 
        showNotification('Veuillez ajouter au moins un échantillon à la demande', 'error');
        return; 
    }

    // Vérifier les doublons avec les demandes existantes
    const duplicates = demandeTemp.filter(item => {
        return App.demandes.some(d => 
            d.Statut === 'En attente' && 
            d.echantillons.some(e => e.ref === item.ref)
        );
    });
    
    if (duplicates.length > 0) {
        const refs = duplicates.map(d => d.ref).join(', ');
        showNotification(`Les échantillons ${refs} sont déjà dans des demandes en attente`, 'error');
        return;
    }

    try {
        if (editDemandeId !== null) {
            // Mode édition
            App.demandes = App.demandes.filter(d => d.id !== editDemandeId);
            
            const newDemande = {
                id: editDemandeId,
                echantillons: demandeTemp.map(item => ({
                    ref: item.ref,
                    famille: item.famille,
                    couleur: item.couleur,
                    qte: item.qte
                })),
                date: new Date().toISOString(),
                statut: 'En attente'
            };
            
            App.demandes.unshift(newDemande);
            
            showNotification(`Demande ${newDemande.echantillons.map(e => e.ref).join(', ')} modifiée avec succès !`, 'success');
        } else {
            // Nouvelle demande
            const newDemande = {
                id: App.nextDemandeId++,
                echantillons: demandeTemp.map(item => ({
                    ref: item.ref,
                    famille: item.famille,
                    couleur: item.couleur,
                    qte: item.qte
                })),
                date: new Date().toISOString(),
                statut: 'En attente'
            };
            
            App.demandes.unshift(newDemande);
            
            showNotification(`Demande ${newDemande.echantillons.map(e => e.ref).join(', ')} envoyée avec succès !`, 'success');
        }
        
        renderDemandes();
        renderDashboard();
        closeModal('demande');
        resetDemandeModal();
        editDemandeId = null;
    } catch (error) {
        showNotification(error.message, 'error');
    }
}

function saveRetour() {
    if (editRetourId !== null) {
        const retour = App.retours.find(r => String(r.id) === String(id));
        if (!retour) return;
        retour.echantillons = retourTemp.map(item => ({
            ref: item.ref,
            famille: item.famille,
            couleur: item.couleur,
            qte: item.qte
        }));
        retour.commentaire = document.getElementById('commentaireRetour').value || '';
        retour.date = new Date().toISOString();
        // Historique
        App.historique.unshift({
            id: App.nextHistoriqueId++,
            user: App.currentUser.Nom,
            action: 'Modification Retour',
            ref: retour.echantillons.map(e => e.ref).join(', '),
            desc: `Retour modifié pour ${retour.echantillons.map(e => e.ref).join(', ')}`,
            date: retour.date
        });
        renderRetours();
        renderDashboard();
        closeModal('retour');
        resetRetourModal();
        editRetourId = null;
        // Remettre le titre et le bouton à l'état "nouveau retour"
        document.getElementById('retourModalTitle').textContent = 'Nouveau Retour';
        const btn = document.querySelector('#formRetour button[type="submit"]');
        if (btn) btn.innerHTML = '<i class="fas fa-save mr-2"></i>Valider le retour';
        return;
    }
    const qte = parseInt(document.getElementById('qteRetour').value);

    if (!qte || qte < 1) {
        alert('Veuillez saisir une quantité valide');
        return;
    }

    if (retourTemp.length === 0) {
        alert('Ajoutez au moins un échantillon au retour.');
        return;
    }

    const demandeId = document.getElementById('demandeRetourSelect').value;
    console.log('demandeId:', demandeId);
    console.log('Résultat du test:', App.retours.some(r => String(r.idDemande) === String(demandeId) && r.Statut !== 'Refusé'));
    retourTemp.forEach(item => {
        const newRetour = {
            id: App.nextRetourId++,
            idDemande: demandeId, // <-- doit être bien renseigné
            echantillon_id: item.id,
            ref: item.ref,
            famille: item.famille,
            couleur: item.couleur,
            qte: qte,
            date: new Date().toISOString(),
            statut: 'Retourné'
        };
        App.retours.unshift(newRetour);

    });
    renderRetours();
    renderDashboard();
    closeModal('retour');
    resetRetourModal();
}

function filterEchantillonsDemande() {
    const search = document.getElementById('searchEchantillonDemande').value.toLowerCase();
    const select = document.getElementById('echantillonSelect');
    select.innerHTML = '<option value="">Choisir un échantillon...</option>';
    
    echantillons.forEach(sample => {
        
        if (sample && sample.RefEchantillon) {
            const txt = `${sample.RefEchantillon} ${sample.Famille} ${sample.Couleur} ${sample.Description}`.toLowerCase();
            if (txt.includes(search)) {
                const option = `<option value="${sample.RefEchantillon}">\n                ${sample.RefEchantillon} - \n                ${sample.Famille} - \n                ${sample.Couleur} - \n                ${sample.Taille} - \n                ${sample.Description} \n                (Stock: ${sample.Qte})\n            </option>`;
                select.innerHTML += option;
            }
        }
    });
}

// Appelle la fonction au chargement pour remplir le select
document.addEventListener('DOMContentLoaded', filterEchantillonsDemande);

function filterEchantillonsRetour() {
    const search = document.getElementById('searchEchantillonRetour').value.toLowerCase();
    const select = document.getElementById('echantillonRetourSelect');
    select.innerHTML = '<option value="">Choisir un échantillon...</option>';
    echantillonsValidés.forEach(sample => {
        if (sample && sample.refEchantillon) {
            const txt = `${sample.refEchantillon} ${sample.famille} ${sample.couleur}`.toLowerCase();
                if (txt.includes(search)) {
                select.innerHTML += `<option value="${sample.refEchantillon}">${sample.refEchantillon} - ${sample.famille} - ${sample.couleur} (Qte: ${sample.qte})</option>`;
            }
        }
    });
}

function showNotification(message, type = 'success') {
    const container = document.getElementById('notificationsContainer');
    const notif = document.createElement('div');
    
    const iconClass = type === 'success' ? 'fa-check-circle text-green-500' : 'fa-exclamation-circle text-red-500';
    const borderClass = type === 'success' ? 'border-green-500' : 'border-red-500';

    notif.className = `notification bg-white rounded-lg shadow-lg p-4 flex items-start border-l-4 ${borderClass}`;
    
    notif.innerHTML = `
        <div class="flex-shrink-0 pt-0.5">
            <i class="fas ${iconClass} text-xl"></i>
        </div>
        <div class="ml-3 flex-1">
            <p class="text-sm font-medium text-gray-900">${message}</p>
        </div>
        <div class="ml-4 flex-shrink-0">
            <button onclick="closeNotification(this)" class="inline-flex text-gray-400 hover:text-gray-500">
                <span class="sr-only">Close</span>
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    
    container.appendChild(notif);

    setTimeout(() => {
        closeNotification(notif.querySelector('button'));
    }, 5000);
}

function closeNotification(element) {
    const notification = element.closest('.notification');
    if (notification) {
        notification.style.animation = 'slideOutRight 0.5s forwards';
        setTimeout(() => {
            notification.remove();
        }, 500);
    }
}

function envoyerDemandeServeur() {
    if (demandeTemp.length === 0) {
        showNotification('Veuillez ajouter au moins un échantillon à la demande.', 'error');
        ajouterActionHistorique('Erreur Validation', '', 'Tentative d\'envoi de demande sans échantillon');
        return false;
    }
    document.getElementById('echantillons_json').value = JSON.stringify(demandeTemp);
    let totalQte = 0;
    demandeTemp.forEach(item => { totalQte += parseInt(item.qte); });
    document.getElementById('formQteDemande').value = totalQte;
    const commentaire = document.getElementById('commentaireDemande').value.trim();
    if (!commentaire) {
        showNotification('Le commentaire est obligatoire pour valider la demande.', 'error');
        ajouterActionHistorique('Erreur Validation', '', 'Tentative d\'envoi de demande sans commentaire');
        return false;
    }
    console.log('echantillons_json au submit:', document.getElementById('echantillons_json').value);
    return true;
}

let demandeIdToDelete = null;

function openConfirmDeleteModal(id) {
    demandeIdToDelete = id;
    document.getElementById('confirmDeleteModal').classList.remove('hidden');
}
function closeConfirmDeleteModal() {
    demandeIdToDelete = null;
    document.getElementById('confirmDeleteModal').classList.add('hidden');
}
document.getElementById('confirmDeleteBtn').onclick = function() {
    if (demandeIdToDelete !== null) {
        // Envoie un POST pour supprimer côté serveur
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'delete_demande_id';
        input.value = demandeIdToDelete;
        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
    }
};

let retours = <?php echo json_encode($retours); ?>;

let echantillonsValidés = <?php echo json_encode($echantillons_valides); ?>;

function loadUserValidatedSamples() {
    const select = document.getElementById('echantillonRetourSelect');
    select.innerHTML = '<option value="">Choisir un échantillon...</option>';
    echantillonsValidés.forEach(e => {
        if (e && e.refEchantillon) {
        const opt = document.createElement('option');
        opt.value = e.refEchantillon;
        opt.textContent = `${e.refEchantillon} - ${e.famille} - ${e.couleur} (Qte: ${e.qte})`;
        opt.selected = true; // Sélectionne tous les échantillons par défaut
        select.appendChild(opt);
        }
    });
}

function envoyerRetourServeur() {
    // Contrôle anti-double retour
    const demandeSelect = document.getElementById('demandeRetourSelect');
    const editId = document.getElementById('edit_retour_id') ? document.getElementById('edit_retour_id').value : '';
    if (demandeSelect && !editId) { // Seulement en création
        const demandeId = demandeSelect.value;
        // Debug :
        console.log('demandeId:', demandeId);
        console.log('App.retours:', App.retours);
        console.log('Résultat du test:', App.retours.some(r => String(r.idDemande) === String(demandeId) && r.Statut !== 'Refusé'));
        // Vérifie si un retour existe déjà pour cette demande (hors refusé)
        const dejaRetour = App.retours.some(r => String(r.idDemande) === String(demandeId) && r.Statut !== 'Refusé');
        if (dejaRetour) {
            showNotification('Un retour a déjà été effectué pour cette demande.', 'error');
            ajouterActionHistorique('Erreur Validation', '', `Tentative de retour pour demande ${demandeId} déjà traitée`);
            return false;
        }
    }
    let totalQte = 0;
    retourTemp.forEach(item => { totalQte += parseInt(item.qte); });
    document.getElementById('formQteRetour').value = totalQte;
    document.getElementById('echantillons_retour_json').value = JSON.stringify(retourTemp);
    const commentaire = document.getElementById('commentaireRetour').value.trim();
    if (!commentaire) {
        showNotification('Le commentaire est obligatoire pour valider le retour.', 'error');
        ajouterActionHistorique('Erreur Validation', '', 'Tentative d\'envoi de retour sans commentaire');
        return false;
    }
    return true;
}

document.addEventListener('DOMContentLoaded', function() {
    const demandeSelect = document.getElementById('demandeRetourSelect');
    const echantillonSelect = document.getElementById('echantillonRetourSelect');
    if (demandeSelect && echantillonSelect && typeof demandesRetour !== 'undefined') {
        // Récupère les demandes déjà utilisées dans un retour (hors refusé)
        const demandesAvecRetour = new Set();
        App.retours.forEach(retour => {
            if (retour.idDemande && retour.Statut !== 'Refusé') {
                demandesAvecRetour.add(String(retour.idDemande));
            }
        });
        // Remplir le select des demandes
        demandesRetour.forEach(demande => {
            // Récupère les refs déjà retournés pour cette demande
            const dejaRetournés = new Set();
            App.retours.forEach(r => {
                if (r.idDemande == demande.idDemande && r.Statut !== 'Refusé') {
                    r.echantillons.forEach(e => dejaRetournés.add(e.ref));
                }
            });
            // Filtre les échantillons restants à retourner
            const echantillonsRestants = demande.echantillons.filter(e => !dejaRetournés.has(e.refEchantillon));
            const opt = document.createElement('option');
            opt.value = demande.idDemande;
            opt.textContent = `Demande #${demande.idDemande} - ${demande.DateDemande} - ${demande.Statut}`;
            // Désactive SEULEMENT si plus aucun échantillon à retourner
            if (echantillonsRestants.length === 0) {
                opt.disabled = true;
                opt.textContent += ' (tous les échantillons déjà retournés)';
            }
            demandeSelect.appendChild(opt);
        });
        // Quand on sélectionne une demande, remplir le select des échantillons
        demandeSelect.addEventListener('change', function() {
            echantillonSelect.innerHTML = '';
            const demande = demandesRetour.find(d => d.idDemande == this.value);
            if (demande && demande.echantillons.length > 0) {
                echantillonSelect.disabled = true;
                // Récupère toutes les familles, couleurs, stocks distincts
                const familles = [...new Set(demande.echantillons.map(e => e.famille))];
                const couleurs = [...new Set(demande.echantillons.map(e => e.couleur))];
                const stocks = demande.echantillons.map(e => `${e.refEchantillon}: ${e.qte}`);

                // === AJOUT : remplir le select avec tous les échantillons ===
                demande.echantillons.forEach(e => {
                    const opt = document.createElement('option');
                    opt.value = e.refEchantillon;
                    opt.textContent = `${e.refEchantillon} - ${e.famille} - ${e.couleur} (Qte: ${e.qte})`;
                    opt.selected = true; // tous sélectionnés
                    echantillonSelect.appendChild(opt);
                });

                document.getElementById('familleRetour').value = familles.join(', ');
                document.getElementById('couleurRetour').value = couleurs.join(', ');
                document.getElementById('qteRetour').value = 1;

                // Afficher la liste détaillée dans la zone de détails
                let details = '<ul>';
                demande.echantillons.forEach(e => {
                    details += `<li><b>${e.refEchantillon}</b> — ${e.famille}, ${e.couleur}, Qte: ${e.qte}</li>`;
                });
                details += '</ul>';
                document.getElementById('echantillonRetourDetails').innerHTML = details;
                

            } else {
                echantillonSelect.disabled = true;
                document.getElementById('familleRetour').value = '';
                document.getElementById('couleurRetour').value = '';
                document.getElementById('qteRetour').value = 1;
                document.getElementById('echantillonRetourDetails').innerHTML = '<p class="text-sm text-gray-600">Sélectionnez une demande pour voir les échantillons.</p>';
            }
            document.getElementById('idDemandeRetour').value = this.value; // AJOUT OBLIGATOIRE
        });
    }
});

console.log(App.retours);
console.log(App.retours[0].echantillons);

// Ajout des petits modales d'information pour les nombres du dashboard
// Place ce code juste après le dashboard principal (par exemple après <section id="dashboardSection" ...)

// HTML à insérer dans le dashboard (exemple, à placer dans le DOM)
/*
<div id="modalsDashboard" style="display:none; position:fixed; top:20px; left:50%; transform:translateX(-50%); z-index:9999;">
  <div id="modalInfo" style="background:#fff; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,0.15); padding:24px 32px; min-width:220px; text-align:center; font-size:1.2em; font-weight:500; color:#222;">
    <span id="modalInfoContent"></span>
    <br><br>
    <button onclick="closeModalInfo()" style="margin-top:8px; background:#667eea; color:#fff; border:none; border-radius:4px; padding:6px 18px; cursor:pointer;">Fermer</button>
  </div>
</div>
*/

// JS à ajouter dans le <script> principal
window.showModalInfo = function(content) {
    document.getElementById('modalInfoContent').innerHTML = content;
    document.getElementById('modalsDashboard').style.display = 'block';
};
window.closeModalInfo = function() {
    document.getElementById('modalsDashboard').style.display = 'none';
};

// Ajoute un onclick sur chaque carte du dashboard
// Par exemple, dans renderDashboard() :
document.getElementById('totalDemandes').parentElement.parentElement.onclick = function() {
    showModalInfo('Total Demandes : ' + App.demandes.length);
};
document.getElementById('demandesValidees').parentElement.parentElement.onclick = function() {
    showModalInfo('Demandes Validées : ' + App.demandes.filter(d => d.Statut === 'Validée' || d.Statut === 'Approuvée').length);
};
document.getElementById('demandesAttente').parentElement.parentElement.onclick = function() {
    showModalInfo('Demandes en Attente : ' + App.demandes.filter(d => d.Statut === 'En attente').length);
};
document.getElementById('demandesRefusees').parentElement.parentElement.onclick = function() {
    showModalInfo('Demandes Refusées : ' + App.demandes.filter(d => d.Statut === 'Refusée').length);
};
document.getElementById('totalRetours').parentElement.parentElement.onclick = function() {
    showModalInfo('Total Retours : ' + App.retours.length);
};
document.getElementById('retoursAttente').parentElement.parentElement.onclick = function() {
    showModalInfo('Retours en Attente : ' + App.retours.filter(r => r.Statut === 'En attente').length);
};
document.getElementById('retoursValides').parentElement.parentElement.onclick = function() {
    showModalInfo('Retours Validés : ' + App.retours.filter(r => r.Statut === 'Validé' || r.Statut === 'Approuvé').length);
};
document.getElementById('retoursRefuses').parentElement.parentElement.onclick = function() {
    showModalInfo('Retours Refusés : ' + App.retours.filter(r => r.Statut === 'Refusé').length);
};
// ...

// Supprime tout code de modale dashboard ajouté précédemment
// Ajoute les deux notifications modales simples (en haut pour demande, en bas pour retour)

// HTML à ajouter juste avant </body> :
/*
<div id="modalNotifDemande" style="display:none; position:fixed; top:30px; left:50%; transform:translateX(-50%); z-index:9999; background:#10b981; color:#fff; padding:16px 32px; border-radius:8px; font-size:1.1em; font-weight:500; box-shadow:0 2px 8px rgba(0,0,0,0.12);">Demande envoyée avec succès !</div>
<div id="modalNotifRetour" style="display:none; position:fixed; bottom:30px; left:50%; transform:translateX(-50%); z-index:9999; background:#059669; color:#fff; padding:16px 32px; border-radius:8px; font-size:1.1em; font-weight:500; box-shadow:0 2px 8px rgba(0,0,0,0.12);">Retour envoyé avec succès !</div>
*/

// JS à ajouter dans le <script> principal
window.showNotifDemande = function() {
    const el = document.getElementById('modalNotifDemande');
    el.style.display = 'block';
    setTimeout(() => { el.style.display = 'none'; }, 2500);
};
window.showNotifRetour = function() {
    const el = document.getElementById('modalNotifRetour');
    el.style.display = 'block';
    setTimeout(() => { el.style.display = 'none'; }, 2500);
};

// Dans la fonction d'ajout de demande (après succès, juste avant ou après le reset/fermeture du modal) :
// showNotifDemande();
// Dans la fonction d'ajout de retour (après succès, juste avant ou après le reset/fermeture du modal) :
// showNotifRetour();
// ...

console.log('retourTemp:', retourTemp);
console.log('App.retours:', App.retours);
let demandeId = document.getElementById('demandeRetourSelect').value;
console.log('demandeId:', demandeId);
console.log('Résultat du test:', App.retours.some(r => String(r.idDemande) === String(demandeId) && r.Statut !== 'Refusé'));

console.log(
  'Résultat du test:',
  App.retours.some(
    r => String(r.idDemande) === String(document.getElementById('demandeRetourSelect').value) && r.Statut !== 'Refusé'
  )
);

function accepterRetour(idRetour) {
    // AJAX call to accept the retour
}
function refuserRetour(idRetour) {
    // AJAX call to refuse the retour
}

let retourIdToDelete = null;

function openConfirmDeleteRetourModal(id) {
    retourIdToDelete = id;
    document.getElementById('confirmDeleteRetourModal').classList.remove('hidden');
}
function closeConfirmDeleteRetourModal() {
    retourIdToDelete = null;
    document.getElementById('confirmDeleteRetourModal').classList.add('hidden');
}
document.getElementById('confirmDeleteRetourBtn').onclick = function() {
    if (retourIdToDelete !== null) {
        // Envoie un POST pour supprimer côté serveur
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'delete_retour_id';
        input.value = retourIdToDelete;
        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
    }
};

// SUPPRIMÉ : error_log('delete_retour_id=' . var_export($_POST['delete_retour_id'], true));

// Quand tu ouvres la modale de retour pour une demande
const demande = demandesRetour.find(d => d.idDemande == demandeId);
const dejaRetournés = new Set();
App.retours.forEach(r => {
    if (r.idDemande == demandeId && r.Statut !== 'Refusé') {
        r.echantillons.forEach(e => dejaRetournés.add(e.ref));
    }
});

console.log('demandeTemp au submit:', demandeTemp);

window.demandeTemp = [];

// =================================================================================
// FONCTIONS HISTORIQUE (COPIÉES DU DASHBOARD STOCK)
// =================================================================================

// Fonction pour ajouter une action à l'historique via AJAX
function ajouterActionHistorique(typeAction, refEchantillon, description) {
    const formData = new FormData();
    formData.append('action_historique', '1');
    formData.append('type_action', typeAction);
    formData.append('ref_echantillon', refEchantillon);
    formData.append('description', description);
    
    fetch('dashboard_groupe.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log('Action ajoutée à l\'historique:', typeAction);
        } else {
            console.error('Erreur lors de l\'ajout à l\'historique:', data.error);
        }
    })
    .catch(error => {
        console.error('Erreur AJAX:', error);
    });
}

function renderHistory(list = historiques) {
    const tbody = document.getElementById('historiqueTableBody');
    tbody.innerHTML = '';
    if (!list || list.length === 0) {
        tbody.innerHTML = `<tr><td colspan='5' class='text-center text-gray-400 py-6'>Aucune entrée d'historique.</td></tr>`;
        return;
    }
    list.forEach(row => {
        let dateStr = '';
        if (row.DateAction) {
            let iso = row.DateAction.replace(' ', 'T');
            if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(iso)) {
                iso += ':00';
            }
            // Traiter la date comme locale et soustraire 1 heure
            const date = new Date(iso);
            if (!isNaN(date)) {
                // Soustraction d'1 heure
                date.setHours(date.getHours() - 1);
                dateStr = date.toLocaleString('fr-FR', {
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            } else {
                dateStr = row.DateAction;
            }
        }
        const user = (row.Prenom || row.Nom) ? `<span class='text-indigo-700 font-semibold'>${row.Prenom || ''} ${row.Nom || ''}</span>` : `<span class='text-gray-400'>Inconnu</span>`;
        const actionClass = {
            'Création': 'bg-green-100 text-green-800',
            'Modification': 'bg-blue-100 text-blue-800',
            'Suppression': 'bg-red-100 text-red-800',
            'Demande': 'bg-yellow-100 text-yellow-800',
            'Approbation': 'bg-green-100 text-green-800',
            'Rejet': 'bg-red-100 text-red-800',
            'Retour': 'bg-indigo-100 text-indigo-800',
            'Fabrication': 'bg-purple-100 text-purple-800',
            'Modification Fabrication': 'bg-blue-200 text-blue-900 font-semibold',
            'Suppression Fabrication': 'bg-red-200 text-red-900 font-semibold',
            'Fabrication Terminée': 'bg-purple-200 text-purple-900 font-semibold'
        }[row.TypeAction] || 'bg-gray-100 text-gray-800';
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td class='px-4 py-3 text-sm text-gray-700'>${dateStr}</td>
            <td class='px-4 py-3 text-sm font-semibold text-gray-800'>${user}</td>
            <td class='px-4 py-3 text-sm'><span class='inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${actionClass}'>${row.TypeAction}</span></td>
            <td class='px-4 py-3 text-sm font-medium text-gray-900'>${row.RefEchantillon}</td>
            <td class='px-4 py-3 text-sm text-gray-700 break-words' style='max-width: 300px;'>${row.Description}</td>
        `;
        tbody.appendChild(tr);
    });
}

function filterHistory() {
    const startDate = document.getElementById('historyDateStart').value;
    const endDate = document.getElementById('historyDateEnd').value;
    const action = document.getElementById('historyAction').value;
    const user = document.getElementById('historyUser').value;
    const search = document.getElementById('historySearch').value.toLowerCase();

    let filtered = historiques.filter(h => {
        // Filtre par date
        if (startDate && h.DateAction < startDate) return false;
        if (endDate && h.DateAction > endDate + ' 23:59:59') return false;
        
        // Filtre par action
        if (action && h.TypeAction !== action) return false;
        
        // Filtre par utilisateur
        if (user) {
            const userName = `${h.Prenom || ''} ${h.Nom || ''}`.trim();
            if (userName !== user) return false;
        }
        
        // Filtre par recherche
        if (search) {
            const searchText = `${h.TypeAction} ${h.RefEchantillon} ${h.Description} ${h.Prenom || ''} ${h.Nom || ''}`.toLowerCase();
            if (!searchText.includes(search)) return false;
        }
        
        return true;
    });

    renderHistory(filtered);
}

// Initialisation de l'historique
document.addEventListener('DOMContentLoaded', function() {
    renderHistory();
    
    // Ajout des event listeners pour les filtres
    ['historyDateStart','historyDateEnd','historyAction','historyUser','historySearch'].forEach(id => {
        const element = document.getElementById(id);
        if (element) {
            element.addEventListener('change', filterHistory);
            element.addEventListener('input', filterHistory);
        }
    });
});

</script>

<?php
// Récupération des données historiques (100 dernières entrées)
$historiques = $conn->query(
    "SELECT H.*, U.Nom, U.Prenom FROM Historique H LEFT JOIN Utilisateur U ON H.idUtilisateur = U.idUtilisateur ORDER BY H.DateAction DESC LIMIT 100"
)->fetch_all(MYSQLI_ASSOC);
?>
<script>
let historiques = <?php echo json_encode($historiques); ?>;

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
</script>

</body>
</html>
