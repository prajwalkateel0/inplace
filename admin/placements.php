<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('admin');

$pageTitle = 'All Placements';
$pageSubtitle = 'Overview of all student placements';
$activePage = 'placements';
$userId = authId();

$unreadCount = 0;
$pendingRequests = 0;

// Filters
$filterStatus = $_GET['status'] ?? '';
$filterCompany = $_GET['company'] ?? '';
$filterYear = $_GET['year'] ?? '';
$filterSearch = trim($_GET['search'] ?? '');

$where = [];
$params = [];

if ($filterStatus) {
    $where[] = "p.status = ?";
    $params[] = $filterStatus;
}

if ($filterCompany) {
    $where[] = "p.company_id = ?";
    $params[] = $filterCompany;
}

if ($filterYear) {
    $where[] = "u.academic_year = ?";
    $params[] = $filterYear;
}

if ($filterSearch) {
    $where[] = "(u.full_name LIKE ? OR c.name LIKE ? OR p.role_title LIKE ?)";
    $params[] = "%$filterSearch%";
    $params[] = "%$filterSearch%";
    $params[] = "%$filterSearch%";
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Fetch all placements
$stmt = $pdo->prepare("
    SELECT
        p.*,
        u.full_name AS student_name,
        u.email AS student_email,
        u.avatar_initials,
        u.academic_year,
        u.programme_type,
        c.name AS company_name,
        c.city AS company_city,
        c.sector AS company_sector,
        (SELECT full_name FROM users WHERE id = p.tutor_id) AS tutor_name
    FROM placements p
    JOIN users u ON p.student_id = u.id
    JOIN companies c ON p.company_id = c.id
    $whereSQL
    ORDER BY p.created_at DESC
");
$stmt->execute($params);
$placements = $stmt->fetchAll();

// Get stats
$stmt = $pdo->query("
    SELECT
        status,
        COUNT(*) as count
    FROM placements
    GROUP BY status
");
$stats = [];
foreach ($stmt->fetchAll() as $row) {
    $stats[$row['status']] = $row['count'];
}

// Get companies for filter
$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();

// Get academic years for filter
$years = $pdo->query("SELECT DISTINCT academic_year FROM users WHERE role='student' AND academic_year IS NOT NULL ORDER BY academic_year DESC")->fetchAll();
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>

    <div class="page-content">

        <!-- Stats Overview -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));
                    gap:1.25rem;margin-bottom:2rem;">
            
            <div class="panel" style="margin-bottom:0;padding:1.25rem 1.75rem;">
                <p style="font-size:0.75rem;font-weight:600;text-transform:uppercase;
                          letter-spacing:0.05em;color:var(--muted);margin-bottom:0.5rem;">
                    Total Placements
                </p>
                <h3 style="font-family:'Playfair Display',serif;font-size:1.625rem;color:var(--navy);">
                    <?= count($placements) ?>
                </h3>
            </div>

            <div class="panel" style="margin-bottom:0;padding:1.25rem 1.75rem;">
                <p style="font-size:0.75rem;font-weight:600;text-transform:uppercase;
                          letter-spacing:0.05em;color:var(--muted);margin-bottom:0.5rem;">
                    Active
                </p>
                <h3 style="font-family:'Playfair Display',serif;font-size:1.625rem;color:var(--success);">
                    <?= $stats['active'] ?? 0 ?>
                </h3>
            </div>

            <div class="panel" style="margin-bottom:0;padding:1.25rem 1.75rem;">
                <p style="font-size:0.75rem;font-weight:600;text-transform:uppercase;
                          letter-spacing:0.05em;color:var(--muted);margin-bottom:0.5rem;">
                    Pending
                </p>
                <h3 style="font-family:'Playfair Display',serif;font-size:1.625rem;color:var(--warning);">
                    <?= ($stats['submitted'] ?? 0) + ($stats['awaiting_provider'] ?? 0) + ($stats['awaiting_tutor'] ?? 0) ?>
                </h3>
            </div>

            <div class="panel" style="margin-bottom:0;padding:1.25rem 1.75rem;">
                <p style="font-size:0.75rem;font-weight:600;text-transform:uppercase;
                          letter-spacing:0.05em;color:var(--muted);margin-bottom:0.5rem;">
                    Approved
                </p>
                <h3 style="font-family:'Playfair Display',serif;font-size:1.625rem;color:var(--info);">
                    <?= $stats['approved'] ?? 0 ?>
                </h3>
            </div>

        </div>

        <!-- Filters -->
        <form method="GET" style="display:flex;gap:0.875rem;margin-bottom:1.5rem;flex-wrap:wrap;">
            
            <input type="text" name="search"
                   value="<?= htmlspecialchars($filterSearch) ?>"
                   placeholder="🔍  Search student, company, or role..."
                   style="padding:0.6875rem 1rem;border:1.5px solid var(--border);
                          border-radius:var(--radius-sm);font-family:inherit;font-size:0.875rem;
                          background:var(--white);min-width:300px;">

            <select name="status" onchange="this.form.submit()">
                <option value="">All Statuses</option>
                <option value="submitted" <?= $filterStatus==='submitted'?'selected':'' ?>>Submitted</option>
                <option value="awaiting_provider" <?= $filterStatus==='awaiting_provider'?'selected':'' ?>>Awaiting Provider</option>
                <option value="awaiting_tutor" <?= $filterStatus==='awaiting_tutor'?'selected':'' ?>>Awaiting Tutor</option>
                <option value="approved" <?= $filterStatus==='approved'?'selected':'' ?>>Approved</option>
                <option value="active" <?= $filterStatus==='active'?'selected':'' ?>>Active</option>
                <option value="rejected" <?= $filterStatus==='rejected'?'selected':'' ?>>Rejected</option>
                <option value="terminated" <?= $filterStatus==='terminated'?'selected':'' ?>>Terminated</option>
            </select>

            <select name="company" onchange="this.form.submit()">
                <option value="">All Companies</option>
                <?php foreach ($companies as $comp): ?>
                <option value="<?= $comp['id'] ?>" <?= $filterCompany==(string)$comp['id']?'selected':'' ?>>
                    <?= htmlspecialchars($comp['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>

            <select name="year" onchange="this.form.submit()">
                <option value="">All Years</option>
                <?php foreach ($years as $yr): ?>
                <option value="<?= htmlspecialchars($yr['academic_year']) ?>" <?= $filterYear===$yr['academic_year']?'selected':'' ?>>
                    <?= htmlspecialchars($yr['academic_year']) ?>
                </option>
                <?php endforeach; ?>
            </select>

            <div style="margin-left:auto;display:flex;gap:0.75rem;">
                <?php if ($filterSearch || $filterStatus || $filterCompany || $filterYear): ?>
                    <a href="placements.php" class="btn btn-ghost btn-sm">✕ Clear</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <a href="export-placements.php?<?= http_build_query($_GET) ?>" class="btn btn-success btn-sm">
                    📥 Export CSV
                </a>
            </div>

        </form>

        <!-- Placements Table -->
        <div class="panel">
            <div class="panel-header">
                <h3><?= count($placements) ?> Placement<?= count($placements)!==1?'s':'' ?></h3>
            </div>

            <?php if (empty($placements)): ?>
            <div style="text-align:center;padding:4rem 2rem;">
                <div style="font-size:3rem;margin-bottom:1rem;">🏢</div>
                <p style="color:var(--muted);font-size:1rem;">No placements found.</p>
            </div>

            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Company</th>
                            <th>Role</th>
                            <th>Dates</th>
                            <th>Year</th>
                            <th>Tutor</th>
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
                                    <div class="avatar"><?= htmlspecialchars($p['avatar_initials']??'??') ?></div>
                                    <div>
                                        <h4><?= htmlspecialchars($p['student_name']) ?></h4>
                                        <p><?= htmlspecialchars($p['student_email']) ?></p>
                                    </div>
                                </div>
                            </td>

                            <!-- Company -->
                            <td>
                                <div style="font-weight:500;"><?= htmlspecialchars($p['company_name']) ?></div>
                                <div style="font-size:0.8125rem;color:var(--muted);">
                                    <?= htmlspecialchars($p['company_city']) ?>
                                    <?php if ($p['company_sector']): ?>
                                        · <?= htmlspecialchars($p['company_sector']) ?>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- Role -->
                            <td style="max-width:200px;">
                                <span class="type-chip">
                                    <?= htmlspecialchars($p['role_title']??'N/A') ?>
                                </span>
                            </td>

                            <!-- Dates -->
                            <td style="font-family:'DM Mono',monospace;font-size:0.8125rem;">
                                <?= date('d M Y', strtotime($p['start_date'])) ?><br>
                                <span style="color:var(--muted);">to</span><br>
                                <?= date('d M Y', strtotime($p['end_date'])) ?>
                            </td>

                            <!-- Year -->
                            <td style="font-size:0.875rem;">
                                <?= htmlspecialchars($p['academic_year']??'N/A') ?>
                                <div style="font-size:0.75rem;color:var(--muted);">
                                    <?= htmlspecialchars($p['programme_type']??'') ?>
                                </div>
                            </td>

                            <!-- Tutor -->
                            <td style="font-size:0.875rem;">
                                <?= htmlspecialchars($p['tutor_name']??'Unassigned') ?>
                            </td>

                            <!-- Status -->
                            <td>
                                <?php
                                $badgeClass = match($p['status']) {
                                    'approved', 'active' => 'approved',
                                    'rejected', 'terminated' => 'rejected',
                                    'submitted', 'awaiting_provider', 'awaiting_tutor' => 'pending',
                                    default => 'open'
                                };
                                ?>
                                <span class="badge badge-<?= $badgeClass ?>">
                                    <?= ucwords(str_replace('_', ' ', $p['status'])) ?>
                                </span>
                            </td>

                            <!-- Actions -->
                            <td>
                                <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
                                    <a href="view-placement.php?id=<?= $p['id'] ?>" 
                                       class="btn btn-ghost btn-sm">View</a>
                                    <a href="edit-placement.php?id=<?= $p['id'] ?>" 
                                       class="btn btn-primary btn-sm">Edit</a>
                                </div>
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