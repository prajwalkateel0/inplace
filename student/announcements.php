<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('student');

$pageTitle    = 'Announcements';
$pageSubtitle = 'Messages from your placement team';
$activePage   = 'announcements';
$userId       = authId();

// unread messages count for the sidebar
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount     = (int)$stmt->fetchColumn();
$pendingRequests = 0;

// make sure the announcements tables exist (tutor creates them, but just in case)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS announcements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            author_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            audience ENUM('all','year','programme') DEFAULT 'all',
            target_value VARCHAR(100) DEFAULT NULL,
            is_pinned TINYINT(1) DEFAULT 0,
            expires_at DATE DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS announcement_reads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            announcement_id INT NOT NULL,
            student_id INT NOT NULL,
            read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_read (announcement_id, student_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}

// get the student's year and programme so we can filter announcements for them
$stmt = $pdo->prepare("SELECT academic_year, programme_type FROM users WHERE id = ?");
$stmt->execute([$userId]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);

// fetch announcements that apply to this student
$stmt = $pdo->prepare("
    SELECT a.*,
           u.full_name AS author_name,
           u.avatar_initials AS author_initials,
           (SELECT read_at FROM announcement_reads ar
            WHERE ar.announcement_id = a.id AND ar.student_id = ?) AS read_at
    FROM announcements a
    JOIN users u ON a.author_id = u.id
    WHERE
        (a.expires_at IS NULL OR a.expires_at >= CURDATE())
        AND (
            a.audience = 'all'
            OR (a.audience = 'year'      AND a.target_value = ?)
            OR (a.audience = 'programme' AND a.target_value = ?)
        )
    ORDER BY a.is_pinned DESC, a.created_at DESC
");
$stmt->execute([$userId, $me['academic_year'] ?? '', $me['programme_type'] ?? '']);
$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalCount  = count($announcements);
$unreadAnns  = array_filter($announcements, fn($a) => !$a['read_at']);
$unreadAnnCount = count($unreadAnns);

// check if the user only wants to see unread announcements
$filterUnread = isset($_GET['filter']) && $_GET['filter'] === 'unread';
$displayed = $filterUnread ? array_values($unreadAnns) : $announcements;

// mark all displayed announcements as read for this student
if (!empty($displayed)) {
    $ids = array_column($displayed, 'id');
    foreach ($ids as $aid) {
        try {
            $pdo->prepare("INSERT IGNORE INTO announcement_reads (announcement_id, student_id) VALUES (?, ?)")
                ->execute([$aid, $userId]);
        } catch (Exception $e) {}
    }
}
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>
    <div class="page-content">

        <!-- Header bar -->
        <div style="display:flex;align-items:center;justify-content:space-between;
                    flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
            <div>
                <h2 style="font-family:'Playfair Display',serif;font-size:1.5rem;color:var(--navy);margin-bottom:0.25rem;">
                    📢 Announcements
                </h2>
                <p style="color:var(--muted);font-size:0.875rem;">
                    <?= $totalCount ?> announcement<?= $totalCount !== 1 ? 's' : '' ?>
                    <?= $unreadAnnCount > 0 ? " · <strong style='color:var(--navy);'>{$unreadAnnCount} unread</strong>" : ' · All read' ?>
                </p>
            </div>
            <div style="display:flex;gap:0.75rem;">
                <a href="announcements.php"
                   class="btn <?= !$filterUnread ? 'btn-primary' : 'btn-ghost' ?> btn-sm">
                    All (<?= $totalCount ?>)
                </a>
                <a href="announcements.php?filter=unread"
                   class="btn <?= $filterUnread ? 'btn-primary' : 'btn-ghost' ?> btn-sm">
                    Unread (<?= $unreadAnnCount ?>)
                </a>
            </div>
        </div>

        <?php if (empty($displayed)): ?>
        <div class="panel">
            <div style="text-align:center;padding:4rem 2rem;">
                <div style="font-size:4rem;margin-bottom:1rem;">📭</div>
                <h3 style="font-family:'Playfair Display',serif;color:var(--navy);margin-bottom:0.75rem;">
                    <?= $filterUnread ? 'All caught up!' : 'No announcements yet' ?>
                </h3>
                <p style="color:var(--muted);max-width:380px;margin:0 auto;">
                    <?= $filterUnread
                        ? 'You\'ve read all announcements. Check back later for new messages from your tutor.'
                        : 'Your tutor hasn\'t posted any announcements yet. Check back soon.' ?>
                </p>
                <?php if ($filterUnread && $totalCount > 0): ?>
                <a href="announcements.php" class="btn btn-ghost btn-sm" style="margin-top:1.25rem;">
                    View all announcements →
                </a>
                <?php endif; ?>
            </div>
        </div>

        <?php else: ?>

        <div style="display:flex;flex-direction:column;gap:1rem;">
            <?php foreach ($displayed as $ann):
                $isUnread = !$ann['read_at'] || (new DateTime($ann['read_at'])) < (new DateTime($ann['created_at']));
                $isNew    = (new DateTime($ann['created_at'])) > (new DateTime())->modify('-3 days');
            ?>
            <div class="panel" style="margin-bottom:0;
                         <?= $ann['is_pinned'] ? 'border-left:4px solid #e8a020;' : '' ?>">
                <div style="padding:1.75rem 2rem;">

                    <!-- Top meta row -->
                    <div style="display:flex;align-items:flex-start;gap:1rem;flex-wrap:wrap;">

                        <!-- Avatar -->
                        <div style="width:44px;height:44px;border-radius:50%;flex-shrink:0;
                                    background:linear-gradient(135deg,#0c1b33,#1a2d4d);
                                    display:flex;align-items:center;justify-content:center;
                                    font-size:1rem;font-weight:700;color:white;">
                            <?= htmlspecialchars($ann['author_initials'] ?? 'T') ?>
                        </div>

                        <div style="flex:1;min-width:0;">
                            <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.2rem;">
                                <span style="font-weight:600;font-size:0.9375rem;color:var(--navy);">
                                    <?= htmlspecialchars($ann['author_name']) ?>
                                </span>
                                <span style="color:var(--muted);font-size:0.8125rem;">·</span>
                                <span style="font-size:0.8125rem;color:var(--muted);">
                                    <?= date('d M Y, g:i A', strtotime($ann['created_at'])) ?>
                                </span>
                                <?php if ($ann['is_pinned']): ?>
                                <span style="font-size:0.7rem;font-weight:700;background:#fef3c7;
                                             color:#92400e;padding:0.15rem 0.5rem;border-radius:4px;">
                                    📌 Pinned
                                </span>
                                <?php endif; ?>
                                <?php if ($isNew && !$ann['is_pinned']): ?>
                                <span style="font-size:0.7rem;font-weight:700;background:#dbeafe;
                                             color:#1e40af;padding:0.15rem 0.5rem;border-radius:4px;">
                                    NEW
                                </span>
                                <?php endif; ?>
                                <?php if ($isUnread): ?>
                                <span style="width:8px;height:8px;border-radius:50%;
                                             background:var(--navy);display:inline-block;"
                                      title="Unread"></span>
                                <?php endif; ?>
                            </div>

                            <!-- Audience chip -->
                            <?php
                            $audienceLabel = match($ann['audience']) {
                                'year'      => 'Year ' . ($ann['target_value'] ?? ''),
                                'programme' => $ann['target_value'] ?? '',
                                default     => 'All Students',
                            };
                            ?>
                            <span style="font-size:0.75rem;background:var(--cream);
                                         color:var(--muted);padding:0.1rem 0.5rem;
                                         border-radius:4px;border:1px solid var(--border);">
                                <?= htmlspecialchars($audienceLabel) ?>
                            </span>
                        </div>
                    </div>

                    <!-- Title & body -->
                    <div style="margin-top:1.25rem;padding-left:0;">
                        <h3 style="font-family:'Playfair Display',serif;font-size:1.125rem;
                                   color:var(--navy);margin-bottom:0.75rem;line-height:1.4;">
                            <?= htmlspecialchars($ann['title']) ?>
                        </h3>
                        <div style="font-size:0.9375rem;color:var(--text);line-height:1.8;
                                    white-space:pre-wrap;word-break:break-word;">
                            <?= htmlspecialchars($ann['body']) ?>
                        </div>
                    </div>

                    <!-- Footer -->
                    <?php if ($ann['expires_at']): ?>
                    <p style="margin-top:1rem;font-size:0.8rem;color:var(--muted);">
                        ⏳ This announcement expires <?= date('d M Y', strtotime($ann['expires_at'])) ?>
                    </p>
                    <?php endif; ?>

                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php include '../includes/footer.php'; ?>
