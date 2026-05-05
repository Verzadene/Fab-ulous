<?php

class NotificationRepository {
    private mysqli $conn;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    public function getUnreadCount(int $userID): int {
        $stmt = $this->conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE userID = ? AND is_read = 0");
        if (!$stmt) return 0;
        $stmt->bind_param("i", $userID);
        $stmt->execute();
        $count = (int)$stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();
        return $count;
    }

    public function getUnreadNotifications(int $userID, int $limit = 20): array {
        $stmt = $this->conn->prepare(
            "SELECT n.notifID, n.type, n.post_id, n.ref_id, n.is_read, n.created_at,
                    a.username AS actor_username, a.first_name, a.last_name
             FROM notifications n
             JOIN accounts a ON n.actor_id = a.id
             WHERE n.userID = ? AND n.is_read = 0
             ORDER BY n.created_at DESC
             LIMIT ?"
        );
        if (!$stmt) return [];
        $stmt->bind_param("ii", $userID, $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function markAsRead(int $userID, int $notifID = 0): bool {
        if ($notifID > 0) {
            $stmt = $this->conn->prepare("UPDATE notifications SET is_read = 1 WHERE notifID = ? AND userID = ?");
            $stmt->bind_param("ii", $notifID, $userID);
        } else {
            $stmt = $this->conn->prepare("UPDATE notifications SET is_read = 1 WHERE userID = ?");
            $stmt->bind_param("i", $userID);
        }
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}
?>