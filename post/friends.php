<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/FriendRepository.php';
header('Content-Type: application/json');

if (empty($_SESSION['user']) || empty($_SESSION['mfa_verified'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$conn = db_connect();
$repo = new FriendRepository($conn);

$myID  = (int)$_SESSION['user']['id'];
$myUsername = $_SESSION['user']['username'];

// ── GET: check friendship status with a user ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    if ($action === 'list') {
        $directory = $repo->getFriendDirectory($myID);
        $conn->close();
        echo json_encode(['success' => true, 'directory' => $directory]);
        exit;
    }

    $targetID = (int)($_GET['user_id'] ?? 0);
    if (!$targetID || $targetID === $myID) {
        echo json_encode(['success' => false, 'error' => 'Invalid user']);
        $conn->close();
        exit;
    }

    $row = $repo->getFriendshipStatus($myID, $targetID);
    $conn->close();

    if (!$row) {
        echo json_encode(['success' => true, 'status' => 'none']);
    } else {
        echo json_encode([
            'success'       => true,
            'status'        => $row['status'],
            'friendshipID'  => $row['friendshipID'],
            'i_requested'   => ((int)$row['requesterID'] === $myID),
        ]);
    }
    exit;
}

// ── POST actions ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Send a friend request
    if ($action === 'send') {
        $receiverID = (int)($_POST['receiver_id'] ?? 0);
        if (!$receiverID || $receiverID === $myID) {
            echo json_encode(['success' => false, 'error' => 'Invalid receiver']);
            $conn->close();
            exit;
        }

        if ($repo->getFriendshipStatus($myID, $receiverID)) {
            echo json_encode(['success' => false, 'error' => 'Request already exists']);
            $conn->close();
            exit;
        }

        $friendshipID = $repo->createFriendRequest($myID, $receiverID);
        $conn->close();

        if ($friendshipID) {
            echo json_encode(['success' => true, 'friendshipID' => $friendshipID]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Insert failed']);
        }

        exit;
    }

    // Accept a friend request
    if ($action === 'accept') {
        $friendshipID = (int)($_POST['friendship_id'] ?? 0);
        if (!$friendshipID) {
            echo json_encode(['success' => false, 'error' => 'Invalid ID']);
            $conn->close();
            exit;
        }

        $requesterID = $repo->getPendingRequest($friendshipID, $myID);
        if (!$requesterID) {
            echo json_encode(['success' => false, 'error' => 'Request not found']);
            $conn->close(); exit;
        }

        $ok = $repo->acceptFriendRequest($friendshipID, $myID, $requesterID);
        $conn->close();
        echo json_encode(['success' => $ok]);
        exit;
    }

    // Reject / cancel / remove a friendship (deletes the record so either side can resend)
    if ($action === 'reject' || $action === 'cancel' || $action === 'remove') {
        $friendshipID = (int)($_POST['friendship_id'] ?? 0);
        if (!$friendshipID) {
            echo json_encode(['success' => false, 'error' => 'Invalid ID']);
            $conn->close();
            exit;
        }

        $repo->deleteFriendship($friendshipID, $myID);
        $conn->close();
        echo json_encode(['success' => true]);
        exit;
    }
}

$conn->close();
echo json_encode(['success' => false, 'error' => 'Invalid request']);
?>
