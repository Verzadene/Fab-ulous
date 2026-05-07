<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/PostRepository.php';
header('Content-Type: application/json');

if (empty($_SESSION['user']) || empty($_SESSION['mfa_verified'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    exit;
}

$repo = new PostRepository('db_connect');

$userID = (int)$_SESSION['user']['id'];

// ── GET: fetch comments ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get') {
    $postID = (int)($_GET['post_id'] ?? 0);
    $comments = $repo->getComments($postID);
    
    echo json_encode(['status' => 'success', 'data' => ['comments' => $comments]]);
    exit;
}

// ── POST: add comment ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';

    if ($action === 'delete') {
        $commentID = (int)($_POST['comment_id'] ?? 0);
        if (!$commentID) {
            echo json_encode(['status' => 'error', 'message' => 'Missing data']);
            exit;
        }
        $ok = $repo->deleteComment($commentID, $userID);
        echo json_encode(['status' => $ok ? 'success' : 'error']);
        exit;
    }

    if ($action === 'edit') {
        $commentID = (int)($_POST['comment_id'] ?? 0);
        $content = trim($_POST['content'] ?? '');
        if (!$commentID || empty($content)) {
            echo json_encode(['status' => 'error', 'message' => 'Missing data']);
            exit;
        }
        $content = mb_substr($content, 0, 500);
        $ok = $repo->editComment($commentID, $userID, $content);
        echo json_encode(['status' => $ok ? 'success' : 'error']);
        exit;
    }

    $postID  = (int)($_POST['post_id'] ?? 0);
    $content = trim($_POST['content'] ?? '');

    if (!$postID || empty($content)) {
        echo json_encode(['status' => 'error', 'message' => 'Missing data']);
        exit;
    }

    $content = mb_substr($content, 0, 500);

    $ok = $repo->addComment($postID, $userID, $content);

    if ($ok) {
        echo json_encode(['status' => 'success', 'data' => []]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to add comment']);
    }
    exit;
}

?>
