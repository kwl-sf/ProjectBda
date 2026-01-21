<?php
// includes/save_planning_html.php

/**
 * Génère et sauvegarde l'HTML complet d'un planning
 */
function save_planning_html($formation_ids, $semestre, $session, $start_date, $end_date, $admin_info, $pdo) {
    
    // Générer l'HTML
    $html = generate_planning_html($formation_ids, $semestre, $session, $start_date, $end_date, $admin_info, $pdo);
    
    // Créer le dossier si nécessaire
    $html_dir = '../storage/planning_html/';
    if (!file_exists($html_dir)) {
        mkdir($html_dir, 0777, true);
    }
    
    // Nom unique pour le fichier
    $filename = 'planning_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.html';
    $filepath = $html_dir . $filename;
    
    // Sauvegarder
    if (file_put_contents($filepath, $html)) {
        return $filename;
    }
    
    return false;
}

/**
 * Génère le HTML d'un planning
 */
function generate_planning_html($formation_ids, $semestre, $session, $start_date, $end_date, $admin_info, $pdo) {
    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Planning Examens <?php echo $semestre; ?> - <?php echo $session; ?></title>
        <style>
            /* نفس الـ CSS الذي في generate_schedule.php */
            <?php echo get_planning_css(); ?>
        </style>
    </head>
    <body>
        <!-- نفس هيكل HTML الذي في generate_schedule.php -->
        <?php echo generate_planning_content($formation_ids, $semestre, $session, $start_date, $end_date, $admin_info, $pdo); ?>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

/**
 * Retourne le CSS du planning
 */
function get_planning_css() {
    return '
        /* نسخة مبسطة من CSS generate_schedule.php */
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { background: #4361ee; color: white; padding: 20px; text-align: center; }
        .formation { margin: 20px 0; border: 1px solid #ddd; border-radius: 5px; }
        .formation-title { background: #f0f0f0; padding: 10px; font-weight: bold; }
        .exam-table { width: 100%; border-collapse: collapse; }
        .exam-table th, .exam-table td { border: 1px solid #ddd; padding: 8px; }
        .exam-table th { background: #f9f9f9; }
        .salle-info { background: #e8f4fd; padding: 5px; border-radius: 3px; }
    ';
}

/**
 * Génère le contenu du planning
 */
function generate_planning_content($formation_ids, $semestre, $session, $start_date, $end_date, $admin_info, $pdo) {
    ob_start();
    ?>
    <div class="planning-container">
        <div class="header">
            <h1>📅 Planning des Examens</h1>
            <p>Semestre: <?php echo $semestre; ?> | Session: <?php echo $session; ?></p>
            <p>Période: <?php echo $start_date; ?> au <?php echo $end_date; ?></p>
        </div>
        
        <?php
        if (!empty($formation_ids)) {
            foreach ($formation_ids as $formation_id) {
                display_formation_exams($formation_id, $semestre, $pdo);
            }
        }
        ?>
        
        <div class="footer">
            <p>Généré par: <?php echo $admin_info['nom']; ?> le <?php echo date('d/m/Y H:i'); ?></p>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Affiche les examens d'une formation
 */
function display_formation_exams($formation_id, $semestre, $pdo) {
    // نفس دالة display_formation_exams التي في send_to_chefs.php
    // (يمكن نسخها من send_to_chefs.php)
}
?>