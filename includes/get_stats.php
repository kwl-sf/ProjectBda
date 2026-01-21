<?php
// includes/get_stats.php
require_once 'config.php';

header('Content-Type: application/json');


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Méthode non autorisée']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$formations_ids = $input['formations'] ?? [];
$semestre = $input['semestre'] ?? 'S1';

if (empty($formations_ids)) {
    echo json_encode([
        'students' => 0,
        'modules' => 0,
        'estimated_exams' => 0
    ]);
    exit;
}

$placeholders = str_repeat('?,', count($formations_ids) - 1) . '?';
$params = $formations_ids;

// Nombre d'étudiants
$query_students = "
    SELECT COUNT(DISTINCT e.id) as count
    FROM etudiants e
    WHERE e.formation_id IN ($placeholders)
    AND e.id IN (
        SELECT DISTINCT i.etudiant_id 
        FROM inscriptions i 
        WHERE i.semestre = ?
    )
";

$student_params = array_merge($formations_ids, [$semestre]);
$stmt = $pdo->prepare($query_students);
$stmt->execute($student_params);
$students = $stmt->fetch()['count'];

// Nombre de modules
$query_modules = "
    SELECT COUNT(DISTINCT m.id) as count
    FROM modules m
    WHERE m.formation_id IN ($placeholders)
    AND m.id IN (
        SELECT DISTINCT i.module_id 
        FROM inscriptions i 
        WHERE i.semestre = ?
    )
";

$module_params = array_merge($formations_ids, [$semestre]);
$stmt = $pdo->prepare($query_modules);
$stmt->execute($module_params);
$modules = $stmt->fetch()['count'];

// Estimation du nombre d'examens (avec groupes)
$estimated_exams = 0;
if ($modules > 0) {
    // Estimations basées sur les effectifs
    $query_effectifs = "
        SELECT m.id, COUNT(DISTINCT i.etudiant_id) as nb_etudiants
        FROM modules m
        JOIN inscriptions i ON m.id = i.module_id
        WHERE m.formation_id IN ($placeholders)
        AND i.semestre = ?
        GROUP BY m.id
    ";
    
    $stmt = $pdo->prepare($query_effectifs);
    $stmt->execute($module_params);
    $effectifs = $stmt->fetchAll();
    
    foreach ($effectifs as $module) {
        // Si plus de 100 étudiants, diviser en groupes
        if ($module['nb_etudiants'] > 100) {
            $groupes = ceil($module['nb_etudiants'] / 100);
            $estimated_exams += min($groupes, 3); // Max 3 groupes par module
        } else {
            $estimated_exams += 1;
        }
    }
} else {
    $estimated_exams = ceil($modules * 1.2); // Estimation par défaut
}

echo json_encode([
    'success' => true,
    'students' => (int)$students,
    'modules' => (int)$modules,
    'estimated_exams' => (int)$estimated_exams,
    'formations_count' => count($formations_ids),
    'semestre' => $semestre
]);
?>