<?php
// chef_dept/conflicts.php
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

// Paramètres de filtrage
$type = $_GET['type'] ?? '';
$statut = $_GET['statut'] ?? 'detecte';
$date_debut = $_GET['date_debut'] ?? '';
$date_fin = $_GET['date_fin'] ?? '';

// Construire la requête pour les conflits du département
$sql = "SELECT c.*,
               p1.nom as entite1_nom, p1.prenom as entite1_prenom,
               p2.nom as entite2_nom, p2.prenom as entite2_prenom,
               e1.nom as etudiant1_nom, e1.prenom as etudiant1_prenom,
               e2.nom as etudiant2_nom, e2.prenom as etudiant2_prenom,
               ex1.date_heure as examen1_date, ex2.date_heure as examen2_date,
               m1.nom as module1_nom, m2.nom as module2_nom
        FROM conflits c
        LEFT JOIN professeurs p1 ON c.entite1_id = p1.id AND c.type = 'professeur'
        LEFT JOIN professeurs p2 ON c.entite2_id = p2.id AND c.type = 'professeur'
        LEFT JOIN etudiants e1 ON c.entite1_id = e1.id AND c.type = 'etudiant'
        LEFT JOIN etudiants e2 ON c.entite2_id = e2.id AND c.type = 'etudiant'
        LEFT JOIN examens ex1 ON c.entite1_id = ex1.id AND c.type IN ('salle', 'horaire')
        LEFT JOIN examens ex2 ON c.entite2_id = ex2.id AND c.type IN ('salle', 'horaire')
        LEFT JOIN modules m1 ON ex1.module_id = m1.id
        LEFT JOIN modules m2 ON ex2.module_id = m2.id
        WHERE (p1.dept_id = ? OR p2.dept_id = ? OR 
               e1.id IN (SELECT id FROM etudiants WHERE formation_id IN 
                         (SELECT id FROM formations WHERE dept_id = ?)) OR
               e2.id IN (SELECT id FROM etudiants WHERE formation_id IN 
                         (SELECT id FROM formations WHERE dept_id = ?))) ";

$params = [$dept_id, $dept_id, $dept_id, $dept_id];

if ($type && $type !== 'all') {
    $sql .= " AND c.type = ?";
    $params[] = $type;
}

if ($statut && $statut !== 'all') {
    $sql .= " AND c.statut = ?";
    $params[] = $statut;
}

if ($date_debut) {
    $sql .= " AND DATE(c.date_detection) >= ?";
    $params[] = $date_debut;
}

if ($date_fin) {
    $sql .= " AND DATE(c.date_detection) <= ?";
    $params[] = $date_fin;
}

$sql .= " ORDER BY c.date_detection DESC, 
          CASE c.statut 
            WHEN 'detecte' THEN 1
            WHEN 'resolu' THEN 3
            ELSE 2
          END";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$conflits = $stmt->fetchAll();

// Statistiques
$stats = [
    'total' => count($conflits),
    'detecte' => 0,
    'resolu' => 0,
    'ignore' => 0,
    'par_type' => []
];

foreach ($conflits as $conflit) {
    $stats[$conflit['statut']]++;
    
    $type_key = $conflit['type'];
    if (!isset($stats['par_type'][$type_key])) {
        $stats['par_type'][$type_key] = 0;
    }
    $stats['par_type'][$type_key]++;
}

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $conflit_id = $_POST['conflit_id'] ?? 0;
    $resolution = $_POST['resolution'] ?? '';
    
    if ($action === 'resoudre') {
        $stmt = $pdo->prepare("
            UPDATE conflits 
            SET statut = 'resolu', 
                date_resolution = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([$conflit_id]);
        
        // Journaliser
        $stmt = $pdo->prepare("
            INSERT INTO logs_activite (utilisateur_id, utilisateur_type, action, details)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$user['id'], 'chef_dept', 'Conflit résolu', 'Conflit ID: ' . $conflit_id]);
        
        $_SESSION['flash_message'] = 'Conflit marqué comme résolu.';
        $_SESSION['flash_type'] = 'success';
        
    } elseif ($action === 'ignorer') {
        $stmt = $pdo->prepare("
            UPDATE conflits 
            SET statut = 'ignore'
            WHERE id = ?
        ");
        
        $stmt->execute([$conflit_id]);
        
        $_SESSION['flash_message'] = 'Conflit ignoré.';
        $_SESSION['flash_type'] = 'warning';
    }
    
    header('Location: conflicts.php');
    exit();
}

$page_title = "Gestion des Conflits - " . htmlspecialchars($dept['nom']);
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
        .conflicts-container {
            max-width: 1600px;
            margin: 0 auto;
        }
        
        /* Statistiques */
        .conflict-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .conflict-stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .conflict-stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
        }
        
        .conflict-stat-card.detecte::before { background: var(--warning); }
        .conflict-stat-card.resolu::before { background: var(--success); }
        .conflict-stat-card.ignore::before { background: var(--gray-500); }
        .conflict-stat-card.total::before { background: var(--primary); }
        
        .conflict-stat-icon {
            font-size: 2rem;
            margin-bottom: 1rem;
        }
        
        .conflict-stat-card.detecte .conflict-stat-icon { color: var(--warning); }
        .conflict-stat-card.resolu .conflict-stat-icon { color: var(--success); }
        .conflict-stat-card.ignore .conflict-stat-icon { color: var(--gray-500); }
        .conflict-stat-card.total .conflict-stat-icon { color: var(--primary); }
        
        .conflict-stat-value {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            color: var(--gray-900);
        }
        
        .conflict-stat-label {
            font-size: 0.9rem;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Filtres */
        .filters-section {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1rem;
        }
        
        /* Liste des conflits */
        .conflicts-list {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        
        .conflict-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            border-left: 4px solid transparent;
            transition: all 0.3s ease;
        }
        
        .conflict-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }
        
        .conflict-card.detecte {
            border-left-color: var(--warning);
            background: linear-gradient(90deg, rgba(243, 156, 18, 0.05), white);
        }
        
        .conflict-card.resolu {
            border-left-color: var(--success);
            background: linear-gradient(90deg, rgba(46, 204, 113, 0.05), white);
        }
        
        .conflict-card.ignore {
            border-left-color: var(--gray-500);
            background: linear-gradient(90deg, rgba(149, 165, 166, 0.05), white);
        }
        
        .conflict-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .conflict-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gray-900);
        }
        
        .conflict-badge {
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .badge-detecte {
            background: rgba(243, 156, 18, 0.1);
            color: var(--warning);
        }
        
        .badge-resolu {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success);
        }
        
        .badge-ignore {
            background: rgba(149, 165, 166, 0.1);
            color: var(--gray-600);
        }
        
        .conflict-body {
            margin-bottom: 1.5rem;
        }
        
        .conflict-description {
            color: var(--gray-700);
            line-height: 1.6;
            margin-bottom: 1rem;
        }
        
        .conflict-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        
        .entity-card {
            background: var(--gray-50);
            border-radius: var(--border-radius-sm);
            padding: 1rem;
        }
        
        .entity-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--gray-800);
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .entity-info {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .info-label {
            font-weight: 600;
            color: var(--gray-700);
            font-size: 0.9rem;
        }
        
        .info-value {
            color: var(--gray-900);
            font-weight: 500;
        }
        
        .conflict-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 1rem;
            border-top: 1px solid var(--gray-200);
        }
        
        .conflict-date {
            font-size: 0.85rem;
            color: var(--gray-600);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .conflict-actions {
            display: flex;
            gap: 0.75rem;
        }
        
        .btn-conflict {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: var(--border-radius-sm);
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }
        
        .btn-conflict.resolve {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success);
        }
        
        .btn-conflict.resolve:hover {
            background: rgba(46, 204, 113, 0.2);
        }
        
        .btn-conflict.ignore {
            background: rgba(149, 165, 166, 0.1);
            color: var(--gray-600);
        }
        
        .btn-conflict.ignore:hover {
            background: rgba(149, 165, 166, 0.2);
        }
        
        /* Aucun conflit */
        .no-conflicts {
            text-align: center;
            padding: 3rem;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .conflict-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .conflict-details {
                grid-template-columns: 1fr;
            }
            
            .conflict-footer {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            
            .conflict-actions {
                width: 100%;
                justify-content: flex-end;
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
                    <h1><i class="fas fa-exclamation-triangle"></i> Gestion des Conflits</h1>
                    <p>Résolution des conflits détectés dans le département <?php echo htmlspecialchars($dept['nom']); ?></p>
                </div>
                <div class="header-actions">
                    <button class="menu-toggle" id="menuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                </div>
            </header>
            
            <?php display_flash_message(); ?>
            
            <div class="conflicts-container">
                <!-- Statistiques -->
                <div class="conflict-stats">
                    <div class="conflict-stat-card total">
                        <div class="conflict-stat-icon">
                            <i class="fas fa-exclamation-circle"></i>
                        </div>
                        <div class="conflict-stat-value"><?php echo $stats['total']; ?></div>
                        <div class="conflict-stat-label">Conflits Total</div>
                    </div>
                    
                    <div class="conflict-stat-card detecte">
                        <div class="conflict-stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="conflict-stat-value"><?php echo $stats['detecte']; ?></div>
                        <div class="conflict-stat-label">À Résoudre</div>
                    </div>
                    
                    <div class="conflict-stat-card resolu">
                        <div class="conflict-stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="conflict-stat-value"><?php echo $stats['resolu']; ?></div>
                        <div class="conflict-stat-label">Résolus</div>
                    </div>
                    
                    <div class="conflict-stat-card ignore">
                        <div class="conflict-stat-icon">
                            <i class="fas fa-eye-slash"></i>
                        </div>
                        <div class="conflict-stat-value"><?php echo $stats['ignore']; ?></div>
                        <div class="conflict-stat-label">Ignorés</div>
                    </div>
                </div>
                
                <!-- Filtres -->
                <div class="filters-section">
                    <form method="GET" action="" id="filterForm">
                        <div class="filters-grid">
                            <div class="filter-group">
                                <label for="type">
                                    <i class="fas fa-tag"></i> Type de conflit
                                </label>
                                <select id="type" name="type">
                                    <option value="all">Tous les types</option>
                                    <option value="etudiant" <?php echo $type === 'etudiant' ? 'selected' : ''; ?>>Étudiant</option>
                                    <option value="professeur" <?php echo $type === 'professeur' ? 'selected' : ''; ?>>Professeur</option>
                                    <option value="salle" <?php echo $type === 'salle' ? 'selected' : ''; ?>>Salle</option>
                                    <option value="horaire" <?php echo $type === 'horaire' ? 'selected' : ''; ?>>Horaire</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label for="statut">
                                    <i class="fas fa-info-circle"></i> Statut
                                </label>
                                <select id="statut" name="statut">
                                    <option value="all">Tous les statuts</option>
                                    <option value="detecte" <?php echo $statut === 'detecte' ? 'selected' : ''; ?>>Détecté</option>
                                    <option value="resolu" <?php echo $statut === 'resolu' ? 'selected' : ''; ?>>Résolu</option>
                                    <option value="ignore" <?php echo $statut === 'ignore' ? 'selected' : ''; ?>>Ignoré</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label for="date_debut">
                                    <i class="far fa-calendar-alt"></i> Date début
                                </label>
                                <input type="text" 
                                       id="date_debut" 
                                       name="date_debut" 
                                       class="datepicker" 
                                       value="<?php echo htmlspecialchars($date_debut); ?>"
                                       placeholder="JJ/MM/AAAA">
                            </div>
                            
                            <div class="filter-group">
                                <label for="date_fin">
                                    <i class="far fa-calendar-alt"></i> Date fin
                                </label>
                                <input type="text" 
                                       id="date_fin" 
                                       name="date_fin" 
                                       class="datepicker" 
                                       value="<?php echo htmlspecialchars($date_fin); ?>"
                                       placeholder="JJ/MM/AAAA">
                            </div>
                        </div>
                        
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary" style="padding: 0.75rem 1.5rem;">
                                <i class="fas fa-filter"></i> Appliquer les filtres
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="resetFilters()">
                                <i class="fas fa-redo"></i> Réinitialiser
                            </button>
                            <button type="button" class="btn btn-success" onclick="detectNewConflicts()">
                                <i class="fas fa-search"></i> Détecter nouveaux conflits
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Liste des conflits -->
                <div class="conflicts-list">
                    <?php if (empty($conflits)): ?>
                        <div class="no-conflicts">
                            <i class="fas fa-check-circle fa-3x" style="color: var(--success); margin-bottom: 1rem;"></i>
                            <h3 style="color: var(--gray-700); margin-bottom: 0.5rem;">Aucun conflit détecté</h3>
                            <p style="color: var(--gray-600);">Tous les examens du département sont planifiés sans conflit.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($conflits as $conflit): ?>
                            <div class="conflict-card <?php echo $conflit['statut']; ?>">
                                <div class="conflict-header">
                                    <div class="conflict-title">
                                        <?php 
                                        $titles = [
                                            'etudiant' => 'Conflit d\'emploi du temps étudiant',
                                            'professeur' => 'Conflit de surveillance professeur',
                                            'salle' => 'Conflit d\'occupation de salle',
                                            'horaire' => 'Conflit horaire'
                                        ];
                                        echo $titles[$conflit['type']] ?? 'Conflit détecté';
                                        ?>
                                    </div>
                                    <span class="conflict-badge badge-<?php echo $conflit['statut']; ?>">
                                        <i class="fas <?php 
                                            echo $conflit['statut'] === 'detecte' ? 'fa-clock' : 
                                                   ($conflit['statut'] === 'resolu' ? 'fa-check-circle' : 'fa-eye-slash'); 
                                        ?>"></i>
                                        <?php echo ucfirst($conflit['statut']); ?>
                                    </span>
                                </div>
                                
                                <div class="conflict-body">
                                    <div class="conflict-description">
                                        <?php echo htmlspecialchars($conflit['description']); ?>
                                    </div>
                                    
                                    <div class="conflict-details">
                                        <!-- Entité 1 -->
                                        <div class="entity-card">
                                            <div class="entity-title">
                                                <i class="fas fa-user"></i>
                                                <?php if ($conflit['type'] === 'professeur'): ?>
                                                    Professeur concerné
                                                <?php elseif ($conflit['type'] === 'etudiant'): ?>
                                                    Étudiant concerné
                                                <?php else: ?>
                                                    Premier élément
                                                <?php endif; ?>
                                            </div>
                                            <div class="entity-info">
                                                <?php if ($conflit['type'] === 'professeur' && $conflit['entite1_nom']): ?>
                                                    <div class="info-row">
                                                        <span class="info-label">Nom:</span>
                                                        <span class="info-value">
                                                            <?php echo htmlspecialchars($conflit['entite1_prenom'] . ' ' . $conflit['entite1_nom']); ?>
                                                        </span>
                                                    </div>
                                                <?php elseif ($conflit['type'] === 'etudiant' && $conflit['etudiant1_nom']): ?>
                                                    <div class="info-row">
                                                        <span class="info-label">Étudiant:</span>
                                                        <span class="info-value">
                                                            <?php echo htmlspecialchars($conflit['etudiant1_prenom'] . ' ' . $conflit['etudiant1_nom']); ?>
                                                        </span>
                                                    </div>
                                                <?php elseif ($conflit['type'] === 'salle' && $conflit['examen1_date']): ?>
                                                    <div class="info-row">
                                                        <span class="info-label">Examen:</span>
                                                        <span class="info-value">
                                                            <?php echo htmlspecialchars($conflit['module1_nom'] ?? 'Examen'); ?>
                                                        </span>
                                                    </div>
                                                    <div class="info-row">
                                                        <span class="info-label">Date:</span>
                                                        <span class="info-value">
                                                            <?php echo format_date_fr($conflit['examen1_date'], true); ?>
                                                        </span>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="info-row">
                                                        <span class="info-label">ID:</span>
                                                        <span class="info-value"><?php echo $conflit['entite1_id']; ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <!-- Entité 2 -->
                                        <div class="entity-card">
                                            <div class="entity-title">
                                                <i class="fas fa-user"></i>
                                                <?php if ($conflit['type'] === 'professeur'): ?>
                                                    Second professeur
                                                <?php elseif ($conflit['type'] === 'etudiant'): ?>
                                                    Second étudiant
                                                <?php else: ?>
                                                    Second élément
                                                <?php endif; ?>
                                            </div>
                                            <div class="entity-info">
                                                <?php if ($conflit['type'] === 'professeur' && $conflit['entite2_nom']): ?>
                                                    <div class="info-row">
                                                        <span class="info-label">Nom:</span>
                                                        <span class="info-value">
                                                            <?php echo htmlspecialchars($conflit['entite2_prenom'] . ' ' . $conflit['entite2_nom']); ?>
                                                        </span>
                                                    </div>
                                                <?php elseif ($conflit['type'] === 'etudiant' && $conflit['etudiant2_nom']): ?>
                                                    <div class="info-row">
                                                        <span class="info-label">Étudiant:</span>
                                                        <span class="info-value">
                                                            <?php echo htmlspecialchars($conflit['etudiant2_prenom'] . ' ' . $conflit['etudiant2_nom']); ?>
                                                        </span>
                                                    </div>
                                                <?php elseif ($conflit['type'] === 'salle' && $conflit['examen2_date']): ?>
                                                    <div class="info-row">
                                                        <span class="info-label">Examen:</span>
                                                        <span class="info-value">
                                                            <?php echo htmlspecialchars($conflit['module2_nom'] ?? 'Examen'); ?>
                                                        </span>
                                                    </div>
                                                    <div class="info-row">
                                                        <span class="info-label">Date:</span>
                                                        <span class="info-value">
                                                            <?php echo format_date_fr($conflit['examen2_date'], true); ?>
                                                        </span>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="info-row">
                                                        <span class="info-label">ID:</span>
                                                        <span class="info-value"><?php echo $conflit['entite2_id']; ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="conflict-footer">
                                    <div class="conflict-date">
                                        <i class="far fa-clock"></i>
                                        Détecté le <?php echo format_date_fr($conflit['date_detection'], true); ?>
                                        <?php if ($conflit['date_resolution']): ?>
                                            <span style="margin-left: 1rem;">
                                                <i class="fas fa-check"></i>
                                                Résolu le <?php echo format_date_fr($conflit['date_resolution'], true); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if ($conflit['statut'] === 'detecte'): ?>
                                    <div class="conflict-actions">
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="resoudre">
                                            <input type="hidden" name="conflit_id" value="<?php echo $conflit['id']; ?>">
                                            <button type="submit" class="btn-conflict resolve">
                                                <i class="fas fa-check"></i> Marquer comme résolu
                                            </button>
                                        </form>
                                        
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="ignorer">
                                            <input type="hidden" name="conflit_id" value="<?php echo $conflit['id']; ?>">
                                            <button type="submit" class="btn-conflict ignore">
                                                <i class="fas fa-eye-slash"></i> Ignorer
                                            </button>
                                        </form>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/fr.js"></script>
    <script>
        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            // Initialiser les datepickers
            flatpickr.localize(flatpickr.l10ns.fr);
            flatpickr(".datepicker", {
                dateFormat: "Y-m-d",
                locale: "fr"
            });
            
            // Menu toggle
            document.getElementById('menuToggle').addEventListener('click', function() {
                document.querySelector('.sidebar').classList.toggle('active');
            });
        });
        
        // Réinitialiser les filtres
        function resetFilters() {
            document.getElementById('type').value = 'all';
            document.getElementById('statut').value = 'all';
            document.getElementById('date_debut').value = '';
            document.getElementById('date_fin').value = '';
            document.getElementById('filterForm').submit();
        }
        
        // Détecter de nouveaux conflits
        function detectNewConflicts() {
            if (confirm('Voulez-vous lancer la détection de nouveaux conflits ?')) {
                window.location.href = 'detect_conflicts.php';
            }
        }
    </script>
</body>
</html>