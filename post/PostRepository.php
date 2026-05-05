<?php

class PostRepository {
    private mysqli $conn;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    /**
     * Fetches the main social feed for a user.
     * If friendships are enabled, it fetches own posts + friends' posts.
     * Otherwise, it just fetches own posts.
     */
    public function getFeed(int $userID, bool $hasFriendships, int $limit = 20): array {
        if ($hasFriendships) {
            $stmt = $this->conn->prepare("
                SELECT p.postID, p.caption, p.image_url, p.created_at,
                       a.id AS authorID, a.username AS author, a.profile_pic AS author_pic,
                       (SELECT COUNT(*) FROM likes WHERE postID = p.postID) AS like_count,
                       (SELECT COUNT(*) FROM comments WHERE postID = p.postID) AS comment_count,
                       EXISTS(SELECT 1 FROM likes WHERE postID = p.postID AND userID = ?) AS user_liked
                FROM posts p
                JOIN accounts a ON p.userID = a.id
                WHERE p.userID = ?
                   OR EXISTS(
                       SELECT 1 FROM friendships
                       WHERE status = 'accepted'
                         AND ((requesterID = ? AND receiverID = p.userID)
                           OR (receiverID = ? AND requesterID = p.userID))
                   )
                ORDER BY p.created_at DESC
                LIMIT ?
            ");
            $stmt->bind_param("iiiii", $userID, $userID, $userID, $userID, $limit);
        } else {
            $stmt = $this->conn->prepare("
                SELECT p.postID, p.caption, p.image_url, p.created_at,
                       a.id AS authorID, a.username AS author, a.profile_pic AS author_pic,
                       (SELECT COUNT(*) FROM likes WHERE postID = p.postID) AS like_count,
                       (SELECT COUNT(*) FROM comments WHERE postID = p.postID) AS comment_count,
                       EXISTS(SELECT 1 FROM likes WHERE postID = p.postID AND userID = ?) AS user_liked
                FROM posts p
                JOIN accounts a ON p.userID = a.id
                WHERE p.userID = ?
                ORDER BY p.created_at DESC
                LIMIT ?
            ");
            $stmt->bind_param("iii", $userID, $userID, $limit);
        }

        $stmt->execute();
        $posts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $posts;
    }

    public function createPost(int $userID, string $caption, ?string $imageURL): bool {
        $stmt = $this->conn->prepare("INSERT INTO posts (userID, caption, image_url) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $userID, $caption, $imageURL);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function editPost(int $postID, int $userID, string $caption): bool {
        $stmt = $this->conn->prepare('UPDATE posts SET caption = ? WHERE postID = ? AND userID = ?');
        $stmt->bind_param('sii', $caption, $postID, $userID);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $ok && $affected > 0;
    }

    public function deletePost(int $postID, int $userID): bool {
        $stmt = $this->conn->prepare('DELETE FROM posts WHERE postID = ? AND userID = ?');
        $stmt->bind_param('ii', $postID, $userID);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $ok && $affected > 0;
    }

    public function logAuditAction(int $userId, string $username, string $action, int $targetId): void {
        $log = $this->conn->prepare(
            "INSERT INTO audit_log (admin_id, admin_username, action, target_type, target_id, visibility_role)
             VALUES (?, ?, ?, 'post', ?, 'admin')"
        );
        if ($log) {
            $log->bind_param('issi', $userId, $username, $action, $targetId);
            $log->execute();
            $log->close();
        }
    }
}
?>