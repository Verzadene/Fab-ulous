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
$userId = (int) $_SESSION['user']['id'];
$username = $_SESSION['user']['username'];
$name = $_SESSION['user']['name'];
$role = $_SESSION['user']['role'] ?? 'user';
$isAdmin = in_array($role, ['admin', 'super_admin'], true);

$hasMessagesTable = (bool) $conn->query("SHOW TABLES LIKE 'messages'")->num_rows;
$friends = [];
$selectedFriendId = (int) ($_GET['friend'] ?? 0);

$friendStmt = $conn->prepare(
    "SELECT id, name, username FROM (
        SELECT a.id,
               CONCAT(a.first_name, ' ', a.last_name) AS name,
               a.username
        FROM friendships f
        JOIN accounts a ON a.id = f.receiverID
        WHERE f.requesterID = ? AND f.status = 'accepted'

        UNION

        SELECT a.id,
               CONCAT(a.first_name, ' ', a.last_name) AS name,
               a.username
        FROM friendships f
        JOIN accounts a ON a.id = f.requesterID
        WHERE f.receiverID = ? AND f.status = 'accepted'
    ) AS accepted_friends
    ORDER BY name ASC"
);
$friendStmt->bind_param('ii', $userId, $userId);
$friendStmt->execute();
$friends = $friendStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$friendStmt->close();

$selectedFriend = null;
foreach ($friends as $friend) {
    if ($selectedFriendId === (int) $friend['id']) {
        $selectedFriend = $friend;
        break;
    }
}

if (!$selectedFriend && !empty($friends)) {
    $selectedFriend = $friends[0];
    $selectedFriendId = (int) $selectedFriend['id'];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FABulous - Messages</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Josefin+Sans:wght@400;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="post.css"/>
  <link rel="stylesheet" href="messages.css"/>
</head>
<body>
  <div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>
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
    <a href="post.php" class="drawer-link" onclick="closeDrawer()">News Feed</a>
    <a href="messages.php" class="drawer-link active" onclick="closeDrawer()">Messages</a>
    <a href="commissions.php" class="drawer-link" onclick="closeDrawer()">Commissions</a>
    <a href="../profile/profile.php" class="drawer-link" onclick="closeDrawer()">Settings</a>
    <?php if ($isAdmin): ?>
      <a href="../admin/admin.php" class="drawer-link drawer-admin" onclick="closeDrawer()">Admin Dashboard</a>
    <?php endif; ?>
    <a href="../login/logout.php" class="drawer-link drawer-logout" onclick="closeDrawer()">Logout</a>
  </nav>

  <nav class="topnav">
    <img src="../images/Top_Left_Nav_Logo.png" alt="FABulous Logo" class="nav-logo"/>
    <div class="nav-links">
      <a href="post.php" class="nav-item">Home</a>
      <a href="#" class="nav-item">Projects</a>
      <a href="commissions.php" class="nav-item">Commissions</a>
      <a href="messages.php" class="nav-item active">Messages</a>
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

  <div class="dashboard-body messages-dashboard">
    <div class="messages-layout">
      <aside class="messages-friends side-card">
        <div class="messages-card-head">
          <div>
            <p class="side-card-kicker">Community</p>
            <h2 class="messages-title">Messages</h2>
          </div>
          <span class="messages-count"><?php echo count($friends); ?></span>
        </div>
        <p class="messages-subtitle">Accepted friends are ready for direct chat.</p>

        <label class="friend-search-shell messages-search-shell" for="friendFilter">
          <span class="friend-search-icon">&#128269;</span>
          <input id="friendFilter" class="friend-search-input" type="search" placeholder="Filter friends" autocomplete="off"/>
        </label>

        <div class="message-friend-list" id="friendList">
          <?php if (empty($friends)): ?>
            <div class="messages-empty">
              <strong>No friends yet</strong>
              <span>Accept or send a friend request from the feed to start messaging.</span>
            </div>
          <?php else: ?>
            <?php foreach ($friends as $friend): ?>
              <a
                href="?friend=<?php echo (int) $friend['id']; ?>"
                class="message-friend-row<?php echo $selectedFriendId === (int) $friend['id'] ? ' active' : ''; ?>"
                data-name="<?php echo htmlspecialchars(strtolower($friend['name'])); ?>"
                data-username="<?php echo htmlspecialchars(strtolower($friend['username'])); ?>"
              >
                <span class="message-friend-avatar">
                  <?php echo htmlspecialchars(strtoupper(substr($friend['username'], 0, 1))); ?>
                </span>
                <span class="message-friend-copy">
                  <strong><?php echo htmlspecialchars($friend['name']); ?></strong>
                  <small>@<?php echo htmlspecialchars($friend['username']); ?></small>
                </span>
              </a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </aside>

      <main class="messages-thread side-card">
        <?php if (!$hasMessagesTable): ?>
          <div class="messages-empty messages-unavailable">
            <strong>Messages are not available yet</strong>
            <span>The code is ready, but your database still needs a compatible <code>messages</code> table.</span>
          </div>
        <?php elseif (!$selectedFriend): ?>
          <div class="messages-empty">
            <strong>Select a friend</strong>
            <span>Choose a friend from the left panel to open your conversation.</span>
          </div>
        <?php else: ?>
          <div class="thread-head">
            <div class="thread-person">
              <span class="message-friend-avatar large">
                <?php echo htmlspecialchars(strtoupper(substr($selectedFriend['username'], 0, 1))); ?>
              </span>
              <div>
                <h3><?php echo htmlspecialchars($selectedFriend['name']); ?></h3>
                <p>@<?php echo htmlspecialchars($selectedFriend['username']); ?></p>
              </div>
            </div>
            <span class="thread-badge">Accepted connection</span>
          </div>

          <div class="thread-stream" id="threadStream">
            <div class="messages-loading">Loading conversation...</div>
          </div>

          <form class="thread-composer" id="messageForm">
            <textarea
              id="messageInput"
              class="thread-input"
              placeholder="Write a message..."
              maxlength="1000"
              rows="3"
              required
            ></textarea>
            <div class="thread-actions">
              <p class="thread-helper">Messages refresh automatically every few seconds.</p>
              <button type="submit" class="thread-send">Send Message</button>
            </div>
          </form>
        <?php endif; ?>
      </main>
    </div>
  </div>

  <script>
    const burgerBtn = document.getElementById('burgerBtn');
    const navDrawer = document.getElementById('navDrawer');
    const drawerOverlay = document.getElementById('drawerOverlay');
    const friendFilter = document.getElementById('friendFilter');
    const threadStream = document.getElementById('threadStream');
    const messageForm = document.getElementById('messageForm');
    const messageInput = document.getElementById('messageInput');
    const selectedFriendId = <?php echo (int) $selectedFriendId; ?>;
    const messagesReady = <?php echo $hasMessagesTable ? 'true' : 'false'; ?>;
    let pollHandle = null;

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

    function esc(value) {
      const div = document.createElement('div');
      div.textContent = String(value ?? '');
      return div.innerHTML;
    }

    function renderMessages(messages) {
      if (!threadStream) return;

      if (!messages.length) {
        threadStream.innerHTML = `
          <div class="messages-empty inline-empty">
            <strong>No messages yet</strong>
            <span>Say hello and start the conversation.</span>
          </div>
        `;
        return;
      }

      threadStream.innerHTML = messages.map(message => `
        <article class="message-bubble ${message.is_mine ? 'mine' : 'theirs'}">
          <div class="message-meta">
            <strong>${esc(message.sender_name)}</strong>
            <span>${esc(message.sent_at)}</span>
          </div>
          <p>${esc(message.message_text).replace(/\n/g, '<br>')}</p>
        </article>
      `).join('');

      threadStream.scrollTop = threadStream.scrollHeight;
    }

    async function loadConversation() {
      if (!messagesReady || !selectedFriendId || !threadStream) return;

      try {
        const response = await fetch(`messages_api.php?action=conversation&friend_id=${selectedFriendId}`);
        const data = await response.json();

        if (!data.success) {
          threadStream.innerHTML = `
            <div class="messages-empty inline-empty">
              <strong>Conversation unavailable</strong>
              <span>${esc(data.error || 'Unable to load messages right now.')}</span>
            </div>
          `;
          return;
        }

        renderMessages(data.messages || []);
      } catch (error) {
        threadStream.innerHTML = `
          <div class="messages-empty inline-empty">
            <strong>Connection issue</strong>
            <span>We could not refresh this conversation.</span>
          </div>
        `;
      }
    }

    async function sendMessage(event) {
      event.preventDefault();
      if (!selectedFriendId || !messageInput) return;

      const message = messageInput.value.trim();
      if (!message) return;

      const body = new URLSearchParams({
        action: 'send',
        friend_id: String(selectedFriendId),
        message_text: message
      });

      try {
        const response = await fetch('messages_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString()
        });
        const data = await response.json();
        if (data.success) {
          messageInput.value = '';
          await loadConversation();
        }
      } catch (error) {
        console.error('Message send failed.', error);
      }
    }

    friendFilter?.addEventListener('input', event => {
      const query = event.target.value.trim().toLowerCase();
      document.querySelectorAll('.message-friend-row').forEach(item => {
        const haystack = `${item.dataset.name} ${item.dataset.username}`;
        item.style.display = haystack.includes(query) ? '' : 'none';
      });
    });

    messageForm?.addEventListener('submit', sendMessage);

    if (messagesReady && selectedFriendId) {
      loadConversation();
      pollHandle = window.setInterval(loadConversation, 4000);
    }

    window.addEventListener('beforeunload', () => {
      if (pollHandle) {
        window.clearInterval(pollHandle);
      }
    });
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
