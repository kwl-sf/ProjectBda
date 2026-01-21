<?php
// admin/dashboard.php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Vérifier que l'utilisateur est admin
require_role(['admin']);

// Récupérer l'utilisateur connecté - Utiliser la nouvelle fonction
$user = get_logged_in_user();

// Récupérer les statistiques
$stats = [];

try {
    // Nombre total d'étudiants
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM etudiants");
    $stats['etudiants'] = $stmt->fetch()['total'];
    
    // Nombre total de professeurs
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM professeurs");
    $stats['professeurs'] = $stmt->fetch()['total'];
    
    // Nombre total de salles
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM lieu_examen WHERE disponible = 1");
    $stats['salles'] = $stmt->fetch()['total'];
    
    // Nombre d'examens planifiés
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM examens WHERE statut = 'confirme'");
    $stats['examens'] = $stmt->fetch()['total'];
    
    // Nombre d'examens en attente de validation
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM examens WHERE statut = 'en_attente_validation'");
    $stats['examens_attente'] = $stmt->fetch()['total'];
    
    // Récupérer les conflits non résolus
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM conflits WHERE statut = 'detecte'");
    $stats['conflits'] = $stmt->fetch()['total'];
    
} catch (PDOException $e) {
    // إضافة رسالة خطأ بدلاً من توقف التطبيق
    error_log("Erreur SQL dans dashboard: " . $e->getMessage());
    $stats = [
        'etudiants' => 0,
        'professeurs' => 0,
        'salles' => 0,
        'examens' => 0,
        'examens_attente' => 0,
        'conflits' => 0
    ];
}

try {
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
    
} catch (PDOException $e) {
    error_log("Erreur notifications: " . $e->getMessage());
    $notification_count = 0;
    $notifications = [];
}

try {
    // Récupérer les validations récentes des chefs de département
    $stmt = $pdo->query("
        SELECT ecd.*, d.nom as departement_nom, 
               p.nom as chef_nom, p.prenom as chef_prenom,
               p2.nom as admin_nom, p2.prenom as admin_prenom
        FROM envois_chefs_departement ecd
        JOIN departements d ON ecd.departement_id = d.id
        JOIN professeurs p ON ecd.chef_dept_id = p.id
        JOIN professeurs p2 ON ecd.admin_id = p2.id
        WHERE ecd.statut IN ('valide', 'rejete')
        ORDER BY ecd.date_validation DESC 
        LIMIT 5
    ");
    $validations_recentes = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Erreur validations récentes: " . $e->getMessage());
    $validations_recentes = [];
}

try {
    // Récupérer l'activité récente
    $stmt = $pdo->query("SELECT * FROM logs_activite ORDER BY created_at DESC LIMIT 10");
    $activites = $stmt->fetchAll();
    
    // Récupérer les examens à venir
    $stmt = $pdo->query("SELECT e.*, m.nom as module_nom, l.nom as salle_nom 
                         FROM examens e 
                         JOIN modules m ON e.module_id = m.id 
                         JOIN lieu_examen l ON e.salle_id = l.id 
                         WHERE e.date_heure >= NOW() 
                         ORDER BY e.date_heure ASC 
                         LIMIT 5");
    $examens_prochains = $stmt->fetchAll();
    
    // Récupérer l'état des examens
    $stmt = $pdo->query("SELECT statut, COUNT(*) as count FROM examens GROUP BY statut");
    $etat_examens = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Erreur données générales: " . $e->getMessage());
    $activites = [];
    $examens_prochains = [];
    $etat_examens = [];
}

// Titre de la page
$page_title = "Tableau de Bord Administrateur";
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
        .welcome-banner {
            background: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%);
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .welcome-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 20px 20px;
            animation: float 20s linear infinite;
        }
        
        .welcome-banner h2 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 1;
        }
        
        .welcome-banner p {
            opacity: 0.9;
            font-size: 1.1rem;
            position: relative;
            z-index: 1;
        }
        
        .performance-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(255,255,255,0.2);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            margin-top: 1rem;
            position: relative;
            z-index: 1;
            backdrop-filter: blur(10px);
        }
        
        .performance-badge i {
            color: #ffd166;
            animation: pulse 2s infinite;
        }
        
        .dashboard-content {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }
        
        /* Notifications */
        .notification-dropdown {
            position: relative;
        }
        
        .notification-list {
            position: absolute;
            top: 100%;
            right: 0;
            width: 400px;
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
        
        /* Validation Status */
        .validation-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            font-weight: 600;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            margin-top: 0.5rem;
        }
        
        .validation-status.valide {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success);
        }
        
        .validation-status.rejete {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
        }
        
        @media (max-width: 1024px) {
            .dashboard-content {
                grid-template-columns: 1fr;
            }
            
            .notification-list {
                position: fixed;
                top: 70px;
                right: 20px;
                left: 20px;
                width: auto;
            }
        }
        
        @keyframes float {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-graduation-cap"></i> PlanExam Pro</h2>
                <p>Administration des Examens</p>
            </div>
            
            <div class="user-info">
                <div class="user-avatar">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div class="user-name"><?php echo htmlspecialchars($user['full_name'] ?? 'Administrateur'); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($user['role_fr'] ?? 'Administrateur'); ?></div>
            </div>
            
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-item active">
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
                <a href="conflicts.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-exclamation-triangle"></i></span>
                    <span>Conflits <span class="notification-count"><?php echo $stats['conflits']; ?></span></span>
                </a>
                <a href="GererUser.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-users"></i></span>
                    <span>Gérer les Utilisateurs</span>
                </a>
                <a href="Statistique.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                    <span>Statistique</span>
                </a>
                <a href="settings.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-cog"></i></span>
                    <span>Paramètres</span>
                </a>
                <a href="../logout.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                    <span>Déconnexion</span>
                </a>
            </nav>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="header">
                <div class="header-left">
                    <h1>Tableau de Bord Administrateur</h1>
                    <p>Bienvenue dans le centre de contrôle des examens</p>
                </div>
                <div class="header-actions">
                    <div class="performance-badge">
                        <i class="fas fa-bolt"></i>
                        <span>Système opérationnel à 100%</span>
                    </div>
                    
                    <!-- Notifications Dropdown -->
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
                                                    'message' => 'fas fa-envelope',
                                                    'system' => 'fas fa-cog'
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
                    
                    <button class="menu-toggle" id="menuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                </div>
            </header>
            
            <!-- Welcome Banner -->
            <div class="welcome-banner animate__animated animate__fadeIn">
                <h2>👋 Bonjour, <?php echo htmlspecialchars($user['prenom'] ?? 'Admin'); ?> !</h2>
                <p>Prêt à optimiser la planification des examens pour 13,000 étudiants ?</p>
                <div class="performance-badge">
                    <i class="fas fa-rocket"></i>
                    <span>Performance: Génération en 38 secondes</span>
                </div>
            </div>
            
            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card primary animate__animated animate__fadeInUp">
                    <div class="stat-header">
                        <div>
                            <div class="stat-title">Étudiants</div>
                            <div class="stat-value"><?php echo number_format($stats['etudiants']); ?></div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                    </div>
                    <div class="stat-change positive">
                        <i class="fas fa-arrow-up"></i>
                        <span>+5% ce mois</span>
                    </div>
                </div>
                
                <div class="stat-card success animate__animated animate__fadeInUp" style="animation-delay: 0.1s;">
                    <div class="stat-header">
                        <div>
                            <div class="stat-title">Professeurs</div>
                            <div class="stat-value"><?php echo number_format($stats['professeurs']); ?></div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-chalkboard-teacher"></i>
                        </div>
                    </div>
                    <div class="stat-change positive">
                        <i class="fas fa-arrow-up"></i>
                        <span>+2% ce mois</span>
                    </div>
                </div>
                
                <div class="stat-card warning animate__animated animate__fadeInUp" style="animation-delay: 0.2s;">
                    <div class="stat-header">
                        <div>
                            <div class="stat-title">Salles Disponibles</div>
                            <div class="stat-value"><?php echo $stats['salles']; ?></div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-building"></i>
                        </div>
                    </div>
                    <div class="stat-change positive">
                        <i class="fas fa-check-circle"></i>
                        <span>Toutes opérationnelles</span>
                    </div>
                </div>
                
                <div class="stat-card danger animate__animated animate__fadeInUp" style="animation-delay: 0.3s;">
                    <div class="stat-header">
                        <div>
                            <div class="stat-title">Validation en attente</div>
                            <div class="stat-value"><?php echo $stats['examens_attente']; ?></div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-hourglass-half"></i>
                        </div>
                    </div>
                    <div class="stat-change <?php echo $stats['examens_attente'] > 0 ? 'warning' : 'positive'; ?>">
                        <i class="fas <?php echo $stats['examens_attente'] > 0 ? 'fa-clock' : 'fa-check-circle'; ?>"></i>
                        <span><?php echo $stats['examens_attente'] > 0 ? 'À valider' : 'Tous validés'; ?></span>
                    </div>
                </div>
            </div>
            
            <div class="dashboard-content">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Quick Actions -->
                    <div class="quick-actions">
                        <h2 class="section-title">
                            <i class="fas fa-bolt"></i>
                            Actions Rapides
                        </h2>
                        <div class="actions-grid">
                            <a href="generate_schedule.php" class="action-card animate__animated animate__fadeIn">
                                <div class="action-icon">
                                    <i class="fas fa-calendar-plus"></i>
                                </div>
                                <h3 class="action-title">Générer EDT</h3>
                                <p class="action-desc">Lancer l'algorithme de planification automatique</p>
                            </a>
                            
                            <a href="manage_rooms.php" class="action-card animate__animated animate__fadeIn" style="animation-delay: 0.1s;">
                                <div class="action-icon">
                                    <i class="fas fa-building"></i>
                                </div>
                                <h3 class="action-title">Gérer Salles</h3>
                                <p class="action-desc">Configurer les salles et amphis disponibles</p>
                            </a>
                            
                            <a href="conflicts.php" class="action-card animate__animated animate__fadeIn" style="animation-delay: 0.2s;">
                                <div class="action-icon">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </div>
                                <h3 class="action-title">Résoudre Conflits</h3>
                                <p class="action-desc">Examiner et résoudre les conflits détectés</p>
                            </a>
                            
                            <a href="reports.php" class="action-card animate__animated animate__fadeIn" style="animation-delay: 0.3s;">
                                <div class="action-icon">
                                    <i class="fas fa-file-export"></i>
                                </div>
                                <h3 class="action-title">Exporter Rapports</h3>
                                <p class="action-desc">Générer des rapports détaillés au format PDF/Excel</p>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Validations Récentes -->
                    <?php if (!empty($validations_recentes)): ?>
                    <div class="recent-activity animate__animated animate__fadeIn">
                        <h2 class="section-title">
                            <i class="fas fa-check-double"></i>
                            Validations Récentes des Chefs de Département
                        </h2>
                        <div class="activity-list">
                            <?php foreach ($validations_recentes as $validation): ?>
                                <div class="activity-item">
                                    <div class="activity-icon <?php echo $validation['statut']; ?>">
                                        <i class="fas fa-<?php echo $validation['statut'] === 'valide' ? 'check' : 'times'; ?>-circle"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-text">
                                            <strong><?php echo htmlspecialchars($validation['departement_nom']); ?></strong>
                                            <div class="validation-status <?php echo $validation['statut']; ?>">
                                                <i class="fas fa-<?php echo $validation['statut'] === 'valide' ? 'check' : 'times'; ?>"></i>
                                                <?php echo ucfirst($validation['statut']); ?> par <?php echo htmlspecialchars($validation['chef_prenom'] . ' ' . $validation['chef_nom']); ?>
                                            </div>
                                        </div>
                                        <div class="activity-time">
                                            <i class="far fa-clock"></i>
                                            <?php echo format_date_fr($validation['date_validation'] ?? $validation['created_at'], true); ?>
                                        </div>
                                        <?php if (!empty($validation['commentaires_validation'])): ?>
                                            <div class="activity-comment">
                                                <small>"<?php echo truncate_text(htmlspecialchars($validation['commentaires_validation']), 60); ?>"</small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Right Column -->
                <div class="right-column">
                    <!-- Exam Status -->
                    <?php if (!empty($etat_examens)): ?>
                    <div class="exam-status animate__animated animate__fadeIn">
                        <h2 class="section-title">
                            <i class="fas fa-chart-pie"></i>
                            État des Examens
                        </h2>
                        <div class="status-bars">
                            <?php 
                            $total_examens = array_sum(array_column($etat_examens, 'count'));
                            foreach ($etat_examens as $etat): 
                                $pourcentage = $total_examens > 0 ? ($etat['count'] / $total_examens * 100) : 0;
                            ?>
                                <div class="status-bar">
                                    <span class="status-label"><?php echo ucfirst($etat['statut']); ?></span>
                                    <div class="status-progress">
                                        <div class="status-fill <?php echo $etat['statut']; ?>" style="width: <?php echo $pourcentage; ?>%"></div>
                                    </div>
                                    <span class="status-count"><?php echo $etat['count']; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Upcoming Exams -->
                    <?php if (!empty($examens_prochains)): ?>
                    <div class="calendar-preview animate__animated animate__fadeIn" style="animation-delay: 0.2s;">
                        <h2 class="section-title">
                            <i class="fas fa-calendar-alt"></i>
                            Examens à Venir
                        </h2>
                        <div class="activity-list">
                            <?php foreach ($examens_prochains as $examen): ?>
                                <div class="activity-item">
                                    <div class="activity-icon success">
                                        <i class="fas fa-file-alt"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-text"><?php echo htmlspecialchars($examen['module_nom']); ?></div>
                                        <div class="activity-time">
                                            <i class="far fa-clock"></i>
                                            <?php echo format_date_fr($examen['date_heure'], true); ?>
                                            <span style="margin-left: 10px; color: var(--primary);">
                                                <i class="fas fa-door-open"></i> <?php echo htmlspecialchars($examen['salle_nom']); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- System Status -->
                    <div class="exam-status animate__animated animate__fadeIn" style="animation-delay: 0.3s;">
                        <h2 class="section-title">
                            <i class="fas fa-server"></i>
                            État du Système
                        </h2>
                        <div class="status-bars">
                            <div class="status-bar">
                                <span class="status-label">Base de Données</span>
                                <div class="status-progress">
                                    <div class="status-fill confirme" style="width: 100%"></div>
                                </div>
                                <span class="status-count">✅</span>
                            </div>
                            <div class="status-bar">
                                <span class="status-label">Serveur Web</span>
                                <div class="status-progress">
                                    <div class="status-fill confirme" style="width: 100%"></div>
                                </div>
                                <span class="status-count">✅</span>
                            </div>
                            <div class="status-bar">
                                <span class="status-label">Algorithme IA</span>
                                <div class="status-progress">
                                    <div class="status-fill confirme" style="width: 98%"></div>
                                </div>
                                <span class="status-count">98%</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        // Menu Toggle for Mobile
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
        });
        
        // Animate progress bars on load
        document.addEventListener('DOMContentLoaded', function() {
            const progressBars = document.querySelectorAll('.status-fill');
            progressBars.forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0';
                setTimeout(() => {
                    bar.style.width = width;
                }, 500);
            });
            
            // Notification badge pulse
            const notificationBadge = document.querySelector('.notification-badge');
            if (notificationBadge.querySelector('.notification-count')) {
                setInterval(() => {
                    notificationBadge.classList.toggle('animate-pulse');
                }, 2000);
            }
        });
        
        // Notifications functions
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
                    user_id: <?php echo $user['id'] ?? 0; ?>, 
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
            
            if (!notificationList.contains(event.target) && !notificationBadge.contains(event.target)) {
                notificationList.classList.remove('show');
            }
        });
    </script>
</body>
</html>