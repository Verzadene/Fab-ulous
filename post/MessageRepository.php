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
}
?>