<?php
// chef_dept/validation.php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Vérifier que l'utilisateur est chef de département
require_role(['chef_dept']);

$user = get_logged_in_user();
$dept_id = $user['dept_id'];

// Récupérer les informations du département
$stmt = $pdo->prepare("SELECT nom FROM departements WHERE id = ?");
$stmt->execute([$dept_id]);
$departement = $stmt->fetch();

// Paramètres
$envoi_id = $_GET['envoi_id'] ?? 0;
$action = $_POST['action'] ?? '';

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $envoi_id) {
    $commentaire = $_POST['commentaire'] ?? '';
    
    if ($action === 'valider') {
        $stmt = $pdo->prepare("
            UPDATE envois_chefs_departement 
            SET statut = 'valide', 
                date_validation = NOW(),
                commentaires_validation = ?
            WHERE id = ? AND chef_dept_id = ? AND departement_id = ?
        ");
        $stmt->execute([$commentaire, $envoi_id, $user['id'], $dept_id]);
        
        // تحديث حالة الامتحانات إلى 'confirme'
        $stmt = $pdo->prepare("
            SELECT nb_examens, semestre, session, date_debut, date_fin 
            FROM envois_chefs_departement 
            WHERE id = ?
        ");
        $stmt->execute([$envoi_id]);
        $envoi_info = $stmt->fetch();
        
        if ($envoi_info) {
            $stmt = $pdo->prepare("
                UPDATE examens e
                JOIN modules m ON e.module_id = m.id
                JOIN formations f ON m.formation_id = f.id
                SET e.statut = 'confirme'
                WHERE f.dept_id = ?
                AND e.statut = 'planifie'
                AND DATE(e.date_heure) BETWEEN ? AND ?
            ");
            $stmt->execute([$dept_id, $envoi_info['date_debut'], $envoi_info['date_fin']]);
        }
        
        // إنشاء إشعار للإدمن
        $stmt = $pdo->prepare("
            INSERT INTO notifications 
            (destinataire_id, destinataire_type, type, titre, contenu, lien, lue, created_at)
            SELECT admin_id, 'prof', 'validation', 'Validation EDT', 
                   CONCAT('Le chef du département ', d.nom, ' a validé l\'emploi du temps.'), 
                   '../admin/dashboard.php', 0, NOW()
            FROM envois_chefs_departement ecd
            JOIN departements d ON ecd.departement_id = d.id
            WHERE ecd.id = ?
        ");
        $stmt->execute([$envoi_id]);
        
        $_SESSION['flash_message'] = "Emploi du temps validé avec succès !";
        $_SESSION['flash_type'] = 'success';
        
    } elseif ($action === 'rejeter') {
        $stmt = $pdo->prepare("
            UPDATE envois_chefs_departement 
            SET statut = 'rejete', 
                date_validation = NOW(),
                commentaires_validation = ?
            WHERE id = ? AND chef_dept_id = ? AND departement_id = ?
        ");
        $stmt->execute([$commentaire, $envoi_id, $user['id'], $dept_id]);
        
        // إنشاء إشعار للإدمن
        $stmt = $pdo->prepare("
            INSERT INTO notifications 
            (destinataire_id, destinataire_type, type, titre, contenu, lien, lue, created_at)
            SELECT admin_id, 'prof', 'validation', 'Rejet EDT', 
                   CONCAT('Le chef du département ', d.nom, ' a rejeté l\'emploi du temps. Commentaire: ', ?), 
                   '../admin/dashboard.php', 0, NOW()
            FROM envois_chefs_departement ecd
            JOIN departements d ON ecd.departement_id = d.id
            WHERE ecd.id = ?
        ");
        $stmt->execute([$commentaire, $envoi_id]);
        
        $_SESSION['flash_message'] = "Emploi du temps rejeté. Un commentaire a été envoyé à l'administration.";
        $_SESSION['flash_type'] = 'warning';
    }
    
    header("Location: validation.php");
    exit();
}

// Récupérer les envois en attente
$stmt = $pdo->prepare("
    SELECT ecd.*, p.nom as admin_nom, p.prenom as admin_prenom
    FROM envois_chefs_departement ecd
    JOIN professeurs p ON ecd.admin_id = p.id
    WHERE ecd.chef_dept_id = ? 
    AND ecd.departement_id = ?
    ORDER BY ecd.created_at DESC
");
$stmt->execute([$user['id'], $dept_id]);
$envois = $stmt->fetchAll();

// Récupérer les détails de l'envoi sélectionné
$envoi_details = null;
$html_content = '';

if ($envoi_id) {
    $stmt = $pdo->prepare("
        SELECT ecd.*, p.nom as admin_nom, p.prenom as admin_prenom
        FROM envois_chefs_departement ecd
        JOIN professeurs p ON ecd.admin_id = p.id
        WHERE ecd.id = ? AND ecd.chef_dept_id = ? AND ecd.departement_id = ?
    ");
    $stmt->execute([$envoi_id, $user['id'], $dept_id]);
    $envoi_details = $stmt->fetch();
    
    if ($envoi_details && !empty($envoi_details['html_content'])) {
        $html_content = $envoi_details['html_content'];
    }
}

$page_title = "Validation des Emplois du Temps";
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
    <style>
        .validation-container {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 2rem;
            max-width: 1600px;
            margin: 0 auto;
        }
        
        @media (max-width: 1024px) {
            .validation-container {
                grid-template-columns: 1fr;
            }
        }
        
        .envois-list {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-md);
            overflow: hidden;
        }
        
        .envois-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 1.5rem;
        }
        
        .envoi-item {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            cursor: pointer;
            transition: var(--transition);
            position: relative;
        }
        
        .envoi-item:hover {
            background: var(--gray-100);
        }
        
        .envoi-item.active {
            background: rgba(67, 97, 238, 0.1);
            border-left: 4px solid var(--primary);
        }
        
        .envoi-title {
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
        }
        
        .envoi-details {
            font-size: 0.85rem;
            color: var(--gray-600);
            line-height: 1.4;
        }
        
        .envoi-details i {
            width: 16px;
            text-align: center;
            margin-right: 0.25rem;
        }
        
        .envoi-status {
            position: absolute;
            top: 1rem;
            right: 1rem;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-envoye { background: rgba(52, 152, 219, 0.1); color: var(--info); }
        .status-valide { background: rgba(46, 204, 113, 0.1); color: var(--success); }
        .status-rejete { background: rgba(231, 76, 60, 0.1); color: var(--danger); }
        
        .validation-content {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-md);
            padding: 2rem;
        }
        
        .validation-header {
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid var(--gray-200);
        }
        
        .validation-header h2 {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
        }
        
        .validation-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .info-card {
            background: var(--gray-50);
            padding: 1rem;
            border-radius: var(--border-radius-sm);
            border-left: 4px solid var(--primary);
        }
        
        .info-label {
            font-size: 0.9rem;
            color: var(--gray-600);
            margin-bottom: 0.25rem;
        }
        
        .info-value {
            font-weight: 600;
            color: var(--gray-900);
        }
        
        .html-content-container {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: var(--border-radius-sm);
            padding: 20px;
            margin-bottom: 2rem;
            overflow-x: auto;
            max-height: 600px;
            overflow-y: auto;
        }
        
        .html-content-container * {
            font-family: Arial, sans-serif !important;
        }
        
        .validation-actions {
            background: var(--gray-50);
            padding: 2rem;
            border-radius: var(--border-radius);
            margin-top: 2rem;
        }
        
        .action-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
        }
        
        .btn-validate {
            background: linear-gradient(135deg, var(--success), #27ae60);
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: var(--border-radius);
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            transition: var(--transition);
        }
        
        .btn-validate:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .btn-reject {
            background: linear-gradient(135deg, var(--danger), #c0392b);
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: var(--border-radius);
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            transition: var(--transition);
        }
        
        .btn-reject:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .comment-box {
            margin-top: 1.5rem;
        }
        
        .comment-box label {
            display: block;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 0.5rem;
        }
        
        .comment-box textarea {
            width: 100%;
            padding: 1rem;
            border: 2px solid var(--gray-300);
            border-radius: var(--border-radius-sm);
            font-size: 1rem;
            min-height: 100px;
            resize: vertical;
            transition: var(--transition);
        }
        
        .comment-box textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--gray-500);
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .no-content {
            background: var(--gray-100);
            padding: 2rem;
            border-radius: var(--border-radius);
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .no-content i {
            font-size: 3rem;
            color: var(--warning);
            margin-bottom: 1rem;
        }


        /* أضف هذا CSS في قسم <style> في chef_dept/validation.php */

.html-content-container {
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: var(--border-radius-sm);
    padding: 20px;
    margin-bottom: 2rem;
    overflow-x: auto;
    max-height: 800px;
    overflow-y: auto;
}

.html-content-container * {
    font-family: 'Poppins', Arial, sans-serif !important;
}

/* تنسيق البطاقات داخل HTML */
.html-content-container .emploi-du-temps-html {
    font-family: 'Poppins', Arial, sans-serif;
}

.html-content-container table {
    border-collapse: collapse;
    width: 100%;
}

.html-content-container th {
    background: #f5f5f5;
    padding: 1rem;
    text-align: left;
    font-weight: 600;
    color: #666;
    border-bottom: 2px solid #ddd;
}

.html-content-container td {
    padding: 1rem;
    border-bottom: 1px solid #eee;
    vertical-align: top;
}

.html-content-container tr:hover {
    background: #f9f9f9;
}

/* تنسيق الأيقونات */
.html-content-container i.fas {
    font-family: 'Font Awesome 6 Free' !important;
    font-weight: 900;
}

/* تنسيق البطاقات */
.html-content-container .salle-card {
    background: rgba(67, 97, 238, 0.05);
    border-left: 4px solid #4361ee;
    padding: 0.75rem;
    border-radius: 6px;
    margin-bottom: 0.5rem;
}

/* تنسيق العناوين */
.html-content-container h1, 
.html-content-container h2, 
.html-content-container h3 {
    color: #333;
    margin-bottom: 0.5rem;
}

/* تنسيق التدرجات */
.html-content-container .gradient-header {
    background: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%);
    color: white;
    padding: 1.5rem;
    border-radius: 8px;
}

/* تحسين عرض الجداول */
.html-content-container table {
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    overflow: hidden;
}

.html-content-container th:first-child {
    border-top-left-radius: 8px;
}

.html-content-container th:last-child {
    border-top-right-radius: 8px;
}

.html-content-container tr:last-child td {
    border-bottom: none;
}


    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-university"></i> <?php echo htmlspecialchars($departement['nom'] ?? 'Département'); ?></h2>
                <p>Chef de Département</p>
            </div>
            
            <div class="user-info">
                <div class="user-avatar" style="background: linear-gradient(135deg, #3a0ca3 0%, #4361ee 100%);">
                    <i class="fas fa-user-tie"></i>
                </div>
                <div class="user-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($user['role_fr']); ?></div>
            </div>
            
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                    <span>Tableau de Bord</span>
                </a>
                <a href="department_schedule.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-calendar-alt"></i></span>
                    <span>Planning Departement</span>
                </a>
                <a href="validation.php" class="nav-item active">
                    <span class="nav-icon"><i class="fas fa-check-circle"></i></span>
                    <span>Validation EDT</span>
                </a>
               
                <a href="Students_list.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-users"></i></span>
                    <span>Students list </span>
                </a>
                <a href="professors_list.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-chalkboard-teacher"></i></span>
                    <span>professsors list</span>
                </a>
                <a href="../logout.php" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                    <span>Déconnexion</span>
                </a>
            </nav>
        </aside>
        
        <main class="main-content">
            <header class="header">
                <div class="header-left">
                    <h1><i class="fas fa-check-circle"></i> Validation des Emplois du Temps</h1>
                    <p>Examinez et validez les emplois du temps envoyés par l'administration</p>
                </div>
                <div class="header-actions">
                    <button class="menu-toggle" id="menuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                </div>
            </header>
            
            <?php display_flash_message(); ?>
            
            
                
                <!-- Contenu de validation -->
                <div class="validation-content">
                    <?php if ($envoi_details): ?>
                        <div class="validation-header">
                            <h2>Examen de l'Emploi du Temps</h2>
                            <p>Semestre <?php echo $envoi_details['semestre']; ?> - <?php echo $envoi_details['session']; ?></p>
                        </div>
                        
                        <div class="validation-info">
                            <div class="info-card">
                                <div class="info-label">Période</div>
                                <div class="info-value">
                                    <?php echo date('d/m/Y', strtotime($envoi_details['date_debut'])); ?> - 
                                    <?php echo date('d/m/Y', strtotime($envoi_details['date_fin'])); ?>
                                </div>
                            </div>
                            
                            <div class="info-card">
                                <div class="info-label">Nombre d'examens</div>
                                <div class="info-value"><?php echo $envoi_details['nb_examens']; ?> examens</div>
                            </div>
                            
                            <div class="info-card">
                                <div class="info-label">Statut actuel</div>
                                <div class="info-value">
                                    <span class="status-<?php echo $envoi_details['statut']; ?>">
                                        <?php echo ucfirst($envoi_details['statut']); ?>
                                    </span>
                                </div>
                            </div>
                            
                            <?php if ($envoi_details['message']): ?>
                                <div class="info-card" style="grid-column: span 2;">
                                    <div class="info-label">Message de l'administration</div>
                                    <div class="info-value"><?php echo htmlspecialchars($envoi_details['message']); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- عرض HTML المحفوظ -->
                        <h3 style="margin-bottom: 1rem; color: var(--gray-800);">
                            <i class="fas fa-calendar-alt"></i> Détail de l'Emploi du Temps
                        </h3>
                        
                        <?php if (!empty($html_content)): ?>
                            <div class="html-content-container">
                                <?php echo $html_content; ?>
                            </div>
                        <?php else: ?>
                            <div class="no-content">
                                <i class="fas fa-exclamation-triangle"></i>
                                <h3 style="color: var(--gray-700); margin-bottom: 1rem;">Aucun détail d'emploi du temps trouvé</h3>
                                <p style="color: var(--gray-600);">
                                    Le contenu de l'emploi du temps n'a pas été trouvé dans la base de données.
                                </p>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Actions de validation -->
                        <?php if ($envoi_details['statut'] === 'envoye'): ?>
                            <div class="validation-actions">
                                <h3 style="margin-bottom: 1.5rem; text-align: center; color: var(--gray-800);">
                                    <i class="fas fa-gavel"></i> Actions de Validation
                                </h3>
                                
                                <form method="POST" action="">
                                    <input type="hidden" name="envoi_id" value="<?php echo $envoi_id; ?>">
                                    
                                    <div class="comment-box">
                                        <label for="commentaire">
                                            <i class="fas fa-comment"></i>
                                            Commentaire (optionnel)
                                        </label>
                                        <textarea name="commentaire" id="commentaire" 
                                                  placeholder="Ajoutez un commentaire pour l'administration..."></textarea>
                                    </div>
                                    
                                    <div class="action-buttons">
                                        <button type="submit" name="action" value="valider" class="btn-validate">
                                            <i class="fas fa-check"></i>
                                            Valider l'Emploi du Temps
                                        </button>
                                        
                                        <button type="submit" name="action" value="rejeter" class="btn-reject" 
                                                onclick="return confirm('Êtes-vous sûr de vouloir rejeter cet emploi du temps ?');">
                                            <i class="fas fa-times"></i>
                                            Rejeter
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php elseif ($envoi_details['statut'] === 'valide'): ?>
                            <div style="background: rgba(46, 204, 113, 0.1); border-radius: var(--border-radius); padding: 1.5rem; text-align: center;">
                                <i class="fas fa-check-circle" style="color: var(--success); font-size: 2rem; margin-bottom: 1rem;"></i>
                                <h3 style="color: var(--success); margin-bottom: 0.5rem;">Emploi du Temps Validé</h3>
                                <p style="color: var(--gray-700);">
                                    Validé le <?php echo format_date_fr($envoi_details['date_validation'], true); ?>
                                </p>
                                <?php if ($envoi_details['commentaires_validation']): ?>
                                    <div style="margin-top: 1rem; padding: 1rem; background: white; border-radius: var(--border-radius-sm);">
                                        <strong>Votre commentaire :</strong><br>
                                        <?php echo htmlspecialchars($envoi_details['commentaires_validation']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($envoi_details['statut'] === 'rejete'): ?>
                            <div style="background: rgba(231, 76, 60, 0.1); border-radius: var(--border-radius); padding: 1.5rem; text-align: center;">
                                <i class="fas fa-times-circle" style="color: var(--danger); font-size: 2rem; margin-bottom: 1rem;"></i>
                                <h3 style="color: var(--danger); margin-bottom: 0.5rem;">Emploi du Temps Rejeté</h3>
                                <p style="color: var(--gray-700);">
                                    Rejeté le <?php echo format_date_fr($envoi_details['date_validation'], true); ?>
                                </p>
                                <?php if ($envoi_details['commentaires_validation']): ?>
                                    <div style="margin-top: 1rem; padding: 1rem; background: white; border-radius: var(--border-radius-sm);">
                                        <strong>Votre commentaire :</strong><br>
                                        <?php echo htmlspecialchars($envoi_details['commentaires_validation']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-file-alt"></i>
                            <h3>Sélectionnez un emploi du temps</h3>
                            <p>Cliquez sur un emploi du temps dans la liste pour le consulter et le valider.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
        });
        
        // التأكد من أن HTML يظهر بشكل صحيح
        document.addEventListener('DOMContentLoaded', function() {
            const htmlContainer = document.querySelector('.html-content-container');
            if (htmlContainer) {
                // إضافة تأثيرات للصور والأيقونات في HTML
                const icons = htmlContainer.querySelectorAll('i');
                icons.forEach(icon => {
                    if (icon.classList.contains('fa')) {
                        icon.style.fontFamily = 'Font Awesome 6 Free, Arial, sans-serif';
                    }
                });
            }
        });
    </script>
</body>
</html>