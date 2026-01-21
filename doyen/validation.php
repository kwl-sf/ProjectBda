<?php
// doyen/validation.php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Vérifier que l'utilisateur est doyen ou vice-doyen
require_role(['doyen', 'vice_doyen']);

$user = get_logged_in_user();

// Traitement de la validation
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $envoi_id = $_POST['envoi_id'];
    $action = $_POST['action'];
    $commentaire = $_POST['commentaire'] ?? '';
    
    try {
        $pdo->beginTransaction();
        
        $statut = '';
        $date_reponse = date('Y-m-d H:i:s');
        
        switch ($action) {
            case 'valider':
                $statut = 'valide_doyen';
                break;
            case 'rejeter':
                $statut = 'rejete_doyen';
                break;
            case 'demander_modification':
                $statut = 'modifie_doyen';
                break;
        }
        
        $stmt = $pdo->prepare("
            UPDATE envois_chef_a_doyen 
            SET statut = :statut,
                commentaires_doyen = :commentaire,
                date_reponse_doyen = :date_reponse
            WHERE id = :id
        ");
        
        $stmt->execute([
            ':statut' => $statut,
            ':commentaire' => $commentaire,
            ':date_reponse' => $date_reponse,
            ':id' => $envoi_id
        ]);
        
        // Enregistrer dans les logs
        log_activity($user['id'], 'doyen', 
            "Validation EDT - $action", 
            "Envoi ID: $envoi_id");
        
        $pdo->commit();
        
        $_SESSION['success'] = "Action effectuée avec succès!";
        header('Location: validation.php');
        exit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Erreur: " . $e->getMessage();
    }
}

// Récupérer les envois
$filter = $_GET['filter'] ?? 'pending';
$dept_id = $_GET['dept'] ?? null;

$sql = "
    SELECT 
        ecd.*,
        d.nom as dept_nom,
        p.nom as chef_nom,
        p.prenom as chef_prenom,
        p.email as chef_email,
        ve.edt_periode,
        ve.statut as edt_statut,
        vp.nom as valide_par_nom,
        vp.prenom as valide_par_prenom,
        (SELECT COUNT(*) FROM examens WHERE DATE(date_heure) BETWEEN ? AND ?) as nb_examens
    FROM envois_chef_a_doyen ecd
    JOIN departements d ON ecd.dept_id = d.id
    JOIN professeurs p ON ecd.chef_id = p.id
    JOIN envoyes_chefs ec ON ecd.envoi_chef_id = ec.id
    JOIN validations_edt ve ON ec.edt_id = ve.id
    LEFT JOIN professeurs vp ON ve.valide_par = vp.id
    WHERE 1=1
";

$params = ['2026-01-01', '2026-01-31']; // Exemple de période

if ($filter == 'pending') {
    $sql .= " AND ecd.statut = 'envoye_doyen'";
} elseif ($filter == 'validated') {
    $sql .= " AND ecd.statut = 'valide_doyen'";
} elseif ($filter == 'rejected') {
    $sql .= " AND ecd.statut = 'rejete_doyen'";
} elseif ($filter == 'modified') {
    $sql .= " AND ecd.statut = 'modifie_doyen'";
}

if ($dept_id) {
    $sql .= " AND ecd.dept_id = ?";
    $params[] = $dept_id;
}

$sql .= " ORDER BY ecd.date_envoi DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$envois = $stmt->fetchAll();

// Récupérer les départements pour filtre
$stmt = $pdo->prepare("SELECT id, nom FROM departements ORDER BY nom");
$stmt->execute();
$departements = $stmt->fetchAll();

// Statistiques
$stats = [
    'pending' => 0,
    'validated' => 0,
    'rejected' => 0,
    'total' => count($envois)
];

foreach ($envois as $envoi) {
    switch ($envoi['statut']) {
        case 'envoye_doyen': $stats['pending']++; break;
        case 'valide_doyen': $stats['validated']++; break;
        case 'rejete_doyen': $stats['rejected']++; break;
    }
}

$page_title = "Validation EDT - Doyenné";
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
    <style>
        .validation-header {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            color: white;
            padding: 2rem;
            border-radius: var(--border-radius);
            margin-bottom: 2rem;
        }
        
        .stats-tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }
        
        .stat-tab {
            flex: 1;
            min-width: 200px;
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius-sm);
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            border: 2px solid transparent;
        }
        
        .stat-tab:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }
        
        .stat-tab.active {
            border-color: var(--primary);
        }
        
        .stat-tab .number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .stat-tab.pending .number { color: var(--warning); }
        .stat-tab.validated .number { color: var(--success); }
        .stat-tab.rejected .number { color: var(--danger); }
        .stat-tab.all .number { color: var(--primary); }
        
        .envoi-list {
            display: grid;
            gap: 1.5rem;
        }
        
        .envoi-card {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }
        
        .envoi-card:hover {
            box-shadow: var(--shadow-md);
        }
        
        .envoi-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .envoi-dept {
            font-weight: 600;
            font-size: 1.2rem;
            color: var(--gray-800);
        }
        
        .envoi-status {
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-pending { background: #fff3cd; color: #856404; }
        .status-validated { background: #d4edda; color: #155724; }
        .status-rejected { background: #f8d7da; color: #721c24; }
        .status-modified { background: #d1ecf1; color: #0c5460; }
        
        .envoi-body {
            padding: 1.5rem;
        }
        
        .envoi-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
        }
        
        .info-label {
            font-size: 0.85rem;
            color: var(--gray-600);
            margin-bottom: 0.25rem;
        }
        
        .info-value {
            font-weight: 500;
            color: var(--gray-800);
        }
        
        .envoi-actions {
            display: flex;
            gap: 1rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--gray-200);
        }
        
        .comment-section {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--gray-200);
        }
        
        .comment-text {
            background: var(--gray-100);
            padding: 1rem;
            border-radius: var(--border-radius-sm);
            font-size: 0.9rem;
            color: var(--gray-700);
        }
        
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        
        .modal {
            background: white;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--gray-800);
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--gray-500);
            cursor: pointer;
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--gray-500);
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        <!-- Sidebar identique -->
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="main-content">
            <header class="header">
                <div class="header-left">
                    <h1>Validation EDT - Doyenné</h1>
                    <p>Examiner et valider les emplois du temps des départements</p>
                </div>
            </header>
            
            <div class="validation-header">
                <h2><i class="fas fa-file-signature"></i> Validation des Emplois du Temps</h2>
                <p>Supervision et validation finale des plannings d'examens</p>
            </div>
            
            <!-- Statistiques tabs -->
            <div class="stats-tabs">
                <a href="?filter=pending" class="stat-tab pending <?php echo $filter == 'pending' ? 'active' : ''; ?>">
                    <div class="number"><?php echo $stats['pending']; ?></div>
                    <div class="label">En attente</div>
                </a>
                
                <a href="?filter=validated" class="stat-tab validated <?php echo $filter == 'validated' ? 'active' : ''; ?>">
                    <div class="number"><?php echo $stats['validated']; ?></div>
                    <div class="label">Validés</div>
                </a>
                
                <a href="?filter=rejected" class="stat-tab rejected <?php echo $filter == 'rejected' ? 'active' : ''; ?>">
                    <div class="number"><?php echo $stats['rejected']; ?></div>
                    <div class="label">Rejetés</div>
                </a>
                
                <a href="?filter=all" class="stat-tab all <?php echo $filter == 'all' ? 'active' : ''; ?>">
                    <div class="number"><?php echo $stats['total']; ?></div>
                    <div class="label">Total</div>
                </a>
            </div>
            
            <!-- Filtres -->
            <div class="filters-container">
                <form method="GET" class="filters-grid">
                    <div class="filter-group">
                        <label class="filter-label">Département</label>
                        <select name="dept" class="form-select" onchange="this.form.submit()">
                            <option value="">Tous les départements</option>
                            <?php foreach ($departements as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>" 
                                        <?php echo $dept_id == $dept['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['nom']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Statut</label>
                        <select name="filter" class="form-select" onchange="this.form.submit()">
                            <option value="pending" <?php echo $filter == 'pending' ? 'selected' : ''; ?>>En attente</option>
                            <option value="validated" <?php echo $filter == 'validated' ? 'selected' : ''; ?>>Validés</option>
                            <option value="rejected" <?php echo $filter == 'rejected' ? 'selected' : ''; ?>>Rejetés</option>
                            <option value="modified" <?php echo $filter == 'modified' ? 'selected' : ''; ?>>À modifier</option>
                            <option value="all" <?php echo $filter == 'all' ? 'selected' : ''; ?>>Tous</option>
                        </select>
                    </div>
                </form>
            </div>
            
            <!-- Liste des envois -->
            <div class="envoi-list">
                <?php if (empty($envois)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h3>Aucun emploi du temps à valider</h3>
                        <p>Tous les EDT ont été traités</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($envois as $envoi): 
                        $status_class = '';
                        $status_text = '';
                        switch ($envoi['statut']) {
                            case 'envoye_doyen': 
                                $status_class = 'status-pending';
                                $status_text = 'En attente';
                                break;
                            case 'valide_doyen': 
                                $status_class = 'status-validated';
                                $status_text = 'Validé';
                                break;
                            case 'rejete_doyen': 
                                $status_class = 'status-rejected';
                                $status_text = 'Rejeté';
                                break;
                            case 'modifie_doyen': 
                                $status_class = 'status-modified';
                                $status_text = 'À modifier';
                                break;
                        }
                    ?>
                        <div class="envoi-card">
                            <div class="envoi-header">
                                <div class="envoi-dept">
                                    <i class="fas fa-building"></i>
                                    <?php echo htmlspecialchars($envoi['dept_nom']); ?>
                                </div>
                                <span class="envoi-status <?php echo $status_class; ?>">
                                    <?php echo $status_text; ?>
                                </span>
                            </div>
                            
                            <div class="envoi-body">
                                <div class="envoi-info-grid">
                                    <div class="info-item">
                                        <span class="info-label">Chef de département</span>
                                        <span class="info-value">
                                            <i class="fas fa-user-tie"></i>
                                            <?php echo htmlspecialchars($envoi['chef_nom'] . ' ' . $envoi['chef_prenom']); ?>
                                        </span>
                                    </div>
                                    
                                    <div class="info-item">
                                        <span class="info-label">Période</span>
                                        <span class="info-value">
                                            <i class="far fa-calendar"></i>
                                            <?php echo htmlspecialchars($envoi['edt_periode']); ?>
                                        </span>
                                    </div>
                                    
                                    <div class="info-item">
                                        <span class="info-label">Date d'envoi</span>
                                        <span class="info-value">
                                            <i class="far fa-clock"></i>
                                            <?php echo date('d/m/Y H:i', strtotime($envoi['date_envoi'])); ?>
                                        </span>
                                    </div>
                                    
                                    <div class="info-item">
                                        <span class="info-label">Nombre d'examens</span>
                                        <span class="info-value">
                                            <i class="fas fa-file-alt"></i>
                                            <?php echo $envoi['nb_examens']; ?> examens
                                        </span>
                                    </div>
                                </div>
                                
                                <?php if (!empty($envoi['commentaires_chef'])): ?>
                                    <div class="comment-section">
                                        <div class="info-label">Commentaires du chef:</div>
                                        <div class="comment-text"><?php echo nl2br(htmlspecialchars($envoi['commentaires_chef'])); ?></div>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($envoi['commentaires_doyen'])): ?>
                                    <div class="comment-section">
                                        <div class="info-label">Vos commentaires:</div>
                                        <div class="comment-text"><?php echo nl2br(htmlspecialchars($envoi['commentaires_doyen'])); ?></div>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($envoi['statut'] == 'envoye_doyen'): ?>
                                    <div class="envoi-actions">
                                        <button class="btn btn-success" 
                                                onclick="openValidationModal(<?php echo $envoi['id']; ?>, 'valider')">
                                            <i class="fas fa-check"></i> Valider
                                        </button>
                                        
                                        <button class="btn btn-danger" 
                                                onclick="openValidationModal(<?php echo $envoi['id']; ?>, 'rejeter')">
                                            <i class="fas fa-times"></i> Rejeter
                                        </button>
                                        
                                        <button class="btn btn-warning" 
                                                onclick="openValidationModal(<?php echo $envoi['id']; ?>, 'demander_modification')">
                                            <i class="fas fa-edit"></i> Demander modification
                                        </button>
                                        
                                        <a href="validation_detail.php?id=<?php echo $envoi['id']; ?>" 
                                           class="btn btn-outline">
                                            <i class="fas fa-eye"></i> Voir détails
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <!-- Modal de validation -->
    <div class="modal-overlay" id="validationModal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-title" id="modalTitle">Validation EDT</div>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="validationForm">
                    <input type="hidden" name="envoi_id" id="envoiId">
                    <input type="hidden" name="action" id="actionType">
                    
                    <div class="form-group">
                        <label for="commentaire" class="form-label">Commentaire (optionnel)</label>
                        <textarea name="commentaire" id="commentaire" class="form-control" 
                                  rows="4" placeholder="Ajoutez un commentaire si nécessaire..."></textarea>
                    </div>
                    
                    <div class="form-actions" style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            Confirmer
                        </button>
                        <button type="button" class="btn btn-outline" onclick="closeModal()">
                            Annuler
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function openValidationModal(envoiId, action) {
            const modal = document.getElementById('validationModal');
            const title = document.getElementById('modalTitle');
            const envoiInput = document.getElementById('envoiId');
            const actionInput = document.getElementById('actionType');
            const submitBtn = document.getElementById('submitBtn');
            
            envoiInput.value = envoiId;
            actionInput.value = action;
            
            let actionText = '';
            let btnClass = '';
            
            switch (action) {
                case 'valider':
                    actionText = 'Valider cet EDT';
                    btnClass = 'btn-success';
                    break;
                case 'rejeter':
                    actionText = 'Rejeter cet EDT';
                    btnClass = 'btn-danger';
                    break;
                case 'demander_modification':
                    actionText = 'Demander une modification';
                    btnClass = 'btn-warning';
                    break;
            }
            
            title.textContent = actionText;
            submitBtn.className = `btn ${btnClass}`;
            submitBtn.innerHTML = `<i class="fas fa-check"></i> ${actionText}`;
            
            modal.style.display = 'flex';
        }
        
        function closeModal() {
            document.getElementById('validationModal').style.display = 'none';
            document.getElementById('commentaire').value = '';
        }
        
        // Fermer modal en cliquant à l'extérieur
        document.getElementById('validationModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        // Messages flash
        <?php if (isset($_SESSION['success'])): ?>
            alert('<?php echo $_SESSION['success']; ?>');
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            alert('Erreur: <?php echo $_SESSION['error']; ?>');
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
    </script>
</body>
</html>