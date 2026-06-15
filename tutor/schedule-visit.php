<?php
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/calendar_invite_helper.php';  // ← NEW

requireAuth('tutor');

$pageTitle    = 'Schedule Visit';
$pageSubtitle = 'Schedule a new placement visit';
$activePage   = 'visits';
$userId       = authId();

// Sidebar badges
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM placements WHERE status IN ('submitted','awaiting_tutor')");
$pendingRequests = (int)$stmt->fetchColumn();

// ── Handle form submission ───────────────────────────────────────
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $placementId = (int)$_POST['placement_id'];
        $visitDate   = $_POST['visit_date'];
        $visitTime   = $_POST['visit_time'];
        $duration    = (float)($_POST['duration'] ?? 2);
        $type        = $_POST['type'];
        $location    = trim($_POST['location'] ?? '');
        $meetingLink = trim($_POST['meeting_link'] ?? '');
        $notes       = trim($_POST['notes'] ?? '');

        // Insert visit
        $stmt = $pdo->prepare("
            INSERT INTO visits
                (placement_id, tutor_id, visit_date, visit_time, duration_hours, type, location, meeting_link, notes, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled')
        ");
        $stmt->execute([$placementId, $userId, $visitDate, $visitTime, $duration, $type, $location, $meetingLink, $notes]);
        
        $visitId = $pdo->lastInsertId();

        // ═══════════════════════════════════════════════════════
        // SEND CALENDAR INVITE VIA EMAIL
        // ═══════════════════════════════════════════════════════
        
        // Fetch visit details with all necessary info
        $stmt = $pdo->prepare("
            SELECT 
                v.*,
                p.role_title,
                s.id AS student_id,
                s.full_name AS student_name,
                s.email AS student_email,
                t.id AS tutor_id,
                t.full_name AS tutor_name,
                t.email AS tutor_email,
                c.name AS company_name,
                c.address AS company_address,
                c.city AS company_city
            FROM visits v
            JOIN placements p ON v.placement_id = p.id
            JOIN users s ON p.student_id = s.id
            JOIN users t ON v.tutor_id = t.id
            LEFT JOIN companies c ON p.company_id = c.id
            WHERE v.id = ?
        ");
        $stmt->execute([$visitId]);
        $visitDetails = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($visitDetails) {
            // Prepare organizer (tutor)
            $organizer = [
                'name'  => $visitDetails['tutor_name'],
                'email' => $visitDetails['tutor_email']
            ];
            
            // Prepare attendees (student)
            $attendees = [
                [
                    'name'  => $visitDetails['student_name'],
                    'email' => $visitDetails['student_email']
                ]
            ];
            
            // Send calendar invite
            $inviteSent = sendCalendarInvite($visitDetails, $organizer, $attendees);
            
            if ($inviteSent) {
                $success = "✅ Visit scheduled successfully! Calendar invites have been sent to " . $visitDetails['student_name'] . " and added to your Outlook calendar.";
            } else {
                $success = "Visit scheduled, but calendar invite email failed. Please check email configuration.";
            }
        } else {
            $success = "Visit scheduled successfully!";
        }

    } catch (Exception $e) {
        $error = "Failed to schedule visit: " . $e->getMessage();
    }
}

// ── Fetch all active placements for dropdown ─────────────────────
// ── Fetch all active placements for dropdown ─────────────────────
$stmt = $pdo->query("
    SELECT
        p.id,
        u.full_name AS student_name,
        u.email AS student_email,
        c.name      AS company_name,
        c.city,
        p.role_title
    FROM placements p
    JOIN users u     ON p.student_id = u.id
    JOIN companies c ON p.company_id = c.id
    WHERE p.status IN ('approved','active')
    ORDER BY u.full_name ASC
");
$placements = $stmt->fetchAll();
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>

    <div class="page-content">

        <?php if ($success): ?>
        <div style="background:var(--success-bg);border:1px solid #6ee7b7;border-radius:var(--radius);
                    padding:1.5rem 2rem;margin-bottom:1.5rem;">
            <div style="display:flex;align-items:start;gap:1rem;">
                <div style="font-size:1.5rem;">📅</div>
                <div style="flex:1;">
                    <p style="color:var(--success);font-weight:600;margin-bottom:0.5rem;"><?= htmlspecialchars($success) ?></p>
                    <p style="color:var(--success);font-size:0.875rem;opacity:0.9;">
                        The meeting request will appear in both your Outlook calendar and the student's calendar.
                        They can Accept or Decline from their email.
                    </p>
                    <a href="/inplace/tutor/visits.php" class="btn btn-success btn-sm" style="margin-top:1rem;">
                        View All Visits →
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div style="background:var(--danger-bg);border:1px solid #fca5a5;border-radius:var(--radius);
                    padding:1.25rem 2rem;margin-bottom:1.5rem;">
            <p style="color:var(--danger);font-weight:500;">⚠️ <?= htmlspecialchars($error) ?></p>
        </div>
        <?php endif; ?>

        <!-- ═══════════════════════════════════════════════════════
             SCHEDULE FORM
        ════════════════════════════════════════════════════════ -->
        <div class="panel">
            <div class="panel-header">
                <h3>📅 Schedule New Visit</h3>
                <p>Schedule a placement visit - calendar invites will be sent automatically</p>
            </div>

            <div class="panel-body">
                <form method="POST">

                    <div class="form-grid" style="margin-bottom:2rem;">

                        <div class="form-group full-col">
                            <label>Select Student Placement <span style="color:var(--danger);">*</span></label>
                            <select name="placement_id" required id="placementSelect" onchange="updatePlacementInfo()"
                                    style="padding:0.875rem 1rem;border:2px solid var(--border);
                                           border-radius:var(--radius-sm);width:100%;font-family:inherit;
                                           font-size:0.9375rem;background:var(--cream);">
                                <option value="">-- Choose a student --</option>
                                <?php foreach ($placements as $p): ?>
                                <option value="<?= $p['id'] ?>" 
                                        data-student="<?= htmlspecialchars($p['student_name']) ?>"
                                        data-email="<?= htmlspecialchars($p['student_email']) ?>"
                                        data-company="<?= htmlspecialchars($p['company_name']) ?>"
                                        data-role="<?= htmlspecialchars($p['role_title']) ?>">
                                    <?= htmlspecialchars($p['student_name']) ?>
                                    — <?= htmlspecialchars($p['role_title']) ?> at
                                    <?= htmlspecialchars($p['company_name']) ?>,
                                    <?= htmlspecialchars($p['city']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Student Info Preview -->
                        <div class="form-group full-col" id="studentInfo" style="display:none;">
                            <div style="background:var(--cream);padding:1rem;border-radius:8px;border:1px solid var(--border);">
                                <div style="font-size:0.75rem;color:var(--muted);margin-bottom:0.5rem;text-transform:uppercase;font-weight:600;">
                                    Calendar Invite Will Be Sent To:
                                </div>
                                <div style="display:flex;align-items:center;gap:0.75rem;">
                                    <div style="width:36px;height:36px;border-radius:8px;background:var(--navy);
                                                color:white;display:flex;align-items:center;justify-content:center;
                                                font-weight:700;font-size:0.875rem;" id="studentInitials">
                                    </div>
                                    <div>
                                        <div style="font-weight:600;color:var(--navy);" id="studentName"></div>
                                        <div style="font-size:0.875rem;color:var(--muted);" id="studentEmail"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Visit Date <span style="color:var(--danger);">*</span></label>
                            <input type="date" name="visit_date" required
                                   min="<?= date('Y-m-d') ?>"
                                   value="<?= htmlspecialchars($_POST['visit_date'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label>Visit Time <span style="color:var(--danger);">*</span></label>
                            <input type="time" name="visit_time" required
                                   value="<?= htmlspecialchars($_POST['visit_time'] ?? '14:00') ?>">
                        </div>

                        <div class="form-group">
                            <label>Duration (hours) <span style="color:var(--danger);">*</span></label>
                            <select name="duration" required>
                                <option value="0.5">30 minutes</option>
                                <option value="1">1 hour</option>
                                <option value="1.5">1.5 hours</option>
                                <option value="2" selected>2 hours</option>
                                <option value="2.5">2.5 hours</option>
                                <option value="3">3 hours</option>
                                <option value="4">4 hours</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Visit Type <span style="color:var(--danger);">*</span></label>
                            <select name="type" required id="visitType" onchange="toggleVisitFields()">
                                <option value="physical">📍 Physical (In-person at company)</option>
                                <option value="virtual">🖥 Virtual (Online meeting)</option>
                            </select>
                        </div>

                        <div class="form-group full-col" id="locationField">
                            <label>Location / Company Address</label>
                            <input type="text" name="location"
                                   placeholder="e.g., Rolls-Royce plc, Moor Lane, Derby"
                                   value="<?= htmlspecialchars($_POST['location'] ?? '') ?>">
                        </div>

                        <div class="form-group full-col" id="meetingLinkField" style="display:none;">
                            <label>Meeting Link (Teams / Zoom) <span style="color:var(--danger);">*</span></label>
                            <input type="url" name="meeting_link"
                                   placeholder="https://teams.microsoft.com/..."
                                   value="<?= htmlspecialchars($_POST['meeting_link'] ?? '') ?>">
                            <small style="color:var(--muted);font-size:0.8125rem;margin-top:0.25rem;display:block;">
                                This link will be included in the calendar invite
                            </small>
                        </div>

                        <div class="form-group full-col">
                            <label>Notes / Agenda</label>
                            <textarea name="notes" rows="4"
                                      placeholder="What will be discussed during this visit?"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                            <small style="color:var(--muted);font-size:0.8125rem;margin-top:0.25rem;display:block;">
                                This will appear in the calendar invite description
                            </small>
                        </div>

                    </div>

                    <div class="divider"></div>

                    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:1.5rem;">
                        <div style="color:var(--muted);font-size:0.875rem;">
                            📧 Calendar invite will be sent to student's email
                        </div>
                        <div style="display:flex;gap:1rem;">
                            <a href="/inplace/tutor/visits.php" class="btn btn-ghost">← Back</a>
                            <button type="submit" class="btn btn-primary">📅 Schedule & Send Invite</button>
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
    const meetingLinkInput = meetingLinkField.querySelector('input');
    
    if (type === 'virtual') {
        locationField.style.display = 'none';
        meetingLinkField.style.display = 'block';
        meetingLinkInput.required = true;
    } else {
        locationField.style.display = 'block';
        meetingLinkField.style.display = 'none';
        meetingLinkInput.required = false;
    }
}

function updatePlacementInfo() {
    const select = document.getElementById('placementSelect');
    const option = select.options[select.selectedIndex];
    const infoDiv = document.getElementById('studentInfo');
    
    if (option.value) {
        const studentName = option.dataset.student;
        const studentEmail = option.dataset.email;
        const initials = studentName.split(' ').map(n => n[0]).join('').toUpperCase();
        
        document.getElementById('studentName').textContent = studentName;
        document.getElementById('studentEmail').textContent = studentEmail;
        document.getElementById('studentInitials').textContent = initials;
        
        infoDiv.style.display = 'block';
    } else {
        infoDiv.style.display = 'none';
    }
}
</script>

<?php include '../includes/footer.php'; ?>