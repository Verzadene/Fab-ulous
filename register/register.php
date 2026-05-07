<?php
session_start();
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: register.html');
    exit;
}

$password        = $_POST['password'] ?? '';
$confirmPassword = $_POST['confirmPassword'] ?? '';

// Password strength validation
if (
    strlen($password) < 8
    || preg_match_all('/[^a-zA-Z0-9]/', $password) < 1
    || preg_match_all('/[0-9]/', $password) < 1
) {
    header('Location: ../register/register.html?error=weak_password');
    exit;
}

if ($password !== $confirmPassword) {
    header('Location: ../register/register.html?error=password_mismatch');
    exit;
}

$firstName = trim($_POST['firstName'] ?? '');
$lastName  = trim($_POST['lastName']  ?? '');
$username  = trim($_POST['username']  ?? '');
$email     = strtolower(trim($_POST['email'] ?? ''));

// Check if email or username already exists in accounts
$connAccounts = db_connect('accounts');
$checkStmt = $connAccounts->prepare('SELECT email, username FROM accounts WHERE email = ? OR username = ?');
$checkStmt->bind_param('ss', $email, $username);
$checkStmt->execute();
$existing = $checkStmt->get_result()->fetch_assoc();
$checkStmt->close();

if ($existing) {
    $errCode = ($existing['email'] === $email) ? 'email_taken' : 'username_taken';
    header("Location: ../register/register.html?error={$errCode}");
    exit;
}

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

// Check if Google prefill matches
$prefill  = get_google_registration_prefill();
$googleId = null;

if (!empty($prefill['google_id']) && !empty($prefill['email']) && strtolower($prefill['email']) === $email) {
    $googleId = $prefill['google_id'];
}

// Google OAuth registration: bypass email verification
if ($googleId) {
    $stmt = $connAccounts->prepare(
        'INSERT INTO accounts (first_name, last_name, username, email, password, google_id)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('ssssss', $firstName, $lastName, $username, $email, $hashedPassword, $googleId);

    if ($stmt->execute()) {
        $userId = $connAccounts->insert_id;
        begin_user_session([
            'id'         => $userId,
            'username'   => $username,
            'email'      => $email,
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'role'       => 'user',
            'google_id'  => $googleId,
        ], true, 'google');
        clear_google_registration_prefill();
        $stmt->close();
        header('Location: ../post/post.php');
        exit;
    }

    $errorMessage = $stmt->error;
    $stmt->close();
    die('Registration failed: ' . $errorMessage);
}

// Regular email registration — check username uniqueness in pending_registrations too
$connPending = db_connect('pending_registrations');
$pendingUserCheck = $connPending->prepare('SELECT id FROM pending_registrations WHERE username = ? AND email != ?');
$pendingUserCheck->bind_param('ss', $username, $email);
$pendingUserCheck->execute();
$pendingUserCheck->store_result();
if ($pendingUserCheck->num_rows > 0) {
    $pendingUserCheck->close();
    header('Location: ../register/register.html?error=username_taken');
    exit;
}
$pendingUserCheck->close();

// Generate 6-digit verification code
$code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

// Upsert into pending_registrations
$stmt = $connPending->prepare(
    'INSERT INTO pending_registrations (first_name, last_name, username, email, password_hash, verify_code, code_expires_at)
     VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 60 MINUTE))
     ON DUPLICATE KEY UPDATE
       first_name=VALUES(first_name), last_name=VALUES(last_name), username=VALUES(username),
       password_hash=VALUES(password_hash), verify_code=VALUES(verify_code),
       code_expires_at=DATE_ADD(NOW(), INTERVAL 60 MINUTE), updated_at=NOW()'
);
$stmt->bind_param('ssssss', $firstName, $lastName, $username, $email, $hashedPassword, $code);

if (!$stmt->execute()) {
    $errorMessage = $stmt->error;
    $stmt->close();
    die('Pending registration failed: ' . $errorMessage);
}
$stmt->close();

// Send verification email
$displayName = trim($firstName . ' ' . $lastName);
$mailSent = send_registration_verification_email($email, $displayName, $code);

if (!$mailSent) {
    if (!smtp_is_configured()) {
        header('Location: ../register/register.html?error=smtp_not_configured');
    } else {
        header('Location: ../register/register.html?error=email_failed');
    }
    exit;
}

$_SESSION['pending_reg_email']   = $email;
$_SESSION['pending_reg_sent_at'] = time();

header('Location: verify_registration.php');
exit;
