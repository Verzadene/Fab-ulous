<?php
session_start();
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if (empty($_SESSION['user']) || empty($_SESSION['mfa_verified'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$conn = db_connect();

$myID  = (int)$_SESSION['user']['id'];
$myUsername = $_SESSION['user']['username'];

// ── GET: check friendship status with a user ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $targetID = (int)($_GET['user_id'] ?? 0);
    if (!$targetID || $targetID === $myID) {
        echo json_encode(['success' => false, 'error' => 'Invalid user']);
        exit;
    }

    $st = $conn->prepare(
        "SELECT friendshipID, status, requesterID FROM friendships
         WHERE (requesterID = ? AND receiverID = ?)
            OR (receiverID = ? AND requesterID = ?)
         LIMIT 1"
    );
    $st->bind_param("iiii", $myID, $targetID, $myID, $targetID);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
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
            exit;
        }

        // Check not already pending/accepted
        $chk = $conn->prepare(
            "SELECT friendshipID FROM friendships
             WHERE (requesterID = ? AND receiverID = ?)
                OR (receiverID = ? AND requesterID = ?)
             LIMIT 1"
        );
        $chk->bind_param("iiii", $myID, $receiverID, $myID, $receiverID);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $chk->close();
            echo json_encode(['success' => false, 'error' => 'Request already exists']);
            exit;
        }
        $chk->close();

        $ins = $conn->prepare(
            "INSERT INTO friendships (requesterID, receiverID, status) VALUES (?, ?, 'pending')"
        );
        $ins->bind_param("ii", $myID, $receiverID);
        if (!$ins->execute()) {
            echo json_encode(['success' => false, 'error' => 'Insert failed']);
            $ins->close(); $conn->close(); exit;
        }
        $friendshipID = $conn->insert_id;
        $ins->close();

        // Emit notification to receiver
        $notif = $conn->prepare(
            "INSERT INTO notifications (userID, actor_id, type, ref_id) VALUES (?, ?, 'friend_request', ?)"
        );
        if ($notif) {
            $notif->bind_param("iii", $receiverID, $myID, $friendshipID);
            $notif->execute();
            $notif->close();
        }

        $conn->close();
        echo json_encode(['success' => true, 'friendshipID' => $friendshipID]);
        exit;
    }

    // Accept a friend request
    if ($action === 'accept') {
        $friendshipID = (int)($_POST['friendship_id'] ?? 0);
        if (!$friendshipID) {
            echo json_encode(['success' => false, 'error' => 'Invalid ID']);
            exit;
        }

        // Verify the current user is the receiver
        $chk = $conn->prepare(
            "SELECT requesterID FROM friendships
             WHERE friendshipID = ? AND receiverID = ? AND status = 'pending'"
        );
        $chk->bind_param("ii", $friendshipID, $myID);
        $chk->execute();
        $row = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'Request not found']);
            $conn->close(); exit;
        }
        $requesterID = (int)$row['requesterID'];

        $upd = $conn->prepare(
            "UPDATE friendships SET status = 'accepted' WHERE friendshipID = ?"
        );
        $upd->bind_param("i", $friendshipID);
        $upd->execute();
        $upd->close();

        // Mark the original friend_request notification as read
        $markRead = $conn->prepare(
            "UPDATE notifications SET is_read = 1
             WHERE userID = ? AND actor_id = ? AND type = 'friend_request' AND ref_id = ?"
        );
        if ($markRead) {
            $markRead->bind_param("iii", $myID, $requesterID, $friendshipID);
            $markRead->execute();
            $markRead->close();
        }

        // Notify the original requester that request was accepted
        $notif = $conn->prepare(
            "INSERT INTO notifications (userID, actor_id, type, ref_id) VALUES (?, ?, 'friend_accepted', ?)"
        );
        if ($notif) {
            $notif->bind_param("iii", $requesterID, $myID, $friendshipID);
            $notif->execute();
            $notif->close();
        }

        $conn->close();
        echo json_encode(['success' => true]);
        exit;
    }

    // Reject / cancel / remove a friendship (deletes the record so either side can resend)
    if ($action === 'reject' || $action === 'cancel' || $action === 'remove') {
        $friendshipID = (int)($_POST['friendship_id'] ?? 0);
        if (!$friendshipID) {
            echo json_encode(['success' => false, 'error' => 'Invalid ID']);
            exit;
        }

        // Allow both requester (cancel) and receiver (reject) to delete
        $del = $conn->prepare(
            "DELETE FROM friendships
             WHERE friendshipID = ?
               AND (requesterID = ? OR receiverID = ?)"
        );
        $del->bind_param("iii", $friendshipID, $myID, $myID);
        $del->execute();
        $del->close();

        // Clean up associated notifications
        $delNotif = $conn->prepare(
            "DELETE FROM notifications WHERE ref_id = ? AND type IN ('friend_request','friend_accepted')"
        );
        if ($delNotif) {
            $delNotif->bind_param("i", $friendshipID);
            $delNotif->execute();
            $delNotif->close();
        }

        $conn->close();
        echo json_encode(['success' => true]);
        exit;
    }
}

echo json_encode(['success' => false, 'error' => 'Invalid request']);
?>
