<?php
// admin/generate_schedule.php - VERSION COMPLÈTE AVEC GROUPES ET SEMESTRES
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/algorithm.php';

// Vérifier que l'utilisateur est admin
require_role(['admin']);

// Récupérer l'utilisateur connecté
$user = get_logged_in_user();

// Variables
$message = '';
$message_type = '';
$generation_time = 0;
$conflicts_detected = 0;
$exams_generated = 0;
$groupes_crees = 0;
$selected_departments = [];
$selected_formations = [];
$start_date = '';
$end_date = '';
$session = 'normale';
$semestre = 'S1';
$generation_result = null;

// Récupérer tous les départements
$stmt = $pdo->query("SELECT * FROM departements ORDER BY nom");
$departements = $stmt->fetchAll();

// Récupérer toutes les formations avec leurs départements
$stmt = $pdo->query("
    SELECT f.*, d.nom as departement_nom 
    FROM formations f 
    JOIN departements d ON f.dept_id = d.id 
    ORDER BY d.nom, f.nom
");
$formations = $stmt->fetchAll();

// Group formations by department
$formations_by_department = [];
foreach ($formations as $formation) {
    $dept_id = $formation['dept_id'];
    if (!isset($formations_by_department[$dept_id])) {
        $formations_by_department[$dept_id] = [];
    }
    $formations_by_department[$dept_id][] = $formation;
}

// Récupérer les statistiques
$stats = [];
$stmt = $pdo->query("SELECT COUNT(*) as total FROM examens WHERE statut = 'confirme'");
$stats['confirmed_exams'] = $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT COUNT(*) as total FROM examens WHERE statut = 'planifie'");
$stats['planned_exams'] = $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT COUNT(*) as total FROM modules");
$stats['total_modules'] = $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT COUNT(*) as total FROM etudiants");
$stats['total_students'] = $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT COUNT(*) as total FROM conflits WHERE statut = 'detecte'");
$stats['active_conflicts'] = $stmt->fetch()['total'];

// Récupérer le nombre de salles par type
$stmt = $pdo->query("
    SELECT type, COUNT(*) as count, SUM(capacite) as total_capacity 
    FROM lieu_examen 
    WHERE disponible = 1 
    GROUP BY type
");
$salles_par_type = $stmt->fetchAll();

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Récupérer les paramètres
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $session = $_POST['session'] ?? 'normale';
    $semestre = $_POST['semestre'] ?? 'S1';
    $selected_departments = $_POST['departements'] ?? [];
    $selected_formations = $_POST['formations'] ?? [];
    
    if ($action === 'generate') {
        // Validation
        if (empty($start_date) || empty($end_date)) {
            $message = "Veuillez sélectionner une période valide";
            $message_type = 'error';
        } elseif (count($selected_formations) === 0) {
            $message = "Veuillez sélectionner au moins une formation";
            $message_type = 'error';
        } else {
            // Démarrer le chronomètre
            $start_time = microtime(true);
            
            // Paramètres pour l'algorithme
            $params = [
                'semestre' => $semestre,
                'formations' => $selected_formations,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'session' => $session
            ];
            
            // Exécuter l'algorithme amélioré
            $result = genererEmploiDuTemps($params, $pdo);
            
            // Arrêter le chronomètre
            $end_time = microtime(true);
            $generation_time = round($end_time - $start_time, 2);
            $generation_result = $result;
            
            if ($result['success']) {
                $message = "Emploi du temps généré avec succès en {$generation_time} secondes !";
                $message_type = 'success';
                $conflicts_detected = $result['conflicts'] ?? 0;
                $exams_generated = $result['exams_generated'];
                $groupes_crees = $result['groupes_crees'] ?? 0;
                
                // Journaliser
                $stmt = $pdo->prepare("INSERT INTO logs_activite (utilisateur_id, utilisateur_type, action, details) VALUES (?, ?, ?, ?)");
                $details = "Session: {$session}, Semestre: {$semestre}, Période: {$start_date} à {$end_date}";
                $details .= ", Formations: " . count($selected_formations);
                $details .= ", Examens: {$exams_generated}, Groupes: {$groupes_crees}";
                $stmt->execute([$user['id'], 'admin', 'Génération EDT avec groupes', $details]);
            } else {
                $message = "Erreur lors de la génération : " . $result['error'];
                $message_type = 'error';
            }
        }
    } elseif ($action === 'clear') {
        $stmt = $pdo->prepare("DELETE FROM examens WHERE statut = 'planifie'");
        $stmt->execute();
        $message = "Tous les examens non confirmés ont été supprimés.";
        $message_type = 'warning';
    } elseif ($action === 'detect_conflicts') {
        // Détecter les conflits
        $result = detecterConflits();
        $message = "Détection terminée : {$result['count']} conflits détectés";
        $message_type = $result['count'] > 0 ? 'warning' : 'success';
    }
}

// Récupérer les formations المختارة مع وحداتها
$formations_avec_modules = [];

if (count($selected_formations) > 0) {
    $placeholders = str_repeat('?,', count($selected_formations) - 1) . '?';
    
    // Récupérer كل التكوينات المختارة
    $stmt = $pdo->prepare("
        SELECT 
            f.id,
            f.nom as formation_nom,
            d.nom as departement_nom,
            COUNT(DISTINCT m.id) as nb_modules,
            COUNT(DISTINCT e.id) as nb_examens_planifies
        FROM formations f
        JOIN departements d ON f.dept_id = d.id
        LEFT JOIN modules m ON f.id = m.formation_id
        LEFT JOIN examens e ON m.id = e.module_id AND e.statut = 'planifie'
        WHERE f.id IN ($placeholders)
        GROUP BY f.id, f.nom, d.nom
        ORDER BY d.nom, f.nom
    ");
    $stmt->execute($selected_formations);
    $formations_base = $stmt->fetchAll();
    
    foreach ($formations_base as $formation) {
        $formation_id = $formation['id'];
        
        // 🔹 الاستعلام الأول: جلب المادة + التاريخ (مرة واحدة)
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                m.id AS module_id,
                m.nom AS module_nom,
                m.semestre,
                e.date_heure,
                e.duree_minutes
            FROM modules m
            JOIN examens e ON m.id = e.module_id
            WHERE m.formation_id = ?
              AND m.semestre = ?
              AND e.statut = 'planifie'
            ORDER BY e.date_heure, m.nom
        ");
        $stmt->execute([$formation_id, $semestre]);
        $modules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // إحصائيات
        $modules_avec_exam = count($modules);
        $modules_sans_exam = 0;
        
        // جلب عدد وحدات التكوين
        $stmtModules = $pdo->prepare("SELECT COUNT(*) as count FROM modules WHERE formation_id = ? AND semestre = ?");
        $stmtModules->execute([$formation_id, $semestre]);
        $total_modules = $stmtModules->fetch()['count'];
        $modules_sans_exam = $total_modules - $modules_avec_exam;
        
        $formations_avec_modules[] = [
            'formation_id' => $formation_id,
            'formation_nom' => $formation['formation_nom'],
            'departement_nom' => $formation['departement_nom'],
            'nb_modules' => $total_modules,
            'nb_examens_planifies' => $formation['nb_examens_planifies'],
            'modules_avec_exam' => $modules_avec_exam,
            'modules_sans_exam' => $modules_sans_exam,
            'modules' => $modules
        ];
    }
}

// Récupérer l'historique
$stmt = $pdo->query("SELECT * FROM logs_activite WHERE action LIKE '%Génération%' ORDER BY created_at DESC LIMIT 5");
$generation_history = $stmt->fetchAll();

// Récupérer les salles disponibles
$stmt = $pdo->query("SELECT * FROM lieu_examen WHERE disponible = 1 ORDER BY type, capacite");
$salles_disponibles = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Générer EDT | <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        .generation-container { max-width: 1600px; margin: 0 auto; }
        
        /* Sections principales */
        .section-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            margin-bottom: 1.5rem;
            animation: fadeIn 0.5s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--gray-200);
        }
        
        .section-title i {
            color: var(--primary);
            font-size: 1.75rem;
        }
        
        /* Sélection semestre */
        .semestre-selection {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .semestre-card {
            padding: 1.5rem;
            border: 3px solid var(--gray-200);
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
        }
        
        .semestre-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .semestre-card.selected {
            border-color: var(--primary);
            background: rgba(67, 97, 238, 0.05);
        }
        
        .semestre-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }
        
        .semestre-card[data-semestre="S1"] .semestre-icon { color: #3498db; }
        .semestre-card[data-semestre="S2"] .semestre-icon { color: #e74c3c; }
        
        .semestre-name {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .semestre-period {
            font-size: 0.9rem;
            color: var(--gray-600);
        }
        
        /* Sélection dates */
        .date-selection-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }
        
        .date-input-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .date-input-group label {
            font-weight: 600;
            color: var(--gray-700);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .date-input {
            padding: 0.85rem 1rem;
            border: 2px solid var(--gray-300);
            border-radius: var(--border-radius-sm);
            font-size: 1rem;
            transition: var(--transition);
        }
        
        .date-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        
        /* Sélection formations */
        .formations-accordion {
            margin-top: 1rem;
        }
        
        .department-card {
            border: 1px solid var(--gray-200);
            border-radius: var(--border-radius-sm);
            margin-bottom: 1rem;
            overflow: hidden;
        }
        
        .department-header {
            padding: 1rem 1.5rem;
            background: var(--gray-100);
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
            color: var(--gray-800);
            transition: var(--transition);
        }
        
        .department-header:hover {
            background: var(--gray-200);
        }
        
        .department-header i {
            transition: transform 0.3s ease;
        }
        
        .department-header.expanded i {
            transform: rotate(180deg);
        }
        
        .department-content {
            padding: 1.5rem;
            background: white;
            display: none;
        }
        
        .department-content.expanded {
            display: block;
            animation: slideDown 0.3s ease-out;
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .formations-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1rem;
        }
        
        .formation-select-card {
            padding: 1rem;
            border: 2px solid var(--gray-200);
            border-radius: var(--border-radius-sm);
            cursor: pointer;
            transition: var(--transition);
            position: relative;
        }
        
        .formation-select-card:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
        }
        
        .formation-select-card.selected {
            background: rgba(67, 97, 238, 0.05);
            border-color: var(--primary);
            box-shadow: 0 4px 6px rgba(67, 97, 238, 0.1);
        }
        
        .formation-name {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--gray-900);
        }
        
        .formation-details {
            font-size: 0.85rem;
            color: var(--gray-600);
            line-height: 1.4;
        }
        
        .formation-details i {
            width: 16px;
            text-align: center;
            margin-right: 0.25rem;
        }
        
        .formation-checkbox {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 20px;
            height: 20px;
            border-radius: 4px;
            border: 2px solid var(--gray-300);
            background: white;
            transition: var(--transition);
        }
        
        .formation-select-card.selected .formation-checkbox {
            background: var(--primary);
            border-color: var(--primary);
        }
        
        .formation-select-card.selected .formation-checkbox::after {
            content: '✓';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-weight: bold;
        }
        
        /* Statistiques de sélection */
        .selection-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
            padding: 1.5rem;
            background: var(--gray-50);
            border-radius: var(--border-radius);
        }
        
        .stat-item {
            text-align: center;
            padding: 1rem;
            background: white;
            border-radius: var(--border-radius-sm);
            box-shadow: var(--shadow-sm);
        }
        
        .stat-number {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--primary);
            display: block;
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            font-size: 0.85rem;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Boutons d'action */
        .action-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin: 2rem 0;
        }
        
        .action-button {
            padding: 1.25rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            text-decoration: none;
            color: white;
        }
        
        .action-button:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .action-button i {
            font-size: 2rem;
        }
        
        .action-generate {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            grid-column: span 2;
        }
        
        .action-preview {
            background: linear-gradient(135deg, var(--info), #4cc9f0);
        }
        
        .action-clear {
            background: linear-gradient(135deg, var(--danger), #f72585);
        }
        
        .action-detect {
            background: linear-gradient(135deg, var(--warning), #f8961e);
        }
        
        /* Résultats de génération */
        .generation-results {
            margin-top: 2rem;
        }
        
        .results-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .result-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            text-align: center;
            position: relative;
            overflow: hidden;
            transition: var(--transition);
        }
        
        .result-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .result-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
        }
        
        .result-card.exams::before { background: var(--primary); }
        .result-card.groups::before { background: var(--success); }
        .result-card.conflicts::before { background: var(--warning); }
        .result-card.time::before { background: var(--info); }
        
        .result-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }
        
        .result-card.exams .result-icon { color: var(--primary); }
        .result-card.groups .result-icon { color: var(--success); }
        .result-card.conflicts .result-icon { color: var(--warning); }
        .result-card.time .result-icon { color: var(--info); }
        
        .result-value {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
        }
        
        .result-label {
            font-size: 0.9rem;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Tableaux des formations */
        .formation-tables {
            margin-top: 2rem;
        }
        
        .formation-table-container {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-md);
            margin-bottom: 2rem;
            overflow: hidden;
        }
        
        .formation-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 1.5rem;
        }
        
        .formation-header h3 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .formation-subtitle {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }
        
        .subtitle-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        .modules-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .modules-table th {
            padding: 1rem;
            text-align: left;
            background: var(--gray-100);
            font-weight: 600;
            color: var(--gray-700);
            border-bottom: 2px solid var(--gray-300);
        }
        
        .modules-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--gray-200);
            vertical-align: top;
        }
        
        .modules-table tr:hover {
            background: var(--gray-50);
        }
        
        .examen-info {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        
        .module-header {
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--gray-200);
        }
        
        .module-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
            line-height: 1.4;
        }
        
        .module-subtitle {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
            color: var(--gray-600);
            font-size: 0.95rem;
            margin-bottom: 0.25rem;
        }
        
        .module-detail-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .salles-par-type {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .type-groupe {
            background: white;
            border-radius: var(--border-radius-sm);
            border: 1px solid var(--gray-200);
            overflow: hidden;
        }
        
        .type-header {
            padding: 0.75rem 1rem;
            background: var(--gray-100);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 600;
            color: var(--gray-800);
            border-bottom: 1px solid var(--gray-200);
        }
        
        .type-icon {
            font-size: 1.25rem;
        }
        
        .type-name {
            flex-grow: 1;
        }
        
        .type-content {
            padding: 0.75rem;
        }
        
        .salle-item {
            background: rgba(67, 97, 238, 0.05);
            border-left: 4px solid var(--primary);
            padding: 0.75rem;
            border-radius: var(--border-radius-sm);
            margin-bottom: 0.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.2s ease;
        }
        
        .salle-item:hover {
            background: rgba(67, 97, 238, 0.1);
            transform: translateX(5px);
        }
        
        .salle-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .salle-icon {
            font-size: 1.25rem;
            width: 30px;
            text-align: center;
        }
        
        .salle-name {
            font-weight: 600;
            color: var(--gray-900);
        }
        
        .salle-type-badge {
            font-size: 0.85rem;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-weight: 600;
        }
        
        .salle-type-badge.amphi {
            background: rgba(155, 89, 182, 0.1);
            color: #9b59b6;
        }
        
        .salle-type-badge.salle {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success);
        }
        
        .salle-type-badge.labo {
            background: rgba(52, 152, 219, 0.1);
            color: var(--info);
        }
        
        .groupes-info {
            background: rgba(46, 204, 113, 0.1);
            border-radius: 4px;
            padding: 0.5rem 0.75rem;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--success);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .no-examen {
            text-align: center;
            padding: 1.5rem;
            color: var(--gray-500);
            font-style: italic;
        }
        
        /* Informations salles */
        .salles-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
        }
        
        .salle-card {
            background: white;
            padding: 1rem;
            border-radius: var(--border-radius-sm);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
        }
        
        .salle-type-indicator {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .salle-type-indicator.amphi {
            background: rgba(155, 89, 182, 0.1);
            color: #9b59b6;
        }
        
        .salle-type-indicator.salle {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success);
        }
        
        .salle-type-indicator.labo {
            background: rgba(52, 152, 219, 0.1);
            color: var(--info);
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .date-selection-grid {
                grid-template-columns: 1fr;
            }
            
            .action-button {
                grid-column: span 1;
            }
            
            .action-generate {
                grid-column: span 1;
            }
        }
        
        @media (max-width: 768px) {
            .formations-grid {
                grid-template-columns: 1fr;
            }
            
            .results-grid {
                grid-template-columns: 1fr;
            }
            
            .selection-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .modules-table {
                font-size: 0.85rem;
            }
            
            .modules-table th,
            .modules-table td {
                padding: 0.75rem 0.5rem;
            }
            
            .module-subtitle {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .salle-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
            }
            
            .groupes-info {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-graduation-cap"></i> PlanExam Pro</h2>
                <p>Génération des EDT</p>
            </div>
            
            <div class="user-info">
                <div class="user-avatar"><i class="fas fa-user-shield"></i></div>
                <div class="user-name"><?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($user['role_fr']); ?></div>
            </div>
            
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-item"><span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span><span>Tableau de Bord</span></a>
                <a href="generate_schedule.php" class="nav-item active"><span class="nav-icon"><i class="fas fa-calendar-plus"></i></span><span>Générer EDT</span></a>
                <a href="manage_rooms.php" class="nav-item"><span class="nav-icon"><i class="fas fa-building"></i></span><span>Gérer les Salles</span></a>
                <a href="conflicts.php" class="nav-item"><span class="nav-icon"><i class="fas fa-exclamation-triangle"></i></span><span>Conflits</span></a>
                <a href="GererUser.php" class="nav-item"><span class="nav-icon"><i class="fas fa-building"></i></span><span>Gérer les Utilisateurs</span></a>
                <a href="Statistique.php" class="nav-item"><span class="nav-icon"><i class="fas fa-building"></i></span><span>Les Statistique </span></a>
                <a href="" class="nav-item"><span class="nav-icon"><i class="fas fa-building"></i></span><span>Les Parametre </span></a>
                <a href="../logout.php" class="nav-item"><span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span><span>Déconnexion</span></a>
            </nav>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="generation-container">
                <!-- En-tête -->
                <header class="header">
                    <div class="header-left">
                        <h1><i class="fas fa-brain"></i> Génération Intelligente des EDT</h1>
                        <p>Créez des emplois du temps optimisés avec gestion des groupes et des semestres</p>
                    </div>
                    <div class="header-actions">
                        <button class="menu-toggle" id="menuToggle">
                            <i class="fas fa-bars"></i>
                        </button>
                    </div>
                </header>
                
                <!-- Messages -->
                <?php if ($message): ?>
                    <div class="flash-message flash-<?php echo $message_type; ?> animate__animated animate__fadeIn">
                        <span class="flash-icon">
                            <?php 
                            $icons = [
                                'success' => '✅',
                                'error' => '❌',
                                'warning' => '⚠️',
                                'info' => 'ℹ️'
                            ];
                            echo $icons[$message_type] ?? 'ℹ️';
                            ?>
                        </span>
                        <span><?php echo htmlspecialchars($message); ?></span>
                    </div>
                <?php endif; ?>
                
                <!-- Formulaire principal -->
                <form method="POST" action="" id="generationForm">
                    <input type="hidden" name="action" id="actionInput" value="generate">
                    <input type="hidden" name="semestre" id="semestreInput" value="<?php echo $semestre; ?>">
                    
                    <!-- Section 1: Sélection du semestre -->
                    <div class="section-card">
                        <h2 class="section-title">
                            <i class="fas fa-calendar-alt"></i>
                            1. Sélection du Semestre
                        </h2>
                        
                        <div class="semestre-selection">
                            <div class="semestre-card <?php echo $semestre === 'S1' ? 'selected' : ''; ?>" 
                                 data-semestre="S1"
                                 onclick="selectSemestre('S1')">
                                <div class="semestre-icon">
                                    <i class="fas fa-sun"></i>
                                </div>
                                <div class="semestre-name">Semestre 1 (S1)</div>
                                <div class="semestre-period">Septembre - Janvier</div>
                            </div>
                            
                            <div class="semestre-card <?php echo $semestre === 'S2' ? 'selected' : ''; ?>" 
                                 data-semestre="S2"
                                 onclick="selectSemestre('S2')">
                                <div class="semestre-icon">
                                    <i class="fas fa-snowflake"></i>
                                </div>
                                <div class="semestre-name">Semestre 2 (S2)</div>
                                <div class="semestre-period">Février - Juin</div>
                            </div>
                        </div>
                        
                        <div class="semestre-details" style="margin-top: 1.5rem; padding: 1rem; background: var(--gray-50); border-radius: var(--border-radius-sm);">
                            <h4 style="margin-bottom: 0.5rem; color: var(--gray-700);">
                                <i class="fas fa-info-circle"></i> Informations
                            </h4>
                            <div id="semestreInfoS1" style="display: <?php echo $semestre === 'S1' ? 'block' : 'none'; ?>;">
                                <p><strong>Semestre 1 (S1):</strong> Examens des modules enseignés de septembre à janvier.</p>
                                <p>Session normale : Janvier | Session rattrapage : Juin</p>
                            </div>
                            <div id="semestreInfoS2" style="display: <?php echo $semestre === 'S2' ? 'block' : 'none'; ?>;">
                                <p><strong>Semestre 2 (S2):</strong> Examens des modules enseignés de février à juin.</p>
                                <p>Session normale : Mai-Juin | Session rattrapage : Septembre</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Section 2: Période et session -->
                    <div class="section-card">
                        <h2 class="section-title">
                            <i class="fas fa-clock"></i>
                            2. Période et Session
                        </h2>
                        
                        <div class="date-selection-grid">
                            <div class="date-input-group">
                                <label for="start_date">
                                    <i class="fas fa-play-circle"></i>
                                    Date de Début
                                </label>
                                <input type="text" 
                                       id="start_date" 
                                       name="start_date" 
                                       class="date-input" 
                                       value="<?php echo htmlspecialchars($start_date ?: date('Y-m-d', strtotime('+1 day'))); ?>" 
                                       required
                                       placeholder="Sélectionnez une date">
                            </div>
                            
                            <div class="date-input-group">
                                <label for="end_date">
                                    <i class="fas fa-stop-circle"></i>
                                    Date de Fin
                                </label>
                                <input type="text" 
                                       id="end_date" 
                                       name="end_date" 
                                       class="date-input" 
                                       value="<?php echo htmlspecialchars($end_date ?: date('Y-m-d', strtotime('+21 days'))); ?>" 
                                       required
                                       placeholder="Sélectionnez une date">
                            </div>
                        </div>
                        
                        <div style="margin-top: 1.5rem;">
                            <label for="session" style="font-weight: 600; color: var(--gray-700); margin-bottom: 0.5rem; display: block;">
                                <i class="fas fa-graduation-cap"></i>
                                Type de Session
                            </label>
                            <select id="session" name="session" class="date-input" style="width: 100%;">
                                <option value="normale" <?php echo $session === 'normale' ? 'selected' : ''; ?>>Session Normale</option>
                                <option value="rattrapage" <?php echo $session === 'rattrapage' ? 'selected' : ''; ?>>Session de Rattrapage</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Section 3: Sélection des formations -->
                    <div class="section-card">
                        <h2 class="section-title">
                            <i class="fas fa-university"></i>
                            3. Sélection des Formations
                        </h2>
                        
                        <div style="display: flex; gap: 1rem; margin-bottom: 1rem; flex-wrap: wrap;">
                            <button type="button" class="btn btn-primary" onclick="selectAllFormations()">
                                <i class="fas fa-check-double"></i> Tout Sélectionner
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="deselectAllFormations()">
                                <i class="fas fa-times"></i> Tout Désélectionner
                            </button>
                            <button type="button" class="btn btn-info" onclick="toggleAllDepartments()">
                                <i class="fas fa-folder"></i> Tout Développer/Réduire
                            </button>
                        </div>
                        
                        <div class="formations-accordion">
                            <?php foreach ($departements as $dept): ?>
                                <?php if (isset($formations_by_department[$dept['id']])): ?>
                                    <div class="department-card">
                                        <div class="department-header" onclick="toggleDepartment(<?php echo $dept['id']; ?>)">
                                            <span>
                                                <i class="fas fa-building"></i>
                                                <?php echo htmlspecialchars($dept['nom']); ?>
                                                <small style="margin-left: 0.5rem; color: var(--gray-600);">
                                                    (<?php echo count($formations_by_department[$dept['id']]); ?> formations)
                                                </small>
                                            </span>
                                            <i class="fas fa-chevron-down"></i>
                                        </div>
                                        
                                        <div class="department-content" id="dept-content-<?php echo $dept['id']; ?>">
                                            <div class="formations-grid">
                                                <?php foreach ($formations_by_department[$dept['id']] as $formation): ?>
                                                    <div class="formation-select-card <?php echo in_array($formation['id'], $selected_formations) ? 'selected' : ''; ?>"
                                                         onclick="toggleFormation(<?php echo $formation['id']; ?>)"
                                                         data-formation-id="<?php echo $formation['id']; ?>">
                                                        <div class="formation-checkbox"></div>
                                                        <div class="formation-name">
                                                            <?php echo htmlspecialchars($formation['nom']); ?>
                                                        </div>
                                                        <div class="formation-details">
                                                            <div>
                                                                <i class="fas fa-user-graduate"></i>
                                                                <?php 
                                                                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM etudiants WHERE formation_id = ?");
                                                                $stmt->execute([$formation['id']]);
                                                                $count = $stmt->fetch()['count'];
                                                                echo $count . ' étudiants';
                                                                ?>
                                                            </div>
                                                            <div>
                                                                <i class="fas fa-book"></i>
                                                                <?php 
                                                                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM modules WHERE formation_id = ?");
                                                                $stmt->execute([$formation['id']]);
                                                                $modules_count = $stmt->fetch()['count'];
                                                                echo $modules_count . ' modules';
                                                                ?>
                                                            </div>
                                                        </div>
                                                        <input type="checkbox" 
                                                               name="formations[]" 
                                                               value="<?php echo $formation['id']; ?>"
                                                               style="display: none;"
                                                               <?php echo in_array($formation['id'], $selected_formations) ? 'checked' : ''; ?>>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Statistiques de sélection -->
                        <div class="selection-stats">
                            <div class="stat-item">
                                <span class="stat-number" id="formationsSelected">0</span>
                                <span class="stat-label">Formations</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-number" id="studentsSelected">0</span>
                                <span class="stat-label">Étudiants</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-number" id="modulesSelected">0</span>
                                <span class="stat-label">Modules</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-number" id="estimatedExams">0</span>
                                <span class="stat-label">Examens estimés</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Section 4: Informations sur les salles disponibles -->
                    <div class="section-card">
                        <h2 class="section-title">
                            <i class="fas fa-door-open"></i>
                            4. Salles Disponibles
                        </h2>
                        
                        <div class="salles-info">
                            <?php foreach ($salles_par_type as $type): ?>
                                <div class="salle-card">
                                    <span class="salle-type-indicator <?php echo $type['type']; ?>">
                                        <?php echo ucfirst($type['type']); ?>
                                    </span>
                                    <div style="font-size: 1.5rem; font-weight: 700; color: var(--gray-900);">
                                        <?php echo $type['count']; ?>
                                    </div>
                                    <div style="font-size: 0.9rem; color: var(--gray-600);">
                                        Capacité totale : <?php echo number_format($type['total_capacity']); ?> places
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div style="margin-top: 1rem; padding: 1rem; background: var(--gray-50); border-radius: var(--border-radius-sm);">
                            <p style="color: var(--gray-700); margin-bottom: 0.5rem;">
                                <i class="fas fa-lightbulb"></i>
                                <strong>Information :</strong> Le système divise automatiquement les groupes de plus de 100 étudiants.
                            </p>
                            <ul style="margin: 0; padding-left: 1.5rem; color: var(--gray-600);">
                                <li>Amphithéâtres : Groupes de plus de 100 étudiants</li>
                                <li>Salles : Groupes de 30 à 100 étudiants</li>
                                <li>Laboratoires : Examens pratiques</li>
                            </ul>
                        </div>
                    </div>
                    
                    <!-- Section 5: Boutons d'action -->
                    <div class="action-buttons">
                        <button type="button" class="action-button action-generate" onclick="startGeneration()">
                            <i class="fas fa-play"></i>
                            <span>Lancer la Génération</span>
                            <small>Algorithme intelligent avec groupes</small>
                        </button>
                        
                        <button type="button" class="action-button action-preview" onclick="previewGeneration()">
                            <i class="fas fa-eye"></i>
                            <span>Aperçu</span>
                            <small>Visualiser avant génération</small>
                        </button>
                        
                        <button type="button" class="action-button action-clear" onclick="clearGeneration()">
                            <i class="fas fa-trash"></i>
                            <span>Effacer</span>
                            <small>Supprimer les planifications</small>
                        </button>
                        
                        <button type="button" class="action-button action-detect" onclick="detectConflicts()">
                            <i class="fas fa-search"></i>
                            <span>Détecter</span>
                            <small>Rechercher les conflits</small>
                        </button>
                    </div>
                </form>
                
                <!-- Section 6: Résultats de la génération -->
                <?php if ($generation_result): ?>
                    <div class="section-card generation-results">
                        <h2 class="section-title">
                            <i class="fas fa-chart-bar"></i>
                            Résultats de la Génération
                        </h2>
                        
                        <div class="results-grid">
                            <div class="result-card exams">
                                <div class="result-icon">
                                    <i class="fas fa-file-alt"></i>
                                </div>
                                <div class="result-value"><?php echo $exams_generated; ?></div>
                                <div class="result-label">Examens Générés</div>
                            </div>
                            
                            <div class="result-card groups">
                                <div class="result-icon">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div class="result-value"><?php echo $groupes_crees; ?></div>
                                <div class="result-label">Groupes Créés</div>
                            </div>
                            
                            <div class="result-card conflicts">
                                <div class="result-icon">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </div>
                                <div class="result-value"><?php echo $conflicts_detected; ?></div>
                                <div class="result-label">Conflits Détectés</div>
                            </div>
                            
                            <div class="result-card time">
                                <div class="result-icon">
                                    <i class="fas fa-stopwatch"></i>
                                </div>
                                <div class="result-value"><?php echo $generation_time; ?>s</div>
                                <div class="result-label">Temps d'Exécution</div>
                            </div>
                        </div>
                        
                        <div style="margin-top: 2rem; padding: 1.5rem; background: linear-gradient(135deg, rgba(67, 97, 238, 0.1), rgba(114, 9, 183, 0.1)); border-radius: var(--border-radius);">
                            <h3 style="margin-bottom: 1rem; color: var(--gray-800);">
                                <i class="fas fa-check-circle" style="color: var(--success);"></i>
                                Génération réussie !
                            </h3>
                            <p style="color: var(--gray-700); margin-bottom: 0.5rem;">
                                L'emploi du temps a été généré avec succès pour le semestre <strong><?php echo $semestre; ?></strong>.
                            </p>
                            <ul style="color: var(--gray-600); margin: 0; padding-left: 1.5rem;">
                                <li>Session : <?php echo $session === 'normale' ? 'Session Normale' : 'Session de Rattrapage'; ?></li>
                                <li>Période : <?php echo date('d/m/Y', strtotime($start_date)); ?> au <?php echo date('d/m/Y', strtotime($end_date)); ?></li>
                                <li>Formations sélectionnées : <?php echo count($selected_formations); ?></li>
                                <?php if ($groupes_crees > 0): ?>
                                    <li>Division en groupes : <?php echo $groupes_crees; ?> groupes créés automatiquement</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>



                <div class="section-card">
    <h2 class="section-title">
        <i class="fas fa-paper-plane"></i>
        Envoi aux Chefs de Département
    </h2>
    
    <?php if ($exams_generated > 0 && !empty($formations_avec_modules)): ?>
        <div style="background: linear-gradient(135deg, rgba(67, 97, 238, 0.1), rgba(114, 9, 183, 0.1)); 
                    border-radius: var(--border-radius); 
                    padding: 2rem; 
                    margin-bottom: 1.5rem;">
            <h3 style="color: var(--gray-800); margin-bottom: 1rem;">
                <i class="fas fa-share-alt"></i>
                Prêt à envoyer aux départements ?
            </h3>
            
            <div class="departments-to-send">
                <h4 style="color: var(--gray-700); margin-bottom: 1rem;">Départements concernés :</h4>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 1rem;">
                    <?php 
                    // تجميع الأقسام المشتركة
                    $dept_ids = [];
                    foreach ($formations_avec_modules as $formation) {
                        $stmt = $pdo->prepare("SELECT dept_id FROM formations WHERE id = ?");
                        $stmt->execute([$formation['formation_id']]);
                        $dept_id = $stmt->fetch()['dept_id'];
                        if (!in_array($dept_id, $dept_ids)) {
                            $dept_ids[] = $dept_id;
                            
                            // الحصول على معلومات القسم
                            $stmt_dept = $pdo->prepare("SELECT d.*, p.nom as chef_nom, p.prenom as chef_prenom 
                                                        FROM departements d 
                                                        LEFT JOIN professeurs p ON d.id = p.dept_id AND p.role = 'chef_dept'
                                                        WHERE d.id = ?");
                            $stmt_dept->execute([$dept_id]);
                            $dept_info = $stmt_dept->fetch();
                            ?>
                            <div class="dept-card" style="background: white; padding: 1rem; border-radius: var(--border-radius-sm); box-shadow: var(--shadow-sm);">
                                <div style="font-weight: 600; color: var(--gray-900); margin-bottom: 0.5rem;">
                                    <?php echo htmlspecialchars($dept_info['nom'] ?? 'Département'); ?>
                                </div>
                                <div style="font-size: 0.9rem; color: var(--gray-600);">
                                    <?php if ($dept_info['chef_nom']): ?>
                                        <i class="fas fa-user-tie"></i> Chef : <?php echo htmlspecialchars($dept_info['chef_prenom'] . ' ' . $dept_info['chef_nom']); ?>
                                    <?php else: ?>
                                        <i class="fas fa-user-slash"></i> Aucun chef désigné
                                    <?php endif; ?>
                                </div>
                                <div style="margin-top: 0.5rem; font-size: 0.85rem; color: var(--primary);">
                                    <i class="fas fa-file-alt"></i> 
                                    <?php 
                                    $count = 0;
                                    foreach ($formations_avec_modules as $f) {
                                        $stmt_check = $pdo->prepare("SELECT dept_id FROM formations WHERE id = ?");
                                        $stmt_check->execute([$f['formation_id']]);
                                        if ($stmt_check->fetch()['dept_id'] == $dept_id) {
                                            $count += $f['nb_examens_planifies'];
                                        }
                                    }
                                    echo $count . ' examens à valider';
                                    ?>
                                </div>
                            </div>
                            <?php
                        }
                    }
                    ?>
                </div>
            </div>
            
            <form method="POST" action="send_to_chefs.php" id="sendForm" style="margin-top: 2rem;">
                <input type="hidden" name="semestre" value="<?php echo $semestre; ?>">
                <input type="hidden" name="session" value="<?php echo $session; ?>">
                <input type="hidden" name="period" value="<?php echo $start_date . '_' . $end_date; ?>">
                
                <div style="margin-bottom: 1.5rem;">
                    <label style="font-weight: 600; color: var(--gray-700); margin-bottom: 0.5rem; display: block;">
                        <i class="fas fa-comment"></i>
                        Message d'accompagnement (optionnel)
                    </label>
                    <textarea name="message" rows="3" 
                              style="width: 100%; padding: 1rem; border: 2px solid var(--gray-300); border-radius: var(--border-radius-sm); font-size: 1rem;"
                              placeholder="Ajoutez un message personnalisé pour les chefs de département..."></textarea>
                </div>
                
                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="previewSend()">
                        <i class="fas fa-eye"></i> Aperçu
                    </button>
                    <button type="submit" class="btn btn-success" style="background: linear-gradient(135deg, #2ecc71, #27ae60);">
                        <i class="fas fa-paper-plane"></i> Envoyer aux Chefs de Département
                    </button>
                </div>
            </form>
        </div>
    <?php else: ?>
        <div style="text-align: center; padding: 3rem; color: var(--gray-500);">
            <i class="fas fa-calendar-times" style="font-size: 3rem; margin-bottom: 1rem;"></i>
            <h3 style="margin-bottom: 0.5rem;">Aucun emploi du temps à envoyer</h3>
            <p>Générez d'abord un emploi du temps pour pouvoir l'envoyer aux chefs de département.</p>
        </div>
    <?php endif; ?>
</div>



                
                <!-- Section 7: Tableaux des formations -->
                <?php if (!empty($formations_avec_modules)): ?>
                    <div class="section-card formation-tables">
                        <h2 class="section-title">
                            <i class="fas fa-table"></i>
                            Emplois du Temps par Formation
                        </h2>
                        
                        <?php foreach ($formations_avec_modules as $formation): ?>
                            <div class="formation-table-container" style="margin-bottom: 2rem;">
                                <div class="formation-header">
                                    <h3>
                                        <i class="fas fa-university"></i>
                                        <?php echo htmlspecialchars($formation['formation_nom']); ?>
                                    </h3>
                                    <div class="formation-subtitle">
                                        <div class="subtitle-item">
                                            <i class="fas fa-building"></i>
                                            <?php echo htmlspecialchars($formation['departement_nom']); ?>
                                        </div>
                                        <div class="subtitle-item">
                                            <i class="fas fa-book"></i>
                                            <?php echo $formation['nb_modules']; ?> modules
                                        </div>
                                        <div class="subtitle-item">
                                            <i class="fas fa-calendar-check"></i>
                                            <?php echo $formation['nb_examens_planifies']; ?> examens planifiés
                                        </div>
                                    </div>
                                </div>
                                
                                <table class="modules-table">
                                    <thead>
                                        <tr>
                                            <th width="40%">Matière</th>
                                            <th width="60%">Examens Planifiés</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($formation['modules'])): ?>
                                            <?php foreach ($formation['modules'] as $module): ?>
                                                <tr>
                                                    <td>
                                                        <div class="module-info">
                                                            <h4><?php echo htmlspecialchars($module['module_nom']); ?></h4>
                                                            <div class="module-details">
                                                                <i class="fas fa-hashtag"></i> <?php echo $module['semestre'] ?? 'S1'; ?>
                                                                <span style="margin: 0 0.5rem;">•</span>
                                                                <i class="fas fa-clock"></i> 
                                                                <?php 
                                                                $duree_heures = floor($module['duree_minutes'] / 60);
                                                                $duree_minutes = $module['duree_minutes'] % 60;
                                                                echo $duree_heures . 'h' . ($duree_minutes < 10 ? '0' : '') . $duree_minutes;
                                                                ?>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="examen-info">
                                                            <!-- 🔹 عرض المادة + التاريخ مرة واحدة -->
                                                            <div class="module-header">
                                                                <div class="module-title">
                                                                    <?php echo htmlspecialchars($module['module_nom']); ?> – <?php echo $module['semestre'] ?? 'S1'; ?> – 
                                                                    <?php
                                                                        $date_heure = new DateTime($module['date_heure']);
                                                                        echo format_date_fr($date_heure->format('Y-m-d'), false);
                                                                        echo ' à ' . $date_heure->format('H:i');
                                                                    ?>
                                                                </div>
                                                                <div class="module-subtitle">
                                                                    <span class="module-detail-item">
                                                                        <i class="fas fa-clock"></i>
                                                                        <?php 
                                                                        $duree_heures = floor($module['duree_minutes'] / 60);
                                                                        $duree_minutes = $module['duree_minutes'] % 60;
                                                                        echo $duree_heures . 'h' . ($duree_minutes < 10 ? '0' : '') . $duree_minutes;
                                                                        ?>
                                                                    </span>
                                                                </div>
                                                            </div>
                                                            
                                                            <!-- 🔹 جلب تفاصيل القاعات + الأفواج -->
                                                            <?php
                                                            // الاستعلام الثاني: جلب تفاصيل القاعات
                                                            $stmtDetails = $pdo->prepare("
                                                                SELECT
                                                                    l.nom AS salle_nom,
                                                                    l.type AS salle_type,
                                                                    GROUP_CONCAT(
                                                                        DISTINCT et.num_groupe
                                                                        ORDER BY et.num_groupe
                                                                        SEPARATOR ', '
                                                                    ) AS groupes
                                                                FROM examens e
                                                                JOIN lieu_examen l ON e.salle_id = l.id
                                                                JOIN examens_etudiants ee ON e.id = ee.examen_id
                                                                JOIN etudiants et ON ee.etudiant_id = et.id
                                                                WHERE e.module_id = ?
                                                                  AND e.date_heure = ?
                                                                GROUP BY l.id
                                                                ORDER BY 
                                                                    CASE l.type 
                                                                        WHEN 'amphi' THEN 1
                                                                        WHEN 'salle' THEN 2
                                                                        WHEN 'labo' THEN 3
                                                                        ELSE 4
                                                                    END,
                                                                    l.nom
                                                            ");
                                                            
                                                            $stmtDetails->execute([
                                                                $module['module_id'],
                                                                $module['date_heure']
                                                            ]);
                                                            
                                                            $details = $stmtDetails->fetchAll();
                                                            
                                                            if (!empty($details)):
                                                                // عرض القاعات مباشرة دون تقسيم إلى مجموعات
                                                            ?>
                                                                <div class="salles-list">
                                                                    <?php foreach ($details as $detail): ?>
                                                                        <?php 
                                                                        // تحديد الأيقونة حسب نوع القاعة
                                                                        $icon = '';
                                                                        $badge_class = '';
                                                                        $badge_text = '';
                                                                        
                                                                        switch($detail['salle_type']) {
                                                                            case 'amphi':
                                                                                $icon = '🏫';
                                                                                $badge_class = 'amphi';
                                                                                $badge_text = 'Amphithéâtre';
                                                                                break;
                                                                            case 'salle':
                                                                                $icon = '🚪';
                                                                                $badge_class = 'salle';
                                                                                $badge_text = 'Salle';
                                                                                break;
                                                                            case 'labo':
                                                                                $icon = '🔬';
                                                                                $badge_class = 'labo';
                                                                                $badge_text = 'Laboratoire';
                                                                                break;
                                                                            default:
                                                                                $icon = '🏢';
                                                                                $badge_class = 'salle';
                                                                                $badge_text = 'Salle';
                                                                        }
                                                                        ?>
                                                                        <div class="salle-item">
                                                                            <div class="salle-info">
                                                                                <span class="salle-icon"><?php echo $icon; ?></span>
                                                                                <span class="salle-name"><?php echo htmlspecialchars($detail['salle_nom']); ?></span>
                                                                                <span class="salle-type-badge <?php echo $badge_class; ?>"><?php echo $badge_text; ?></span>
                                                                            </div>
                                                                            <div class="groupes-info">
                                                                                <i class="fas fa-users"></i>
                                                                                Groupes : <?php echo htmlspecialchars($detail['groupes']); ?>
                                                                            </div>
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            <?php else: ?>
                                                                <div class="no-examen">
                                                                    <i class="fas fa-exclamation-circle"></i>
                                                                    Aucune salle attribuée pour cet examen
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="2" style="text-align: center; padding: 2rem; color: var(--gray-500);">
                                                    <i class="fas fa-book" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                                                    <h4 style="margin-bottom: 0.5rem;">Aucun examen planifié pour cette formation</h4>
                                                    <p>Utilisez le bouton "Lancer la Génération" pour créer un emploi du temps</p>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/fr.js"></script>
    <script>
        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            // Initialiser les datepickers
            flatpickr.localize(flatpickr.l10ns.fr);
            flatpickr("#start_date", { 
                dateFormat: "Y-m-d", 
                locale: "fr",
                minDate: "today",
                defaultDate: "<?php echo $start_date ?: date('Y-m-d', strtotime('+1 day')); ?>"
            });
            flatpickr("#end_date", { 
                dateFormat: "Y-m-d", 
                locale: "fr",
                minDate: "today",
                defaultDate: "<?php echo $end_date ?: date('Y-m-d', strtotime('+21 days')); ?>"
            });
            
            // Mettre à jour les statistiques
            updateSelectionStats();
            
            // Ouvrir tous les départements par défault
            openAllDepartments();
            
            // Menu toggle
            document.getElementById('menuToggle').addEventListener('click', function() {
                document.querySelector('.sidebar').classList.toggle('active');
            });
        });
        
        // Gestion du semestre
        function selectSemestre(semestre) {
            document.getElementById('semestreInput').value = semestre;
            
            // Mettre à jour l'affichage
            document.querySelectorAll('.semestre-card').forEach(card => {
                card.classList.remove('selected');
            });
            document.querySelector(`.semestre-card[data-semestre="${semestre}"]`).classList.add('selected');
            
            // Mettre à jour les informations
            document.getElementById('semestreInfoS1').style.display = semestre === 'S1' ? 'block' : 'none';
            document.getElementById('semestreInfoS2').style.display = semestre === 'S2' ? 'block' : 'none';
            
            // Mettre à jour les statistiques
            updateSelectionStats();
        }
        
        // Gestion des départements
        function toggleDepartment(deptId) {
            const content = document.getElementById('dept-content-' + deptId);
            const header = content.previousElementSibling;
            const icon = header.querySelector('.fa-chevron-down');
            
            content.classList.toggle('expanded');
            header.classList.toggle('expanded');
            
            if (icon.classList.contains('fa-chevron-down')) {
                icon.classList.remove('fa-chevron-down');
                icon.classList.add('fa-chevron-up');
            } else {
                icon.classList.remove('fa-chevron-up');
                icon.classList.add('fa-chevron-down');
            }
        }
        
        function toggleAllDepartments() {
            const departments = document.querySelectorAll('.department-content');
            const allExpanded = Array.from(departments).every(dept => dept.classList.contains('expanded'));
            
            departments.forEach(dept => {
                const header = dept.previousElementSibling;
                const icon = header.querySelector('i');
                
                if (allExpanded) {
                    dept.classList.remove('expanded');
                    header.classList.remove('expanded');
                    icon.classList.remove('fa-chevron-up');
                    icon.classList.add('fa-chevron-down');
                } else {
                    dept.classList.add('expanded');
                    header.classList.add('expanded');
                    icon.classList.remove('fa-chevron-down');
                    icon.classList.add('fa-chevron-up');
                }
            });
        }
        
        function openAllDepartments() {
            document.querySelectorAll('.department-content').forEach(dept => {
                const header = dept.previousElementSibling;
                const icon = header.querySelector('i');
                
                dept.classList.add('expanded');
                header.classList.add('expanded');
                icon.classList.remove('fa-chevron-down');
                icon.classList.add('fa-chevron-up');
            });
        }
        
        // Gestion des formations
        function toggleFormation(formationId) {
            const card = document.querySelector(`.formation-select-card[data-formation-id="${formationId}"]`);
            const checkbox = card.querySelector('input[type="checkbox"]');
            
            if (checkbox.checked) {
                checkbox.checked = false;
                card.classList.remove('selected');
            } else {
                checkbox.checked = true;
                card.classList.add('selected');
            }
            
            updateSelectionStats();
        }
        
        function selectAllFormations() {
            document.querySelectorAll('.formation-select-card').forEach(card => {
                const checkbox = card.querySelector('input[type="checkbox"]');
                checkbox.checked = true;
                card.classList.add('selected');
            });
            updateSelectionStats();
        }
        
        function deselectAllFormations() {
            document.querySelectorAll('.formation-select-card').forEach(card => {
                const checkbox = card.querySelector('input[type="checkbox"]');
                checkbox.checked = false;
                card.classList.remove('selected');
            });
            updateSelectionStats();
        }
        
        // Mise à jour des statistiques
        function updateSelectionStats() {
            const selectedFormations = document.querySelectorAll('input[name="formations[]"]:checked').length;
            const semestre = document.getElementById('semestreInput').value;
            
            document.getElementById('formationsSelected').textContent = selectedFormations;
            
            // Calculer les statistiques estimées
            const estimatedStudents = selectedFormations * 150; // Estimation
            const estimatedModules = selectedFormations * 8; // Estimation
            const estimatedExams = Math.ceil(estimatedModules * 1.2); // +20% pour les groupes
            
            document.getElementById('studentsSelected').textContent = estimatedStudents.toLocaleString();
            document.getElementById('modulesSelected').textContent = estimatedModules;
            document.getElementById('estimatedExams').textContent = estimatedExams;
        }
        
        // Actions
        function startGeneration() {
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            const selectedFormations = document.querySelectorAll('input[name="formations[]"]:checked').length;
            
            if (!startDate || !endDate) {
                alert('Veuillez sélectionner une période valide');
                return;
            }
            
            if (selectedFormations === 0) {
                alert('Veuillez sélectionner au moins une formation');
                return;
            }
            
            // Validation des dates
            const start = new Date(startDate);
            const end = new Date(endDate);
            const diffDays = Math.ceil((end - start) / (1000 * 60 * 60 * 24));
            
            if (diffDays < 1) {
                alert('La date de fin doit être après la date de début');
                return;
            }
            
            if (diffDays > 30) {
                if (!confirm('La période sélectionnée dépasse 30 jours. Êtes-vous sûr de vouloir continuer ?')) {
                    return;
                }
            }
            
            // Afficher l'animation de chargement
            const generateBtn = document.querySelector('.action-generate');
            const originalHtml = generateBtn.innerHTML;
            
            generateBtn.innerHTML = `
                <i class="fas fa-cog fa-spin"></i>
                <span>Génération en cours...</span>
                <small>Veuillez patienter</small>
            `;
            generateBtn.disabled = true;
            
            // Soumettre le formulaire
            setTimeout(() => {
                document.getElementById('actionInput').value = 'generate';
                document.getElementById('generationForm').submit();
            }, 500);
        }
        
        function previewGeneration() {
            const start = document.getElementById('start_date').value;
            const end = document.getElementById('end_date').value;
            const selected = document.querySelectorAll('input[name="formations[]"]:checked').length;
            const semestre = document.getElementById('semestreInput').value;
            const session = document.getElementById('session').value;
            
            if (!start || !end) {
                alert('Veuillez sélectionner une période');
                return;
            }
            
            if (selected === 0) {
                alert('Veuillez sélectionner au moins une formation');
                return;
            }
            
            const semestreName = semestre === 'S1' ? 'Semestre 1' : 'Semestre 2';
            const sessionName = session === 'normale' ? 'Session Normale' : 'Session de Rattrapage';
            
            alert(`APERÇU DE LA GÉNÉRATION\n\n` +
                  `📚 Semestre : ${semestreName}\n` +
                  `🎓 Session : ${sessionName}\n` +
                  `📅 Période : ${start} au ${end}\n` +
                  `🏫 Formations : ${selected} sélectionnées\n` +
                  `👨🎓 Étudiants estimés : ${document.getElementById('studentsSelected').textContent}\n` +
                  `📚 Modules estimés : ${document.getElementById('modulesSelected').textContent}\n` +
                  `📝 Examens estimés : ${document.getElementById('estimatedExams').textContent}\n\n` +
                  `Le système divisera automatiquement les grands groupes.`);
        }
        
        function clearGeneration() {
            if (confirm('Êtes-vous sûr de vouloir supprimer tous les examens planifiés ? Cette action est irréversible.')) {
                document.getElementById('actionInput').value = 'clear';
                document.getElementById('generationForm').submit();
            }
        }
        
        function detectConflicts() {
            if (confirm('Lancer la détection des conflits dans les examens existants ?')) {
                document.getElementById('actionInput').value = 'detect_conflicts';
                document.getElementById('generationForm').submit();
            }
        }

        // دالة عرض معاينة الإرسال
function previewSend() {
    const deptCards = document.querySelectorAll('.dept-card');
    let departments = [];
    deptCards.forEach(card => {
        departments.push(card.querySelector('div:first-child').textContent.trim());
    });
    
    alert(`APERÇU DE L'ENVOI\n\n` +
          `📤 Destinataires : ${departments.length} départements\n` +
          `📚 Semestre : ${document.querySelector('#semestreInput').value}\n` +
          `📅 Période : ${document.querySelector('#start_date').value} au ${document.querySelector('#end_date').value}\n` +
          `📝 Examens : ${<?php echo $exams_generated; ?>} examens générés\n\n` +
          `Les emplois du temps seront envoyés tels quels, avec leur structure complète.`);
} 
    </script>
</body>
</html>