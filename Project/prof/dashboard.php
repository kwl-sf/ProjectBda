<?php
// prof/dashboard.php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';


require_role(['prof', 'chef_dept']);

$user = get_logged_in_user();


$stmt = $pdo->prepare("
    SELECT p.*, d.nom as dept_nom, 
           COUNT(DISTINCT m.id) as nb_modules,
           COUNT(DISTINCT ex.id) as nb_examens_mois
    FROM professeurs p
    LEFT JOIN departements d ON p.dept_id = d.id
    LEFT JOIN modules m ON p.id = m.prof_id
    LEFT JOIN examens ex ON p.id = ex.prof_id AND MONTH(ex.date_heure) = MONTH(CURRENT_DATE())
    WHERE p.id = ?
    GROUP BY p.id
");
$stmt->execute([$user['id']]);
$professeur = $stmt->fetch();


$start_date = date('Y-m-d');
$end_date = date('Y-m-d', strtotime('+30 days'));

$stmt = $pdo->prepare("
    SELECT 
        ex.*,
        m.nom as module_nom,
        m.credits,
        l.nom as salle_nom,
        l.type as salle_type,
        l.capacite,
        f.nom as formation_nom,
        d.nom as dept_nom,
        COUNT(DISTINCT ee.etudiant_id) as nb_etudiants
    FROM examens ex
    JOIN modules m ON ex.module_id = m.id
    JOIN formations f ON m.formation_id = f.id
    JOIN departements d ON f.dept_id = d.id
    JOIN lieu_examen l ON ex.salle_id = l.id
    LEFT JOIN examens_etudiants ee ON ex.id = ee.examen_id
    WHERE ex.prof_id = ?
    AND DATE(ex.date_heure) BETWEEN ? AND ?
    GROUP BY ex.id
    ORDER BY ex.date_heure ASC
    LIMIT 5
");
$stmt->execute([$user['id'], $start_date, $end_date]);
$examens_prochains = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT 
        ex.*,
        m.nom as module_nom,
        p.nom as prof_principal_nom,
        p.prenom as prof_principal_prenom,
        l.nom as salle_nom,
        s.role as surveillance_role
    FROM surveillants s
    JOIN examens ex ON s.examen_id = ex.id
    JOIN modules m ON ex.module_id = m.id
    JOIN professeurs p ON ex.prof_id = p.id
    JOIN lieu_examen l ON ex.salle_id = l.id
    WHERE s.prof_id = ?
    AND DATE(ex.date_heure) BETWEEN ? AND ?
    ORDER BY ex.date_heure ASC
    LIMIT 5
");
$stmt->execute([$user['id'], $start_date, $end_date]);
$surveillances = $stmt->fetchAll();


$stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT ex.id) as total_examens_mois,
        COUNT(DISTINCT CASE WHEN DATE(ex.date_heure) >= ? THEN ex.id END) as examens_a_venir,
        COUNT(DISTINCT s.examen_id) as surveillances_mois,
        COUNT(DISTINCT m.id) as modules_enseignes,
        AVG(ex.duree_minutes) as duree_moyenne
    FROM professeurs p
    LEFT JOIN examens ex ON p.id = ex.prof_id AND MONTH(ex.date_heure) = MONTH(CURRENT_DATE())
    LEFT JOIN surveillants s ON p.id = s.prof_id AND MONTH((SELECT date_heure FROM examens WHERE id = s.examen_id)) = MONTH(CURRENT_DATE())
    LEFT JOIN modules m ON p.id = m.prof_id
    WHERE p.id = ?
");
$stmt->execute([$user['id'], $start_date]);
$stats = $stmt->fetch();


$stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT ee.etudiant_id) as nb_etudiants_total,
        COUNT(DISTINCT CASE WHEN ee.note_examen >= 10 THEN ee.etudiant_id END) as reussis,
        COUNT(DISTINCT CASE WHEN ee.note_examen < 10 AND ee.note_examen IS NOT NULL THEN ee.etudiant_id END) as echoues
    FROM modules m
    JOIN examens ex ON m.id = ex.module_id
    JOIN examens_etudiants ee ON ex.id = ee.examen_id
    WHERE m.prof_id = ?
    AND ex.session = 'normale'
");
$stmt->execute([$user['id']]);
$stats_etudiants = $stmt->fetch();


$stmt = $pdo->prepare("
    SELECT COUNT(*) as count FROM notifications 
    WHERE destinataire_id = ? 
    AND destinataire_type = 'prof' 
    AND lue = 0
");
$stmt->execute([$user['id']]);
$notification_count = $stmt->fetch()['count'];

$page_title = "Tableau de Bord - Enseignant";
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
        .prof-header {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: white;
            padding: 2rem;
            border-radius: var(--border-radius);
            margin-bottom: 2rem;
        }
        
        .prof-info {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }
        
        .prof-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
        }
        
        .prof-details h2 {
            margin: 0 0 0.5rem 0;
        }
        
        .prof-details p {
            margin: 0.25rem 0;
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
            border-radius: var(--border-radius-sm);
            padding: 1.5rem;
            text-align: center;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem auto;
            font-size: 1.5rem;
            color: white;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--gray-800);
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: var(--gray-600);
        }
        
        .dashboard-content {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }
        
        @media (max-width: 1024px) {
            .dashboard-content {
                grid-template-columns: 1fr;
            }
        }
        
        .exams-section, .surveillance-section, .quick-stats {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
        }
        
        .section-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .exam-list, .surveillance-list {
            display: grid;
            gap: 1rem;
        }
        
        .exam-item, .surveillance-item {
            padding: 1rem;
            border: 1px solid var(--gray-200);
            border-radius: var(--border-radius-sm);
            transition: var(--transition);
        }
        
        .exam-item:hover, .surveillance-item:hover {
            border-color: var(--primary);
            box-shadow: var(--shadow-sm);
        }
        
        .exam-header, .surveillance-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }
        
        .exam-module {
            font-weight: 600;
            color: var(--gray-800);
        }
        
        .exam-date {
            background: var(--gray-100);
            padding: 0.5rem 1rem;
            border-radius: var(--border-radius-sm);
            text-align: center;
            min-width: 120px;
        }
        
        .exam-day {
            font-weight: 600;
            color: var(--primary);
        }
        
        .exam-time {
            font-size: 0.9rem;
            color: var(--gray-600);
        }
        
        .exam-details {
            display: flex;
            gap: 1rem;
            font-size: 0.9rem;
            color: var(--gray-600);
        }
        
        .exam-detail {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .student-stats {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-around;
            text-align: center;
        }
        
        .stat-mini {
            display: flex;
            flex-direction: column;
        }
        
        .stat-mini .number {
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        .stat-mini .label {
            font-size: 0.8rem;
            color: var(--gray-600);
        }
        
        .stat-mini.success .number { color: var(--success); }
        .stat-mini.warning .number { color: var(--warning); }
        .stat-mini.danger .number { color: var(--danger); }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-top: 2rem;
        }
        
        .action-card {
            background: var(--gray-50);
            border-radius: var(--border-radius-sm);
            padding: 1rem;
            text-decoration: none;
            color: var(--gray-700);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .action-card:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }
        
        .action-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        
        .action-card:hover .action-icon {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }
        
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--gray-500);
        }
        
        .empty-state i {
            font-size: 2rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .surveillance-role {
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .role-principal {
            background: #d4edda;
            color: #155724;
        }
        
        .role-secondaire {
            background: #fff3cd;
            color: #856404;
        }
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-chalkboard-teacher"></i> Espace Enseignant</h2>
                <p>Bienvenue, <?php echo htmlspecialchars($professeur['prenom']); ?></p>
            </div>
            
            <div class="user-info">
                <div class="user-avatar prof">
                    <i class="fas fa-chalkboard-teacher"></i>
                </div>
                <div class="user-name"><?php echo htmlspecialchars($professeur['prenom'] . ' ' . $professeur['nom']); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($professeur['specialite'] ?? 'Enseignant'); ?></div>
            </div>
            
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-item active">
                    <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                    <span>Tableau de Bord</span>
                </a>
                <a href="my_schedule.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-calendar-alt"></i></span>
                    <span>Mon Emploi du Temps</span>
                </a>
                <a href="exams.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-file-alt"></i></span>
                    <span>Mes Examens</span>
                </a>
                <a href="surveillance.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-eye"></i></span>
                    <span>Mes Surveillances</span>
                </a>
                <a href="students.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-users"></i></span>
                    <span>Mes Étudiants</span>
                </a>
                <?php if ($user['role'] == 'chef_dept'): ?>
                    <a href="../chef_dept/dashboard.php" class="nav-item">
                        <span class="nav-icon"><i class="fas fa-crown"></i></span>
                        <span>Espace Chef de Département</span>
                    </a>
                <?php endif; ?>
                <a href="../logout.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                    <span>Déconnexion</span>
                </a>
            </nav>
            
            <div class="sidebar-footer">
                <div class="dept-stats">
                    <div class="stat-mini">
                        <i class="fas fa-file-alt"></i>
                        <span><?php echo $stats['examens_a_venir'] ?? 0; ?></span>
                    </div>
                    <div class="stat-mini">
                        <i class="fas fa-eye"></i>
                        <span><?php echo $stats['surveillances_mois'] ?? 0; ?></span>
                    </div>
                    <div class="stat-mini">
                        <i class="fas fa-bell"></i>
                        <span><?php echo $notification_count; ?></span>
                    </div>
                </div>
            </div>
        </aside>
        
        <main class="main-content">
            <header class="header">
                <div class="header-left">
                    <h1>Tableau de Bord - Enseignant</h1>
                    <p>Bienvenue dans votre espace de travail</p>
                </div>
                <div class="header-actions">
                    <span class="badge badge-primary">
                        <?php echo htmlspecialchars($professeur['dept_nom']); ?>
                    </span>
                    <?php if ($professeur['specialite']): ?>
                        <span class="badge badge-secondary">
                            <?php echo htmlspecialchars($professeur['specialite']); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </header>
            
            
            <div class="prof-header">
                <div class="prof-info">
                    <div class="prof-avatar">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                    <div class="prof-details">
                        <h2>Pr. <?php echo htmlspecialchars($professeur['prenom'] . ' ' . $professeur['nom']); ?></h2>
                        <p>
                            <i class="fas fa-building"></i>
                            Département <?php echo htmlspecialchars($professeur['dept_nom']); ?>
                        </p>
                        <?php if ($professeur['specialite']): ?>
                            <p>
                                <i class="fas fa-graduation-cap"></i>
                                Spécialité: <?php echo htmlspecialchars($professeur['specialite']); ?>
                            </p>
                        <?php endif; ?>
                        <p>
                            <i class="fas fa-book"></i>
                            <?php echo $professeur['nb_modules']; ?> modules enseignés
                        </p>
                    </div>
                </div>
            </div>
            
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['examens_a_venir'] ?? 0; ?></div>
                    <div class="stat-label">Examens à venir</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #2ecc71 0%, #27ae60 100%);">
                        <i class="fas fa-eye"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['surveillances_mois'] ?? 0; ?></div>
                    <div class="stat-label">Surveillances</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #9b59b6 0%, #8e44ad 100%);">
                        <i class="fas fa-book"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['modules_enseignes'] ?? 0; ?></div>
                    <div class="stat-label">Modules enseignés</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-number"><?php echo number_format($stats['duree_moyenne'] ?? 0, 0); ?>min</div>
                    <div class="stat-label">Durée moyenne</div>
                </div>
            </div>
            
            <div class="dashboard-content">
                
                <div class="left-column">
                    
                    <div class="exams-section">
                        <h2 class="section-title">
                            <i class="fas fa-calendar-alt"></i>
                            Mes Examens à Venir
                        </h2>
                        
                        <?php if (empty($examens_prochains)): ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar-check"></i>
                                <p>Aucun examen à venir</p>
                            </div>
                        <?php else: ?>
                            <div class="exam-list">
                                <?php foreach ($examens_prochains as $examen): ?>
                                    <div class="exam-item">
                                        <div class="exam-header">
                                            <div class="exam-module">
                                                <?php echo htmlspecialchars($examen['module_nom']); ?>
                                                <span class="badge badge-light"><?php echo $examen['credits']; ?> crédits</span>
                                            </div>
                                            <div class="exam-date">
                                                <div class="exam-day">
                                                    <?php echo date('d/m', strtotime($examen['date_heure'])); ?>
                                                </div>
                                                <div class="exam-time">
                                                    <?php echo date('H:i', strtotime($examen['date_heure'])); ?>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="exam-details">
                                            <div class="exam-detail">
                                                <i class="fas fa-building"></i>
                                                <?php echo htmlspecialchars($examen['salle_nom']); ?> (<?php echo ucfirst($examen['salle_type']); ?>)
                                            </div>
                                            <div class="exam-detail">
                                                <i class="fas fa-users"></i>
                                                <?php echo $examen['nb_etudiants']; ?> étudiants
                                            </div>
                                            <div class="exam-detail">
                                                <i class="fas fa-graduation-cap"></i>
                                                <?php echo htmlspecialchars($examen['formation_nom']); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    
                    <div class="surveillance-section">
                        <h2 class="section-title">
                            <i class="fas fa-eye"></i>
                            Mes Surveillances
                        </h2>
                        
                        <?php if (empty($surveillances)): ?>
                            <div class="empty-state">
                                <i class="fas fa-eye-slash"></i>
                                <p>Aucune surveillance programmée</p>
                            </div>
                        <?php else: ?>
                            <div class="surveillance-list">
                                <?php foreach ($surveillances as $surveillance): ?>
                                    <div class="surveillance-item">
                                        <div class="exam-header">
                                            <div class="exam-module">
                                                <?php echo htmlspecialchars($surveillance['module_nom']); ?>
                                                <span class="surveillance-role <?php echo $surveillance['surveillance_role'] == 'principal' ? 'role-principal' : 'role-secondaire'; ?>">
                                                    <?php echo $surveillance['surveillance_role']; ?>
                                                </span>
                                            </div>
                                            <div class="exam-date">
                                                <div class="exam-day">
                                                    <?php echo date('d/m', strtotime($surveillance['date_heure'])); ?>
                                                </div>
                                                <div class="exam-time">
                                                    <?php echo date('H:i', strtotime($surveillance['date_heure'])); ?>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="exam-details">
                                            <div class="exam-detail">
                                                <i class="fas fa-user-tie"></i>
                                                Pr. <?php echo htmlspecialchars($surveillance['prof_principal_nom'] . ' ' . $surveillance['prof_principal_prenom']); ?>
                                            </div>
                                            <div class="exam-detail">
                                                <i class="fas fa-building"></i>
                                                <?php echo htmlspecialchars($surveillance['salle_nom']); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                
                <div class="right-column">
                    
                    <div class="quick-stats">
                        <h2 class="section-title">
                            <i class="fas fa-users"></i>
                            Statistiques Étudiants
                        </h2>
                        
                        <div class="student-stats">
                            <div class="stat-mini success">
                                <div class="number"><?php echo $stats_etudiants['reussis'] ?? 0; ?></div>
                                <div class="label">Réussis</div>
                            </div>
                            
                            <div class="stat-mini warning">
                                <div class="number"><?php echo $stats_etudiants['echoues'] ?? 0; ?></div>
                                <div class="label">Échoués</div>
                            </div>
                            
                            <div class="stat-mini">
                                <div class="number"><?php echo $stats_etudiants['nb_etudiants_total'] ?? 0; ?></div>
                                <div class="label">Total</div>
                            </div>
                        </div>
                        
                        
                        <div class="quick-actions">
                            <a href="exams.php" class="action-card">
                                <div class="action-icon">
                                    <i class="fas fa-file-alt"></i>
                                </div>
                                <div>
                                    <strong>Gérer examens</strong>
                                    <small>Planifier, modifier</small>
                                </div>
                            </a>
                            
                            <a href="students.php" class="action-card">
                                <div class="action-icon">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div>
                                    <strong>Voir étudiants</strong>
                                    <small>Liste et notes</small>
                                </div>
                            </a>
                            
                            <a href="my_schedule.php" class="action-card">
                                <div class="action-icon">
                                    <i class="fas fa-calendar-alt"></i>
                                </div>
                                <div>
                                    <strong>Mon planning</strong>
                                    <small>Emploi du temps</small>
                                </div>
                            </a>
                            
                            <a href="notes.php" class="action-card">
                                <div class="action-icon">
                                    <i class="fas fa-edit"></i>
                                </div>
                                <div>
                                    <strong>Saisir notes</strong>
                                    <small>Évaluer copies</small>
                                </div>
                            </a>
                        </div>
                        
                        
                        <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--gray-200);">
                            <h3 style="font-size: 1rem; font-weight: 600; color: var(--gray-700); margin-bottom: 1rem;">
                                <i class="fas fa-bell"></i> Alertes
                            </h3>
                            
                            <?php 
                            
                            $tomorrow = date('Y-m-d', strtotime('+1 day'));
                            $stmt = $pdo->prepare("
                                SELECT COUNT(*) as count 
                                FROM examens 
                                WHERE prof_id = ? 
                                AND DATE(date_heure) = ?
                            ");
                            $stmt->execute([$user['id'], $tomorrow]);
                            $exams_tomorrow = $stmt->fetch()['count'];
                            
                            if ($exams_tomorrow > 0): ?>
                                <div style="background: #fff3cd; color: #856404; padding: 0.75rem; border-radius: var(--border-radius-sm); margin-bottom: 0.5rem;">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <strong><?php echo $exams_tomorrow; ?> examen(s) demain</strong>
                                    <p style="margin: 0.25rem 0 0 0; font-size: 0.9rem;">
                                        Pensez à préparer vos sujets
                                    </p>
                                </div>
                            <?php endif; ?>
                            
                            <?php 
                            
                            $stmt = $pdo->prepare("
                                SELECT COUNT(*) as count 
                                FROM surveillants s
                                JOIN examens ex ON s.examen_id = ex.id
                                WHERE s.prof_id = ? 
                                AND DATE(ex.date_heure) = ?
                            ");
                            $stmt->execute([$user['id'], $tomorrow]);
                            $surv_tomorrow = $stmt->fetch()['count'];
                            
                            if ($surv_tomorrow > 0): ?>
                                <div style="background: #d1ecf1; color: #0c5460; padding: 0.75rem; border-radius: var(--border-radius-sm);">
                                    <i class="fas fa-eye"></i>
                                    <strong><?php echo $surv_tomorrow; ?> surveillance(s) demain</strong>
                                    <p style="margin: 0.25rem 0 0 0; font-size: 0.9rem;">
                                        Vérifiez les salles et horaires
                                    </p>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($exams_tomorrow == 0 && $surv_tomorrow == 0): ?>
                                <div style="text-align: center; color: var(--gray-500); padding: 1rem;">
                                    <i class="fas fa-check-circle"></i>
                                    <p style="margin: 0.5rem 0 0 0;">Aucune alerte pour demain</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        
        function updateNotifications() {
            fetch('../includes/get_notifications_count.php')
                .then(response => response.json())
                .then(data => {
                    const badge = document.querySelector('.sidebar-footer .stat-mini:nth-child(3) span');
                    if (badge && data.count !== undefined) {
                        badge.textContent = data.count;
                    }
                });
        }
        
       
        setInterval(updateNotifications, 60000);
    </script>
</body>
</html>