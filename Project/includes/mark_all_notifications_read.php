<?php
// includes/mark_all_notifications_read.php
require_once 'config.php';

$data = json_decode(file_get_contents('php://input'), true);
$user_id = $data['user_id'] ?? 0;
$user_type = $data['user_type'] ?? '';

if ($user_id > 0 && $user_type) {
    $stmt = $pdo->prepare("UPDATE notifications SET lue = 1 WHERE destinataire_id = ? AND destinataire_type = ?");
    $stmt->execute([$user_id, $user_type]);
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false]);
}