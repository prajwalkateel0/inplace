<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

require_once __DIR__ . '/../PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/../PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer-master/src/SMTP.php';

requireAuth('admin');

$pageTitle = 'Registration Approvals';
$pageSubtitle = 'Review and approve student registrations';
$activePage = 'approve_registrations';
$userId = authId();

$unreadCount = 0;
$pendingRequests = 0;

$actionMsg = '';
$actionType = '';

// ── Helper: send approval email ──────────────────────────────────────────────
function sendApprovalEmail($pdo, $targetUserId, $serverVars) {
    $studentStmt = $pdo->prepare("SELECT full_name, email FROM users WHERE id = ?");
    $studentStmt->execute([$targetUserId]);
    $student = $studentStmt->fetch();
    if (!$student) return;

    $mailCfg  = require __DIR__ . '/../config/email_config.php';
    $loginUrl = ((!empty($serverVars['HTTPS']) && $serverVars['HTTPS'] !== 'off') ? 'https' : 'http')
                . '://' . $serverVars['HTTP_HOST'] . '/inplace/login.php';

    $htmlBody = "
    <div style='font-family:\"DM Sans\",Arial,sans-serif;max-width:600px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;'>
      <div style='background:linear-gradient(135deg,#0c1b33 0%,#1a2d4d 100%);padding:2rem;text-align:center;'>
        <h1 style='color:#ffffff;font-size:1.5rem;margin:0;font-family:Georgia,serif;'>InPlace</h1>
        <p style='color:rgba(255,255,255,0.8);margin:0.5rem 0 0;font-size:0.9rem;'>Registration Approved</p>
      </div>
      <div style='padding:2rem;'>
        <h2 style='color:#0c1b33;font-family:Georgia,serif;margin-bottom:0.5rem;'>Welcome, " . htmlspecialchars($student['full_name']) . "!</h2>
        <p style='color:#374151;font-size:1rem;line-height:1.6;margin-bottom:1.5rem;'>
          Your InPlace account has been <strong style='color:#10b981;'>approved</strong> by the admin.
          You can now log in and start your industrial placement journey.
        </p>
        <div style='background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:1rem 1.25rem;margin-bottom:1.5rem;'>
          <p style='margin:0;color:#15803d;font-size:0.9rem;'>
            Your account is now active. Log in with your registered email address.
          </p>
        </div>
        <div style='text-align:center;margin:2rem 0;'>
          <a href='$loginUrl'
             style='display:inline-block;padding:0.875rem 2rem;background-color:#0c1b33;
                    color:#ffffff !important;text-decoration:none;border-radius:10px;
                    font-weight:700;font-size:1rem;border:2px solid #0c1b33;'>
            Log In to InPlace
          </a>
        </div>
        <p style='color:#6b7a8d;font-size:0.85rem;text-align:center;'>
          This is an automated notification from InPlace.
        </p>
      </div>
    </div>";

    $altBody = "Hi {$student['full_name']},\n\nYour InPlace account has been approved! You can now log in at: $loginUrl\n\nWelcome aboard.";

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $mailCfg['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $mailCfg['smtp_user'];
        $mail->Password   = $mailCfg['smtp_pass'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $mailCfg['smtp_port'];
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom($mailCfg['from_email'], $mailCfg['from_name']);
        $mail->addAddress($student['email'], $student['full_name']);
        $mail->isHTML(true);
        $mail->Subject = 'InPlace - Your Registration Has Been Approved';
        $mail->Body    = $htmlBody;
        $mail->AltBody = $altBody;
        $mail->send();
    } catch (MailException $e) {
        error_log('Approval email failed to ' . $student['email'] . ': ' . $mail->ErrorInfo);
    }
}

// ── Handle POST actions ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Single approve / reject
    $targetUserId = (int)($_POST['user_id'] ?? 0);

    if ($targetUserId > 0 && $action === 'approve') {
        $stmt = $pdo->prepare("UPDATE users SET approval_status='approved', approved_by=?, approved_at=NOW() WHERE id=?");
        $stmt->execute([$userId, $targetUserId]);
        sendApprovalEmail($pdo, $targetUserId, $_SERVER);
        $actionMsg  = "Student account approved successfully!";
        $actionType = 'success';

    } elseif ($targetUserId > 0 && $action === 'reject') {
        $pdo->prepare("DELETE FROM users WHERE id=? AND approval_status='pending'")->execute([$targetUserId]);
        $actionMsg  = "Registration rejected and user record deleted. They can register again next year.";
        $actionType = 'warning';

    // Bulk approve
    } elseif ($action === 'bulk_approve') {
        $rawIds = $_POST['user_ids'] ?? [];
        $ids    = array_values(array_filter(array_map('intval', $rawIds)));
        $count  = 0;
        foreach ($ids as $uid) {
            $stmt = $pdo->prepare("UPDATE users SET approval_status='approved', approved_by=?, approved_at=NOW() WHERE id=? AND approval_status='pending'");
            $stmt->execute([$userId, $uid]);
            if ($stmt->rowCount()) {
                sendApprovalEmail($pdo, $uid, $_SERVER);
                $count++;
            }
        }
        $actionMsg  = "$count student(s) approved successfully!";
        $actionType = 'success';

    // Bulk reject
    } elseif ($action === 'bulk_reject') {
        $rawIds = $_POST['user_ids'] ?? [];
        $ids    = array_values(array_filter(array_map('intval', $rawIds)));
        $count  = 0;
        foreach ($ids as $uid) {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id=? AND approval_status='pending'");
            $stmt->execute([$uid]);
            $count += $stmt->rowCount();
        }
        $actionMsg  = "$count registration(s) rejected and deleted. They can re-register next year.";
        $actionType = 'warning';
    }
}

// ── Filters (GET) ────────────────────────────────────────────────────────────
$filterYear      = trim($_GET['year']      ?? '');
$filterProgramme = trim($_GET['programme'] ?? '');
$filterStatus    = trim($_GET['status']    ?? '');

$where  = ["role = 'student'", "approval_status IN ('pending','approved')"];
$params = [];

if ($filterYear !== '') {
    $where[]  = "academic_year = ?";
    $params[] = $filterYear;
}
if ($filterProgramme !== '') {
    $where[]  = "programme_type = ?";
    $params[] = $filterProgramme;
}
if ($filterStatus !== '') {
    $where[]  = "approval_status = ?";
    $params[] = $filterStatus;
}

$sql  = "SELECT id, full_name, email, academic_year, programme_type, created_at, approval_status
         FROM users
         WHERE " . implode(' AND ', $where) . "
         ORDER BY FIELD(approval_status,'pending','approved'), created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$registrations = $stmt->fetchAll();

// Dropdown options
$allYears = $pdo->query(
    "SELECT DISTINCT academic_year FROM users WHERE role='student' AND approval_status IN ('pending','approved') ORDER BY academic_year"
)->fetchAll(PDO::FETCH_COLUMN);

$allProgrammes = $pdo->query(
    "SELECT DISTINCT programme_type FROM users WHERE role='student' AND approval_status IN ('pending','approved') ORDER BY programme_type"
)->fetchAll(PDO::FETCH_COLUMN);

// Counts
$pendingCount = 0;
foreach ($registrations as $reg) {
    if ($reg['approval_status'] === 'pending') $pendingCount++;
}

// Total (unfiltered)
$totalCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM users WHERE role='student' AND approval_status IN ('pending','approved')"
)->fetchColumn();
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>

    <div class="page-content">

        <?php if ($actionMsg): ?>
        <div style="background:var(--<?= $actionType ?>-bg);
                    border:1px solid <?= $actionType==='success'?'#6ee7b7':'#fcd34d' ?>;
                    border-radius:var(--radius);padding:1.25rem 2rem;margin-bottom:1.5rem;">
            <p style="color:var(--<?= $actionType ?>);font-weight:500;"><?= htmlspecialchars($actionMsg) ?></p>
        </div>
        <?php endif; ?>

        <!-- Stats -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));
                    gap:1.25rem;margin-bottom:2rem;">
            <div class="panel" style="margin-bottom:0;padding:1.25rem 1.75rem;">
                <p style="font-size:0.75rem;font-weight:600;text-transform:uppercase;
                          letter-spacing:0.05em;color:var(--muted);margin-bottom:0.5rem;">Pending Approval</p>
                <h3 style="font-family:'Playfair Display',serif;font-size:1.625rem;
                           color:<?= $pendingCount>0?'var(--warning)':'var(--navy)' ?>;">
                    <?= $pendingCount ?>
                </h3>
            </div>
            <div class="panel" style="margin-bottom:0;padding:1.25rem 1.75rem;">
                <p style="font-size:0.75rem;font-weight:600;text-transform:uppercase;
                          letter-spacing:0.05em;color:var(--muted);margin-bottom:0.5rem;">Total Registrations</p>
                <h3 style="font-family:'Playfair Display',serif;font-size:1.625rem;color:var(--navy);">
                    <?= $totalCount ?>
                </h3>
            </div>
        </div>

        <!-- Registrations Table -->
        <div class="panel">
            <div class="panel-header" style="flex-wrap:wrap;gap:1rem;">
                <h3>Student Registrations</h3>

                <!-- Filter bar -->
                <form method="GET" id="filterForm"
                      style="display:flex;gap:0.625rem;flex-wrap:wrap;align-items:center;margin-left:auto;">

                    <select name="year" onchange="this.form.submit()"
                            style="padding:0.45rem 0.75rem;border:1px solid var(--border);border-radius:var(--radius-sm);
                                   font-size:0.85rem;background:var(--white);color:var(--text);cursor:pointer;">
                        <option value="">All Years</option>
                        <?php foreach ($allYears as $y): ?>
                        <option value="<?= htmlspecialchars($y) ?>" <?= $filterYear===$y?'selected':'' ?>>
                            <?= htmlspecialchars($y) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="programme" onchange="this.form.submit()"
                            style="padding:0.45rem 0.75rem;border:1px solid var(--border);border-radius:var(--radius-sm);
                                   font-size:0.85rem;background:var(--white);color:var(--text);cursor:pointer;">
                        <option value="">All Programmes</option>
                        <?php foreach ($allProgrammes as $p): ?>
                        <option value="<?= htmlspecialchars($p) ?>" <?= $filterProgramme===$p?'selected':'' ?>>
                            <?= htmlspecialchars($p) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="status" onchange="this.form.submit()"
                            style="padding:0.45rem 0.75rem;border:1px solid var(--border);border-radius:var(--radius-sm);
                                   font-size:0.85rem;background:var(--white);color:var(--text);cursor:pointer;">
                        <option value="">All Status</option>
                        <option value="pending"  <?= $filterStatus==='pending' ?'selected':'' ?>>Pending</option>
                        <option value="approved" <?= $filterStatus==='approved'?'selected':'' ?>>Approved</option>
                    </select>

                    <?php if ($filterYear || $filterProgramme || $filterStatus): ?>
                    <a href="approve-registrations.php"
                       style="font-size:0.8rem;color:var(--muted);text-decoration:none;white-space:nowrap;">
                        Clear filters
                    </a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Bulk action toolbar (hidden until checkboxes selected) -->
            <div id="bulkToolbar" style="display:none;align-items:center;gap:1rem;
                 background:var(--navy);color:#fff;padding:0.875rem 1.25rem;
                 border-radius:var(--radius-sm);margin:0 0 1rem;">
                <span id="bulkCountLabel" style="font-size:0.9rem;font-weight:600;"></span>
                <div style="margin-left:auto;display:flex;gap:0.5rem;">
                    <button type="button" class="btn btn-success btn-sm"
                            onclick="openBulkApproveModal()">
                        ✓ Approve Selected
                    </button>
                    <button type="button" class="btn btn-danger btn-sm"
                            onclick="openBulkRejectModal()">
                        ✗ Reject Selected
                    </button>
                </div>
            </div>

            <?php if (empty($registrations)): ?>
            <div style="text-align:center;padding:4rem 2rem;">
                <div style="font-size:3rem;margin-bottom:1rem;">👥</div>
                <p style="color:var(--muted);font-size:1rem;">No registrations found.</p>
            </div>

            <?php else: ?>
            <div class="table-wrap">
                <table id="regTable">
                    <thead>
                        <tr>
                            <th style="width:2.5rem;">
                                <input type="checkbox" id="selectAll" title="Select all pending"
                                       style="cursor:pointer;width:1rem;height:1rem;">
                            </th>
                            <th>Student</th>
                            <th>Email</th>
                            <th>Academic Year</th>
                            <th>Programme</th>
                            <th>Registered</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($registrations as $reg): ?>
                        <tr data-status="<?= htmlspecialchars($reg['approval_status']) ?>">
                            <td>
                                <?php if ($reg['approval_status'] === 'pending'): ?>
                                <input type="checkbox" class="row-check" value="<?= (int)$reg['id'] ?>"
                                       style="cursor:pointer;width:1rem;height:1rem;">
                                <?php else: ?>
                                <span style="color:var(--muted);font-size:0.8rem;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-weight:500;"><?= htmlspecialchars($reg['full_name']) ?></div>
                            </td>
                            <td style="font-size:0.875rem;color:var(--muted);">
                                <?= htmlspecialchars($reg['email']) ?>
                            </td>
                            <td>
                                <span class="type-chip"><?= htmlspecialchars($reg['academic_year']) ?></span>
                            </td>
                            <td style="font-size:0.875rem;">
                                <?= htmlspecialchars($reg['programme_type']) ?>
                            </td>
                            <td style="font-family:'DM Mono',monospace;font-size:0.8125rem;">
                                <?= date('d M Y', strtotime($reg['created_at'])) ?>
                            </td>
                            <td>
                                <?php
                                $badgeClass = match($reg['approval_status']) {
                                    'approved' => 'approved',
                                    'rejected' => 'rejected',
                                    'pending'  => 'pending',
                                    default    => 'pending'
                                };
                                ?>
                                <span class="badge badge-<?= $badgeClass ?>">
                                    <?= ucfirst($reg['approval_status']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($reg['approval_status'] === 'pending'): ?>
                                <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
                                    <button type="button" class="btn btn-success btn-sm"
                                            onclick="openApproveModal(<?= (int)$reg['id'] ?>, '<?= htmlspecialchars($reg['full_name'], ENT_QUOTES) ?>')">
                                        ✓ Approve
                                    </button>
                                    <button type="button" class="btn btn-danger btn-sm"
                                            onclick="openRejectModal(<?= (int)$reg['id'] ?>, '<?= htmlspecialchars($reg['full_name'], ENT_QUOTES) ?>')">
                                        ✗ Reject
                                    </button>
                                </div>
                                <?php else: ?>
                                <span style="font-size:0.875rem;color:var(--muted);">Approved</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- ── Modal styles ─────────────────────────────────────────────────────────── -->
<style>
  @keyframes ipFadeIn { from{opacity:0} to{opacity:1} }
  @keyframes ipPopIn  { from{opacity:0;transform:translateY(10px) scale(.98)} to{opacity:1;transform:translateY(0) scale(1)} }

  .ip-modal-overlay{
    display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);
    z-index:1000;align-items:center;justify-content:center;
    animation:ipFadeIn .15s ease-out;
  }
  .ip-modal-overlay.active{ display:flex; }

  .ip-modal{
    background:var(--white);border-radius:var(--radius);padding:2.5rem;
    width:100%;max-width:500px;box-shadow:0 20px 60px rgba(0,0,0,0.2);
    animation:ipPopIn .18s ease-out;
  }
</style>

<!-- Single Approve Modal -->
<div id="approveModal" class="ip-modal-overlay" aria-hidden="true">
  <div class="ip-modal" role="dialog" aria-modal="true" aria-labelledby="approveTitle">
    <h3 id="approveTitle" style="font-family:'Playfair Display',serif;font-size:1.375rem;color:var(--navy);margin-bottom:0.5rem;">
      ✅ Approve Registration
    </h3>
    <p id="approveStudentName" style="color:var(--muted);font-size:0.9rem;margin-bottom:1.5rem;"></p>
    <div style="background:var(--success-bg);border:1px solid #6ee7b7;border-radius:var(--radius-sm);padding:0.875rem 1rem;margin-bottom:1.25rem;">
      <p style="margin:0;color:var(--success);font-size:0.9rem;">This will activate the student account immediately.</p>
    </div>
    <form method="POST" id="approveForm">
      <input type="hidden" name="user_id" id="approveUserId">
      <input type="hidden" name="action" value="approve">
      <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
        <button type="button" class="btn btn-ghost" onclick="closeApproveModal()">Cancel</button>
        <button type="submit" class="btn btn-success" id="approveConfirmBtn">Confirm Approval</button>
      </div>
    </form>
  </div>
</div>

<!-- Single Reject Modal -->
<div id="rejectModal" class="ip-modal-overlay" aria-hidden="true">
  <div class="ip-modal" role="dialog" aria-modal="true" aria-labelledby="rejectTitle">
    <h3 id="rejectTitle" style="font-family:'Playfair Display',serif;font-size:1.375rem;color:var(--danger);margin-bottom:0.5rem;">
      ⚠️ Reject &amp; Delete Registration
    </h3>
    <p id="rejectStudentName" style="color:var(--muted);font-size:0.9rem;margin-bottom:1.5rem;"></p>
    <div style="background:#fff5f5;border:1px solid #fca5a5;border-radius:var(--radius-sm);padding:0.875rem 1rem;margin-bottom:1.25rem;">
      <p style="margin:0;color:var(--danger);font-size:0.9rem;">
        This will <strong>permanently delete</strong> the user's record. They can re-register with the same email.
      </p>
    </div>
    <form method="POST" id="rejectForm">
      <input type="hidden" name="user_id" id="rejectUserId">
      <input type="hidden" name="action" value="reject">
      <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
        <button type="button" class="btn btn-ghost" onclick="closeRejectModal()">Cancel</button>
        <button type="submit" class="btn btn-danger" id="rejectConfirmBtn">Confirm Rejection</button>
      </div>
    </form>
  </div>
</div>

<!-- Bulk Approve Modal -->
<div id="bulkApproveModal" class="ip-modal-overlay" aria-hidden="true">
  <div class="ip-modal" role="dialog" aria-modal="true">
    <h3 style="font-family:'Playfair Display',serif;font-size:1.375rem;color:var(--navy);margin-bottom:0.5rem;">
      ✅ Bulk Approve
    </h3>
    <p id="bulkApproveDesc" style="color:var(--muted);font-size:0.9rem;margin-bottom:1.5rem;"></p>
    <div style="background:var(--success-bg);border:1px solid #6ee7b7;border-radius:var(--radius-sm);padding:0.875rem 1rem;margin-bottom:1.25rem;">
      <p style="margin:0;color:var(--success);font-size:0.9rem;">
        All selected pending students will be approved and notified by email.
      </p>
    </div>
    <form method="POST" id="bulkApproveForm">
      <input type="hidden" name="action" value="bulk_approve">
      <div id="bulkApproveIds"></div>
      <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
        <button type="button" class="btn btn-ghost" onclick="closeBulkApproveModal()">Cancel</button>
        <button type="submit" class="btn btn-success" id="bulkApproveConfirmBtn">Approve All Selected</button>
      </div>
    </form>
  </div>
</div>

<!-- Bulk Reject Modal -->
<div id="bulkRejectModal" class="ip-modal-overlay" aria-hidden="true">
  <div class="ip-modal" role="dialog" aria-modal="true">
    <h3 style="font-family:'Playfair Display',serif;font-size:1.375rem;color:var(--danger);margin-bottom:0.5rem;">
      ⚠️ Bulk Reject &amp; Delete
    </h3>
    <p id="bulkRejectDesc" style="color:var(--muted);font-size:0.9rem;margin-bottom:1.5rem;"></p>
    <div style="background:#fff5f5;border:1px solid #fca5a5;border-radius:var(--radius-sm);padding:0.875rem 1rem;margin-bottom:1.25rem;">
      <p style="margin:0;color:var(--danger);font-size:0.9rem;">
        This will <strong>permanently delete</strong> all selected pending registrations. They can re-register next year.
      </p>
    </div>
    <form method="POST" id="bulkRejectForm">
      <input type="hidden" name="action" value="bulk_reject">
      <div id="bulkRejectIds"></div>
      <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
        <button type="button" class="btn btn-ghost" onclick="closeBulkRejectModal()">Cancel</button>
        <button type="submit" class="btn btn-danger" id="bulkRejectConfirmBtn">Reject All Selected</button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Checkbox / bulk toolbar logic ────────────────────────────────────────────
const selectAll  = document.getElementById('selectAll');
const toolbar    = document.getElementById('bulkToolbar');
const countLabel = document.getElementById('bulkCountLabel');

function getChecked() {
    return [...document.querySelectorAll('.row-check:checked')].map(cb => cb.value);
}

function updateToolbar() {
    const ids = getChecked();
    if (ids.length > 0) {
        toolbar.style.display = 'flex';
        countLabel.textContent = ids.length + ' student' + (ids.length > 1 ? 's' : '') + ' selected';
    } else {
        toolbar.style.display = 'none';
    }
    // sync select-all state
    const all = document.querySelectorAll('.row-check');
    selectAll.indeterminate = ids.length > 0 && ids.length < all.length;
    selectAll.checked = ids.length > 0 && ids.length === all.length;
}

selectAll?.addEventListener('change', function() {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
    updateToolbar();
});

document.querySelectorAll('.row-check').forEach(cb =>
    cb.addEventListener('change', updateToolbar)
);

// ── Build hidden inputs for bulk forms ───────────────────────────────────────
function buildHiddenIds(containerId, ids) {
    const container = document.getElementById(containerId);
    container.innerHTML = '';
    ids.forEach(id => {
        const inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'user_ids[]';
        inp.value = id;
        container.appendChild(inp);
    });
}

// ── Bulk approve modal ───────────────────────────────────────────────────────
function openBulkApproveModal() {
    const ids = getChecked();
    if (!ids.length) return;
    document.getElementById('bulkApproveDesc').textContent =
        'You are about to approve ' + ids.length + ' pending registration' + (ids.length > 1 ? 's' : '') + '.';
    buildHiddenIds('bulkApproveIds', ids);
    const btn = document.getElementById('bulkApproveConfirmBtn');
    btn.disabled = false; btn.textContent = 'Approve All Selected';
    document.getElementById('bulkApproveModal').classList.add('active');
    document.getElementById('bulkApproveModal').setAttribute('aria-hidden','false');
}
function closeBulkApproveModal() {
    document.getElementById('bulkApproveModal').classList.remove('active');
    document.getElementById('bulkApproveModal').setAttribute('aria-hidden','true');
}
document.getElementById('bulkApproveForm').addEventListener('submit', function() {
    const btn = document.getElementById('bulkApproveConfirmBtn');
    btn.disabled = true; btn.textContent = 'Approving...';
});
document.getElementById('bulkApproveModal').addEventListener('click', function(e) {
    if (e.target === this) closeBulkApproveModal();
});

// ── Bulk reject modal ────────────────────────────────────────────────────────
function openBulkRejectModal() {
    const ids = getChecked();
    if (!ids.length) return;
    document.getElementById('bulkRejectDesc').textContent =
        'You are about to reject and delete ' + ids.length + ' pending registration' + (ids.length > 1 ? 's' : '') + '.';
    buildHiddenIds('bulkRejectIds', ids);
    const btn = document.getElementById('bulkRejectConfirmBtn');
    btn.disabled = false; btn.textContent = 'Reject All Selected';
    document.getElementById('bulkRejectModal').classList.add('active');
    document.getElementById('bulkRejectModal').setAttribute('aria-hidden','false');
}
function closeBulkRejectModal() {
    document.getElementById('bulkRejectModal').classList.remove('active');
    document.getElementById('bulkRejectModal').setAttribute('aria-hidden','true');
}
document.getElementById('bulkRejectForm').addEventListener('submit', function() {
    const btn = document.getElementById('bulkRejectConfirmBtn');
    btn.disabled = true; btn.textContent = 'Rejecting...';
});
document.getElementById('bulkRejectModal').addEventListener('click', function(e) {
    if (e.target === this) closeBulkRejectModal();
});

// ── Single approve modal ─────────────────────────────────────────────────────
function openApproveModal(userId, studentName) {
    document.getElementById('approveUserId').value = userId;
    document.getElementById('approveStudentName').textContent =
        'You are about to approve the registration for: ' + studentName;
    const btn = document.getElementById('approveConfirmBtn');
    btn.disabled = false; btn.textContent = 'Confirm Approval';
    document.getElementById('approveModal').classList.add('active');
    document.getElementById('approveModal').setAttribute('aria-hidden','false');
}
function closeApproveModal() {
    document.getElementById('approveModal').classList.remove('active');
    document.getElementById('approveModal').setAttribute('aria-hidden','true');
}
document.getElementById('approveModal').addEventListener('click', function(e) {
    if (e.target === this) closeApproveModal();
});
document.getElementById('approveForm').addEventListener('submit', function() {
    const btn = document.getElementById('approveConfirmBtn');
    btn.disabled = true; btn.textContent = 'Approving...';
});

// ── Single reject modal ──────────────────────────────────────────────────────
function openRejectModal(userId, studentName) {
    document.getElementById('rejectUserId').value = userId;
    document.getElementById('rejectStudentName').textContent =
        'You are about to reject the registration for: ' + studentName;
    const btn = document.getElementById('rejectConfirmBtn');
    btn.disabled = false; btn.textContent = 'Confirm Rejection';
    document.getElementById('rejectModal').classList.add('active');
    document.getElementById('rejectModal').setAttribute('aria-hidden','false');
}
function closeRejectModal() {
    document.getElementById('rejectModal').classList.remove('active');
    document.getElementById('rejectModal').setAttribute('aria-hidden','true');
}
document.getElementById('rejectModal').addEventListener('click', function(e) {
    if (e.target === this) closeRejectModal();
});
document.getElementById('rejectForm').addEventListener('submit', function() {
    const btn = document.getElementById('rejectConfirmBtn');
    btn.disabled = true; btn.textContent = 'Rejecting...';
});

// ── ESC closes any open modal ────────────────────────────────────────────────
document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape') return;
    closeApproveModal(); closeRejectModal();
    closeBulkApproveModal(); closeBulkRejectModal();
});
</script>

<?php include '../includes/footer.php'; ?>
