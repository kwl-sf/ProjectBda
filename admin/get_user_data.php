<?php
// admin/get_user_data.php
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role(['admin']);

$id = intval($_GET['id']);
$type = $_GET['type'];

header('Content-Type: application/json');

try {
    if ($type === 'prof') {
        $stmt = $pdo->prepare("SELECT * FROM professeurs WHERE id = ?");
        $stmt->execute([$id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM etudiants WHERE id = ?");
        $stmt->execute([$id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    echo json_encode($data ?: []);
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>