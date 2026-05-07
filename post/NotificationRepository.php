<?php
/**
 * NotificationRepository — all database logic for user notifications.
 *
 * Cross-database rule:
 *   getUnreadNotifications() needs actor display data from the accounts DB.
 *   Step 1: fetch notifications from the notifications DB.
 *   Step 2: fetch actor info from the accounts DB.
 *   Step 3: merge in PHP.
 */
class NotificationRepository {
    private $dbConnectFactory;

    public function __construct(callable $dbConnectFactory)
    {
        $this->dbConnectFactory = $dbConnectFactory;
    }

    private function getConnection(string $domain): mysqli
    {
        return call_user_func($this->dbConnectFactory, $domain);
    }

    public function getUnreadCount(int $userID): int {
        $conn = $this->getConnection('notifications');
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE userID = ? AND is_read = 0");
        if (!$stmt) return 0;
        $stmt->bind_param("i", $userID);
        $stmt->execute();
        $count = (int)$stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();
        return $count;
    }

    /**
     * Returns unread notifications with actor display info merged in.
     *
     * Application-level aggregation (avoids cross-DB JOIN):
     *   1. Fetch unread notification rows from the notifications DB.
     *   2. Collect unique actor_ids, fetch display info from the accounts DB.
     *   3. Merge in PHP.
     */
    public function getUnreadNotifications(int $userID, int $limit = 20): array {
        // Step 1: notifications
        $connNotif = $this->getConnection('notifications');
        $stmt = $connNotif->prepare(
            "SELECT notifID, type, post_id, ref_id, is_read, created_at, actor_id
             FROM notifications
             WHERE userID = ? AND is_read = 0
             ORDER BY created_at DESC
             LIMIT ?"
        );
        if (!$stmt) return [];
        $stmt->bind_param("ii", $userID, $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($rows)) return [];

        // Step 2: actor info
        $actorIds     = array_values(array_unique(array_column($rows, 'actor_id')));
        $placeholders = implode(',', array_fill(0, count($actorIds), '?'));
        $types        = str_repeat('i', count($actorIds));
        $connAccounts = $this->getConnection('accounts');

        $accStmt = $connAccounts->prepare(
            "SELECT id, username, first_name, last_name, profile_pic
             FROM accounts
             WHERE id IN ({$placeholders})"
        );
        $accStmt->bind_param($types, ...$actorIds);
        $accStmt->execute();
        $actorRows = $accStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $accStmt->close();

        $actorMap = [];
        foreach ($actorRows as $actor) {
            $actorMap[(int)$actor['id']] = $actor;
        }

        // Step 3: merge
        foreach ($rows as &$row) {
            $actor = $actorMap[(int)$row['actor_id']] ?? [];
            $row['actor_username']    = $actor['username']    ?? 'Unknown';
            $row['first_name']        = $actor['first_name']  ?? '';
            $row['last_name']         = $actor['last_name']   ?? '';
            $row['actor_profile_pic'] = $actor['profile_pic'] ?? null;
        }
        unset($row);

        return $rows;
    }

    public function markAsRead(int $userID, int $notifID = 0): bool {
        $conn = $this->getConnection('notifications');
        if ($notifID > 0) {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE notifID = ? AND userID = ?");
            $stmt->bind_param("ii", $notifID, $userID);
        } else {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE userID = ?");
            $stmt->bind_param("i", $userID);
        }
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}