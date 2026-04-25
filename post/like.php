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

$postID = (int)($_POST['post_id'] ?? 0);
$userID = (int)$_SESSION['user']['id'];

if (!$postID) {
    echo json_encode(['success' => false, 'error' => 'Invalid post']);
    exit;
}

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
}

$cntStmt = $conn->prepare("SELECT COUNT(*) AS c FROM likes WHERE postID = ?");
$cntStmt->bind_param("i", $postID);
$cntStmt->execute();
$count = (int)$cntStmt->get_result()->fetch_assoc()['c'];
$cntStmt->close();
$conn->close();

echo json_encode(['success' => true, 'liked' => $liked, 'like_count' => $count]);
?>
