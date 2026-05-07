<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../post/CommissionRepository.php'; // Include CommissionRepository

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

$commissionRepo = new CommissionRepository('db_connect');
$adminId = (int) $_SESSION['user']['id'];
$adminUsername = $_SESSION['user']['username'];

$result = $commissionRepo->processUpdateCommission($commissionId, $status, $adminNote, $amount, $adminId, $adminUsername, $allowedStatuses);

// The processUpdateCommission method already returns the necessary data,
// so we just need to echo it.
echo json_encode([
    'success' => $result['success'],
    'status' => $result['status'] ?? $status, // Fallback to $status if not in result
    'amount' => $result['amount'] ?? $amount, // Fallback to $amount if not in result
    'amount_formatted' => $result['amount_formatted'] ?? '₱' . number_format($amount, 2),
    'error' => $result['error'] ?? null,
]);
