<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/PostRepository.php';

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
$actorUsername = $_SESSION['user']['username'];

if (!$postId) {
    echo json_encode(['success' => false, 'error' => 'Missing post ID.']);
    exit;
}

$conn = db_connect();
$repo = new PostRepository($conn);

$ok = $repo->processDeletePost($postId, $userId, $actorUsername);

$conn->close();

if (!$ok) {
    echo json_encode(['success' => false, 'error' => 'Post not found or not yours.']);
    exit;
}

echo json_encode(['success' => true]);
