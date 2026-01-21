<?php
// etudiant/my_schedule.php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

require_role(['etudiant']);

$user = get_logged_in_user();


$month = $_GET['month'] ?? date('m');
$year = $_GET['year'] ?? date('Y');
$view = $_GET['view'] ?? 'month'; // month, week, day


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
        p.nom as prof_nom,
        p.prenom as prof_prenom,
        l.nom as salle_nom,
        l.type as salle_type,
        l.batiment,
        f.nom as formation_nom,
        ee.present
    FROM examens ex
    JOIN examens_etudiants ee ON ex.id = ee.examen_id
    JOIN modules m ON ex.module_id = m.id
    JOIN professeurs p ON ex.prof_id = p.id
    JOIN lieu_examen l ON ex.salle_id = l.id
    JOIN formations f ON m.formation_id = f.id
    WHERE ee.etudiant_id = ?
    AND DATE(ex.date_heure) BETWEEN ? AND ?
    ORDER BY ex.date_heure
");
$stmt->execute([$user['id'], $start_date, $end_date]);
$examens = $stmt->fetchAll();


$examens_par_jour = [];
foreach ($examens as $examen) {
    $jour = date('Y-m-d', strtotime($examen['date_heure']));
    $examens_par_jour[$jour][] = $examen;
}


$stmt = $pdo->prepare("
    SELECT e.*, f.nom as formation_nom, d.nom as dept_nom
    FROM etudiants e
    JOIN formations f ON e.formation_id = f.id
    JOIN departements d ON f.dept_id = d.id
    WHERE e.id = ?
");
$stmt->execute([$user['id']]);
$etudiant = $stmt->fetch();

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
            background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
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
        
        
        .month-view {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            border: 1px solid var(--gray-200);
        }
        
        .day-header {
            background: var(--gray-100);
            padding: 1rem;
            text-align: center;
            font-weight: 600;
            color: var(--gray-700);
            border-bottom: 1px solid var(--gray-200);
            border-right: 1px solid var(--gray-200);
        }
        
        .day-header:last-child {
            border-right: none;
        }
        
        .calendar-day {
            min-height: 120px;
            border-right: 1px solid var(--gray-200);
            border-bottom: 1px solid var(--gray-200);
            padding: 0.5rem;
            position: relative;
        }
        
        .calendar-day:nth-child(7n) {
            border-right: none;
        }
        
        .calendar-day.other-month {
            background: var(--gray-50);
            color: var(--gray-500);
        }
        
        .calendar-day.today {
            background: rgba(52, 152, 219, 0.1);
        }
        
        .day-number {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            font-weight: 600;
            color: var(--gray-700);
        }
        
        .day-exams {
            margin-top: 1.5rem;
        }
        
        .exam-mini-calendar {
            background: var(--primary-light);
            color: var(--primary-dark);
            padding: 0.25rem 0.5rem;
            border-radius: var(--border-radius-sm);
            margin-bottom: 0.25rem;
            font-size: 0.8rem;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .exam-mini-calendar:hover {
            background: var(--primary);
            color: white;
        }
        
        .exam-time-small {
            font-weight: 600;
        }
        
       
        .week-view {
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
            min-height: 400px;
            position: relative;
        }
        
        .exam-block {
            position: absolute;
            background: var(--primary-light);
            border-left: 3px solid var(--primary);
            padding: 0.5rem;
            border-radius: 3px;
            cursor: pointer;
            transition: var(--transition);
            overflow: hidden;
            font-size: 0.85rem;
        }
        
        .exam-block:hover {
            background: var(--primary);
            color: white;
            transform: scale(1.02);
            z-index: 10;
            box-shadow: var(--shadow-md);
        }
        
        
        .day-view {
            padding: 2rem;
        }
        
        .exam-card-detailed {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: var(--border-radius-sm);
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: var(--transition);
        }
        
        .exam-card-detailed:hover {
            border-color: var(--primary);
            box-shadow: var(--shadow-sm);
        }
        
        .exam-time-large {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }
        
        .exam-info-detailed {
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
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="main-content">
            <header class="header">
                <div class="header-left">
                    <h1>Mon Emploi du Temps</h1>
                    <p>Planning de vos examens</p>
                </div>
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="printSchedule()">
                        <i class="fas fa-print"></i> Imprimer
                    </button>
                </div>
            </header>
            
            <!-- En-tête -->
            <div class="schedule-header">
                <h2>
                    <i class="fas fa-calendar-alt"></i>
                    Planning des Examens
                </h2>
                <p>
                    <?php if ($view == 'month'): ?>
                        Mois de <?php echo date('F Y', strtotime($start_date)); ?>
                    <?php elseif ($view == 'week'): ?>
                        Semaine du <?php echo date('d/m/Y', strtotime($start_date)); ?> au <?php echo date('d/m/Y', strtotime($end_date)); ?>
                    <?php else: ?>
                        Journée du <?php echo date('d/m/Y', strtotime($start_date)); ?>
                    <?php endif; ?>
                </p>
            </div>
            
            
            <div class="view-controls">
                <a href="?view=month&month=<?php echo date('m'); ?>&year=<?php echo date('Y'); ?>" 
                   class="view-btn <?php echo $view == 'month' ? 'active' : ''; ?>">
                    <i class="far fa-calendar"></i> Mois
                </a>
                <a href="?view=week&week_start=<?php echo date('Y-m-d', strtotime('monday this week')); ?>" 
                   class="view-btn <?php echo $view == 'week' ? 'active' : ''; ?>">
                    <i class="far fa-calendar-week"></i> Semaine
                </a>
                <a href="?view=day&day=<?php echo date('Y-m-d'); ?>" 
                   class="view-btn <?php echo $view == 'day' ? 'active' : ''; ?>">
                    <i class="far fa-calendar-day"></i> Jour
                </a>
            </div>
            
           
            <div class="date-navigation">
                <?php if ($view == 'month'): ?>
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
                    
                <?php elseif ($view == 'week'): ?>
                    <a href="?view=week&week_start=<?php echo date('Y-m-d', strtotime($start_date . ' -7 days')); ?>" 
                       class="btn btn-outline">
                        <i class="fas fa-chevron-left"></i> Semaine précédente
                    </a>
                    
                    <span style="flex: 1; text-align: center; font-weight: 600;">
                        Semaine <?php echo date('W', strtotime($start_date)); ?>
                    </span>
                    
                    <a href="?view=week&week_start=<?php echo date('Y-m-d', strtotime($start_date . ' +7 days')); ?>" 
                       class="btn btn-outline">
                        Semaine suivante <i class="fas fa-chevron-right"></i>
                    </a>
                    
                <?php else: ?>
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
                <?php endif; ?>
            </div>
            
            
            <div class="calendar-container">
                <?php if (empty($examens)): ?>
                    <div class="empty-schedule">
                        <i class="fas fa-calendar-times"></i>
                        <h3>Aucun examen programmé</h3>
                        <p>Vérifiez une autre période</p>
                    </div>
                <?php else: ?>
                    
                    <?php if ($view == 'month'): ?>
                        
                        <div class="month-view">
                            <?php 
                            $jours_semaine = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
                            foreach ($jours_semaine as $jour): ?>
                                <div class="day-header"><?php echo $jour; ?></div>
                            <?php endforeach; ?>
                            
                            <?php
                            $first_day = date('N', strtotime($start_date)); // 1 = Lundi, 7 = Dimanche
                            $days_in_month = date('t', strtotime($start_date));
                            $current_day = 1;
                            
                            
                            for ($i = 1; $i < $first_day; $i++): ?>
                                <div class="calendar-day other-month">
                                    <div class="day-number">
                                        <?php echo date('d', strtotime($start_date . " -" . ($first_day - $i) . " days")); ?>
                                    </div>
                                </div>
                            <?php endfor; ?>
                            
                            
                            while ($current_day <= $days_in_month):
                                $current_date = date('Y-m-d', strtotime("$year-$month-$current_day"));
                                $is_today = $current_date == date('Y-m-d');
                                $has_exam = isset($examens_par_jour[$current_date]);
                            ?>
                                <div class="calendar-day <?php echo $is_today ? 'today' : ''; ?>">
                                    <div class="day-number"><?php echo $current_day; ?></div>
                                    
                                    <?php if ($has_exam): ?>
                                        <div class="day-exams">
                                            <?php foreach ($examens_par_jour[$current_date] as $examen): ?>
                                                <div class="exam-mini-calendar" 
                                                     onclick="showExamDetails(<?php echo $examen['id']; ?>)">
                                                    <div class="exam-time-small">
                                                        <?php echo date('H:i', strtotime($examen['date_heure'])); ?>
                                                    </div>
                                                    <div><?php echo truncate_text(htmlspecialchars($examen['module_nom']), 15); ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php 
                                $current_day++;
                            endwhile;
                            
                           
                            $last_day_of_week = date('N', strtotime("$year-$month-$days_in_month"));
                            if ($last_day_of_week < 7) {
                                $next_days = 7 - $last_day_of_week;
                                for ($i = 1; $i <= $next_days; $i++): ?>
                                    <div class="calendar-day other-month">
                                        <div class="day-number"><?php echo $i; ?></div>
                                    </div>
                                <?php endfor;
                            }
                            ?>
                        </div>
                        
                    <?php elseif ($view == 'week'): ?>
                       
                        <div class="week-view">
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
                        
                    <?php else: ?>
                        
                        <div class="day-view">
                            <h3 style="margin-bottom: 1.5rem;">
                                <i class="far fa-calendar-day"></i>
                                <?php echo date('l d F Y', strtotime($start_date)); ?>
                            </h3>
                            
                            <?php if (isset($examens_par_jour[$start_date])): ?>
                                <?php foreach ($examens_par_jour[$start_date] as $examen): ?>
                                    <div class="exam-card-detailed" onclick="showExamDetails(<?php echo $examen['id']; ?>)">
                                        <div class="exam-time-large">
                                            <?php echo date('H:i', strtotime($examen['date_heure'])); ?>
                                            -
                                            <?php echo date('H:i', strtotime($examen['date_heure']) + $examen['duree_minutes'] * 60); ?>
                                        </div>
                                        
                                        <div style="font-weight: 600; font-size: 1.2rem; color: var(--gray-800);">
                                            <?php echo htmlspecialchars($examen['module_nom']); ?>
                                            <span class="badge badge-primary"><?php echo $examen['credits']; ?> crédits</span>
                                        </div>
                                        
                                        <div class="exam-info-detailed">
                                            <div class="info-item-detailed">
                                                <div class="info-icon">
                                                    <i class="fas fa-user-tie"></i>
                                                </div>
                                                <div>
                                                    <div style="font-size: 0.9rem; color: var(--gray-600);">Enseignant</div>
                                                    <div style="font-weight: 500;"><?php echo htmlspecialchars($examen['prof_nom'] . ' ' . $examen['prof_prenom']); ?></div>
                                                </div>
                                            </div>
                                            
                                            <div class="info-item-detailed">
                                                <div class="info-icon">
                                                    <i class="fas fa-building"></i>
                                                </div>
                                                <div>
                                                    <div style="font-size: 0.9rem; color: var(--gray-600);">Salle</div>
                                                    <div style="font-weight: 500;"><?php echo htmlspecialchars($examen['salle_nom']); ?></div>
                                                    <div style="font-size: 0.85rem; color: var(--gray-500);"><?php echo ucfirst($examen['salle_type']); ?> - <?php echo htmlspecialchars($examen['batiment']); ?></div>
                                                </div>
                                            </div>
                                            
                                            <div class="info-item-detailed">
                                                <div class="info-icon">
                                                    <i class="fas fa-clock"></i>
                                                </div>
                                                <div>
                                                    <div style="font-size: 0.9rem; color: var(--gray-600);">Durée</div>
                                                    <div style="font-weight: 500;"><?php echo $examen['duree_minutes']; ?> minutes</div>
                                                </div>
                                            </div>
                                            
                                            <div class="info-item-detailed">
                                                <div class="info-icon">
                                                    <i class="fas fa-graduation-cap"></i>
                                                </div>
                                                <div>
                                                    <div style="font-size: 0.9rem; color: var(--gray-600);">Formation</div>
                                                    <div style="font-weight: 500;"><?php echo htmlspecialchars($examen['formation_nom']); ?></div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <?php if ($examen['present'] !== null): ?>
                                            <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--gray-200);">
                                                <span class="badge <?php echo $examen['present'] ? 'badge-success' : 'badge-danger'; ?>">
                                                    <?php echo $examen['present'] ? 'Présent' : 'Absent'; ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-schedule">
                                    <i class="fas fa-calendar-check"></i>
                                    <h3>Aucun examen ce jour</h3>
                                    <p>Profitez-en pour réviser !</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    
    <div class="modal" id="examModal">
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
        
        function showExamDetails(examId) {
            fetch('../includes/get_exam_details.php?id=' + examId)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('modalContent').innerHTML = html;
                    document.getElementById('examModal').style.display = 'flex';
                });
        }
        
        function closeModal() {
            document.getElementById('examModal').style.display = 'none';
        }
        
        
        <?php if ($view == 'week'): ?>
        document.addEventListener('DOMContentLoaded', function() {
            <?php foreach ($examens as $examen): 
                $start_hour = (int)date('H', strtotime($examen['date_heure']));
                $start_minute = (int)date('i', strtotime($examen['date_heure']));
                $end_hour = $start_hour + ceil($examen['duree_minutes'] / 60);
                $day = date('Y-m-d', strtotime($examen['date_heure']));
                
                
                $top = (($start_hour - 8) * 60 + $start_minute) * 0.8; // 0.8px per minute
                $height = $examen['duree_minutes'] * 0.8;
            ?>
                const cell = document.getElementById('cell-<?php echo $day; ?>-<?php echo $start_hour; ?>');
                if (cell) {
                    const examBlock = document.createElement('div');
                    examBlock.className = 'exam-block';
                    examBlock.style.top = '<?php echo $top; ?>px';
                    examBlock.style.height = '<?php echo $height; ?>px';
                    examBlock.style.left = '5px';
                    examBlock.style.right = '5px';
                    examBlock.innerHTML = `
                        <div style="font-weight: 600;"><?php echo date('H:i', strtotime($examen['date_heure'])); ?></div>
                        <div style="font-size: 0.8rem;"><?php echo truncate_text(htmlspecialchars($examen['module_nom']), 20); ?></div>
                        <div style="font-size: 0.75rem; margin-top: 2px;"><?php echo htmlspecialchars($examen['salle_nom']); ?></div>
                    `;
                    examBlock.onclick = () => showExamDetails(<?php echo $examen['id']; ?>);
                    cell.appendChild(examBlock);
                }
            <?php endforeach; ?>
        });
        <?php endif; ?>
        
        
        document.getElementById('examModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
    </script>
</body>
</html>