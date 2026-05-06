<?php

class CommissionRepository {
    private mysqli $conn;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    public function getCommissionById(int $commissionId): ?array {
        $stmt = $this->conn->prepare('SELECT userID, status FROM commissions WHERE commissionID = ? LIMIT 1');
        if (!$stmt) return null;
        $stmt->bind_param('i', $commissionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    public function updateCommission(int $commissionId, string $status, string $adminNote, float $amount): bool {
        $stmt = $this->conn->prepare('UPDATE commissions SET status = ?, admin_note = ?, amount = ? WHERE commissionID = ?');
        if (!$stmt) return false;
        $stmt->bind_param('ssdi', $status, $adminNote, $amount, $commissionId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function logAuditAction(int $adminId, string $adminUsername, string $action, int $targetId): void {
        $log = $this->conn->prepare(
            "INSERT INTO audit_log (admin_id, admin_username, action, target_type, target_id, visibility_role)
             VALUES (?, ?, ?, 'commission', ?, 'admin')"
        );
        if ($log) {
            $log->bind_param('issi', $adminId, $adminUsername, $action, $targetId);
            $log->execute();
            $log->close();
        }
    }

    public function createCommission(int $userId, string $title, string $description, ?string $attachUrl): ?int {
        $ins = $this->conn->prepare(
            "INSERT INTO commissions (userID, commission_name, description, stl_file_url, status)
             VALUES (?, ?, ?, ?, 'Pending')"
        );
        if (!$ins) return null;
        $ins->bind_param('isss', $userId, $title, $description, $attachUrl);
        $ok = $ins->execute();
        $insertId = $ok ? (int) $this->conn->insert_id : null;
        $ins->close();
        return $insertId;
    }

    public function getAdminIds(): array {
        $admins = $this->conn->query("SELECT id FROM accounts WHERE role IN ('admin','super_admin') AND banned = 0");
        $ids = [];
        if ($admins) {
            while ($admin = $admins->fetch_assoc()) {
                $ids[] = (int) $admin['id'];
            }
        }
        return $ids;
    }

    public function getAllCommissions(bool $isAdmin, int $userId): array {
        $commissionColumns = [];
        $columnsResult = $this->conn->query('SHOW COLUMNS FROM commissions');
        while ($columnsResult && $column = $columnsResult->fetch_assoc()) {
            $commissionColumns[$column['Field']] = true;
        }

        $titleExprAdmin = isset($commissionColumns['commission_name'])
            ? "COALESCE(NULLIF(c.commission_name, ''), c.description)"
            : (isset($commissionColumns['title']) ? "COALESCE(NULLIF(c.title, ''), c.description)" : 'c.description');

        $titleExprUser = isset($commissionColumns['commission_name'])
            ? "COALESCE(NULLIF(commission_name, ''), description)"
            : (isset($commissionColumns['title']) ? "COALESCE(NULLIF(title, ''), description)" : 'description');

        $noteColAdmin = isset($commissionColumns['admin_note']) ? 'c.admin_note' : "'' AS admin_note";
        $noteColUser  = isset($commissionColumns['admin_note']) ? 'admin_note'   : "'' AS admin_note";
        $attachColAdmin = isset($commissionColumns['stl_file_url']) ? 'c.stl_file_url AS attachment_url' : "'' AS attachment_url";
        $attachColUser  = isset($commissionColumns['stl_file_url']) ? 'stl_file_url AS attachment_url' : "'' AS attachment_url";
        
        $hasPaymentsTable = (bool) $this->conn->query("SHOW TABLES LIKE 'commission_payments'")->num_rows;
        $paymentColsAdmin = $hasPaymentsTable
            ? ", (SELECT cp.status FROM commission_payments cp WHERE cp.commissionID = c.commissionID ORDER BY cp.created_at DESC LIMIT 1) AS payment_status,
                 (SELECT cp.paid_at FROM commission_payments cp WHERE cp.commissionID = c.commissionID AND cp.status = 'paid' ORDER BY cp.paid_at DESC LIMIT 1) AS paid_at"
            : ", '' AS payment_status, NULL AS paid_at";
        $paymentColsUser = $hasPaymentsTable
            ? ", (SELECT cp.status FROM commission_payments cp WHERE cp.commissionID = commissions.commissionID ORDER BY cp.created_at DESC LIMIT 1) AS payment_status,
                 (SELECT cp.paid_at FROM commission_payments cp WHERE cp.commissionID = commissions.commissionID AND cp.status = 'paid' ORDER BY cp.paid_at DESC LIMIT 1) AS paid_at"
            : ", '' AS payment_status, NULL AS paid_at";

        if ($isAdmin) {
            $stmt = $this->conn->prepare(
                "SELECT c.commissionID, {$titleExprAdmin} AS title, c.description, c.amount, c.status, c.created_at, {$noteColAdmin}, {$attachColAdmin},
                        a.username AS requester_username, CONCAT(a.first_name, ' ', a.last_name) AS requester_name, a.profile_pic AS requester_pic, a.email AS requester_email
                        {$paymentColsAdmin}
                 FROM commissions c JOIN accounts a ON c.userID = a.id ORDER BY c.created_at DESC"
            );
            $stmt->execute();
        } else {
            $stmt = $this->conn->prepare(
                "SELECT commissionID, {$titleExprUser} AS title, description, amount, status, created_at, {$noteColUser}, {$attachColUser} {$paymentColsUser}
                 FROM commissions WHERE userID = ? ORDER BY created_at DESC"
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
            $stats['spent'] += (float) ($c['amount'] ?? 0);
            $s = $c['status'] ?? '';
            if ($s === 'Pending')   $stats['pending']++;
            elseif (in_array($s, ['Accepted','Ongoing','Delayed'], true)) $stats['active']++;
            elseif ($s === 'Completed') $stats['completed']++;
        }
        
        return ['commissions' => $commissions, 'stats' => $stats];
    }

    public function processSubmitCommission(int $userId, string $title, string $description, ?array $file): array {
        $title       = mb_substr(trim($title), 0, 255);
        $description = mb_substr(trim($description), 0, 2000);
        $attachUrl   = null;

        if ($description === '') {
            return ['success' => false, 'error' => 'Description is required.'];
        }

        if (!empty($file['name'])) {
            $uploadErr = (int) ($file['error'] ?? UPLOAD_ERR_OK);

            if ($uploadErr !== UPLOAD_ERR_OK) {
                return ['success' => false, 'error' => 'File upload failed. Please try again.'];
            } elseif ($file['size'] > 10 * 1024 * 1024) {
                return ['success' => false, 'error' => 'Attachment must be smaller than 10 MB.'];
            }

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
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
            $destPath     = $uploadDir . $safeFilename;
            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                return ['success' => false, 'error' => 'Failed to save attachment.'];
            }
            $attachUrl = 'uploads/commissions/' . $safeFilename;
        }

        $newId = $this->createCommission($userId, $title, $description, $attachUrl);
        if ($newId !== null) {
            $adminIds = $this->getAdminIds();
            foreach ($adminIds as $adminId) {
                if ($adminId !== $userId) {
                    create_notification($this->conn, $adminId, $userId, 'commission_submitted', null, $newId);
                }
            }
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

        $existing = $this->getCommissionById($commissionId);
        $ownerId = $existing ? (int) ($existing['userID'] ?? 0) : 0;
        $previousStatus = $existing ? (string) ($existing['status'] ?? '') : '';

        if ($this->updateCommission($commissionId, $status, $adminNote, $amount)) {
            if ($ownerId > 0 && $previousStatus !== $status) {
                $notifType = $status === 'Accepted' ? 'commission_approved' : 'commission_updated';
                create_notification($this->conn, $ownerId, $adminId, $notifType, null, $commissionId);
            }
            $this->logAuditAction($adminId, $adminUsername, "Updated commission #{$commissionId} to {$status}", $commissionId);
            return ['success' => true, 'status' => $status, 'amount' => $amount, 'amount_formatted' => '₱' . number_format($amount, 2)];
        }
        return ['success' => false, 'error' => 'Update failed.'];
    }
}
?>