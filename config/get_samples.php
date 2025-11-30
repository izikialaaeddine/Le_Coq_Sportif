<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user']) || !isset($_SESSION['user']['id'])) {
    echo json_encode(['success' => false, 'message' => 'Utilisateur non connecté']);
    exit;
}

$user_id = $_SESSION['user']['id'];
$type = $_GET['type'] ?? '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$famille = isset($_GET['famille']) ? trim($_GET['famille']) : '';
$couleur = isset($_GET['couleur']) ? trim($_GET['couleur']) : '';
$statut = isset($_GET['statut']) ? trim($_GET['statut']) : '';

try {
    // Get all families and colors for dropdowns
    $familles_query = "SELECT id, nom FROM familles ORDER BY nom";
    $familles_result = $conn->query($familles_query);
    $familles = [];
    while ($row = $familles_result->fetch_assoc()) {
        $familles[] = $row;
    }

    $couleurs_query = "SELECT id, nom FROM couleurs ORDER BY nom";
    $couleurs_result = $conn->query($couleurs_query);
    $couleurs = [];
    while ($row = $couleurs_result->fetch_assoc()) {
        $couleurs[] = $row;
    }

    // Get samples based on type
    if ($type === 'available') {
        // Get all available samples
        $samples_query = "SELECT e.id, e.reference, e.famille_id, e.couleur_id, 
                                f.nom as famille_nom, c.nom as couleur_nom, e.quantite
                         FROM echantillons e
                         JOIN familles f ON e.famille_id = f.id
                         JOIN couleurs c ON e.couleur_id = c.id
                         WHERE e.quantite > 0
                         ORDER BY e.reference";
    } elseif ($type === 'user_borrowed') {
        // Get samples borrowed by the current user
        $samples_query = "SELECT e.id, e.reference, e.famille_id, e.couleur_id,
                                f.nom as famille_nom, c.nom as couleur_nom, d.quantite
                         FROM demandes d
                         JOIN echantillons e ON d.echantillon_id = e.id
                         JOIN familles f ON e.famille_id = f.id
                         JOIN couleurs c ON e.couleur_id = c.id
                         WHERE d.user_id = $user_id 
                         AND d.statut = 'Validée'
                         AND d.quantite_retournee < d.quantite
                         ORDER BY d.date_demande DESC";
    } else {
        // Recherche et filtrage dynamiques
        $where = [];
        if ($search !== '') {
            $searchSql = $conn->real_escape_string($search);
            $where[] = "(e.reference LIKE '%$searchSql%' OR f.nom LIKE '%$searchSql%' OR c.nom LIKE '%$searchSql%' OR e.taille LIKE '%$searchSql%')";
        }
        if ($famille !== '') {
            $familleSql = $conn->real_escape_string($famille);
            $where[] = "f.nom = '$familleSql'";
        }
        if ($couleur !== '') {
            $couleurSql = $conn->real_escape_string($couleur);
            $where[] = "c.nom = '$couleurSql'";
        }
        if ($statut !== '') {
            $statutSql = $conn->real_escape_string($statut);
            $where[] = "e.statut = '$statutSql'";
        }
        $whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';
        $samples_query = "SELECT e.id, e.reference, e.famille_id, e.couleur_id, f.nom as famille_nom, c.nom as couleur_nom, e.taille, e.quantite, e.statut FROM echantillons e JOIN familles f ON e.famille_id = f.id JOIN couleurs c ON e.couleur_id = c.id $whereSql ORDER BY e.reference";
    }

    $samples_result = $conn->query($samples_query);
    $samples = [];
    while ($row = $samples_result->fetch_assoc()) {
        $samples[] = $row;
    }

    echo json_encode([
        'success' => true,
        'samples' => $samples,
        'familles' => $familles,
        'couleurs' => $couleurs
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur lors de la récupération des données: ' . $e->getMessage()]);
}
?> 