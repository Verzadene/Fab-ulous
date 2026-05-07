<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/PaymentRepository.php';

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

$repo = new PaymentRepository('db_connect');
if (!$repo->checkPaymentsTableExists()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'commission_payments table is missing.']);
    exit;
}

try {
    $affected = $repo->processWebhookPayment($eventId, $eventType, $resource);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Repository processing failed: ' . $e->getMessage()]);
    exit;
}
echo json_encode(['success' => true, 'updated' => $affected > 0]);
