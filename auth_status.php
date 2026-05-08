<?php
/**
 * auth_status.php
 *
 * Lightweight session-status endpoint consumed by pure-HTML pages
 * (landing.html, register.html) that cannot run server-side PHP redirects.
 *
 * Response (always JSON):
 *   { "authenticated": true,  "redirect": "../post/post.php"  }
 *   { "authenticated": true,  "redirect": "../admin/admin.php" }
 *   { "authenticated": false }
 *
 * Place this file at: /Fab-ulous/auth_status.php  (project root)
 */

session_start();
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

// Full session: user record present AND MFA step completed
if (!empty($_SESSION['user']) && !empty($_SESSION['mfa_verified'])) {
    $role     = $_SESSION['user']['role'] ?? 'user';
    $redirect = dashboard_path_for_role($role);
    echo json_encode(['authenticated' => true, 'redirect' => $redirect]);
    exit;
}

echo json_encode(['authenticated' => false]);
