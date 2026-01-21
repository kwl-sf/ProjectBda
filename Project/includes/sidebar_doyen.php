<?php
// doyen/includes/sidebar_doyen.php
?>
<aside class="sidebar sidebar-doyen">
    <div class="sidebar-header">
        <h2><i class="fas fa-university"></i> Doyenné</h2>
        <p>Vue Stratégique</p>
    </div>
    
    <div class="user-info">
        <div class="user-avatar" style="background: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%);">
            <i class="fas fa-user-graduate"></i>
        </div>
        <div class="user-name"><?php echo htmlspecialchars($user['full_name'] ?? 'Doyen'); ?></div>
        <div class="user-role"><?php echo htmlspecialchars($user['role_fr'] ?? 'Doyen'); ?></div>
    </div>
    
    <nav class="sidebar-nav">
        <a href="dashboard.php" class="nav-item active">
            <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
            <span>Tableau de Bord</span>
        </a>
        <a href="validation.php" class="nav-item">
            <span class="nav-icon"><i class="fas fa-check-double"></i></span>
            <span>Validation Finale</span>
        </a>
        <a href="overview.php" class="nav-item">
            <span class="nav-icon"><i class="fas fa-eye"></i></span>
            <span>Vue d'Ensemble</span>
        </a>
        <a href="kpis.php" class="nav-item">
            <span class="nav-icon"><i class="fas fa-chart-line"></i></span>
            <span>Indicateurs (KPIs)</span>
        </a>
        <a href="reports.php" class="nav-item">
            <span class="nav-icon"><i class="fas fa-file-alt"></i></span>
            <span>Rapports</span>
        </a>
        <a href="conflits.php" class="nav-item">
            <span class="nav-icon"><i class="fas fa-exclamation-triangle"></i></span>
            <span>Conflits</span>
        </a>
        <a href="../logout.php" class="nav-item">
            <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
            <span>Déconnexion</span>
        </a>
    </nav>
</aside>