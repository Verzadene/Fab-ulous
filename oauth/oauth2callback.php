<?php
session_start();

$conn = new mysqli("localhost", "root", "", "fab_ulous");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$client_id     = '313306839766-5be832449af0f4lf0autei7oogm2ra5f.apps.googleusercontent.com';
$client_secret = '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';
$redirect_uri  = 'http://localhost/Fab-ulous/oauth/oauth2callback.php';

if (!isset($_GET['code'])) {
    die("Error: No authorization code received.");
}

// Step 1: Exchange code for access token
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => 'https://oauth2.googleapis.com/token',
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'code'          => $_GET['code'],
        'client_id'     => $client_id,
        'client_secret' => $client_secret,
        'redirect_uri'  => $redirect_uri,
        'grant_type'    => 'authorization_code'
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);
$tokenResponse = curl_exec($ch);
if (curl_errno($ch)) { die("Curl error (token): " . curl_error($ch)); }
curl_close($ch);

$tokenData = json_decode($tokenResponse, true);
if (!isset($tokenData['access_token'])) {
    die("No access token received:<br><pre>$tokenResponse</pre>");
}

// Step 2: Fetch Google user info
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => 'https://www.googleapis.com/oauth2/v1/userinfo?alt=json',
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $tokenData['access_token']],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);
$userInfoRaw = curl_exec($ch);
if (curl_errno($ch)) { die("Curl error (user info): " . curl_error($ch)); }
curl_close($ch);

$gUser     = json_decode($userInfoRaw, true);
$googleID  = $gUser['id'];
$fullName  = $gUser['name'];
$email     = $gUser['email'];
$nameParts = explode(' ', $fullName, 2);
$firstName = $nameParts[0];
$lastName  = $nameParts[1] ?? '';

// Step 3: Check for existing account (duplicate guard)
$check = $conn->prepare("SELECT * FROM accounts WHERE email = ?");
$check->bind_param("s", $email);
$check->execute();
$existing = $check->get_result()->fetch_assoc();
$check->close();

if ($existing) {
    // Account exists — log them in; link google_id if missing
    $userID   = $existing['id'];
    $username = $existing['username'];
    $userRole = $existing['role'] ?? 'user';

    if (empty($existing['google_id'])) {
        $upd = $conn->prepare("UPDATE accounts SET google_id = ? WHERE id = ?");
        $upd->bind_param("si", $googleID, $userID);
        $upd->execute();
        $upd->close();
    }
} else {
    // New user — derive unique username and insert
    $username = strtolower(str_replace(' ', '', $fullName));

    $unameCheck = $conn->prepare("SELECT id FROM accounts WHERE username = ?");
    $unameCheck->bind_param("s", $username);
    $unameCheck->execute();
    $unameCheck->store_result();
    if ($unameCheck->num_rows > 0) {
        $username .= rand(100, 999);
    }
    $unameCheck->close();

    $ins = $conn->prepare(
        "INSERT INTO accounts (first_name, last_name, username, email, password, google_id, role)
         VALUES (?, ?, ?, ?, '', ?, 'user')"
    );
    $ins->bind_param("sssss", $firstName, $lastName, $username, $email, $googleID);
    if (!$ins->execute()) { die("Insert failed: " . $ins->error); }
    $userID   = $conn->insert_id;
    $userRole = 'user';
    $ins->close();
}

// Step 4: Set session and redirect
$_SESSION['user'] = [
    'id'        => $userID,
    'username'  => $username,
    'name'      => $fullName,
    'email'     => $email,
    'google_id' => $googleID,
    'role'      => $userRole
];

$conn->close();
header($userRole === 'admin' ? 'Location: ../admin/admin.php' : 'Location: ../post/post.php');
exit;
?>
