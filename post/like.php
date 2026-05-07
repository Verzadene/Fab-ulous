<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/PostRepository.php'; // Use PostRepository for likes
header('Content-Type: application/json');

if (empty($_SESSION['user']) || empty($_SESSION['mfa_verified'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    exit;
}

$repo = new PostRepository('db_connect'); // Instantiate PostRepository

$postID = (int)($_POST['post_id'] ?? 0);
$userID = (int)$_SESSION['user']['id'];

if (!$postID) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid post']);
    exit;
}

$postOwnerID = $repo->getPostOwner($postID);

if (!$postOwnerID) {
    echo json_encode(['status' => 'error', 'message' => 'Post not found']);
    exit;
}

$result = $repo->processLike($postID, $userID);

echo json_encode([
    'status' => 'success',
    'data' => $result
]);
?>
