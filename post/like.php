<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/InteractionRepository.php';
header('Content-Type: application/json');

if (empty($_SESSION['user']) || empty($_SESSION['mfa_verified'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    exit;
}

$conn = db_connect();
$repo = new InteractionRepository($conn);

$postID = (int)($_POST['post_id'] ?? 0);
$userID = (int)$_SESSION['user']['id'];

if (!$postID) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid post']);
    $conn->close();
    exit;
}

$postOwnerID = $repo->getPostOwner($postID);

if (!$postOwnerID) {
    echo json_encode(['status' => 'error', 'message' => 'Post not found']);
    $conn->close(); exit;
}

$result = $repo->processLike($postID, $userID);
$conn->close();

echo json_encode([
    'status' => 'success',
    'data' => $result
]);
?>
