<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/AdminRepository.php';
require_once __DIR__ . '/../post/CommissionRepository.php'; // For commission actions

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

$adminID       = (int)$_SESSION['user']['id'];
$adminUsername = $_SESSION['user']['username'];
$isSuperAdmin  = ($role === 'super_admin');

$adminRepo = new AdminRepository('db_connect');
$commissionRepo = new CommissionRepository('db_connect'); // Instantiate CommissionRepository

// ── Handle POST Actions ──────────────────────────────────────────
$actionMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action   = $_POST['action'];
    $targetID = (int)($_POST['target_id'] ?? 0);

    if ($action === 'ban_user' && $targetID) {
        $banReason = trim($_POST['ban_reason'] ?? '');
        $actionMsg = $adminRepo->processBanUser($targetID, $adminID, $adminUsername, $isSuperAdmin, $banReason);
    } elseif ($action === 'unban_user' && $targetID) {
        $actionMsg = $adminRepo->processUnbanUser($targetID, $adminID, $adminUsername);
    } elseif ($action === 'delete_user' && $targetID) {
        $deletionReason = $_POST['deletion_reason'] ?? '';
        $actionMsg = $adminRepo->processDeleteUser($targetID, $adminID, $adminUsername, $deletionReason, $isSuperAdmin);
    } elseif ($action === 'delete_post' && $targetID) {
        $actionMsg = $adminRepo->processDeletePost($targetID, $adminID, $adminUsername);
    } elseif ($action === 'promote_to_admin' && $targetID && $isSuperAdmin) {
        $actionMsg = $adminRepo->processPromoteToAdmin($targetID, $adminID, $adminUsername);
    } elseif ($action === 'demote_to_user' && $targetID && $isSuperAdmin) {
        $actionMsg = $adminRepo->processDemoteToUser($targetID, $adminID, $adminUsername); // Corrected call
    } elseif ($action === 'update_commission' && $targetID) {
        $newStatus = $_POST['commission_status'] ?? '';
        $adminNote = $_POST['admin_note'] ?? '';
        $amount = (float)($_POST['amount'] ?? 0);
        // Delegate commission updates to CommissionRepository
        $result = $commissionRepo->processUpdateCommission($targetID, $newStatus, $adminNote, $amount, $adminID, $adminUsername, ['Pending', 'Accepted', 'Ongoing', 'Delayed', 'Completed', 'Cancelled']);
        $actionMsg = $result['success'] ? $result['message'] ?? "Commission #{$targetID} updated." : $result['error'];
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

// ── Live Audit Log (visibility-filtered, searchable) ─────────────
$auditSearch = trim($_GET['audit_search'] ?? '');
$auditHours  = (int)($_GET['audit_hours'] ?? 8);
if (!in_array($auditHours, [8, 24, 72, 168, 720], true)) {
    $auditHours = 8;
}
$auditLogs = $adminRepo->searchAuditLogs($isSuperAdmin, $auditSearch, $auditHours);

// ── User List ────────────────────────────────────────────────────
$users = $adminRepo->getAllUsers();

// ── All Posts (Feed Moderator) ───────────────────────────────────
$allPosts = $adminRepo->getAllPosts();

// ── Commissions ──────────────────────────────────────────────────
$commissions = $commissionRepo->getAllCommissions(true, $adminID); // Call CommissionRepository for all commissions
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

          <!-- ── Audit Filters ── -->
          <div class="audit-filters">
            <div class="audit-search-wrap">
              <svg class="audit-search-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
              </svg>
              <input
                type="text"
                id="auditSearchInput"
                class="audit-search-input"
                placeholder="Search by username or name…"
                value="<?php echo htmlspecialchars($auditSearch); ?>"
                oninput="filterAuditLog()"
                autocomplete="off"
              >
              <button class="audit-search-clear" id="auditClearBtn" onclick="clearAuditSearch()" title="Clear search" aria-label="Clear search" style="display:<?php echo $auditSearch !== '' ? 'flex' : 'none'; ?>">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              </button>
            </div>
            <div class="audit-time-pills" role="group" aria-label="Time window">
              <?php
                $pills = [8 => '8 hrs', 24 => '24 hrs', 72 => '3 days', 168 => '7 days', 720 => '30 days'];
                foreach ($pills as $h => $label):
              ?>
                <button
                  type="button"
                  class="audit-pill<?php echo $auditHours === $h ? ' active' : ''; ?>"
                  data-hours="<?php echo $h; ?>"
                  onclick="setAuditWindow(<?php echo $h; ?>, this)"
                ><?php echo $label; ?></button>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- ── Audit result count ── -->
          <p class="audit-result-count" id="auditResultCount">
            <?php
              $cnt = count($auditLogs);
              $windowLabel = $pills[$auditHours] ?? "{$auditHours} hrs";
              echo $cnt === 0
                ? 'No entries found.'
                : "{$cnt} " . ($cnt === 1 ? 'entry' : 'entries') . " · last {$windowLabel}";
            ?>
          </p>

          <div class="audit-list" id="auditList">
            <?php if (empty($auditLogs)): ?>
              <p class="audit-empty" id="auditEmpty">No admin actions in this period.</p>
            <?php else: ?>
              <?php foreach ($auditLogs as $log):
                $fullName = trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? ''));
                $searchData = strtolower($log['admin_username'] . ' ' . $fullName);
              ?>
                <div class="audit-entry" data-search="<?php echo htmlspecialchars($searchData); ?>">
                  <span class="audit-admin"><?php echo htmlspecialchars($log['admin_username']); ?></span>
                  <?php if ($fullName): ?>
                    <span class="audit-fullname">(<?php echo htmlspecialchars($fullName); ?>)</span>
                  <?php endif; ?>:
                  <?php echo htmlspecialchars($log['action']); ?>
                  <span class="audit-time"><?php echo date('M d, H:i', strtotime($log['created_at'])); ?></span>
                </div>
              <?php endforeach; ?>
              <p class="audit-empty" id="auditEmpty" style="display:none;">No entries match your search.</p>
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
      
      <div class="admin-filters">
        <input type="text" id="filterUsersText" placeholder="Search by name, username, email..." oninput="filterUsers()" style="flex: 1; min-width: 200px;">
        <input type="date" id="filterUsersStart" onchange="filterUsers()" title="Start Date">
        <input type="date" id="filterUsersEnd" onchange="filterUsers()" title="End Date">
      </div>

      <div class="table-wrap">
        <table class="admin-table" id="usersTable">
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
                $canDelete   = !$isSelf && !$isSuperTgt && ($isSuperAdmin || $uRole === 'user');
                
                $searchString = htmlspecialchars(strtolower($u['username'] . ' ' . $u['first_name'] . ' ' . $u['last_name'] . ' ' . $u['email']));
                $dateString = date('Y-m-d', strtotime($u['created_at']));
              ?>
              <tr class="<?php echo $u['banned'] ? 'banned-row' : ''; ?>" 
                  data-search="<?php echo $searchString; ?>" 
                  data-date="<?php echo $dateString; ?>">
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
                    <?php if ($canUnban): ?>
                      <button type="button"
                              class="action-btn btn-unban"
                              onclick="openUnbanUserModal(<?php echo $u['id']; ?>, <?php echo htmlspecialchars(json_encode($u['username']), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($u['email']), ENT_QUOTES); ?>)">
                        Unban
                      </button>
                    <?php else: ?>
                      <button type="button"
                              class="action-btn btn-ban"
                              onclick="openBanUserModal(<?php echo $u['id']; ?>, <?php echo htmlspecialchars(json_encode($u['username']), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($u['email']), ENT_QUOTES); ?>)">
                        Ban
                      </button>
                    <?php endif; ?>
                  <?php endif; ?>
                  <?php if ($canDelete): ?>
                    <button type="button" 
                            class="action-btn btn-delete"
                            onclick="openDeleteUserModal(<?php echo $u['id']; ?>, <?php echo htmlspecialchars(json_encode($u['username']), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($u['email']), ENT_QUOTES); ?>)">
                      Delete
                    </button>
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
                  <?php if (!$canBan && !$canUnban && !$canPromote && !$canDemote && !$canDelete): ?>
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
      
      <div class="admin-filters">
        <input type="text" id="filterFeedText" placeholder="Search by username or caption..." oninput="filterFeed()" style="flex: 1; min-width: 200px;">
        <label><input type="checkbox" id="filterFeedHasCaption" onchange="filterFeed()"> Must have caption</label>
        <input type="date" id="filterFeedStart" onchange="filterFeed()" title="Start Date">
        <input type="date" id="filterFeedEnd" onchange="filterFeed()" title="End Date">
      </div>

      <div class="table-wrap">
        <table class="admin-table" id="feedTable">
          <thead>
            <tr>
              <th>Post ID</th><th>Author</th><th>Caption</th>
              <th>Likes</th><th>Comments</th><th>Posted</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($allPosts)): ?>
              <tr class="empty-row"><td colspan="7" style="text-align:center;padding:28px;color:rgba(255,255,255,0.4);">No posts yet.</td></tr>
            <?php else: ?>
              <?php foreach ($allPosts as $p): ?>
                <?php 
                  $caption = $p['caption'] ?? '';
                  $searchString = htmlspecialchars(strtolower($p['username'] . ' ' . $caption));
                  $dateString = date('Y-m-d', strtotime($p['created_at']));
                  $hasCaption = trim($caption) !== '' ? '1' : '0';
                ?>
                <tr data-search="<?php echo $searchString; ?>" data-date="<?php echo $dateString; ?>" data-has-caption="<?php echo $hasCaption; ?>">
                  <td>#<?php echo $p['postID']; ?></td>
                  <td><?php echo htmlspecialchars($p['username']); ?></td>
                  <td class="caption-cell caption-cell-expanded">
                    <?php
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
      
      <div class="admin-filters">
        <input type="text" id="filterCommText" placeholder="Search requester, title, description..." oninput="filterCommissions()" style="flex: 1; min-width: 200px;">
        <input type="date" id="filterCommStart" onchange="filterCommissions()" title="Start Date">
        <input type="date" id="filterCommEnd" onchange="filterCommissions()" title="End Date">
      </div>

      <div class="table-wrap">
        <table class="admin-table commissions-table" id="commTable">
          <thead>
            <tr>
              <th>ID</th><th>Requester</th><th>Email</th><th>Title</th><th>Description</th>
              <th>Amount</th><th>Status</th><th>Submitted</th><th>Admin Note</th><th>Update</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($commissions)): ?>
              <tr class="empty-row"><td colspan="10" style="text-align:center;padding:28px;color:rgba(255,255,255,0.4);">No commissions yet.</td></tr>
            <?php else: ?>
              <?php foreach ($commissions as $c): ?>
                <?php 
                  $searchString = htmlspecialchars(strtolower(($c['requester'] ?? '') . ' ' . ($c['title'] ?? '') . ' ' . ($c['description'] ?? '')));
                  $dateString = date('Y-m-d', strtotime($c['created_at']));
                ?>
                <tr data-search="<?php echo $searchString; ?>" data-date="<?php echo $dateString; ?>">
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

// Filter Logic
function applyFilters(tableId, textId, startId, endId, extraLogic = null) {
  const table = document.getElementById(tableId);
  if (!table) return;
  const rows = table.querySelectorAll('tbody tr:not(.empty-row)');
  const text = document.getElementById(textId).value.toLowerCase().trim();
  const start = document.getElementById(startId).value;
  const end = document.getElementById(endId).value;

  rows.forEach(row => {
    const searchData = row.getAttribute('data-search') || '';
    const dateData = row.getAttribute('data-date') || '';

    const matchText = text === '' || searchData.includes(text);
    const matchStart = start === '' || dateData >= start;
    const matchEnd = end === '' || dateData <= end;
    const matchExtra = extraLogic ? extraLogic(row) : true;

    row.style.display = (matchText && matchStart && matchEnd && matchExtra) ? '' : 'none';
  });
}

function filterUsers() {
  applyFilters('usersTable', 'filterUsersText', 'filterUsersStart', 'filterUsersEnd');
}

function filterFeed() {
  const hasCaptionObj = document.getElementById('filterFeedHasCaption');
  applyFilters('feedTable', 'filterFeedText', 'filterFeedStart', 'filterFeedEnd', (row) => {
    if (hasCaptionObj.checked) return row.getAttribute('data-has-caption') === '1';
    return true;
  });
}

function filterCommissions() {
  applyFilters('commTable', 'filterCommText', 'filterCommStart', 'filterCommEnd');
}

// ── Audit Log Filter ─────────────────────────────────────────────
function filterAuditLog() {
  const input   = document.getElementById('auditSearchInput');
  const text    = input ? input.value.toLowerCase().trim() : '';
  const entries = document.querySelectorAll('#auditList .audit-entry');
  const empty   = document.getElementById('auditEmpty');
  const counter = document.getElementById('auditResultCount');
  const clearBtn = document.getElementById('auditClearBtn');

  if (clearBtn) clearBtn.style.display = text ? 'flex' : 'none';

  let visible = 0;
  entries.forEach(entry => {
    const data = (entry.getAttribute('data-search') || '').toLowerCase();
    const show = text === '' || data.includes(text);
    entry.style.display = show ? '' : 'none';
    if (show) visible++;
  });

  if (empty) empty.style.display = visible === 0 ? '' : 'none';
  if (counter) {
    const total = entries.length;
    if (text === '') {
      counter.textContent = total === 0
        ? 'No entries found.'
        : `${total} ${total === 1 ? 'entry' : 'entries'} · shown`;
    } else {
      counter.textContent = visible === 0
        ? 'No entries match your search.'
        : `${visible} of ${total} ${total === 1 ? 'entry' : 'entries'} match`;
    }
  }
}

function clearAuditSearch() {
  const input = document.getElementById('auditSearchInput');
  if (input) { input.value = ''; input.focus(); }
  filterAuditLog();
}

function setAuditWindow(hours, btn) {
  // Update pill active state immediately for snappy feel
  document.querySelectorAll('.audit-pill').forEach(p => p.classList.remove('active'));
  if (btn) btn.classList.add('active');

  // Re-fetch by navigating; preserve any active search text
  const search = (document.getElementById('auditSearchInput')?.value ?? '').trim();
  const url = new URL(window.location.href);
  url.searchParams.set('audit_hours', hours);
  if (search) url.searchParams.set('audit_search', search);
  else url.searchParams.delete('audit_search');
  // Keep the page at the dashboard tab
  window.location.href = url.toString();
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

<!-- ── Ban User Modal ── -->
<div id="banUserModal" class="modal fade" tabindex="-1" aria-labelledby="banUserModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content delete-modal-content">
      <div class="modal-header ban-modal-header">
        <h5 class="modal-title ban-modal-title" id="banUserModalLabel">Ban User Account</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body delete-modal-body">
        <div class="ban-warning">
          <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line>
          </svg>
          <h6>Ban Account</h6>
          <p id="banUserInfo" class="delete-user-info"></p>
        </div>
        <form id="banUserForm" method="POST" onsubmit="submitBanUserForm(event)">
          <input type="hidden" name="action" value="ban_user"/>
          <input type="hidden" name="target_id" id="banUserId" value=""/>

          <div class="form-group">
            <label for="banReasonTextarea" class="form-label">Reason for Ban</label>
            <p class="form-text">Provide a reason for banning this account. This will be recorded in the audit log.</p>
            <textarea
              id="banReasonTextarea"
              name="ban_reason"
              class="form-control deletion-reason-textarea"
              placeholder="e.g., Repeated violations of community guidelines, Harassment, Spam activity..."
              rows="5"
              maxlength="1000"></textarea>
            <div class="reason-char-count">
              <span id="banCharCount">0</span>/1000 characters
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer delete-modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn ban-modal-confirm-btn" onclick="confirmBanUser()">Ban Account</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Unban User Modal ── -->
<div id="unbanUserModal" class="modal fade" tabindex="-1" aria-labelledby="unbanUserModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content delete-modal-content">
      <div class="modal-header unban-modal-header">
        <h5 class="modal-title unban-modal-title" id="unbanUserModalLabel">Unban User Account</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body delete-modal-body">
        <div class="unban-warning">
          <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"></circle>
            <polyline points="9 12 11 14 15 10"></polyline>
          </svg>
          <h6>Restore Account Access</h6>
          <p id="unbanUserInfo" class="delete-user-info"></p>
        </div>
        <p class="unban-description">
          Unbanning this account will restore the user's ability to log in and use the platform.
          This action will be recorded in the audit log.
        </p>
        <form id="unbanUserForm" method="POST">
          <input type="hidden" name="action" value="unban_user"/>
          <input type="hidden" name="target_id" id="unbanUserId" value=""/>
        </form>
      </div>
      <div class="modal-footer delete-modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn unban-modal-confirm-btn" onclick="confirmUnbanUser()">Restore Access</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Delete User Modal ── -->
<div id="deleteUserModal" class="modal fade" tabindex="-1" aria-labelledby="deleteUserModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content delete-modal-content">
      <div class="modal-header delete-modal-header">
        <h5 class="modal-title" id="deleteUserModalLabel">Delete User Account</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body delete-modal-body">
        <div class="delete-warning">
          <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="8" x2="12" y2="12"></line>
            <line x1="12" y1="16" x2="12.01" y2="16"></line>
          </svg>
          <h6>Permanently Delete Account</h6>
          <p id="deleteUserInfo" class="delete-user-info"></p>
        </div>
        <form id="deleteUserForm" method="POST" onsubmit="submitDeleteUserForm(event)">
          <input type="hidden" name="action" value="delete_user"/>
          <input type="hidden" name="target_id" id="deleteUserId" value=""/>
          
          <div class="form-group">
            <label for="deletionReasonTextarea" class="form-label">Reason for Deletion</label>
            <p class="form-text">Inform the user why their account is being deleted. This message will be sent to their email.</p>
            <textarea 
              id="deletionReasonTextarea"
              name="deletion_reason"
              class="form-control deletion-reason-textarea"
              placeholder="e.g., Violation of community guidelines, Spam activity, User request..."
              rows="5"
              maxlength="1000"
              required></textarea>
            <div class="reason-char-count">
              <span id="charCount">0</span>/1000 characters
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer delete-modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" onclick="confirmDeleteUser()">Delete Account Permanently</button>
      </div>
    </div>
  </div>
</div>

<script>
let deleteUserModal;

function openDeleteUserModal(userId, username, email) {
  const userInfo = document.getElementById('deleteUserInfo');
  userInfo.innerHTML = `<strong>${username}</strong> (${email})`;
  
  document.getElementById('deleteUserId').value = userId;
  document.getElementById('deletionReasonTextarea').value = '';
  document.getElementById('charCount').textContent = '0';
  
  deleteUserModal = new bootstrap.Modal(document.getElementById('deleteUserModal'));
  deleteUserModal.show();
}

function confirmDeleteUser() {
  const reason = document.getElementById('deletionReasonTextarea').value.trim();
  
  if (!reason) {
    alert('Please provide a reason for the account deletion.');
    return;
  }
  
  if (!confirm('Are you sure? This action cannot be undone. The user account and all associated data will be permanently deleted.')) {
    return;
  }
  
  document.getElementById('deleteUserForm').submit();
}

// Character counter for deletion reason
document.getElementById('deletionReasonTextarea').addEventListener('input', function() {
  document.getElementById('charCount').textContent = this.value.length;
});
</script>

<script>
let banUserModal;

function openBanUserModal(userId, username, email) {
  const userInfo = document.getElementById('banUserInfo');
  userInfo.innerHTML = `<strong>${username}</strong> (${email})`;

  document.getElementById('banUserId').value = userId;
  document.getElementById('banReasonTextarea').value = '';
  document.getElementById('banCharCount').textContent = '0';

  banUserModal = new bootstrap.Modal(document.getElementById('banUserModal'));
  banUserModal.show();
}

function confirmBanUser() {
  const reason = document.getElementById('banReasonTextarea').value.trim();

  if (!reason) {
    alert('Please provide a reason for banning this account.');
    return;
  }

  if (!confirm('Are you sure you want to ban this account? The user will lose access to the platform.')) {
    return;
  }

  document.getElementById('banUserForm').submit();
}

// Character counter for ban reason
document.getElementById('banReasonTextarea').addEventListener('input', function() {
  document.getElementById('banCharCount').textContent = this.value.length;
});
</script>

<script>
let unbanUserModal;

function openUnbanUserModal(userId, username, email) {
  const userInfo = document.getElementById('unbanUserInfo');
  userInfo.innerHTML = `<strong>${username}</strong> (${email})`;

  document.getElementById('unbanUserId').value = userId;

  unbanUserModal = new bootstrap.Modal(document.getElementById('unbanUserModal'));
  unbanUserModal.show();
}

function confirmUnbanUser() {
  if (!confirm('Restore access for this account? The user will be able to log in again.')) {
    return;
  }
  document.getElementById('unbanUserForm').submit();
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>