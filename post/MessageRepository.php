<?php
/**
 * MessageRepository — all database logic for direct messages and the contacts directory.
 *
 * Schema reference (fab_ulous_messages.messages):
 *   - senderID   (formerly sender_id)
 *   - receiverID (formerly receiver_id)
 *   - message_text
 *
 * Cross-database rule:
 *   Contacts and messages live in different databases. All cross-domain reads use
 *   application-level aggregation (two queries + PHP merge). Single-domain writes
 *   use plain table names on the already-selected database.
 */
class MessageRepository {
    private $dbConnectFactory;

    public function __construct(callable $dbConnectFactory)
    {
        $this->dbConnectFactory = $dbConnectFactory;
    }

    private function getConnection(string $domain): mysqli
    {
        return call_user_func($this->dbConnectFactory, $domain);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Schema introspection
    // ──────────────────────────────────────────────────────────────────────────

    public function getMessagesSchema(): array {
        static $schema = null;
        if ($schema !== null) return $schema;

        $conn = $this->getConnection('messages');

        if (!(bool)$conn->query("SHOW TABLES LIKE 'messages'")->num_rows) {
            $schema = ['ready' => false, 'error' => 'The messages table does not exist yet.'];
            return $schema;
        }

        $columns = [];
        $result  = $conn->query('SHOW COLUMNS FROM messages');
        while ($row = $result->fetch_assoc()) {
            $columns[$row['Field']] = true;
        }

        // Canonical column is `message_text`; fall back to `content` for old schemas
        $messageColumn = isset($columns['message_text']) ? 'message_text'
            : (isset($columns['content']) ? 'content' : null);
        // Canonical time column is `created_at`; fall back to `timestamp`
        $timeColumn = isset($columns['created_at']) ? 'created_at'
            : (isset($columns['timestamp']) ? 'timestamp' : null);

        if (!$messageColumn || !$timeColumn || !isset($columns['senderID']) || !isset($columns['receiverID'])) {
            $schema = ['ready' => false, 'error' => 'The messages table is missing expected columns (senderID, receiverID, message_text/content, created_at/timestamp).'];
            return $schema;
        }

        $schema = ['ready' => true, 'message_column' => $messageColumn, 'time_column' => $timeColumn];
        return $schema;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Contacts directory
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Fetches all contacts with friendship status merged in.
     *
     * Application-level aggregation:
     *   1. Fetch all eligible accounts from the accounts DB.
     *   2. Fetch friendships for this user from the friendships DB (if enabled).
     *   3. Merge and sort in PHP.
     */
    public function getContacts(int $userId, bool $hasFriendships): array {
        // Step 1: accounts
        $connAccounts = $this->getConnection('accounts');
        $contStmt = $connAccounts->prepare(
            "SELECT id, CONCAT(first_name, ' ', last_name) AS name, username, profile_pic, bio
             FROM accounts
             WHERE id != ? AND banned = 0
             ORDER BY username ASC"
        );
        $contStmt->bind_param('i', $userId);
        $contStmt->execute();
        $contacts = $contStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $contStmt->close();

        if (empty($contacts)) return [];

        // Default friendship fields
        foreach ($contacts as &$contact) {
            $contact['friend_status']    = 'none';
            $contact['friendship_id']    = 0;
            $contact['friend_requester'] = 0;
        }
        unset($contact);

        if (!$hasFriendships) return $contacts;

        // Step 2: friendships for this user
        $connFriendships = $this->getConnection('friendships');
        $fsStmt = $connFriendships->prepare(
            "SELECT friendshipID, user1_id, user2_id, status
             FROM friendships
             WHERE user1_id = ? OR user2_id = ?"
        );
        $fsStmt->bind_param('ii', $userId, $userId);
        $fsStmt->execute();
        $fsRows = $fsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $fsStmt->close();

        // Build lookup by the other user's ID
        $fsMap = [];
        foreach ($fsRows as $fs) {
            $otherId = ((int)$fs['user1_id'] === $userId) ? (int)$fs['user2_id'] : (int)$fs['user1_id'];
            $fsMap[$otherId] = $fs;
        }

        // Step 3: merge
        foreach ($contacts as &$contact) {
            $fs = $fsMap[(int)$contact['id']] ?? null;
            if ($fs) {
                $contact['friend_status']    = $fs['status'];
                $contact['friendship_id']    = (int)$fs['friendshipID'];
                $contact['friend_requester'] = (int)$fs['user1_id']; // user1_id is always the requester
            }
        }
        unset($contact);

        // Sort: accepted first, pending second, then alphabetical
        usort($contacts, static function (array $a, array $b) use ($userId): int {
            $rankA = $a['friend_status'] === 'accepted' ? 0 : ($a['friend_status'] === 'pending' ? 1 : 2);
            $rankB = $b['friend_status'] === 'accepted' ? 0 : ($b['friend_status'] === 'pending' ? 1 : 2);
            if ($rankA !== $rankB) return $rankA - $rankB;
            return strcasecmp($a['username'], $b['username']);
        });

        return $contacts;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Reads
    // ──────────────────────────────────────────────────────────────────────────

    public function checkUserExists(int $userId): bool {
        $conn = $this->getConnection('accounts');
        $stmt = $conn->prepare("SELECT id FROM accounts WHERE id = ? AND banned = 0 LIMIT 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        return $exists;
    }

    /**
     * Fetches a conversation between two users.
     *
     * Application-level aggregation:
     *   1. Fetch messages from the messages DB.
     *   2. Collect unique senderIDs, fetch display names from the accounts DB.
     *   3. Merge in PHP.
     */
    public function getConversation(int $userId, int $friendId, string $messageColumn, string $timeColumn, int $limit = 150): array {
        $connMessages = $this->getConnection('messages');
        $sql = "SELECT senderID, receiverID,
                       `{$messageColumn}` AS message_text,
                       `{$timeColumn}`    AS sent_at
                FROM messages
                WHERE (senderID = ? AND receiverID = ?)
                   OR (senderID = ? AND receiverID = ?)
                ORDER BY `{$timeColumn}` ASC
                LIMIT ?";
        $stmt = $connMessages->prepare($sql);
        $stmt->bind_param('iiiii', $userId, $friendId, $friendId, $userId, $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($rows)) return [];

        // Fetch sender display names
        $senderIds    = array_values(array_unique(array_column($rows, 'senderID')));
        $placeholders = implode(',', array_fill(0, count($senderIds), '?'));
        $types        = str_repeat('i', count($senderIds));
        $connAccounts = $this->getConnection('accounts');
        $nameStmt     = $connAccounts->prepare(
            "SELECT id, CONCAT(first_name, ' ', last_name) AS full_name FROM accounts WHERE id IN ({$placeholders})"
        );
        $nameStmt->bind_param($types, ...$senderIds);
        $nameStmt->execute();
        $nameRows = $nameStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $nameStmt->close();

        $nameMap = array_column($nameRows, 'full_name', 'id');
        foreach ($rows as &$row) {
            $row['sender_name'] = $nameMap[(int)$row['senderID']] ?? 'Unknown';
        }
        unset($row);

        return $rows;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Writes
    // ──────────────────────────────────────────────────────────────────────────

    public function sendMessage(int $senderId, int $receiverId, string $message, string $messageColumn): bool {
        $conn = $this->getConnection('messages');
        $stmt = $conn->prepare("INSERT INTO messages (senderID, receiverID, `{$messageColumn}`) VALUES (?, ?, ?)");
        $stmt->bind_param('iis', $senderId, $receiverId, $message);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Process methods (used by endpoint controllers)
    // ──────────────────────────────────────────────────────────────────────────

    public function processGetConversation(int $userId, int $friendId, array $schema): array {
        if (!$schema['ready']) {
            return ['success' => false, 'error' => $schema['error']];
        }
        if (!$this->checkUserExists($friendId)) {
            return ['success' => false, 'error' => 'That account does not exist.'];
        }

        $rows = $this->getConversation($userId, $friendId, $schema['message_column'], $schema['time_column']);

        $messages = array_map(static function (array $row) use ($userId): array {
            return [
                'message_text' => $row['message_text'],
                'sender_name'  => $row['sender_name'],
                'sent_at'      => date('M d, Y H:i', strtotime($row['sent_at'])),
                'is_mine'      => (int)$row['senderID'] === $userId,
            ];
        }, $rows);

        return ['success' => true, 'messages' => $messages];
    }

    public function processSendMessage(int $userId, int $friendId, string $message, array $schema): array {
        if (!$schema['ready']) {
            return ['success' => false, 'error' => $schema['error']];
        }
        if (!$friendId || $message === '') {
            return ['success' => false, 'error' => 'Message data is incomplete.'];
        }
        if (!$this->checkUserExists($friendId)) {
            return ['success' => false, 'error' => 'That account does not exist.'];
        }

        $message = mb_substr($message, 0, 1000);
        $success = $this->sendMessage($userId, $friendId, $message, $schema['message_column']);

        if ($success) {
            create_notification($friendId, $userId, 'message', null, $userId);
        }

        return ['success' => $success];
    }
}