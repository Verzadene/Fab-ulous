<?php

class InteractionRepository {
    private mysqli $conn;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    public function getPostOwner(int $postID): int {
        $stmt = $this->conn->prepare("SELECT userID FROM posts WHERE postID = ?");
        $stmt->bind_param("i", $postID);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        return $row ? (int)$row['userID'] : 0;
    }

    public function toggleLike(int $postID, int $userID): bool {
        $check = $this->conn->prepare("SELECT likeID FROM likes WHERE postID = ? AND userID = ?");
        $check->bind_param("ii", $postID, $userID);
        $check->execute();
        $check->store_result();
        $alreadyLiked = $check->num_rows > 0;
        $check->close();

        if ($alreadyLiked) {
            $del = $this->conn->prepare("DELETE FROM likes WHERE postID = ? AND userID = ?");
            $del->bind_param("ii", $postID, $userID);
            $del->execute();
            $del->close();
            return false; // Result is now unliked
        } else {
            $ins = $this->conn->prepare("INSERT INTO likes (postID, userID) VALUES (?, ?)");
            $ins->bind_param("ii", $postID, $userID);
            $ins->execute();
            $ins->close();
            return true; // Result is now liked
        }
    }

    public function getLikeCount(int $postID): int {
        $cntStmt = $this->conn->prepare("SELECT COUNT(*) AS c FROM likes WHERE postID = ?");
        $cntStmt->bind_param("i", $postID);
        $cntStmt->execute();
        $count = (int)$cntStmt->get_result()->fetch_assoc()['c'];
        $cntStmt->close();
        return $count;
    }

    public function addNotification(int $userID, int $actorID, string $type, int $postID): void {
        if ($userID === $actorID) return;

        if ($type === 'like') {
            $notif = $this->conn->prepare(
                "INSERT INTO notifications (userID, actor_id, type, post_id)
                 SELECT ?, ?, 'like', ?
                 WHERE NOT EXISTS (
                     SELECT 1 FROM notifications
                     WHERE userID = ? AND actor_id = ? AND type = 'like' AND post_id = ?
                 )"
            );
            if ($notif) {
                $notif->bind_param("iiiiii", $userID, $actorID, $postID, $userID, $actorID, $postID);
                $notif->execute();
                $notif->close();
            }
        } else {
            $notif = $this->conn->prepare(
                "INSERT INTO notifications (userID, actor_id, type, post_id) VALUES (?, ?, ?, ?)"
            );
            if ($notif) {
                $notif->bind_param("iisi", $userID, $actorID, $type, $postID);
                $notif->execute();
                $notif->close();
            }
        }
    }

    public function getComments(int $postID, int $limit = 50): array {
        $cstmt = $this->conn->prepare(
            "SELECT c.commentID, c.userID, c.content, c.created_at, a.username
             FROM comments c JOIN accounts a ON c.userID = a.id
             WHERE c.postID = ?
             ORDER BY c.created_at ASC
             LIMIT ?"
        );
        $cstmt->bind_param("ii", $postID, $limit);
        $cstmt->execute();
        $result = $cstmt->get_result();
        $cstmt->close();
        
        $comments = [];
        if ($result) {
            while ($r = $result->fetch_assoc()) {
                $comments[] = $r;
            }
        }
        return $comments;
    }

    public function addComment(int $postID, int $userID, string $content): bool {
        $stmt = $this->conn->prepare("INSERT INTO comments (postID, userID, content) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $postID, $userID, $content);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function getCommentOwner(int $commentID): int {
        $stmt = $this->conn->prepare("SELECT userID FROM comments WHERE commentID = ?");
        $stmt->bind_param("i", $commentID);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        return $row ? (int)$row['userID'] : 0;
    }

    public function editComment(int $commentID, int $userID, string $content): bool {
        $stmt = $this->conn->prepare("UPDATE comments SET content = ? WHERE commentID = ? AND userID = ?");
        $stmt->bind_param("sii", $content, $commentID, $userID);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function deleteComment(int $commentID, int $userID): bool {
        $stmt = $this->conn->prepare("DELETE FROM comments WHERE commentID = ? AND userID = ?");
        $stmt->bind_param("ii", $commentID, $userID);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function processLike(int $postID, int $userID): array {
        $liked = $this->toggleLike($postID, $userID);
        $postOwnerID = $this->getPostOwner($postID);

        if ($liked && $postOwnerID && $postOwnerID !== $userID) {
            $this->addNotification($postOwnerID, $userID, 'like', $postID);
        }

        $count = $this->getLikeCount($postID);
        return ['liked' => $liked, 'like_count' => $count];
    }

    public function processAddComment(int $postID, int $userID, string $content): bool {
        $ok = $this->addComment($postID, $userID, $content);
        $postOwnerID = $this->getPostOwner($postID);

        if ($ok && $postOwnerID && $postOwnerID !== $userID) {
            $this->addNotification($postOwnerID, $userID, 'comment', $postID);
        }

        return $ok;
    }
}
?>