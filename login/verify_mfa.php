<?php
session_start();
require_once __DIR__ . '/../config.php';

if (!empty($_SESSION['user']) && !empty($_SESSION['mfa_verified'])) {
    header('Location: ' . dashboard_path_for_role($_SESSION['user']['role'] ?? 'user'));
    exit;
}

$pendingUser = $_SESSION['pending_mfa_user'] ?? null;
if (!$pendingUser) {
    header('Location: login.html');
    exit;
}

$conn = db_connect();
$error = '';
$success = '';

if (!accounts_support_mfa($conn)) {
    $error = 'MFA database columns are missing. Run the SQL update before continuing.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $action = $_POST['action'] ?? 'verify';

    if ($action === 'resend') {
        $sentAt = (int) ($_SESSION['pending_mfa_sent_at'] ?? 0);
        $remaining = MFA_RESEND_COOLDOWN_SECONDS - (time() - $sentAt);

        if ($remaining > 0) {
            $error = "Please wait {$remaining} more seconds before requesting another code.";
        } else {
            $code = (string) random_int(100000, 999999);

            if (!store_mfa_code($conn, (int) $pendingUser['id'], $code)) {
                $error = 'We could not generate a new verification code. Please try again.';
            } else {
                $_SESSION['pending_mfa_sent_at'] = time();
                $mailSent = send_mfa_code_email(
                    $pendingUser['email'],
                    trim($pendingUser['first_name'] . ' ' . $pendingUser['last_name']),
                    $code
                );

                if (!$mailSent) {
                    clear_mfa_code($conn, (int) $pendingUser['id']);
                    $error = get_last_mail_error() ?: 'A new verification code could not be sent to your email address.';
                } else {
                    $success = 'A new verification code has been sent.';
                }
            }
        }
    } else {
        $submittedCode = trim($_POST['verification_code'] ?? '');
        $stmt = $conn->prepare(
            'SELECT id, first_name, last_name, username, email, role, google_id, profile_pic, mfa_code, mfa_code_expires_at
             FROM accounts
             WHERE id = ?
             LIMIT 1'
        );
        $stmt->bind_param('i', $pendingUser['id']);
        $stmt->execute();
        $account = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$account) {
            clear_pending_auth();
            $error = 'This account could not be found anymore. Please sign in again.';
        } elseif (empty($submittedCode)) {
            $error = 'Enter the 6-digit verification code.';
        } elseif ($account['mfa_code'] !== $submittedCode) {
            $error = 'That verification code is incorrect.';
        } elseif (empty($account['mfa_code_expires_at']) || strtotime($account['mfa_code_expires_at']) < time()) {
            $error = 'That verification code has expired. Request a new one.';
        } else {
            clear_mfa_code($conn, (int) $account['id']);
            begin_user_session($account, true, 'password');
            header('Location: ' . dashboard_path_for_role($account['role'] ?? 'user'));
            $conn->close();
            exit;
        }
    }
}

$maskedEmail = mask_email_address($pendingUser['email']);
$secondsLeft = max(0, MFA_RESEND_COOLDOWN_SECONDS - (time() - (int) ($_SESSION['pending_mfa_sent_at'] ?? 0)));
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FABulous - Verify Sign In</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Josefin+Sans:wght@400;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="login.css" />
  <link rel="stylesheet" href="verify_mfa.css" />
</head>
<body>

  <nav class="topnav">
    <img src="../images/Top_Left_Nav_Logo.png" alt="FABulous Logo" class="nav-logo" />
  </nav>

  <div class="page-controls">
    <a href="login.html" class="ctrl-btn return-btn">&#8592; Back to Login</a>
  </div>

  <main class="card-container verify-card-shell">
    <div class="left-panel verify-panel-left">
      <img src="../images/Big Logo.png" alt="FABulous Logo" class="brand-logo-img" />
      <span class="security-badge">SECURE CHECKPOINT</span>
      <h1 class="brand-heading">Verify your<br/>FABulous sign in</h1>
      <p class="brand-desc">
        We sent a 6-digit verification code to <strong><?php echo htmlspecialchars($maskedEmail); ?></strong>.
        Enter it below to unlock your dashboard.
      </p>
      <div class="security-orbs">
        <span></span><span></span><span></span>
      </div>
    </div>

    <div class="right-panel verify-panel-right">
      <h2 class="panel-title">Multi-Factor Verification</h2>
      <p class="verify-copy">
        This extra step protects your account before we open the platform.
      </p>

      <?php if ($error): ?>
        <p class="error-msg"><?php echo htmlspecialchars($error); ?></p>
      <?php endif; ?>

      <?php if ($success): ?>
        <p class="success-msg"><?php echo htmlspecialchars($success); ?></p>
      <?php endif; ?>

      <form method="POST" class="verify-form">
        <input type="hidden" name="action" value="verify" />
        <div class="input-group">
          <input
            type="text"
            name="verification_code"
            class="input-field code-input"
            placeholder="Enter 6-digit code"
            inputmode="numeric"
            pattern="[0-9]{6}"
            maxlength="6"
            autocomplete="one-time-code"
            required
          />
        </div>
        <button type="submit" class="btn-primary">Verify and Continue</button>
      </form>

      <form method="POST" class="resend-form">
        <input type="hidden" name="action" value="resend" />
        <button type="submit" id="resendBtn" class="btn-secondary" <?php echo $secondsLeft > 0 ? 'disabled' : ''; ?>>
          Resend Code
        </button>
        <p class="resend-copy" id="resendCopy">
          <?php if ($secondsLeft > 0): ?>
            You can request a new code in <span id="countdown"><?php echo $secondsLeft; ?></span>s.
          <?php else: ?>
            Need a fresh code? Request another one.
          <?php endif; ?>
        </p>
      </form>
    </div>
  </main>

  <script>
    const resendBtn = document.getElementById('resendBtn');
    const resendCopy = document.getElementById('resendCopy');
    let secondsLeft = <?php echo (int) $secondsLeft; ?>;

    function tickCountdown() {
      if (secondsLeft <= 0) {
        resendBtn.disabled = false;
        resendCopy.textContent = 'Need a fresh code? Request another one.';
        return;
      }

      resendBtn.disabled = true;
      const countdown = document.getElementById('countdown');
      if (countdown) {
        countdown.textContent = String(secondsLeft);
      }
      secondsLeft -= 1;
      window.setTimeout(tickCountdown, 1000);
    }

    if (secondsLeft > 0) {
      tickCountdown();
    }
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
