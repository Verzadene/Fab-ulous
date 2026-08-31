<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../post/CommissionRepository.php';

header('Content-Type: application/json');

$role = $_SESSION['user']['role'] ?? '';
if (empty($_SESSION['user']) || empty($_SESSION['mfa_verified']) || !in_array($role, ['admin', 'super_admin'], true)) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized request.']);
    exit;
}

$isSuperAdmin = ($role === 'super_admin');

// Assigned Position for Commission — only a Super Admin may set or change the
// assigned admin. This mirrors the server-side gate pattern used by
// commission_update.php's self-approval check: the UI already hides the
// assignment control from regular admins, but the restriction is enforced
// here too so a crafted request can't bypass it.
if (!$isSuperAdmin) {
    echo json_encode(['success' => false, 'error' => 'Only a Super Admin can assign commissions.']);
    exit;
}

$commissionId = (int) ($_POST['target_id'] ?? 0);
$rawAssignee  = $_POST['assigned_admin_id'] ?? '';
$assignedAdminId = ($rawAssignee === '') ? null : (int) $rawAssignee;

$commissionRepo = new CommissionRepository('db_connect');
$adminId       = (int) $_SESSION['user']['id'];
$adminUsername = $_SESSION['user']['username'];

$result = $commissionRepo->processAssignCommission($commissionId, $assignedAdminId, $adminId, $adminUsername, $isSuperAdmin);

echo json_encode($result);
