<?php
session_start();
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if (empty($_SESSION['user']) || empty($_SESSION['mfa_verified'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in.']);
    exit;
}

$conn = db_connect();
$userId = (int) $_SESSION['user']['id'];

function messages_schema(mysqli $conn): array
{
    static $schema = null;

    if ($schema !== null) {
        return $schema;
    }

    if (!(bool) $conn->query("SHOW TABLES LIKE 'messages'")->num_rows) {
        $schema = ['ready' => false, 'error' => 'The messages table does not exist yet.'];
        return $schema;
    }

    $columns = [];
    $result = $conn->query('SHOW COLUMNS FROM messages');
    while ($row = $result->fetch_assoc()) {
        $columns[$row['Field']] = true;
    }

    $messageColumn = isset($columns['message_text']) ? 'message_text' : (isset($columns['content']) ? 'content' : null);
    $timeColumn = isset($columns['created_at']) ? 'created_at' : (isset($columns['timestamp']) ? 'timestamp' : null);

    if (!$messageColumn || !$timeColumn || !isset($columns['senderID']) || !isset($columns['receiverID'])) {
        $schema = ['ready' => false, 'error' => 'The messages table is missing one or more expected columns.'];
        return $schema;
    }

    $schema = [
        'ready' => true,
        'message_column' => $messageColumn,
        'time_column' => $timeColumn,
    ];

    return $schema;
}

function is_accepted_friend(mysqli $conn, int $myId, int $friendId): bool
{
    $stmt = $conn->prepare(
        "SELECT friendshipID
         FROM friendships
         WHERE status = 'accepted'
           AND ((requesterID = ? AND receiverID = ?) OR (requesterID = ? AND receiverID = ?))
         LIMIT 1"
    );
    $stmt->bind_param('iiii', $myId, $friendId, $friendId, $myId);
    $stmt->execute();
    $stmt->store_result();
    $accepted = $stmt->num_rows > 0;
    $stmt->close();
    return $accepted;
}

$schema = messages_schema($conn);
if (!$schema['ready']) {
    $conn->close();
    echo json_encode(['success' => false, 'error' => $schema['error'], 'unavailable' => true]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'conversation';
    $friendId = (int) ($_GET['friend_id'] ?? 0);

    if ($action !== 'conversation' || !$friendId) {
        $conn->close();
        echo json_encode(['success' => false, 'error' => 'Invalid request.']);
        exit;
    }

    // Verify target account exists and is not banned
    $chk = $conn->prepare("SELECT id FROM accounts WHERE id = ? AND banned = 0 LIMIT 1");
    $chk->bind_param('i', $friendId);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows === 0) {
        $chk->close();
        $conn->close();
        echo json_encode(['success' => false, 'error' => 'That account does not exist.']);
        exit;
    }
    $chk->close();

    $messageColumn = $schema['message_column'];
    $timeColumn = $schema['time_column'];
    $sql = "
        SELECT m.senderID,
               m.receiverID,
               m.`{$messageColumn}` AS message_text,
               m.`{$timeColumn}` AS sent_at,
               CONCAT(a.first_name, ' ', a.last_name) AS sender_name
        FROM messages m
        JOIN accounts a ON a.id = m.senderID
        WHERE (m.senderID = ? AND m.receiverID = ?)
           OR (m.senderID = ? AND m.receiverID = ?)
        ORDER BY m.`{$timeColumn}` ASC
        LIMIT 150
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iiii', $userId, $friendId, $friendId, $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();

    $messages = array_map(static function (array $row) use ($userId): array {
        return [
            'message_text' => $row['message_text'],
            'sender_name' => $row['sender_name'],
            'sent_at' => date('M d, Y H:i', strtotime($row['sent_at'])),
            'is_mine' => (int) $row['senderID'] === $userId,
        ];
    }, $rows);

    echo json_encode(['success' => true, 'messages' => $messages]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $friendId = (int) ($_POST['friend_id'] ?? 0);
    $message = trim($_POST['message_text'] ?? '');

    if ($action !== 'send' || !$friendId || $message === '') {
        $conn->close();
        echo json_encode(['success' => false, 'error' => 'Message data is incomplete.']);
        exit;
    }

    // Verify target account exists and is not banned
    $chk = $conn->prepare("SELECT id FROM accounts WHERE id = ? AND banned = 0 LIMIT 1");
    $chk->bind_param('i', $friendId);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows === 0) {
        $chk->close();
        $conn->close();
        echo json_encode(['success' => false, 'error' => 'That account does not exist.']);
        exit;
    }
    $chk->close();

    $message = mb_substr($message, 0, 1000);
    $messageColumn = $schema['message_column'];
    $sql = "INSERT INTO messages (senderID, receiverID, `{$messageColumn}`) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iis', $userId, $friendId, $message);
    $success = $stmt->execute();
    $stmt->close();
    $conn->close();

    echo json_encode(['success' => $success]);
    exit;
}

$conn->close();
echo json_encode(['success' => false, 'error' => 'Unsupported request method.']);
