<?php
// notifications.php - Page commune pour tous les rôles
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

require_login();

$user = get_logged_in_user();
$role = $_SESSION['user_role'];
$user_id = $user['id'];

// Récupérer toutes les notifications
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

$sql = "SELECT * FROM notifications 
        WHERE utilisateur_id = ? 
        AND utilisateur_type = ? 
        ORDER BY date_creation DESC 
        LIMIT ? OFFSET ?";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(1, $user_id, PDO::PARAM_INT);
$stmt->bindValue(2, $role, PDO::PARAM_STR);
$stmt->bindValue(3, $per_page, PDO::PARAM_INT);
$stmt->bindValue(4, $offset, PDO::PARAM_INT);
$stmt->execute();
$notifications = $stmt->fetchAll();

// Compter le total
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM notifications WHERE utilisateur_id = ? AND utilisateur_type = ?");
$stmt->execute([$user_id, $role]);
$total_notifications = $stmt->fetch()['total'];
$total_pages = ceil($total_notifications / $per_page);

// Marquer toutes les notifications comme lues si on vient de consulter la page
if ($page === 1) {
    $pdo->prepare("UPDATE notifications SET lu = TRUE, date_lu = NOW() WHERE utilisateur_id = ? AND utilisateur_type = ? AND lu = FALSE")
        ->execute([$user_id, $role]);
}

$page_title = "Notifications - " . htmlspecialchars($user['role_fr']);
?>
<!DOCTYPE html>
<html lang="fr" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title . ' | ' . SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .notifications-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .notifications-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--gray-200);
        }
        
        .notifications-actions {
            display: flex;
            gap: 1rem;
        }
        
        .notification-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: var(--shadow-md);
            border-left: 4px solid transparent;
            transition: all 0.3s ease;
        }
        
        .notification-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        
        .notification-card.info { border-left-color: var(--info); }
        .notification-card.success { border-left-color: var(--success); }
        .notification-card.warning { border-left-color: var(--warning); }
        .notification-card.danger { border-left-color: var(--danger); }
        
        .notification-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.75rem;
        }
        
        .notification-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--gray-900);
            margin: 0;
        }
        
        .notification-time {
            font-size: 0.85rem;
            color: var(--gray-500);
            white-space: nowrap;
        }
        
        .notification-body {
            color: var(--gray-700);
            line-height: 1.5;
            margin-bottom: 1rem;
        }
        
        .notification-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .notification-link {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .notification-link:hover {
            text-decoration: underline;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }
        
        .page-link {
            padding: 0.5rem 1rem;
            border: 1px solid var(--gray-300);
            border-radius: var(--border-radius-sm);
            color: var(--gray-700);
            text-decoration: none;
            transition: all 0.2s ease;
        }
        
        .page-link:hover {
            background: var(--gray-100);
        }
        
        .page-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
        }
        
        .empty-state i {
            font-size: 4rem;
            color: var(--gray-300);
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <?php include 'includes/sidebar_chef.php'; // Adaptez selon le rôle ?>
        
        <!-- Main Content -->
        <main class="main-content">
            <header class="header">
                <div class="header-left">
                    <h1><i class="fas fa-bell"></i> Mes Notifications</h1>
                    <p>Consultez l'historique de toutes vos notifications</p>
                </div>
                <div class="header-actions">
                    <button class="menu-toggle" id="menuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                </div>
            </header>
            
            <div class="notifications-container">
                <!-- En-tête -->
                <div class="notifications-header">
                    <h2 style="margin: 0; color: var(--gray-900);">
                        <i class="fas fa-history"></i> Historique des Notifications
                    </h2>
                    
                    <div class="notifications-actions">
                        <span style="color: var(--gray-600); font-size: 0.9rem;">
                            <?php echo $total_notifications; ?> notification(s)
                        </span>
                        <button class="btn btn-primary" onclick="window.history.back()">
                            <i class="fas fa-arrow-left"></i> Retour
                        </button>
                    </div>
                </div>
                
                <!-- Liste des notifications -->
                <?php if (empty($notifications)): ?>
                    <div class="empty-state">
                        <i class="far fa-bell-slash"></i>
                        <h3 style="color: var(--gray-700); margin-bottom: 0.5rem;">Aucune notification</h3>
                        <p style="color: var(--gray-600);">Vous n'avez pas encore reçu de notifications.</p>
                    </div>
                <?php else: ?>
                    <div class="notifications-list">
                        <?php foreach ($notifications as $notif): ?>
                            <div class="notification-card <?php echo $notif['type']; ?>">
                                <div class="notification-header">
                                    <h3 class="notification-title">
                                        <?php echo htmlspecialchars($notif['titre']); ?>
                                    </h3>
                                    <div class="notification-time">
                                        <?php echo format_date_fr($notif['date_creation'], true); ?>
                                    </div>
                                </div>
                                
                                <div class="notification-body">
                                    <?php echo nl2br(htmlspecialchars($notif['message'])); ?>
                                </div>
                                
                                <div class="notification-footer">
                                    <?php if ($notif['lien']): ?>
                                    <a href="<?php echo htmlspecialchars($notif['lien']); ?>" class="notification-link">
                                        <i class="fas fa-external-link-alt"></i> Voir les détails
                                    </a>
                                    <?php else: ?>
                                    <span></span>
                                    <?php endif; ?>
                                    
                                    <span style="font-size: 0.85rem; color: var(--gray-500);">
                                        <?php echo $notif['lu'] ? 'Lu' : 'Non lu'; ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>" class="page-link">
                            <i class="fas fa-chevron-left"></i> Précédent
                        </a>
                        <?php endif; ?>
                        
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <?php if ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                            <a href="?page=<?php echo $i; ?>" 
                               class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                            <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                            <span class="page-link">...</span>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>" class="page-link">
                            Suivant <i class="fas fa-chevron-right"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <script>
        // Menu Toggle
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
        });
    </script>
</body>
</html>