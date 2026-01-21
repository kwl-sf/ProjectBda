<?php
// chef_dept/professors_list.php
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
$specialite = $_GET['specialite'] ?? '';
$search = $_GET['search'] ?? '';
$role = $_GET['role'] ?? '';

// Récupérer les professeurs du département
$sql = "SELECT p.*, 
               COUNT(DISTINCT e.id) as nb_examens,
               COUNT(DISTINCT m.id) as nb_modules,
               COUNT(DISTINCT s.examen_id) as nb_surveillances
        FROM professeurs p
        LEFT JOIN examens e ON p.id = e.prof_id
        LEFT JOIN modules m ON p.id = m.prof_id
        LEFT JOIN surveillants s ON p.id = s.prof_id
        WHERE p.dept_id = ? ";

$params = [$dept_id];

if ($specialite) {
    $sql .= " AND p.specialite LIKE ?";
    $params[] = "%$specialite%";
}

if ($role && $role !== 'all') {
    $sql .= " AND p.role = ?";
    $params[] = $role;
}

if ($search) {
    $sql .= " AND (p.nom LIKE ? OR p.prenom LIKE ? OR p.email LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$sql .= " GROUP BY p.id
          ORDER BY p.role DESC, p.nom, p.prenom";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$professeurs = $stmt->fetchAll();

// Récupérer les spécialités uniques
$sql_specialites = "SELECT DISTINCT specialite 
                    FROM professeurs 
                    WHERE dept_id = ? AND specialite IS NOT NULL AND specialite != ''
                    ORDER BY specialite";
$stmt_specialites = $pdo->prepare($sql_specialites);
$stmt_specialites->execute([$dept_id]);
$specialites = $stmt_specialites->fetchAll();

// Statistiques
$stats = [
    'total' => count($professeurs),
    'par_role' => [],
    'nb_chefs' => 0,
    'nb_profs' => 0
];

foreach ($professeurs as $prof) {
    if ($prof['role'] === 'chef_dept') {
        $stats['nb_chefs']++;
    } else {
        $stats['nb_profs']++;
    }
    
    $role_key = $prof['role'];
    if (!isset($stats['par_role'][$role_key])) {
        $stats['par_role'][$role_key] = 0;
    }
    $stats['par_role'][$role_key]++;
}

$page_title = "Liste des Professeurs - " . htmlspecialchars($dept['nom']);
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
        .professors-container {
            max-width: 1600px;
            margin: 0 auto;
        }
        
        /* Statistiques rapides */
        .prof-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .prof-stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .prof-stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
        }
        
        .prof-stat-card.total::before { background: linear-gradient(90deg, #4361ee, #4cc9f0); }
        .prof-stat-card.chefs::before { background: linear-gradient(90deg, #9b59b6, #8e44ad); }
        .prof-stat-card.profs::before { background: linear-gradient(90deg, #2ecc71, #27ae60); }
        .prof-stat-card.exams::before { background: linear-gradient(90deg, #e74c3c, #c0392b); }
        
        .prof-stat-icon {
            font-size: 2rem;
            margin-bottom: 1rem;
        }
        
        .prof-stat-card.total .prof-stat-icon { color: #4361ee; }
        .prof-stat-card.chefs .prof-stat-icon { color: #9b59b6; }
        .prof-stat-card.profs .prof-stat-icon { color: #2ecc71; }
        .prof-stat-card.exams .prof-stat-icon { color: #e74c3c; }
        
        .prof-stat-value {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            color: var(--gray-900);
        }
        
        .prof-stat-label {
            font-size: 0.9rem;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.5px;
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
        
        /* Cartes de professeurs */
        .professors-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }
        
        .professor-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .professor-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .professor-card.chef_dept::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, #9b59b6, #8e44ad);
        }
        
        .professor-card.prof::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, #2ecc71, #27ae60);
        }
        
        .professor-card.admin::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, #3498db, #2980b9);
        }
        
        .professor-header {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .professor-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
            font-weight: 600;
        }
        
        .professor-card.chef_dept .professor-avatar {
            background: linear-gradient(135deg, #9b59b6, #8e44ad);
        }
        
        .professor-card.prof .professor-avatar {
            background: linear-gradient(135deg, #2ecc71, #27ae60);
        }
        
        .professor-card.admin .professor-avatar {
            background: linear-gradient(135deg, #3498db, #2980b9);
        }
        
        .professor-info {
            flex: 1;
        }
        
        .professor-name {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.25rem;
        }
        
        .professor-role {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .professor-card.chef_dept .professor-role {
            background: rgba(155, 89, 182, 0.1);
            color: #9b59b6;
        }
        
        .professor-card.prof .professor-role {
            background: rgba(46, 204, 113, 0.1);
            color: #2ecc71;
        }
        
        .professor-card.admin .professor-role {
            background: rgba(52, 152, 219, 0.1);
            color: #3498db;
        }
        
        .professor-specialite {
            font-size: 0.9rem;
            color: var(--gray-600);
        }
        
        .professor-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .stat-item {
            text-align: center;
            padding: 0.75rem;
            background: var(--gray-50);
            border-radius: var(--border-radius-sm);
        }
        
        .stat-value {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--gray-900);
            display: block;
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            font-size: 0.85rem;
            color: var(--gray-600);
        }
        
        .professor-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        
        .btn-professor {
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
        
        .btn-professor.primary {
            background: rgba(67, 97, 238, 0.1);
            color: var(--primary);
        }
        
        .btn-professor.success {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success);
        }
        
        .btn-professor.info {
            background: rgba(52, 152, 219, 0.1);
            color: var(--info);
        }
        
        .btn-professor:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
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
            .prof-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .professors-grid {
                grid-template-columns: 1fr;
            }
            
            .professor-header {
                flex-direction: column;
                text-align: center;
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
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
                <a href="Students_list.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-users"></i></span>
                    <span>Students list </span>
                </a>
                <a href="professors_list.php" class="nav-item active">
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
                    <h1><i class="fas fa-chalkboard-teacher"></i> Liste des Professeurs</h1>
                    <p>Gestion des professeurs du département <?php echo htmlspecialchars($departement['nom'] ?? 'Département'); ?></p>
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
            
            <div class="professors-container">
                <!-- Statistiques -->
                <div class="prof-stats">
                    <div class="prof-stat-card total">
                        <div class="prof-stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="prof-stat-value"><?php echo $stats['total']; ?></div>
                        <div class="prof-stat-label">Total Professeurs</div>
                    </div>
                    
                    <div class="prof-stat-card chefs">
                        <div class="prof-stat-icon">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <div class="prof-stat-value"><?php echo $stats['nb_chefs']; ?></div>
                        <div class="prof-stat-label">Chefs de Département</div>
                    </div>
                    
                    <div class="prof-stat-card profs">
                        <div class="prof-stat-icon">
                            <i class="fas fa-chalkboard-teacher"></i>
                        </div>
                        <div class="prof-stat-value"><?php echo $stats['nb_profs']; ?></div>
                        <div class="prof-stat-label">Professeurs</div>
                    </div>
                    
                    <div class="prof-stat-card exams">
                        <div class="prof-stat-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div class="prof-stat-value">
                            <?php
                            $total_examens = 0;
                            foreach ($professeurs as $prof) {
                                $total_examens += $prof['nb_examens'];
                            }
                            echo $total_examens;
                            ?>
                        </div>
                        <div class="prof-stat-label">Examens Planifiés</div>
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
                                   placeholder="Rechercher un professeur (nom, prénom, email)...">
                        </div>
                        
                        <div class="filters-grid">
                            <div class="filter-group">
                                <label for="specialite">
                                    <i class="fas fa-graduation-cap"></i> Spécialité
                                </label>
                                <select id="specialite" name="specialite">
                                    <option value="">Toutes les spécialités</option>
                                    <?php foreach ($specialites as $spec): ?>
                                        <?php if ($spec['specialite']): ?>
                                        <option value="<?php echo htmlspecialchars($spec['specialite']); ?>" 
                                                <?php echo $specialite == $spec['specialite'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($spec['specialite']); ?>
                                        </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label for="role">
                                    <i class="fas fa-user-tag"></i> Rôle
                                </label>
                                <select id="role" name="role">
                                    <option value="all">Tous les rôles</option>
                                    <option value="chef_dept" <?php echo $role === 'chef_dept' ? 'selected' : ''; ?>>Chef de Département</option>
                                    <option value="prof" <?php echo $role === 'prof' ? 'selected' : ''; ?>>Professeur</option>
                                    <option value="admin" <?php echo $role === 'admin' ? 'selected' : ''; ?>>Administrateur</option>
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
                            <button type="button" class="btn btn-success" onclick="exportProfessors()">
                                <i class="fas fa-download"></i> Exporter
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Liste des professeurs -->
                <div class="professors-grid">
                    <?php if (empty($professeurs)): ?>
                        <div style="grid-column: 1 / -1; text-align: center; padding: 3rem;">
                            <i class="fas fa-user-tie fa-3x" style="color: var(--gray-300); margin-bottom: 1rem;"></i>
                            <h3 style="color: var(--gray-700); margin-bottom: 0.5rem;">Aucun professeur trouvé</h3>
                            <p style="color: var(--gray-600);">Aucun professeur ne correspond à vos critères de recherche.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($professeurs as $prof): ?>
                            <div class="professor-card <?php echo $prof['role']; ?>">
                                <div class="professor-header">
                                    <div class="professor-avatar">
                                        <?php echo strtoupper(substr($prof['prenom'], 0, 1) . substr($prof['nom'], 0, 1)); ?>
                                    </div>
                                    <div class="professor-info">
                                        <div class="professor-name">
                                            <?php echo htmlspecialchars($prof['prenom'] . ' ' . $prof['nom']); ?>
                                        </div>
                                        <div class="professor-role">
                                            <?php 
                                            $roles_display = [
                                                'chef_dept' => 'Chef de Département',
                                                'prof' => 'Professeur',
                                                'admin' => 'Administrateur'
                                            ];
                                            echo $roles_display[$prof['role']] ?? $prof['role'];
                                            ?>
                                        </div>
                                        <?php if ($prof['specialite']): ?>
                                        <div class="professor-specialite">
                                            <i class="fas fa-graduation-cap"></i>
                                            <?php echo htmlspecialchars($prof['specialite']); ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="professor-stats">
                                    <div class="stat-item">
                                        <span class="stat-value"><?php echo $prof['nb_modules']; ?></span>
                                        <span class="stat-label">Modules</span>
                                    </div>
                                    
                                    <div class="stat-item">
                                        <span class="stat-value"><?php echo $prof['nb_examens']; ?></span>
                                        <span class="stat-label">Examens</span>
                                    </div>
                                    
                                    <div class="stat-item">
                                        <span class="stat-value"><?php echo $prof['nb_surveillances']; ?></span>
                                        <span class="stat-label">Surveillances</span>
                                    </div>
                                    
                                    <div class="stat-item">
                                        <span class="stat-value"><?php echo $prof['max_examens_par_jour']; ?></span>
                                        <span class="stat-label">Max/jour</span>
                                    </div>
                                </div>
                                
                                <div class="professor-actions">
                                    <a href="professor_details.php?id=<?php echo $prof['id']; ?>" 
                                       class="btn-professor primary">
                                        <i class="fas fa-eye"></i> Profil
                                    </a>
                                    <a href="mailto:<?php echo htmlspecialchars($prof['email']); ?>" 
                                       class="btn-professor success">
                                        <i class="fas fa-envelope"></i> Contacter
                                    </a>
                                    <a href="professor_schedule.php?id=<?php echo $prof['id']; ?>" 
                                       class="btn-professor info">
                                        <i class="fas fa-calendar-alt"></i> Planning
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        // Menu Toggle
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
        });
        
        // Réinitialiser les filtres
        function resetFilters() {
            document.getElementById('specialite').value = '';
            document.getElementById('role').value = 'all';
            document.querySelector('input[name="search"]').value = '';
            document.getElementById('filterForm').submit();
        }
        
        // Exporter les professeurs
        function exportProfessors() {
            const specialite = document.getElementById('specialite').value;
            const role = document.getElementById('role').value;
            const search = document.querySelector('input[name="search"]').value;
            
            let url = 'export_professors.php?';
            if (specialite) url += 'specialite=' + encodeURIComponent(specialite) + '&';
            if (role && role !== 'all') url += 'role=' + role + '&';
            if (search) url += 'search=' + encodeURIComponent(search);
            
            window.location.href = url;
        }
        
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