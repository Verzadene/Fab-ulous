<?php
session_start();
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$prefill = get_google_registration_prefill();
echo json_encode([
    'success' => true,
    'prefill' => [
        'firstName' => $prefill['first_name'] ?? '',
        'lastName' => $prefill['last_name'] ?? '',
        'email' => $prefill['email'] ?? '',
        'googleLinked' => !empty($prefill['google_id']),
        'fullName' => $prefill['full_name'] ?? '',
    ],
]);
