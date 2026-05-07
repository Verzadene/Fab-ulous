<?php
/**
 * PostRepository — all database logic for posts, likes, and comments.
 *
 * Cross-database JOIN rule:
 *   MySQL does not support JOINs across separate databases in prepared statements
 *   without using fully qualified names (db.table). Since db_connect() selects a
 *   specific database, queries that touch more than one domain must either:
 *     (a) use fully-qualified `database.table` references in the SQL, OR
 *     (b) perform application-level aggregation (fetch IDs from DB-A, then query DB-B).
 *
 *   This repository uses (a) for read-heavy queries (getFeed, getComments) and
 *   (b) everywhere else to keep each write isolated to its own domain.
 */
class PostRepository {
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
    // Feed
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Fetches the main social feed for a user (own posts + accepted friends' posts).
     *
     * Step 1: Fetch accepted friend IDs from the friendships DB.
     * Step 2: Fetch posts from the posts DB, using fully-qualified names for the
     *         cross-database subqueries (accounts, likes, comments).
     */
    public function getFeed(int $userID, int $limit = 20): array {
        $connFriendships = $this->getConnection('friendships');

        // Step 1 — collect allowed author IDs (self + accepted friends)
        $allowedUserIDs = [$userID];

        $hasFriendships = (bool) $connFriendships->query("SHOW TABLES LIKE 'friendships'")->num_rows;
        if ($hasFriendships) {
            $stmtFriends = $connFriendships->prepare(
                "SELECT user1_id, user2_id FROM friendships
                 WHERE status = 'accepted' AND (user1_id = ? OR user2_id = ?)"
            );
            $stmtFriends->bind_param("ii", $userID, $userID);
            $stmtFriends->execute();
            $friendshipResults = $stmtFriends->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmtFriends->close();

            foreach ($friendshipResults as $fs) {
                $allowedUserIDs[] = ((int)$fs['user1_id'] === $userID) ? (int)$fs['user2_id'] : (int)$fs['user1_id'];
            }
            $allowedUserIDs = array_unique($allowedUserIDs);
        }

        // Step 2 — fetch posts with cross-DB subqueries using qualified names
        $connPosts   = $this->getConnection('posts');
        $dbAccounts  = DB_CONFIG['accounts']['name'];
        $dbLikes     = DB_CONFIG['likes']['name'];
        $dbComments  = DB_CONFIG['comments']['name'];

        $placeholders = implode(',', array_fill(0, count($allowedUserIDs), '?'));
        $types        = str_repeat('i', count($allowedUserIDs));

        $sql = "SELECT p.postID, p.caption, p.image_url, p.created_at,
                       a.id AS authorID, a.username AS author, a.profile_pic AS author_pic, a.bio AS author_bio,
                       (SELECT COUNT(*) FROM `{$dbLikes}`.likes WHERE postID = p.postID) AS like_count,
                       (SELECT COUNT(*) FROM `{$dbComments}`.comments WHERE postID = p.postID) AS comment_count,
                       EXISTS(SELECT 1 FROM `{$dbLikes}`.likes WHERE postID = p.postID AND userID = ?) AS user_liked
                FROM posts p
                JOIN `{$dbAccounts}`.accounts a ON p.userID = a.id
                WHERE p.userID IN ({$placeholders})
                ORDER BY p.created_at DESC
                LIMIT ?";

        $stmt = $connPosts->prepare($sql);
        $params = array_merge([$userID], $allowedUserIDs, [$limit]);
        $stmt->bind_param('i' . $types . 'i', ...$params);
        $stmt->execute();
        $posts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $posts;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Posts CRUD
    // ──────────────────────────────────────────────────────────────────────────

    public function processCreatePost(int $userID, string $caption, ?array $imageFile): bool {
        $imageURL = null;

        if ($imageFile && $imageFile['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../uploads/posts/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            $ext     = strtolower(pathinfo($imageFile['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','webp'];

            if (in_array($ext, $allowed, true) && $imageFile['size'] <= 5 * 1024 * 1024) {
                $filename = uniqid('post_', true) . '.' . $ext;
                if (move_uploaded_file($imageFile['tmp_name'], $uploadDir . $filename)) {
                    $imageURL = '../uploads/posts/' . $filename;
                }
            }
        }

        if (empty($caption) && !$imageURL) {
            return false;
        }

        return $this->createPost($userID, $caption, $imageURL);
    }

    public function createPost(int $userID, string $caption, ?string $imageURL): bool {
        $connPosts = $this->getConnection('posts');
        $stmt = $connPosts->prepare("INSERT INTO posts (userID, caption, image_url) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $userID, $caption, $imageURL);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function processEditPost(int $postID, int $userID, string $caption): bool {
        $caption = mb_substr(trim($caption), 0, 2000);
        if ($caption === '') return false;
        return $this->editPost($postID, $userID, $caption);
    }

    public function editPost(int $postID, int $userID, string $caption): bool {
        $connPosts = $this->getConnection('posts');
        $stmt = $connPosts->prepare('UPDATE posts SET caption = ? WHERE postID = ? AND userID = ?');
        $stmt->bind_param('sii', $caption, $postID, $userID);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $ok && $affected > 0;
    }

    public function processDeletePost(int $postID, int $userID, string $actorUsername): bool {
        $ok = $this->deletePost($postID, $userID);
        if ($ok) {
            $this->logAuditAction($userID, $actorUsername, "User {$actorUsername} deleted their post #{$postID}", $postID);
        }
        return $ok;
    }

    public function deletePost(int $postID, int $userID): bool {
        $connPosts = $this->getConnection('posts');
        $stmt = $connPosts->prepare('DELETE FROM posts WHERE postID = ? AND userID = ?');
        $stmt->bind_param('ii', $postID, $userID);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $ok && $affected > 0;
    }

    public function getPostOwner(int $postID): int {
        $connPosts = $this->getConnection('posts');
        $stmt = $connPosts->prepare("SELECT userID FROM posts WHERE postID = ?");
        $stmt->bind_param("i", $postID);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['userID'] : 0;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Likes
    // ──────────────────────────────────────────────────────────────────────────

    public function toggleLike(int $postID, int $userID): bool {
        $connLikes = $this->getConnection('likes');
        $check = $connLikes->prepare("SELECT likeID FROM likes WHERE postID = ? AND userID = ?");
        $check->bind_param("ii", $postID, $userID);
        $check->execute();
        $check->store_result();
        $alreadyLiked = $check->num_rows > 0;
        $check->close();

        if ($alreadyLiked) {
            $del = $connLikes->prepare("DELETE FROM likes WHERE postID = ? AND userID = ?");
            $del->bind_param("ii", $postID, $userID);
            $del->execute();
            $del->close();
            return false; // now unliked
        } else {
            $ins = $connLikes->prepare("INSERT INTO likes (postID, userID) VALUES (?, ?)");
            $ins->bind_param("ii", $postID, $userID);
            $ins->execute();
            $ins->close();
            return true; // now liked
        }
    }

    public function getLikeCount(int $postID): int {
        $connLikes = $this->getConnection('likes');
        $stmt = $connLikes->prepare("SELECT COUNT(*) AS c FROM likes WHERE postID = ?");
        $stmt->bind_param("i", $postID);
        $stmt->execute();
        $count = (int)$stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();
        return $count;
    }

    public function processLike(int $postID, int $userID): array {
        $liked       = $this->toggleLike($postID, $userID);
        $postOwnerID = $this->getPostOwner($postID);

        if ($liked && $postOwnerID && $postOwnerID !== $userID) {
            $this->addNotification($postOwnerID, $userID, 'like', $postID);
        }

        return ['liked' => $liked, 'like_count' => $this->getLikeCount($postID)];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Comments
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Fetch comments for a post.
     *
     * Application-level aggregation:
     *   1. Fetch comments (commentID, userID, comment_text, created_at) from the comments DB.
     *   2. Collect unique userIDs, fetch usernames from the accounts DB.
     *   3. Merge in PHP.
     *
     * NOTE: The canonical column name from setup_micro_dbs.sql is `comment_text`, NOT `content`.
     */
    public function getComments(int $postID, int $limit = 50): array {
        $connComments = $this->getConnection('comments');

        // Step 1: fetch comments
        $cstmt = $connComments->prepare(
            "SELECT commentID, userID, comment_text AS content, created_at
             FROM comments
             WHERE postID = ?
             ORDER BY created_at ASC
             LIMIT ?"
        );
        $cstmt->bind_param("ii", $postID, $limit);
        $cstmt->execute();
        $commentRows = $cstmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $cstmt->close();

        if (empty($commentRows)) return [];

        // Step 2: fetch author names from accounts DB
        $userIds          = array_values(array_unique(array_column($commentRows, 'userID')));
        $placeholders     = implode(',', array_fill(0, count($userIds), '?'));
        $types            = str_repeat('i', count($userIds));
        $connAccounts     = $this->getConnection('accounts');

        $accStmt = $connAccounts->prepare(
            "SELECT id, username FROM accounts WHERE id IN ({$placeholders})"
        );
        $accStmt->bind_param($types, ...$userIds);
        $accStmt->execute();
        $accountRows = $accStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $accStmt->close();

        // Step 3: merge
        $usernames = array_column($accountRows, 'username', 'id');

        foreach ($commentRows as &$row) {
            $row['username'] = $usernames[$row['userID']] ?? 'Unknown User';
        }

        return $commentRows;
    }

    public function addComment(int $postID, int $userID, string $content): bool {
        $connComments = $this->getConnection('comments');
        $stmt = $connComments->prepare("INSERT INTO comments (postID, userID, comment_text) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $postID, $userID, $content);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function getCommentOwner(int $commentID): int {
        $connComments = $this->getConnection('comments');
        $stmt = $connComments->prepare("SELECT userID FROM comments WHERE commentID = ?");
        $stmt->bind_param("i", $commentID);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['userID'] : 0;
    }

    public function editComment(int $commentID, int $userID, string $content): bool {
        $connComments = $this->getConnection('comments');
        $stmt = $connComments->prepare("UPDATE comments SET comment_text = ? WHERE commentID = ? AND userID = ?");
        $stmt->bind_param("sii", $content, $commentID, $userID);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function deleteComment(int $commentID, int $userID): bool {
        $connComments = $this->getConnection('comments');
        $stmt = $connComments->prepare("DELETE FROM comments WHERE commentID = ? AND userID = ?");
        $stmt->bind_param("ii", $commentID, $userID);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function processAddComment(int $postID, int $userID, string $content): bool {
        $ok          = $this->addComment($postID, $userID, $content);
        $postOwnerID = $this->getPostOwner($postID);

        if ($ok && $postOwnerID && $postOwnerID !== $userID) {
            $this->addNotification($postOwnerID, $userID, 'comment', $postID);
        }

        return $ok;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    public function addNotification(int $userID, int $actorID, string $type, int $postID): void {
        if ($userID === $actorID) return;
        create_notification($userID, $actorID, $type, $postID);
    }

    public function logAuditAction(int $userId, string $username, string $action, int $targetId): void {
        $connAudit = $this->getConnection('audit_log');
        $log = $connAudit->prepare(
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