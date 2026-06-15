<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('tutor');

$pageTitle    = 'Edit Visit';
$pageSubtitle = 'Update visit details';
$activePage   = 'visits';
$userId       = authId();

$visitId = (int)($_GET['id'] ?? 0);

// Sidebar badges
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM placements WHERE status IN ('submitted','awaiting_tutor')");
$pendingRequests = (int)$stmt->fetchColumn();

// ── Fetch visit data ─────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        v.*,
        p.id AS placement_id,
        u.full_name AS student_name,
        c.name AS company_name,
        c.city
    FROM visits v
    JOIN placements p ON v.placement_id = p.id
    JOIN users u ON p.student_id = u.id
    JOIN companies c ON p.company_id = c.id
    WHERE v.id = ? AND v.tutor_id = ?
");
$stmt->execute([$visitId, $userId]);
$visit = $stmt->fetch();

if (!$visit) {
    header("Location: /inplace/tutor/visits.php?error=not_found");
    exit;
}

// ── Handle form submission ───────────────────────────────────────
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $visitDate   = $_POST['visit_date'];
        $visitTime   = $_POST['visit_time'];
        $type        = $_POST['type'];
        $location    = trim($_POST['location'] ?? '');
        $meetingLink = trim($_POST['meeting_link'] ?? '');
        $notes       = trim($_POST['notes'] ?? '');

        $stmt = $pdo->prepare("
            UPDATE visits
            SET visit_date = ?, visit_time = ?, type = ?, location = ?, meeting_link = ?, notes = ?, updated_at = NOW()
            WHERE id = ? AND tutor_id = ?
        ");
        $stmt->execute([$visitDate, $visitTime, $type, $location, $meetingLink, $notes, $visitId, $userId]);

        // Notify student of change
        $stmt = $pdo->prepare("SELECT student_id FROM placements WHERE id = ?");
        $stmt->execute([$visit['placement_id']]);
        $row = $stmt->fetch();
        
        if ($row) {
            $msg = "📅 Your visit has been rescheduled to " . date('d M Y', strtotime($visitDate)) . " at " . date('g:i A', strtotime($visitTime)) . ".";
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, 'visit_updated', ?)");
            $stmt->execute([$row['student_id'], $msg]);
        }

        header("Location: /inplace/tutor/visits.php?success=updated");
        exit;

    } catch (Exception $e) {
        $error = "Failed to update visit: " . $e->getMessage();
    }
}
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>

    <div class="page-content">

        <?php if ($error): ?>
        <div style="background:var(--danger-bg);border:1px solid #fca5a5;border-radius:var(--radius);
                    padding:1.25rem 2rem;margin-bottom:1.5rem;">
            <p style="color:var(--danger);font-weight:500;">⚠️ <?= htmlspecialchars($error) ?></p>
        </div>
        <?php endif; ?>

        <!-- ═══════════════════════════════════════════════════════
             EDIT FORM
        ════════════════════════════════════════════════════════ -->
        <div class="panel">
            <div class="panel-header">
                <div>
                    <h3>Edit Visit</h3>
                    <p>
                        <?= htmlspecialchars($visit['student_name']) ?>
                        — <?= htmlspecialchars($visit['company_name']) ?>,
                        <?= htmlspecialchars($visit['city']) ?>
                    </p>
                </div>
                <span class="badge badge-<?= $visit['status']==='confirmed'?'approved':($visit['status']==='cancelled'?'rejected':'pending') ?>">
                    <?= ucfirst($visit['status']) ?>
                </span>
            </div>

            <div class="panel-body">
                <form method="POST">

                    <div class="form-grid" style="margin-bottom:2rem;">

                        <div class="form-group">
                            <label>Visit Date <span style="color:var(--danger);">*</span></label>
                            <input type="date" name="visit_date" required
                                   min="<?= date('Y-m-d') ?>"
                                   value="<?= htmlspecialchars($visit['visit_date']) ?>">
                        </div>

                        <div class="form-group">
                            <label>Visit Time <span style="color:var(--danger);">*</span></label>
                            <input type="time" name="visit_time" required
                                   value="<?= htmlspecialchars($visit['visit_time']) ?>">
                        </div>

                        <div class="form-group full-col">
                            <label>Visit Type <span style="color:var(--danger);">*</span></label>
                            <select name="type" required id="visitType" onchange="toggleVisitFields()">
                                <option value="physical" <?= $visit['type']==='physical'?'selected':'' ?>>
                                    📍 Physical (In-person at company)
                                </option>
                                <option value="virtual" <?= $visit['type']==='virtual'?'selected':'' ?>>
                                    🖥 Virtual (Online meeting)
                                </option>
                            </select>
                        </div>

                        <div class="form-group full-col" id="locationField">
                            <label>Location / Company Address</label>
                            <input type="text" name="location"
                                   placeholder="e.g., Dyson Ltd, Malmesbury"
                                   value="<?= htmlspecialchars($visit['location'] ?? '') ?>">
                        </div>

                        <div class="form-group full-col" id="meetingLinkField" style="display:none;">
                            <label>Meeting Link (Teams / Zoom)</label>
                            <input type="url" name="meeting_link"
                                   placeholder="https://teams.microsoft.com/..."
                                   value="<?= htmlspecialchars($visit['meeting_link'] ?? '') ?>">
                        </div>

                        <div class="form-group full-col">
                            <label>Notes / Agenda</label>
                            <textarea name="notes" rows="4"
                                      placeholder="What will be discussed during this visit?"><?= htmlspecialchars($visit['notes'] ?? '') ?></textarea>
                        </div>

                    </div>

                    <div class="divider"></div>

                    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:1.5rem;">
                        <div>
                            <p style="font-size:0.8125rem;color:var(--muted);">
                                Originally scheduled: <?= date('d M Y', strtotime($visit['created_at'])) ?>
                            </p>
                        </div>
                        <div style="display:flex;gap:1rem;">
                            <a href="/inplace/tutor/visits.php" class="btn btn-ghost">← Back</a>
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                        </div>
                    </div>

                </form>
            </div>
        </div>

    </div>
</div>

<script>
function toggleVisitFields() {
    const type = document.getElementById('visitType').value;
    const locationField = document.getElementById('locationField');
    const meetingLinkField = document.getElementById('meetingLinkField');
    
    if (type === 'virtual') {
        locationField.style.display = 'none';
        meetingLinkField.style.display = 'block';
    } else {
        locationField.style.display = 'block';
        meetingLinkField.style.display = 'none';
    }
}

// Run on page load to set correct visibility
toggleVisitFields();
</script>

<?php include '../includes/footer.php'; ?>