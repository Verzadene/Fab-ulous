<?php
session_start();
require_once __DIR__ . '/../config.php';

// Google OAuth redirect
if (isset($_GET['google'])) {
    if (trim(GOOGLE_CLIENT_SECRET) === '') {
        header('Location: login.html?error=google_oauth_config');
        exit;
    }

    $scope    = urlencode('openid https://www.googleapis.com/auth/userinfo.profile https://www.googleapis.com/auth/userinfo.email');
    $auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?response_type=code'
              . '&client_id='    . urlencode(GOOGLE_CLIENT_ID)
              . '&redirect_uri=' . urlencode(GOOGLE_REDIRECT_URI)
              . '&scope='        . $scope
              . '&access_type=offline'
              . '&prompt=select_account';
    header('Location: ' . $auth_url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.html');
    exit;
}

$conn = db_connect();
$usernameOrEmail = trim($_POST['username'] ?? '');
$password        = $_POST['password'] ?? '';

$stmt = $conn->prepare("SELECT * FROM accounts WHERE username = ? OR email = ?");
$stmt->bind_param("ss", $usernameOrEmail, $usernameOrEmail);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

if (!$user || empty($user['password']) || !password_verify($password, $user['password'])) {
    header('Location: login.html?err=' . urlencode('Invalid username/email or password.'));
    exit;
}

if (in_array($user['role'] ?? '', ['admin', 'super_admin'], true)) {
    header('Location: login.html?err=' . urlencode('Invalid username/email or password.'));
    exit;
}

if ($user['banned']) {
    header('Location: login.html?err=' . urlencode('Your account has been suspended. Contact the administrator.'));
    exit;
}

$conn = db_connect();

if (!accounts_support_mfa($conn)) {
    $conn->close();
    header('Location: login.html?err=' . urlencode('MFA is not ready yet. Run the SQL update for the accounts table first.'));
    exit;
}

$code = (string) random_int(100000, 999999);

if (!store_mfa_code($conn, (int) $user['id'], $code)) {
    $conn->close();
    header('Location: login.html?err=' . urlencode('We could not start MFA verification. Please try again.'));
    exit;
}

clear_pending_auth();
clear_google_registration_prefill();

$_SESSION['pending_mfa_user'] = [
    'id'          => (int) $user['id'],
    'username'    => $user['username'],
    'email'       => $user['email'],
    'first_name'  => $user['first_name'],
    'last_name'   => $user['last_name'],
    'role'        => $user['role'] ?? 'user',
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
    $errMsg = get_last_mail_error() ?: 'A verification code could not be sent to your email address.';
    header('Location: login.html?err=' . urlencode($errMsg));
    exit;
}

$conn->close();
header('Location: verify_mfa.php');
exit;
