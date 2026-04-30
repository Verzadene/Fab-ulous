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
$username = $_SESSION['user']['username'];
$name = $_SESSION['user']['name'];
$role = $_SESSION['user']['role'] ?? 'user';
$isAdmin = in_array($role, ['admin', 'super_admin'], true);

$hasFriendships = (bool)$conn->query("SHOW TABLES LIKE 'friendships'")->num_rows;
$hasNotifs = (bool)$conn->query("SHOW TABLES LIKE 'notifications'")->num_rows;
$myProfilePic = $_SESSION['user']['profile_pic'] ?? null;
$myAvatarUrl = $myProfilePic ? '../uploads/profile_pics/' . rawurlencode($myProfilePic) : null;

if ($hasFriendships) {
    $feedStmt = $conn->prepare("
        SELECT p.postID, p.caption, p.image_url, p.created_at,
               a.id AS authorID, a.username AS author, a.profile_pic AS author_pic,
               (SELECT COUNT(*) FROM likes WHERE postID = p.postID) AS like_count,
               (SELECT COUNT(*) FROM comments WHERE postID = p.postID) AS comment_count,
               EXISTS(SELECT 1 FROM likes WHERE postID = p.postID AND userID = ?) AS user_liked
        FROM posts p
        JOIN accounts a ON p.userID = a.id
        WHERE p.userID = ?
           OR EXISTS(
               SELECT 1 FROM friendships
               WHERE status = 'accepted'
                 AND ((requesterID = ? AND receiverID = p.userID)
                   OR (receiverID = ? AND requesterID = p.userID))
           )
        ORDER BY p.created_at DESC
        LIMIT 20
    ");
    $feedStmt->bind_param("iiii", $userID, $userID, $userID, $userID);
    $feedStmt->execute();
    $posts = $feedStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $feedStmt->close();

    $discStmt = $conn->prepare("
        SELECT p.postID, p.caption, p.image_url, p.created_at,
               a.id AS authorID, a.username AS author, a.profile_pic AS author_pic,
               (SELECT COUNT(*) FROM likes WHERE postID = p.postID) AS like_count,
               (SELECT COUNT(*) FROM comments WHERE postID = p.postID) AS comment_count,
               EXISTS(SELECT 1 FROM likes WHERE postID = p.postID AND userID = ?) AS user_liked,
               COALESCE((
                   SELECT status FROM friendships
                   WHERE (requesterID = ? AND receiverID = a.id)
                      OR (receiverID = ? AND requesterID = a.id)
                   LIMIT 1
               ), 'none') AS friend_status,
               (SELECT friendshipID FROM friendships
                WHERE (requesterID = ? AND receiverID = a.id)
                   OR (receiverID = ? AND requesterID = a.id)
                LIMIT 1) AS friendship_id,
               (SELECT requesterID FROM friendships
                WHERE (requesterID = ? AND receiverID = a.id)
                   OR (receiverID = ? AND requesterID = a.id)
                LIMIT 1) AS friend_requester
        FROM posts p
        JOIN accounts a ON p.userID = a.id
        WHERE p.userID != ?
          AND NOT EXISTS(
              SELECT 1 FROM friendships
              WHERE status = 'accepted'
                AND ((requesterID = ? AND receiverID = p.userID)
                  OR (receiverID = ? AND requesterID = p.userID))
          )
        ORDER BY p.created_at DESC
        LIMIT 8
    ");
    $discStmt->bind_param(
        "iiiiiiiiii",
        $userID,
        $userID,
        $userID,
        $userID,
        $userID,
        $userID,
        $userID,
        $userID,
        $userID,
        $userID
    );
    $discStmt->execute();
    $discoverPosts = $discStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $discStmt->close();
} else {
    $feedStmt = $conn->prepare("
        SELECT p.postID, p.caption, p.image_url, p.created_at,
               a.id AS authorID, a.username AS author, a.profile_pic AS author_pic,
               (SELECT COUNT(*) FROM likes WHERE postID = p.postID) AS like_count,
               (SELECT COUNT(*) FROM comments WHERE postID = p.postID) AS comment_count,
               EXISTS(SELECT 1 FROM likes WHERE postID = p.postID AND userID = ?) AS user_liked
        FROM posts p
        JOIN accounts a ON p.userID = a.id
        ORDER BY p.created_at DESC
        LIMIT 20
    ");
    $feedStmt->bind_param("i", $userID);
    $feedStmt->execute();
    $posts = $feedStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $feedStmt->close();
    $discoverPosts = [];
}

$unreadCount = 0;
if ($hasNotifs) {
    $notifCountStmt = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE userID = ? AND is_read = 0");
    $notifCountStmt->bind_param("i", $userID);
    $notifCountStmt->execute();
    $unreadCount = (int)$notifCountStmt->get_result()->fetch_assoc()['c'];
    $notifCountStmt->close();
}

$friendDirectory = [];
if ($hasFriendships) {
    $friendDirStmt = $conn->prepare("
        SELECT a.id,
               CONCAT(a.first_name, ' ', a.last_name) AS name,
               a.username,
               COALESCE((
                   SELECT status FROM friendships
                   WHERE (requesterID = ? AND receiverID = a.id)
                      OR (receiverID = ? AND requesterID = a.id)
                   LIMIT 1
               ), 'none') AS friend_status,
               (SELECT friendshipID FROM friendships
                WHERE (requesterID = ? AND receiverID = a.id)
                   OR (receiverID = ? AND requesterID = a.id)
                LIMIT 1) AS friendship_id,
               (SELECT requesterID FROM friendships
                WHERE (requesterID = ? AND receiverID = a.id)
                   OR (receiverID = ? AND requesterID = a.id)
                LIMIT 1) AS friend_requester
        FROM accounts a
        WHERE a.id != ? AND a.banned = 0
        ORDER BY
            CASE
                WHEN EXISTS(
                    SELECT 1 FROM friendships
                    WHERE status = 'accepted'
                      AND ((requesterID = ? AND receiverID = a.id)
                        OR (receiverID = ? AND requesterID = a.id))
                ) THEN 0
                ELSE 1
            END,
            a.username ASC
    ");
    $friendDirStmt->bind_param(
        "iiiiiiiii",
        $userID,
        $userID,
        $userID,
        $userID,
        $userID,
        $userID,
        $userID,
        $userID,
        $userID
    );
    $friendDirStmt->execute();
    $friendDirectory = $friendDirStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $friendDirStmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FABulous - Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Josefin+Sans:wght@400;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="post.css"/>
</head>
<body>
  <div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>
  <nav class="nav-drawer" id="navDrawer" aria-label="Quick navigation">
    <div class="drawer-profile">
      <div class="drawer-avatar">
        <?php if ($myAvatarUrl): ?>
          <img src="<?php echo htmlspecialchars($myAvatarUrl); ?>" class="drawer-avatar-img" alt="Profile"/>
        <?php else: ?>
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="52" height="52">
            <circle cx="50" cy="35" r="22" fill="#1a1a1a"/>
            <ellipse cx="50" cy="85" rx="35" ry="25" fill="#1a1a1a"/>
          </svg>
        <?php endif; ?>
      </div>
      <p class="drawer-name"><?php echo htmlspecialchars($name); ?></p>
      <p class="drawer-username">@<?php echo htmlspecialchars($username); ?></p>
    </div>
    <a href="post.php" class="drawer-link active" onclick="closeDrawer()">News Feed</a>
    <a href="messages.php" class="drawer-link" onclick="closeDrawer()">Messages</a>
    <a href="#" class="drawer-link" onclick="closeDrawer()">Uploads</a>
    <a href="../profile/profile.php" class="drawer-link" onclick="closeDrawer()">Settings</a>
    <?php if ($isAdmin): ?>
      <a href="../admin/admin.php" class="drawer-link drawer-admin" onclick="closeDrawer()">Admin Dashboard</a>
    <?php endif; ?>
    <a href="../login/logout.php" class="drawer-link drawer-logout" onclick="closeDrawer()">Logout</a>
  </nav>

  <nav class="topnav">
    <img src="../images/Top_Left_Nav_Logo.png" alt="FABulous Logo" class="nav-logo"/>
    <div class="nav-links">
      <a href="post.php" class="nav-item active">Home</a>
      <a href="#" class="nav-item">Projects</a>
      <a href="commissions.php" class="nav-item">Commissions</a>
      <a href="#" class="nav-item">History</a>
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

  <div class="dashboard-body">
    <div class="dashboard-grid">
      <main class="feed">
        <button class="create-post-btn" onclick="openModal()">
          + Share a Project or Update
        </button>

        <div id="postModal" class="modal-overlay" onclick="closeModalOutside(event)">
          <div class="modal-card">
            <button class="modal-close" onclick="closeModal()">&times;</button>
            <div class="modal-body">
              <div class="modal-left">
                <h2 class="modal-title">Create Post</h2>
                <form action="create_post.php" method="POST" enctype="multipart/form-data" id="postForm">
                  <textarea
                    name="caption"
                    class="caption-input"
                    placeholder="Share your project update..."
                    rows="6"
                    oninput="updatePreview()"
                    required
                  ></textarea>
                  <label class="file-label">
                    <input type="file" name="image" accept="image/*" id="imgInput" onchange="previewImage(this)"/>
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

        <?php if (empty($posts)): ?>
          <div class="empty-feed">
            <p>No posts from friends yet.</p>
            <p class="empty-sub">Add friends from the right panel to start building your feed.</p>
          </div>
        <?php else: ?>
          <?php foreach ($posts as $post): ?>
            <?php $isOwnPost = ((int)$post['authorID'] === $userID); ?>
            <div class="post-card" id="post-<?php echo $post['postID']; ?>">
              <div class="post-header">
                <div class="post-avatar">
                  <?php if (!empty($post['author_pic'])): ?>
                    <img src="../uploads/profile_pics/<?php echo rawurlencode($post['author_pic']); ?>" class="post-avatar-img" alt=""/>
                  <?php else: ?>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="42" height="42">
                      <circle cx="50" cy="35" r="22" fill="#4E7A5E"/>
                      <ellipse cx="50" cy="85" rx="35" ry="25" fill="#4E7A5E"/>
                    </svg>
                  <?php endif; ?>
                </div>
                <div class="post-meta">
                  <span class="post-author"><?php echo htmlspecialchars($post['author']); ?></span>
                  <span class="post-time"><?php echo date('M d, Y \a\t H:i', strtotime($post['created_at'])); ?></span>
                </div>
                <?php if ($isOwnPost): ?>
                  <div class="post-own-actions">
                    <button class="post-own-btn" onclick="openEditPost(<?php echo $post['postID']; ?>, <?php echo htmlspecialchars(json_encode($post['caption']), ENT_QUOTES); ?>)" title="Edit">&#9998;</button>
                    <button class="post-own-btn post-own-delete" onclick="deletePost(<?php echo $post['postID']; ?>)" title="Delete">&#128465;</button>
                  </div>
                <?php endif; ?>
              </div>

              <?php if (!empty($post['caption'])): ?>
                <p class="post-caption"><?php echo nl2br(htmlspecialchars($post['caption'])); ?></p>
              <?php endif; ?>

              <?php if (!empty($post['image_url'])): ?>
                <img src="<?php echo htmlspecialchars($post['image_url']); ?>" class="post-image" alt="Post image"/>
              <?php endif; ?>

              <div class="post-actions">
                <button class="post-action-btn like-btn <?php echo $post['user_liked'] ? 'liked' : ''; ?>" onclick="toggleLike(<?php echo $post['postID']; ?>, this)">
                  <span class="heart-icon">&#10084;</span>
                  <span class="like-count"><?php echo $post['like_count']; ?></span>
                </button>
                <button class="post-action-btn comment-toggle-btn" onclick="toggleComments(<?php echo $post['postID']; ?>)">
                  <span>&#128172;</span>
                  <span><?php echo $post['comment_count']; ?> Comment<?php echo (int)$post['comment_count'] !== 1 ? 's' : ''; ?></span>
                </button>
              </div>

              <div class="comments-section" id="comments-<?php echo $post['postID']; ?>" style="display:none;">
                <div class="comments-list" id="clist-<?php echo $post['postID']; ?>"></div>
                <form class="comment-form" onsubmit="submitComment(event,<?php echo $post['postID']; ?>)">
                  <input type="text" class="comment-input" placeholder="Write a comment..." maxlength="500" required/>
                  <button type="submit" class="comment-submit">Post</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($discoverPosts)): ?>
          <div class="discover-heading">
            <span>Discover People</span>
          </div>
          <?php foreach ($discoverPosts as $post): ?>
            <?php
              $fStatus = $post['friend_status'] ?? 'none';
              $fID = (int)($post['friendship_id'] ?? 0);
              $fRequester = (int)($post['friend_requester'] ?? 0);
              $authorID = (int)$post['authorID'];
            ?>
            <div class="post-card discover-card" id="post-<?php echo $post['postID']; ?>">
              <div class="post-header">
                <div class="post-avatar">
                  <?php if (!empty($post['author_pic'])): ?>
                    <img src="../uploads/profile_pics/<?php echo rawurlencode($post['author_pic']); ?>" class="post-avatar-img" alt=""/>
                  <?php else: ?>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="42" height="42">
                      <circle cx="50" cy="35" r="22" fill="#4E7A5E"/>
                      <ellipse cx="50" cy="85" rx="35" ry="25" fill="#4E7A5E"/>
                    </svg>
                  <?php endif; ?>
                </div>
                <div class="post-meta">
                  <span class="post-author"><?php echo htmlspecialchars($post['author']); ?></span>
                  <span class="post-time"><?php echo date('M d, Y \a\t H:i', strtotime($post['created_at'])); ?></span>
                </div>
                <div class="friend-action" id="fa-<?php echo $authorID; ?>">
                  <?php if ($fStatus === 'none'): ?>
                    <button class="btn-add-friend" onclick="sendFriendRequest(<?php echo $authorID; ?>)">+ Add Friend</button>
                  <?php elseif ($fStatus === 'pending' && $fRequester === $userID): ?>
                    <button class="btn-friend-pending" onclick="cancelFriendRequest(<?php echo $fID; ?>, <?php echo $authorID; ?>)">Pending</button>
                  <?php elseif ($fStatus === 'pending' && $fRequester !== $userID): ?>
                    <button class="btn-accept-friend" onclick="acceptFriendRequest(<?php echo $fID; ?>, <?php echo $authorID; ?>)">Accept</button>
                  <?php else: ?>
                    <span class="friend-badge">&#10003; Friends</span>
                  <?php endif; ?>
                </div>
              </div>

              <?php if (!empty($post['caption'])): ?>
                <p class="post-caption"><?php echo nl2br(htmlspecialchars($post['caption'])); ?></p>
              <?php endif; ?>

              <?php if (!empty($post['image_url'])): ?>
                <img src="<?php echo htmlspecialchars($post['image_url']); ?>" class="post-image" alt="Post image"/>
              <?php endif; ?>

              <div class="post-actions">
                <button class="post-action-btn like-btn <?php echo $post['user_liked'] ? 'liked' : ''; ?>" onclick="toggleLike(<?php echo $post['postID']; ?>, this)">
                  <span class="heart-icon">&#10084;</span>
                  <span class="like-count"><?php echo $post['like_count']; ?></span>
                </button>
                <button class="post-action-btn comment-toggle-btn" onclick="toggleComments(<?php echo $post['postID']; ?>)">
                  <span>&#128172;</span>
                  <span><?php echo $post['comment_count']; ?> Comment<?php echo (int)$post['comment_count'] !== 1 ? 's' : ''; ?></span>
                </button>
              </div>

              <div class="comments-section" id="comments-<?php echo $post['postID']; ?>" style="display:none;">
                <div class="comments-list" id="clist-<?php echo $post['postID']; ?>"></div>
                <form class="comment-form" onsubmit="submitComment(event,<?php echo $post['postID']; ?>)">
                  <input type="text" class="comment-input" placeholder="Write a comment..." maxlength="500" required/>
                  <button type="submit" class="comment-submit">Post</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </main>

      <aside class="right-rail">
        <section class="side-card notif-card">
          <div class="side-card-header">
            <div>
              <p class="side-card-kicker">Updates</p>
              <h3 class="side-card-title notif-heading">
                Notifications
                <?php if ($unreadCount > 0): ?>
                  <span class="notif-badge" id="notifBadge"><?php echo $unreadCount; ?></span>
                <?php else: ?>
                  <span class="notif-badge" id="notifBadge" style="display:none;">0</span>
                <?php endif; ?>
              </h3>
            </div>
            <button class="notif-mark-read" onclick="markAllRead()" title="Mark all read">&#10003;</button>
          </div>
          <div id="notifList" class="notif-list">
            <p class="notif-empty">Loading...</p>
          </div>
        </section>

        <section class="side-card friends-card">
          <div class="side-card-header friends-card-header">
            <div>
              <p class="side-card-kicker">Community</p>
              <h3 class="side-card-title">Friends</h3>
            </div>
            <span class="friend-count-pill" id="friendCountPill">0</span>
          </div>

          <label class="friend-search-shell" for="friendSearchInput">
            <span class="friend-search-icon">&#128269;</span>
            <input id="friendSearchInput" class="friend-search-input" type="search" placeholder="Search people" autocomplete="off"/>
          </label>

          <div class="friend-section">
            <div class="friend-section-heading">Pending Requests</div>
            <div class="friend-stack" id="friendRequestsList">
              <p class="friend-empty">No pending requests.</p>
            </div>
          </div>

          <div class="friend-section">
            <div class="friend-section-heading">Your Friends</div>
            <div class="friend-stack" id="friendsList">
              <p class="friend-empty">No friends yet.</p>
            </div>
          </div>

          <div class="friend-section">
            <div class="friend-section-heading">Find People</div>
            <div class="friend-stack" id="friendResultsList">
              <p class="friend-empty friend-empty-alone">Search by name or username to connect.</p>
            </div>
          </div>
        </section>
      </aside>
    </div>
  </div>

<!-- Edit Post Modal -->
<div id="editPostModal" class="modal-overlay" onclick="closeEditModalOutside(event)" style="display:none;">
  <div class="modal-card" style="max-width:520px;">
    <button class="modal-close" onclick="closeEditPost()">&times;</button>
    <div class="modal-body" style="flex-direction:column;gap:16px;">
      <h2 class="modal-title">Edit Post</h2>
      <form id="editPostForm" onsubmit="submitEditPost(event)">
        <input type="hidden" id="editPostId" name="post_id"/>
        <textarea id="editPostCaption" name="caption" class="caption-input" rows="6" maxlength="2000" required></textarea>
        <div class="modal-actions" style="margin-top:12px;">
          <button type="button" class="modal-btn-discard" onclick="closeEditPost()">Cancel</button>
          <button type="submit" class="modal-btn-upload">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const burgerBtn = document.getElementById('burgerBtn');
const navDrawer = document.getElementById('navDrawer');
const drawerOverlay = document.getElementById('drawerOverlay');
const friendSearchInput = document.getElementById('friendSearchInput');
const friendRequestsList = document.getElementById('friendRequestsList');
const friendsList = document.getElementById('friendsList');
const friendResultsList = document.getElementById('friendResultsList');
const friendCountPill = document.getElementById('friendCountPill');
const myUserID = <?php echo (int)$userID; ?>;
let friendDirectory = <?php echo json_encode($friendDirectory, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

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

function openModal() {
  document.getElementById('postModal').classList.add('show');
}

function closeModal() {
  document.getElementById('postModal').classList.remove('show');
}

function closeModalOutside(event) {
  if (event.target.id === 'postModal') closeModal();
}

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

function esc(value) {
  const div = document.createElement('div');
  div.textContent = String(value ?? '');
  return div.innerHTML;
}

function normalizeFriendRecord(record) {
  return {
    id: Number(record.id),
    name: String(record.name ?? '').trim() || String(record.username ?? ''),
    username: String(record.username ?? ''),
    friend_status: String(record.friend_status ?? 'none'),
    friendship_id: record.friendship_id ? Number(record.friendship_id) : 0,
    friend_requester: record.friend_requester ? Number(record.friend_requester) : 0
  };
}

function updateFriendRecord(authorID, patch) {
  friendDirectory = friendDirectory.map(record => {
    const normalized = normalizeFriendRecord(record);
    if (normalized.id !== Number(authorID)) return normalized;
    return { ...normalized, ...patch };
  });
  renderFriendsPanel();
  syncDiscoverFriendAction(authorID);
}

function updateFriendRecordByFriendship(friendshipID, patch) {
  let targetID = null;
  friendDirectory = friendDirectory.map(record => {
    const normalized = normalizeFriendRecord(record);
    if (normalized.friendship_id !== Number(friendshipID)) return normalized;
    targetID = normalized.id;
    return { ...normalized, ...patch };
  });
  renderFriendsPanel();
  if (targetID !== null) {
    syncDiscoverFriendAction(targetID);
  }
}

function renderDiscoverFriendButton(record) {
  const authorID = Number(record.id);
  if (record.friend_status === 'accepted') {
    return '<span class="friend-badge">&#10003; Friends</span>';
  }
  if (record.friend_status === 'pending' && record.friend_requester === myUserID) {
    return `<button class="btn-friend-pending" onclick="cancelFriendRequest(${record.friendship_id}, ${authorID})">Pending</button>`;
  }
  if (record.friend_status === 'pending' && record.friend_requester !== myUserID) {
    return `<button class="btn-accept-friend" onclick="acceptFriendRequest(${record.friendship_id}, ${authorID})">Accept</button>`;
  }
  return `<button class="btn-add-friend" onclick="sendFriendRequest(${authorID})">+ Add Friend</button>`;
}

function syncDiscoverFriendAction(authorID) {
  const slot = document.getElementById('fa-' + authorID);
  if (!slot) return;
  const record = friendDirectory.map(normalizeFriendRecord).find(item => item.id === Number(authorID));
  if (!record) return;
  slot.innerHTML = renderDiscoverFriendButton(record);
}

function buildFriendRow(record, actionMarkup) {
  const safeName = esc(record.name);
  const safeUsername = esc(record.username);
  const initial = safeUsername.charAt(0).toUpperCase() || 'U';

  return `
    <div class="friend-row">
      <div class="friend-person">
        <div class="friend-avatar">${initial}</div>
        <div class="friend-copy">
          <div class="friend-name">${safeName}</div>
          <div class="friend-handle">@${safeUsername}</div>
        </div>
      </div>
      <div class="friend-row-actions">${actionMarkup}</div>
    </div>
  `;
}

function getFilteredFriendMatches() {
  const query = (friendSearchInput?.value || '').trim().toLowerCase();
  if (!query) return [];

  return friendDirectory
    .map(normalizeFriendRecord)
    .filter(record => {
      const name = record.name.toLowerCase();
      const username = record.username.toLowerCase();
      return name.includes(query) || username.includes(query);
    });
}

function renderFriendsPanel() {
  const records = friendDirectory.map(normalizeFriendRecord);
  const incoming = records.filter(record => record.friend_status === 'pending' && record.friend_requester !== myUserID);
  const accepted = records.filter(record => record.friend_status === 'accepted');
  const matches = getFilteredFriendMatches();

  friendCountPill.textContent = String(accepted.length);

  friendRequestsList.innerHTML = incoming.length
    ? incoming.map(record => buildFriendRow(record, `
        <button class="friend-mini-btn accept" onclick="acceptFriendRequest(${record.friendship_id}, ${record.id})">Accept</button>
        <button class="friend-mini-btn remove" onclick="removeFriend(${record.friendship_id}, ${record.id}, 'reject')">Decline</button>
      `)).join('')
    : '<p class="friend-empty">No pending requests.</p>';

  friendsList.innerHTML = accepted.length
    ? accepted.map(record => buildFriendRow(record, `
        <button class="friend-mini-btn remove" onclick="removeFriend(${record.friendship_id}, ${record.id}, 'remove')">Remove</button>
      `)).join('')
    : '<p class="friend-empty">No friends yet. Start adding people below.</p>';

  if (!friendSearchInput?.value.trim()) {
    friendResultsList.innerHTML = '<p class="friend-empty friend-empty-alone">Search by name or username to connect.</p>';
    return;
  }

  friendResultsList.innerHTML = matches.length
    ? matches.map(record => {
        let actionMarkup = '';

        if (record.friend_status === 'accepted') {
          actionMarkup = `<button class="friend-mini-btn remove" onclick="removeFriend(${record.friendship_id}, ${record.id}, 'remove')">Remove</button>`;
        } else if (record.friend_status === 'pending' && record.friend_requester === myUserID) {
          actionMarkup = `<button class="friend-mini-btn pending" onclick="cancelFriendRequest(${record.friendship_id}, ${record.id})">Pending</button>`;
        } else if (record.friend_status === 'pending') {
          actionMarkup = `
            <button class="friend-mini-btn accept" onclick="acceptFriendRequest(${record.friendship_id}, ${record.id})">Accept</button>
            <button class="friend-mini-btn remove" onclick="removeFriend(${record.friendship_id}, ${record.id}, 'reject')">Decline</button>
          `;
        } else {
          actionMarkup = `<button class="friend-mini-btn add" onclick="sendFriendRequest(${record.id})">Add</button>`;
        }

        return buildFriendRow(record, actionMarkup);
      }).join('')
    : '<p class="friend-empty friend-empty-alone">No people matched your search.</p>';
}

async function toggleLike(postID, btn) {
  try {
    const response = await fetch('like.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'post_id=' + postID
    });
    const data = await response.json();
    if (data.success) {
      btn.querySelector('.like-count').textContent = data.like_count;
      btn.classList.toggle('liked', data.liked);
    }
  } catch (error) {
    console.error('Like error:', error);
  }
}

function toggleComments(postID) {
  const section = document.getElementById('comments-' + postID);
  const open = section.style.display === 'none';
  section.style.display = open ? 'block' : 'none';
  if (open) loadComments(postID);
}

async function loadComments(postID) {
  const list = document.getElementById('clist-' + postID);
  list.innerHTML = '<p class="loading-comments">Loading...</p>';
  try {
    const response = await fetch('comment.php?action=get&post_id=' + postID);
    const data = await response.json();
    list.innerHTML = '';

    if (data.comments && data.comments.length) {
      data.comments.forEach(comment => {
        const item = document.createElement('div');
        item.className = 'comment-item';
        item.innerHTML = `<span class="comment-author">${esc(comment.username)}</span> ${esc(comment.content)}`;
        list.appendChild(item);
      });
    } else {
      list.innerHTML = '<p class="no-comments">No comments yet. Be the first!</p>';
    }
  } catch (error) {
    list.innerHTML = '<p class="no-comments">Could not load comments.</p>';
  }
}

async function submitComment(event, postID) {
  event.preventDefault();
  const input = event.target.querySelector('.comment-input');
  const content = input.value.trim();
  if (!content) return;

  try {
    const response = await fetch('comment.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'post_id=' + postID + '&content=' + encodeURIComponent(content)
    });
    const data = await response.json();
    if (data.success) {
      input.value = '';
      loadComments(postID);
    }
  } catch (error) {
    console.error('Comment error:', error);
  }
}

async function sendFriendRequest(authorID) {
  const btn = document.querySelector(`#fa-${authorID} button`);
  if (btn) {
    btn.disabled = true;
    btn.textContent = '...';
  }

  try {
    const response = await fetch('friends.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=send&receiver_id=' + authorID
    });
    const data = await response.json();

    if (data.success) {
      updateFriendRecord(authorID, {
        friend_status: 'pending',
        friendship_id: Number(data.friendshipID),
        friend_requester: myUserID
      });
    } else if (btn) {
      btn.disabled = false;
      btn.textContent = '+ Add Friend';
    }
  } catch (error) {
    console.error(error);
  }
}

async function cancelFriendRequest(friendshipID, authorID) {
  try {
    const response = await fetch('friends.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=cancel&friendship_id=' + friendshipID
    });
    const data = await response.json();

    if (data.success) {
      updateFriendRecord(authorID, {
        friend_status: 'none',
        friendship_id: 0,
        friend_requester: 0
      });
    }
  } catch (error) {
    console.error(error);
  }
}

async function acceptFriendRequest(friendshipID, authorID) {
  try {
    const response = await fetch('friends.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=accept&friendship_id=' + friendshipID
    });
    const data = await response.json();

    if (data.success) {
      updateFriendRecord(authorID, {
        friend_status: 'accepted',
        friendship_id: Number(friendshipID),
        friend_requester: 0
      });
      loadNotifications();
    }
  } catch (error) {
    console.error(error);
  }
}

async function removeFriend(friendshipID, authorID, action = 'remove') {
  try {
    const response = await fetch('friends.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=' + encodeURIComponent(action) + '&friendship_id=' + friendshipID
    });
    const data = await response.json();

    if (data.success) {
      updateFriendRecord(authorID, {
        friend_status: 'none',
        friendship_id: 0,
        friend_requester: 0
      });
      loadNotifications();
    }
  } catch (error) {
    console.error(error);
  }
}

async function loadNotifications() {
  try {
    const response = await fetch('notifications.php?action=list');
    const data = await response.json();
    const list = document.getElementById('notifList');
    const badge = document.getElementById('notifBadge');

    if (!data.success || !data.notifications.length) {
      list.innerHTML = '<p class="notif-empty">No notifications yet.</p>';
      badge.style.display = 'none';
      return;
    }

    const unread = data.notifications.filter(item => !parseInt(item.is_read, 10)).length;
    badge.textContent = unread;
    badge.style.display = unread > 0 ? 'inline-flex' : 'none';
    list.innerHTML = '';

    data.notifications.forEach(notification => {
      const item = document.createElement('div');
      item.className = 'notif-item' + (parseInt(notification.is_read, 10) ? '' : ' unread');
      item.dataset.id = notification.notifID;

      let actionHTML = '';
      if (notification.type === 'friend_request' && notification.ref_id) {
        actionHTML = `
          <div class="notif-actions">
            <button onclick="acceptFromNotif(${notification.ref_id}, ${notification.notifID})" class="btn-notif-accept">Accept</button>
            <button onclick="rejectFromNotif(${notification.ref_id}, ${notification.notifID})" class="btn-notif-reject">Decline</button>
          </div>
        `;
      }

      item.innerHTML = `
        <p class="notif-msg">${esc(notification.message)}</p>
        <span class="notif-time">${esc(String(notification.created_at).substring(0, 16).replace('T', ' '))}</span>
        ${actionHTML}
      `;

      item.addEventListener('click', function(event) {
        if (event.target.tagName === 'BUTTON') return;
        markRead(notification.notifID);
        item.classList.remove('unread');
      });

      list.appendChild(item);
    });
  } catch (error) {
    console.error('Notification error:', error);
  }
}

async function acceptFromNotif(friendshipID, notifID) {
  try {
    const response = await fetch('friends.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=accept&friendship_id=' + friendshipID
    });
    const data = await response.json();

    if (data.success) {
      updateFriendRecordByFriendship(friendshipID, {
        friend_status: 'accepted',
        friendship_id: Number(friendshipID),
        friend_requester: 0
      });
      markRead(notifID);
      loadNotifications();
    }
  } catch (error) {
    console.error(error);
  }
}

async function rejectFromNotif(friendshipID, notifID) {
  try {
    const response = await fetch('friends.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=reject&friendship_id=' + friendshipID
    });
    const data = await response.json();

    if (data.success) {
      updateFriendRecordByFriendship(friendshipID, {
        friend_status: 'none',
        friendship_id: 0,
        friend_requester: 0
      });
      markRead(notifID);
      loadNotifications();
    }
  } catch (error) {
    console.error(error);
  }
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

friendSearchInput?.addEventListener('input', renderFriendsPanel);

renderFriendsPanel();
loadNotifications();
setInterval(loadNotifications, 60000);

function openEditPost(postId, caption) {
  document.getElementById('editPostId').value = postId;
  document.getElementById('editPostCaption').value = caption;
  document.getElementById('editPostModal').style.display = 'flex';
}

function closeEditPost() {
  document.getElementById('editPostModal').style.display = 'none';
}

function closeEditModalOutside(event) {
  if (event.target.id === 'editPostModal') closeEditPost();
}

async function submitEditPost(event) {
  event.preventDefault();
  const postId = document.getElementById('editPostId').value;
  const caption = document.getElementById('editPostCaption').value.trim();
  if (!caption) return;

  try {
    const response = await fetch('edit_post.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'post_id=' + encodeURIComponent(postId) + '&caption=' + encodeURIComponent(caption)
    });
    const data = await response.json();
    if (data.success) {
      const card = document.getElementById('post-' + postId);
      if (card) {
        const captionEl = card.querySelector('.post-caption');
        if (captionEl) captionEl.innerHTML = esc(caption).replace(/\n/g, '<br>');
      }
      closeEditPost();
    } else {
      alert(data.error || 'Could not save changes.');
    }
  } catch (error) {
    console.error('Edit post error:', error);
  }
}

async function deletePost(postId) {
  if (!confirm('Delete this post? This cannot be undone.')) return;

  try {
    const response = await fetch('delete_post.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'post_id=' + postId
    });
    const data = await response.json();
    if (data.success) {
      const card = document.getElementById('post-' + postId);
      if (card) card.remove();
    } else {
      alert(data.error || 'Could not delete post.');
    }
  } catch (error) {
    console.error('Delete post error:', error);
  }
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
