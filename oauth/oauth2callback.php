<?php
session_start();
require_once __DIR__ . '/../config.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

if (!isset($_GET['code'])) {
    die("Error: No authorization code received.");
}

// ── Step 1: Exchange auth code for access token ───────────────────
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => 'https://oauth2.googleapis.com/token',
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'code'          => $_GET['code'],
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'grant_type'    => 'authorization_code',
    ]),
    CURLOPT_RETURNTRANSFER => true,
    // SSL verification is disabled because XAMPP on Windows does not ship a
    // CA-certificate bundle by default.  In production, remove these two lines
    // and ensure curl.cainfo points to a valid cacert.pem in php.ini.
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);
$tokenResponse = curl_exec($ch);
if (curl_errno($ch)) { die("Curl error (token): " . curl_error($ch)); }
curl_close($ch);

$tokenData = json_decode($tokenResponse, true);
if (empty($tokenData['access_token'])) {
    die("No access token received.<br><pre>" . htmlspecialchars($tokenResponse) . "</pre>");
}

// ── Step 2: Fetch Google user profile ─────────────────────────────
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

$gUser = json_decode($userInfoRaw, true);

if (empty($gUser['id']) || empty($gUser['email'])) {
    die("Could not retrieve Google profile.");
}

$googleID  = $gUser['id'];
$fullName  = trim($gUser['name'] ?? 'Google User');
$email     = strtolower(trim($gUser['email']));
$nameParts = explode(' ', $fullName, 2);
$firstName = $nameParts[0];
$lastName  = $nameParts[1] ?? '';

// ── Step 3: Upsert account ────────────────────────────────────────
$check = $conn->prepare("SELECT * FROM accounts WHERE email = ?");
$check->bind_param("s", $email);
$check->execute();
$existing = $check->get_result()->fetch_assoc();
$check->close();

if ($existing) {
    $userID   = $existing['id'];
    $username = $existing['username'];
    $userRole = $existing['role'] ?? 'user';

    if ($existing['banned']) {
        $conn->close();
        header('Location: ../login/login.php?error=banned');
        exit;
    }

    // Link google_id if this account was originally email-registered
    if (empty($existing['google_id'])) {
        $upd = $conn->prepare("UPDATE accounts SET google_id = ? WHERE id = ?");
        $upd->bind_param("si", $googleID, $userID);
        $upd->execute();
        $upd->close();
    }
} else {
    // Derive a unique username from the Google display name
    $base     = strtolower(preg_replace('/[^a-z0-9]/i', '', $fullName));
    $base     = $base ?: 'user';
    $username = $base;

    $uCheck = $conn->prepare("SELECT id FROM accounts WHERE username = ?");
    $uCheck->bind_param("s", $username);
    $uCheck->execute();
    $uCheck->store_result();
    if ($uCheck->num_rows > 0) {
        $username = $base . rand(100, 9999);
    }
    $uCheck->close();

    $ins = $conn->prepare(
        "INSERT INTO accounts (first_name, last_name, username, email, password, google_id, role)
         VALUES (?, ?, ?, ?, '', ?, 'user')"
    );
    $ins->bind_param("sssss", $firstName, $lastName, $username, $email, $googleID);
    if (!$ins->execute()) { die("Registration failed: " . $ins->error); }
    $userID   = $conn->insert_id;
    $userRole = 'user';
    $ins->close();
}

// ── Step 4: Set session and redirect ─────────────────────────────
$_SESSION['user'] = [
    'id'        => $userID,
    'username'  => $username,
    'name'      => $fullName,
    'email'     => $email,
    'google_id' => $googleID,
    'role'      => $userRole,
];

$conn->close();

$dest = in_array($userRole, ['admin', 'super_admin'])
      ? '../admin/admin.php'
      : '../post/post.php';
header('Location: ' . $dest);
exit;
?>
