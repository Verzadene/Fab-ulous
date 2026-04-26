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

$myID = (int)$_SESSION['user']['id'];

// ── GET: list or count ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'list';

    if ($action === 'count') {
        $st = $conn->prepare(
            "SELECT COUNT(*) AS c FROM notifications WHERE userID = ? AND is_read = 0"
        );
        $st->bind_param("i", $myID);
        $st->execute();
        $count = (int)$st->get_result()->fetch_assoc()['c'];
        $st->close();
        $conn->close();
        echo json_encode(['success' => true, 'count' => $count]);
        exit;
    }

    // Default: list recent notifications
    $st = $conn->prepare(
        "SELECT n.notifID, n.type, n.post_id, n.ref_id, n.is_read, n.created_at,
                a.username AS actor_username, a.first_name, a.last_name
         FROM notifications n
         JOIN accounts a ON n.actor_id = a.id
         WHERE n.userID = ?
         ORDER BY n.created_at DESC
         LIMIT 20"
    );
    $st->bind_param("i", $myID);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    $conn->close();

    // Build human-readable messages
    foreach ($rows as &$r) {
        $actor = htmlspecialchars($r['first_name'] . ' ' . $r['last_name']);
        switch ($r['type']) {
            case 'like':
                $r['message'] = "$actor liked your post.";
                break;
            case 'comment':
                $r['message'] = "$actor commented on your post.";
                break;
            case 'friend_request':
                $r['message'] = "$actor sent you a friend request.";
                break;
            case 'friend_accepted':
                $r['message'] = "$actor accepted your friend request.";
                break;
            default:
                $r['message'] = "$actor did something.";
        }
    }
    unset($r);

    echo json_encode(['success' => true, 'notifications' => $rows]);
    exit;
}

// ── POST: mark read ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_read') {
        $notifID = (int)($_POST['notif_id'] ?? 0);
        if ($notifID) {
            $st = $conn->prepare(
                "UPDATE notifications SET is_read = 1 WHERE notifID = ? AND userID = ?"
            );
            $st->bind_param("ii", $notifID, $myID);
            $st->execute();
            $st->close();
        } else {
            // Mark ALL as read
            $st = $conn->prepare(
                "UPDATE notifications SET is_read = 1 WHERE userID = ?"
            );
            $st->bind_param("i", $myID);
            $st->execute();
            $st->close();
        }
        $conn->close();
        echo json_encode(['success' => true]);
        exit;
    }
}

$conn->close();
echo json_encode(['success' => false, 'error' => 'Invalid request']);
?>
