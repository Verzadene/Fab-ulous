<?php
/**
 * FriendRepository — all database logic for friend requests and the friend directory.
 *
 * Schema reference (fab_ulous_friendships.friendships):
 *   - user1_id  (requester, formerly requesterID)
 *   - user2_id  (receiver,  formerly receiverID)
 *   - status    ENUM('pending','accepted')
 *
 * Cross-database rule:
 *   The directory query reads accounts (accounts DB) and friendships (friendships DB).
 *   These are fetched in two separate steps and merged in PHP to avoid cross-database JOINs.
 */
class FriendRepository {
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
    // Directory
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Returns all non-banned, non-self users with their friendship status.
     *
     * Application-level aggregation (avoids cross-DB JOIN):
     *   1. Fetch all eligible accounts from the accounts DB.
     *   2. Fetch all friendships involving the current user from the friendships DB.
     *   3. Merge in PHP and sort (accepted first, then pending, then alphabetical).
     */
    public function getFriendDirectory(int $userID): array {
        // Step 1: eligible accounts
        $connAccounts = $this->getConnection('accounts');
        $accStmt = $connAccounts->prepare(
            "SELECT id,
                    CONCAT(first_name, ' ', last_name) AS name,
                    username, profile_pic, bio
             FROM accounts
             WHERE id != ? AND banned = 0
             ORDER BY username ASC"
        );
        $accStmt->bind_param("i", $userID);
        $accStmt->execute();
        $users = $accStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $accStmt->close();

        if (empty($users)) return [];

        // Step 2: all friendships for this user
        $connFriendships = $this->getConnection('friendships');
        $fsStmt = $connFriendships->prepare(
            "SELECT friendshipID, user1_id, user2_id, status
             FROM friendships
             WHERE user1_id = ? OR user2_id = ?"
        );
        $fsStmt->bind_param("ii", $userID, $userID);
        $fsStmt->execute();
        $fsRows = $fsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $fsStmt->close();

        // Build a lookup keyed by the other user's ID
        $fsMap = [];
        foreach ($fsRows as $fs) {
            $otherId = ((int)$fs['user1_id'] === $userID) ? (int)$fs['user2_id'] : (int)$fs['user1_id'];
            $fsMap[$otherId] = $fs;
        }

        // Step 3: merge
        foreach ($users as &$user) {
            $fs = $fsMap[(int)$user['id']] ?? null;
            $user['friend_status']    = $fs['status'] ?? 'none';
            $user['friendship_id']    = $fs ? (int)$fs['friendshipID'] : 0;
            // user1_id is always the requester
            $user['friend_requester'] = $fs ? (int)$fs['user1_id'] : 0;
        }
        unset($user);

        // Sort: accepted first, then by username
        usort($users, static function (array $a, array $b) use ($userID): int {
            $rankA = $a['friend_status'] === 'accepted' ? 0 : ($a['friend_status'] === 'pending' ? 1 : 2);
            $rankB = $b['friend_status'] === 'accepted' ? 0 : ($b['friend_status'] === 'pending' ? 1 : 2);
            if ($rankA !== $rankB) return $rankA - $rankB;
            return strcasecmp($a['username'], $b['username']);
        });

        return $users;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Status / lookup
    // ──────────────────────────────────────────────────────────────────────────

    public function getFriendshipStatus(int $user1, int $user2): ?array {
        $conn = $this->getConnection('friendships');
        $stmt = $conn->prepare(
            "SELECT friendshipID, status, user1_id AS requesterID
             FROM friendships
             WHERE (user1_id = ? AND user2_id = ?)
                OR (user2_id = ? AND user1_id = ?)
             LIMIT 1"
        );
        $stmt->bind_param("iiii", $user1, $user2, $user1, $user2);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    public function getPendingRequest(int $friendshipID, int $receiverID): ?int {
        $conn = $this->getConnection('friendships');
        $stmt = $conn->prepare(
            "SELECT user1_id FROM friendships
             WHERE friendshipID = ? AND user2_id = ? AND status = 'pending'"
        );
        $stmt->bind_param("ii", $friendshipID, $receiverID);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['user1_id'] : null;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Mutations
    // ──────────────────────────────────────────────────────────────────────────

    public function createFriendRequest(int $requesterID, int $receiverID): ?int {
        $conn = $this->getConnection('friendships');
        $ins = $conn->prepare(
            "INSERT INTO friendships (user1_id, user2_id, status) VALUES (?, ?, 'pending')"
        );
        $ins->bind_param("ii", $requesterID, $receiverID);
        if (!$ins->execute()) {
            $ins->close();
            return null;
        }
        $friendshipID = (int)$conn->insert_id;
        $ins->close();

        create_notification($receiverID, $requesterID, 'friend_request', null, $friendshipID);
        return $friendshipID;
    }

    public function acceptFriendRequest(int $friendshipID, int $receiverID, int $requesterID): bool {
        $conn = $this->getConnection('friendships');
        // user2_id is the receiver (the one accepting)
        $upd = $conn->prepare(
            "UPDATE friendships SET status = 'accepted' WHERE friendshipID = ? AND user2_id = ?"
        );
        $upd->bind_param("ii", $friendshipID, $receiverID);
        $ok = $upd->execute() && $upd->affected_rows > 0;
        $upd->close();

        if ($ok) {
            create_notification($requesterID, $receiverID, 'friend_accepted', null, $friendshipID);
        }

        return $ok;
    }

    public function deleteFriendship(int $friendshipID, int $userID): bool {
        $conn = $this->getConnection('friendships');
        $del = $conn->prepare(
            "DELETE FROM friendships
             WHERE friendshipID = ?
               AND (user1_id = ? OR user2_id = ?)"
        );
        $del->bind_param("iii", $friendshipID, $userID, $userID);
        $del->execute();
        $affected = $del->affected_rows;
        $del->close();
        return $affected > 0;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Process methods (used by endpoint controllers)
    // ──────────────────────────────────────────────────────────────────────────

    public function processGetStatus(int $myID, int $targetID): array {
        if (!$targetID || $targetID === $myID) {
            return ['success' => false, 'error' => 'Invalid user'];
        }
        $row = $this->getFriendshipStatus($myID, $targetID);
        if (!$row) {
            return ['success' => true, 'status' => 'none'];
        }
        return [
            'success'      => true,
            'status'       => $row['status'],
            'friendshipID' => (int)$row['friendshipID'],
            'i_requested'  => ((int)$row['requesterID'] === $myID),
        ];
    }

    public function processSendRequest(int $myID, int $receiverID): array {
        if (!$receiverID || $receiverID === $myID) {
            return ['success' => false, 'error' => 'Invalid receiver'];
        }
        if ($this->getFriendshipStatus($myID, $receiverID)) {
            return ['success' => false, 'error' => 'Request already exists'];
        }
        $friendshipID = $this->createFriendRequest($myID, $receiverID);
        return $friendshipID
            ? ['success' => true, 'friendshipID' => $friendshipID]
            : ['success' => false, 'error' => 'Insert failed'];
    }

    public function processAcceptRequest(int $myID, int $friendshipID): array {
        if (!$friendshipID) return ['success' => false, 'error' => 'Invalid ID'];
        $requesterID = $this->getPendingRequest($friendshipID, $myID);
        if (!$requesterID) return ['success' => false, 'error' => 'Request not found'];
        return ['success' => $this->acceptFriendRequest($friendshipID, $myID, $requesterID)];
    }

    public function processRemoveFriendship(int $myID, int $friendshipID): array {
        if (!$friendshipID) return ['success' => false, 'error' => 'Invalid ID'];
        return ['success' => $this->deleteFriendship($friendshipID, $myID)];
    }
}