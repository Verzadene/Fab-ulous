<?php
session_start();

if (!isset($_SESSION['user'])) {
    header('Location: ../login/login.php');
    exit;
}

$conn = new mysqli("localhost", "root", "", "fab_ulous");
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

$userID   = (int)$_SESSION['user']['id'];
$username = $_SESSION['user']['username'];
$name     = $_SESSION['user']['name'];
$role     = $_SESSION['user']['role'] ?? 'user';

$postsResult = $conn->query("
    SELECT p.postID, p.caption, p.image_url, p.created_at,
           a.username AS author,
           (SELECT COUNT(*) FROM likes    WHERE postID = p.postID) AS like_count,
           (SELECT COUNT(*) FROM comments WHERE postID = p.postID) AS comment_count,
           EXISTS(SELECT 1 FROM likes WHERE postID = p.postID AND userID = $userID) AS user_liked
    FROM posts p JOIN accounts a ON p.userID = a.id
    ORDER BY p.created_at DESC
    LIMIT 20
");
$posts = [];
if ($postsResult) while ($r = $postsResult->fetch_assoc()) $posts[] = $r;

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

  <!-- TOP NAV -->
  <nav class="topnav">
    <img src="../images/Top_Left_Nav_Logo.png" alt="FABulous Logo" class="nav-logo"/>
    <div class="nav-links">
      <a href="post.php" class="nav-item active">Home</a>
      <a href="#" class="nav-item">Projects</a>
      <a href="#" class="nav-item">Commissions</a>
      <a href="#" class="nav-item">History</a>
      <?php if ($role === 'admin'): ?>
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
        <a href="post.php" class="sidebar-link active">News Feed</a>
        <a href="#" class="sidebar-link">Messages</a>
        <a href="#" class="sidebar-link">Uploads</a>
        <a href="../profile/profile.php" class="sidebar-link">Settings</a>
        <a href="../login/logout.php" class="sidebar-link sidebar-logout">Logout</a>
      </nav>
    </aside>

    <!-- MAIN FEED -->
    <main class="feed">

      <!-- Create Post Trigger -->
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
                          placeholder="Share your project update..."
                          rows="6"
                          oninput="updatePreview()"
                          required></textarea>
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
                  <div>
                    <p class="preview-author"><?php echo htmlspecialchars($username); ?></p>
                  </div>
                </div>
                <img id="previewImg" class="preview-img" style="display:none;" alt=""/>
                <p id="previewCaption" class="preview-caption"></p>
                <div class="preview-actions">
                  <span>&#10084; 0</span>
                  <span>&#128172; 0</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Feed Posts -->
      <?php if (empty($posts)): ?>
        <div class="empty-feed">
          <p>No posts yet — be the first to share a project!</p>
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

    </main>

    <!-- RIGHT NOTIF PANEL -->
    <aside class="notif-panel">
      <div class="notif-card">
        <h3 class="notif-heading">Notifications</h3>
        <p class="notif-empty">No notifications yet.</p>
      </div>
    </aside>

  </div><!-- end page-body -->

<script>
// ── Modal ──────────────────────────────────────────────
function openModal()            { document.getElementById('postModal').classList.add('show'); }
function closeModal()           { document.getElementById('postModal').classList.remove('show'); }
function closeModalOutside(e)   { if (e.target.id === 'postModal') closeModal(); }

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

// ── Like ───────────────────────────────────────────────
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

// ── Comments ───────────────────────────────────────────
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
  const input   = e.target.querySelector('.comment-input');
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

function esc(str) {
  const d = document.createElement('div');
  d.textContent = str;
  return d.innerHTML;
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
