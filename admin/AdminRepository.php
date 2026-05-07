<?php

// AdminRepository.php
// This class is responsible for all database interactions related to admin functionalities,
// especially user management (ban, unban, delete) and audit logging.
// It now uses the multi-database connection logic from config.php.

class AdminRepository
{
    private $dbConnectFactory; // Callable to get a database connection (e.g., db_connect from config.php)

    /**
     * Constructor for AdminRepository.
     *
     * @param callable $dbConnectFactory A callable function that takes a string (database domain)
     *                                   and returns a mysqli connection.
     */
    public function __construct(callable $dbConnectFactory)
    {
        $this->dbConnectFactory = $dbConnectFactory;
    }

    /**
     * Helper to get a connection for a specific database domain.
     *
     * @param string $domain The logical name of the database (e.g., 'accounts', 'posts').
     * @return mysqli An open mysqli connection.
     */
    private function getConnection(string $domain): mysqli
    {
        return call_user_func($this->dbConnectFactory, $domain);
    }

    /**
     * Deletes a user and all associated data across micro-databases.
     * This method handles cascading deletes as foreign keys are not enforced at the DB level.
     *
     * @param int $userIdToDelete The ID of the user to delete.
     * @param string $deletionReason The reason for deletion.
     * @param int $adminId The ID of the admin performing the action.
     * @param string $adminUsername The username of the admin.
     * @param string $adminRole The role of the admin ('admin' or 'super_admin').
     * @return array Success status and message/error.
     */
    public function processDeleteUser(
        int $userIdToDelete,
        string $deletionReason,
        int $adminId,
        string $adminUsername,
        string $adminRole
    ): array {
        $connAccounts = $this->getConnection('accounts');
        // Note: MySQL does not support cross-database transactions.
        // Operations are performed sequentially. If a step fails, subsequent steps
        // might not execute, leading to partial deletion. Application-level
        // compensation logic or a more robust distributed transaction system
        // would be needed for full ACID compliance in a micro-database setup.
        // For this context, we proceed with sequential deletions.

        try {
            // 1. Fetch user details for logging and email
            $stmt = $connAccounts->prepare("SELECT email, first_name, last_name, username FROM accounts WHERE id = ?");
            $stmt->bind_param('i', $userIdToDelete);
            $stmt->execute();
            $userToDelete = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$userToDelete) {
                return ['success' => false, 'error' => 'User not found.'];
            }

            $userEmail = $userToDelete['email'];
            $userName = trim($userToDelete['first_name'] . ' ' . $userToDelete['last_name']);
            $userUsername = $userToDelete['username'];

            // 2. Send deletion email (before actual deletion)
            // Assumes send_account_deletion_email and get_last_mail_error are globally available from config.php
            $mailSent = send_account_deletion_email($userEmail, $userName, $deletionReason);
            $emailLog = $mailSent ? 'Email sent successfully.' : 'Email failed: ' . get_last_mail_error();

            // 3. Delete user's data from other micro-databases
            // Posts
            $connPosts = $this->getConnection('posts');
            $stmt = $connPosts->prepare("DELETE FROM posts WHERE userID = ?");
            $stmt->bind_param('i', $userIdToDelete);
            $stmt->execute();
            $stmt->close();

            // Likes
            $connLikes = $this->getConnection('likes');
            $stmt = $connLikes->prepare("DELETE FROM likes WHERE userID = ?");
            $stmt->bind_param('i', $userIdToDelete);
            $stmt->execute();
            $stmt->close();

            // Comments
            $connComments = $this->getConnection('comments');
            $stmt = $connComments->prepare("DELETE FROM comments WHERE userID = ?");
            $stmt->bind_param('i', $userIdToDelete);
            $stmt->execute();
            $stmt->close();

            // Commissions and their payments
            $connCommissions = $this->getConnection('commissions');
            $stmt = $connCommissions->prepare("SELECT commissionID FROM commissions WHERE userID = ?");
            $stmt->bind_param('i', $userIdToDelete);
            $stmt->execute();
            $commissionIds = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (!empty($commissionIds)) {
                $commissionIdPlaceholders = implode(',', array_fill(0, count($commissionIds), '?'));
                $commissionIdValues = array_column($commissionIds, 'commissionID');

                $connPayments = $this->getConnection('commission_payments');
                $stmt = $connPayments->prepare("DELETE FROM commission_payments WHERE commissionID IN ({$commissionIdPlaceholders})");
                $types = str_repeat('i', count($commissionIdValues));
                $stmt->bind_param($types, ...$commissionIdValues);
                $stmt->execute();
                $stmt->close();
            }
            $stmt = $connCommissions->prepare("DELETE FROM commissions WHERE userID = ?");
            $stmt->bind_param('i', $userIdToDelete);
            $stmt->execute();
            $stmt->close();

            // Friendships (user1_id = requester, user2_id = receiver)
            $connFriendships = $this->getConnection('friendships');
            $stmt = $connFriendships->prepare("DELETE FROM friendships WHERE user1_id = ? OR user2_id = ?");
            $stmt->bind_param('ii', $userIdToDelete, $userIdToDelete);
            $stmt->execute();
            $stmt->close();

            // Notifications (userID or actor_id)
            $connNotifications = $this->getConnection('notifications');
            $stmt = $connNotifications->prepare("DELETE FROM notifications WHERE userID = ? OR actor_id = ?");
            $stmt->bind_param('ii', $userIdToDelete, $userIdToDelete);
            $stmt->execute();
            $stmt->close();

            // Messages (senderID or receiverID — canonical column names in fab_ulous_messages.messages)
            $connMessages = $this->getConnection('messages');
            $stmt = $connMessages->prepare("DELETE FROM messages WHERE senderID = ? OR receiverID = ?");
            $stmt->bind_param('ii', $userIdToDelete, $userIdToDelete);
            $stmt->execute();
            $stmt->close();

            // Pending Registrations (by email)
            $connPendingReg = $this->getConnection('pending_registrations');
            $stmt = $connPendingReg->prepare("DELETE FROM pending_registrations WHERE email = ?");
            $stmt->bind_param('s', $userEmail);
            $stmt->execute();
            $stmt->close();

            // Password Resets (by email)
            $connPasswordResets = $this->getConnection('password_resets');
            $stmt = $connPasswordResets->prepare("DELETE FROM password_resets WHERE email = ?");
            $stmt->bind_param('s', $userEmail);
            $stmt->execute();
            $stmt->close();

            // 4. Delete the account itself (last)
            $stmt = $connAccounts->prepare("DELETE FROM accounts WHERE id = ?");
            $stmt->bind_param('i', $userIdToDelete);
            $stmt->execute();
            $deletedRows = $stmt->affected_rows;
            $stmt->close();

            if ($deletedRows === 0) {
                // If the account wasn't deleted, rollback any potential partial changes (though not truly atomic cross-DB)
                return ['success' => false, 'error' => 'Account could not be deleted (possibly already gone).'];
            }

            // 5. Log the action in the audit log
            $connAudit = $this->getConnection('audit_log');
            $auditAction = "Deleted user account '{$userUsername}' (ID: {$userIdToDelete}). Reason: '{$deletionReason}'. Email status: {$emailLog}";
            $auditVisibility = ($adminRole === 'super_admin') ? 'super_admin' : 'admin';
            $stmt = $connAudit->prepare(
                "INSERT INTO audit_log (admin_id, admin_username, action, target_type, target_id, visibility_role)
                 VALUES (?, ?, ?, 'account', ?, ?)"
            );
            $stmt->bind_param('isiss', $adminId, $adminUsername, $auditAction, $userIdToDelete, $auditVisibility);
            $stmt->execute();
            $stmt->close();

            return ['success' => true, 'message' => 'User account and all associated data deleted successfully.'];

        } catch (Exception $e) {
            // Log the exception for debugging
            error_log("Error deleting user {$userIdToDelete}: " . $e->getMessage());
            return ['success' => false, 'error' => 'An unexpected error occurred during deletion: ' . $e->getMessage()];
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // User Management
    // ──────────────────────────────────────────────────────────────────────────

    public function getAllUsers(): array
    {
        $connAccounts = $this->getConnection('accounts');
        $stmt = $connAccounts->prepare("SELECT id, first_name, last_name, username, email, role, banned, created_at FROM accounts ORDER BY created_at DESC");
        $stmt->execute();
        $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $users;
    }

    public function processBanUser(int $targetId, int $adminId, string $adminUsername, bool $isSuperAdmin, string $banReason): string
    {
        $connAccounts = $this->getConnection('accounts');
        $stmt = $connAccounts->prepare("SELECT role FROM accounts WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $targetId);
        $stmt->execute();
        $targetRole = $stmt->get_result()->fetch_assoc()['role'] ?? 'user';
        $stmt->close();

        if ($targetRole === 'super_admin' && !$isSuperAdmin) {
            return "Only a Super Admin can ban another Super Admin.";
        }

        $stmt = $connAccounts->prepare("UPDATE accounts SET banned = 1 WHERE id = ?");
        $stmt->bind_param('i', $targetId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected > 0) {
            $this->logAuditAction($adminId, $adminUsername, "Banned user ID {$targetId}. Reason: {$banReason}", $targetId, $isSuperAdmin ? 'super_admin' : 'admin');
            return "User ID {$targetId} has been banned.";
        }
        return "Failed to ban user ID {$targetId}.";
    }

    public function processUnbanUser(int $targetId, int $adminId, string $adminUsername): string
    {
        $connAccounts = $this->getConnection('accounts');
        $stmt = $connAccounts->prepare("UPDATE accounts SET banned = 0 WHERE id = ?");
        $stmt->bind_param('i', $targetId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected > 0) {
            $this->logAuditAction($adminId, $adminUsername, "Unbanned user ID {$targetId}.", $targetId, 'admin');
            return "User ID {$targetId} has been unbanned.";
        }
        return "Failed to unban user ID {$targetId}.";
    }

    public function processPromoteToAdmin(int $targetId, int $adminId, string $adminUsername): string
    {
        $connAccounts = $this->getConnection('accounts');
        $stmt = $connAccounts->prepare("UPDATE accounts SET role = 'admin' WHERE id = ? AND role = 'user'");
        $stmt->bind_param('i', $targetId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected > 0) {
            $this->logAuditAction($adminId, $adminUsername, "Promoted user ID {$targetId} to admin.", $targetId, 'super_admin');
            return "User ID {$targetId} promoted to admin.";
        }
        return "Failed to promote user ID {$targetId} to admin (already admin or super admin).";
    }

    public function processDemoteToUser(int $targetId, int $adminId, string $adminUsername): string
    {
        $connAccounts = $this->getConnection('accounts');
        $stmt = $connAccounts->prepare("UPDATE accounts SET role = 'user' WHERE id = ? AND role = 'admin'");
        $stmt->bind_param('i', $targetId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected > 0) {
            $this->logAuditAction($adminId, $adminUsername, "Demoted admin ID {$targetId} to user.", $targetId, 'super_admin');
            return "Admin ID {$targetId} demoted to user.";
        }
        return "Failed to demote admin ID {$targetId} to user (already user or super admin).";
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Post Moderation
    // ──────────────────────────────────────────────────────────────────────────

    public function getAllPosts(): array
    {
        $connPosts = $this->getConnection('posts');
        $dbAccounts = DB_CONFIG['accounts']['name'];
        $dbLikes = DB_CONFIG['likes']['name'];
        $dbComments = DB_CONFIG['comments']['name'];

        $stmt = $connPosts->prepare(
            "SELECT p.postID, p.userID, p.caption, p.image_url, p.created_at,
                    a.username,
                    (SELECT COUNT(*) FROM `{$dbLikes}`.likes WHERE postID = p.postID) AS likes,
                    (SELECT COUNT(*) FROM `{$dbComments}`.comments WHERE postID = p.postID) AS comments
             FROM posts p
             JOIN `{$dbAccounts}`.accounts a ON p.userID = a.id
             ORDER BY p.created_at DESC"
        );
        $stmt->execute();
        $posts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $posts;
    }

    public function processDeletePost(int $postId, int $adminId, string $adminUsername): string
    {
        // Delete from likes
        $connLikes = $this->getConnection('likes');
        $stmt = $connLikes->prepare("DELETE FROM likes WHERE postID = ?");
        $stmt->bind_param('i', $postId);
        $stmt->execute();
        $stmt->close();

        // Delete from comments
        $connComments = $this->getConnection('comments');
        $stmt = $connComments->prepare("DELETE FROM comments WHERE postID = ?");
        $stmt->bind_param('i', $postId);
        $stmt->execute();
        $stmt->close();

        // Delete from posts
        $connPosts = $this->getConnection('posts');
        $stmt = $connPosts->prepare("DELETE FROM posts WHERE postID = ?");
        $stmt->bind_param('i', $postId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected > 0) {
            $this->logAuditAction($adminId, $adminUsername, "Deleted post ID {$postId}.", $postId, 'admin');
            return "Post ID {$postId} deleted.";
        }
        return "Failed to delete post ID {$postId} (not found).";
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Dashboard Metrics & Audit Log
    // ──────────────────────────────────────────────────────────────────────────

    public function getDashboardMetrics(): array
    {
        $connPosts = $this->getConnection('posts');
        $connAccounts = $this->getConnection('accounts');
        $connLikes = $this->getConnection('likes');
        $connComments = $this->getConnection('comments');
        $connPayments = $this->getConnection('commission_payments');

        $activeProjects = (int)($connPosts->query("SELECT COUNT(*) FROM posts")->fetch_row()[0] ?? 0);
        $totalUsers = (int)($connAccounts->query("SELECT COUNT(*) FROM accounts")->fetch_row()[0] ?? 0);
        $totalLikes = (int)($connLikes->query("SELECT COUNT(*) FROM likes")->fetch_row()[0] ?? 0);
        $totalComments = (int)($connComments->query("SELECT COUNT(*) FROM comments")->fetch_row()[0] ?? 0);
        $revenueSales = (float)($connPayments->query("SELECT SUM(amount) FROM commission_payments WHERE status = 'paid'")->fetch_row()[0] ?? 0.0);

        $engagementRate = ($activeProjects > 0) ? round(($totalLikes + $totalComments) / $activeProjects, 2) : 0;

        return [
            'activeProjects' => $activeProjects,
            'totalUsers' => $totalUsers,
            'engagementRate' => $engagementRate,
            'revenueSales' => number_format($revenueSales, 2),
        ];
    }

    public function getOrderPipeline(): array
    {
        $connCommissions = $this->getConnection('commissions');
        $stmt = $connCommissions->prepare("SELECT status, COUNT(*) AS count FROM commissions GROUP BY status");
        $stmt->execute();
        $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $pipeline = [
            'Pending' => 0, 'Accepted' => 0, 'Ongoing' => 0,
            'Delayed' => 0, 'Completed' => 0, 'Cancelled' => 0,
        ];
        foreach ($results as $row) {
            $pipeline[$row['status']] = (int)$row['count'];
        }
        return $pipeline;
    }

    public function searchAuditLogs(bool $isSuperAdmin, string $searchTerm, int $hours): array
    {
        $connAudit = $this->getConnection('audit_log');
        $dbAccounts = DB_CONFIG['accounts']['name'];

        $sql = "SELECT al.logID, al.admin_id, al.admin_username, al.action, al.created_at,
                       a.first_name, a.last_name
                FROM audit_log al
                JOIN `{$dbAccounts}`.accounts a ON al.admin_id = a.id
                WHERE al.created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)";

        $params = [$hours];
        $types = 'i';

        if (!$isSuperAdmin) {
            $sql .= " AND al.visibility_role = 'admin'";
        }

        if ($searchTerm !== '') {
            $sql .= " AND (al.admin_username LIKE ? OR al.action LIKE ? OR a.first_name LIKE ? OR a.last_name LIKE ?)";
            $searchTerm = '%' . $searchTerm . '%';
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
            $types .= 'ssss';
        }

        $sql .= " ORDER BY al.created_at DESC";

        $stmt = $connAudit->prepare($sql);
        if ($searchTerm !== '') {
            $stmt->bind_param($types, ...$params);
        } else {
            $stmt->bind_param($types, $params[0]);
        }
        
        $stmt->execute();
        $logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $logs;
    }

    /**
     * Logs an admin action to the audit log.
     *
     * @param int $adminId The ID of the admin performing the action.
     * @param string $adminUsername The username of the admin.
     * @param string $action A description of the action.
     * @param int|null $targetId The ID of the entity affected by the action (e.g., user ID, post ID).
     * @param string $visibilityRole The role required to view this log ('admin' or 'super_admin').
     */
    public function logAuditAction(int $adminId, string $adminUsername, string $action, ?int $targetId = null, string $visibilityRole = 'admin'): void {
        $connAudit = $this->getConnection('audit_log');
        $log  = $connAudit->prepare(
            "INSERT INTO audit_log (admin_id, admin_username, action, target_type, target_id, visibility_role)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        if ($log) {
            // Determine target_type based on context, or pass explicitly
            $targetType = null; // Default to null, can be set by calling methods
            if ($targetId !== null) {
                // Simple heuristic, can be refined by calling method
                if (str_contains($action, 'user')) $targetType = 'account';
                elseif (str_contains($action, 'post')) $targetType = 'post';
                elseif (str_contains($action, 'commission')) $targetType = 'commission';
            }
            $log->bind_param('ississ', $adminId, $adminUsername, $action, $targetType, $targetId, $visibilityRole);
            $log->execute();
            $log->close();
        }
    }
}