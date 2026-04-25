<?php
session_start();

// ── MySQL Connection ───────────────────────────────────────
$conn = new mysqli("localhost", "root", "", "fab_ulous");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ── OAuth credentials ──────────────────────────────────────
$client_id     = '313306839766-5be832449af0f4lf0autei7oogm2ra5f.apps.googleusercontent.com';
$client_secret = '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';
$redirect_uri  = 'http://localhost/Fab-ulous/oauth/oauth2callback.php';

if (!isset($_GET['code'])) {
    die("Error: No authorization code received.");
}

$code = $_GET['code'];

// ── Step 1: Exchange code for access token ─────────────────
$token_url   = 'https://oauth2.googleapis.com/token';
$post_fields = [
    'code'          => $code,
    'client_id'     => $client_id,
    'client_secret' => $client_secret,
    'redirect_uri'  => $redirect_uri,
    'grant_type'    => 'authorization_code'
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $token_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // XAMPP localhost SSL bypass
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

$response = curl_exec($ch);
if (curl_errno($ch)) {
    die("Curl error (token): " . curl_error($ch));
}
curl_close($ch);

$token_data = json_decode($response, true);
if (!isset($token_data['access_token'])) {
    die("No access token received:<br><pre>$response</pre>");
}

$access_token = $token_data['access_token'];

// ── Step 2: Fetch Google user info ─────────────────────────
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://www.googleapis.com/oauth2/v1/userinfo?alt=json');
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $access_token]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

$user_info = curl_exec($ch);
if (curl_errno($ch)) {
    die("Curl error (user info): " . curl_error($ch));
}
curl_close($ch);

$user      = json_decode($user_info, true);
$google_id = $user['id'];
$fullName  = $user['name'];
$email     = $user['email'];

// Split name into first / last for accounts table
$nameParts = explode(' ', $fullName, 2);
$firstName = $nameParts[0];
$lastName  = isset($nameParts[1]) ? $nameParts[1] : '';

// ── Step 3: Duplicate check ────────────────────────────────
// If the email already exists (registered manually or via Google before),
// just log them in — do NOT create a second account.
$checkStmt = $conn->prepare("SELECT * FROM accounts WHERE email = ?");
$checkStmt->bind_param("s", $email);
$checkStmt->execute();
$result   = $checkStmt->get_result();
$existing = $result->fetch_assoc();
$checkStmt->close();

if (!$existing) {
    // New user — insert into accounts
    // USERNAME derived from Google name (lowercase, no spaces)
    $username = strtolower(str_replace(' ', '', $fullName));

    // If that username is already taken, append a random number
    $unameCheck = $conn->prepare("SELECT id FROM accounts WHERE username = ?");
    $unameCheck->bind_param("s", $username);
    $unameCheck->execute();
    $unameCheck->store_result();
    if ($unameCheck->num_rows > 0) {
        $username = $username . rand(100, 999);
    }
    $unameCheck->close();

    // PASSWORD is empty for Google accounts — they never use a password
    $insertStmt = $conn->prepare(
        "INSERT INTO accounts (first_name, last_name, username, email, password, google_id)
         VALUES (?, ?, ?, ?, '', ?)"
    );
    $insertStmt->bind_param("sssss", $firstName, $lastName, $username, $email, $google_id);

    if (!$insertStmt->execute()) {
        die("Insert failed: " . $insertStmt->error);
    }
    $insertStmt->close();
}

// ── Step 4: Set session and go to dashboard ────────────────
$_SESSION['user'] = [
    'name'      => $fullName,
    'email'     => $email,
    'google_id' => $google_id
];

$conn->close();
header('Location: ../post/post.html');
exit;
?>