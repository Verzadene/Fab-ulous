<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

function paymongo_webhook_is_configured(): bool
{
    return PAYMONGO_WEBHOOK_SECRET !== ''
        && strpos(PAYMONGO_WEBHOOK_SECRET, 'REPLACE_WITH_PAYMONGO_WEBHOOK_SECRET') === false;
}

function paymongo_parse_signature(string $header): array
{
    $parts = [];
    foreach (explode(',', $header) as $piece) {
        [$key, $value] = array_pad(explode('=', trim($piece), 2), 2, '');
        if ($key !== '') {
            $parts[$key] = $value;
        }
    }
    return $parts;
}

function paymongo_signature_is_valid(string $payload, string $header): bool
{
    if (!paymongo_webhook_is_configured()) {
        return false;
    }

    $parts = paymongo_parse_signature($header);
    $timestamp = $parts['t'] ?? '';
    if ($timestamp === '') {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, PAYMONGO_WEBHOOK_SECRET);
    $testSignature = $parts['te'] ?? '';
    $liveSignature = $parts['li'] ?? '';

    return ($testSignature !== '' && hash_equals($expected, $testSignature))
        || ($liveSignature !== '' && hash_equals($expected, $liveSignature));
}

$payload = file_get_contents('php://input') ?: '';
$signatureHeader = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';

if (!paymongo_signature_is_valid($payload, $signatureHeader)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid PayMongo signature.']);
    exit;
}

$event = json_decode($payload, true);
if (!is_array($event)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON payload.']);
    exit;
}

$eventId = $event['data']['id'] ?? null;
$eventType = $event['data']['attributes']['type'] ?? '';
$resource = $event['data']['attributes']['data'] ?? [];
$resourceAttributes = $resource['attributes'] ?? [];

if (!in_array($eventType, ['checkout_session.payment.paid', 'payment.paid'], true)) {
    echo json_encode(['success' => true, 'ignored' => true]);
    exit;
}

$checkoutId = null;
$paymentId = null;
$commissionId = null;
$localPaymentId = null;
$reference = null;

if ($eventType === 'checkout_session.payment.paid') {
    $checkoutId = $resource['id'] ?? null;
    $reference = $resourceAttributes['reference_number'] ?? null;
    $metadata = $resourceAttributes['metadata'] ?? [];
    $commissionId = isset($metadata['commission_id']) ? (int)$metadata['commission_id'] : null;
    $localPaymentId = isset($metadata['payment_id']) ? (int)$metadata['payment_id'] : null;
    $payments = $resourceAttributes['payments'] ?? [];
    if (!empty($payments[0]['id'])) {
        $paymentId = $payments[0]['id'];
    }
} elseif ($eventType === 'payment.paid') {
    $paymentId = $resource['id'] ?? null;
    $metadata = $resourceAttributes['metadata'] ?? [];
    $commissionId = isset($metadata['commission_id']) ? (int)$metadata['commission_id'] : null;
    $localPaymentId = isset($metadata['payment_id']) ? (int)$metadata['payment_id'] : null;
}

$conn = db_connect();
$hasPayments = (bool)$conn->query("SHOW TABLES LIKE 'commission_payments'")->num_rows;
if (!$hasPayments) {
    $conn->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'commission_payments table is missing.']);
    exit;
}

if ($localPaymentId) {
    $stmt = $conn->prepare(
        "UPDATE commission_payments
         SET status = 'paid',
             paymongo_checkout_id = COALESCE(?, paymongo_checkout_id),
             paymongo_payment_id = COALESCE(?, paymongo_payment_id),
             paymongo_reference = COALESCE(?, paymongo_reference),
             webhook_event_id = ?,
             paid_at = COALESCE(paid_at, NOW())
         WHERE paymentID = ?"
    );
    $stmt->bind_param('ssssi', $checkoutId, $paymentId, $reference, $eventId, $localPaymentId);
} else {
    $stmt = $conn->prepare(
        "UPDATE commission_payments
         SET status = 'paid',
             paymongo_payment_id = COALESCE(?, paymongo_payment_id),
             paymongo_reference = COALESCE(?, paymongo_reference),
             webhook_event_id = ?,
             paid_at = COALESCE(paid_at, NOW())
         WHERE paymongo_checkout_id = ?"
    );
    $stmt->bind_param('ssss', $paymentId, $reference, $eventId, $checkoutId);
}

$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if ($commissionId && $affected > 0) {
    $commissionOwnerId = 0;
    $ownerStmt = $conn->prepare("SELECT userID FROM commissions WHERE commissionID = ? LIMIT 1");
    if ($ownerStmt) {
        $ownerStmt->bind_param('i', $commissionId);
        $ownerStmt->execute();
        $ownerRow = $ownerStmt->get_result()->fetch_assoc();
        $ownerStmt->close();
        $commissionOwnerId = (int)($ownerRow['userID'] ?? 0);
    }

    if ($commissionOwnerId > 0) {
        create_notification($conn, $commissionOwnerId, $commissionOwnerId, 'commission_paid', null, $commissionId);

        $admins = $conn->query("SELECT id FROM accounts WHERE role IN ('admin','super_admin') AND banned = 0");
        if ($admins) {
            while ($admin = $admins->fetch_assoc()) {
                create_notification($conn, (int)$admin['id'], $commissionOwnerId, 'commission_paid', null, $commissionId);
            }
        }
    }

    $log = $conn->prepare(
        "INSERT INTO audit_log (admin_id, admin_username, action, target_type, target_id, visibility_role)
         VALUES (0, 'PayMongo', ?, 'commission', ?, 'admin')"
    );
    if ($log) {
        $action = "Marked commission #{$commissionId} payment as paid";
        $log->bind_param('si', $action, $commissionId);
        $log->execute();
        $log->close();
    }
}

$conn->close();
echo json_encode(['success' => true, 'updated' => $affected > 0]);
