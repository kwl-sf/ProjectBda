<?php
// admin/export_template.php
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="modele_import_utilisateurs.csv"');

$output = fopen('php://output', 'w');

// En-têtes
fputcsv($output, ['type', 'nom', 'prenom', 'email', 'role', 'departement', 'formation', 'promo']);

// Exemples
fputcsv($output, ['prof', 'Dupont', 'Jean', 'jean.dupont@univ.fr', 'prof', 'Informatique', '', '']);
fputcsv($output, ['prof', 'Martin', 'Sophie', 'sophie.martin@univ.fr', 'chef_dept', 'Mathématiques', '', '']);
fputcsv($output, ['etudiant', 'Durand', 'Pierre', 'pierre.durand@univ.fr', '', '', 'Licence Informatique', '2024']);
fputcsv($output, ['etudiant', 'Leroy', 'Marie', 'marie.leroy@univ.fr', '', '', 'Master Mathématiques', '2023']);

fclose($output);
?>