<?php
$conn = new mysqli("localhost", "root", "", "fab_ulous");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$password        = $_POST['password']        ?? '';
$confirmPassword = $_POST['confirmPassword'] ?? '';

// Server-side password validation
if (strlen($password) < 16 ||
    preg_match_all('/[^a-zA-Z0-9]/', $password) < 2 ||
    preg_match_all('/[0-9]/', $password) < 2) {
    header("Location: ../register/register.html?error=weak_password");
    exit;
}

if ($password !== $confirmPassword) {
    header("Location: ../register/register.html?error=password_mismatch");
    exit;
}

$firstName = trim($_POST['firstName'] ?? '');
$lastName  = trim($_POST['lastName']  ?? '');
$username  = trim($_POST['username']  ?? '');
$email     = trim($_POST['email']     ?? '');

// Duplicate check
$checkStmt = $conn->prepare("SELECT email, username FROM accounts WHERE email = ? OR username = ?");
$checkStmt->bind_param("ss", $email, $username);
$checkStmt->execute();
$existing = $checkStmt->get_result()->fetch_assoc();
$checkStmt->close();

if ($existing) {
    $errCode = ($existing['email'] === $email) ? 'email_taken' : 'username_taken';
    header("Location: ../register/register.html?error=$errCode");
    exit;
}

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

$stmt = $conn->prepare(
    "INSERT INTO accounts (first_name, last_name, username, email, password) VALUES (?, ?, ?, ?, ?)"
);
$stmt->bind_param("sssss", $firstName, $lastName, $username, $email, $hashedPassword);

if ($stmt->execute()) {
    $userID = $conn->insert_id;
    session_start();
    $_SESSION['user'] = [
        'id'       => $userID,
        'username' => $username,
        'email'    => $email,
        'name'     => $firstName . ' ' . $lastName,
        'role'     => 'user'
    ];
    $stmt->close();
    $conn->close();
    header("Location: ../post/post.php");
    exit;
} else {
    die("Registration failed: " . $stmt->error);
}
?>
