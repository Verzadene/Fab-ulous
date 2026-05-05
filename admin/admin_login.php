<?php
session_start();
require_once __DIR__ . '/../config.php';

if (isset($_SESSION['user']) && in_array($_SESSION['user']['role'] ?? '', ['admin', 'super_admin'], true)) {
    header('Location: admin.php');
    exit;
}

$conn = db_connect();

$error = '';
$lockoutBucket = 'fab_global_login';
$lockoutRemaining = login_lockout_remaining($lockoutBucket);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input    = trim($_POST['username']);
    $password = $_POST['password'];

    if ($lockoutRemaining > 0) {
        $error = '';
    } else {
        $stmt = $conn->prepare(
            "SELECT * FROM accounts WHERE (username = ? OR email = ?) AND role IN ('admin', 'super_admin')"
        );
        $stmt->bind_param("ss", $input, $input);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($password, $user['password'])) {
            if ($user['banned']) {
                $error = 'This admin account has been suspended.';
            } else {
                if (!accounts_support_mfa($conn)) {
                    $error = 'MFA is not ready yet. Run the SQL update for the accounts table first.';
                } else {
                    $code = (string) random_int(100000, 999999);

                    if (!store_mfa_code($conn, (int) $user['id'], $code)) {
                        $error = 'We could not start MFA verification. Please try again.';
                    } else {
                        clear_login_lockout($lockoutBucket);
                        clear_pending_auth();
                        $_SESSION['pending_mfa_user'] = [
                            'id' => (int) $user['id'],
                            'username' => $user['username'],
                            'email' => $user['email'],
                            'first_name' => $user['first_name'],
                            'last_name' => $user['last_name'],
                            'role' => $user['role'],
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
                            $error = get_last_mail_error() ?: 'A verification code could not be sent to this admin email address.';
                        } else {
                            header('Location: ../login/verify_mfa.php');
                            $conn->close();
                            exit;
                        }
                    }
                }
            }
        } else {
            $lockoutRemaining = record_login_failure($lockoutBucket);
            if ($lockoutRemaining <= 0) {
                $error = 'Invalid credentials or not an admin account.';
            }
        }
    }
}
$conn->close();
$lockoutRemaining = login_lockout_remaining($lockoutBucket);
$isLocked = $lockoutRemaining > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FABulous – Admin Login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Josefin+Sans:wght@400;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="admin_login.css"/>
</head>
<body>

  <nav class="topnav">
    <img src="../images/Top_Left_Nav_Logo.png" alt="FABulous Logo" class="nav-logo"/>
  </nav>

  <div class="page-controls">
    <a href="../login/login.php" class="ctrl-btn return-btn">&#8592; User Login</a>
  </div>

  <div class="auth-viewport">
    <div class="auth-slider" id="authSlider" style="transform: translateX(-100%);">
      <!-- Pos 0: Login -->
      <div class="auth-slider-step"></div>
      <!-- Pos 1: Admin -->
      <div class="auth-slider-step">
        <main class="card-container">
          <div class="left-panel">
            <img src="../images/Big Logo.png" alt="FABulous Logo" class="brand-logo-img" />
            <h1 class="brand-heading">Admin Access<br/>FABulous</h1>
            <p class="brand-desc">Restricted to authorized Fab Lab administrators only.</p>
            <div class="carousel-dots">
              <span class="dot active"></span><span class="dot"></span><span class="dot"></span>
            </div>
          </div>

          <div class="right-panel">
            <span class="admin-badge">ADMIN PORTAL</span>
            <h2 class="panel-title">Admin Login</h2>

            <?php if (!empty($error)): ?>
              <p class="error-msg"><?php echo htmlspecialchars($error); ?></p>
            <?php endif; ?>

            <div id="lockoutMsg" data-remaining="<?php echo (int) $lockoutRemaining; ?>" style="<?php echo $isLocked ? '' : 'display:none;'; ?>" class="error-msg">
              <?php if ($isLocked): ?>
                Too many failed attempts. Please wait <?php echo (int) $lockoutRemaining; ?> seconds.
              <?php endif; ?>
            </div>

            <form method="POST" action="" class="auth-form" id="loginForm">
              <div class="input-group">
                <input type="text" name="username" class="input-field"
                       placeholder="Admin Username or Email" autocomplete="username" required <?php echo $isLocked ? 'disabled' : ''; ?>/>
              </div>
              <div class="input-group">
                <input type="password" name="password" id="password" class="input-field"
                       placeholder="Password" autocomplete="current-password" required <?php echo $isLocked ? 'disabled' : ''; ?>/>
              </div>
              <div class="show-password-row">
                <label class="checkbox-label">
                  <input type="checkbox" id="showPass" onchange="togglePassword()" <?php echo $isLocked ? 'disabled' : ''; ?>/>
                  <span class="custom-checkbox"></span>
                  Show Password
                </label>
              </div>
              <button type="submit" class="btn-primary" <?php echo $isLocked ? 'disabled' : ''; ?>>Sign In as Admin</button>
            </form>
          </div>
        </main>
      </div>
      <!-- Pos 2: Register -->
      <div class="auth-slider-step"></div>
    </div>
  </div>

  <script>
    function togglePassword() {
      document.getElementById('password').type =
        document.getElementById('showPass').checked ? 'text' : 'password';
    }

    (function () {
      const form = document.getElementById('loginForm');
      const lockoutMsg = document.getElementById('lockoutMsg');
      let remaining = parseInt(lockoutMsg?.dataset.remaining || '0', 10);

      if (!remaining || !form || !lockoutMsg) return;

      if (remaining >= 59) {
        alert("Too many failed attempts. You are locked out from typing your credentials for 1 minute.");
      }

      form.querySelectorAll('input, button').forEach(el => el.disabled = true);
      const timer = window.setInterval(() => {
        remaining -= 1;
        if (remaining <= 0) {
          window.clearInterval(timer);
          window.location.reload();
          return;
        }
        lockoutMsg.textContent = 'Too many failed attempts. Please wait ' + remaining + ' second' + (remaining !== 1 ? 's' : '') + '.';
      }, 1000);
    })();

    // Page Transition Animation Logic
    document.addEventListener('DOMContentLoaded', () => {
      const slider = document.getElementById('authSlider');
      if (!slider) return;

      const slideFrom = sessionStorage.getItem('slideFrom');
      if (slideFrom === 'login' || slideFrom === 'register') {
        slider.style.transition = 'none';
        slider.style.transform = slideFrom === 'login' ? 'translateX(0)' : 'translateX(-200%)';
        requestAnimationFrame(() => {
          requestAnimationFrame(() => {
            slider.style.transition = 'transform 0.4s ease-in-out';
            slider.style.transform = 'translateX(-100%)';
          });
        });
        sessionStorage.removeItem('slideFrom');
      }

      document.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', e => {
          const href = link.getAttribute('href');
          if (!href) return;

          let targetPos = href.includes('register.html') ? 2 : (href.includes('login.php') && !href.includes('admin_login.php') ? 0 : -1);
          if (targetPos !== -1) {
            e.preventDefault();
            sessionStorage.setItem('slideFrom', 'admin');
            slider.style.transition = 'transform 0.4s ease-in-out';
            slider.style.transform = `translateX(-${targetPos * 100}%)`;
            setTimeout(() => window.location.href = link.href, 400);
          }
        });
      });
    });
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
