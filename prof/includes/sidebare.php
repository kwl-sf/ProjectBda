<?php
// prof/includes/sidebar.php
// Sidebar موحد للأستاذ

// معلومات الأستاذ
$professeur = [];
if (isset($user)) {
    $stmt = $pdo->prepare("
        SELECT p.*, d.nom as dept_nom
        FROM professeurs p
        LEFT JOIN departements d ON p.dept_id = d.id
        WHERE p.id = ?
    ");
    $stmt->execute([$user['id']]);
    $professeur = $stmt->fetch();
}

// عدد الإشعارات
$notification_count = 0;
if (isset($user)) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM notifications 
        WHERE destinataire_id = ? 
        AND destinataire_type = 'prof' 
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
        FROM examens 
        WHERE prof_id = ?
        AND DATE(date_heure) BETWEEN ? AND ?
    ");
    $stmt->execute([$user['id'], $start_date, $end_date]);
    $exams_next_week = $stmt->fetch()['count'];
}
?>
<aside class="sidebar">
    <div class="sidebar-header">
        <h2><i class="fas fa-chalkboard-teacher"></i> Espace Enseignant</h2>
        <p><?php echo isset($professeur['dept_nom']) ? htmlspecialchars($professeur['dept_nom']) : ''; ?></p>
    </div>
    
    <div class="user-info">
        <div class="user-avatar prof">
            <i class="fas fa-chalkboard-teacher"></i>
        </div>
        <div class="user-name"><?php echo isset($professeur['prenom']) ? htmlspecialchars($professeur['prenom'] . ' ' . $professeur['nom']) : ''; ?></div>
        <div class="user-role"><?php echo isset($professeur['specialite']) ? htmlspecialchars($professeur['specialite']) : 'Enseignant'; ?></div>
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
        <a href="surveillance.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'surveillance.php' ? 'active' : ''; ?>">
            <span class="nav-icon"><i class="fas fa-eye"></i></span>
            <span>Mes Surveillances</span>
        </a>
        <a href="students.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'students.php' ? 'active' : ''; ?>">
            <span class="nav-icon"><i class="fas fa-users"></i></span>
            <span>Mes Étudiants</span>
        </a>
        <?php if (isset($user) && $user['role'] == 'chef_dept'): ?>
            <a href="../chef_dept/dashboard.php" class="nav-item">
                <span class="nav-icon"><i class="fas fa-crown"></i></span>
                <span>Espace Chef de Département</span>
            </a>
        <?php endif; ?>
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
                <i class="fas fa-eye"></i>
                <span>0</span>
            </div>
        </div>
    </div>
</aside>