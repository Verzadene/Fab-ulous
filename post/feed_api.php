<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/PostRepository.php';

header('Content-Type: application/json');

if (empty($_SESSION['user']) || empty($_SESSION['mfa_verified'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    exit;
}

$conn = db_connect();
$userID = (int)$_SESSION['user']['id'];
$hasFriendships = (bool)$conn->query("SHOW TABLES LIKE 'friendships'")->num_rows;

$postRepo = new PostRepository($conn);
$posts = $postRepo->getFeed($userID, $hasFriendships);

$conn->close();

echo json_encode(['status' => 'success', 'data' => ['posts' => $posts]]);
?>