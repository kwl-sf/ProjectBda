<?php
// doyen/reports.php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Vérifier que l'utilisateur est doyen ou vice-doyen
require_role(['doyen', 'vice_doyen']);

$user = get_logged_in_user();

// Récupérer les paramètres
$report_type = $_GET['type'] ?? 'global';
$start_date = $_GET['start'] ?? date('Y-m-01');
$end_date = $_GET['end'] ?? date('Y-m-t');
$dept_id = $_GET['dept'] ?? null;

// Générer le rapport
$report_data = [];
$report_title = '';

switch ($report_type) {
    case 'global':
        $report_title = 'Rapport Global de la Faculté';
        $report_data = generate_global_report($pdo, $start_date, $end_date, $dept_id);
        break;
        
    case 'examens':
        $report_title = 'Rapport des Examens';
        $report_data = generate_exam_report($pdo, $start_date, $end_date, $dept_id);
        break;
        
    case 'ressources':
        $report_title = 'Utilisation des Ressources';
        $report_data = generate_resource_report($pdo, $start_date, $end_date, $dept_id);
        break;
        
    case 'performance':
        $report_title = 'Performance Académique';
        $report_data = generate_performance_report($pdo, $start_date, $end_date, $dept_id);
        break;
}

// Récupérer les départements pour filtre
$stmt = $pdo->prepare("SELECT id, nom FROM departements ORDER BY nom");
$stmt->execute();
$departements = $stmt->fetchAll();

$page_title = "Rapports - Doyenné";

// Fonctions de génération de rapports
function generate_global_report($pdo, $start_date, $end_date, $dept_id) {
    $sql = "
        SELECT 
            'Départements' as category,
            COUNT(DISTINCT d.id) as value,
            'Nombre total' as unit
        FROM departements d
        WHERE 1=1
    ";
    
    if ($dept_id) {
        $sql .= " AND d.id = :dept_id";
    }
    
    $sql .= "
        UNION ALL
        SELECT 
            'Formations' as category,
            COUNT(DISTINCT f.id) as value,
            'Nombre total' as unit
        FROM formations f
        LEFT JOIN departements d ON f.dept_id = d.id
        WHERE 1=1
    ";
    
    if ($dept_id) {
        $sql .= " AND d.id = :dept_id";
    }
    
    $sql .= "
        UNION ALL
        SELECT 
            'Examens planifiés' as category,
            COUNT(DISTINCT e.id) as value,
            'Nombre total' as unit
        FROM examens e
        LEFT JOIN modules m ON e.module_id = m.id
        LEFT JOIN formations f ON m.formation_id = f.id
        LEFT JOIN departements d ON f.dept_id = d.id
        WHERE e.date_heure BETWEEN :start_date AND :end_date
    ";
    
    if ($dept_id) {
        $sql .= " AND d.id = :dept_id";
    }
    
    $sql .= "
        UNION ALL
        SELECT 
            'Salles utilisées' as category,
            COUNT(DISTINCT e.salle_id) as value,
            'Nombre unique' as unit
        FROM examens e
        LEFT JOIN modules m ON e.module_id = m.id
        LEFT JOIN formations f ON m.formation_id = f.id
        LEFT JOIN departements d ON f.dept_id = d.id
        WHERE e.date_heure BETWEEN :start_date AND :end_date
    ";
    
    if ($dept_id) {
        $sql .= " AND d.id = :dept_id";
    }
    
    $params = [
        ':start_date' => $start_date . ' 00:00:00',
        ':end_date' => $end_date . ' 23:59:59'
    ];
    
    if ($dept_id) {
        $params[':dept_id'] = $dept_id;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function generate_exam_report($pdo, $start_date, $end_date, $dept_id) {
    $sql = "
        SELECT 
            'Examens normaux' as category,
            COUNT(DISTINCT CASE WHEN e.session = 'normale' THEN e.id END) as value,
            'Nombre' as unit
        FROM examens e
        LEFT JOIN modules m ON e.module_id = m.id
        LEFT JOIN formations f ON m.formation_id = f.id
        LEFT JOIN departements d ON f.dept_id = d.id
        WHERE e.date_heure BETWEEN :start_date AND :end_date
    ";
    
    if ($dept_id) {
        $sql .= " AND d.id = :dept_id";
    }
    
    $sql .= "
        UNION ALL
        SELECT 
            'Examens rattrapage' as category,
            COUNT(DISTINCT CASE WHEN e.session = 'rattrapage' THEN e.id END) as value,
            'Nombre' as unit
        FROM examens e
        LEFT JOIN modules m ON e.module_id = m.id
        LEFT JOIN formations f ON m.formation_id = f.id
        LEFT JOIN departements d ON f.dept_id = d.id
        WHERE e.date_heure BETWEEN :start_date AND :end_date
    ";
    
    if ($dept_id) {
        $sql .= " AND d.id = :dept_id";
    }
    
    $sql .= "
        UNION ALL
        SELECT 
            'Heures totales' as category,
            SUM(e.duree_minutes) / 60 as value,
            'Heures' as unit
        FROM examens e
        LEFT JOIN modules m ON e.module_id = m.id
        LEFT JOIN formations f ON m.formation_id = f.id
        LEFT JOIN departements d ON f.dept_id = d.id
        WHERE e.date_heure BETWEEN :start_date AND :end_date
    ";
    
    if ($dept_id) {
        $sql .= " AND d.id = :dept_id";
    }
    
    $sql .= "
        UNION ALL
        SELECT 
            'Taux occupation salles' as category,
            ROUND(AVG(
                (SELECT COUNT(*) FROM examens_etudiants ee WHERE ee.examen_id = e.id) * 100.0 / 
                (SELECT capacite FROM lieu_examen WHERE id = e.salle_id)
            ), 2) as value,
            'Pourcentage' as unit
        FROM examens e
        LEFT JOIN modules m ON e.module_id = m.id
        LEFT JOIN formations f ON m.formation_id = f.id
        LEFT JOIN departements d ON f.dept_id = d.id
        WHERE e.date_heure BETWEEN :start_date AND :end_date
    ";
    
    if ($dept_id) {
        $sql .= " AND d.id = :dept_id";
    }
    
    $params = [
        ':start_date' => $start_date . ' 00:00:00',
        ':end_date' => $end_date . ' 23:59:59'
    ];
    
    if ($dept_id) {
        $params[':dept_id'] = $dept_id;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function generate_resource_report($pdo, $start_date, $end_date, $dept_id) {
    // Similaire aux autres fonctions
    $sql = "
        SELECT 
            'Salles amphis' as category,
            COUNT(DISTINCT CASE WHEN l.type = 'amphi' THEN l.id END) as value,
            'Nombre' as unit
        FROM lieu_examen l
        WHERE l.disponible = 1
    ";
    
    // ... (le reste de la fonction)
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll();
}

function generate_performance_report($pdo, $start_date, $end_date, $dept_id) {
    $sql = "
        SELECT 
            'Taux réussite moyen' as category,
            ROUND(AVG(i.note), 2) as value,
            'Note / 20' as unit
        FROM inscriptions i
        LEFT JOIN etudiants e ON i.etudiant_id = e.id
        LEFT JOIN formations f ON e.formation_id = f.id
        LEFT JOIN departements d ON f.dept_id = d.id
        WHERE i.annee_scolaire = '2025-2026'
        AND i.note IS NOT NULL
    ";
    
    if ($dept_id) {
        $sql .= " AND d.id = :dept_id";
    }
    
    $params = [];
    
    if ($dept_id) {
        $params[':dept_id'] = $dept_id;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
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
        .reports-header {
            background: linear-gradient(135deg, #8e44ad 0%, #9b59b6 100%);
            color: white;
            padding: 2rem;
            border-radius: var(--border-radius);
            margin-bottom: 2rem;
        }
        
        .report-types {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }
        
        .report-type-btn {
            padding: 1rem 1.5rem;
            background: white;
            border: 2px solid var(--gray-200);
            border-radius: var(--border-radius-sm);
            cursor: pointer;
            font-weight: 500;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            min-width: 200px;
        }
        
        .report-type-btn:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }
        
        .report-type-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .report-type-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--gray-100);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        
        .report-type-btn.active .report-type-icon {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }
        
        .report-filters {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            margin-bottom: 2rem;
            box-shadow: var(--shadow-sm);
        }
        
        .report-content {
            background: white;
            border-radius: var(--border-radius);
            padding: 2rem;
            box-shadow: var(--shadow-sm);
        }
        
        .report-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .summary-card {
            background: var(--gray-50);
            border-radius: var(--border-radius-sm);
            padding: 1.5rem;
            text-align: center;
        }
        
        .summary-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }
        
        .summary-label {
            font-size: 1rem;
            color: var(--gray-700);
            font-weight: 500;
        }
        
        .summary-unit {
            font-size: 0.9rem;
            color: var(--gray-600);
            margin-top: 0.25rem;
        }
        
        .report-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 2rem;
        }
        
        .report-table th {
            background: var(--gray-100);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--gray-700);
            border-bottom: 2px solid var(--gray-200);
        }
        
        .report-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .report-table tr:hover {
            background: var(--gray-50);
        }
        
        .report-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid var(--gray-200);
        }
        
        .empty-report {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--gray-500);
        }
        
        .empty-report i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .date-inputs {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        
        @media print {
            .report-types,
            .report-filters,
            .report-actions,
            .header-actions {
                display: none;
            }
            
            .reports-header {
                background: white !important;
                color: black !important;
                padding: 1rem 0;
                border: 1px solid #000;
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
                    <h1>Rapports - Doyenné</h1>
                    <p>Génération de rapports stratégiques</p>
                </div>
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="printReport()">
                        <i class="fas fa-print"></i> Imprimer
                    </button>
                    <button class="btn btn-success" onclick="exportPDF()">
                        <i class="fas fa-file-pdf"></i> Exporter PDF
                    </button>
                </div>
            </header>
            
            <div class="reports-header">
                <h2><i class="fas fa-chart-pie"></i> <?php echo $report_title; ?></h2>
                <p>Période: <?php echo date('d/m/Y', strtotime($start_date)); ?> - <?php echo date('d/m/Y', strtotime($end_date)); ?></p>
            </div>
            
            <!-- Types de rapports -->
            <div class="report-types">
                <a href="?type=global&start=<?php echo $start_date; ?>&end=<?php echo $end_date; ?>&dept=<?php echo $dept_id; ?>" 
                   class="report-type-btn <?php echo $report_type == 'global' ? 'active' : ''; ?>">
                    <div class="report-type-icon">
                        <i class="fas fa-university"></i>
                    </div>
                    <div>
                        <div>Global</div>
                        <small>Vue d'ensemble</small>
                    </div>
                </a>
                
                <a href="?type=examens&start=<?php echo $start_date; ?>&end=<?php echo $end_date; ?>&dept=<?php echo $dept_id; ?>" 
                   class="report-type-btn <?php echo $report_type == 'examens' ? 'active' : ''; ?>">
                    <div class="report-type-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div>
                        <div>Examens</div>
                        <small>Statistiques examens</small>
                    </div>
                </a>
                
                <a href="?type=ressources&start=<?php echo $start_date; ?>&end=<?php echo $end_date; ?>&dept=<?php echo $dept_id; ?>" 
                   class="report-type-btn <?php echo $report_type == 'ressources' ? 'active' : ''; ?>">
                    <div class="report-type-icon">
                        <i class="fas fa-building"></i>
                    </div>
                    <div>
                        <div>Ressources</div>
                        <small>Utilisation salles</small>
                    </div>
                </a>
                
                <a href="?type=performance&start=<?php echo $start_date; ?>&end=<?php echo $end_date; ?>&dept=<?php echo $dept_id; ?>" 
                   class="report-type-btn <?php echo $report_type == 'performance' ? 'active' : ''; ?>">
                    <div class="report-type-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div>
                        <div>Performance</div>
                        <small>Résultats académiques</small>
                    </div>
                </a>
            </div>
            
            <!-- Filtres -->
            <div class="report-filters">
                <form method="GET" id="reportForm">
                    <input type="hidden" name="type" value="<?php echo $report_type; ?>">
                    
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label class="filter-label">Département</label>
                            <select name="dept" class="form-select" onchange="document.getElementById('reportForm').submit()">
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
                            <label class="filter-label">Période</label>
                            <div class="date-inputs">
                                <input type="date" name="start" class="form-control" 
                                       value="<?php echo $start_date; ?>"
                                       onchange="document.getElementById('reportForm').submit()">
                                <span>à</span>
                                <input type="date" name="end" class="form-control" 
                                       value="<?php echo $end_date; ?>"
                                       onchange="document.getElementById('reportForm').submit()">
                            </div>
                        </div>
                        
                        <div class="filter-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Appliquer
                            </button>
                            <a href="reports.php" class="btn btn-outline">
                                <i class="fas fa-redo"></i> Réinitialiser
                            </a>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Contenu du rapport -->
            <div class="report-content">
                <?php if (empty($report_data)): ?>
                    <div class="empty-report">
                        <i class="fas fa-file-alt fa-3x"></i>
                        <h3>Aucune donnée disponible</h3>
                        <p>Modifiez les filtres ou essayez une autre période</p>
                    </div>
                <?php else: ?>
                    <!-- Résumé -->
                    <div class="report-summary">
                        <?php foreach ($report_data as $item): ?>
                            <div class="summary-card">
                                <div class="summary-value"><?php echo $item['value']; ?></div>
                                <div class="summary-label"><?php echo $item['category']; ?></div>
                                <div class="summary-unit"><?php echo $item['unit']; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Tableau détaillé -->
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th width="50%">Indicateur</th>
                                <th width="25%">Valeur</th>
                                <th width="25%">Unité</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report_data as $item): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($item['category']); ?></td>
                                    <td>
                                        <span style="font-weight: 600; color: var(--primary);">
                                            <?php echo $item['value']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $item['unit']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <!-- Actions -->
                    <div class="report-actions">
                        <button class="btn btn-primary" onclick="generateDetailedReport()">
                            <i class="fas fa-file-excel"></i> Générer rapport détaillé
                        </button>
                        <button class="btn btn-outline" onclick="scheduleReport()">
                            <i class="fas fa-clock"></i> Planifier ce rapport
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <script>
        function printReport() {
            window.print();
        }
        
        function exportPDF() {
            alert('Fonction d\'export PDF à implémenter');
            // Utiliser une bibliothèque comme jsPDF ou envoyer une requête au serveur
        }
        
        function generateDetailedReport() {
            const params = new URLSearchParams(window.location.search);
            window.location.href = 'generate_report.php?' + params.toString() + '&format=excel';
        }
        
        function scheduleReport() {
            const modal = document.createElement('div');
            modal.innerHTML = `
                <div style="position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; z-index:1000;">
                    <div style="background:white; padding:2rem; border-radius:10px; width:90%; max-width:500px;">
                        <h3>Planifier ce rapport</h3>
                        <p>Ce rapport sera généré automatiquement et envoyé par email.</p>
                        
                        <div style="margin:1rem 0;">
                            <label>Fréquence</label>
                            <select class="form-control" style="width:100%; padding:0.5rem; margin-top:0.5rem;">
                                <option>Quotidien</option>
                                <option>Hebdomadaire</option>
                                <option>Mensuel</option>
                            </select>
                        </div>
                        
                        <div style="margin:1rem 0;">
                            <label>Email de destination</label>
                            <input type="email" class="form-control" value="<?php echo $user['email']; ?>" style="width:100%; padding:0.5rem; margin-top:0.5rem;">
                        </div>
                        
                        <div style="display:flex; gap:1rem; margin-top:2rem;">
                            <button class="btn btn-primary" onclick="alert('Planification confirmée!')">Confirmer</button>
                            <button class="btn btn-outline" onclick="this.closest('div[style*=\"position:fixed\"]').remove()">Annuler</button>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
        }
    </script>
</body>
</html>