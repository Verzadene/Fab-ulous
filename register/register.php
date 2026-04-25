<?php
// ── MySQL Connection ───────────────────────────────────────
// Change "root" and "" to your MySQL username and password if different
$conn = new mysqli("localhost", "root", "", "fab_ulous");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ── Server-side password validation ───────────────────────
$password = $_POST['password'];

if (strlen($password) < 16) {
    die("Password must be at least 16 characters.");
}
if (preg_match_all('/[^a-zA-Z0-9]/', $password) < 2) {
    die("Password must contain at least 2 special characters.");
}
if (preg_match_all('/[0-9]/', $password) < 2) {
    die("Password must contain at least 2 numbers.");
}

$firstName = $_POST['firstName'];
$lastName  = $_POST['lastName'];
$username  = $_POST['username'];
$email     = $_POST['email'];

// ── Duplicate check: same email OR same username ───────────
// Prevents creating a duplicate account whether the user
// previously signed up manually or via Google OAuth.
$checkStmt = $conn->prepare("SELECT * FROM accounts WHERE email = ? OR username = ?");
$checkStmt->bind_param("ss", $email, $username);
$checkStmt->execute();
$result   = $checkStmt->get_result();
$existing = $result->fetch_assoc();
$checkStmt->close();

if ($existing) {
    if ($existing['email'] === $email) {
        header("Location: ../register/register.html?error=email_taken");
    } else {
        header("Location: ../register/register.html?error=username_taken");
    }
    exit;
}

// ── Insert new account ─────────────────────────────────────
$stmt = $conn->prepare(
    "INSERT INTO accounts (first_name, last_name, username, email, password)
     VALUES (?, ?, ?, ?, ?)"
);
$stmt->bind_param("sssss", $firstName, $lastName, $username, $email, $password);

if ($stmt->execute()) {
    $stmt->close();
    $conn->close();
    header("Location: ../post/post.html");
    exit;
} else {
    die("Registration failed: " . $stmt->error);
}
?>