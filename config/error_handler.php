<?php
// Gestionnaire d'erreurs centralisé
// Ce fichier gère l'affichage des erreurs de manière centralisée

function displayError($message, $type = 'error') {
    // Stocker l'erreur en session pour affichage dans les dashboards
    if (!isset($_SESSION)) {
        session_start();
    }
    
    $_SESSION['error'] = [
        'message' => $message,
        'type' => $type,
        'timestamp' => time()
    ];
}

function getError() {
    if (!isset($_SESSION)) {
        session_start();
    }
    
    if (isset($_SESSION['error'])) {
        $error = $_SESSION['error'];
        unset($_SESSION['error']);
        return $error;
    }
    return null;
}

// Fonction pour logger les erreurs (optionnel)
function logError($message, $file = 'error.log') {
    $logFile = __DIR__ . '/../logs/' . $file;
    $logDir = dirname($logFile);
    
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

