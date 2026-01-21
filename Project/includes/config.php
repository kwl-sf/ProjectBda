<?php
// includes/config.php


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
}

if (!defined('DB_USER')) {
    define('DB_USER', 'root');
}

if (!defined('DB_PASS')) {
    define('DB_PASS', '');
}

if (!defined('DB_NAME')) {
    define('DB_NAME', 'planning_examens_dep');
}


try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    die("Erreur de connexion à la base de données: " . $e->getMessage());
}

// URL de base
if (!defined('BASE_URL')) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $folder = dirname($_SERVER['SCRIPT_NAME']);
    define('BASE_URL', $protocol . $host . $folder);
}

// Configuration du site
if (!defined('SITE_NAME')) {
    define('SITE_NAME', 'Planification Examens - Université');
}

if (!defined('SITE_VERSION')) {
    define('SITE_VERSION', '2.0.0');
}


if (!defined('ROLES')) {
    define('ROLES', [
        'admin' => 'Administrateur Examens',
        'doyen' => 'Doyen',
        'vice_doyen' => 'Vice-Doyen',
        'chef_dept' => 'Chef de Département',
        'prof' => 'Professeur',
        'etudiant' => 'Étudiant'
    ]);
}