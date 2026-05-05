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

$userID = (int)$_SESSION['user']['id'];

// ── GET: fetch comments ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get') {
    $postID = (int)($_GET['post_id'] ?? 0);
    $comments = $repo->getComments($postID);
    $conn->close();
    
    echo json_encode(['status' => 'success', 'data' => ['comments' => $comments]]);
    exit;
}

// ── POST: add comment ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postID  = (int)($_POST['post_id'] ?? 0);
    $content = trim($_POST['content'] ?? '');

    if (!$postID || empty($content)) {
        echo json_encode(['status' => 'error', 'message' => 'Missing data']);
        $conn->close();
        exit;
    }

    $content = mb_substr($content, 0, 500);

    $postOwnerID = $repo->getPostOwner($postID);

    $ok = $repo->addComment($postID, $userID, $content);

    if ($ok && $postOwnerID && $postOwnerID !== $userID) {
        $repo->addNotification($postOwnerID, $userID, 'comment', $postID);
    }

    $conn->close();
    if ($ok) {
        echo json_encode(['status' => 'success', 'data' => []]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to add comment']);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
?>
