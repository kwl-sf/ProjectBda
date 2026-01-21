<?php
// professeur/mes_examens.php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/fonctions.php';

verifierAuthentification();
verifierPermission('prof');

$db = getDBConnection();
$user_id = $_SESSION['user_id'];

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['confirmer_examen'])) {
        $examen_id = $_POST['examen_id'];
        
        $sql = "UPDATE examens SET statut = 'confirme' WHERE id = ? AND prof_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param('ii', $examen_id, $user_id);
        
        if ($stmt->execute()) {
            $message = "Examen confirmé avec succès";
            $message_type = 'success';
            
            logActivite($user_id, $_SESSION['user_role'], 'Confirmation examen', [
                'examen_id' => $examen_id
            ]);
        } else {
            $message = "Erreur lors de la confirmation de l'examen";
            $message_type = 'error';
        }
    }
    
    if (isset($_POST['signaler_probleme'])) {
        $examen_id = $_POST['examen_id'];
        $probleme = $_POST['probleme'];
        
        // Créer un conflit pour signaler le problème
        $sql = "INSERT INTO conflits (type, description, entite1_id, statut, date_detection) 
                VALUES ('professeur', ?, ?, 'detecte', NOW())";
        $stmt = $db->prepare($sql);
        $stmt->bind_param('si', $probleme, $examen_id);
        
        if ($stmt->execute()) {
            $message = "Problème signalé à l'administration";
            $message_type = 'success';
            
            logActivite($user_id, $_SESSION['user_role'], 'Signalement problème examen', [
                'examen_id' => $examen_id,
                'probleme' => $probleme
            ]);
        } else {
            $message = "Erreur lors du signalement du problème";
            $message_type = 'error';
        }
    }
}

// Filtres
$filtre_statut = $_GET['statut'] ?? 'tous';
$filtre_periode = $_GET['periode'] ?? 'futur';

// Construire la requête avec filtres
$where_conditions = ["e.prof_id = ?"];
$params = [$user_id];
$types = 'i';

if ($filtre_statut != 'tous') {
    $where_conditions[] = "e.statut = ?";
    $params[] = $filtre_statut;
    $types .= 's';
}

if ($filtre_periode == 'passe') {
    $where_conditions[] = "e.date_heure < NOW()";
} elseif ($filtre_periode == 'futur') {
    $where_conditions[] = "e.date_heure >= NOW()";
}

$where_clause = implode(' AND ', $where_conditions);

// Récupérer les examens du professeur
$examens = fetchAll("SELECT e.*, m.nom as module_nom, 
                            f.nom as formation_nom,
                            l.nom as salle_nom, l.type as salle_type, l.capacite,
                            COUNT(DISTINCT i.etudiant_id) as nb_etudiants
                     FROM examens e
                     JOIN modules m ON e.module_id = m.id
                     JOIN formations f ON m.formation_id = f.id
                     JOIN lieu_examen l ON e.salle_id = l.id
                     LEFT JOIN inscriptions i ON m.id = i.module_id
                     WHERE $where_clause
                     GROUP BY e.id
                     ORDER BY e.date_heure DESC", $params, $types);

// Statistiques
$stats_examens = [
    'total' => count($examens),
    'planifie' => fetchOne("SELECT COUNT(*) as count FROM examens WHERE prof_id = ? AND statut = 'planifie'", 
                          [$user_id], 'i')['count'],
    'confirme' => fetchOne("SELECT COUNT(*) as count FROM examens WHERE prof_id = ? AND statut = 'confirme'", 
                          [$user_id], 'i')['count'],
    'annule' => fetchOne("SELECT COUNT(*) as count FROM examens WHERE prof_id = ? AND statut = 'annule'", 
                        [$user_id], 'i')['count']
];
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes Examens - Professeur</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="dashboard-container">
        <?php include '../includes/sidebar.php'; ?>
        
        <main class="main-content">
            <div class="content-header">
                <h1><i class="fas fa-file-alt"></i> Mes Examens</h1>
                <p>Gestion de tous vos examens programmés</p>
            </div>
            
            <?php if (isset($message)): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <i class="fas fa-<?php echo $message_type == 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <!-- Statistiques -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats_examens['total']; ?></h3>
                        <p>Examens Totaux</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats_examens['planifie']; ?></h3>
                        <p>En Attente</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-check"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats_examens['confirme']; ?></h3>
                        <p>Confirmés</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-times"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats_examens['annule']; ?></h3>
                        <p>Annulés</p>
                    </div>
                </div>
            </div>
            
            <!-- Filtres -->
            <div class="filters-card">
                <h3><i class="fas fa-filter"></i> Filtres</h3>
                <form method="GET" class="filter-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Statut</label>
                            <select name="statut" class="form-control">
                                <option value="tous" <?php echo $filtre_statut == 'tous' ? 'selected' : ''; ?>>Tous les statuts</option>
                                <option value="planifie" <?php echo $filtre_statut == 'planifie' ? 'selected' : ''; ?>>Planifié</option>
                                <option value="confirme" <?php echo $filtre_statut == 'confirme' ? 'selected' : ''; ?>>Confirmé</option>
                                <option value="annule" <?php echo $filtre_statut == 'annule' ? 'selected' : ''; ?>>Annulé</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Période</label>
                            <select name="periode" class="form-control">
                                <option value="tous" <?php echo $filtre_periode == 'tous' ? 'selected' : ''; ?>>Toutes périodes</option>
                                <option value="futur" <?php echo $filtre_periode == 'futur' ? 'selected' : ''; ?>>À venir</option>
                                <option value="passe" <?php echo $filtre_periode == 'passe' ? 'selected' : ''; ?>>Passés</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Filtrer
                        </button>
                        <a href="mes_examens.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Réinitialiser
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- Liste des examens -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-list"></i> Liste de Mes Examens</h3>
                    <button class="btn btn-primary btn-small" onclick="exporterExamens()">
                        <i class="fas fa-download"></i> Exporter
                    </button>
                </div>
                <div class="card-body">
                    <?php if (empty($examens)): ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-times"></i>
                            <h4>Aucun examen trouvé</h4>
                            <p>Aucun examen ne correspond à vos critères de recherche.</p>
                        </div>
                    <?php else: ?>
                        <div class="examens-list">
                            <?php foreach ($examens as $examen): ?>
                                <div class="examen-card examen-<?php echo $examen['statut']; ?>">
                                    <div class="examen-header">
                                        <div class="examen-info">
                                            <h4><?php echo $examen['module_nom']; ?></h4>
                                            <div class="examen-meta">
                                                <span><i class="fas fa-graduation-cap"></i> <?php echo $examen['formation_nom']; ?></span>
                                                <span><i class="fas fa-users"></i> <?php echo $examen['nb_etudiants']; ?> étudiants</span>
                                                <span><i class="fas fa-door-open"></i> <?php echo $examen['salle_nom']; ?> (<?php echo $examen['capacite']; ?> places)</span>
                                            </div>
                                        </div>
                                        <div class="examen-status">
                                            <span class="badge badge-<?php 
                                                echo $examen['statut'] == 'planifie' ? 'warning' : 
                                                     ($examen['statut'] == 'confirme' ? 'success' : 'danger'); 
                                            ?>">
                                                <?php echo ucfirst($examen['statut']); ?>
                                            </span>
                                            <div class="examen-date">
                                                <i class="fas fa-calendar-day"></i>
                                                <?php echo formaterDateTime($examen['date_heure'], 'd/m/Y H:i'); ?>
                                            </div>
                                            <div class="examen-duree">
                                                <i class="fas fa-clock"></i>
                                                <?php echo $examen['duree_minutes']; ?> minutes
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="examen-actions">
                                        <?php if ($examen['statut'] == 'planifie'): ?>
                                            <form method="POST" class="inline-form">
                                                <input type="hidden" name="examen_id" value="<?php echo $examen['id']; ?>">
                                                <button type="submit" name="confirmer_examen" 
                                                        class="btn btn-success" 
                                                        onclick="return confirm('Confirmer votre participation à cet examen ?')">
                                                    <i class="fas fa-check"></i> Confirmer
                                                </button>
                                            </form>
                                            
                                            <button class="btn btn-warning" 
                                                    onclick="signalerProblemeModal(<?php echo $examen['id']; ?>)">
                                                <i class="fas fa-exclamation-triangle"></i> Problème
                                            </button>
                                        <?php endif; ?>
                                        
                                        <button class="btn btn-info" 
                                                onclick="voirDetailsExamen(<?php echo $examen['id']; ?>)">
                                            <i class="fas fa-info-circle"></i> Détails
                                        </button>
                                        
                                        <?php if ($examen['date_heure'] >= date('Y-m-d H:i:s')): ?>
                                            <button class="btn btn-primary" 
                                                    onclick="telechargerListeEtudiants(<?php echo $examen['id']; ?>)">
                                                <i class="fas fa-download"></i> Liste étudiants
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Pagination -->
                        <div class="pagination">
                            <span>Total : <?php echo count($examens); ?> examens</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
    
    <?php include '../includes/footer.php'; ?>
    
    <!-- Modal Signalement Problème -->
    <div class="modal" id="problemeModal">
        <div class="modal-overlay" data-dismiss="modal"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Signaler un Problème</h3>
                <button class="modal-close" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST" id="problemeForm">
                <input type="hidden" name="examen_id" id="examen_id_probleme">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Description du problème *</label>
                        <textarea name="probleme" class="form-control" rows="4" required 
                                  placeholder="Décrivez le problème rencontré..."></textarea>
                    </div>
                    <div class="form-group">
                        <small class="text-muted">Votre signalement sera transmis à l'administration.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Annuler</button>
                    <button type="submit" name="signaler_probleme" class="btn btn-warning">
                        <i class="fas fa-paper-plane"></i> Envoyer
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
    function signalerProblemeModal(examenId) {
        document.getElementById('examen_id_probleme').value = examenId;
        document.getElementById('problemeModal').classList.add('show');
    }
    
    function voirDetailsExamen(examenId) {
        // Redirection vers une page de détails ou ouverture de modal
        window.location.href = 'details_examen.php?id=' + examenId;
    }
    
    function telechargerListeEtudiants(examenId) {
        if (confirm('Télécharger la liste des étudiants pour cet examen ?')) {
            // Simulation de téléchargement
            alert('Génération de la liste en cours...');
            // Ici, ajouter un appel AJAX pour générer le PDF
        }
    }
    
    function exporterExamens() {
        const format = prompt('Format d\'export (Excel, PDF, CSV) :', 'Excel');
        if (format) {
            alert('Export de vos examens en ' + format + ' en cours...');
            // Ici, ajouter un appel AJAX pour exporter
        }
    }
    
    // Gestion des modales
    document.addEventListener('DOMContentLoaded', function() {
        // Fermer modales
        document.querySelectorAll('[data-dismiss="modal"]').forEach(btn => {
            btn.addEventListener('click', function() {
                this.closest('.modal').classList.remove('show');
            });
        });
        
        // Fermer en cliquant en dehors
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', function() {
                this.closest('.modal').classList.remove('show');
            });
        });
    });
    </script>
    
    <style>
    .examens-list {
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
    }
    
    .examen-card {
        background-color: #f8f9fa;
        border-radius: var(--border-radius);
        padding: 1.5rem;
        border: 1px solid #eee;
        border-left: 4px solid;
    }
    
    .examen-planifie {
        border-left-color: #ffc107;
    }
    
    .examen-confirme {
        border-left-color: #28a745;
    }
    
    .examen-annule {
        border-left-color: #dc3545;
    }
    
    .examen-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 1rem;
    }
    
    .examen-info h4 {
        margin: 0 0 0.5rem 0;
        font-size: 1.2rem;
        color: var(--primary-color);
    }
    
    .examen-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
        font-size: 0.9rem;
        color: #666;
    }
    
    .examen-meta span {
        display: flex;
        align-items: center;
        gap: 5px;
    }
    
    .examen-status {
        text-align: right;
        min-width: 150px;
    }
    
    .examen-date, .examen-duree {
        display: flex;
        align-items: center;
        gap: 5px;
        font-size: 0.9rem;
        margin-top: 5px;
        color: #666;
        justify-content: flex-end;
    }
    
    .examen-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        padding-top: 1rem;
        border-top: 1px solid #eee;
    }
    
    .pagination {
        margin-top: 2rem;
        padding-top: 1rem;
        border-top: 1px solid #eee;
        text-align: center;
        color: #666;
    }
    
    .inline-form {
        display: inline-block;
    }
    </style>
</body>
</html>