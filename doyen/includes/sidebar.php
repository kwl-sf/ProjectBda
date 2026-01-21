<?php
// doyen/includes/sidebar.php
// Ce fichier est inclus dans toutes les pages du doyen pour avoir une sidebar uniforme

// Récupérer le nombre de notifications
$notification_count = 0;
$pending_validation = 0;

if (isset($user)) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications 
                           WHERE destinataire_id = ? 
                           AND destinataire_type = 'prof' 
                           AND lue = 0");
    $stmt->execute([$user['id']]);
    $notification_count = $stmt->fetch()['count'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM envois_chef_a_doyen 
                           WHERE statut = 'envoye_doyen'");
    $stmt->execute();
    $pending_validation = $stmt->fetch()['count'];
}
?>
<aside class="sidebar">
    <div class="sidebar-header">
        <h2><i class="fas fa-university"></i> Doyenné</h2>
        <p><?php echo isset($user) ? htmlspecialchars($user['role_fr']) : ''; ?></p>
    </div>
    
    <div class="user-info">
        <div class="user-avatar doyen">
            <i class="fas fa-user-graduate"></i>
        </div>
        <div class="user-name"><?php echo isset($user) ? htmlspecialchars($user['full_name']) : ''; ?></div>
        <div class="user-role"><?php echo isset($user) ? htmlspecialchars($user['role_fr']) : ''; ?></div>
    </div>
    
    <nav class="sidebar-nav">
        <a href="dashboard.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
            <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
            <span>Tableau de Bord</span>
        </a>
        <a href="faculty_schedule.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'faculty_schedule.php' ? 'active' : ''; ?>">
            <span class="nav-icon"><i class="fas fa-calendar-alt"></i></span>
            <span>Planning Faculté</span>
        </a>
        <a href="validation.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'validation.php' ? 'active' : ''; ?>">
            <span class="nav-icon"><i class="fas fa-check-circle"></i></span>
            <span>Validation EDT</span>
            <?php if ($pending_validation > 0): ?>
                <span class="notification-count"><?php echo $pending_validation; ?></span>
            <?php endif; ?>
        </a>
        <a href="departments.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'departments.php' ? 'active' : ''; ?>">
            <span class="nav-icon"><i class="fas fa-building"></i></span>
            <span>Départements</span>
        </a>
        <a href="kpis.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'kpis.php' ? 'active' : ''; ?>">
            <span class="nav-icon"><i class="fas fa-chart-line"></i></span>
            <span>Indicateurs KPIs</span>
        </a>
        <a href="reports.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>">
            <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
            <span>Rapports</span>
        </a>
        <a href="../logout.php" class="nav-item">
            <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
            <span>Déconnexion</span>
        </a>
    </nav>
    
    <div class="sidebar-footer">
        <div class="dept-stats">
            <div class="stat-mini">
                <i class="fas fa-bell"></i>
                <span><?php echo $notification_count; ?></span>
            </div>
            <div class="stat-mini">
                <i class="fas fa-file-signature"></i>
                <span><?php echo $pending_validation; ?></span>
            </div>
            <div class="stat-mini">
                <i class="fas fa-building"></i>
                <span><?php echo isset($stats['total_departements']) ? $stats['total_departements'] : '0'; ?></span>
            </div>
        </div>
    </div>
</aside>