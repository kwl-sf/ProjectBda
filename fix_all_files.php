<?php
// fix_functions.php
echo "<h1>Correction des fonctions get_current_user()</h1>";

$files_to_fix = [
    'admin/dashboard.php',
    'admin/generate_schedule.php', 
    'admin/manage_rooms.php',
    'admin/conflicts.php'
];

foreach ($files_to_fix as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        $new_content = str_replace('get_current_user()', 'get_logged_in_user()', $content);
        
        if ($content !== $new_content) {
            file_put_contents($file, $new_content);
            echo "<p style='color: green;'>✅ $file corrigé</p>";
        } else {
            echo "<p>$file : déjà corrigé</p>";
        }
    } else {
        echo "<p style='color: orange;'>⚠️ $file : non trouvé</p>";
    }
}

echo "<hr>";
echo "<h2>Vérification des fonctions :</h2>";

// Vérifier si get_current_user() est une fonction PHP
if (function_exists('get_current_user')) {
    echo "<p>PHP get_current_user() existe, retourne: " . get_current_user() . "</p>";
}

// Vérifier notre fonction
require_once 'includes/auth.php';
if (function_exists('get_logged_in_user')) {
    echo "<p>Notre fonction get_logged_in_user() existe ✓</p>";
}

echo "<p><a href='admin/dashboard.php'>Tester le dashboard admin</a></p>";
?>