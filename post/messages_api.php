<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/MessageRepository.php';

header('Content-Type: application/json');

if (empty($_SESSION['user']) || empty($_SESSION['mfa_verified'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in.']);
    exit;
}

$msgRepo = new MessageRepository('db_connect');
$userId = (int) $_SESSION['user']['id'];

$schema = $msgRepo->getMessagesSchema();
if (!$schema['ready']) {
    echo json_encode(['success' => false, 'error' => $schema['error'], 'unavailable' => true]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'conversation';
    $friendId = (int) ($_GET['friend_id'] ?? 0);

    if ($action !== 'conversation' || !$friendId) {
        echo json_encode(['success' => false, 'error' => 'Invalid request.']);
        exit;
    }

    $result = $msgRepo->processGetConversation($userId, $friendId, $schema);
    
    echo json_encode($result);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $friendId = (int) ($_POST['friend_id'] ?? 0);
    $message = trim($_POST['message_text'] ?? '');

    if ($action !== 'send') {
        echo json_encode(['success' => false, 'error' => 'Invalid request.']);
        exit;
    }

    $result = $msgRepo->processSendMessage($userId, $friendId, $message, $schema);
    
    echo json_encode($result);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unsupported request method.']);
