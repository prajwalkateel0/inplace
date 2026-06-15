<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('tutor');

$pageTitle    = 'Announcements';
$pageSubtitle = 'Broadcast messages to your students';
$activePage   = 'announcements';
$userId       = authId();

// Sidebar badges
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount = (int)$stmt->fetchColumn();
$stmt = $pdo->query("SELECT COUNT(*) FROM placements WHERE status IN ('submitted','awaiting_tutor','awaiting_provider')");
$pendingRequests = (int)$stmt->fetchColumn();

// ── Auto-create tables ───────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS announcements (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        author_id    INT NOT NULL,
        title        VARCHAR(255) NOT NULL,
        body         TEXT NOT NULL,
        audience     ENUM('all','year','programme') DEFAULT 'all',
        target_value VARCHAR(100) DEFAULT NULL,
        is_pinned    TINYINT(1) DEFAULT 0,
        expires_at   DATE DEFAULT NULL,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_author  (author_id),
        INDEX idx_pinned  (is_pinned),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS announcement_reads (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        announcement_id INT NOT NULL,
        student_id      INT NOT NULL,
        read_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_read (announcement_id, student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$flash = ['msg' => '', 'type' => ''];

// ── POST handlers ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'edit') {
        $title      = trim($_POST['title']       ?? '');
        $body       = trim($_POST['body']        ?? '');
        $audience   = in_array($_POST['audience'] ?? '', ['all','year','programme']) ? $_POST['audience'] : 'all';
        $targetVal  = trim($_POST['target_value'] ?? '') ?: null;
        $isPinned   = isset($_POST['is_pinned']) ? 1 : 0;
        $expiresAt  = trim($_POST['expires_at'] ?? '') ?: null;

        if (!$title || !$body) {
            $flash = ['msg' => 'Title and body are required.', 'type' => 'danger'];
        } elseif ($action === 'edit') {
            $aid = (int)($_POST['announcement_id'] ?? 0);
            $pdo->prepare("
                UPDATE announcements
                SET title=?, body=?, audience=?, target_value=?, is_pinned=?, expires_at=?, updated_at=NOW()
                WHERE id=? AND author_id=?
            ")->execute([$title, $body, $audience, $targetVal, $isPinned, $expiresAt, $aid, $userId]);
            $flash = ['msg' => 'Announcement updated.', 'type' => 'success'];
        } else {
            $pdo->prepare("
                INSERT INTO announcements (author_id, title, body, audience, target_value, is_pinned, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([$userId, $title, $body, $audience, $targetVal, $isPinned, $expiresAt]);
            $flash = ['msg' => 'Announcement posted successfully.', 'type' => 'success'];
        }

    } elseif ($action === 'delete') {
        $aid = (int)($_POST['announcement_id'] ?? 0);
        $pdo->prepare("DELETE FROM announcements WHERE id=? AND author_id=?")->execute([$aid, $userId]);
        $pdo->prepare("DELETE FROM announcement_reads WHERE announcement_id=?")->execute([$aid]);
        $flash = ['msg' => 'Announcement deleted.', 'type' => 'success'];

    } elseif ($action === 'toggle_pin') {
        $aid = (int)($_POST['announcement_id'] ?? 0);
        $pdo->prepare("UPDATE announcements SET is_pinned = NOT is_pinned WHERE id=? AND author_id=?")->execute([$aid, $userId]);
        $flash = ['msg' => 'Pin status updated.', 'type' => 'success'];
    }
}

// ── Fetch all active student count ───────────────────────────────
$totalStudents = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='student' AND is_active=1")->fetchColumn();

// ── Fetch all announcements by this tutor ────────────────────────
$announcements = $pdo->prepare("
    SELECT a.*,
           (SELECT COUNT(*) FROM announcement_reads ar WHERE ar.announcement_id = a.id) AS read_count
    FROM announcements a
    WHERE a.author_id = ?
    ORDER BY a.is_pinned DESC, a.created_at DESC
");
$announcements->execute([$userId]);
$announcements = $announcements->fetchAll(PDO::FETCH_ASSOC);

// Academic years and programmes for targeting
$years = ['2024/25','2025/26','2026/27','2027/28'];
$programmes = [
    'BSc Computer Science','BSc Software Engineering','BSc Data Science',
    'BEng Engineering','MEng Engineering','MSc Computer Science','Other'
];
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>
    <div class="page-content">

        <?php if ($flash['msg']): ?>
        <div style="background:var(--<?= $flash['type'] ?>-bg);border:1px solid <?= $flash['type']==='success'?'#6ee7b7':'#fca5a5' ?>;
                    border-radius:var(--radius);padding:1.25rem 2rem;margin-bottom:1.5rem;">
            <p style="color:var(--<?= $flash['type'] ?>);font-weight:500;"><?= htmlspecialchars($flash['msg']) ?></p>
        </div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:1fr 360px;gap:1.5rem;align-items:start;">

            <!-- ── Left: Announcements list ──────────────────── -->
            <div>
                <div class="panel">
                    <div class="panel-header">
                        <div>
                            <h3>Posted Announcements</h3>
                            <p><?= count($announcements) ?> total · <?= $totalStudents ?> active students</p>
                        </div>
                        <button class="btn btn-primary btn-sm"
                                onclick="document.getElementById('composePanel').scrollIntoView({behavior:'smooth'})">
                            + New Announcement
                        </button>
                    </div>

                    <?php if (empty($announcements)): ?>
                    <div style="text-align:center;padding:3rem 2rem;">
                        <div style="font-size:3rem;margin-bottom:0.75rem;">📢</div>
                        <p style="color:var(--muted);">No announcements posted yet.</p>
                        <p style="color:var(--muted);font-size:0.875rem;margin-top:0.25rem;">Use the form on the right to broadcast a message to your students.</p>
                    </div>
                    <?php else: ?>
                    <div style="padding:0;">
                        <?php foreach ($announcements as $ann):
                            $isExpired  = $ann['expires_at'] && $ann['expires_at'] < date('Y-m-d');
                            $audienceLabel = match($ann['audience']) {
                                'year'       => 'Year: ' . ($ann['target_value'] ?? '?'),
                                'programme'  => 'Programme: ' . ($ann['target_value'] ?? '?'),
                                default      => 'All Students',
                            };
                        ?>
                        <div style="padding:1.5rem 2rem;border-bottom:1px solid var(--border);
                                    <?= $ann['is_pinned'] ? 'background:linear-gradient(to right,rgba(232,160,32,0.06),transparent);border-left:3px solid #e8a020;' : '' ?>
                                    <?= $isExpired ? 'opacity:0.55;' : '' ?>">
                            <div style="display:flex;align-items:flex-start;gap:0.75rem;">
                                <div style="flex:1;min-width:0;">
                                    <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.25rem;">
                                        <?php if ($ann['is_pinned']): ?>
                                        <span style="font-size:0.7rem;font-weight:700;background:#fef3c7;color:#92400e;
                                                     padding:0.15rem 0.5rem;border-radius:4px;">📌 PINNED</span>
                                        <?php endif; ?>
                                        <?php if ($isExpired): ?>
                                        <span style="font-size:0.7rem;font-weight:700;background:var(--danger-bg);color:var(--danger);
                                                     padding:0.15rem 0.5rem;border-radius:4px;">EXPIRED</span>
                                        <?php endif; ?>
                                        <span style="font-size:0.75rem;background:var(--cream);color:var(--muted);
                                                     padding:0.15rem 0.5rem;border-radius:4px;border:1px solid var(--border);">
                                            <?= htmlspecialchars($audienceLabel) ?>
                                        </span>
                                    </div>
                                    <h4 style="font-size:1rem;font-weight:600;color:var(--navy);margin-bottom:0.35rem;">
                                        <?= htmlspecialchars($ann['title']) ?>
                                    </h4>
                                    <p style="font-size:0.875rem;color:var(--text);line-height:1.6;
                                              max-height:3.6em;overflow:hidden;text-overflow:ellipsis;
                                              display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;">
                                        <?= nl2br(htmlspecialchars($ann['body'])) ?>
                                    </p>
                                    <div style="display:flex;align-items:center;gap:1rem;margin-top:0.75rem;
                                                font-size:0.8rem;color:var(--muted);">
                                        <span>📅 <?= date('d M Y, g:i A', strtotime($ann['created_at'])) ?></span>
                                        <span>👁 <?= $ann['read_count'] ?> read</span>
                                        <?php if ($ann['expires_at']): ?>
                                        <span>⏳ Expires <?= date('d M Y', strtotime($ann['expires_at'])) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <!-- Actions -->
                                <div style="display:flex;flex-direction:column;gap:0.4rem;flex-shrink:0;">
                                    <button class="btn btn-ghost btn-sm"
                                            onclick="openEdit(<?= $ann['id'] ?>,
                                                <?= htmlspecialchars(json_encode($ann['title'])) ?>,
                                                <?= htmlspecialchars(json_encode($ann['body'])) ?>,
                                                '<?= $ann['audience'] ?>',
                                                '<?= htmlspecialchars(addslashes($ann['target_value'] ?? '')) ?>',
                                                <?= $ann['is_pinned'] ?>,
                                                '<?= $ann['expires_at'] ?? '' ?>')">
                                        ✏️ Edit
                                    </button>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="toggle_pin">
                                        <input type="hidden" name="announcement_id" value="<?= $ann['id'] ?>">
                                        <button type="submit" class="btn btn-ghost btn-sm" style="width:100%;">
                                            <?= $ann['is_pinned'] ? '📌 Unpin' : '📌 Pin' ?>
                                        </button>
                                    </form>
                                    <form method="POST" style="display:inline;"
                                          onsubmit="return confirm('Delete this announcement?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="announcement_id" value="<?= $ann['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" style="width:100%;">🗑 Delete</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── Right: Compose form ───────────────────────── -->
            <div id="composePanel" style="position:sticky;top:1.5rem;">
                <div class="panel">
                    <div class="panel-header">
                        <div>
                            <h3 id="formTitle">New Announcement</h3>
                            <p id="formSubtitle">Broadcast to your students</p>
                        </div>
                    </div>
                    <div class="panel-body">
                        <form method="POST" id="announceForm">
                            <input type="hidden" name="action" id="formAction" value="create">
                            <input type="hidden" name="announcement_id" id="formAid" value="">

                            <div class="form-group" style="margin-bottom:1.25rem;">
                                <label>Title <span style="color:var(--danger);">*</span></label>
                                <input type="text" name="title" id="formTitleInput" required
                                       placeholder="e.g., Important: Report Submission Reminder">
                            </div>

                            <div class="form-group" style="margin-bottom:1.25rem;">
                                <label>Message <span style="color:var(--danger);">*</span></label>
                                <textarea name="body" id="formBody" rows="7" required
                                          placeholder="Write your announcement here…"
                                          style="resize:vertical;"></textarea>
                            </div>

                            <div class="form-group" style="margin-bottom:1.25rem;">
                                <label>Audience</label>
                                <select name="audience" id="formAudience"
                                        onchange="toggleTargetField(this.value)">
                                    <option value="all">All Students</option>
                                    <option value="year">Specific Academic Year</option>
                                    <option value="programme">Specific Programme</option>
                                </select>
                            </div>

                            <div id="targetField" style="display:none;margin-bottom:1.25rem;">
                                <div class="form-group" id="yearField" style="display:none;">
                                    <label>Academic Year</label>
                                    <select name="target_value" id="formTargetYear">
                                        <?php foreach ($years as $y): ?>
                                        <option value="<?= $y ?>"><?= $y ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group" id="progField" style="display:none;">
                                    <label>Programme</label>
                                    <select name="target_value" id="formTargetProg">
                                        <?php foreach ($programmes as $p): ?>
                                        <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div style="display:flex;gap:1.5rem;margin-bottom:1.25rem;flex-wrap:wrap;">
                                <label style="display:flex;align-items:center;gap:0.4rem;cursor:pointer;font-size:0.9rem;">
                                    <input type="checkbox" name="is_pinned" id="formPinned"
                                           style="width:16px;height:16px;">
                                    📌 Pin this announcement
                                </label>
                            </div>

                            <div class="form-group" style="margin-bottom:1.5rem;">
                                <label>Expires On (optional)</label>
                                <input type="date" name="expires_at" id="formExpires"
                                       min="<?= date('Y-m-d') ?>">
                                <small style="color:var(--muted);">Hidden from students after this date.</small>
                            </div>

                            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                                <button type="button" id="cancelEdit" class="btn btn-ghost"
                                        style="display:none;" onclick="resetForm()">Cancel</button>
                                <button type="submit" id="formSubmit" class="btn btn-primary">
                                    Post Announcement →
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
function toggleTargetField(val) {
    document.getElementById('targetField').style.display = val !== 'all' ? 'block' : 'none';
    document.getElementById('yearField').style.display  = val === 'year'       ? 'block' : 'none';
    document.getElementById('progField').style.display  = val === 'programme'  ? 'block' : 'none';
}

function openEdit(id, title, body, audience, targetVal, pinned, expires) {
    document.getElementById('formAction').value     = 'edit';
    document.getElementById('formAid').value        = id;
    document.getElementById('formTitleInput').value = title;
    document.getElementById('formBody').value       = body;
    document.getElementById('formAudience').value   = audience;
    document.getElementById('formPinned').checked   = !!pinned;
    document.getElementById('formExpires').value    = expires;
    toggleTargetField(audience);
    if (audience === 'year')      document.getElementById('formTargetYear').value = targetVal;
    if (audience === 'programme') document.getElementById('formTargetProg').value = targetVal;
    document.getElementById('formTitle').textContent    = 'Edit Announcement';
    document.getElementById('formSubtitle').textContent = 'Update and save changes';
    document.getElementById('formSubmit').textContent   = 'Save Changes →';
    document.getElementById('cancelEdit').style.display = 'inline-flex';
    document.getElementById('composePanel').scrollIntoView({behavior:'smooth'});
}

function resetForm() {
    document.getElementById('announceForm').reset();
    document.getElementById('formAction').value          = 'create';
    document.getElementById('formAid').value             = '';
    document.getElementById('formTitle').textContent     = 'New Announcement';
    document.getElementById('formSubtitle').textContent  = 'Broadcast to your students';
    document.getElementById('formSubmit').textContent    = 'Post Announcement →';
    document.getElementById('cancelEdit').style.display  = 'none';
    document.getElementById('targetField').style.display = 'none';
}
</script>

<?php include '../includes/footer.php'; ?>
