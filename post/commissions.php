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

$conn    = db_connect();
$userId  = (int) $_SESSION['user']['id'];
$username = $_SESSION['user']['username'];
$name    = $_SESSION['user']['name'];
$role    = $_SESSION['user']['role'] ?? 'user';
$isAdmin = in_array($role, ['admin', 'super_admin'], true);
$myProfilePic = $_SESSION['user']['profile_pic'] ?? null;
$myAvatarUrl  = $myProfilePic ? '../uploads/profile_pics/' . rawurlencode($myProfilePic) : null;

$allowedStatuses = ['Pending', 'Accepted', 'Ongoing', 'Delayed', 'Completed', 'Cancelled'];

$pageMsg = '';
$pageMsgIsError = false;

// ── Admin POST: update commission status / note ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin && ($_POST['action'] ?? '') === 'update_commission') {
    header('Content-Type: application/json');
    $commissionId = (int) ($_POST['target_id'] ?? 0);
    $status       = trim($_POST['commission_status'] ?? '');
    $adminNote    = mb_substr(trim($_POST['admin_note'] ?? ''), 0, 500);

    if (!$commissionId || !in_array($status, $allowedStatuses, true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid data.']);
        $conn->close();
        exit;
    }

    $upd = $conn->prepare('UPDATE commissions SET status = ?, admin_note = ? WHERE commissionID = ?');
    $upd->bind_param('ssi', $status, $adminNote, $commissionId);
    $ok = $upd->execute();
    $upd->close();

    if ($ok) {
        $logAction = "Updated commission #{$commissionId} to {$status}";
        $log = $conn->prepare(
            "INSERT INTO audit_log (admin_id, admin_username, action, target_type, target_id, visibility_role)
             VALUES (?, ?, ?, 'commission', ?, 'admin')"
        );
        if ($log) {
            $log->bind_param('issi', $userId, $username, $logAction, $commissionId);
            $log->execute();
            $log->close();
        }
    }

    $conn->close();
    echo json_encode(['success' => $ok, 'status' => $status]);
    exit;
}

// ── User POST: submit new commission ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isAdmin && ($_POST['action'] ?? '') === 'submit_commission') {
    $title       = mb_substr(trim($_POST['title'] ?? ''), 0, 255);
    $description = mb_substr(trim($_POST['description'] ?? ''), 0, 2000);

    if ($description === '') {
        $pageMsg = 'Description is required.';
        $pageMsgIsError = true;
    } else {
        $attachUrl = null;
        if (!empty($_FILES['attachment']['name'])) {
            $file      = $_FILES['attachment'];
            $uploadErr = (int) ($file['error'] ?? UPLOAD_ERR_OK);

            if ($uploadErr !== UPLOAD_ERR_OK) {
                $pageMsg = 'File upload failed. Please try again.';
                $pageMsgIsError = true;
            } elseif ($file['size'] > 10 * 1024 * 1024) {
                $pageMsg = 'Attachment must be smaller than 10 MB.';
                $pageMsgIsError = true;
            } else {
                $finfo     = new finfo(FILEINFO_MIME_TYPE);
                $mime      = $finfo->file($file['tmp_name']);
                $allowedMimes = [
                    'application/pdf'       => 'pdf',
                    'model/stl'             => 'stl',
                    'application/octet-stream' => null,
                ];

                $origName  = strtolower($file['name']);
                $ext       = pathinfo($origName, PATHINFO_EXTENSION);
                $allowedExts = ['pdf', 'stl'];

                if (!in_array($ext, $allowedExts, true)) {
                    $pageMsg = 'Only PDF and STL files are allowed.';
                    $pageMsgIsError = true;
                } elseif ($file['size'] === 0) {
                    $pageMsg = 'The uploaded file is empty.';
                    $pageMsgIsError = true;
                } else {
                    $uploadDir = __DIR__ . '/../uploads/commissions/';
                    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                        $pageMsg = 'Could not prepare upload folder.';
                        $pageMsgIsError = true;
                    } else {
                        $safeFilename = $userId . '_' . time() . '.' . $ext;
                        $destPath     = $uploadDir . $safeFilename;
                        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                            $pageMsg = 'Failed to save attachment.';
                            $pageMsgIsError = true;
                        } else {
                            $attachUrl = 'uploads/commissions/' . $safeFilename;
                        }
                    }
                }
            }
        }

        if (!$pageMsgIsError) {
            $ins = $conn->prepare(
                "INSERT INTO commissions (userID, commission_name, description, stl_file_url, status)
                 VALUES (?, ?, ?, ?, 'Pending')"
            );
            $ins->bind_param('isss', $userId, $title, $description, $attachUrl);
            $ok = $ins->execute();
            $ins->close();

            if ($ok) {
                // Notify admins about new commission
                $admins = $conn->query("SELECT id FROM accounts WHERE role IN ('admin','super_admin') AND banned = 0");
                if ($admins) {
                    $newId = (int) $conn->insert_id;
                    $actorId = $userId;
                    while ($admin = $admins->fetch_assoc()) {
                        $adminId = (int) $admin['id'];
                        if ($adminId !== $userId) {
                            $notif = $conn->prepare(
                                "INSERT INTO notifications (userID, actor_id, type, ref_id, is_read)
                                 VALUES (?, ?, 'commission_submitted', ?, 0)"
                            );
                            if ($notif) {
                                $notif->bind_param('iii', $adminId, $actorId, $newId);
                                $notif->execute();
                                $notif->close();
                            }
                        }
                    }
                }

                $pageMsg = 'Commission request submitted successfully!';
            } else {
                $pageMsg = 'Could not submit request. Please try again.';
                $pageMsgIsError = true;
            }
        }
    }
}

// ── Detect available columns ─────────────────────────────────────
$commissionColumns = [];
$columnsResult = $conn->query('SHOW COLUMNS FROM commissions');
while ($columnsResult && $column = $columnsResult->fetch_assoc()) {
    $commissionColumns[$column['Field']] = true;
}

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

$noteColAdmin = isset($commissionColumns['admin_note']) ? 'c.admin_note' : "'' AS admin_note";
$noteColUser  = isset($commissionColumns['admin_note']) ? 'admin_note'   : "'' AS admin_note";
$attachColAdmin = isset($commissionColumns['stl_file_url']) ? 'c.stl_file_url AS attachment_url' : "'' AS attachment_url";
$attachColUser  = isset($commissionColumns['stl_file_url']) ? 'stl_file_url AS attachment_url' : "'' AS attachment_url";

if ($isAdmin) {
    $stmt = $conn->prepare(
        "SELECT c.commissionID, {$titleExprAdmin} AS title, c.description,
                c.amount, c.status, c.created_at, {$noteColAdmin}, {$attachColAdmin},
                a.username AS requester_username,
                CONCAT(a.first_name, ' ', a.last_name) AS requester_name,
                a.profile_pic AS requester_pic
         FROM commissions c
         JOIN accounts a ON c.userID = a.id
         ORDER BY c.created_at DESC"
    );
    $stmt->execute();
} else {
    $stmt = $conn->prepare(
        "SELECT commissionID, {$titleExprUser} AS title, description,
                amount, status, created_at, {$noteColUser}, {$attachColUser}
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

$stats = ['total' => count($commissions), 'pending' => 0, 'active' => 0, 'completed' => 0, 'spent' => 0.0];
foreach ($commissions as $c) {
    $stats['spent'] += (float) ($c['amount'] ?? 0);
    $s = $c['status'] ?? '';
    if ($s === 'Pending')   $stats['pending']++;
    elseif (in_array($s, ['Accepted','Ongoing','Delayed'], true)) $stats['active']++;
    elseif ($s === 'Completed') $stats['completed']++;
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
    <button type="button" class="hamburger-btn" id="burgerBtn"
            aria-label="Toggle menu" aria-controls="navDrawer" aria-expanded="false"
            onclick="toggleDrawer()">
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

      <?php if ($pageMsg): ?>
        <div class="commission-page-msg <?php echo $pageMsgIsError ? 'commission-msg-error' : 'commission-msg-ok'; ?>">
          <?php echo htmlspecialchars($pageMsg); ?>
        </div>
      <?php endif; ?>

      <section class="commission-stats">
        <article class="commission-stat side-card"><span>Total Requests</span><strong><?php echo number_format($stats['total']); ?></strong></article>
        <article class="commission-stat side-card"><span>Pending</span><strong><?php echo number_format($stats['pending']); ?></strong></article>
        <article class="commission-stat side-card"><span>In Progress</span><strong><?php echo number_format($stats['active']); ?></strong></article>
        <article class="commission-stat side-card"><span>Total Value</span><strong>&#8369;<?php echo number_format($stats['spent'], 2); ?></strong></article>
      </section>

      <?php if (!$isAdmin): ?>
      <!-- USER SUBMISSION FORM -->
      <section class="commission-submit-card side-card">
        <div class="commission-table-head">
          <div>
            <p class="side-card-kicker">New Request</p>
            <h2>Submit a Commission</h2>
          </div>
        </div>
        <form method="POST" action="" enctype="multipart/form-data" class="commission-submit-form">
          <input type="hidden" name="action" value="submit_commission"/>
          <div class="commission-field">
            <label class="commission-label">Title <span class="commission-optional">(optional)</span></label>
            <input type="text" name="title" class="commission-input" placeholder="Short title for your request" maxlength="255"/>
          </div>
          <div class="commission-field">
            <label class="commission-label">Description <span class="commission-required">*</span></label>
            <textarea name="description" class="commission-textarea" placeholder="Describe what you need — dimensions, material, quantity, deadline…" rows="5" maxlength="2000" required></textarea>
          </div>
          <div class="commission-field">
            <label class="commission-label">Attachment <span class="commission-optional">(PDF or STL, max 10 MB)</span></label>
            <input type="file" name="attachment" class="commission-file-input" accept=".pdf,.stl"/>
          </div>
          <button type="submit" class="thread-send" style="align-self:flex-start;">Submit Request</button>
        </form>
      </section>
      <?php endif; ?>

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
            <span><?php echo $isAdmin ? 'No commissions have been submitted yet.' : 'Use the form above to submit your first commission request.'; ?></span>
          </div>
        <?php else: ?>
          <div class="commission-table-wrap">
            <?php if ($isAdmin): ?>
              <table class="commission-table">
                <thead>
                  <tr>
                    <th>ID</th><th>Requester</th><th>Title</th><th>Description</th>
                    <th>Status / Update</th><th>Amount</th><th>File</th><th>Submitted</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($commissions as $c): ?>
                    <?php $picUrl = !empty($c['requester_pic']) ? '../uploads/profile_pics/' . rawurlencode($c['requester_pic']) : null; ?>
                    <tr>
                      <td>#<?php echo (int) $c['commissionID']; ?></td>
                      <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                          <?php if ($picUrl): ?>
                            <img src="<?php echo htmlspecialchars($picUrl); ?>" style="width:28px;height:28px;border-radius:50%;object-fit:cover;" alt=""/>
                          <?php endif; ?>
                          <div>
                            <strong><?php echo htmlspecialchars($c['requester_username'] ?? ''); ?></strong><br/>
                            <small><?php echo htmlspecialchars($c['requester_name'] ?? ''); ?></small>
                          </div>
                        </div>
                      </td>
                      <td><?php echo htmlspecialchars($c['title'] ?: 'Untitled'); ?></td>
                      <td class="commission-description"><?php echo htmlspecialchars(mb_substr($c['description'] ?? '', 0, 96)); ?></td>
                      <td>
                        <span class="status-badge status-<?php echo strtolower(str_replace([' '], ['-'], $c['status'])); ?>">
                          <?php echo htmlspecialchars($c['status']); ?>
                        </span>
                        <form class="commission-form" onsubmit="saveCommission(event, <?php echo (int) $c['commissionID']; ?>)">
                          <select name="commission_status" class="commission-select">
                            <?php foreach ($allowedStatuses as $s): ?>
                              <option value="<?php echo $s; ?>" <?php echo $c['status'] === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                            <?php endforeach; ?>
                          </select>
                          <textarea name="admin_note" class="commission-note" placeholder="Progress update / note"><?php echo htmlspecialchars($c['admin_note'] ?? ''); ?></textarea>
                          <button type="submit" class="action-btn btn-save">Save</button>
                        </form>
                      </td>
                      <td>&#8369;<?php echo number_format((float) ($c['amount'] ?? 0), 2); ?></td>
                      <td>
                        <?php if (!empty($c['attachment_url'])): ?>
                          <a href="../<?php echo htmlspecialchars($c['attachment_url']); ?>" target="_blank" class="commission-file-link">&#128196; View</a>
                        <?php else: ?>
                          <span style="color:rgba(255,255,255,0.3);">—</span>
                        <?php endif; ?>
                      </td>
                      <td><?php echo date('M d, Y', strtotime($c['created_at'])); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php else: ?>
              <table class="commission-table">
                <thead>
                  <tr>
                    <th>ID</th><th>Title</th><th>Description</th><th>Status</th>
                    <th>Amount</th><th>Submitted</th><th>Admin Note</th><th>File</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($commissions as $c): ?>
                    <tr>
                      <td>#<?php echo (int) $c['commissionID']; ?></td>
                      <td><?php echo htmlspecialchars($c['title'] ?: 'Untitled'); ?></td>
                      <td class="commission-description"><?php echo htmlspecialchars(mb_substr($c['description'] ?? '', 0, 96)); ?></td>
                      <td>
                        <span class="status-badge status-<?php echo strtolower(str_replace([' '], ['-'], $c['status'])); ?>">
                          <?php echo htmlspecialchars($c['status']); ?>
                        </span>
                      </td>
                      <td>&#8369;<?php echo number_format((float) ($c['amount'] ?? 0), 2); ?></td>
                      <td><?php echo date('M d, Y', strtotime($c['created_at'])); ?></td>
                      <td><?php echo htmlspecialchars($c['admin_note'] ?: 'No update yet.'); ?></td>
                      <td>
                        <?php if (!empty($c['attachment_url'])): ?>
                          <a href="../<?php echo htmlspecialchars($c['attachment_url']); ?>" target="_blank" class="commission-file-link">&#128196; View</a>
                        <?php else: ?>
                          <span style="color:rgba(255,255,255,0.3);">—</span>
                        <?php endif; ?>
                      </td>
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
        ? forceState : !navDrawer.classList.contains('open');
      navDrawer.classList.toggle('open', shouldOpen);
      drawerOverlay.classList.toggle('show', shouldOpen);
      document.body.classList.toggle('menu-open', shouldOpen);
      burgerBtn?.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
    }

    function closeDrawer() { toggleDrawer(false); }

    document.addEventListener('keydown', event => {
      if (event.key === 'Escape') closeDrawer();
    });

    <?php if ($isAdmin): ?>
    async function saveCommission(event, id) {
      event.preventDefault();
      const form = event.target;
      const data = new FormData(form);
      data.append('action', 'update_commission');
      data.append('target_id', id);
      try {
        const r = await fetch('commissions.php', { method: 'POST', body: new URLSearchParams(data) });
        const json = await r.json();
        if (json.success) {
          const badge = form.closest('tr').querySelector('.status-badge');
          if (badge) {
            badge.textContent = json.status;
            badge.className = 'status-badge status-' + json.status.toLowerCase().replaceAll(' ', '-');
          }
        }
      } catch (e) { console.error(e); }
    }
    <?php endif; ?>
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
