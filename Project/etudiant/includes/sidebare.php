<?php
// etudiant/includes/sidebar.php
// Sidebar موحد للطالب

// معلومات الطالب
$etudiant = [];
if (isset($user)) {
    $stmt = $pdo->prepare("
        SELECT e.*, f.nom as formation_nom, d.nom as dept_nom
        FROM etudiants e
        JOIN formations f ON e.formation_id = f.id
        JOIN departements d ON f.dept_id = d.id
        WHERE e.id = ?
    ");
    $stmt->execute([$user['id']]);
    $etudiant = $stmt->fetch();
}

// عدد الإشعارات
$notification_count = 0;
if (isset($user)) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM notifications 
        WHERE destinataire_id = ? 
        AND destinataire_type = 'etudiant' 
        AND lue = 0
    ");
    $stmt->execute([$user['id']]);
    $notification_count = $stmt->fetch()['count'];
}

// عدد الامتحانات القادمة
$exams_next_week = 0;
if (isset($user)) {
    $start_date = date('Y-m-d');
    $end_date = date('Y-m-d', strtotime('+7 days'));
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM examens_etudiants ee
        JOIN examens ex ON ee.examen_id = ex.id
        WHERE ee.etudiant_id = ?
        AND DATE(ex.date_heure) BETWEEN ? AND ?
    ");
    $stmt->execute([$user['id'], $start_date, $end_date]);
    $exams_next_week = $stmt->fetch()['count'];
}
?>
<aside class="sidebar">
    <div class="sidebar-header">
        <h2><i class="fas fa-user-graduate"></i> Espace Étudiant</h2>
        <p><?php echo isset($etudiant['formation_nom']) ? htmlspecialchars($etudiant['formation_nom']) : ''; ?></p>
    </div>
    
    <div class="user-info">
        <div class="user-avatar student">
            <i class="fas fa-user-graduate"></i>
        </div>
        <div class="user-name"><?php echo isset($etudiant['prenom']) ? htmlspecialchars($etudiant['prenom'] . ' ' . $etudiant['nom']) : ''; ?></div>
        <div class="user-role">Étudiant - Promo <?php echo isset($etudiant['promo']) ? htmlspecialchars($etudiant['promo']) : ''; ?></div>
    </div>
    
    <nav class="sidebar-nav">
        <a href="dashboard.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
            <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
            <span>Tableau de Bord</span>
        </a>
        <a href="my_schedule.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'my_schedule.php' ? 'active' : ''; ?>">
            <span class="nav-icon"><i class="fas fa-calendar-alt"></i></span>
            <span>Mon Emploi du Temps</span>
        </a>
        <a href="exams.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'exams.php' ? 'active' : ''; ?>">
            <span class="nav-icon"><i class="fas fa-file-alt"></i></span>
            <span>Mes Examens</span>
        </a>
        <a href="notes.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'notes.php' ? 'active' : ''; ?>">
            <span class="nav-icon"><i class="fas fa-chart-line"></i></span>
            <span>Mes Notes</span>
        </a>
        <a href="absences.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'absences.php' ? 'active' : ''; ?>">
            <span class="nav-icon"><i class="fas fa-user-clock"></i></span>
            <span>Absences</span>
        </a>
        <a href="../logout.php" class="nav-item">
            <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
            <span>Déconnexion</span>
        </a>
    </nav>
    
    <div class="sidebar-footer">
        <div class="dept-stats">
            <div class="stat-mini">
                <i class="fas fa-file-alt"></i>
                <span><?php echo $exams_next_week; ?></span>
            </div>
            <div class="stat-mini">
                <i class="fas fa-bell"></i>
                <span><?php echo $notification_count; ?></span>
            </div>
            <div class="stat-mini">
                <i class="fas fa-graduation-cap"></i>
                <span><?php echo isset($etudiant['promo']) ? $etudiant['promo'] : ''; ?></span>
            </div>
        </div>
    </div>
</aside>