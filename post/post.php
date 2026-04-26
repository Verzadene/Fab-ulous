<?php
session_start();

if (!isset($_SESSION['user'])) {
    header('Location: ../login/login.php');
    exit;
}

$conn = new mysqli("localhost", "root", "", "fab_ulous");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$userID   = (int)$_SESSION['user']['id'];
$username = $_SESSION['user']['username'];
$name     = $_SESSION['user']['name'];
$role     = $_SESSION['user']['role'] ?? 'user';
$isAdmin  = in_array($role, ['admin', 'super_admin']);

// ── Check whether v2 tables exist (graceful fallback pre-migration) ──
$hasFriendships = (bool)$conn->query("SHOW TABLES LIKE 'friendships'")->num_rows;
$hasNotifs      = (bool)$conn->query("SHOW TABLES LIKE 'notifications'")->num_rows;

// ── Friend feed: own posts + accepted-friend posts ────────────────
if ($hasFriendships) {
    $feedStmt = $conn->prepare("
        SELECT p.postID, p.caption, p.image_url, p.created_at,
               a.id AS authorID, a.username AS author,
               (SELECT COUNT(*) FROM likes    WHERE postID = p.postID) AS like_count,
               (SELECT COUNT(*) FROM comments WHERE postID = p.postID) AS comment_count,
               EXISTS(SELECT 1 FROM likes WHERE postID = p.postID AND userID = ?) AS user_liked
        FROM posts p JOIN accounts a ON p.userID = a.id
        WHERE p.userID = ?
           OR EXISTS(
               SELECT 1 FROM friendships
               WHERE status = 'accepted'
                 AND ((requesterID = ? AND receiverID = p.userID)
                   OR (receiverID  = ? AND requesterID = p.userID))
           )
        ORDER BY p.created_at DESC
        LIMIT 20
    ");
    $feedStmt->bind_param("iiii", $userID, $userID, $userID, $userID);
    $feedStmt->execute();
    $posts = $feedStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $feedStmt->close();

    // Discover: posts from non-friends (up to 8), with friendship status
    $discStmt = $conn->prepare("
        SELECT p.postID, p.caption, p.image_url, p.created_at,
               a.id AS authorID, a.username AS author,
               (SELECT COUNT(*) FROM likes    WHERE postID = p.postID) AS like_count,
               (SELECT COUNT(*) FROM comments WHERE postID = p.postID) AS comment_count,
               EXISTS(SELECT 1 FROM likes WHERE postID = p.postID AND userID = ?) AS user_liked,
               COALESCE((
                   SELECT status FROM friendships
                   WHERE (requesterID = ? AND receiverID = a.id)
                      OR (receiverID  = ? AND requesterID = a.id)
                   LIMIT 1
               ), 'none') AS friend_status,
               (SELECT friendshipID FROM friendships
                WHERE (requesterID = ? AND receiverID = a.id)
                   OR (receiverID  = ? AND requesterID = a.id)
                LIMIT 1) AS friendship_id,
               (SELECT requesterID FROM friendships
                WHERE (requesterID = ? AND receiverID = a.id)
                   OR (receiverID  = ? AND requesterID = a.id)
                LIMIT 1) AS friend_requester
        FROM posts p JOIN accounts a ON p.userID = a.id
        WHERE p.userID != ?
          AND NOT EXISTS(
              SELECT 1 FROM friendships
              WHERE status = 'accepted'
                AND ((requesterID = ? AND receiverID = p.userID)
                  OR (receiverID  = ? AND requesterID = p.userID))
          )
        ORDER BY p.created_at DESC
        LIMIT 8
    ");
    $discStmt->bind_param("iiiiiiiiiii",
        $userID, $userID, $userID, $userID, $userID, $userID, $userID,
        $userID, $userID, $userID, $userID);
    $discStmt->execute();
    $discoverPosts = $discStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $discStmt->close();
} else {
    // Pre-migration fallback: show all posts
    $feedStmt = $conn->prepare("
        SELECT p.postID, p.caption, p.image_url, p.created_at,
               a.id AS authorID, a.username AS author,
               (SELECT COUNT(*) FROM likes    WHERE postID = p.postID) AS like_count,
               (SELECT COUNT(*) FROM comments WHERE postID = p.postID) AS comment_count,
               EXISTS(SELECT 1 FROM likes WHERE postID = p.postID AND userID = ?) AS user_liked
        FROM posts p JOIN accounts a ON p.userID = a.id
        ORDER BY p.created_at DESC
        LIMIT 20
    ");
    $feedStmt->bind_param("i", $userID);
    $feedStmt->execute();
    $posts = $feedStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $feedStmt->close();
    $discoverPosts = [];
}

// ── Unread notification count ─────────────────────────────────────
$unreadCount = 0;
if ($hasNotifs) {
    $nc = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE userID = ? AND is_read = 0");
    $nc->bind_param("i", $userID);
    $nc->execute();
    $unreadCount = (int)$nc->get_result()->fetch_assoc()['c'];
    $nc->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FABulous – Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Roboto+Condensed:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="post.css"/>
</head>
<body>

  <!-- ── NAV DRAWER (hamburger target) ── -->
  <div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>
  <nav class="nav-drawer" id="navDrawer" aria-label="Mobile navigation">
    <div class="drawer-profile">
      <div class="avatar-placeholder drawer-avatar">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="48" height="48">
          <circle cx="50" cy="35" r="22" fill="#1a1a1a"/>
          <ellipse cx="50" cy="85" rx="35" ry="25" fill="#1a1a1a"/>
        </svg>
      </div>
      <p class="drawer-name"><?php echo htmlspecialchars($name); ?></p>
      <p class="drawer-username">@<?php echo htmlspecialchars($username); ?></p>
    </div>
    <a href="post.php"                  class="drawer-link active">News Feed</a>
    <a href="#"                         class="drawer-link">Messages</a>
    <a href="#"                         class="drawer-link">Uploads</a>
    <a href="../profile/profile.php"    class="drawer-link">Settings</a>
    <?php if ($isAdmin): ?>
      <a href="../admin/admin.php"      class="drawer-link drawer-admin">Admin Dashboard</a>
    <?php endif; ?>
    <a href="../login/logout.php"       class="drawer-link drawer-logout">Logout</a>
  </nav>

  <!-- ── TOP NAV ── -->
  <nav class="topnav">
    <img src="../images/Top_Left_Nav_Logo.png" alt="FABulous Logo" class="nav-logo"/>
    <div class="nav-links">
      <a href="post.php" class="nav-item active">Home</a>
      <a href="#" class="nav-item">Projects</a>
      <a href="#" class="nav-item">Commissions</a>
      <a href="#" class="nav-item">History</a>
      <?php if ($isAdmin): ?>
        <a href="../admin/admin.php" class="nav-item nav-admin-link">Admin &#9632;</a>
      <?php endif; ?>
    </div>
    <button class="hamburger-btn" id="hamburgerBtn"
            aria-label="Toggle menu" onclick="toggleDrawer()">
      <span></span><span></span><span></span>
    </button>
  </nav>

  <!-- ── PAGE BODY ── -->
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
        <a href="post.php"               class="sidebar-link active">News Feed</a>
        <a href="#"                      class="sidebar-link">Messages</a>
        <a href="#"                      class="sidebar-link">Uploads</a>
        <a href="../profile/profile.php" class="sidebar-link">Settings</a>
        <?php if ($isAdmin): ?>
          <a href="../admin/admin.php"   class="sidebar-link sidebar-admin">Admin Dashboard</a>
        <?php endif; ?>
        <a href="../login/logout.php"    class="sidebar-link sidebar-logout">Logout</a>
      </nav>
    </aside>

    <!-- MAIN FEED -->
    <main class="feed">

      <!-- Create Post trigger -->
      <button class="create-post-btn" onclick="openModal()">
        + Share a Project or Update
      </button>

      <!-- Create Post Modal -->
      <div id="postModal" class="modal-overlay" onclick="closeModalOutside(event)">
        <div class="modal-card">
          <button class="modal-close" onclick="closeModal()">&times;</button>
          <div class="modal-body">
            <div class="modal-left">
              <h2 class="modal-title">Create Post</h2>
              <form action="create_post.php" method="POST" enctype="multipart/form-data" id="postForm">
                <textarea name="caption" class="caption-input"
                          placeholder="Share your project update…"
                          rows="6" oninput="updatePreview()" required></textarea>
                <label class="file-label">
                  <input type="file" name="image" accept="image/*"
                         id="imgInput" onchange="previewImage(this)"/>
                  &#128247; Attach Image (optional)
                </label>
                <div class="modal-actions">
                  <button type="button" class="modal-btn-discard" onclick="closeModal()">Discard</button>
                  <button type="submit" class="modal-btn-upload">Upload</button>
                </div>
              </form>
            </div>
            <div class="modal-right">
              <h3 class="preview-heading">Post Preview</h3>
              <div class="preview-card">
                <div class="preview-header">
                  <div class="preview-avatar">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="32" height="32">
                      <circle cx="50" cy="35" r="22" fill="#4E7A5E"/>
                      <ellipse cx="50" cy="85" rx="35" ry="25" fill="#4E7A5E"/>
                    </svg>
                  </div>
                  <p class="preview-author"><?php echo htmlspecialchars($username); ?></p>
                </div>
                <img id="previewImg" class="preview-img" style="display:none;" alt=""/>
                <p id="previewCaption" class="preview-caption"></p>
                <div class="preview-actions"><span>&#10084; 0</span><span>&#128172; 0</span></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ── FRIEND FEED ── -->
      <?php if (empty($posts)): ?>
        <div class="empty-feed">
          <p>No posts from friends yet.</p>
          <p class="empty-sub">Add friends below to see their updates here.</p>
        </div>
      <?php else: ?>
        <?php foreach ($posts as $post): ?>
          <div class="post-card" id="post-<?php echo $post['postID']; ?>">

            <div class="post-header">
              <div class="post-avatar">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="42" height="42">
                  <circle cx="50" cy="35" r="22" fill="#4E7A5E"/>
                  <ellipse cx="50" cy="85" rx="35" ry="25" fill="#4E7A5E"/>
                </svg>
              </div>
              <div class="post-meta">
                <span class="post-author"><?php echo htmlspecialchars($post['author']); ?></span>
                <span class="post-time"><?php echo date('M d, Y \a\t H:i', strtotime($post['created_at'])); ?></span>
              </div>
            </div>

            <?php if (!empty($post['caption'])): ?>
              <p class="post-caption"><?php echo nl2br(htmlspecialchars($post['caption'])); ?></p>
            <?php endif; ?>

            <?php if (!empty($post['image_url'])): ?>
              <img src="<?php echo htmlspecialchars($post['image_url']); ?>"
                   class="post-image" alt="Post image"/>
            <?php endif; ?>

            <div class="post-actions">
              <button class="post-action-btn like-btn <?php echo $post['user_liked'] ? 'liked' : ''; ?>"
                      onclick="toggleLike(<?php echo $post['postID']; ?>, this)">
                <span class="heart-icon">&#10084;</span>
                <span class="like-count"><?php echo $post['like_count']; ?></span>
              </button>
              <button class="post-action-btn comment-toggle-btn"
                      onclick="toggleComments(<?php echo $post['postID']; ?>)">
                <span>&#128172;</span>
                <span><?php echo $post['comment_count']; ?> Comment<?php echo (int)$post['comment_count'] !== 1 ? 's' : ''; ?></span>
              </button>
            </div>

            <div class="comments-section" id="comments-<?php echo $post['postID']; ?>" style="display:none;">
              <div class="comments-list" id="clist-<?php echo $post['postID']; ?>"></div>
              <form class="comment-form" onsubmit="submitComment(event,<?php echo $post['postID']; ?>)">
                <input type="text" class="comment-input" placeholder="Write a comment…" maxlength="500" required/>
                <button type="submit" class="comment-submit">Post</button>
              </form>
            </div>

          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <!-- ── DISCOVER SECTION ── -->
      <?php if (!empty($discoverPosts)): ?>
        <div class="discover-heading">
          <span>&#127760; Discover People</span>
        </div>
        <?php foreach ($discoverPosts as $post): ?>
          <div class="post-card discover-card" id="post-<?php echo $post['postID']; ?>">

            <div class="post-header">
              <div class="post-avatar">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="42" height="42">
                  <circle cx="50" cy="35" r="22" fill="#4E7A5E"/>
                  <ellipse cx="50" cy="85" rx="35" ry="25" fill="#4E7A5E"/>
                </svg>
              </div>
              <div class="post-meta">
                <span class="post-author"><?php echo htmlspecialchars($post['author']); ?></span>
                <span class="post-time"><?php echo date('M d, Y \a\t H:i', strtotime($post['created_at'])); ?></span>
              </div>

              <?php
                $fStatus     = $post['friend_status']   ?? 'none';
                $fID         = (int)($post['friendship_id']  ?? 0);
                $fRequester  = (int)($post['friend_requester'] ?? 0);
                $authorID    = (int)$post['authorID'];
              ?>
              <div class="friend-action" id="fa-<?php echo $authorID; ?>">
                <?php if ($fStatus === 'none'): ?>
                  <button class="btn-add-friend"
                          onclick="sendFriendRequest(<?php echo $authorID; ?>)">
                    + Add Friend
                  </button>
                <?php elseif ($fStatus === 'pending' && $fRequester === $userID): ?>
                  <button class="btn-friend-pending"
                          onclick="cancelFriendRequest(<?php echo $fID; ?>, <?php echo $authorID; ?>)">
                    Pending
                  </button>
                <?php elseif ($fStatus === 'pending' && $fRequester !== $userID): ?>
                  <button class="btn-accept-friend"
                          onclick="acceptFriendRequest(<?php echo $fID; ?>, <?php echo $authorID; ?>)">
                    Accept
                  </button>
                <?php endif; ?>
              </div>
            </div>

            <?php if (!empty($post['caption'])): ?>
              <p class="post-caption"><?php echo nl2br(htmlspecialchars($post['caption'])); ?></p>
            <?php endif; ?>

            <?php if (!empty($post['image_url'])): ?>
              <img src="<?php echo htmlspecialchars($post['image_url']); ?>"
                   class="post-image" alt="Post image"/>
            <?php endif; ?>

            <div class="post-actions">
              <button class="post-action-btn like-btn <?php echo $post['user_liked'] ? 'liked' : ''; ?>"
                      onclick="toggleLike(<?php echo $post['postID']; ?>, this)">
                <span class="heart-icon">&#10084;</span>
                <span class="like-count"><?php echo $post['like_count']; ?></span>
              </button>
              <button class="post-action-btn comment-toggle-btn"
                      onclick="toggleComments(<?php echo $post['postID']; ?>)">
                <span>&#128172;</span>
                <span><?php echo $post['comment_count']; ?> Comment<?php echo (int)$post['comment_count'] !== 1 ? 's' : ''; ?></span>
              </button>
            </div>

            <div class="comments-section" id="comments-<?php echo $post['postID']; ?>" style="display:none;">
              <div class="comments-list" id="clist-<?php echo $post['postID']; ?>"></div>
              <form class="comment-form" onsubmit="submitComment(event,<?php echo $post['postID']; ?>)">
                <input type="text" class="comment-input" placeholder="Write a comment…" maxlength="500" required/>
                <button type="submit" class="comment-submit">Post</button>
              </form>
            </div>

          </div>
        <?php endforeach; ?>
      <?php endif; ?>

    </main>

    <!-- RIGHT: Notification panel -->
    <aside class="notif-panel">
      <div class="notif-card">
        <div class="notif-header-row">
          <h3 class="notif-heading">
            Notifications
            <?php if ($unreadCount > 0): ?>
              <span class="notif-badge" id="notifBadge"><?php echo $unreadCount; ?></span>
            <?php else: ?>
              <span class="notif-badge" id="notifBadge" style="display:none;">0</span>
            <?php endif; ?>
          </h3>
          <button class="notif-mark-read" onclick="markAllRead()" title="Mark all read">&#10003;</button>
        </div>
        <div id="notifList" class="notif-list">
          <p class="notif-empty">Loading…</p>
        </div>
      </div>
    </aside>

  </div><!-- end page-body -->

<script>
// ── Drawer ─────────────────────────────────────────────────────────
function toggleDrawer() {
  document.getElementById('navDrawer').classList.toggle('open');
  document.getElementById('drawerOverlay').classList.toggle('show');
}
function closeDrawer() {
  document.getElementById('navDrawer').classList.remove('open');
  document.getElementById('drawerOverlay').classList.remove('show');
}
// Close drawer on ESC
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDrawer(); });

// ── Modal ──────────────────────────────────────────────────────────
function openModal()          { document.getElementById('postModal').classList.add('show'); }
function closeModal()         { document.getElementById('postModal').classList.remove('show'); }
function closeModalOutside(e) { if (e.target.id === 'postModal') closeModal(); }
function previewImage(input) {
  const img = document.getElementById('previewImg');
  if (input.files && input.files[0]) {
    img.src = URL.createObjectURL(input.files[0]);
    img.style.display = 'block';
  }
}
function updatePreview() {
  document.getElementById('previewCaption').textContent =
    document.querySelector('.caption-input').value;
}

// ── Like ───────────────────────────────────────────────────────────
async function toggleLike(postID, btn) {
  try {
    const res  = await fetch('like.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'post_id=' + postID
    });
    const data = await res.json();
    if (data.success) {
      btn.querySelector('.like-count').textContent = data.like_count;
      btn.classList.toggle('liked', data.liked);
    }
  } catch(e) { console.error('Like error:', e); }
}

// ── Comments ───────────────────────────────────────────────────────
function toggleComments(postID) {
  const section = document.getElementById('comments-' + postID);
  const open    = section.style.display === 'none';
  section.style.display = open ? 'block' : 'none';
  if (open) loadComments(postID);
}
async function loadComments(postID) {
  const list = document.getElementById('clist-' + postID);
  list.innerHTML = '<p class="loading-comments">Loading…</p>';
  try {
    const res  = await fetch('comment.php?action=get&post_id=' + postID);
    const data = await res.json();
    list.innerHTML = '';
    if (data.comments && data.comments.length) {
      data.comments.forEach(c => {
        const d = document.createElement('div');
        d.className = 'comment-item';
        d.innerHTML = `<span class="comment-author">${esc(c.username)}</span> ${esc(c.content)}`;
        list.appendChild(d);
      });
    } else {
      list.innerHTML = '<p class="no-comments">No comments yet. Be the first!</p>';
    }
  } catch(e) { list.innerHTML = '<p class="no-comments">Could not load comments.</p>'; }
}
async function submitComment(e, postID) {
  e.preventDefault();
  const input = e.target.querySelector('.comment-input');
  const content = input.value.trim();
  if (!content) return;
  try {
    const res  = await fetch('comment.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'post_id=' + postID + '&content=' + encodeURIComponent(content)
    });
    const data = await res.json();
    if (data.success) { input.value = ''; loadComments(postID); }
  } catch(e) { console.error('Comment error:', e); }
}

// ── Friend actions ─────────────────────────────────────────────────
async function sendFriendRequest(authorID) {
  const btn = document.querySelector(`#fa-${authorID} button`);
  if (btn) { btn.disabled = true; btn.textContent = '…'; }
  try {
    const res  = await fetch('friends.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=send&receiver_id=' + authorID
    });
    const data = await res.json();
    if (data.success) {
      const fa = document.getElementById('fa-' + authorID);
      fa.innerHTML = `<button class="btn-friend-pending"
        onclick="cancelFriendRequest(${data.friendshipID}, ${authorID})">Pending</button>`;
    } else if (btn) {
      btn.disabled = false; btn.textContent = '+ Add Friend';
    }
  } catch(e) { console.error(e); }
}
async function cancelFriendRequest(friendshipID, authorID) {
  try {
    await fetch('friends.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=cancel&friendship_id=' + friendshipID
    });
    const fa = document.getElementById('fa-' + authorID);
    fa.innerHTML = `<button class="btn-add-friend"
      onclick="sendFriendRequest(${authorID})">+ Add Friend</button>`;
  } catch(e) { console.error(e); }
}
async function acceptFriendRequest(friendshipID, authorID) {
  try {
    const res  = await fetch('friends.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=accept&friendship_id=' + friendshipID
    });
    const data = await res.json();
    if (data.success) {
      const fa = document.getElementById('fa-' + authorID);
      if (fa) fa.innerHTML = '<span class="friend-badge">&#10003; Friends</span>';
      loadNotifications(); // Refresh tray
    }
  } catch(e) { console.error(e); }
}

// ── Notifications ──────────────────────────────────────────────────
async function loadNotifications() {
  try {
    const res  = await fetch('notifications.php?action=list');
    const data = await res.json();
    const list = document.getElementById('notifList');
    const badge = document.getElementById('notifBadge');
    if (!data.success || !data.notifications.length) {
      list.innerHTML = '<p class="notif-empty">No notifications yet.</p>';
      badge.style.display = 'none';
      return;
    }
    const unread = data.notifications.filter(n => !parseInt(n.is_read)).length;
    badge.textContent = unread;
    badge.style.display = unread > 0 ? 'inline-flex' : 'none';

    list.innerHTML = '';
    data.notifications.forEach(n => {
      const item = document.createElement('div');
      item.className = 'notif-item' + (parseInt(n.is_read) ? '' : ' unread');
      item.dataset.id = n.notifID;

      let actionHTML = '';
      if (n.type === 'friend_request' && n.ref_id) {
        actionHTML = `
          <div class="notif-actions">
            <button onclick="acceptFromNotif(${n.ref_id}, ${n.notifID})" class="btn-notif-accept">Accept</button>
            <button onclick="rejectFromNotif(${n.ref_id}, ${n.notifID})" class="btn-notif-reject">Decline</button>
          </div>`;
      }

      item.innerHTML = `
        <p class="notif-msg">${esc(n.message)}</p>
        <span class="notif-time">${esc(n.created_at.substring(0,16).replace('T',' '))}</span>
        ${actionHTML}`;

      item.addEventListener('click', function(e) {
        if (e.target.tagName === 'BUTTON') return;
        markRead(n.notifID);
        item.classList.remove('unread');
      });
      list.appendChild(item);
    });
  } catch(e) { console.error('Notification error:', e); }
}

async function acceptFromNotif(friendshipID, notifID) {
  try {
    const res  = await fetch('friends.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=accept&friendship_id=' + friendshipID
    });
    const data = await res.json();
    if (data.success) { markRead(notifID); loadNotifications(); }
  } catch(e) { console.error(e); }
}
async function rejectFromNotif(friendshipID, notifID) {
  try {
    await fetch('friends.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=reject&friendship_id=' + friendshipID
    });
    markRead(notifID);
    loadNotifications();
  } catch(e) { console.error(e); }
}
async function markRead(notifID) {
  await fetch('notifications.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=mark_read&notif_id=' + notifID
  });
}
async function markAllRead() {
  await fetch('notifications.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=mark_read'
  });
  loadNotifications();
}

function esc(str) {
  const d = document.createElement('div');
  d.textContent = String(str ?? '');
  return d.innerHTML;
}

// Boot
loadNotifications();
// Refresh notification count every 60 s
setInterval(loadNotifications, 60000);
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
