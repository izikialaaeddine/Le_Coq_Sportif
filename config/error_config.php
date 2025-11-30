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
ini_set('opcache.revalidate_freq', 60);

// Désactiver les informations de débogage
ini_set('expose_php', 0);

// Optimisations mémoire
ini_set('memory_limit', '128M');
ini_set('max_execution_time', 15);

// Compression de sortie (désactivée pour éviter les problèmes de performance)
// if (extension_loaded('zlib') && !ob_get_level()) {
//     ob_start('ob_gzhandler');
// }

// Ne pas démarrer la session ici - elle doit être démarrée dans les fichiers qui en ont besoin

// Optimiser les requêtes
ini_set('default_socket_timeout', 5);

