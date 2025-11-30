<?php
require_once __DIR__ . '/config/error_config.php';
session_start();
date_default_timezone_set('Europe/Paris');
require_once __DIR__ . '/config/db.php';

// Redirection si non connecté ou mauvais rôle AVANT toute sortie
if (!isset($_SESSION['user']) || $_SESSION['user']['idRole'] != 3) {
    header('Location: index.php', true, 302);
    exit;
}

$user = $_SESSION['user'];

// Notifications (stockées en session)
$notification = null;
if (isset($_SESSION['notification'])) {
    $notification = $_SESSION['notification'];
}
$notifMessage = '';
$notifType = '';
if (isset($_SESSION['notif_message'])) {
    $notifMessage = $_SESSION['notif_message'];
    $notifType = $_SESSION['notif_type'] ?? 'success';
    unset($_SESSION['notif_message'], $_SESSION['notif_type']);
}
// Remplacer la récupération de $samples par une version enrichie avec nbEmprunts
$samples = [];
$res = $conn->query("SELECT * FROM Echantillon ORDER BY DateCreation DESC");
while ($row = $res->fetch_assoc()) {
    $ref = $row['RefEchantillon'];

    // Total emprunté
    $resEmprunt = $conn->query("SELECT SUM(de.qte) as nb FROM DemandeEchantillon de JOIN Demande d ON d.iddemande = de.iddemande WHERE de.refEchantillon = '".$conn->real_escape_string($ref)."' AND d.statut IN ('Validée', 'emprunte', 'Prêt pour retrait', 'En fabrication', 'Attente inter-service')");
    $rowEmprunt = $resEmprunt ? $resEmprunt->fetch_assoc() : null;
    $nbEmprunts = (int)($rowEmprunt['nb'] ?? 0);

    // Total retourné - CORRECTION: utiliser Statut au lieu de StatutRetour
    $resRetour = $conn->query("SELECT SUM(re.qte) as nb FROM RetourEchantillon re JOIN Retour r ON r.idretour = re.idretour WHERE re.RefEchantillon = '".$conn->real_escape_string($ref)."' AND r.statut IN ('Validé', 'Approuvé', 'Retourné')");
    $rowRetour = $resRetour ? $resRetour->fetch_assoc() : null;
    $nbRetours = (int)($rowRetour['nb'] ?? 0);

    // CORRECTION: Logique de calcul des quantités
    // Stock réellement disponible = Stock actuel - Quantité empruntée non retournée
    $qteReellementDisponible = max(0, (int)$row['Qte'] - ($nbEmprunts - $nbRetours));
    $qteEmprunteeNonRetournee = max(0, $nbEmprunts - $nbRetours);
    
    $row['QteInitial'] = (int)$row['Qte']; // Stock initial dans la base
    $row['QteReellementDisponible'] = $qteReellementDisponible; // Stock réellement disponible
    $row['QteEmprunteeNonRetournee'] = $qteEmprunteeNonRetournee; // Quantité empruntée non retournée
    $samples[] = $row;
}
// Récupérer les 5 derniers échantillons avec nom/prénom utilisateur
$lastSamplesSQL = "SELECT E.*, U.nom AS Nom, U.prenom AS Prenom FROM Echantillon E LEFT JOIN Utilisateur U ON E.idutilisateur = U.idutilisateur ORDER BY E.datecreation DESC LIMIT 5";
$lastSamplesPHP = $conn->query($lastSamplesSQL)->fetch_all(MYSQLI_ASSOC);
// Après la récupération de $samples :
$totalSamplesPHP = count($samples);
$today = date('Y-m-d');
$todaySamplesPHP = 0;
foreach ($samples as $s) {
    if (isset($s['DateCreation']) && strpos($s['DateCreation'], $today) === 0) {
        $todaySamplesPHP++;
    }
}
// Récupère toutes les familles distinctes
$familles = $conn->query("SELECT nom FROM Famille ORDER BY nom")->fetch_all(MYSQLI_ASSOC);
// Récupère toutes les couleurs distinctes
$couleurs = $conn->query("SELECT nom FROM Couleur ORDER BY nom")->fetch_all(MYSQLI_ASSOC);

$default_familles = [
    "Tissu", "Cuir", "Plastique", "Métal", "Céramique", "Maille", "Polyester", "Nylon", "Laine", "Coton", "Lin", "Soie", "Velours", "Denim", "Élasthanne", "Microfibre", "Mousse", "Caoutchouc", "PVC", "Polyuréthane", "Acrylique", "Viscose", "Toile", "Satin"
];
$default_couleurs = [
    "Rouge", "Noir", "Bleu", "Argent", "Blanc", "Vert", "Jaune", "Orange", "Rose", "Marron", "Beige", "Violet", "Gris", "Doré", "Bordeaux", "Turquoise", "Kaki", "Bleu marine", "Fuchsia", "Anthracite"
];

if (count($familles) === 0) {
    $familles = array_map(fn($f) => ['nom' => $f], $default_familles);
}
if (count($couleurs) === 0) {
    $couleurs = array_map(fn($c) => ['nom' => $c], $default_couleurs);
}

function ajouterHistorique($conn, $idUtilisateur, $refEchantillon, $typeAction, $description, $dateAction = null) {
    if ($dateAction === null) {
        $dateAction = date('Y-m-d H:i:s');
    }
    $stmt = $conn->prepare("INSERT INTO Historique (idutilisateur, RefEchantillon, TypeAction, DateAction, Description) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) {
        die("Erreur prepare: " . $conn->error);
    }
    $stmt->bind_param("issss", $idUtilisateur, $refEchantillon, $typeAction, $dateAction, $description);
    if (!$stmt->execute()) {
        die("Erreur execute: " . $stmt->error);
    }
}

function echantillonSupprimable($conn, $refEchantillon) {
    $count = 0;
    $stmt = $conn->prepare("SELECT COUNT(*) FROM Historique WHERE RefEchantillon = ? AND TypeAction NOT IN ('Création', 'Modification', 'Suppression')");
    $stmt->bind_param("s", $refEchantillon);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return $count == 0;
}

$error = '';
$showError = false;
$formWasSubmitted = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_sample');
$isEdit = false;
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($formWasSubmitted) {
    $isEdit = (isset($_POST['edit_mode']) && $_POST['edit_mode'] == '1');
    // Validation simple
    $valFamille = ($_POST['famille'] === 'Autre') ? trim($_POST['other_famille'] ?? '') : trim($_POST['famille'] ?? '');
    $valCouleur = ($_POST['Couleur'] === 'Autre') ? trim($_POST['other_couleur'] ?? '') : trim($_POST['Couleur'] ?? '');

    if (
        empty($_POST['reference']) ||
        empty($valFamille) ||
        empty($valCouleur) ||
        empty($_POST['taille']) ||
        empty($_POST['qte'])
    ) {
        $error = "Tous les champs obligatoires doivent être remplis.";
        $showError = true;
    }

    // Gestion famille
    if ($_POST['famille'] === 'Autre' && !empty($_POST['other_famille'])) {
        $famille = trim($_POST['other_famille']);
        $stmt = $conn->prepare("INSERT IGNORE INTO Famille (nom) VALUES (?)");
        $stmt->bind_param("s", $famille);
        $stmt->execute();
    } else {
        $famille = $valFamille;
    }
    // Gestion couleur
    if ($_POST['Couleur'] === 'Autre' && !empty($_POST['other_couleur'])) {
        $couleur = trim($_POST['other_couleur']);
        $stmt = $conn->prepare("INSERT IGNORE INTO Couleur (nom) VALUES (?)");
        $stmt->bind_param("s", $couleur);
        $stmt->execute();
    } else {
        $couleur = $valCouleur;
    }

    if (empty($error)) {
        try {
            if ($isEdit) {
                // 1. Récupère l'ancien échantillon AVANT la modification
                $stmtOld = $conn->prepare("SELECT * FROM Echantillon WHERE RefEchantillon = ?");
                $stmtOld->bind_param("s", $_POST['reference']);
                $stmtOld->execute();
                $old = $stmtOld->get_result()->fetch_assoc();
                $stmtOld->close();

                // 2. Fais la modification
                $stmt = $conn->prepare("UPDATE Echantillon SET Famille=?, Couleur=?, Taille=?, Qte=?, Statut=?, Description=? WHERE RefEchantillon=?");
                $stmt->bind_param(
                    "sssisss",
                    $famille,
                    $couleur,
                    $_POST['taille'],
                    $_POST['qte'],
                    $_POST['statut'],
                    $_POST['description'],
                    $_POST['reference']
                );
                if (!$stmt->execute()) {
                    die("Erreur SQL (modif) : " . $stmt->error);
                }

                // 3. Génère la description détaillée
                $modifs = [];
                if ($old['Famille'] !== $famille) {
                    $modifs[] = "Famille : {$old['Famille']} → $famille";
                }
                if ($old['Couleur'] !== $couleur) {
                    $modifs[] = "Couleur : {$old['Couleur']} → $couleur";
                }
                if ($old['Taille'] !== $_POST['taille']) {
                    $modifs[] = "Taille : {$old['Taille']} → {$_POST['taille']}";
                }
                if ($old['Qte'] != $_POST['qte']) {
                    $modifs[] = "Qté : {$old['Qte']} → {$_POST['qte']}";
                }
                if ($old['Statut'] !== $_POST['statut']) {
                    $modifs[] = "Statut : {$old['Statut']} → {$_POST['statut']}";
                }
                if ($old['Description'] !== $_POST['description']) {
                    $oldDesc = $old['Description'] ?? '';
                    $newDesc = $_POST['description'] ?? '';
                    $modifs[] = 'Description : "' . htmlspecialchars($oldDesc) . '" → "' . htmlspecialchars($newDesc) . '"';
                }
                $nomPrenom = $_SESSION['user']['Prenom'] . ' ' . $_SESSION['user']['Nom'];
                if ($modifs) {
                    $description = "L'échantillon {$_POST['reference']} a été modifié : " . implode(', ', $modifs);
                } else {
                    $description = "Tentative de modification sur l'échantillon {$_POST['reference']} (aucune donnée changée).";
                }
                $dateMoinsUneHeure = date('Y-m-d H:i:s', strtotime('-1 hour'));
                ajouterHistorique($conn, $_SESSION['user']['id'], $_POST['reference'], 'Modification', $description, $dateMoinsUneHeure);
                $message = "Échantillon " . htmlspecialchars($_POST['reference']) . " modifié avec succès !";
                $_SESSION['notif_message'] = $message;
                $_SESSION['notif_type'] = "success";
                header('Location: dashboard_reception.php');
                exit;
            } else {
                // AJOUT inchangé
                $dateMoinsUneHeure = date('Y-m-d H:i:s', strtotime('-1 hour'));
                $stmt = $conn->prepare("INSERT INTO Echantillon (RefEchantillon, Famille, Couleur, Taille, Qte, Statut, Description, DateCreation, idutilisateur) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param(
                    "ssssisssi",
                    $_POST['reference'],
                    $famille,
                    $couleur,
                    $_POST['taille'],
                    $_POST['qte'],
                    $_POST['statut'],
                    $_POST['description'],
                    $dateMoinsUneHeure,
                    $_SESSION['user']['id']
                );
                if (!$stmt->execute()) {
                    if ($conn->errno == 1062) {
                        $error = "La référence existe déjà. Veuillez en choisir une autre.";
                    } else {
                        $error = "Erreur lors de l'ajout : " . $conn->error;
                    }
                    $showError = true;
                } else {
                    $nomPrenom = $_SESSION['user']['Prenom'] . ' ' . $_SESSION['user']['Nom'];
                    $description = "L’échantillon {$_POST['reference']} a été ajouté (Famille: $famille, Couleur: $couleur, Taille: {$_POST['taille']}, Qté: {$_POST['qte']}).";
                    ajouterHistorique($conn, $_SESSION['user']['id'], $_POST['reference'], 'Création', $description, $dateMoinsUneHeure);
                    $message = "Échantillon " . htmlspecialchars($_POST['reference']) . " ajouté avec succès !";
                    $_SESSION['notif_message'] = $message;
                    $_SESSION['notif_type'] = "success";
                    header('Location: dashboard_reception.php');
                    exit;
                }
            }
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                $error = "La référence existe déjà. Veuillez en choisir une autre.";
            } else {
                $error = "Erreur lors de l'ajout/modification : " . $e->getMessage();
            }
            $showError = true;
        }
    }
    // En cas d’erreur, le message s’affichera dans le formulaire ET en notification
    if (!empty($error)) {
        $_SESSION['notif_message'] = $error;
        $_SESSION['notif_type'] = "error";
        header('Location: dashboard_reception.php');
        exit;
    }
}

if (isset($_POST['delete_ref'])) {
    $ref = $_POST['delete_ref'];
    if (!echantillonSupprimable($conn, $ref)) {
        $_SESSION['notif_message'] = "Impossible de supprimer cet échantillon : il a déjà fait l'objet d'une demande ou d'une autre action.";
        $_SESSION['notif_type'] = "error";
        header('Location: dashboard_reception.php');
        exit;
    }
    // 1. Ajoute l'action "Suppression" dans l'historique
    $nomPrenom = $_SESSION['user']['Prenom'] . ' ' . $_SESSION['user']['Nom'];
    $description = "L’échantillon $ref a été supprimé.";
    $dateMoinsUneHeure = date('Y-m-d H:i:s', strtotime('-1 hour'));
    ajouterHistorique($conn, $_SESSION['user']['id'], $ref, 'Suppression', $description, $dateMoinsUneHeure);

    // 2. Supprime toutes les autres actions sauf "Création" et "Suppression"
    $stmt = $conn->prepare("DELETE FROM Historique WHERE RefEchantillon = ? AND TypeAction NOT IN ('Création', 'Suppression')");
    $stmt->bind_param("s", $ref);
    $stmt->execute();

    // 3. Supprime l'échantillon
    $stmt = $conn->prepare("DELETE FROM Echantillon WHERE RefEchantillon = ?");
    $stmt->bind_param("s", $ref);
    $stmt->execute();

    $_SESSION['notif_message'] = "Échantillon " . htmlspecialchars($ref) . " supprimé avec succès.";
    $_SESSION['notif_type'] = "success";
    header('Location: dashboard_reception.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Chef de Réception</title>
    <link rel="icon" type="image/png" href="photos/logo.png">
    <link rel="shortcut icon" type="image/png" href="photos/logo.png">
    <link rel="apple-touch-icon" href="photos/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
    <style>
        * { font-family: 'Inter', sans-serif; }
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
        .modal {
            backdrop-filter: blur(5px);
        }
        .badge {
  display: inline-block;
  padding: 0.25em 0.75em;
  border-radius: 9999px;
  font-size: 0.85em;
  font-weight: 500;
}
.badge-green { background: #d1fae5; color: #065f46; }
.badge-yellow { background: #fef3c7; color: #92400e; }
.badge-red { background: #fee2e2; color: #991b1b; }
.badge-gray { background: #e5e7eb; color: #374151; }
.badge-blue { background: #dbeafe; color: #1e40af; }
.badge-black { background: #d1d5db; color: #111; }
.ts-dropdown, .tom-select-dropdown {
    z-index: 99999 !important;
    background: #fff !important;
    color: #222 !important;
    box-shadow: 0 8px 32px rgba(0,0,0,0.18) !important;
    border-radius: 0.75rem !important;
    border: 1px solid #e5e7eb !important;
    padding: 0.5rem 0 !important;
}
.ts-dropdown .option, .tom-select-dropdown .option {
    color: #222 !important;
    background: #fff !important;
    padding: 0.5rem 1rem !important;
    font-size: 1rem !important;
    border-radius: 0.5rem !important;
}
.ts-dropdown .option.active, .tom-select-dropdown .option.active,
.ts-dropdown .option.selected, .tom-select-dropdown .option.selected {
    background: #667eea !important;
    color: #fff !important;
}
.ts-dropdown .no-results, .tom-select-dropdown .no-results {
    color: #888 !important;
    padding: 0.5rem 1rem !important;
}
#sampleModal {
    z-index: 1050 !important;
}
.modal {
    z-index: 1050 !important;
}
.ts-dropdown, .tom-select-dropdown {
    position: fixed !important;
    z-index: 99999 !important;
}
#sampleModal .fade-in {
    max-height: 90vh;
    overflow-y: auto;
}
#sampleModal > div {
    max-height: 90vh;
    overflow-y: auto;
        }
.option-autre {
    font-weight: bold !important;
    background: #fff !important;
    color: #111 !important;
    border-top: 1px solid #bbb !important;
}
.badge-suppression {
    background-color: #fee2e2;
    color: #b91c1c;
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
                        <strong>Bienvenue sur le tableau de bord Réception !</strong>
                    </p>
                    <p class="text-sm">
                        Ce tableau de bord vous permet de gérer les échantillons reçus. Vous pouvez :
                    </p>
                    <ul class="list-disc list-inside text-sm space-y-2 ml-2">
                        <li><strong>Ajouter</strong> de nouveaux échantillons au système</li>
                        <li><strong>Modifier</strong> les informations des échantillons existants</li>
                        <li><strong>Supprimer</strong> des échantillons du système</li>
                        <li><strong>Rechercher</strong> et filtrer les échantillons</li>
                        <li><strong>Consulter</strong> l'historique de toutes les opérations</li>
                    </ul>
                    <p class="text-sm mt-4 text-gray-600">
                        <i class="fas fa-inbox mr-1"></i>
                        En tant qu'agent de réception, vous êtes responsable de l'enregistrement initial de tous les échantillons.
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

    <div id="mainApp">
        <!-- Header -->
        <header class="bg-white shadow-lg border-b border-gray-200">
            <div class="flex items-center justify-between px-6 py-4">
                <div class="flex items-center space-x-4">
                    <img src="photos/logo.png" alt="Logo" class="h-10 w-10 object-contain" />
                    <div>
                        <h1 class="text-2xl font-bold text-gray-800">Gestion d'Échantillons</h1>
                        <p class="text-sm text-gray-600">Réception - Tableau de bord</p>
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
                            <p class="font-medium text-gray-800" id="userName"><?php echo htmlspecialchars($user['Nom']); ?></p>
                            <p class="text-sm text-gray-600" id="userRoleText">Réception</p>
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
                        <button class="sidebar-item w-full flex items-center px-4 py-3 text-left text-white hover:bg-white hover:bg-opacity-10 rounded-lg transition-all active" data-section="dashboard">
                            <i class="fas fa-tachometer-alt mr-3"></i>
                            <span>Tableau de bord</span>
                        </button>
                        <button class="sidebar-item w-full flex items-center px-4 py-3 text-left text-white hover:bg-white hover:bg-opacity-10 rounded-lg transition-all" data-section="samples">
                            <i class="fas fa-vial mr-3"></i>
                            <span>Échantillons</span>
                        </button>
                        <button class="sidebar-item w-full flex items-center px-4 py-3 text-left text-white hover:bg-white hover:bg-opacity-10 rounded-lg transition-all" data-section="history">
                            <i class="fas fa-history mr-3"></i>
                            <span>Historique</span>
                        </button>
                    </div>
                </nav>
            </aside>
            <!-- Main Content -->
            <main class="flex-1 p-6 bg-gray-50">
                <!-- Tableau de bord Section -->
                <div id="dashboardSection" class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <div class="card-hover bg-white p-6 rounded-lg shadow-md">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm text-gray-600">Total Échantillons Reçus</p>
                                    <p class="text-2xl font-bold text-gray-800" id="totalSamples"><?= $totalSamplesPHP ?></p>
                                </div>
                                <i class="fas fa-vial text-3xl text-indigo-600"></i>
                            </div>
                        </div>
                        <div class="card-hover bg-white p-6 rounded-lg shadow-md">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm text-gray-600">Reçus Aujourd'hui</p>
                                    <p class="text-2xl font-bold text-green-600" id="todaySamples"><?= $todaySamplesPHP ?></p>
                                </div>
                                <i class="fas fa-calendar-day text-3xl text-green-600"></i>
                            </div>
                        </div>
                    </div>
                        <div class="bg-white p-6 rounded-lg shadow-md">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Derniers Échantillons Ajoutés</h3>
                        <ul id="lastSamplesList" class="space-y-2">
                            <!-- Liste JS -->
                        </ul>
                    </div>
                </div>
                <!-- Échantillons Section -->
                <div id="samplesSection" class="hidden space-y-6">
                    <div class="bg-white p-6 rounded-lg shadow-md">
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-xl font-semibold text-gray-800">Gestion des Échantillons</h2>
                            <div class="flex space-x-2">
                                <button id="addSampleBtn" class="btn-primary text-white px-4 py-2 rounded-lg font-medium">
                                    <i class="fas fa-plus mr-2"></i>Nouvel Échantillon
                                </button>
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
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="samplesTableBody">
                                    <!-- Table content will be populated here -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <!-- Historique Section -->
                <div id="historySection" class="hidden space-y-6">
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
                            <?php
                            $users_query = $conn->query("SELECT DISTINCT U.idutilisateur AS idUtilisateur, U.prenom AS Prenom, U.nom AS Nom FROM Historique H LEFT JOIN Utilisateur U ON H.idutilisateur = U.idutilisateur WHERE U.idutilisateur IS NOT NULL ORDER BY U.nom, U.prenom");
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
                            ?>
                            <select id="historyUser" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="">Tous les utilisateurs</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?= htmlspecialchars($u['idUtilisateur']) ?>">
                                        <?= htmlspecialchars(($u['Prenom'] ?? '') . ' ' . ($u['Nom'] ?? '')) ?>
                                    </option>
                                <?php endforeach; ?>
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
    <!-- Modal Add/Edit Sample -->
    <div id="sampleModal" class="fixed inset-0 bg-black bg-opacity-50 modal hidden flex items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-2xl w-full max-w-md m-4 fade-in">
            <div class="flex items-center justify-between p-6 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800" id="sampleModalTitle">Nouvel Échantillon</h3>
                <button class="text-gray-400 hover:text-gray-600 close-modal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="sampleForm" class="p-6 space-y-4" method="post">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Référence</label>
                    <input type="text" id="sampleRef" name="reference" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
                </div>
                <!-- Famille -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Famille</label>
                    <select id="sampleFamily" name="famille" class="w-full px-3 py-2 border border-gray-300 rounded-lg" required>
                        <option value="">Sélectionner une famille</option>
                        <optgroup label="Familles existantes">
                            <?php foreach ($familles as $f): ?>
                                <option value="<?= htmlspecialchars($f['nom']) ?>"><?= htmlspecialchars($f['nom']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="">
                            <option value="Autre" class="option-autre">Autre</option>
                        </optgroup>
                    </select>
                </div>
                <input type="text" id="otherFamily" name="other_famille" class="w-full px-3 py-2 border border-gray-300 rounded-lg mt-2 hidden" placeholder="Entrer une nouvelle famille">

                <!-- Couleur -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Couleur</label>
                    <select id="sampleColor" name="Couleur" class="w-full px-3 py-2 border border-gray-300 rounded-lg" required>
                        <option value="">Sélectionner une couleur</option>
                        <optgroup label="Couleurs existantes">
                            <?php foreach ($couleurs as $c): ?>
                                <option value="<?= htmlspecialchars($c['nom']) ?>"><?= htmlspecialchars($c['nom']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="">
                            <option value="Autre" class="option-autre">Autre</option>
                        </optgroup>
                    </select>
                </div>
                <input type="text" id="otherColorInput" name="other_couleur" class="w-full px-3 py-2 border border-gray-300 rounded-lg mt-2 hidden" placeholder="Entrer une nouvelle couleur">

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Taille</label>
                    <select id="sampleSize" name="taille" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
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
                    <label class="block text-sm font-medium text-gray-700 mb-2">Quantité</label>
                    <input type="number" id="sampleQte" name="qte" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" value="1" min="1">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Statut</label>
                    <input type="hidden" id="sampleStatus" name="statut" value="Disponible">
                    <span class="badge badge-green">Disponible</span>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                    <textarea id="sampleDescription" name="description" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" rows="3" placeholder="Description optionnelle"></textarea>
                </div>
                <input type="hidden" name="action" value="add_sample">
                <input type="hidden" id="editMode" name="edit_mode" value="0">
                <div class="flex justify-end space-x-3">
                    <button type="button" class="px-4 py-2 text-gray-600 border border-gray-300 rounded-lg hover:bg-gray-50 close-modal">Annuler</button>
                    <button type="submit" class="btn-primary text-white px-4 py-2 rounded-lg font-medium">
                        <span id="sampleSubmitText"><?php echo (isset($_POST['edit_mode']) && $_POST['edit_mode'] == '1') ? 'Modifier' : 'Ajouter'; ?></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <!-- Modal Détails Échantillon -->
    <div id="detailsModal" class="fixed inset-0 bg-black bg-opacity-50 modal hidden flex items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-2xl w-full max-w-md m-4 fade-in">
            <div class="flex items-center justify-between p-6 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800">Détails de l'échantillon</h3>
                <button class="text-gray-400 hover:text-gray-600 close-details-modal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-6 space-y-3" id="detailsContent">
                <!-- Contenu dynamique -->
            </div>
        </div>
    </div>
    <!-- Modale de confirmation de suppression -->
    <div id="deleteConfirmModal" class="fixed inset-0 bg-black bg-opacity-50 modal hidden flex items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-2xl w-full max-w-md m-4 fade-in">
            <div class="flex items-center justify-between p-6 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800">Confirmation de suppression</h3>
                <button class="text-gray-400 hover:text-gray-600 close-delete-modal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-6 space-y-3">
                <p class="text-gray-700">Voulez-vous vraiment supprimer cet échantillon ? Cette action est irréversible.</p>
                <div class="flex justify-end gap-3 mt-4">
                    <button id="cancelDeleteBtn" class="px-4 py-2 text-gray-600 border border-gray-300 rounded-lg hover:bg-gray-50">Annuler</button>
                    <form id="deleteSampleForm" method="post" class="inline">
                        <input type="hidden" name="delete_ref" id="deleteRefInput">
                        <button type="submit" class="btn-primary text-white px-4 py-2 rounded-lg font-medium bg-red-600 hover:bg-red-700">Supprimer</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <div id="notificationsContainer" class="fixed top-4 right-4 z-50 flex flex-col gap-2"></div>
    <script>
        let samples = <?= json_encode($samples) ?>;
        let totalSamples = <?= $totalSamplesPHP ?>;
        let todaySamples = <?= $todaySamplesPHP ?>;
        let lastSamples = <?php echo isset($lastSamplesPHP) ? json_encode($lastSamplesPHP) : '[]'; ?>;
        // Après la récupération de $samples, récupérer aussi le nom/prénom de l'utilisateur pour chaque échantillon :
        // if (lastSamples) {
        //     lastSamples = lastSamples.map(sample => {
        //         // Si l'utilisateur n'est pas trouvé (par exemple, si c'est un échantillon ajouté avant l'ajout de l'utilisateur)
        //         // On peut définir un nom/prénom par défaut ou laisser vide
        //         const user = sample.Nom || sample.Prenom || 'Inconnu';
        //         return {
        //             ...sample,
        //             Nom: user, // Ajouter le nom de l'utilisateur
        //             Prenom: user // Ajouter le prénom de l'utilisateur
        //         };
        //     });
        // }

        // --- Fonction pour afficher les échantillons dans la table ---
        function renderSamples(list = samples) {
            const tbody = document.getElementById('samplesTableBody');
            tbody.innerHTML = '';
            list.forEach((sample, idx) => {
                // Vérifie si supprimable (injecté depuis PHP)
                const canDelete = sample.canDelete !== false;
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="px-4 py-3 font-bold uppercase">${sample.RefEchantillon}</td>
                    <td class="px-4 py-3">${sample.Famille}</td>
                    <td class="px-4 py-3">${getColorBadge(sample.Couleur)}</td>
                    <td class="px-4 py-3">${sample.Taille}</td>
                    <td class="px-4 py-3">
                        <div class="space-y-1">
                            <div class="text-sm"><span class="font-semibold text-blue-600">Stock:</span> ${sample.Qte}</div>
                            <div class="text-sm"><span class="font-semibold text-green-600">Disponible:</span> ${sample.QteReellementDisponible || sample.Qte}</div>
                            ${sample.QteEmprunteeNonRetournee > 0 ? `<div class="text-sm"><span class="font-semibold text-red-600">Emprunté:</span> ${sample.QteEmprunteeNonRetournee}</div>` : ''}
                        </div>
                    </td>
                    <td class="px-4 py-3">${getStatusBadge(sample.Statut, sample.Qte, sample.QteEmprunteeNonRetournee, sample.QteInitial)}</td>
                    <td class="px-4 py-3">${sample.DateCreation}</td>
                    <td class="px-4 py-3 flex space-x-2">
                        <button class="text-blue-600 hover:text-blue-800" title="Voir" data-idx="${idx}"><i class="fas fa-eye"></i></button>
                        <button class="text-blue-600 hover:text-blue-800 editSampleBtn" data-idx="${idx}" title="Modifier"><i class="fas fa-edit"></i></button>
                        <button class="text-red-600 hover:text-red-800 deleteSampleBtn" data-idx="${idx}" title="Supprimer" ${canDelete ? '' : 'disabled style="opacity:0.4;pointer-events:none;"'}><i class="fas fa-trash"></i></button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

        function getColorBadge(color) {
            const colorMap = {
                'Rouge': '#ef4444',
                'Noir': '#374151',
                'Bleu': '#3b82f6',
                'Argent': '#9ca3af',
                'Blanc': '#d1d5db',
                'Vert': '#10b981',
                'Jaune': '#f59e0b',
                'Violet': '#8b5cf6',
                'Rose': '#ec4899',
                'Orange': '#f97316',
                'Beige': '#f5f5dc',
                'Anthracite': '#2d2d2d',
                'Marron': '#7c4700',
                'Turquoise': '#06b6d4',
                'Doré': '#fbbf24',
                'Gris': '#6b7280',
                'Bordeaux': '#7c2d12',
            };
            function getTextColor(bgHex) {
                bgHex = bgHex.replace('#', '');
                const r = parseInt(bgHex.substring(0,2), 16);
                const g = parseInt(bgHex.substring(2,4), 16);
                const b = parseInt(bgHex.substring(4,6), 16);
                const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
                return luminance > 0.6 ? '#222' : '#fff';
            }
            const bg = colorMap[color] || '#6b7280';
            const textColor = getTextColor(bg);
            return `<span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium" style="background-color: ${bg}; color: ${textColor};">${color}</span>`;
        }

        function getStatusBadge(status, qte, QteEmprunteeNonRetournee, QteInitial) {
            // CORRECTION: Logique de statut basée sur les quantités réelles
            const qteDisponible = parseInt(qte) || 0;
            const qteEmpruntee = parseInt(QteEmprunteeNonRetournee) || 0;
            
            // Si il y a des quantités empruntées non retournées, l'échantillon est partiellement emprunté
            if (qteEmpruntee > 0) {
                if (qteDisponible > 0) {
                    return '<span class="badge badge-yellow">Partiellement emprunté</span>';
                } else {
                    return '<span class="badge badge-red">Entièrement emprunté</span>';
                }
            }
            
            // Si pas d'emprunts, vérifier le statut de la base
            if (status === 'En fabrication' || status === 'fabrication') {
                return '<span class="badge badge-blue">En fabrication</span>';
            } else if (qteDisponible > 0) {
                return '<span class="badge badge-green">Disponible</span>';
            } else {
                return '<span class="badge badge-gray">Stock épuisé</span>';
            }
        }

        // --- Fonction pour afficher les stats et derniers échantillons ---
        function renderStats() {
            document.getElementById('totalSamples').textContent = totalSamples;
            document.getElementById('todaySamples').textContent = todaySamples;
            const lastList = document.getElementById('lastSamplesList');
            lastList.innerHTML = '';
            lastSamples.forEach(sample => {
                const date = sample.DateCreation ? new Date(sample.DateCreation.replace(' ', 'T')) : '';
                const dateStr = date ? date.toLocaleString('fr-FR', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' }) : '';
                let desc = sample.Description ? `<div class='text-xs text-gray-500 mt-1'>${sample.Description}</div>` : '';
                let user = (sample.Nom || sample.Prenom) ? `<span class='text-indigo-700 font-semibold'>${sample.Prenom || ''} ${sample.Nom || ''}</span>` : `<span class='text-gray-400'>Inconnu</span>`;
                const li = document.createElement('li');
                li.className = 'bg-white border border-indigo-100 shadow-sm rounded-lg px-4 py-3 mb-2 flex flex-col gap-1';
                li.innerHTML = `
                    <div class='flex items-center gap-2 mb-1'>
                        <i class='fas fa-vial text-indigo-500'></i>
                        <span class='font-bold text-indigo-700 text-lg'>${sample.RefEchantillon}</span>
                        <span class='ml-2 text-gray-700'>${sample.Famille}</span>
                        <span class='ml-2 text-gray-700'>${sample.Couleur}</span>
                        <span class='ml-2 text-gray-500'>Taille: ${sample.Taille}</span>
                        <span class='ml-2 text-gray-500'>Qté: ${sample.Qte}</span>
                    </div>
                    <div class='flex items-center justify-between'>
                        <span class='text-xs text-gray-400'>${dateStr}</span>
                        <span class='text-xs text-right'>Ajouté par ${user}</span>
                    </div>
                    ${desc}
                `;
                lastList.appendChild(li);
            });
        }

        // --- Ouvrir la modale d'ajout ---
        const addSampleBtn = document.getElementById('addSampleBtn');
        const sampleModal = document.getElementById('sampleModal');
        const sampleForm = document.getElementById('sampleForm');

        addSampleBtn.addEventListener('click', () => {
            document.getElementById('sampleModalTitle').textContent = 'Nouvel Échantillon';
            document.getElementById('sampleSubmitText').textContent = 'Ajouter';
            sampleForm.reset();
            document.getElementById('sampleStatus').value = 'Disponible'; // Reset status to default
            document.getElementById('editMode').value = 0; // Ensure it's 0 for new sample
            sampleModal.classList.remove('hidden');
        });

        // --- Fermer la modale (bouton Annuler ou croix) ---
        document.querySelectorAll('.close-modal').forEach(btn => {
            btn.addEventListener('click', () => {
                document.getElementById('sampleRef').readOnly = false;
                document.getElementById('editMode').value = 0;
                sampleModal.classList.add('hidden');
            });
        });

        // --- Validation du formulaire d'ajout ---
        // Pour savoir si on édite ou ajoute
        let editSampleIdx = null;

        // Ouvrir la modale en mode édition
        document.addEventListener('click', function(e) {
            if (e.target.closest('.editSampleBtn')) {
                const idx = e.target.closest('.editSampleBtn').getAttribute('data-idx');
                const sample = samples[idx];
                editSampleIdx = idx;
                document.getElementById('sampleModalTitle').textContent = 'Modifier Échantillon';
                document.getElementById('sampleSubmitText').textContent = 'Modifier';
                document.getElementById('sampleRef').value = sample.RefEchantillon;
                document.getElementById('sampleRef').readOnly = true;
                // --- ICI : gestion famille/couleur ---
                const tomFamille = document.getElementById('sampleFamily').tomselect;
                const tomCouleur = document.getElementById('sampleColor').tomselect;

                // --- Famille ---
                if (tomFamille.options.hasOwnProperty(sample.Famille)) {
                    tomFamille.setValue(sample.Famille);
                    document.getElementById('otherFamily').classList.add('hidden');
                    document.getElementById('otherFamily').value = '';
                } else {
                    tomFamille.setValue('Autre');
                    document.getElementById('otherFamily').classList.remove('hidden');
                    document.getElementById('otherFamily').value = sample.Famille;
                }

                // --- Couleur ---
                if (tomCouleur.options.hasOwnProperty(sample.Couleur)) {
                    tomCouleur.setValue(sample.Couleur);
                    document.getElementById('otherColorInput').classList.add('hidden');
                    document.getElementById('otherColorInput').value = '';
                } else {
                    tomCouleur.setValue('Autre');
                    document.getElementById('otherColorInput').classList.remove('hidden');
                    document.getElementById('otherColorInput').value = sample.Couleur;
                }
                document.getElementById('sampleSize').value = sample.Taille;
                document.getElementById('sampleQte').value = sample.Qte;
                document.getElementById('sampleDescription').value = sample.Description;
                document.getElementById('sampleStatus').value = sample.Statut;
                document.getElementById('editMode').value = 1;
                sampleModal.classList.remove('hidden');
            }
        });

        // --- Editer un échantillon ---
        document.addEventListener('click', function(e) {
            if (e.target.closest('.deleteSampleBtn')) {
                const idx = e.target.closest('.deleteSampleBtn').getAttribute('data-idx');
                const sample = samples[idx];
                if (e.target.closest('button').disabled) return;
                document.getElementById('deleteRefInput').value = sample.RefEchantillon;
                document.getElementById('deleteConfirmModal').classList.remove('hidden');
            }
            // Fermer la modale
            if (e.target.classList.contains('close-modal') || e.target.closest('.close-modal')) {
                document.getElementById('sampleRef').readOnly = false;
                document.getElementById('editMode').value = 0;
                sampleModal.classList.add('hidden');
            }
        });

        // --- Voir les détails ---
        document.addEventListener('click', function(e) {
            if (e.target.closest('.fa-eye')) {
                const idx = e.target.closest('button').getAttribute('data-idx');
                const sample = samples[idx];
                // Remplir la modale de détails
                const content = `
                    <div><span class='font-semibold'>Référence :</span> ${sample.RefEchantillon}</div>
                    <div><span class='font-semibold'>Famille :</span> ${sample.Famille}</div>
                    <div><span class='font-semibold'>Couleur :</span> ${sample.Couleur}</div>
                    <div><span class='font-semibold'>Taille :</span> ${sample.Taille}</div>
                    <div><span class='font-semibold'>Quantité :</span> ${sample.Qte}</div>
                    <div><span class='font-semibold'>Statut :</span> ${sample.Statut}</div>
                    <div><span class='font-semibold'>Description :</span> ${sample.Description || ''}</div>
                    <div><span class='font-semibold'>Date création :</span> ${sample.DateCreation}</div>
                `;
                document.getElementById('detailsContent').innerHTML = content;
                document.getElementById('detailsModal').classList.remove('hidden');
            }
            // Fermer la modale détails
            if (e.target.classList.contains('close-details-modal') || e.target.closest('.close-details-modal')) {
                document.getElementById('detailsModal').classList.add('hidden');
            }
        });

        // --- Historique ---
        <?php
        $historiques = $conn->query(
            "SELECT H.*, U.nom AS Nom, U.prenom AS Prenom FROM Historique H LEFT JOIN Utilisateur U ON H.idutilisateur = U.idutilisateur ORDER BY H.DateAction DESC LIMIT 100"
        )->fetch_all(MYSQLI_ASSOC);
        ?>
        let historiques = <?php echo json_encode($historiques); ?>;
        // console.log(historiques); // Ajoute cette ligne pour debug
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
                    // On force le format ISO pour que JS comprenne bien la date
                    let iso = row.DateAction.replace(' ', 'T');
                    // Si la date n'a pas de secondes, on ajoute :00
                    if (/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}$/.test(iso)) {
                        iso += ':00';
                    }
                    const date = new Date(iso);
                    if (!isNaN(date)) {
                        dateStr = date.toLocaleString('fr-FR', {
                            year: 'numeric',
                            month: '2-digit',
                            day: '2-digit',
                            hour: '2-digit',
                            minute: '2-digit'
                        });
                    } else {
                        dateStr = row.DateAction; // fallback brut
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
                    'Fabrication': 'bg-purple-100 text-purple-800',
                    'Retour': 'bg-indigo-100 text-indigo-800'
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

        function toISODate(str) {
            // str = '15/07/2025 11:48:44' => '2025-07-15'
            if (!str) return '';
            const parts = str.split(' ')[0].split('/');
            if (parts.length !== 3) return '';
            return `${parts[2]}-${parts[1].padStart(2, '0')}-${parts[0].padStart(2, '0')}`;
        }

        // --- Déconnexion ---
        document.getElementById('logoutBtn').addEventListener('click', function() {
            window.location.href = 'logout.php';
        });

        // --- Rafraîchir les données lors du changement de section ---
        // SUPPRIMER ces lignes si elles existent :
        // samplesSection.addEventListener('click', loadSamples);
        // historySection.addEventListener('click', renderHistory);
        //
        // On ne doit PAS rappeler renderHistory ni renderSamples sur navigation !
        //
        // À l'initialisation seulement :
        renderSamples();
        renderStats();
        renderHistory();
        //
        // Après chaque ajout/modif/suppression :
        // filterSamples();
        // filterHistory();
        //
        // Pour chaque filtre ou recherche :
        // document.getElementById('searchSamples').addEventListener('input', filterSamples);
        // document.getElementById('filterStatus').addEventListener('change', filterSamples);
        // document.getElementById('filterFamily').addEventListener('change', filterSamples);
        // document.getElementById('historyDateStart').addEventListener('change', filterHistory);
        // document.getElementById('historyDateEnd').addEventListener('change', filterHistory);
        // document.getElementById('historyAction').addEventListener('change', filterHistory);
        // document.getElementById('historyUser').addEventListener('change', filterHistory);
        // document.getElementById('historySearch').addEventListener('input', filterHistory);

        // --- Recherche et filtres combinés ---
        function normalizeStr(str) {
            return (str || '').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();
        }

        function filterSamples() {
            const searchTerm = normalizeStr(document.getElementById('searchSamples').value);
            const statusFilter = document.getElementById('filterStatus').value;
            const familyFilter = document.getElementById('filterFamily').value;
            let filteredSamples = samples;

            if (searchTerm) {
                filteredSamples = filteredSamples.filter(sample => {
                    const ref = normalizeStr(sample.RefEchantillon);
                    const fam = normalizeStr(sample.Famille);
                    const col = normalizeStr(sample.Couleur);
                    const desc = normalizeStr(sample.Description);
                    return ref.startsWith(searchTerm) || fam.startsWith(searchTerm) || col.startsWith(searchTerm) || desc.startsWith(searchTerm);
                });
            }

            if (statusFilter) {
                const wantedStatus = normalizeStr(statusFilter);
                filteredSamples = filteredSamples.filter(sample => normalizeStr(sample.Statut) === wantedStatus);
            }

            if (familyFilter) {
                const wantedFamily = normalizeStr(familyFilter);
                filteredSamples = filteredSamples.filter(sample => normalizeStr(sample.Famille) === wantedFamily);
            }

            renderSamples(filteredSamples);
        }

        document.getElementById('searchSamples').addEventListener('input', function() {
            filterSamples();
            const searchTerm = this.value.trim();
            if (searchTerm.length > 0) {
                // addHistory('Recherche', { reference: searchTerm }); // This line was removed as per the new_code
            }
        });
        document.getElementById('filterStatus').addEventListener('change', filterSamples);
        document.getElementById('filterFamily').addEventListener('change', filterSamples);

        // --- Remplir dynamiquement le filtre famille ---
        function populateFamilyFilter() {
            const filterFamily = document.getElementById('filterFamily');
            const currentValue = filterFamily.value; // Sauvegarde la sélection
            const families = [...new Set(samples.map(s => s.Famille))];
            filterFamily.innerHTML = '<option value="">Toutes les familles</option>' +
                families.map(fam => `<option value="${fam}">${fam}</option>`).join('');
            filterFamily.value = currentValue; // Restaure la sélection
        }

        // --- Remplir dynamiquement le filtre statut ---
        function populateStatusFilter() {
            const filterStatus = document.getElementById('filterStatus');
            const currentValue = filterStatus.value; // Sauvegarde la sélection
            const fixedStatuts = ['Disponible', 'Emprunté', 'En fabrication'];
            const dynamicStatuts = [...new Set(samples.map(s => s.Statut))];
            const allStatuts = Array.from(new Set([...fixedStatuts, ...dynamicStatuts]));
            filterStatus.innerHTML = '<option value="">Tous les statuts</option>' +
                allStatuts.map(st => `<option value="${st}">${st}</option>`).join('');
            filterStatus.value = currentValue; // Restaure la sélection
        }

        // --- Appeler ces fonctions à l'initialisation et après chaque ajout/suppression/modification ---
        populateFamilyFilter();
        populateStatusFilter();

        // Après chaque ajout/modif/suppression d'échantillon, ajoute :
        // populateFamilyFilter();
        // populateStatusFilter();
        // filterSamples();

        // Par exemple, après un ajout/modif/suppression dans le submit du formulaire ou la suppression :
        // ...
        // samples.unshift(sample);
        // ...
        // populateFamilyFilter();
        // populateStatusFilter();
        // filterSamples();
        // ...
        // Idem pour modif/suppression

        // --- Filtrage et recherche de l'historique comme chef de stock ---
        function filterHistory() {
            const startDate = document.getElementById('historyDateStart').value; // format YYYY-MM-DD
            const endDate = document.getElementById('historyDateEnd').value;
            const action = document.getElementById('historyAction').value;
            const userId = document.getElementById('historyUser') ? document.getElementById('historyUser').value : '';
            const searchTerm = document.getElementById('historySearch').value.toLowerCase();

            let filteredHistory = historiques;

            if (startDate) {
                const start = new Date(startDate);
                filteredHistory = filteredHistory.filter(h => {
                    const d = toISODate(h.DateAction);
                    if (!d) return false;
                    // On compare les objets Date
                    return new Date(d) >= start;
                });
            }
            if (endDate) {
                const end = new Date(endDate);
                filteredHistory = filteredHistory.filter(h => {
                    const d = toISODate(h.DateAction);
                    if (!d) return false;
                    return new Date(d) <= end;
                });
            }
            if (action) {
                filteredHistory = filteredHistory.filter(h => h.TypeAction === action);
            }
            if (userId) {
                filteredHistory = filteredHistory.filter(h => String(h.idUtilisateur) === String(userId));
            }
            if (searchTerm) {
                filteredHistory = filteredHistory.filter(h =>
                    (h.DateAction && h.DateAction.toLowerCase().startsWith(searchTerm)) ||
                    (h.NomUtilisateur && h.NomUtilisateur.toLowerCase().startsWith(searchTerm)) ||
                    (h.TypeAction && h.TypeAction.toLowerCase().startsWith(searchTerm)) ||
                    (h.RefEchantillon && h.RefEchantillon.toLowerCase().startsWith(searchTerm)) ||
                    (h.Description && h.Description.toLowerCase().startsWith(searchTerm))
                );
            }
            renderHistory(filteredHistory);
        }

        document.getElementById('historyDateStart').addEventListener('change', filterHistory);
        document.getElementById('historyDateEnd').addEventListener('change', filterHistory);
        document.getElementById('historyAction').addEventListener('change', filterHistory);
        document.getElementById('historyUser').addEventListener('change', filterHistory);
        document.getElementById('historySearch').addEventListener('input', filterHistory);

        // --- Gestion du menu latéral avec mémorisation ---
        const dashboardSection = document.getElementById('dashboardSection');
        const samplesSection = document.getElementById('samplesSection');
        const historySection = document.getElementById('historySection');
        const sidebarItems = document.querySelectorAll('.sidebar-item');

        function showSection(section) {
            dashboardSection.classList.add('hidden');
            samplesSection.classList.add('hidden');
            historySection.classList.add('hidden');
            if (section === 'dashboard') dashboardSection.classList.remove('hidden');
            if (section === 'samples') samplesSection.classList.remove('hidden');
            if (section === 'history') historySection.classList.remove('hidden');
            // Mémorise la section
            localStorage.setItem('receptionSection', section);
            // Active le bon bouton
            sidebarItems.forEach(i => i.classList.remove('active'));
            document.querySelector(`.sidebar-item[data-section='${section}']`).classList.add('active');
        }

        // Au clic sur un bouton du menu
        sidebarItems.forEach(item => {
            item.addEventListener('click', function() {
                showSection(this.getAttribute('data-section'));
            });
        });

        // Au chargement, restaure la dernière section vue
        const lastSection = localStorage.getItem('receptionSection') || 'dashboard';
        showSection(lastSection);

        // --- Initialisation Tom Select ---
        new TomSelect('#sampleFamily', {
            create: false,
            sortField: 'text',
            placeholder: 'Rechercher une famille...',
            dropdownParent: 'body'
        });
        new TomSelect('#sampleColor', {
            create: false,
            sortField: 'text',
            placeholder: 'Rechercher une couleur...',
            dropdownParent: 'body'
        });

        // Affiche le champ texte si "Autre" est sélectionné
        document.getElementById('sampleFamily').addEventListener('change', function() {
            document.getElementById('otherFamily').classList.toggle('hidden', this.value !== 'Autre');
        });
        document.getElementById('sampleColor').addEventListener('change', function() {
            document.getElementById('otherColorInput').classList.toggle('hidden', this.value !== 'Autre');
        });

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
            }, 6000); // 6 secondes
        }
    </script>
    <script>
        // Appel PHP (notification réelle)
        <?php if (!empty($notifMessage)): ?>
            showNotification(<?= json_encode($notifMessage) ?>, <?= json_encode($notifType) ?>);
        <?php endif; ?>
    </script>
    <script>
document.addEventListener('click', function(e) {
    if (e.target.closest('.deleteSampleBtn')) {
        const idx = e.target.closest('.deleteSampleBtn').getAttribute('data-idx');
        const sample = samples[idx];
        if (e.target.closest('button').disabled) return;
        document.getElementById('deleteRefInput').value = sample.RefEchantillon;
        document.getElementById('deleteConfirmModal').classList.remove('hidden');
    }
    if (e.target.classList.contains('close-delete-modal') || e.target.closest('.close-delete-modal')) {
        document.getElementById('deleteConfirmModal').classList.add('hidden');
    }
});
document.getElementById('cancelDeleteBtn').addEventListener('click', function() {
    document.getElementById('deleteConfirmModal').classList.add('hidden');
});
</script>
<!-- Modal de confirmation de suppression -->
<div id="deleteModal" class="fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center z-50 hidden">
    <div class="bg-white rounded-lg shadow-lg p-8 max-w-sm w-full text-center">
        <h2 class="text-xl font-bold mb-4 text-red-600"><i class="fas fa-exclamation-triangle mr-2"></i>Confirmation</h2>
        <p class="mb-6">Voulez-vous vraiment supprimer cet élément ?</p>
        <form id="deleteForm" method="post" class="inline">
            <input type="hidden" name="idEchantillon" id="deleteEchantillonId" value="">
            <input type="hidden" name="action" value="delete">
            <button type="button" onclick="closeDeleteModal()" class="px-4 py-2 bg-gray-300 rounded mr-2 hover:bg-gray-400">Annuler</button>
            <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700">Supprimer</button>
        </form>
    </div>
</div>
<script>
function openDeleteModal(id) {
    document.getElementById('deleteEchantillonId').value = id;
    document.getElementById('deleteModal').classList.remove('hidden');
}
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
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
</script>
</body>
</html> 