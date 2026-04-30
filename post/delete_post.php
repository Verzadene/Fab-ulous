<?php
session_start();
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if (empty($_SESSION['user']) || empty($_SESSION['mfa_verified'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit;
}

$userId = (int) $_SESSION['user']['id'];
$postId = (int) ($_POST['post_id'] ?? 0);

if (!$postId) {
    echo json_encode(['success' => false, 'error' => 'Missing post ID.']);
    exit;
}

$conn = db_connect();

$stmt = $conn->prepare('DELETE FROM posts WHERE postID = ? AND userID = ?');
$stmt->bind_param('ii', $postId, $userId);
$ok = $stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if ($ok && $affected > 0) {
    $actorUsername = $_SESSION['user']['username'];
    $action = "User {$actorUsername} deleted their post #{$postId}";
    $log = $conn->prepare(
        "INSERT INTO audit_log (admin_id, admin_username, action, target_type, target_id, visibility_role)
         VALUES (?, ?, ?, 'post', ?, 'admin')"
    );
    if ($log) {
        $log->bind_param('issi', $userId, $actorUsername, $action, $postId);
        $log->execute();
        $log->close();
    }
}

$conn->close();

if (!$ok || $affected === 0) {
    echo json_encode(['success' => false, 'error' => 'Post not found or not yours.']);
    exit;
}

echo json_encode(['success' => true]);
