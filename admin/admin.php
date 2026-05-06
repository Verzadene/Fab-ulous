<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/AdminRepository.php';

// RBAC: admin and super_admin only
$role = $_SESSION['user']['role'] ?? '';
if (!isset($_SESSION['user']) || !in_array($role, ['admin', 'super_admin'], true)) {
    header('Location: ../admin/admin_login.php');
    exit;
}

if (empty($_SESSION['mfa_verified'])) {
    header('Location: ../login/verify_mfa.php');
    exit;
}

$conn = db_connect();

$adminID       = (int)$_SESSION['user']['id'];
$adminUsername = $_SESSION['user']['username'];
$isSuperAdmin  = ($role === 'super_admin');

$adminRepo = new AdminRepository($conn);

// ── Handle POST Actions ──────────────────────────────────────────
$actionMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action   = $_POST['action'];
    $targetID = (int)($_POST['target_id'] ?? 0);

    if ($action === 'ban_user' && $targetID) {
        $actionMsg = $adminRepo->processBanUser($targetID, $adminID, $adminUsername, $isSuperAdmin);
    } elseif ($action === 'unban_user' && $targetID) {
        $actionMsg = $adminRepo->processUnbanUser($targetID, $adminID, $adminUsername);
    } elseif ($action === 'delete_post' && $targetID) {
        $actionMsg = $adminRepo->processDeletePost($targetID, $adminID, $adminUsername);
    } elseif ($action === 'promote_to_admin' && $targetID && $isSuperAdmin) {
        $actionMsg = $adminRepo->processPromoteToAdmin($targetID, $adminID, $adminUsername);
    } elseif ($action === 'demote_to_user' && $targetID && $isSuperAdmin) {
        $actionMsg = $adminRepo->processDemoteToUser($targetID, $adminID, $adminUsername);
    } elseif ($action === 'update_commission' && $targetID) {
        $newStatus = $_POST['commission_status'] ?? '';
        $adminNote = $_POST['admin_note'] ?? '';
        $amount = (float)($_POST['amount'] ?? 0);
        $actionMsg = $adminRepo->processUpdateCommission($targetID, $adminID, $newStatus, $adminNote, $amount);
    }

    header('Location: admin.php?msg=' . urlencode($actionMsg));
    exit;
}
if (isset($_GET['msg'])) $actionMsg = htmlspecialchars($_GET['msg']);

// ── Dashboard Metrics ────────────────────────────────────────────
$metrics = $adminRepo->getDashboardMetrics();
$activeProjects = $metrics['activeProjects'];
$totalUsers     = $metrics['totalUsers'];
$engagementRate = $metrics['engagementRate'];
$revenueSales   = $metrics['revenueSales'];

// ── Order Pipeline ───────────────────────────────────────────────
$pipeline = $adminRepo->getOrderPipeline();

// ── Live Audit Log (visibility-filtered) ────────────────────────
$auditLogs = $adminRepo->getAuditLogs($isSuperAdmin);

// ── User List ────────────────────────────────────────────────────
$users = $adminRepo->getAllUsers();

// ── All Posts (Feed Moderator) ───────────────────────────────────
$allPosts = $adminRepo->getAllPosts();

// ── Commissions ──────────────────────────────────────────────────
$commissions = $adminRepo->getAllCommissions();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>FABulous – Admin Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Josefin+Sans:wght@400;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
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

    <div class="action-msg" id="liveActionMsg" <?php echo $actionMsg ? '' : 'style="display:none;"'; ?>>
      <?php echo htmlspecialchars($actionMsg); ?>
    </div>

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
            <span><span class="legend-dot accepted"></span>Accepted: <?php echo $pipeline['Accepted']; ?></span>
            <span><span class="legend-dot ongoing"></span>Ongoing: <?php echo $pipeline['Ongoing']; ?></span>
            <span><span class="legend-dot delayed"></span>Delayed: <?php echo $pipeline['Delayed']; ?></span>
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
                // Admins can unban admin peers so suspended staff accounts can be recovered.
                $canUnban    = !$isSelf && (bool)$u['banned'] && in_array($uRole, ['user','admin','super_admin'], true);
                $canBan      = !$isSelf && !$u['banned'] && !$isSuperTgt && ($isSuperAdmin || $uRole === 'user');
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
                  <?php if ($canBan || $canUnban): ?>
                    <form method="POST" style="display:inline;">
                      <input type="hidden" name="action"    value="<?php echo $canUnban ? 'unban_user' : 'ban_user'; ?>"/>
                      <input type="hidden" name="target_id" value="<?php echo $u['id']; ?>"/>
                      <button type="submit"
                              class="action-btn <?php echo $canUnban ? 'btn-unban' : 'btn-ban'; ?>"
                              onclick="return confirm('<?php echo $canUnban ? 'Unban' : 'Ban'; ?> this account?')">
                        <?php echo $canUnban ? 'Unban' : 'Ban'; ?>
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
                  <?php if (!$canBan && !$canUnban && !$canPromote && !$canDemote): ?>
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
                  <td class="caption-cell caption-cell-expanded">
                    <?php
                      $caption = $p['caption'] ?? '';
                      $shortCaption = mb_substr($caption, 0, 90);
                      $captionIsLong = mb_strlen($caption) > 90;
                    ?>
                    <?php if ($captionIsLong): ?>
                      <details class="caption-details">
                        <summary><?php echo htmlspecialchars($shortCaption); ?>...</summary>
                        <div class="caption-full"><?php echo nl2br(htmlspecialchars($caption)); ?></div>
                      </details>
                    <?php else: ?>
                      <?php echo htmlspecialchars($caption); ?>
                    <?php endif; ?>
                  </td>
                  <td><?php echo $p['likes']; ?></td>
                  <td><?php echo $p['comments']; ?></td>
                  <td><?php echo date('M d, Y', strtotime($p['created_at'])); ?></td>
                  <td>
                    <form method="POST" style="display:inline;">
                      <input type="hidden" name="action"    value="delete_post"/>
                      <input type="hidden" name="target_id" value="<?php echo $p['postID']; ?>"/>
                      <button type="submit" class="action-btn btn-ban"
                              onclick="return confirmDeletePost(this, <?php echo (int)$p['postID']; ?>, <?php echo htmlspecialchars(json_encode(mb_substr($p['caption'] ?? '', 0, 200)), ENT_QUOTES); ?>)">Remove</button>
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
              <th>ID</th><th>Requester</th><th>Email</th><th>Title</th><th>Description</th>
              <th>Amount</th><th>Status</th><th>Submitted</th><th>Admin Note</th><th>Update</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($commissions)): ?>
              <tr><td colspan="10" style="text-align:center;padding:28px;color:rgba(255,255,255,0.4);">No commissions yet.</td></tr>
            <?php else: ?>
              <?php foreach ($commissions as $c): ?>
                <tr>
                  <td>#<?php echo $c['commissionID']; ?></td>
                  <td><?php echo htmlspecialchars($c['requester'] ?? '—'); ?></td>
                  <td>
                    <?php if (!empty($c['requester_email'])): ?>
                      <a class="requester-email" href="mailto:<?php echo htmlspecialchars($c['requester_email']); ?>">
                        <?php echo htmlspecialchars($c['requester_email']); ?>
                      </a>
                    <?php else: ?>
                      <span class="no-action">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="caption-cell"><?php echo htmlspecialchars($c['title'] ?? '—'); ?></td>
                  <td class="caption-cell"><?php echo htmlspecialchars(mb_substr($c['description'] ?? '', 0, 60)) . (mb_strlen($c['description'] ?? '') > 60 ? '…' : ''); ?></td>
                  <td id="commission-amount-display-<?php echo $c['commissionID']; ?>">&#8369;<?php echo number_format((float)($c['amount'] ?? 0), 2); ?></td>
                  <td>
                    <span
                      class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $c['status'])); ?>"
                      id="commission-status-<?php echo $c['commissionID']; ?>"
                    >
                      <?php echo htmlspecialchars($c['status']); ?>
                    </span>
                  </td>
                  <td><?php echo date('M d, Y', strtotime($c['created_at'])); ?></td>
                  <td class="caption-cell" id="commission-note-display-<?php echo $c['commissionID']; ?>">
                    <?php echo htmlspecialchars(($c['admin_note'] ?? '') !== '' ? $c['admin_note'] : 'No note yet.'); ?>
                  </td>
                  <td>
                    <form method="POST" class="commission-form" data-commission-id="<?php echo $c['commissionID']; ?>">
                      <input type="hidden" name="action"    value="update_commission"/>
                      <input type="hidden" name="target_id" value="<?php echo $c['commissionID']; ?>"/>
                      <select name="commission_status" class="commission-select">
                        <?php foreach (['Pending','Accepted','Ongoing','Delayed','Completed','Cancelled'] as $s): ?>
                          <option value="<?php echo $s; ?>" <?php echo $c['status'] === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                        <?php endforeach; ?>
                      </select>
                      <input type="text" name="admin_note" class="commission-note"
                             value="<?php echo htmlspecialchars($c['admin_note'] ?? ''); ?>"
                             placeholder="Add a note…" maxlength="500"/>
                      <label class="commission-amount-label">
                        Amount
                        <input type="number" name="amount" class="commission-amount-input"
                               value="<?php echo htmlspecialchars(number_format((float)($c['amount'] ?? 0), 2, '.', '')); ?>"
                               min="0" step="0.01"/>
                      </label>
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
function confirmDeletePost(btn, postId, caption) {
  const preview = caption ? '\n\nCaption preview:\n"' + caption.substring(0, 120) + (caption.length > 120 ? '…' : '') + '"' : '';
  return confirm('Remove post #' + postId + ' permanently?' + preview);
}

function switchTab(name, btn) {
  document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.sidebar-link').forEach(l => l.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  btn.classList.add('active');
}

const liveActionMsg = document.getElementById('liveActionMsg');

function showActionMessage(message, isError = false) {
  liveActionMsg.textContent = message;
  liveActionMsg.style.display = 'block';
  liveActionMsg.style.borderColor = isError ? '#e74c3c' : '';
  liveActionMsg.style.color = isError ? '#ff8a8a' : '';
  liveActionMsg.style.background = isError ? 'rgba(231,76,60,0.12)' : '';
}

function statusClassName(status) {
  return 'status-badge status-' + String(status).toLowerCase().replaceAll(' ', '-');
}

async function saveCommissionForm(form, successMessage) {
  const payload = new FormData(form);

  try {
    const response = await fetch('commission_update.php', {
      method: 'POST',
      body: payload
    });
    const data = await response.json();

    if (!data.success) {
      showActionMessage(data.error || 'Commission update failed.', true);
      return;
    }

    const commissionId = form.dataset.commissionId;
    const statusBadge = document.getElementById('commission-status-' + commissionId);
    const noteDisplay = document.getElementById('commission-note-display-' + commissionId);
    const amountDisplay = document.getElementById('commission-amount-display-' + commissionId);
    const noteField = form.querySelector('.commission-note');

    if (statusBadge) {
      statusBadge.className = statusClassName(data.status);
      statusBadge.textContent = data.status;
    }

    if (noteDisplay && noteField) {
      noteDisplay.textContent = noteField.value.trim() || 'No note yet.';
    }

    if (amountDisplay && data.amount_formatted) {
      amountDisplay.textContent = data.amount_formatted;
    }

    showActionMessage(successMessage || `Commission #${commissionId} updated.`);
  } catch (error) {
    showActionMessage('Commission update failed. Please try again.', true);
  }
}

document.querySelectorAll('.commission-form').forEach(form => {
  const select = form.querySelector('.commission-select');

  form.addEventListener('submit', event => {
    event.preventDefault();
    saveCommissionForm(form, `Commission #${form.dataset.commissionId} saved.`);
  });

  select?.addEventListener('change', () => {
    saveCommissionForm(form, `Commission #${form.dataset.commissionId} status updated.`);
  });
});

new Chart(document.getElementById('pipelineChart').getContext('2d'), {
  type: 'doughnut',
  data: {
    labels: ['Pending','Accepted','Ongoing','Delayed','Completed','Cancelled'],
    datasets: [{
      data: [
        <?php echo $pipeline['Pending']; ?>,
        <?php echo $pipeline['Accepted']; ?>,
        <?php echo $pipeline['Ongoing']; ?>,
        <?php echo $pipeline['Delayed']; ?>,
        <?php echo $pipeline['Completed']; ?>,
        <?php echo $pipeline['Cancelled']; ?>
      ],
      backgroundColor: ['#f39c12','#2ecc71','#3498db','#e67e22','#27ae60','#e74c3c'],
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
