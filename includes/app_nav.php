<?php
$navActive = $navActive ?? '';
$navRoot = $navRoot ?? '../';
$navRoot = rtrim($navRoot, '/\\') . '/';

$currentUser = $_SESSION['user'] ?? [];
$navName = $name ?? ($currentUser['name'] ?? '');
$navUsername = $username ?? ($currentUser['username'] ?? '');
$navAvatarUrl = $myAvatarUrl ?? (function_exists('get_current_user_avatar') ? get_current_user_avatar() : null);
$navRole = $role ?? ($currentUser['role'] ?? 'user');
$navIsAdmin = $isAdmin ?? in_array($navRole, ['admin', 'super_admin'], true);

$navItems = [
    ['key' => 'feed', 'label' => 'News Feed', 'href' => $navRoot . 'post/post.php'],
    ['key' => 'messages', 'label' => 'Messages', 'href' => $navRoot . 'post/messages.php'],
    ['key' => 'commissions', 'label' => 'Commissions', 'href' => $navRoot . 'post/commissions.php'],
    ['key' => 'settings', 'label' => 'Settings', 'href' => $navRoot . 'profile/profile.php'],
];
?>
<div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>
<nav class="nav-drawer" id="navDrawer" aria-label="Quick navigation">
  <div class="drawer-profile">
    <div class="drawer-avatar">
      <?php if ($navAvatarUrl): ?>
        <img src="<?php echo htmlspecialchars($navAvatarUrl); ?>" class="drawer-avatar-img" alt="Profile" style="width:100%;height:100%;border-radius:50%;object-fit:cover;"/>
      <?php else: ?>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="52" height="52">
          <circle cx="50" cy="35" r="22" fill="#1a1a1a"/>
          <ellipse cx="50" cy="85" rx="35" ry="25" fill="#1a1a1a"/>
        </svg>
      <?php endif; ?>
    </div>
    <p class="drawer-name" id="drawerName"><?php echo htmlspecialchars($navName); ?></p>
    <p class="drawer-username" id="drawerUsername">@<?php echo htmlspecialchars($navUsername); ?></p>
  </div>
  <?php foreach ($navItems as $item): ?>
    <a href="<?php echo htmlspecialchars($item['href']); ?>" class="drawer-link<?php echo $navActive === $item['key'] ? ' active' : ''; ?>" onclick="closeDrawer()"><?php echo htmlspecialchars($item['label']); ?></a>
  <?php endforeach; ?>
  <?php if ($navIsAdmin): ?>
    <a href="<?php echo htmlspecialchars($navRoot . 'admin/admin.php'); ?>" class="drawer-link drawer-admin" onclick="closeDrawer()">Admin Dashboard</a>
  <?php endif; ?>
  <a href="<?php echo htmlspecialchars($navRoot . 'login/logout.php'); ?>" class="drawer-link drawer-logout" onclick="closeDrawer()">Logout</a>
</nav>

<nav class="topnav">
  <img src="<?php echo htmlspecialchars($navRoot . 'images/Top_Left_Nav_Logo.png'); ?>" alt="FABulous Logo" class="nav-logo"/>
  <div class="topnav-actions">
    <button
      type="button"
      class="help-btn"
      id="helpBtn"
      aria-label="Open help"
      data-bs-toggle="offcanvas"
      data-bs-target="#helpOffcanvas"
      aria-controls="helpOffcanvas"
    >Help</button>
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
  </div>
</nav>

<!-- Help Offcanvas Panel -->
<div class="offcanvas offcanvas-end help-offcanvas" tabindex="-1" id="helpOffcanvas" aria-labelledby="helpOffcanvasLabel">
  <div class="offcanvas-header help-offcanvas-header">
    <div class="help-offcanvas-title-group">
      <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      <h5 class="offcanvas-title" id="helpOffcanvasLabel">How to Use FABulous</h5>
    </div>
    <button type="button" class="help-offcanvas-close" data-bs-dismiss="offcanvas" aria-label="Close">&times;</button>
  </div>
  <div class="help-offcanvas-kicker">
    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
    <span>Navigation Guide — Burger Menu ☰</span>
  </div>
  <div class="offcanvas-body help-offcanvas-body">
    <p class="help-intro">Tap the <strong>&#9776; burger menu</strong> (top-right) to access all main features. Here's what each item does:</p>

    <div class="help-menu-list">

      <div class="help-menu-item">
        <div class="help-menu-icon help-icon-commission">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/><circle cx="12" cy="10" r="3"/></svg>
        </div>
        <div class="help-menu-text">
          <span class="help-menu-label">Commissions</span>
          <span class="help-menu-desc">Browse open commission requests from the community or submit your own. Commission a maker to build something custom for you. This is where 3D printing projects are listed, negotiated, and tracked.</span>
        </div>
      </div>

      <div class="help-menu-item">
        <div class="help-menu-icon help-icon-post">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
        </div>
        <div class="help-menu-text">
          <span class="help-menu-label">Share a Project or Update</span>
          <span class="help-menu-desc">Create a new post to share your latest build, progress update, or idea with your friends on the platform. You can attach an image and write a caption to show off what you're working on.</span>
        </div>
      </div>

      <div class="help-menu-item">
        <div class="help-menu-icon help-icon-notif">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        </div>
        <div class="help-menu-text">
          <span class="help-menu-label">Updates / Notifications</span>
          <span class="help-menu-desc">See real-time alerts about activity relevant to you. Likes on your posts, new comments, friend requests, commission status changes, and payment updates. A badge shows how many unread notifications you have.</span>
        </div>
      </div>

      <div class="help-menu-item">
        <div class="help-menu-icon help-icon-community">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </div>
        <div class="help-menu-text">
          <span class="help-menu-label">Community (from Friends)</span>
          <span class="help-menu-desc">Your News Feed shows posts only from people you're friends with. Keeping your feed personal and relevant. Use the "Find People" search in the right panel to connect with other makers, send friend requests, and grow your community.</span>
        </div>
      </div>

      <div class="help-menu-item">
        <div class="help-menu-icon help-icon-settings">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
        </div>
        <div class="help-menu-text">
          <span class="help-menu-label">Settings</span>
          <span class="help-menu-desc">Manage your profile: update your display name, username, bio, and profile picture. You can also change your password, configure multi-factor authentication (MFA), and link or unlink your Google account here.</span>
        </div>
      </div>

    </div>

    <div class="help-tip-box">
      <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <span>Your feed is <strong>friends-only</strong>. Add friends first to see posts from others!</span>
    </div>
  </div>
</div>