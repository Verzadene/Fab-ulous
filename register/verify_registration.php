<?php
session_start();
require_once __DIR__ . '/../config.php';

$pendingEmail = $_SESSION['pending_reg_email'] ?? '';
if (!$pendingEmail) {
    header('Location: register.html');
    exit;
}

$conn    = db_connect();
$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'verify';

    if ($action === 'resend') {
        $sentAt    = (int) ($_SESSION['pending_reg_sent_at'] ?? 0);
        $remaining = 60 - (time() - $sentAt);

        if ($remaining > 0) {
            $error = "Please wait {$remaining} more seconds.";
        } else {
            $newCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $upd = $conn->prepare(
                'UPDATE pending_registrations SET verify_code=?, code_expires_at=DATE_ADD(NOW(), INTERVAL 60 MINUTE), updated_at=NOW() WHERE email=?'
            );
            $upd->bind_param('ss', $newCode, $pendingEmail);
            $upd->execute();
            $upd->close();

            // Get display name from pending record
            $nm = $conn->prepare('SELECT first_name, last_name FROM pending_registrations WHERE email=?');
            $nm->bind_param('s', $pendingEmail);
            $nm->execute();
            $row = $nm->get_result()->fetch_assoc();
            $nm->close();
            $displayName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));

            $mailSent = send_registration_verification_email($pendingEmail, $displayName, $newCode);
            if ($mailSent) {
                $_SESSION['pending_reg_sent_at'] = time();
                $success = 'A new code has been sent.';
            } else {
                $error = 'Could not send email. Please try again.';
            }
        }
    } else {
        $submitted = trim($_POST['verification_code'] ?? '');
        $chk = $conn->prepare(
            'SELECT id, first_name, last_name, username, email, password_hash, google_id, verify_code, code_expires_at
             FROM pending_registrations WHERE email=? LIMIT 1'
        );
        $chk->bind_param('s', $pendingEmail);
        $chk->execute();
        $pending = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$pending) {
            $error = 'Registration session expired. Please register again.';
        } elseif (empty($submitted)) {
            $error = 'Enter the 6-digit verification code.';
        } elseif ($pending['verify_code'] !== $submitted) {
            $error = 'That code is incorrect.';
        } elseif (strtotime($pending['code_expires_at']) < time()) {
            $error = 'That code has expired. Request a new one.';
        } else {
            // Insert verified account into accounts
            $ins = $conn->prepare(
                'INSERT INTO accounts (first_name, last_name, username, email, password) VALUES (?, ?, ?, ?, ?)'
            );
            $ins->bind_param('sssss', $pending['first_name'], $pending['last_name'], $pending['username'], $pending['email'], $pending['password_hash']);

            if ($ins->execute()) {
                $userId = $conn->insert_id;
                $ins->close();

                // Remove pending record
                $del = $conn->prepare('DELETE FROM pending_registrations WHERE email=?');
                $del->bind_param('s', $pendingEmail);
                $del->execute();
                $del->close();

                unset($_SESSION['pending_reg_email'], $_SESSION['pending_reg_sent_at']);

                begin_user_session([
                    'id'         => $userId,
                    'username'   => $pending['username'],
                    'email'      => $pending['email'],
                    'first_name' => $pending['first_name'],
                    'last_name'  => $pending['last_name'],
                    'role'       => 'user',
                    'google_id'  => null,
                ], true, 'password');

                $conn->close();
                header('Location: ../post/post.php');
                exit;
            } else {
                $ins->close();
                $error = 'Account creation failed. The username or email may already be registered.';
            }
        }
    }
}

$maskedEmail  = mask_email_address($pendingEmail);
$secondsLeft  = max(0, 60 - (time() - (int) ($_SESSION['pending_reg_sent_at'] ?? 0)));
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FABulous – Verify Email</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Josefin+Sans:wght@400;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="register.css"/>
  <link rel="stylesheet" href="verify_registration.css"/>
</head>
<body>

  <nav class="topnav">
    <img src="../images/Top_Left_Nav_Logo.png" alt="FABulous Logo" class="nav-logo"/>
  </nav>

  <div class="page-controls">
    <a href="register.html" class="ctrl-btn return-btn">&#8592; Back to Register</a>
  </div>

  <main class="card-container verify-card-shell">

    <!-- Left Panel -->
    <div class="left-panel verify-panel-left">
      <img src="../images/Big Logo.png" alt="FABulous Logo" class="brand-logo-img"/>
      <span class="security-badge">EMAIL VERIFICATION</span>
      <h1 class="brand-heading">Verify your<br/>email address</h1>
      <p class="brand-desc">
        We sent a 6-digit code to <strong><?php echo htmlspecialchars($maskedEmail); ?></strong>.
        Enter it below to activate your FABulous account.
      </p>
      <div class="security-orbs">
        <span></span><span></span><span></span>
      </div>
    </div>

    <!-- Right Panel -->
    <div class="right-panel verify-panel-right">
      <h2 class="panel-title">Email Verification</h2>
      <p class="verify-copy">
        This step confirms your email address before your account is created.
      </p>

      <?php if ($error): ?>
        <p class="error-msg"><?php echo htmlspecialchars($error); ?></p>
      <?php endif; ?>

      <?php if ($success): ?>
        <p class="success-msg"><?php echo htmlspecialchars($success); ?></p>
      <?php endif; ?>

      <form method="POST" class="verify-form">
        <input type="hidden" name="action" value="verify"/>
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
        <button type="submit" class="btn-primary">Verify and Create Account</button>
      </form>

      <form method="POST" class="resend-form">
        <input type="hidden" name="action" value="resend"/>
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
    const resendBtn  = document.getElementById('resendBtn');
    const resendCopy = document.getElementById('resendCopy');
    let secondsLeft  = <?php echo (int) $secondsLeft; ?>;

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
