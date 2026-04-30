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
$isAdmin = in_array($role, ['admin', 'super_admin'], true);

// Check if profile_pic column exists (migration_v4 may not have run yet)
$hasPicColumn = false;
$colCheck = $conn->query("SHOW COLUMNS FROM accounts LIKE 'profile_pic'");
if ($colCheck && $colCheck->num_rows > 0) {
    $hasPicColumn = true;
}

$picSelect = $hasPicColumn ? ', profile_pic' : '';
$stmt = $conn->prepare(
    "SELECT first_name, last_name, username, email, password, google_id, created_at{$picSelect}
     FROM accounts WHERE id = ?"
);
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($user && !isset($user['profile_pic'])) {
    $user['profile_pic'] = null;
}

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

    // Handle profile picture upload
    $picUpdated = false;
    $newPicFilename = null;

    if (!$hasPicColumn && !empty($_FILES['profile_pic']['name'])) {
        $errors[] = 'Profile pictures are not enabled yet. Run database/migration_v4.sql first.';
    } elseif ($hasPicColumn && !empty($_FILES['profile_pic']['name'])) {
        $file = $_FILES['profile_pic'];
        $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
        $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_OK);

        if ($uploadError !== UPLOAD_ERR_OK) {
            $errors[] = 'The selected profile picture could not be uploaded. Please try again.';
        } elseif (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $errors[] = 'The selected profile picture upload is invalid. Please choose the file again.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);

            if (!isset($allowedMimes[$mime])) {
                $errors[] = 'Profile picture must be a JPEG or PNG image.';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $errors[] = 'Profile picture must be smaller than 2 MB.';
            } else {
                $ext = $allowedMimes[$mime];
                $uploadDir = __DIR__ . '/../uploads/profile_pics/';
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                    $errors[] = 'Could not prepare the profile picture upload folder.';
                } elseif (!is_writable($uploadDir)) {
                    $errors[] = 'The profile picture upload folder is not writable.';
                } else {
                    $newPicFilename = $userID . '.' . $ext;
                    $destPath = $uploadDir . $newPicFilename;
                    $oldPath = null;

                    // Delete old file if extension changed.
                    $oldPic = $user['profile_pic'] ?? '';
                    if ($oldPic && $oldPic !== $newPicFilename) {
                        $oldPath = $uploadDir . $oldPic;
                    }

                    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                        $errors[] = 'Failed to save profile picture. Please try again.';
                        $newPicFilename = null;
                    } else {
                        if ($oldPath && file_exists($oldPath)) {
                            @unlink($oldPath);
                        }
                        clearstatcache(true, $destPath);
                        $picUpdated = true;
                    }
                }
            }
        }
    }

    // Profile field validation
    if (empty($errors)) {
        if ($firstName === '' || $lastName === '' || $newUsername === '' || $newEmail === '') {
            $errors[] = 'All profile fields are required.';
        } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address.';
        } elseif (strlen($newUsername) < 3 || strlen($newUsername) > 50) {
            $errors[] = 'Username must be 3–50 characters.';
        } else {
            $ck = $conn->prepare("SELECT id FROM accounts WHERE (username = ? OR email = ?) AND id != ?");
            $ck->bind_param("ssi", $newUsername, $newEmail, $userID);
            $ck->execute();
            $ck->store_result();
            if ($ck->num_rows > 0) {
                $errors[] = 'That username or email is already used by another account.';
            }
            $ck->close();
        }
    }

    // Password change validation
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
            if (!empty($user['password'])) {
                if (!password_verify($currentPass, $user['password'])) {
                    $errors[] = 'Current password is incorrect.';
                } else {
                    $updatePass = true;
                }
            } else {
                $updatePass = true;
            }

            if ($updatePass) {
                $hashedPass = password_hash($newPass, PASSWORD_DEFAULT);
            }
        }
    }

    // Persist changes
    if (empty($errors)) {
        if ($picUpdated && $updatePass) {
            $upd = $conn->prepare(
                "UPDATE accounts SET first_name=?, last_name=?, username=?, email=?, password=?, profile_pic=? WHERE id=?"
            );
            $upd->bind_param("ssssssi", $firstName, $lastName, $newUsername, $newEmail, $hashedPass, $newPicFilename, $userID);
        } elseif ($picUpdated) {
            $upd = $conn->prepare(
                "UPDATE accounts SET first_name=?, last_name=?, username=?, email=?, profile_pic=? WHERE id=?"
            );
            $upd->bind_param("sssssi", $firstName, $lastName, $newUsername, $newEmail, $newPicFilename, $userID);
        } elseif ($updatePass) {
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
            $_SESSION['user']['name']     = $firstName . ' ' . $lastName;
            $_SESSION['user']['username'] = $newUsername;
            $_SESSION['user']['email']    = $newEmail;
            if ($picUpdated) {
                $_SESSION['user']['profile_pic'] = $newPicFilename;
                $user['profile_pic'] = $newPicFilename;
            }

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

$profilePic = $user['profile_pic'] ?? null;
$avatarVersion = null;
if ($profilePic) {
    $avatarPath = __DIR__ . '/../uploads/profile_pics/' . $profilePic;
    $avatarVersion = file_exists($avatarPath) ? (string) filemtime($avatarPath) : (string) time();
}
$avatarUrl  = $profilePic
    ? '../uploads/profile_pics/' . rawurlencode($profilePic) . '?v=' . rawurlencode((string) $avatarVersion)
    : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FABulous – Profile Settings</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Josefin+Sans:wght@400;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../post/post.css"/>
  <link rel="stylesheet" href="profile.css"/>
</head>
<body>

  <!-- DRAWER OVERLAY -->
  <div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>

  <!-- NAV DRAWER -->
  <nav class="nav-drawer" id="navDrawer" aria-label="Quick navigation">
    <div class="drawer-profile">
      <div class="drawer-avatar">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="52" height="52">
          <circle cx="50" cy="35" r="22" fill="#1a1a1a"/>
          <ellipse cx="50" cy="85" rx="35" ry="25" fill="#1a1a1a"/>
        </svg>
      </div>
      <p class="drawer-name"><?php echo htmlspecialchars($name); ?></p>
      <p class="drawer-username">@<?php echo htmlspecialchars($username); ?></p>
    </div>
    <a href="../post/post.php" class="drawer-link" onclick="closeDrawer()">News Feed</a>
    <a href="../post/messages.php" class="drawer-link" onclick="closeDrawer()">Messages</a>
    <a href="profile.php" class="drawer-link active" onclick="closeDrawer()">Settings</a>
    <?php if ($isAdmin): ?>
      <a href="../admin/admin.php" class="drawer-link drawer-admin" onclick="closeDrawer()">Admin Dashboard</a>
    <?php endif; ?>
    <a href="../login/logout.php" class="drawer-link drawer-logout" onclick="closeDrawer()">Logout</a>
  </nav>

  <!-- TOP NAV -->
  <nav class="topnav">
    <img src="../images/Top_Left_Nav_Logo.png" alt="FABulous Logo" class="nav-logo"/>
    <div class="nav-links">
      <a href="../post/post.php" class="nav-item">Home</a>
      <a href="../post/commissions.php" class="nav-item">Commissions</a>
      <a href="../post/messages.php" class="nav-item">Messages</a>
      <?php if ($isAdmin): ?>
        <a href="../admin/admin.php" class="nav-item nav-admin-link">Admin</a>
      <?php endif; ?>
    </div>
    <button
      type="button"
      class="hamburger-btn"
      id="burgerBtn"
      aria-label="Toggle menu"
      aria-controls="navDrawer"
      aria-expanded="false"
      onclick="toggleDrawer()"
    >
      <span></span><span></span><span></span>
    </button>
  </nav>

  <!-- PAGE BODY -->
  <div class="profile-body">
    <div class="profile-grid">

      <!-- MAIN FORM CARD -->
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

          <form method="POST" action="" enctype="multipart/form-data">

            <!-- Avatar Section -->
            <div class="avatar-section">
              <div class="avatar-preview" id="avatarPreview">
                <?php if ($avatarUrl): ?>
                  <img src="<?php echo $avatarUrl; ?>" alt="Profile picture" id="avatarImg"/>
                <?php else: ?>
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="80" height="80" id="avatarSvg">
                    <circle cx="50" cy="35" r="22" fill="#4E7A5E"/>
                    <ellipse cx="50" cy="85" rx="35" ry="25" fill="#4E7A5E"/>
                  </svg>
                <?php endif; ?>
              </div>
              <div class="avatar-upload-area">
                <label class="avatar-upload-label" for="profile_pic">
                  &#128247; Change Photo
                  <input type="file" id="profile_pic" name="profile_pic" accept="image/jpeg,image/png"/>
                </label>
                <span class="avatar-upload-hint">JPEG or PNG &middot; max 2 MB</span>
              </div>
            </div>

            <!-- Profile Information -->
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

            <!-- Change Password -->
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

            <!-- Actions -->
            <div class="settings-actions">
              <a href="../post/post.php" class="btn-cancel">Cancel</a>
              <button type="submit" class="btn-save">Save Changes</button>
            </div>

          </form>
        </div>
      </main>

      <!-- RIGHT SIDEBAR -->
      <aside class="profile-sidebar">
        <div class="profile-sidebar-card">
          <p class="sidebar-card-kicker">Your Account</p>
          <h3 class="sidebar-card-title">Account Info</h3>
          <div class="account-badges">
            <?php if ($hasGoogle): ?>
              <span class="info-badge google-badge">&#10003; Google Linked</span>
            <?php endif; ?>
            <?php if ($hasPassword): ?>
              <span class="info-badge pass-badge">&#10003; Password Set</span>
            <?php else: ?>
              <span class="info-badge no-pass-badge">No Password Yet</span>
            <?php endif; ?>
          </div>
          <p class="info-member">
            <strong>Member since</strong>
            <?php echo htmlspecialchars($memberSince); ?>
          </p>
          <div class="sidebar-links">
            <a href="../post/post.php" class="sidebar-nav-link">&#127968; Dashboard</a>
            <a href="../login/logout.php" class="sidebar-nav-link logout">&#8617; Logout</a>
          </div>
        </div>
      </aside>

    </div>
  </div>

  <script>
    const burgerBtn    = document.getElementById('burgerBtn');
    const navDrawer    = document.getElementById('navDrawer');
    const drawerOverlay = document.getElementById('drawerOverlay');

    function toggleDrawer(forceState) {
      const shouldOpen = typeof forceState === 'boolean'
        ? forceState
        : !navDrawer.classList.contains('open');

      navDrawer.classList.toggle('open', shouldOpen);
      drawerOverlay.classList.toggle('show', shouldOpen);
      document.body.classList.toggle('menu-open', shouldOpen);
      burgerBtn?.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
    }

    function closeDrawer() {
      toggleDrawer(false);
    }

    document.addEventListener('keydown', event => {
      if (event.key === 'Escape') closeDrawer();
    });

    // Password match indicator
    const newPassInput     = document.getElementById('new_password');
    const confirmPassInput = document.getElementById('confirm_password');

    function checkMatch() {
      if (!confirmPassInput || confirmPassInput.value === '') {
        confirmPassInput?.classList.remove('match', 'mismatch');
        return;
      }
      const match = newPassInput.value === confirmPassInput.value;
      confirmPassInput.classList.toggle('match',    match);
      confirmPassInput.classList.toggle('mismatch', !match);
    }

    newPassInput?.addEventListener('input', checkMatch);
    confirmPassInput?.addEventListener('input', checkMatch);

    // Avatar preview
    const picInput = document.getElementById('profile_pic');
    picInput?.addEventListener('change', function () {
      const file = this.files[0];
      if (!file) return;
      const reader = new FileReader();
      reader.onload = function (e) {
        const preview = document.getElementById('avatarPreview');
        preview.innerHTML = '<img src="' + e.target.result + '" alt="Preview" style="width:100%;height:100%;object-fit:cover;"/>';
      };
      reader.readAsDataURL(file);
    });
  </script>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
