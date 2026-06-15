<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('admin');

$pageTitle    = 'Audit Logs';
$pageSubtitle = 'System activity and audit trail';
$activePage   = 'dashboard';
$userId       = authId();
$unreadCount  = 0;
$pendingRequests = 0;

// Filters
$filterUser   = trim($_GET['user']   ?? '');
$filterAction = trim($_GET['action'] ?? '');
$filterDate   = trim($_GET['date']   ?? '');

$where  = ['1=1'];
$params = [];

if ($filterUser) {
    $where[]  = "u.full_name LIKE ?";
    $params[] = "%$filterUser%";
}
if ($filterAction) {
    $where[]  = "a.action LIKE ?";
    $params[] = "%$filterAction%";
}
if ($filterDate) {
    $where[]  = "DATE(a.created_at) = ?";
    $params[] = $filterDate;
}

$whereSQL = implode(' AND ', $where);

// Fetch logs
try {
    $stmt = $pdo->prepare("
        SELECT a.*, u.full_name, u.email, u.role
        FROM audit_log a
        LEFT JOIN users u ON a.user_id = u.id
        WHERE $whereSQL
        ORDER BY a.created_at DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $logs = [];
}
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>
    <div class="page-content">

        <!-- Filter bar -->
        <form method="GET" style="display:flex;gap:0.75rem;margin-bottom:1.5rem;flex-wrap:wrap;align-items:center;">
            <input type="text" name="user" placeholder="Search user..."
                   value="<?= htmlspecialchars($filterUser) ?>"
                   style="padding:0.625rem 1rem;border:1.5px solid var(--border);border-radius:var(--radius-sm);
                          font-family:inherit;font-size:0.875rem;background:var(--white);min-width:200px;">
            <input type="text" name="action" placeholder="Filter by action..."
                   value="<?= htmlspecialchars($filterAction) ?>"
                   style="padding:0.625rem 1rem;border:1.5px solid var(--border);border-radius:var(--radius-sm);
                          font-family:inherit;font-size:0.875rem;background:var(--white);min-width:200px;">
            <input type="date" name="date" value="<?= htmlspecialchars($filterDate) ?>"
                   style="padding:0.625rem 1rem;border:1.5px solid var(--border);border-radius:var(--radius-sm);
                          font-family:inherit;font-size:0.875rem;background:var(--white);">
            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            <?php if ($filterUser || $filterAction || $filterDate): ?>
                <a href="logs.php" class="btn btn-ghost btn-sm">✕ Clear</a>
            <?php endif; ?>
            <span style="margin-left:auto;font-size:0.875rem;color:var(--muted);">
                <?= count($logs) ?> entries
            </span>
        </form>

        <div class="panel">
            <div class="panel-header"><h3>Audit Log</h3></div>
            <?php if (empty($logs)): ?>
            <div style="text-align:center;padding:3rem;color:var(--muted);">No log entries found.</div>
            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>User</th>
                            <th>Role</th>
                            <th>Action</th>
                            <th>Table</th>
                            <th>Record ID</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td style="font-size:0.8125rem;color:var(--muted);white-space:nowrap;">
                                <?= date('d M Y H:i', strtotime($log['created_at'])) ?>
                            </td>
                            <td>
                                <div style="font-weight:500;"><?= htmlspecialchars($log['full_name'] ?? 'Unknown') ?></div>
                                <div style="font-size:0.75rem;color:var(--muted);"><?= htmlspecialchars($log['email'] ?? '') ?></div>
                            </td>
                            <td>
                                <span class="badge badge-pending" style="font-size:0.7rem;">
                                    <?= htmlspecialchars(ucfirst($log['role'] ?? '—')) ?>
                                </span>
                            </td>
                            <td style="font-size:0.8125rem;">
                                <?= htmlspecialchars(str_replace('_', ' ', $log['action'] ?? '')) ?>
                            </td>
                            <td style="font-size:0.8125rem;color:var(--muted);">
                                <?= htmlspecialchars($log['table_affected'] ?? '—') ?>
                            </td>
                            <td style="font-size:0.8125rem;color:var(--muted);">
                                <?= htmlspecialchars($log['record_id'] ?? '—') ?>
                            </td>
                            <td style="font-size:0.8125rem;color:var(--muted);">
                                <?= htmlspecialchars($log['ip_address'] ?? '—') ?>
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
