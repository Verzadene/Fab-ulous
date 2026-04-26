<?php
session_start();

// RBAC: admin and super_admin only
$role = $_SESSION['user']['role'] ?? '';
if (!isset($_SESSION['user']) || !in_array($role, ['admin', 'super_admin'])) {
    header('Location: ../admin/admin_login.php');
    exit;
}

$conn = new mysqli("localhost", "root", "", "fab_ulous");
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

$adminID       = (int)$_SESSION['user']['id'];
$adminUsername = $_SESSION['user']['username'];
$isSuperAdmin  = ($role === 'super_admin');

// ── Handle POST Actions ──────────────────────────────────────────
$actionMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action   = $_POST['action'];
    $targetID = (int)($_POST['target_id'] ?? 0);

    if ($action === 'ban_user' && $targetID) {
        // super_admin can ban anyone except other super_admins; admin can ban only users
        $allowedRoles = $isSuperAdmin ? "('user','admin')" : "('user')";
        $upd = $conn->prepare("UPDATE accounts SET banned = 1 WHERE id = ? AND role IN $allowedRoles AND id != ?");
        $upd->bind_param("ii", $targetID, $adminID); $upd->execute(); $upd->close();
        $sel = $conn->prepare("SELECT username FROM accounts WHERE id = ?");
        $sel->bind_param("i", $targetID); $sel->execute();
        $row = $sel->get_result()->fetch_assoc(); $sel->close();
        $logAction = "Banned user: " . ($row['username'] ?? 'Unknown');
        $log = $conn->prepare("INSERT INTO audit_log (admin_id, admin_username, action, target_type, target_id) VALUES (?,?,?,'user',?)");
        $log->bind_param("issi", $adminID, $adminUsername, $logAction, $targetID);
        $log->execute(); $log->close();
        $actionMsg = "User banned.";
    }

    if ($action === 'unban_user' && $targetID) {
        $allowedRoles = $isSuperAdmin ? "('user','admin')" : "('user')";
        $upd = $conn->prepare("UPDATE accounts SET banned = 0 WHERE id = ? AND role IN $allowedRoles");
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

    // super_admin only: promote user → admin
    if ($action === 'promote_to_admin' && $targetID && $isSuperAdmin) {
        $upd = $conn->prepare("UPDATE accounts SET role = 'admin' WHERE id = ? AND role = 'user'");
        $upd->bind_param("i", $targetID); $upd->execute(); $upd->close();
        $sel = $conn->prepare("SELECT username FROM accounts WHERE id = ?");
        $sel->bind_param("i", $targetID); $sel->execute();
        $row = $sel->get_result()->fetch_assoc(); $sel->close();
        $logAction = "Promoted to admin: " . ($row['username'] ?? 'Unknown');
        $log = $conn->prepare("INSERT INTO audit_log (admin_id, admin_username, action, target_type, target_id) VALUES (?,?,?,'user',?)");
        $log->bind_param("issi", $adminID, $adminUsername, $logAction, $targetID);
        $log->execute(); $log->close();
        $actionMsg = "User promoted to admin.";
    }

    // super_admin only: demote admin → user
    if ($action === 'demote_to_user' && $targetID && $isSuperAdmin) {
        $upd = $conn->prepare("UPDATE accounts SET role = 'user' WHERE id = ? AND role = 'admin'");
        $upd->bind_param("i", $targetID); $upd->execute(); $upd->close();
        $sel = $conn->prepare("SELECT username FROM accounts WHERE id = ?");
        $sel->bind_param("i", $targetID); $sel->execute();
        $row = $sel->get_result()->fetch_assoc(); $sel->close();
        $logAction = "Demoted to user: " . ($row['username'] ?? 'Unknown');
        $log = $conn->prepare("INSERT INTO audit_log (admin_id, admin_username, action, target_type, target_id) VALUES (?,?,?,'user',?)");
        $log->bind_param("issi", $adminID, $adminUsername, $logAction, $targetID);
        $log->execute(); $log->close();
        $actionMsg = "Admin demoted to user.";
    }

    // Update commission status / admin note
    if ($action === 'update_commission' && $targetID) {
        $newStatus = $_POST['commission_status'] ?? '';
        $adminNote = mb_substr(trim($_POST['admin_note'] ?? ''), 0, 500);
        $allowedStatuses = ['Pending', 'In Progress', 'Completed', 'Cancelled'];
        if (in_array($newStatus, $allowedStatuses, true)) {
            $upd = $conn->prepare("UPDATE commissions SET status = ?, admin_note = ? WHERE commissionID = ?");
            if ($upd) {
                $upd->bind_param("ssi", $newStatus, $adminNote, $targetID);
                $upd->execute(); $upd->close();
                $actionMsg = "Commission #$targetID updated.";
            }
        }
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

// ── Commissions ──────────────────────────────────────────────────
$commissions = [];
// Check if admin_note column exists (added by migration_v2.sql)
$hasAdminNote = false;
$colRes = $conn->query("SHOW COLUMNS FROM commissions LIKE 'admin_note'");
if ($colRes && $colRes->num_rows > 0) $hasAdminNote = true;

$noteCol = $hasAdminNote ? ', c.admin_note' : ", '' AS admin_note";
$cRes = $conn->query("
    SELECT c.commissionID, c.title, c.description, c.amount, c.status, c.created_at$noteCol,
           a.username AS requester
    FROM commissions c
    LEFT JOIN accounts a ON c.userID = a.id
    ORDER BY c.created_at DESC
");
if ($cRes) while ($r = $cRes->fetch_assoc()) $commissions[] = $r;

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
      <button class="sidebar-link" onclick="switchTab('commissions',this)">Commissions</button>
      <a href="../post/post.php" class="sidebar-link">Post Section</a>
      <a href="admin_logout.php" class="sidebar-link logout-link">Logout</a>
    </nav>
    <?php if ($isSuperAdmin): ?>
      <div class="sidebar-role-badge">Super Admin</div>
    <?php endif; ?>
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
              <?php
                $uRole       = $u['role'];
                $isSelf      = ((int)$u['id'] === $adminID);
                $isSuperTgt  = ($uRole === 'super_admin');
                $isAdminTgt  = ($uRole === 'admin');
                // Admins can only act on plain users; super_admin can act on users and admins (not self, not other supers)
                $canBan      = !$isSelf && !$isSuperTgt && ($isSuperAdmin || $uRole === 'user');
                $canPromote  = $isSuperAdmin && $uRole === 'user';
                $canDemote   = $isSuperAdmin && $isAdminTgt && !$isSelf;
              ?>
              <tr class="<?php echo $u['banned'] ? 'banned-row' : ''; ?>">
                <td><?php echo $u['id']; ?></td>
                <td><?php echo htmlspecialchars($u['first_name'].' '.$u['last_name']); ?></td>
                <td><?php echo htmlspecialchars($u['username']); ?></td>
                <td><?php echo htmlspecialchars($u['email']); ?></td>
                <td><span class="role-badge <?php echo $uRole; ?>"><?php echo $uRole; ?></span></td>
                <td><?php echo $u['banned']
                    ? '<span class="banned-badge">Banned</span>'
                    : '<span class="active-badge">Active</span>'; ?></td>
                <td><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
                <td class="action-cell">
                  <?php if ($canBan): ?>
                    <form method="POST" style="display:inline;">
                      <input type="hidden" name="action"    value="<?php echo $u['banned'] ? 'unban_user' : 'ban_user'; ?>"/>
                      <input type="hidden" name="target_id" value="<?php echo $u['id']; ?>"/>
                      <button type="submit"
                              class="action-btn <?php echo $u['banned'] ? 'btn-unban' : 'btn-ban'; ?>"
                              onclick="return confirm('<?php echo $u['banned'] ? 'Unban' : 'Ban'; ?> this user?')">
                        <?php echo $u['banned'] ? 'Unban' : 'Ban'; ?>
                      </button>
                    </form>
                  <?php endif; ?>
                  <?php if ($canPromote): ?>
                    <form method="POST" style="display:inline;">
                      <input type="hidden" name="action"    value="promote_to_admin"/>
                      <input type="hidden" name="target_id" value="<?php echo $u['id']; ?>"/>
                      <button type="submit" class="action-btn btn-promote"
                              onclick="return confirm('Promote to admin?')">Promote</button>
                    </form>
                  <?php endif; ?>
                  <?php if ($canDemote): ?>
                    <form method="POST" style="display:inline;">
                      <input type="hidden" name="action"    value="demote_to_user"/>
                      <input type="hidden" name="target_id" value="<?php echo $u['id']; ?>"/>
                      <button type="submit" class="action-btn btn-demote"
                              onclick="return confirm('Demote this admin to user?')">Demote</button>
                    </form>
                  <?php endif; ?>
                  <?php if (!$canBan && !$canPromote && !$canDemote): ?>
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

    <!-- ── COMMISSIONS TAB ── -->
    <div id="tab-commissions" class="tab-content">
      <h2 class="section-heading">Commission Management</h2>
      <div class="table-wrap">
        <table class="admin-table commissions-table">
          <thead>
            <tr>
              <th>ID</th><th>Requester</th><th>Title</th><th>Description</th>
              <th>Amount</th><th>Status</th><th>Submitted</th><th>Admin Note</th><th>Update</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($commissions)): ?>
              <tr><td colspan="9" style="text-align:center;padding:28px;color:rgba(255,255,255,0.4);">No commissions yet.</td></tr>
            <?php else: ?>
              <?php foreach ($commissions as $c): ?>
                <tr>
                  <td>#<?php echo $c['commissionID']; ?></td>
                  <td><?php echo htmlspecialchars($c['requester'] ?? '—'); ?></td>
                  <td class="caption-cell"><?php echo htmlspecialchars($c['title'] ?? '—'); ?></td>
                  <td class="caption-cell"><?php echo htmlspecialchars(mb_substr($c['description'] ?? '', 0, 60)) . (mb_strlen($c['description'] ?? '') > 60 ? '…' : ''); ?></td>
                  <td>&#8369;<?php echo number_format((float)($c['amount'] ?? 0), 2); ?></td>
                  <td><span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $c['status'])); ?>"><?php echo htmlspecialchars($c['status']); ?></span></td>
                  <td><?php echo date('M d, Y', strtotime($c['created_at'])); ?></td>
                  <td class="caption-cell"><?php echo htmlspecialchars($c['admin_note'] ?? ''); ?></td>
                  <td>
                    <form method="POST" class="commission-form">
                      <input type="hidden" name="action"    value="update_commission"/>
                      <input type="hidden" name="target_id" value="<?php echo $c['commissionID']; ?>"/>
                      <select name="commission_status" class="commission-select">
                        <?php foreach (['Pending','In Progress','Completed','Cancelled'] as $s): ?>
                          <option value="<?php echo $s; ?>" <?php echo $c['status'] === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                        <?php endforeach; ?>
                      </select>
                      <input type="text" name="admin_note" class="commission-note"
                             value="<?php echo htmlspecialchars($c['admin_note'] ?? ''); ?>"
                             placeholder="Add a note…" maxlength="500"/>
                      <button type="submit" class="action-btn btn-save">Save</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div><!-- end tab-commissions -->

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
