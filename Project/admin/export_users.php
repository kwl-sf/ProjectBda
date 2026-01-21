<?php
// admin/export_users.php
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role(['admin']);

$type = $_GET['type'] ?? 'professeurs';

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $type . '_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');

try {
    if ($type === 'professeurs') {
        fputcsv($output, ['ID', 'Nom', 'Prénom', 'Email', 'Rôle', 'Département', 'Statut', 'Date Création']);
        
        $stmt = $pdo->query("
            SELECT p.*, d.nom as departement_nom
            FROM professeurs p
            LEFT JOIN departements d ON p.dept_id = d.id
            ORDER BY p.nom
        ");
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['id'],
                $row['nom'],
                $row['prenom'],
                $row['email'],
                $row['role'],
                $row['departement_nom'] ?? '',
                $row['actif'] ? 'Actif' : 'Inactif',
                $row['created_at']
            ]);
        }
        
    } else {
        fputcsv($output, ['ID', 'Nom', 'Prénom', 'Email', 'Formation', 'Promotion', 'Département', 'Statut', 'Date Création']);
        
        $stmt = $pdo->query("
            SELECT e.*, f.nom as formation_nom, d.nom as departement_nom
            FROM etudiants e
            JOIN formations f ON e.formation_id = f.id
            JOIN departements d ON f.dept_id = d.id
            ORDER BY e.nom
        ");
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['id'],
                $row['nom'],
                $row['prenom'],
                $row['email'],
                $row['formation_nom'],
                $row['promo'],
                $row['departement_nom'],
                $row['actif'] ? 'Actif' : 'Inactif',
                $row['created_at']
            ]);
        }
    }
} catch (PDOException $e) {
    fputcsv($output, ['Erreur', $e->getMessage()]);
}

fclose($output);
?>