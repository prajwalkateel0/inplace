<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('tutor');

$pageTitle    = 'All Placements';
$pageSubtitle = 'View and manage all student placements';
$activePage   = 'placements';
$userId       = authId();

// Sidebar badges
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM placements WHERE status IN ('submitted','awaiting_tutor')");
$pendingRequests = (int)$stmt->fetchColumn();

// ── Filters ──────────────────────────────────────────────────────
$filterStatus   = $_GET['status']   ?? '';
$filterCompany  = $_GET['company']  ?? '';
$filterLocation = $_GET['location'] ?? '';
$filterSearch   = trim($_GET['search'] ?? '');

$where  = [];
$params = [];

// Only show approved/active placements by default (not drafts/rejected)
if ($filterStatus) {
    $where[]  = "p.status = ?";
    $params[] = $filterStatus;
} else {
    $where[] = "p.status IN ('approved','active')";
}

if ($filterCompany) {
    $where[]  = "c.name LIKE ?";
    $params[] = "%$filterCompany%";
}

if ($filterLocation) {
    $where[]  = "c.city LIKE ?";
    $params[] = "%$filterLocation%";
}

if ($filterSearch) {
    $where[]  = "(u.full_name LIKE ? OR c.name LIKE ? OR c.city LIKE ?)";
    $params[] = "%$filterSearch%";
    $params[] = "%$filterSearch%";
    $params[] = "%$filterSearch%";
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Fetch all placements ─────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        p.*,
        u.full_name       AS student_name,
        u.email           AS student_email,
        u.student_id      AS student_number,
        u.avatar_initials AS student_initials,
        c.name            AS company_name,
        c.city            AS company_city,
        c.address         AS company_address,
        c.sector          AS company_sector
    FROM placements p
    JOIN users     u ON p.student_id = u.id
    JOIN companies c ON p.company_id = c.id
    $whereSQL
    ORDER BY p.start_date DESC, u.full_name ASC
");
$stmt->execute($params);
$placements = $stmt->fetchAll();

// ── Get unique companies and cities for filter dropdowns ─────────
$stmt = $pdo->query("
    SELECT DISTINCT c.name
    FROM companies c
    JOIN placements p ON p.company_id = c.id
    WHERE p.status IN ('approved','active')
    ORDER BY c.name ASC
");
$companies = $stmt->fetchAll(PDO::FETCH_COLUMN);

$stmt = $pdo->query("
    SELECT DISTINCT c.city
    FROM companies c
    JOIN placements p ON p.company_id = c.id
    WHERE p.status IN ('approved','active') AND c.city IS NOT NULL AND c.city != ''
    ORDER BY c.city ASC
");
$cities = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>

    <div class="page-content">

        <!-- ── Filter Bar ────────────────────────────────────── -->
        <form method="GET" class="filter-bar">

            <input type="text" name="search"
                   value="<?= htmlspecialchars($filterSearch) ?>"
                   placeholder="🔍  Search by name, company or location...">

            <select name="status" onchange="this.form.submit()">
                <option value="" <?= !$filterStatus?'selected':'' ?>>All Statuses</option>
                <option value="approved" <?= $filterStatus==='approved'?'selected':'' ?>>Approved</option>
                <option value="active"   <?= $filterStatus==='active'?'selected':'' ?>>Active</option>
                <option value="completed"<?= $filterStatus==='completed'?'selected':'' ?>>Completed</option>
            </select>

            <select name="location" onchange="this.form.submit()">
                <option value="">All Locations</option>
                <?php foreach ($cities as $city): ?>
                <option value="<?= htmlspecialchars($city) ?>"
                        <?= $filterLocation===$city?'selected':'' ?>>
                    <?= htmlspecialchars($city) ?>
                </option>
                <?php endforeach; ?>
            </select>

            <select name="company" onchange="this.form.submit()">
                <option value="">All Companies</option>
                <?php foreach ($companies as $comp): ?>
                <option value="<?= htmlspecialchars($comp) ?>"
                        <?= $filterCompany===$comp?'selected':'' ?>>
                    <?= htmlspecialchars($comp) ?>
                </option>
                <?php endforeach; ?>
            </select>

            <div style="margin-left:auto;display:flex;gap:0.75rem;">
                <?php if ($filterSearch || $filterStatus || $filterLocation || $filterCompany): ?>
                    <a href="all-placements.php" class="btn btn-ghost btn-sm">✕ Clear</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <a href="/inplace/tutor/map-view.php" class="btn btn-ghost btn-sm">🗺 Map View</a>
                <button type="button" class="btn btn-ghost btn-sm"
                        onclick="window.print()">⬇ Export CSV</button>
            </div>

        </form>


        <!-- ═══════════════════════════════════════════════════════
             PLACEMENTS TABLE
        ════════════════════════════════════════════════════════ -->
        <div class="panel">
            <div class="panel-header">
                <div>
                    <h3><?= count($placements) ?> Student<?= count($placements)!==1?'s':'' ?> on Placement</h3>
                    <p>Academic Year 2024–25</p>
                </div>
            </div>

            <?php if (empty($placements)): ?>
            <div style="text-align:center;padding:4rem 2rem;">
                <div style="font-size:3rem;margin-bottom:1rem;">👥</div>
                <p style="color:var(--muted);font-size:1rem;">No placements found matching your filters.</p>
            </div>

            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Company & Location</th>
                            <th>Role</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($placements as $p): ?>
                        <tr>
                            <!-- Student -->
                            <td>
                                <div class="avatar-cell">
                                    <div class="avatar">
                                        <?= htmlspecialchars($p['student_initials'] ?? '??') ?>
                                    </div>
                                    <div>
                                        <h4><?= htmlspecialchars($p['student_name']) ?></h4>
                                        <p>
                                            <?= htmlspecialchars($p['student_number'] ?? $p['student_email']) ?>
                                        </p>
                                    </div>
                                </div>
                            </td>

                            <!-- Company -->
                            <td>
                                <div style="font-weight:500;">
                                    <?= htmlspecialchars($p['company_name']) ?>
                                </div>
                                <div style="font-size:0.8125rem;color:var(--muted);">
                                    <?= htmlspecialchars($p['company_city'] ?: 'N/A') ?>
                                    <?= $p['company_sector'] ? ' · ' . htmlspecialchars($p['company_sector']) : '' ?>
                                </div>
                            </td>

                            <!-- Role -->
                            <td>
                                <span class="type-chip">
                                    <?= htmlspecialchars($p['role_title'] ?? 'N/A') ?>
                                </span>
                            </td>

                            <!-- Start Date -->
                            <td style="font-family:'DM Mono',monospace;font-size:0.8125rem;">
                                <?= date('d M Y', strtotime($p['start_date'])) ?>
                            </td>

                            <!-- End Date -->
                            <td style="font-family:'DM Mono',monospace;font-size:0.8125rem;">
                                <?= date('d M Y', strtotime($p['end_date'])) ?>
                            </td>

                            <!-- Status Badge -->
                            <td>
                                <?php
                                $badgeClass = match($p['status']) {
                                    'approved','active' => 'approved',
                                    'completed'         => 'approved',
                                    'terminated'        => 'rejected',
                                    default             => 'pending'
                                };
                                ?>
                                <span class="badge badge-<?= $badgeClass ?>">
                                    <?= ucfirst($p['status']) ?>
                                </span>
                            </td>

                            <!-- Actions -->
                            <td>
                                <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
                                    <button class="btn btn-ghost btn-sm"
                                            onclick="viewDetail(<?= $p['id'] ?>)">
                                        View
                                    </button>
                                    <button class="btn btn-primary btn-sm"
                                            onclick="window.location='/inplace/tutor/edit-placement.php?id=<?= $p['id'] ?>'">
                                        Edit
                                    </button>
                                </div>
                            </td>
                        </tr>

                        <!-- ── Expandable Detail Row ────────────────── -->
                        <tr id="detail-<?= $p['id'] ?>" style="display:none;">
                            <td colspan="7" style="background:var(--cream);padding:1.5rem 2rem;">

                                <div class="info-grid" style="margin-bottom:1.25rem;">
                                    <div class="info-item">
                                        <label>Student Email</label>
                                        <p><a href="mailto:<?= htmlspecialchars($p['student_email']) ?>"
                                              style="color:var(--navy);">
                                            <?= htmlspecialchars($p['student_email']) ?>
                                        </a></p>
                                    </div>
                                    <div class="info-item">
                                        <label>Company Address</label>
                                        <p><?= htmlspecialchars($p['company_address'] ?? 'N/A') ?></p>
                                    </div>
                                    <div class="info-item">
                                        <label>Supervisor</label>
                                        <p><?= htmlspecialchars($p['supervisor_name'] ?? 'N/A') ?></p>
                                    </div>
                                    <div class="info-item">
                                        <label>Supervisor Email</label>
                                        <p>
                                            <?php if ($p['supervisor_email']): ?>
                                            <a href="mailto:<?= htmlspecialchars($p['supervisor_email']) ?>"
                                               style="color:var(--navy);">
                                                <?= htmlspecialchars($p['supervisor_email']) ?>
                                            </a>
                                            <?php else: ?>
                                            N/A
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div class="info-item">
                                        <label>Supervisor Phone</label>
                                        <p><?= htmlspecialchars($p['supervisor_phone'] ?? 'N/A') ?></p>
                                    </div>
                                    <div class="info-item">
                                        <label>Salary</label>
                                        <p><?= htmlspecialchars($p['salary'] ?? 'Not stated') ?></p>
                                    </div>
                                    <div class="info-item">
                                        <label>Working Pattern</label>
                                        <p><?= htmlspecialchars($p['working_pattern'] ?? 'N/A') ?></p>
                                    </div>
                                </div>

                                <?php if ($p['job_description']): ?>
                                <div>
                                    <p style="font-size:0.75rem;font-weight:600;text-transform:uppercase;
                                              letter-spacing:0.05em;color:var(--muted);margin-bottom:0.5rem;">
                                        Job Description
                                    </p>
                                    <p style="font-size:0.9rem;line-height:1.6;color:var(--text);">
                                        <?= nl2br(htmlspecialchars($p['job_description'])) ?>
                                    </p>
                                </div>
                                <?php endif; ?>

                                <div style="margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid var(--border);
                                            display:flex;gap:0.75rem;">
                                    <button class="btn btn-primary btn-sm"
                                            onclick="window.location='/inplace/tutor/schedule-visit.php?placement_id=<?= $p['id'] ?>'">
                                        🗓 Schedule Visit
                                    </button>
                                    <button class="btn btn-ghost btn-sm"
                                            onclick="window.location='/inplace/tutor/messages.php?student_id=<?= $p['student_id'] ?>'">
                                        💬 Message Student
                                    </button>
                                    <button class="btn btn-ghost btn-sm"
                                            onclick="openTerminate(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['student_name'])) ?>')">
                                        ⚠️ Terminate Placement
                                    </button>
                                </div>

                            </td>
                        </tr>

                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

        </div><!-- /panel -->

    </div><!-- /page-content -->
</div><!-- /main -->


<!-- ══════════════════════════════════════════════════════════════
     MODAL: Terminate Placement
══════════════════════════════════════════════════════════════ -->
<div id="terminateModal" style="display:none;position:fixed;inset:0;
     background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--white);border-radius:var(--radius);padding:2.5rem;
                width:100%;max-width:500px;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
        <h3 style="font-family:'Playfair Display',serif;font-size:1.375rem;
                   color:var(--danger);margin-bottom:0.5rem;">
            ⚠️ Terminate Placement
        </h3>
        <p id="terminateSubtitle" style="color:var(--muted);font-size:0.9rem;margin-bottom:1.5rem;"></p>

        <form method="POST" action="/inplace/tutor/actions/terminate-placement.php">
            <input type="hidden" name="placement_id" id="terminatePlacementId">

            <div class="form-group" style="margin-bottom:1.5rem;">
                <label>Reason for termination <span style="color:var(--danger);">*</span></label>
                <textarea name="reason" rows="4" required
                          placeholder="Explain clearly why this placement is being terminated..."
                          style="width:100%;padding:0.875rem;border:2px solid #fca5a5;
                                 border-radius:var(--radius-sm);font-family:inherit;
                                 font-size:0.9375rem;background:#fff8f8;resize:vertical;"></textarea>
            </div>

            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button type="button" class="btn btn-ghost" onclick="closeTerminate()">
                    Cancel
                </button>
                <button type="submit" class="btn btn-danger">
                    Confirm Termination
                </button>
            </div>
        </form>
    </div>
</div>


<script>
function viewDetail(id) {
    const row = document.getElementById('detail-' + id);
    if (row) {
        row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
    }
}

function openTerminate(id, studentName) {
    document.getElementById('terminatePlacementId').value = id;
    document.getElementById('terminateSubtitle').textContent =
        'You are about to terminate ' + studentName + '\'s placement. This action will notify the student and mark the placement as terminated.';
    document.getElementById('terminateModal').style.display = 'flex';
}

function closeTerminate() {
    document.getElementById('terminateModal').style.display = 'none';
}

// Close on outside click
document.getElementById('terminateModal').addEventListener('click', function(e) {
    if (e.target === this) closeTerminate();
});
</script>

<?php include '../includes/footer.php'; ?>