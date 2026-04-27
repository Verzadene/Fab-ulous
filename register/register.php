<?php
session_start();
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: register.html');
    exit;
}

$conn = db_connect();

$password = $_POST['password'] ?? '';
$confirmPassword = $_POST['confirmPassword'] ?? '';

if (
    strlen($password) < 16
    || preg_match_all('/[^a-zA-Z0-9]/', $password) < 2
    || preg_match_all('/[0-9]/', $password) < 2
) {
    $conn->close();
    header('Location: ../register/register.html?error=weak_password');
    exit;
}

if ($password !== $confirmPassword) {
    $conn->close();
    header('Location: ../register/register.html?error=password_mismatch');
    exit;
}

$firstName = trim($_POST['firstName'] ?? '');
$lastName = trim($_POST['lastName'] ?? '');
$username = trim($_POST['username'] ?? '');
$email = strtolower(trim($_POST['email'] ?? ''));

$checkStmt = $conn->prepare('SELECT email, username FROM accounts WHERE email = ? OR username = ?');
$checkStmt->bind_param('ss', $email, $username);
$checkStmt->execute();
$existing = $checkStmt->get_result()->fetch_assoc();
$checkStmt->close();

if ($existing) {
    $conn->close();
    $errCode = ($existing['email'] === $email) ? 'email_taken' : 'username_taken';
    header("Location: ../register/register.html?error={$errCode}");
    exit;
}

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);
$prefill = get_google_registration_prefill();
$googleId = null;

if (!empty($prefill['google_id']) && !empty($prefill['email']) && strtolower($prefill['email']) === $email) {
    $googleId = $prefill['google_id'];
}

if ($googleId) {
    $stmt = $conn->prepare(
        'INSERT INTO accounts (first_name, last_name, username, email, password, google_id)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('ssssss', $firstName, $lastName, $username, $email, $hashedPassword, $googleId);
} else {
    $stmt = $conn->prepare(
        'INSERT INTO accounts (first_name, last_name, username, email, password)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('sssss', $firstName, $lastName, $username, $email, $hashedPassword);
}

if ($stmt->execute()) {
    $userId = $conn->insert_id;
    begin_user_session([
        'id' => $userId,
        'username' => $username,
        'email' => $email,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'role' => 'user',
        'google_id' => $googleId,
    ], true, $googleId ? 'google' : 'password');
    clear_google_registration_prefill();
    $stmt->close();
    $conn->close();
    header('Location: ../post/post.php');
    exit;
}

$errorMessage = $stmt->error;
$stmt->close();
$conn->close();
die('Registration failed: ' . $errorMessage);
