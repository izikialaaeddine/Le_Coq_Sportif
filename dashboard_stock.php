<?php
// Démarrer la session AVANT TOUT
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
require_once __DIR__ . '/config/error_config.php';
require_once __DIR__ . '/config/db.php';
// Endpoint pour récupérer tous les échantillons à Qte=0
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_zero_stock_samples') {
    header('Content-Type: application/json');
    $res = $conn->query("
        SELECT e.refEchantillon AS RefEchantillon, e.famille AS Famille, e.couleur AS Couleur, e.taille AS Taille, e.qte AS Qte
        FROM Echantillon e
        WHERE e.qte = 0
        AND e.refechantillon NOT IN (
            SELECT f.refechantillon
            FROM Fabrication f
            WHERE f.statutfabrication != 'Terminée'
        )
    ");
    $samples = [];
    while ($row = $res->fetch_assoc()) {
        $samples[] = $row;
    }
    echo json_encode(['success' => true, 'samples' => $samples]);
    exit;
}

// Helper function to add history entries
function ajouterHistorique($conn, $idUtilisateur, $refEchantillon, $typeAction, $description, $dateAction = null) {
    // PostgreSQL: utiliser idutilisateur en minuscules
    if ($dateAction === null) {
        $dateAction = date('Y-m-d H:i:s');
    }
    $stmt = $conn->prepare("INSERT INTO Historique (idutilisateur, refechantillon, typeaction, dateaction, description) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) { return false; }
    $stmt->bind_param("issss", $idUtilisateur, $refEchantillon, $typeAction, $dateAction, $description);
    if (!$stmt->execute()) { return false; }
    return true;
}

// Fonction pour formater les dates en diminuant d'1 heure
function formatDateMoinsUneHeure($dateString) {
    if (empty($dateString)) return '';
    $date = new DateTime($dateString);
    $date->modify('-1 hour');
    return $date->format('Y-m-d H:i:s');
}

// Action pour mettre à jour le statut d'une demande approuvée
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_approved_status') {
    header('Content-Type: application/json');
    $idDemande = $_POST['idDemande'] ?? 0;
    $newStatus = $_POST['newStatus'] ?? '';
    $idUtilisateur = $_SESSION['user']['id'] ?? 1;

    if (empty($idDemande) || empty($newStatus)) {
        echo json_encode(['success' => false, 'error' => 'Données invalides.']);
        exit;
    }

    $conn->begin_transaction();
    try {
        // 1. Mettre à jour le statut de la demande
        $stmt = $conn->prepare("UPDATE Demande SET statut = ? WHERE iddemande = ? AND statut = 'Approuvée'");
        $stmt->bind_param("si", $newStatus, $idDemande);
        if (!$stmt->execute()) { throw new Exception("Erreur de mise à jour de la demande: " . $stmt->error); }
        if ($stmt->affected_rows === 0) { throw new Exception("La demande n'est pas approuvée ou n'existe pas."); }
        
        // 2. Récupérer les échantillons de la demande
        $echantillonsDemandes = [];
        $resEch = $conn->query("SELECT refEchantillon, qte FROM DemandeEchantillon WHERE idDemande = $idDemande");
        while ($e = $resEch->fetch_assoc()) { $echantillonsDemandes[] = $e; }

        $refs = array_map(fn($e) => $e['refEchantillon'], $echantillonsDemandes);
        $refsString = implode(', ', $refs);
        $description = "Statut de la demande pour {$refsString} mis à jour à '{$newStatus}'.";

        // 3. Logique spécifique au statut
        if ($newStatus === 'Prêt pour retrait') {
            foreach ($echantillonsDemandes as $ech) {
                $stmtEch = $conn->prepare("UPDATE Echantillon SET qte = qte - ?, statut = 'emprunte' WHERE refechantillon = ? AND qte >= ?");
                $stmtEch->bind_param("isi", $ech['qte'], $ech['refEchantillon'], $ech['qte']);
                if (!$stmtEch->execute()) { throw new Exception("Erreur de mise à jour du stock pour {$ech['refEchantillon']}: " . $stmtEch->error); }
                if ($stmtEch->affected_rows === 0) { throw new Exception("Stock insuffisant ou échantillon non disponible pour {$ech['refEchantillon']}."); }
            }
            $description .= " Le stock a été mis à jour.";
        } elseif ($newStatus === 'En fabrication') {
            foreach ($echantillonsDemandes as $ech) {
                $stmtEch = $conn->prepare("UPDATE Echantillon SET statut = 'En fabrication' WHERE refechantillon = ?");
                $stmtEch->bind_param("s", $ech['refEchantillon']);
                if (!$stmtEch->execute()) { throw new Exception("Erreur de mise à jour du statut pour {$ech['refEchantillon']}: " . $stmtEch->error); }
            }
            $description .= " Le statut de l'échantillon a été mis à jour.";
        }
        
        // 4. Ajouter à l'historique
        ajouterHistorique($conn, $idUtilisateur, $refsString, 'Mise à jour statut', $description);

        $conn->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_all_page_data') {
    header('Content-Type: application/json');
    $data = [];

    // --- STATS ---
    $stats = [
        'totalSamples' => 0, 'availableSamples' => 0, 'borrowedSamples' => 0, 'fabricationSamples' => 0,
        'pendingRequests' => 0, 'approvedRequests' => 0, 'rejectedRequests' => 0, 'returnedRequests' => 0,
    ];
    $resSamples = $conn->query("SELECT Statut, COUNT(*) as count FROM Echantillon GROUP BY Statut");
    if ($resSamples) {
        while ($row = $resSamples->fetch_assoc()) {
            $status = strtolower(trim($row['Statut']));
            if ($status === 'disponible') $stats['availableSamples'] = $row['count'];
            elseif ($status === 'emprunte') $stats['borrowedSamples'] = $row['count'];
            elseif ($status === 'en fabrication') $stats['fabricationSamples'] = $row['count'];
        }
        $stats['totalSamples'] = $stats['availableSamples'] + $stats['borrowedSamples'] + $stats['fabricationSamples'];
    }
    $resDemandes = $conn->query("SELECT Statut, TypeDemande, COUNT(*) as count FROM Demande GROUP BY Statut, TypeDemande");
    if ($resDemandes) {
        while($row = $resDemandes->fetch_assoc()) {
            $status = strtolower(trim($row['Statut'] ?? ''));
            $type = strtolower(trim($row['TypeDemande'] ?? ''));
            if ($type === 'demande' || $type === 'Demande') {
                if ($status === 'en attente') $stats['pendingRequests'] = $row['count'];
                elseif (in_array($status, ['approuvée', 'validée', 'emprunte', 'prêt pour retrait', 'en fabrication', 'attente inter-service'])) {
                    $stats['approvedRequests'] += $row['count'];
                }
                elseif ($status === 'refusée') $stats['rejectedRequests'] = $row['count'];
            }
        }
    }
    // Stats Retours pour la section "Échantillons Retournés"
    $resRetours = $conn->query("SELECT Statut, COUNT(*) as count FROM Retour GROUP BY Statut");
    if ($resRetours) {
        while($row = $resRetours->fetch_assoc()) {
            $status = strtolower(trim($row['Statut']));
            if ($status === 'validé' || $status === 'approuvé') {
                $stats['returnedRequests'] += $row['count'];
            }
        }
    }
    $data['stats'] = $stats;

    // --- ACTIVITÉ RÉCENTE ---
    $recent_activities = [];
    $query_activity = "
    (SELECT 'demande' as type, d.DateDemande as activity_date, u.prenom AS Prenom, u.nom AS Nom, GROUP_CONCAT(de.refEchantillon SEPARATOR ', ') as details
    FROM Demande d
    JOIN Utilisateur u ON d.idUtilisateur = u.idUtilisateur
    JOIN DemandeEchantillon de ON d.idDemande = de.idDemande
    WHERE d.TypeDemande = 'Demande'
    GROUP BY d.idDemande, d.DateDemande, u.prenom, u.nom
    )
    UNION ALL
    (SELECT 'retour' as type, d.DateDemande as activity_date, u.prenom AS Prenom, u.nom AS Nom, GROUP_CONCAT(de.refEchantillon SEPARATOR ', ') as details
    FROM Demande d
    JOIN Utilisateur u ON d.idUtilisateur = u.idUtilisateur
    JOIN DemandeEchantillon de ON d.idDemande = de.idDemande
    WHERE d.TypeDemande = 'Retour'
    GROUP BY d.idDemande, d.DateDemande, u.prenom, u.nom
    )
    UNION ALL
    (SELECT 'fabrication' as type, f.dateCreation as activity_date, u.prenom AS Prenom, u.nom AS Nom, GROUP_CONCAT(f.refEchantillon SEPARATOR ', ') as details
    FROM Fabrication f
    JOIN Utilisateur u ON f.idUtilisateur = u.idUtilisateur
    WHERE f.idLot IS NOT NULL
    GROUP BY f.idLot
    )
    ORDER BY activity_date DESC
    LIMIT 5;
    ";
    $res_activity = $conn->query($query_activity);
    if ($res_activity) { while($row = $res_activity->fetch_assoc()){ $recent_activities[] = $row; } }
    $data['recent_activities'] = $recent_activities;

    // --- SAMPLES TABLE ---
    $samples_query = $conn->query("SELECT * FROM Echantillon ORDER BY dateCreation DESC");
    if ($samples_query) {
        if (method_exists($samples_query, 'fetch_all')) {
            $data['samples'] = $samples_query->fetch_all(MYSQLI_ASSOC);
        } else {
            $data['samples'] = [];
            while ($row = $samples_query->fetch_assoc()) {
                $data['samples'][] = $row;
            }
        }
    } else {
        $data['samples'] = [];
    }

    // --- REQUESTS TABLE ---
    $demandes_et_retours = [];
    $res = $conn->query("SELECT d.*, u.nom AS NomDemandeur, u.prenom AS PrenomDemandeur
                         FROM Demande d
                         JOIN Utilisateur u ON d.idUtilisateur = u.idUtilisateur
                         ORDER BY d.DateDemande DESC LIMIT 100");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['echantillons'] = [];
            $idDemande = $row['idDemande'];
            $res2 = $conn->query("SELECT * FROM DemandeEchantillon WHERE idDemande = " . intval($idDemande));
            if($res2) {
                while ($e = $res2->fetch_assoc()) {
                    $row['echantillons'][] = $e;
                }
            }
                $row['request_date'] = $row['DateDemande']; // Clé de tri commune
                $demandes_et_retours[] = $row;
        }
    }
    // === AJOUT : Récupérer les retours en attente ===
    $res = $conn->query("SELECT r.*, u.nom AS NomDemandeur, u.prenom AS PrenomDemandeur
                         FROM Retour r
                         JOIN Utilisateur u ON r.idutilisateur = u.idutilisateur
                         ORDER BY r.dateretour DESC LIMIT 100");
    if ($res && $res->num_rows > 0) {
        $retours = [];
        while ($row = $res->fetch_assoc()) {
            $idRetour = $row['idRetour'] ?? $row['idretour'] ?? '';
            // Get all echantillons for this retour
            $echs = [];
            $res2 = $conn->query("SELECT re.refechantillon AS refEchantillon, re.qte AS qte, e.famille AS famille, e.couleur AS couleur FROM RetourEchantillon re LEFT JOIN Echantillon e ON re.refechantillon = e.refechantillon WHERE re.idretour = " . intval($idRetour));
            if ($res2) {
                while ($e = $res2->fetch_assoc()) {
                    $echs[] = $e;
                }
            }
            // Prepare display: concatenate all refs, familles, couleurs, qtes
            $refs = implode('<br>', array_map(fn($e) => htmlspecialchars($e['refEchantillon'] ?? $e['RefEchantillon'] ?? $e['refechantillon'] ?? ''), $echs));
            $familles = implode('<br>', array_map(fn($e) => htmlspecialchars($e['famille'] ?? ''), $echs));
            $couleurs = implode('<br>', array_map(fn($e) => htmlspecialchars($e['couleur'] ?? ''), $echs));
            $qtes = implode('<br>', array_map(fn($e) => htmlspecialchars($e['qte'] ?? ''), $echs));
            ?>
            <tr>
                <td class="px-4 py-3"><?= $refs ?></td>
                <td class="px-4 py-3"><?= $familles ?></td>
                <td class="px-4 py-3"><?= $couleurs ?></td>
                <td class="px-4 py-3"><?= $qtes ?></td>
                <td class="px-4 py-3"><?= htmlspecialchars($row['DateRetour']) ?></td>
                <td class="px-4 py-3"><?= htmlspecialchars($row['Statut']) ?></td>
                <td class="px-4 py-3">
                    <!-- Add action buttons if needed, e.g. accept/refuse -->
                    <?php if (strtolower($row['Statut']) === 'en attente'): ?>
                        <button onclick="acceptRetour('<?= $row['idRetour'] ?>')" class="text-green-600 hover:text-green-900" title="Accepter le retour">
                            <i class="fas fa-check-circle"></i>
                        </button>
                        <button onclick="refuserRetour('<?= $row['idRetour'] ?>')" class="text-red-600 hover:text-red-900" title="Refuser le retour">
                            <i class="fas fa-times-circle"></i>
                        </button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php
        }
    }

    // 2. Récupérer les Fabrications et les formater comme des demandes
    $fabrications_as_demandes = [];
    $resFab = $conn->query("SELECT f.idlot AS idLot, f.datecreation AS DateCreation, f.statutfabrication AS StatutFabrication, f.idutilisateur AS idUtilisateur, u.nom AS NomDemandeur, u.prenom AS PrenomDemandeur FROM Fabrication f JOIN Utilisateur u ON f.idutilisateur = u.idutilisateur WHERE f.idlot IS NOT NULL AND f.idlot != '' GROUP BY f.idlot, f.datecreation, f.statutfabrication, f.idutilisateur, u.nom, u.prenom ORDER BY f.datecreation DESC");
    if ($resFab) {
        while($lot = $resFab->fetch_assoc()) {
            $lot_details = [];
            $idLot = $lot['idLot'] ?? $lot['idlot'] ?? '';
            $resDet = $conn->query("SELECT fab.refechantillon AS RefEchantillon, fab.qte AS Qte, e.famille AS Famille, e.couleur AS Couleur FROM Fabrication fab LEFT JOIN Echantillon e ON fab.refechantillon = e.refechantillon WHERE fab.idlot = '" . $conn->real_escape_string($idLot) . "'");
            if ($resDet) {
                while($det = $resDet->fetch_assoc()) {
                    $lot_details[] = ['refEchantillon' => $det['RefEchantillon'] ?? $det['refechantillon'] ?? '', 'famille' => $det['Famille'] ?? $det['famille'] ?? '', 'couleur' => $det['Couleur'] ?? $det['couleur'] ?? '', 'qte' => $det['Qte'] ?? $det['qte'] ?? 0];
                }
            }
            $fabrications_as_demandes[] = [
                'idDemande' => $idLot,
                'NomDemandeur' => $lot['NomDemandeur'] ?? $lot['nom'] ?? '',
                'PrenomDemandeur' => $lot['PrenomDemandeur'] ?? $lot['prenom'] ?? '',
                'echantillons' => $lot_details,
                'DateDemande' => $lot['DateCreation'] ?? $lot['datecreation'] ?? '',
                'Statut' => $lot['StatutFabrication'] ?? $lot['statutfabrication'] ?? '',
                'TypeDemande' => 'fabrication',
                'request_date' => $lot['DateCreation'] ?? $lot['datecreation'] ?? '',
            ];
        }
    }

    // 3. Fusionner et trier
    $all_requests = array_merge($demandes_et_retours, $retours, $fabrications_as_demandes);
    usort($all_requests, function($a, $b) {
        return strtotime($b['request_date']) - strtotime($a['request_date']);
    });
    $data['requests'] = $all_requests;

    // --- HISTORY TABLE ---
    $history_query = $conn->query("SELECT H.*, U.nom AS Nom, U.prenom AS Prenom, R.role AS Role FROM Historique H LEFT JOIN Utilisateur U ON H.idutilisateur = U.idutilisateur LEFT JOIN Role R ON U.idrole = R.idrole ORDER BY H.dateaction DESC LIMIT 100");
    if ($history_query) {
        if (method_exists($history_query, 'fetch_all')) {
            $data['history'] = $history_query->fetch_all(MYSQLI_ASSOC);
        } else {
            $data['history'] = [];
            while ($row = $history_query->fetch_assoc()) {
                $data['history'][] = $row;
            }
        }
    } else {
        $data['history'] = [];
    }

    // --- FABRICATION CENTER MODAL ---
    $fabrications = [];
    $resFab = $conn->query("SELECT f.*, e.famille AS Famille, e.couleur AS Couleur, e.taille AS Taille, f.refechantillon AS RefEchantillon, f.qte AS Qte, f.statutfabrication AS StatutFabrication, f.datecreation AS DateCreation
                            FROM Fabrication f 
                            LEFT JOIN Echantillon e ON f.refechantillon = e.refechantillon 
                            WHERE f.idlot IS NOT NULL AND f.idlot != ''
                            ORDER BY f.datecreation DESC, f.idlot DESC");
    $groupedFabrications = [];
    if ($resFab) {
        while ($row = $resFab->fetch_assoc()) {
            $idLot = $row['idlot'] ?? $row['idLot'] ?? '';
            $groupedFabrications[$idLot][] = $row;
        }
    }
    $data['grouped_fabrications'] = $groupedFabrications;

    echo json_encode($data);
    exit;
}


if (!isset($_SESSION['user']) || $_SESSION['user']['idRole'] != 1) { // 1 = Chef de Stock
    header('Location: index.php', true, 302);
    exit;
}
$currentUser = $_SESSION['user'];

// --- STATS POUR LE DASHBOARD ---
$stats = [
    'totalSamples' => 0, 'availableSamples' => 0, 'borrowedSamples' => 0, 'fabricationSamples' => 0,
    'pendingRequests' => 0, 'approvedRequests' => 0, 'rejectedRequests' => 0, 'returnedRequests' => 0,
    'pendingReturns' => 0, 'approvedReturns' => 0, 'rejectedReturns' => 0,
    'pendingFabrications' => 0, 'completedFabrications' => 0,
];

// Stats Echantillons - Correction complète (sans fabrication qui est dans sa propre section)
    $resSamples = $conn->query("SELECT Statut, COUNT(*) as count FROM Echantillon GROUP BY Statut");
if ($resSamples) {
    while ($row = $resSamples->fetch_assoc()) {
        $status = strtolower(trim($row['Statut']));
        if ($status === 'disponible') $stats['availableSamples'] = $row['count'];
        elseif ($status === 'emprunte') $stats['borrowedSamples'] = $row['count'];
        // En fabrication est maintenant géré dans les statistiques des fabrications
    }
    $stats['totalSamples'] = $stats['availableSamples'] + $stats['borrowedSamples'];
}

// Stats Demandes - Correction complète avec logique appropriée
$resDemandes = $conn->query("SELECT Statut, TypeDemande, COUNT(*) as count FROM Demande GROUP BY Statut, TypeDemande");
if ($resDemandes) {
    while($row = $resDemandes->fetch_assoc()) {
        $status = strtolower(trim($row['Statut']));
        $type = strtolower(trim($row['TypeDemande']));
        if ($type === 'demande') {
            if ($status === 'en attente') $stats['pendingRequests'] = $row['count'];
            // Demandes approuvées = toutes les demandes qui ont été validées (pas seulement "Approuvée")
            elseif (in_array($status, ['approuvée', 'validée', 'emprunte', 'prêt pour retrait', 'en fabrication', 'attente inter-service'])) {
                $stats['approvedRequests'] += $row['count'];
            }
            elseif ($status === 'refusée') $stats['rejectedRequests'] = $row['count'];
        }
        // Les retours sont maintenant gérés dans la section des retours
    }
}

// Stats Retours - Correction pour utiliser la table Retour correctement avec debug
$resRetours = $conn->query("SELECT Statut, COUNT(*) as count FROM Retour GROUP BY Statut");
if ($resRetours) {
    while($row = $resRetours->fetch_assoc()) {
        $status = strtolower(trim($row['Statut']));
        // Debug: afficher tous les statuts trouvés
        error_log("Statut retour trouvé: " . $status . " (original: " . $row['Statut'] . ") - count: " . $row['count']);
        if ($status === 'en attente') $stats['pendingReturns'] = $row['count'];
        elseif ($status === 'validé' || $status === 'approuvé') $stats['approvedReturns'] = $row['count'];
        elseif ($status === 'refusé') $stats['rejectedReturns'] = $row['count'];
    }
}

// Les retours sont maintenant gérés dans leur propre section

// Stats Fabrications - Debug complet pour voir toutes les données
$debugQuery = "SELECT statutfabrication AS StatutFabrication, idlot AS idLot, COUNT(*) as count FROM Fabrication GROUP BY statutfabrication, idlot ORDER BY statutfabrication";
$debugRes = $conn->query($debugQuery);
if ($debugRes) {
    error_log("=== DEBUG FABRICATIONS ===");
    while($row = $debugRes->fetch_assoc()) {
        error_log("Statut: '" . $row['StatutFabrication'] . "', idLot: '" . $row['idLot'] . "', count: " . $row['count']);
    }
    error_log("=== FIN DEBUG FABRICATIONS ===");
}

// Stats Fabrications - Ajout des statistiques des fabrications avec debug
$resFabrications = $conn->query("SELECT statutfabrication AS StatutFabrication, COUNT(DISTINCT idlot) as count FROM Fabrication WHERE idlot IS NOT NULL AND idlot != '' GROUP BY statutfabrication");
if ($resFabrications) {
    while($row = $resFabrications->fetch_assoc()) {
        $status = strtolower(trim($row['StatutFabrication']));
        // Debug: afficher tous les statuts trouvés
        error_log("Statut fabrication trouvé: " . $status . " (original: " . $row['StatutFabrication'] . ") - count: " . $row['count']);
        if ($status === 'en attente' || $status === 'en cours') $stats['pendingFabrications'] = $row['count'];
        elseif ($status === 'terminée') $stats['completedFabrications'] = $row['count'];
    }
}

// --- ACTIVITÉ RÉCENTE ---
$recent_activities = [];
$query_activity = "
(SELECT 'demande' as type, d.datedemande as activity_date, u.prenom AS Prenom, u.nom AS Nom, string_agg(de.refechantillon, ', ') as details
FROM Demande d
JOIN Utilisateur u ON d.idutilisateur = u.idutilisateur
JOIN DemandeEchantillon de ON d.iddemande = de.iddemande
WHERE d.typedemande = 'demande'
GROUP BY d.iddemande, d.datedemande, u.prenom, u.nom
)
UNION ALL
(SELECT 'retour' as type, d.datedemande as activity_date, u.prenom AS Prenom, u.nom AS Nom, string_agg(de.refechantillon, ', ') as details
FROM Demande d
JOIN Utilisateur u ON d.idutilisateur = u.idutilisateur
JOIN DemandeEchantillon de ON d.iddemande = de.iddemande
WHERE d.typedemande = 'retour'
GROUP BY d.iddemande, d.datedemande, u.prenom, u.nom
)
UNION ALL
(SELECT 'fabrication' as type, f.datecreation as activity_date, u.prenom AS Prenom, u.nom AS Nom, string_agg(f.refechantillon, ', ') as details
FROM Fabrication f
JOIN Utilisateur u ON f.idutilisateur = u.idutilisateur
    WHERE f.idlot IS NOT NULL
    GROUP BY f.idlot, f.datecreation, u.prenom, u.nom
)
ORDER BY activity_date DESC
LIMIT 5;
";
$res_activity = $conn->query($query_activity);
if ($res_activity) {
    while($row = $res_activity->fetch_assoc()){
        $recent_activities[] = $row;
    }
}

// --- RÉCUPÉRER LES ÉCHANTILLONS POUR LE TABLEAU ---
$echantillons = [];
$echantillons_query = $conn->query("SELECT * FROM Echantillon ORDER BY dateCreation DESC");
if ($echantillons_query) {
    if (method_exists($echantillons_query, 'fetch_all')) {
        $echantillons = $echantillons_query->fetch_all(MYSQLI_ASSOC);
    } else {
        while ($row = $echantillons_query->fetch_assoc()) {
            $echantillons[] = $row;
        }
    }
} else {
    // Debug: logger l'erreur si la requête échoue
    error_log("ERREUR: Requête échantillons échouée");
    if (method_exists($conn, 'error')) {
        error_log("Erreur DB: " . $conn->error);
    }
}

// Action pour récupérer les détails pour les modales du dashboard
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_dashboard_details') {
    header('Content-Type: application/json');
    $type = $_GET['type'] ?? '';
    $data = [];
    $query = '';

    switch ($type) {
        case 'available_samples':
            $query = "SELECT refechantillon AS RefEchantillon, famille AS Famille, couleur AS Couleur, taille AS Taille, qte AS Qte, datecreation AS DateCreation FROM Echantillon WHERE statut = 'disponible' ORDER BY datecreation DESC";
            break;
        case 'borrowed_samples':
            $query = "SELECT refechantillon AS RefEchantillon, famille AS Famille, couleur AS Couleur, taille AS Taille, datecreation AS DateCreation FROM Echantillon WHERE statut = 'emprunte' ORDER BY datecreation DESC";
            break;
        case 'fabrication_samples':
            $query = "SELECT refechantillon AS RefEchantillon, famille AS Famille, couleur AS Couleur, taille AS Taille, qte AS Qte, datecreation AS DateCreation FROM Echantillon WHERE statut = 'En fabrication' ORDER BY datecreation DESC";
            break;
        case 'pending_requests':
            $query = "SELECT d.idDemande, d.DateDemande, u.nom AS Nom, u.prenom AS Prenom FROM Demande d JOIN Utilisateur u ON d.idUtilisateur = u.idUtilisateur WHERE d.TypeDemande = 'Demande' AND d.Statut = 'En attente' ORDER BY d.DateDemande DESC";
            break;
        case 'approved_requests':
            $query = "SELECT d.idDemande, d.DateDemande, u.nom AS Nom, u.prenom AS Prenom, d.Statut FROM Demande d JOIN Utilisateur u ON d.idUtilisateur = u.idUtilisateur WHERE d.TypeDemande = 'Demande' AND d.Statut IN ('Approuvée', 'Validée', 'emprunte', 'Prêt pour retrait', 'En fabrication', 'Attente inter-service') ORDER BY d.DateDemande DESC";
            break;
        case 'rejected_requests':
            $query = "SELECT d.idDemande, d.DateDemande, u.nom AS Nom, u.prenom AS Prenom FROM Demande d JOIN Utilisateur u ON d.idUtilisateur = u.idUtilisateur WHERE d.TypeDemande = 'Demande' AND d.Statut = 'Refusée' ORDER BY d.DateDemande DESC";
            break;
        case 'pending_returns':
            $query = "SELECT r.idretour AS idDemande, r.dateretour AS DateDemande, u.nom AS Nom, u.prenom AS Prenom, r.statut AS Statut FROM Retour r JOIN Utilisateur u ON r.idutilisateur = u.idutilisateur WHERE r.statut = 'En attente' ORDER BY r.dateretour DESC";
            break;
        case 'approved_returns':
            $query = "SELECT r.idretour AS idDemande, r.dateretour AS DateDemande, u.nom AS Nom, u.prenom AS Prenom, r.statut AS Statut FROM Retour r JOIN Utilisateur u ON r.idutilisateur = u.idutilisateur WHERE r.statut IN ('Validé', 'Approuvé', 'Retourné') ORDER BY r.dateretour DESC";
            break;
        case 'rejected_returns':
            $query = "SELECT r.idretour AS idDemande, r.dateretour AS DateDemande, u.nom AS Nom, u.prenom AS Prenom, r.statut AS Statut FROM Retour r JOIN Utilisateur u ON r.idutilisateur = u.idutilisateur WHERE r.statut = 'Refusé' ORDER BY r.dateretour DESC";
            break;
        case 'pending_fabrications':
            $query = "SELECT f.idlot AS idLot, f.datecreation AS DateCreation, u.nom AS Nom, u.prenom AS Prenom, f.statutfabrication AS StatutFabrication FROM Fabrication f JOIN Utilisateur u ON f.idutilisateur = u.idutilisateur WHERE f.statutfabrication IN ('En attente', 'En cours') AND f.idlot IS NOT NULL AND f.idlot != '' GROUP BY f.idlot, f.datecreation, u.nom, u.prenom, f.statutfabrication ORDER BY f.datecreation DESC";
            break;
        case 'completed_fabrications':
            $query = "SELECT f.idlot AS idLot, f.datecreation AS DateCreation, u.nom AS Nom, u.prenom AS Prenom, f.statutfabrication AS StatutFabrication FROM Fabrication f JOIN Utilisateur u ON f.idutilisateur = u.idutilisateur WHERE f.statutfabrication = 'Terminée' AND f.idlot IS NOT NULL AND f.idlot != '' GROUP BY f.idlot, f.datecreation, u.nom, u.prenom, f.statutfabrication ORDER BY f.datecreation DESC";
            break;
    }

    if (!empty($query)) {
        $res = $conn->query($query);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                if ((strpos($type, 'requests') !== false || strpos($type, 'returns') !== false || strpos($type, 'fabrications') !== false) && isset($row['idDemande'])) {
                    $row['echantillons'] = [];
                    // Pour les retours, utiliser la table RetourEchantillon
                    if (strpos($type, 'returns') !== false) {
                        $res2 = $conn->query("SELECT re.refechantillon AS refEchantillon, e.famille AS famille, e.couleur AS couleur, re.qte AS qte FROM RetourEchantillon re LEFT JOIN Echantillon e ON re.refechantillon = e.refechantillon WHERE re.idretour = " . $row['idDemande']);
                    } elseif (strpos($type, 'fabrications') !== false) {
                        // Pour les fabrications, utiliser la table Fabrication
                        $res2 = $conn->query("SELECT f.refechantillon AS refEchantillon, e.famille AS famille, e.couleur AS couleur, f.qte AS qte FROM Fabrication f LEFT JOIN Echantillon e ON f.refechantillon = e.refechantillon WHERE f.idlot = '" . $conn->real_escape_string($row['idDemande']) . "'");
                    } else {
                        // Pour les autres demandes, utiliser DemandeEchantillon
                        $res2 = $conn->query("SELECT * FROM DemandeEchantillon WHERE idDemande = " . $row['idDemande']);
                    }
                    if ($res2) while ($e = $res2->fetch_assoc()) $row['echantillons'][] = $e;
                }
                $data[] = $row;
            }
        }
    }
    
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}


// Action pour enregistrer un retour d'échantillon
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'return_sample') {
    header('Content-Type: application/json');
    if (!isset($_POST['refEchantillon']) || empty($_POST['refEchantillon'])) {
        echo json_encode(['success' => false, 'error' => 'Référence de l\'échantillon manquante.']);
        exit;
    }
    $refEchantillon = $_POST['refEchantillon'];
    $ok = true;
    $errorMsg = '';

    $stmt = $conn->prepare("SELECT statut AS Statut FROM Echantillon WHERE refechantillon = ?");
    $stmt->bind_param("s", $refEchantillon);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            $ok = false;
            $errorMsg = 'Échantillon non trouvé.';
        } else {
            $row = $result->fetch_assoc();
            $statut = strtolower(trim($row['Statut']));
            if ($statut !== 'emprunte') {
                $ok = false;
                $errorMsg = 'Échantillon non emprunté.';
            }
        }
    } else {
        $ok = false;
        $errorMsg = 'Erreur lors de la vérification du statut: ' . $stmt->error;
    }
    $stmt->close();

    if ($ok) {
        $stmt = $conn->prepare("UPDATE Echantillon SET statut = 'retourné' WHERE refechantillon = ?");
        $stmt->bind_param("s", $refEchantillon);
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Erreur lors de la mise à jour du statut: ' . $stmt->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'error' => $errorMsg]);
    }
    exit;
}

// Action pour récupérer les détails d'un lot de fabrication
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_fabrication_lot_details') {
    header('Content-Type: application/json');
    if (!isset($_GET['idLot']) || empty($_GET['idLot'])) {
        echo json_encode(['success' => false, 'error' => 'ID du lot manquant.']);
        exit;
    }
    $idLot = $_GET['idLot'];
    $details = [];
    $stmt = $conn->prepare("SELECT f.refechantillon AS RefEchantillon, f.qte AS Qte, e.famille AS Famille, e.couleur AS Couleur, e.taille AS Taille FROM Fabrication f LEFT JOIN Echantillon e ON f.refechantillon = e.refechantillon WHERE f.idlot = ?");
    $stmt->bind_param("s", $idLot);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while($row = $result->fetch_assoc()) {
            $details[] = $row;
        }
        echo json_encode(['success' => true, 'details' => $details]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Erreur de base de données.']);
    }
    exit;
}

// Action pour mettre à jour un lot de fabrication
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_fabrication_lot') {
    header('Content-Type: application/json');
    $idLot = $_POST['idLot'] ?? '';
    $data = json_decode($_POST['fabrication_json'], true);

    if (empty($idLot) || !is_array($data)) {
        echo json_encode(['success' => false, 'error' => 'Données invalides.']);
        exit;
    }
    
    $conn->begin_transaction();
    try {
        // Récupérer l'état initial du lot
        $oldItems = [];
        $resOld = $conn->query("SELECT refechantillon AS RefEchantillon, qte AS Qte FROM Fabrication WHERE idlot = '" . $conn->real_escape_string($idLot) . "'");
        while ($row = $resOld->fetch_assoc()) {
            $ref = $row['RefEchantillon'] ?? $row['refechantillon'] ?? '';
            $qte = $row['Qte'] ?? $row['qte'] ?? 0;
            $oldItems[$ref] = $qte;
        }

        // Récupérer les métadonnées du lot avant de le supprimer
        $stmt_meta = $conn->prepare("SELECT idutilisateur AS idUtilisateur, idvalidateur AS idValidateur, datecreation AS DateCreation, statutfabrication AS StatutFabrication FROM Fabrication WHERE idlot = ? LIMIT 1");
        $stmt_meta->bind_param("s", $idLot);
        $stmt_meta->execute();
        $meta_result = $stmt_meta->get_result();
        if ($meta_result->num_rows === 0 && !empty($data)) {
             throw new Exception("Lot non trouvé.");
        }
        $meta = $meta_result->fetch_assoc();
        $stmt_meta->close();

        // 1. Supprimer les anciennes entrées pour ce lot
        $stmtDel = $conn->prepare("DELETE FROM Fabrication WHERE idlot = ?");
        $stmtDel->bind_param("s", $idLot);
        $stmtDel->execute();
        $stmtDel->close();

        // 2. Insérer les nouvelles entrées si la liste n'est pas vide
        $newItems = [];
        if (!empty($data)) {
            $stmtIns = $conn->prepare("INSERT INTO Fabrication (RefEchantillon, idUtilisateur, idValidateur, DateCreation, StatutFabrication, Qte, idLot) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($data as $fab) {
                $ref = $fab['ref'];
                $qte = $fab['qte'];
                $stmtIns->bind_param("sisssis", $ref, $meta['idUtilisateur'], $meta['idValidateur'], $meta['DateCreation'], $meta['StatutFabrication'], $qte, $idLot);
                if (!$stmtIns->execute()) {
                    throw new Exception("Erreur lors de l'insertion: " . $stmtIns->error);
                }
                $newItems[$ref] = $qte;
            }
            $stmtIns->close();
        }
        
        $user = $_SESSION['user'];
        $userId = $user['id'];
        $details = [];
        // Comparer les anciens et nouveaux items
        foreach ($newItems as $ref => $newQte) {
            $oldQte = $oldItems[$ref] ?? null;
            $resDet = $conn->query("SELECT famille AS Famille, couleur AS Couleur, taille AS Taille FROM Echantillon WHERE refechantillon = '$ref' LIMIT 1");
            $rowDet = $resDet ? $resDet->fetch_assoc() : null;
            $famille = $rowDet['Famille'] ?? '';
            $couleur = $rowDet['Couleur'] ?? '';
            $taille = $rowDet['Taille'] ?? '';
            if ($oldQte === null) {
                $details[] = "$ref (Ajouté, Qté : $newQte, Famille : $famille, Couleur : $couleur, Taille : $taille)";
            } elseif ($oldQte != $newQte) {
                $details[] = "$ref (Qté : $oldQte → $newQte, Famille : $famille, Couleur : $couleur, Taille : $taille)";
            } else {
                $details[] = "$ref (Aucun changement, Qté : $newQte, Famille : $famille, Couleur : $couleur, Taille : $taille)";
            }
        }
        // Échantillons supprimés
        foreach ($oldItems as $ref => $oldQte) {
            if (!isset($newItems[$ref])) {
                $resDet = $conn->query("SELECT famille AS Famille, couleur AS Couleur, taille AS Taille FROM Echantillon WHERE refechantillon = '$ref' LIMIT 1");
                $rowDet = $resDet ? $resDet->fetch_assoc() : null;
                $famille = $rowDet['Famille'] ?? '';
                $couleur = $rowDet['Couleur'] ?? '';
                $taille = $rowDet['Taille'] ?? '';
                $details[] = "$ref (Supprimé, Qté : $oldQte, Famille : $famille, Couleur : $couleur, Taille : $taille)";
            }
        }
        $detailsString = implode(', ', $details);
        $refsString = implode(', ', array_keys($newItems));
        $description = "Modification du lot $idLot : $detailsString";
        ajouterHistorique($conn, $userId, $refsString, 'Modification Fabrication', $description);

        $conn->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => 'Erreur de base de données: ' . $e->getMessage()]);
    }
    exit;
}

// Action pour supprimer un lot de fabrication
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_fabrication_lot') {
    header('Content-Type: application/json');
    if (!isset($_POST['idLot']) || empty($_POST['idLot'])) {
        echo json_encode(['success' => false, 'error' => 'ID du lot manquant.']);
        exit;
    }
    
    $idLot = $_POST['idLot'];
    $user = $_SESSION['user'];
    $userId = $user['id'];
    $details = [];
    $resDet = $conn->query("SELECT refechantillon AS RefEchantillon, qte AS Qte FROM Fabrication WHERE idlot = '" . $conn->real_escape_string($idLot) . "'");
    while ($row = $resDet->fetch_assoc()) {
        $ref = $row['RefEchantillon'] ?? $row['refechantillon'] ?? '';
        $qte = $row['Qte'] ?? $row['qte'] ?? 0;
        $resEch = $conn->query("SELECT famille AS Famille, couleur AS Couleur, taille AS Taille FROM Echantillon WHERE refechantillon = '" . $conn->real_escape_string($ref) . "' LIMIT 1");
        $rowEch = $resEch ? $resEch->fetch_assoc() : null;
        $famille = $rowEch['Famille'] ?? $rowEch['famille'] ?? '';
        $couleur = $rowEch['Couleur'] ?? $rowEch['couleur'] ?? '';
        $taille = $rowEch['Taille'] ?? $rowEch['taille'] ?? '';
        $details[] = "$ref (Supprimé, Qté : $qte, Famille : $famille, Couleur : $couleur, Taille : $taille)";
    }
    $detailsString = implode(', ', $details);
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("DELETE FROM Fabrication WHERE idlot = ?");
        $stmt->bind_param("s", $idLot);
        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }
        $description = "Suppression du lot $idLot : $detailsString";
        ajouterHistorique($conn, $userId, '', 'Suppression Fabrication', $description);
        $conn->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => 'Erreur de base de données: ' . $e->getMessage()]);
    }
    exit;
}

// Action pour terminer un lot de fabrication et mettre à jour le stock
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_fabrication_lot') {
    header('Content-Type: application/json');
    $idLot = $_POST['idLot'] ?? '';
    $idUtilisateur = $_SESSION['user']['id'] ?? 1;
    $user = $_SESSION['user'];
    if (empty($idLot)) {
        echo json_encode(['success' => false, 'error' => 'ID du lot manquant.']);
        exit;
    }

    $conn->begin_transaction();
    try {
        // 1. Vérifier si le lot n'est pas déjà terminé
        $stmt_check = $conn->prepare("SELECT statutfabrication AS StatutFabrication FROM Fabrication WHERE idlot = ? LIMIT 1");
        $stmt_check->bind_param("s", $idLot);
        $stmt_check->execute();
        $res_check = $stmt_check->get_result();
        $fab_status = $res_check->fetch_assoc();
        if (!$fab_status) {
            throw new Exception("Lot de fabrication non trouvé.");
        }
        $statutFab = $fab_status['StatutFabrication'] ?? $fab_status['statutfabrication'] ?? '';
        if (strtolower($statutFab) === 'terminée') {
            throw new Exception("Ce lot de fabrication est déjà terminé.");
        }

        // 2. Récupérer les échantillons du lot
        $echantillons_a_fabriquer = [];
        $stmt_items = $conn->prepare("SELECT refechantillon AS RefEchantillon, qte AS Qte FROM Fabrication WHERE idlot = ?");
        $stmt_items->bind_param("s", $idLot);
        $stmt_items->execute();
        $result_items = $stmt_items->get_result();
        while ($item = $result_items->fetch_assoc()) {
            $echantillons_a_fabriquer[] = $item;
        }

        if (empty($echantillons_a_fabriquer)) {
            throw new Exception("Le lot de fabrication est vide.");
        }

        // 3. Mettre à jour le stock pour chaque échantillon
        $stmt_update_stock = $conn->prepare("UPDATE Echantillon SET qte = qte + ? WHERE refechantillon = ?");
        foreach ($echantillons_a_fabriquer as $ech) {
            $qte = $ech['Qte'] ?? $ech['qte'] ?? 0;
            $ref = $ech['RefEchantillon'] ?? $ech['refechantillon'] ?? '';
            $stmt_update_stock->bind_param("is", $qte, $ref);
            if (!$stmt_update_stock->execute()) {
                throw new Exception("Erreur de mise à jour du stock pour {$ref}: " . $stmt_update_stock->error);
            }
        }
        $stmt_update_stock->close();

        // 4. Mettre à jour le statut du lot de fabrication
        $stmt_update_lot = $conn->prepare("UPDATE Fabrication SET statutfabrication = 'Terminée' WHERE idlot = ?");
        $stmt_update_lot->bind_param("s", $idLot);
        if (!$stmt_update_lot->execute()) {
            throw new Exception("Erreur de mise à jour du statut du lot: " . $stmt_update_lot->error);
        }
        $stmt_update_lot->close();

        // 5. Ajouter à l'historique
        $details = [];
        foreach ($echantillons_a_fabriquer as $ech) {
            $ref = $ech['RefEchantillon'];
            $qte = $ech['Qte'];
            $resDet = $conn->query("SELECT famille AS Famille, couleur AS Couleur, taille AS Taille FROM Echantillon WHERE refechantillon = '$ref' LIMIT 1");
            $rowDet = $resDet ? $resDet->fetch_assoc() : null;
            $famille = $rowDet['Famille'] ?? '';
            $couleur = $rowDet['Couleur'] ?? '';
            $taille = $rowDet['Taille'] ?? '';
            $details[] = "$ref (Terminé, Qté : $qte, Famille : $famille, Couleur : $couleur, Taille : $taille)";
        }
        $detailsString = implode(', ', $details);
        $refs_string = implode(', ', array_map(fn($e) => $e['RefEchantillon'], $echantillons_a_fabriquer));
        $description = "Fabrication terminée du lot $idLot : $detailsString";
        ajouterHistorique($conn, $idUtilisateur, $refs_string, 'Fabrication Terminée', $description);

        $conn->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Action pour accepter un retour et remettre en stock
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'accept_return') {
    header('Content-Type: application/json');
    $idDemande = $_POST['idDemande'] ?? 0;
    $idUtilisateur = $_SESSION['user']['id'] ?? 1;

    if (empty($idDemande)) {
        echo json_encode(['success' => false, 'error' => 'ID de la demande de retour manquant.']);
        exit;
    }

    $conn->begin_transaction();
    try {
        // 1. Vérifier si c'est bien une demande de retour en attente
        $stmt_check = $conn->prepare("SELECT Statut FROM Demande WHERE idDemande = ? AND TypeDemande = 'Retour'");
        $stmt_check->bind_param("i", $idDemande);
        $stmt_check->execute();
        $res_check = $stmt_check->get_result();
        $demande_status = $res_check->fetch_assoc();

        if (!$demande_status) {
            throw new Exception("Demande de retour non trouvée.");
        }
        if ($demande_status['Statut'] !== 'En attente') {
            throw new Exception("Cette demande de retour a déjà été traitée.");
        }

        // 2. Récupérer les échantillons de la demande de retour
        $echantillons_a_retourner = [];
        $stmt_items = $conn->prepare("SELECT refEchantillon, qte FROM DemandeEchantillon WHERE idDemande = ?");
        $stmt_items->bind_param("i", $idDemande);
        $stmt_items->execute();
        $result_items = $stmt_items->get_result();
        while ($item = $result_items->fetch_assoc()) {
            $echantillons_a_retourner[] = $item;
        }

        if (empty($echantillons_a_retourner)) {
            throw new Exception("Aucun échantillon trouvé pour cette demande de retour.");
        }

        // 3. Mettre à jour le stock et le statut pour chaque échantillon
        // On suppose que le statut redevient 'disponible'
        $stmt_update_stock = $conn->prepare("UPDATE Echantillon SET qte = qte + ?, statut = 'disponible' WHERE refechantillon = ?");
        foreach ($echantillons_a_retourner as $ech) {
            $qte = $ech['qte'];
            $ref = $ech['refEchantillon'];
            $stmt_update_stock->bind_param("is", $qte, $ref);
            if (!$stmt_update_stock->execute()) {
                throw new Exception("Erreur de mise à jour du stock pour {$ref}: " . $stmt_update_stock->error);
            }
        }
        $stmt_update_stock->close();

        // 4. Mettre à jour le statut de la demande de retour
        $stmt_update_demande = $conn->prepare("UPDATE Demande SET Statut = 'Retourné' WHERE iddemande = ?");
        $stmt_update_demande->bind_param("i", $idDemande);
        if (!$stmt_update_demande->execute()) {
            throw new Exception("Erreur de mise à jour du statut de la demande: " . $stmt_update_demande->error);
        }
        $stmt_update_demande->close();

        // 5. Ajouter à l'historique
        $refs_string = implode(', ', array_map(fn($e) => $e['refEchantillon'], $echantillons_a_retourner));
        $description = "M. {$user['Prenom']} {$user['Nom']} a accepté le retour de {$refsString} (demande {$idDemande}).";
        ajouterHistorique($conn, $idUtilisateur, $refs_string, 'Retour', $description);

        $conn->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Action pour accepter un retour de la table Retour
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'accept_retour') {
    header('Content-Type: application/json');
    $idRetour = $_POST['idRetour'] ?? 0;
    $idUtilisateur = $_SESSION['user']['id'] ?? 1;

    if (empty($idRetour)) {
        echo json_encode(['success' => false, 'error' => 'ID du retour manquant.']);
        exit;
    }

    $conn->begin_transaction();
    try {
        // 1. Vérifier si c'est bien un retour en attente
        $stmt_check = $conn->prepare("SELECT Statut FROM Retour WHERE idretour = ?");
        $stmt_check->bind_param("i", $idRetour);
        $stmt_check->execute();
        $res_check = $stmt_check->get_result();
        $retour_status = $res_check->fetch_assoc();

        if (!$retour_status) {
            throw new Exception("Retour non trouvé.");
        }
        if ($retour_status['Statut'] !== 'En attente') {
            throw new Exception("Ce retour a déjà été traité.");
        }

        // 2. Récupérer les échantillons du retour
        $echantillons_a_retourner = [];
        $stmt_items = $conn->prepare("SELECT refechantillon AS RefEchantillon, qte AS qte FROM RetourEchantillon WHERE idretour = ?");
        $stmt_items->bind_param("i", $idRetour);
        $stmt_items->execute();
        $result_items = $stmt_items->get_result();
        while ($item = $result_items->fetch_assoc()) {
            $echantillons_a_retourner[] = $item;
        }

        if (empty($echantillons_a_retourner)) {
            throw new Exception("Aucun échantillon trouvé pour ce retour.");
        }

        // 3. Mettre à jour le stock et le statut pour chaque échantillon
        $stmt_update_stock = $conn->prepare("UPDATE Echantillon SET qte = qte + ?, statut = 'disponible' WHERE refechantillon = ?");
        foreach ($echantillons_a_retourner as $ech) {
            $qte = $ech['qte'];
            $ref = $ech['RefEchantillon'];
            $stmt_update_stock->bind_param("is", $qte, $ref);
            if (!$stmt_update_stock->execute()) {
                throw new Exception("Erreur de mise à jour du stock pour {$ref}: " . $stmt_update_stock->error);
            }
        }
        $stmt_update_stock->close();

        // 4. Mettre à jour le statut du retour
        $stmt_update_retour = $conn->prepare("UPDATE Retour SET Statut = 'Validé' WHERE idretour = ?");
        $stmt_update_retour->bind_param("i", $idRetour);
        if (!$stmt_update_retour->execute()) {
            throw new Exception("Erreur de mise à jour du statut du retour: " . $stmt_update_retour->error);
        }
        $stmt_update_retour->close();

        // 5. Ajouter à l'historique
        $refs_string = implode(', ', array_map(fn($e) => $e['RefEchantillon'], $echantillons_a_retourner));
        $description = "M. {$_SESSION['user']['Prenom']} {$_SESSION['user']['Nom']} a accepté le retour de {$refs_string} (retour {$idRetour}).";
        ajouterHistorique($conn, $idUtilisateur, $refs_string, 'Retour', $description);

        $conn->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Action pour refuser un retour de la table Retour
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'refuse_retour') {
    header('Content-Type: application/json');
    $idRetour = $_POST['idRetour'] ?? 0;
    $idUtilisateur = $_SESSION['user']['id'] ?? 1;

    if (empty($idRetour)) {
        echo json_encode(['success' => false, 'error' => 'ID du retour manquant.']);
        exit;
    }

    $conn->begin_transaction();
    try {
        // 1. Vérifier si c'est bien un retour en attente
        $stmt_check = $conn->prepare("SELECT Statut FROM Retour WHERE idretour = ?");
        $stmt_check->bind_param("i", $idRetour);
        $stmt_check->execute();
        $res_check = $stmt_check->get_result();
        $retour_status = $res_check->fetch_assoc();

        if (!$retour_status) {
            throw new Exception("Retour non trouvé.");
        }
        if ($retour_status['Statut'] !== 'En attente') {
            throw new Exception("Ce retour a déjà été traité.");
        }

        // 2. Récupérer les échantillons du retour
        $echantillons_a_retourner = [];
        $stmt_items = $conn->prepare("SELECT refechantillon AS RefEchantillon, qte AS qte FROM RetourEchantillon WHERE idretour = ?");
        $stmt_items->bind_param("i", $idRetour);
        $stmt_items->execute();
        $result_items = $stmt_items->get_result();
        while ($item = $result_items->fetch_assoc()) {
            $echantillons_a_retourner[] = $item;
        }

        // 3. Mettre à jour le statut du retour
        $stmt_update_retour = $conn->prepare("UPDATE Retour SET Statut = 'Refusé' WHERE idretour = ?");
        $stmt_update_retour->bind_param("i", $idRetour);
        if (!$stmt_update_retour->execute()) {
            throw new Exception("Erreur de mise à jour du statut du retour: " . $stmt_update_retour->error);
        }
        $stmt_update_retour->close();

        // 4. Ajouter à l'historique
        $refs_string = implode(', ', array_map(fn($e) => $e['RefEchantillon'], $echantillons_a_retourner));
        $description = "M. {$_SESSION['user']['Prenom']} {$_SESSION['user']['Nom']} a refusé le retour de {$refs_string} (retour {$idRetour}).";
        ajouterHistorique($conn, $idUtilisateur, $refs_string, 'Retour', $description);

        $conn->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}


// Action pour approuver/refuser une demande
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['idDemande'])) {
    $id = intval($_POST['idDemande']);
    $idValidateur = isset($_SESSION['user']['id']) ? $_SESSION['user']['id'] : 1;
    $statut = ($_POST['action'] === 'approuver') ? 'Approuvée' : 'Refusée';

    $stmt = $conn->prepare("UPDATE Demande SET Statut=?, idValidateur=? WHERE idDemande=?");
    $stmt->bind_param("sii", $statut, $idValidateur, $id);
    $ok = $stmt->execute();

    // Ajout à l'historique (homogène)
    $echantillons = [];
    $refs = [];
    $resEch = $conn->query("SELECT refEchantillon, qte FROM DemandeEchantillon WHERE idDemande = $id");
    while ($e = $resEch->fetch_assoc()) {
        $ref = $e['refEchantillon'];
        $qte = $e['qte'];
        $refs[] = $ref;
        $resDet = $conn->query("SELECT famille, couleur, taille FROM Echantillon WHERE refEchantillon = '$ref' LIMIT 1");
        $rowDet = $resDet ? $resDet->fetch_assoc() : null;
        $famille = $rowDet['famille'] ?? '';
        $couleur = $rowDet['couleur'] ?? '';
        $taille = $rowDet['taille'] ?? '';
        $echantillons[] = "$ref (Qté : $qte, Famille : $famille, Couleur : $couleur, Taille : $taille)";
    }
    $detailsString = implode(', ', $echantillons);
    $typeAction = ($statut === 'Approuvée') ? 'Approbation' : 'Rejet';
    $description = "$typeAction de la demande $id : $detailsString";
    ajouterHistorique($conn, $idValidateur, implode(', ', $refs), $typeAction, $description);

    header('Content-Type: application/json');
    echo json_encode(['success' => $ok, 'error' => $stmt->error]);
    exit;
}

// Action pour enregistrer une fabrication
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fabrication_json'])) {
    $userId = $_SESSION['user']['id'] ?? 1;
    $date = date('Y-m-d H:i:s');
    $ok = true;
    $errorMsg = '';
    $data = json_decode($_POST['fabrication_json'], true);
    if (is_array($data) && !empty($data)) {
        $idLot = uniqid('fab_'); // Génère un identifiant unique pour le lot
        $idValidateur = null;
        $statut = 'En attente';
        $stmt = $conn->prepare("INSERT INTO Fabrication (RefEchantillon, idUtilisateur, idValidateur, DateCreation, StatutFabrication, Qte, idLot) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($data as $fab) {
            $ref = $fab['ref'];
            $qte = $fab['qte'];
            $stmt->bind_param("sisssis", $ref, $userId, $idValidateur, $date, $statut, $qte, $idLot);
            if (!$stmt->execute()) {
                $ok = false;
                $errorMsg .= $stmt->error . ' ';
            }
        }
    } else {
        $ok = false;
        $errorMsg = 'JSON mal formé ou vide';
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => $ok, 'error' => $errorMsg]);
    exit;
}

function getTextColor($bgHex) {
    // Convertit le code hex en RGB
    $bgHex = ltrim($bgHex, '#');
    $r = hexdec(substr($bgHex, 0, 2));
    $g = hexdec(substr($bgHex, 2, 2));
    $b = hexdec(substr($bgHex, 4, 2));
    // Calcul de la luminance
    $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    return ($luminance > 0.6) ? '#222' : '#fff'; // Noir si fond clair, blanc sinon
}

// Les échantillons sont déjà récupérés plus haut (ligne 456)

$colorMap = [
    'Rouge' => '#ef4444',
    'Noir' => '#374151',
    'Bleu' => '#3b82f6',
    'Argent' => '#9ca3af',
    'Blanc' => '#d1d5db',
    'Vert' => '#10b981',
    'Jaune' => '#f59e0b',
    'Violet' => '#8b5cf6',
    'Rose' => '#ec4899',
    'Orange' => '#f97316',
    'Beige' => '#f5f5dc',
    'Anthracite' => '#2d2d2d',
    'Marron' => '#7c4700',
    'Turquoise' => '#06b6d4',
    'Doré' => '#fbbf24',
    'Gris' => '#6b7280',
    'Bordeaux' => '#7c2d12',
    // Ajoute ici toutes les couleurs que tu veux gérer
];

// Charger les fabrications pour le centre de fabrication
$fabrications = [];
$resFab = $conn->query("SELECT f.*, e.famille AS Famille, e.couleur AS Couleur, e.taille AS Taille, f.refechantillon AS RefEchantillon, f.qte AS Qte, f.statutfabrication AS StatutFabrication, f.datecreation AS DateCreation, f.idlot AS idLot
                        FROM Fabrication f 
                        LEFT JOIN Echantillon e ON f.refechantillon = e.refechantillon 
                        WHERE f.idlot IS NOT NULL AND f.idlot != ''
                        ORDER BY f.datecreation DESC, f.idlot DESC");
$groupedFabrications = [];
if ($resFab) {
    while ($row = $resFab->fetch_assoc()) {
        $idLot = $row['idLot'] ?? $row['idlot'] ?? '';
        $groupedFabrications[$idLot][] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Chef de Stock</title>
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
                        <strong>Bienvenue sur le tableau de bord Chef de Stock !</strong>
                    </p>
                    <p class="text-sm">
                        Ce tableau de bord vous permet de gérer le stock des échantillons. Vous pouvez :
                    </p>
                    <ul class="list-disc list-inside text-sm space-y-2 ml-2">
                        <li><strong>Visualiser</strong> les statistiques du stock (total, disponible, en attente)</li>
                        <li><strong>Approuver ou rejeter</strong> les demandes d'échantillons</li>
                        <li><strong>Gérer les retours</strong> d'échantillons et les remettre en stock</li>
                        <li><strong>Suivre les fabrications</strong> et valider leur terminaison</li>
                        <li><strong>Consulter l'historique</strong> de toutes les opérations</li>
                    </ul>
                    <p class="text-sm mt-4 text-gray-600">
                        <i class="fas fa-boxes mr-1"></i>
                        Vous êtes responsable de la gestion complète du stock et de la validation des opérations.
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

    <!-- Main Application (Dashboard only, no login) -->
    <div id="mainApp">
        <!-- Header -->
        <header class="bg-white shadow-lg border-b border-gray-200">
            <div class="flex items-center justify-between px-6 py-4">
                <div class="flex items-center space-x-4">
                    <img src="photos/logo.png" alt="Logo" class="h-10 w-10 object-contain" />
                    <div>
                        <h1 class="text-2xl font-bold text-gray-800">Gestion d'Échantillons</h1>
                        <p class="text-sm text-gray-600" id="userRole">Chef de Stock - Tableau de bord</p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-4">
                    <button onclick="openInfoModal()" class="px-3 py-2 bg-blue-100 text-blue-600 rounded-lg hover:bg-blue-200 transition-colors" title="Aide">
                        <i class="fas fa-question-circle"></i>
                    </button>
                    <div class="flex items-center space-x-2">
                        <div id="userAvatar" class="w-8 h-8 bg-indigo-600 rounded-full flex items-center justify-center">
                            <i class="fas fa-user text-white text-sm"></i>
                        </div>
                        <div>
                            <p class="font-medium text-gray-800" id="userName">Utilisateur</p>
                            <p class="text-sm text-gray-600" id="userRoleText">Chef de Stock</p>
                        </div>
                    </div>
                    
                    <button id="logoutBtn" class="px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 transition-colors">
                        <i class="fas fa-sign-out-alt mr-2"></i>Déconnexion
                    </button>
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
                <!-- Dashboard -->
                <div id="dashboardSection" class="space-y-6">
                    <h3 class="text-xl font-semibold text-gray-700">Statistiques des Échantillons</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <div id="card-total-samples" class="card-hover bg-white p-6 rounded-lg shadow-md">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm text-gray-600">Total Échantillons</p>
                                    <p class="text-2xl font-bold text-gray-800" id="totalSamples"><?= $stats['totalSamples'] ?></p>
                                </div>
                                <i class="fas fa-vial text-3xl text-indigo-600"></i>
                            </div>
                        </div>
                        
                        <div id="card-available-samples" class="card-hover bg-white p-6 rounded-lg shadow-md cursor-pointer" data-content-type="available_samples">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm text-gray-600">Disponibles</p>
                                    <p class="text-2xl font-bold text-green-600" id="availableSamples"><?= $stats['availableSamples'] ?></p>
                                </div>
                                <i class="fas fa-check-circle text-3xl text-green-600"></i>
                            </div>
                        </div>
                        
                        <div id="card-borrowed-samples" class="card-hover bg-white p-6 rounded-lg shadow-md cursor-pointer" data-content-type="borrowed_samples">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm text-gray-600">Empruntés</p>
                                    <p class="text-2xl font-bold text-yellow-600" id="borrowedSamples"><?= $stats['borrowedSamples'] ?></p>
                                </div>
                                <i class="fas fa-hand-holding text-3xl text-yellow-600"></i>
                            </div>
                        </div>
                    </div>
                    
                    <h3 class="text-xl font-semibold text-gray-700 pt-4">Statistiques des Demandes</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <div id="card-pending-requests" class="card-hover bg-white p-6 rounded-lg shadow-md cursor-pointer" data-content-type="pending_requests">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm text-gray-600">Demandes en attente</p>
                                    <p class="text-2xl font-bold text-yellow-600"><?= $stats['pendingRequests'] ?></p>
                                </div>
                                <i class="fas fa-inbox text-3xl text-yellow-600"></i>
                            </div>
                        </div>
                        <div id="card-approved-requests" class="card-hover bg-white p-6 rounded-lg shadow-md cursor-pointer" data-content-type="approved_requests">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm text-gray-600">Demandes Approuvées</p>
                                    <p class="text-2xl font-bold text-green-600"><?= $stats['approvedRequests'] ?></p>
                                </div>
                                <i class="fas fa-check-double text-3xl text-green-600"></i>
                            </div>
                        </div>
                        <div id="card-rejected-requests" class="card-hover bg-white p-6 rounded-lg shadow-md cursor-pointer" data-content-type="rejected_requests">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm text-gray-600">Demandes Refusées</p>
                                    <p class="text-2xl font-bold text-red-600"><?= $stats['rejectedRequests'] ?></p>
                                </div>
                                <i class="fas fa-times-circle text-3xl text-red-600"></i>
                            </div>
                        </div>
                    </div>

                    <h3 class="text-xl font-semibold text-gray-700 pt-4">Statistiques des Retours</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <div id="card-pending-returns" class="card-hover bg-white p-6 rounded-lg shadow-md cursor-pointer" data-content-type="pending_returns">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm text-gray-600">Retours en attente</p>
                                    <p class="text-2xl font-bold text-yellow-600"><?= $stats['pendingReturns'] ?? 0 ?></p>
                                </div>
                                <i class="fas fa-clock text-3xl text-yellow-600"></i>
                            </div>
                        </div>
                        <div id="card-approved-returns" class="card-hover bg-white p-6 rounded-lg shadow-md cursor-pointer" data-content-type="approved_returns">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm text-gray-600">Retours Validés</p>
                                    <p class="text-2xl font-bold text-green-600"><?= $stats['approvedReturns'] ?? 0 ?></p>
                                </div>
                                <i class="fas fa-check-circle text-3xl text-green-600"></i>
                            </div>
                        </div>
                        <div id="card-rejected-returns" class="card-hover bg-white p-6 rounded-lg shadow-md cursor-pointer" data-content-type="rejected_returns">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm text-gray-600">Retours Refusés</p>
                                    <p class="text-2xl font-bold text-red-600"><?= $stats['rejectedReturns'] ?? 0 ?></p>
                                </div>
                                <i class="fas fa-times-circle text-3xl text-red-600"></i>
                            </div>
                        </div>
                    </div>

                    <h3 class="text-xl font-semibold text-gray-700 pt-4">Statistiques des Fabrications</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-2 gap-6">
                        <div id="card-pending-fabrications" class="card-hover bg-white p-6 rounded-lg shadow-md cursor-pointer" data-content-type="pending_fabrications">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm text-gray-600">Fabrications en cours</p>
                                    <p class="text-2xl font-bold text-orange-600"><?= $stats['pendingFabrications'] ?? 0 ?></p>
                                </div>
                                <i class="fas fa-cogs text-3xl text-orange-600"></i>
                            </div>
                        </div>
                        <div id="card-completed-fabrications" class="card-hover bg-white p-6 rounded-lg shadow-md cursor-pointer" data-content-type="completed_fabrications">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm text-gray-600">Fabrications Terminées</p>
                                    <p class="text-2xl font-bold text-purple-600"><?= $stats['completedFabrications'] ?? 0 ?></p>
                                </div>
                                <i class="fas fa-check-double text-3xl text-purple-600"></i>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div class="bg-white p-6 rounded-lg shadow-md">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Répartition des Échantillons</h3>
                            <div class="chart-container" style="height:300px">
                                <canvas id="samplesChart"></canvas>
                            </div>
                        </div>
                        
                        <div class="bg-white p-6 rounded-lg shadow-md">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Activité Récente</h3>
                            <div id="recentActivity" class="space-y-4">
                                <?php if (empty($recent_activities)): ?>
                                    <p class="text-center text-gray-400 py-4">Aucune activité récente.</p>
                                <?php else: ?>
                                    <?php foreach ($recent_activities as $activity):
                                        $icon = ''; $color = ''; $description = '';
                                        $user = htmlspecialchars($activity['Prenom'] . ' ' . $activity['Nom']);
                                        $details = htmlspecialchars($activity['details']);

                                        switch ($activity['type']) {
                                            case 'demande':
                                                $icon = 'fa-inbox'; $color = 'text-blue-500';
                                                $description = "<strong>{$user}</strong> a fait une demande pour: <strong>{$details}</strong>.";
                                                break;
                                            case 'retour':
                                                $icon = 'fa-undo'; $color = 'text-green-500';
                                                $description = "<strong>{$user}</strong> a enregistré un retour pour: <strong>{$details}</strong>.";
                                                break;
                                            case 'fabrication':
                                                $icon = 'fa-cog'; $color = 'text-purple-500';
                                                $description = "<strong>{$user}</strong> a lancé une fabrication pour: <strong>{$details}</strong>.";
                                                break;
                                        }
                                    ?>
                                    <div class="flex items-start">
                                        <div class="flex-shrink-0">
                                            <div class="w-8 h-8 rounded-full bg-gray-100 flex items-center justify-center">
                                                <i class="fas <?= $icon ?> <?= $color ?>"></i>
                                            </div>
                                        </div>
                                        <div class="ml-3">
                                            <p class="text-sm text-gray-700"><?= $description ?></p>
                                            <p class="text-xs text-gray-500"><?= date('d M Y, H:i', strtotime($activity['activity_date'])) ?></p>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Samples Management -->
                <div id="samplesSection" class="hidden space-y-6">
                    <div class="bg-white p-6 rounded-lg shadow-md">
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-xl font-semibold text-gray-800">Gestion des Échantillons</h2>
                            <div class="flex space-x-2">
                                <button id="launchFabricationBtn" class="btn-primary text-white px-4 py-2 rounded-lg font-medium">
                                    <i class="fas fa-cog mr-2"></i>Lancer fabrication
                                </button>
                                <button id="openCentreFabricationBtn" class="btn-primary text-white px-4 py-2 rounded-lg font-medium">
                                    <i class="fas fa-industry mr-2"></i>Centre de fabrication
                                </button>
                                <!-- Bouton Exporter supprimé -->
                            </div>
                        </div>
                        
                        <div class="mb-4 flex flex-wrap gap-4">
                            <div class="flex-1 min-w-64">
                                <input type="text" id="searchSamples" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" placeholder="Rechercher par référence, famille, couleur...">
                            </div>
                            <select id="filterStatus" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="">Tous les statuts</option>
                                <option value="disponible">Disponible</option>
                                <option value="emprunte">Emprunté</option>
                                <option value="fabrication">En fabrication</option>
                            </select>
                            <select id="filterFamily" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="">Toutes les familles</option>
                            </select>
                        </div>
                        
                        <div class="overflow-x-auto">
                            <table class="w-full table-auto">
                                <thead>
                                    <tr class="bg-gray-50">
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Référence</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Famille</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Couleur</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Taille</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Quantité</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Statut</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date Création</th>
                                    </tr>
                                </thead>
                                <tbody id="samplesTableBody">
                                    <?php 
                                    // Debug
                                    if (empty($echantillons)) {
                                        echo '<tr><td colspan="7" class="px-4 py-3 text-center text-red-600">⚠️ Aucun échantillon trouvé dans la base de données</td></tr>';
                                    }
                                    foreach ($echantillons as $sample): 
                                        // Gérer les deux cas : majuscules (alias) et minuscules (PostgreSQL)
                                        $refEchantillon = $sample['RefEchantillon'] ?? $sample['refechantillon'] ?? '';
                                        $famille = $sample['Famille'] ?? $sample['famille'] ?? '';
                                        $couleur = $sample['Couleur'] ?? $sample['couleur'] ?? '';
                                        $taille = $sample['Taille'] ?? $sample['taille'] ?? '';
                                        $qte = $sample['Qte'] ?? $sample['qte'] ?? 0;
                                        $statut = $sample['Statut'] ?? $sample['statut'] ?? '';
                                        $dateCreation = $sample['DateCreation'] ?? $sample['datecreation'] ?? '';
                                    ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-3 text-sm font-medium text-gray-900"><?= htmlspecialchars($refEchantillon) ?></td>
                                            <td class="px-4 py-3 text-sm text-gray-700"><?= htmlspecialchars($famille) ?></td>
                                            <td class="px-4 py-3 text-sm text-gray-700">
                                                <?php
                                                    $bg = isset($colorMap[$couleur]) ? $colorMap[$couleur] : '#6b7280';
                                                    $textColor = getTextColor($bg);
                                                ?>
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium"
                                                      style="background-color: <?= htmlspecialchars($bg) ?>; color: <?= htmlspecialchars($textColor) ?>;">
                                                    <?= htmlspecialchars($couleur) ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-gray-700"><?= htmlspecialchars($taille) ?></td>
                                            <td class="px-4 py-3 text-sm text-gray-700">
<?php
    // CORRECTION: Calcul correct des quantités
    $qteStock = (int)$qte; // Stock dans la base
    
    // Calculer la quantité empruntée (tous les statuts validés)
    $resEmprunt = $conn->query("
        SELECT SUM(de.qte) as total
        FROM DemandeEchantillon de
        JOIN Demande d ON d.idDemande = de.idDemande
        WHERE de.refEchantillon = '" . $conn->real_escape_string($refEchantillon) . "'
          AND d.Statut IN ('Approuvée', 'Validée', 'emprunte', 'Prêt pour retrait', 'En fabrication', 'Attente inter-service')
    ");
    $rowEmprunt = $resEmprunt ? $resEmprunt->fetch_assoc() : null;
    $qteEmpruntee = (int)($rowEmprunt['total'] ?? 0);
    
    // Calculer la quantité retournée
    $resRetour = $conn->query("
        SELECT SUM(re.qte) as total
        FROM RetourEchantillon re
        JOIN Retour r ON r.idRetour = re.idRetour
        WHERE re.refEchantillon = '" . $conn->real_escape_string($refEchantillon) . "'
          AND r.Statut IN ('Validé', 'Approuvé', 'Retourné')
    ");
    $rowRetour = $resRetour ? $resRetour->fetch_assoc() : null;
    $qteRetournee = (int)($rowRetour['total'] ?? 0);
    
    // Quantité réellement empruntée (non retournée)
    $qteEmprunteeNonRetournee = max(0, $qteEmpruntee - $qteRetournee);
    
    // Stock réellement disponible
    $qteReellementDisponible = max(0, $qteStock - $qteEmprunteeNonRetournee);
    
    // Affichage clair et détaillé des quantités
    echo "<div class='space-y-1'>";
    echo "<div class='text-sm'><span class='font-semibold text-blue-600'>Stock total:</span> $qteStock</div>";
    echo "<div class='text-sm'><span class='font-semibold text-green-600'>Disponible:</span> $qteReellementDisponible</div>";
    if ($qteEmprunteeNonRetournee > 0) {
        echo "<div class='text-sm'><span class='font-semibold text-red-600'>Emprunté:</span> $qteEmprunteeNonRetournee</div>";
    }
    if ($qteRetournee > 0) {
        echo "<div class='text-sm'><span class='font-semibold text-purple-600'>Retourné:</span> $qteRetournee</div>";
    }
    echo "</div>";
?>
                                            </td>
                                            <td class="px-4 py-3 text-sm">
                                                <?php
                                                    // CORRECTION: Statut basé sur les quantités réelles
                                                    $statutClass = 'bg-gray-100 text-gray-800';
                                                    $statutText = 'Inconnu';
                                                    
                                                    if ($qteReellementDisponible > 0 && $qteEmprunteeNonRetournee > 0) {
                                                        $statutClass = 'bg-yellow-100 text-yellow-800';
                                                        $statutText = 'Partiellement emprunté';
                                                    } elseif ($qteEmprunteeNonRetournee > 0) {
                                                        $statutClass = 'bg-red-100 text-red-800';
                                                        $statutText = 'Entièrement emprunté';
                                                    } elseif ($qteReellementDisponible > 0) {
                                                        $statutClass = 'bg-green-100 text-green-800';
                                                        $statutText = 'Disponible';
                                                    } else {
                                                        $statutClass = 'bg-gray-100 text-gray-800';
                                                        $statutText = 'Stock épuisé';
                                                    }
                                                ?>
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?= $statutClass ?>">
                                                    <?= htmlspecialchars($statutText) ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-gray-700"><?= htmlspecialchars(formatDateMoinsUneHeure($dateCreation)) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Requests Management -->
                <div id="requestsSection" class="hidden space-y-6">
                    <div class="bg-white p-6 rounded-lg shadow-md">
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-xl font-semibold text-gray-800" id="requestsTitle">Gestion des Demandes</h2>
                            <button id="newRequestBtn" class="btn-primary text-white px-4 py-2 rounded-lg font-medium hidden">
                                <i class="fas fa-rocket mr-2"></i>Nouvelle Demande
                            </button>
                        </div>
                        
                        <div class="mb-4 flex flex-wrap gap-4">
                            <div class="flex-1 min-w-64">
                                <input type="text" id="searchRequests" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" placeholder="Rechercher par échantillon, demandeur...">
                            </div>
                            <select id="filterRequestStatus" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="">Tous les statuts</option>
                                <option value="pending">En attente</option>
                                <option value="approved">Approuvée</option>
                                <option value="rejected">Rejetée</option>
                                <option value="fabrication">En fabrication</option>
                                <option value="returned">Retournée</option>
                                <option value="En attente">Retours en attente</option>
                                <option value="Validé">Retours validés</option>
                                <option value="Refusé">Retours refusés</option>
                            </select>
                        </div>

                        <div class="flex border-b mb-4">
                            <button id="showDemandesBtn" class="px-4 py-2 font-semibold border-b-2">Demandes</button>
                            <button id="showRetoursBtn" class="px-4 py-2 font-semibold border-b-2">Retours</button>
                            <button id="showFabricationsBtn" class="px-4 py-2 font-semibold border-b-2">Fabrications</button>
                        </div>
                        
                        <div class="overflow-x-auto">
                            <table class="w-full table-auto">
                                <thead>
                                    <tr class="bg-gray-50">
                                        <!-- <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th> -->
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Demandeur</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Échantillon</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Quantité</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Statut</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="requestsTableBody">
                                    <?php
                                    // --- Préparation des données pour le tableau unifié ---

                                    // 1. Récupérer les Demandes et Retours
                                    $demandes_et_retours = [];
                                    $res = $conn->query("SELECT d.*, u.nom AS NomDemandeur, u.prenom AS PrenomDemandeur
                                                         FROM Demande d
                                                         JOIN Utilisateur u ON d.idutilisateur = u.idutilisateur
                                                         ORDER BY d.datedemande DESC LIMIT 100");
                                    if ($res) {
                                    while ($row = $res->fetch_assoc()) {
                                        $row['echantillons'] = [];
                                        $idDemande = $row['idDemande'];
                                        $res2 = $conn->query("SELECT * FROM DemandeEchantillon WHERE idDemande = " . intval($idDemande));
                                        if($res2) {
                                            while ($e = $res2->fetch_assoc()) {
                                                $row['echantillons'][] = $e;
                                            }
                                        }
                                            $row['request_date'] = $row['DateDemande'] ?? ''; // Clé de tri commune
                                            $demandes_et_retours[] = $row;
                                    }
                                    }

                                    // 1b. Récupérer les retours de la table Retour
                                    $retours = [];
                                    $resRetours = $conn->query("SELECT r.*, u.nom AS NomDemandeur, u.prenom AS PrenomDemandeur
                                                                FROM Retour r
                                                                JOIN Utilisateur u ON r.idutilisateur = u.idutilisateur
                                                                ORDER BY r.DateRetour DESC");
                                    if ($resRetours) {
                                        while ($row = $resRetours->fetch_assoc()) {
                                            $row['echantillons'] = [];
                                            $idRetour = $row['idRetour'];
                                            $res2 = $conn->query("SELECT * FROM RetourEchantillon WHERE idRetour = " . intval($idRetour));
                                            if ($res2) {
                                                while ($e = $res2->fetch_assoc()) {
                                                    $row['echantillons'][] = $e;
                                                }
                                            }
                                            $row['request_date'] = $row['DateRetour'] ?? '';
                                            $row['TypeDemande'] = 'retour'; // Pour l'affichage/filtrage JS
                                            $retours[] = $row;
                                        }
                                    }

                                    // 2. Récupérer les Fabrications et les formater comme des demandes
                                    $fabrications_as_demandes = [];
                                    $resFab = $conn->query("SELECT f.idlot AS idLot, f.datecreation AS DateCreation, f.statutfabrication AS StatutFabrication, f.idutilisateur AS idUtilisateur, u.nom AS NomDemandeur, u.prenom AS PrenomDemandeur FROM Fabrication f JOIN Utilisateur u ON f.idutilisateur = u.idutilisateur WHERE f.idlot IS NOT NULL AND f.idlot != '' GROUP BY f.idlot, f.datecreation, f.statutfabrication, f.idutilisateur, u.nom, u.prenom ORDER BY f.datecreation DESC");
                                    if ($resFab) {
                                        while($lot = $resFab->fetch_assoc()) {
                                            $lot_details = [];
                                            $idLot = $lot['idLot'];
                                            $resDet = $conn->query("SELECT * FROM Fabrication WHERE idLot = '" . $conn->real_escape_string($idLot) . "'");
                                            if ($resDet) {
                                                while($det = $resDet->fetch_assoc()) {
                                                    $lot_details[] = ['refEchantillon' => $det['refEchantillon'], 'famille' => $det['famille'] ?? '', 'couleur' => $det['couleur'] ?? '', 'qte' => $det['qte'] ?? 0];
                                                }
                                            }
                                            $fabrications_as_demandes[] = [
                                                'idDemande' => $idLot,
                                                'NomDemandeur' => $lot['NomDemandeur'] ?? $lot['nom'] ?? '',
                                                'PrenomDemandeur' => $lot['PrenomDemandeur'] ?? $lot['prenom'] ?? '',
                                                'echantillons' => $lot_details,
                                                'DateDemande' => formatDateMoinsUneHeure($lot['DateCreation'] ?? ''),
                                                'Statut' => $lot['StatutFabrication'] ?? '',
                                                'TypeDemande' => 'fabrication',
                                                'request_date' => formatDateMoinsUneHeure($lot['DateCreation'] ?? ''),
                                            ];
                                        }
                                    }

                                    // 3. Fusionner et trier
                                    $all_requests = array_merge($demandes_et_retours, $retours, $fabrications_as_demandes);
                                    usort($all_requests, function($a, $b) {
                                        return strtotime($b['request_date']) - strtotime($a['request_date']);
                                    });
                                    ?>
                                    <?php foreach ($all_requests as $demande): ?>
                                        <tr class="hover:bg-gray-50" data-type="<?= htmlspecialchars(strtolower($demande['TypeDemande'] ?? 'demande')) ?>">
                                            <!-- <td class="px-4 py-3 text-sm font-medium text-gray-900"><?= htmlspecialchars($demande['idDemande']) ?></td> -->
                                            <td class="px-4 py-3 text-sm text-gray-700">
                                                <?= htmlspecialchars($demande['PrenomDemandeur'] . ' ' . $demande['NomDemandeur']) ?>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-gray-700">
                                                <?php foreach ($demande['echantillons'] as $e): ?>
                                                    <div>
                                                        <?= htmlspecialchars($e['refEchantillon'] ?? '') ?>
                                                        <?php if (isset($e['famille'])): ?> (<?= htmlspecialchars($e['famille']) ?>)<?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-gray-700">
                                                <?php if (strtolower(trim($demande['TypeDemande'] ?? '')) === 'retour'): ?>
                                                <?php foreach ($demande['echantillons'] as $e): ?>
                                                        <div><?= htmlspecialchars($e['qte'] ?? '') ?></div>
                                                <?php endforeach; ?>
                                                <?php else: ?>
                                                    <?php foreach ($demande['echantillons'] as $e): ?>
                                                        <div>1</div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-gray-700">
                                                <?php 
                                                    $dateToShow = $demande['DateDemande'] ?? $demande['DateRetour'] ?? $demande['request_date'];
                                                    echo htmlspecialchars($dateToShow);
                                                ?>
                                            </td>
                                            <td class="px-4 py-3 text-sm">
                                                    <?php
                                                        $statut = strtolower(trim($demande['Statut']));
                                                    $type = strtolower(trim($demande['TypeDemande']));

                                                    $statutClass = '';
                                                    if ($statut === 'en attente') $statutClass = 'bg-yellow-100 text-yellow-800';
                                                    elseif ($statut === 'approuvée') $statutClass = 'bg-green-100 text-green-800';
                                                    elseif ($statut === 'refusée') $statutClass = 'bg-red-100 text-red-800';
                                                    elseif ($statut === 'retourné') $statutClass = 'bg-blue-100 text-blue-800';
                                                    elseif ($statut === 'prêt pour retrait') $statutClass = 'bg-green-200 text-green-900 font-semibold';
                                                    elseif ($statut === 'en fabrication') $statutClass = 'bg-purple-100 text-purple-800';
                                                    elseif ($statut === 'attente inter-service') $statutClass = 'bg-gray-200 text-gray-800';
                                                    // Pour les retours, utiliser les mêmes couleurs que les demandes
                                                    elseif ($statut === 'validé') $statutClass = 'bg-green-100 text-green-800';
                                                    elseif ($statut === 'refusé') $statutClass = 'bg-red-100 text-red-800';
                                                    else $statutClass = 'bg-gray-100 text-gray-800';
                                                ?>
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?= $statutClass ?>">
                                                    <?= htmlspecialchars(ucfirst($demande['Statut'])) ?>
                                                </span>
                                                <?php if ($type === 'demande' && $statut === 'approuvée'): ?>
                                                    <select onchange="updateApprovedStatus(<?= $demande['idDemande'] ?>, this.value)" class="mt-2 w-full px-2 py-1 border border-gray-300 rounded-lg text-xs focus:outline-none focus:ring-1 focus:ring-indigo-500">
                                                        <option value="">-- Choisir une action --</option>
                                                        <option value="Prêt pour retrait">Disponible (Prêt)</option>
                                                        <option value="En fabrication">Stock 0 (Fabrication)</option>
                                                        <option value="Attente inter-service">Emprunté (Attente)</option>
                                                    </select>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-gray-700"><?= htmlspecialchars(ucfirst($demande['TypeDemande'] ?? 'demande')) ?></td>
                                            <td class="px-4 py-3 text-sm">
                                                <?php if ($demande['TypeDemande'] === 'fabrication'): ?>
                                                    <?php if (strtolower(trim($demande['Statut'])) !== 'terminée'): ?>
                                                        <button onclick="editLot('<?= htmlspecialchars($demande['idDemande']) ?>')" class="text-indigo-600 hover:text-indigo-900 mr-2" title="Modifier"><i class="fas fa-edit"></i></button>
                                                        <button onclick="deleteLot('<?= htmlspecialchars($demande['idDemande']) ?>')" class="text-red-600 hover:text-red-900" title="Supprimer"><i class="fas fa-trash"></i></button>
                                                        <button onclick="completeLot('<?= htmlspecialchars($demande['idDemande']) ?>')" class="text-green-600 hover:text-green-900 mr-2" title="Confirmer et Recevoir la Fabrication"><i class="fas fa-check-square"></i></button>
                                                    <?php endif; ?>
                                                <?php elseif (strtolower(trim($demande['TypeDemande'])) === 'retour' && strtolower(trim($demande['Statut'])) === 'en attente'): ?>
                                                    <button onclick="acceptRetour(<?= $demande['idRetour'] ?>)" class="text-green-600 hover:text-green-900" title="Approuver">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                    <button onclick="refuseRetour(<?= $demande['idRetour'] ?>)" class="text-red-600 hover:text-red-900" title="Refuser">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                <?php elseif (strtolower(trim($demande['TypeDemande'])) === 'demande' && strtolower(trim($demande['Statut'])) === 'en attente'): ?>
                                                    <button onclick="approuverDemande('<?= $demande['idDemande'] ?>')" class="text-green-600 hover:text-green-900" title="Approuver">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                    <button onclick="refuserDemande('<?= $demande['idDemande'] ?>')" class="text-red-600 hover:text-red-900" title="Refuser">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- History Section -->
                <div id="historySection" class="hidden space-y-6">
                    <div class="bg-white p-6 rounded-lg shadow-md">
                        <h2 class="text-xl font-semibold text-gray-800 mb-6">Historique et Traçabilité</h2>
                        <div class="mb-4 flex flex-wrap gap-4">
                            <input type="date" id="historyDateStart" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <input type="date" id="historyDateEnd" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <select id="historyAction" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="">Toutes les actions</option>
                                <?php
                                $actions = $conn->query("SELECT DISTINCT TypeAction FROM Historique ORDER BY TypeAction")->fetch_all(MYSQLI_ASSOC);
                                foreach ($actions as $action) {
                                    echo '<option value="' . htmlspecialchars($action['TypeAction']) . '">' . htmlspecialchars($action['TypeAction']) . '</option>';
                                }
                                ?>
                            </select>
                            <select id="historyUser" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="">Tous les utilisateurs</option>
                                <?php
                                $users_query = $conn->query("SELECT DISTINCT U.prenom AS Prenom, U.nom AS Nom FROM Historique H JOIN Utilisateur U ON H.idutilisateur = U.idutilisateur ORDER BY U.prenom, U.nom");
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
                                foreach ($users as $user) {
                                    $fullName = $user['Prenom'] . ' ' . $user['Nom'];
                                    echo '<option value="' . htmlspecialchars($fullName) . '">' . htmlspecialchars($fullName) . '</option>';
                                }
                                ?>
                            </select>
                            <select id="historyRole" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="">Tous les rôles</option>
                                <?php
                                $roles = $conn->query("SELECT DISTINCT r.Role FROM Utilisateur u JOIN Role r ON u.idRole = r.idRole ORDER BY r.Role")->fetch_all(MYSQLI_ASSOC);
                                foreach ($roles as $role) {
                                    echo '<option value="' . htmlspecialchars($role['Role']) . '">' . htmlspecialchars($role['Role']) . '</option>';
                                }
                                ?>
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
                                    <tbody id="historyTableBody">
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
    <!-- Add/Edit Sample Modal -->
    <div id="sampleModal" class="fixed inset-0 bg-black bg-opacity-50 modal hidden flex items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-2xl w-full max-w-md m-4 fade-in">
            <div class="flex items-center justify-between p-6 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800" id="sampleModalTitle">Nouvel Échantillon</h3>
                <button class="text-gray-400 hover:text-gray-600 close-modal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="sampleForm" class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Référence</label>
                    <input type="text" id="sampleRef" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Famille</label>
                    <input type="text" id="sampleFamily" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Couleur</label>
                    <input type="text" id="sampleColor" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Taille</label>
                    <select id="sampleSize" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
                        <option value="">Sélectionner une taille</option>
                        <option value="XS">XS</option>
                        <option value="S">S</option>
                        <option value="M">M</option>
                        <option value="L">L</option>
                        <option value="XL">XL</option>
                        <option value="XXL">XXL</option>
                        <option value="Unique">Unique</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                    <textarea id="sampleDescription" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" rows="3" placeholder="Description optionnelle"></textarea>
                </div>
                <div class="flex justify-end space-x-3">
                    <button type="button" class="px-4 py-2 text-gray-600 border border-gray-300 rounded-lg hover:bg-gray-50 close-modal">Annuler</button>
                    <button type="submit" class="btn-primary text-white px-4 py-2 rounded-lg font-medium">
                        <span id="sampleSubmitText">Ajouter</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- New Request Modal -->
    <div id="requestModal" class="fixed inset-0 bg-black bg-opacity-50 modal hidden flex items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-2xl w-full max-w-md m-4 fade-in">
            <div class="flex items-center justify-between p-6 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800">Nouvelle Demande</h3>
                <button class="text-gray-400 hover:text-gray-600 close-modal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="requestForm" class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Échantillon</label>
                    <select id="requestSample" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
                        <option value="">Sélectionner un échantillon</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Quantité</label>
                    <input type="number" id="requestQuantity" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" min="1" value="1" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Urgence</label>
                    <select id="requestUrgency" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
                        <option value="normale">Normale</option>
                        <option value="urgente">Urgente</option>
                        <option value="critique">Critique</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Date limite souhaitée</label>
                    <input type="date" id="requestDeadline" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Commentaire</label>
                    <textarea id="requestComment" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" rows="3" placeholder="Commentaire optionnel"></textarea>
                </div>
                <div class="flex justify-end space-x-3">
                    <button type="button" class="px-4 py-2 text-gray-600 border border-gray-300 rounded-lg hover:bg-gray-50 close-modal">Annuler</button>
                    <button type="submit" class="btn-primary text-white px-4 py-2 rounded-lg font-medium">
                        <i class="fas fa-rocket mr-2"></i>Envoyer
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Fabrication Modal (remplacé pour être plus complet) -->
    <div id="fabricationModal" class="fixed inset-0 bg-black bg-opacity-50 modal hidden flex items-center justify-center z-50 overflow-y-auto py-8">
        <div class="bg-white rounded-lg shadow-2xl w-full max-w-2xl m-4 fade-in max-h-[90vh] flex flex-col">
            <div class="flex items-center justify-between p-4 border-b bg-gray-50 rounded-t-lg">
                <h3 id="fabricationModalTitle" class="text-lg font-semibold">Nouvelle Fabrication</h3>
                <button onclick="closeModal('fabrication')" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="formFabrication" method="POST">
                <input type="hidden" name="fabrication" value="1">
                <input type="hidden" name="echantillons_json" id="echantillons_fabrication_json">
                <div class="p-6 space-y-4 overflow-y-auto flex-1" style="max-height: 80vh;">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Sélectionner un échantillon</label>
                        <input type="text" id="searchEchantillonFabrication" placeholder="Rechercher un échantillon..." class="w-full px-3 py-2 mb-2 border rounded">
                        <select id="echantillonFabricationSelect" class="w-full px-3 py-2 border rounded focus:ring-2 focus:ring-blue-500">
                            <option value="">Choisir un échantillon...</option>
                            <?php foreach ($echantillons as $sample): 
                                $refEch = $sample['RefEchantillon'] ?? $sample['refechantillon'] ?? '';
                                $fam = $sample['Famille'] ?? $sample['famille'] ?? '';
                                $coul = $sample['Couleur'] ?? $sample['couleur'] ?? '';
                                $tail = $sample['Taille'] ?? $sample['taille'] ?? '';
                                $qt = $sample['Qte'] ?? $sample['qte'] ?? 0;
                            ?>
                                    <option
                                        value="<?= htmlspecialchars($refEch) ?>"
                                        data-famille="<?= htmlspecialchars($fam) ?>"
                                        data-couleur="<?= htmlspecialchars($coul) ?>"
                                        data-taille="<?= htmlspecialchars($tail) ?>"
                                        data-qte="<?= htmlspecialchars($qt) ?>"
                                    >
                                        <?= htmlspecialchars($refEch) ?> – <?= htmlspecialchars($fam) ?> – <?= htmlspecialchars($coul) ?> – <?= htmlspecialchars($tail) ?> (Stock: <?= htmlspecialchars($qt) ?>)
                                    </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Famille</label>
                            <input type="text" id="familleFabrication" class="w-full px-3 py-2 border rounded bg-gray-100" readonly>
                </div>
                <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Couleur</label>
                            <input type="text" id="couleurFabrication" class="w-full px-3 py-2 border rounded bg-gray-100" readonly>
                </div>
                <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Taille</label>
                            <input type="text" id="tailleFabrication" class="w-full px-3 py-2 border rounded bg-gray-100" readonly>
                </div>
                <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Stock disponible</label>
                            <input type="text" id="stockFabrication" class="w-full px-3 py-2 border rounded bg-gray-100" readonly>
                </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Quantité à fabriquer</label>
                        <input id="qteFabrication" type="number" class="w-full px-3 py-2 border rounded" min="1" value="1">
                    </div>
                    <div class="flex justify-end p-4 bg-gray-50 border-t space-x-3 rounded-b-lg">
                        <button type="button" onclick="addFabricationTemp()" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                            <i class="fas fa-plus mr-2"></i>Ajouter à la fabrication
                        </button>
                        <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                            <i class="fas fa-save mr-2"></i>Valider la fabrication
                        </button>
                        <button type="button" onclick="closeModal('fabrication')" class="px-4 py-2 text-gray-600 border rounded hover:bg-gray-50">
                            <i class="fas fa-times mr-2"></i>Annuler
                    </button>
                    </div>
                    <div class="overflow-x-auto mt-4">
                        <h3 class="text-lg font-semibold text-gray-800 mb-3">Échantillons à fabriquer</h3>
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
                            <tbody id="fabricationTempTableBody">
                                <!-- Les échantillons sélectionnés seront affichés ici -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Centre de fabrication -->
    <div id="centreFabricationModal" class="fixed inset-0 bg-black bg-opacity-50 modal hidden flex items-center justify-center z-50 overflow-y-auto py-8">
        <div class="bg-white rounded-lg shadow-2xl w-full max-w-4xl m-4 fade-in max-h-[90vh] flex flex-col">
            <div class="flex items-center justify-between p-4 border-b bg-gray-50 rounded-t-lg">
                <h3 class="text-lg font-semibold">Centre de fabrication</h3>
                <button onclick="closeModal('centreFabrication')" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-6 space-y-4 overflow-y-auto flex-1" style="max-height: 80vh;">
                <table class="w-full table-auto">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Référence</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Famille</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Couleur</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Taille</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Quantité</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Statut</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="centreFabricationTableBody">
                        <?php if (empty($groupedFabrications)): ?>
                            <tr><td colspan="8" class="text-center text-gray-400 py-6">Aucune fabrication enregistrée</td></tr>
                        <?php else: ?>
                            <?php $isFirstLot = true; ?>
                            <?php foreach ($groupedFabrications as $idLot => $group): ?>
                                <?php $rowspan = count($group); ?>
                                <?php foreach ($group as $i => $fab): ?>
                                    <tr class="<?= ($i === 0 && !$isFirstLot) ? 'border-t-2 border-gray-200' : '' ?>">
                                        <?php
                                        $refEch = $fab['RefEchantillon'] ?? $fab['refechantillon'] ?? '';
                                        $fam = $fab['Famille'] ?? $fab['famille'] ?? '';
                                        $coul = $fab['Couleur'] ?? $fab['couleur'] ?? '';
                                        $tail = $fab['Taille'] ?? $fab['taille'] ?? '';
                                        $qt = $fab['Qte'] ?? $fab['qte'] ?? 0;
                                        $statutFab = $fab['StatutFabrication'] ?? $fab['statutfabrication'] ?? '';
                                        $dateCre = $fab['DateCreation'] ?? $fab['datecreation'] ?? '';
                                        ?>
                                        <td class="px-4 py-3 text-sm font-medium text-gray-900"><?= htmlspecialchars($refEch) ?></td>
                                        <td class="px-4 py-3 text-sm text-gray-700"><?= htmlspecialchars($fam) ?></td>
                                        <td class="px-4 py-3 text-sm text-gray-700"><?= htmlspecialchars($coul) ?></td>
                                        <td class="px-4 py-3 text-sm text-gray-700"><?= htmlspecialchars($tail) ?></td>
                                        <td class="px-4 py-3 text-sm text-gray-700"><?= htmlspecialchars($qt) ?></td>
                                        <?php if ($i === 0): ?>
                                            <td class="px-4 py-3 text-sm text-blue-700 font-semibold" rowspan="<?= $rowspan ?>"><?= htmlspecialchars($statutFab) ?></td>
                                            <td class="px-4 py-3 text-sm text-gray-700" rowspan="<?= $rowspan ?>"><?= htmlspecialchars(formatDateMoinsUneHeure($dateCre)) ?></td>
                                            <td class="px-4 py-3 text-sm" rowspan="<?= $rowspan ?>">
                                                <?php if (strtolower($statutFab) !== 'terminée'): ?>
                                                    <button onclick="editLot('<?= htmlspecialchars($idLot) ?>')" class="text-indigo-600 hover:text-indigo-900 mr-2" title="Modifier"><i class="fas fa-edit"></i></button>
                                                    <button onclick="deleteLot('<?= htmlspecialchars($idLot) ?>')" class="text-red-600 hover:text-red-900" title="Supprimer"><i class="fas fa-trash"></i></button>
                                                    <button onclick="completeLot('<?= htmlspecialchars($idLot) ?>')" class="text-green-600 hover:text-green-900" title="Confirmer et Recevoir la Fabrication"><i class="fas fa-check-square"></i></button>
                                                <?php endif; ?>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                                <?php $isFirstLot = false; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            </div>
        </div>

    <!-- Edit Fabrication Modal -->
    <div id="editFabricationModal" class="fixed inset-0 bg-black bg-opacity-50 modal hidden flex items-center justify-center z-50 overflow-y-auto py-8">
        <div class="bg-white rounded-lg shadow-2xl w-full max-w-3xl m-4 fade-in max-h-[90vh] flex flex-col">
            <div class="flex items-center justify-between p-4 border-b bg-gray-50 rounded-t-lg">
                <h3 class="text-lg font-semibold">Modifier la Fabrication <span id="editLotId" class="text-indigo-600 font-mono text-sm"></span></h3>
                <button onclick="closeModal('editFabrication')" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
    </div>
            <form id="formEditFabrication">
                <input type="hidden" id="editFabricationIdLot" name="idLot">
                
                <div class="p-6 space-y-4 overflow-y-auto flex-1">
                    <!-- Table of items currently in the lot -->
                    <div class="overflow-x-auto">
                        <h3 class="text-lg font-semibold text-gray-800 mb-3">Échantillons du lot</h3>
                        <table class="w-full table-auto">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Référence</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Détails</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Quantité</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="editFabricationTableBody">
                                <!-- Rempli par JavaScript -->
                            </tbody>
                        </table>
                    </div>

                    <!-- Section to add new samples -->
                    <div class="border-t pt-4 mt-4">
                         <h3 class="text-lg font-semibold text-gray-800 mb-3">Ajouter un échantillon</h3>
                         <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Sélectionner un échantillon disponible</label>
                            <select id="addEchantillonToLotSelect" class="w-full px-3 py-2 border rounded focus:ring-2 focus:ring-blue-500">
                               <!-- Rempli par JavaScript -->
                            </select>
                        </div>
                        <div class="flex items-end gap-4">
                            <div class="flex-grow">
                                 <label class="block text-sm font-medium text-gray-700 mb-2">Quantité à ajouter</label>
                                 <input id="qteAddToLot" type="number" class="w-full px-3 py-2 border rounded" min="1" value="1">
                            </div>
                            <button type="button" onclick="addSampleToEditList()" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 h-10">
                                <i class="fas fa-plus mr-2"></i>Ajouter au lot
                            </button>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end p-4 bg-gray-50 border-t space-x-3 rounded-b-lg">
                    <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                        <i class="fas fa-save mr-2"></i>Sauvegarder les modifications
                    </button>
                    <button type="button" onclick="closeModal('editFabrication')" class="px-4 py-2 text-gray-600 border rounded hover:bg-gray-50">
                        <i class="fas fa-times mr-2"></i>Annuler
                    </button>
                </div>
            </form>
        </div>
    </div>


    <!-- Notifications Container -->
    <div id="notificationsContainer" class="fixed top-4 right-4 space-y-2 z-50">
        <!-- Notifications will be added here -->
    </div>

    <!-- Modal de confirmation pour accepter une demande -->
    <div id="confirmAcceptDemandeModal" class="fixed inset-0 bg-black bg-opacity-50 modal hidden flex items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 max-w-md w-full mx-4">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900">Confirmer l'approbation</h3>
                <button class="text-gray-400 hover:text-gray-600" onclick="document.getElementById('confirmAcceptDemandeModal').classList.add('hidden')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <p class="text-gray-700 mb-6">Êtes-vous sûr de vouloir approuver cette demande ?</p>
            <input type="hidden" id="confirmAcceptDemandeId" value="">
            <div class="flex justify-end space-x-3">
                <button onclick="document.getElementById('confirmAcceptDemandeModal').classList.add('hidden')" class="px-4 py-2 text-gray-600 bg-gray-200 rounded-lg hover:bg-gray-300">
                    Annuler
                </button>
                <button onclick="confirmAcceptDemande()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    Approuver
                </button>
            </div>
        </div>
    </div>

    <!-- Modal de confirmation pour refuser une demande -->
    <div id="confirmRefuseDemandeModal" class="fixed inset-0 bg-black bg-opacity-50 modal hidden flex items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 max-w-md w-full mx-4">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900">Confirmer le refus</h3>
                <button class="text-gray-400 hover:text-gray-600" onclick="document.getElementById('confirmRefuseDemandeModal').classList.add('hidden')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <p class="text-gray-700 mb-6">Êtes-vous sûr de vouloir refuser cette demande ?</p>
            <input type="hidden" id="confirmRefuseDemandeId" value="">
            <div class="flex justify-end space-x-3">
                <button onclick="document.getElementById('confirmRefuseDemandeModal').classList.add('hidden')" class="px-4 py-2 text-gray-600 bg-gray-200 rounded-lg hover:bg-gray-300">
                    Annuler
                </button>
                <button onclick="confirmRefuseDemande()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                    Refuser
                </button>
            </div>
        </div>
    </div>

    <!-- Modal de confirmation pour accepter un retour -->
    <div id="confirmAcceptRetourModal" class="fixed inset-0 bg-black bg-opacity-50 modal hidden flex items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 max-w-md w-full mx-4">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900">Confirmer l'acceptation</h3>
                <button class="text-gray-400 hover:text-gray-600" onclick="document.getElementById('confirmAcceptRetourModal').classList.add('hidden')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <p class="text-gray-700 mb-6">Êtes-vous sûr de vouloir accepter ce retour ?</p>
            <input type="hidden" id="confirmAcceptRetourId" value="">
            <div class="flex justify-end space-x-3">
                <button onclick="document.getElementById('confirmAcceptRetourModal').classList.add('hidden')" class="px-4 py-2 text-gray-600 bg-gray-200 rounded-lg hover:bg-gray-300">
                    Annuler
                </button>
                <button onclick="confirmAcceptRetour()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    Accepter
                </button>
            </div>
        </div>
    </div>

    <!-- Modal de confirmation pour refuser un retour -->
    <div id="confirmRefuseRetourModal" class="fixed inset-0 bg-black bg-opacity-50 modal hidden flex items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 max-w-md w-full mx-4">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900">Confirmer le refus</h3>
                <button class="text-gray-400 hover:text-gray-600" onclick="document.getElementById('confirmRefuseRetourModal').classList.add('hidden')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <p class="text-gray-700 mb-6">Êtes-vous sûr de vouloir refuser ce retour ?</p>
            <input type="hidden" id="confirmRefuseRetourId" value="">
            <div class="flex justify-end space-x-3">
                <button onclick="document.getElementById('confirmRefuseRetourModal').classList.add('hidden')" class="px-4 py-2 text-gray-600 bg-gray-200 rounded-lg hover:bg-gray-300">
                    Annuler
                </button>
                <button onclick="confirmRefuseRetour()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                    Refuser
                </button>
            </div>
        </div>
    </div>

    <!-- Modal de confirmation pour supprimer un lot de fabrication -->
    <div id="confirmDeleteLotModal" class="fixed inset-0 bg-black bg-opacity-50 modal hidden flex items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 max-w-md w-full mx-4">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900">Confirmer la suppression</h3>
                <button class="text-gray-400 hover:text-gray-600" onclick="document.getElementById('confirmDeleteLotModal').classList.add('hidden')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <p class="text-gray-700 mb-6">Êtes-vous sûr de vouloir supprimer ce lot de fabrication ?</p>
            <input type="hidden" id="confirmDeleteLotId" value="">
            <div class="flex justify-end space-x-3">
                <button onclick="document.getElementById('confirmDeleteLotModal').classList.add('hidden')" class="px-4 py-2 text-gray-600 bg-gray-200 rounded-lg hover:bg-gray-300">
                    Annuler
                </button>
                <button onclick="confirmDeleteLot()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                    Supprimer
                </button>
            </div>
        </div>
    </div>

    <!-- Modal de confirmation pour compléter un lot de fabrication -->
    <div id="confirmCompleteLotModal" class="fixed inset-0 bg-black bg-opacity-50 modal hidden flex items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 max-w-md w-full mx-4">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900">Confirmer la réception</h3>
                <button class="text-gray-400 hover:text-gray-600" onclick="document.getElementById('confirmCompleteLotModal').classList.add('hidden')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <p class="text-gray-700 mb-6">Êtes-vous sûr de vouloir confirmer et recevoir cette fabrication ?</p>
            <input type="hidden" id="confirmCompleteLotId" value="">
            <div class="flex justify-end space-x-3">
                <button onclick="document.getElementById('confirmCompleteLotModal').classList.add('hidden')" class="px-4 py-2 text-gray-600 bg-gray-200 rounded-lg hover:bg-gray-300">
                    Annuler
                </button>
                <button onclick="confirmCompleteLot()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    Confirmer
                </button>
            </div>
        </div>
    </div>

    <script>
        console.log('JS chargé');

        // FONCTIONS GLOBALES POUR LES MODALES - DOIVENT ÊTRE DÉFINIES EN PREMIER
        function approuverDemande(idDemande) {
            console.log('approuverDemande appelée avec ID:', idDemande);
            const idInput = document.getElementById('confirmAcceptDemandeId');
            const modal = document.getElementById('confirmAcceptDemandeModal');
            
            if (!idInput) {
                console.error('Élément confirmAcceptDemandeId non trouvé');
                return;
            }
            if (!modal) {
                console.error('Élément confirmAcceptDemandeModal non trouvé');
                return;
            }
            
            idInput.value = idDemande;
            modal.classList.remove('hidden');
        }
        
        function refuserDemande(idDemande) {
            console.log('refuserDemande appelée avec ID:', idDemande);
            const idInput = document.getElementById('confirmRefuseDemandeId');
            const modal = document.getElementById('confirmRefuseDemandeModal');
            
            if (!idInput) {
                console.error('Élément confirmRefuseDemandeId non trouvé');
                return;
            }
            if (!modal) {
                console.error('Élément confirmRefuseDemandeModal non trouvé');
                return;
            }
            
            idInput.value = idDemande;
            modal.classList.remove('hidden');
        }

        function acceptRetour(idRetour) {
            console.log('acceptRetour appelée avec ID:', idRetour);
            const idInput = document.getElementById('confirmAcceptRetourId');
            const modal = document.getElementById('confirmAcceptRetourModal');
            
            if (!idInput) {
                console.error('Élément confirmAcceptRetourId non trouvé');
                return;
            }
            if (!modal) {
                console.error('Élément confirmAcceptRetourModal non trouvé');
                return;
            }
            
            idInput.value = idRetour;
            modal.classList.remove('hidden');
        }

        function refuseRetour(idRetour) {
            console.log('refuseRetour appelée avec ID:', idRetour);
            const idInput = document.getElementById('confirmRefuseRetourId');
            const modal = document.getElementById('confirmRefuseRetourModal');
            
            if (!idInput) {
                console.error('Élément confirmRefuseRetourId non trouvé');
                return;
            }
            if (!modal) {
                console.error('Élément confirmRefuseRetourModal non trouvé');
                return;
            }
            
            idInput.value = idRetour;
            modal.classList.remove('hidden');
        }

        function deleteLot(idLot) {
            console.log('deleteLot appelée avec ID:', idLot);
            const idInput = document.getElementById('confirmDeleteLotId');
            const modal = document.getElementById('confirmDeleteLotModal');
            
            if (!idInput) {
                console.error('Élément confirmDeleteLotId non trouvé');
                return;
            }
            if (!modal) {
                console.error('Élément confirmDeleteLotModal non trouvé');
                return;
            }
            
            idInput.value = idLot;
            modal.classList.remove('hidden');
        }

        function completeLot(idLot) {
            console.log('completeLot appelée avec ID:', idLot);
            const idInput = document.getElementById('confirmCompleteLotId');
            const modal = document.getElementById('confirmCompleteLotModal');
            
            if (!idInput) {
                console.error('Élément confirmCompleteLotId non trouvé');
                return;
            }
            if (!modal) {
                console.error('Élément confirmCompleteLotModal non trouvé');
                return;
            }
            
            idInput.value = idLot;
            modal.classList.remove('hidden');
        }

        function closeModal(modalName) {
            const modalId = modalName + 'Modal';
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('hidden');
            }
        }

        // --- FONCTIONS UTILITAIRES ET D'AFFICHAGE UNIQUEMENT ---

        // Affichage des échantillons (à remplir dynamiquement)
        function renderSamplesTable(samples) {
            const tbody = document.getElementById('samplesTableBody');
            tbody.innerHTML = (samples && samples.length) ? samples.map(sample => `
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-sm font-medium text-gray-900">${sample.ref}</td>
                    <td class="px-4 py-3 text-sm text-gray-700">${sample.family}</td>
                    <td class="px-4 py-3 text-sm text-gray-700">
                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium" style="background-color: ${getColorForSample(sample.color)}; color: white;">
                            ${sample.color}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-700">${sample.size}</td>
                    <td class="px-4 py-3 text-sm text-gray-700">${sample.quantity}</td>
                    <td class="px-4 py-3 text-sm">
                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${getStatusClass(sample.status)}">
                            ${getStatusText(sample.status)}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-700">${formatDate(sample.createdAt)}</td>
                    <td class="px-4 py-3 text-sm">
                        <div class="flex space-x-2">
                            <button onclick="viewSample('${sample.ref}')" class="text-blue-600 hover:text-blue-900" title="Voir détails">
                                <i class="fas fa-eye"></i>
                            </button>
                            <!-- Autres actions à ajouter dynamiquement -->
                        </div>
                    </td>
                </tr>
            `).join('') : '<tr><td colspan="7" class="text-center text-gray-400 py-6">Aucun échantillon</td></tr>';
        }

        // Fonctions utilitaires (à garder)
        function getColorForSample(color) {
            const colors = {
                'Rouge': '#ef4444',
                'Noir': '#374151',
                'Bleu': '#3b82f6',
                'Argent': '#9ca3af',
                'Blanc': '#d1d5db',
                'Vert': '#10b981',
                'Jaune': '#f59e0b',
                'Violet': '#8b5cf6',
                'Rose': '#ec4899',
                'Orange': '#f97316'
            };
            return colors[color] || '#6b7280';
        }
        function getStatusClass(status) {
            const classes = {
                'disponible': 'bg-green-100 text-green-800',
                'emprunte': 'bg-yellow-100 text-yellow-800',
                'fabrication': 'bg-blue-100 text-blue-800'
            };
            return classes[status] || 'bg-gray-100 text-gray-800';
        }
        function getStatusText(status) {
            const texts = {
                'disponible': 'Disponible',
                'emprunte': 'Emprunté',
                'fabrication': 'En fabrication'
            };
            return texts[status] || status;
        }
        function formatDate(dateString) {
            if (!dateString) return '';
            const date = new Date(dateString);
            return date.toLocaleDateString('fr-FR', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }
        // Notification (à garder)
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification-slide bg-white border-l-4 rounded-lg shadow-lg p-4 max-w-sm ${
                type === 'success' ? 'border-green-500' : 
                type === 'error' ? 'border-red-500' : 
                'border-blue-500'
            }`;
            notification.innerHTML = `
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-${type === 'success' ? 'check-circle text-green-500' : 
                                  type === 'error' ? 'exclamation-circle text-red-500' : 
                                  'info-circle text-blue-500'} text-lg"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-gray-900 whitespace-pre-line">${message}</p>
                    </div>
                    <button onclick="this.parentElement.parentElement.remove()" class="ml-auto text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            console.log('notificationsContainer:', document.getElementById('notificationsContainer'));
            document.getElementById('notificationsContainer').appendChild(notification);
            setTimeout(() => {
                if (notification.parentElement) {
                    notification.remove();
                }
            }, 5000);
        }
        // ...
        // Les autres fonctions d'affichage (demandes, historique, etc.) peuvent être gardées vides ou prêtes à recevoir des données dynamiques.
        // ...

        // Interface et navigation (sidebar, header, sections)
        const App = {
            currentUser: { id: 1, username: 'stock', password: 'admin', role: 'chef_stock', name: 'Chef Stock' },
            currentSection: 'dashboard',
        };

        function showSection(section) {
            // Hide all sections
            document.querySelectorAll('#mainApp main > div').forEach(div => div.classList.add('hidden'));
            // Show selected section
            document.getElementById(section + 'Section').classList.remove('hidden');
            // Update sidebar
            document.querySelectorAll('.sidebar-item').forEach(item => item.classList.remove('active'));
            document.querySelector(`[data-section="${section}"]`)?.classList.add('active');
            App.currentSection = section;
        }

        function initializeMainApp() {
            // Set user info
            document.getElementById('userName').textContent = currentUser.name;
            document.getElementById('userRoleText').textContent = getRoleDisplayName(currentUser.role);
            document.getElementById('userRole').textContent = `${getRoleDisplayName(currentUser.role)} - Tableau de bord`;
            // Set user avatar color based on role
            const avatar = document.getElementById('userAvatar');
            avatar.className = `w-8 h-8 rounded-full flex items-center justify-center role-${currentUser.role}`;
            // Generate sidebar menu
            generateSidebarMenu();
            // Show dashboard by default
            showSection('dashboard');
        }

        function getRoleDisplayName(role) {
            const roleNames = {
                'chef_stock': 'Chef de Stock',
                'chef_groupe': 'Chef de Groupe',
                'reception': 'Réception'
            };
            return roleNames[role] || role;
        }

        function generateSidebarMenu() {
            const menuItems = {
                'chef_stock': [
                    { id: 'dashboard', icon: 'fas fa-tachometer-alt', label: 'Tableau de bord' },
                    { id: 'samples', icon: 'fas fa-vial', label: 'Échantillons' },
                    { id: 'requests', icon: 'fas fa-inbox', label: 'Demandes' },
                    { id: 'history', icon: 'fas fa-history', label: 'Historique' }
                ],
                'chef_groupe': [
                    { id: 'dashboard', icon: 'fas fa-tachometer-alt', label: 'Tableau de bord' },
                    { id: 'samples', icon: 'fas fa-vial', label: 'Échantillons' },
                    { id: 'requests', icon: 'fas fa-rocket', label: 'Mes Demandes' },
                    { id: 'history', icon: 'fas fa-history', label: 'Historique' }
                ],
                'reception': [
                    { id: 'dashboard', icon: 'fas fa-tachometer-alt', label: 'Tableau de bord' },
                    { id: 'samples', icon: 'fas fa-vial', label: 'Échantillons' },
                    { id: 'history', icon: 'fas fa-history', label: 'Historique' }
                ]
            };
            const menu = menuItems[currentUser.role] || menuItems['chef_stock'];
            const sidebarMenu = document.getElementById('sidebarMenu');
            sidebarMenu.innerHTML = menu.map(item => `
                <button class="sidebar-item w-full flex items-center px-4 py-3 text-left text-white hover:bg-white hover:bg-opacity-10 rounded-lg transition-all" data-section="${item.id}">
                    <i class="${item.icon} mr-3"></i>
                    <span>${item.label}</span>
                </button>
            `).join('');
            document.querySelectorAll('.sidebar-item').forEach(item => {
                item.addEventListener('click', () => {
                    const section = item.dataset.section;
                    showSection(section);
                });
            });
        }

        function formatBonjourUser(user) {
            if (!user || !user.nom || !user.prenom) return '';
            const nom = user.nom.toUpperCase();
            const prenom = user.prenom.charAt(0).toUpperCase() + user.prenom.slice(1).toLowerCase();
            return `Bonjour Mr. ${nom} ${prenom}`;
        }

        // Notification (à garder)
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification-slide bg-white border-l-4 rounded-lg shadow-lg p-4 max-w-sm ${
                type === 'success' ? 'border-green-500' : 
                type === 'error' ? 'border-red-500' : 
                'border-blue-500'
            }`;
            notification.innerHTML = `
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-${type === 'success' ? 'check-circle text-green-500' : 
                                          type === 'error' ? 'exclamation-circle text-red-500' : 
                                          'info-circle text-blue-500'} text-lg"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-gray-900 whitespace-pre-line">${message}</p>
                    </div>
                    <button onclick="this.parentElement.parentElement.remove()" class="ml-auto text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            console.log('notificationsContainer:', document.getElementById('notificationsContainer'));
            document.getElementById('notificationsContainer').appendChild(notification);
            setTimeout(() => {
                if (notification.parentElement) {
                    notification.remove();
                }
            }, 5000);
        }

        // Fonctions pour le modal d'information
        function openInfoModal() {
            const modal = document.getElementById('infoModal');
            if (modal) {
                modal.classList.remove('hidden');
                console.log('Modal opened');
            } else {
                console.error('infoModal not found');
            }
        }

        function closeInfoModal() {
            const modal = document.getElementById('infoModal');
            if (modal) {
                modal.classList.add('hidden');
            }
        }

        // Initialisation de l'interface
        document.addEventListener('DOMContentLoaded', function() {
            initializeMainApp();
            
            // Initialiser le modal d'information
            setTimeout(() => {
                const infoModal = document.getElementById('infoModal');
                console.log('Looking for infoModal:', infoModal);
                if (infoModal) {
                    infoModal.addEventListener('click', function(e) {
                        if (e.target === this) {
                            closeInfoModal();
                        }
                    });
                    
                    // Ouvrir automatiquement le modal au chargement de la page
                    console.log('Opening modal...');
                    openInfoModal();
                } else {
                    console.error('infoModal element not found in DOM');
                }
            }, 2000);
            
            // Ajoute ici les listeners pour les modals, etc. (comme avant)
            // Remplacer l'ancien bouton d'ajout d'échantillon par le bouton de fabrication
            document.getElementById('launchFabricationBtn').addEventListener('click', function() {
                const select = document.getElementById('echantillonFabricationSelect');
                select.innerHTML = ''; // Vide le select

                <?php foreach ($echantillons as $sample): 
                    $stat = $sample['Statut'] ?? $sample['statut'] ?? '';
                    $refEch = $sample['RefEchantillon'] ?? $sample['refechantillon'] ?? '';
                    $fam = $sample['Famille'] ?? $sample['famille'] ?? '';
                    $coul = $sample['Couleur'] ?? $sample['couleur'] ?? '';
                    $tail = $sample['Taille'] ?? $sample['taille'] ?? '';
                    $qt = $sample['Qte'] ?? $sample['qte'] ?? 0;
                    // On ne propose que les échantillons disponibles
                    if (strtolower(trim($stat)) === 'disponible'): ?>
                        var option = document.createElement('option');
                        option.value = '<?= htmlspecialchars($refEch) ?>';
                        option.textContent = '<?= htmlspecialchars($refEch) ?> – <?= htmlspecialchars($fam) ?> – <?= htmlspecialchars($coul) ?> – <?= htmlspecialchars($tail) ?> (Stock: <?= htmlspecialchars($qt) ?>)';
                        option.setAttribute('data-famille', '<?= htmlspecialchars($fam) ?>');
                        option.setAttribute('data-couleur', '<?= htmlspecialchars($coul) ?>');
                        option.setAttribute('data-taille', '<?= htmlspecialchars($tail) ?>');
                        option.setAttribute('data-qte', '<?= htmlspecialchars($qt) ?>');
                        select.appendChild(option);
                    <?php endif; ?>
                <?php endforeach; ?>

                document.getElementById('fabricationModal').classList.remove('hidden');
                renderFabricationTempTable(); // Appelle la fonction SEULEMENT après ouverture
            });
            document.querySelectorAll('.close-modal').forEach(button => {
                button.addEventListener('click', () => {
                    document.getElementById('sampleModal').classList.add('hidden');
                    document.getElementById('requestModal').classList.add('hidden');
                    document.getElementById('fabricationModal').classList.add('hidden');
                    document.getElementById('centreFabricationModal').classList.add('hidden'); // Fermer le modal centre de fabrication
                });
            });
            const openCentreBtn = document.getElementById('openCentreFabricationBtn');
            if (openCentreBtn) {
                openCentreBtn.onclick = function() {
                    document.getElementById('centreFabricationModal').classList.remove('hidden');
                };
            }

            // Affiche le(s) modal(s) pour chaque échantillon à zéro au chargement
            (async function() {
                try {
                    const resp = await fetch('?action=get_zero_stock_samples');
                    const info = await resp.json();
                    if (info.success && info.samples && info.samples.length > 0) {
                        let idx = 0;
                        const showNext = () => {
                            if (idx < info.samples.length) {
                                const s = info.samples[idx++];
                                showZeroStockModal(s.RefEchantillon, s.Famille, s.Couleur, s.Taille, showNext);
                            }
                        };
                        showNext();
                    }
                } catch (e) {}
            })();
        });


        function confirmAcceptDemande() {
            const idDemande = document.getElementById('confirmAcceptDemandeId').value;
            console.log('confirmAcceptDemande - ID:', idDemande);
            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=approuver&idDemande=' + encodeURIComponent(idDemande)
            })
            .then(response => {
                console.log('Response status:', response.status);
                return response.json();
            })
            .then(data => {
                console.log('Response data:', data);
                if (data.success) {
                    showNotification('Demande approuvée !', 'success');
                    location.reload();
                } else {
                    showNotification('Erreur lors de l\'approbation: ' + (data.error || 'Erreur inconnue'), 'error');
                }
            })
            .catch(error => {
                console.error('Erreur fetch:', error);
                showNotification('Erreur de communication: ' + error.message, 'error');
            });
            document.getElementById('confirmAcceptDemandeModal').classList.add('hidden');
        }

        function confirmRefuseDemande() {
            const idDemande = document.getElementById('confirmRefuseDemandeId').value;
            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=refuser&idDemande=' + encodeURIComponent(idDemande)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Demande refusée.', 'info');
                    location.reload();
                } else {
                    showNotification('Erreur lors du refus.', 'error');
                }
            });
            document.getElementById('confirmRefuseDemandeModal').classList.add('hidden');
        }

        function updateApprovedStatus(idDemande, newStatus) {
            if (!newStatus) {
                return; // Ne rien faire si l'option par défaut est sélectionnée
            }

            // Ouvre le modal de confirmation personnalisé
            openStatusConfirmModal(idDemande, newStatus);
        }

        // Modal de confirmation pour changement de statut
        function openStatusConfirmModal(idDemande, newStatus) {
            const modal = document.getElementById('statusConfirmModal');
            const msg = document.getElementById('statusConfirmMessage');
            modal.classList.remove('hidden');
            msg.textContent = `Voulez-vous vraiment mettre à jour le statut de cette demande à "${newStatus}" ?`;
            // On stocke les infos sur le bouton de validation
            const btn = document.getElementById('statusConfirmValidateBtn');
            btn.onclick = function() {
                modal.classList.add('hidden');
                sendStatusUpdate(idDemande, newStatus);
            };
            // Annuler
            document.getElementById('statusConfirmCancelBtn').onclick = function() {
                modal.classList.add('hidden');
                // Réinitialiser le select
                const selectElement = document.querySelector(`select[onchange="updateApprovedStatus(${idDemande}, this.value)"]`);
                if (selectElement) selectElement.value = "";
            };
        }

        function sendStatusUpdate(idDemande, newStatus) {
            const params = new URLSearchParams({
                action: 'update_approved_status',
                idDemande: idDemande,
                newStatus: newStatus
            });
            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params
            })
            .then(res => res.json())
            .then(async data => {
                if (data.success) {
                    showNotification('Statut mis à jour avec succès.', 'success');
                    // Vérifier tous les échantillons à zéro
                    try {
                        const resp = await fetch('?action=get_zero_stock_samples');
                        const info = await resp.json();
                        if (info.success && info.samples && info.samples.length > 0) {
                            let idx = 0;
                            const showNext = () => {
                                if (idx < info.samples.length) {
                                    const s = info.samples[idx++];
                                    showZeroStockModal(s.RefEchantillon, s.Famille, s.Couleur, s.Taille, showNext);
                                } else {
                                    location.reload();
                                }
                            };
                            showNext();
                            return;
                        }
                    } catch (e) { /* ignore erreur */ }
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showNotification('Erreur : ' + (data.error || 'Erreur inconnue'), 'error');
                    const selectElement = document.querySelector(`select[onchange=\"updateApprovedStatus(${idDemande}, this.value)\"]`);
                    if (selectElement) selectElement.value = "";
                }
            }).catch(err => {
                showNotification('Une erreur technique est survenue.', 'error');
                const selectElement = document.querySelector(`select[onchange=\"updateApprovedStatus(${idDemande}, this.value)\"]`);
                if (selectElement) selectElement.value = "";
            });
        }
    </script>
    <script>
// =================================================================================
// BLOC DE FONCTIONS GLOBALES
// Ces fonctions doivent être accessibles partout, y compris par les onclick() du HTML.
// =================================================================================

function closeModal(modalName) {
    const modal = document.getElementById(modalName + 'Modal');
    if (modal) modal.classList.add('hidden');
}

function normalize(str) {
    if (!str) return '';
    return str.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/\s+/g, ' ').trim();
}

function applyAllRequestFilters() {
    const search = normalize(document.getElementById('searchRequests').value);
    const statut = normalize(document.getElementById('filterRequestStatus').value);
    
    let activeType = 'demande';
    if (document.getElementById('showRetoursBtn').classList.contains('text-indigo-600')) activeType = 'retour';
    else if (document.getElementById('showFabricationsBtn').classList.contains('text-indigo-600')) activeType = 'fabrication';
    
    const rows = document.querySelectorAll('#requestsTableBody tr');
    
    // Mapping des statuts selon le type actif
    let selectedStatutText = statut;
    if (activeType === 'retour') {
        // Pour les retours, utiliser les statuts directs
        const retourStatutMap = { 
            'retours en attente': 'en attente', 
            'retours validés': 'validé', 
            'retours refusés': 'refusé' 
        };
        selectedStatutText = retourStatutMap[statut] || statut;
    } else if (activeType === 'fabrication') {
        // Pour les fabrications, utiliser les statuts de fabrication
        const fabricationStatutMap = { 
            'fabrications en cours': 'en cours', 
            'fabrications terminées': 'terminée' 
        };
        selectedStatutText = fabricationStatutMap[statut] || statut;
    } else {
        // Pour les demandes, utiliser le mapping existant
        const demandeStatutMap = { 
            'demandes en attente': 'en attente', 
            'demandes approuvées': 'approuvée', 
            'demandes refusées': 'refusée',
            'demandes en fabrication': 'en fabrication',
            'demandes retournées': 'retourné'
        };
        selectedStatutText = demandeStatutMap[statut] || statut;
    }

    rows.forEach(row => {
        const rowType = row.dataset.type;
        const matchType = (rowType === activeType);
        
        // Recherche dans tous les champs pertinents
        const demandeur = normalize(row.children[0]?.textContent || '');
        const echantillons = normalize(row.children[1]?.textContent || '');
        const quantite = normalize(row.children[2]?.textContent || '');
        const date = normalize(row.children[3]?.textContent || '');
        const statutCell = normalize(row.children[4]?.textContent || '');
        
        const searchText = demandeur + ' ' + echantillons + ' ' + quantite + ' ' + date + ' ' + statutCell;
        const matchSearch = search === '' || searchText.includes(search);
        
        const matchStatut = statut === '' || statutCell.includes(selectedStatutText);

        row.style.display = (matchType && matchSearch && matchStatut) ? '' : 'none';
    });
}



function updateStatusFilterOptions(activeType) {
    const statusFilter = document.getElementById('filterRequestStatus');
    statusFilter.innerHTML = '<option value="">Tous les statuts</option>';
    
    if (activeType === 'demande') {
        const options = [
            { value: 'demandes en attente', text: 'Demandes en attente' },
            { value: 'demandes approuvées', text: 'Demandes approuvées' },
            { value: 'demandes refusées', text: 'Demandes refusées' },
            { value: 'demandes en fabrication', text: 'Demandes en fabrication' },
            { value: 'demandes retournées', text: 'Demandes retournées' }
        ];
        options.forEach(opt => {
            const option = document.createElement('option');
            option.value = opt.value;
            option.textContent = opt.text;
            statusFilter.appendChild(option);
        });
    } else if (activeType === 'retour') {
        const options = [
            { value: 'retours en attente', text: 'Retours en attente' },
            { value: 'retours validés', text: 'Retours validés' },
            { value: 'retours refusés', text: 'Retours refusés' }
        ];
        options.forEach(opt => {
            const option = document.createElement('option');
            option.value = opt.value;
            option.textContent = opt.text;
            statusFilter.appendChild(option);
        });
    } else if (activeType === 'fabrication') {
        const options = [
            { value: 'fabrications en cours', text: 'Fabrications en cours' },
            { value: 'fabrications terminées', text: 'Fabrications terminées' }
        ];
        options.forEach(opt => {
            const option = document.createElement('option');
            option.value = opt.value;
            option.textContent = opt.text;
            statusFilter.appendChild(option);
        });
    }
}

function setupRequestTypeButtons() {
    const buttons = [
        document.getElementById('showDemandesBtn'),
        document.getElementById('showRetoursBtn'),
        document.getElementById('showFabricationsBtn')
    ];
    function setActiveButton(activeBtn) {
        buttons.forEach(btn => {
            const isActive = btn === activeBtn;
            btn.classList.toggle('text-indigo-600', isActive);
            btn.classList.toggle('border-indigo-600', isActive);
            btn.classList.toggle('text-gray-500', !isActive);
            btn.classList.toggle('border-transparent', !isActive);
        });
        
        // Déterminer le type actif
        let activeType = 'demande';
        if (activeBtn === buttons[1]) activeType = 'retour';
        else if (activeBtn === buttons[2]) activeType = 'fabrication';
        
        // Mettre à jour les options du filtre de statut
        updateStatusFilterOptions(activeType);
        
        applyAllRequestFilters();
    }
    buttons.forEach(btn => btn.addEventListener('click', () => setActiveButton(btn)));
    setActiveButton(buttons[0]); // Set initial state
}


function returnSample(refEchantillon) {
    if (!confirm(`Êtes-vous sûr de vouloir enregistrer le retour de l'échantillon ${refEchantillon} ?`)) return;
    const params = new URLSearchParams({ action: 'return_sample', refEchantillon: refEchantillon });
    fetch(window.location.pathname, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showNotification('Retour de l\'échantillon enregistré.', 'success');
            setTimeout(() => location.reload(), 1200);
                } else {
            showNotification('Erreur lors du retour : ' + (data.error || 'Erreur inconnue'), 'error');
        }
    }).catch(err => showNotification('Une erreur technique est survenue.', 'error'));
}

async function editLot(idLot) {
    currentEditLotId = idLot;
    document.getElementById('editLotId').textContent = `(${idLot})`;
    document.getElementById('editFabricationIdLot').value = idLot;
    try {
        const response = await fetch(`${window.location.pathname}?action=get_fabrication_lot_details&idLot=${idLot}`);
        const data = await response.json();
        if (data.success) {
            lotToEditList = data.details.map(d => ({ ...d, Qte: parseInt(d.Qte, 10) }));
            const addSelect = document.getElementById('addEchantillonToLotSelect');
            const sourceOptions = document.querySelectorAll('#sourceEchantillons option');
            addSelect.innerHTML = '<option value="">Choisir un échantillon...</option>';
            sourceOptions.forEach(opt => addSelect.appendChild(opt.cloneNode(true)));
            renderEditFabricationTable();
            document.getElementById('editFabricationModal').classList.remove('hidden');
        } else {
            showNotification('Erreur de chargement: ' + data.error, 'error');
        }
    } catch (err) {
        showNotification('Une erreur technique est survenue.', 'error');
    }
}

function deleteLot(idLot) {
    document.getElementById('confirmDeleteLotId').value = idLot;
    document.getElementById('confirmDeleteLotModal').classList.remove('hidden');
}

function completeLot(idLot) {
    document.getElementById('confirmCompleteLotId').value = idLot;
    document.getElementById('confirmCompleteLotModal').classList.remove('hidden');
}

function confirmDeleteLot() {
    const idLot = document.getElementById('confirmDeleteLotId').value;
    const params = new URLSearchParams({ action: 'delete_fabrication_lot', idLot: idLot });
    fetch(window.location.pathname, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params
    })
    .then(res => res.json())
    .then((data) => {
        if (data.success) {
            showNotification('Lot de fabrication supprimé avec succès.', 'success');
            setTimeout(() => location.reload(), 1200);
        } else {
            showNotification('Erreur lors de la suppression : ' + (data.error || 'Erreur inconnue'), 'error');
        }
    }).catch(err => showNotification('Une erreur technique est survenue.', 'error'));
    document.getElementById('confirmDeleteLotModal').classList.add('hidden');
}

function confirmCompleteLot() {
    const idLot = document.getElementById('confirmCompleteLotId').value;
    const params = new URLSearchParams({ action: 'complete_fabrication_lot', idLot: idLot });
    fetch(window.location.pathname, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params
    })
    .then(res => res.json())
    .then((data) => {
        if (data.success) {
            showNotification('Lot de fabrication terminé et stock mis à jour.', 'success');
            setTimeout(() => location.reload(), 1200);
        } else {
            showNotification('Erreur : ' + (data.error || 'Erreur inconnue'), 'error');
        }
    }).catch(err => showNotification('Une erreur technique est survenue.', 'error'));
    document.getElementById('confirmCompleteLotModal').classList.add('hidden');
}

function acceptReturn(idDemande) {
    if (!confirm('Voulez-vous accepter ce retour et réintégrer les échantillons au stock ?')) return;
    const params = new URLSearchParams({ action: 'accept_return', idDemande: idDemande });
    fetch(window.location.pathname, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params
    })
    .then(res => res.json())
    .then(async (data) => {
        if (data.success) {
            showNotification('Retour accepté et stock mis à jour.', 'success');
            setTimeout(() => location.reload(), 1200);
        } else {
            showNotification('Erreur : ' + (data.error || 'Erreur inconnue'), 'error');
        }
    }).catch(err => showNotification('Une erreur technique est survenue.', 'error'));
}

// Fonction pour accepter un retour de la table Retour
function acceptRetour(idRetour) {
    document.getElementById('confirmAcceptRetourId').value = idRetour;
    document.getElementById('confirmAcceptRetourModal').classList.remove('hidden');
}

function refuseRetour(idRetour) {
    document.getElementById('confirmRefuseRetourId').value = idRetour;
    document.getElementById('confirmRefuseRetourModal').classList.remove('hidden');
}

function confirmAcceptRetour() {
    const idRetour = document.getElementById('confirmAcceptRetourId').value;
    const params = new URLSearchParams({ action: 'accept_retour', idRetour: idRetour });
    fetch(window.location.pathname, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params
    })
    .then(res => res.json())
    .then(async (data) => {
        if (data.success) {
            showNotification('Retour accepté et stock mis à jour.', 'success');
            setTimeout(() => location.reload(), 1200);
        } else {
            showNotification('Erreur : ' + (data.error || 'Erreur inconnue'), 'error');
        }
    }).catch(err => showNotification('Une erreur technique est survenue.', 'error'));
    document.getElementById('confirmAcceptRetourModal').classList.add('hidden');
}

function confirmRefuseRetour() {
    const idRetour = document.getElementById('confirmRefuseRetourId').value;
    const params = new URLSearchParams({ action: 'refuse_retour', idRetour: idRetour });
    fetch(window.location.pathname, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params
    })
    .then(res => res.json())
    .then(async (data) => {
        if (data.success) {
            showNotification('Retour refusé.', 'info');
            setTimeout(() => location.reload(), 1200);
        } else {
            showNotification('Erreur : ' + (data.error || 'Erreur inconnue'), 'error');
        }
    }).catch(err => showNotification('Une erreur technique est survenue.', 'error'));
    document.getElementById('confirmRefuseRetourModal').classList.add('hidden');
}


// =================================================================================
// BLOC D'INITIALISATION
// S'exécute une fois que le document est prêt.
// =================================================================================

// Stock temporaire en JS et données de l'utilisateur connecté
const currentUser = {
    id: <?= json_encode($currentUser['id']) ?>,
    role: 'chef_stock', // Rôle JS pour les classes CSS et les clés d'objet
    name: <?= json_encode($currentUser['Prenom'] . ' ' . $currentUser['Nom']) ?>,
    nom: <?= json_encode($currentUser['Nom']) ?>,
    prenom: <?= json_encode($currentUser['Prenom']) ?>
};
let fabricationTempList = [];
let lotToEditList = [];
let currentEditLotId = null;

document.addEventListener('DOMContentLoaded', function() {
    
    // --- Initialisation des composants ---
    initializeMainApp();
    setupRequestTypeButtons();
    
    // --- Chart.js Initialization ---
    const ctx = document.getElementById('samplesChart');
    if (ctx) {
        // Initialisation de la variable globale du graphique
        window.samplesChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Disponibles', 'Empruntés', 'En Fabrication'],
                datasets: [{
                    label: 'Répartition des Échantillons',
                    data: [
                        <?= $stats['availableSamples'] ?>,
                        <?= $stats['borrowedSamples'] ?>,
                        <?= $stats['fabricationSamples'] ?>
                    ],
                    backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
                    borderColor: '#fff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    }
    
    // --- Listeners pour les filtres ---
    document.getElementById('searchRequests').addEventListener('input', applyAllRequestFilters);
    document.getElementById('filterRequestStatus').addEventListener('change', applyAllRequestFilters);
    
    // --- Listeners pour les filtres de l'historique ---
    document.getElementById('historyDateStart').addEventListener('change', filterHistory);
    document.getElementById('historyDateEnd').addEventListener('change', filterHistory);
    document.getElementById('historyAction').addEventListener('change', filterHistory);
    document.getElementById('historyUser').addEventListener('change', filterHistory);
    document.getElementById('historySearch').addEventListener('input', filterHistory);
    


    // --- Listeners pour les modales ---
    document.getElementById('launchFabricationBtn').addEventListener('click', function() {
        const select = document.getElementById('echantillonFabricationSelect');
        select.innerHTML = '<option value="">Choisir un échantillon...</option>';
        const sourceOptions = document.querySelectorAll('#sourceEchantillons option');
        sourceOptions.forEach(opt => select.appendChild(opt.cloneNode(true)));
        document.getElementById('fabricationModal').classList.remove('hidden');
    renderFabricationTempTable();
    });

    document.querySelectorAll('.close-modal, [onclick^="closeModal"]').forEach(button => {
        const modalId = button.closest('.modal')?.id;
        if(modalId) {
            button.onclick = () => document.getElementById(modalId).classList.add('hidden');
        }
    });

    const openCentreBtn = document.getElementById('openCentreFabricationBtn');
    if (openCentreBtn) {
        openCentreBtn.onclick = () => document.getElementById('centreFabricationModal').classList.remove('hidden');
    }

    // --- Listener pour les cartes du dashboard ---
    document.querySelectorAll('[data-content-type]').forEach(card => {
        card.addEventListener('click', () => {
            const contentType = card.dataset.contentType;
            const cardTitle = card.querySelector('p:first-child').textContent;
            openDetailsModal(contentType, cardTitle);
        });
    });

    // --- Listeners pour les formulaires ---
    document.getElementById('formFabrication').addEventListener('submit', handleNewFabricationSubmit);
    document.getElementById('formEditFabrication').addEventListener('submit', handleEditFabricationSubmit);

    // --- Autocomplete pour la création de fabrication ---
    const searchInput = document.getElementById('searchEchantillonFabrication');
    const select = document.getElementById('echantillonFabricationSelect');
    let allCreationOptions = Array.from(select.options).slice(1);

    searchInput.addEventListener('input', function() {
        const search = searchInput.value.trim().toLowerCase();
        select.innerHTML = '<option value="">Choisir un échantillon...</option>';
        allCreationOptions.forEach(option => {
            if (option.textContent.toLowerCase().includes(search)) {
                select.appendChild(option);
            }
        });
    });

    select.addEventListener('change', function() {
        const selected = select.options[select.selectedIndex];
        document.getElementById('familleFabrication').value = selected.getAttribute('data-famille') || '';
        document.getElementById('couleurFabrication').value = selected.getAttribute('data-couleur') || '';
        document.getElementById('tailleFabrication').value = selected.getAttribute('data-taille') || '';
        document.getElementById('stockFabrication').value = selected.getAttribute('data-qte') || '';
    });
        });

// =================================================================================
// SOUMISSION DES FORMULAIRES
// =================================================================================

function handleNewFabricationSubmit(e) {
    e.preventDefault();
    if (fabricationTempList.length === 0) {
        showNotification('Aucun échantillon à fabriquer.', 'error');
        return;
    }
    const params = new URLSearchParams({
        fabrication_json: JSON.stringify(fabricationTempList)
    });
    fetch(window.location.pathname, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params
    })
    .then(res => res.json())
    .then((data) => {
        if (data.success) {
            showNotification('Commande de fabrication enregistrée !', 'success');
            fabricationTempList = [];
            closeModal('fabrication');
            setTimeout(() => location.reload(), 1200);
        } else {
            showNotification('Erreur lors de l\'enregistrement: ' + (data.error || 'Erreur inconnue'), 'error');
        }
    });
}

function handleEditFabricationSubmit(e) {
    e.preventDefault();
    const idLot = document.getElementById('editFabricationIdLot').value;
    const fabricationData = lotToEditList.map(item => ({ ref: item.RefEchantillon, qte: item.Qte }));
    if (fabricationData.length === 0) {
        if (!confirm('Le lot est vide. Voulez-vous le supprimer définitivement ?')) return;
    }
    const params = new URLSearchParams({
        action: 'update_fabrication_lot',
        idLot: idLot,
        fabrication_json: JSON.stringify(fabricationData)
    });
    fetch(window.location.pathname, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params
    })
    .then(res => res.json())
    .then((data) => {
        if (data.success) {
            showNotification('Lot de fabrication mis à jour.', 'success');
            closeModal('editFabrication');
            setTimeout(() => location.reload(), 1200);
        } else {
            showNotification('Erreur de mise à jour: ' + (data.error || 'Erreur inconnue'), 'error');
        }
    }).catch(err => showNotification('Une erreur technique est survenue.', 'error'));
}

// =================================================================================
// FONCTIONS DE MANIPULATION DES LISTES TEMPORAIRES
// =================================================================================

function addFabricationTemp() {
    const select = document.getElementById('echantillonFabricationSelect');
    if (!select.value) return;
    const qte = parseInt(document.getElementById('qteFabrication').value, 10);
    if (fabricationTempList.some(e => e.ref === select.value)) {
        showNotification('Cet échantillon est déjà dans la liste.', 'error');
        return;
    }
    if (qte < 1) {
        showNotification('La quantité doit être au moins de 1.', 'error');
        return;
    }
    const opt = select.options[select.selectedIndex];
    fabricationTempList.push({ 
        ref: select.value, 
        famille: opt.dataset.famille, 
        couleur: opt.dataset.couleur, 
        taille: opt.dataset.taille, 
        qte: qte 
    });
    renderFabricationTempTable();
}

function renderFabricationTempTable() {
    const tbody = document.getElementById('fabricationTempTableBody');
    if (fabricationTempList.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-gray-400 py-6">Aucun échantillon à fabriquer</td></tr>';
        return;
    }
    tbody.innerHTML = fabricationTempList.map((e, idx) => `
        <tr>
            <td class="px-4 py-3 text-sm font-medium text-gray-900">${e.ref}</td>
            <td class="px-4 py-3 text-sm text-gray-700">${e.famille}</td>
            <td class="px-4 py-3 text-sm text-gray-700">${e.couleur}</td>
            <td class="px-4 py-3 text-sm text-gray-700">${e.qte}</td>
            <td class="px-4 py-3 text-sm">
                <button type="button" onclick="removeFabricationTemp(${idx})" class="text-red-600 hover:text-red-900" title="Supprimer"><i class="fas fa-trash"></i></button>
            </td>
        </tr>`).join('');
}

function removeFabricationTemp(idx) {
    fabricationTempList.splice(idx, 1);
    renderFabricationTempTable();
}

function renderEditFabricationTable() {
    const tbody = document.getElementById('editFabricationTableBody');
    if (lotToEditList.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-gray-400 py-6">Ce lot est vide. Ajoutez un échantillon.</td></tr>';
        return;
    }
    tbody.innerHTML = lotToEditList.map((e, idx) => `
        <tr class="hover:bg-gray-50">
            <td class="px-4 py-3 text-sm font-medium">${e.RefEchantillon}</td>
            <td class="px-4 py-3 text-sm">${e.Famille}, ${e.Couleur}, ${e.Taille}</td>
            <td class="px-4 py-3 text-sm"><input type="number" value="${e.Qte}" min="1" onchange="updateLotEditQty(${idx}, this.value)" class="w-20 px-2 py-1 border rounded"></td>
            <td class="px-4 py-3 text-sm"><button type="button" onclick="removeLotEditItem(${idx})" class="text-red-600 hover:text-red-900" title="Retirer"><i class="fas fa-trash"></i></button></td>
        </tr>`).join('');
}

function updateLotEditQty(index, newQty) {
    const qty = parseInt(newQty, 10);
    if (qty > 0) lotToEditList[index].Qte = qty;
    renderEditFabricationTable();
}

function removeLotEditItem(index) {
    lotToEditList.splice(index, 1);
    renderEditFabricationTable();
}

function addSampleToEditList() {
    const select = document.getElementById('addEchantillonToLotSelect');
    if (!select.value) return;
    const qte = parseInt(document.getElementById('qteAddToLot').value, 10);
    if (qte < 1) {
        showNotification('La quantité doit être au moins de 1.', 'error');
        return;
    }
    if (lotToEditList.some(e => e.RefEchantillon === select.value)) {
        showNotification('Cet échantillon est déjà dans le lot.', 'error');
        return;
    }
    const opt = select.options[select.selectedIndex];
    lotToEditList.push({
        RefEchantillon: select.value, Qte: qte,
        Famille: opt.dataset.famille, Couleur: opt.dataset.couleur, Taille: opt.dataset.taille
    });
    renderEditFabricationTable();
    select.selectedIndex = 0;
    document.getElementById('qteAddToLot').value = "1";
}

function normalizeString(str) {
    return (str || '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '') // retire les accents
        .replace(/\s+/g, ' ')
        .trim();
}

function filterHistory() {
    const dateStart = document.getElementById('historyDateStart').value;
    const dateEnd = document.getElementById('historyDateEnd').value;
    const action = document.getElementById('historyAction').value;
    const user = document.getElementById('historyUser').value;
    const role = document.getElementById('historyRole').value;
    const search = document.getElementById('historySearch').value.toLowerCase();
    // Correction du filtrage par rôle
    const filteredList = historiques.filter(row => {
        // Filtre par rôle (souple)
        if (role) {
            const roleNorm = normalizeString(role);
            const rowRoleNorm = normalizeString(row.Role);
            if (!rowRoleNorm.includes(roleNorm)) return false;
        }
        // Filtre par date
        if (dateStart || dateEnd) {
            const rowDate = new Date(row.DateAction);
            if (dateStart && rowDate < new Date(dateStart + 'T00:00:00')) return false;
            if (dateEnd && rowDate > new Date(dateEnd + 'T23:59:59')) return false;
        }
        // Filtre par action
        if (action && row.TypeAction !== action) return false;
        // Filtre par utilisateur
        if (user && !((row.Prenom + ' ' + row.Nom).toLowerCase().includes(user.toLowerCase()))) return false;
        // Filtre par recherche
        if (search) {
            const searchText = (row.Prenom + ' ' + row.Nom + ' ' + row.TypeAction + ' ' + row.RefEchantillon + ' ' + row.Description).toLowerCase();
            if (!searchText.includes(search)) return false;
        }
        return true;
    });
    renderHistory(filteredList);
}

function renderHistory(list = historiques) {
    const tbody = document.getElementById('historyTableBody');
    tbody.innerHTML = '';
    if (!list || list.length === 0) {
        tbody.innerHTML = `<tr><td colspan='5' class='text-center text-gray-400 py-6'>Aucune entrée d'historique.</td></tr>`;
        return;
    }
    list.forEach(row => {
        let dateStr = '';
        if (row.DateAction) {
            let iso = row.DateAction.replace(' ', 'T');
            if (/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}$/.test(iso)) {
                iso += ':00';
            }
            const date = new Date(iso);
            if (!isNaN(date)) {
                // Diminuer d'1 heure pour les actions liées à la fabrication
                const fabricationActions = ['Fabrication', 'Modification Fabrication', 'Suppression Fabrication', 'Fabrication Terminée'];
                if (fabricationActions.includes(row.TypeAction)) {
                    date.setHours(date.getHours() - 1);
                }
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

document.addEventListener('DOMContentLoaded', function() {
    renderHistory();
    // Initialiser les filtres
    setupRequestTypeButtons();
    applyAllRequestFilters();
    filterHistory();
    
    // S'assurer que tous les filtres sont initialisés
    const searchSamplesInput = document.getElementById('searchSamples');
    if (searchSamplesInput) {
        
    }
    
    // Initialiser les filtres de l'historique
    const historyFilters = ['historyDateStart', 'historyDateEnd', 'historyAction', 'historyUser', 'historyRole', 'historySearch'];
    historyFilters.forEach(filterId => {
        const element = document.getElementById(filterId);
        if (element) {
            if (filterId === 'historySearch') {
                element.addEventListener('input', filterHistory);
            } else {
                element.addEventListener('change', filterHistory);
            }
        }
    });
    
    // Initialiser les filtres de demandes
    const requestFilters = ['searchRequests', 'filterRequestStatus'];
    requestFilters.forEach(filterId => {
        const element = document.getElementById(filterId);
        if (element) {
            if (filterId === 'searchRequests') {
                element.addEventListener('input', applyAllRequestFilters);
            } else {
                element.addEventListener('change', applyAllRequestFilters);
            }
        }
    });
});
</script>

<!-- Hidden select to store all available samples -->
<select id="sourceEchantillons" class="hidden">
    <?php foreach ($echantillons as $sample): 
        $refEch = $sample['RefEchantillon'] ?? $sample['refechantillon'] ?? '';
        $fam = $sample['Famille'] ?? $sample['famille'] ?? '';
        $coul = $sample['Couleur'] ?? $sample['couleur'] ?? '';
        $tail = $sample['Taille'] ?? $sample['taille'] ?? '';
        $qt = $sample['Qte'] ?? $sample['qte'] ?? 0;
    ?>
        <option
            value="<?= htmlspecialchars($refEch) ?>"
            data-famille="<?= htmlspecialchars($fam) ?>"
            data-couleur="<?= htmlspecialchars($coul) ?>"
            data-taille="<?= htmlspecialchars($tail) ?>"
            data-qte="<?= htmlspecialchars($qt) ?>"
        >
            <?= htmlspecialchars($refEch) ?> – <?= htmlspecialchars($fam) ?> – <?= htmlspecialchars($coul) ?> (Stock: <?= htmlspecialchars($qt) ?>)
        </option>
    <?php endforeach; ?>
</select>

<?php
$historiques = $conn->query(
    "SELECT H.*, U.nom AS Nom, U.prenom AS Prenom, R.role AS Role FROM Historique H LEFT JOIN Utilisateur U ON H.idutilisateur = U.idutilisateur LEFT JOIN Role R ON U.idrole = R.idrole ORDER BY H.dateaction DESC LIMIT 100"
)->fetch_all(MYSQLI_ASSOC);
?>
<script>
let historiques = <?php echo json_encode($historiques); ?>;
</script>

<!-- Modal de confirmation de statut -->
<div id="statusConfirmModal" class="fixed inset-0 bg-black bg-opacity-50 modal hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-md m-4 fade-in">
        <div class="flex items-center justify-between p-6 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-800">Confirmation</h3>
            <button class="text-gray-400 hover:text-gray-600" onclick="document.getElementById('statusConfirmModal').classList.add('hidden')"><i class="fas fa-times"></i></button>
        </div>
        <div class="p-6">
            <p id="statusConfirmMessage" class="text-gray-700 mb-6">Voulez-vous vraiment mettre à jour le statut ?</p>
            <div class="flex justify-end space-x-3">
                <button id="statusConfirmCancelBtn" type="button" class="px-4 py-2 text-gray-600 border border-gray-300 rounded-lg hover:bg-gray-50">Annuler</button>
                <button id="statusConfirmValidateBtn" type="button" class="btn-primary text-white px-4 py-2 rounded-lg font-medium">Valider</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal stock à zéro -->
<div id="zeroStockModal" class="fixed inset-0 bg-black bg-opacity-50 modal hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-md m-4 fade-in">
        <div class="flex items-center justify-between p-6 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-800">Stock épuisé</h3>
            <button class="text-gray-400 hover:text-gray-600" onclick="closeZeroStockModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="p-6">
            <p id="zeroStockMessage" class="text-gray-700 mb-6">Cet échantillon est à 0.</p>
            <div class="flex justify-end space-x-3">
                <button id="zeroStockLaterBtn" type="button" class="px-4 py-2 text-gray-600 border border-gray-300 rounded-lg hover:bg-gray-50">Plus tard</button>
                <button id="zeroStockFabBtn" type="button" class="btn-primary text-white px-4 py-2 rounded-lg font-medium">Lancer fabrication</button>
            </div>
        </div>
    </div>
</div>
<script>
let zeroStockTimeout = null;
function showZeroStockModal(ref, famille, couleur, taille, onClose) {
    const modal = document.getElementById('zeroStockModal');
    const msg = document.getElementById('zeroStockMessage');
    msg.textContent = `L'échantillon ${ref} (${famille}, ${couleur}, ${taille}) a une quantité de 0. Voulez-vous lancer une fabrication ?`;
    modal.classList.remove('hidden');
    // Bouton Plus tard
    document.getElementById('zeroStockLaterBtn').onclick = () => closeZeroStockModal(onClose);
    // Bouton Lancer fabrication
    document.getElementById('zeroStockFabBtn').onclick = function() {
        closeZeroStockModal(onClose);
        openFabricationModalWithSample(ref);
    };
    // Timer auto-fermeture
    if (zeroStockTimeout) clearTimeout(zeroStockTimeout);
    zeroStockTimeout = setTimeout(() => closeZeroStockModal(onClose), 7000);
}
function closeZeroStockModal(onClose) {
    document.getElementById('zeroStockModal').classList.add('hidden');
    if (zeroStockTimeout) clearTimeout(zeroStockTimeout);
    if (typeof onClose === 'function') onClose();
}
function openFabricationModalWithSample(ref) {
    // Ouvre le modal de fabrication avec l'échantillon pré-sélectionné
    const fabricationModal = document.getElementById('fabricationModal');
    fabricationModal.classList.remove('hidden');
    const select = document.getElementById('echantillonFabricationSelect');
    if (select) {
        select.value = ref;
        const event = new Event('change');
        select.dispatchEvent(event);
    }
}

// Les fonctions openInfoModal et closeInfoModal sont maintenant définies dans le script principal

</body>
</html>