<?php
// doyen/departments.php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Vérifier que l'utilisateur est doyen ou vice-doyen
require_role(['doyen', 'vice_doyen']);

$user = get_logged_in_user();

// Récupérer tous les départements avec statistiques
$sql = "
    SELECT 
        d.*,
        COUNT(DISTINCT f.id) as nb_formations,
        COUNT(DISTINCT p.id) as nb_profs,
        COUNT(DISTINCT e.id) as nb_etudiants,
        COUNT(DISTINCT m.id) as nb_modules,
        COUNT(DISTINCT ex.id) as nb_examens_mois,
        c.nom as chef_nom,
        c.prenom as chef_prenom
    FROM departements d
    LEFT JOIN formations f ON d.id = f.dept_id
    LEFT JOIN professeurs p ON d.id = p.dept_id AND p.role = 'prof'
    LEFT JOIN etudiants e ON f.id = e.formation_id
    LEFT JOIN modules m ON f.id = m.formation_id
    LEFT JOIN examens ex ON m.id = ex.module_id 
        AND MONTH(ex.date_heure) = MONTH(CURRENT_DATE())
    LEFT JOIN professeurs c ON d.id = c.dept_id AND c.role = 'chef_dept'
    GROUP BY d.id
    ORDER BY d.nom
";

$stmt = $pdo->prepare($sql);
$stmt->execute();
$departements = $stmt->fetchAll();

// Récupérer les KPIs globaux
$sql_kpis = "
    SELECT 
        SUM(nb_formations) as total_formations,
        SUM(nb_profs) as total_profs,
        SUM(nb_etudiants) as total_etudiants,
        SUM(nb_modules) as total_modules,
        SUM(nb_examens_mois) as total_examens_mois
    FROM (
        SELECT 
            d.id,
            COUNT(DISTINCT f.id) as nb_formations,
            COUNT(DISTINCT p.id) as nb_profs,
            COUNT(DISTINCT e.id) as nb_etudiants,
            COUNT(DISTINCT m.id) as nb_modules,
            COUNT(DISTINCT ex.id) as nb_examens_mois
        FROM departements d
        LEFT JOIN formations f ON d.id = f.dept_id
        LEFT JOIN professeurs p ON d.id = p.dept_id
        LEFT JOIN etudiants e ON f.id = e.formation_id
        LEFT JOIN modules m ON f.id = m.formation_id
        LEFT JOIN examens ex ON m.id = ex.module_id 
            AND MONTH(ex.date_heure) = MONTH(CURRENT_DATE())
        GROUP BY d.id
    ) as stats
";

$stmt = $pdo->prepare($sql_kpis);
$stmt->execute();
$kpis_globaux = $stmt->fetch();

$page_title = "Départements - Doyenné";
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
        .depts-header {
            background: linear-gradient(135deg, #8B0000 0%, #DC143C 100%);
            color: white;
            padding: 2rem;
            border-radius: var(--border-radius);
            margin-bottom: 2rem;
        }
        
        .global-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .global-stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius-sm);
            text-align: center;
            box-shadow: var(--shadow-sm);
        }
        
        .global-stat-card .icon {
            font-size: 2rem;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }
        
        .global-stat-card .number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--gray-800);
        }
        
        .global-stat-card .label {
            font-size: 0.9rem;
            color: var(--gray-600);
        }
        
        .depts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }
        
        .dept-card {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }
        
        .dept-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }
        
        .dept-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary) 100%);
            color: white;
        }
        
        .dept-name {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .dept-chef {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        .dept-body {
            padding: 1.5rem;
        }
        
        .dept-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .dept-stat {
            text-align: center;
        }
        
        .dept-stat .number {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.25rem;
        }
        
        .dept-stat .label {
            font-size: 0.85rem;
            color: var(--gray-600);
        }
        
        .dept-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        
        .btn-icon {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .empty-state {
            grid-column: 1 / -1;
            text-align: center;
            padding: 3rem 1rem;
            color: var(--gray-500);
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
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
                    <h1>Départements - Doyenné</h1>
                    <p>Supervision des départements de la faculté</p>
                </div>
            </header>
            
            <div class="depts-header">
                <h2><i class="fas fa-building"></i> Départements de la Faculté</h2>
                <p>Vue globale de tous les départements sous votre supervision</p>
            </div>
            
            <!-- Statistiques globales -->
            <div class="global-stats">
                <div class="global-stat-card">
                    <div class="icon"><i class="fas fa-building"></i></div>
                    <div class="number"><?php echo count($departements); ?></div>
                    <div class="label">Départements</div>
                </div>
                
                <div class="global-stat-card">
                    <div class="icon"><i class="fas fa-graduation-cap"></i></div>
                    <div class="number"><?php echo $kpis_globaux['total_formations'] ?? 0; ?></div>
                    <div class="label">Formations</div>
                </div>
                
                <div class="global-stat-card">
                    <div class="icon"><i class="fas fa-user-tie"></i></div>
                    <div class="number"><?php echo $kpis_globaux['total_profs'] ?? 0; ?></div>
                    <div class="label">Enseignants</div>
                </div>
                
                <div class="global-stat-card">
                    <div class="icon"><i class="fas fa-users"></i></div>
                    <div class="number"><?php echo $kpis_globaux['total_etudiants'] ?? 0; ?></div>
                    <div class="label">Étudiants</div>
                </div>
                
                <div class="global-stat-card">
                    <div class="icon"><i class="fas fa-file-alt"></i></div>
                    <div class="number"><?php echo $kpis_globaux['total_examens_mois'] ?? 0; ?></div>
                    <div class="label">Examens ce mois</div>
                </div>
            </div>
            
            <!-- Grille des départements -->
            <div class="depts-grid">
                <?php if (empty($departements)): ?>
                    <div class="empty-state">
                        <i class="fas fa-building"></i>
                        <h3>Aucun département enregistré</h3>
                        <p>Commencez par ajouter des départements</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($departements as $dept): ?>
                        <div class="dept-card">
                            <div class="dept-header">
                                <div class="dept-name"><?php echo htmlspecialchars($dept['nom']); ?></div>
                                <?php if ($dept['chef_nom']): ?>
                                    <div class="dept-chef">
                                        <i class="fas fa-crown"></i>
                                        <?php echo htmlspecialchars($dept['chef_nom'] . ' ' . $dept['chef_prenom']); ?>
                                    </div>
                                <?php else: ?>
                                    <div class="dept-chef">
                                        <i class="fas fa-exclamation-circle"></i>
                                        Aucun chef assigné
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="dept-body">
                                <div class="dept-stats">
                                    <div class="dept-stat">
                                        <div class="number"><?php echo $dept['nb_formations']; ?></div>
                                        <div class="label">Formations</div>
                                    </div>
                                    
                                    <div class="dept-stat">
                                        <div class="number"><?php echo $dept['nb_profs']; ?></div>
                                        <div class="label">Enseignants</div>
                                    </div>
                                    
                                    <div class="dept-stat">
                                        <div class="number"><?php echo $dept['nb_etudiants']; ?></div>
                                        <div class="label">Étudiants</div>
                                    </div>
                                    
                                    <div class="dept-stat">
                                        <div class="number"><?php echo $dept['nb_examens_mois']; ?></div>
                                        <div class="label">Examens</div>
                                    </div>
                                </div>
                                
                                <div class="dept-actions">
                                    <a href="department_detail.php?id=<?php echo $dept['id']; ?>" 
                                       class="btn btn-sm btn-primary btn-icon">
                                        <i class="fas fa-eye"></i> Voir
                                    </a>
                                    
                                    <a href="department_schedule.php?dept=<?php echo $dept['id']; ?>" 
                                       class="btn btn-sm btn-outline btn-icon">
                                        <i class="fas fa-calendar-alt"></i> Planning
                                    </a>
                                    
                                    <a href="department_stats.php?id=<?php echo $dept['id']; ?>" 
                                       class="btn btn-sm btn-outline btn-icon">
                                        <i class="fas fa-chart-bar"></i> Statistiques
                                    </a>
                                    
                                    <?php if (!$dept['chef_nom']): ?>
                                        <a href="assign_chef.php?dept=<?php echo $dept['id']; ?>" 
                                           class="btn btn-sm btn-warning btn-icon">
                                            <i class="fas fa-user-plus"></i> Assigner chef
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>