<?php
// includes/mark_notification_read.php
require_once 'config.php';

$data = json_decode(file_get_contents('php://input'), true);
$notification_id = $data['id'] ?? 0;

if ($notification_id > 0) {
    $stmt = $pdo->prepare("UPDATE notifications SET lue = 1 WHERE id = ?");
    $stmt->execute([$notification_id]);
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false]);
}