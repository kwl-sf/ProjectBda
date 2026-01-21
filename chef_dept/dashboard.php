<?php
// chef_dept/dashboard.php
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

// Statistiques du département
$stats = [];

// Nombre d'étudiants dans le département
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM etudiants e 
                       JOIN formations f ON e.formation_id = f.id 
                       WHERE f.dept_id = ?");
$stmt->execute([$dept_id]);
$stats['etudiants'] = $stmt->fetch()['total'];

// Nombre de professeurs dans le département
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM professeurs WHERE dept_id = ?");
$stmt->execute([$dept_id]);
$stats['professeurs'] = $stmt->fetch()['total'];

// Nombre de formations dans le département
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM formations WHERE dept_id = ?");
$stmt->execute([$dept_id]);
$stats['formations'] = $stmt->fetch()['total'];

// Nombre d'examens du département
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM examens e 
                       JOIN modules m ON e.module_id = m.id 
                       JOIN formations f ON m.formation_id = f.id 
                       WHERE f.dept_id = ?");
$stmt->execute([$dept_id]);
$stats['examens'] = $stmt->fetch()['total'];

// Conflits non résolus du département
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM conflits c
                       JOIN examens e ON c.entite1_id = e.id
                       JOIN modules m ON e.module_id = m.id
                       JOIN formations f ON m.formation_id = f.id
                       WHERE f.dept_id = ? AND c.statut = 'detecte'");
$stmt->execute([$dept_id]);
$stats['conflits'] = $stmt->fetch()['total'];

// Examens à venir du département (les 5 prochains)
$stmt = $pdo->prepare("SELECT e.*, m.nom as module_nom, l.nom as salle_nom, 
                       p.nom as prof_nom, p.prenom as prof_prenom
                       FROM examens e 
                       JOIN modules m ON e.module_id = m.id 
                       JOIN formations f ON m.formation_id = f.id
                       JOIN lieu_examen l ON e.salle_id = l.id 
                       JOIN professeurs p ON e.prof_id = p.id
                       WHERE f.dept_id = ? AND e.date_heure >= NOW() 
                       AND e.statut = 'confirme'
                       ORDER BY e.date_heure ASC 
                       LIMIT 5");
$stmt->execute([$dept_id]);
$examens_prochains = $stmt->fetchAll();

// Derniers examens créés dans le département
$stmt = $pdo->prepare("SELECT e.*, m.nom as module_nom, p.nom as prof_nom, 
                       p.prenom as prof_prenom, l.nom as salle_nom, f.nom as formation_nom
                       FROM examens e 
                       JOIN modules m ON e.module_id = m.id 
                       JOIN professeurs p ON e.prof_id = p.id
                       JOIN formations f ON m.formation_id = f.id
                       JOIN lieu_examen l ON e.salle_id = l.id 
                       WHERE f.dept_id = ? 
                       ORDER BY e.created_at DESC 
                       LIMIT 5");
$stmt->execute([$dept_id]);
$derniers_examens = $stmt->fetchAll();

// Récupérer les formations du département
$stmt = $pdo->prepare("SELECT id, nom, nb_modules FROM formations WHERE dept_id = ?");
$stmt->execute([$dept_id]);
$formations = $stmt->fetchAll();

// Récupérer les examens par statut pour le département
$stmt = $pdo->prepare("SELECT e.statut, COUNT(*) as count 
                       FROM examens e 
                       JOIN modules m ON e.module_id = m.id 
                       JOIN formations f ON m.formation_id = f.id 
                       WHERE f.dept_id = ? 
                       GROUP BY e.statut");
$stmt->execute([$dept_id]);
$etat_examens = $stmt->fetchAll();

// Activité récente dans le département
$stmt = $pdo->prepare("SELECT la.* FROM logs_activite la
                       LEFT JOIN professeurs p ON la.utilisateur_id = p.id AND la.utilisateur_type IN ('prof', 'chef_dept')
                       LEFT JOIN etudiants e ON la.utilisateur_id = e.id AND la.utilisateur_type = 'etudiant'
                       LEFT JOIN formations f ON e.formation_id = f.id
                       WHERE (p.dept_id = ? OR f.dept_id = ?)
                       ORDER BY la.created_at DESC 
                       LIMIT 10");
$stmt->execute([$dept_id, $dept_id]);
$activites = $stmt->fetchAll();

// Récupérer les envois en attente
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM envois_chefs_departement 
                       WHERE chef_dept_id = ? 
                       AND departement_id = ?
                       AND statut = 'envoye'");
$stmt->execute([$user['id'], $dept_id]);
$pending_validation = $stmt->fetch()['count'];

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

$page_title = "Tableau de Bord Chef de Département";
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
        .dept-banner {
            background: linear-gradient(135deg, #3a0ca3 0%, #4361ee 100%);
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .dept-banner::before {
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
        
        .dept-banner h2 {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 1;
        }
        
        .dept-banner p {
            opacity: 0.9;
            font-size: 1.1rem;
            position: relative;
            z-index: 1;
        }
        
        .dept-badge {
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
        
        @keyframes float {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .formations-list {
            display: grid;
            gap: 1rem;
        }
        
        .formation-item {
            background: white;
            padding: 1rem;
            border-radius: var(--border-radius-sm);
            border-left: 4px solid var(--primary);
            transition: var(--transition);
        }
        
        .formation-item:hover {
            transform: translateX(5px);
            box-shadow: var(--shadow-md);
        }
        
        .formation-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        
        .formation-name {
            font-weight: 600;
            color: var(--gray-800);
        }
        
        .formation-modules {
            color: var(--gray-600);
            font-size: 0.9rem;
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
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        <!-- Sidebar -->
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
                <a href="dashboard.php" class="nav-item active">
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
                    <span class="nav-icon"><i class="fas fa-cogs"></i></span>
                    <span>Students list </span>
                </a>
                <a href="professors_list.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                    <span>professsors list</span>
                </a>
                <a href="../logout.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                    <span>Déconnexion</span>
                </a>
            </nav>
            
            <div class="sidebar-footer">
                <div class="dept-stats">
                    <div class="stat-mini">
                        <i class="fas fa-user-graduate"></i>
                        <span><?php echo number_format($stats['etudiants']); ?></span>
                    </div>
                    <div class="stat-mini">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <span><?php echo number_format($stats['professeurs']); ?></span>
                    </div>
                    <div class="stat-mini">
                        <i class="fas fa-graduation-cap"></i>
                        <span><?php echo number_format($stats['formations']); ?></span>
                    </div>
                </div>
            </div>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="header">
                <div class="header-left">
                    <h1>Tableau de Bord - <?php echo htmlspecialchars($departement['nom'] ?? 'Département'); ?></h1>
                    <p>Gestion des examens et emplois du temps du département</p>
                </div>
                <div class="header-actions">
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
                                                    'conflit' => 'fas fa-exclamation-triangle'
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
                    
                    <a href="validation.php" class="btn btn-primary">
                        <i class="fas fa-check-circle"></i>
                        Valider EDT
                        <?php if ($pending_validation > 0): ?>
                            <span class="badge"><?php echo $pending_validation; ?></span>
                        <?php endif; ?>
                    </a>
                    
                    <button class="menu-toggle" id="menuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                </div>
            </header>
            
            <!-- Département Banner -->
            <div class="dept-banner animate__animated animate__fadeIn">
                <h2>👨‍🏫 Bienvenue, <?php echo htmlspecialchars($user['prenom']); ?> !</h2>
                <p>Vous gérez le département <strong><?php echo htmlspecialchars($departement['nom']); ?></strong> avec <?php echo number_format($stats['etudiants']); ?> étudiants et <?php echo number_format($stats['professeurs']); ?> professeurs.</p>
                <div class="dept-badge">
                    <i class="fas fa-chart-line"></i>
                    <span>Performance du département: Excellent</span>
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
                        <i class="fas fa-users"></i>
                        <span><?php echo count($formations); ?> formations</span>
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
                        <i class="fas fa-check-circle"></i>
                        <span>Actifs: <?php echo $stats['professeurs']; ?></span>
                    </div>
                </div>
                
                <div class="stat-card warning animate__animated animate__fadeInUp" style="animation-delay: 0.2s;">
                    <div class="stat-header">
                        <div>
                            <div class="stat-title">Examens Planifiés</div>
                            <div class="stat-value"><?php echo number_format($stats['examens']); ?></div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                    </div>
                    <div class="stat-change positive">
                        <i class="fas fa-calendar-check"></i>
                        <span>5 à venir cette semaine</span>
                    </div>
                </div>
                
                <div class="stat-card danger animate__animated animate__fadeInUp" style="animation-delay: 0.3s;">
                    <div class="stat-header">
                        <div>
                            <div class="stat-title">Conflits</div>
                            <div class="stat-value"><?php echo $stats['conflits']; ?></div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                    </div>
                    <div class="stat-change <?php echo $stats['conflits'] > 0 ? 'negative' : 'positive'; ?>">
                        <i class="fas <?php echo $stats['conflits'] > 0 ? 'fa-arrow-up' : 'fa-check-circle'; ?>"></i>
                        <span><?php echo $stats['conflits'] > 0 ? 'À résoudre' : 'Aucun conflit'; ?></span>
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
                            <a href="validation.php" class="action-card animate__animated animate__fadeIn">
                                <div class="action-icon">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <h3 class="action-title">Valider EDT</h3>
                                <p class="action-desc">Examiner et valider l'emploi du temps du département</p>
                                <?php if ($pending_validation > 0): ?>
                                    <div class="pending-badge"><?php echo $pending_validation; ?> en attente</div>
                                <?php endif; ?>
                            </a>
                            
                            <a href="department_schedule.php" class="action-card animate__animated animate__fadeIn" style="animation-delay: 0.1s;">
                                <div class="action-icon">
                                    <i class="fas fa-calendar-alt"></i>
                                </div>
                                <h3 class="action-title">Voir EDT Complet</h3>
                                <p class="action-desc">Consulter l'emploi du temps complet du département</p>
                            </a>
                            
                            <a href="manage_department.php" class="action-card animate__animated animate__fadeIn" style="animation-delay: 0.2s;">
                                <div class="action-icon">
                                    <i class="fas fa-cogs"></i>
                                </div>
                                <h3 class="action-title">Gérer Département</h3>
                                <p class="action-desc">Configurer les formations et paramètres du département</p>
                            </a>
                            
                            <a href="stats.php" class="action-card animate__animated animate__fadeIn" style="animation-delay: 0.3s;">
                                <div class="action-icon">
                                    <i class="fas fa-chart-bar"></i>
                                </div>
                                <h3 class="action-title">Rapports Détaillés</h3>
                                <p class="action-desc">Générer des rapports statistiques du département</p>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Formations du Département -->
                    <div class="recent-activity animate__animated animate__fadeIn">
                        <h2 class="section-title">
                            <i class="fas fa-graduation-cap"></i>
                            Formations du Département
                        </h2>
                        <div class="formations-list">
                            <?php foreach ($formations as $formation): ?>
                                <div class="formation-item">
                                    <div class="formation-header">
                                        <div class="formation-name"><?php echo htmlspecialchars($formation['nom']); ?></div>
                                        <div class="formation-modules">
                                            <i class="fas fa-book"></i>
                                            <?php echo $formation['nb_modules']; ?> modules
                                        </div>
                                    </div>
                                    <div style="font-size: 0.9rem; color: var(--gray-600);">
                                        <i class="fas fa-user-graduate"></i>
                                        <?php 
                                        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM etudiants WHERE formation_id = ?");
                                        $stmt->execute([$formation['id']]);
                                        $count = $stmt->fetch()['count'];
                                        echo $count . ' étudiants';
                                        ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column -->
                <div class="right-column">
                    <!-- État des Examens -->
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
                    
                    <!-- Examens à Venir -->
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
                                        <div class="activity-text">
                                            <strong><?php echo htmlspecialchars($examen['module_nom']); ?></strong><br>
                                            <small>Par: <?php echo htmlspecialchars($examen['prof_prenom'] . ' ' . $examen['prof_nom']); ?></small>
                                        </div>
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
                            <?php if (empty($examens_prochains)): ?>
                                <div class="activity-item">
                                    <div class="activity-content">
                                        <div class="activity-text" style="text-align: center; color: var(--gray-500);">
                                            Aucun examen à venir
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Activité Récente -->
                    <div class="exam-status animate__animated animate__fadeIn" style="animation-delay: 0.3s;">
                        <h2 class="section-title">
                            <i class="fas fa-history"></i>
                            Activité Récente
                        </h2>
                        <div class="activity-list">
                            <?php foreach ($activites as $activite): ?>
                                <div class="activity-item">
                                    <div class="activity-icon <?php echo $activite['utilisateur_type']; ?>">
                                        <?php 
                                        $icons = [
                                            'admin' => 'fas fa-user-shield',
                                            'prof' => 'fas fa-user-tie',
                                            'chef_dept' => 'fas fa-user-tie',
                                            'etudiant' => 'fas fa-user-graduate',
                                            'system' => 'fas fa-robot'
                                        ];
                                        echo '<i class="' . ($icons[$activite['utilisateur_type']] ?? 'fas fa-user') . '"></i>';
                                        ?>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-text"><?php echo truncate_text(htmlspecialchars($activite['action']), 50); ?></div>
                                        <div class="activity-time">
                                            <i class="far fa-clock"></i>
                                            <?php echo format_date_fr($activite['created_at'], true); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($activites)): ?>
                                <div class="activity-item">
                                    <div class="activity-content">
                                        <div class="activity-text" style="text-align: center; color: var(--gray-500);">
                                            Aucune activité récente
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
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
            
            if (!notificationList.contains(event.target) && !notificationBadge.contains(event.target)) {
                notificationList.classList.remove('show');
            }
        });
    </script>
</body>
</html>