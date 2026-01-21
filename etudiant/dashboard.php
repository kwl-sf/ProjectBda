<?php
// etudiant/dashboard.php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';


require_role(['etudiant']);

$user = get_logged_in_user();


$stmt = $pdo->prepare("
    SELECT e.*, f.nom as formation_nom, d.nom as dept_nom
    FROM etudiants e
    JOIN formations f ON e.formation_id = f.id
    JOIN departements d ON f.dept_id = d.id
    WHERE e.id = ?
");
$stmt->execute([$user['id']]);
$etudiant = $stmt->fetch();


$start_date = date('Y-m-d');
$end_date = date('Y-m-d', strtotime('+7 days'));

$stmt = $pdo->prepare("
    SELECT 
        ex.*,
        m.nom as module_nom,
        m.credits,
        p.nom as prof_nom,
        p.prenom as prof_prenom,
        l.nom as salle_nom,
        l.type as salle_type,
        f.nom as formation_nom,
        ee.present,
        ee.note_examen
    FROM examens ex
    JOIN examens_etudiants ee ON ex.id = ee.examen_id
    JOIN modules m ON ex.module_id = m.id
    JOIN professeurs p ON ex.prof_id = p.id
    JOIN lieu_examen l ON ex.salle_id = l.id
    JOIN formations f ON m.formation_id = f.id
    WHERE ee.etudiant_id = ?
    AND DATE(ex.date_heure) BETWEEN ? AND ?
    ORDER BY ex.date_heure ASC
    LIMIT 5
");
$stmt->execute([$user['id'], $start_date, $end_date]);
$examens_prochains = $stmt->fetchAll();


$stmt = $pdo->prepare("
    SELECT 
        ex.*,
        m.nom as module_nom,
        m.credits,
        p.nom as prof_nom,
        p.prenom as prof_prenom,
        l.nom as salle_nom,
        ee.present,
        ee.note_examen
    FROM examens ex
    JOIN examens_etudiants ee ON ex.id = ee.examen_id
    JOIN modules m ON ex.module_id = m.id
    JOIN professeurs p ON ex.prof_id = p.id
    JOIN lieu_examen l ON ex.salle_id = l.id
    WHERE ee.etudiant_id = ?
    AND DATE(ex.date_heure) < ?
    AND ex.session = 'normale'
    ORDER BY ex.date_heure DESC
    LIMIT 5
");
$stmt->execute([$user['id'], $start_date]);
$examens_passes = $stmt->fetchAll();


$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_examens,
        SUM(CASE WHEN DATE(ex.date_heure) >= ? THEN 1 ELSE 0 END) as examens_a_venir,
        SUM(CASE WHEN DATE(ex.date_heure) < ? THEN 1 ELSE 0 END) as examens_passes,
        AVG(CASE WHEN ee.note_examen IS NOT NULL THEN ee.note_examen END) as moyenne_generale,
        COUNT(DISTINCT m.id) as modules_inscrits
    FROM examens ex
    JOIN examens_etudiants ee ON ex.id = ee.examen_id
    JOIN modules m ON ex.module_id = m.id
    WHERE ee.etudiant_id = ?
");
$stmt->execute([$user['id'], $start_date, $start_date]);
$stats = $stmt->fetch();


$week_start = date('Y-m-d', strtotime('monday this week'));
$week_end = date('Y-m-d', strtotime('sunday this week'));

$stmt = $pdo->prepare("
    SELECT 
        ex.*,
        m.nom as module_nom,
        m.credits,
        p.nom as prof_nom,
        p.prenom as prof_prenom,
        l.nom as salle_nom,
        l.type as salle_type,
        DAYOFWEEK(ex.date_heure) as jour_semaine
    FROM examens ex
    JOIN examens_etudiants ee ON ex.id = ee.examen_id
    JOIN modules m ON ex.module_id = m.id
    JOIN professeurs p ON ex.prof_id = p.id
    JOIN lieu_examen l ON ex.salle_id = l.id
    WHERE ee.etudiant_id = ?
    AND DATE(ex.date_heure) BETWEEN ? AND ?
    ORDER BY ex.date_heure
");
$stmt->execute([$user['id'], $week_start, $week_end]);
$emploi_temps_semaine = $stmt->fetchAll();


$emploi_par_jour = [];
foreach ($emploi_temps_semaine as $examen) {
    $jour = date('N', strtotime($examen['date_heure'])); // 1 = Lundi, 7 = Dimanche
    $emploi_par_jour[$jour][] = $examen;
}

$page_title = "Tableau de Bord - Étudiant";
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
        .student-header {
            background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
            color: white;
            padding: 2rem;
            border-radius: var(--border-radius);
            margin-bottom: 2rem;
        }
        
        .student-info {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }
        
        .student-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
        }
        
        .student-details h2 {
            margin: 0 0 0.5rem 0;
        }
        
        .student-details p {
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
        
        .week-schedule {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-sm);
        }
        
        .week-days {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .day-column {
            border: 1px solid var(--gray-200);
            border-radius: var(--border-radius-sm);
            overflow: hidden;
        }
        
        .day-header {
            background: var(--gray-100);
            padding: 0.75rem;
            text-align: center;
            font-weight: 600;
            color: var(--gray-700);
            border-bottom: 1px solid var(--gray-200);
        }
        
        .day-exams {
            padding: 0.5rem;
            min-height: 150px;
        }
        
        .exam-mini {
            background: var(--primary-light);
            color: var(--primary-dark);
            padding: 0.5rem;
            border-radius: var(--border-radius-sm);
            margin-bottom: 0.5rem;
            font-size: 0.85rem;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .exam-mini:hover {
            background: var(--primary);
            color: white;
        }
        
        .exam-time {
            font-weight: 600;
            font-size: 0.8rem;
        }
        
        .exam-module {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .next-exams, .past-exams {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-sm);
        }
        
        .exam-list {
            display: grid;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .exam-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border: 1px solid var(--gray-200);
            border-radius: var(--border-radius-sm);
            transition: var(--transition);
        }
        
        .exam-item:hover {
            border-color: var(--primary);
            box-shadow: var(--shadow-sm);
        }
        
        .exam-info {
            flex: 1;
        }
        
        .exam-module-name {
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: 0.25rem;
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
        
        .exam-time-full {
            font-size: 0.9rem;
            color: var(--gray-600);
        }
        
        .note-badge {
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .note-success {
            background: #d4edda;
            color: #155724;
        }
        
        .note-warning {
            background: #fff3cd;
            color: #856404;
        }
        
        .note-danger {
            background: #f8d7da;
            color: #721c24;
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
        
        .quick-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            flex-wrap: wrap;
        }
        
        .action-btn {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem 1.5rem;
            background: white;
            border: 2px solid var(--gray-200);
            border-radius: var(--border-radius-sm);
            text-decoration: none;
            color: var(--gray-700);
            transition: var(--transition);
            flex: 1;
            min-width: 200px;
        }
        
        .action-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }
        
        .action-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--gray-100);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        
        @media (max-width: 768px) {
            .week-days {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .exam-details {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .action-btn {
                min-width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-user-graduate"></i> Espace Étudiant</h2>
                <p>Bienvenue, <?php echo htmlspecialchars($etudiant['prenom']); ?></p>
            </div>
            
            <div class="user-info">
                <div class="user-avatar student">
                    <i class="fas fa-user-graduate"></i>
                </div>
                <div class="user-name"><?php echo htmlspecialchars($etudiant['prenom'] . ' ' . $etudiant['nom']); ?></div>
                <div class="user-role">Étudiant - <?php echo htmlspecialchars($etudiant['formation_nom']); ?></div>
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
                <a href="notes.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-chart-line"></i></span>
                    <span>Mes Notes</span>
                </a>
                <a href="absences.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-user-clock"></i></span>
                    <span>Absences</span>
                </a>
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
                        <i class="fas fa-check-circle"></i>
                        <span><?php echo $stats['examens_passes'] ?? 0; ?></span>
                    </div>
                    <div class="stat-mini">
                        <i class="fas fa-book"></i>
                        <span><?php echo $stats['modules_inscrits'] ?? 0; ?></span>
                    </div>
                </div>
            </div>
        </aside>
        
        <main class="main-content">
            <header class="header">
                <div class="header-left">
                    <h1>Tableau de Bord - Étudiant</h1>
                    <p>Bienvenue dans votre espace personnel</p>
                </div>
                <div class="header-actions">
                    <span class="badge badge-primary">
                        <?php echo htmlspecialchars($etudiant['formation_nom']); ?>
                    </span>
                    <span class="badge badge-secondary">
                        Promo <?php echo htmlspecialchars($etudiant['promo']); ?>
                    </span>
                </div>
            </header>
            
            
            <div class="student-header">
                <div class="student-info">
                    <div class="student-avatar">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="student-details">
                        <h2><?php echo htmlspecialchars($etudiant['prenom'] . ' ' . $etudiant['nom']); ?></h2>
                        <p>
                            <i class="fas fa-graduation-cap"></i>
                            <?php echo htmlspecialchars($etudiant['formation_nom']); ?>
                        </p>
                        <p>
                            <i class="fas fa-building"></i>
                            Département <?php echo htmlspecialchars($etudiant['dept_nom']); ?>
                        </p>
                        <p>
                            <i class="fas fa-calendar-alt"></i>
                            Promo <?php echo htmlspecialchars($etudiant['promo']); ?>
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
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['examens_passes'] ?? 0; ?></div>
                    <div class="stat-label">Examens passés</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #9b59b6 0%, #8e44ad 100%);">
                        <i class="fas fa-book"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['modules_inscrits'] ?? 0; ?></div>
                    <div class="stat-label">Modules inscrits</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-number"><?php echo number_format($stats['moyenne_generale'] ?? 0, 2); ?></div>
                    <div class="stat-label">Moyenne générale</div>
                </div>
            </div>
            
            
            <div class="week-schedule">
                <h2 class="section-title">
                    <i class="fas fa-calendar-week"></i>
                    Emploi du Temps - Semaine <?php echo date('W'); ?>
                </h2>
                
                <div class="week-days">
                    <?php 
                    $jours = [
                        1 => 'Lundi',
                        2 => 'Mardi',
                        3 => 'Mercredi',
                        4 => 'Jeudi',
                        5 => 'Vendredi',
                        6 => 'Samedi',
                        7 => 'Dimanche'
                    ];
                    
                    foreach ($jours as $num => $jour):
                        $date_jour = date('d/m', strtotime($week_start . ' +' . ($num-1) . ' days'));
                    ?>
                        <div class="day-column">
                            <div class="day-header">
                                <div><?php echo $jour; ?></div>
                                <small><?php echo $date_jour; ?></small>
                            </div>
                            <div class="day-exams">
                                <?php if (isset($emploi_par_jour[$num]) && !empty($emploi_par_jour[$num])): ?>
                                    <?php foreach ($emploi_par_jour[$num] as $examen): ?>
                                        <div class="exam-mini" title="<?php echo htmlspecialchars($examen['module_nom']); ?>">
                                            <div class="exam-time">
                                                <?php echo date('H:i', strtotime($examen['date_heure'])); ?>
                                            </div>
                                            <div class="exam-module">
                                                <?php echo truncate_text(htmlspecialchars($examen['module_nom']), 15); ?>
                                            </div>
                                            <small><?php echo htmlspecialchars($examen['salle_nom']); ?></small>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div style="text-align: center; color: var(--gray-500); padding: 1rem; font-size: 0.9rem;">
                                        Aucun examen
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            
            <div class="quick-actions">
                <a href="my_schedule.php" class="action-btn">
                    <div class="action-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div>
                        <strong>Voir mon emploi du temps</strong>
                        <small>Calendrier complet</small>
                    </div>
                </a>
                
                <a href="exams.php" class="action-btn">
                    <div class="action-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div>
                        <strong>Mes examens</strong>
                        <small>Tous mes examens</small>
                    </div>
                </a>
                
                <a href="notes.php" class="action-btn">
                    <div class="action-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div>
                        <strong>Mes notes</strong>
                        <small>Résultats et statistiques</small>
                    </div>
                </a>
                
                <a href="absences.php" class="action-btn">
                    <div class="action-icon">
                        <i class="fas fa-user-clock"></i>
                    </div>
                    <div>
                        <strong>Mes absences</strong>
                        <small>Suivi des présences</small>
                    </div>
                </a>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                
                <div class="next-exams">
                    <h2 class="section-title">
                        <i class="fas fa-arrow-right"></i>
                        Examens à Venir
                    </h2>
                    
                    <?php if (empty($examens_prochains)): ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-check"></i>
                            <p>Aucun examen à venir cette semaine</p>
                        </div>
                    <?php else: ?>
                        <div class="exam-list">
                            <?php foreach ($examens_prochains as $examen): ?>
                                <div class="exam-item">
                                    <div class="exam-info">
                                        <div class="exam-module-name">
                                            <?php echo htmlspecialchars($examen['module_nom']); ?>
                                            <span class="badge badge-light"><?php echo $examen['credits']; ?> crédits</span>
                                        </div>
                                        <div class="exam-details">
                                            <div class="exam-detail">
                                                <i class="fas fa-user-tie"></i>
                                                <?php echo htmlspecialchars($examen['prof_nom'] . ' ' . $examen['prof_prenom']); ?>
                                            </div>
                                            <div class="exam-detail">
                                                <i class="fas fa-building"></i>
                                                <?php echo htmlspecialchars($examen['salle_nom']); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="exam-date">
                                        <div class="exam-day">
                                            <?php echo date('d/m', strtotime($examen['date_heure'])); ?>
                                        </div>
                                        <div class="exam-time-full">
                                            <?php echo date('H:i', strtotime($examen['date_heure'])); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                
                <div class="past-exams">
                    <h2 class="section-title">
                        <i class="fas fa-history"></i>
                        Derniers Examens
                    </h2>
                    
                    <?php if (empty($examens_passes)): ?>
                        <div class="empty-state">
                            <i class="fas fa-history"></i>
                            <p>Aucun examen passé</p>
                        </div>
                    <?php else: ?>
                        <div class="exam-list">
                            <?php foreach ($examens_passes as $examen): 
                                $note_class = '';
                                if ($examen['note_examen'] >= 10) {
                                    $note_class = 'note-success';
                                } elseif ($examen['note_examen'] >= 8) {
                                    $note_class = 'note-warning';
                                } else {
                                    $note_class = 'note-danger';
                                }
                            ?>
                                <div class="exam-item">
                                    <div class="exam-info">
                                        <div class="exam-module-name">
                                            <?php echo htmlspecialchars($examen['module_nom']); ?>
                                            <span class="badge badge-light"><?php echo $examen['credits']; ?> crédits</span>
                                        </div>
                                        <div class="exam-details">
                                            <div class="exam-detail">
                                                <i class="fas fa-calendar-alt"></i>
                                                <?php echo date('d/m/Y', strtotime($examen['date_heure'])); ?>
                                            </div>
                                            <div class="exam-detail">
                                                <i class="fas fa-building"></i>
                                                <?php echo htmlspecialchars($examen['salle_nom']); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="exam-date">
                                        <?php if ($examen['note_examen'] !== null): ?>
                                            <div class="note-badge <?php echo $note_class; ?>">
                                                <?php echo number_format($examen['note_examen'], 2); ?>/20
                                            </div>
                                        <?php else: ?>
                                            <div class="note-badge note-warning">
                                                En attente
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        
        document.querySelectorAll('.exam-mini').forEach(item => {
            item.addEventListener('click', function() {
                const moduleName = this.getAttribute('title');
                alert(`Détails de l'examen:\n${moduleName}`);
            });
        });
    </script>
</body>
</html>