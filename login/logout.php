<?php
session_start();
require_once __DIR__ . '/../config.php';

// ── AUDIT LOG: User Logout ─────────────────────────────────────────────────
// Record the logout event BEFORE destroying the session, while we still have
// the user identity.  We only log when a real authenticated session exists.
if (!empty($_SESSION['user']) && !empty($_SESSION['mfa_verified'])) {
    $userId   = (int) ($_SESSION['user']['id']       ?? 0);
    $username = (string) ($_SESSION['user']['username'] ?? '');

    if ($userId > 0 && $username !== '') {
        try {
            $connAudit = db_connect('audit_log');
            $stmt = $connAudit->prepare(
                "INSERT INTO audit_log
                    (admin_id, admin_username, action, target_type, target_id, visibility_role)
                 VALUES
                    (?, ?, 'User Logout', 'account', ?, 'admin')"
            );
            if ($stmt) {
                $stmt->bind_param('isi', $userId, $username, $userId);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            // Audit failure must never block logout
            error_log('FABulous audit logout error: ' . $e->getMessage());
        }
    }
}
// ───────────────────────────────────────────────────────────────────────────

session_destroy();
header('Location: ../login/login.php');
exit;