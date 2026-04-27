<?php
session_start();

if (isset($_SESSION['user']) && in_array($_SESSION['user']['role'] ?? '', ['admin', 'super_admin'], true)) {
    header('Location: admin.php');
    exit;
}

$conn = new mysqli("localhost", "root", "", "fab_ulous");
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input    = trim($_POST['username']);
    $password = $_POST['password'];

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
            $_SESSION['user'] = [
                'id'        => $user['id'],
                'username'  => $user['username'],
                'email'     => $user['email'],
                'name'      => $user['first_name'] . ' ' . $user['last_name'],
                'role'      => $user['role'],
                'google_id' => $user['google_id'] ?? null,
            ];
            $conn->close();
            header('Location: admin.php');
            exit;
        }
    } else {
        $error = 'Invalid credentials or not an admin account.';
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FABulous – Admin Login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Roboto+Condensed:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../login/login.css"/>
  <style>
  .left-panel { background: #1a2820; }
  .admin-badge {
    display: inline-block;
    background: rgba(52,152,219,0.18);
    color: #5dade2;
    font-size: 11px;
    font-weight: 800;
    padding: 3px 12px;
    border-radius: 999px;
    letter-spacing: 1px;
    margin-bottom: 4px;
  }
  .brand-logo-img {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    object-fit: contain;
    flex-shrink: 0;
  }
  </style>
</head>
<body>

  <nav class="topnav">
    <img src="../images/Top_Left_Nav_Logo.png" alt="FABulous Logo" class="nav-logo"/>
  </nav>

  <div class="page-controls">
    <a href="../login/login.php" class="ctrl-btn return-btn">&#8592; User Login</a>
  </div>

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

      <?php if ($error): ?>
        <p class="error-msg"><?php echo htmlspecialchars($error); ?></p>
      <?php endif; ?>

      <form method="POST" action="">
        <div class="input-group">
          <input type="text" name="username" class="input-field"
                 placeholder="Admin Username or Email" autocomplete="username" required/>
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
        <button type="submit" class="btn-primary">Sign In as Admin</button>
      </form>
    </div>
  </main>

  <script>
    function togglePassword() {
      document.getElementById('password').type =
        document.getElementById('showPass').checked ? 'text' : 'password';
    }
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
