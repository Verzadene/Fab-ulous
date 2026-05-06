<?php

class AdminRepository {
    private mysqli $conn;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    public function getDashboardMetrics(): array {
        $activeProjects = (int)$this->conn->query("SELECT COUNT(*) AS c FROM posts")->fetch_assoc()['c'];
        $totalUsers     = (int)$this->conn->query("SELECT COUNT(*) AS c FROM accounts WHERE role='user'")->fetch_assoc()['c'];

        $engRow = $this->conn->query("
            SELECT ((SELECT COUNT(*) FROM likes)+(SELECT COUNT(*) FROM comments)) AS i,
                   (SELECT COUNT(*) FROM posts) AS p
        ")->fetch_assoc();
        $engagementRate = $engRow['p'] > 0 ? round($engRow['i'] / $engRow['p'], 2) : 0;

        $revRow = $this->conn->query("SELECT COALESCE(SUM(amount),0) AS t FROM commissions WHERE status='Completed'")->fetch_assoc();
        $revenueSales = number_format((float)$revRow['t'], 2);

        return [
            'activeProjects' => $activeProjects,
            'totalUsers'     => $totalUsers,
            'engagementRate' => $engagementRate,
            'revenueSales'   => $revenueSales
        ];
    }

    public function getOrderPipeline(): array {
        $pipeline = ['Pending' => 0, 'Accepted' => 0, 'Ongoing' => 0, 'Delayed' => 0, 'Completed' => 0, 'Cancelled' => 0];
        $pRes = $this->conn->query("SELECT status, COUNT(*) AS c FROM commissions GROUP BY status");
        if ($pRes) {
            while ($r = $pRes->fetch_assoc()) {
                if (array_key_exists($r['status'], $pipeline)) {
                    $pipeline[$r['status']] = (int)$r['c'];
                }
            }
        }
        return $pipeline;
    }

    public function getAuditLogs(bool $isSuperAdmin, int $limit = 8): array {
        $auditLogs = [];
        $auditFilter = $isSuperAdmin ? '' : "WHERE visibility_role = 'admin'";
        $aRes = $this->conn->query("SELECT admin_username, action, created_at FROM audit_log {$auditFilter} ORDER BY created_at DESC LIMIT {$limit}");
        if ($aRes) {
            while ($r = $aRes->fetch_assoc()) {
                $auditLogs[] = $r;
            }
        }
        return $auditLogs;
    }

    public function getAllUsers(): array {
        $users = [];
        $uRes = $this->conn->query("SELECT id, first_name, last_name, username, email, role, banned, created_at FROM accounts ORDER BY created_at DESC");
        if ($uRes) while ($r = $uRes->fetch_assoc()) $users[] = $r;
        return $users;
    }

    public function getAllPosts(): array {
        $allPosts = [];
        $fpRes = $this->conn->query("
            SELECT p.postID, p.caption, p.created_at, a.username,
                   (SELECT COUNT(*) FROM likes    WHERE postID = p.postID) AS likes,
                   (SELECT COUNT(*) FROM comments WHERE postID = p.postID) AS comments
            FROM posts p JOIN accounts a ON p.userID = a.id
            ORDER BY p.created_at DESC
        ");
        if ($fpRes) while ($r = $fpRes->fetch_assoc()) $allPosts[] = $r;
        return $allPosts;
    }

    public function getAllCommissions(): array {
        $commissions = [];
        $commissionColumns = [];
        $colRes = $this->conn->query("SHOW COLUMNS FROM commissions");
        if ($colRes) while ($col = $colRes->fetch_assoc()) $commissionColumns[$col['Field']] = true;

        $hasAdminNote = isset($commissionColumns['admin_note']);
        $noteCol = $hasAdminNote ? ', c.admin_note' : ", '' AS admin_note";
        $titleExpr = isset($commissionColumns['commission_name']) ? "COALESCE(NULLIF(c.commission_name, ''), c.description)" : (isset($commissionColumns['title']) ? "COALESCE(NULLIF(c.title, ''), c.description)" : "c.description");

        $cRes = $this->conn->query("
            SELECT c.commissionID, $titleExpr AS title, c.description, c.amount, c.status, c.created_at$noteCol,
                   a.username AS requester, a.email AS requester_email
            FROM commissions c LEFT JOIN accounts a ON c.userID = a.id ORDER BY c.created_at DESC
        ");
        if ($cRes) while ($r = $cRes->fetch_assoc()) $commissions[] = $r;
        return $commissions;
    }

    public function logAdminAction(int $adminId, string $adminUsername, string $action, string $targetType, int $targetId, string $visibilityRole): void {
        $log = $this->conn->prepare("INSERT INTO audit_log (admin_id, admin_username, action, target_type, target_id, visibility_role) VALUES (?,?,?,?,?,?)");
        if ($log) {
            $log->bind_param("ississ", $adminId, $adminUsername, $action, $targetType, $targetId, $visibilityRole);
            $log->execute();
            $log->close();
        }
    }

    public function processBanUser(int $targetID, int $adminID, string $adminUsername, bool $isSuperAdmin): string {
        $allowedRoles = $isSuperAdmin ? "('user','admin')" : "('user')";
        $upd = $this->conn->prepare("UPDATE accounts SET banned = 1 WHERE id = ? AND role IN $allowedRoles AND id != ?");
        $upd->bind_param("ii", $targetID, $adminID); $upd->execute(); $upd->close();
        
        $sel = $this->conn->prepare("SELECT username, role FROM accounts WHERE id = ?");
        $sel->bind_param("i", $targetID); $sel->execute();
        $row = $sel->get_result()->fetch_assoc(); $sel->close();
        
        $logAction = "Banned user: " . ($row['username'] ?? 'Unknown');
        $vis = ($row['role'] ?? 'user') === 'user' ? 'admin' : 'super_admin';
        $this->logAdminAction($adminID, $adminUsername, $logAction, 'user', $targetID, $vis);
        
        return "User banned.";
    }

    public function processUnbanUser(int $targetID, int $adminID, string $adminUsername): string {
        $upd = $this->conn->prepare("UPDATE accounts SET banned = 0 WHERE id = ? AND role IN ('user','admin','super_admin') AND id != ?");
        $upd->bind_param("ii", $targetID, $adminID); $upd->execute(); $upd->close();
        
        $sel = $this->conn->prepare("SELECT username, role FROM accounts WHERE id = ?");
        $sel->bind_param("i", $targetID); $sel->execute();
        $row = $sel->get_result()->fetch_assoc(); $sel->close();
        
        $logAction = "Unbanned user: " . ($row['username'] ?? 'Unknown');
        $vis = in_array(($row['role'] ?? 'user'), ['admin', 'super_admin'], true) ? 'super_admin' : 'admin';
        $this->logAdminAction($adminID, $adminUsername, $logAction, 'user', $targetID, $vis);
        
        return "User unbanned.";
    }

    public function processDeletePost(int $targetID, int $adminID, string $adminUsername): string {
        $del = $this->conn->prepare("DELETE FROM posts WHERE postID = ?");
        $del->bind_param("i", $targetID); $del->execute(); $del->close();
        
        $logAction = "Admin removed post #$targetID";
        $this->logAdminAction($adminID, $adminUsername, $logAction, 'post', $targetID, 'admin');
        return "Post removed.";
    }

    public function processPromoteToAdmin(int $targetID, int $adminID, string $adminUsername): string {
        $upd = $this->conn->prepare("UPDATE accounts SET role = 'admin' WHERE id = ? AND role = 'user'");
        $upd->bind_param("i", $targetID); $upd->execute(); $upd->close();
        
        $sel = $this->conn->prepare("SELECT username FROM accounts WHERE id = ?");
        $sel->bind_param("i", $targetID); $sel->execute();
        $row = $sel->get_result()->fetch_assoc(); $sel->close();
        
        $logAction = "Promoted to admin: " . ($row['username'] ?? 'Unknown');
        $this->logAdminAction($adminID, $adminUsername, $logAction, 'user', $targetID, 'super_admin');
        return "User promoted to admin.";
    }

    public function processDemoteToUser(int $targetID, int $adminID, string $adminUsername): string {
        $upd = $this->conn->prepare("UPDATE accounts SET role = 'user' WHERE id = ? AND role = 'admin'");
        $upd->bind_param("i", $targetID); $upd->execute(); $upd->close();
        
        $sel = $this->conn->prepare("SELECT username FROM accounts WHERE id = ?");
        $sel->bind_param("i", $targetID); $sel->execute();
        $row = $sel->get_result()->fetch_assoc(); $sel->close();
        
        $logAction = "Demoted to user: " . ($row['username'] ?? 'Unknown');
        $this->logAdminAction($adminID, $adminUsername, $logAction, 'user', $targetID, 'super_admin');
        return "Admin demoted to user.";
    }

    public function processUpdateCommission(int $targetID, int $adminID, string $newStatus, string $adminNote, float $amount): string {
        $allowedStatuses = ['Pending', 'Accepted', 'Ongoing', 'Delayed', 'Completed', 'Cancelled'];
        if (!in_array($newStatus, $allowedStatuses, true)) return "Invalid status.";

        $adminNote = mb_substr(trim($adminNote), 0, 500);
        $amount = max(0, round($amount, 2));
        $ownerId = 0;
        $previousStatus = '';
        
        $existing = $this->conn->prepare("SELECT userID, status FROM commissions WHERE commissionID = ? LIMIT 1");
        if ($existing) {
            $existing->bind_param("i", $targetID);
            $existing->execute();
            $existingRow = $existing->get_result()->fetch_assoc();
            $existing->close();
            $ownerId = (int)($existingRow['userID'] ?? 0);
            $previousStatus = (string)($existingRow['status'] ?? '');
        }

        $upd = $this->conn->prepare("UPDATE commissions SET status = ?, admin_note = ?, amount = ? WHERE commissionID = ?");
        if ($upd) {
            $upd->bind_param("ssdi", $newStatus, $adminNote, $amount, $targetID);
            $upd->execute(); $upd->close();
            if ($ownerId > 0 && $previousStatus !== $newStatus) {
                $notifType = $newStatus === 'Accepted' ? 'commission_approved' : 'commission_updated';
                create_notification($this->conn, $ownerId, $adminID, $notifType, null, $targetID);
            }
        }
        return "Commission #$targetID updated.";
    }
}
?>