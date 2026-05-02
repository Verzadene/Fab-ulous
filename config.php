<?php
/**
 * FABulous - centralised configuration (single source of truth).
 * Include with: require_once __DIR__ . '/../config.php';   (from subdirs)
 *               require_once __DIR__ . '/config.php';       (from root)
 */

$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    require_once $localConfig;
}

// Google OAuth
defined('GOOGLE_CLIENT_ID') || define(
    'GOOGLE_CLIENT_ID',
    getenv('GOOGLE_CLIENT_ID') ?: '313306839766-5be832449af0f4lf0autei7oogm2ra5f.apps.googleusercontent.com'
);
defined('GOOGLE_CLIENT_SECRET') || define(
    'GOOGLE_CLIENT_SECRET',
    getenv('GOOGLE_CLIENT_SECRET') ?: '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'
);
defined('GOOGLE_REDIRECT_URI') || define(
    'GOOGLE_REDIRECT_URI',
    getenv('GOOGLE_REDIRECT_URI') ?: 'http://localhost/Fab-ulous/oauth/oauth2callback.php'
);
defined('APP_ENV') || define('APP_ENV', getenv('APP_ENV') ?: 'local');
defined('APP_URL') || define('APP_URL', getenv('APP_URL') ?: 'http://localhost/Fab-ulous');

// PayMongo Checkout
defined('PAYMONGO_SECRET_KEY') || define(
    'PAYMONGO_SECRET_KEY',
    getenv('PAYMONGO_SECRET_KEY') ?: 'sk_test_REPLACE_WITH_PAYMONGO_SECRET_KEY'
);
defined('PAYMONGO_WEBHOOK_SECRET') || define(
    'PAYMONGO_WEBHOOK_SECRET',
    getenv('PAYMONGO_WEBHOOK_SECRET') ?: 'whsec_REPLACE_WITH_PAYMONGO_WEBHOOK_SECRET'
);
defined('PAYMONGO_API_BASE') || define('PAYMONGO_API_BASE', getenv('PAYMONGO_API_BASE') ?: 'https://api.paymongo.com/v1');
defined('PAYMONGO_PAYMENT_METHOD_TYPES') || define('PAYMONGO_PAYMENT_METHOD_TYPES', getenv('PAYMONGO_PAYMENT_METHOD_TYPES') ?: 'card,gcash');

// Database
defined('DB_HOST') || define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
defined('DB_USER') || define('DB_USER', getenv('DB_USER') ?: 'root');
defined('DB_PASS') || define('DB_PASS', getenv('DB_PASS') ?: '');
defined('DB_NAME') || define('DB_NAME', getenv('DB_NAME') ?: 'fab_ulous');
defined('DB_PORT') || define('DB_PORT', (int) (getenv('DB_PORT') ?: 3306));

// SMTP / email
defined('SMTP_HOST') || define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
defined('SMTP_PORT') || define('SMTP_PORT', (int) (getenv('SMTP_PORT') ?: 465));
defined('SMTP_ENCRYPTION') || define('SMTP_ENCRYPTION', getenv('SMTP_ENCRYPTION') ?: 'ssl');
defined('SMTP_USERNAME') || define('SMTP_USERNAME', getenv('SMTP_USERNAME') ?: 'fab.ulouslab.real@gmail.com');
defined('SMTP_PASSWORD') || define('SMTP_PASSWORD', getenv('SMTP_PASSWORD') ?: 'lzhg hotg ojbi sujn');
defined('MAIL_FROM_ADDRESS') || define('MAIL_FROM_ADDRESS', getenv('MAIL_FROM_ADDRESS') ?: 'fab.ulouslab.real@gmail.com');
defined('MAIL_FROM_NAME') || define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'FABulous');

// MFA
defined('MFA_CODE_TTL_MINUTES') || define('MFA_CODE_TTL_MINUTES', 10);
defined('MFA_RESEND_COOLDOWN_SECONDS') || define('MFA_RESEND_COOLDOWN_SECONDS', 60);

$GLOBALS['FABULOUS_LAST_MAIL_ERROR'] = '';

/**
 * Returns an open MySQLi connection.
 * Caller is responsible for closing it.
 */
function db_connect(): mysqli
{
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    if ($conn->connect_error) {
        http_response_code(500);
        die('Database connection failed.');
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

function dashboard_path_for_role(string $role): string
{
    return in_array($role, ['admin', 'super_admin'], true)
        ? '../admin/admin.php'
        : '../post/post.php';
}

function begin_user_session(array $user, bool $mfaVerified = true, string $authMethod = 'password'): void
{
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
        'role' => $user['role'] ?? 'user',
        'google_id' => $user['google_id'] ?? null,
        'profile_pic' => $user['profile_pic'] ?? null,
        'auth_method' => $authMethod,
    ];
    $_SESSION['mfa_verified'] = $mfaVerified;
    unset($_SESSION['pending_mfa_user'], $_SESSION['pending_mfa_sent_at']);
}

function clear_pending_auth(): void
{
    unset($_SESSION['pending_mfa_user'], $_SESSION['pending_mfa_sent_at']);
}

function prime_google_registration_prefill(string $email, string $fullName, string $googleId): void
{
    $parts = preg_split('/\s+/', trim($fullName), 2) ?: [];
    $_SESSION['google_registration_prefill'] = [
        'email' => strtolower(trim($email)),
        'full_name' => trim($fullName),
        'first_name' => $parts[0] ?? '',
        'last_name' => $parts[1] ?? '',
        'google_id' => $googleId,
    ];
}

function get_google_registration_prefill(): array
{
    return $_SESSION['google_registration_prefill'] ?? [];
}

function clear_google_registration_prefill(): void
{
    unset($_SESSION['google_registration_prefill']);
}

function accounts_support_mfa(mysqli $conn): bool
{
    static $cached = null;

    if ($cached !== null) {
        return $cached;
    }

    $required = [
        'mfa_code' => false,
        'mfa_code_expires_at' => false,
    ];

    $columns = $conn->query('SHOW COLUMNS FROM accounts');
    if (!$columns) {
        $cached = false;
        return false;
    }

    while ($column = $columns->fetch_assoc()) {
        if (array_key_exists($column['Field'], $required)) {
            $required[$column['Field']] = true;
        }
    }

    $cached = !in_array(false, $required, true);
    return $cached;
}

function store_mfa_code(mysqli $conn, int $userId, string $code): bool
{
    $stmt = $conn->prepare(
        'UPDATE accounts
         SET mfa_code = ?, mfa_code_expires_at = DATE_ADD(NOW(), INTERVAL ? MINUTE)
         WHERE id = ?'
    );
    if (!$stmt) {
        return false;
    }

    $ttl = MFA_CODE_TTL_MINUTES;
    $stmt->bind_param('sii', $code, $ttl, $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function clear_mfa_code(mysqli $conn, int $userId): void
{
    $stmt = $conn->prepare(
        'UPDATE accounts
         SET mfa_code = NULL, mfa_code_expires_at = NULL
         WHERE id = ?'
    );
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }
}

function mask_email_address(string $email): string
{
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) {
        return $email;
    }

    $local = $parts[0];
    $domain = $parts[1];
    $visible = substr($local, 0, 2);
    $hiddenLength = max(2, strlen($local) - 2);

    return $visible . str_repeat('*', $hiddenLength) . '@' . $domain;
}

function set_last_mail_error(string $message): void
{
    $GLOBALS['FABULOUS_LAST_MAIL_ERROR'] = $message;
}

function get_last_mail_error(): string
{
    return (string) ($GLOBALS['FABULOUS_LAST_MAIL_ERROR'] ?? '');
}

function smtp_is_configured(): bool
{
    return SMTP_HOST !== ''
        && SMTP_PORT > 0
        && SMTP_USERNAME !== ''
        && SMTP_PASSWORD !== ''
        && MAIL_FROM_ADDRESS !== '';
}

function smtp_read_response($socket): array
{
    $message = '';
    $code = 0;

    while (($line = fgets($socket, 515)) !== false) {
        $message .= $line;
        if (preg_match('/^(\d{3})([\s-])/', $line, $matches)) {
            $code = (int) $matches[1];
            if ($matches[2] === ' ') {
                break;
            }
        } else {
            break;
        }
    }

    return [$code, trim($message)];
}

function smtp_expect($socket, array $allowedCodes): bool
{
    [$code, $message] = smtp_read_response($socket);
    if (!in_array($code, $allowedCodes, true)) {
        set_last_mail_error($message !== '' ? $message : 'Unexpected SMTP server response.');
        return false;
    }

    return true;
}

function smtp_write($socket, string $command): bool
{
    $written = fwrite($socket, $command . "\r\n");
    if ($written === false) {
        set_last_mail_error('Could not write to the SMTP server.');
        return false;
    }

    return true;
}

function smtp_format_header(string $label, string $value): string
{
    return $label . ': ' . $value;
}

function smtp_encode_header(string $value): string
{
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function smtp_normalize_body(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = str_replace("\n.", "\n..", $body);
    return str_replace("\n", "\r\n", $body);
}

function send_smtp_mail(string $toEmail, string $toName, string $subject, string $body): bool
{
    if (!smtp_is_configured()) {
        set_last_mail_error('SMTP is not configured yet. Add your SMTP settings in config.php.');
        return false;
    }

    set_last_mail_error('');

    $scheme = SMTP_ENCRYPTION === 'ssl' ? 'ssl://' : '';
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => APP_ENV !== 'local',
            'verify_peer_name' => APP_ENV !== 'local',
            'allow_self_signed' => APP_ENV === 'local',
        ],
    ]);

    $socket = @stream_socket_client(
        $scheme . SMTP_HOST . ':' . SMTP_PORT,
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        set_last_mail_error('SMTP connection failed: ' . $errstr);
        return false;
    }

    stream_set_timeout($socket, 20);

    if (!smtp_expect($socket, [220])) {
        fclose($socket);
        return false;
    }

    if (!smtp_write($socket, 'EHLO localhost') || !smtp_expect($socket, [250])) {
        fclose($socket);
        return false;
    }

    if (SMTP_ENCRYPTION === 'tls') {
        if (!smtp_write($socket, 'STARTTLS') || !smtp_expect($socket, [220])) {
            fclose($socket);
            return false;
        }

        $cryptoEnabled = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($cryptoEnabled !== true) {
            set_last_mail_error('Could not enable TLS for SMTP.');
            fclose($socket);
            return false;
        }

        if (!smtp_write($socket, 'EHLO localhost') || !smtp_expect($socket, [250])) {
            fclose($socket);
            return false;
        }
    }

    if (!smtp_write($socket, 'AUTH LOGIN') || !smtp_expect($socket, [334])) {
        fclose($socket);
        return false;
    }

    if (!smtp_write($socket, base64_encode(SMTP_USERNAME)) || !smtp_expect($socket, [334])) {
        fclose($socket);
        return false;
    }

    if (!smtp_write($socket, base64_encode(SMTP_PASSWORD)) || !smtp_expect($socket, [235])) {
        fclose($socket);
        return false;
    }

    if (!smtp_write($socket, 'MAIL FROM:<' . MAIL_FROM_ADDRESS . '>') || !smtp_expect($socket, [250])) {
        fclose($socket);
        return false;
    }

    if (!smtp_write($socket, 'RCPT TO:<' . $toEmail . '>') || !smtp_expect($socket, [250, 251])) {
        fclose($socket);
        return false;
    }

    if (!smtp_write($socket, 'DATA') || !smtp_expect($socket, [354])) {
        fclose($socket);
        return false;
    }

    $headers = [
        smtp_format_header('Date', date(DATE_RFC2822)),
        smtp_format_header('From', smtp_encode_header(MAIL_FROM_NAME) . ' <' . MAIL_FROM_ADDRESS . '>'),
        smtp_format_header('To', ($toName !== '' ? smtp_encode_header($toName) . ' ' : '') . '<' . $toEmail . '>'),
        smtp_format_header('Subject', smtp_encode_header($subject)),
        smtp_format_header('MIME-Version', '1.0'),
        smtp_format_header('Content-Type', 'text/plain; charset=UTF-8'),
        smtp_format_header('Content-Transfer-Encoding', '8bit'),
    ];

    $payload = implode("\r\n", $headers)
        . "\r\n\r\n"
        . smtp_normalize_body($body)
        . "\r\n.";

    if (!smtp_write($socket, $payload) || !smtp_expect($socket, [250])) {
        fclose($socket);
        return false;
    }

    smtp_write($socket, 'QUIT');
    fclose($socket);
    return true;
}

function send_mfa_code_email(string $email, string $displayName, string $code): bool
{
    $subject = 'Your FABulous verification code';
    $message = "Hello {$displayName},\n\n"
        . "Your FABulous verification code is: {$code}\n\n"
        . 'This code expires in ' . MFA_CODE_TTL_MINUTES . " minutes.\n"
        . 'Sign-in page: ' . APP_URL . "/login/verify_mfa.php\n\n"
        . "If you did not request this login, you can ignore this email.\n\n"
        . "FABulous Security";

    return send_smtp_mail($email, $displayName, $subject, $message);
}

function send_password_reset_email(string $email, string $displayName, string $code): bool
{
    $subject = 'Reset your FABulous password';
    $message = "Hello {$displayName},\n\n"
        . "Your FABulous password reset code is: {$code}\n\n"
        . "This code expires in 30 minutes.\n"
        . 'Reset page: ' . APP_URL . "/login/reset_password.php\n\n"
        . "If you did not request a password reset, you can ignore this email.\n\n"
        . "FABulous Security";

    return send_smtp_mail($email, $displayName, $subject, $message);
}

function send_registration_verification_email(string $email, string $displayName, string $code): bool
{
    $subject = 'Verify your FABulous account';
    $message = "Hello {$displayName},\n\n"
        . "Your FABulous account verification code is: {$code}\n\n"
        . "This code expires in 60 minutes.\n"
        . 'Verification page: ' . APP_URL . "/register/verify_registration.php\n\n"
        . "If you did not request this, you can safely ignore this email.\n\n"
        . "FABulous Team";
    return send_smtp_mail($email, $displayName, $subject, $message);
}
