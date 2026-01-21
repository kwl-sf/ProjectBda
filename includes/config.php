<?php
// Démarrage de la session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Si db.php échoue, continuez sans base de données
if (!isset($db) && file_exists(__DIR__ . '/../db.php')) {
    require_once __DIR__ . '/../db.php';
}

// Paramètres du site
define('SITE_NAME', 'Système de Planification des Examens');
define('SITE_VERSION', '2.0');
?>