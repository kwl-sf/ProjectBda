<?php
// chef_dept/view_planning_html.php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Vérifier que l'utilisateur est chef de département
require_role(['chef_dept']);

$user = get_logged_in_user();
$edt_id = $_GET['id'] ?? 0;

// Récupérer les informations du planning
$sql = "SELECT ve.*, 
               p.nom as admin_nom, p.prenom as admin_prenom,
               ec.html_file, ec.date_envoi, ec.date_limite
        FROM validations_edt ve
        JOIN envoyes_chefs ec ON ve.id = ec.edt_id
        JOIN professeurs p ON ve.valide_par = p.id
        WHERE ve.id = ? 
        AND ec.chef_id = ?
        AND ec.dept_id = ?";

$stmt = $pdo->prepare($sql);
$stmt->execute([$edt_id, $user['id'], $user['dept_id']]);
$planning = $stmt->fetch();

if (!$planning) {
    header('Location: validation.php');
    exit();
}

// Marquer comme vu si pas encore vu
if ($planning['statut'] === 'envoye') {
    $stmt = $pdo->prepare("UPDATE envoyes_chefs SET statut = 'vu' WHERE edt_id = ? AND chef_id = ?");
    $stmt->execute([$edt_id, $user['id']]);
}

// Lire le fichier HTML
$html_file = '../storage/planning_html/' . $planning['html_file'];
if (!file_exists($html_file)) {
    die('Fichier planning non trouvé.');
}

// Afficher le contenu HTML
$html_content = file_get_contents($html_file);

// Ajouter des boutons d'action
$html_content = str_replace('</body>', '
    <div style="position: fixed; bottom: 20px; right: 20px; display: flex; gap: 10px;">
        <button onclick="window.print()" style="padding: 10px 20px; background: #4361ee; color: white; border: none; border-radius: 5px; cursor: pointer;">
            <i class="fas fa-print"></i> Imprimer
        </button>
        <button onclick="window.location.href=\'validation.php\'" style="padding: 10px 20px; background: #2ecc71; color: white; border: none; border-radius: 5px; cursor: pointer;">
            <i class="fas fa-check"></i> Retour à la validation
        </button>
    </div>
    <script>
        // Ajouter les icônes FontAwesome
        var link = document.createElement("link");
        link.rel = "stylesheet";
        link.href = "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css";
        document.head.appendChild(link);
    </script>
</body>', $html_content);

echo $html_content;
?>