<?php
// Configuration globale pour désactiver l'affichage des erreurs
// Inclure ce fichier au début de chaque page PHP

// Désactiver l'affichage des erreurs
error_reporting(0);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

// Activer le logging des erreurs (sans affichage)
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

// Masquer les warnings et notices
ini_set('html_errors', 0);

// Optimisations de performance
ini_set('opcache.enable', 1);
ini_set('opcache.memory_consumption', 128);
ini_set('opcache.max_accelerated_files', 10000);

// Désactiver les informations de débogage
ini_set('expose_php', 0);
?>

