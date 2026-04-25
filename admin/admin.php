<?php
session_start();

// RBAC: admin only
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: ../admin/admin_login.php');
    exit;
}

$conn = new mysqli("localhost", "root", "", "fab_ulous");
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

$adminID       = (int)$_SESSION['user']['id'];
$adminUsername = $_SESSION['user']['username'];

// ── Handle POST Actions ──────────────────────────────────────────
$actionMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action   = $_POST['action'];
    $targetID = (int)($_POST['target_id'] ?? 0);

    if ($action === 'ban_user' && $targetID) {
        $upd = $conn->prepare("UPDATE accounts SET banned = 1 WHERE id = ? AND role = 'user'");
        $upd->bind_param("i", $targetID); $upd->execute(); $upd->close();
        $sel = $conn->prepare("SELECT username FROM accounts WHERE id = ?");
        $sel->bind_param("i", $targetID); $sel->execute();
        $row = $sel->get_result()->fetch_assoc(); $sel->close();
        $logAction = "Banned user: " . ($row['username'] ?? 'Unknown');
        $log = $conn->prepare("INSERT INTO audit_log (admin_id, admin_username, action, target_type, target_id) VALUES (?,?,?,'user',?)");
        $log->bind_param("issi", $adminID, $adminUsername, $logAction, $targetID);
        $log->execute(); $log->close();
        $actionMsg = "User banned successfully.";
    }

    if ($action === 'unban_user' && $targetID) {
        $upd = $conn->prepare("UPDATE accounts SET banned = 0 WHERE id = ?");
        $upd->bind_param("i", $targetID); $upd->execute(); $upd->close();
        $sel = $conn->prepare("SELECT username FROM accounts WHERE id = ?");
        $sel->bind_param("i", $targetID); $sel->execute();
        $row = $sel->get_result()->fetch_assoc(); $sel->close();
        $logAction = "Unbanned user: " . ($row['username'] ?? 'Unknown');
        $log = $conn->prepare("INSERT INTO audit_log (admin_id, admin_username, action, target_type, target_id) VALUES (?,?,?,'user',?)");
        $log->bind_param("issi", $adminID, $adminUsername, $logAction, $targetID);
        $log->execute(); $log->close();
        $actionMsg = "User unbanned.";
    }

    if ($action === 'delete_post' && $targetID) {
        $del = $conn->prepare("DELETE FROM posts WHERE postID = ?");
        $del->bind_param("i", $targetID); $del->execute(); $del->close();
        $logAction = "Removed post #$targetID";
        $log = $conn->prepare("INSERT INTO audit_log (admin_id, admin_username, action, target_type, target_id) VALUES (?,?,?,'post',?)");
        $log->bind_param("issi", $adminID, $adminUsername, $logAction, $targetID);
        $log->execute(); $log->close();
        $actionMsg = "Post removed.";
    }

    header('Location: admin.php?msg=' . urlencode($actionMsg));
    exit;
}
if (isset($_GET['msg'])) $actionMsg = htmlspecialchars($_GET['msg']);

// ── Dashboard Metrics ────────────────────────────────────────────
$activeProjects = (int)$conn->query("SELECT COUNT(*) AS c FROM posts")->fetch_assoc()['c'];
$totalUsers     = (int)$conn->query("SELECT COUNT(*) AS c FROM accounts WHERE role='user'")->fetch_assoc()['c'];

$engRow = $conn->query("
    SELECT ((SELECT COUNT(*) FROM likes)+(SELECT COUNT(*) FROM comments)) AS i,
           (SELECT COUNT(*) FROM posts) AS p
")->fetch_assoc();
$engagementRate = $engRow['p'] > 0 ? round($engRow['i'] / $engRow['p'], 2) : 0;

$revRow = $conn->query("SELECT COALESCE(SUM(amount),0) AS t FROM commissions WHERE status='Completed'")->fetch_assoc();
$revenueSales = number_format((float)$revRow['t'], 2);

// ── Order Pipeline ───────────────────────────────────────────────
$pipeline = ['Pending' => 0, 'In Progress' => 0, 'Completed' => 0, 'Cancelled' => 0];
$pRes = $conn->query("SELECT status, COUNT(*) AS c FROM commissions GROUP BY status");
if ($pRes) while ($r = $pRes->fetch_assoc()) $pipeline[$r['status']] = (int)$r['c'];

// ── Live Audit Log ───────────────────────────────────────────────
$auditLogs = [];
$aRes = $conn->query("SELECT admin_username, action, created_at FROM audit_log ORDER BY created_at DESC LIMIT 8");
if ($aRes) while ($r = $aRes->fetch_assoc()) $auditLogs[] = $r;

// ── User List ────────────────────────────────────────────────────
$users = [];
$uRes = $conn->query("SELECT id, first_name, last_name, username, email, role, banned, created_at FROM accounts ORDER BY created_at DESC");
if ($uRes) while ($r = $uRes->fetch_assoc()) $users[] = $r;

// ── All Posts (Feed Moderator) ───────────────────────────────────
$allPosts = [];
$fpRes = $conn->query("
    SELECT p.postID, p.caption, p.created_at, a.username,
           (SELECT COUNT(*) FROM likes    WHERE postID = p.postID) AS likes,
           (SELECT COUNT(*) FROM comments WHERE postID = p.postID) AS comments
    FROM posts p JOIN accounts a ON p.userID = a.id
    ORDER BY p.created_at DESC
");
if ($fpRes) while ($r = $fpRes->fetch_assoc()) $allPosts[] = $r;

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FABulous – Admin Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Roboto+Condensed:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <link rel="stylesheet" href="admin.css"/>
</head>
<body>

<div class="admin-layout">

  <!-- ── SIDEBAR ── -->
  <aside class="admin-sidebar">
    <div class="sidebar-logo">
      <img src="../images/Top_Left_Nav_Logo.png" alt="FABulous" class="sidebar-logo-img"/>
    </div>
    <nav class="sidebar-nav">
      <button class="sidebar-link active" onclick="switchTab('dashboard',this)">Dashboard</button>
      <button class="sidebar-link" onclick="switchTab('users',this)">User Management</button>
      <button class="sidebar-link" onclick="switchTab('feed',this)">Feed Moderator</button>
      <a href="../post/post.php" class="sidebar-link">Post Section</a>
      <a href="admin_logout.php" class="sidebar-link logout-link">Logout</a>
    </nav>
  </aside>

  <!-- ── MAIN ── -->
  <main class="admin-main">

    <div class="admin-header">
      <div>
        <p class="admin-welcome">Welcome back, <?php echo htmlspecialchars($adminUsername); ?>!</p>
        <h1 class="admin-title">Dashboard</h1>
      </div>
      <div class="header-actions">
        <button class="btn-print" onclick="window.print()">&#128438; Print Report</button>
        <img src="../images/Top_Left_Nav_Logo.png" alt="" class="header-logo"/>
      </div>
    </div>

    <?php if ($actionMsg): ?>
      <div class="action-msg"><?php echo $actionMsg; ?></div>
    <?php endif; ?>

    <!-- ── DASHBOARD TAB ── -->
    <div id="tab-dashboard" class="tab-content active">

      <div class="metrics-grid">
        <div class="metric-card">
          <p class="metric-title">Active Projects</p>
          <p class="metric-value"><?php echo number_format($activeProjects); ?></p>
          <p class="metric-sub">Total posts on platform</p>
        </div>
        <div class="metric-card">
          <p class="metric-title">Total Users</p>
          <p class="metric-value"><?php echo number_format($totalUsers); ?></p>
          <p class="metric-sub">Registered accounts</p>
        </div>
        <div class="metric-card">
          <p class="metric-title">Engagement Rate</p>
          <p class="metric-value"><?php echo $engagementRate; ?></p>
          <p class="metric-sub">Interactions per post</p>
        </div>
        <div class="metric-card">
          <p class="metric-title">Revenue Sales</p>
          <p class="metric-value">&#8369;<?php echo $revenueSales; ?></p>
          <p class="metric-sub">Completed commissions</p>
        </div>
      </div>

      <div class="bottom-row">
        <div class="audit-card">
          <h2 class="card-heading">Live Audit</h2>
          <div class="audit-list">
            <?php if (empty($auditLogs)): ?>
              <p class="audit-empty">No admin actions recorded yet.</p>
            <?php else: ?>
              <?php foreach ($auditLogs as $log): ?>
                <div class="audit-entry">
                  <span class="audit-admin"><?php echo htmlspecialchars($log['admin_username']); ?></span>:
                  <?php echo htmlspecialchars($log['action']); ?>
                  <span class="audit-time"><?php echo date('M d, H:i', strtotime($log['created_at'])); ?></span>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <div class="pipeline-card">
          <h2 class="card-heading" style="align-self:flex-start;">Order Pipeline</h2>
          <canvas id="pipelineChart" width="190" height="190"></canvas>
          <div class="pipeline-legend">
            <span><span class="legend-dot pending"></span>Pending: <?php echo $pipeline['Pending']; ?></span>
            <span><span class="legend-dot inprogress"></span>In Progress: <?php echo $pipeline['In Progress']; ?></span>
            <span><span class="legend-dot completed"></span>Completed: <?php echo $pipeline['Completed']; ?></span>
            <span><span class="legend-dot cancelled"></span>Cancelled: <?php echo $pipeline['Cancelled']; ?></span>
          </div>
        </div>
      </div>
    </div><!-- end tab-dashboard -->

    <!-- ── USER MANAGEMENT TAB ── -->
    <div id="tab-users" class="tab-content">
      <h2 class="section-heading">User Management</h2>
      <div class="table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th>ID</th><th>Name</th><th>Username</th><th>Email</th>
              <th>Role</th><th>Status</th><th>Joined</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $u): ?>
              <tr class="<?php echo $u['banned'] ? 'banned-row' : ''; ?>">
                <td><?php echo $u['id']; ?></td>
                <td><?php echo htmlspecialchars($u['first_name'].' '.$u['last_name']); ?></td>
                <td><?php echo htmlspecialchars($u['username']); ?></td>
                <td><?php echo htmlspecialchars($u['email']); ?></td>
                <td><span class="role-badge <?php echo $u['role']; ?>"><?php echo $u['role']; ?></span></td>
                <td><?php echo $u['banned']
                    ? '<span class="banned-badge">Banned</span>'
                    : '<span class="active-badge">Active</span>'; ?></td>
                <td><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
                <td>
                  <?php if ($u['role'] !== 'admin'): ?>
                    <form method="POST" style="display:inline;">
                      <input type="hidden" name="action"    value="<?php echo $u['banned'] ? 'unban_user' : 'ban_user'; ?>"/>
                      <input type="hidden" name="target_id" value="<?php echo $u['id']; ?>"/>
                      <button type="submit"
                              class="action-btn <?php echo $u['banned'] ? 'btn-unban' : 'btn-ban'; ?>"
                              onclick="return confirm('<?php echo $u['banned'] ? 'Unban' : 'Ban'; ?> this user?')">
                        <?php echo $u['banned'] ? 'Unban' : 'Ban'; ?>
                      </button>
                    </form>
                  <?php else: ?>
                    <span class="no-action">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div><!-- end tab-users -->

    <!-- ── FEED MODERATOR TAB ── -->
    <div id="tab-feed" class="tab-content">
      <h2 class="section-heading">Feed Moderator</h2>
      <div class="table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th>Post ID</th><th>Author</th><th>Caption</th>
              <th>Likes</th><th>Comments</th><th>Posted</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($allPosts)): ?>
              <tr><td colspan="7" style="text-align:center;padding:28px;color:rgba(255,255,255,0.4);">No posts yet.</td></tr>
            <?php else: ?>
              <?php foreach ($allPosts as $p): ?>
                <tr>
                  <td>#<?php echo $p['postID']; ?></td>
                  <td><?php echo htmlspecialchars($p['username']); ?></td>
                  <td class="caption-cell"><?php echo htmlspecialchars(mb_substr($p['caption'] ?? '', 0, 80)) . (mb_strlen($p['caption'] ?? '') > 80 ? '…' : ''); ?></td>
                  <td><?php echo $p['likes']; ?></td>
                  <td><?php echo $p['comments']; ?></td>
                  <td><?php echo date('M d, Y', strtotime($p['created_at'])); ?></td>
                  <td>
                    <form method="POST" style="display:inline;">
                      <input type="hidden" name="action"    value="delete_post"/>
                      <input type="hidden" name="target_id" value="<?php echo $p['postID']; ?>"/>
                      <button type="submit" class="action-btn btn-ban"
                              onclick="return confirm('Remove this post permanently?')">Remove</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div><!-- end tab-feed -->

  </main>
</div>

<script>
function switchTab(name, btn) {
  document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.sidebar-link').forEach(l => l.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  btn.classList.add('active');
}

new Chart(document.getElementById('pipelineChart').getContext('2d'), {
  type: 'doughnut',
  data: {
    labels: ['Pending','In Progress','Completed','Cancelled'],
    datasets: [{
      data: [
        <?php echo $pipeline['Pending']; ?>,
        <?php echo $pipeline['In Progress']; ?>,
        <?php echo $pipeline['Completed']; ?>,
        <?php echo $pipeline['Cancelled']; ?>
      ],
      backgroundColor: ['#f39c12','#3498db','#2ecc71','#e74c3c'],
      borderWidth: 0,
      hoverOffset: 8
    }]
  },
  options: {
    responsive: false,
    plugins: { legend: { display: false } },
    cutout: '62%'
  }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
