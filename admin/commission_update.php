<?php
session_start();
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$role = $_SESSION['user']['role'] ?? '';
if (empty($_SESSION['user']) || empty($_SESSION['mfa_verified']) || !in_array($role, ['admin', 'super_admin'], true)) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized request.']);
    exit;
}

$commissionId = (int) ($_POST['target_id'] ?? 0);
$status = trim($_POST['commission_status'] ?? '');
$adminNote = mb_substr(trim($_POST['admin_note'] ?? ''), 0, 500);
$amount = max(0, round((float) ($_POST['amount'] ?? 0), 2));
$allowedStatuses = ['Pending', 'Accepted', 'Ongoing', 'Delayed', 'Completed', 'Cancelled'];

if (!$commissionId || !in_array($status, $allowedStatuses, true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid commission update.']);
    exit;
}

$conn = db_connect();
$ownerId = 0;
$previousStatus = '';
$existing = $conn->prepare('SELECT userID, status FROM commissions WHERE commissionID = ? LIMIT 1');
if ($existing) {
    $existing->bind_param('i', $commissionId);
    $existing->execute();
    $existingRow = $existing->get_result()->fetch_assoc();
    $existing->close();
    $ownerId = (int) ($existingRow['userID'] ?? 0);
    $previousStatus = (string) ($existingRow['status'] ?? '');
}

$stmt = $conn->prepare('UPDATE commissions SET status = ?, admin_note = ?, amount = ? WHERE commissionID = ?');
$stmt->bind_param('ssdi', $status, $adminNote, $amount, $commissionId);
$success = $stmt->execute();
$stmt->close();

if ($success) {
    $adminId = (int) $_SESSION['user']['id'];
    $adminUsername = $_SESSION['user']['username'];
    if ($ownerId > 0 && $previousStatus !== $status) {
        $notifType = $status === 'Accepted' ? 'commission_approved' : 'commission_updated';
        create_notification($conn, $ownerId, $adminId, $notifType, null, $commissionId);
    }

    $action = "Updated commission #{$commissionId} to {$status}";
    $log = $conn->prepare(
        "INSERT INTO audit_log (admin_id, admin_username, action, target_type, target_id, visibility_role)
         VALUES (?, ?, ?, 'commission', ?, 'admin')"
    );
    if ($log) {
        $log->bind_param('issi', $adminId, $adminUsername, $action, $commissionId);
        $log->execute();
        $log->close();
    }
}

$conn->close();
echo json_encode([
    'success' => $success,
    'status' => $status,
    'amount' => $amount,
    'amount_formatted' => '₱' . number_format($amount, 2),
]);
