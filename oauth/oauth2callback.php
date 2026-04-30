<?php
session_start();
require_once __DIR__ . '/../config.php';

$conn = db_connect();

if (trim(GOOGLE_CLIENT_SECRET) === '') {
    $conn->close();
    header('Location: ../login/login.php?error=google_oauth_config');
    exit;
}

if (!isset($_GET['code'])) {
    $conn->close();
    header('Location: ../login/login.php?error=oauth_exchange_failed');
    exit;
}

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://oauth2.googleapis.com/token',
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'code' => $_GET['code'],
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
        'grant_type' => 'authorization_code',
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);
$tokenResponse = curl_exec($ch);
$tokenError = curl_errno($ch) ? curl_error($ch) : null;
curl_close($ch);

if ($tokenError) {
    $conn->close();
    header('Location: ../login/login.php?error=oauth_exchange_failed');
    exit;
}

$tokenData = json_decode((string) $tokenResponse, true);
if (empty($tokenData['access_token'])) {
    $conn->close();
    header('Location: ../login/login.php?error=oauth_exchange_failed');
    exit;
}

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://www.googleapis.com/oauth2/v1/userinfo?alt=json',
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tokenData['access_token']],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);
$userInfoRaw = curl_exec($ch);
$userInfoError = curl_errno($ch) ? curl_error($ch) : null;
curl_close($ch);

if ($userInfoError) {
    $conn->close();
    header('Location: ../login/login.php?error=oauth_exchange_failed');
    exit;
}

$gUser = json_decode((string) $userInfoRaw, true);
if (
    empty($gUser['id'])
    || empty($gUser['email'])
    || (isset($gUser['verified_email']) && !$gUser['verified_email'])
) {
    $conn->close();
    header('Location: ../login/login.php?error=oauth_exchange_failed');
    exit;
}

$googleId = $gUser['id'];
$fullName = trim($gUser['name'] ?? 'Google User');
$email = strtolower(trim($gUser['email']));
$nameParts = preg_split('/\s+/', $fullName, 2) ?: [];
$firstName = $nameParts[0] ?? 'Google';
$lastName = $nameParts[1] ?? 'User';

$check = $conn->prepare('SELECT * FROM accounts WHERE google_id = ? OR email = ? LIMIT 1');
$check->bind_param('ss', $googleId, $email);
$check->execute();
$existing = $check->get_result()->fetch_assoc();
$check->close();

if (!$existing) {
    prime_google_registration_prefill($email, $fullName, $googleId);
    $conn->close();
    header('Location: ../login/login.php?error=google_account_missing');
    exit;
}

if (!empty($existing['banned'])) {
    $conn->close();
    header('Location: ../login/login.php?error=banned');
    exit;
}

if (empty($existing['google_id'])) {
    $link = $conn->prepare('UPDATE accounts SET google_id = ? WHERE id = ?');
    $link->bind_param('si', $googleId, $existing['id']);
    $link->execute();
    $link->close();
    $existing['google_id'] = $googleId;
}

clear_google_registration_prefill();
begin_user_session([
    'id' => (int) $existing['id'],
    'username' => $existing['username'],
    'email' => $existing['email'],
    'first_name' => $existing['first_name'] ?? $firstName,
    'last_name' => $existing['last_name'] ?? $lastName,
    'role' => $existing['role'] ?? 'user',
    'google_id' => $existing['google_id'] ?? $googleId,
], true, 'google');

$conn->close();
header('Location: ' . dashboard_path_for_role($existing['role'] ?? 'user'));
exit;
