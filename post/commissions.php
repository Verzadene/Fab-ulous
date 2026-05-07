<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/CommissionRepository.php';

if (empty($_SESSION['user'])) {
    header('Location: ../login/login.php');
    exit;
}

if (empty($_SESSION['mfa_verified'])) {
    header('Location: ../login/verify_mfa.php');
    exit;
}

$commissionRepo = new CommissionRepository('db_connect');
$userId  = (int) $_SESSION['user']['id'];
$username = $_SESSION['user']['username'];
$name    = $_SESSION['user']['name'];
$role    = $_SESSION['user']['role'] ?? 'user';
$isAdmin = in_array($role, ['admin', 'super_admin'], true);

$myAvatarUrl = get_current_user_avatar();

$allowedStatuses = ['Pending', 'Accepted', 'Ongoing', 'Delayed', 'Completed', 'Cancelled'];

$pageMsg = '';
$pageMsgIsError = false;

if (isset($_GET['payment'])) {
    $paymentState = $_GET['payment'];
    if ($paymentState === 'success') {
        $pageMsg = 'Payment checkout completed. The status will update when PayMongo confirms the payment webhook.';
    } elseif ($paymentState === 'cancelled') {
        $pageMsg = 'Payment checkout was cancelled.';
        $pageMsgIsError = true;
    } elseif ($paymentState === 'error') {
        $pageMsg = $_GET['message'] ?? 'Payment could not be started.';
        $pageMsgIsError = true;
    }
}

// ── GET: list commissions (JSON API) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'list') {
    header('Content-Type: application/json');
    $data = $commissionRepo->getCommissionsWithStats($isAdmin, $userId);
    
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

// ── Admin POST: update commission status / note ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin && ($_POST['action'] ?? '') === 'update_commission') {
    header('Content-Type: application/json');
    $commissionId = (int) ($_POST['target_id'] ?? 0);
    $status       = $_POST['commission_status'] ?? '';
    $adminNote    = $_POST['admin_note'] ?? '';
    $amount       = (float) ($_POST['amount'] ?? 0);

    $result = $commissionRepo->processUpdateCommission($commissionId, $status, $adminNote, $amount, $userId, $username, $allowedStatuses);

    echo json_encode($result);
    exit;
}

// ── User POST: submit new commission ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isAdmin && ($_POST['action'] ?? '') === 'submit_commission') {
    header('Content-Type: application/json');
    $title       = $_POST['title'] ?? '';
    $description = $_POST['description'] ?? '';
    $file        = $_FILES['attachment'] ?? null;

    $result = $commissionRepo->processSubmitCommission($userId, $title, $description, $file);

    echo json_encode($result);
    exit;
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
  <?php
  $navActive = 'commissions';
  $navRoot = '../';
  require __DIR__ . '/../includes/app_nav.php';
  ?>

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
      </section>

      <?php if ($pageMsg): ?>
        <div id="commissionPageMsg" class="commission-page-msg <?php echo $pageMsgIsError ? 'commission-msg-error' : 'commission-msg-ok'; ?>">
          <?php echo htmlspecialchars($pageMsg); ?>
        </div>
      <?php else: ?>
        <div id="commissionPageMsg" class="commission-page-msg" style="display:none;"></div>
      <?php endif; ?>

      <section class="commission-stats">
        <article class="commission-stat side-card"><span>Total Requests</span><strong id="statTotal">0</strong></article>
        <article class="commission-stat side-card"><span>Pending</span><strong id="statPending">0</strong></article>
        <article class="commission-stat side-card"><span>In Progress</span><strong id="statActive">0</strong></article>
        <article class="commission-stat side-card"><span>Total Value</span><strong id="statSpent">&#8369;0.00</strong></article>
      </section>

      <?php if (!$isAdmin): ?>
      <section class="commission-submit-card side-card">
        <div class="commission-table-head">
          <div>
            <p class="side-card-kicker">New Request</p>
            <h2>Submit a Commission</h2>
          </div>
        </div>
        <form id="submitCommissionForm" enctype="multipart/form-data" class="commission-submit-form" onsubmit="submitCommission(event)">
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
          <span class="thread-badge" id="completedBadge">0 Completed</span>
        </div>

        <div class="messages-empty commission-empty" id="emptyState" style="display:none;">
          <strong>No commission requests yet</strong>
          <span id="emptyStateMsg"></span>
        </div>

        <div class="commission-table-wrap" id="tableWrap" style="display:none;">
          <table class="commission-table">
            <thead>
              <tr>
                <?php if ($isAdmin): ?>
                  <th>ID</th><th>Requester</th><th>Email</th><th>Title</th><th>Description</th>
                  <th>Status / Update</th><th>Amount</th><th>Payment</th><th>File</th><th>Submitted</th>
                <?php else: ?>
                  <th>ID</th><th>Title</th><th>Description</th><th>Status</th>
                  <th>Amount</th><th>Payment</th><th>Submitted</th><th>Admin Note</th><th>File</th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody id="commissionsTableBody">
            </tbody>
          </table>
        </div>
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

    const isAdmin = <?php echo $isAdmin ? 'true' : 'false'; ?>;
    const allowedStatuses = <?php echo json_encode($allowedStatuses); ?>;

    function esc(val) {
      const div = document.createElement('div');
      div.textContent = String(val ?? '');
      return div.innerHTML;
    }

    function formatDate(dateStr) {
      const d = new Date(dateStr.replace(/-/g, '/'));
      return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function formatMoney(amount) {
      return '₱' + new Intl.NumberFormat('en-PH', {minimumFractionDigits: 2, maximumFractionDigits:2}).format(Number(amount));
    }

    async function loadCommissions() {
      try {
        const response = await fetch('commissions.php?action=list');
        const data = await response.json();
        if (data.success) {
          updateStats(data.stats);
          renderCommissions(data.commissions);
        }
      } catch (error) {
        console.error('Error loading commissions:', error);
      }
    }

    function updateStats(stats) {
      document.getElementById('statTotal').textContent = new Intl.NumberFormat().format(stats.total);
      document.getElementById('statPending').textContent = new Intl.NumberFormat().format(stats.pending);
      document.getElementById('statActive').textContent = new Intl.NumberFormat().format(stats.active);
      document.getElementById('statSpent').innerHTML = '&#8369;' + new Intl.NumberFormat('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2}).format(stats.spent);
      const badge = document.getElementById('completedBadge');
      if (badge) badge.textContent = new Intl.NumberFormat().format(stats.completed) + ' Completed';
    }

    function renderCommissions(commissions) {
      const tbody = document.getElementById('commissionsTableBody');
      const emptyState = document.getElementById('emptyState');
      const tableWrap = document.getElementById('tableWrap');

      if (!commissions || commissions.length === 0) {
        emptyState.style.display = 'flex';
        document.getElementById('emptyStateMsg').textContent = isAdmin ? 'No commissions have been submitted yet.' : 'Use the form above to submit your first commission request.';
        tableWrap.style.display = 'none';
        return;
      }

      emptyState.style.display = 'none';
      tableWrap.style.display = 'block';

      tbody.innerHTML = commissions.map(c => {
        const title = esc(c.title || 'Untitled');
        const desc = esc(c.description || '').substring(0, 96);
        const statusClass = 'status-' + (c.status || '').toLowerCase().replace(/ /g, '-');
        const amountNum = Number(c.amount || 0);
        
        let paymentHtml = '';
        if (c.payment_status === 'paid') {
          paymentHtml = '<span class="payment-badge paid">Paid</span>';
        } else if (c.payment_status) {
          paymentHtml = `<span class="payment-badge pending">${esc(c.payment_status.charAt(0).toUpperCase() + c.payment_status.slice(1))}</span>`;
        }

        let fileHtml = '<span style="color:rgba(255,255,255,0.3);">—</span>';
        if (c.attachment_url) {
          fileHtml = `<a href="../${esc(c.attachment_url)}" target="_blank" class="commission-file-link">&#128196; View</a>`;
        }

        if (isAdmin) {
          const picUrl = c.requester_pic ? '../uploads/profile_pics/' + encodeURIComponent(c.requester_pic) : null;
          const picHtml = picUrl ? `<img src="${esc(picUrl)}" style="width:28px;height:28px;border-radius:50%;object-fit:cover;" alt=""/>` : '';
          const statusOptions = allowedStatuses.map(s => `<option value="${s}" ${c.status === s ? 'selected' : ''}>${s}</option>`).join('');
          
          if (!paymentHtml) paymentHtml = '<span style="color:rgba(255,255,255,0.3);">—</span>';

          return `
            <tr>
              <td>#${c.commissionID}</td>
              <td>
                <div style="display:flex;align-items:center;gap:8px;">
                  ${picHtml}
                  <div>
                    <strong>${esc(c.requester_username)}</strong><br/>
                    <small>${esc(c.requester_name)}</small>
                  </div>
                </div>
              </td>
              <td><a class="commission-file-link" href="mailto:${esc(c.requester_email)}">${esc(c.requester_email)}</a></td>
              <td>${title}</td>
              <td class="commission-description">${desc}</td>
              <td>
                <span class="status-badge ${statusClass}">${esc(c.status)}</span>
                <form class="commission-form" onsubmit="saveCommission(event, ${c.commissionID})">
                  <select name="commission_status" class="commission-select">${statusOptions}</select>
                  <textarea name="admin_note" class="commission-note" placeholder="Progress update / note">${esc(c.admin_note)}</textarea>
                  <label class="commission-amount-label">
                    Amount
                    <input type="number" name="amount" class="commission-amount-input" value="${amountNum.toFixed(2)}" min="0" step="0.01"/>
                  </label>
                  <button type="submit" class="action-btn btn-save">Save</button>
                </form>
              </td>
              <td class="commission-amount-cell">${formatMoney(amountNum)}</td>
              <td>${paymentHtml}</td>
              <td>${fileHtml}</td>
              <td>${formatDate(c.created_at)}</td>
            </tr>
          `;
        } else {
          let payCellHtml = `<span>${formatMoney(amountNum)}</span>`;
          if (amountNum > 0 && c.payment_status !== 'paid') {
             payCellHtml += `
               <form method="POST" action="paymongo_checkout.php">
                 <input type="hidden" name="commission_id" value="${c.commissionID}"/>
                 <button type="submit" class="commission-pay-btn">Pay</button>
               </form>
             `;
          }
          if (!paymentHtml) {
             paymentHtml = amountNum > 0 ? '<span class="payment-badge pending">Unpaid</span>' : '<span style="color:rgba(255,255,255,0.3);">Awaiting amount</span>';
          }

          return `
            <tr>
              <td>#${c.commissionID}</td>
              <td>${title}</td>
              <td class="commission-description">${desc}</td>
              <td><span class="status-badge ${statusClass}">${esc(c.status)}</span></td>
              <td><div class="commission-pay-cell">${payCellHtml}</div></td>
              <td>${paymentHtml}</td>
              <td>${formatDate(c.created_at)}</td>
              <td>${esc(c.admin_note || 'No update yet.')}</td>
              <td>${fileHtml}</td>
            </tr>
          `;
        }
      }).join('');
    }

    async function submitCommission(event) {
      event.preventDefault();
      const form = event.target;
      const formData = new FormData(form);
      formData.append('action', 'submit_commission');

      try {
        const response = await fetch('commissions.php', { method: 'POST', body: formData });
        const data = await response.json();
        
        const msgBox = document.getElementById('commissionPageMsg');
        msgBox.style.display = 'block';
        
        if (data.success) {
          msgBox.className = 'commission-page-msg commission-msg-ok';
          msgBox.textContent = data.message;
          form.reset();
          loadCommissions();
        } else {
          msgBox.className = 'commission-page-msg commission-msg-error';
          msgBox.textContent = data.error || 'Submission failed.';
        }
      } catch (error) {
        console.error('Error submitting commission:', error);
      }
    }

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
          loadCommissions();
        }
      } catch (e) { console.error(e); }
    }
    
    // Auto-load on init
    loadCommissions();
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
