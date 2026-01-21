<?php
// prof/my_schedule.php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';


require_role(['prof', 'chef_dept']);

$user = get_logged_in_user();


$month = $_GET['month'] ?? date('m');
$year = $_GET['year'] ?? date('Y');
$view = $_GET['view'] ?? 'week'; // month, week, day


if ($view == 'month') {
    $start_date = "$year-$month-01";
    $end_date = date('Y-m-t', strtotime($start_date));
} elseif ($view == 'week') {
    $week_start = $_GET['week_start'] ?? date('Y-m-d', strtotime('monday this week'));
    $start_date = $week_start;
    $end_date = date('Y-m-d', strtotime($start_date . ' +6 days'));
} else { // day
    $day = $_GET['day'] ?? date('Y-m-d');
    $start_date = $day;
    $end_date = $day;
}


$stmt = $pdo->prepare("
    SELECT 
        ex.*,
        m.nom as module_nom,
        m.credits,
        l.nom as salle_nom,
        l.type as salle_type,
        l.batiment,
        f.nom as formation_nom,
        d.nom as dept_nom,
        COUNT(DISTINCT ee.etudiant_id) as nb_etudiants,
        'enseignement' as type_activite
    FROM examens ex
    JOIN modules m ON ex.module_id = m.id
    JOIN formations f ON m.formation_id = f.id
    JOIN departements d ON f.dept_id = d.id
    JOIN lieu_examen l ON ex.salle_id = l.id
    LEFT JOIN examens_etudiants ee ON ex.id = ee.examen_id
    WHERE ex.prof_id = ?
    AND DATE(ex.date_heure) BETWEEN ? AND ?
    GROUP BY ex.id
");


$stmt_surv = $pdo->prepare("
    SELECT 
        ex.*,
        m.nom as module_nom,
        m.credits,
        p.nom as prof_principal_nom,
        p.prenom as prof_principal_prenom,
        l.nom as salle_nom,
        l.type as salle_type,
        l.batiment,
        f.nom as formation_nom,
        d.nom as dept_nom,
        s.role as surveillance_role,
        'surveillance' as type_activite
    FROM surveillants s
    JOIN examens ex ON s.examen_id = ex.id
    JOIN modules m ON ex.module_id = m.id
    JOIN professeurs p ON ex.prof_id = p.id
    JOIN formations f ON m.formation_id = f.id
    JOIN departements d ON f.dept_id = d.id
    JOIN lieu_examen l ON ex.salle_id = l.id
    WHERE s.prof_id = ?
    AND DATE(ex.date_heure) BETWEEN ? AND ?
");


$stmt->execute([$user['id'], $start_date, $end_date]);
$examens = $stmt->fetchAll();

$stmt_surv->execute([$user['id'], $start_date, $end_date]);
$surveillances = $stmt_surv->fetchAll();


$activites = array_merge($examens, $surveillances);


$activites_par_jour = [];
foreach ($activites as $activite) {
    $jour = date('Y-m-d', strtotime($activite['date_heure']));
    $activites_par_jour[$jour][] = $activite;
}


$stmt = $pdo->prepare("
    SELECT p.*, d.nom as dept_nom
    FROM professeurs p
    LEFT JOIN departements d ON p.dept_id = d.id
    WHERE p.id = ?
");
$stmt->execute([$user['id']]);
$professeur = $stmt->fetch();

$page_title = "Mon Emploi du Temps";
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        .schedule-header {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: white;
            padding: 2rem;
            border-radius: var(--border-radius);
            margin-bottom: 2rem;
        }
        
        .view-controls {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }
        
        .view-btn {
            padding: 0.75rem 1.5rem;
            background: white;
            border: 2px solid var(--gray-200);
            border-radius: var(--border-radius-sm);
            cursor: pointer;
            font-weight: 500;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .view-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        
        .view-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .date-navigation {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .calendar-container {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }
        
       
        .week-view-prof {
            display: grid;
            grid-template-columns: 80px repeat(7, 1fr);
            border: 1px solid var(--gray-200);
        }
        
        .time-slot {
            border-right: 1px solid var(--gray-200);
            border-bottom: 1px solid var(--gray-200);
            padding: 0.5rem;
            text-align: center;
            font-size: 0.9rem;
            color: var(--gray-600);
        }
        
        .day-column {
            border-right: 1px solid var(--gray-200);
            border-bottom: 1px solid var(--gray-200);
            position: relative;
        }
        
        .day-column:last-child {
            border-right: none;
        }
        
        .day-column-header {
            background: var(--gray-100);
            padding: 1rem;
            text-align: center;
            font-weight: 600;
            color: var(--gray-700);
            border-bottom: 1px solid var(--gray-200);
        }
        
        .day-column-content {
            min-height: 600px;
            position: relative;
        }
        
        .activity-block {
            position: absolute;
            padding: 0.75rem;
            border-radius: var(--border-radius-sm);
            cursor: pointer;
            transition: var(--transition);
            overflow: hidden;
            font-size: 0.85rem;
            border-left: 4px solid;
        }
        
        .activity-block:hover {
            transform: scale(1.02);
            z-index: 10;
            box-shadow: var(--shadow-md);
        }
        
        .activity-enseignement {
            background: rgba(52, 152, 219, 0.1);
            border-left-color: #3498db;
            color: #2980b9;
        }
        
        .activity-surveillance {
            background: rgba(46, 204, 113, 0.1);
            border-left-color: #2ecc71;
            color: #27ae60;
        }
        
        .activity-block:hover.activity-enseignement {
            background: #3498db;
            color: white;
        }
        
        .activity-block:hover.activity-surveillance {
            background: #2ecc71;
            color: white;
        }
        
        .activity-type {
            font-size: 0.75rem;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .activity-time {
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .activity-module {
            margin: 0.25rem 0;
            font-weight: 500;
        }
        
        .activity-details {
            font-size: 0.8rem;
            opacity: 0.9;
        }
        
        /* عرض اليوم للأستاذ */
        .day-view-prof {
            padding: 2rem;
        }
        
        .activity-card {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: var(--border-radius-sm);
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: var(--transition);
            position: relative;
            border-left: 4px solid;
        }
        
        .activity-card.enseignement {
            border-left-color: #3498db;
        }
        
        .activity-card.surveillance {
            border-left-color: #2ecc71;
        }
        
        .activity-card:hover {
            border-color: var(--primary);
            box-shadow: var(--shadow-sm);
        }
        
        .activity-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-enseignement {
            background: rgba(52, 152, 219, 0.1);
            color: #2980b9;
        }
        
        .badge-surveillance {
            background: rgba(46, 204, 113, 0.1);
            color: #27ae60;
        }
        
        .activity-time-large {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }
        
        .activity-info-detailed {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .info-item-detailed {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .info-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--gray-100);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
        }
        
        .activity-card.enseignement .info-icon {
            background: rgba(52, 152, 219, 0.1);
            color: #3498db;
        }
        
        .activity-card.surveillance .info-icon {
            background: rgba(46, 204, 113, 0.1);
            color: #2ecc71;
        }
        
        .empty-schedule {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--gray-500);
        }
        
        .empty-schedule i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .legend {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid var(--gray-200);
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .legend-color {
            width: 20px;
            height: 20px;
            border-radius: 3px;
        }
        
        .legend-color.enseignement {
            background: #3498db;
        }
        
        .legend-color.surveillance {
            background: #2ecc71;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        
        .modal-content {
            background: white;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 2rem;
        }
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="main-content">
            <header class="header">
                <div class="header-left">
                    <h1>Mon Emploi du Temps</h1>
                    <p>Planning de vos activités (enseignement et surveillance)</p>
                </div>
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="printSchedule()">
                        <i class="fas fa-print"></i> Imprimer
                    </button>
                    <a href="exams.php" class="btn btn-outline">
                        <i class="fas fa-plus"></i> Ajouter un examen
                    </a>
                </div>
            </header>
            
            <!-- En-tête -->
            <div class="schedule-header">
                <h2>
                    <i class="fas fa-calendar-alt"></i>
                    Planning des Activités
                </h2>
                <p>
                    Pr. <?php echo htmlspecialchars($professeur['prenom'] . ' ' . $professeur['nom']); ?>
                    - Département <?php echo htmlspecialchars($professeur['dept_nom']); ?>
                </p>
            </div>
            
            
            <div class="view-controls">
                <a href="?view=week&week_start=<?php echo date('Y-m-d', strtotime('monday this week')); ?>" 
                   class="view-btn <?php echo $view == 'week' ? 'active' : ''; ?>">
                    <i class="far fa-calendar-week"></i> Semaine
                </a>
                <a href="?view=day&day=<?php echo date('Y-m-d'); ?>" 
                   class="view-btn <?php echo $view == 'day' ? 'active' : ''; ?>">
                    <i class="far fa-calendar-day"></i> Jour
                </a>
                <a href="?view=month&month=<?php echo date('m'); ?>&year=<?php echo date('Y'); ?>" 
                   class="view-btn <?php echo $view == 'month' ? 'active' : ''; ?>">
                    <i class="far fa-calendar"></i> Mois
                </a>
            </div>
            
            
            <div class="date-navigation">
                <?php if ($view == 'week'): ?>
                    <a href="?view=week&week_start=<?php echo date('Y-m-d', strtotime($start_date . ' -7 days')); ?>" 
                       class="btn btn-outline">
                        <i class="fas fa-chevron-left"></i> Semaine précédente
                    </a>
                    
                    <span style="flex: 1; text-align: center; font-weight: 600;">
                        Semaine du <?php echo date('d/m/Y', strtotime($start_date)); ?> au <?php echo date('d/m/Y', strtotime($end_date)); ?>
                    </span>
                    
                    <a href="?view=week&week_start=<?php echo date('Y-m-d', strtotime($start_date . ' +7 days')); ?>" 
                       class="btn btn-outline">
                        Semaine suivante <i class="fas fa-chevron-right"></i>
                    </a>
                    
                <?php elseif ($view == 'day'): ?>
                    <a href="?view=day&day=<?php echo date('Y-m-d', strtotime($start_date . ' -1 day')); ?>" 
                       class="btn btn-outline">
                        <i class="fas fa-chevron-left"></i> Jour précédent
                    </a>
                    
                    <input type="date" id="dayPicker" value="<?php echo $start_date; ?>" 
                           class="form-control" style="max-width: 200px;"
                           onchange="window.location.href='?view=day&day=' + this.value">
                    
                    <a href="?view=day&day=<?php echo date('Y-m-d', strtotime($start_date . ' +1 day')); ?>" 
                       class="btn btn-outline">
                        Jour suivant <i class="fas fa-chevron-right"></i>
                    </a>
                    
                <?php elseif ($view == 'month'): ?>
                    <a href="?view=month&month=<?php echo date('m', strtotime($start_date . ' -1 month')); ?>&year=<?php echo date('Y', strtotime($start_date . ' -1 month')); ?>" 
                       class="btn btn-outline">
                        <i class="fas fa-chevron-left"></i> Mois précédent
                    </a>
                    
                    <span style="flex: 1; text-align: center; font-weight: 600;">
                        <?php echo date('F Y', strtotime($start_date)); ?>
                    </span>
                    
                    <a href="?view=month&month=<?php echo date('m', strtotime($start_date . ' +1 month')); ?>&year=<?php echo date('Y', strtotime($start_date . ' +1 month')); ?>" 
                       class="btn btn-outline">
                        Mois suivant <i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
            
            
            <div class="calendar-container">
                <?php if (empty($activites)): ?>
                    <div class="empty-schedule">
                        <i class="fas fa-calendar-times"></i>
                        <h3>Aucune activité programmée</h3>
                        <p>Vérifiez une autre période</p>
                    </div>
                <?php else: ?>
                    
                    <?php if ($view == 'week'): ?>
                        
                        <div class="week-view-prof">
                            <div style="grid-column: 1;"></div>
                            <?php 
                            $jours = [];
                            for ($i = 0; $i < 7; $i++) {
                                $day_date = date('Y-m-d', strtotime($start_date . " +$i days"));
                                $jours[] = [
                                    'date' => $day_date,
                                    'name' => date('l', strtotime($day_date)),
                                    'short' => date('D', strtotime($day_date)),
                                    'day' => date('d', strtotime($day_date))
                                ];
                            }
                            
                            foreach ($jours as $jour): ?>
                                <div class="day-column-header">
                                    <div><?php echo $jour['short']; ?></div>
                                    <div><?php echo $jour['day']; ?></div>
                                </div>
                            <?php endforeach; ?>
                            
                            
                            <?php for ($hour = 8; $hour <= 20; $hour++): ?>
                                <div class="time-slot"><?php echo sprintf('%02d:00', $hour); ?></div>
                                
                                <?php foreach ($jours as $jour): ?>
                                    <div class="day-column" id="cell-<?php echo $jour['date']; ?>-<?php echo $hour; ?>">
                                        
                                    </div>
                                <?php endforeach; ?>
                            <?php endfor; ?>
                        </div>
                        
                    <?php elseif ($view == 'day'): ?>
                        
                        <div class="day-view-prof">
                            <h3 style="margin-bottom: 1.5rem;">
                                <i class="far fa-calendar-day"></i>
                                <?php echo date('l d F Y', strtotime($start_date)); ?>
                            </h3>
                            
                            <?php if (isset($activites_par_jour[$start_date])): 
                                usort($activites_par_jour[$start_date], function($a, $b) {
                                    return strtotime($a['date_heure']) - strtotime($b['date_heure']);
                                });
                            ?>
                                <?php foreach ($activites_par_jour[$start_date] as $activite): 
                                    $is_enseignement = $activite['type_activite'] == 'enseignement';
                                ?>
                                    <div class="activity-card <?php echo $is_enseignement ? 'enseignement' : 'surveillance'; ?>">
                                        <div class="activity-badge <?php echo $is_enseignement ? 'badge-enseignement' : 'badge-surveillance'; ?>">
                                            <?php echo $is_enseignement ? 'Enseignement' : 'Surveillance'; ?>
                                        </div>
                                        
                                        <div class="activity-time-large">
                                            <?php echo date('H:i', strtotime($activite['date_heure'])); ?>
                                            -
                                            <?php echo date('H:i', strtotime($activite['date_heure']) + $activite['duree_minutes'] * 60); ?>
                                        </div>
                                        
                                        <div style="font-weight: 600; font-size: 1.2rem; color: var(--gray-800);">
                                            <?php echo htmlspecialchars($activite['module_nom']); ?>
                                            <span class="badge badge-primary"><?php echo $activite['credits']; ?> crédits</span>
                                        </div>
                                        
                                        <div class="activity-info-detailed">
                                            <div class="info-item-detailed">
                                                <div class="info-icon">
                                                    <i class="fas fa-building"></i>
                                                </div>
                                                <div>
                                                    <div style="font-size: 0.9rem; color: var(--gray-600);">Salle</div>
                                                    <div style="font-weight: 500;"><?php echo htmlspecialchars($activite['salle_nom']); ?></div>
                                                    <div style="font-size: 0.85rem; color: var(--gray-500);"><?php echo ucfirst($activite['salle_type']); ?> - <?php echo htmlspecialchars($activite['batiment']); ?></div>
                                                </div>
                                            </div>
                                            
                                            <div class="info-item-detailed">
                                                <div class="info-icon">
                                                    <i class="fas fa-graduation-cap"></i>
                                                </div>
                                                <div>
                                                    <div style="font-size: 0.9rem; color: var(--gray-600);">Formation</div>
                                                    <div style="font-weight: 500;"><?php echo htmlspecialchars($activite['formation_nom']); ?></div>
                                                    <div style="font-size: 0.85rem; color: var(--gray-500);"><?php echo htmlspecialchars($activite['dept_nom']); ?></div>
                                                </div>
                                            </div>
                                            
                                            <div class="info-item-detailed">
                                                <div class="info-icon">
                                                    <i class="fas fa-clock"></i>
                                                </div>
                                                <div>
                                                    <div style="font-size: 0.9rem; color: var(--gray-600);">Durée</div>
                                                    <div style="font-weight: 500;"><?php echo $activite['duree_minutes']; ?> minutes</div>
                                                </div>
                                            </div>
                                            
                                            <?php if ($is_enseignement): ?>
                                                <div class="info-item-detailed">
                                                    <div class="info-icon">
                                                        <i class="fas fa-users"></i>
                                                    </div>
                                                    <div>
                                                        <div style="font-size: 0.9rem; color: var(--gray-600);">Étudiants</div>
                                                        <div style="font-weight: 500;"><?php echo $activite['nb_etudiants']; ?> inscrits</div>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <div class="info-item-detailed">
                                                    <div class="info-icon">
                                                        <i class="fas fa-user-tie"></i>
                                                    </div>
                                                    <div>
                                                        <div style="font-size: 0.9rem; color: var(--gray-600);">Enseignant principal</div>
                                                        <div style="font-weight: 500;">Pr. <?php echo htmlspecialchars($activite['prof_principal_nom'] . ' ' . $activite['prof_principal_prenom']); ?></div>
                                                    </div>
                                                </div>
                                                
                                                <div class="info-item-detailed">
                                                    <div class="info-icon">
                                                        <i class="fas fa-eye"></i>
                                                    </div>
                                                    <div>
                                                        <div style="font-size: 0.9rem; color: var(--gray-600);">Rôle</div>
                                                        <div style="font-weight: 500;"><?php echo ucfirst($activite['surveillance_role']); ?></div>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-schedule">
                                    <i class="fas fa-calendar-check"></i>
                                    <h3>Aucune activité ce jour</h3>
                                    <p>Journée libre</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                    <?php else: ?>
                        
                        <div style="padding: 2rem;">
                            <h3 style="margin-bottom: 1.5rem;">
                                <?php echo date('F Y', strtotime($start_date)); ?>
                            </h3>
                            
                            <div style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 1rem;">
                                <?php 
                                $jours_semaine = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
                                foreach ($jours_semaine as $jour): ?>
                                    <div style="font-weight: 600; text-align: center; padding: 0.5rem; background: var(--gray-100);">
                                        <?php echo $jour; ?>
                                    </div>
                                <?php endforeach; ?>
                                
                                <?php
                                $first_day = date('N', strtotime($start_date));
                                $days_in_month = date('t', strtotime($start_date));
                                $current_day = 1;
                                
                                for ($i = 1; $i < $first_day; $i++): ?>
                                    <div style="height: 100px; background: var(--gray-50);"></div>
                                <?php endfor; ?>
                                
                                while ($current_day <= $days_in_month):
                                    $current_date = date('Y-m-d', strtotime("$year-$month-$current_day"));
                                    $has_activite = isset($activites_par_jour[$current_date]);
                                ?>
                                    <div style="height: 100px; border: 1px solid var(--gray-200); padding: 0.5rem; position: relative;">
                                        <div style="font-weight: 600; margin-bottom: 0.5rem;"><?php echo $current_day; ?></div>
                                        
                                        <?php if ($has_activite): 
                                            $activites_count = count($activites_par_jour[$current_date]);
                                            $enseignement_count = 0;
                                            $surveillance_count = 0;
                                            
                                            foreach ($activites_par_jour[$current_date] as $activite) {
                                                if ($activite['type_activite'] == 'enseignement') {
                                                    $enseignement_count++;
                                                } else {
                                                    $surveillance_count++;
                                                }
                                            }
                                        ?>
                                            <div style="font-size: 0.8rem;">
                                                <div>
                                                    <span style="color: #3498db;">●</span> <?php echo $enseignement_count; ?> ens.
                                                </div>
                                                <div>
                                                    <span style="color: #2ecc71;">●</span> <?php echo $surveillance_count; ?> surv.
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php 
                                    $current_day++;
                                endwhile;
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            
            
            <div class="legend">
                <div class="legend-item">
                    <div class="legend-color enseignement"></div>
                    <span>Enseignement</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color surveillance"></div>
                    <span>Surveillance</span>
                </div>
            </div>
        </main>
    </div>
    
    
    <div class="modal" id="activityModal">
        <div class="modal-content">
            <div id="modalContent">Chargement...</div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/fr.js"></script>
    <script>
        // Date picker
        flatpickr('#dayPicker', {
            dateFormat: 'Y-m-d',
            locale: 'fr'
        });
        
        function printSchedule() {
            window.print();
        }
        
        function showActivityDetails(activityId, activityType) {
            fetch('../includes/get_activity_details.php?id=' + activityId + '&type=' + activityType)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('modalContent').innerHTML = html;
                    document.getElementById('activityModal').style.display = 'flex';
                });
        }
        
        function closeModal() {
            document.getElementById('activityModal').style.display = 'none';
        }
        
        
        <?php if ($view == 'week'): ?>
        document.addEventListener('DOMContentLoaded', function() {
            <?php foreach ($activites as $activite): 
                $start_hour = (int)date('H', strtotime($activite['date_heure']));
                $start_minute = (int)date('i', strtotime($activite['date_heure']));
                $end_hour = $start_hour + ceil($activite['duree_minutes'] / 60);
                $day = date('Y-m-d', strtotime($activite['date_heure']));
                
                
                $top = (($start_hour - 8) * 60 + $start_minute) * 0.8;
                $height = $activite['duree_minutes'] * 0.8;
                
                $activity_class = $activite['type_activite'] == 'enseignement' ? 'activity-enseignement' : 'activity-surveillance';
            ?>
                const cell = document.getElementById('cell-<?php echo $day; ?>-<?php echo $start_hour; ?>');
                if (cell) {
                    const activityBlock = document.createElement('div');
                    activityBlock.className = 'activity-block <?php echo $activity_class; ?>';
                    activityBlock.style.top = '<?php echo $top; ?>px';
                    activityBlock.style.height = '<?php echo $height; ?>px';
                    activityBlock.style.left = '5px';
                    activityBlock.style.right = '5px';
                    activityBlock.innerHTML = `
                        <div class="activity-type"><?php echo $activite['type_activite'] == 'enseignement' ? 'Enseignement' : 'Surveillance'; ?></div>
                        <div class="activity-time"><?php echo date('H:i', strtotime($activite['date_heure'])); ?></div>
                        <div class="activity-module"><?php echo truncate_text(htmlspecialchars($activite['module_nom']), 15); ?></div>
                        <div class="activity-details"><?php echo htmlspecialchars($activite['salle_nom']); ?></div>
                    `;
                    activityBlock.onclick = () => showActivityDetails(<?php echo $activite['id']; ?>, '<?php echo $activite['type_activite']; ?>');
                    cell.appendChild(activityBlock);
                }
            <?php endforeach; ?>
        });
        <?php endif; ?>
        
       
        document.getElementById('activityModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
    </script>
</body>
</html>