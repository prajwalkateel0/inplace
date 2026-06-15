<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('provider');

$pageTitle    = 'My Students';
$pageSubtitle = 'Students currently placed at your company';
$activePage   = 'students';
$userId       = authId();

// Get provider's company
$stmt = $pdo->prepare("SELECT company_id, full_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$provider = $stmt->fetch();
$companyId = $provider['company_id'] ?? null;

if (!$companyId) { header('Location: dashboard.php'); exit; }

// Sidebar badges
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM placements WHERE company_id = ? AND status = 'awaiting_provider'");
$stmt->execute([$companyId]);
$pendingRequests = (int)$stmt->fetchColumn();

// Filters
$filterStatus = $_GET['status'] ?? '';
$filterSearch = trim($_GET['search'] ?? '');

$where  = ["p.company_id = ?"];
$params = [$companyId];

if ($filterStatus) {
    $where[]  = "p.status = ?";
    $params[] = $filterStatus;
} else {
    $where[] = "p.status IN ('awaiting_provider','awaiting_tutor','approved','active')";
}

if ($filterSearch) {
    $where[]  = "(u.full_name LIKE ? OR u.email LIKE ? OR p.role_title LIKE ?)";
    $params[] = "%$filterSearch%";
    $params[] = "%$filterSearch%";
    $params[] = "%$filterSearch%";
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT
        p.*,
        u.full_name       AS student_name,
        u.email           AS student_email,
        u.avatar_initials,
        u.academic_year,
        u.programme_type,
        t.full_name       AS tutor_name
    FROM placements p
    JOIN users u ON p.student_id = u.id
    LEFT JOIN users t ON p.tutor_id = t.id
    $whereSQL
    ORDER BY
        CASE p.status
            WHEN 'active'            THEN 1
            WHEN 'approved'          THEN 2
            WHEN 'awaiting_tutor'    THEN 3
            WHEN 'awaiting_provider' THEN 4
            ELSE 5
        END,
        p.start_date DESC
");
$stmt->execute($params);
$students = $stmt->fetchAll();
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>

    <div class="page-content">

        <!-- Filter Bar -->
        <form method="GET" class="filter-bar">
            <input type="text" name="search"
                   value="<?= htmlspecialchars($filterSearch) ?>"
                   placeholder="🔍 Search by name, email or role...">

            <select name="status" onchange="this.form.submit()">
                <option value="" <?= !$filterStatus ? 'selected' : '' ?>>All Statuses</option>
                <option value="active"            <?= $filterStatus==='active'           ?'selected':'' ?>>Active</option>
                <option value="approved"          <?= $filterStatus==='approved'         ?'selected':'' ?>>Approved</option>
                <option value="awaiting_tutor"    <?= $filterStatus==='awaiting_tutor'   ?'selected':'' ?>>Awaiting Tutor</option>
                <option value="awaiting_provider" <?= $filterStatus==='awaiting_provider'?'selected':'' ?>>Awaiting Provider</option>
                <option value="rejected"          <?= $filterStatus==='rejected'         ?'selected':'' ?>>Rejected</option>
            </select>

            <div style="margin-left:auto;display:flex;gap:0.75rem;">
                <?php if ($filterSearch || $filterStatus): ?>
                    <a href="students.php" class="btn btn-ghost btn-sm">✕ Clear</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
            </div>
        </form>

        <!-- Table -->
        <div class="panel">
            <div class="panel-header">
                <div>
                    <h3><?= count($students) ?> Student<?= count($students) !== 1 ? 's' : '' ?></h3>
                    <p>Placed at your company</p>
                </div>
            </div>

            <?php if (empty($students)): ?>
            <div style="text-align:center;padding:4rem 2rem;">
                <div style="font-size:3rem;margin-bottom:1rem;">👥</div>
                <p style="color:var(--muted);font-size:1rem;">No students found matching your filters.</p>
            </div>
            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Role</th>
                            <th>Dates</th>
                            <th>Year / Programme</th>
                            <th>Tutor</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $s): ?>
                        <tr>
                            <td>
                                <div class="avatar-cell">
                                    <div class="avatar"><?= htmlspecialchars($s['avatar_initials'] ?? '??') ?></div>
                                    <div>
                                        <h4><?= htmlspecialchars($s['student_name']) ?></h4>
                                        <p><?= htmlspecialchars($s['student_email']) ?></p>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="type-chip">
                                    <?= htmlspecialchars($s['role_title'] ?? 'Not specified') ?>
                                </span>
                            </td>
                            <td style="font-family:'DM Mono',monospace;font-size:0.8125rem;">
                                <?= date('d M Y', strtotime($s['start_date'])) ?><br>
                                <span style="color:var(--muted);">to</span><br>
                                <?= date('d M Y', strtotime($s['end_date'])) ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($s['academic_year'] ?? 'N/A') ?><br>
                                <span style="font-size:0.75rem;color:var(--muted);">
                                    <?= htmlspecialchars($s['programme_type'] ?? '') ?>
                                </span>
                            </td>
                            <td style="font-size:0.875rem;">
                                <?= htmlspecialchars($s['tutor_name'] ?? 'Unassigned') ?>
                            </td>
                            <td>
                                <?php
                                $badgeClass = match($s['status']) {
                                    'active', 'approved'    => 'approved',
                                    'awaiting_provider',
                                    'awaiting_tutor'        => 'pending',
                                    'rejected','terminated' => 'rejected',
                                    default                  => 'open'
                                };
                                ?>
                                <span class="badge badge-<?= $badgeClass ?>">
                                    <?= ucwords(str_replace('_', ' ', $s['status'])) ?>
                                </span>
                            </td>
                            <td>
                                <a href="view-placement.php?id=<?= $s['id'] ?>"
                                   class="btn btn-ghost btn-sm">View</a>
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

<?php include '../includes/footer.php'; ?>
