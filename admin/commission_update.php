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
$allowedStatuses = ['Pending', 'In Progress', 'Completed', 'Cancelled'];

if (!$commissionId || !in_array($status, $allowedStatuses, true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid commission update.']);
    exit;
}

$conn = db_connect();
$stmt = $conn->prepare('UPDATE commissions SET status = ?, admin_note = ? WHERE commissionID = ?');
$stmt->bind_param('ssi', $status, $adminNote, $commissionId);
$success = $stmt->execute();
$stmt->close();

if ($success) {
    $adminId = (int) $_SESSION['user']['id'];
    $adminUsername = $_SESSION['user']['username'];
    $action = "Updated commission #{$commissionId} to {$status}";
    $log = $conn->prepare(
        "INSERT INTO audit_log (admin_id, admin_username, action, target_type, target_id)
         VALUES (?, ?, ?, 'commission', ?)"
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
]);
