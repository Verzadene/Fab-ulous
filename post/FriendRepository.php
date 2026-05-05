<?php

class FriendRepository {
    private mysqli $conn;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    public function getFriendDirectory(int $userID): array {
        $stmt = $this->conn->prepare("
            SELECT a.id,
                   CONCAT(a.first_name, ' ', a.last_name) AS name,
                   a.username,
                   a.profile_pic,
                   COALESCE((
                       SELECT status FROM friendships
                       WHERE (requesterID = ? AND receiverID = a.id)
                          OR (receiverID = ? AND requesterID = a.id)
                       LIMIT 1
                   ), 'none') AS friend_status,
                   (SELECT friendshipID FROM friendships
                    WHERE (requesterID = ? AND receiverID = a.id)
                       OR (receiverID = ? AND requesterID = a.id)
                    LIMIT 1) AS friendship_id,
                   (SELECT requesterID FROM friendships
                    WHERE (requesterID = ? AND receiverID = a.id)
                       OR (receiverID = ? AND requesterID = a.id)
                    LIMIT 1) AS friend_requester
            FROM accounts a
            WHERE a.id != ? AND a.banned = 0
            ORDER BY
                CASE
                    WHEN EXISTS(
                        SELECT 1 FROM friendships
                        WHERE status = 'accepted'
                          AND ((requesterID = ? AND receiverID = a.id)
                            OR (receiverID = ? AND requesterID = a.id))
                    ) THEN 0
                    ELSE 1
                END,
                a.username ASC
        ");
        
        $stmt->bind_param("iiiiiiiii", $userID, $userID, $userID, $userID, $userID, $userID, $userID, $userID, $userID);
        $stmt->execute();
        $directory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        return $directory;
    }

    public function getFriendshipStatus(int $user1, int $user2): ?array {
        $stmt = $this->conn->prepare(
            "SELECT friendshipID, status, requesterID FROM friendships
             WHERE (requesterID = ? AND receiverID = ?)
                OR (receiverID = ? AND requesterID = ?)
             LIMIT 1"
        );
        $stmt->bind_param("iiii", $user1, $user2, $user1, $user2);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        return $row ?: null;
    }

    public function createFriendRequest(int $requesterID, int $receiverID): ?int {
        $ins = $this->conn->prepare(
            "INSERT INTO friendships (requesterID, receiverID, status) VALUES (?, ?, 'pending')"
        );
        $ins->bind_param("ii", $requesterID, $receiverID);
        if (!$ins->execute()) {
            $ins->close();
            return null;
        }
        $friendshipID = $this->conn->insert_id;
        $ins->close();

        $notif = $this->conn->prepare(
            "INSERT INTO notifications (userID, actor_id, type, ref_id) VALUES (?, ?, 'friend_request', ?)"
        );
        if ($notif) {
            $notif->bind_param("iii", $receiverID, $requesterID, $friendshipID);
            $notif->execute();
            $notif->close();
        }

        return $friendshipID;
    }

    public function getPendingRequest(int $friendshipID, int $receiverID): ?int {
        $chk = $this->conn->prepare(
            "SELECT requesterID FROM friendships
             WHERE friendshipID = ? AND receiverID = ? AND status = 'pending'"
        );
        $chk->bind_param("ii", $friendshipID, $receiverID);
        $chk->execute();
        $row = $chk->get_result()->fetch_assoc();
        $chk->close();
        
        return $row ? (int)$row['requesterID'] : null;
    }

    public function acceptFriendRequest(int $friendshipID, int $receiverID, int $requesterID): bool {
        $upd = $this->conn->prepare("UPDATE friendships SET status = 'accepted' WHERE friendshipID = ?");
        $upd->bind_param("i", $friendshipID);
        $ok = $upd->execute();
        $upd->close();

        if ($ok) {
            $markRead = $this->conn->prepare(
                "UPDATE notifications SET is_read = 1
                 WHERE userID = ? AND actor_id = ? AND type = 'friend_request' AND ref_id = ?"
            );
            if ($markRead) {
                $markRead->bind_param("iii", $receiverID, $requesterID, $friendshipID);
                $markRead->execute();
                $markRead->close();
            }

            $notif = $this->conn->prepare(
                "INSERT INTO notifications (userID, actor_id, type, ref_id) VALUES (?, ?, 'friend_accepted', ?)"
            );
            if ($notif) {
                $notif->bind_param("iii", $requesterID, $receiverID, $friendshipID);
                $notif->execute();
                $notif->close();
            }
        }
        
        return $ok;
    }

    public function deleteFriendship(int $friendshipID, int $userID): bool {
        $del = $this->conn->prepare(
            "DELETE FROM friendships
             WHERE friendshipID = ?
               AND (requesterID = ? OR receiverID = ?)"
        );
        $del->bind_param("iii", $friendshipID, $userID, $userID);
        $del->execute();
        $del->close();

        $delNotif = $this->conn->prepare(
            "DELETE FROM notifications WHERE ref_id = ? AND type IN ('friend_request','friend_accepted')"
        );
        if ($delNotif) {
            $delNotif->bind_param("i", $friendshipID);
            $delNotif->execute();
            $delNotif->close();
        }

        return true;
    }
}
?>