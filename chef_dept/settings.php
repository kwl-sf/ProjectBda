<?php
// chef_dept/settings.php
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

// Récupérer les paramètres du département
$sql_params = "SELECT * FROM parametres_systeme WHERE cle LIKE 'dept_%' OR cle LIKE 'global_%'";
$params = $pdo->query($sql_params)->fetchAll();

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_settings') {
        $max_examens_prof = $_POST['max_examens_prof'] ?? 3;
        $max_examens_etudiant = $_POST['max_examens_etudiant'] ?? 1;
        $duree_examen_min = $_POST['duree_examen_min'] ?? 60;
        $plage_horaire_debut = $_POST['plage_horaire_debut'] ?? '08:00:00';
        $plage_horaire_fin = $_POST['plage_horaire_fin'] ?? '20:00:00';
        $priorite_departement = $_POST['priorite_departement'] ?? 1;
        
        // Mettre à jour les paramètres
        $settings = [
            'max_examens_prof_par_jour' => $max_examens_prof,
            'max_examens_etudiant_par_jour' => $max_examens_etudiant,
            'duree_examen_min' => $duree_examen_min,
            'plage_horaire_debut' => $plage_horaire_debut,
            'plage_horaire_fin' => $plage_horaire_fin,
            'priorite_departement' => $priorite_departement
        ];
        
        foreach ($settings as $key => $value) {
            $stmt = $pdo->prepare("
                INSERT INTO parametres_systeme (cle, valeur, description) 
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE valeur = ?, updated_at = NOW()
            ");
            $description = "Paramètre système mis à jour par " . $user['full_name'];
            $stmt->execute([$key, $value, $description, $value]);
        }
        
        $_SESSION['flash_message'] = 'Paramètres mis à jour avec succès !';
        $_SESSION['flash_type'] = 'success';
        
    } elseif ($action === 'update_department') {
        $nom = $_POST['nom'] ?? '';
        $description = $_POST['description'] ?? '';
        
        if ($nom) {
            $stmt = $pdo->prepare("UPDATE departements SET nom = ?, description = ? WHERE id = ?");
            $stmt->execute([$nom, $description, $dept_id]);
            
            $_SESSION['flash_message'] = 'Informations du département mises à jour !';
            $_SESSION['flash_type'] = 'success';
        }
        
    } elseif ($action === 'add_formation') {
        $nom_formation = $_POST['nom_formation'] ?? '';
        $nb_modules = $_POST['nb_modules'] ?? 0;
        
        if ($nom_formation) {
            $stmt = $pdo->prepare("INSERT INTO formations (nom, dept_id, nb_modules) VALUES (?, ?, ?)");
            $stmt->execute([$nom_formation, $dept_id, $nb_modules]);
            
            $_SESSION['flash_message'] = 'Formation ajoutée avec succès !';
            $_SESSION['flash_type'] = 'success';
        }
    }
    
    header('Location: settings.php');
    exit();
}

// Récupérer les formations du département
$sql_formations = "SELECT * FROM formations WHERE dept_id = ? ORDER BY nom";
$stmt_formations = $pdo->prepare($sql_formations);
$stmt_formations->execute([$dept_id]);
$formations = $stmt_formations->fetchAll();

// Récupérer les paramètres actuels
$current_settings = [];
foreach ($params as $param) {
    $current_settings[$param['cle']] = $param['valeur'];
}

$page_title = "Paramètres - " . htmlspecialchars($dept['nom']);
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
        .settings-container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        /* Tabs */
        .settings-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 2rem;
            border-bottom: 2px solid var(--gray-200);
            padding-bottom: 0.5rem;
        }
        
        .tab-btn {
            padding: 1rem 1.5rem;
            background: none;
            border: none;
            font-size: 1rem;
            font-weight: 600;
            color: var(--gray-600);
            cursor: pointer;
            position: relative;
            transition: all 0.3s ease;
        }
        
        .tab-btn:hover {
            color: var(--primary);
        }
        
        .tab-btn.active {
            color: var(--primary);
        }
        
        .tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: -0.5rem;
            left: 0;
            width: 100%;
            height: 3px;
            background: var(--primary);
            border-radius: 3px;
        }
        
        /* Sections */
        .settings-section {
            display: none;
            background: white;
            border-radius: var(--border-radius);
            padding: 2rem;
            box-shadow: var(--shadow-md);
            margin-bottom: 2rem;
            animation: fadeIn 0.5s ease-out;
        }
        
        .settings-section.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--gray-200);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        /* Formulaire */
        .settings-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 2rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--gray-300);
            border-radius: var(--border-radius-sm);
            font-family: inherit;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        
        .form-help {
            font-size: 0.85rem;
            color: var(--gray-600);
            margin-top: 0.25rem;
        }
        
        .form-actions {
            grid-column: 1 / -1;
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            padding-top: 1.5rem;
            border-top: 1px solid var(--gray-200);
        }
        
        /* Formations */
        .formations-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .formation-card {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            position: relative;
            transition: all 0.3s ease;
        }
        
        .formation-card:hover {
            border-color: var(--primary);
            box-shadow: var(--shadow-md);
        }
        
        .formation-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .formation-name {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gray-900);
        }
        
        .formation-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .formation-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .detail-item {
            text-align: center;
            padding: 0.75rem;
            background: var(--gray-50);
            border-radius: var(--border-radius-sm);
        }
        
        .detail-value {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary);
            display: block;
            margin-bottom: 0.25rem;
        }
        
        .detail-label {
            font-size: 0.85rem;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Informations système */
        .system-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .info-card {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: var(--border-radius);
            padding: 1.5rem;
        }
        
        .info-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--gray-700);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .info-content {
            color: var(--gray-600);
            line-height: 1.6;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .settings-tabs {
                flex-direction: column;
            }
            
            .settings-form {
                grid-template-columns: 1fr;
            }
            
            .formations-list {
                grid-template-columns: 1fr;
            }
            
            .system-info {
                grid-template-columns: 1fr;
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
                    <h1><i class="fas fa-cog"></i> Paramètres du Département</h1>
                    <p>Configurez les paramètres de votre département</p>
                </div>
                <div class="header-actions">
                    <button class="menu-toggle" id="menuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                </div>
            </header>
            
            <?php display_flash_message(); ?>
            
            <div class="settings-container">
                <!-- Tabs -->
                <div class="settings-tabs">
                    <button class="tab-btn active" onclick="showTab('general')">
                        <i class="fas fa-sliders-h"></i> Général
                    </button>
                    <button class="tab-btn" onclick="showTab('formations')">
                        <i class="fas fa-university"></i> Formations
                    </button>
                    <button class="tab-btn" onclick="showTab('examens')">
                        <i class="fas fa-file-alt"></i> Paramètres Examens
                    </button>
                    <button class="tab-btn" onclick="showTab('system')">
                        <i class="fas fa-info-circle"></i> Informations Système
                    </button>
                </div>
                
                <!-- Section Générale -->
                <div class="settings-section active" id="general-tab">
                    <h2 class="section-title">
                        <i class="fas fa-sliders-h"></i> Paramètres Généraux
                    </h2>
                    
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_department">
                        
                        <div class="settings-form">
                            <div class="form-group">
                                <label for="nom">
                                    <i class="fas fa-building"></i> Nom du Département
                                </label>
                                <input type="text" 
                                       id="nom" 
                                       name="nom" 
                                       value="<?php echo htmlspecialchars($dept['nom']); ?>"
                                       required>
                            </div>
                            
                            <div class="form-group">
                                <label for="description">
                                    <i class="fas fa-align-left"></i> Description
                                </label>
                                <textarea id="description" 
                                          name="description" 
                                          rows="4"
                                          placeholder="Description du département..."><?php echo htmlspecialchars($dept['description'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label for="email_contact">
                                    <i class="fas fa-envelope"></i> Email de Contact
                                </label>
                                <input type="email" 
                                       id="email_contact" 
                                       name="email_contact" 
                                       value="<?php echo htmlspecialchars($user['email']); ?>"
                                       required>
                                <div class="form-help">Email principal pour les communications</div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Enregistrer les modifications
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Section Formations -->
                <div class="settings-section" id="formations-tab">
                    <h2 class="section-title">
                        <i class="fas fa-university"></i> Gestion des Formations
                    </h2>
                    
                    <!-- Liste des formations -->
                    <h3 style="font-size: 1.25rem; font-weight: 700; color: var(--gray-800); margin-bottom: 1.5rem;">
                        Formations du Département
                    </h3>
                    
                    <div class="formations-list">
                        <?php if (empty($formations)): ?>
                            <div style="grid-column: 1 / -1; text-align: center; padding: 2rem; color: var(--gray-500);">
                                <i class="fas fa-graduation-cap fa-2x" style="margin-bottom: 1rem;"></i>
                                <p>Aucune formation configurée</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($formations as $formation): ?>
                                <?php
                                // Compter les étudiants
                                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM etudiants WHERE formation_id = ?");
                                $stmt->execute([$formation['id']]);
                                $nb_etudiants = $stmt->fetch()['count'];
                                
                                // Compter les modules
                                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM modules WHERE formation_id = ?");
                                $stmt->execute([$formation['id']]);
                                $nb_modules = $stmt->fetch()['count'];
                                ?>
                                <div class="formation-card">
                                    <div class="formation-header">
                                        <div class="formation-name">
                                            <?php echo htmlspecialchars($formation['nom']); ?>
                                        </div>
                                        <div class="formation-actions">
                                            <button class="btn btn-sm btn-primary">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div class="formation-details">
                                        <div class="detail-item">
                                            <span class="detail-value"><?php echo $formation['nb_modules']; ?></span>
                                            <span class="detail-label">Modules</span>
                                        </div>
                                        
                                        <div class="detail-item">
                                            <span class="detail-value"><?php echo $nb_etudiants; ?></span>
                                            <span class="detail-label">Étudiants</span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Formulaire d'ajout -->
                    <h3 style="font-size: 1.25rem; font-weight: 700; color: var(--gray-800); margin-top: 2rem; margin-bottom: 1.5rem;">
                        <i class="fas fa-plus-circle"></i> Ajouter une nouvelle formation
                    </h3>
                    
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="add_formation">
                        
                        <div class="settings-form">
                            <div class="form-group">
                                <label for="nom_formation">
                                    <i class="fas fa-graduation-cap"></i> Nom de la formation
                                </label>
                                <input type="text" 
                                       id="nom_formation" 
                                       name="nom_formation" 
                                       placeholder="Ex: Licence en Informatique"
                                       required>
                            </div>
                            
                            <div class="form-group">
                                <label for="nb_modules">
                                    <i class="fas fa-book"></i> Nombre de modules prévus
                                </label>
                                <input type="number" 
                                       id="nb_modules" 
                                       name="nb_modules" 
                                       min="1" 
                                       max="20"
                                       value="6"
                                       required>
                                <div class="form-help">Généralement entre 6 et 9 modules par formation</div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-plus"></i> Ajouter la formation
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Section Paramètres Examens -->
                <div class="settings-section" id="examens-tab">
                    <h2 class="section-title">
                        <i class="fas fa-file-alt"></i> Paramètres des Examens
                    </h2>
                    
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_settings">
                        
                        <div class="settings-form">
                            <div class="form-group">
                                <label for="max_examens_prof">
                                    <i class="fas fa-user-tie"></i> Examens max/jour pour un professeur
                                </label>
                                <input type="number" 
                                       id="max_examens_prof" 
                                       name="max_examens_prof" 
                                       min="1" 
                                       max="5"
                                       value="<?php echo htmlspecialchars($current_settings['max_examens_prof_par_jour'] ?? 3); ?>"
                                       required>
                                <div class="form-help">Nombre maximum d'examens qu'un professeur peut surveiller par jour</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="max_examens_etudiant">
                                    <i class="fas fa-user-graduate"></i> Examens max/jour pour un étudiant
                                </label>
                                <input type="number" 
                                       id="max_examens_etudiant" 
                                       name="max_examens_etudiant" 
                                       min="1" 
                                       max="3"
                                       value="<?php echo htmlspecialchars($current_settings['max_examens_etudiant_par_jour'] ?? 1); ?>"
                                       required>
                                <div class="form-help">Nombre maximum d'examens qu'un étudiant peut passer par jour</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="duree_examen_min">
                                    <i class="fas fa-clock"></i> Durée minimale d'un examen (minutes)
                                </label>
                                <input type="number" 
                                       id="duree_examen_min" 
                                       name="duree_examen_min" 
                                       min="30" 
                                       max="180"
                                       value="<?php echo htmlspecialchars($current_settings['duree_examen_min'] ?? 60); ?>"
                                       required>
                                <div class="form-help">Durée standard d'un examen en minutes</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="plage_horaire_debut">
                                    <i class="far fa-clock"></i> Heure de début des examens
                                </label>
                                <input type="time" 
                                       id="plage_horaire_debut" 
                                       name="plage_horaire_debut" 
                                       value="<?php echo htmlspecialchars($current_settings['plage_horaire_debut'] ?? '08:00:00'); ?>"
                                       required>
                            </div>
                            
                            <div class="form-group">
                                <label for="plage_horaire_fin">
                                    <i class="far fa-clock"></i> Heure de fin des examens
                                </label>
                                <input type="time" 
                                       id="plage_horaire_fin" 
                                       name="plage_horaire_fin" 
                                       value="<?php echo htmlspecialchars($current_settings['plage_horaire_fin'] ?? '20:00:00'); ?>"
                                       required>
                            </div>
                            
                            <div class="form-group">
                                <label for="priorite_departement">
                                    <i class="fas fa-star"></i> Priorité département
                                </label>
                                <select id="priorite_departement" name="priorite_departement">
                                    <option value="1" <?php echo ($current_settings['priorite_departement'] ?? 1) == 1 ? 'selected' : ''; ?>>Élevée</option>
                                    <option value="2" <?php echo ($current_settings['priorite_departement'] ?? 1) == 2 ? 'selected' : ''; ?>>Moyenne</option>
                                    <option value="3" <?php echo ($current_settings['priorite_departement'] ?? 1) == 3 ? 'selected' : ''; ?>>Normale</option>
                                </select>
                                <div class="form-help">Priorité pour l'attribution des salles</div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Enregistrer les paramètres
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="resetToDefaults()">
                                    <i class="fas fa-redo"></i> Valeurs par défaut
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Section Informations Système -->
                <div class="settings-section" id="system-tab">
                    <h2 class="section-title">
                        <i class="fas fa-info-circle"></i> Informations Système
                    </h2>
                    
                    <div class="system-info">
                        <div class="info-card">
                            <div class="info-title">
                                <i class="fas fa-server"></i> Statut du Système
                            </div>
                            <div class="info-content">
                                <p><strong>Base de données:</strong> Connectée</p>
                                <p><strong>Serveur web:</strong> Opérationnel</p>
                                <p><strong>Dernière vérification:</strong> <?php echo date('d/m/Y H:i'); ?></p>
                            </div>
                        </div>
                        
                        <div class="info-card">
                            <div class="info-title">
                                <i class="fas fa-chart-bar"></i> Statistiques Département
                            </div>
                            <div class="info-content">
                                <p><strong>Formations:</strong> <?php echo count($formations); ?></p>
                                <p><strong>Professeurs:</strong> 
                                    <?php 
                                    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM professeurs WHERE dept_id = ?");
                                    $stmt->execute([$dept_id]);
                                    echo $stmt->fetch()['count'];
                                    ?>
                                </p>
                                <p><strong>Étudiants:</strong> 
                                    <?php 
                                    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM etudiants WHERE formation_id IN (SELECT id FROM formations WHERE dept_id = ?)");
                                    $stmt->execute([$dept_id]);
                                    echo $stmt->fetch()['count'];
                                    ?>
                                </p>
                            </div>
                        </div>
                        
                        <div class="info-card">
                            <div class="info-title">
                                <i class="fas fa-calendar-alt"></i> Planning Actuel
                            </div>
                            <div class="info-content">
                                <p><strong>Examens planifiés:</strong> 
                                    <?php 
                                    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM examens e JOIN modules m ON e.module_id = m.id JOIN formations f ON m.formation_id = f.id WHERE f.dept_id = ? AND e.statut = 'planifie'");
                                    $stmt->execute([$dept_id]);
                                    echo $stmt->fetch()['count'];
                                    ?>
                                </p>
                                <p><strong>Examens confirmés:</strong> 
                                    <?php 
                                    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM examens e JOIN modules m ON e.module_id = m.id JOIN formations f ON m.formation_id = f.id WHERE f.dept_id = ? AND e.statut = 'confirme'");
                                    $stmt->execute([$dept_id]);
                                    echo $stmt->fetch()['count'];
                                    ?>
                                </p>
                                <p><strong>Prochain examen:</strong> 
                                    <?php 
                                    $stmt = $pdo->prepare("SELECT MIN(e.date_heure) as prochain FROM examens e JOIN modules m ON e.module_id = m.id JOIN formations f ON m.formation_id = f.id WHERE f.dept_id = ? AND e.date_heure > NOW()");
                                    $stmt->execute([$dept_id]);
                                    $prochain = $stmt->fetch()['prochain'];
                                    echo $prochain ? format_date_fr($prochain, true) : 'Aucun';
                                    ?>
                                </p>
                            </div>
                        </div>
                        
                        <div class="info-card">
                            <div class="info-title">
                                <i class="fas fa-cogs"></i> Configuration
                            </div>
                            <div class="info-content">
                                <p><strong>Version système:</strong> <?php echo SITE_VERSION; ?></p>
                                <p><strong>Dernière mise à jour:</strong> <?php echo date('d/m/Y'); ?></p>
                                <p><strong>Chef de département:</strong> <?php echo htmlspecialchars($user['full_name']); ?></p>
                            </div>
                        </div>
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
        
        // Gestion des tabs
        function showTab(tabId) {
            // Cacher toutes les sections
            document.querySelectorAll('.settings-section').forEach(section => {
                section.classList.remove('active');
            });
            
            // Désactiver tous les boutons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Afficher la section correspondante
            document.getElementById(tabId + '-tab').classList.add('active');
            
            // Activer le bouton correspondant
            document.querySelectorAll('.tab-btn').forEach(btn => {
                if (btn.onclick.toString().includes(tabId)) {
                    btn.classList.add('active');
                }
            });
        }
        
        // Réinitialiser aux valeurs par défaut
        function resetToDefaults() {
            if (confirm('Voulez-vous réinitialiser tous les paramètres aux valeurs par défaut ?')) {
                document.getElementById('max_examens_prof').value = 3;
                document.getElementById('max_examens_etudiant').value = 1;
                document.getElementById('duree_examen_min').value = 60;
                document.getElementById('plage_horaire_debut').value = '08:00';
                document.getElementById('plage_horaire_fin').value = '20:00';
                document.getElementById('priorite_departement').value = 1;
            }
        }
    </script>
</body>
</html>