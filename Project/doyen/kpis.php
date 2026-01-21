<?php
// doyen/kpis.php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Vérifier que l'utilisateur est doyen ou vice-doyen
require_role(['doyen', 'vice_doyen']);

$user = get_logged_in_user();

// Récupérer les paramètres
$period = $_GET['period'] ?? 'month';
$dept_id = $_GET['dept'] ?? null;

// Calculer les dates selon la période
$date_ranges = [
    'week' => ['start' => date('Y-m-d', strtotime('-7 days')), 'end' => date('Y-m-d')],
    'month' => ['start' => date('Y-m-d', strtotime('-30 days')), 'end' => date('Y-m-d')],
    'quarter' => ['start' => date('Y-m-d', strtotime('-90 days')), 'end' => date('Y-m-d')],
    'year' => ['start' => date('Y-m-d', strtotime('-365 days')), 'end' => date('Y-m-d')]
];

$range = $date_ranges[$period] ?? $date_ranges['month'];

// Récupérer les KPIs
$sql = "
    SELECT 
        k.*,
        d.nom as dept_nom
    FROM kpis_academiques k
    LEFT JOIN departements d ON k.departement_id = d.id
    WHERE k.date_calcul BETWEEN :start AND :end
";

$params = [
    ':start' => $range['start'],
    ':end' => $range['end']
];

if ($dept_id) {
    $sql .= " AND (k.departement_id = :dept_id OR k.departement_id IS NULL)";
    $params[':dept_id'] = $dept_id;
}

$sql .= " ORDER BY k.date_calcul DESC, k.nom_kpi";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$kpis = $stmt->fetchAll();

// Organiser les KPIs par catégorie
$kpis_by_category = [];
foreach ($kpis as $kpi) {
    $category = get_kpi_category($kpi['nom_kpi']);
    $kpis_by_category[$category][] = $kpi;
}

// Récupérer les départements pour filtre
$stmt = $pdo->prepare("SELECT id, nom FROM departements ORDER BY nom");
$stmt->execute();
$departements = $stmt->fetchAll();

// Fonction pour catégoriser les KPIs
function get_kpi_category($kpi_name) {
    $categories = [
        'utilisation_ressources' => ['occupation', 'salle', 'amphi', 'capacité'],
        'performance' => ['taux', 'réussite', 'moyenne', 'performance'],
        'efficacité' => ['temps', 'efficacité', 'productivité', 'optimisation'],
        'satisfaction' => ['satisfaction', 'feedback', 'qualité'],
        'financier' => ['coût', 'budget', 'économies']
    ];
    
    $kpi_lower = strtolower($kpi_name);
    foreach ($categories as $category => $keywords) {
        foreach ($keywords as $keyword) {
            if (strpos($kpi_lower, $keyword) !== false) {
                return $category;
            }
        }
    }
    return 'autres';
}

// Noms des catégories en français
$category_names = [
    'utilisation_ressources' => 'Utilisation des Ressources',
    'performance' => 'Performance Académique',
    'efficacité' => 'Efficacité Opérationnelle',
    'satisfaction' => 'Satisfaction',
    'financier' => 'Aspects Financiers',
    'autres' => 'Autres Indicateurs'
];

$page_title = "Indicateurs de Performance - Doyenné";
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
        .kpis-header {
            background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
            color: white;
            padding: 2rem;
            border-radius: var(--border-radius);
            margin-bottom: 2rem;
        }
        
        .period-selector {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }
        
        .period-btn {
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
        
        .period-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        
        .period-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .kpi-categories {
            display: grid;
            gap: 2rem;
        }
        
        .category-card {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }
        
        .category-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            background: var(--gray-50);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .category-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--gray-800);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .category-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
        }
        
        .category-body {
            padding: 1.5rem;
        }
        
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        
        .kpi-card {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: var(--border-radius-sm);
            padding: 1.5rem;
            transition: var(--transition);
        }
        
        .kpi-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }
        
        .kpi-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        
        .kpi-name {
            font-weight: 600;
            color: var(--gray-800);
            flex: 1;
        }
        
        .kpi-value {
            font-size: 2rem;
            font-weight: 700;
            text-align: center;
            margin: 1rem 0;
        }
        
        .kpi-trend {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }
        
        .trend-up {
            color: var(--success);
        }
        
        .trend-down {
            color: var(--danger);
        }
        
        .trend-neutral {
            color: var(--gray-500);
        }
        
        .kpi-details {
            font-size: 0.85rem;
            color: var(--gray-600);
            margin-top: 0.5rem;
        }
        
        .kpi-department {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: var(--gray-100);
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.85rem;
        }
        
        .empty-kpis {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--gray-500);
        }
        
        .empty-kpis i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .charts-section {
            margin-top: 3rem;
        }
        
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 2rem;
            margin-top: 2rem;
        }
        
        .chart-container {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
        }
        
        .chart-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        @media (max-width: 768px) {
            .kpi-grid {
                grid-template-columns: 1fr;
            }
            
            .charts-grid {
                grid-template-columns: 1fr;
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
                    <h1>Indicateurs de Performance - Doyenné</h1>
                    <p>Tableau de bord des indicateurs clés de la faculté</p>
                </div>
            </header>
            
            <div class="kpis-header">
                <h2><i class="fas fa-chart-line"></i> Tableau de Bord KPIs</h2>
                <p>Suivi des performances académiques et opérationnelles</p>
            </div>
            
            <!-- Filtres -->
            <div class="filters-container">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label class="filter-label">Période</label>
                        <div class="period-selector">
                            <a href="?period=week<?php echo $dept_id ? '&dept=' . $dept_id : ''; ?>" 
                               class="period-btn <?php echo $period == 'week' ? 'active' : ''; ?>">
                                <i class="far fa-calendar-week"></i> Semaine
                            </a>
                            <a href="?period=month<?php echo $dept_id ? '&dept=' . $dept_id : ''; ?>" 
                               class="period-btn <?php echo $period == 'month' ? 'active' : ''; ?>">
                                <i class="far fa-calendar-alt"></i> Mois
                            </a>
                            <a href="?period=quarter<?php echo $dept_id ? '&dept=' . $dept_id : ''; ?>" 
                               class="period-btn <?php echo $period == 'quarter' ? 'active' : ''; ?>">
                                <i class="fas fa-calendar"></i> Trimestre
                            </a>
                            <a href="?period=year<?php echo $dept_id ? '&dept=' . $dept_id : ''; ?>" 
                               class="period-btn <?php echo $period == 'year' ? 'active' : ''; ?>">
                                <i class="far fa-calendar"></i> Année
                            </a>
                        </div>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Département</label>
                        <select name="dept" class="form-select" onchange="window.location.href='?period=<?php echo $period; ?>&dept=' + this.value">
                            <option value="">Tous les départements</option>
                            <?php foreach ($departements as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>" 
                                        <?php echo $dept_id == $dept['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['nom']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            
            <!-- Catégories de KPIs -->
            <div class="kpi-categories">
                <?php 
                $category_colors = [
                    'utilisation_ressources' => ['#3498db', '#2980b9'],
                    'performance' => ['#9b59b6', '#8e44ad'],
                    'efficacité' => ['#e74c3c', '#c0392b'],
                    'satisfaction' => ['#f1c40f', '#f39c12'],
                    'financier' => ['#1abc9c', '#16a085'],
                    'autres' => ['#95a5a6', '#7f8c8d']
                ];
                
                foreach ($category_names as $category_id => $category_name): 
                    if (!isset($kpis_by_category[$category_id]) || empty($kpis_by_category[$category_id])) {
                        continue;
                    }
                    
                    $color = $category_colors[$category_id] ?? ['#3498db', '#2980b9'];
                ?>
                    <div class="category-card">
                        <div class="category-header">
                            <div class="category-title">
                                <div class="category-icon" style="background: linear-gradient(135deg, <?php echo $color[0]; ?> 0%, <?php echo $color[1]; ?> 100%);">
                                    <?php 
                                    $icons = [
                                        'utilisation_ressources' => 'fas fa-building',
                                        'performance' => 'fas fa-graduation-cap',
                                        'efficacité' => 'fas fa-tachometer-alt',
                                        'satisfaction' => 'fas fa-smile',
                                        'financier' => 'fas fa-money-bill-wave',
                                        'autres' => 'fas fa-chart-bar'
                                    ];
                                    echo '<i class="' . ($icons[$category_id] ?? 'fas fa-chart-bar') . '"></i>';
                                    ?>
                                </div>
                                <?php echo $category_name; ?>
                            </div>
                            <span class="badge badge-light">
                                <?php echo count($kpis_by_category[$category_id]); ?> indicateurs
                            </span>
                        </div>
                        
                        <div class="category-body">
                            <div class="kpi-grid">
                                <?php foreach ($kpis_by_category[$category_id] as $kpi): 
                                    $trend = rand(-1, 1); // Exemple - À remplacer par calcul réel
                                    $trend_class = $trend > 0 ? 'trend-up' : ($trend < 0 ? 'trend-down' : 'trend-neutral');
                                    $trend_icon = $trend > 0 ? 'fa-arrow-up' : ($trend < 0 ? 'fa-arrow-down' : 'fa-minus');
                                ?>
                                    <div class="kpi-card">
                                        <div class="kpi-header">
                                            <div class="kpi-name"><?php echo htmlspecialchars($kpi['nom_kpi']); ?></div>
                                            <div class="kpi-trend <?php echo $trend_class; ?>">
                                                <i class="fas <?php echo $trend_icon; ?>"></i>
                                                <span><?php echo abs($trend); ?>%</span>
                                            </div>
                                        </div>
                                        
                                        <div class="kpi-value" style="color: <?php echo $color[0]; ?>;">
                                            <?php echo number_format($kpi['valeur'], 2); ?>
                                        </div>
                                        
                                        <div class="kpi-details">
                                            <?php if ($kpi['dept_nom']): ?>
                                                <div class="kpi-department">
                                                    <i class="fas fa-building"></i>
                                                    <?php echo htmlspecialchars($kpi['dept_nom']); ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="kpi-department">
                                                    <i class="fas fa-university"></i>
                                                    Faculté entière
                                                </div>
                                            <?php endif; ?>
                                            
                                            <div style="margin-top: 0.5rem;">
                                                <i class="far fa-calendar"></i>
                                                <?php echo date('d/m/Y', strtotime($kpi['date_calcul'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <?php if (empty($kpis_by_category)): ?>
                    <div class="empty-kpis">
                        <i class="fas fa-chart-bar fa-3x"></i>
                        <h3>Aucun KPI disponible</h3>
                        <p>Aucun indicateur n'a été calculé pour cette période</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Graphiques -->
            <div class="charts-section">
                <h2 class="section-title">
                    <i class="fas fa-chart-area"></i>
                    Visualisation des Tendances
                </h2>
                
                <div class="charts-grid">
                    <div class="chart-container">
                        <div class="chart-title">
                            <i class="fas fa-building"></i>
                            Occupation des Salles par Département
                        </div>
                        <canvas id="occupationChart" height="250"></canvas>
                    </div>
                    
                    <div class="chart-container">
                        <div class="chart-title">
                            <i class="fas fa-chart-line"></i>
                            Évolution des KPIs Clés
                        </div>
                        <canvas id="evolutionChart" height="250"></canvas>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <script>
        // Graphique d'occupation
        const occupationCtx = document.getElementById('occupationChart').getContext('2d');
        const occupationChart = new Chart(occupationCtx, {
            type: 'bar',
            data: {
                labels: ['Informatique', 'Maths', 'Physique', 'Chimie', 'Biologie'],
                datasets: [{
                    label: 'Taux d\'occupation (%)',
                    data: [85, 72, 68, 91, 64],
                    backgroundColor: [
                        'rgba(139, 0, 0, 0.8)',
                        'rgba(46, 204, 113, 0.8)',
                        'rgba(52, 152, 219, 0.8)',
                        'rgba(155, 89, 182, 0.8)',
                        'rgba(241, 196, 15, 0.8)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        title: {
                            display: true,
                            text: 'Pourcentage (%)'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });
        
        // Graphique d'évolution
        const evolutionCtx = document.getElementById('evolutionChart').getContext('2d');
        const evolutionChart = new Chart(evolutionCtx, {
            type: 'line',
            data: {
                labels: ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun'],
                datasets: [
                    {
                        label: 'Taux de réussite',
                        data: [72, 75, 78, 80, 82, 85],
                        borderColor: 'rgba(139, 0, 0, 1)',
                        backgroundColor: 'rgba(139, 0, 0, 0.1)',
                        tension: 0.4
                    },
                    {
                        label: 'Satisfaction étudiants',
                        data: [68, 70, 72, 75, 77, 80],
                        borderColor: 'rgba(46, 204, 113, 1)',
                        backgroundColor: 'rgba(46, 204, 113, 0.1)',
                        tension: 0.4
                    },
                    {
                        label: 'Utilisation salles',
                        data: [65, 68, 72, 75, 78, 82],
                        borderColor: 'rgba(52, 152, 219, 1)',
                        backgroundColor: 'rgba(52, 152, 219, 0.1)',
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        title: {
                            display: true,
                            text: 'Score'
                        }
                    }
                }
            }
        });
        
        // Animations
        document.addEventListener('DOMContentLoaded', function() {
            const kpiCards = document.querySelectorAll('.kpi-card');
            kpiCards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
                card.classList.add('animate__animated', 'animate__fadeInUp');
            });
        });
    </script>
</body>
</html>