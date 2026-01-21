<?php
// test_password.php
require_once 'includes/config.php';

echo "<h1>Test des Mots de Passe dans la Base de Données</h1>";

try {
    // Tester tous les utilisateurs
    $tables = [
        'professeurs' => ['role', 'admin', 'doyen', 'vice_doyen', 'chef_dept', 'prof'],
        'etudiants' => ['role', 'etudiant']
    ];
    
    foreach ($tables as $table => $roles) {
        echo "<h2>Table: $table</h2>";
        
        $query = "SELECT id, nom, prenom, email, password_hash";
        if (isset($roles[0]) && $roles[0] === 'role') {
            $query .= ", role";
        }
        $query .= " FROM $table";
        
        $stmt = $pdo->query($query);
        $users = $stmt->fetchAll();
        
        echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
        echo "<tr>
                <th>ID</th>
                <th>Nom</th>
                <th>Email</th>
                <th>Rôle</th>
                <th>Hash (premier 30 chars)</th>
                <th>Test password123</th>
                <th>Longueur Hash</th>
            </tr>";
        
        foreach ($users as $user) {
            $hash = $user['password_hash'];
            $test_password = 'password123';
            $test_result = password_verify($test_password, $hash) ? '✅ VRAI' : '❌ FAUX';
            
            echo "<tr>";
            echo "<td>{$user['id']}</td>";
            echo "<td>{$user['prenom']} {$user['nom']}</td>";
            echo "<td>{$user['email']}</td>";
            echo "<td>" . ($user['role'] ?? $roles[1]) . "</td>";
            echo "<td>" . substr($hash, 0, 30) . "...</td>";
            echo "<td>$test_result</td>";
            echo "<td>" . strlen($hash) . " chars</td>";
            echo "</tr>";
        }
        
        echo "</table>";
        
        // Suggestions de correction
        echo "<h3>Suggestions :</h3>";
        echo "<ol>";
        echo "<li>Si password_verify retourne FAUX, le mot de passe dans la base n'est pas 'password123'</li>";
        echo "<li>La longueur d'un hash bcrypt doit être de 60 caractères</li>";
        echo "<li>Pour réinitialiser un mot de passe, exécutez :</li>";
        echo "</ol>";
        
        echo "<pre style='background: #f4f4f4; padding: 10px;'>";
        echo "UPDATE $table SET password_hash = ? WHERE email = ?;\n";
        echo "// Avec le hash de 'password123':\n";
        echo "// " . password_hash('password123', PASSWORD_DEFAULT);
        echo "</pre>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Erreur: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<h2>Pour réinitialiser le mot de passe de l'admin :</h2>";
echo "<pre style='background: #f4f4f4; padding: 10px;'>";
echo "<?php\n";
echo "require_once 'includes/config.php';\n";
echo "\$hash = password_hash('password123', PASSWORD_DEFAULT);\n";
echo "\$stmt = \$pdo->prepare(\"UPDATE professeurs SET password_hash = ? WHERE role = 'admin' LIMIT 1\");\n";
echo "\$stmt->execute([\$hash]);\n";
echo "echo 'Mot de passe réinitialisé avec succès';\n";
echo "?>";
echo "</pre>";

echo "<p><a href='login.php'>Retour à la page de connexion</a></p>";
?>