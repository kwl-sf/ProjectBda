<?php
// admin/manage_rooms.php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Vérifier que l'utilisateur est admin
require_role(['admin']);

// Récupérer l'utilisateur connecté
$user = get_logged_in_user();

// Variables
$message = '';
$message_type = '';

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_room') {
        // Ajouter une nouvelle salle
        $nom = trim($_POST['nom'] ?? '');
        $capacite = intval($_POST['capacite'] ?? 0);
        $type = $_POST['type'] ?? 'salle';
        $batiment = trim($_POST['batiment'] ?? '');
        $equipements = trim($_POST['equipements'] ?? '');
        
        if (empty($nom) || $capacite <= 0) {
            $message = "Veuillez remplir tous les champs obligatoires";
            $message_type = 'error';
        } else {
            $stmt = $pdo->prepare("INSERT INTO lieu_examen (nom, capacite, type, batiment, equipements) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$nom, $capacite, $type, $batiment, $equipements]);
            
            $message = "Salle ajoutée avec succès";
            $message_type = 'success';
            
            // Journaliser l'action
            $stmt = $pdo->prepare("INSERT INTO logs_activite (utilisateur_id, utilisateur_type, action, details) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user['id'], 'admin', 'Ajout salle', "Salle: $nom, Capacité: $capacite"]);
        }
        
    } elseif ($action === 'edit_room') {
        // Modifier une salle existante
        $id = intval($_POST['id'] ?? 0);
        $nom = trim($_POST['nom'] ?? '');
        $capacite = intval($_POST['capacite'] ?? 0);
        $type = $_POST['type'] ?? 'salle';
        $batiment = trim($_POST['batiment'] ?? '');
        $equipements = trim($_POST['equipements'] ?? '');
        $disponible = isset($_POST['disponible']) ? 1 : 0;
        
        $stmt = $pdo->prepare("UPDATE lieu_examen SET nom = ?, capacite = ?, type = ?, batiment = ?, equipements = ?, disponible = ? WHERE id = ?");
        $stmt->execute([$nom, $capacite, $type, $batiment, $equipements, $disponible, $id]);
        
        $message = "Salle modifiée avec succès";
        $message_type = 'success';
        
        // Journaliser l'action
        $stmt = $pdo->prepare("INSERT INTO logs_activite (utilisateur_id, utilisateur_type, action, details) VALUES (?, ?, ?, ?)");
        $stmt->execute([$user['id'], 'admin', 'Modification salle', "Salle #$id: $nom"]);
        
    } elseif ($action === 'delete_room') {
        // Supprimer une salle
        $id = intval($_POST['id'] ?? 0);
        
        // Vérifier si la salle est utilisée dans des examens
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM examens WHERE salle_id = ?");
        $stmt->execute([$id]);
        $usage = $stmt->fetch();
        
        if ($usage['count'] > 0) {
            $message = "Impossible de supprimer cette salle : elle est utilisée dans des examens";
            $message_type = 'error';
        } else {
            $stmt = $pdo->prepare("DELETE FROM lieu_examen WHERE id = ?");
            $stmt->execute([$id]);
            
            $message = "Salle supprimée avec succès";
            $message_type = 'success';
            
            // Journaliser l'action
            $stmt = $pdo->prepare("INSERT INTO logs_activite (utilisateur_id, utilisateur_type, action, details) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user['id'], 'admin', 'Suppression salle', "Salle #$id supprimée"]);
        }
    } elseif ($action === 'toggle_status') {
        // Activer/Désactiver une salle
        $id = intval($_POST['id'] ?? 0);
        $current_status = intval($_POST['current_status'] ?? 0);
        $new_status = $current_status ? 0 : 1;
        
        $stmt = $pdo->prepare("UPDATE lieu_examen SET disponible = ? WHERE id = ?");
        $stmt->execute([$new_status, $id]);
        
        $status_text = $new_status ? 'activée' : 'désactivée';
        $message = "Salle $status_text avec succès";
        $message_type = 'success';
    }
}

// Récupérer toutes les salles
$stmt = $pdo->query("SELECT * FROM lieu_examen ORDER BY batiment, type, nom");
$salles = $stmt->fetchAll();

// Récupérer les statistiques
$stmt = $pdo->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN disponible = 1 THEN 1 ELSE 0 END) as disponibles,
    SUM(capacite) as capacite_totale,
    AVG(capacite) as capacite_moyenne
FROM lieu_examen");
$stats = $stmt->fetch();

// Récupérer l'occupation des salles
$stmt = $pdo->query("SELECT 
    l.*,
    COUNT(e.id) as examens_planifies,
    GROUP_CONCAT(DISTINCT DATE(e.date_heure) ORDER BY e.date_heure LIMIT 5) as dates_examens
FROM lieu_examen l
LEFT JOIN examens e ON l.id = e.salle_id AND e.statut = 'confirme'
GROUP BY l.id
ORDER BY examens_planifies DESC");
$salles_avec_occupation = $stmt->fetchAll();

// Récupérer les salles par type
$stmt = $pdo->query("SELECT type, COUNT(*) as count FROM lieu_examen GROUP BY type");
$salles_par_type = $stmt->fetchAll();

// Récupérer les salles par bâtiment
$stmt = $pdo->query("SELECT batiment, COUNT(*) as count FROM lieu_examen GROUP BY batiment");
$salles_par_batiment = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gérer les Salles | <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .rooms-container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header-section {
            background: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%);
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .header-content h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        
        .header-content p {
            opacity: 0.9;
        }
        
        .stats-badges {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .stat-badge {
            background: rgba(255, 255, 255, 0.2);
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            backdrop-filter: blur(10px);
            text-align: center;
        }
        
        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            display: block;
        }
        
        .stat-label {
            font-size: 0.85rem;
            opacity: 0.9;
        }
        
        .rooms-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .room-card {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow-md);
            transition: var(--transition);
            position: relative;
        }
        
        .room-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .room-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .room-type {
            font-size: 0.85rem;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .room-type.amphi { background: rgba(67, 97, 238, 0.1); color: var(--primary); }
        .room-type.salle { background: rgba(46, 204, 113, 0.1); color: var(--success); }
        .room-type.labo { background: rgba(155, 89, 182, 0.1); color: #9b59b6; }
        
        .room-status {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
        }
        
        .status-dot.active { background: var(--success); }
        .status-dot.inactive { background: var(--danger); }
        
        .room-body {
            padding: 1.5rem;
        }
        
        .room-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--gray-900);
        }
        
        .room-batiment {
            color: var(--gray-600);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .room-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1rem;
        }
        
        .info-item {
            text-align: center;
        }
        
        .info-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
            display: block;
        }
        
        .info-label {
            font-size: 0.85rem;
            color: var(--gray-600);
        }
        
        .room-equipements {
            background: var(--gray-100);
            padding: 0.75rem;
            border-radius: var(--border-radius-sm);
            margin-bottom: 1rem;
        }
        
        .equipement-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        
        .equipement-tag {
            background: white;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.85rem;
            border: 1px solid var(--gray-200);
        }
        
        .room-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--gray-100);
            border: none;
            cursor: pointer;
            transition: var(--transition);
            color: var(--gray-700);
        }
        
        .btn-icon:hover {
            background: var(--gray-200);
            transform: scale(1.1);
        }
        
        .btn-icon.edit:hover { background: rgba(67, 97, 238, 0.1); color: var(--primary); }
        .btn-icon.delete:hover { background: rgba(231, 76, 60, 0.1); color: var(--danger); }
        .btn-icon.toggle:hover { background: rgba(46, 204, 113, 0.1); color: var(--success); }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        
        .modal-content {
            background: white;
            border-radius: var(--border-radius-lg);
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalSlideIn 0.3s ease-out;
        }
        
        @keyframes modalSlideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--gray-900);
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray-600);
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: var(--transition);
        }
        
        .modal-close:hover {
            background: var(--gray-100);
            color: var(--danger);
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .form-group.full-width {
            grid-column: span 2;
        }
        
        .distribution-charts {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .chart-container {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
        }
        
        .chart-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--gray-900);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .chart-bars {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .chart-bar {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .chart-label {
            width: 120px;
            font-weight: 500;
        }
        
        .chart-progress {
            flex: 1;
            height: 10px;
            background: var(--gray-200);
            border-radius: 5px;
            overflow: hidden;
        }
        
        .chart-fill {
            height: 100%;
            border-radius: 5px;
        }
        
        .chart-count {
            width: 60px;
            text-align: right;
            font-weight: 600;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            background: var(--gray-100);
            border-radius: var(--border-radius);
            margin: 2rem 0;
        }
        
        .empty-state i {
            font-size: 3rem;
            color: var(--gray-400);
            margin-bottom: 1rem;
        }
        
        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: var(--gray-700);
        }
        
        .empty-state p {
            color: var(--gray-600);
            max-width: 500px;
            margin: 0 auto 1.5rem;
        }
        
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .form-group.full-width {
                grid-column: span 1;
            }
            
            .distribution-charts {
                grid-template-columns: 1fr;
            }
            
            .rooms-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-graduation-cap"></i> PlanExam Pro</h2>
                <p>Gestion des Salles</p>
            </div>
            
            <div class="user-info">
                <div class="user-avatar">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div class="user-name"><?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($user['role_fr']); ?></div>
            </div>
            
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                    <span>Tableau de Bord</span>
                </a>
                <a href="generate_schedule.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-calendar-plus"></i></span>
                    <span>Générer EDT</span>
                </a>
                <a href="manage_rooms.php" class="nav-item active">
                    <span class="nav-icon"><i class="fas fa-building"></i></span>
                    <span>Gérer les Salles</span>
                </a>
                <a href="conflicts.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-exclamation-triangle"></i></span>
                    <span>Conflits</span>
                </a>

                <a href="" class="nav-item"><span class="nav-icon"><i class="fas fa-building"></i></span><span>Gérer les Utilisateurs</span></a>
                <a href="Statistique.php" class="nav-item"><span class="nav-icon"><i class="fas fa-building"></i></span><span>Les Statistique </span></a>
                <a href="GererUser.php" class="nav-item"><span class="nav-icon"><i class="fas fa-building"></i></span><span>Les Parametre </span></a> 
                <a href="../logout.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                    <span>Déconnexion</span>
                </a>

            </nav>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="rooms-container">
                <!-- Header Section -->
                <div class="header-section">
                    <div class="header-content">
                        <h1><i class="fas fa-building"></i> Gestion des Salles d'Examen</h1>
                        <p>Configurez et gérez toutes les salles, amphis et laboratoires disponibles pour les examens</p>
                    </div>
                    
                    <div class="stats-badges">
                        <div class="stat-badge">
                            <span class="stat-number"><?php echo $stats['total']; ?></span>
                            <span class="stat-label">Salles Total</span>
                        </div>
                        <div class="stat-badge">
                            <span class="stat-number"><?php echo $stats['disponibles']; ?></span>
                            <span class="stat-label">Disponibles</span>
                        </div>
                        <div class="stat-badge">
                            <span class="stat-number"><?php echo number_format($stats['capacite_totale']); ?></span>
                            <span class="stat-label">Capacité Totale</span>
                        </div>
                    </div>
                </div>
                
                <!-- Messages -->
                <?php if ($message): ?>
                    <div class="flash-message flash-<?php echo $message_type; ?> animate__animated animate__fadeIn">
                        <span class="flash-icon">
                            <?php echo $message_type === 'success' ? '✅' : '⚠️'; ?>
                        </span>
                        <span><?php echo htmlspecialchars($message); ?></span>
                    </div>
                <?php endif; ?>
                
                <!-- Action Buttons -->
                <div style="margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: center;">
                    <h2 class="section-title">
                        <i class="fas fa-door-open"></i>
                        Toutes les Salles
                    </h2>
                    <button class="btn btn-primary" onclick="openAddModal()">
                        <i class="fas fa-plus"></i>
                        Ajouter une Salle
                    </button>
                </div>
                
                <!-- Distribution Charts -->
                <div class="distribution-charts">
                    <div class="chart-container">
                        <h3 class="chart-title">
                            <i class="fas fa-chart-pie"></i>
                            Répartition par Type
                        </h3>
                        <div class="chart-bars">
                            <?php foreach ($salles_par_type as $type): ?>
                                <div class="chart-bar">
                                    <span class="chart-label"><?php echo ucfirst($type['type']); ?></span>
                                    <div class="chart-progress">
                                        <div class="chart-fill" style="width: <?php echo ($type['count'] / $stats['total']) * 100; ?>%; 
                                            background: <?php 
                                                if ($type['type'] === 'amphi') echo 'var(--primary)';
                                                elseif ($type['type'] === 'salle') echo 'var(--success)';
                                                else echo '#9b59b6';
                                            ?>;">
                                        </div>
                                    </div>
                                    <span class="chart-count"><?php echo $type['count']; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="chart-container">
                        <h3 class="chart-title">
                            <i class="fas fa-university"></i>
                            Répartition par Bâtiment
                        </h3>
                        <div class="chart-bars">
                            <?php foreach ($salles_par_batiment as $batiment): ?>
                                <div class="chart-bar">
                                    <span class="chart-label"><?php echo htmlspecialchars($batiment['batiment'] ?: 'Non spécifié'); ?></span>
                                    <div class="chart-progress">
                                        <div class="chart-fill" style="width: <?php echo ($batiment['count'] / $stats['total']) * 100; ?>%; 
                                            background: <?php echo generate_badge_color($batiment['batiment']); ?>;">
                                        </div>
                                    </div>
                                    <span class="chart-count"><?php echo $batiment['count']; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Rooms Grid -->
                <?php if (count($salles) > 0): ?>
                    <div class="rooms-grid">
                        <?php foreach ($salles_avec_occupation as $salle): ?>
                            <div class="room-card animate__animated animate__fadeIn">
                                <div class="room-header">
                                    <span class="room-type <?php echo $salle['type']; ?>">
                                        <i class="fas fa-<?php 
                                            if ($salle['type'] === 'amphi') echo 'chalkboard-teacher';
                                            elseif ($salle['type'] === 'labo') echo 'flask';
                                            else echo 'door-open';
                                        ?>"></i>
                                        <?php echo ucfirst($salle['type']); ?>
                                    </span>
                                    <span class="room-status">
                                        <span class="status-dot <?php echo $salle['disponible'] ? 'active' : 'inactive'; ?>"></span>
                                        <?php echo $salle['disponible'] ? 'Disponible' : 'Indisponible'; ?>
                                    </span>
                                </div>
                                
                                <div class="room-body">
                                    <h3 class="room-title"><?php echo htmlspecialchars($salle['nom']); ?></h3>
                                    <div class="room-batiment">
                                        <i class="fas fa-university"></i>
                                        <?php echo htmlspecialchars($salle['batiment'] ?: 'Non spécifié'); ?>
                                    </div>
                                    
                                    <div class="room-info">
                                        <div class="info-item">
                                            <span class="info-value"><?php echo $salle['capacite']; ?></span>
                                            <span class="info-label">Places</span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-value"><?php echo $salle['examens_planifies']; ?></span>
                                            <span class="info-label">Examens</span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-value">
                                                <?php echo $salle['examens_planifies'] > 0 ? '💼' : '📭'; ?>
                                            </span>
                                            <span class="info-label">Occupation</span>
                                        </div>
                                    </div>
                                    
                                    <?php if ($salle['equipements']): ?>
                                        <div class="room-equipements">
                                            <h4 style="font-size: 0.9rem; margin-bottom: 0.5rem; color: var(--gray-700);">
                                                <i class="fas fa-tools"></i> Équipements
                                            </h4>
                                            <div class="equipement-list">
                                                <?php 
                                                $equipements_list = explode(',', $salle['equipements']);
                                                foreach ($equipements_list as $equip):
                                                    if (trim($equip)):
                                                ?>
                                                    <span class="equipement-tag"><?php echo htmlspecialchars(trim($equip)); ?></span>
                                                <?php 
                                                    endif;
                                                endforeach; 
                                                ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="room-actions">
                                        <button class="btn-icon edit" onclick="openEditModal(<?php echo $salle['id']; ?>)"
                                                data-nom="<?php echo htmlspecialchars($salle['nom']); ?>"
                                                data-capacite="<?php echo $salle['capacite']; ?>"
                                                data-type="<?php echo $salle['type']; ?>"
                                                data-batiment="<?php echo htmlspecialchars($salle['batiment']); ?>"
                                                data-equipements="<?php echo htmlspecialchars($salle['equipements']); ?>"
                                                data-disponible="<?php echo $salle['disponible']; ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="id" value="<?php echo $salle['id']; ?>">
                                            <input type="hidden" name="current_status" value="<?php echo $salle['disponible']; ?>">
                                            <button type="submit" class="btn-icon toggle" title="<?php echo $salle['disponible'] ? 'Désactiver' : 'Activer'; ?>">
                                                <i class="fas fa-power-off"></i>
                                            </button>
                                        </form>
                                        
                                        <button class="btn-icon delete" onclick="confirmDelete(<?php echo $salle['id']; ?>, '<?php echo htmlspecialchars($salle['nom']); ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-door-closed"></i>
                        <h3>Aucune salle disponible</h3>
                        <p>Commencez par ajouter des salles, amphis ou laboratoires pour pouvoir planifier les examens.</p>
                        <button class="btn btn-primary" onclick="openAddModal()">
                            <i class="fas fa-plus"></i>
                            Ajouter votre première salle
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <!-- Add Room Modal -->
    <div class="modal" id="addModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus"></i> Ajouter une Nouvelle Salle</h3>
                <button class="modal-close" onclick="closeAddModal()">×</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="add_room">
                    
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label for="add_nom"><i class="fas fa-signature"></i> Nom de la Salle *</label>
                            <input type="text" id="add_nom" name="nom" class="form-control" required 
                                   placeholder="Ex: Amphi A, Salle 101, Labo Info 1">
                        </div>
                        
                        <div class="form-group">
                            <label for="add_capacite"><i class="fas fa-users"></i> Capacité *</label>
                            <input type="number" id="add_capacite" name="capacite" class="form-control" required 
                                   min="1" max="1000" placeholder="Nombre de places">
                        </div>
                        
                        <div class="form-group">
                            <label for="add_type"><i class="fas fa-tag"></i> Type *</label>
                            <select id="add_type" name="type" class="form-control" required>
                                <option value="salle">Salle</option>
                                <option value="amphi">Amphithéâtre</option>
                                <option value="labo">Laboratoire</option>
                            </select>
                        </div>
                        
                        <div class="form-group full-width">
                            <label for="add_batiment"><i class="fas fa-university"></i> Bâtiment</label>
                            <input type="text" id="add_batiment" name="batiment" class="form-control" 
                                   placeholder="Ex: Bâtiment Principal, Bâtiment Informatique">
                        </div>
                        
                        <div class="form-group full-width">
                            <label for="add_equipements"><i class="fas fa-tools"></i> Équipements (séparés par des virgules)</label>
                            <input type="text" id="add_equipements" name="equipements" class="form-control" 
                                   placeholder="Ex: Vidéoprojecteur, Tableau blanc, 30 PC">
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                        <button type="submit" class="btn btn-primary" style="flex: 1;">
                            <i class="fas fa-save"></i> Enregistrer
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeAddModal()" style="flex: 1;">
                            <i class="fas fa-times"></i> Annuler
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Room Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Modifier la Salle</h3>
                <button class="modal-close" onclick="closeEditModal()">×</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="editForm">
                    <input type="hidden" name="action" value="edit_room">
                    <input type="hidden" name="id" id="edit_id">
                    
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label for="edit_nom"><i class="fas fa-signature"></i> Nom de la Salle *</label>
                            <input type="text" id="edit_nom" name="nom" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_capacite"><i class="fas fa-users"></i> Capacité *</label>
                            <input type="number" id="edit_capacite" name="capacite" class="form-control" required min="1" max="1000">
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_type"><i class="fas fa-tag"></i> Type *</label>
                            <select id="edit_type" name="type" class="form-control" required>
                                <option value="salle">Salle</option>
                                <option value="amphi">Amphithéâtre</option>
                                <option value="labo">Laboratoire</option>
                            </select>
                        </div>
                        
                        <div class="form-group full-width">
                            <label for="edit_batiment"><i class="fas fa-university"></i> Bâtiment</label>
                            <input type="text" id="edit_batiment" name="batiment" class="form-control">
                        </div>
                        
                        <div class="form-group full-width">
                            <label for="edit_equipements"><i class="fas fa-tools"></i> Équipements</label>
                            <input type="text" id="edit_equipements" name="equipements" class="form-control" 
                                   placeholder="Séparés par des virgules">
                        </div>
                        
                        <div class="form-group">
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="checkbox" id="edit_disponible" name="disponible" value="1">
                                <span>Salle disponible</span>
                            </label>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                        <button type="submit" class="btn btn-primary" style="flex: 1;">
                            <i class="fas fa-save"></i> Enregistrer les modifications
                        </button>
                        <button type="button" class="btn btn-danger" onclick="closeEditModal()" style="flex: 1;">
                            <i class="fas fa-times"></i> Annuler
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Confirmer la suppression</h3>
                <button class="modal-close" onclick="closeDeleteModal()">×</button>
            </div>
            <div class="modal-body">
                <p id="deleteMessage">Êtes-vous sûr de vouloir supprimer cette salle ? Cette action est irréversible.</p>
                
                <form method="POST" action="" id="deleteForm">
                    <input type="hidden" name="action" value="delete_room">
                    <input type="hidden" name="id" id="delete_id">
                    
                    <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                        <button type="submit" class="btn btn-danger" style="flex: 1;">
                            <i class="fas fa-trash"></i> Oui, supprimer
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()" style="flex: 1;">
                            <i class="fas fa-times"></i> Annuler
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Menu Toggle
        document.querySelector('.menu-toggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
        });
        
        // Modal Functions
        function openAddModal() {
            document.getElementById('addModal').style.display = 'flex';
        }
        
        function closeAddModal() {
            document.getElementById('addModal').style.display = 'none';
        }
        
        function openEditModal(roomId) {
            const roomCard = document.querySelector(`button.edit[data-nom]`);
            if (roomCard) {
                document.getElementById('edit_id').value = roomId;
                document.getElementById('edit_nom').value = roomCard.getAttribute('data-nom');
                document.getElementById('edit_capacite').value = roomCard.getAttribute('data-capacite');
                document.getElementById('edit_type').value = roomCard.getAttribute('data-type');
                document.getElementById('edit_batiment').value = roomCard.getAttribute('data-batiment');
                document.getElementById('edit_equipements').value = roomCard.getAttribute('data-equipements');
                document.getElementById('edit_disponible').checked = roomCard.getAttribute('data-disponible') === '1';
                
                document.getElementById('editModal').style.display = 'flex';
            }
        }
        
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }
        
        function confirmDelete(roomId, roomName) {
            document.getElementById('delete_id').value = roomId;
            document.getElementById('deleteMessage').innerHTML = 
                `Êtes-vous sûr de vouloir supprimer la salle <strong>"${roomName}"</strong> ? Cette action est irréversible.`;
            document.getElementById('deleteModal').style.display = 'flex';
        }
        
        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        }
        
        // Add animation to room cards
        document.addEventListener('DOMContentLoaded', function() {
            const roomCards = document.querySelectorAll('.room-card');
            roomCards.forEach((card, index) => {
                card.style.animationDelay = (index * 0.1) + 's';
            });
        });
        
        // Form validation for capacity
        document.getElementById('add_capacite')?.addEventListener('change', function(e) {
            if (this.value < 1) this.value = 1;
            if (this.value > 1000) this.value = 1000;
        });
        
        document.getElementById('edit_capacite')?.addEventListener('change', function(e) {
            if (this.value < 1) this.value = 1;
            if (this.value > 1000) this.value = 1000;
        });
    </script>
</body>
</html>