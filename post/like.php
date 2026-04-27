<?php
session_start();
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if (empty($_SESSION['user']) || empty($_SESSION['mfa_verified'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$conn = db_connect();

$postID = (int)($_POST['post_id'] ?? 0);
$userID = (int)$_SESSION['user']['id'];

if (!$postID) {
    echo json_encode(['success' => false, 'error' => 'Invalid post']);
    exit;
}

// Get post owner (needed for notification)
$ownerStmt = $conn->prepare("SELECT userID FROM posts WHERE postID = ?");
$ownerStmt->bind_param("i", $postID);
$ownerStmt->execute();
$ownerRow = $ownerStmt->get_result()->fetch_assoc();
$ownerStmt->close();

if (!$ownerRow) {
    echo json_encode(['success' => false, 'error' => 'Post not found']);
    $conn->close(); exit;
}
$postOwnerID = (int)$ownerRow['userID'];

// Toggle like
$check = $conn->prepare("SELECT likeID FROM likes WHERE postID = ? AND userID = ?");
$check->bind_param("ii", $postID, $userID);
$check->execute();
$check->store_result();
$alreadyLiked = $check->num_rows > 0;
$check->close();

if ($alreadyLiked) {
    $del = $conn->prepare("DELETE FROM likes WHERE postID = ? AND userID = ?");
    $del->bind_param("ii", $postID, $userID);
    $del->execute();
    $del->close();
    $liked = false;
} else {
    $ins = $conn->prepare("INSERT INTO likes (postID, userID) VALUES (?, ?)");
    $ins->bind_param("ii", $postID, $userID);
    $ins->execute();
    $ins->close();
    $liked = true;

    // Notify post owner (not when liking own post, not duplicate if already notified)
    if ($postOwnerID !== $userID) {
        $notif = $conn->prepare(
            "INSERT INTO notifications (userID, actor_id, type, post_id)
             SELECT ?, ?, 'like', ?
             WHERE NOT EXISTS (
                 SELECT 1 FROM notifications
                 WHERE userID = ? AND actor_id = ? AND type = 'like' AND post_id = ?
             )"
        );
        if ($notif) {
            $notif->bind_param("iiiiii", $postOwnerID, $userID, $postID,
                                         $postOwnerID, $userID, $postID);
            $notif->execute();
            $notif->close();
        }
    }
}

$cntStmt = $conn->prepare("SELECT COUNT(*) AS c FROM likes WHERE postID = ?");
$cntStmt->bind_param("i", $postID);
$cntStmt->execute();
$count = (int)$cntStmt->get_result()->fetch_assoc()['c'];
$cntStmt->close();
$conn->close();

echo json_encode(['success' => true, 'liked' => $liked, 'like_count' => $count]);
?>
