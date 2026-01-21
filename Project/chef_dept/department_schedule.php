<?php
// chef_dept/department_schedule.php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Vérifier que l'utilisateur est chef de département
require_role(['chef_dept']);

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

// Filtrer par période (par défaut: ce mois)
$period = $_GET['period'] ?? date('Y-m');
$start_date = date('Y-m-01', strtotime($period));
$end_date = date('Y-m-t', strtotime($period));

// Récupérer les examens du département pour la période
$stmt = $pdo->prepare("SELECT e.*, m.nom as module_nom, f.nom as formation_nom,
                       p.nom as prof_nom, p.prenom as prof_prenom,
                       l.nom as salle_nom, l.capacite, l.type
                       FROM examens e 
                       JOIN modules m ON e.module_id = m.id 
                       JOIN formations f ON m.formation_id = f.id
                       JOIN professeurs p ON e.prof_id = p.id
                       JOIN lieu_examen l ON e.salle_id = l.id 
                       WHERE f.dept_id = ? 
                       AND DATE(e.date_heure) BETWEEN ? AND ?
                       ORDER BY e.date_heure ASC");
$stmt->execute([$dept_id, $start_date, $end_date]);
$examens = $stmt->fetchAll();

// Grouper les examens par jour
$examens_par_jour = [];
foreach ($examens as $examen) {
    $date = date('Y-m-d', strtotime($examen['date_heure']));
    if (!isset($examens_par_jour[$date])) {
        $examens_par_jour[$date] = [];
    }
    $examens_par_jour[$date][] = $examen;
}

$page_title = "Emploi du Temps - " . $departement['nom'];
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
        .schedule-header {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }
        
        .filters {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .filter-group {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }
        
        .filter-select {
            padding: 0.5rem 1rem;
            border: 1px solid var(--gray-300);
            border-radius: var(--border-radius-sm);
            background: white;
            min-width: 200px;
        }
        
        .day-schedule {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-md);
        }
        
        .day-header {
            background: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%);
            color: white;
            padding: 1rem;
            border-radius: var(--border-radius-sm);
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .day-title {
            font-size: 1.2rem;
            font-weight: 600;
        }
        
        .day-count {
            background: rgba(255, 255, 255, 0.2);
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.9rem;
        }
        
        .exams-list {
            display: grid;
            gap: 1rem;
        }
        
        .exam-card {
            padding: 1rem;
            border-left: 4px solid var(--primary);
            background: var(--gray-100);
            border-radius: var(--border-radius-sm);
            transition: var(--transition);
        }
        
        .exam-card:hover {
            transform: translateX(5px);
            box-shadow: var(--shadow-sm);
        }
        
        .exam-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        
        .exam-title {
            font-weight: 600;
            color: var(--gray-800);
            font-size: 1.1rem;
        }
        
        .exam-time {
            background: var(--primary);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.9rem;
        }
        
        .exam-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            font-size: 0.9rem;
            color: var(--gray-600);
        }
        
        .detail-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray-500);
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .export-buttons {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        
        .stat-card.mini {
            padding: 1rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }
        
        .stat-card.mini .stat-icon {
            width: 40px;
            height: 40px;
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
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
                <a href="department_schedule.php" class="nav-item active">
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
        
        <main class="main-content">
            <header class="header">
                <div class="header-left">
                    <h1>Emploi du Temps - <?php echo htmlspecialchars($departement['nom']); ?></h1>
                    <p>Planning des examens du département pour <?php echo date('F Y', strtotime($period)); ?></p>
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
            
            <!-- Filtres et Statistiques -->
            <div class="schedule-header">
                <div class="filters">
                    <div class="filter-group">
                        <label for="period">Période:</label>
                        <input type="month" id="period" name="period" 
                               value="<?php echo $period; ?>" class="filter-select"
                               onchange="window.location.href = '?period=' + this.value">
                    </div>
                    
                    <div class="filter-group">
                        <label for="formation">Formation:</label>
                        <select id="formation" class="filter-select" onchange="filterFormation(this.value)">
                            <option value="">Toutes les formations</option>
                            <?php
                            $stmt = $pdo->prepare("SELECT id, nom FROM formations WHERE dept_id = ? ORDER BY nom");
                            $stmt->execute([$dept_id]);
                            $formations = $stmt->fetchAll();
                            foreach ($formations as $formation) {
                                echo '<option value="' . $formation['id'] . '">' . htmlspecialchars($formation['nom']) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                </div>
                
                <div class="stats-grid" style="margin-top: 1.5rem;">
                    <div class="stat-card mini primary">
                        <div class="stat-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div class="stat-value"><?php echo count($examens); ?></div>
                        <div class="stat-title">Examens</div>
                    </div>
                    
                    <div class="stat-card mini success">
                        <div class="stat-icon">
                            <i class="fas fa-door-open"></i>
                        </div>
                        <div class="stat-value">
                            <?php 
                            $salles = array_unique(array_column($examens, 'salle_nom'));
                            echo count($salles);
                            ?>
                        </div>
                        <div class="stat-title">Salles Utilisées</div>
                    </div>
                    
                    <div class="stat-card mini warning">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-value">
                            <?php
                            $profs = array_unique(array_map(function($e) {
                                return $e['prof_id'];
                            }, $examens));
                            echo count($profs);
                            ?>
                        </div>
                        <div class="stat-title">Professeurs Impliqués</div>
                    </div>
                    
                    <div class="stat-card mini info">
                        <div class="stat-icon">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                        <div class="stat-value">
                            <?php
                            $formation_examens = array_unique(array_column($examens, 'formation_nom'));
                            echo count($formation_examens);
                            ?>
                        </div>
                        <div class="stat-title">Formations Concernées</div>
                    </div>
                </div>
            </div>
            
            <!-- Planning -->
            <?php if (empty($examens_par_jour)): ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-times"></i>
                    <h3>Aucun examen planifié pour cette période</h3>
                    <p>Il n'y a pas d'examens programmés pour <?php echo date('F Y', strtotime($period)); ?>.</p>
                </div>
            <?php else: ?>
                <?php foreach ($examens_par_jour as $date => $examens_jour): ?>
                    <div class="day-schedule">
                        <div class="day-header">
                            <div class="day-title">
                                <?php echo date('l d F Y', strtotime($date)); ?>
                            </div>
                            <div class="day-count">
                                <?php echo count($examens_jour); ?> examen<?php echo count($examens_jour) > 1 ? 's' : ''; ?>
                            </div>
                        </div>
                        
                        <div class="exams-list">
                            <?php foreach ($examens_jour as $examen): ?>
                                <div class="exam-card">
                                    <div class="exam-header">
                                        <div class="exam-title">
                                            <?php echo htmlspecialchars($examen['module_nom']); ?>
                                        </div>
                                        <div class="exam-time">
                                            <i class="far fa-clock"></i>
                                            <?php echo date('H:i', strtotime($examen['date_heure'])); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="exam-details">
                                        <div class="detail-item">
                                            <i class="fas fa-user-tie"></i>
                                            <span>Professeur: <?php echo htmlspecialchars($examen['prof_prenom'] . ' ' . $examen['prof_nom']); ?></span>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <i class="fas fa-graduation-cap"></i>
                                            <span>Formation: <?php echo htmlspecialchars($examen['formation_nom']); ?></span>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <i class="fas fa-door-open"></i>
                                            <span>Salle: <?php echo htmlspecialchars($examen['salle_nom']); ?> (<?php echo $examen['type']; ?>)</span>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <i class="fas fa-users"></i>
                                            <span>Capacité: <?php echo $examen['capacite']; ?> places</span>
                                        </div>
                                    </div>
                                    
                                    <div class="export-buttons">
                                        <a href="exam_details.php?id=<?php echo $examen['id']; ?>" 
                                           class="btn btn-sm btn-primary">
                                            <i class="fas fa-info-circle"></i> Détails
                                        </a>
                                        <a href="modify_exam.php?id=<?php echo $examen['id']; ?>" 
                                           class="btn btn-sm btn-warning">
                                            <i class="fas fa-edit"></i> Modifier
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </main>
    </div>
    
    <script>
        // Menu Toggle
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
        });
        
        // Filter by formation
        function filterFormation(formationId) {
            if (formationId) {
                // Implémenter la filtration côté client ou rediriger
                alert('Filtration par formation à implémenter');
            }
        }
        
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
            
            if (notificationList && notificationBadge && 
                !notificationList.contains(event.target) && 
                !notificationBadge.contains(event.target)) {
                notificationList.classList.remove('show');
            }
        });
    </script>
</body>
</html>