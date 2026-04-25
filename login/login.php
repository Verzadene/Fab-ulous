<?php
session_start();

$conn = new mysqli("localhost", "root", "", "fab_ulous");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Google OAuth redirect
if (isset($_GET['google'])) {
    $client_id    = '313306839766-5be832449af0f4lf0autei7oogm2ra5f.apps.googleusercontent.com';
    $redirect_uri = 'http://localhost/Fab-ulous/oauth/oauth2callback.php';
    $scope        = urlencode('https://www.googleapis.com/auth/userinfo.profile https://www.googleapis.com/auth/userinfo.email');
    $auth_url     = "https://accounts.google.com/o/oauth2/v2/auth?response_type=code&client_id=$client_id&redirect_uri=$redirect_uri&scope=$scope&access_type=offline";
    header('Location: ' . $auth_url);
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameOrEmail = trim($_POST['username']);
    $password        = $_POST['password'];

    $stmt = $conn->prepare("SELECT * FROM accounts WHERE username = ? OR email = ?");
    $stmt->bind_param("ss", $usernameOrEmail, $usernameOrEmail);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($user && !empty($user['password']) && password_verify($password, $user['password'])) {
        if ($user['banned']) {
            $error = 'Your account has been suspended. Contact the administrator.';
        } else {
            $_SESSION['user'] = [
                'id'        => $user['id'],
                'username'  => $user['username'],
                'email'     => $user['email'],
                'name'      => $user['first_name'] . ' ' . $user['last_name'],
                'role'      => $user['role'] ?? 'user',
                'google_id' => $user['google_id'] ?? null,
            ];
            $conn->close();
            header($_SESSION['user']['role'] === 'admin'
                ? 'Location: ../admin/admin.php'
                : 'Location: ../post/post.php');
            exit;
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
  <link href="https://fonts.googleapis.com/css2?family=Roboto+Condensed:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../login/login.css" />
</head>
<body>

  <nav class="topnav">
    <img src="../images/Top_Left_Nav_Logo.png" alt="FABulous Logo" class="nav-logo" />
  </nav>

  <div class="page-controls">
    <a href="../landing/landing.html" class="ctrl-btn return-btn">&#8592; Return</a>
    <a href="../admin/admin_login.php" class="ctrl-btn admin-btn">Admin &#8594;</a>
  </div>

  <main class="card-container">

    <div class="left-panel">
      <div class="brand-logo-placeholder">F★</div>
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

      <?php if ($error): ?>
        <p class="error-msg"><?php echo htmlspecialchars($error); ?></p>
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
        <a href="#" class="link-btn">Forgot Password?</a>
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
