<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/NotificationRepository.php';
header('Content-Type: application/json');

if (empty($_SESSION['user']) || empty($_SESSION['mfa_verified'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$repo = new NotificationRepository('db_connect');

$myID = (int)$_SESSION['user']['id'];

// ── GET: list or count ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'list';

    if ($action === 'count') {
        $count = $repo->getUnreadCount($myID);
        echo json_encode(['success' => true, 'count' => $count]);
        exit;
    }

    // Default: list unread notifications. Read items are hidden from the drawer
    // so the checkmark action visibly clears the current notification list.
    $rows = $repo->getUnreadNotifications($myID);

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
            case 'commission_submitted':
                $r['message'] = "$actor submitted a new commission request.";
                break;
            case 'commission_approved':
                $r['message'] = "$actor approved your commission request.";
                break;
            case 'commission_updated':
                $r['message'] = "$actor updated your commission request.";
                break;
            case 'commission_paid':
                if ((int) ($r['actor_id'] ?? 0) === $myID) {
                    $r['message'] = "Your commission payment was received.";
                } else {
                    $r['message'] = "$actor paid for a commission.";
                }
                break;
            case 'message':
                $r['message'] = "$actor sent you a message.";
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
        $repo->markAsRead($myID, $notifID);
        echo json_encode(['success' => true]);
        exit;
    }
}

echo json_encode(['success' => false, 'error' => 'Invalid request']);
?>
