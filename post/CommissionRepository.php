<?php
/**
 * CommissionRepository — all database logic for commissions and commission payments.
 *
 * Cross-database rule:
 *   - Writes always target the already-selected database (plain table names).
 *   - Cross-domain reads in getAllCommissions() use fully-qualified `db.table` references
 *     because the query runs on the commissions connection but joins accounts data and
 *     sub-selects from commission_payments.
 */
class CommissionRepository {
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
    // Single commission
    // ──────────────────────────────────────────────────────────────────────────

    public function getCommissionById(int $commissionId): ?array {
        $conn = $this->getConnection('commissions');
        $stmt = $conn->prepare('SELECT userID, status FROM commissions WHERE commissionID = ? LIMIT 1');
        if (!$stmt) return null;
        $stmt->bind_param('i', $commissionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    public function updateCommission(int $commissionId, string $status, string $adminNote, float $amount): bool {
        $conn = $this->getConnection('commissions');
        $stmt = $conn->prepare('UPDATE commissions SET status = ?, admin_note = ?, amount = ? WHERE commissionID = ?');
        if (!$stmt) return false;
        $stmt->bind_param('ssdi', $status, $adminNote, $amount, $commissionId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function createCommission(int $userId, string $title, string $description, ?string $attachUrl): ?int {
        $conn = $this->getConnection('commissions');
        $ins = $conn->prepare(
            "INSERT INTO commissions (userID, commission_name, description, stl_file_url, status)
             VALUES (?, ?, ?, ?, 'Pending')"
        );
        if (!$ins) return null;
        $ins->bind_param('isss', $userId, $title, $description, $attachUrl);
        $ok = $ins->execute();
        $insertId = $ok ? (int)$conn->insert_id : null;
        $ins->close();
        return $insertId;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    public function getAdminIds(): array {
        $conn = $this->getConnection('accounts');
        $stmt = $conn->prepare("SELECT id FROM accounts WHERE role IN (?, ?) AND banned = 0");
        if (!$stmt) {
            error_log("CommissionRepository::getAdminIds prepare failed: " . $conn->error);
            return [];
        }
        $r1 = 'admin';
        $r2 = 'super_admin';
        $stmt->bind_param('ss', $r1, $r2);
        $stmt->execute();
        $result = $stmt->get_result();
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int)$row['id'];
        }
        $stmt->close();
        return $ids;
    }

    public function logAuditAction(int $adminId, string $adminUsername, string $action, int $targetId): void {
        $conn = $this->getConnection('audit_log');
        $log  = $conn->prepare(
            "INSERT INTO audit_log (admin_id, admin_username, action, target_type, target_id, visibility_role)
             VALUES (?, ?, ?, 'commission', ?, 'admin')"
        );
        if ($log) {
            $log->bind_param('issi', $adminId, $adminUsername, $action, $targetId);
            $log->execute();
            $log->close();
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Listing
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Returns all commissions (admin) or the current user's commissions (user).
     *
     * The admin query joins commissions → accounts and sub-selects from commission_payments.
     * All three live in separate databases, so fully-qualified names are required.
     */
    public function getAllCommissions(bool $isAdmin, int $userId): array {
        $connCommissions = $this->getConnection('commissions');
        $dbAccounts      = DB_CONFIG['accounts']['name'];
        $dbPayments      = DB_CONFIG['commission_payments']['name'];

        // Detect available columns (handles schema evolution)
        $commissionColumns = [];
        $colResult = $connCommissions->query('SHOW COLUMNS FROM commissions');
        while ($colResult && $col = $colResult->fetch_assoc()) {
            $commissionColumns[$col['Field']] = true;
        }

        $titleExpr = isset($commissionColumns['commission_name'])
            ? "COALESCE(NULLIF(commission_name, ''), description)"
            : (isset($commissionColumns['title']) ? "COALESCE(NULLIF(title, ''), description)" : 'description');

        $noteCol   = isset($commissionColumns['admin_note'])   ? 'admin_note'   : "'' AS admin_note";
        $attachCol = isset($commissionColumns['stl_file_url']) ? 'stl_file_url AS attachment_url' : "'' AS attachment_url";

        // Check payments table exists
        $connPayments    = $this->getConnection('commission_payments');
        $hasPaymentsTable = (bool)$connPayments->query("SHOW TABLES LIKE 'commission_payments'")->num_rows;

        $paymentSubSelect = $hasPaymentsTable
            ? ", (SELECT cp.status FROM `{$dbPayments}`.commission_payments cp WHERE cp.commissionID = c.commissionID ORDER BY cp.created_at DESC LIMIT 1) AS payment_status,
                 (SELECT cp.paid_at FROM `{$dbPayments}`.commission_payments cp WHERE cp.commissionID = c.commissionID AND cp.status = 'paid' ORDER BY cp.paid_at DESC LIMIT 1) AS paid_at"
            : ", '' AS payment_status, NULL AS paid_at";

        if ($isAdmin) {
            $stmt = $connCommissions->prepare(
                "SELECT c.commissionID,
                        {$titleExpr} AS title,
                        c.description,
                        c.amount,
                        c.status,
                        c.created_at,
                        c.{$noteCol},
                        c.{$attachCol},
                        a.username AS requester_username,
                        CONCAT(a.first_name, ' ', a.last_name) AS requester_name,
                        a.profile_pic AS requester_pic,
                        a.email AS requester_email
                        {$paymentSubSelect}
                 FROM commissions c
                 JOIN `{$dbAccounts}`.accounts a ON c.userID = a.id
                 ORDER BY c.created_at DESC"
            );
            $stmt->execute();
        } else {
            // The `c` alias is required because $paymentSubSelect references
            // c.commissionID inside its cross-DB sub-selects (kept identical
            // for the admin and user paths to avoid two divergent strings).
            $stmt = $connCommissions->prepare(
                "SELECT c.commissionID,
                        {$titleExpr} AS title,
                        c.description,
                        c.amount,
                        c.status,
                        c.created_at,
                        c.{$noteCol},
                        c.{$attachCol}
                        {$paymentSubSelect}
                 FROM commissions c
                 WHERE c.userID = ?
                 ORDER BY c.created_at DESC"
            );
            $stmt->bind_param('i', $userId);
            $stmt->execute();
        }

        $commissions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $commissions;
    }

    public function getCommissionsWithStats(bool $isAdmin, int $userId): array {
        $commissions = $this->getAllCommissions($isAdmin, $userId);

        $stats = ['total' => count($commissions), 'pending' => 0, 'active' => 0, 'completed' => 0, 'spent' => 0.0];
        foreach ($commissions as $c) {
            $stats['spent'] += (float)($c['amount'] ?? 0);
            $s = $c['status'] ?? '';
            if ($s === 'Pending')                                          $stats['pending']++;
            elseif (in_array($s, ['Accepted', 'Ongoing', 'Delayed'], true)) $stats['active']++;
            elseif ($s === 'Completed')                                    $stats['completed']++;
        }

        return ['commissions' => $commissions, 'stats' => $stats];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Process methods (used by endpoint controllers)
    // ──────────────────────────────────────────────────────────────────────────

    public function processSubmitCommission(int $userId, string $title, string $description, ?array $file): array {
        $title       = mb_substr(trim($title), 0, 255);
        $description = mb_substr(trim($description), 0, 2000);
        $attachUrl   = null;

        if ($description === '') {
            return ['success' => false, 'error' => 'Description is required.'];
        }

        if (!empty($file['name'])) {
            $uploadErr = (int)($file['error'] ?? UPLOAD_ERR_OK);

            if ($uploadErr !== UPLOAD_ERR_OK) {
                return ['success' => false, 'error' => 'File upload failed. Please try again.'];
            } elseif ($file['size'] > 10 * 1024 * 1024) {
                return ['success' => false, 'error' => 'Attachment must be smaller than 10 MB.'];
            }

            $ext         = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowedExts = ['pdf', 'stl'];

            if (!in_array($ext, $allowedExts, true)) {
                return ['success' => false, 'error' => 'Only PDF and STL files are allowed.'];
            } elseif ($file['size'] === 0) {
                return ['success' => false, 'error' => 'The uploaded file is empty.'];
            }

            $uploadDir = __DIR__ . '/../uploads/commissions/';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                return ['success' => false, 'error' => 'Could not prepare upload folder.'];
            }

            $safeFilename = $userId . '_' . time() . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $uploadDir . $safeFilename)) {
                return ['success' => false, 'error' => 'Failed to save attachment.'];
            }
            $attachUrl = 'uploads/commissions/' . $safeFilename;
        }

        $newId = $this->createCommission($userId, $title, $description, $attachUrl);
        if ($newId !== null) {
            return ['success' => true, 'message' => 'Commission request submitted successfully!'];
        }

        return ['success' => false, 'error' => 'Could not submit request. Please try again.'];
    }

    public function processUpdateCommission(int $commissionId, string $status, string $adminNote, float $amount, int $adminId, string $adminUsername, array $allowedStatuses): array {
        $status    = trim($status);
        $adminNote = mb_substr(trim($adminNote), 0, 500);
        $amount    = max(0, round($amount, 2));

        if (!$commissionId || !in_array($status, $allowedStatuses, true)) {
            return ['success' => false, 'error' => 'Invalid data.'];
        }

        $existing       = $this->getCommissionById($commissionId);
        $ownerId        = $existing ? (int)($existing['userID'] ?? 0) : 0;
        $previousStatus = $existing ? (string)($existing['status'] ?? '') : '';

        if ($this->updateCommission($commissionId, $status, $adminNote, $amount)) {
            if ($ownerId > 0 && $previousStatus !== $status) {
                $notifType = $status === 'Accepted' ? 'commission_approved' : 'commission_updated';
                create_notification($ownerId, $adminId, $notifType, null, $commissionId);
            }
            $this->logAuditAction($adminId, $adminUsername, "Updated commission #{$commissionId} to {$status}", $commissionId);
            return ['success' => true, 'status' => $status, 'amount' => $amount, 'amount_formatted' => '₱' . number_format($amount, 2)];
        }

        return ['success' => false, 'error' => 'Update failed.'];
    }
}