<?php
session_start();
require_once __DIR__ . '/../config.php';

if (!empty($_SESSION['user']) && !empty($_SESSION['mfa_verified'])) {
    header('Location: ' . dashboard_path_for_role($_SESSION['user']['role'] ?? 'user'));
    exit;
}

$sent    = isset($_GET['sent']);
$error   = '';
$success = '';

$conn = db_connect();
$tableExists = (bool) $conn->query("SHOW TABLES LIKE 'password_resets'")->num_rows;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $code     = trim($_POST['reset_code'] ?? '');
    $newPass  = $_POST['new_password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if ($action === 'reset') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (!$tableExists) {
            $error = 'Password reset is not available yet. Run database/migration_v5.sql first.';
        } elseif (strlen($code) !== 6 || !ctype_digit($code)) {
            $error = 'Enter the 6-digit code from your email.';
        } elseif (strlen($newPass) < 8) {
            $error = 'New password must be at least 8 characters.';
        } elseif ($newPass !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $tokenStmt = $conn->prepare(
                "SELECT id FROM password_resets
                 WHERE email = ? AND reset_code = ? AND used = 0
                   AND expires_at > NOW()
                 LIMIT 1"
            );
            $tokenStmt->bind_param('ss', $email, $code);
            $tokenStmt->execute();
            $tokenRow = $tokenStmt->get_result()->fetch_assoc();
            $tokenStmt->close();

            if (!$tokenRow) {
                $error = 'That code is incorrect or has expired. Request a new one.';
            } else {
                $hash = password_hash($newPass, PASSWORD_DEFAULT);

                $updStmt = $conn->prepare("UPDATE accounts SET password = ? WHERE email = ?");
                $updStmt->bind_param('ss', $hash, $email);
                $updStmt->execute();
                $updStmt->close();

                $markStmt = $conn->prepare("UPDATE password_resets SET used = 1 WHERE id = ?");
                $markStmt->bind_param('i', $tokenRow['id']);
                $markStmt->execute();
                $markStmt->close();

                unset($_SESSION['reset_email']);
                $conn->close();
                header('Location: login.php?reset=1');
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
  <title>FABulous – Reset Password</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Josefin+Sans:wght@400;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="login.css"/>
</head>
<body>

  <nav class="topnav">
    <img src="../images/Top_Left_Nav_Logo.png" alt="FABulous Logo" class="nav-logo"/>
  </nav>

  <div class="page-controls">
    <a href="forgot_password.php" class="ctrl-btn return-btn">&#8592; Back</a>
  </div>

  <main class="card-container">
    <div class="left-panel">
      <img src="../images/Big Logo.png" alt="FABulous Logo" class="brand-logo-img"/>
      <h1 class="brand-heading">Create a<br/>New Password</h1>
      <p class="brand-desc">
        Enter the 6-digit code we emailed you, then choose a new password.
        Your account data stays intact.
      </p>
      <div class="carousel-dots">
        <span class="dot active"></span><span class="dot"></span><span class="dot"></span>
      </div>
    </div>

    <div class="right-panel">
      <h2 class="panel-title">Reset Password</h2>

      <?php if ($sent): ?>
        <p class="success-msg">Reset code sent! Check your inbox (and spam folder).</p>
      <?php endif; ?>

      <?php if ($error): ?>
        <p class="error-msg"><?php echo htmlspecialchars($error); ?></p>
      <?php endif; ?>

      <form method="POST" action="" class="auth-form auth-form--spacious">
        <input type="hidden" name="action" value="reset"/>
        <div class="input-group">
          <input type="email" name="email" class="input-field"
                 placeholder="Registered email address"
                 value="<?php echo htmlspecialchars($_SESSION['reset_email'] ?? ''); ?>"
                 autocomplete="email" required/>
        </div>
        <div class="input-group">
          <input type="text" name="reset_code" class="input-field"
                 placeholder="6-digit reset code"
                 inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                 autocomplete="one-time-code" required/>
        </div>
        <div class="input-group">
          <input type="password" name="new_password" id="newPass" class="input-field"
                 placeholder="New password (min 8 chars)"
                 autocomplete="new-password" required/>
        </div>
        <div class="input-group">
          <input type="password" name="confirm_password" id="confirmPass" class="input-field"
                 placeholder="Confirm new password"
                 autocomplete="new-password" required/>
        </div>
        <div class="show-password-row">
          <label class="checkbox-label">
            <input type="checkbox" id="showPass" onchange="togglePasswords()"/>
            <span class="custom-checkbox"></span>
            Show Passwords
          </label>
        </div>
        <button type="submit" class="btn-primary">Set New Password</button>
      </form>

      <div class="bottom-links">
        <span class="bottom-text">Need a new code?
          <a href="forgot_password.php" class="link-btn">Request one</a>
        </span>
      </div>
    </div>
  </main>

  <script>
    function togglePasswords() {
      const type = document.getElementById('showPass').checked ? 'text' : 'password';
      document.getElementById('newPass').type = type;
      document.getElementById('confirmPass').type = type;
    }
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
