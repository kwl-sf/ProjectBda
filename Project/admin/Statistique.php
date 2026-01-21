<?php
// admin/Statistique.php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Vérifier que l'utilisateur est admin
require_role(['admin']);

// Récupérer l'utilisateur connecté
$user = get_logged_in_user();

// Paramètres de période par défaut
$current_month = date('m');
$current_year = date('Y');
$start_date = date('Y-m-01'); // Début du mois courant
$end_date = date('Y-m-t'); // Fin du mois courant
$departement_id = isset($_GET['departement']) ? intval($_GET['departement']) : 0;
$periode = isset($_GET['periode']) ? $_GET['periode'] : 'current_month';

// Traitement du formulaire de filtre
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $periode = $_POST['periode'] ?? 'current_month';
    $departement_id = intval($_POST['departement'] ?? 0);
    
    switch ($periode) {
        case 'last_month':
            $start_date = date('Y-m-01', strtotime('-1 month'));
            $end_date = date('Y-m-t', strtotime('-1 month'));
            break;
        case 'last_3_months':
            $start_date = date('Y-m-01', strtotime('-3 months'));
            $end_date = date('Y-m-t');
            break;
        case 'last_6_months':
            $start_date = date('Y-m-01', strtotime('-6 months'));
            $end_date = date('Y-m-t');
            break;
        case 'custom':
            $start_date = $_POST['start_date'] ?? $start_date;
            $end_date = $_POST['end_date'] ?? $end_date;
            break;
        case 'current_year':
            $start_date = date('Y-01-01');
            $end_date = date('Y-12-31');
            break;
        default: // current_month
            $start_date = date('Y-m-01');
            $end_date = date('Y-m-t');
    }
}

try {
    // Récupérer la liste des départements pour le filtre
    $stmt = $pdo->query("SELECT id, nom FROM departements ORDER BY nom");
    $departements = $stmt->fetchAll();
    
    // Statistiques principales
    $stats = [];
    
    // 1. Occupation des salles
    $sql = "SELECT 
                COUNT(DISTINCT e.id) as examens_count,
                COUNT(DISTINCT e.salle_id) as salles_utilisees,
                (SELECT COUNT(*) FROM lieu_examen WHERE disponible = 1) as salles_disponibles,
                ROUND(COUNT(DISTINCT e.salle_id) * 100.0 / 
                      (SELECT COUNT(*) FROM lieu_examen WHERE disponible = 1), 2) as taux_occupation
            FROM examens e
            WHERE e.date_heure BETWEEN ? AND ?
            AND e.statut IN ('confirme', 'planifie')";
    
    $params = [$start_date, $end_date];
    if ($departement_id > 0) {
        $sql .= " AND EXISTS (
            SELECT 1 FROM modules m 
            JOIN formations f ON m.formation_id = f.id
            WHERE m.id = e.module_id 
            AND f.dept_id = ?
        )";
        $params[] = $departement_id;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $stats['occupation'] = $stmt->fetch();
    
    // 2. Taux de conflits
    $sql = "SELECT 
                COUNT(*) as total_conflits,
                SUM(CASE WHEN statut = 'resolu' THEN 1 ELSE 0 END) as conflits_resolus,
                SUM(CASE WHEN statut = 'detecte' THEN 1 ELSE 0 END) as conflits_actifs,
                ROUND(SUM(CASE WHEN statut = 'resolu' THEN 1 ELSE 0 END) * 100.0 / 
                      NULLIF(COUNT(*), 0), 2) as taux_resolution
            FROM conflits c
            WHERE c.date_detection BETWEEN ? AND ?";
    
    $params = [$start_date, $end_date];
    if ($departement_id > 0) {
        // Note: Cette requête nécessite une jointure avec les entités concernées
        // Pour simplifier, on prend tous les conflits
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $stats['conflits'] = $stmt->fetch();
    
    // 3. Charge des professeurs
    $sql = "SELECT 
                p.id,
                CONCAT(p.prenom, ' ', p.nom) as professeur,
                d.nom as departement,
                COUNT(DISTINCT e.id) as examens_surveilles,
                COUNT(DISTINCT s.examen_id) as surveillances,
                ROUND(AVG(
                    (SELECT COUNT(*) FROM examens e2 
                     WHERE DATE(e2.date_heure) = DATE(e.date_heure)
                     AND (e2.prof_id = p.id OR EXISTS (
                         SELECT 1 FROM surveillants s2 
                         WHERE s2.examen_id = e2.id AND s2.prof_id = p.id
                     ))
                    )
                ), 1) as moyenne_examens_par_jour
            FROM professeurs p
            LEFT JOIN departements d ON p.dept_id = d.id
            LEFT JOIN examens e ON e.prof_id = p.id 
                AND e.date_heure BETWEEN ? AND ?
            LEFT JOIN surveillants s ON s.prof_id = p.id 
                AND EXISTS (SELECT 1 FROM examens e2 WHERE e2.id = s.examen_id 
                          AND e2.date_heure BETWEEN ? AND ?)
            WHERE p.role IN ('prof', 'chef_dept')
            GROUP BY p.id
            HAVING examens_surveilles > 0 OR surveillances > 0
            ORDER BY examens_surveilles DESC
            LIMIT 10";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$start_date, $end_date, $start_date, $end_date]);
    $stats['charge_professeurs'] = $stmt->fetchAll();
    
    // 4. Distribution des examens par jour/heure
    $sql = "SELECT 
                DAYOFWEEK(date_heure) as jour_semaine,
                HOUR(date_heure) as heure,
                COUNT(*) as nombre_examens
            FROM examens
            WHERE date_heure BETWEEN ? AND ?
            AND statut IN ('confirme', 'planifie')
            GROUP BY DAYOFWEEK(date_heure), HOUR(date_heure)
            ORDER BY jour_semaine, heure";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$start_date, $end_date]);
    $distributions = $stmt->fetchAll();
    
    $stats['distribution'] = [];
    foreach ($distributions as $dist) {
        $stats['distribution'][] = [
            'jour' => get_jour_semaine($dist['jour_semaine']),
            'heure' => $dist['heure'],
            'nombre' => $dist['nombre_examens']
        ];
    }
    
    // 5. Taux de validation par département
    $sql = "SELECT 
                d.id,
                d.nom as departement,
                COUNT(e.id) as total_examens,
                SUM(CASE WHEN e.statut = 'confirme' THEN 1 ELSE 0 END) as examens_confirmes,
                SUM(CASE WHEN e.statut = 'planifie' THEN 1 ELSE 0 END) as examens_planifies,
                SUM(CASE WHEN e.statut = 'en_attente_validation' THEN 1 ELSE 0 END) as examens_attente,
                ROUND(SUM(CASE WHEN e.statut = 'confirme' THEN 1 ELSE 0 END) * 100.0 / 
                      NULLIF(COUNT(e.id), 0), 2) as taux_validation
            FROM departements d
            LEFT JOIN formations f ON f.dept_id = d.id
            LEFT JOIN modules m ON m.formation_id = f.id
            LEFT JOIN examens e ON e.module_id = m.id 
                AND e.date_heure BETWEEN ? AND ?
            GROUP BY d.id
            HAVING total_examens > 0
            ORDER BY taux_validation DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$start_date, $end_date]);
    $stats['validation_departements'] = $stmt->fetchAll();
    
    // 6. Statistiques des salles
    $sql = "SELECT 
                type,
                COUNT(*) as nombre_salles,
                SUM(capacite) as capacite_totale,
                ROUND(AVG(capacite), 0) as capacite_moyenne,
                (SELECT COUNT(DISTINCT e.salle_id) 
                 FROM examens e 
                 JOIN lieu_examen le ON e.salle_id = le.id
                 WHERE le.type = l.type 
                 AND e.date_heure BETWEEN ? AND ?) as salles_utilisees
            FROM lieu_examen l
            WHERE disponible = 1
            GROUP BY type";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$start_date, $end_date]);
    $stats['salles'] = $stmt->fetchAll();
    
    // 7. Évolution mensuelle des examens
    $sql = "SELECT 
                DATE_FORMAT(date_heure, '%Y-%m') as mois,
                COUNT(*) as nombre_examens,
                COUNT(DISTINCT module_id) as modules_differents,
                COUNT(DISTINCT salle_id) as salles_utilisees
            FROM examens
            WHERE date_heure >= DATE_SUB(?, INTERVAL 6 MONTH)
            AND statut IN ('confirme', 'planifie')
            GROUP BY DATE_FORMAT(date_heure, '%Y-%m')
            ORDER BY mois DESC
            LIMIT 6";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$end_date]);
    $stats['evolution'] = array_reverse($stmt->fetchAll()); // Du plus ancien au plus récent
    
    // 8. KPIs académiques
    $sql = "SELECT 
                nom_kpi,
                valeur,
                date_calcul
            FROM kpis_academiques
            WHERE date_calcul BETWEEN ? AND ?
            ORDER BY date_calcul DESC, nom_kpi
            LIMIT 20";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$start_date, $end_date]);
    $stats['kpis'] = $stmt->fetchAll();
    
    // 9. Nombre de notifications par type
    $sql = "SELECT 
                type,
                COUNT(*) as nombre,
                SUM(CASE WHEN lu = 1 THEN 1 ELSE 0 END) as lues,
                SUM(CASE WHEN lu = 0 THEN 1 ELSE 0 END) as non_lues
            FROM notifications
            WHERE date_creation BETWEEN ? AND ?
            GROUP BY type
            ORDER BY nombre DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$start_date, $end_date]);
    $stats['notifications'] = $stmt->fetchAll();
    
    // 10. Statistiques des étudiants par formation
    $sql = "SELECT 
                f.nom as formation,
                d.nom as departement,
                COUNT(DISTINCT et.id) as nombre_etudiants,
                COUNT(DISTINCT i.module_id) as modules_inscrits,
                ROUND(AVG(i.note), 2) as moyenne_generale
            FROM formations f
            JOIN departements d ON f.dept_id = d.id
            LEFT JOIN etudiants et ON et.formation_id = f.id
            LEFT JOIN inscriptions i ON i.etudiant_id = et.id
                AND i.annee_scolaire = CONCAT(YEAR(?), '-', YEAR(?)+1)
            GROUP BY f.id
            HAVING nombre_etudiants > 0
            ORDER BY nombre_etudiants DESC
            LIMIT 15";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$start_date, $end_date]);
    $stats['formations'] = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Erreur statistiques: " . $e->getMessage());
    // Initialiser des tableaux vides pour éviter les erreurs
    $stats = [
        'occupation' => [],
        'conflits' => [],
        'charge_professeurs' => [],
        'distribution' => [],
        'validation_departements' => [],
        'salles' => [],
        'evolution' => [],
        'kpis' => [],
        'notifications' => [],
        'formations' => []
    ];
    $departements = [];
}

// Fonction utilitaire pour les jours de la semaine
function get_jour_semaine($num) {
    $jours = [
        1 => 'Lundi',
        2 => 'Mardi',
        3 => 'Mercredi',
        4 => 'Jeudi',
        5 => 'Vendredi',
        6 => 'Samedi',
        7 => 'Dimanche'
    ];
    return $jours[$num] ?? 'Inconnu';
}

// Titre de la page
$page_title = "Statistiques Avancées";
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
    <!-- Chart.js pour les graphiques -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .statistics-wrapper {
            padding: 20px;
        }
        
        .filters-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
        }
        
        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            align-items: end;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group label {
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--gray-700);
        }
        
        .form-group select,
        .form-group input {
            padding: 0.75rem;
            border: 1px solid var(--gray-300);
            border-radius: var(--border-radius-sm);
            font-size: 1rem;
        }
        
        .btn-apply {
            background: var(--primary);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: var(--border-radius-sm);
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
        }
        
        .btn-apply:hover {
            background: var(--primary-dark);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            transition: var(--transition);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .stat-card h3 {
            color: var(--gray-800);
            margin-bottom: 1rem;
            font-size: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .stat-card h3 i {
            color: var(--primary);
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: var(--gray-600);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        
        .stat-change {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .stat-change.positive {
            color: var(--success);
        }
        
        .stat-change.negative {
            color: var(--danger);
        }
        
        .chart-container {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
        }
        
        .chart-container h3 {
            color: var(--gray-800);
            margin-bottom: 1rem;
            font-size: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .chart-container h3 i {
            color: var(--primary);
        }
        
        .chart-wrapper {
            position: relative;
            height: 300px;
            width: 100%;
        }
        
        .table-container {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            overflow-x: auto;
        }
        
        .table-container h3 {
            color: var(--gray-800);
            margin-bottom: 1rem;
            font-size: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .table-container h3 i {
            color: var(--primary);
        }
        
        .stats-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .stats-table th {
            background: var(--gray-100);
            color: var(--gray-700);
            font-weight: 600;
            padding: 1rem;
            text-align: left;
            border-bottom: 2px solid var(--gray-300);
        }
        
        .stats-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .stats-table tr:hover {
            background: var(--gray-50);
        }
        
        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge.success {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success);
        }
        
        .badge.warning {
            background: rgba(241, 196, 15, 0.1);
            color: var(--warning);
        }
        
        .badge.danger {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
        }
        
        .badge.info {
            background: rgba(52, 152, 219, 0.1);
            color: var(--info);
        }
        
        .export-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            justify-content: center;
        }
        
        .btn-export {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border-radius: var(--border-radius-sm);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
        }
        
        .btn-export.pdf {
            background: var(--danger);
            color: white;
        }
        
        .btn-export.excel {
            background: var(--success);
            color: white;
        }
        
        .btn-export.csv {
            background: var(--info);
            color: white;
        }
        
        .btn-export:hover {
            opacity: 0.9;
            transform: translateY(-2px);
        }
        
        .section-title {
            color: var(--gray-800);
            margin-bottom: 1.5rem;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .period-info {
            background: var(--primary-light);
            color: var(--primary);
            padding: 1rem;
            border-radius: var(--border-radius-sm);
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .period-info i {
            font-size: 1.25rem;
        }
        
        @media (max-width: 768px) {
            .filter-form {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .export-buttons {
                flex-direction: column;
            }
            
            .chart-wrapper {
                height: 250px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        <!-- Sidebar (identique au dashboard) -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-graduation-cap"></i> PlanExam Pro</h2>
                <p>Administration des Examens</p>
            </div>
            
            <div class="user-info">
                <div class="user-avatar">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div class="user-name"><?php echo htmlspecialchars($user['full_name'] ?? 'Administrateur'); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($user['role_fr'] ?? 'Administrateur'); ?></div>
            </div>
            
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                    <span>Tableau de Bord</span>
                </a>
                <a href="Statistique.php" class="nav-item active">
                    <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                    <span>Statistique</span>
                </a>
                <a href="generate_schedule.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-calendar-plus"></i></span>
                    <span>Générer EDT</span>
                </a>
                <a href="manage_rooms.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-building"></i></span>
                    <span>Gérer les Salles</span>
                </a>
                <a href="conflicts.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-exclamation-triangle"></i></span>
                    <span>Conflits</span>
                </a>
                <a href="GererUser.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-users"></i></span>
                    <span>Gérer les Utilisateurs</span>
                </a>
                <a href="settings.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-cog"></i></span>
                    <span>Paramètres</span>
                </a>
                <a href="../logout.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                    <span>Déconnexion</span>
                </a>
            </nav>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="header">
                <div class="header-left">
                    <h1>Statistiques Avancées</h1>
                    <p>Analyse et rapports détaillés sur les examens</p>
                </div>
                <div class="header-actions">
                    <button class="menu-toggle" id="menuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                </div>
            </header>
            
            <!-- Période d'analyse -->
            <div class="period-info animate__animated animate__fadeIn">
                <i class="fas fa-calendar-alt"></i>
                <div>
                    <strong>Période d'analyse :</strong> 
                    <?php echo format_date_fr($start_date, false) . ' au ' . format_date_fr($end_date, false); ?>
                    <?php if ($departement_id > 0): 
                        $dept_name = '';
                        foreach ($departements as $dept) {
                            if ($dept['id'] == $departement_id) {
                                $dept_name = $dept['nom'];
                                break;
                            }
                        }
                    ?>
                        | <strong>Département :</strong> <?php echo htmlspecialchars($dept_name); ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Filtres -->
            <div class="filters-card animate__animated animate__fadeIn">
                <form method="post" class="filter-form">
                    <div class="form-group">
                        <label for="periode"><i class="fas fa-calendar"></i> Période</label>
                        <select name="periode" id="periode" onchange="toggleCustomDates()">
                            <option value="current_month" <?php echo $periode === 'current_month' ? 'selected' : ''; ?>>Mois en cours</option>
                            <option value="last_month" <?php echo $periode === 'last_month' ? 'selected' : ''; ?>>Mois précédent</option>
                            <option value="last_3_months" <?php echo $periode === 'last_3_months' ? 'selected' : ''; ?>>3 derniers mois</option>
                            <option value="last_6_months" <?php echo $periode === 'last_6_months' ? 'selected' : ''; ?>>6 derniers mois</option>
                            <option value="current_year" <?php echo $periode === 'current_year' ? 'selected' : ''; ?>>Année en cours</option>
                            <option value="custom" <?php echo $periode === 'custom' ? 'selected' : ''; ?>>Période personnalisée</option>
                        </select>
                    </div>
                    
                    <div class="form-group" id="customDatesGroup" style="display: <?php echo $periode === 'custom' ? 'flex' : 'none'; ?>">
                        <label for="start_date">Date début</label>
                        <input type="date" name="start_date" id="start_date" value="<?php echo $start_date; ?>">
                    </div>
                    
                    <div class="form-group" id="customDatesGroup2" style="display: <?php echo $periode === 'custom' ? 'flex' : 'none'; ?>">
                        <label for="end_date">Date fin</label>
                        <input type="date" name="end_date" id="end_date" value="<?php echo $end_date; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="departement"><i class="fas fa-university"></i> Département</label>
                        <select name="departement" id="departement">
                            <option value="0">Tous les départements</option>
                            <?php foreach ($departements as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>" <?php echo $departement_id == $dept['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['nom']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <button type="submit" class="btn-apply">
                            <i class="fas fa-filter"></i> Appliquer les filtres
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Statistiques Principales -->
            <div class="stats-grid">
                <div class="stat-card animate__animated animate__fadeInUp">
                    <h3><i class="fas fa-building"></i> Occupation des Salles</h3>
                    <div class="stat-value"><?php echo $stats['occupation']['taux_occupation'] ?? '0'; ?>%</div>
                    <div class="stat-label">
                        <?php echo $stats['occupation']['salles_utilisees'] ?? '0'; ?> / 
                        <?php echo $stats['occupation']['salles_disponibles'] ?? '0'; ?> salles utilisées
                    </div>
                    <div class="stat-change positive">
                        <i class="fas fa-arrow-up"></i>
                        <span><?php echo $stats['occupation']['examens_count'] ?? '0'; ?> examens planifiés</span>
                    </div>
                </div>
                
                <div class="stat-card animate__animated animate__fadeInUp" style="animation-delay: 0.1s;">
                    <h3><i class="fas fa-exclamation-triangle"></i> Conflits</h3>
                    <div class="stat-value"><?php echo $stats['conflits']['total_conflits'] ?? '0'; ?></div>
                    <div class="stat-label">
                        <?php echo $stats['conflits']['conflits_resolus'] ?? '0'; ?> résolus | 
                        <?php echo $stats['conflits']['conflits_actifs'] ?? '0'; ?> actifs
                    </div>
                    <div class="stat-change <?php echo ($stats['conflits']['taux_resolution'] ?? 0) >= 80 ? 'positive' : 'negative'; ?>">
                        <i class="fas fa-<?php echo ($stats['conflits']['taux_resolution'] ?? 0) >= 80 ? 'check' : 'times'; ?>"></i>
                        <span>Taux de résolution: <?php echo $stats['conflits']['taux_resolution'] ?? '0'; ?>%</span>
                    </div>
                </div>
                
                <div class="stat-card animate__animated animate__fadeInUp" style="animation-delay: 0.2s;">
                    <h3><i class="fas fa-chalkboard-teacher"></i> Charge Professeurs</h3>
                    <div class="stat-value"><?php echo count($stats['charge_professeurs']); ?></div>
                    <div class="stat-label">Professeurs avec examens</div>
                    <?php if (!empty($stats['charge_professeurs'])): 
                        $moyenne = array_sum(array_column($stats['charge_professeurs'], 'examens_surveilles')) / count($stats['charge_professeurs']);
                    ?>
                        <div class="stat-change <?php echo $moyenne > 5 ? 'negative' : 'positive'; ?>">
                            <i class="fas fa-<?php echo $moyenne > 5 ? 'exclamation' : 'check'; ?>-circle"></i>
                            <span>Moyenne: <?php echo round($moyenne, 1); ?> examens/prof</span>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="stat-card animate__animated animate__fadeInUp" style="animation-delay: 0.3s;">
                    <h3><i class="fas fa-check-circle"></i> Taux de Validation</h3>
                    <?php if (!empty($stats['validation_departements'])): 
                        $moyenne_validation = array_sum(array_column($stats['validation_departements'], 'taux_validation')) / count($stats['validation_departements']);
                    ?>
                        <div class="stat-value"><?php echo round($moyenne_validation, 1); ?>%</div>
                        <div class="stat-label">Moyenne des départements</div>
                        <div class="stat-change <?php echo $moyenne_validation >= 90 ? 'positive' : 'warning'; ?>">
                            <i class="fas fa-<?php echo $moyenne_validation >= 90 ? 'check' : 'clock'; ?>"></i>
                            <span><?php echo count($stats['validation_departements']); ?> départements</span>
                        </div>
                    <?php else: ?>
                        <div class="stat-value">0%</div>
                        <div class="stat-label">Aucune donnée disponible</div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Graphiques et Tableaux -->
            <div class="dashboard-content">
                <!-- Colonne Gauche -->
                <div class="left-column">
                    <!-- Évolution des examens -->
                    <?php if (!empty($stats['evolution'])): ?>
                    <div class="chart-container animate__animated animate__fadeIn">
                        <h3><i class="fas fa-chart-line"></i> Évolution des Examens</h3>
                        <div class="chart-wrapper">
                            <canvas id="evolutionChart"></canvas>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Distribution par type de salle -->
                    <?php if (!empty($stats['salles'])): ?>
                    <div class="chart-container animate__animated animate__fadeIn" style="animation-delay: 0.2s;">
                        <h3><i class="fas fa-door-open"></i> Répartition des Salles</h3>
                        <div class="chart-wrapper">
                            <canvas id="sallesChart"></canvas>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Top 10 des professeurs les plus chargés -->
                    <?php if (!empty($stats['charge_professeurs'])): ?>
                    <div class="table-container animate__animated animate__fadeIn" style="animation-delay: 0.3s;">
                        <h3><i class="fas fa-chalkboard-teacher"></i> Top 10 - Charge des Professeurs</h3>
                        <table class="stats-table">
                            <thead>
                                <tr>
                                    <th>Professeur</th>
                                    <th>Département</th>
                                    <th>Examens</th>
                                    <th>Surveillances</th>
                                    <th>Moy/Jour</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['charge_professeurs'] as $prof): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($prof['professeur']); ?></td>
                                    <td><?php echo htmlspecialchars($prof['departement']); ?></td>
                                    <td><span class="badge <?php echo $prof['examens_surveilles'] > 10 ? 'danger' : ($prof['examens_surveilles'] > 5 ? 'warning' : 'success'); ?>">
                                        <?php echo $prof['examens_surveilles']; ?>
                                    </span></td>
                                    <td><?php echo $prof['surveillances']; ?></td>
                                    <td><?php echo $prof['moyenne_examens_par_jour']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Colonne Droite -->
                <div class="right-column">
                    <!-- Taux de validation par département -->
                    <?php if (!empty($stats['validation_departements'])): ?>
                    <div class="chart-container animate__animated animate__fadeIn">
                        <h3><i class="fas fa-check-double"></i> Validation par Département</h3>
                        <div class="chart-wrapper">
                            <canvas id="validationChart"></canvas>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Distribution des examens par jour -->
                    <?php if (!empty($stats['distribution'])): ?>
                    <div class="chart-container animate__animated animate__fadeIn" style="animation-delay: 0.2s;">
                        <h3><i class="fas fa-calendar-day"></i> Répartition par Jour</h3>
                        <div class="chart-wrapper">
                            <canvas id="distributionChart"></canvas>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Statistiques des formations -->
                    <?php if (!empty($stats['formations'])): ?>
                    <div class="table-container animate__animated animate__fadeIn" style="animation-delay: 0.3s;">
                        <h3><i class="fas fa-graduation-cap"></i> Statistiques par Formation</h3>
                        <table class="stats-table">
                            <thead>
                                <tr>
                                    <th>Formation</th>
                                    <th>Étudiants</th>
                                    <th>Modules</th>
                                    <th>Moyenne</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['formations'] as $formation): ?>
                                <tr>
                                    <td>
                                        <div><?php echo htmlspecialchars($formation['formation']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($formation['departement']); ?></small>
                                    </td>
                                    <td><?php echo $formation['nombre_etudiants']; ?></td>
                                    <td><?php echo $formation['modules_inscrits']; ?></td>
                                    <td>
                                        <span class="badge <?php echo $formation['moyenne_generale'] >= 12 ? 'success' : ($formation['moyenne_generale'] >= 10 ? 'warning' : 'danger'); ?>">
                                            <?php echo $formation['moyenne_generale'] ?: 'N/A'; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                    
                    <!-- KPIs Académiques -->
                    <?php if (!empty($stats['kpis'])): ?>
                    <div class="table-container animate__animated animate__fadeIn" style="animation-delay: 0.4s;">
                        <h3><i class="fas fa-chart-pie"></i> KPIs Académiques</h3>
                        <table class="stats-table">
                            <thead>
                                <tr>
                                    <th>Indicateur</th>
                                    <th>Valeur</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['kpis'] as $kpi): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($kpi['nom_kpi']); ?></td>
                                    <td><strong><?php echo $kpi['valeur']; ?></strong></td>
                                    <td><?php echo format_date_fr($kpi['date_calcul'], false); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Boutons d'export -->
            <div class="export-buttons animate__animated animate__fadeIn">
                <a href="export_statistics.php?type=pdf&start=<?php echo $start_date; ?>&end=<?php echo $end_date; ?>&dept=<?php echo $departement_id; ?>" 
                   class="btn-export pdf" target="_blank">
                    <i class="fas fa-file-pdf"></i> Exporter en PDF
                </a>
                <a href="export_statistics.php?type=excel&start=<?php echo $start_date; ?>&end=<?php echo $end_date; ?>&dept=<?php echo $departement_id; ?>" 
                   class="btn-export excel" target="_blank">
                    <i class="fas fa-file-excel"></i> Exporter en Excel
                </a>
                <a href="export_statistics.php?type=csv&start=<?php echo $start_date; ?>&end=<?php echo $end_date; ?>&dept=<?php echo $departement_id; ?>" 
                   class="btn-export csv" target="_blank">
                    <i class="fas fa-file-csv"></i> Exporter en CSV
                </a>
            </div>
        </main>
    </div>
    
    <script>
        // Menu Toggle
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
        });
        
        // Afficher/masquer les dates personnalisées
        function toggleCustomDates() {
            const periode = document.getElementById('periode').value;
            const group1 = document.getElementById('customDatesGroup');
            const group2 = document.getElementById('customDatesGroup2');
            
            if (periode === 'custom') {
                group1.style.display = 'flex';
                group2.style.display = 'flex';
            } else {
                group1.style.display = 'none';
                group2.style.display = 'none';
            }
        }
        
        // Graphiques avec Chart.js
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (!empty($stats['evolution'])): ?>
            // Graphique d'évolution
            const evolutionCtx = document.getElementById('evolutionChart').getContext('2d');
            const evolutionLabels = <?php echo json_encode(array_column($stats['evolution'], 'mois')); ?>;
            const evolutionData = <?php echo json_encode(array_column($stats['evolution'], 'nombre_examens')); ?>;
            
            new Chart(evolutionCtx, {
                type: 'line',
                data: {
                    labels: evolutionLabels,
                    datasets: [{
                        label: 'Nombre d\'examens',
                        data: evolutionData,
                        borderColor: '#4361ee',
                        backgroundColor: 'rgba(67, 97, 238, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
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
            <?php endif; ?>
            
            <?php if (!empty($stats['salles'])): ?>
            // Graphique des salles
            const sallesCtx = document.getElementById('sallesChart').getContext('2d');
            const sallesLabels = <?php echo json_encode(array_column($stats['salles'], 'type')); ?>;
            const sallesData = <?php echo json_encode(array_column($stats['salles'], 'nombre_salles')); ?>;
            const sallesColors = ['#4361ee', '#3a0ca3', '#7209b7', '#f72585'];
            
            new Chart(sallesCtx, {
                type: 'doughnut',
                data: {
                    labels: sallesLabels,
                    datasets: [{
                        data: sallesData,
                        backgroundColor: sallesColors,
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right'
                        }
                    }
                }
            });
            <?php endif; ?>
            
            <?php if (!empty($stats['validation_departements'])): ?>
            // Graphique de validation
            const validationCtx = document.getElementById('validationChart').getContext('2d');
            const validationLabels = <?php echo json_encode(array_column($stats['validation_departements'], 'departement')); ?>;
            const validationData = <?php echo json_encode(array_column($stats['validation_departements'], 'taux_validation')); ?>;
            
            new Chart(validationCtx, {
                type: 'bar',
                data: {
                    labels: validationLabels,
                    datasets: [{
                        label: 'Taux de validation (%)',
                        data: validationData,
                        backgroundColor: validationData.map(val => 
                            val >= 90 ? '#2ecc71' : val >= 70 ? '#f39c12' : '#e74c3c'
                        ),
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            title: {
                                display: true,
                                text: 'Pourcentage (%)'
                            }
                        }
                    }
                }
            });
            <?php endif; ?>
            
            <?php if (!empty($stats['distribution'])): ?>
            // Graphique de distribution
            const distributionCtx = document.getElementById('distributionChart').getContext('2d');
            
            // Regrouper par jour
            const jours = {};
            <?php foreach ($stats['distribution'] as $dist): ?>
                const jour = '<?php echo $dist['jour']; ?>';
                if (!jours[jour]) jours[jour] = 0;
                jours[jour] += <?php echo $dist['nombre']; ?>;
            <?php endforeach; ?>
            
            const distributionLabels = Object.keys(jours);
            const distributionData = Object.values(jours);
            
            new Chart(distributionCtx, {
                type: 'polarArea',
                data: {
                    labels: distributionLabels,
                    datasets: [{
                        data: distributionData,
                        backgroundColor: [
                            '#4361ee', '#3a0ca3', '#7209b7', '#f72585',
                            '#4cc9f0', '#560bad', '#480ca8', '#3a0ca3'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        r: {
                            ticks: {
                                display: false
                            }
                        }
                    }
                }
            });
            <?php endif; ?>
        });
    </script>
</body>
</html>