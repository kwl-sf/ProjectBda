<?php
// admin/manage_users.php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Vérifier que l'utilisateur est admin
require_role(['admin']);

// Récupérer l'utilisateur connecté
$user = get_logged_in_user();

// Messages
$message = '';
$message_type = '';

// Actions CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Ajouter un utilisateur
        if (isset($_POST['add_user'])) {
            $nom = trim($_POST['nom']);
            $prenom = trim($_POST['prenom']);
            $email = trim($_POST['email']);
            $role = $_POST['role'];
            $departement_id = $_POST['departement_id'] ?? null;
            $formation_id = $_POST['formation_id'] ?? null;
            $promo = $_POST['promo'] ?? null;
            
            // Validation
            if (empty($nom) || empty($prenom) || empty($email) || empty($role)) {
                throw new Exception('Tous les champs obligatoires doivent être remplis');
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Adresse email invalide');
            }
            
            // Vérifier si l'email existe déjà
            $stmt = $pdo->prepare("SELECT id FROM professeurs WHERE email = ? 
                                   UNION 
                                   SELECT id FROM etudiants WHERE email = ?");
            $stmt->execute([$email, $email]);
            if ($stmt->fetch()) {
                throw new Exception('Cette adresse email est déjà utilisée');
            }
            
            // Générer un mot de passe temporaire
            $password_temp = generate_temp_password();
            $password_hash = password_hash($password_temp, PASSWORD_DEFAULT);
            
            if (in_array($role, ['prof', 'chef_dept', 'admin', 'doyen', 'vice-doyen'])) {
                // Ajouter comme professeur
                $sql = "INSERT INTO professeurs (nom, prenom, email, password_hash, role, dept_id) 
                        VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$nom, $prenom, $email, $password_hash, $role, $departement_id]);
                $user_id = $pdo->lastInsertId();
                
                $message = "Professeur ajouté avec succès. Mot de passe temporaire: $password_temp";
                
            } elseif ($role === 'etudiant') {
                // Ajouter comme étudiant
                $sql = "INSERT INTO etudiants (nom, prenom, email, password_hash, formation_id, promo) 
                        VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$nom, $prenom, $email, $password_hash, $formation_id, $promo]);
                $user_id = $pdo->lastInsertId();
                
                $message = "Étudiant ajouté avec succès. Mot de passe temporaire: $password_temp";
            }
            
            // Envoyer un email de bienvenue (simulé ici)
            // send_welcome_email($email, $password_temp, $role);
            
            $message_type = 'success';
            
        } 
        // Modifier un utilisateur
        elseif (isset($_POST['edit_user'])) {
            $user_id = $_POST['user_id'];
            $user_type = $_POST['user_type'];
            $nom = trim($_POST['nom']);
            $prenom = trim($_POST['prenom']);
            $email = trim($_POST['email']);
            $role = $_POST['role'] ?? null;
            $departement_id = $_POST['departement_id'] ?? null;
            $formation_id = $_POST['formation_id'] ?? null;
            $promo = $_POST['promo'] ?? null;
            $active = isset($_POST['active']) ? 1 : 0;
            
            if ($user_type === 'prof') {
                $sql = "UPDATE professeurs 
                        SET nom = ?, prenom = ?, email = ?, role = ?, dept_id = ?, actif = ? 
                        WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$nom, $prenom, $email, $role, $departement_id, $active, $user_id]);
            } else {
                $sql = "UPDATE etudiants 
                        SET nom = ?, prenom = ?, email = ?, formation_id = ?, promo = ?, actif = ? 
                        WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$nom, $prenom, $email, $formation_id, $promo, $active, $user_id]);
            }
            
            $message = 'Utilisateur modifié avec succès';
            $message_type = 'success';
            
        }
        // Supprimer un utilisateur
        elseif (isset($_POST['delete_user'])) {
            $user_id = $_POST['user_id'];
            $user_type = $_POST['user_type'];
            
            // Ne pas permettre la suppression de soi-même
            if (($user_type === 'prof' && $user_id == $user['id']) || 
                ($user_type === 'etudiant' && $user_id == $user['id'])) {
                throw new Exception('Vous ne pouvez pas supprimer votre propre compte');
            }
            
            if ($user_type === 'prof') {
                // Vérifier si le professeur a des examens assignés
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM examens WHERE prof_id = ?");
                $stmt->execute([$user_id]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception('Ce professeur a des examens assignés. Réassignez-les d\'abord.');
                }
                
                $stmt = $pdo->prepare("DELETE FROM professeurs WHERE id = ?");
                $stmt->execute([$user_id]);
            } else {
                // Vérifier si l'étudiant a des inscriptions
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM inscriptions WHERE etudiant_id = ?");
                $stmt->execute([$user_id]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception('Cet étudiant a des inscriptions. Supprimez-les d\'abord.');
                }
                
                $stmt = $pdo->prepare("DELETE FROM etudiants WHERE id = ?");
                $stmt->execute([$user_id]);
            }
            
            $message = 'Utilisateur supprimé avec succès';
            $message_type = 'success';
            
        }
        // Réinitialiser le mot de passe
        elseif (isset($_POST['reset_password'])) {
            $user_id = $_POST['user_id'];
            $user_type = $_POST['user_type'];
            
            $new_password = generate_temp_password();
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            
            if ($user_type === 'prof') {
                $stmt = $pdo->prepare("UPDATE professeurs SET password_hash = ? WHERE id = ?");
            } else {
                $stmt = $pdo->prepare("UPDATE etudiants SET password_hash = ? WHERE id = ?");
            }
            
            $stmt->execute([$password_hash, $user_id]);
            
            $message = "Mot de passe réinitialisé. Nouveau mot de passe: $new_password";
            $message_type = 'success';
            
        }
        // Importer des utilisateurs
        elseif (isset($_POST['import_users'])) {
            if (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
                $file_path = $_FILES['import_file']['tmp_name'];
                $file_type = pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION);
                
                $imported = 0;
                $errors = [];
                
                if ($file_type === 'csv') {
                    $handle = fopen($file_path, 'r');
                    $header = fgetcsv($handle); // Lire l'en-tête
                    
                    while (($data = fgetcsv($handle)) !== false) {
                        $type = $data[0];
                        $nom = $data[1];
                        $prenom = $data[2];
                        $email = $data[3];
                        $role = $data[4] ?? 'prof';
                        $departement = $data[5] ?? null;
                        $formation = $data[6] ?? null;
                        $promo = $data[7] ?? null;
                        
                        try {
                            // Vérifier l'email
                            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                $errors[] = "Email invalide: $email";
                                continue;
                            }
                            
                            // Vérifier si existe
                            $stmt = $pdo->prepare("SELECT id FROM professeurs WHERE email = ? 
                                                   UNION 
                                                   SELECT id FROM etudiants WHERE email = ?");
                            $stmt->execute([$email, $email]);
                            if ($stmt->fetch()) {
                                $errors[] = "Email déjà existant: $email";
                                continue;
                            }
                            
                            $password_temp = generate_temp_password();
                            $password_hash = password_hash($password_temp, PASSWORD_DEFAULT);
                            
                            if ($type === 'prof') {
                                // Trouver l'ID du département
                                $dept_id = null;
                                if ($departement) {
                                    $stmt = $pdo->prepare("SELECT id FROM departements WHERE nom LIKE ?");
                                    $stmt->execute(["%$departement%"]);
                                    $dept = $stmt->fetch();
                                    $dept_id = $dept['id'] ?? null;
                                }
                                
                                $sql = "INSERT INTO professeurs (nom, prenom, email, password_hash, role, dept_id) 
                                        VALUES (?, ?, ?, ?, ?, ?)";
                                $stmt = $pdo->prepare($sql);
                                $stmt->execute([$nom, $prenom, $email, $password_hash, $role, $dept_id]);
                                
                            } elseif ($type === 'etudiant') {
                                // Trouver l'ID de la formation
                                $formation_id = null;
                                if ($formation) {
                                    $stmt = $pdo->prepare("SELECT id FROM formations WHERE nom LIKE ?");
                                    $stmt->execute(["%$formation%"]);
                                    $form = $stmt->fetch();
                                    $formation_id = $form['id'] ?? null;
                                }
                                
                                $sql = "INSERT INTO etudiants (nom, prenom, email, password_hash, formation_id, promo) 
                                        VALUES (?, ?, ?, ?, ?, ?)";
                                $stmt = $pdo->prepare($sql);
                                $stmt->execute([$nom, $prenom, $email, $password_hash, $formation_id, $promo]);
                            }
                            
                            $imported++;
                            
                        } catch (Exception $e) {
                            $errors[] = "Erreur pour $email: " . $e->getMessage();
                        }
                    }
                    
                    fclose($handle);
                    
                    $message = "$imported utilisateurs importés avec succès";
                    if (!empty($errors)) {
                        $message .= ". Erreurs: " . count($errors);
                        $_SESSION['import_errors'] = $errors;
                    }
                    $message_type = 'success';
                    
                } else {
                    throw new Exception('Format de fichier non supporté. Utilisez CSV.');
                }
            } else {
                throw new Exception('Veuillez sélectionner un fichier');
            }
        }
        
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = 'error';
    }
}

// Récupérer les données pour les formulaires
try {
    // Récupérer tous les professeurs
    $stmt = $pdo->query("
        SELECT p.*, d.nom as departement_nom,
               CASE p.role 
                   WHEN 'prof' THEN 'Professeur'
                   WHEN 'chef_dept' THEN 'Chef de Département'
                   WHEN 'admin' THEN 'Administrateur'
                   WHEN 'doyen' THEN 'Doyen'
                   WHEN 'vice-doyen' THEN 'Vice-Doyen'
                   ELSE p.role
               END as role_label
        FROM professeurs p
        LEFT JOIN departements d ON p.dept_id = d.id
        ORDER BY p.role, p.nom
    ");
    $professeurs = $stmt->fetchAll();
    
    // Récupérer tous les étudiants
    $stmt = $pdo->query("
        SELECT e.*, f.nom as formation_nom, d.nom as departement_nom
        FROM etudiants e
        JOIN formations f ON e.formation_id = f.id
        JOIN departements d ON f.dept_id = d.id
        ORDER BY e.promo DESC, e.nom
    ");
    $etudiants = $stmt->fetchAll();
    
    // Récupérer les départements pour les formulaires
    $stmt = $pdo->query("SELECT id, nom FROM departements ORDER BY nom");
    $departements = $stmt->fetchAll();
    
    // Récupérer les formations pour les formulaires
    $stmt = $pdo->query("SELECT id, nom, dept_id FROM formations ORDER BY nom");
    $formations = $stmt->fetchAll();
    
    // Statistiques des utilisateurs
    $stats = [
        'total_professeurs' => count($professeurs),
        'total_etudiants' => count($etudiants),
        'active_professeurs' => 0,
        'active_etudiants' => 0,
        'par_role' => [],
        'par_departement' => []
    ];
    
    foreach ($professeurs as $prof) {
        if ($prof['actif'] ?? 1) $stats['active_professeurs']++;
        $role = $prof['role'] ?? 'prof';
        if (!isset($stats['par_role'][$role])) $stats['par_role'][$role] = 0;
        $stats['par_role'][$role]++;
    }
    
    foreach ($etudiants as $etud) {
        if ($etud['actif'] ?? 1) $stats['active_etudiants']++;
        $dep = $etud['departement_nom'];
        if (!isset($stats['par_departement'][$dep])) $stats['par_departement'][$dep] = 0;
        $stats['par_departement'][$dep]++;
    }
    
} catch (PDOException $e) {
    error_log("Erreur gestion utilisateurs: " . $e->getMessage());
    $professeurs = [];
    $etudiants = [];
    $departements = [];
    $formations = [];
    $stats = [];
}

// Titre de la page
$page_title = "Gestion des Utilisateurs";

// Fonction utilitaire
function generate_temp_password($length = 8) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[rand(0, strlen($chars) - 1)];
    }
    return $password;
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
        .users-management {
            padding: 20px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--gray-900);
        }
        
        .stat-label {
            color: var(--gray-600);
            font-size: 0.9rem;
        }
        
        .tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            border-bottom: 2px solid var(--gray-200);
        }
        
        .tab {
            padding: 1rem 1.5rem;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 600;
            color: var(--gray-600);
            position: relative;
            transition: var(--transition);
        }
        
        .tab.active {
            color: var(--primary);
        }
        
        .tab.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--primary);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        
        .actions-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            gap: 1rem;
        }
        
        .search-box {
            flex: 1;
            max-width: 400px;
            position: relative;
        }
        
        .search-box input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 3rem;
            border: 1px solid var(--gray-300);
            border-radius: var(--border-radius);
            font-size: 1rem;
        }
        
        .search-box i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-500);
        }
        
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: var(--border-radius);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            border: none;
            font-size: 0.9rem;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
        }
        
        .btn-success {
            background: var(--success);
            color: white;
        }
        
        .btn-success:hover {
            background: var(--success-dark);
        }
        
        .btn-warning {
            background: var(--warning);
            color: white;
        }
        
        .btn-warning:hover {
            background: var(--warning-dark);
        }
        
        .btn-danger {
            background: var(--danger);
            color: white;
        }
        
        .btn-danger:hover {
            background: var(--danger-dark);
        }
        
        .table-container {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            overflow-x: auto;
        }
        
        .users-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .users-table th {
            background: var(--gray-100);
            color: var(--gray-700);
            font-weight: 600;
            padding: 1rem;
            text-align: left;
            border-bottom: 2px solid var(--gray-300);
        }
        
        .users-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .users-table tr:hover {
            background: var(--gray-50);
        }
        
        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge.role-prof {
            background: rgba(52, 152, 219, 0.1);
            color: var(--info);
        }
        
        .badge.role-chef_dept {
            background: rgba(155, 89, 182, 0.1);
            color: #9b59b6;
        }
        
        .badge.role-admin {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
        }
        
        .badge.role-etudiant {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success);
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .status-badge.active::before {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--success);
        }
        
        .status-badge.inactive::before {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--danger);
        }
        
        .action-buttons-cell {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn-icon {
            width: 36px;
            height: 36px;
            border-radius: var(--border-radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
            border: 1px solid var(--gray-300);
            background: white;
            color: var(--gray-700);
        }
        
        .btn-icon:hover {
            background: var(--gray-100);
            transform: translateY(-2px);
        }
        
        .btn-icon.edit:hover {
            border-color: var(--info);
            color: var(--info);
        }
        
        .btn-icon.delete:hover {
            border-color: var(--danger);
            color: var(--danger);
        }
        
        .btn-icon.reset:hover {
            border-color: var(--warning);
            color: var(--warning);
        }
        
        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
            animation: fadeIn 0.3s ease;
        }
        
        .modal-content {
            background: white;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
        }
        
        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            margin: 0;
            color: var(--gray-800);
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray-600);
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--gray-700);
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--gray-300);
            border-radius: var(--border-radius-sm);
            font-size: 1rem;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--gray-200);
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .checkbox-group input {
            width: auto;
        }
        
        .message {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .message.success {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success);
            border-left: 4px solid var(--success);
        }
        
        .message.error {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }
        
        .import-section {
            background: var(--gray-50);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .import-section h4 {
            margin-top: 0;
            margin-bottom: 1rem;
            color: var(--gray-800);
        }
        
        .download-template {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            margin-top: 1rem;
        }
        
        @media (max-width: 768px) {
            .actions-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-box {
                max-width: none;
            }
            
            .action-buttons {
                justify-content: center;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .users-table {
                font-size: 0.9rem;
            }
            
            .action-buttons-cell {
                flex-direction: column;
            }
            
            .btn-icon {
                width: 100%;
                margin-bottom: 0.25rem;
            }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
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
                <a href="Statistique.php" class="nav-item">
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
                <a href="manage_users.php" class="nav-item active">
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
                    <h1>Gestion des Utilisateurs</h1>
                    <p>Administration complète des comptes utilisateurs</p>
                </div>
                <div class="header-actions">
                    <button class="menu-toggle" id="menuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                </div>
            </header>
            
            <!-- Message de notification -->
            <?php if ($message): ?>
                <div class="message <?php echo $message_type; ?> animate__animated animate__fadeIn">
                    <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <!-- Statistiques -->
            <div class="stats-grid animate__animated animate__fadeIn">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['total_professeurs'] ?? 0; ?></div>
                    <div class="stat-label">Professeurs</div>
                    <small><?php echo $stats['active_professeurs'] ?? 0; ?> actifs</small>
                </div>
                
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['total_etudiants'] ?? 0; ?></div>
                    <div class="stat-label">Étudiants</div>
                    <small><?php echo $stats['active_etudiants'] ?? 0; ?> actifs</small>
                </div>
                
                <div class="stat-card">
                    <div class="stat-value"><?php echo ($stats['total_professeurs'] ?? 0) + ($stats['total_etudiants'] ?? 0); ?></div>
                    <div class="stat-label">Total Utilisateurs</div>
                    <small>Dans le système</small>
                </div>
                
                <div class="stat-card">
                    <div class="stat-value"><?php echo count($stats['par_role'] ?? []); ?></div>
                    <div class="stat-label">Rôles</div>
                    <small>Différents</small>
                </div>
            </div>
            
            <!-- Section Import -->
            <div class="import-section animate__animated animate__fadeIn">
                <h4><i class="fas fa-file-import"></i> Importation en Masse</h4>
                <form method="post" enctype="multipart/form-data">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="import_file">Fichier CSV</label>
                            <input type="file" name="import_file" id="import_file" accept=".csv" required>
                            <small>Format: type,nom,prenom,email,role,departement,formation,promo</small>
                        </div>
                    </div>
                    <button type="submit" name="import_users" class="btn btn-success">
                        <i class="fas fa-upload"></i> Importer les Utilisateurs
                    </button>
                    <a href="export_template.php" class="download-template">
                        <i class="fas fa-download"></i> Télécharger le modèle CSV
                    </a>
                </form>
                
                <?php if (isset($_SESSION['import_errors']) && !empty($_SESSION['import_errors'])): ?>
                    <div class="message error" style="margin-top: 1rem;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div>
                            <strong>Erreurs d'importation:</strong>
                            <ul style="margin: 0.5rem 0 0 1.5rem; font-size: 0.9rem;">
                                <?php foreach ($_SESSION['import_errors'] as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <?php unset($_SESSION['import_errors']); ?>
                <?php endif; ?>
            </div>
            
            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="showTab('professeurs')">
                    <i class="fas fa-chalkboard-teacher"></i> Professeurs
                </button>
                <button class="tab" onclick="showTab('etudiants')">
                    <i class="fas fa-user-graduate"></i> Étudiants
                </button>
                <button class="tab" onclick="showTab('ajouter')">
                    <i class="fas fa-user-plus"></i> Ajouter un Utilisateur
                </button>
            </div>
            
            <!-- Tab Content: Professeurs -->
            <div id="professeurs" class="tab-content active">
                <div class="actions-bar">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchProfs" placeholder="Rechercher un professeur...">
                    </div>
                    <div class="action-buttons">
                        <button class="btn btn-primary" onclick="showAddModal('prof')">
                            <i class="fas fa-plus"></i> Ajouter un Professeur
                        </button>
                        <button class="btn btn-success" onclick="exportUsers('professeurs')">
                            <i class="fas fa-file-export"></i> Exporter
                        </button>
                    </div>
                </div>
                
                <div class="table-container">
                    <table class="users-table" id="professeursTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nom & Prénom</th>
                                <th>Email</th>
                                <th>Rôle</th>
                                <th>Département</th>
                                <th>Statut</th>
                                <th>Date Création</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($professeurs as $prof): ?>
                                <tr>
                                    <td>#<?php echo $prof['id']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($prof['prenom'] . ' ' . $prof['nom']); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($prof['email']); ?></td>
                                    <td>
                                        <span class="badge role-<?php echo $prof['role']; ?>">
                                            <?php echo htmlspecialchars($prof['role_label']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($prof['departement_nom'] ?? 'Non assigné'); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo ($prof['actif'] ?? 1) ? 'active' : 'inactive'; ?>">
                                            <?php echo ($prof['actif'] ?? 1) ? 'Actif' : 'Inactif'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo format_date_fr($prof['created_at'], false); ?></td>
                                    <td>
                                        <div class="action-buttons-cell">
                                            <button class="btn-icon edit" title="Modifier" 
                                                    onclick="editUser(<?php echo $prof['id']; ?>, 'prof')">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn-icon reset" title="Réinitialiser mot de passe"
                                                    onclick="resetPassword(<?php echo $prof['id']; ?>, 'prof')">
                                                <i class="fas fa-key"></i>
                                            </button>
                                            <button class="btn-icon delete" title="Supprimer"
                                                    onclick="deleteUser(<?php echo $prof['id']; ?>, 'prof', '<?php echo htmlspecialchars(addslashes($prof['prenom'] . ' ' . $prof['nom'])); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Tab Content: Étudiants -->
            <div id="etudiants" class="tab-content">
                <div class="actions-bar">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchEtudiants" placeholder="Rechercher un étudiant...">
                    </div>
                    <div class="action-buttons">
                        <button class="btn btn-primary" onclick="showAddModal('etudiant')">
                            <i class="fas fa-plus"></i> Ajouter un Étudiant
                        </button>
                        <button class="btn btn-success" onclick="exportUsers('etudiants')">
                            <i class="fas fa-file-export"></i> Exporter
                        </button>
                    </div>
                </div>
                
                <div class="table-container">
                    <table class="users-table" id="etudiantsTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nom & Prénom</th>
                                <th>Email</th>
                                <th>Formation</th>
                                <th>Promo</th>
                                <th>Statut</th>
                                <th>Date Création</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($etudiants as $etud): ?>
                                <tr>
                                    <td>#<?php echo $etud['id']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($etud['prenom'] . ' ' . $etud['nom']); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($etud['email']); ?></td>
                                    <td>
                                        <div><?php echo htmlspecialchars($etud['formation_nom']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($etud['departement_nom']); ?></small>
                                    </td>
                                    <td><?php echo $etud['promo']; ?></td>
                                    <td>
                                        <span class="status-badge <?php echo ($etud['actif'] ?? 1) ? 'active' : 'inactive'; ?>">
                                            <?php echo ($etud['actif'] ?? 1) ? 'Actif' : 'Inactif'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo format_date_fr($etud['created_at'], false); ?></td>
                                    <td>
                                        <div class="action-buttons-cell">
                                            <button class="btn-icon edit" title="Modifier"
                                                    onclick="editUser(<?php echo $etud['id']; ?>, 'etudiant')">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn-icon reset" title="Réinitialiser mot de passe"
                                                    onclick="resetPassword(<?php echo $etud['id']; ?>, 'etudiant')">
                                                <i class="fas fa-key"></i>
                                            </button>
                                            <button class="btn-icon delete" title="Supprimer"
                                                    onclick="deleteUser(<?php echo $etud['id']; ?>, 'etudiant', '<?php echo htmlspecialchars(addslashes($etud['prenom'] . ' ' . $etud['nom'])); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Tab Content: Ajouter -->
            <div id="ajouter" class="tab-content">
                <div class="form-container" style="max-width: 800px; margin: 0 auto;">
                    <h3 style="margin-bottom: 2rem; color: var(--gray-800);">
                        <i class="fas fa-user-plus"></i> Ajouter un Nouvel Utilisateur
                    </h3>
                    
                    <form method="post" id="addUserForm">
                        <div class="form-group">
                            <label for="user_type">Type d'utilisateur *</label>
                            <select name="user_type" id="user_type" onchange="toggleUserFields()" required>
                                <option value="">Sélectionnez un type</option>
                                <option value="prof">Professeur</option>
                                <option value="etudiant">Étudiant</option>
                            </select>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="nom">Nom *</label>
                                <input type="text" name="nom" id="nom" required>
                            </div>
                            <div class="form-group">
                                <label for="prenom">Prénom *</label>
                                <input type="text" name="prenom" id="prenom" required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="email">Email *</label>
                            <input type="email" name="email" id="email" required>
                        </div>
                        
                        <!-- Champs pour Professeur -->
                        <div id="prof_fields" style="display: none;">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="role">Rôle *</label>
                                    <select name="role" id="role">
                                        <option value="prof">Professeur</option>
                                        <option value="chef_dept">Chef de Département</option>
                                        <option value="admin">Administrateur</option>
                                        <option value="doyen">Doyen</option>
                                        <option value="vice-doyen">Vice-Doyen</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="departement_id">Département</label>
                                    <select name="departement_id" id="departement_id">
                                        <option value="">Sélectionnez un département</option>
                                        <?php foreach ($departements as $dept): ?>
                                            <option value="<?php echo $dept['id']; ?>">
                                                <?php echo htmlspecialchars($dept['nom']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Champs pour Étudiant -->
                        <div id="etudiant_fields" style="display: none;">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="formation_id">Formation *</label>
                                    <select name="formation_id" id="formation_id">
                                        <option value="">Sélectionnez une formation</option>
                                        <?php foreach ($formations as $form): ?>
                                            <option value="<?php echo $form['id']; ?>">
                                                <?php echo htmlspecialchars($form['nom']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="promo">Promotion *</label>
                                    <select name="promo" id="promo">
                                        <?php for ($i = date('Y'); $i <= date('Y') + 5; $i++): ?>
                                            <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="button" class="btn" onclick="showTab('professeurs')">
                                <i class="fas fa-times"></i> Annuler
                            </button>
                            <button type="submit" name="add_user" class="btn btn-primary">
                                <i class="fas fa-save"></i> Créer l'Utilisateur
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Modal pour modifier un utilisateur -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Modifier l'Utilisateur</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="post" id="editForm">
                    <input type="hidden" name="user_id" id="edit_user_id">
                    <input type="hidden" name="user_type" id="edit_user_type">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_nom">Nom *</label>
                            <input type="text" name="nom" id="edit_nom" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_prenom">Prénom *</label>
                            <input type="text" name="prenom" id="edit_prenom" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_email">Email *</label>
                        <input type="email" name="email" id="edit_email" required>
                    </div>
                    
                    <div id="edit_prof_fields">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="edit_role">Rôle</label>
                                <select name="role" id="edit_role">
                                    <option value="prof">Professeur</option>
                                    <option value="chef_dept">Chef de Département</option>
                                    <option value="admin">Administrateur</option>
                                    <option value="doyen">Doyen</option>
                                    <option value="vice-doyen">Vice-Doyen</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="edit_departement_id">Département</label>
                                <select name="departement_id" id="edit_departement_id">
                                    <option value="">Non assigné</option>
                                    <?php foreach ($departements as $dept): ?>
                                        <option value="<?php echo $dept['id']; ?>">
                                            <?php echo htmlspecialchars($dept['nom']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div id="edit_etudiant_fields">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="edit_formation_id">Formation</label>
                                <select name="formation_id" id="edit_formation_id">
                                    <?php foreach ($formations as $form): ?>
                                        <option value="<?php echo $form['id']; ?>">
                                            <?php echo htmlspecialchars($form['nom']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="edit_promo">Promotion</label>
                                <select name="promo" id="edit_promo">
                                    <?php for ($i = date('Y') - 5; $i <= date('Y') + 5; $i++): ?>
                                        <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" name="active" id="edit_active" value="1" checked>
                            <label for="edit_active">Compte actif</label>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn" onclick="closeModal()">
                            <i class="fas fa-times"></i> Annuler
                        </button>
                        <button type="submit" name="edit_user" class="btn btn-primary">
                            <i class="fas fa-save"></i> Enregistrer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal de confirmation de suppression -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Confirmer la suppression</h3>
                <button class="modal-close" onclick="closeDeleteModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p id="deleteMessage">Êtes-vous sûr de vouloir supprimer cet utilisateur ?</p>
                <form method="post" id="deleteForm">
                    <input type="hidden" name="user_id" id="delete_user_id">
                    <input type="hidden" name="user_type" id="delete_user_type">
                    <div class="form-actions">
                        <button type="button" class="btn" onclick="closeDeleteModal()">
                            <i class="fas fa-times"></i> Annuler
                        </button>
                        <button type="submit" name="delete_user" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Supprimer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal de réinitialisation de mot de passe -->
    <div class="modal" id="resetModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Réinitialiser le mot de passe</h3>
                <button class="modal-close" onclick="closeResetModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p id="resetMessage">Êtes-vous sûr de vouloir réinitialiser le mot de passe de cet utilisateur ?</p>
                <p><small>Un nouveau mot de passe temporaire sera généré et affiché.</small></p>
                <form method="post" id="resetForm">
                    <input type="hidden" name="user_id" id="reset_user_id">
                    <input type="hidden" name="user_type" id="reset_user_type">
                    <div class="form-actions">
                        <button type="button" class="btn" onclick="closeResetModal()">
                            <i class="fas fa-times"></i> Annuler
                        </button>
                        <button type="submit" name="reset_password" class="btn btn-warning">
                            <i class="fas fa-key"></i> Réinitialiser
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Menu Toggle
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
        });
        
        // Gestion des tabs
        function showTab(tabName) {
            // Masquer tous les tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Afficher le tab sélectionné
            document.getElementById(tabName).classList.add('active');
            event.target.classList.add('active');
        }
        
        // Toggle les champs selon le type d'utilisateur
        function toggleUserFields() {
            const userType = document.getElementById('user_type').value;
            document.getElementById('prof_fields').style.display = userType === 'prof' ? 'block' : 'none';
            document.getElementById('etudiant_fields').style.display = userType === 'etudiant' ? 'block' : 'none';
            
            // Rendre les champs obligatoires selon le type
            const profRequired = userType === 'prof';
            const etudiantRequired = userType === 'etudiant';
            
            document.getElementById('role').required = profRequired;
            document.getElementById('formation_id').required = etudiantRequired;
            document.getElementById('promo').required = etudiantRequired;
        }
        
        // Recherche dans les tables
        document.getElementById('searchProfs')?.addEventListener('keyup', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('#professeursTable tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });
        
        document.getElementById('searchEtudiants')?.addEventListener('keyup', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('#etudiantsTable tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });
        
        // Fonctions pour les modals
        function showAddModal(type) {
            document.getElementById('user_type').value = type;
            toggleUserFields();
            showTab('ajouter');
        }
        
        function editUser(userId, userType) {
            // Récupérer les données de l'utilisateur (simulation)
            // En réalité, vous devriez faire une requête AJAX
            fetch(`get_user_data.php?id=${userId}&type=${userType}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('edit_user_id').value = userId;
                    document.getElementById('edit_user_type').value = userType;
                    document.getElementById('edit_nom').value = data.nom || '';
                    document.getElementById('edit_prenom').value = data.prenom || '';
                    document.getElementById('edit_email').value = data.email || '';
                    
                    if (userType === 'prof') {
                        document.getElementById('edit_prof_fields').style.display = 'block';
                        document.getElementById('edit_etudiant_fields').style.display = 'none';
                        document.getElementById('edit_role').value = data.role || 'prof';
                        document.getElementById('edit_departement_id').value = data.dept_id || '';
                    } else {
                        document.getElementById('edit_prof_fields').style.display = 'none';
                        document.getElementById('edit_etudiant_fields').style.display = 'block';
                        document.getElementById('edit_formation_id').value = data.formation_id || '';
                        document.getElementById('edit_promo').value = data.promo || '';
                    }
                    
                    document.getElementById('edit_active').checked = data.actif !== 0;
                    document.getElementById('modalTitle').textContent = `Modifier ${data.prenom} ${data.nom}`;
                    document.getElementById('editModal').classList.add('active');
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    alert('Erreur lors du chargement des données');
                });
        }
        
        function resetPassword(userId, userType) {
            document.getElementById('reset_user_id').value = userId;
            document.getElementById('reset_user_type').value = userType;
            document.getElementById('resetModal').classList.add('active');
        }
        
        function deleteUser(userId, userType, userName) {
            document.getElementById('delete_user_id').value = userId;
            document.getElementById('delete_user_type').value = userType;
            document.getElementById('deleteMessage').textContent = 
                `Êtes-vous sûr de vouloir supprimer l'utilisateur "${userName}" ? Cette action est irréversible.`;
            document.getElementById('deleteModal').classList.add('active');
        }
        
        function closeModal() {
            document.getElementById('editModal').classList.remove('active');
        }
        
        function closeResetModal() {
            document.getElementById('resetModal').classList.remove('active');
        }
        
        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('active');
        }
        
        function exportUsers(type) {
            window.location.href = `export_users.php?type=${type}`;
        }
        
        // Fermer les modals en cliquant à l'extérieur
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                closeModal();
                closeResetModal();
                closeDeleteModal();
            }
        }
        
        // Initialiser
        document.addEventListener('DOMContentLoaded', function() {
            // S'il y a des erreurs d'import, afficher la section import
            <?php if (isset($_SESSION['import_errors']) && !empty($_SESSION['import_errors'])): ?>
                showTab('professeurs');
            <?php endif; ?>
        });
    </script>
</body>
</html>