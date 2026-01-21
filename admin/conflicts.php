<?php
// admin/conflicts.php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Vérifier que l'utilisateur est admin
require_role(['admin']);

// Récupérer l'utilisateur connecté
$user = get_logged_in_user();

// Variables
$message = '';
$message_type = '';

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'resolve_conflict') {
        // Résoudre un conflit
        $conflict_id = intval($_POST['conflict_id'] ?? 0);
        
        $stmt = $pdo->prepare("UPDATE conflits SET statut = 'resolu', date_resolution = NOW() WHERE id = ?");
        $stmt->execute([$conflict_id]);
        
        $message = "Conflit marqué comme résolu";
        $message_type = 'success';
        
        // Journaliser l'action
        $stmt = $pdo->prepare("INSERT INTO logs_activite (utilisateur_id, utilisateur_type, action, details) VALUES (?, ?, ?, ?)");
        $stmt->execute([$user['id'], 'admin', 'Résolution conflit', "Conflit #$conflict_id résolu"]);
        
    } elseif ($action === 'ignore_conflict') {
        // Ignorer un conflit
        $conflict_id = intval($_POST['conflict_id'] ?? 0);
        
        $stmt = $pdo->prepare("UPDATE conflits SET statut = 'ignore', date_resolution = NOW() WHERE id = ?");
        $stmt->execute([$conflict_id]);
        
        $message = "Conflit ignoré";
        $message_type = 'warning';
        
    } elseif ($action === 'resolve_all') {
        // Résoudre tous les conflits détectés
        $stmt = $pdo->prepare("UPDATE conflits SET statut = 'resolu', date_resolution = NOW() WHERE statut = 'detecte'");
        $stmt->execute();
        
        $message = "Tous les conflits ont été marqués comme résolus";
        $message_type = 'success';
        
    } elseif ($action === 'run_detection') {
        // Lancer la détection automatique
        require_once '../includes/algorithm.php';
        $result = detecterConflits();
        
        $message = "Détection terminée : " . $result['count'] . " conflits détectés";
        $message_type = $result['count'] > 0 ? 'warning' : 'success';
    }
}

// Récupérer les conflits
$filter = $_GET['filter'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';

$query = "SELECT c.*, 
                 CASE 
                     WHEN c.type = 'etudiant' THEN (SELECT CONCAT(prenom, ' ', nom) FROM etudiants WHERE id = c.entite1_id)
                     WHEN c.type = 'professeur' THEN (SELECT CONCAT(prenom, ' ', nom) FROM professeurs WHERE id = c.entite1_id)
                     WHEN c.type = 'salle' THEN (SELECT nom FROM lieu_examen WHERE id = c.entite1_id)
                     ELSE 'Examen #' || c.entite1_id
                 END as entite1_nom,
                 CASE 
                     WHEN c.type = 'etudiant' THEN (SELECT CONCAT(prenom, ' ', nom) FROM etudiants WHERE id = c.entite2_id)
                     WHEN c.type = 'professeur' THEN (SELECT CONCAT(prenom, ' ', nom) FROM professeurs WHERE id = c.entite2_id)
                     WHEN c.type = 'salle' THEN (SELECT nom FROM lieu_examen WHERE id = c.entite2_id)
                     ELSE 'Examen #' || c.entite2_id
                 END as entite2_nom
          FROM conflits c
          WHERE 1=1";

$params = [];

if ($filter !== 'all') {
    $query .= " AND c.statut = ?";
    $params[] = $filter;
}

if ($type_filter !== 'all') {
    $query .= " AND c.type = ?";
    $params[] = $type_filter;
}

$query .= " ORDER BY c.date_detection DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$conflits = $stmt->fetchAll();

// Statistiques des conflits
$stmt = $pdo->query("SELECT 
    statut, 
    COUNT(*) as count,
    ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER(), 1) as percentage
FROM conflits 
GROUP BY statut");
$stats_conflits = $stmt->fetchAll();

$stmt = $pdo->query("SELECT 
    type, 
    COUNT(*) as count 
FROM conflits 
WHERE statut = 'detecte'
GROUP BY type");
$conflits_par_type = $stmt->fetchAll();

// Conflits par département
$stmt = $pdo->query("SELECT 
    d.nom as departement,
    COUNT(c.id) as count
FROM conflits c
LEFT JOIN examens e ON (c.type = 'salle' OR c.type = 'horaire') AND (c.entite1_id = e.id OR c.entite2_id = e.id)
LEFT JOIN modules m ON e.module_id = m.id
LEFT JOIN formations f ON m.formation_id = f.id
LEFT JOIN departements d ON f.dept_id = d.id
WHERE c.statut = 'detecte'
GROUP BY d.id, d.nom
ORDER BY count DESC");
$conflits_par_departement = $stmt->fetchAll();

// Conflits récents
$stmt = $pdo->query("SELECT 
    c.*,
    DATE_FORMAT(c.date_detection, '%Y-%m-%d') as date_detection_day
FROM conflits c
WHERE c.statut = 'detecte'
ORDER BY c.date_detection DESC
LIMIT 10");
$conflits_recents = $stmt->fetchAll();

// Nombre total de conflits par statut
$detected_count = 0;
$resolved_count = 0;
$ignored_count = 0;

foreach ($stats_conflits as $stat) {
    if ($stat['statut'] === 'detecte') $detected_count = $stat['count'];
    if ($stat['statut'] === 'resolu') $resolved_count = $stat['count'];
    if ($stat['statut'] === 'ignore') $ignored_count = $stat['count'];
}

$total_conflits = $detected_count + $resolved_count + $ignored_count;
?>
<!DOCTYPE html>
<html lang="fr" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Conflits | <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .conflicts-container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header-section {
            background: linear-gradient(135deg, #f72585 0%, #b5179e 100%);
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .header-content h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        
        .header-content p {
            opacity: 0.9;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
        }
        
        .stat-card.detected::before { background: #f72585; }
        .stat-card.resolved::before { background: #4cc9f0; }
        .stat-card.ignored::before { background: #7209b7; }
        .stat-card.total::before { background: #4361ee; }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }
        
        .stat-card.detected .stat-icon { background: #f72585; }
        .stat-card.resolved .stat-icon { background: #4cc9f0; }
        .stat-card.ignored .stat-icon { background: #7209b7; }
        .stat-card.total .stat-icon { background: #4361ee; }
        
        .stat-title {
            font-size: 0.9rem;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
        }
        
        .stat-percentage {
            font-size: 0.9rem;
            font-weight: 600;
        }
        
        .stat-percentage.positive { color: #4cc9f0; }
        .stat-percentage.negative { color: #f72585; }
        
        .filters-section {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .filter-group {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .filter-badge {
            padding: 0.5rem 1.5rem;
            border-radius: 50px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            color: var(--gray-700);
            border: 2px solid transparent;
        }
        
        .filter-badge:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        
        .filter-badge.active {
            border-color: var(--primary);
            color: var(--primary);
            background: rgba(67, 97, 238, 0.1);
        }
        
        .filter-badge.detected { color: #f72585; }
        .filter-badge.resolved { color: #4cc9f0; }
        .filter-badge.ignored { color: #7209b7; }
        
        .action-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .conflict-list {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            margin-bottom: 2rem;
        }
        
        .conflict-header {
            display: grid;
            grid-template-columns: 50px 150px 1fr 1fr 200px 150px;
            gap: 1rem;
            padding: 1rem 1.5rem;
            background: var(--gray-100);
            font-weight: 600;
            color: var(--gray-700);
            border-bottom: 1px solid var(--gray-200);
        }
        
        .conflict-item {
            display: grid;
            grid-template-columns: 50px 150px 1fr 1fr 200px 150px;
            gap: 1rem;
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            align-items: center;
            transition: var(--transition);
        }
        
        .conflict-item:hover {
            background: var(--gray-50);
        }
        
        .conflict-item:last-child {
            border-bottom: none;
        }
        
        .conflict-type {
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            text-align: center;
            text-transform: uppercase;
        }
        
        .conflict-type.etudiant { background: rgba(76, 201, 240, 0.1); color: #4cc9f0; }
        .conflict-type.professeur { background: rgba(114, 9, 183, 0.1); color: #7209b7; }
        .conflict-type.salle { background: rgba(67, 97, 238, 0.1); color: #4361ee; }
        .conflict-type.horaire { background: rgba(247, 37, 133, 0.1); color: #f72585; }
        
        .conflict-status {
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            text-align: center;
        }
        
        .conflict-status.detecte { background: rgba(247, 37, 133, 0.1); color: #f72585; }
        .conflict-status.resolu { background: rgba(76, 201, 240, 0.1); color: #4cc9f0; }
        .conflict-status.ignore { background: rgba(114, 9, 183, 0.1); color: #7209b7; }
        
        .conflict-entities {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .entity-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            background: var(--gray-100);
            border-radius: var(--border-radius-sm);
        }
        
        .entity-icon {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            color: white;
        }
        
        .entity-icon.student { background: #4cc9f0; }
        .entity-icon.professor { background: #7209b7; }
        .entity-icon.room { background: #4361ee; }
        .entity-icon.exam { background: #f72585; }
        
        .conflict-description {
            color: var(--gray-700);
            line-height: 1.5;
        }
        
        .conflict-date {
            color: var(--gray-600);
            font-size: 0.9rem;
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        
        .conflict-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn-action {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: var(--border-radius-sm);
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-resolve {
            background: rgba(76, 201, 240, 0.1);
            color: #4cc9f0;
        }
        
        .btn-resolve:hover {
            background: #4cc9f0;
            color: white;
        }
        
        .btn-ignore {
            background: rgba(114, 9, 183, 0.1);
            color: #7209b7;
        }
        
        .btn-ignore:hover {
            background: #7209b7;
            color: white;
        }
        
        .charts-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .chart-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
        }
        
        .chart-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: var(--gray-900);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .chart-bars {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .chart-bar {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .chart-label {
            width: 120px;
            font-weight: 500;
        }
        
        .chart-progress {
            flex: 1;
            height: 10px;
            background: var(--gray-200);
            border-radius: 5px;
            overflow: hidden;
        }
        
        .chart-fill {
            height: 100%;
            border-radius: 5px;
        }
        
        .chart-count {
            width: 60px;
            text-align: right;
            font-weight: 600;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            background: var(--gray-100);
            border-radius: var(--border-radius);
            margin: 2rem 0;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: var(--gray-300);
            margin-bottom: 1rem;
        }
        
        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: var(--gray-700);
        }
        
        .empty-state p {
            color: var(--gray-600);
            max-width: 500px;
            margin: 0 auto 1.5rem;
        }
        
        .conflict-details {
            margin-top: 1rem;
            padding: 1rem;
            background: var(--gray-100);
            border-radius: var(--border-radius-sm);
            border-left: 4px solid var(--primary);
            display: none;
        }
        
        .conflict-details.show {
            display: block;
            animation: slideDown 0.3s ease-out;
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .detail-item {
            padding: 0.75rem;
            background: white;
            border-radius: var(--border-radius-sm);
        }
        
        .detail-label {
            font-size: 0.85rem;
            color: var(--gray-600);
            margin-bottom: 0.25rem;
        }
        
        .detail-value {
            font-weight: 600;
            color: var(--gray-900);
        }
        
        .btn-details {
            background: none;
            border: none;
            color: var(--primary);
            cursor: pointer;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
        }
        
        .btn-details:hover {
            text-decoration: underline;
        }
        
        @media (max-width: 1200px) {
            .conflict-header,
            .conflict-item {
                grid-template-columns: 50px 150px 1fr 200px 150px;
            }
            
            .conflict-item .conflict-description {
                display: none;
            }
        }
        
        @media (max-width: 992px) {
            .conflict-header,
            .conflict-item {
                grid-template-columns: 50px 1fr 200px 150px;
            }
            
            .conflict-item .conflict-entities {
                display: none;
            }
            
            .charts-section {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .conflict-header,
            .conflict-item {
                grid-template-columns: 1fr;
                gap: 0.5rem;
            }
            
            .conflict-header {
                display: none;
            }
            
            .conflict-item {
                padding: 1rem;
                border: 1px solid var(--gray-200);
                border-radius: var(--border-radius-sm);
                margin-bottom: 1rem;
            }
            
            .conflict-actions {
                justify-content: center;
            }
            
            .filters-section {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-group {
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
                <p>Gestion des Conflits</p>
            </div>
            
            <div class="user-info">
                <div class="user-avatar">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div class="user-name"><?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($user['role_fr']); ?></div>
            </div>
            
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                    <span>Tableau de Bord</span>
                </a>
                <a href="generate_schedule.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-calendar-plus"></i></span>
                    <span>Générer EDT</span>
                </a>
                <a href="manage_rooms.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-building"></i></span>
                    <span>Gérer les Salles</span>
                </a>
                <a href="conflicts.php" class="nav-item active">
                    <span class="nav-icon"><i class="fas fa-exclamation-triangle"></i></span>
                    <span>Conflits <span class="notification-count"><?php echo $detected_count; ?></span></span>
                </a>

                <a href="GererUser.php" class="nav-item"><span class="nav-icon"><i class="fas fa-building"></i></span><span>Gérer les Utilisateurs</span></a>
                <a href="Statistique.php" class="nav-item"><span class="nav-icon"><i class="fas fa-building"></i></span><span>Les Statistique </span></a>
                <a href="" class="nav-item"><span class="nav-icon"><i class="fas fa-building"></i></span><span>Les Parametre </span></a>
                <a href="../logout.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                    <span>Déconnexion</span>
                </a>
            </nav>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="conflicts-container">
                <!-- Header Section -->
                <div class="header-section">
                    <div class="header-content">
                        <h1><i class="fas fa-exclamation-triangle"></i> Gestion des Conflits</h1>
                        <p>Détection et résolution des conflits dans la planification des examens</p>
                    </div>
                    
                    <div class="action-buttons">
                        <form method="POST" action="" style="display: inline;">
                            <input type="hidden" name="action" value="run_detection">
                            <button type="submit" class="btn btn-warning">
                                <i class="fas fa-search"></i> Détecter les Conflits
                            </button>
                        </form>
                        
                        <?php if ($detected_count > 0): ?>
                            <form method="POST" action="" style="display: inline;">
                                <input type="hidden" name="action" value="resolve_all">
                                <button type="submit" class="btn btn-success" 
                                        onclick="return confirm('Marquer tous les conflits comme résolus ?')">
                                    <i class="fas fa-check-circle"></i> Tout Résoudre
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Messages -->
                <?php if ($message): ?>
                    <div class="flash-message flash-<?php echo $message_type; ?> animate__animated animate__fadeIn">
                        <span class="flash-icon">
                            <?php echo $message_type === 'success' ? '✅' : '⚠️'; ?>
                        </span>
                        <span><?php echo htmlspecialchars($message); ?></span>
                    </div>
                <?php endif; ?>
                
                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card detected animate__animated animate__fadeInUp">
                        <div class="stat-header">
                            <div>
                                <div class="stat-title">Conflits Détectés</div>
                                <div class="stat-value"><?php echo $detected_count; ?></div>
                            </div>
                            <div class="stat-icon">
                                <i class="fas fa-exclamation-circle"></i>
                            </div>
                        </div>
                        <div class="stat-percentage negative">
                            <?php if ($total_conflits > 0): ?>
                                <?php echo round(($detected_count / $total_conflits) * 100, 1); ?>% du total
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="stat-card resolved animate__animated animate__fadeInUp" style="animation-delay: 0.1s;">
                        <div class="stat-header">
                            <div>
                                <div class="stat-title">Conflits Résolus</div>
                                <div class="stat-value"><?php echo $resolved_count; ?></div>
                            </div>
                            <div class="stat-icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                        </div>
                        <div class="stat-percentage positive">
                            <?php if ($total_conflits > 0): ?>
                                <?php echo round(($resolved_count / $total_conflits) * 100, 1); ?>% du total
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="stat-card ignored animate__animated animate__fadeInUp" style="animation-delay: 0.2s;">
                        <div class="stat-header">
                            <div>
                                <div class="stat-title">Conflits Ignorés</div>
                                <div class="stat-value"><?php echo $ignored_count; ?></div>
                            </div>
                            <div class="stat-icon">
                                <i class="fas fa-eye-slash"></i>
                            </div>
                        </div>
                        <div class="stat-percentage">
                            <?php if ($total_conflits > 0): ?>
                                <?php echo round(($ignored_count / $total_conflits) * 100, 1); ?>% du total
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="stat-card total animate__animated animate__fadeInUp" style="animation-delay: 0.3s;">
                        <div class="stat-header">
                            <div>
                                <div class="stat-title">Total Conflits</div>
                                <div class="stat-value"><?php echo $total_conflits; ?></div>
                            </div>
                            <div class="stat-icon">
                                <i class="fas fa-chart-bar"></i>
                            </div>
                        </div>
                        <div class="stat-percentage">
                            Depuis le début
                        </div>
                    </div>
                </div>
                
                <!-- Charts Section -->
                <div class="charts-section">
                    <div class="chart-card">
                        <h3 class="chart-title">
                            <i class="fas fa-chart-pie"></i>
                            Conflits par Type
                        </h3>
                        <div class="chart-bars">
                            <?php foreach ($conflits_par_type as $type): ?>
                                <div class="chart-bar">
                                    <span class="chart-label">
                                        <?php 
                                        $type_names = [
                                            'etudiant' => 'Étudiant',
                                            'professeur' => 'Professeur',
                                            'salle' => 'Salle',
                                            'horaire' => 'Horaire'
                                        ];
                                        echo $type_names[$type['type']] ?? ucfirst($type['type']);
                                        ?>
                                    </span>
                                    <div class="chart-progress">
                                        <div class="chart-fill" style="width: <?php echo ($type['count'] / max($detected_count, 1)) * 100; ?>%; 
                                            background: <?php 
                                                if ($type['type'] === 'etudiant') echo '#4cc9f0';
                                                elseif ($type['type'] === 'professeur') echo '#7209b7';
                                                elseif ($type['type'] === 'salle') echo '#4361ee';
                                                else echo '#f72585';
                                            ?>;">
                                        </div>
                                    </div>
                                    <span class="chart-count"><?php echo $type['count']; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="chart-card">
                        <h3 class="chart-title">
                            <i class="fas fa-university"></i>
                            Conflits par Département
                        </h3>
                        <div class="chart-bars">
                            <?php foreach ($conflits_par_departement as $dept): ?>
                                <div class="chart-bar">
                                    <span class="chart-label"><?php echo htmlspecialchars($dept['departement'] ?: 'Non spécifié'); ?></span>
                                    <div class="chart-progress">
                                        <div class="chart-fill" style="width: <?php 
                                            $max = max(array_column($conflits_par_departement, 'count'));
                                            echo $max > 0 ? ($dept['count'] / $max * 100) : 0;
                                        ?>%; 
                                            background: <?php echo generate_badge_color($dept['departement']); ?>;">
                                        </div>
                                    </div>
                                    <span class="chart-count"><?php echo $dept['count']; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Filters Section -->
                <div class="filters-section">
                    <div class="filter-group">
                        <span style="font-weight: 600; color: var(--gray-700);">Filtrer par :</span>
                        
                        <a href="?filter=all&type=<?php echo $type_filter; ?>" 
                           class="filter-badge <?php echo $filter === 'all' ? 'active' : ''; ?>">
                            Tous (<?php echo $total_conflits; ?>)
                        </a>
                        
                        <a href="?filter=detecte&type=<?php echo $type_filter; ?>" 
                           class="filter-badge detected <?php echo $filter === 'detecte' ? 'active' : ''; ?>">
                            <i class="fas fa-exclamation-circle"></i> Détectés (<?php echo $detected_count; ?>)
                        </a>
                        
                        <a href="?filter=resolu&type=<?php echo $type_filter; ?>" 
                           class="filter-badge resolved <?php echo $filter === 'resolu' ? 'active' : ''; ?>">
                            <i class="fas fa-check-circle"></i> Résolus (<?php echo $resolved_count; ?>)
                        </a>
                        
                        <a href="?filter=ignore&type=<?php echo $type_filter; ?>" 
                           class="filter-badge ignored <?php echo $filter === 'ignore' ? 'active' : ''; ?>">
                            <i class="fas fa-eye-slash"></i> Ignorés (<?php echo $ignored_count; ?>)
                        </a>
                    </div>
                    
                    <div class="filter-group">
                        <span style="font-weight: 600; color: var(--gray-700);">Type :</span>
                        
                        <a href="?filter=<?php echo $filter; ?>&type=all" 
                           class="filter-badge <?php echo $type_filter === 'all' ? 'active' : ''; ?>">
                            Tous types
                        </a>
                        
                        <a href="?filter=<?php echo $filter; ?>&type=etudiant" 
                           class="filter-badge <?php echo $type_filter === 'etudiant' ? 'active' : ''; ?>">
                            Étudiant
                        </a>
                        
                        <a href="?filter=<?php echo $filter; ?>&type=professeur" 
                           class="filter-badge <?php echo $type_filter === 'professeur' ? 'active' : ''; ?>">
                            Professeur
                        </a>
                        
                        <a href="?filter=<?php echo $filter; ?>&type=salle" 
                           class="filter-badge <?php echo $type_filter === 'salle' ? 'active' : ''; ?>">
                            Salle
                        </a>
                        
                        <a href="?filter=<?php echo $filter; ?>&type=horaire" 
                           class="filter-badge <?php echo $type_filter === 'horaire' ? 'active' : ''; ?>">
                            Horaire
                        </a>
                    </div>
                </div>
                
                <!-- Conflicts List -->
                <div class="conflict-list">
                    <?php if (count($conflits) > 0): ?>
                        <div class="conflict-header">
                            <div>ID</div>
                            <div>Type</div>
                            <div>Entités</div>
                            <div>Description</div>
                            <div>Date</div>
                            <div>Actions</div>
                        </div>
                        
                        <?php foreach ($conflits as $conflict): ?>
                            <div class="conflict-item animate__animated animate__fadeIn">
                                <div>#<?php echo $conflict['id']; ?></div>
                                
                                <div>
                                    <span class="conflict-type <?php echo $conflict['type']; ?>">
                                        <?php 
                                        $type_names = [
                                            'etudiant' => 'Étudiant',
                                            'professeur' => 'Professeur',
                                            'salle' => 'Salle',
                                            'horaire' => 'Horaire'
                                        ];
                                        echo $type_names[$conflict['type']] ?? ucfirst($conflict['type']);
                                        ?>
                                    </span>
                                </div>
                                
                                <div class="conflict-entities">
                                    <div class="entity-item">
                                        <span class="entity-icon 
                                            <?php 
                                                if ($conflict['type'] === 'etudiant') echo 'student';
                                                elseif ($conflict['type'] === 'professeur') echo 'professor';
                                                elseif ($conflict['type'] === 'salle') echo 'room';
                                                else echo 'exam';
                                            ?>">
                                            <i class="fas 
                                                <?php 
                                                if ($conflict['type'] === 'etudiant') echo 'fa-user-graduate';
                                                elseif ($conflict['type'] === 'professeur') echo 'fa-user-tie';
                                                elseif ($conflict['type'] === 'salle') echo 'fa-door-open';
                                                else echo 'fa-file-alt';
                                                ?>">
                                            </i>
                                        </span>
                                        <span><?php echo htmlspecialchars($conflict['entite1_nom'] ?: 'Entité #' . $conflict['entite1_id']); ?></span>
                                    </div>
                                    
                                    <div class="entity-item">
                                        <span class="entity-icon 
                                            <?php 
                                                if ($conflict['type'] === 'etudiant') echo 'student';
                                                elseif ($conflict['type'] === 'professeur') echo 'professor';
                                                elseif ($conflict['type'] === 'salle') echo 'room';
                                                else echo 'exam';
                                            ?>">
                                            <i class="fas 
                                                <?php 
                                                if ($conflict['type'] === 'etudiant') echo 'fa-user-graduate';
                                                elseif ($conflict['type'] === 'professeur') echo 'fa-user-tie';
                                                elseif ($conflict['type'] === 'salle') echo 'fa-door-open';
                                                else echo 'fa-file-alt';
                                                ?>">
                                            </i>
                                        </span>
                                        <span><?php echo htmlspecialchars($conflict['entite2_nom'] ?: 'Entité #' . $conflict['entite2_id']); ?></span>
                                    </div>
                                </div>
                                
                                <div class="conflict-description">
                                    <?php echo htmlspecialchars($conflict['description']); ?>
                                </div>
                                
                                <div class="conflict-date">
                                    <span>Détection : <?php echo format_date_fr($conflict['date_detection'], true); ?></span>
                                    <?php if ($conflict['date_resolution']): ?>
                                        <span>Résolution : <?php echo format_date_fr($conflict['date_resolution'], true); ?></span>
                                    <?php endif; ?>
                                </div>
                                
                                <div>
                                    <span class="conflict-status <?php echo $conflict['statut']; ?>">
                                        <?php echo ucfirst($conflict['statut']); ?>
                                    </span>
                                </div>
                                
                                <div class="conflict-actions">
                                    <?php if ($conflict['statut'] === 'detecte'): ?>
                                        <form method="POST" action="" style="display: inline;">
                                            <input type="hidden" name="action" value="resolve_conflict">
                                            <input type="hidden" name="conflict_id" value="<?php echo $conflict['id']; ?>">
                                            <button type="submit" class="btn-action btn-resolve" 
                                                    onclick="return confirm('Marquer ce conflit comme résolu ?')">
                                                <i class="fas fa-check"></i> Résoudre
                                            </button>
                                        </form>
                                        
                                        <form method="POST" action="" style="display: inline;">
                                            <input type="hidden" name="action" value="ignore_conflict">
                                            <input type="hidden" name="conflict_id" value="<?php echo $conflict['id']; ?>">
                                            <button type="submit" class="btn-action btn-ignore"
                                                    onclick="return confirm('Ignorer ce conflit ?')">
                                                <i class="fas fa-eye-slash"></i> Ignorer
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <button type="button" class="btn-details" 
                                            onclick="toggleDetails(<?php echo $conflict['id']; ?>)">
                                        <i class="fas fa-info-circle"></i> Détails
                                    </button>
                                </div>
                                
                                <!-- Details Section -->
                                <div class="conflict-details" id="details-<?php echo $conflict['id']; ?>">
                                    <div class="details-grid">
                                        <div class="detail-item">
                                            <div class="detail-label">Type de conflit</div>
                                            <div class="detail-value">
                                                <?php 
                                                $type_descriptions = [
                                                    'etudiant' => 'Étudiant en double examen',
                                                    'professeur' => 'Professeur surchargé',
                                                    'salle' => 'Salle double réservation',
                                                    'horaire' => 'Chevauchement horaire'
                                                ];
                                                echo $type_descriptions[$conflict['type']] ?? 'Conflit non spécifié';
                                                ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <div class="detail-label">Statut</div>
                                            <div class="detail-value">
                                                <?php if ($conflict['statut'] === 'detecte'): ?>
                                                    <span style="color: #f72585;">● En attente de résolution</span>
                                                <?php elseif ($conflict['statut'] === 'resolu'): ?>
                                                    <span style="color: #4cc9f0;">● Résolu le <?php echo format_date_fr($conflict['date_resolution']); ?></span>
                                                <?php else: ?>
                                                    <span style="color: #7209b7;">● Ignoré le <?php echo format_date_fr($conflict['date_resolution']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <div class="detail-label">ID des entités</div>
                                            <div class="detail-value">#<?php echo $conflict['entite1_id']; ?> et #<?php echo $conflict['entite2_id']; ?></div>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <div class="detail-label">Description complète</div>
                                            <div class="detail-value"><?php echo htmlspecialchars($conflict['description']); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <h3>Aucun conflit trouvé</h3>
                            <p>Tous les examens sont planifiés sans conflit. Félicitations !</p>
                            <form method="POST" action="" style="display: inline;">
                                <input type="hidden" name="action" value="run_detection">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Lancer une nouvelle détection
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        // Menu Toggle
        document.querySelector('.menu-toggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
        });
        
        // Toggle details
        function toggleDetails(conflictId) {
            const details = document.getElementById('details-' + conflictId);
            details.classList.toggle('show');
        }
        
        // Add animation to conflict items
        document.addEventListener('DOMContentLoaded', function() {
            const conflictItems = document.querySelectorAll('.conflict-item');
            conflictItems.forEach((item, index) => {
                item.style.animationDelay = (index * 0.05) + 's';
            });
            
            // Auto-refresh if there are detected conflicts
            if (<?php echo $detected_count; ?> > 0) {
                setTimeout(() => {
                    // Show notification badge animation
                    const badge = document.querySelector('.notification-count');
                    if (badge) {
                        badge.classList.add('animate-pulse');
                        setInterval(() => {
                            badge.classList.toggle('animate-pulse');
                        }, 2000);
                    }
                }, 1000);
            }
        });
        
        // Filter animation
        const filterBadges = document.querySelectorAll('.filter-badge');
        filterBadges.forEach(badge => {
            badge.addEventListener('click', function(e) {
                if (!this.classList.contains('active')) {
                    filterBadges.forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                }
            });
        });
    </script>
</body>
</html>