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

      <!-- ── Overview Card ── -->
      <section class="commission-hero side-card">
        <div class="commission-hero-copy">
          <p class="side-card-kicker">Orders</p>
          <?php if ($isAdmin): ?>
            <h1>Commission Master List</h1>
            <p>Full platform overview of all commission requests.</p>
          <?php else: ?>
            <h1>Commission Overview</h1>
            <p>Track the status of your FABulous service requests in one place.</p>
          <?php endif; ?>
        </div>
        <?php if (!$isAdmin): ?>
        <button class="comm-submit-toggle" id="submitToggle" onclick="toggleSubmitForm()">
          + Submit a Commission
        </button>
        <?php endif; ?>
      </section>

      <!-- ── Payment / submission messages ── -->
      <?php if ($pageMsg): ?>
        <div id="commissionPageMsg" class="commission-page-msg <?php echo $pageMsgIsError ? 'commission-msg-error' : 'commission-msg-ok'; ?>">
          <?php echo htmlspecialchars($pageMsg); ?>
        </div>
      <?php else: ?>
        <div id="commissionPageMsg" class="commission-page-msg" style="display:none;"></div>
      <?php endif; ?>

      <!-- ── Stats Row ── -->
      <section class="commission-stats">
        <article class="commission-stat">
          <span>Total Requests</span>
          <strong id="statTotal">—</strong>
        </article>
        <article class="commission-stat">
          <span>Pending</span>
          <strong id="statPending">—</strong>
        </article>
        <article class="commission-stat">
          <span>In Progress</span>
          <strong id="statActive">—</strong>
        </article>
        <article class="commission-stat">
          <span>Total Value</span>
          <strong id="statSpent">—</strong>
        </article>
      </section>

      <!-- ── Submit Form (users only, collapsible) ── -->
      <?php if (!$isAdmin): ?>
      <section class="commission-submit-card side-card" id="submitFormCard" style="display:none;">
        <div class="commission-table-head">
          <div>
            <p class="side-card-kicker">New Request</p>
            <h2>Submit a Commission</h2>
          </div>
          <button class="comm-close-btn" onclick="toggleSubmitForm()" title="Close form">&times;</button>
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

      <!-- ── Commission Table ── -->
      <section class="commission-table-card side-card">
        <div class="commission-table-head">
          <div class="comm-head-left">
            <p class="side-card-kicker">Updates</p>
            <div class="comm-head-title-row">
              <h2>Recent Requests</h2>
              <span class="thread-badge" id="completedBadge">0 Completed</span>
            </div>
          </div>
          <div class="comm-search-wrap">
            <svg class="comm-search-icon" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input type="text" id="commSearch" class="comm-search-input" placeholder="Search by field…" oninput="filterTable(this.value)"/>
          </div>
        </div>

        <div class="messages-empty commission-empty" id="emptyState" style="display:none;">
          <strong>No commission requests yet</strong>
          <span id="emptyStateMsg"></span>
        </div>

        <div class="commission-table-wrap" id="tableWrap" style="display:none;">
          <table class="commission-table" id="commTable">
            <thead>
              <tr>
                <th>S/N</th>
                <?php if ($isAdmin): ?>
                  <th>Requester</th><th>Email</th><th>Title</th><th>Description</th>
                  <th>Status</th><th>Update</th><th>Amount</th><th>Payment</th><th>File</th><th>Submitted</th>
                <?php else: ?>
                  <th>Title</th><th>Description</th><th>Status</th>
                  <th>Amount</th><th>Payment</th><th>Submitted</th><th>Admin Note</th><th>File</th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody id="commissionsTableBody">
            </tbody>
          </table>
        </div>

        <div class="comm-count-row" id="commCountRow" style="display:none;">
          Showing <strong id="commCountShown">0</strong> of <strong id="commCountTotal">0</strong> commissions
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

    let allCommissions = [];

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
      return '₱' + new Intl.NumberFormat('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}).format(Number(amount));
    }

    function toggleSubmitForm() {
      const card = document.getElementById('submitFormCard');
      const btn  = document.getElementById('submitToggle');
      if (!card) return;
      const isOpen = card.style.display !== 'none';
      card.style.display = isOpen ? 'none' : 'block';
      if (btn) btn.classList.toggle('active', !isOpen);
    }

    async function loadCommissions() {
      try {
        const response = await fetch('commissions.php?action=list');
        const data = await response.json();
        if (data.success) {
          allCommissions = data.commissions || [];
          updateStats(data.stats);
          renderCommissions(allCommissions);
        }
      } catch (error) {
        console.error('Error loading commissions:', error);
      }
    }

    function updateStats(stats) {
      document.getElementById('statTotal').textContent   = new Intl.NumberFormat().format(stats.total);
      document.getElementById('statPending').textContent = new Intl.NumberFormat().format(stats.pending);
      document.getElementById('statActive').textContent  = new Intl.NumberFormat().format(stats.active);
      document.getElementById('statSpent').innerHTML     = '&#8369;' + new Intl.NumberFormat('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}).format(stats.spent);
      const badge = document.getElementById('completedBadge');
      if (badge) badge.textContent = new Intl.NumberFormat().format(stats.completed) + ' Completed';
    }

    function filterTable(query) {
      if (!query) {
        renderCommissions(allCommissions);
        return;
      }
      const q = query.toLowerCase();
      const filtered = allCommissions.filter(c => {
        return (c.title || '').toLowerCase().includes(q)
          || (c.description || '').toLowerCase().includes(q)
          || (c.status || '').toLowerCase().includes(q)
          || (c.requester_username || '').toLowerCase().includes(q)
          || (c.requester_email || '').toLowerCase().includes(q)
          || String(c.commissionID).includes(q);
      });
      renderCommissions(filtered);
    }

    function renderCommissions(commissions) {
      const tbody      = document.getElementById('commissionsTableBody');
      const emptyState = document.getElementById('emptyState');
      const tableWrap  = document.getElementById('tableWrap');
      const countRow   = document.getElementById('commCountRow');

      if (!commissions || commissions.length === 0) {
        emptyState.style.display = 'flex';
        document.getElementById('emptyStateMsg').textContent = isAdmin
          ? 'No commissions have been submitted yet.'
          : 'Use the button above to submit your first commission request.';
        tableWrap.style.display = 'none';
        if (countRow) countRow.style.display = 'none';
        return;
      }

      emptyState.style.display = 'none';
      tableWrap.style.display  = 'block';

      if (countRow) {
        countRow.style.display = 'block';
        document.getElementById('commCountShown').textContent = commissions.length;
        document.getElementById('commCountTotal').textContent = allCommissions.length;
      }

      tbody.innerHTML = commissions.map((c, idx) => {
        const sn    = idx + 1;
        const title = esc(c.title || 'Untitled');
        const desc  = esc(c.description || '').substring(0, 96);
        const statusClass = 'status-' + (c.status || '').toLowerCase().replace(/ /g, '-');
        const amountNum   = Number(c.amount || 0);

        let paymentHtml = '';
        if (c.payment_status === 'paid') {
          paymentHtml = '<span class="payment-badge paid">Paid</span>';
        } else if (c.payment_status) {
          paymentHtml = `<span class="payment-badge pending">${esc(c.payment_status.charAt(0).toUpperCase() + c.payment_status.slice(1))}</span>`;
        }

        let fileHtml = '<span class="comm-empty-cell">—</span>';
        if (c.attachment_url) {
          fileHtml = `<a href="../${esc(c.attachment_url)}" target="_blank" class="commission-file-link">&#128196; View</a>`;
        }

        if (isAdmin) {
          const picUrl  = c.requester_pic ? '../uploads/profile_pics/' + encodeURIComponent(c.requester_pic) : null;
          const picHtml = picUrl ? `<img src="${esc(picUrl)}" class="comm-avatar" alt=""/>` : '<span class="comm-avatar-placeholder"></span>';
          const statusOptions = allowedStatuses.map(s => `<option value="${s}" ${c.status === s ? 'selected' : ''}>${s}</option>`).join('');
          if (!paymentHtml) paymentHtml = '<span class="comm-empty-cell">—</span>';

          return `
            <tr>
              <td class="comm-sn">${sn}</td>
              <td>
                <div class="comm-requester">
                  ${picHtml}
                  <div>
                    <span class="comm-requester-name">${esc(c.requester_username)}</span>
                    <span class="comm-requester-sub">${esc(c.requester_name)}</span>
                  </div>
                </div>
              </td>
              <td><a class="commission-file-link" href="mailto:${esc(c.requester_email)}">${esc(c.requester_email)}</a></td>
              <td class="comm-title-cell">${title}</td>
              <td class="commission-description">${desc}</td>
              <td><span class="status-badge ${statusClass}">${esc(c.status)}</span></td>
              <td>
                <form class="commission-form" onsubmit="saveCommission(event, ${c.commissionID})">
                  <select name="commission_status" class="commission-select">${statusOptions}</select>
                  <textarea name="admin_note" class="commission-note" placeholder="Progress update / note">${esc(c.admin_note)}</textarea>
                  <label class="commission-amount-label">
                    Amount (₱)
                    <input type="number" name="amount" class="commission-amount-input" value="${amountNum.toFixed(2)}" min="0" step="0.01"/>
                  </label>
                  <button type="submit" class="action-btn btn-save">Save</button>
                </form>
              </td>
              <td class="commission-amount-cell">${formatMoney(amountNum)}</td>
              <td>${paymentHtml}</td>
              <td>${fileHtml}</td>
              <td class="comm-date-cell">${formatDate(c.created_at)}</td>
            </tr>
          `;
        } else {
          let payCellHtml = `<span class="comm-amount-text">${formatMoney(amountNum)}</span>`;
          if (amountNum > 0 && c.payment_status !== 'paid') {
            payCellHtml += `
              <form method="POST" action="paymongo_checkout.php">
                <input type="hidden" name="commission_id" value="${c.commissionID}"/>
                <button type="submit" class="commission-pay-btn">Pay</button>
              </form>
            `;
          }
          if (!paymentHtml) {
            paymentHtml = amountNum > 0
              ? '<span class="payment-badge pending">Unpaid</span>'
              : '<span class="comm-empty-cell">Awaiting amount</span>';
          }

          return `
            <tr>
              <td class="comm-sn">${sn}</td>
              <td class="comm-title-cell">${title}</td>
              <td class="commission-description">${desc}</td>
              <td><span class="status-badge ${statusClass}">${esc(c.status)}</span></td>
              <td><div class="commission-pay-cell">${payCellHtml}</div></td>
              <td>${paymentHtml}</td>
              <td class="comm-date-cell">${formatDate(c.created_at)}</td>
              <td class="comm-note-cell">${esc(c.admin_note || 'No update yet.')}</td>
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
          toggleSubmitForm();
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
        const r    = await fetch('commissions.php', { method: 'POST', body: new URLSearchParams(data) });
        const json = await r.json();
        if (json.success) { loadCommissions(); }
      } catch (e) { console.error(e); }
    }

    loadCommissions();
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
