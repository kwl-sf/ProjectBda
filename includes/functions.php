<?php
// includes/functions.php


function demarrerSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Vérifie si l'utilisateur est connecté
 */
function is_logged_in() {
    return isset($_SESSION['user_id']) && isset($_SESSION['user_role']);
}

/**
 * Redirige vers une page avec message
 */
function redirect($url, $message = null, $type = 'success') {
    if ($message) {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = $type;
    }
    header("Location: " . BASE_URL . "/" . $url);
    exit();
}

/**
 * Affiche les messages flash
 */
function display_flash_message() {
    if (isset($_SESSION['flash_message'])) {
        $type = $_SESSION['flash_type'] ?? 'info';
        $message = $_SESSION['flash_message'];
        
        $icons = [
            'success' => '✅',
            'error' => '❌',
            'warning' => '⚠️',
            'info' => 'ℹ️'
        ];
        
        $icon = $icons[$type] ?? 'ℹ️';
        
        echo "<div class='flash-message flash-$type animate__animated animate__fadeIn'>
                <span class='flash-icon'>$icon</span>
                <span>$message</span>
                <button class='flash-close' onclick='this.parentElement.remove()'>×</button>
              </div>";
        
        unset($_SESSION['flash_message']);
        unset($_SESSION['flash_type']);
    }
}

/**
 * Obtient le rôle de l'utilisateur en français
 */
function get_role_french($role) {
    $roles = [
        'admin' => 'Administrateur Examens',
        'doyen' => 'Doyen',
        'vice_doyen' => 'Vice-Doyen',
        'chef_dept' => 'Chef de Département',
        'prof' => 'Professeur',
        'etudiant' => 'Étudiant'
    ];
    
    return $roles[$role] ?? $role;
}

/**
 * Formate la date en français
 */
function format_date_fr($date, $show_time = false) {
    // التحقق من أن التاريخ ليس فارغاً
    if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return 'Date non définie';
    }
    
    try {
        $timestamp = strtotime($date);
        
        if ($timestamp === false) {
            return 'Date invalide';
        }
        
        $mois = [
            'January' => 'janvier', 'February' => 'février', 'March' => 'mars',
            'April' => 'avril', 'May' => 'mai', 'June' => 'juin',
            'July' => 'juillet', 'August' => 'août', 'September' => 'septembre',
            'October' => 'octobre', 'November' => 'novembre', 'December' => 'décembre'
        ];
        
        $jours = [
            'Monday' => 'Lundi', 'Tuesday' => 'Mardi', 'Wednesday' => 'Mercredi',
            'Thursday' => 'Jeudi', 'Friday' => 'Vendredi', 'Saturday' => 'Samedi',
            'Sunday' => 'Dimanche'
        ];
        
        $jour_semaine = $jours[date('l', $timestamp)] ?? '';
        $jour = date('d', $timestamp);
        $mois_nom = $mois[date('F', $timestamp)] ?? '';
        $annee = date('Y', $timestamp);
        
        $formatted = "$jour_semaine $jour $mois_nom $annee";
        
        if ($show_time) {
            $heure = date('H:i', $timestamp);
            $formatted .= " à $heure";
        }
        
        return $formatted;
    } catch (Exception $e) {
        error_log("Erreur format_date_fr: " . $e->getMessage() . " - Date: " . $date);
        return 'Erreur de date';
    }
}

/**
 * Calcule la durée en heures et minutes
 */
function format_duree($minutes) {
    $heures = floor($minutes / 60);
    $minutes_restantes = $minutes % 60;
    
    if ($heures > 0) {
        return "$heures h " . ($minutes_restantes > 0 ? "$minutes_restantes min" : "");
    }
    return "$minutes min";
}

/**
 * Génère une couleur aléatoire pour les badges
 */
function generate_badge_color($text) {
    $colors = [
        '#3498db', '#2ecc71', '#e74c3c', '#f39c12',
        '#9b59b6', '#1abc9c', '#d35400', '#34495e'
    ];
    $index = abs(crc32($text)) % count($colors);
    
    return $colors[$index];
}


function format_number($number, $decimals = 0) {
    return number_format($number, $decimals, ',', ' ');
}


function is_valid_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}


function truncate_text($text, $max_length = 100) {
    if (strlen($text) <= $max_length) {
        return $text;
    }
    return substr($text, 0, $max_length) . '...';
}


function get_statut_text($statut) {
    $statuts = [
        'planifie' => 'Planifié',
        'confirme' => 'Confirmé',
        'annule' => 'Annulé',
        'en_attente_validation' => 'En attente de validation',
        'valide' => 'Validé',
        'rejete' => 'Rejeté',
        'envoye' => 'Envoyé',
        'vu' => 'Vu',
        'modifie' => 'Modifié',
        'envoye_doyen' => 'Envoyé au Doyen',
        'vu_doyen' => 'Vu par le Doyen',
        'valide_doyen' => 'Validé par le Doyen',
        'rejete_doyen' => 'Rejeté par le Doyen'
    ];
    
    return $statuts[$statut] ?? ucfirst($statut);
}


function get_notification_icon($type) {
    $icons = [
        'emploi_temps' => 'fas fa-calendar-alt',
        'validation' => 'fas fa-check-circle',
        'conflit' => 'fas fa-exclamation-triangle',
        'message' => 'fas fa-envelope',
        'system' => 'fas fa-cog',
        'info' => 'fas fa-info-circle',
        'warning' => 'fas fa-exclamation-triangle',
        'success' => 'fas fa-check-circle',
        'danger' => 'fas fa-times-circle'
    ];
    
    return $icons[$type] ?? 'fas fa-bell';
}

/**
 * Récupère un seul enregistrement
 */
function fetchOne($sql, $params = []) {
    global $pdo;
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    } catch (Exception $e) {
        error_log("Erreur fetchOne: " . $e->getMessage());
        return false;
    }
}


function verifierAuthentification() {
    demarrerSession();
    
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        
        header('Location: ../login.php');
        exit();
    }
    
    return true;
}

/**
 */
function verifierPermission($permission_requise) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['user_role'])) {
        header('Location: ../login.php?error=no_role');
        exit();
    }
    
    if ($_SESSION['user_role'] === 'admin') {
        return true;
    }
    
    $permissions = $_SESSION['user_permissions'] ?? [];
    
    if (!in_array($permission_requise, $permissions)) {
        header('Location: ../unauthorized.php?error=no_permission');
        exit();
    }
    
    return true;
}

function send_notification($user_id, $user_type, $type, $title, $message, $link = null) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications 
            (utilisateur_id, utilisateur_type, type, titre, message, lien, lu, date_creation)
            VALUES (?, ?, ?, ?, ?, ?, 0, NOW())
        ");
        $stmt->execute([$user_id, $user_type, $type, $title, $message, $link]);
        
        return $pdo->lastInsertId();
    } catch (Exception $e) {
        error_log("Erreur send_notification: " . $e->getMessage());
        return false;
    }
}


function get_unread_notifications_count($user_id, $user_type) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM notifications 
            WHERE utilisateur_id = ? 
            AND utilisateur_type = ? 
            AND lu = 0
        ");
        $stmt->execute([$user_id, $user_type]);
        $result = $stmt->fetch();
        
        return $result['count'] ?? 0;
    } catch (Exception $e) {
        error_log("Erreur get_unread_notifications_count: " . $e->getMessage());
        return 0;
    }
}


function get_notifications($user_id, $user_type, $limit = 10) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT * 
            FROM notifications 
            WHERE utilisateur_id = ? 
            AND utilisateur_type = ? 
            ORDER BY date_creation DESC 
            LIMIT ?
        ");
        $stmt->execute([$user_id, $user_type, $limit]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Erreur get_notifications: " . $e->getMessage());
        return [];
    }
}


function mark_notification_as_read($notification_id) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET lu = 1, date_lu = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$notification_id]);
        return true;
    } catch (Exception $e) {
        error_log("Erreur mark_notification_as_read: " . $e->getMessage());
        return false;
    }
}


function mark_all_notifications_as_read($user_id, $user_type) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET lu = 1, date_lu = NOW() 
            WHERE utilisateur_id = ? 
            AND utilisateur_type = ? 
            AND lu = 0
        ");
        $stmt->execute([$user_id, $user_type]);
        return true;
    } catch (Exception $e) {
        error_log("Erreur mark_all_notifications_as_read: " . $e->getMessage());
        return false;
    }
}


function time_ago($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    
    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;
    
    $string = [
        'y' => 'an',
        'm' => 'mois',
        'w' => 'semaine',
        'd' => 'jour',
        'h' => 'heure',
        'i' => 'minute',
        's' => 'seconde',
    ];
    
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }
    
    if (!$full) $string = array_slice($string, 0, 1);
    
    return $string ? 'il y a ' . implode(', ', $string) : 'maintenant';
}


/*
function require_role($allowed_roles = []) {
    demarrerSession();
    
    if (!isset($_SESSION['user_role'])) {
        redirect('login.php', 'Veuillez vous connecter d\'abord', 'error');
    }
    
    if (!in_array($_SESSION['user_role'], $allowed_roles)) {
        redirect('unauthorized.php', 'Vous n\'avez pas les permissions nécessaires', 'error');
    }
    
    return true;
}
*
 */
function get_logged_in_user() {
    global $pdo;
    
    demarrerSession();
    
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
        return null;
    }
    
    $user_id = $_SESSION['user_id'];
    $user_role = $_SESSION['user_role'];
    
    try {
        if ($user_role === 'etudiant') {
            $stmt = $pdo->prepare("
                SELECT e.*, f.nom as formation_nom, d.nom as departement_nom
                FROM etudiants e
                JOIN formations f ON e.formation_id = f.id
                JOIN departements d ON f.dept_id = d.id
                WHERE e.id = ?
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT p.*, d.nom as departement_nom,
                       CONCAT(p.nom, ' ', p.prenom) as full_name
                FROM professeurs p
                LEFT JOIN departements d ON p.dept_id = d.id
                WHERE p.id = ?
            ");
        }
        
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if ($user) {
            $user['role'] = $user_role;
            $user['role_fr'] = get_role_french($user_role);
        }
        
        return $user;
    } catch (Exception $e) {
        error_log("Erreur get_logged_in_user: " . $e->getMessage());
        return null;
    }
}


function has_permission($permission) {
    demarrerSession();
    
    if (!isset($_SESSION['user_permissions'])) {
        return false;
    }
    
    return in_array($permission, $_SESSION['user_permissions']);
}

function hash_password($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}


function verify_password($password, $hash) {
    return password_verify($password, $hash);
}


function generate_token($length = 32) {
    return bin2hex(random_bytes($length / 2));
}


function sanitize_input($input) {
    if (is_array($input)) {
        return array_map('sanitize_input', $input);
    }
    
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}


function log_activity($user_id, $user_type, $action, $details = null) {
    global $pdo;
    
    try {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        
        $stmt = $pdo->prepare("
            INSERT INTO logs_activite 
            (utilisateur_id, utilisateur_type, action, details, ip_address, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([$user_id, $user_type, $action, $details, $ip_address]);
        return true;
    } catch (Exception $e) {
        error_log("Erreur log_activity: " . $e->getMessage());
        return false;
    }
}


function load_html_content($edt_id) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT html_content 
            FROM edt_versions 
            WHERE edt_id = ? 
            ORDER BY id DESC 
            LIMIT 1
        ");
        $stmt->execute([$edt_id]);
        $result = $stmt->fetch();
        
        return $result['html_content'] ?? '';
    } catch (Exception $e) {
        error_log("Erreur load_html_content: " . $e->getMessage());
        return '';
    }
}


function check_schedule_conflicts($examen_id = null) {
    global $pdo;
    
    try {
        
        $sql_prof = "
            SELECT 
                e1.id as exam1_id,
                e2.id as exam2_id,
                p.nom as prof_nom,
                p.prenom as prof_prenom,
                CONCAT('Professeur ', p.nom, ' ', p.prenom, ' a deux examens en même temps') as description,
                'professeur' as type
            FROM examens e1
            JOIN examens e2 ON e1.prof_id = e2.prof_id 
                AND e1.id != e2.id
                AND (
                    (e1.date_heure BETWEEN e2.date_heure AND DATE_ADD(e2.date_heure, INTERVAL e2.duree_minutes MINUTE))
                    OR (DATE_ADD(e1.date_heure, INTERVAL e1.duree_minutes MINUTE) BETWEEN e2.date_heure AND DATE_ADD(e2.date_heure, INTERVAL e2.duree_minutes MINUTE))
                )
            JOIN professeurs p ON e1.prof_id = p.id
            WHERE 1=1
        ";
        
        
        $sql_student = "
            SELECT 
                e1.id as exam1_id,
                e2.id as exam2_id,
                et.nom as etudiant_nom,
                et.prenom as etudiant_prenom,
                CONCAT('Étudiant ', et.nom, ' ', et.prenom, ' a deux examens en même temps') as description,
                'etudiant' as type
            FROM examens_etudiants ee1
            JOIN examens_etudiants ee2 ON ee1.etudiant_id = ee2.etudiant_id 
                AND ee1.examen_id != ee2.examen_id
            JOIN examens e1 ON ee1.examen_id = e1.id
            JOIN examens e2 ON ee2.examen_id = e2.id
            JOIN etudiants et ON ee1.etudiant_id = et.id
            WHERE (
                (e1.date_heure BETWEEN e2.date_heure AND DATE_ADD(e2.date_heure, INTERVAL e2.duree_minutes MINUTE))
                OR (DATE_ADD(e1.date_heure, INTERVAL e1.duree_minutes MINUTE) BETWEEN e2.date_heure AND DATE_ADD(e2.date_heure, INTERVAL e2.duree_minutes MINUTE))
            )
        ";
        
        
        
        $conflicts = [];
        
       
        return $conflicts;
    } catch (Exception $e) {
        error_log("Erreur check_schedule_conflicts: " . $e->getMessage());
        return [];
    }
}


function generate_select_options($table, $value_field, $text_field, $selected = '', $where = '') {
    global $pdo;
    
    try {
        $sql = "SELECT $value_field, $text_field FROM $table";
        if ($where) {
            $sql .= " WHERE $where";
        }
        $sql .= " ORDER BY $text_field";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll();
        
        $options = '';
        foreach ($results as $row) {
            $value = $row[$value_field];
            $text = $row[$text_field];
            $is_selected = ($value == $selected) ? 'selected' : '';
            $options .= "<option value='$value' $is_selected>$text</option>";
        }
        
        return $options;
    } catch (Exception $e) {
        error_log("Erreur generate_select_options: " . $e->getMessage());
        return '';
    }
}


function include_chart_js() {
    echo '
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    ';
}


function include_advanced_charts() {
    echo '
    <script src="https://cdn.jsdelivr.net/npm/luxon@3.0.4"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-luxon@1.2.1"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@1.4.0"></script>
    ';
}


function include_animations() {
    echo '
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/wow/1.1.2/wow.min.js"></script>
    <script>
        new WOW().init();
    </script>
    ';
}


function include_select2() {
    echo '
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            $(".select2").select2({
                theme: "bootstrap",
                width: "100%",
                placeholder: "Sélectionnez une option"
            });
        });
    </script>
    ';
}