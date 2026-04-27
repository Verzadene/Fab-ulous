<?php
session_start();
require_once __DIR__ . '/../config.php';

if (empty($_SESSION['user'])) {
    header('Location: ../login/login.php');
    exit;
}

if (empty($_SESSION['mfa_verified'])) {
    header('Location: ../login/verify_mfa.php');
    exit;
}

$conn = db_connect();

$userID = (int)$_SESSION['user']['id'];
$role   = $_SESSION['user']['role'] ?? 'user';

$stmt = $conn->prepare(
    "SELECT first_name, last_name, username, email, password, google_id, created_at
     FROM accounts WHERE id = ?"
);
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    session_destroy();
    header('Location: ../login/login.php');
    exit;
}

$success = '';
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName   = trim($_POST['first_name']      ?? '');
    $lastName    = trim($_POST['last_name']        ?? '');
    $newUsername = trim($_POST['username']         ?? '');
    $newEmail    = trim($_POST['email']            ?? '');
    $newPass     = $_POST['new_password']          ?? '';
    $confirmPass = $_POST['confirm_password']      ?? '';
    $currentPass = $_POST['current_password']      ?? '';

    // Profile field validation
    if ($firstName === '' || $lastName === '' || $newUsername === '' || $newEmail === '') {
        $errors[] = 'All profile fields are required.';
    } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address.';
    } elseif (strlen($newUsername) < 3 || strlen($newUsername) > 50) {
        $errors[] = 'Username must be 3–50 characters.';
    } else {
        // Uniqueness check (exclude self)
        $ck = $conn->prepare("SELECT id FROM accounts WHERE (username = ? OR email = ?) AND id != ?");
        $ck->bind_param("ssi", $newUsername, $newEmail, $userID);
        $ck->execute();
        $ck->store_result();
        if ($ck->num_rows > 0) {
            $errors[] = 'That username or email is already used by another account.';
        }
        $ck->close();
    }

    // Password change validation (only when a new password was provided)
    $updatePass = false;
    $hashedPass = null;

    if (empty($errors) && $newPass !== '') {
        if (strlen($newPass) < 16) {
            $errors[] = 'Password must be at least 16 characters.';
        } elseif (preg_match_all('/[^a-zA-Z0-9]/', $newPass) < 2) {
            $errors[] = 'Password must contain at least 2 special characters.';
        } elseif (preg_match_all('/[0-9]/', $newPass) < 2) {
            $errors[] = 'Password must contain at least 2 numbers.';
        } elseif ($newPass !== $confirmPass) {
            $errors[] = 'New password and confirmation do not match.';
        } else {
            // Existing password accounts must verify the current password first
            if (!empty($user['password'])) {
                if (!password_verify($currentPass, $user['password'])) {
                    $errors[] = 'Current password is incorrect.';
                } else {
                    $updatePass = true;
                }
            } else {
                // Google-only user setting a password for the first time — no current password required
                $updatePass = true;
            }

            if ($updatePass) {
                $hashedPass = password_hash($newPass, PASSWORD_DEFAULT);
            }
        }
    }

    // Persist changes
    if (empty($errors)) {
        if ($updatePass) {
            $upd = $conn->prepare(
                "UPDATE accounts SET first_name=?, last_name=?, username=?, email=?, password=? WHERE id=?"
            );
            $upd->bind_param("sssssi", $firstName, $lastName, $newUsername, $newEmail, $hashedPass, $userID);
        } else {
            $upd = $conn->prepare(
                "UPDATE accounts SET first_name=?, last_name=?, username=?, email=? WHERE id=?"
            );
            $upd->bind_param("ssssi", $firstName, $lastName, $newUsername, $newEmail, $userID);
        }

        if ($upd->execute()) {
            // Sync session so the sidebar name/username update immediately
            $_SESSION['user']['name']     = $firstName . ' ' . $lastName;
            $_SESSION['user']['username'] = $newUsername;
            $_SESSION['user']['email']    = $newEmail;

            // Update local $user array so the form shows fresh values
            $user['first_name'] = $firstName;
            $user['last_name']  = $lastName;
            $user['username']   = $newUsername;
            $user['email']      = $newEmail;
            if ($updatePass) $user['password'] = $hashedPass;

            $success = 'Profile updated successfully.';
        } else {
            $errors[] = 'Update failed. Please try again.';
        }
        $upd->close();
    }
}

$conn->close();

$name        = $_SESSION['user']['name'];
$username    = $_SESSION['user']['username'];
$hasPassword = !empty($user['password']);
$hasGoogle   = !empty($user['google_id']);
$memberSince = date('M d, Y', strtotime($user['created_at']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FABulous – Profile Settings</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Roboto+Condensed:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../post/post.css"/>
  <link rel="stylesheet" href="profile.css"/>
</head>
<body>

  <!-- TOP NAV -->
  <nav class="topnav">
    <img src="../images/Top_Left_Nav_Logo.png" alt="FABulous Logo" class="nav-logo"/>
    <div class="nav-links">
      <a href="../post/post.php" class="nav-item">Home</a>
      <a href="#" class="nav-item">Projects</a>
      <a href="../post/commissions.php" class="nav-item">Commissions</a>
      <a href="#" class="nav-item">History</a>
      <?php if (in_array($role, ['admin', 'super_admin'], true)): ?>
        <a href="../admin/admin.php" class="nav-item nav-admin-link">Admin &#9632;</a>
      <?php endif; ?>
    </div>
    <button class="hamburger-btn" aria-label="Menu">
      <span></span><span></span><span></span>
    </button>
  </nav>

  <!-- PAGE BODY -->
  <div class="page-body">

    <!-- LEFT SIDEBAR -->
    <aside class="sidebar">
      <div class="profile-section">
        <div class="avatar-placeholder">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" class="avatar-icon">
            <circle cx="50" cy="35" r="22" fill="#1a1a1a"/>
            <ellipse cx="50" cy="85" rx="35" ry="25" fill="#1a1a1a"/>
          </svg>
        </div>
        <p class="profile-username"><?php echo htmlspecialchars($name); ?></p>
        <p class="profile-email">@<?php echo htmlspecialchars($username); ?></p>
      </div>
      <nav class="sidebar-nav">
        <a href="../post/post.php" class="sidebar-link">News Feed</a>
        <a href="../post/messages.php" class="sidebar-link">Messages</a>
        <a href="#" class="sidebar-link">Uploads</a>
        <a href="profile.php" class="sidebar-link active">Settings</a>
        <a href="../login/logout.php" class="sidebar-link sidebar-logout">Logout</a>
      </nav>
    </aside>

    <!-- MAIN: settings form -->
    <main class="feed">
      <div class="settings-card">
        <h2 class="settings-title">Profile Settings</h2>

        <?php if ($success): ?>
          <div class="alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
          <div class="alert-error">
            <?php foreach ($errors as $e): ?>
              <p><?php echo htmlspecialchars($e); ?></p>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="POST" action="">

          <!-- ── Section 1: Profile information ── -->
          <div class="settings-section">
            <h3 class="settings-section-title">Profile Information</h3>

            <div class="settings-row">
              <div class="settings-field">
                <label class="field-label" for="first_name">First Name</label>
                <input type="text" id="first_name" name="first_name" class="field-input"
                       value="<?php echo htmlspecialchars($user['first_name']); ?>"
                       maxlength="100" required/>
              </div>
              <div class="settings-field">
                <label class="field-label" for="last_name">Last Name</label>
                <input type="text" id="last_name" name="last_name" class="field-input"
                       value="<?php echo htmlspecialchars($user['last_name']); ?>"
                       maxlength="100" required/>
              </div>
            </div>

            <div class="settings-field">
              <label class="field-label" for="username">Username</label>
              <input type="text" id="username" name="username" class="field-input"
                     value="<?php echo htmlspecialchars($user['username']); ?>"
                     maxlength="50" required/>
            </div>

            <div class="settings-field">
              <label class="field-label" for="email">Email</label>
              <input type="email" id="email" name="email" class="field-input"
                     value="<?php echo htmlspecialchars($user['email']); ?>"
                     maxlength="150" required/>
            </div>
          </div>

          <!-- ── Section 2: Password ── -->
          <div class="settings-section">
            <h3 class="settings-section-title">
              <?php echo $hasPassword ? 'Change Password' : 'Set a Password'; ?>
            </h3>

            <?php if ($hasGoogle && !$hasPassword): ?>
              <p class="settings-hint">
                Your account was created with Google. Setting a password here lets you also log in directly with your email and password.
              </p>
            <?php elseif ($hasGoogle && $hasPassword): ?>
              <p class="settings-hint">
                Your account is linked with Google and has a password set. You can log in either way.
              </p>
            <?php endif; ?>

            <?php if ($hasPassword): ?>
              <div class="settings-field">
                <label class="field-label" for="current_password">Current Password</label>
                <input type="password" id="current_password" name="current_password"
                       class="field-input" placeholder="Enter your current password"
                       autocomplete="current-password"/>
              </div>
            <?php endif; ?>

            <div class="settings-row">
              <div class="settings-field">
                <label class="field-label" for="new_password">New Password</label>
                <input type="password" id="new_password" name="new_password"
                       class="field-input"
                       placeholder="<?php echo $hasPassword ? 'Leave blank to keep current' : 'Min 16 chars'; ?>"
                       autocomplete="new-password"/>
              </div>
              <div class="settings-field">
                <label class="field-label" for="confirm_password">Confirm New Password</label>
                <input type="password" id="confirm_password" name="confirm_password"
                       class="field-input"
                       placeholder="Repeat new password"
                       autocomplete="new-password"/>
              </div>
            </div>
            <p class="pass-requirements">Minimum 16 characters &middot; at least 2 special characters &middot; at least 2 numbers</p>
          </div>

          <!-- ── Actions ── -->
          <div class="settings-actions">
            <a href="../post/post.php" class="btn-cancel">Cancel</a>
            <button type="submit" class="btn-save">Save Changes</button>
          </div>

        </form>
      </div>
    </main>

    <!-- RIGHT: account info panel -->
    <aside class="notif-panel">
      <div class="notif-card">
        <h3 class="notif-heading">Account Info</h3>
        <div class="account-info">
          <?php if ($hasGoogle): ?>
            <span class="info-badge google-badge">&#10003; Google Linked</span>
          <?php endif; ?>
          <?php if ($hasPassword): ?>
            <span class="info-badge pass-badge">&#10003; Password Set</span>
          <?php else: ?>
            <span class="info-badge no-pass-badge">No Password Yet</span>
          <?php endif; ?>
          <p class="info-member">Member since<br/><?php echo htmlspecialchars($memberSince); ?></p>
        </div>
      </div>
    </aside>

  </div>

  <script>
  const newPassInput     = document.getElementById('new_password');
  const confirmPassInput = document.getElementById('confirm_password');

  function checkMatch() {
    if (confirmPassInput.value === '') {
      confirmPassInput.classList.remove('match', 'mismatch');
      return;
    }
    const match = newPassInput.value === confirmPassInput.value;
    confirmPassInput.classList.toggle('match',    match);
    confirmPassInput.classList.toggle('mismatch', !match);
  }

  newPassInput?.addEventListener('input', checkMatch);
  confirmPassInput?.addEventListener('input', checkMatch);
  </script>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
