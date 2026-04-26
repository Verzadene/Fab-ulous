<?php
/**
 * FABulous - centralised configuration.
 * Include with: require_once __DIR__ . '/../config.php';   (from subdirs)
 *               require_once __DIR__ . '/config.php';       (from root)
 *
 * Do NOT commit real secrets to a public repository.
 * For local development, create config.local.php and define any secrets there.
 */

// Load untracked local overrides first.
$localConfig = __DIR__ . '/config.local.php';
if (file_exists($localConfig)) {
    require $localConfig;
}

// Google OAuth
defined('GOOGLE_CLIENT_ID') || define(
    'GOOGLE_CLIENT_ID',
    getenv('GOOGLE_CLIENT_ID') ?: '313306839766-5be832449af0f4lf0autei7oogm2ra5f.apps.googleusercontent.com'
);
defined('GOOGLE_CLIENT_SECRET') || define(
    'GOOGLE_CLIENT_SECRET',
    getenv('GOOGLE_CLIENT_SECRET') ?: ''
);
defined('GOOGLE_REDIRECT_URI') || define(
    'GOOGLE_REDIRECT_URI',
    getenv('GOOGLE_REDIRECT_URI') ?: 'http://localhost/Fab-ulous/oauth/oauth2callback.php'
);

// Database
defined('DB_HOST') || define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
defined('DB_USER') || define('DB_USER', getenv('DB_USER') ?: 'root');
defined('DB_PASS') || define('DB_PASS', getenv('DB_PASS') ?: '');
defined('DB_NAME') || define('DB_NAME', getenv('DB_NAME') ?: 'fab_ulous');

/**
 * Returns an open MySQLi connection.
 * Caller is responsible for closing it.
 */
function db_connect(): mysqli
{
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        http_response_code(500);
        die('Database connection failed.');
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}
