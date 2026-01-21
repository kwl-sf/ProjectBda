<?php
// includes/header.php
if (!isset($page_title)) {
    $page_title = SITE_NAME;
}

$current_user = get_current_user();
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
</head>
<body>
    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-graduation-cap"></i> PlanExam Pro</h2>
                <p><?php echo $current_user['role_fr'] ?? 'Utilisateur'; ?></p>
            </div>
            
            <div class="user-info">
                <div class="user-avatar" style="background: <?php echo generate_badge_color($current_user['full_name'] ?? ''); ?>">
                    <i class="fas <?php 
                        switch($current_user['role'] ?? '') {
                            case 'admin': echo 'fa-user-shield'; break;
                            case 'doyen': echo 'fa-crown'; break;
                            case 'vice_doyen': echo 'fa-user-tie'; break;
                            case 'chef_dept': echo 'fa-user-tie'; break;
                            case 'prof': echo 'fa-chalkboard-teacher'; break;
                            case 'etudiant': echo 'fa-user-graduate'; break;
                            default: echo 'fa-user';
                        }
                    ?>"></i>
                </div>
                <div class="user-name"><?php echo htmlspecialchars($current_user['full_name'] ?? 'Utilisateur'); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($current_user['role_fr'] ?? 'Rôle inconnu'); ?></div>
                <?php if (!empty($current_user['departement_nom'])): ?>
                    <div class="user-department">
                        <i class="fas fa-university"></i>
                        <?php echo htmlspecialchars($current_user['departement_nom']); ?>
                    </div>
                <?php endif; ?>
            </div>