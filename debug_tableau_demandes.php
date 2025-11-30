<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/db.php';

echo "<h1>Debug Tableau Demandes</h1>";

// Simuler exactement ce que fait dashboard_stock.php
$demandes_et_retours = [];

// Récupérer les demandes
$res = $conn->query("SELECT d.*, u.nom AS NomDemandeur, u.prenom AS PrenomDemandeur
                     FROM Demande d
                     JOIN Utilisateur u ON d.idUtilisateur = u.idUtilisateur
                     ORDER BY d.DateDemande DESC LIMIT 100");
if ($res) {
    echo "<h2>Demandes récupérées:</h2>";
    $count = 0;
    while ($row = $res->fetch_assoc()) {
        $count++;
        echo "<h3>Demande $count:</h3>";
        echo "<pre>";
        print_r($row);
        echo "</pre>";
        
        $row['echantillons'] = [];
        $idDemande = $row['idDemande'];
        echo "<p>ID Demande: $idDemande</p>";
        
        $res2 = $conn->query("SELECT * FROM DemandeEchantillon WHERE idDemande = " . intval($idDemande));
        if($res2) {
            echo "<p>Échantillons:</p><ul>";
            $echCount = 0;
            while ($e = $res2->fetch_assoc()) {
                $echCount++;
                echo "<li>Échantillon $echCount: ";
                echo "<pre>";
                print_r($e);
                echo "</pre>";
                echo "</li>";
                $row['echantillons'][] = $e;
            }
            echo "</ul>";
            if ($echCount === 0) {
                echo "<p style='color:red'>⚠️ Aucun échantillon trouvé!</p>";
            }
        } else {
            echo "<p style='color:red'>ERREUR: " . $conn->error . "</p>";
        }
        
        $row['request_date'] = $row['DateDemande'] ?? '';
        $demandes_et_retours[] = $row;
        
        echo "<p>Demande ajoutée à \$demandes_et_retours</p>";
    }
    if ($count === 0) {
        echo "<p style='color:red'>⚠️ Aucune demande trouvée!</p>";
    }
} else {
    echo "ERREUR: " . $conn->error . "<br>";
}

// Récupérer les retours
$retours = [];
$resRetours = $conn->query("SELECT r.*, u.nom AS NomDemandeur, u.prenom AS PrenomDemandeur
                            FROM Retour r
                            JOIN Utilisateur u ON r.idUtilisateur = u.idUtilisateur
                            ORDER BY r.DateRetour DESC LIMIT 100");
if ($resRetours) {
    while ($row = $resRetours->fetch_assoc()) {
        $row['echantillons'] = [];
        $idRetour = $row['idRetour'];
        $res2 = $conn->query("SELECT * FROM RetourEchantillon WHERE idRetour = " . intval($idRetour));
        if($res2) {
            while ($e = $res2->fetch_assoc()) {
                $row['echantillons'][] = $e;
            }
        }
        $row['request_date'] = $row['DateRetour'] ?? '';
        $row['TypeDemande'] = 'retour';
        $retours[] = $row;
    }
}

// Récupérer les fabrications
$fabrications_as_demandes = [];
$resFab = $conn->query("SELECT f.idLot, f.dateCreation AS DateCreation, f.statutFabrication AS StatutFabrication, f.idUtilisateur AS idUtilisateur, u.nom AS NomDemandeur, u.prenom AS PrenomDemandeur FROM Fabrication f JOIN Utilisateur u ON f.idUtilisateur = u.idUtilisateur WHERE f.idLot IS NOT NULL AND f.idLot != '' GROUP BY f.idLot, f.dateCreation, f.statutFabrication, f.idUtilisateur, u.nom, u.prenom ORDER BY f.dateCreation DESC");
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
            'NomDemandeur' => $lot['NomDemandeur'] ?? '',
            'PrenomDemandeur' => $lot['PrenomDemandeur'] ?? '',
            'echantillons' => $lot_details,
            'DateDemande' => $lot['DateCreation'] ?? '',
            'Statut' => $lot['StatutFabrication'] ?? '',
            'TypeDemande' => 'fabrication',
            'request_date' => $lot['DateCreation'] ?? '',
        ];
    }
}

// Fusionner et trier
$all_requests = array_merge($demandes_et_retours, $retours, $fabrications_as_demandes);
usort($all_requests, function($a, $b) {
    return strtotime($b['request_date']) - strtotime($a['request_date']);
});

echo "<h2>Résultat final:</h2>";
echo "<p>Nombre de demandes dans \$demandes_et_retours: " . count($demandes_et_retours) . "</p>";
echo "<p>Nombre de retours dans \$retours: " . count($retours) . "</p>";
echo "<p>Nombre de fabrications dans \$fabrications_as_demandes: " . count($fabrications_as_demandes) . "</p>";
echo "<p>Nombre total dans \$all_requests: " . count($all_requests) . "</p>";

echo "<h2>Contenu de \$all_requests:</h2>";
echo "<pre>";
print_r($all_requests);
echo "</pre>";

echo "<br><a href='dashboard_stock.php'>Retour au dashboard</a>";
?>

