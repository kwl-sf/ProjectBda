<?php
// doyen/validation_detail.php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Vérifier que l'utilisateur est doyen ou vice-doyen
require_role(['doyen', 'vice_doyen']);

$user = get_logged_in_user();

// Vérifier l'ID
if (!isset($_GET['id'])) {
    header('Location: validation.php');
    exit();
}

$envoi_id = $_GET['id'];

// Récupérer les détails de l'envoi
$sql = "
    SELECT 
        ecd.*,
        d.nom as dept_nom,
        d.id as dept_id,
        p.nom as chef_nom,
        p.prenom as chef_prenom,
        p.email as chef_email,
        ve.edt_periode,
        ve.statut as edt_statut,
        ve.commentaires as edt_commentaires,
        vp.nom as valide_par_nom,
        vp.prenom as valide_par_prenom,
        ep.nom as envoye_par_nom,
        ep.prenom as envoye_par_prenom
    FROM envois_chef_a_doyen ecd
    JOIN departements d ON ecd.dept_id = d.id
    JOIN professeurs p ON ecd.chef_id = p.id
    JOIN envoyes_chefs ec ON ecd.envoi_chef_id = ec.id
    JOIN validations_edt ve ON ec.edt_id = ve.id
    LEFT JOIN professeurs vp ON ve.valide_par = vp.id
    LEFT JOIN professeurs ep ON ec.envoye_par = ep.id
    WHERE ecd.id = ?
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$envoi_id]);
$envoi = $stmt->fetch();

if (!$envoi) {
    header('Location: validation.php');
    exit();
}

// Récupérer les examens de cet EDT
$sql_examens = "
    SELECT 
        e.*,
        m.nom as module_nom,
        m.credits,
        prof.nom as prof_nom,
        prof.prenom as prof_prenom,
        l.nom as salle_nom,
        l.type as salle_type,
        l.capacite,
        f.nom as formation_nom,
        (SELECT COUNT(*) FROM examens_etudiants ee WHERE ee.examen_id = e.id) as nombre_etudiants
    FROM examens e
    JOIN modules m ON e.module_id = m.id
    JOIN formations f ON m.formation_id = f.id
    JOIN departements d ON f.dept_id = d.id
    JOIN professeurs prof ON e.prof_id = prof.id
    JOIN lieu_examen l ON e.salle_id = l.id
    WHERE d.id = ?
    AND DATE(e.date_heure) BETWEEN ? AND ?
    ORDER BY e.date_heure
";

// Décoder la période (format: 2026-01)
$periode = $envoi['edt_periode'];
$year_month = explode('-', $periode);
if (count($year_month) == 2) {
    $start_date = $year_month[0] . '-' . $year_month[1] . '-01';
    $end_date = date('Y-m-t', strtotime($start_date));
} else {
    $start_date = date('Y-m-01');
    $end_date = date('Y-m-t');
}

$stmt_examens = $pdo->prepare($sql_examens);
$stmt_examens->execute([$envoi['dept_id'], $start_date, $end_date]);
$examens = $stmt_examens->fetchAll();

// Organiser par jour
$examens_par_jour = [];
foreach ($examens as $examen) {
    $jour = date('Y-m-d', strtotime($examen['date_heure']));
    $examens_par_jour[$jour][] = $examen;
}

// Statistiques
$stats = [
    'total_examens' => count($examens),
    'total_etudiants' => 0,
    'salles_utilisees' => count(array_unique(array_column($examens, 'salle_id'))),
    'profs_impliques' => count(array_unique(array_column($examens, 'prof_id'))),
    'heures_total' => array_sum(array_column($examens, 'duree_minutes')) / 60
];

foreach ($examens as $examen) {
    $stats['total_etudiants'] += $examen['nombre_etudiants'];
}

$page_title = "Détails Validation - " . htmlspecialchars($envoi['dept_nom']);
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
        .detail-header {
            background: linear-gradient(135deg, #8B0000 0%, #DC143C 100%);
            color: white;
            padding: 2rem;
            border-radius: var(--border-radius);
            margin-bottom: 2rem;
        }
        
        .status-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.9rem;
            margin-left: 1rem;
        }
        
        .status-pending { background: #fff3cd; color: #856404; }
        .status-validated { background: #d4edda; color: #155724; }
        .status-rejected { background: #f8d7da; color: #721c24; }
        
        .info-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .info-card {
            background: white;
            border-radius: var(--border-radius-sm);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
        }
        
        .info-card .title {
            font-size: 0.9rem;
            color: var(--gray-600);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .info-card .value {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--gray-800);
        }
        
        .timeline {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }
        
        .timeline-day {
            border-bottom: 1px solid var(--gray-200);
        }
        
        .timeline-day:last-child {
            border-bottom: none;
        }
        
        .day-header {
            background: var(--gray-50);
            padding: 1rem 1.5rem;
            font-weight: 600;
            color: var(--gray-700);
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .day-header:hover {
            background: var(--gray-100);
        }
        
        .day-header.active {
            background: var(--primary-light);
            color: var(--primary-dark);
        }
        
        .day-date {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .day-exam-count {
            background: var(--primary);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.85rem;
        }
        
        .day-exams {
            padding: 0;
            display: none;
        }
        
        .day-exams.show {
            display: block;
        }
        
        .exam-item {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            transition: var(--transition);
        }
        
        .exam-item:hover {
            background: var(--gray-50);
        }
        
        .exam-item:last-child {
            border-bottom: none;
        }
        
        .exam-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }
        
        .exam-time {
            font-weight: 600;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .exam-module {
            font-weight: 600;
            color: var(--gray-800);
            font-size: 1.1rem;
        }
        
        .exam-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            font-size: 0.9rem;
            color: var(--gray-600);
        }
        
        .exam-detail {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .capacity-indicator {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .capacity-bar {
            width: 80px;
            height: 6px;
            background: var(--gray-200);
            border-radius: 3px;
            overflow: hidden;
        }
        
        .capacity-fill {
            height: 100%;
            background: var(--success);
        }
        
        .capacity-fill.high {
            background: var(--warning);
        }
        
        .capacity-fill.full {
            background: var(--danger);
        }
        
        .comments-section {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-top: 2rem;
            box-shadow: var(--shadow-sm);
        }
        
        .comment-box {
            background: var(--gray-100);
            padding: 1rem;
            border-radius: var(--border-radius-sm);
            margin-bottom: 1rem;
        }
        
        .comment-author {
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .comment-date {
            font-size: 0.85rem;
            color: var(--gray-500);
            margin-top: 0.5rem;
        }
        
        .validation-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid var(--gray-200);
        }
        
        .no-exams {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--gray-500);
        }
        
        .no-exams i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        
        .modal-content {
            background: white;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 500px;
            padding: 2rem;
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
                    <h1>Détails Validation EDT</h1>
                    <p>Examen détaillé de l'emploi du temps</p>
                </div>
                <div class="header-actions">
                    <a href="validation.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Retour
                    </a>
                </div>
            </header>
            
            <!-- En-tête -->
            <div class="detail-header">
                <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                    <div>
                        <h2 style="margin: 0 0 0.5rem 0;">
                            <i class="fas fa-building"></i>
                            <?php echo htmlspecialchars($envoi['dept_nom']); ?>
                        </h2>
                        <p style="opacity: 0.9; margin-bottom: 0.5rem;">
                            <i class="fas fa-user-tie"></i>
                            Chef de département: <?php echo htmlspecialchars($envoi['chef_nom'] . ' ' . $envoi['chef_prenom']); ?>
                        </p>
                        <p style="opacity: 0.9; margin: 0;">
                            <i class="far fa-calendar"></i>
                            Période: <?php echo htmlspecialchars($envoi['edt_periode']); ?>
                        </p>
                    </div>
                    <div>
                        <?php
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
                                $status_class = 'status-pending';
                                $status_text = 'À modifier';
                                break;
                        }
                        ?>
                        <span class="status-badge <?php echo $status_class; ?>">
                            <?php echo $status_text; ?>
                        </span>
                    </div>
                </div>
            </div>
            
            <!-- Statistiques -->
            <div class="info-cards">
                <div class="info-card">
                    <div class="title">
                        <i class="fas fa-file-alt"></i>
                        Examens
                    </div>
                    <div class="value"><?php echo $stats['total_examens']; ?></div>
                </div>
                
                <div class="info-card">
                    <div class="title">
                        <i class="fas fa-users"></i>
                        Étudiants concernés
                    </div>
                    <div class="value"><?php echo $stats['total_etudiants']; ?></div>
                </div>
                
                <div class="info-card">
                    <div class="title">
                        <i class="fas fa-building"></i>
                        Salles utilisées
                    </div>
                    <div class="value"><?php echo $stats['salles_utilisees']; ?></div>
                </div>
                
                <div class="info-card">
                    <div class="title">
                        <i class="fas fa-user-tie"></i>
                        Enseignants impliqués
                    </div>
                    <div class="value"><?php echo $stats['profs_impliques']; ?></div>
                </div>
                
                <div class="info-card">
                    <div class="title">
                        <i class="fas fa-clock"></i>
                        Heures totales
                    </div>
                    <div class="value"><?php echo number_format($stats['heures_total'], 1); ?>h</div>
                </div>
            </div>
            
            <!-- Planning détaillé -->
            <div class="timeline">
                <div style="padding: 1.5rem; border-bottom: 1px solid var(--gray-200);">
                    <h3 style="margin: 0;">
                        <i class="fas fa-calendar-alt"></i>
                        Détail des examens
                    </h3>
                </div>
                
                <?php if (empty($examens_par_jour)): ?>
                    <div class="no-exams">
                        <i class="fas fa-calendar-times"></i>
                        <h3>Aucun examen programmé</h3>
                        <p>Vérifiez la période sélectionnée</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($examens_par_jour as $jour => $examens_jour): 
                        $date_formatted = date('l d/m/Y', strtotime($jour));
                        $date_formatted = ucfirst(strftime('%A %d/%m/%Y', strtotime($jour)));
                    ?>
                        <div class="timeline-day">
                            <div class="day-header" onclick="toggleDay('<?php echo $jour; ?>')">
                                <div class="day-date">
                                    <i class="far fa-calendar"></i>
                                    <?php echo $date_formatted; ?>
                                </div>
                                <div class="day-exam-count">
                                    <?php echo count($examens_jour); ?> examen(s)
                                </div>
                            </div>
                            
                            <div class="day-exams" id="day-<?php echo $jour; ?>">
                                <?php foreach ($examens_jour as $examen): 
                                    $occupation_rate = ($examen['nombre_etudiants'] / $examen['capacite']) * 100;
                                    $capacity_class = '';
                                    if ($occupation_rate > 90) $capacity_class = 'full';
                                    elseif ($occupation_rate > 70) $capacity_class = 'high';
                                ?>
                                    <div class="exam-item">
                                        <div class="exam-header">
                                            <div class="exam-time">
                                                <i class="far fa-clock"></i>
                                                <?php echo date('H:i', strtotime($examen['date_heure'])); ?>
                                                -
                                                <?php echo date('H:i', strtotime($examen['date_heure']) + $examen['duree_minutes'] * 60); ?>
                                            </div>
                                            <div class="exam-module">
                                                <?php echo htmlspecialchars($examen['module_nom']); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="exam-details">
                                            <div class="exam-detail">
                                                <i class="fas fa-user-tie"></i>
                                                <span><?php echo htmlspecialchars($examen['prof_nom'] . ' ' . $examen['prof_prenom']); ?></span>
                                            </div>
                                            
                                            <div class="exam-detail">
                                                <i class="fas fa-building"></i>
                                                <span><?php echo htmlspecialchars($examen['salle_nom']); ?> (<?php echo ucfirst($examen['salle_type']); ?>)</span>
                                            </div>
                                            
                                            <div class="exam-detail capacity-indicator">
                                                <i class="fas fa-users"></i>
                                                <span><?php echo $examen['nombre_etudiants']; ?>/<?php echo $examen['capacite']; ?></span>
                                                <div class="capacity-bar">
                                                    <div class="capacity-fill <?php echo $capacity_class; ?>" 
                                                         style="width: <?php echo min($occupation_rate, 100); ?>%"></div>
                                                </div>
                                            </div>
                                            
                                            <div class="exam-detail">
                                                <i class="fas fa-graduation-cap"></i>
                                                <span><?php echo htmlspecialchars($examen['formation_nom']); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- Commentaires -->
            <div class="comments-section">
                <h3 style="margin-bottom: 1.5rem;">
                    <i class="fas fa-comments"></i>
                    Commentaires
                </h3>
                
                <?php if (!empty($envoi['commentaires_chef'])): ?>
                    <div class="comment-box">
                        <div class="comment-author">
                            <i class="fas fa-crown"></i>
                            Chef de département: <?php echo htmlspecialchars($envoi['chef_nom'] . ' ' . $envoi['chef_prenom']); ?>
                        </div>
                        <div><?php echo nl2br(htmlspecialchars($envoi['commentaires_chef'])); ?></div>
                        <div class="comment-date">
                            Envoyé le: <?php echo date('d/m/Y H:i', strtotime($envoi['date_envoi'])); ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($envoi['commentaires_doyen'])): ?>
                    <div class="comment-box">
                        <div class="comment-author">
                            <i class="fas fa-user-graduate"></i>
                            Votre commentaire
                        </div>
                        <div><?php echo nl2br(htmlspecialchars($envoi['commentaires_doyen'])); ?></div>
                        <div class="comment-date">
                            Répondu le: <?php echo date('d/m/Y H:i', strtotime($envoi['date_reponse_doyen'])); ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if ($envoi['statut'] == 'envoye_doyen'): ?>
                    <!-- Actions de validation -->
                    <div class="validation-actions">
                        <button class="btn btn-success" onclick="openValidationModal('valider')">
                            <i class="fas fa-check"></i> Valider cet EDT
                        </button>
                        
                        <button class="btn btn-danger" onclick="openValidationModal('rejeter')">
                            <i class="fas fa-times"></i> Rejeter
                        </button>
                        
                        <button class="btn btn-warning" onclick="openValidationModal('demander_modification')">
                            <i class="fas fa-edit"></i> Demander modification
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <!-- Modal de validation -->
    <div class="modal" id="validationModal">
        <div class="modal-content">
            <h3 id="modalTitle">Validation EDT</h3>
            <form method="POST" action="validation.php">
                <input type="hidden" name="envoi_id" value="<?php echo $envoi_id; ?>">
                <input type="hidden" name="action" id="modalAction">
                
                <div class="form-group" style="margin: 1rem 0;">
                    <label for="commentaire" class="form-label">Commentaire (optionnel)</label>
                    <textarea name="commentaire" id="commentaire" class="form-control" 
                              rows="4" placeholder="Ajoutez un commentaire si nécessaire..."></textarea>
                </div>
                
                <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
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
    
    <script>
        function toggleDay(dayId) {
            const dayElement = document.getElementById('day-' + dayId);
            const header = dayElement.previousElementSibling;
            
            dayElement.classList.toggle('show');
            header.classList.toggle('active');
        }
        
        function openValidationModal(action) {
            const modal = document.getElementById('validationModal');
            const title = document.getElementById('modalTitle');
            const actionInput = document.getElementById('modalAction');
            const submitBtn = document.getElementById('submitBtn');
            
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
            actionInput.value = action;
            submitBtn.className = `btn ${btnClass}`;
            submitBtn.innerHTML = `<i class="fas fa-check"></i> ${actionText}`;
            
            modal.style.display = 'flex';
        }
        
        function closeModal() {
            document.getElementById('validationModal').style.display = 'none';
        }
        
        // Fermer modal en cliquant à l'extérieur
        document.getElementById('validationModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        // Ouvrir le premier jour par défaut
        document.addEventListener('DOMContentLoaded', function() {
            const firstDay = document.querySelector('.timeline-day');
            if (firstDay) {
                const firstDayId = firstDay.querySelector('.day-exams').id.replace('day-', '');
                toggleDay(firstDayId);
            }
        });
    </script>
</body>
</html>