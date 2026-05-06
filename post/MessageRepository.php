<?php

class MessageRepository {
    private mysqli $conn;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    /**
     * Fetches all contacts for the messaging directory.
     * Friends are ordered first if friendships are enabled.
     */
    public function getContacts(int $userId, bool $hasFriendships): array {
        if ($hasFriendships) {
            $contStmt = $this->conn->prepare(
                "SELECT a.id,
                        CONCAT(a.first_name, ' ', a.last_name) AS name,
                        a.username,
                        a.profile_pic,
                        a.bio,
                        COALESCE((
                            SELECT status FROM friendships
                            WHERE (requesterID = ? AND receiverID = a.id)
                               OR (receiverID = ? AND requesterID = a.id)
                            LIMIT 1
                        ), 'none') AS friend_status
                 FROM accounts a
                 WHERE a.id != ? AND a.banned = 0
                 ORDER BY
                     CASE WHEN EXISTS(
                         SELECT 1 FROM friendships
                         WHERE status = 'accepted'
                           AND ((requesterID = ? AND receiverID = a.id)
                             OR (receiverID = ? AND requesterID = a.id))
                     ) THEN 0 ELSE 1 END,
                     a.username ASC"
            );
            $contStmt->bind_param('iiiii', $userId, $userId, $userId, $userId, $userId);
        } else {
            $contStmt = $this->conn->prepare(
                "SELECT a.id,
                        CONCAT(a.first_name, ' ', a.last_name) AS name,
                        a.username,
                        a.profile_pic,
                        a.bio,
                        'none' AS friend_status
                 FROM accounts a
                 WHERE a.id != ? AND a.banned = 0
                 ORDER BY a.username ASC"
            );
            $contStmt->bind_param('i', $userId);
        }
        
        $contStmt->execute();
        $contacts = $contStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $contStmt->close();
        
        return $contacts;
    }

    public function getMessagesSchema(): array {
        static $schema = null;
        if ($schema !== null) {
            return $schema;
        }

        if (!(bool) $this->conn->query("SHOW TABLES LIKE 'messages'")->num_rows) {
            $schema = ['ready' => false, 'error' => 'The messages table does not exist yet.'];
            return $schema;
        }

        $columns = [];
        $result = $this->conn->query('SHOW COLUMNS FROM messages');
        while ($row = $result->fetch_assoc()) {
            $columns[$row['Field']] = true;
        }

        $messageColumn = isset($columns['message_text']) ? 'message_text' : (isset($columns['content']) ? 'content' : null);
        $timeColumn = isset($columns['created_at']) ? 'created_at' : (isset($columns['timestamp']) ? 'timestamp' : null);

        if (!$messageColumn || !$timeColumn || !isset($columns['senderID']) || !isset($columns['receiverID'])) {
            $schema = ['ready' => false, 'error' => 'The messages table is missing one or more expected columns.'];
            return $schema;
        }

        $schema = ['ready' => true, 'message_column' => $messageColumn, 'time_column' => $timeColumn];
        return $schema;
    }

    public function checkUserExists(int $userId): bool {
        $chk = $this->conn->prepare("SELECT id FROM accounts WHERE id = ? AND banned = 0 LIMIT 1");
        $chk->bind_param('i', $userId);
        $chk->execute();
        $chk->store_result();
        $exists = $chk->num_rows > 0;
        $chk->close();
        return $exists;
    }

    public function getConversation(int $userId, int $friendId, string $messageColumn, string $timeColumn, int $limit = 150): array {
        $sql = "SELECT m.senderID, m.receiverID, m.`{$messageColumn}` AS message_text, m.`{$timeColumn}` AS sent_at,
                       CONCAT(a.first_name, ' ', a.last_name) AS sender_name
                FROM messages m JOIN accounts a ON a.id = m.senderID
                WHERE (m.senderID = ? AND m.receiverID = ?) OR (m.senderID = ? AND m.receiverID = ?)
                ORDER BY m.`{$timeColumn}` ASC LIMIT ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('iiiii', $userId, $friendId, $friendId, $userId, $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function sendMessage(int $senderId, int $receiverId, string $message, string $messageColumn): bool {
        $stmt = $this->conn->prepare("INSERT INTO messages (senderID, receiverID, `{$messageColumn}`) VALUES (?, ?, ?)");
        $stmt->bind_param('iis', $senderId, $receiverId, $message);
        $success = $stmt->execute();
        $stmt->close();
        return $success;
    }

    public function processGetConversation(int $userId, int $friendId, array $schema): array {
        if (!$this->checkUserExists($friendId)) {
            return ['success' => false, 'error' => 'That account does not exist.'];
        }

        $messageColumn = $schema['message_column'];
        $timeColumn = $schema['time_column'];
        
        $rows = $this->getConversation($userId, $friendId, $messageColumn, $timeColumn);

        $messages = array_map(static function (array $row) use ($userId): array {
            return [
                'message_text' => $row['message_text'],
                'sender_name' => $row['sender_name'],
                'sent_at' => date('M d, Y H:i', strtotime($row['sent_at'])),
                'is_mine' => (int) $row['senderID'] === $userId,
            ];
        }, $rows);

        return ['success' => true, 'messages' => $messages];
    }

    public function processSendMessage(int $userId, int $friendId, string $message, array $schema): array {
        if (!$friendId || $message === '') {
            return ['success' => false, 'error' => 'Message data is incomplete.'];
        }

        if (!$this->checkUserExists($friendId)) {
            return ['success' => false, 'error' => 'That account does not exist.'];
        }

        $message = mb_substr($message, 0, 1000);
        $success = $this->sendMessage($userId, $friendId, $message, $schema['message_column']);

        if ($success) {
            create_notification($this->conn, $friendId, $userId, 'message', null, $userId);
        }

        return ['success' => $success];
    }
}
?>