<?php
session_start();
require_once __DIR__ . '/../config.php';

if (!empty($_SESSION['user']) && !empty($_SESSION['mfa_verified'])) {
    header('Location: ' . dashboard_path_for_role($_SESSION['user']['role'] ?? 'user'));
    exit;
}

$step  = 1;
$error = '';
$success = '';

$conn = db_connect();

// Check password_resets table exists
$tableExists = (bool) $conn->query("SHOW TABLES LIKE 'password_resets'")->num_rows;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'send_code') {
        $email = strtolower(trim($_POST['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $accStmt = $conn->prepare("SELECT id, first_name FROM accounts WHERE email = ? LIMIT 1");
            $accStmt->bind_param('s', $email);
            $accStmt->execute();
            $account = $accStmt->get_result()->fetch_assoc();
            $accStmt->close();

            if (!$account) {
                unset($_SESSION['reset_email']);
                $error = 'No FABulous account exists for that email address.';
            } elseif (!$tableExists) {
                $error = 'Password reset is not available yet. Run database/migration_v5.sql first.';
            } else {
                $delStmt = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
                $delStmt->bind_param('s', $email);
                $delStmt->execute();
                $delStmt->close();

                $code = (string) random_int(100000, 999999);
                $insStmt = $conn->prepare(
                    "INSERT INTO password_resets (email, reset_code, expires_at)
                     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))"
                );
                $insStmt->bind_param('ss', $email, $code);
                $inserted = $insStmt->execute();
                $insStmt->close();

                if (!$inserted) {
                    $error = 'We could not prepare a reset code. Please try again.';
                } else {
                    $displayName = trim((string) ($account['first_name'] ?? ''));
                    if ($displayName === '') {
                        $displayName = 'User';
                    }

                    $mailSent = send_password_reset_email($email, $displayName, $code);
                    if (!$mailSent) {
                        $cleanupStmt = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
                        if ($cleanupStmt) {
                            $cleanupStmt->bind_param('s', $email);
                            $cleanupStmt->execute();
                            $cleanupStmt->close();
                        }

                        unset($_SESSION['reset_email']);
                        $error = get_last_mail_error() ?: 'We could not send the reset code email. Please try again.';
                    } else {
                        $success = 'Reset code sent. Check your inbox and spam folder.';
                        $_SESSION['reset_email'] = $email;
                    }
                }
            }

            if ($success) {
                $conn->close();
                header('Location: reset_password.php?sent=1');
                exit;
            }
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FABulous – Forgot Password</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Josefin+Sans:wght@400;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="login.css"/>
</head>
<body>

  <nav class="topnav">
    <img src="../images/Top_Left_Nav_Logo.png" alt="FABulous Logo" class="nav-logo"/>
  </nav>

  <div class="page-controls">
    <a href="login.php" class="ctrl-btn return-btn">&#8592; Back to Login</a>
  </div>

  <main class="card-container">
    <div class="left-panel">
      <img src="../images/Big Logo.png" alt="FABulous Logo" class="brand-logo-img"/>
      <h1 class="brand-heading">Reset your<br/>Password</h1>
      <p class="brand-desc">
        Enter the email linked to your FABulous account and we will send you a reset code.
        This also works for Google-linked accounts that want to set a local password.
      </p>
      <div class="carousel-dots">
        <span class="dot active"></span><span class="dot active"></span><span class="dot"></span>
      </div>
    </div>

    <div class="right-panel">
      <h2 class="panel-title">Forgot Password?</h2>

      <?php if ($error): ?>
        <p class="error-msg"><?php echo htmlspecialchars($error); ?></p>
      <?php endif; ?>

      <p class="verify-copy">
        Enter your registered email and we will send a 6-digit reset code.
      </p>

      <form method="POST" action="" class="auth-form auth-form--spacious">
        <input type="hidden" name="action" value="send_code"/>
        <div class="input-group">
          <input type="email" name="email" class="input-field"
                 placeholder="Email address" autocomplete="email" required/>
        </div>
        <button type="submit" class="btn-primary">Send Reset Code</button>
      </form>

      <div class="bottom-links">
        <span class="bottom-text">Remembered your password?
          <a href="login.php" class="link-btn">Sign In</a>
        </span>
      </div>
    </div>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
