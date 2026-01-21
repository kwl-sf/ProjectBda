<?php
// login.php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Si déjà connecté, rediriger vers le dashboard approprié
if (is_logged_in()) {
    $role = $_SESSION['user_role'];
    $dashboard_path = get_dashboard_path($role);
    redirect($dashboard_path);
}

// Initialisation des variables
$error = '';
$selected_role = $_POST['role'] ?? '';
$email = $_POST['email'] ?? '';
$password = $_POST['password'] ?? '';

// Liste des rôles disponibles
$available_roles = [
    'admin' => ['icon' => 'fas fa-user-shield', 'label' => 'Administrateur'],
    'doyen' => ['icon' => 'fas fa-crown', 'label' => 'Doyen / Vice-Doyen'],
    'chef_dept' => ['icon' => 'fas fa-user-tie', 'label' => 'Chef de Département'],
    'prof' => ['icon' => 'fas fa-chalkboard-teacher', 'label' => 'Professeur'],
    'etudiant' => ['icon' => 'fas fa-user-graduate', 'label' => 'Étudiant']
];

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected_role = trim($_POST['role'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // Validation
    if (empty($selected_role) || !array_key_exists($selected_role, $available_roles)) {
        $error = "Veuillez sélectionner un type d'utilisateur valide";
    } elseif (empty($email) || empty($password)) {
        $error = "Veuillez remplir tous les champs";
    } else {
        // Chercher l'utilisateur selon le rôle sélectionné
        if ($selected_role === 'etudiant') {
            // Chercher dans les étudiants
            $stmt = $pdo->prepare("SELECT * FROM etudiants WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            $role = 'etudiant';
        } else {
            // Chercher dans les professeurs selon le rôle
            $stmt = $pdo->prepare("SELECT * FROM professeurs WHERE email = ? AND role = ?");
            $stmt->execute([$email, $selected_role]);
            $user = $stmt->fetch();
            $role = $selected_role;
        }
        
        // Debug: Afficher les informations de débogage (à désactiver en production)
        // error_log("Tentative de connexion - Email: $email, Rôle: $role, User trouvé: " . ($user ? 'OUI' : 'NON'));
        
        // Vérifier l'utilisateur et le mot de passe
        if ($user) {
            $password_hash = $user['password_hash'];
            
            // Debug: Afficher le hash pour vérification
            // error_log("Hash stocké: $password_hash");
            // error_log("Mot de passe fourni: $password");
            
            // Méthode 1: Vérification avec password_verify (recommandé)
            if (password_verify($password, $password_hash)) {
                login_success($user, $role, $email);
                exit; // Important: sortir après la redirection
            }
            // Méthode 2: Vérification pour les mots de passe non hachés (backup)
            elseif ($password === $password_hash) {
                login_success($user, $role, $email);
                exit;
            }
            // Méthode 3: Vérification pour le mot de passe par défaut "password123"
            elseif ($password === 'password123' && password_verify('password123', $password_hash)) {
                login_success($user, $role, $email);
                exit;
            }
            else {
                $error = "Email ou mot de passe incorrect";
                
                // Debug: Vérifier pourquoi password_verify échoue
                // error_log("Échec password_verify - Hash: " . substr($password_hash, 0, 20) . "...");
                // error_log("password_verify résultat: " . (password_verify($password, $password_hash) ? 'true' : 'false'));
            }
        } else {
            $error = "Aucun utilisateur trouvé avec ces informations";
        }
    }
}

/**
 * Fonction appelée en cas de connexion réussie
 */
function login_success($user, $role, $email) {
    global $pdo;
    
    // Debug
    error_log("Connexion réussie - User ID: " . $user['id'] . ", Rôle: $role, Email: $email");
    
    // Connexion réussie
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_role'] = $role;
    $_SESSION['user_name'] = ($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? '');
    $_SESSION['user_email'] = $email;
    
    // Mettre à jour la dernière connexion
    $table = ($role === 'etudiant') ? 'etudiants' : 'professeurs';
    
    try {
        // Ajouter la colonne last_login si elle n'existe pas
        $stmt = $pdo->prepare("UPDATE $table SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$user['id']]);
    } catch (Exception $e) {
        // Ignorer si la colonne n'existe pas
        error_log("Note: Colonne last_login non trouvée - " . $e->getMessage());
    }
    
    // Journaliser l'activité
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'inconnu';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'inconnu';
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO logs_activite (utilisateur_id, utilisateur_type, action, ip_address, details) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user['id'], 
            $role, 
            'Connexion', 
            $ip,
            "Connexion en tant que " . $role
        ]);
    } catch (Exception $e) {
        // Ignorer les erreurs de journalisation
        error_log("Erreur journalisation: " . $e->getMessage());
    }
    
    // Rediriger vers le dashboard approprié
    $dashboard_path = get_dashboard_path($role);
    redirect($dashboard_path, 'Connexion réussie ! Bienvenue ' . $_SESSION['user_name'], 'success');
}

/**
 * Obtenir le chemin du dashboard selon le rôle
 */
function get_dashboard_path($role) {
    switch ($role) {
        case 'admin':
            return 'admin/dashboard.php';
        case 'doyen':
            return 'doyen/dashboard.php';
        case 'chef_dept':
            return 'chef_dept/dashboard.php';
        case 'prof':
            return 'prof/dashboard.php';
        case 'etudiant':
            return 'etudiant/dashboard.php';
        default:
            return 'login.php';
    }
}

// Fonction de débogage pour vérifier les utilisateurs
function debug_users() {
    global $pdo;
    
    echo "<h3>Debug - Utilisateurs dans la base :</h3>";
    
    // Vérifier les administrateurs
    $stmt = $pdo->query("SELECT id, email, role, password_hash FROM professeurs WHERE role = 'admin'");
    echo "<h4>Administrateurs :</h4>";
    while ($row = $stmt->fetch()) {
        echo "ID: {$row['id']}, Email: {$row['email']}, Role: {$row['role']}<br>";
        echo "Hash: " . substr($row['password_hash'], 0, 30) . "...<br>";
        echo "Test password_verify('password123'): " . (password_verify('password123', $row['password_hash']) ? '✅ VRAI' : '❌ FAUX') . "<br><br>";
    }
}
?>
<!DOCTYPE html>
<html lang="fr" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion | <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <!-- Font Awesome pour les icônes -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Garder le même style que précédemment */
        .login-steps {
            display: flex;
            justify-content: center;
            margin-bottom: 2rem;
            position: relative;
        }
        
        .step-indicator {
            display: flex;
            align-items: center;
            position: relative;
        }
        
        .step {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.2rem;
            z-index: 2;
            transition: all 0.3s ease;
            background: var(--gray-300);
            color: var(--gray-700);
            border: 3px solid white;
        }
        
        .step.active {
            background: var(--primary);
            color: white;
            transform: scale(1.1);
            box-shadow: 0 0 0 4px rgba(67, 97, 238, 0.2);
        }
        
        .step-line {
            width: 80px;
            height: 4px;
            background: var(--gray-300);
            margin: 0 10px;
            transition: all 0.3s ease;
        }
        
        .step-line.active {
            background: var(--primary);
        }
        
        .step-label {
            position: absolute;
            top: 50px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 0.85rem;
            color: var(--gray-600);
            white-space: nowrap;
        }
        
        .step.active .step-label {
            color: var(--primary);
            font-weight: 600;
        }
        
        .login-form-section {
            display: none;
            animation: fadeIn 0.5s ease-out;
        }
        
        .login-form-section.active {
            display: block;
        }
        
        .role-selection {
            text-align: center;
        }
        
        .role-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin: 2rem 0;
        }
        
        .role-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 2rem;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 3px solid transparent;
            text-align: center;
            box-shadow: var(--shadow-md);
        }
        
        .role-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
            border-color: var(--gray-200);
        }
        
        .role-card.selected {
            border-color: var(--primary);
            background: linear-gradient(135deg, rgba(67, 97, 238, 0.05), rgba(67, 97, 238, 0.1));
        }
        
        .role-icon {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 2rem;
            color: white;
            background: var(--gradient-primary);
            box-shadow: var(--shadow-md);
        }
        
        .role-card.admin .role-icon { background: linear-gradient(135deg, #4361ee, #3a0ca3); }
        .role-card.doyen .role-icon { background: linear-gradient(135deg, #f72585, #b5179e); }
        .role-card.chef_dept .role-icon { background: linear-gradient(135deg, #4cc9f0, #4895ef); }
        .role-card.prof .role-icon { background: linear-gradient(135deg, #7209b7, #560bad); }
        .role-card.etudiant .role-icon { background: linear-gradient(135deg, #2ecc71, #27ae60); }
        
        .role-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--gray-900);
        }
        
        .role-desc {
            color: var(--gray-600);
            font-size: 0.9rem;
            line-height: 1.5;
        }
        
        .login-credentials {
            max-width: 500px;
            margin: 0 auto;
        }
        
        .role-display {
            text-align: center;
            margin-bottom: 2rem;
            padding: 1rem;
            background: rgba(67, 97, 238, 0.1);
            border-radius: var(--border-radius);
            border-left: 4px solid var(--primary);
        }
        
        .role-display-icon {
            font-size: 2rem;
            margin-bottom: 1rem;
            display: inline-block;
            padding: 1rem;
            border-radius: 50%;
            background: white;
            box-shadow: var(--shadow-md);
        }
        
        .role-display-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
        }
        
        .role-display-desc {
            color: var(--gray-600);
            font-size: 1rem;
        }
        
        .form-actions {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            margin-top: 2rem;
        }
        
        .btn-back {
            background: var(--gray-200);
            color: var(--gray-700);
        }
        
        .btn-back:hover {
            background: var(--gray-300);
        }
        
        .password-toggle {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--gray-500);
            cursor: pointer;
            font-size: 1.2rem;
            padding: 0.5rem;
            z-index: 2;
        }
        
        .debug-info {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 15px;
            margin-top: 20px;
            font-family: monospace;
            font-size: 12px;
            color: #666;
        }
        
        @media (max-width: 768px) {
            .role-grid {
                grid-template-columns: 1fr;
            }
            
            .step-line {
                width: 40px;
            }
            
            .step-label {
                font-size: 0.75rem;
            }
        }
    </style>
</head>
<body>
    <div class="particles" id="particles"></div>
    
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h1><i class="fas fa-graduation-cap"></i> PlanExam Pro</h1>
                <p>Système Intelligent de Planification des Examens</p>
            </div>
            
            <div class="login-body">
                <!-- Indicateur des étapes -->
                <div class="login-steps">
                    <div class="step-indicator">
                        <div class="step active" id="step1">
                            1
                            
                        </div>
                        <div class="step-line" id="line1"></div>
                        <div class="step" id="step2">
                            2
                            
                        </div>
                    </div>
                </div>
                
                <!-- Messages d'erreur -->
                <?php if ($error): ?>
                    <div class="flash-message flash-error animate__animated animate__fadeIn">
                        <span class="flash-icon">❌</span>
                        <span><?php echo htmlspecialchars($error); ?></span>
                        <button class="flash-close" onclick="this.parentElement.remove()">×</button>
                    </div>
                    
                    <!-- Suggestion pour résoudre le problème -->
                    <div class="debug-info">
                        <strong>Conseil de dépannage :</strong><br>
                        1. Vérifiez que vous avez sélectionné le bon type d'utilisateur<br>
                        2. Assurez-vous que l'email est exact<br>
                        3. Essayez le mot de passe par défaut : <strong>password123</strong><br>
                        4. Contactez l'administrateur si le problème persiste
                    </div>
                <?php endif; ?>
                
                <!-- Étape 1: Sélection du rôle -->
                <div class="login-form-section role-selection active" id="step1-section">
                    <h2 style="text-align: center; margin-bottom: 1.5rem; color: var(--gray-900);">
                        <i class="fas fa-user-tag"></i> Qui êtes-vous ?
                    </h2>
                    <p style="text-align: center; color: var(--gray-600); margin-bottom: 2rem;">
                        Sélectionnez votre type d'utilisateur pour continuer
                    </p>
                    
                    <form method="POST" action="" id="roleForm">
                        <input type="hidden" name="role" id="selectedRole">
                        
                        <div class="role-grid">
                            <?php foreach ($available_roles as $role_key => $role_info): ?>
                                <div class="role-card <?php echo $role_key; ?> 
                                    <?php echo ($selected_role === $role_key) ? 'selected' : ''; ?>"
                                    onclick="selectRole('<?php echo $role_key; ?>', this)">
                                    <div class="role-icon">
                                        <i class="<?php echo $role_info['icon']; ?>"></i>
                                    </div>
                                    <div class="role-title"><?php echo $role_info['label']; ?></div>
                                    <div class="role-desc">
                                        <?php 
                                        switch($role_key) {
                                            case 'admin': echo "Gestion complète du système"; break;
                                            case 'doyen': echo "Vue stratégique et validation"; break;
                                            case 'chef_dept': echo "Gestion du département"; break;
                                            case 'prof': echo "Surveillance et consultation"; break;
                                            case 'etudiant': echo "Consultation du planning"; break;
                                        }
                                        ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div style="text-align: center; margin-top: 2rem;">
                            <button type="button" class="btn btn-primary" onclick="goToStep2()" 
                                    id="continueBtn" disabled>
                                <i class="fas fa-arrow-right"></i> Continuer
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Étape 2: Saisie des identifiants -->
                <div class="login-form-section login-credentials" id="step2-section">
                    <div class="role-display" id="roleDisplay">
                        <!-- Rôle sélectionné sera affiché ici par JavaScript -->
                    </div>
                    
                    <form method="POST" action="" id="loginForm">
                        <input type="hidden" name="role" id="formRole" value="<?php echo htmlspecialchars($selected_role); ?>">
                        
                        <div class="form-group">
                            <label for="email"><i class="fas fa-envelope"></i> Adresse Email</label>
                            <div class="input-with-icon">
                                <div class="icon">
                                    <i class="fas fa-user-circle"></i>
                                </div>
                                <input type="email" 
                                       id="email" 
                                       name="email" 
                                       class="form-control" 
                                       required
                                       value="<?php echo htmlspecialchars($email); ?>"
                                       placeholder="votre.email@universite.dz"
                                       autocomplete="username"
                                       autofocus>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="password"><i class="fas fa-lock"></i> Mot de Passe</label>
                            <div class="input-with-icon" style="position: relative;">
                                <div class="icon">
                                    <i class="fas fa-key"></i>
                                </div>
                                <input type="password" 
                                       id="password" 
                                       name="password" 
                                       class="form-control" 
                                       required
                                       placeholder="Votre mot de passe"
                                       autocomplete="current-password">
                                <button type="button" class="password-toggle" onclick="togglePassword()">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            
                        </div>
                        
                        <div class="form-group">
                            <label style="display: flex; align-items: center; gap: 0.5rem;">
                                <input type="checkbox" name="remember" style="width: auto;" checked>
                                <span>Se souvenir de moi</span>
                            </label>
                        </div>
                        
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary btn-back" onclick="goToStep1()">
                                <i class="fas fa-arrow-left"></i> Retour
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-sign-in-alt"></i> Se Connecter
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Lien de test pour le débogage -->
                <?php if (isset($_GET['debug'])): ?>
                    <div class="debug-info">
                        <?php debug_users(); ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="login-footer">
                <p><i class="fas fa-info-circle"></i> Problème de connexion ? Contactez le service informatique</p>
                <p>Université - © <?php echo date('Y'); ?> - Version <?php echo SITE_VERSION; ?></p>
                <p style="font-size: 0.8rem; margin-top: 5px;">
                    <a href="?debug=1" style="color: #666;">Mode débogage</a> | 
                    <a href="test_password.php" style="color: #666;">Tester mot de passe</a>
                </p>
            </div>
        </div>
    </div>
    
    <script>
        // Variables globales
        let selectedRole = '<?php echo $selected_role; ?>';
        
        // Afficher les informations du rôle sélectionné
        function updateRoleDisplay() {
            const roleDisplay = document.getElementById('roleDisplay');
            const roleInfo = {
                'admin': {
                    icon: 'fas fa-user-shield',
                    title: 'Administrateur',
                    desc: 'Accès complet à la gestion du système'
                },
                'doyen': {
                    icon: 'fas fa-crown',
                    title: 'Doyen / Vice-Doyen',
                    desc: 'Vue stratégique et validation des plannings'
                },
                'chef_dept': {
                    icon: 'fas fa-user-tie',
                    title: 'Chef de Département',
                    desc: 'Gestion des examens de votre département'
                },
                'prof': {
                    icon: 'fas fa-chalkboard-teacher',
                    title: 'Professeur',
                    desc: 'Surveillance et consultation des examens'
                },
                'etudiant': {
                    icon: 'fas fa-user-graduate',
                    title: 'Étudiant',
                    desc: 'Consultation de votre planning personnel'
                }
            };
            
            if (selectedRole && roleInfo[selectedRole]) {
                const info = roleInfo[selectedRole];
                roleDisplay.innerHTML = `
                    <div class="role-display-icon" style="background: ${getRoleColor(selectedRole)};">
                        <i class="${info.icon}"></i>
                    </div>
                    <div class="role-display-title">${info.title}</div>
                    <div class="role-display-desc">${info.desc}</div>
                `;
            }
        }
        
        // Fonction pour obtenir la couleur du rôle
        function getRoleColor(role) {
            const colors = {
                'admin': 'linear-gradient(135deg, #4361ee, #3a0ca3)',
                'doyen': 'linear-gradient(135deg, #f72585, #b5179e)',
                'chef_dept': 'linear-gradient(135deg, #4cc9f0, #4895ef)',
                'prof': 'linear-gradient(135deg, #7209b7, #560bad)',
                'etudiant': 'linear-gradient(135deg, #2ecc71, #27ae60)'
            };
            return colors[role] || 'linear-gradient(135deg, #4361ee, #3a0ca3)';
        }
        
        // Sélectionner un rôle
        function selectRole(role, element) {
            selectedRole = role;
            document.getElementById('selectedRole').value = role;
            document.getElementById('formRole').value = role;
            
            // Retirer la sélection de toutes les cartes
            document.querySelectorAll('.role-card').forEach(card => {
                card.classList.remove('selected');
            });
            
            // Ajouter la sélection à la carte cliquée
            element.classList.add('selected');
            
            // Activer le bouton continuer
            document.getElementById('continueBtn').disabled = false;
            
            // Mettre à jour l'affichage
            updateRoleDisplay();
        }
        
        // Aller à l'étape 2
        function goToStep2() {
            if (!selectedRole) {
                alert('Veuillez sélectionner un type d\'utilisateur');
                return;
            }
            
            // Mettre à jour les étapes
            document.getElementById('step1').classList.remove('active');
            document.getElementById('step2').classList.add('active');
            document.getElementById('line1').classList.add('active');
            
            // Changer les sections
            document.getElementById('step1-section').classList.remove('active');
            document.getElementById('step2-section').classList.add('active');
            
            // Focus sur le champ email
            setTimeout(() => {
                document.getElementById('email').focus();
            }, 300);
        }
        
        // Retour à l'étape 1
        function goToStep1() {
            // Mettre à jour les étapes
            document.getElementById('step2').classList.remove('active');
            document.getElementById('step1').classList.add('active');
            document.getElementById('line1').classList.remove('active');
            
            // Changer les sections
            document.getElementById('step2-section').classList.remove('active');
            document.getElementById('step1-section').classList.add('active');
        }
        
        // Afficher/Masquer le mot de passe
        function togglePassword() {
            const passwordField = document.getElementById('password');
            const toggleIcon = document.querySelector('.password-toggle i');
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                toggleIcon.className = 'fas fa-eye-slash';
            } else {
                passwordField.type = 'password';
                toggleIcon.className = 'fas fa-eye';
            }
        }
        
        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            // Si un rôle est déjà sélectionné (après erreur), aller à l'étape 2
            if (selectedRole) {
                goToStep2();
                
                // Sélectionner la carte correspondante
                const roleCard = document.querySelector(`.role-card.${selectedRole}`);
                if (roleCard) {
                    roleCard.classList.add('selected');
                    document.getElementById('continueBtn').disabled = false;
                }
            }
            
            // Focus automatique sur email si en étape 2
            if (window.location.hash === '#step2') {
                goToStep2();
            }
            
            // Gestionnaire pour la touche Entrée
            document.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    const activeSection = document.querySelector('.login-form-section.active');
                    if (activeSection.id === 'step1-section') {
                        goToStep2();
                    }
                }
            });
            
            // Prévenir la soumission du formulaire de rôle
            document.getElementById('roleForm').addEventListener('submit', function(e) {
                e.preventDefault();
                goToStep2();
            });
        });
        
        // Message d'aide si erreur
        <?php if (!empty($error)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            // Si erreur, rester sur l'étape 2
            if (selectedRole) {
                window.location.hash = '#step2';
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>