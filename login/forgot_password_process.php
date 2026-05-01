<?php
session_start();
require_once __DIR__ . '/../config.php';

if (!empty($_SESSION['user']) && !empty($_SESSION['mfa_verified'])) {
    header('Location: ' . dashboard_path_for_role($_SESSION['user']['role'] ?? 'user'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: forgot_password.html');
    exit;
}

$email = strtolower(trim($_POST['email'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: forgot_password.html?err=' . urlencode('Please enter a valid email address.'));
    exit;
}

$conn = db_connect();

$tableExists = (bool) $conn->query("SHOW TABLES LIKE 'password_resets'")->num_rows;

if (!$tableExists) {
    $conn->close();
    header('Location: forgot_password.html?err=' . urlencode('Password reset is not available yet. Run database/migration_v5.sql first.'));
    exit;
}

$accStmt = $conn->prepare("SELECT id, first_name FROM accounts WHERE email = ? LIMIT 1");
$accStmt->bind_param('s', $email);
$accStmt->execute();
$account = $accStmt->get_result()->fetch_assoc();
$accStmt->close();

if (!$account) {
    $conn->close();
    unset($_SESSION['reset_email']);
    header('Location: forgot_password.html?err=' . urlencode('No FABulous account exists for that email address.'));
    exit;
}

$delStmt = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
$delStmt->bind_param('s', $email);
$delStmt->execute();
$delStmt->close();

$code = (string) random_int(100000, 999999);
$insStmt = $conn->prepare(
    "INSERT INTO password_resets (email, reset_code, expires_at)
     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))"
);
$insStmt->bind_param('ss', $email, $code);
$inserted = $insStmt->execute();
$insStmt->close();

if (!$inserted) {
    $conn->close();
    header('Location: forgot_password.html?err=' . urlencode('We could not prepare a reset code. Please try again.'));
    exit;
}

$displayName = trim((string) ($account['first_name'] ?? ''));
if ($displayName === '') {
    $displayName = 'User';
}

$mailSent = send_password_reset_email($email, $displayName, $code);

if (!$mailSent) {
    $cleanupStmt = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
    if ($cleanupStmt) {
        $cleanupStmt->bind_param('s', $email);
        $cleanupStmt->execute();
        $cleanupStmt->close();
    }
    $conn->close();
    unset($_SESSION['reset_email']);
    $errMsg = get_last_mail_error() ?: 'We could not send the reset code email. Please try again.';
    header('Location: forgot_password.html?err=' . urlencode($errMsg));
    exit;
}

$_SESSION['reset_email'] = $email;
$conn->close();
header('Location: reset_password.html?sent=1');
exit;
