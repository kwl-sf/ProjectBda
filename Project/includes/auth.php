<?php
// includes/auth.php
require_once 'functions.php';

if (!function_exists('require_login')) {
    /**
     * Vérifie l'authentification et redirige si non connecté
     */
    function require_login() {
        if (!is_logged_in()) {
            redirect('login.php', 'Veuillez vous connecter pour accéder à cette page', 'error');
        }
    }
}

if (!function_exists('require_role')) {
    /**
     * Vérifie si l'utilisateur a un rôle spécifique
     */
    function require_role($allowed_roles) {
        require_login();
        
        $user_role = $_SESSION['user_role'] ?? null;
        
        if (!$user_role || !in_array($user_role, (array)$allowed_roles)) {
            // Journaliser la tentative d'accès non autorisé
            $user_id = $_SESSION['user_id'] ?? 'inconnu';
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'inconnu';
            $requested_page = $_SERVER['REQUEST_URI'] ?? 'inconnu';
            
           
            log_activity($user_id, $user_role, "Tentative d'accès non autorisé à: $requested_page", "IP: $ip, Role requis: " . implode(', ', (array)$allowed_roles));
            
            
            $error_page = 'unauthorized.php';
            
            
            if (!$user_role) {
                $error_page = 'login.php';
            }
            
            redirect($error_page, 'Accès non autorisé. Vous n\'avez pas les permissions nécessaires.', 'error');
        }
    }
}

if (!function_exists('get_logged_in_user_details')) {
    /**
     * Récupère les informations de l'utilisateur connecté - Version corrigée
     */
    function get_logged_in_user_details() {
        if (!is_logged_in()) {
            return null;
        }
        
        global $pdo;
        $role = $_SESSION['user_role'];
        $id = $_SESSION['user_id'];
        
        try {
            if ($role === 'etudiant') {
                $stmt = $pdo->prepare("
                    SELECT 
                        e.*, 
                        f.nom as formation_nom, 
                        d.nom as departement_nom,
                        d.id as departement_id,
                        CONCAT(e.prenom, ' ', e.nom) as full_name
                    FROM etudiants e
                    LEFT JOIN formations f ON e.formation_id = f.id
                    LEFT JOIN departements d ON f.dept_id = d.id
                    WHERE e.id = ?
                ");
            } else {
                $stmt = $pdo->prepare("
                    SELECT 
                        p.*, 
                        d.nom as departement_nom,
                        d.id as departement_id,
                        CONCAT(p.prenom, ' ', p.nom) as full_name
                    FROM professeurs p
                    LEFT JOIN departements d ON p.dept_id = d.id
                    WHERE p.id = ?
                ");
            }
            
            $stmt->execute([$id]);
            $user = $stmt->fetch();
            
            if ($user) {
                $user['role'] = $role;
                $user['role_fr'] = get_role_french($role);
                
               
                $user['permissions'] = get_user_permissions($role);
                
                
                if ($role === 'chef_dept') {
                    
                    $stmt = $pdo->prepare("
                        SELECT d.* 
                        FROM departements d 
                        WHERE d.id = ?
                    ");
                    $stmt->execute([$user['dept_id'] ?? 0]);
                    $user['department_info'] = $stmt->fetch();
                }
                
                
                if (in_array($role, ['admin', 'doyen', 'vice_doyen'])) {
                    $user['is_admin'] = true;
                    $user['admin_level'] = get_admin_level($role);
                }
            }
            
            return $user;
        } catch (Exception $e) {
            error_log("Erreur get_logged_in_user_details: " . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('get_user_permissions')) {
    /**
     * Obtenir les permissions d'un rôle
     */
    function get_user_permissions($role) {
        $permissions = [
            'admin' => [
                'manage_exams', 'manage_rooms', 'generate_schedule', 
                'manage_users', 'view_all_departments', 'validate_all',
                'system_settings', 'view_reports', 'manage_notifications'
            ],
            'doyen' => [
                'view_all_departments', 'validate_final', 'view_reports',
                'view_kpis', 'view_statistics', 'manage_department_heads'
            ],
            'vice_doyen' => [
                'view_all_departments', 'validate_final', 'view_reports',
                'view_kpis', 'view_statistics'
            ],
            'chef_dept' => [
                'view_department', 'validate_department', 'manage_department_staff',
                'view_department_reports', 'assign_professors'
            ],
            'prof' => [
                'view_own_schedule', 'view_own_exams', 'view_students',
                'input_grades', 'request_changes'
            ],
            'etudiant' => [
                'view_own_schedule', 'view_own_exams', 'view_grades'
            ]
        ];
        
        return $permissions[$role] ?? [];
    }
}

if (!function_exists('get_admin_level')) {
    /**
     * Obtenir le niveau d'administration
     */
    function get_admin_level($role) {
        $levels = [
            'admin' => 100,      
            'doyen' => 90,       
            'vice_doyen' => 80,  
            'chef_dept' => 70,   
            'prof' => 50,        
            'etudiant' => 10     
        ];
        
        return $levels[$role] ?? 0;
    }
}

if (!function_exists('user_can')) {
    /**
     * Vérifie si l'utilisateur a une permission spécifique
     */
    function user_can($permission) {
        $user = get_logged_in_user_details();
        
        if (!$user || !isset($user['permissions'])) {
            return false;
        }
        
       
        if ($user['role'] === 'admin') {
            return true;
        }
        
        return in_array($permission, $user['permissions']);
    }
}

if (!function_exists('user_can_access_department')) {
    /**
     * Vérifie si l'utilisateur peut accéder à un département spécifique
     */
    function user_can_access_department($dept_id) {
        $user = get_logged_in_user_details();
        
        if (!$user) {
            return false;
        }
        
        if (in_array($user['role'], ['admin', 'doyen', 'vice_doyen'])) {
            return true;
        }
        
        
        if ($user['role'] === 'chef_dept') {
            return ($user['dept_id'] == $dept_id);
        }
        
       
        if ($user['role'] === 'prof') {
            return ($user['dept_id'] == $dept_id);
        }
        
        
        if ($user['role'] === 'etudiant' && isset($user['formation_id'])) {
            global $pdo;
            $stmt = $pdo->prepare("
                SELECT f.dept_id 
                FROM formations f 
                WHERE f.id = ?
            ");
            $stmt->execute([$user['formation_id']]);
            $formation = $stmt->fetch();
            
            return ($formation && $formation['dept_id'] == $dept_id);
        }
        
        return false;
    }
}

if (!function_exists('logout')) {
    /**
     * Déconnexion améliorée
     */
    function logout() {
        // Journaliser la déconnexion
        if (isset($_SESSION['user_id'])) {
            $user_id = $_SESSION['user_id'];
            $role = $_SESSION['user_role'] ?? 'inconnu';
            $user_name = $_SESSION['user_name'] ?? 'inconnu';
            
            log_activity($user_id, $role, 'Déconnexion', "Utilisateur: $user_name");
            
            
            if ($role !== 'etudiant') {
                try {
                    global $pdo;
                    $stmt = $pdo->prepare("
                        UPDATE professeurs 
                        SET last_logout = NOW() 
                        WHERE id = ?
                    ");
                    $stmt->execute([$user_id]);
                } catch (Exception $e) {
                    error_log("Erreur lors de la mise à jour du dernier logout: " . $e->getMessage());
                }
            }
        }
        
        
        if (isset($_SESSION['user_id']) && isset($_SESSION['user_role'])) {
            send_notification(
                $_SESSION['user_id'],
                $_SESSION['user_role'],
                'system',
                'Déconnexion réussie',
                'Vous vous êtes déconnecté avec succès.',
                'login.php'
            );
        }
        
        
        $_SESSION = [];
        
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        session_destroy();
        
        
        redirect('login.php', 'Déconnexion réussie. À bientôt!', 'success');
    }
}

if (!function_exists('validate_user_session')) {
    /**
     * Vérifie si l'utilisateur est connecté et a un rôle valide
     */
    function validate_user_session() {
        demarrerSession();
        
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
            return false;
        }
        
        
        $valid_roles = ['admin', 'doyen', 'vice_doyen', 'chef_dept', 'prof', 'etudiant'];
        if (!in_array($_SESSION['user_role'], $valid_roles)) {
            return false;
        }
        
        return true;
    }
}

if (!function_exists('start_secure_session')) {
    /**
     * Démarrer une session sécurisée
     */
    function start_secure_session() {
        
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_secure', 1);
        ini_set('session.use_strict_mode', 1);
        
        
        demarrerSession();
        
        
        if (!isset($_SESSION['initiated'])) {
            session_regenerate_id();
            $_SESSION['initiated'] = true;
            $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
        }
        
        
        if (isset($_SESSION['user_agent']) && $_SESSION['user_agent'] !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
            logout();
            redirect('login.php', 'Sécurité: Session compromise détectée', 'error');
        }
    }
}

if (!function_exists('get_user_department')) {
    /**
     * Récupère le département de l'utilisateur (pour chefs de département et professeurs)
     */
    function get_user_department() {
        $user = get_logged_in_user_details();
        
        if (!$user) {
            return null;
        }
        
        if (in_array($user['role'], ['chef_dept', 'prof'])) {
            return [
                'id' => $user['dept_id'] ?? null,
                'nom' => $user['departement_nom'] ?? null
            ];
        }
        
        if ($user['role'] === 'etudiant') {
            return [
                'id' => $user['departement_id'] ?? null,
                'nom' => $user['departement_nom'] ?? null
            ];
        }
        
        return null;
    }
}

if (!function_exists('can_validate_schedule')) {
    /**
     * Vérifie si l'utilisateur peut valider des emplois du temps
     */
    function can_validate_schedule() {
        $user = get_logged_in_user_details();
        
        if (!$user) {
            return false;
        }
        
        $validating_roles = ['admin', 'doyen', 'vice_doyen', 'chef_dept'];
        return in_array($user['role'], $validating_roles);
    }
}

if (!function_exists('can_view_all_departments')) {
    /**
     * Vérifie si l'utilisateur peut voir tous les départements
     */
    function can_view_all_departments() {
        $user = get_logged_in_user_details();
        
        if (!$user) {
            return false;
        }
        
        $view_all_roles = ['admin', 'doyen', 'vice_doyen'];
        return in_array($user['role'], $view_all_roles);
    }
}


if (!function_exists('get_logged_in_user') && function_exists('get_logged_in_user_details')) {
    function get_logged_in_user() {
        return get_logged_in_user_details();
    }
}

if (!function_exists('get_current_user_info') && function_exists('get_logged_in_user_details')) {
    function get_current_user_info() {
        return get_logged_in_user_details();
    }
}