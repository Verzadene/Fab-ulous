<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/FriendRepository.php';
header('Content-Type: application/json');

if (empty($_SESSION['user']) || empty($_SESSION['mfa_verified'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$repo = new FriendRepository('db_connect');

$myID  = (int)$_SESSION['user']['id'];
$myUsername = $_SESSION['user']['username'];

// ── GET: check friendship status with a user ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    if ($action === 'list') {
        $directory = $repo->getFriendDirectory($myID);
        echo json_encode(['success' => true, 'directory' => $directory]);
        exit;
    }

    $targetID = (int)($_GET['user_id'] ?? 0);
    $result = $repo->processGetStatus($myID, $targetID);
    echo json_encode($result);
    exit;
}

// ── POST actions ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Send a friend request
    if ($action === 'send') {
        $receiverID = (int)($_POST['receiver_id'] ?? 0);
        $result = $repo->processSendRequest($myID, $receiverID);
        echo json_encode($result);
        exit;
    }

    // Accept a friend request
    if ($action === 'accept') {
        $friendshipID = (int)($_POST['friendship_id'] ?? 0);
        $result = $repo->processAcceptRequest($myID, $friendshipID);
        echo json_encode($result);
        exit;
    }

    // Reject / cancel / remove a friendship (deletes the record so either side can resend)
    if ($action === 'reject' || $action === 'cancel' || $action === 'remove') {
        $friendshipID = (int)($_POST['friendship_id'] ?? 0);
        $result = $repo->processRemoveFriendship($myID, $friendshipID);
        echo json_encode($result);
        exit;
    }
}

echo json_encode(['success' => false, 'error' => 'Invalid request']);
?>
