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

$userId  = (int) $_SESSION['user']['id'];
$postId  = (int) ($_POST['post_id'] ?? 0);
$caption = mb_substr(trim($_POST['caption'] ?? ''), 0, 2000);

if (!$postId || $caption === '') {
    echo json_encode(['success' => false, 'error' => 'Missing post data.']);
    exit;
}

$conn = db_connect();

$stmt = $conn->prepare('UPDATE posts SET caption = ? WHERE postID = ? AND userID = ?');
$stmt->bind_param('sii', $caption, $postId, $userId);
$ok = $stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();
$conn->close();

if (!$ok || $affected === 0) {
    echo json_encode(['success' => false, 'error' => 'Post not found or not yours.']);
    exit;
}

echo json_encode(['success' => true]);
