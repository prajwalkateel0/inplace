<?php
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/storage_helper.php';

requireAuth('admin');

$pageTitle    = 'View Placement';
$pageSubtitle = 'Placement details';
$activePage   = 'placements';
$userId       = authId();
$unreadCount  = 0;
$pendingRequests = 0;

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: placements.php'); exit; }

$stmt = $pdo->prepare("
    SELECT
        p.*,
        u.full_name     AS student_name,
        u.email         AS student_email,
        u.avatar_initials,
        u.academic_year,
        u.programme_type,
        c.name          AS company_name,
        c.city          AS company_city,
        c.address       AS company_address,
        c.sector        AS company_sector,
        c.website       AS company_website,
        (SELECT full_name FROM users WHERE id = p.tutor_id) AS tutor_name
    FROM placements p
    JOIN users u ON p.student_id = u.id
    JOIN companies c ON p.company_id = c.id
    WHERE p.id = ?
");
$stmt->execute([$id]);
$p = $stmt->fetch();
if (!$p) { header('Location: placements.php'); exit; }

// Documents
$docs = $pdo->prepare("SELECT * FROM documents WHERE placement_id = ? ORDER BY uploaded_at DESC");
$docs->execute([$id]);
$documents = $docs->fetchAll();
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>

    <div class="page-content">

        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
            <a href="placements.php" class="btn btn-ghost btn-sm">← Back to Placements</a>
            <a href="edit-placement.php?id=<?= $id ?>" class="btn btn-primary btn-sm">Edit Placement</a>
        </div>

        <!-- Status banner -->
        <?php
        $badgeColor = match($p['status']) {
            'approved', 'active'            => '#10b981',
            'rejected', 'terminated'        => '#ef4444',
            default                          => '#f59e0b'
        };
        ?>
        <div style="background:var(--white);border-radius:var(--radius);padding:1.5rem 2rem;
                    margin-bottom:1.5rem;border-left:4px solid <?= $badgeColor ?>;
                    box-shadow:var(--shadow);">
            <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
                <div class="avatar" style="width:3rem;height:3rem;font-size:1.1rem;">
                    <?= htmlspecialchars($p['avatar_initials'] ?? '??') ?>
                </div>
                <div>
                    <h2 style="font-family:'Playfair Display',serif;color:var(--navy);margin:0;">
                        <?= htmlspecialchars($p['student_name']) ?>
                    </h2>
                    <p style="color:var(--muted);margin:0;font-size:0.875rem;">
                        <?= htmlspecialchars($p['student_email']) ?>
                        &nbsp;·&nbsp;
                        <?= htmlspecialchars($p['academic_year'] ?? '') ?>
                        &nbsp;·&nbsp;
                        <?= htmlspecialchars($p['programme_type'] ?? '') ?>
                    </p>
                </div>
                <span style="margin-left:auto;background:<?= $badgeColor ?>1a;color:<?= $badgeColor ?>;
                             border:1px solid <?= $badgeColor ?>;border-radius:20px;
                             padding:0.3rem 1rem;font-weight:600;font-size:0.875rem;">
                    <?= ucwords(str_replace('_', ' ', $p['status'])) ?>
                </span>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">

            <!-- Placement Details -->
            <div class="panel">
                <div class="panel-header"><h3>Placement Details</h3></div>
                <table style="width:100%;border-collapse:collapse;">
                    <?php
                    $rows = [
                        ['Role / Job Title',     $p['role_title'] ?? '—'],
                        ['Company',              $p['company_name']],
                        ['Location',             ($p['company_city'] ?? '') . ($p['company_sector'] ? ' · ' . $p['company_sector'] : '')],
                        ['Start Date',           date('d M Y', strtotime($p['start_date']))],
                        ['End Date',             date('d M Y', strtotime($p['end_date']))],
                        ['Salary',               $p['salary'] ? htmlspecialchars($p['salary']) : '—'],
                        ['Working Pattern',      $p['working_pattern'] ? htmlspecialchars($p['working_pattern']) : '—'],
                        ['Assigned Tutor',       $p['tutor_name'] ?? 'Unassigned'],
                        ['Submitted',            date('d M Y', strtotime($p['created_at']))],
                    ];
                    foreach ($rows as [$label, $value]): ?>
                    <tr>
                        <td style="padding:0.65rem 1rem;font-weight:600;color:var(--navy);
                                   font-size:0.875rem;width:40%;border-bottom:1px solid var(--border);
                                   background:var(--cream);"><?= $label ?></td>
                        <td style="padding:0.65rem 1rem;font-size:0.875rem;
                                   border-bottom:1px solid var(--border);"><?= $value ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <!-- Supervisor & Company -->
            <div class="panel">
                <div class="panel-header"><h3>Supervisor & Company</h3></div>
                <table style="width:100%;border-collapse:collapse;">
                    <?php
                    $rows2 = [
                        ['Supervisor Name',  $p['supervisor_name']  ?? '—'],
                        ['Supervisor Email', $p['supervisor_email'] ?? '—'],
                        ['Supervisor Phone', $p['supervisor_phone'] ?? '—'],
                        ['Company Address',  $p['company_address']  ?? '—'],
                        ['Company Website',  $p['company_website']  ?? '—'],
                    ];
                    foreach ($rows2 as [$label, $value]): ?>
                    <tr>
                        <td style="padding:0.65rem 1rem;font-weight:600;color:var(--navy);
                                   font-size:0.875rem;width:40%;border-bottom:1px solid var(--border);
                                   background:var(--cream);"><?= $label ?></td>
                        <td style="padding:0.65rem 1rem;font-size:0.875rem;
                                   border-bottom:1px solid var(--border);"><?= htmlspecialchars($value) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <!-- Job Description -->
            <?php if ($p['job_description']): ?>
            <div class="panel" style="grid-column:1/-1;">
                <div class="panel-header"><h3>Job Description</h3></div>
                <div style="padding:1.25rem;color:var(--text);font-size:0.9375rem;line-height:1.7;white-space:pre-wrap;">
                    <?= htmlspecialchars($p['job_description']) ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Documents -->
            <div class="panel" style="grid-column:1/-1;">
                <div class="panel-header"><h3>Documents (<?= count($documents) ?>)</h3></div>
                <?php if (empty($documents)): ?>
                    <p style="padding:1.25rem;color:var(--muted);">No documents uploaded.</p>
                <?php else: ?>
                    <div style="padding:1rem;display:flex;flex-wrap:wrap;gap:0.75rem;">
                        <?php foreach ($documents as $doc): ?>
                        <a href="<?= htmlspecialchars(fileUrl($doc['file_path'] ?? '')) ?>"
                           target="_blank"
                           style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.5rem 1rem;
                                  border:1.5px solid var(--border);border-radius:var(--radius-sm);
                                  text-decoration:none;color:var(--navy);font-size:0.875rem;background:var(--cream);">
                            📄 <?= htmlspecialchars($doc['original_name'] ?? $doc['file_name'] ?? 'Document') ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
