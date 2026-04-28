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
$userId   = (int) $_SESSION['user']['id'];
$username = $_SESSION['user']['username'];
$name     = $_SESSION['user']['name'];
$role     = $_SESSION['user']['role'] ?? 'user';
$isAdmin  = in_array($role, ['admin', 'super_admin'], true);

// Admin POST: update commission status inline
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    header('Content-Type: application/json');
    $commissionId = (int) ($_POST['target_id'] ?? 0);
    $status       = trim($_POST['commission_status'] ?? '');
    $adminNote    = mb_substr(trim($_POST['admin_note'] ?? ''), 0, 500);
    $allowed      = ['Pending', 'In Progress', 'Completed', 'Cancelled'];

    if (!$commissionId || !in_array($status, $allowed, true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid data.']);
        $conn->close();
        exit;
    }

    $upd = $conn->prepare('UPDATE commissions SET status=?, admin_note=? WHERE commissionID=?');
    $upd->bind_param('ssi', $status, $adminNote, $commissionId);
    $ok = $upd->execute();
    $upd->close();
    $conn->close();
    echo json_encode(['success' => $ok, 'status' => $status]);
    exit;
}

// Detect columns
$commissionColumns = [];
$columnsResult = $conn->query('SHOW COLUMNS FROM commissions');
while ($columnsResult && $column = $columnsResult->fetch_assoc()) {
    $commissionColumns[$column['Field']] = true;
}

// Build title expression — prefixed with alias for admin (c.), unqualified for user query
$titleExprAdmin = isset($commissionColumns['commission_name'])
    ? "COALESCE(NULLIF(c.commission_name, ''), c.description)"
    : (isset($commissionColumns['title'])
        ? "COALESCE(NULLIF(c.title, ''), c.description)"
        : 'c.description');

$titleExprUser = isset($commissionColumns['commission_name'])
    ? "COALESCE(NULLIF(commission_name, ''), description)"
    : (isset($commissionColumns['title'])
        ? "COALESCE(NULLIF(title, ''), description)"
        : 'description');

$noteColumnAdmin = isset($commissionColumns['admin_note']) ? 'c.admin_note' : "'' AS admin_note";
$noteColumnUser  = isset($commissionColumns['admin_note']) ? 'admin_note'   : "'' AS admin_note";

if ($isAdmin) {
    // Admin sees all commissions with requester info
    $stmt = $conn->prepare(
        "SELECT c.commissionID, {$titleExprAdmin} AS title, c.description, c.amount, c.status, c.created_at, {$noteColumnAdmin},
                a.username AS requester_username,
                CONCAT(a.first_name, ' ', a.last_name) AS requester_name
         FROM commissions c
         JOIN accounts a ON c.userID = a.id
         ORDER BY c.created_at DESC"
    );
    $stmt->execute();
} else {
    // Regular user sees only their own commissions
    $stmt = $conn->prepare(
        "SELECT commissionID, {$titleExprUser} AS title, description, amount, status, created_at, {$noteColumnUser}
         FROM commissions
         WHERE userID = ?
         ORDER BY created_at DESC"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
}

$commissions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

$stats = [
    'total'     => count($commissions),
    'pending'   => 0,
    'active'    => 0,
    'completed' => 0,
    'spent'     => 0.0,
];

foreach ($commissions as $commission) {
    $stats['spent'] += (float) ($commission['amount'] ?? 0);

    if (($commission['status'] ?? '') === 'Pending') {
        $stats['pending']++;
    } elseif (($commission['status'] ?? '') === 'In Progress') {
        $stats['active']++;
    } elseif (($commission['status'] ?? '') === 'Completed') {
        $stats['completed']++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FABulous - Commissions</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Josefin+Sans:wght@400;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="post.css"/>
  <link rel="stylesheet" href="commissions.css"/>
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
    <a href="messages.php" class="drawer-link" onclick="closeDrawer()">Messages</a>
    <a href="commissions.php" class="drawer-link active" onclick="closeDrawer()">Commissions</a>
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
      <a href="commissions.php" class="nav-item active">Commissions</a>
      <a href="messages.php" class="nav-item">Messages</a>
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

  <div class="dashboard-body commissions-dashboard">
    <div class="commissions-layout">

      <section class="commission-hero side-card">
        <div class="commission-hero-copy">
          <p class="side-card-kicker">Orders</p>
          <?php if ($isAdmin): ?>
            <h1>Commission Master List</h1>
            <p>All platform commission requests. Full platform overview.</p>
          <?php else: ?>
            <h1>Your Commission History</h1>
            <p>Track the status of your FABulous service requests in one place.</p>
          <?php endif; ?>
        </div>
        <?php if ($isAdmin): ?>
          <a href="../admin/admin.php" class="commission-admin-link">Open Admin Dashboard</a>
        <?php endif; ?>
      </section>

      <section class="commission-stats">
        <article class="commission-stat side-card">
          <span>Total Requests</span>
          <strong><?php echo number_format($stats['total']); ?></strong>
        </article>
        <article class="commission-stat side-card">
          <span>Pending</span>
          <strong><?php echo number_format($stats['pending']); ?></strong>
        </article>
        <article class="commission-stat side-card">
          <span>In Progress</span>
          <strong><?php echo number_format($stats['active']); ?></strong>
        </article>
        <article class="commission-stat side-card">
          <span>Total Value</span>
          <strong>&#8369;<?php echo number_format($stats['spent'], 2); ?></strong>
        </article>
      </section>

      <section class="commission-table-card side-card">
        <div class="commission-table-head">
          <div>
            <p class="side-card-kicker">Updates</p>
            <h2>Recent Requests</h2>
          </div>
          <span class="thread-badge"><?php echo number_format($stats['completed']); ?> Completed</span>
        </div>

        <?php if (empty($commissions)): ?>
          <div class="messages-empty commission-empty">
            <strong>No commission requests yet</strong>
            <span>Your history will appear here once commission records are added to the platform.</span>
          </div>
        <?php else: ?>
          <div class="commission-table-wrap">
            <?php if ($isAdmin): ?>
              <!-- ADMIN TABLE -->
              <table class="commission-table">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Requester</th>
                    <th>Title</th>
                    <th>Description</th>
                    <th>Status / Note</th>
                    <th>Amount</th>
                    <th>Submitted</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($commissions as $c): ?>
                    <tr>
                      <td>#<?php echo (int) $c['commissionID']; ?></td>
                      <td>
                        <strong><?php echo htmlspecialchars($c['requester_username'] ?? ''); ?></strong><br/>
                        <small><?php echo htmlspecialchars($c['requester_name'] ?? ''); ?></small>
                      </td>
                      <td><?php echo htmlspecialchars($c['title'] ?: 'Untitled Request'); ?></td>
                      <td class="commission-description"><?php echo htmlspecialchars(mb_substr($c['description'] ?? '', 0, 96)); ?></td>
                      <td>
                        <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $c['status'])); ?>">
                          <?php echo htmlspecialchars($c['status']); ?>
                        </span>
                        <form class="commission-form" onsubmit="saveCommission(event, <?php echo (int) $c['commissionID']; ?>)">
                          <select name="commission_status" class="commission-select">
                            <option value="Pending" <?php echo $c['status'] === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="In Progress" <?php echo $c['status'] === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="Completed" <?php echo $c['status'] === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="Cancelled" <?php echo $c['status'] === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                          </select>
                          <textarea name="admin_note" class="commission-note" placeholder="Admin note"><?php echo htmlspecialchars($c['admin_note'] ?? ''); ?></textarea>
                          <button type="submit" class="action-btn btn-save">Save</button>
                        </form>
                      </td>
                      <td>&#8369;<?php echo number_format((float) ($c['amount'] ?? 0), 2); ?></td>
                      <td><?php echo date('M d, Y', strtotime($c['created_at'])); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php else: ?>
              <!-- USER TABLE -->
              <table class="commission-table">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th>Amount</th>
                    <th>Submitted</th>
                    <th>Admin Note</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($commissions as $commission): ?>
                    <tr>
                      <td>#<?php echo (int) $commission['commissionID']; ?></td>
                      <td><?php echo htmlspecialchars($commission['title'] ?: 'Untitled Request'); ?></td>
                      <td class="commission-description"><?php echo htmlspecialchars(mb_substr($commission['description'] ?? '', 0, 96)); ?></td>
                      <td>
                        <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $commission['status'])); ?>">
                          <?php echo htmlspecialchars($commission['status']); ?>
                        </span>
                      </td>
                      <td>&#8369;<?php echo number_format((float) ($commission['amount'] ?? 0), 2); ?></td>
                      <td><?php echo date('M d, Y', strtotime($commission['created_at'])); ?></td>
                      <td><?php echo htmlspecialchars($commission['admin_note'] ?: 'No note yet.'); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </section>

    </div>
  </div>

  <script>
    const burgerBtn     = document.getElementById('burgerBtn');
    const navDrawer     = document.getElementById('navDrawer');
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

    <?php if ($isAdmin): ?>
    async function saveCommission(event, id) {
      event.preventDefault();
      const form = event.target;
      const data = new FormData(form);
      data.append('target_id', id);
      try {
        const r = await fetch('commissions.php', { method: 'POST', body: new URLSearchParams(data) });
        const json = await r.json();
        if (json.success) {
          const badge = form.closest('tr').querySelector('.status-badge');
          if (badge) {
            badge.textContent = json.status;
            badge.className = 'status-badge status-' + json.status.toLowerCase().replace(' ', '-');
          }
        }
      } catch (e) { console.error(e); }
    }
    <?php endif; ?>
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
