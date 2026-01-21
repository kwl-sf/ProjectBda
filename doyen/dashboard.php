<?php
// doyen/dashboard.php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Vérifier que l'utilisateur est doyen ou vice-doyen
require_role(['doyen', 'vice_doyen']);

$user = get_logged_in_user();

// Récupérer le rôle en français
$user['role_fr'] = get_role_french($user['role']);

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

// Récupérer les envois en attente (pour le doyen)
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM envois_chef_a_doyen 
                       WHERE statut = 'envoye_doyen'");
$stmt->execute();
$pending_validation = $stmt->fetch()['count'];

// Récupérer les statistiques
$stmt = $pdo->prepare("
    SELECT 
        (SELECT COUNT(*) FROM departements) as total_departements,
        (SELECT COUNT(*) FROM formations) as total_formations,
        (SELECT COUNT(*) FROM examens WHERE statut = 'confirme') as examens_confirmes,
        (SELECT COUNT(*) FROM examens WHERE statut = 'planifie') as examens_planifies,
        (SELECT COUNT(*) FROM conflits WHERE statut = 'detecte') as conflits_en_cours,
        (SELECT COUNT(*) FROM envois_chef_a_doyen WHERE statut = 'envoye_doyen') as edt_a_valider
");
$stmt->execute();
$stats = $stmt->fetch();

// Récupérer les KPIs
$stmt = $pdo->prepare("
    SELECT nom_kpi, valeur, date_calcul 
    FROM kpis_academiques 
    ORDER BY date_calcul DESC 
    LIMIT 5
");
$stmt->execute();
$kpis = $stmt->fetchAll();

// Récupérer les derniers EDT à valider
$stmt = $pdo->prepare("
    SELECT ecd.*, d.nom as dept_nom, p.nom as chef_nom, p.prenom as chef_prenom
    FROM envois_chef_a_doyen ecd
    JOIN departements d ON ecd.dept_id = d.id
    JOIN professeurs p ON ecd.chef_id = p.id
    WHERE ecd.statut = 'envoye_doyen'
    ORDER BY ecd.date_envoi DESC 
    LIMIT 5
");
$stmt->execute();
$edt_a_valider = $stmt->fetchAll();

// Récupérer les conflits récents
$stmt = $pdo->prepare("
    SELECT c.* 
    FROM conflits c
    WHERE c.statut = 'detecte'
    ORDER BY c.date_detection DESC 
    LIMIT 5
");
$stmt->execute();
$conflits = $stmt->fetchAll();

// محاولة الحصول على معلومات القسم للتعارضات
$conflits_with_dept = [];
foreach ($conflits as $conflit) {
    $dept_nom = 'Non spécifié';
    
    // محاولة معرفة القسم من خلال entite1_id
    if (in_array($conflit['type'], ['etudiant', 'professeur'])) {
        if ($conflit['type'] === 'etudiant') {
            $stmt = $pdo->prepare("
                SELECT d.nom 
                FROM etudiants e
                JOIN formations f ON e.formation_id = f.id
                JOIN departements d ON f.dept_id = d.id
                WHERE e.id = ?
                LIMIT 1
            ");
            $stmt->execute([$conflit['entite1_id']]);
        } else {
            $stmt = $pdo->prepare("
                SELECT d.nom 
                FROM professeurs p
                JOIN departements d ON p.dept_id = d.id
                WHERE p.id = ?
                LIMIT 1
            ");
            $stmt->execute([$conflit['entite1_id']]);
        }
        
        $dept = $stmt->fetch();
        if ($dept) {
            $dept_nom = $dept['nom'];
        }
    } elseif ($conflit['type'] === 'salle') {
        $dept_nom = 'Infrastructure';
    } elseif ($conflit['type'] === 'horaire') {
        $stmt = $pdo->prepare("
            SELECT d.nom 
            FROM examens e
            JOIN modules m ON e.module_id = m.id
            JOIN formations f ON m.formation_id = f.id
            JOIN departements d ON f.dept_id = d.id
            WHERE e.id = ? OR e.id = ?
            LIMIT 1
        ");
        $stmt->execute([$conflit['entite1_id'], $conflit['entite2_id']]);
        $dept = $stmt->fetch();
        if ($dept) {
            $dept_nom = $dept['nom'];
        }
    }
    
    $conflit['dept_nom'] = $dept_nom;
    $conflits_with_dept[] = $conflit;
}

$page_title = "Tableau de Bord - Doyen";
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.css">
    <style>
        /* نفس الـ CSS الموجود في chef_dept/dashboard.php */
        .dept-banner {
            background: linear-gradient(135deg, #8B0000 0%, #DC143C 100%); /* لون أحمر للدكن */
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
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }
        
        /* Notifications - نفس الكود */
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
        
        /* أنماط خاصة بالدكن مع الحفاظ على نفس التصميم */
        .user-avatar.doyen {
            background: linear-gradient(135deg, #8B0000 0%, #DC143C 100%);
        }
        
        .sidebar-header h2 {
            color: white;
        }
        
        .sidebar-header p {
            color: rgba(255, 255, 255, 0.8);
        }
        
        /* أنماط خاصة بالبطاقات */
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 20px;
            color: white;
            font-size: 24px;
        }
        
        .stat-info h3 {
            font-size: 2rem;
            margin: 0;
            color: #2c3e50;
        }
        
        .stat-info p {
            margin: 5px 0 0;
            color: #7f8c8d;
        }
        
        .conflit-item {
            background: #fff5f5;
            border-left: 4px solid #e74c3c;
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 5px;
        }
        
        .conflit-type {
            font-weight: bold;
            color: #e74c3c;
            text-transform: uppercase;
            font-size: 0.8rem;
        }
        
        .conflit-desc {
            margin: 5px 0;
            color: #2c3e50;
        }
        
        .conflit-dept {
            font-size: 0.9rem;
            color: #7f8c8d;
        }
        
        .conflit-date {
            font-size: 0.8rem;
            color: #95a5a6;
            margin-top: 5px;
        }
        
        .kpi-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }
        
        .kpi-item:last-child {
            border-bottom: none;
        }
        
        .kpi-name {
            color: #2c3e50;
            font-weight: 500;
        }
        
        .kpi-value {
            font-weight: bold;
            color: #4361ee;
        }
        
        .kpi-date {
            font-size: 0.8rem;
            color: #95a5a6;
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
        <!-- نفس الـ Sidebar مع تعديلات للدكن -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-university"></i> Doyenné</h2>
                <p><?php echo htmlspecialchars($user['role_fr']); ?></p>
            </div>
            
            <div class="user-info">
                <div class="user-avatar doyen">
                    <i class="fas fa-user-graduate"></i>
                </div>
                <div class="user-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($user['role_fr']); ?></div>
            </div>
            
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-item active">
                    <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                    <span>Tableau de Bord</span>
                </a>
                <a href="faculty_schedule.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-calendar-alt"></i></span>
                    <span>Planning Faculté</span>
                </a>
                <a href="validation.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-check-circle"></i></span>
                    <span>Validation EDT</span>
                    <?php if ($pending_validation > 0): ?>
                        <span class="notification-count"><?php echo $pending_validation; ?></span>
                    <?php endif; ?>
                </a>
                <a href="departments.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-building"></i></span>
                    <span>Départements</span>
                </a>

                <a href="kpis.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                    <span> KPIs  académiques</span>
                </a> 
                
                <a href="reports.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                    <span>Rapports</span>
                </a>
                <a href="../logout.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                    <span>Déconnexion</span>
                </a>
            </nav>
            
            <div class="sidebar-footer">
                <div class="dept-stats">
                    <div class="stat-mini">
                        <i class="fas fa-building"></i>
                        <span><?php echo number_format($stats['total_departements']); ?></span>
                    </div>
                    <div class="stat-mini">
                        <i class="fas fa-graduation-cap"></i>
                        <span><?php echo number_format($stats['total_formations']); ?></span>
                    </div>
                    <div class="stat-mini">
                        <i class="fas fa-file-alt"></i>
                        <span><?php echo number_format($stats['examens_confirmes']); ?></span>
                    </div>
                </div>
            </div>
        </aside>
        
        <main class="main-content">
            <header class="header">
                <div class="header-left">
                    <h1>Tableau de Bord - Doyenné</h1>
                    <p>Vue stratégique globale de la faculté</p>
                </div>
                <div class="header-actions">
                    <!-- نفس نظام الإشعارات -->
                    <div class="notification-dropdown">
                        <button class="notification-badge" onclick="toggleNotifications()">
                            <i class="fas fa-bell"></i>
                            <?php if ($notification_count > 0): ?>
                                <span class="notification-count"><?php echo $notification_count; ?></span>
                            <?php endif; ?>
                        </button>
                        
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
                                                    'kpi' => 'fas fa-chart-line',
                                                    'department' => 'fas fa-building'
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
            
            <!-- بنر الدكن -->
            <div class="dept-banner animate__animated animate__fadeIn">
                <h2>🎓 Bienvenue, <?php echo htmlspecialchars($user['prenom']); ?> !</h2>
                <p>Vous supervisez la faculté avec <strong><?php echo $stats['total_departements']; ?> départements</strong> et <strong><?php echo $stats['total_formations']; ?> formations</strong>.</p>
                <div class="dept-badge">
                    <i class="fas fa-chart-line"></i>
                    <span>Supervision académique: Stratégique</span>
                </div>
            </div>
            
            <!-- إحصائيات الشبكة -->
            <div class="stats-grid">
                <div class="stat-card primary animate__animated animate__fadeInUp">
                    <div class="stat-header">
                        <div>
                            <div class="stat-title">Départements</div>
                            <div class="stat-value"><?php echo number_format($stats['total_departements']); ?></div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-building"></i>
                        </div>
                    </div>
                    <div class="stat-change positive">
                        <i class="fas fa-users"></i>
                        <span><?php echo $stats['total_formations']; ?> formations</span>
                    </div>
                </div>
                
                <div class="stat-card success animate__animated animate__fadeInUp" style="animation-delay: 0.1s;">
                    <div class="stat-header">
                        <div>
                            <div class="stat-title">Formations</div>
                            <div class="stat-value"><?php echo number_format($stats['total_formations']); ?></div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                    </div>
                    <div class="stat-change positive">
                        <i class="fas fa-check-circle"></i>
                        <span>Actives: <?php echo $stats['total_formations']; ?></span>
                    </div>
                </div>
                
                <div class="stat-card warning animate__animated animate__fadeInUp" style="animation-delay: 0.2s;">
                    <div class="stat-header">
                        <div>
                            <div class="stat-title">Examens Confirmés</div>
                            <div class="stat-value"><?php echo number_format($stats['examens_confirmes']); ?></div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                    </div>
                    <div class="stat-change positive">
                        <i class="fas fa-file-alt"></i>
                        <span><?php echo $stats['examens_planifies']; ?> planifiés</span>
                    </div>
                </div>
                
                <div class="stat-card danger animate__animated animate__fadeInUp" style="animation-delay: 0.3s;">
                    <div class="stat-header">
                        <div>
                            <div class="stat-title">Conflits</div>
                            <div class="stat-value"><?php echo $stats['conflits_en_cours']; ?></div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                    </div>
                    <div class="stat-change <?php echo $stats['conflits_en_cours'] > 0 ? 'negative' : 'positive'; ?>">
                        <i class="fas <?php echo $stats['conflits_en_cours'] > 0 ? 'fa-arrow-up' : 'fa-check-circle'; ?>"></i>
                        <span><?php echo $stats['conflits_en_cours'] > 0 ? 'À résoudre' : 'Aucun conflit'; ?></span>
                    </div>
                </div>
            </div>
            
            <div class="dashboard-content">
                <!-- العمود الأيسر -->
                <div class="left-column">
                    <!-- إجراءات سريعة للدكن -->
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
                                <p class="action-desc">Examiner et valider les emplois du temps des départements</p>
                                <?php if ($pending_validation > 0): ?>
                                    <div class="pending-badge"><?php echo $pending_validation; ?> en attente</div>
                                <?php endif; ?>
                            </a>
                            
                            <a href="faculty_schedule.php" class="action-card animate__animated animate__fadeIn" style="animation-delay: 0.1s;">
                                <div class="action-icon">
                                    <i class="fas fa-calendar-alt"></i>
                                </div>
                                <h3 class="action-title">Voir EDT Faculté</h3>
                                <p class="action-desc">Consulter l'emploi du temps complet de la faculté</p>
                            </a>
                            
                            <a href="departments.php" class="action-card animate__animated animate__fadeIn" style="animation-delay: 0.2s;">
                                <div class="action-icon">
                                    <i class="fas fa-cogs"></i>
                                </div>
                                <h3 class="action-title">Gérer Départements</h3>
                                <p class="action-desc">Superviser les départements de la faculté</p>
                            </a>
                            
                            <a href="reports.php" class="action-card animate__animated animate__fadeIn" style="animation-delay: 0.3s;">
                                <div class="action-icon">
                                    <i class="fas fa-chart-bar"></i>
                                </div>
                                <h3 class="action-title">Rapports Détaillés</h3>
                                <p class="action-desc">Générer des rapports stratégiques de la faculté</p>
                            </a>
                        </div>
                    </div>
                    
                    <!-- EDT à valider -->
                    <div class="recent-activity animate__animated animate__fadeIn">
                        <h2 class="section-title">
                            <i class="fas fa-file-signature"></i>
                            EDT à Valider
                        </h2>
                        <?php if (empty($edt_a_valider)): ?>
                            <div class="empty-state" style="padding: 2rem; text-align: center; color: var(--gray-500);">
                                <i class="fas fa-check-circle fa-3x"></i>
                                <p>Aucun EDT à valider</p>
                            </div>
                        <?php else: ?>
                            <div class="formations-list">
                                <?php foreach ($edt_a_valider as $edt): ?>
                                    <div class="formation-item">
                                        <div class="formation-header">
                                            <div class="formation-name"><?php echo htmlspecialchars($edt['dept_nom']); ?></div>
                                            <div class="formation-modules">
                                                <i class="fas fa-user-tie"></i>
                                                <?php echo htmlspecialchars($edt['chef_nom'] . ' ' . $edt['chef_prenom']); ?>
                                            </div>
                                        </div>
                                        <div style="font-size: 0.9rem; color: var(--gray-600);">
                                            <i class="far fa-clock"></i>
                                            Envoyé le: <?php echo date('d/m/Y H:i', strtotime($edt['date_envoi'])); ?>
                                        </div>
                                        <div style="margin-top: 0.5rem;">
                                            <a href="validation_detail.php?id=<?php echo $edt['id']; ?>" class="btn btn-sm btn-primary">
                                                <i class="fas fa-eye"></i> Examiner
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- العمود الأيمن -->
                <div class="right-column">
                    <!-- KPIs -->
                    <div class="exam-status animate__animated animate__fadeIn">
                        <h2 class="section-title">
                            <i class="fas fa-chart-line"></i>
                            Indicateurs Clés (KPIs)
                        </h2>
                        <?php if (empty($kpis)): ?>
                            <div class="empty-state" style="padding: 2rem; text-align: center; color: var(--gray-500);">
                                <i class="fas fa-chart-bar fa-3x"></i>
                                <p>Aucun KPI disponible</p>
                            </div>
                        <?php else: ?>
                            <div class="status-bars">
                                <?php foreach ($kpis as $kpi): ?>
                                    <div class="status-bar">
                                        <span class="status-label"><?php echo htmlspecialchars($kpi['nom_kpi']); ?></span>
                                        <div class="status-progress">
                                            <div class="status-fill" style="width: <?php echo min($kpi['valeur'], 100); ?>%"></div>
                                        </div>
                                        <span class="status-count"><?php echo $kpi['valeur']; ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Conflits récents -->
                    <div class="calendar-preview animate__animated animate__fadeIn" style="animation-delay: 0.2s;">
                        <h2 class="section-title">
                            <i class="fas fa-exclamation-triangle"></i>
                            Conflits Récents
                        </h2>
                        <div class="activity-list">
                            <?php if (empty($conflits_with_dept)): ?>
                                <div class="activity-item">
                                    <div class="activity-content">
                                        <div class="activity-text" style="text-align: center; color: var(--gray-500);">
                                            Aucun conflit détecté
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php foreach ($conflits_with_dept as $conflit): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon danger">
                                            <i class="fas fa-exclamation-triangle"></i>
                                        </div>
                                        <div class="activity-content">
                                            <div class="activity-text">
                                                <strong><?php echo ucfirst($conflit['type']); ?></strong><br>
                                                <small><?php echo htmlspecialchars(truncate_text($conflit['description'], 50)); ?></small>
                                            </div>
                                            <div class="activity-time">
                                                <i class="far fa-clock"></i>
                                                <?php echo time_ago($conflit['date_detection']); ?>
                                                <span style="margin-left: 10px; color: var(--primary);">
                                                    <i class="fas fa-building"></i> <?php echo htmlspecialchars($conflit['dept_nom']); ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Graphique دج -->
                    <div class="exam-status animate__animated animate__fadeIn" style="animation-delay: 0.3s;">
                        <h2 class="section-title">
                            <i class="fas fa-chart-pie"></i>
                            Occupation des Salles
                        </h2>
                        <div class="card-body" style="padding: 20px;">
                            <canvas id="salleOccupationChart" width="400" height="300"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <script>
        // نفس JavaScript الموجود في chef_dept/dashboard.php
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
        });
        
        document.addEventListener('DOMContentLoaded', function() {
            const progressBars = document.querySelectorAll('.status-fill');
            progressBars.forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0';
                setTimeout(() => {
                    bar.style.width = width;
                }, 500);
            });
            
            const notificationBadge = document.querySelector('.notification-badge');
            if (notificationBadge.querySelector('.notification-count')) {
                setInterval(() => {
                    notificationBadge.classList.toggle('animate-pulse');
                }, 2000);
            }
        });
        
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
        
        document.addEventListener('click', function(event) {
            const notificationList = document.getElementById('notificationList');
            const notificationBadge = document.querySelector('.notification-badge');
            
            if (notificationList && notificationBadge && 
                !notificationList.contains(event.target) && 
                !notificationBadge.contains(event.target)) {
                notificationList.classList.remove('show');
            }
        });
        
        // Graphique d'occupation des salles
        const ctx = document.getElementById('salleOccupationChart').getContext('2d');
        const salleChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Occupées', 'Disponibles', 'En maintenance'],
                datasets: [{
                    data: [65, 30, 5],
                    backgroundColor: [
                        'rgba(139, 0, 0, 0.8)',
                        'rgba(46, 204, 113, 0.8)',
                        'rgba(241, 196, 15, 0.8)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                    },
                    title: {
                        display: true,
                        text: 'Taux d\'occupation des salles'
                    }
                }
            }
        });
    </script>
</body>
</html>