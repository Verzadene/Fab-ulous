<?php
session_start();
require_once __DIR__ . '/../config.php';

if (!empty($_SESSION['user']) && !empty($_SESSION['mfa_verified'])) {
    header('Location: ' . dashboard_path_for_role($_SESSION['user']['role'] ?? 'user'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: reset_password.html');
    exit;
}

$email   = strtolower(trim($_POST['email'] ?? ''));
$code    = trim($_POST['reset_code'] ?? '');
$newPass = $_POST['new_password'] ?? '';
$confirm = $_POST['confirm_password'] ?? '';

$errRedirect = function (string $msg) use ($email): void {
    $url = 'reset_password.html?err=' . urlencode($msg);
    header('Location: ' . $url);
    exit;
};

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errRedirect('Please enter a valid email address.');
}

$conn = db_connect();
$tableExists = (bool) $conn->query("SHOW TABLES LIKE 'password_resets'")->num_rows;

if (!$tableExists) {
    $conn->close();
    $errRedirect('Password reset is not available yet. Run database/migration_v5.sql first.');
}

if (strlen($code) !== 6 || !ctype_digit($code)) {
    $conn->close();
    $errRedirect('Enter the 6-digit code from your email.');
}

if (strlen($newPass) < 8) {
    $conn->close();
    $errRedirect('New password must be at least 8 characters.');
}

if ($newPass !== $confirm) {
    $conn->close();
    $errRedirect('Passwords do not match.');
}

$accountStmt = $conn->prepare("SELECT id FROM accounts WHERE email = ? LIMIT 1");
$accountStmt->bind_param('s', $email);
$accountStmt->execute();
$account = $accountStmt->get_result()->fetch_assoc();
$accountStmt->close();

if (!$account) {
    $conn->close();
    $errRedirect('No FABulous account exists for that email address.');
}

$tokenStmt = $conn->prepare(
    "SELECT id FROM password_resets
     WHERE email = ? AND reset_code = ? AND used = 0
       AND expires_at > NOW()
     LIMIT 1"
);
$tokenStmt->bind_param('ss', $email, $code);
$tokenStmt->execute();
$tokenRow = $tokenStmt->get_result()->fetch_assoc();
$tokenStmt->close();

if (!$tokenRow) {
    $conn->close();
    $errRedirect('That code is incorrect or has expired. Request a new one.');
}

$hash = password_hash($newPass, PASSWORD_DEFAULT);

$updStmt = $conn->prepare("UPDATE accounts SET password = ? WHERE id = ?");
$updStmt->bind_param('si', $hash, $account['id']);
$updStmt->execute();
$updStmt->close();

$markStmt = $conn->prepare("UPDATE password_resets SET used = 1 WHERE id = ?");
$markStmt->bind_param('i', $tokenRow['id']);
$markStmt->execute();
$markStmt->close();

unset($_SESSION['reset_email']);
$conn->close();
header('Location: login.html?ok=reset');
exit;
