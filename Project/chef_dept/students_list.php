<?php
// chef_dept/students_list.php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Vérifier que l'utilisateur est chef de département
require_role(['chef_dept']);

// Récupérer l'utilisateur connecté
$user = get_logged_in_user();
$dept_id = $user['dept_id'];

// Récupérer les informations du département
$stmt = $pdo->prepare("SELECT nom FROM departements WHERE id = ?");
$stmt->execute([$dept_id]);
$departement = $stmt->fetch();

// Récupérer les notifications
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications 
                       WHERE destinataire_id = ? 
                       AND destinataire_type = 'prof' 
                       AND lue = 0");
$stmt->execute([$user['id']]);
$notification_count = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT * FROM notifications 
                       WHERE destinataire_id = ? 
                       AND destinataire_type = 'prof'
                       ORDER BY created_at DESC 
                       LIMIT 5");
$stmt->execute([$user['id']]);
$notifications = $stmt->fetchAll();

// Récupérer les envois en attente
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM envois_chefs_departement 
                       WHERE chef_dept_id = ? 
                       AND departement_id = ?
                       AND statut = 'envoye'");
$stmt->execute([$user['id'], $dept_id]);
$pending_validation = $stmt->fetch()['count'];

// Récupérer les informations du département (pour le titre)
$sql = "SELECT * FROM departements WHERE id = ?";
$dept = fetchOne($sql, [$dept_id]);

// Paramètres de filtrage
$formation_id = $_GET['formation_id'] ?? '';
$promo = $_GET['promo'] ?? '';
$search = $_GET['search'] ?? '';
$groupe = $_GET['groupe'] ?? '';

// Récupérer les formations du département
$sql_formations = "SELECT * FROM formations WHERE dept_id = ? ORDER BY nom";
$stmt_formations = $pdo->prepare($sql_formations);
$stmt_formations->execute([$dept_id]);
$formations = $stmt_formations->fetchAll();

// Construire la requête
$sql = "SELECT e.*, 
               f.nom as formation_nom,
               COUNT(DISTINCT ee.examen_id) as nb_examens,
               COUNT(DISTINCT i.module_id) as nb_modules
        FROM etudiants e
        JOIN formations f ON e.formation_id = f.id
        LEFT JOIN examens_etudiants ee ON e.id = ee.etudiant_id
        LEFT JOIN inscriptions i ON e.id = i.etudiant_id
        WHERE f.dept_id = ? ";

$params = [$dept_id];

if ($formation_id && is_numeric($formation_id)) {
    $sql .= " AND e.formation_id = ?";
    $params[] = $formation_id;
}

if ($promo && is_numeric($promo)) {
    $sql .= " AND e.promo = ?";
    $params[] = $promo;
}

if ($groupe && is_numeric($groupe)) {
    $sql .= " AND e.num_groupe = ?";
    $params[] = $groupe;
}

if ($search) {
    $sql .= " AND (e.nom LIKE ? OR e.prenom LIKE ? OR e.email LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$sql .= " GROUP BY e.id
          ORDER BY e.nom, e.prenom";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$etudiants = $stmt->fetchAll();

// Statistiques
$stats = [
    'total' => count($etudiants),
    'par_promo' => [],
    'par_formation' => []
];

foreach ($etudiants as $etudiant) {
    $promo_key = $etudiant['promo'];
    if (!isset($stats['par_promo'][$promo_key])) {
        $stats['par_promo'][$promo_key] = 0;
    }
    $stats['par_promo'][$promo_key]++;
    
    $formation_key = $etudiant['formation_nom'];
    if (!isset($stats['par_formation'][$formation_key])) {
        $stats['par_formation'][$formation_key] = 0;
    }
    $stats['par_formation'][$formation_key]++;
}

// Récupérer les groupes disponibles
$sql_groupes = "SELECT DISTINCT num_groupe 
                FROM etudiants e
                JOIN formations f ON e.formation_id = f.id
                WHERE f.dept_id = ? AND num_groupe IS NOT NULL
                ORDER BY num_groupe";
$stmt_groupes = $pdo->prepare($sql_groupes);
$stmt_groupes->execute([$dept_id]);
$groupes = $stmt_groupes->fetchAll();

$page_title = "Liste des Étudiants - " . htmlspecialchars($dept['nom']);
?>
<!DOCTYPE html>
<html lang="fr" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title . ' | ' . SITE_NAME; ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .students-container {
            max-width: 1600px;
            margin: 0 auto;
        }
        
        /* Filtres */
        .filters-section {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .search-box {
            grid-column: 1 / -1;
            position: relative;
        }
        
        .search-box input {
            width: 100%;
            padding: 0.85rem 1rem 0.85rem 3rem;
            border: 2px solid var(--gray-300);
            border-radius: var(--border-radius-sm);
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .search-box input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        
        .search-box i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-500);
        }
        
        /* Cartes d'étudiants */
        .students-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .student-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .student-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .student-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, #4cc9f0, #4361ee);
        }
        
        .student-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .student-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4cc9f0, #4361ee);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            font-weight: 600;
        }
        
        .student-info {
            flex: 1;
        }
        
        .student-name {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.25rem;
        }
        
        .student-formation {
            font-size: 0.9rem;
            color: var(--primary);
            font-weight: 600;
        }
        
        .student-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .detail-item {
            text-align: center;
            padding: 0.75rem;
            background: var(--gray-50);
            border-radius: var(--border-radius-sm);
        }
        
        .detail-value {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--gray-900);
            display: block;
            margin-bottom: 0.25rem;
        }
        
        .detail-label {
            font-size: 0.85rem;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .student-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        
        .btn-student {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: var(--border-radius-sm);
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            text-decoration: none;
        }
        
        .btn-student.primary {
            background: rgba(67, 97, 238, 0.1);
            color: var(--primary);
        }
        
        .btn-student.success {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success);
        }
        
        .btn-student.info {
            background: rgba(52, 152, 219, 0.1);
            color: var(--info);
        }
        
        .btn-student:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        
        /* Vue tableau (alternative) */
        .view-toggle {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            justify-content: flex-end;
        }
        
        .view-btn {
            padding: 0.5rem 1rem;
            border: 2px solid var(--gray-300);
            background: white;
            border-radius: var(--border-radius-sm);
            cursor: pointer;
            color: var(--gray-700);
            transition: all 0.3s ease;
        }
        
        .view-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .view-btn:hover {
            border-color: var(--primary);
        }
        
        /* Tableau */
        .students-table-container {
            display: none;
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow-md);
            margin-bottom: 2rem;
        }
        
        .students-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .students-table th {
            padding: 1rem;
            background: var(--gray-100);
            text-align: left;
            font-weight: 700;
            color: var(--gray-800);
            border-bottom: 2px solid var(--gray-300);
        }
        
        .students-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .students-table tr:hover {
            background: var(--gray-50);
        }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }
        
        .page-btn {
            padding: 0.5rem 1rem;
            border: 1px solid var(--gray-300);
            border-radius: var(--border-radius-sm);
            background: white;
            color: var(--gray-700);
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .page-btn:hover {
            background: var(--gray-100);
        }
        
        .page-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        /* Statistiques rapides */
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .quick-stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            text-align: center;
        }
        
        .quick-stat-value {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }
        
        .quick-stat-label {
            font-size: 0.9rem;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Notifications styles from first code */
        .notification-dropdown {
            position: relative;
        }
        
        .notification-list {
            position: absolute;
            top: 100%;
            right: 0;
            width: 350px;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            z-index: 1000;
            display: none;
            border: 1px solid var(--gray-200);
        }
        
        .notification-list.show {
            display: block;
            animation: slideDown 0.3s ease-out;
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .notification-header {
            padding: 1rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .notification-header h4 {
            margin: 0;
            font-weight: 600;
            color: var(--gray-800);
        }
        
        .mark-all-read {
            background: none;
            border: none;
            color: var(--primary);
            font-size: 0.85rem;
            cursor: pointer;
            padding: 0.25rem 0.5rem;
            border-radius: var(--border-radius-sm);
        }
        
        .mark-all-read:hover {
            background: var(--gray-100);
        }
        
        .notification-items {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .notification-item {
            display: flex;
            padding: 1rem;
            border-bottom: 1px solid var(--gray-200);
            text-decoration: none;
            color: inherit;
            transition: var(--transition);
            position: relative;
        }
        
        .notification-item:hover {
            background: var(--gray-100);
        }
        
        .notification-item.unread {
            background: rgba(67, 97, 238, 0.05);
        }
        
        .notification-icon {
            margin-right: 1rem;
            font-size: 1.25rem;
            color: var(--primary);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(67, 97, 238, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .notification-content {
            flex: 1;
        }
        
        .notification-title {
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 0.25rem;
        }
        
        .notification-text {
            font-size: 0.9rem;
            color: var(--gray-600);
            margin-bottom: 0.5rem;
            line-height: 1.4;
        }
        
        .notification-time {
            font-size: 0.75rem;
            color: var(--gray-500);
        }
        
        .notification-dot {
            position: absolute;
            top: 1rem;
            right: 1rem;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--primary);
        }
        
        .notification-empty {
            text-align: center;
            padding: 2rem;
            color: var(--gray-500);
        }
        
        .notification-empty i {
            font-size: 2rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .notification-footer {
            padding: 0.75rem 1rem;
            text-align: center;
            border-top: 1px solid var(--gray-200);
        }
        
        .notification-footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .badge {
            background: var(--danger);
            color: white;
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 50px;
            margin-left: 0.5rem;
            font-weight: 600;
        }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .notification-list {
                position: fixed;
                top: 70px;
                right: 20px;
                left: 20px;
                width: auto;
            }
        }
        
        @media (max-width: 768px) {
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .students-grid {
                grid-template-columns: 1fr;
            }
            
            .student-details {
                grid-template-columns: 1fr;
            }
            
            .quick-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .view-toggle {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        <!-- Sidebar (identique au premier code) -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-university"></i> <?php echo htmlspecialchars($departement['nom'] ?? 'Département'); ?></h2>
                <p>Chef de Département</p>
            </div>
            
            <div class="user-info">
                <div class="user-avatar" style="background: linear-gradient(135deg, #3a0ca3 0%, #4361ee 100%);">
                    <i class="fas fa-user-tie"></i>
                </div>
                <div class="user-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($user['role_fr']); ?></div>
            </div>
            
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                    <span>Tableau de Bord</span>
                </a>
                <a href="department_schedule.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-calendar-alt"></i></span>
                    <span>Planning Departement</span>
                </a>
                <a href="validation.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-check-circle"></i></span>
                    <span>Validation EDT</span>
                    <?php if ($pending_validation > 0): ?>
                        <span class="notification-count"><?php echo $pending_validation; ?></span>
                    <?php endif; ?>
                </a>
                <a href="Students_list.php" class="nav-item active">
                    <span class="nav-icon"><i class="fas fa-users"></i></span>
                    <span>Students list </span>
                </a>
                <a href="professors_list.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-chalkboard-teacher"></i></span>
                    <span>professsors list</span>
                </a>
                <a href="../logout.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                    <span>Déconnexion</span>
                </a>
            </nav>
            
            <div class="sidebar-footer">
                <?php
                // Statistiques pour le sidebar
                $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM etudiants e 
                                       JOIN formations f ON e.formation_id = f.id 
                                       WHERE f.dept_id = ?");
                $stmt->execute([$dept_id]);
                $total_etudiants = $stmt->fetch()['total'];
                
                $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM professeurs WHERE dept_id = ?");
                $stmt->execute([$dept_id]);
                $total_professeurs = $stmt->fetch()['total'];
                
                $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM formations WHERE dept_id = ?");
                $stmt->execute([$dept_id]);
                $total_formations = $stmt->fetch()['total'];
                ?>
                <div class="dept-stats">
                    <div class="stat-mini">
                        <i class="fas fa-user-graduate"></i>
                        <span><?php echo number_format($total_etudiants); ?></span>
                    </div>
                    <div class="stat-mini">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <span><?php echo number_format($total_professeurs); ?></span>
                    </div>
                    <div class="stat-mini">
                        <i class="fas fa-graduation-cap"></i>
                        <span><?php echo number_format($total_formations); ?></span>
                    </div>
                </div>
            </div>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <header class="header">
                <div class="header-left">
                    <h1><i class="fas fa-users"></i> Liste des Étudiants</h1>
                    <p>Gestion des étudiants du département <?php echo htmlspecialchars($departement['nom'] ?? 'Département'); ?></p>
                </div>
                <div class="header-actions">
                    <!-- Notifications Dropdown (identique au premier code) -->
                    <div class="notification-dropdown">
                        <button class="notification-badge" onclick="toggleNotifications()">
                            <i class="fas fa-bell"></i>
                            <?php if ($notification_count > 0): ?>
                                <span class="notification-count"><?php echo $notification_count; ?></span>
                            <?php endif; ?>
                        </button>
                        
                        <!-- Notifications List -->
                        <div class="notification-list" id="notificationList">
                            <div class="notification-header">
                                <h4>Notifications</h4>
                                <?php if ($notification_count > 0): ?>
                                    <button type="button" class="mark-all-read" onclick="markAllNotificationsRead()">
                                        <i class="fas fa-check-double"></i> Tout marquer comme lu
                                    </button>
                                <?php endif; ?>
                            </div>
                            
                            <div class="notification-items">
                                <?php if (empty($notifications)): ?>
                                    <div class="notification-empty">
                                        <i class="fas fa-bell-slash"></i>
                                        <p>Aucune notification</p>
                                    </div>
                                <?php else: 
                                    foreach ($notifications as $notification): ?>
                                        <a href="<?php echo htmlspecialchars($notification['lien'] ?? '#'); ?>" 
                                           class="notification-item <?php echo $notification['lue'] ? 'read' : 'unread'; ?>"
                                           onclick="markNotificationRead(<?php echo $notification['id']; ?>)">
                                            <div class="notification-icon">
                                                <?php 
                                                $icons = [
                                                    'emploi_temps' => 'fas fa-calendar-alt',
                                                    'validation' => 'fas fa-check-circle',
                                                    'conflit' => 'fas fa-exclamation-triangle',
                                                    'student' => 'fas fa-user-graduate',
                                                    'professor' => 'fas fa-chalkboard-teacher'
                                                ];
                                                $icon = $icons[$notification['type']] ?? 'fas fa-bell';
                                                ?>
                                                <i class="<?php echo $icon; ?>"></i>
                                            </div>
                                            <div class="notification-content">
                                                <div class="notification-title"><?php echo htmlspecialchars($notification['titre']); ?></div>
                                                <div class="notification-text"><?php echo truncate_text(htmlspecialchars($notification['contenu']), 80); ?></div>
                                                <div class="notification-time"><?php echo format_date_fr($notification['created_at'], true); ?></div>
                                            </div>
                                            <?php if (!$notification['lue']): ?>
                                                <span class="notification-dot"></span>
                                            <?php endif; ?>
                                        </a>
                                    <?php endforeach; 
                                endif; ?>
                            </div>
                            
                            <div class="notification-footer">
                                <a href="notifications.php">Voir toutes les notifications</a>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($pending_validation > 0): ?>
                        <a href="validation.php" class="btn btn-primary">
                            <i class="fas fa-check-circle"></i>
                            Valider EDT
                            <span class="badge"><?php echo $pending_validation; ?></span>
                        </a>
                    <?php endif; ?>
                    
                    <button class="menu-toggle" id="menuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                </div>
            </header>
            
            <div class="students-container">
                <!-- Statistiques rapides -->
                <div class="quick-stats">
                    <div class="quick-stat-card">
                        <div class="quick-stat-value"><?php echo number_format($stats['total']); ?></div>
                        <div class="quick-stat-label">Étudiants Total</div>
                    </div>
                    
                    <?php
                    $promos = array_keys($stats['par_promo']);
                    sort($promos);
                    foreach ($promos as $promo_key):
                        if ($promo_key):
                    ?>
                    <div class="quick-stat-card">
                        <div class="quick-stat-value"><?php echo $stats['par_promo'][$promo_key]; ?></div>
                        <div class="quick-stat-label">Promotion <?php echo $promo_key; ?></div>
                    </div>
                    <?php 
                        endif;
                    endforeach; 
                    ?>
                    
                    <div class="quick-stat-card">
                        <div class="quick-stat-value"><?php echo count($formations); ?></div>
                        <div class="quick-stat-label">Formations</div>
                    </div>
                </div>
                
                <!-- Filtres -->
                <div class="filters-section">
                    <form method="GET" action="" id="filterForm">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" 
                                   name="search" 
                                   value="<?php echo htmlspecialchars($search); ?>"
                                   placeholder="Rechercher un étudiant (nom, prénom, email)...">
                        </div>
                        
                        <div class="filters-grid">
                            <div class="filter-group">
                                <label for="formation_id">
                                    <i class="fas fa-graduation-cap"></i> Formation
                                </label>
                                <select id="formation_id" name="formation_id">
                                    <option value="">Toutes les formations</option>
                                    <?php foreach ($formations as $formation): ?>
                                        <option value="<?php echo $formation['id']; ?>" 
                                                <?php echo $formation_id == $formation['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($formation['nom']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label for="promo">
                                    <i class="fas fa-calendar-alt"></i> Promotion
                                </label>
                                <select id="promo" name="promo">
                                    <option value="">Toutes les promotions</option>
                                    <?php for ($i = 1; $i <= 6; $i++): ?>
                                        <option value="<?php echo $i; ?>" 
                                                <?php echo $promo == $i ? 'selected' : ''; ?>>
                                            Promotion <?php echo $i; ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label for="groupe">
                                    <i class="fas fa-layer-group"></i> Groupe
                                </label>
                                <select id="groupe" name="groupe">
                                    <option value="">Tous les groupes</option>
                                    <?php foreach ($groupes as $g): ?>
                                        <option value="<?php echo $g['num_groupe']; ?>" 
                                                <?php echo $groupe == $g['num_groupe'] ? 'selected' : ''; ?>>
                                            Groupe <?php echo $g['num_groupe']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary" style="padding: 0.75rem 1.5rem;">
                                <i class="fas fa-filter"></i> Appliquer les filtres
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="resetFilters()">
                                <i class="fas fa-redo"></i> Réinitialiser
                            </button>
                            <button type="button" class="btn btn-success" onclick="exportStudents()">
                                <i class="fas fa-download"></i> Exporter
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Toggle vue -->
                <div class="view-toggle">
                    <button class="view-btn active" onclick="showGridView()">
                        <i class="fas fa-th-large"></i> Grille
                    </button>
                    <button class="view-btn" onclick="showTableView()">
                        <i class="fas fa-table"></i> Tableau
                    </button>
                </div>
                
                <!-- Vue grille (par défaut) -->
                <div id="gridView">
                    <div class="students-grid">
                        <?php if (empty($etudiants)): ?>
                            <div style="grid-column: 1 / -1; text-align: center; padding: 3rem;">
                                <i class="fas fa-user-graduate fa-3x" style="color: var(--gray-300); margin-bottom: 1rem;"></i>
                                <h3 style="color: var(--gray-700); margin-bottom: 0.5rem;">Aucun étudiant trouvé</h3>
                                <p style="color: var(--gray-600);">Aucun étudiant ne correspond à vos critères de recherche.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($etudiants as $etudiant): ?>
                                <div class="student-card">
                                    <div class="student-header">
                                        <div class="student-avatar">
                                            <?php echo strtoupper(substr($etudiant['prenom'], 0, 1) . substr($etudiant['nom'], 0, 1)); ?>
                                        </div>
                                        <div class="student-info">
                                            <div class="student-name">
                                                <?php echo htmlspecialchars($etudiant['prenom'] . ' ' . $etudiant['nom']); ?>
                                            </div>
                                            <div class="student-formation">
                                                <?php echo htmlspecialchars($etudiant['formation_nom']); ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="student-details">
                                        <div class="detail-item">
                                            <span class="detail-value"><?php echo $etudiant['promo']; ?></span>
                                            <span class="detail-label">Promotion</span>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <span class="detail-value">
                                                <?php echo $etudiant['num_groupe'] ?: '--'; ?>
                                            </span>
                                            <span class="detail-label">Groupe</span>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <span class="detail-value"><?php echo $etudiant['nb_examens']; ?></span>
                                            <span class="detail-label">Examens</span>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <span class="detail-value"><?php echo $etudiant['nb_modules']; ?></span>
                                            <span class="detail-label">Modules</span>
                                        </div>
                                    </div>
                                    
                                    <div class="student-actions">
                                        <a href="student_details.php?id=<?php echo $etudiant['id']; ?>" 
                                           class="btn-student primary">
                                            <i class="fas fa-eye"></i> Profil
                                        </a>
                                        <a href="mailto:<?php echo htmlspecialchars($etudiant['email']); ?>" 
                                           class="btn-student success">
                                            <i class="fas fa-envelope"></i> Email
                                        </a>
                                        <a href="student_schedule.php?id=<?php echo $etudiant['id']; ?>" 
                                           class="btn-student info">
                                            <i class="fas fa-calendar-alt"></i> Planning
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Vue tableau (cachée par défaut) -->
                <div class="students-table-container" id="tableView">
                    <div class="table-responsive">
                        <table class="students-table">
                            <thead>
                                <tr>
                                    <th>Étudiant</th>
                                    <th>Formation</th>
                                    <th>Promotion</th>
                                    <th>Groupe</th>
                                    <th>Email</th>
                                    <th>Examens</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($etudiants)): ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center; padding: 2rem; color: var(--gray-500);">
                                            Aucun étudiant trouvé
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($etudiants as $etudiant): ?>
                                        <tr>
                                            <td>
                                                <div style="font-weight: 700; color: var(--gray-900);">
                                                    <?php echo htmlspecialchars($etudiant['prenom'] . ' ' . $etudiant['nom']); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($etudiant['formation_nom']); ?>
                                            </td>
                                            <td>
                                                <span style="padding: 0.25rem 0.75rem; background: rgba(67, 97, 238, 0.1); 
                                                      color: var(--primary); border-radius: 4px; font-weight: 600;">
                                                    <?php echo $etudiant['promo']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($etudiant['num_groupe']): ?>
                                                    <span style="padding: 0.25rem 0.75rem; background: rgba(76, 201, 240, 0.1); 
                                                          color: #4cc9f0; border-radius: 4px; font-weight: 600;">
                                                        Groupe <?php echo $etudiant['num_groupe']; ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span style="color: var(--gray-500);">--</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="mailto:<?php echo htmlspecialchars($etudiant['email']); ?>" 
                                                   style="color: var(--primary); text-decoration: none;">
                                                    <?php echo htmlspecialchars($etudiant['email']); ?>
                                                </a>
                                            </td>
                                            <td>
                                                <?php echo $etudiant['nb_examens']; ?>
                                            </td>
                                            <td>
                                                <div style="display: flex; gap: 0.5rem;">
                                                    <a href="student_details.php?id=<?php echo $etudiant['id']; ?>" 
                                                       class="btn-student primary" style="padding: 0.35rem 0.75rem;">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="student_schedule.php?id=<?php echo $etudiant['id']; ?>" 
                                                       class="btn-student info" style="padding: 0.35rem 0.75rem;">
                                                        <i class="fas fa-calendar-alt"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Pagination -->
                <?php if (!empty($etudiants) && count($etudiants) > 30): ?>
                    <div class="pagination">
                        <button class="page-btn">Précédent</button>
                        <button class="page-btn active">1</button>
                        <button class="page-btn">2</button>
                        <button class="page-btn">3</button>
                        <button class="page-btn">Suivant</button>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <script>
        // Menu Toggle
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
        });
        
        // Basculer entre vue grille et tableau
        function showGridView() {
            document.getElementById('gridView').style.display = 'block';
            document.getElementById('tableView').style.display = 'none';
            
            // Mettre à jour les boutons
            document.querySelectorAll('.view-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.view-btn')[0].classList.add('active');
        }
        
        function showTableView() {
            document.getElementById('gridView').style.display = 'none';
            document.getElementById('tableView').style.display = 'block';
            
            // Mettre à jour les boutons
            document.querySelectorAll('.view-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.view-btn')[1].classList.add('active');
        }
        
        // Réinitialiser les filtres
        function resetFilters() {
            document.getElementById('formation_id').value = '';
            document.getElementById('promo').value = '';
            document.getElementById('groupe').value = '';
            document.querySelector('input[name="search"]').value = '';
            document.getElementById('filterForm').submit();
        }
        
        // Exporter les étudiants
        function exportStudents() {
            const formation = document.getElementById('formation_id').value;
            const promo = document.getElementById('promo').value;
            const groupe = document.getElementById('groupe').value;
            const search = document.querySelector('input[name="search"]').value;
            
            let url = 'export_students.php?';
            if (formation) url += 'formation_id=' + formation + '&';
            if (promo) url += 'promo=' + promo + '&';
            if (groupe) url += 'groupe=' + groupe + '&';
            if (search) url += 'search=' + encodeURIComponent(search);
            
            window.location.href = url;
        }
        
        // Initialiser en mode grille
        document.addEventListener('DOMContentLoaded', function() {
            showGridView();
        });
        
        // Notifications functions (identique au premier code)
        function toggleNotifications() {
            const list = document.getElementById('notificationList');
            list.classList.toggle('show');
        }
        
        function markNotificationRead(notificationId) {
            fetch('../includes/mark_notification_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ id: notificationId })
            });
        }
        
        function markAllNotificationsRead() {
            fetch('../includes/mark_all_notifications_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ 
                    user_id: <?php echo $user['id']; ?>, 
                    user_type: 'prof' 
                })
            }).then(() => {
                location.reload();
            });
        }
        
        // Close notifications when clicking outside
        document.addEventListener('click', function(event) {
            const notificationList = document.getElementById('notificationList');
            const notificationBadge = document.querySelector('.notification-badge');
            
            if (notificationList && notificationBadge && 
                !notificationList.contains(event.target) && 
                !notificationBadge.contains(event.target)) {
                notificationList.classList.remove('show');
            }
        });
    </script>
</body>
</html>