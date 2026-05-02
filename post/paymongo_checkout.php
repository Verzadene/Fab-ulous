<?php
session_start();
require_once __DIR__ . '/../config.php';

if (empty($_SESSION['user'])) {
    header('Location: ../login/login.php');
    exit;
}

if (empty($_SESSION['mfa_verified'])) {
    header('Location: ../login/verify_mfa.php');
    exit;
}

function paymongo_is_configured(): bool
{
    return PAYMONGO_SECRET_KEY !== ''
        && strpos(PAYMONGO_SECRET_KEY, 'REPLACE_WITH_PAYMONGO_SECRET_KEY') === false;
}

function redirect_with_payment_error(string $message): void
{
    header('Location: commissions.php?payment=error&message=' . urlencode($message));
    exit;
}

function paymongo_payment_methods(): array
{
    $methods = array_filter(array_map('trim', explode(',', PAYMONGO_PAYMENT_METHOD_TYPES)));
    return $methods ?: ['card', 'gcash'];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_with_payment_error('Invalid payment request.');
}

if (!paymongo_is_configured()) {
    redirect_with_payment_error('PayMongo is not configured yet. Add PAYMONGO_SECRET_KEY in config.php or config.local.php.');
}

if (!function_exists('curl_init')) {
    redirect_with_payment_error('PHP cURL is required before PayMongo checkout can run.');
}

$commissionId = (int)($_POST['commission_id'] ?? 0);
$userId = (int)$_SESSION['user']['id'];

if (!$commissionId) {
    redirect_with_payment_error('Commission not found.');
}

$conn = db_connect();

$hasPayments = (bool)$conn->query("SHOW TABLES LIKE 'commission_payments'")->num_rows;
if (!$hasPayments) {
    $conn->close();
    redirect_with_payment_error('Payment table is missing. Run database/migration_v6_paymongo.sql.');
}

$commissionColumns = [];
$columnsResult = $conn->query('SHOW COLUMNS FROM commissions');
while ($columnsResult && $column = $columnsResult->fetch_assoc()) {
    $commissionColumns[$column['Field']] = true;
}
$titleExpr = isset($commissionColumns['commission_name'])
    ? "COALESCE(NULLIF(c.commission_name, ''), c.description)"
    : (isset($commissionColumns['title'])
        ? "COALESCE(NULLIF(c.title, ''), c.description)"
        : 'c.description');

$stmt = $conn->prepare(
    "SELECT c.commissionID, c.amount, c.status,
            {$titleExpr} AS title,
            a.id AS userID, a.email, CONCAT(a.first_name, ' ', a.last_name) AS payer_name
     FROM commissions c
     JOIN accounts a ON a.id = c.userID
     WHERE c.commissionID = ? AND c.userID = ?
     LIMIT 1"
);
$stmt->bind_param('ii', $commissionId, $userId);
$stmt->execute();
$commission = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$commission) {
    $conn->close();
    redirect_with_payment_error('You can only pay for your own commission.');
}

$amount = round((float)$commission['amount'], 2);
if ($amount <= 0) {
    $conn->close();
    redirect_with_payment_error('This commission does not have a payable amount yet.');
}

$paidCheck = $conn->prepare(
    "SELECT paymentID FROM commission_payments
     WHERE commissionID = ? AND userID = ? AND status = 'paid'
     LIMIT 1"
);
$paidCheck->bind_param('ii', $commissionId, $userId);
$paidCheck->execute();
$paidCheck->store_result();
if ($paidCheck->num_rows > 0) {
    $paidCheck->close();
    $conn->close();
    redirect_with_payment_error('This commission has already been paid.');
}
$paidCheck->close();

$payerName = trim((string)$commission['payer_name']);
$payerEmail = (string)$commission['email'];
$amountInCentavos = (int)round($amount * 100);
$title = mb_substr(trim((string)$commission['title']), 0, 120) ?: 'FABulous Commission';

$ins = $conn->prepare(
    "INSERT INTO commission_payments (commissionID, userID, payer_name, payer_email, amount, currency, status)
     VALUES (?, ?, ?, ?, ?, 'PHP', 'pending')"
);
$ins->bind_param('iissd', $commissionId, $userId, $payerName, $payerEmail, $amount);
$ok = $ins->execute();
$paymentId = (int)$conn->insert_id;
$ins->close();

if (!$ok || !$paymentId) {
    $conn->close();
    redirect_with_payment_error('Could not prepare payment record.');
}

$successUrl = APP_URL . '/post/commissions.php?payment=success&commission_id=' . $commissionId;
$cancelUrl = APP_URL . '/post/commissions.php?payment=cancelled&commission_id=' . $commissionId;

$payload = [
    'data' => [
        'attributes' => [
            'send_email_receipt' => true,
            'show_description' => true,
            'show_line_items' => true,
            'payment_method_types' => paymongo_payment_methods(),
            'description' => 'FABulous commission #' . $commissionId,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'billing' => [
                'name' => $payerName,
                'email' => $payerEmail,
            ],
            'line_items' => [
                [
                    'name' => $title,
                    'description' => 'Commission #' . $commissionId,
                    'amount' => $amountInCentavos,
                    'currency' => 'PHP',
                    'quantity' => 1,
                ],
            ],
            'metadata' => [
                'commission_id' => (string)$commissionId,
                'payment_id' => (string)$paymentId,
                'user_id' => (string)$userId,
            ],
        ],
    ],
];

$ch = curl_init(PAYMONGO_API_BASE . '/checkout_sessions');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 30,
]);

$raw = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

$response = $raw ? json_decode($raw, true) : null;
$attributes = $response['data']['attributes'] ?? [];
$checkoutId = $response['data']['id'] ?? null;
$checkoutUrl = $attributes['checkout_url'] ?? null;
$reference = $attributes['reference_number'] ?? null;

if ($httpCode < 200 || $httpCode >= 300 || !$checkoutId || !$checkoutUrl) {
    $err = $response['errors'][0]['detail'] ?? $curlError ?: 'PayMongo checkout could not be created.';
    $fail = $conn->prepare("UPDATE commission_payments SET status = 'failed' WHERE paymentID = ?");
    $fail->bind_param('i', $paymentId);
    $fail->execute();
    $fail->close();
    $conn->close();
    redirect_with_payment_error($err);
}

$upd = $conn->prepare(
    "UPDATE commission_payments
     SET paymongo_checkout_id = ?, paymongo_reference = ?, checkout_url = ?
     WHERE paymentID = ?"
);
$upd->bind_param('sssi', $checkoutId, $reference, $checkoutUrl, $paymentId);
$upd->execute();
$upd->close();
$conn->close();

header('Location: ' . $checkoutUrl);
exit;
