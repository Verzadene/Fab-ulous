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

$userID = (int)$_SESSION['user']['id'];
$name   = $_SESSION['user']['name'];
$username = $_SESSION['user']['username'];
$role   = $_SESSION['user']['role'] ?? 'user';
$isAdmin = in_array($role, ['admin', 'super_admin'], true);

$myAvatarUrl = get_current_user_avatar();
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

  <?php
  $navActive = 'settings';
  $navRoot = '../';
  require __DIR__ . '/../includes/app_nav.php';
  ?>

  <!-- PAGE BODY -->
  <div class="profile-body">
    <div class="profile-grid">

      <!-- MAIN FORM CARD -->
      <main class="feed">
        <div class="settings-card">
          <h2 class="settings-title">Profile Settings</h2>

          <div class="alert-success" id="alertSuccess" style="display:none;"></div>
          <div class="alert-error" id="alertError" style="display:none;"></div>

          <form id="profileForm" enctype="multipart/form-data" onsubmit="saveProfile(event)">

            <!-- Avatar Section -->
            <div class="avatar-section">
              <div class="avatar-preview" id="avatarPreview">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="80" height="80" id="avatarSvg">
                  <circle cx="50" cy="35" r="22" fill="#4E7A5E"/>
                  <ellipse cx="50" cy="85" rx="35" ry="25" fill="#4E7A5E"/>
                </svg>
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
                  <input type="text" id="first_name" name="first_name" class="field-input" maxlength="100" required/>
                </div>
                <div class="settings-field">
                  <label class="field-label" for="last_name">Last Name</label>
                  <input type="text" id="last_name" name="last_name" class="field-input" maxlength="100" required/>
                </div>
              </div>

              <div class="settings-field">
                <label class="field-label" for="username">Username</label>
                <input type="text" id="username" name="username" class="field-input" maxlength="50" required/>
              </div>

              <div class="settings-field">
                <label class="field-label" for="email">Email</label>
                <input type="email" id="email" name="email" class="field-input" maxlength="150" required/>
              </div>
            </div>

            <!-- Change Password -->
            <div class="settings-section">
              <h3 class="settings-section-title" id="passTitle">Change Password</h3>

              <p class="settings-hint" id="passHint" style="display:none;"></p>

              <div class="settings-field" id="currentPassWrap" style="display:none;">
                <label class="field-label" for="current_password">Current Password</label>
                <input type="password" id="current_password" name="current_password"
                       class="field-input" placeholder="Enter your current password"
                       autocomplete="current-password"/>
              </div>

              <div class="settings-row">
                <div class="settings-field">
                  <label class="field-label" for="new_password">New Password</label>
                  <input type="password" id="new_password" name="new_password"
                         class="field-input"
                         placeholder="Leave blank to keep current"
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
            <span class="info-badge google-badge" id="googleBadge" style="display:none;">&#10003; Google Linked</span>
            <span class="info-badge pass-badge" id="passBadge" style="display:none;">&#10003; Password Set</span>
            <span class="info-badge no-pass-badge" id="noPassBadge" style="display:none;">No Password Yet</span>
          </div>
          <p class="info-member">
            <strong>Member since</strong>
            <span id="memberSinceText"></span>
          </p>
          <div class="sidebar-links">
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

    function esc(val) {
      const div = document.createElement('div');
      div.textContent = String(val ?? '');
      return div.innerHTML;
    }

    async function loadProfile() {
      try {
        const res = await fetch('profile_api.php');
        const json = await res.json();
        
        if (json.success) {
          const d = json.data;
          document.getElementById('first_name').value = d.first_name;
          document.getElementById('last_name').value = d.last_name;
          document.getElementById('username').value = d.username;
          document.getElementById('email').value = d.email;
          document.getElementById('memberSinceText').textContent = d.member_since;

          document.getElementById('drawerName').textContent = d.first_name + ' ' + d.last_name;
          document.getElementById('drawerUsername').textContent = '@' + d.username;

          if (d.avatar_url) {
            document.getElementById('avatarPreview').innerHTML = `<img src="${esc(d.avatar_url)}" alt="Profile picture" style="width:100%;height:100%;object-fit:cover;"/>`;
            const drawerAvatars = document.querySelectorAll('.drawer-avatar');
            drawerAvatars.forEach(da => {
              da.innerHTML = `<img src="${esc(d.avatar_url)}" class="drawer-avatar-img" alt="Profile" style="width:100%;height:100%;border-radius:50%;object-fit:cover;"/>`;
            });
          }

          document.getElementById('googleBadge').style.display = d.has_google ? 'inline-flex' : 'none';
          document.getElementById('passBadge').style.display = d.has_password ? 'inline-flex' : 'none';
          document.getElementById('noPassBadge').style.display = d.has_password ? 'none' : 'inline-flex';

          document.getElementById('passTitle').textContent = d.has_password ? 'Change Password' : 'Set a Password';
          
          const hint = document.getElementById('passHint');
          if (d.has_google) {
            hint.textContent = d.has_password 
              ? 'Your account is linked with Google and has a password set. You can log in either way.' 
              : 'Your account was created with Google. Setting a password here lets you also log in directly with your email and password.';
            hint.style.display = 'block';
          }

          document.getElementById('currentPassWrap').style.display = d.has_password ? 'block' : 'none';
          document.getElementById('new_password').placeholder = d.has_password ? 'Leave blank to keep current' : 'Min 16 chars';
        }
      } catch (err) {
        console.error('Failed to load profile', err);
      }
    }

    async function saveProfile(e) {
      e.preventDefault();
      const fd = new FormData(e.target);
      const alertSuccess = document.getElementById('alertSuccess');
      const alertError = document.getElementById('alertError');
      
      alertSuccess.style.display = 'none'; alertError.style.display = 'none';
      
      const res = await fetch('profile_api.php', { method: 'POST', body: fd });
      const json = await res.json();
      
      if (json.success) {
        alertSuccess.textContent = json.message; alertSuccess.style.display = 'block';
        e.target.reset(); loadProfile();
      } else {
        alertError.innerHTML = json.errors.map(err => `<p>${esc(err)}</p>`).join(''); alertError.style.display = 'block';
      }
    }

    loadProfile();
  </script>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
