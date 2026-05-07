<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/MessageRepository.php';

if (empty($_SESSION['user'])) {
    header('Location: ../login/login.php');
    exit;
}

if (empty($_SESSION['mfa_verified'])) {
    header('Location: ../login/verify_mfa.php');
    exit;
}

$userId = (int) $_SESSION['user']['id'];
$username = $_SESSION['user']['username'];
$name = $_SESSION['user']['name'];
$role = $_SESSION['user']['role'] ?? 'user';
$isAdmin = in_array($role, ['admin', 'super_admin'], true);

$connMessages = db_connect('messages');
$hasMessagesTable = (bool) $connMessages->query("SHOW TABLES LIKE 'messages'")->num_rows;
$connFriendships = db_connect('friendships');
$hasFriendships   = (bool) $connFriendships->query("SHOW TABLES LIKE 'friendships'")->num_rows;
$selectedPersonId = (int) ($_GET['friend'] ?? 0);

$myAvatarUrl = get_current_user_avatar();

$msgRepo = new MessageRepository('db_connect');
$contacts = $msgRepo->getContacts($userId, $hasFriendships);

$selectedContact = null;
foreach ($contacts as $contact) {
    if ($selectedPersonId === (int) $contact['id']) {
        $selectedContact = $contact;
        break;
    }
}

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
  <?php
  $navActive = 'messages';
  $navRoot = '../';
  require __DIR__ . '/../includes/app_nav.php';
  ?>

  <div class="dashboard-body messages-dashboard">
    <div class="messages-layout">
      <aside class="messages-friends side-card">
        <div class="messages-card-head">
          <div>
            <p class="side-card-kicker">Community</p>
            <h2 class="messages-title">Messages</h2>
          </div>
          <span class="messages-count"><?php echo count($contacts); ?></span>
        </div>
        <p class="messages-subtitle">Message anyone on FABulous. Friends are listed first.</p>

        <label class="friend-search-shell messages-search-shell" for="friendFilter">
          <span class="friend-search-icon">&#128269;</span>
          <input id="friendFilter" class="friend-search-input" type="search" placeholder="Filter people" autocomplete="off"/>
        </label>

        <div class="message-friend-list" id="friendList">
          <?php if (empty($contacts)): ?>
            <div class="messages-empty">
              <strong>No accounts found</strong>
              <span>There are no other registered accounts yet.</span>
            </div>
          <?php else: ?>
            <div class="messages-empty messages-search-empty" id="friendSearchEmpty" <?php echo $selectedContact ? 'style="display:none;"' : ''; ?>>
              <strong>Search for someone</strong>
              <span>Type a name or username to show matching people.</span>
            </div>
            <?php foreach ($contacts as $contact): ?>
              <?php
                $contactPic = !empty($contact['profile_pic'])
                    ? '../uploads/profile_pics/' . rawurlencode($contact['profile_pic'])
                    : null;
                $isFriend = ($contact['friend_status'] ?? 'none') === 'accepted';
              ?>
              <a
                href="?friend=<?php echo (int) $contact['id']; ?>"
                class="message-friend-row<?php echo $selectedPersonId === (int) $contact['id'] ? ' active' : ''; ?>"
                data-name="<?php echo htmlspecialchars(strtolower($contact['name'])); ?>"
                data-username="<?php echo htmlspecialchars(strtolower($contact['username'])); ?>"
                style="<?php echo $selectedPersonId === (int) $contact['id'] ? '' : 'display:none;'; ?>"
              >
                <span class="message-friend-avatar">
                  <?php if ($contactPic): ?>
                    <img src="<?php echo htmlspecialchars($contactPic); ?>" class="msg-contact-img" alt=""/>
                  <?php else: ?>
                    <?php echo htmlspecialchars(strtoupper(substr($contact['username'], 0, 1))); ?>
                  <?php endif; ?>
                </span>
                <span class="message-friend-copy">
                  <strong><?php echo htmlspecialchars($contact['name']); ?></strong>
                  <small>@<?php echo htmlspecialchars($contact['username']); ?>
                    <?php if ($isFriend): ?>
                      <span class="msg-friend-tag">&#10003; Friend</span>
                    <?php endif; ?>
                  </small>
                  <?php if (!empty($contact['bio'])): ?>
                    <div style="font-size:0.8em; opacity:0.7; margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo htmlspecialchars($contact['bio']); ?></div>
                  <?php endif; ?>
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
            <span>The code is ready, but your database still needs a compatible <code>messages</code> table. Run <code>migration_v5.sql</code>.</span>
          </div>
        <?php elseif (!$selectedContact): ?>
          <div class="messages-empty">
            <strong>Select someone to message</strong>
            <span>Choose any account from the left panel to start a conversation.</span>
          </div>
        <?php else: ?>
          <?php
            $selPic = !empty($selectedContact['profile_pic'])
                ? '../uploads/profile_pics/' . rawurlencode($selectedContact['profile_pic'])
                : null;
            $selIsFriend = ($selectedContact['friend_status'] ?? 'none') === 'accepted';
          ?>
          <div class="thread-head">
            <div class="thread-person">
              <span class="message-friend-avatar large">
                <?php if ($selPic): ?>
                  <img src="<?php echo htmlspecialchars($selPic); ?>" class="msg-contact-img large" alt=""/>
                <?php else: ?>
                  <?php echo htmlspecialchars(strtoupper(substr($selectedContact['username'], 0, 1))); ?>
                <?php endif; ?>
              </span>
              <div>
                <h3><?php echo htmlspecialchars($selectedContact['name']); ?></h3>
                <p>@<?php echo htmlspecialchars($selectedContact['username']); ?></p>
                <?php if (!empty($selectedContact['bio'])): ?>
                  <p style="font-size:0.85em; opacity:0.8; margin-top:2px; margin-bottom:0; line-height:1.3; max-width:350px;"><?php echo htmlspecialchars($selectedContact['bio']); ?></p>
                <?php endif; ?>
              </div>
            </div>
            <span class="thread-badge">
              <?php echo $selIsFriend ? '&#10003; Friend' : 'Not a friend'; ?>
            </span>
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
    const selectedFriendId = <?php echo (int) $selectedPersonId; ?>;
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
        } else {
          alert('Could not send message: ' + (data.error || 'An unknown error occurred.'));
        }
      } catch (error) {
        console.error('Message send failed.', error);
        alert('Message send failed. Please check the console for details.');
      }
    }

    friendFilter?.addEventListener('input', event => {
      const query = event.target.value.trim().toLowerCase();
      const list = document.getElementById('friendList');
      const empty = document.getElementById('friendSearchEmpty');
      const rows = Array.from(document.querySelectorAll('.message-friend-row'));

      function matchScore(item) {
        const name = item.dataset.name || '';
        const username = item.dataset.username || '';
        const haystack = `${name} ${username}`;
        if (!query || !haystack.includes(query)) return Infinity;
        if (username === query || name === query) return 0;
        if (username.startsWith(query)) return 1;
        if (name.startsWith(query)) return 2;
        const usernameIndex = username.indexOf(query);
        const nameIndex = name.indexOf(query);
        return 10 + Math.min(
          usernameIndex >= 0 ? usernameIndex : 999,
          nameIndex >= 0 ? nameIndex : 999
        );
      }

      let visible = 0;
      rows
        .map(item => ({ item, score: matchScore(item) }))
        .sort((a, b) => a.score - b.score)
        .forEach(({ item, score }) => {
          const show = Number.isFinite(score);
          item.style.display = show ? '' : 'none';
          if (show) {
            visible++;
            list?.appendChild(item);
          }
        });

      if (empty) {
        empty.style.display = query && visible ? 'none' : '';
        empty.querySelector('strong').textContent = query ? 'No matches found' : 'Search for someone';
        empty.querySelector('span').textContent = query
          ? 'Try a more specific username or name.'
          : 'Type a name or username to show matching people.';
      }
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
