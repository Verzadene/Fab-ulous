<?php
session_start();
require_once __DIR__ . '/../config.php';

// Google OAuth redirect
if (isset($_GET['google'])) {
    if (trim(GOOGLE_CLIENT_SECRET) === '') {
        header('Location: login.php?error=google_oauth_config');
        exit;
    }

    $scope    = urlencode('openid https://www.googleapis.com/auth/userinfo.profile https://www.googleapis.com/auth/userinfo.email');
    $auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?response_type=code'
              . '&client_id='    . urlencode(GOOGLE_CLIENT_ID)
              . '&redirect_uri=' . urlencode(GOOGLE_REDIRECT_URI)
              . '&scope='        . $scope
              . '&access_type=offline'
              . '&prompt=select_account';
    header('Location: ' . $auth_url);
    exit;
}

$conn = db_connect();
$error = '';
$errorIsHtml = false;

$loginSuccess = '';
if (isset($_GET['reset']) && $_GET['reset'] === '1') {
    $loginSuccess = 'Password updated successfully. You can now sign in with your new password.';
}

if (isset($_GET['error'])) {
    $error = match ($_GET['error']) {
        'banned' => 'Your account has been suspended. Contact the administrator.',
        'google_oauth_config' => 'Google sign-in is not configured yet. Update config.php with your Google client secret.',
        'oauth_exchange_failed' => 'Google sign-in could not be completed. Please try again.',
        'google_account_missing' => 'No account exists for this Google email yet. Please register first before logging in with Google.',
        default => '',
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameOrEmail = trim($_POST['username']);
    $password        = $_POST['password'];

    $stmt = $conn->prepare("SELECT * FROM accounts WHERE username = ? OR email = ?");
    $stmt->bind_param("ss", $usernameOrEmail, $usernameOrEmail);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($user && !empty($user['password']) && password_verify($password, $user['password'])) {
        if (in_array($user['role'] ?? '', ['admin', 'super_admin'], true)) {
            $error = 'Admin accounts must sign in at the <a href="../admin/admin_login.php" class="link-btn">Admin Portal</a>.';
            $errorIsHtml = true;
        } elseif ($user['banned']) {
            $error = 'Your account has been suspended. Contact the administrator.';
        } else {
            if (!accounts_support_mfa($conn)) {
                $error = 'MFA is not ready yet. Run the SQL update for the accounts table first.';
            } else {
                $code = (string) random_int(100000, 999999);

                if (!store_mfa_code($conn, (int) $user['id'], $code)) {
                    $error = 'We could not start MFA verification. Please try again.';
                } else {
                    clear_pending_auth();
                    clear_google_registration_prefill();

                    $_SESSION['pending_mfa_user'] = [
                        'id' => (int) $user['id'],
                        'username' => $user['username'],
                        'email' => $user['email'],
                        'first_name' => $user['first_name'],
                        'last_name' => $user['last_name'],
                        'role' => $user['role'] ?? 'user',
                        'google_id' => $user['google_id'] ?? null,
                        'profile_pic' => $user['profile_pic'] ?? null,
                    ];
                    $_SESSION['pending_mfa_sent_at'] = time();

                    $mailSent = send_mfa_code_email(
                        $user['email'],
                        trim($user['first_name'] . ' ' . $user['last_name']),
                        $code
                    );

                    if (!$mailSent) {
                        clear_pending_auth();
                        clear_mfa_code($conn, (int) $user['id']);
                        $error = get_last_mail_error() ?: 'A verification code could not be sent to your email address.';
                    } else {
                        header('Location: verify_mfa.php');
                        $conn->close();
                        exit;
                    }

                    if (!$error) {
                        header('Location: verify_mfa.php');
                        $conn->close();
                        exit;
                    }
                }
            }
        }
    } else {
        $error = 'Invalid username/email or password.';
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FABulous – Login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Josefin+Sans:wght@400;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../login/login.css" />
</head>
<body>

  <nav class="topnav">
    <img src="../images/Top_Left_Nav_Logo.png" alt="FABulous Logo" class="nav-logo" />
  </nav>

  <div class="page-controls">
    <a href="../landing/landing.html" class="ctrl-btn return-btn">&#8592; Return to Landing Page</a>
    <a href="../admin/admin_login.php" class="ctrl-btn admin-btn">Admin &#8594;</a>
  </div>

  <main class="card-container">

    <div class="left-panel">
      <img src="../images/Big Logo.png" alt="FABulous Logo" class="brand-logo-img" />
      <h1 class="brand-heading">Explore Fablab<br/>with Fab-ulous</h1>
      <p class="brand-desc">
        As technology evolves, sharing projects in both software and hardware is important.
        FABulous provides a community to share your projects in a single platform
      </p>
      <div class="carousel-dots">
        <span class="dot active"></span>
        <span class="dot"></span>
        <span class="dot"></span>
      </div>
    </div>

    <div class="right-panel">
      <h2 class="panel-title">Welcome!</h2>

      <?php if ($loginSuccess): ?>
        <p class="success-msg"><?php echo htmlspecialchars($loginSuccess); ?></p>
      <?php endif; ?>

      <?php if ($error): ?>
        <p class="error-msg"><?php echo $errorIsHtml ? $error : htmlspecialchars($error); ?></p>
      <?php endif; ?>

      <form method="POST" action="">
        <div class="input-group">
          <input type="text" name="username" id="username" class="input-field"
                 placeholder="Username or Email" autocomplete="username" required/>
        </div>
        <div class="input-group">
          <input type="password" name="password" id="password" class="input-field"
                 placeholder="Password" autocomplete="current-password" required/>
        </div>
        <div class="show-password-row">
          <label class="checkbox-label">
            <input type="checkbox" id="showPass" onchange="togglePassword()"/>
            <span class="custom-checkbox"></span>
            Show Password
          </label>
        </div>
        <button type="submit" class="btn-primary">Sign in</button>
      </form>

      <p class="or-divider">Or</p>

      <a href="?google=1" class="btn-google">
        <svg class="google-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48">
          <path fill="#EA4335" d="M24 9.5c3.2 0 5.9 1.1 8.1 2.9l6-6C34.5 3.1 29.6 1 24 1 14.8 1 7 6.7 3.7 14.6l7 5.4C12.4 13.8 17.7 9.5 24 9.5z"/>
          <path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v8.5h12.7c-.6 3-2.3 5.5-4.8 7.2l7.4 5.7c4.3-4 6.8-9.9 6.8-16.9z"/>
          <path fill="#FBBC05" d="M10.7 28.6A14.5 14.5 0 0 1 9.5 24c0-1.6.3-3.1.7-4.6l-7-5.4A23.8 23.8 0 0 0 .5 24c0 3.8.9 7.4 2.7 10.6l7.5-6z"/>
          <path fill="#34A853" d="M24 46.5c5.6 0 10.4-1.9 13.8-5.1l-7.4-5.7c-1.9 1.3-4.4 2-6.4 2-6.3 0-11.6-4.3-13.5-10l-7.5 6C7 41.8 14.8 46.5 24 46.5z"/>
        </svg>
        Continue with Google
      </a>

      <div class="bottom-links">
        <span class="bottom-text">No Account?
          <a href="../register/register.html" class="link-btn">Register Now!</a>
        </span>
        <a href="forgot_password.php" class="link-btn">Forgot Password?</a>
      </div>
    </div>
  </main>

  <script>
    function togglePassword() {
      const pw = document.getElementById('password');
      pw.type = document.getElementById('showPass').checked ? 'text' : 'password';
    }
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
