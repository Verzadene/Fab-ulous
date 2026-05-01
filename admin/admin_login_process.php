<?php
session_start();
require_once __DIR__ . '/../config.php';

if (isset($_SESSION['user']) && in_array($_SESSION['user']['role'] ?? '', ['admin', 'super_admin'], true)) {
    header('Location: admin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin_login.html');
    exit;
}

$conn = db_connect();
$input    = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

$stmt = $conn->prepare(
    "SELECT * FROM accounts WHERE (username = ? OR email = ?) AND role IN ('admin', 'super_admin')"
);
$stmt->bind_param("ss", $input, $input);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || !password_verify($password, $user['password'])) {
    $conn->close();
    header('Location: admin_login.html?err=' . urlencode('Invalid credentials or not an admin account.'));
    exit;
}

if ($user['banned']) {
    $conn->close();
    header('Location: admin_login.html?err=' . urlencode('This admin account has been suspended.'));
    exit;
}

if (!accounts_support_mfa($conn)) {
    $conn->close();
    header('Location: admin_login.html?err=' . urlencode('MFA is not ready yet. Run the SQL update for the accounts table first.'));
    exit;
}

$code = (string) random_int(100000, 999999);

if (!store_mfa_code($conn, (int) $user['id'], $code)) {
    $conn->close();
    header('Location: admin_login.html?err=' . urlencode('We could not start MFA verification. Please try again.'));
    exit;
}

clear_pending_auth();
$_SESSION['pending_mfa_user'] = [
    'id'          => (int) $user['id'],
    'username'    => $user['username'],
    'email'       => $user['email'],
    'first_name'  => $user['first_name'],
    'last_name'   => $user['last_name'],
    'role'        => $user['role'],
    'google_id'   => $user['google_id'] ?? null,
    'profile_pic' => $user['profile_pic'] ?? null,
];
$_SESSION['pending_mfa_sent_at'] = time();

$mailSent = send_mfa_code_email(
    $user['email'],
    trim($user['first_name'] . ' ' . $user['last_name']),
    $code
);

if (!$mailSent) {
    clear_pending_auth();
    clear_mfa_code($conn, (int) $user['id']);
    $conn->close();
    $errMsg = get_last_mail_error() ?: 'A verification code could not be sent to this admin email address.';
    header('Location: admin_login.html?err=' . urlencode($errMsg));
    exit;
}

$conn->close();
header('Location: ../login/verify_mfa.php');
exit;
