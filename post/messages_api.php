<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/MessageRepository.php';

header('Content-Type: application/json');

if (empty($_SESSION['user']) || empty($_SESSION['mfa_verified'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in.']);
    exit;
}

$conn = db_connect();
$msgRepo = new MessageRepository($conn);
$userId = (int) $_SESSION['user']['id'];

$schema = $msgRepo->getMessagesSchema();
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
    if (!$msgRepo->checkUserExists($friendId)) {
        $conn->close();
        echo json_encode(['success' => false, 'error' => 'That account does not exist.']);
        exit;
    }

    $messageColumn = $schema['message_column'];
    $timeColumn = $schema['time_column'];
    
    $rows = $msgRepo->getConversation($userId, $friendId, $messageColumn, $timeColumn);
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
    if (!$msgRepo->checkUserExists($friendId)) {
        $conn->close();
        echo json_encode(['success' => false, 'error' => 'That account does not exist.']);
        exit;
    }

    $message = mb_substr($message, 0, 1000);
    $messageColumn = $schema['message_column'];
    
    $success = $msgRepo->sendMessage($userId, $friendId, $message, $messageColumn);

    if ($success) {
        create_notification($conn, $friendId, $userId, 'message', null, $userId);
    }

    $conn->close();

    echo json_encode(['success' => $success]);
    exit;
}

$conn->close();
echo json_encode(['success' => false, 'error' => 'Unsupported request method.']);
