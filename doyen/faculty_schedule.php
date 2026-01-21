<?php
// doyen/faculty_schedule.php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Vérifier que l'utilisateur est doyen ou vice-doyen
require_role(['doyen', 'vice_doyen']);

$user = get_logged_in_user();

// Récupérer les paramètres de filtrage
$dept_id = $_GET['dept'] ?? null;
$date = $_GET['date'] ?? date('Y-m-d');
$session = $_GET['session'] ?? 'normale';

// Récupérer tous les départements pour le filtre
$stmt = $pdo->prepare("SELECT id, nom FROM departements ORDER BY nom");
$stmt->execute();
$departements = $stmt->fetchAll();

// Récupérer les examens
$sql = "
    SELECT 
        e.*,
        m.nom as module_nom,
        m.credits,
        p.nom as prof_nom,
        p.prenom as prof_prenom,
        d.nom as dept_nom,
        l.nom as salle_nom,
        l.type as salle_type,
        l.capacite,
        f.nom as formation_nom,
        COUNT(ee.id) as nombre_etudiants
    FROM examens e
    JOIN modules m ON e.module_id = m.id
    JOIN formations f ON m.formation_id = f.id
    JOIN departements d ON f.dept_id = d.id
    JOIN professeurs p ON e.prof_id = p.id
    JOIN lieu_examen l ON e.salle_id = l.id
    LEFT JOIN examens_etudiants ee ON e.id = ee.examen_id
    WHERE e.session = :session
    AND DATE(e.date_heure) = :date
";

$params = [
    ':session' => $session,
    ':date' => $date
];

if ($dept_id) {
    $sql .= " AND d.id = :dept_id";
    $params[':dept_id'] = $dept_id;
}

$sql .= " GROUP BY e.id ORDER BY e.date_heure";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$examens = $stmt->fetchAll();

// Organiser par créneau horaire
$creneaux = [];
foreach ($examens as $examen) {
    $heure = date('H:i', strtotime($examen['date_heure']));
    $creneaux[$heure][] = $examen;
}

// Récupérer les statistiques de la journée
$sql_stats = "
    SELECT 
        COUNT(DISTINCT e.id) as total_examens,
        COUNT(DISTINCT l.id) as salles_utilisees,
        COUNT(DISTINCT p.id) as profs_impliques,
        SUM(l.capacite) as capacite_totale,
        SUM(ee_count.nombre_etudiants) as etudiants_total
    FROM examens e
    JOIN modules m ON e.module_id = m.id
    JOIN formations f ON m.formation_id = f.id
    JOIN departements d ON f.dept_id = d.id
    JOIN professeurs p ON e.prof_id = p.id
    JOIN lieu_examen l ON e.salle_id = l.id
    LEFT JOIN (
        SELECT examen_id, COUNT(*) as nombre_etudiants 
        FROM examens_etudiants 
        GROUP BY examen_id
    ) ee_count ON e.id = ee_count.examen_id
    WHERE e.session = :session
    AND DATE(e.date_heure) = :date
";

if ($dept_id) {
    $sql_stats .= " AND d.id = :dept_id";
}

$stmt_stats = $pdo->prepare($sql_stats);
$stmt_stats->execute($params);
$stats_journee = $stmt_stats->fetch();

$page_title = "Planning de la Faculté";
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
        .planning-header {
            background: linear-gradient(135deg, #8B0000 0%, #DC143C 100%);
            color: white;
            padding: 2rem;
            border-radius: var(--border-radius);
            margin-bottom: 2rem;
        }
        
        .filters-container {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            margin-bottom: 2rem;
            box-shadow: var(--shadow-sm);
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            align-items: end;
        }
        
        .filter-group {
            margin-bottom: 0;
        }
        
        .filter-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--gray-700);
        }
        
        .stat-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card-small {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius-sm);
            text-align: center;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }
        
        .stat-card-small:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }
        
        .stat-card-small .number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }
        
        .stat-card-small .label {
            font-size: 0.9rem;
            color: var(--gray-600);
        }
        
        .timeline-container {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }
        
        .timeline-header {
            background: var(--gray-100);
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            display: grid;
            grid-template-columns: 100px 1fr;
            gap: 1rem;
            font-weight: 600;
            color: var(--gray-700);
        }
        
        .timeline-slot {
            border-bottom: 1px solid var(--gray-200);
        }
        
        .slot-header {
            background: var(--gray-50);
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            color: var(--gray-700);
            display: grid;
            grid-template-columns: 100px 1fr;
            gap: 1rem;
        }
        
        .slot-exams {
            padding: 0;
        }
        
        .exam-card {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            transition: var(--transition);
            display: grid;
            grid-template-columns: 100px 1fr;
            gap: 1rem;
            align-items: center;
        }
        
        .exam-card:hover {
            background: var(--gray-50);
        }
        
        .exam-card:last-child {
            border-bottom: none;
        }
        
        .exam-info {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: center;
        }
        
        .exam-dept {
            background: var(--primary-light);
            color: var(--primary-dark);
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .exam-module {
            font-weight: 600;
            color: var(--gray-800);
        }
        
        .exam-details {
            display: flex;
            gap: 1.5rem;
            font-size: 0.9rem;
            color: var(--gray-600);
            margin-top: 0.5rem;
        }
        
        .exam-detail-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .capacity-indicator {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .capacity-bar {
            width: 100px;
            height: 8px;
            background: var(--gray-200);
            border-radius: 4px;
            overflow: hidden;
        }
        
        .capacity-fill {
            height: 100%;
            background: var(--success);
        }
        
        .capacity-fill.high {
            background: var(--warning);
        }
        
        .capacity-fill.full {
            background: var(--danger);
        }
        
        .no-exams {
            padding: 3rem 1.5rem;
            text-align: center;
            color: var(--gray-500);
        }
        
        .no-exams i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        @media print {
            .filters-container,
            .stat-cards-grid,
            .header-actions {
                display: none;
            }
            
            .planning-header {
                background: white !important;
                color: black !important;
                padding: 1rem 0;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        <!-- Sidebar identique -->
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="main-content">
            <header class="header">
                <div class="header-left">
                    <h1>Planning de la Faculté</h1>
                    <p>Vue globale des examens de la faculté</p>
                </div>
                <div class="header-actions">
                    <button class="btn btn-outline" onclick="window.print()">
                        <i class="fas fa-print"></i> Imprimer
                    </button>
                    <button class="btn btn-primary" onclick="exportToExcel()">
                        <i class="fas fa-file-excel"></i> Exporter
                    </button>
                </div>
            </header>
            
            <!-- En-tête avec stats -->
            <div class="planning-header">
                <h2>
                    <i class="fas fa-calendar-alt"></i>
                    Examens du <?php echo date('d/m/Y', strtotime($date)); ?>
                    <?php if ($dept_id && isset($departements[$dept_id])): ?>
                        - Département <?php echo htmlspecialchars($departements[$dept_id]['nom']); ?>
                    <?php endif; ?>
                </h2>
                <p>Session <?php echo ucfirst($session); ?></p>
            </div>
            
            <!-- Filtres -->
            <div class="filters-container">
                <form method="GET" id="filterForm">
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label class="filter-label">Département</label>
                            <select name="dept" class="form-select" onchange="document.getElementById('filterForm').submit()">
                                <option value="">Tous les départements</option>
                                <?php foreach ($departements as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>" 
                                            <?php echo $dept_id == $dept['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dept['nom']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Date</label>
                            <input type="date" name="date" class="form-control datepicker" 
                                   value="<?php echo $date; ?>"
                                   onchange="document.getElementById('filterForm').submit()">
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Session</label>
                            <select name="session" class="form-select" onchange="document.getElementById('filterForm').submit()">
                                <option value="normale" <?php echo $session == 'normale' ? 'selected' : ''; ?>>Normale</option>
                                <option value="rattrapage" <?php echo $session == 'rattrapage' ? 'selected' : ''; ?>>Rattrapage</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Filtrer
                            </button>
                            <a href="faculty_schedule.php" class="btn btn-outline">
                                <i class="fas fa-redo"></i> Réinitialiser
                            </a>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Statistiques -->
            <div class="stat-cards-grid">
                <div class="stat-card-small">
                    <div class="number"><?php echo $stats_journee['total_examens'] ?? 0; ?></div>
                    <div class="label">Examens</div>
                </div>
                
                <div class="stat-card-small">
                    <div class="number"><?php echo $stats_journee['salles_utilisees'] ?? 0; ?></div>
                    <div class="label">Salles utilisées</div>
                </div>
                
                <div class="stat-card-small">
                    <div class="number"><?php echo $stats_journee['profs_impliques'] ?? 0; ?></div>
                    <div class="label">Professeurs impliqués</div>
                </div>
                
                <div class="stat-card-small">
                    <div class="number"><?php echo $stats_journee['etudiants_total'] ?? 0; ?></div>
                    <div class="label">Étudiants concernés</div>
                </div>
                
                <div class="stat-card-small">
                    <div class="number"><?php echo $stats_journee['capacite_totale'] ?? 0; ?></div>
                    <div class="label">Capacité totale</div>
                </div>
            </div>
            
            <!-- Planning -->
            <div class="timeline-container">
                <div class="timeline-header">
                    <div>Horaire</div>
                    <div>Examens</div>
                </div>
                
                <?php if (empty($creneaux)): ?>
                    <div class="no-exams">
                        <i class="fas fa-calendar-times"></i>
                        <h3>Aucun examen programmé pour cette journée</h3>
                        <p>Essayez une autre date ou département</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($creneaux as $heure => $examens_heure): ?>
                        <div class="timeline-slot">
                            <div class="slot-header">
                                <div><?php echo $heure; ?></div>
                                <div><?php echo count($examens_heure); ?> examen(s)</div>
                            </div>
                            <div class="slot-exams">
                                <?php foreach ($examens_heure as $examen): 
                                    $occupation_rate = ($examen['nombre_etudiants'] / $examen['capacite']) * 100;
                                    $capacity_class = '';
                                    if ($occupation_rate > 90) $capacity_class = 'full';
                                    elseif ($occupation_rate > 70) $capacity_class = 'high';
                                ?>
                                    <div class="exam-card">
                                        <div>
                                            <span class="badge badge-secondary">
                                                <?php echo date('H:i', strtotime($examen['date_heure'])); ?>
                                            </span>
                                        </div>
                                        <div class="exam-info">
                                            <div style="flex: 1;">
                                                <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 0.5rem;">
                                                    <span class="exam-dept">
                                                        <?php echo htmlspecialchars($examen['dept_nom']); ?>
                                                    </span>
                                                    <span class="exam-module">
                                                        <?php echo htmlspecialchars($examen['module_nom']); ?>
                                                    </span>
                                                    <span class="badge badge-light">
                                                        <?php echo $examen['credits']; ?> crédits
                                                    </span>
                                                </div>
                                                
                                                <div class="exam-details">
                                                    <div class="exam-detail-item">
                                                        <i class="fas fa-user-tie"></i>
                                                        <?php echo htmlspecialchars($examen['prof_nom'] . ' ' . $examen['prof_prenom']); ?>
                                                    </div>
                                                    
                                                    <div class="exam-detail-item">
                                                        <i class="fas fa-building"></i>
                                                        <?php echo htmlspecialchars($examen['salle_nom']); ?>
                                                        (<?php echo ucfirst($examen['salle_type']); ?>)
                                                    </div>
                                                    
                                                    <div class="exam-detail-item capacity-indicator">
                                                        <i class="fas fa-users"></i>
                                                        <span><?php echo $examen['nombre_etudiants']; ?>/<?php echo $examen['capacite']; ?></span>
                                                        <div class="capacity-bar">
                                                            <div class="capacity-fill <?php echo $capacity_class; ?>" 
                                                                 style="width: <?php echo min($occupation_rate, 100); ?>%"></div>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="exam-detail-item">
                                                        <i class="fas fa-clock"></i>
                                                        <?php echo $examen['duree_minutes']; ?> min
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/fr.js"></script>
    <script>
        // Date picker
        flatpickr('.datepicker', {
            dateFormat: 'Y-m-d',
            locale: 'fr',
            minDate: 'today'
        });
        
        function exportToExcel() {
            const table = document.querySelector('.timeline-container');
            const html = table.outerHTML;
            const blob = new Blob([html], { type: 'application/vnd.ms-excel' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'planning_faculte_<?php echo date('Y-m-d'); ?>.xls';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }
    </script>
</body>
</html>