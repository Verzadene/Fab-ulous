<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$conn = new mysqli("localhost", "root", "", "fab_ulous");
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'DB error']);
    exit;
}

$userID = (int)$_SESSION['user']['id'];

// ── GET: fetch comments ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get') {
    $postID = (int)($_GET['post_id'] ?? 0);
    $cstmt  = $conn->prepare(
        "SELECT c.content, c.created_at, a.username
         FROM comments c JOIN accounts a ON c.userID = a.id
         WHERE c.postID = ?
         ORDER BY c.created_at ASC
         LIMIT 50"
    );
    $cstmt->bind_param("i", $postID);
    $cstmt->execute();
    $result   = $cstmt->get_result();
    $cstmt->close();
    $comments = [];
    if ($result) while ($r = $result->fetch_assoc()) $comments[] = $r;
    $conn->close();
    echo json_encode(['success' => true, 'comments' => $comments]);
    exit;
}

// ── POST: add comment ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postID  = (int)($_POST['post_id'] ?? 0);
    $content = trim($_POST['content'] ?? '');

    if (!$postID || empty($content)) {
        echo json_encode(['success' => false, 'error' => 'Missing data']);
        exit;
    }

    $content = mb_substr($content, 0, 500);

    // Get post owner for notification
    $ownerStmt = $conn->prepare("SELECT userID FROM posts WHERE postID = ?");
    $ownerStmt->bind_param("i", $postID);
    $ownerStmt->execute();
    $ownerRow = $ownerStmt->get_result()->fetch_assoc();
    $ownerStmt->close();
    $postOwnerID = $ownerRow ? (int)$ownerRow['userID'] : 0;

    $stmt = $conn->prepare("INSERT INTO comments (postID, userID, content) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $postID, $userID, $content);
    $ok = $stmt->execute();
    $stmt->close();

    // Notify post owner (skip for own posts)
    if ($ok && $postOwnerID && $postOwnerID !== $userID) {
        $notif = $conn->prepare(
            "INSERT INTO notifications (userID, actor_id, type, post_id) VALUES (?, ?, 'comment', ?)"
        );
        if ($notif) {
            $notif->bind_param("iii", $postOwnerID, $userID, $postID);
            $notif->execute();
            $notif->close();
        }
    }

    $conn->close();
    echo json_encode(['success' => $ok]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid request']);
?>
