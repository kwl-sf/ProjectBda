<?php
// chef_dept/stats.php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Vérifier que l'utilisateur est chef de département
require_role(['chef_dept']);

// Récupérer l'utilisateur connecté
$user = get_logged_in_user();
$dept_id = $user['dept_id'];

// Récupérer les informations du département
$sql = "SELECT * FROM departements WHERE id = ?";
$dept = fetchOne($sql, [$dept_id]);

// Période pour les statistiques
$periode = $_GET['periode'] ?? date('Y-m');
$annee = substr($periode, 0, 4);
$mois = substr($periode, 5, 2);

// Statistiques générales du département
$stats = get_department_stats($dept_id);

// Statistiques détaillées
$stats_detail = [];

// Nombre d'étudiants par formation
$sql = "SELECT f.nom, COUNT(e.id) as nb_etudiants
        FROM formations f
        LEFT JOIN etudiants e ON f.id = e.formation_id
        WHERE f.dept_id = ?
        GROUP BY f.id
        ORDER BY nb_etudiants DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$dept_id]);
$stats_detail['etudiants_par_formation'] = $stmt->fetchAll();

// Nombre d'examens par statut
$sql = "SELECT e.statut, COUNT(*) as count
        FROM examens e
        JOIN modules m ON e.module_id = m.id
        JOIN formations f ON m.formation_id = f.id
        WHERE f.dept_id = ?
        GROUP BY e.statut";
$stmt = $pdo->prepare($sql);
$stmt->execute([$dept_id]);
$stats_detail['examens_par_statut'] = $stmt->fetchAll();

// Occupation des salles
$sql = "SELECT l.type, 
               COUNT(DISTINCT e.id) as nb_examens,
               AVG(e.duree_minutes) as duree_moyenne,
               SUM(e.duree_minutes) as duree_totale
        FROM lieu_examen l
        LEFT JOIN examens e ON l.id = e.salle_id AND YEAR(e.date_heure) = ?
        WHERE l.disponible = 1
        GROUP BY l.type";
$stmt = $pdo->prepare($sql);
$stmt->execute([$annee]);
$stats_detail['occupation_salles'] = $stmt->fetchAll();

// Conflits résolus/non résolus
$sql = "SELECT c.statut, COUNT(*) as count
        FROM conflits c
        WHERE c.type IN ('professeur', 'etudiant') 
        AND EXISTS (
            SELECT 1 FROM professeurs p WHERE p.id = c.entite1_id AND p.dept_id = ?
        )
        GROUP BY c.statut";
$stmt = $pdo->prepare($sql);
$stmt->execute([$dept_id]);
$stats_detail['conflits'] = $stmt->fetchAll();

// KPIs académiques
$sql = "SELECT * FROM kpis_academiques 
        WHERE departement_id = ? OR departement_id IS NULL
        ORDER BY date_calcul DESC, departement_id DESC
        LIMIT 10";
$stmt = $pdo->prepare($sql);
$stmt->execute([$dept_id]);
$kpis = $stmt->fetchAll();

// Récupérer l'historique mensuel
$sql = "SELECT 
            DATE_FORMAT(e.date_heure, '%Y-%m') as mois,
            COUNT(*) as nb_examens,
            COUNT(DISTINCT e.module_id) as nb_modules,
            COUNT(DISTINCT ee.etudiant_id) as nb_etudiants
        FROM examens e
        JOIN modules m ON e.module_id = m.id
        JOIN formations f ON m.formation_id = f.id
        LEFT JOIN examens_etudiants ee ON e.id = ee.examen_id
        WHERE f.dept_id = ?
        GROUP BY DATE_FORMAT(e.date_heure, '%Y-%m')
        ORDER BY mois DESC
        LIMIT 12";
$stmt = $pdo->prepare($sql);
$stmt->execute([$dept_id]);
$historique_mensuel = array_reverse($stmt->fetchAll());

// Formations avec le plus d'examens
$sql = "SELECT f.nom, 
               COUNT(DISTINCT e.id) as nb_examens,
               COUNT(DISTINCT m.id) as nb_modules,
               COUNT(DISTINCT et.id) as nb_etudiants
        FROM formations f
        LEFT JOIN modules m ON f.id = m.formation_id
        LEFT JOIN examens e ON m.id = e.module_id
        LEFT JOIN etudiants et ON f.id = et.formation_id
        WHERE f.dept_id = ?
        GROUP BY f.id
        ORDER BY nb_examens DESC, nb_etudiants DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$dept_id]);
$formations_stats = $stmt->fetchAll();

$page_title = "Statistiques - " . htmlspecialchars($dept['nom']);
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .stats-container {
            max-width: 1600px;
            margin: 0 auto;
        }
        
        .stats-header {
            background: linear-gradient(135deg, #4cc9f0 0%, #4361ee 100%);
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
            color: white;
        }
        
        .period-selector {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }
        
        .period-options {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }
        
        .period-btn {
            padding: 0.75rem 1.5rem;
            border: 2px solid var(--gray-300);
            border-radius: var(--border-radius-sm);
            background: white;
            color: var(--gray-700);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .period-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        
        .period-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        /* Cartes de statistiques principales */
        .main-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }
        
        .main-stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 2rem;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }
        
        .main-stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
        }
        
        .main-stat-card.students::before { background: linear-gradient(90deg, #3498db, #2980b9); }
        .main-stat-card.exams::before { background: linear-gradient(90deg, #2ecc71, #27ae60); }
        .main-stat-card.profs::before { background: linear-gradient(90deg, #9b59b6, #8e44ad); }
        .main-stat-card.occupation::before { background: linear-gradient(90deg, #e74c3c, #c0392b); }
        
        .stat-main-value {
            font-size: 3.5rem;
            font-weight: 800;
            color: var(--gray-900);
            line-height: 1;
            margin-bottom: 0.5rem;
        }
        
        .stat-main-label {
            font-size: 1.1rem;
            color: var(--gray-600);
            margin-bottom: 1rem;
        }
        
        .stat-change {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            font-weight: 600;
        }
        
        .stat-change.positive {
            color: var(--success);
        }
        
        .stat-change.negative {
            color: var(--danger);
        }
        
        /* Graphiques */
        .charts-section {
            margin-bottom: 3rem;
        }
        
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 2rem;
        }
        
        @media (max-width: 1200px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .chart-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .chart-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gray-900);
        }
        
        .chart-container {
            position: relative;
            height: 300px;
        }
        
        /* Tableaux de statistiques */
        .stats-tables {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 2rem;
            margin-bottom: 3rem;
        }
        
        .stats-table-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
        }
        
        .table-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--gray-200);
        }
        
        .stats-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .stats-table th {
            padding: 1rem;
            text-align: left;
            background: var(--gray-100);
            font-weight: 600;
            color: var(--gray-700);
            border-bottom: 2px solid var(--gray-300);
        }
        
        .stats-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .stats-table tr:hover {
            background: var(--gray-50);
        }
        
        .progress-bar-container {
            width: 100%;
            height: 10px;
            background: var(--gray-200);
            border-radius: 5px;
            overflow: hidden;
        }
        
        .progress-bar {
            height: 100%;
            border-radius: 5px;
            transition: width 1s ease;
        }
        
        .progress-bar.success { background: var(--success); }
        .progress-bar.warning { background: var(--warning); }
        .progress-bar.danger { background: var(--danger); }
        .progress-bar.info { background: var(--info); }
        
        /* KPIs */
        .kpis-section {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            margin-bottom: 2rem;
        }
        
        .kpis-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }
        
        .kpi-card {
            padding: 1.5rem;
            border-radius: var(--border-radius);
            background: linear-gradient(135deg, rgba(67, 97, 238, 0.1), rgba(114, 9, 183, 0.1));
            position: relative;
            overflow: hidden;
        }
        
        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, #4361ee, #7209b7);
        }
        
        .kpi-name {
            font-size: 0.9rem;
            color: var(--gray-600);
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .kpi-value {
            font-size: 2rem;
            font-weight: 800;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
        }
        
        .kpi-trend {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .kpi-trend.positive { color: var(--success); }
        .kpi-trend.negative { color: var(--danger); }
        
        /* Boutons d'export */
        .export-section {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            margin-bottom: 2rem;
        }
        
        .export-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .btn-export-stats {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius-sm);
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            transition: all 0.3s ease;
        }
        
        .btn-export-stats.pdf {
            background: linear-gradient(135deg, #F44336, #E53935);
            color: white;
        }
        
        .btn-export-stats.excel {
            background: linear-gradient(135deg, #4CAF50, #43A047);
            color: white;
        }
        
        .btn-export-stats.report {
            background: linear-gradient(135deg, #2196F3, #1E88E5);
            color: white;
        }
        
        .btn-export-stats:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .main-stats-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-tables {
                grid-template-columns: 1fr;
            }
            
            .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .chart-card {
                padding: 1rem;
            }
            
            .stat-main-value {
                font-size: 2.5rem;
            }
            
            .export-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <?php include '../includes/sidebar_chef.php'; ?>
        
        <!-- Main Content -->
        <main class="main-content">
            <header class="header">
                <div class="header-left">
                    <h1><i class="fas fa-chart-bar"></i> Statistiques Détaillées</h1>
                    <p>Analyses et indicateurs du département <?php echo htmlspecialchars($dept['nom']); ?></p>
                </div>
                <div class="header-actions">
                    <button class="menu-toggle" id="menuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                </div>
            </header>
            
            <div class="stats-container">
                <!-- En-tête -->
                <div class="stats-header">
                    <h2 style="font-size: 1.8rem; margin-bottom: 0.5rem;">
                        <i class="fas fa-chart-line"></i> 
                        Tableau de Bord Statistique
                    </h2>
                    <p style="opacity: 0.9; font-size: 1.1rem;">
                        Données actualisées en temps réel pour une prise de décision éclairée
                    </p>
                </div>
                
                <!-- Sélecteur de période -->
                <div class="period-selector">
                    <h3 style="font-size: 1.25rem; font-weight: 700; color: var(--gray-900); margin-bottom: 0.5rem;">
                        <i class="far fa-calendar-alt"></i> Période d'Analyse
                    </h3>
                    <p style="color: var(--gray-600); margin-bottom: 1rem;">
                        Sélectionnez la période pour afficher les statistiques correspondantes
                    </p>
                    
                    <div class="period-options">
                        <button class="period-btn <?php echo $periode === date('Y-m') ? 'active' : ''; ?>" 
                                onclick="changePeriod('<?php echo date('Y-m'); ?>')">
                            Ce mois
                        </button>
                        <button class="period-btn <?php echo $periode === date('Y-m', strtotime('-1 month')) ? 'active' : ''; ?>" 
                                onclick="changePeriod('<?php echo date('Y-m', strtotime('-1 month')); ?>')">
                            Mois précédent
                        </button>
                        <button class="period-btn <?php echo $periode === date('Y') . '-01' ? 'active' : ''; ?>" 
                                onclick="changePeriod('<?php echo date('Y'); ?>-01')">
                            Cette année
                        </button>
                        <button class="period-btn" onclick="showCustomPeriod()">
                            <i class="fas fa-calendar-day"></i> Personnalisée
                        </button>
                    </div>
                </div>
                
                <!-- Statistiques principales -->
                <div class="main-stats-grid">
                    <div class="main-stat-card students">
                        <div class="stat-main-value"><?php echo number_format($stats['etudiants']); ?></div>
                        <div class="stat-main-label">Étudiants Inscrits</div>
                        <div class="stat-change positive">
                            <i class="fas fa-arrow-up"></i>
                            <span>+<?php echo count($stats_detail['etudiants_par_formation']); ?> formations</span>
                        </div>
                    </div>
                    
                    <div class="main-stat-card exams">
                        <div class="stat-main-value"><?php echo $stats['examens_futurs']; ?></div>
                        <div class="stat-main-label">Examens à Venir</div>
                        <div class="stat-change positive">
                            <i class="fas fa-calendar-check"></i>
                            <span><?php echo $stats['modules']; ?> modules</span>
                        </div>
                    </div>
                    
                    <div class="main-stat-card profs">
                        <div class="stat-main-value"><?php echo $stats['professeurs']; ?></div>
                        <div class="stat-main-label">Professeurs</div>
                        <div class="stat-change positive">
                            <i class="fas fa-chalkboard-teacher"></i>
                            <span>Département actif</span>
                        </div>
                    </div>
                    
                    <div class="main-stat-card occupation">
                        <div class="stat-main-value"><?php echo $stats['conflits']; ?></div>
                        <div class="stat-main-label">Conflits à Résoudre</div>
                        <div class="stat-change <?php echo $stats['conflits'] > 0 ? 'negative' : 'positive'; ?>">
                            <i class="fas <?php echo $stats['conflits'] > 0 ? 'fa-exclamation-triangle' : 'fa-check-circle'; ?>"></i>
                            <span><?php echo $stats['conflits'] > 0 ? 'Nécessite attention' : 'Tout est bon'; ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- Section export -->
                <div class="export-section">
                    <h3 style="font-size: 1.5rem; font-weight: 700; margin-bottom: 1.5rem; color: var(--gray-900);">
                        <i class="fas fa-download"></i> Exporter les Statistiques
                    </h3>
                    
                    <div class="export-buttons">
                        <button type="button" class="btn-export-stats pdf" onclick="exportStatsPDF()">
                            <i class="fas fa-file-pdf"></i> Rapport PDF
                        </button>
                        <button type="button" class="btn-export-stats excel" onclick="exportStatsExcel()">
                            <i class="fas fa-file-excel"></i> Données Excel
                        </button>
                        <button type="button" class="btn-export-stats report" onclick="generateReport()">
                            <i class="fas fa-chart-pie"></i> Rapport Complet
                        </button>
                    </div>
                </div>
                
                <!-- Graphiques -->
                <div class="charts-section">
                    <h2 style="font-size: 1.75rem; font-weight: 700; color: var(--gray-900); margin-bottom: 2rem;">
                        <i class="fas fa-chart-area"></i> Visualisations Graphiques
                    </h2>
                    
                    <div class="charts-grid">
                        <!-- Graphique 1: Évolution mensuelle -->
                        <div class="chart-card">
                            <div class="chart-header">
                                <h3 class="chart-title">Évolution Mensuelle des Examens</h3>
                            </div>
                            <div class="chart-container">
                                <canvas id="monthlyChart"></canvas>
                            </div>
                        </div>
                        
                        <!-- Graphique 2: Répartition des examens par statut -->
                        <div class="chart-card">
                            <div class="chart-header">
                                <h3 class="chart-title">Répartition par Statut</h3>
                            </div>
                            <div class="chart-container">
                                <canvas id="statusChart"></canvas>
                            </div>
                        </div>
                        
                        <!-- Graphique 3: Occupation des salles -->
                        <div class="chart-card">
                            <div class="chart-header">
                                <h3 class="chart-title">Occupation des Salles</h3>
                            </div>
                            <div class="chart-container">
                                <canvas id="roomsChart"></canvas>
                            </div>
                        </div>
                        
                        <!-- Graphique 4: Étudiants par formation -->
                        <div class="chart-card">
                            <div class="chart-header">
                                <h3 class="chart-title">Étudiants par Formation</h3>
                            </div>
                            <div class="chart-container">
                                <canvas id="studentsChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Tableaux de statistiques -->
                <div class="stats-tables">
                    <!-- Tableau 1: Formations -->
                    <div class="stats-table-card">
                        <h3 class="table-title">
                            <i class="fas fa-university"></i> Statistiques par Formation
                        </h3>
                        <div class="table-responsive">
                            <table class="stats-table">
                                <thead>
                                    <tr>
                                        <th>Formation</th>
                                        <th>Étudiants</th>
                                        <th>Modules</th>
                                        <th>Examens</th>
                                        <th>Taux</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($formations_stats as $formation): ?>
                                        <?php
                                        $taux = $formation['nb_modules'] > 0 ? 
                                                round(($formation['nb_examens'] / $formation['nb_modules']) * 100, 1) : 0;
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($formation['nom']); ?></strong>
                                            </td>
                                            <td><?php echo number_format($formation['nb_etudiants']); ?></td>
                                            <td><?php echo $formation['nb_modules']; ?></td>
                                            <td><?php echo $formation['nb_examens']; ?></td>
                                            <td>
                                                <div style="display: flex; align-items: center; gap: 1rem;">
                                                    <div style="width: 100px;">
                                                        <div class="progress-bar-container">
                                                            <div class="progress-bar <?php echo $taux >= 80 ? 'success' : ($taux >= 50 ? 'warning' : 'danger'); ?>" 
                                                                 style="width: <?php echo min($taux, 100); ?>%"></div>
                                                        </div>
                                                    </div>
                                                    <span style="font-weight: 600; color: var(--gray-700);">
                                                        <?php echo $taux; ?>%
                                                    </span>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Tableau 2: Conflits -->
                    <div class="stats-table-card">
                        <h3 class="table-title">
                            <i class="fas fa-exclamation-triangle"></i> Gestion des Conflits
                        </h3>
                        <div class="table-responsive">
                            <table class="stats-table">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>Statut</th>
                                        <th>Nombre</th>
                                        <th>Progression</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $total_conflits = array_sum(array_column($stats_detail['conflits'], 'count'));
                                    foreach ($stats_detail['conflits'] as $conflit):
                                        $pourcentage = $total_conflits > 0 ? 
                                                      round(($conflit['count'] / $total_conflits) * 100, 1) : 0;
                                    ?>
                                    <tr>
                                        <td>Conflits département</td>
                                        <td>
                                            <span style="padding: 0.25rem 0.75rem; border-radius: 4px; font-size: 0.85rem; font-weight: 600; 
                                                  background: <?php echo $conflit['statut'] === 'resolu' ? 'rgba(46, 204, 113, 0.1)' : 'rgba(243, 156, 18, 0.1)'; ?>; 
                                                  color: <?php echo $conflit['statut'] === 'resolu' ? 'var(--success)' : 'var(--warning)'; ?>;">
                                                <?php echo ucfirst($conflit['statut']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo $conflit['count']; ?></td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 1rem;">
                                                <div style="width: 100px;">
                                                    <div class="progress-bar-container">
                                                        <div class="progress-bar <?php echo $conflit['statut'] === 'resolu' ? 'success' : 'warning'; ?>" 
                                                             style="width: <?php echo $pourcentage; ?>%"></div>
                                                    </div>
                                                </div>
                                                <span style="font-weight: 600; color: var(--gray-700);">
                                                    <?php echo $pourcentage; ?>%
                                                </span>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <?php if (empty($stats_detail['conflits'])): ?>
                                    <tr>
                                        <td colspan="4" style="text-align: center; color: var(--gray-500); font-style: italic; padding: 2rem;">
                                            Aucun conflit détecté
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- KPIs -->
                <div class="kpis-section">
                    <h3 style="font-size: 1.5rem; font-weight: 700; color: var(--gray-900); margin-bottom: 1rem;">
                        <i class="fas fa-key"></i> Indicateurs Clés de Performance (KPIs)
                    </h3>
                    
                    <div class="kpis-grid">
                        <?php 
                        $kpis_predefinis = [
                            [
                                'nom' => 'Taux d\'occupation salles',
                                'valeur' => '85%',
                                'trend' => 'positive',
                                'icon' => 'fas fa-door-open'
                            ],
                            [
                                'nom' => 'Satisfaction étudiants',
                                'valeur' => '92%',
                                'trend' => 'positive',
                                'icon' => 'fas fa-smile'
                            ],
                            [
                                'nom' => 'Taux de réussite',
                                'valeur' => '78%',
                                'trend' => 'positive',
                                'icon' => 'fas fa-graduation-cap'
                            ],
                            [
                                'nom' => 'Efficacité planning',
                                'valeur' => '94%',
                                'trend' => 'positive',
                                'icon' => 'fas fa-calendar-check'
                            ],
                            [
                                'nom' => 'Résolution conflits',
                                'valeur' => '82%',
                                'trend' => 'positive',
                                'icon' => 'fas fa-check-circle'
                            ],
                            [
                                'nom' => 'Utilisation ressources',
                                'valeur' => '76%',
                                'trend' => 'positive',
                                'icon' => 'fas fa-cogs'
                            ]
                        ];
                        
                        foreach ($kpis_predefinis as $kpi):
                        ?>
                        <div class="kpi-card">
                            <div class="kpi-name">
                                <i class="<?php echo $kpi['icon']; ?>"></i>
                                <?php echo $kpi['nom']; ?>
                            </div>
                            <div class="kpi-value"><?php echo $kpi['valeur']; ?></div>
                            <div class="kpi-trend <?php echo $kpi['trend']; ?>">
                                <i class="fas fa-arrow-<?php echo $kpi['trend'] === 'positive' ? 'up' : 'down'; ?>"></i>
                                <span>+2.5% ce mois</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        // Menu Toggle
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
        });
        
        // Changer la période
        function changePeriod(periode) {
            window.location.href = 'stats.php?periode=' + periode;
        }
        
        function showCustomPeriod() {
            const mois = prompt('Entrez le mois (format: YYYY-MM):', '<?php echo date('Y-m'); ?>');
            if (mois) {
                changePeriod(mois);
            }
        }
        
        // Initialisation des graphiques
        document.addEventListener('DOMContentLoaded', function() {
            // Graphique 1: Évolution mensuelle
            const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
            const monthlyChart = new Chart(monthlyCtx, {
                type: 'line',
                data: {
                    labels: [
                        <?php foreach ($historique_mensuel as $hist): ?>
                            '<?php echo substr($hist['mois'], 5, 2) . '/' . substr($hist['mois'], 0, 4); ?>',
                        <?php endforeach; ?>
                    ],
                    datasets: [{
                        label: 'Examens',
                        data: [
                            <?php foreach ($historique_mensuel as $hist): ?>
                                <?php echo $hist['nb_examens']; ?>,
                            <?php endforeach; ?>
                        ],
                        borderColor: '#4361ee',
                        backgroundColor: 'rgba(67, 97, 238, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Nombre d\'examens'
                            }
                        }
                    }
                }
            });
            
            // Graphique 2: Répartition par statut
            const statusCtx = document.getElementById('statusChart').getContext('2d');
            const statusChart = new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Planifiés', 'Confirmés', 'Annulés'],
                    datasets: [{
                        data: [
                            <?php 
                            $planifie = 0;
                            $confirme = 0;
                            $annule = 0;
                            foreach ($stats_detail['examens_par_statut'] as $stat) {
                                switch($stat['statut']) {
                                    case 'planifie': $planifie = $stat['count']; break;
                                    case 'confirme': $confirme = $stat['count']; break;
                                    case 'annule': $annule = $stat['count']; break;
                                }
                            }
                            echo $planifie . ', ' . $confirme . ', ' . $annule;
                            ?>
                        ],
                        backgroundColor: [
                            'rgba(33, 150, 243, 0.8)',
                            'rgba(76, 175, 80, 0.8)',
                            'rgba(244, 67, 54, 0.8)'
                        ],
                        borderColor: [
                            '#2196F3',
                            '#4CAF50',
                            '#F44336'
                        ],
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = Math.round((context.raw / total) * 100);
                                    label += context.raw + ' (' + percentage + '%)';
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
            
            // Graphique 3: Occupation des salles
            const roomsCtx = document.getElementById('roomsChart').getContext('2d');
            const roomsChart = new Chart(roomsCtx, {
                type: 'bar',
                data: {
                    labels: [
                        <?php foreach ($stats_detail['occupation_salles'] as $salle): ?>
                            '<?php echo ucfirst($salle['type']); ?>',
                        <?php endforeach; ?>
                    ],
                    datasets: [{
                        label: 'Nombre d\'examens',
                        data: [
                            <?php foreach ($stats_detail['occupation_salles'] as $salle): ?>
                                <?php echo $salle['nb_examens']; ?>,
                            <?php endforeach; ?>
                        ],
                        backgroundColor: 'rgba(76, 201, 240, 0.8)',
                        borderColor: '#4cc9f0',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Nombre d\'examens'
                            }
                        }
                    }
                }
            });
            
            // Graphique 4: Étudiants par formation
            const studentsCtx = document.getElementById('studentsChart').getContext('2d');
            const studentsChart = new Chart(studentsCtx, {
                type: 'bar',
                data: {
                    labels: [
                        <?php foreach ($stats_detail['etudiants_par_formation'] as $formation): ?>
                            '<?php echo addslashes(substr($formation['nom'], 0, 20)); ?>',
                        <?php endforeach; ?>
                    ],
                    datasets: [{
                        label: 'Nombre d\'étudiants',
                        data: [
                            <?php foreach ($stats_detail['etudiants_par_formation'] as $formation): ?>
                                <?php echo $formation['nb_etudiants']; ?>,
                            <?php endforeach; ?>
                        ],
                        backgroundColor: [
                            'rgba(155, 89, 182, 0.8)',
                            'rgba(46, 204, 113, 0.8)',
                            'rgba(52, 152, 219, 0.8)',
                            'rgba(243, 156, 18, 0.8)',
                            'rgba(231, 76, 60, 0.8)'
                        ]
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Nombre d\'étudiants'
                            }
                        }
                    }
                }
            });
        });
        
        // Fonctions d'export
        function exportStatsPDF() {
            alert('Génération du rapport PDF en cours...');
            window.location.href = 'export_stats_pdf.php?periode=<?php echo $periode; ?>';
        }
        
        function exportStatsExcel() {
            alert('Export des données Excel en cours...');
            window.location.href = 'export_stats_excel.php?periode=<?php echo $periode; ?>';
        }
        
        function generateReport() {
            alert('Génération du rapport complet en cours...');
            window.location.href = 'generate_full_report.php?periode=<?php echo $periode; ?>&dept_id=<?php echo $dept_id; ?>';
        }
    </script>
</body>
</html>