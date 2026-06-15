<?php
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../config/app_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;
require_once __DIR__ . '/../PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/../PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer-master/src/SMTP.php';

requireAuth('tutor');

$pageTitle    = 'Provider Meetings';
$pageSubtitle = 'Schedule and manage meetings with placement providers';
$activePage   = 'provider-meetings';
$userId       = authId();

// Sidebar badges
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM placements WHERE status IN ('submitted','awaiting_tutor')");
$pendingRequests = (int)$stmt->fetchColumn();

// ── Ensure provider_meetings table exists ────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS provider_meetings (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            tutor_id        INT NOT NULL,
            company_id      INT NOT NULL,
            contact_name    VARCHAR(255),
            contact_email   VARCHAR(255),
            meeting_date    DATE NOT NULL,
            meeting_time    TIME NOT NULL,
            duration_hours  DECIMAL(4,1) NOT NULL DEFAULT 1,
            type            ENUM('physical','virtual') NOT NULL DEFAULT 'physical',
            location        VARCHAR(500),
            meeting_link    VARCHAR(500),
            agenda          TEXT,
            status          ENUM('scheduled','completed','cancelled') NOT NULL DEFAULT 'scheduled',
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (tutor_id)   REFERENCES users(id)     ON DELETE CASCADE,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}

$success = '';
$error   = '';

// ── Handle form submission ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['schedule_meeting'])) {
    try {
        $companyId    = (int)$_POST['company_id'];
        $contactName  = trim($_POST['contact_name']  ?? '');
        $contactEmail = trim($_POST['contact_email'] ?? '');
        $meetingDate  = $_POST['meeting_date'];
        $meetingTime  = $_POST['meeting_time'];
        $duration     = (float)($_POST['duration'] ?? 1);
        $type         = $_POST['type'];
        $location     = trim($_POST['location']     ?? '');
        $meetingLink  = trim($_POST['meeting_link'] ?? '');
        $agenda       = trim($_POST['agenda']       ?? '');

        if (!$companyId || !$meetingDate || !$meetingTime) {
            throw new Exception("Please fill in all required fields.");
        }

        // Insert meeting record
        $stmt = $pdo->prepare("
            INSERT INTO provider_meetings
                (tutor_id, company_id, contact_name, contact_email, meeting_date, meeting_time,
                 duration_hours, type, location, meeting_link, agenda, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled')
        ");
        $stmt->execute([
            $userId, $companyId, $contactName, $contactEmail,
            $meetingDate, $meetingTime, $duration, $type,
            $location, $meetingLink, $agenda
        ]);
        $meetingId = (int)$pdo->lastInsertId();

        // Fetch tutor + company info for email
        $stmt = $pdo->prepare("
            SELECT u.full_name AS tutor_name, u.email AS tutor_email,
                   c.name AS company_name, c.city
            FROM users u, companies c
            WHERE u.id = ? AND c.id = ?
        ");
        $stmt->execute([$userId, $companyId]);
        $info = $stmt->fetch(PDO::FETCH_ASSOC);

        // ── Send calendar invite email ───────────────────────────
        $emailSent = false;
        if ($contactEmail && $info) {
            $startDT = new DateTime($meetingDate . ' ' . $meetingTime);
            $endDT   = clone $startDT;
            $endDT->modify('+' . $duration . ' hours');

            $dtStart = $startDT->format('Ymd\THis');
            $dtEnd   = $endDT->format('Ymd\THis');
            $dtStamp = gmdate('Ymd\THis\Z');
            $uid     = 'provider-meeting-' . $meetingId . '@inplace-system.com';

            $summary      = 'Provider Meeting — ' . $info['company_name'];
            $locationStr  = ($type === 'virtual') ? 'Virtual Meeting' : ($location ?: $info['company_name']);
            $description  = "Provider Meeting\\n\\n";
            $description .= "Tutor: " . $info['tutor_name'] . "\\n";
            $description .= "Company: " . $info['company_name'] . "\\n";
            if ($contactName) $description .= "Contact: $contactName\\n";
            if ($type === 'virtual' && $meetingLink) $description .= "Join: $meetingLink\\n";
            if ($agenda) $description .= "\\nAgenda:\\n" . str_replace("\n", "\\n", $agenda);

            $ics  = "BEGIN:VCALENDAR\r\n";
            $ics .= "VERSION:2.0\r\n";
            $ics .= "PRODID:-//InPlace//Placement Management System//EN\r\n";
            $ics .= "CALSCALE:GREGORIAN\r\n";
            $ics .= "METHOD:REQUEST\r\n";
            $ics .= "BEGIN:VEVENT\r\n";
            $ics .= "UID:$uid\r\n";
            $ics .= "DTSTAMP:$dtStamp\r\n";
            $ics .= "DTSTART:$dtStart\r\n";
            $ics .= "DTEND:$dtEnd\r\n";
            $ics .= "SUMMARY:$summary\r\n";
            $ics .= "DESCRIPTION:$description\r\n";
            $ics .= "LOCATION:$locationStr\r\n";
            $ics .= "ORGANIZER;CN=\"" . $info['tutor_name'] . "\":mailto:" . $info['tutor_email'] . "\r\n";
            $ics .= "ATTENDEE;CN=\"" . ($contactName ?: $info['company_name']) . "\";ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:$contactEmail\r\n";
            $ics .= "STATUS:CONFIRMED\r\nSEQUENCE:0\r\nPRIORITY:5\r\n";
            $ics .= "BEGIN:VALARM\r\nTRIGGER:-P1D\r\nACTION:DISPLAY\r\nDESCRIPTION:Reminder: Provider meeting tomorrow\r\nEND:VALARM\r\n";
            $ics .= "END:VEVENT\r\nEND:VCALENDAR\r\n";

            // Build HTML email body
            $htmlBody = "
            <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>
              <div style='background:#0c1b33;color:white;padding:24px;border-radius:8px 8px 0 0;'>
                <h2 style='margin:0;font-size:1.3rem;'>📅 Provider Meeting Scheduled</h2>
              </div>
              <div style='background:#fff;padding:28px;border:1px solid #e2e6ec;border-top:none;border-radius:0 0 8px 8px;'>
                <p style='color:#374151;margin-bottom:1.25rem;'>
                  <strong>" . htmlspecialchars($info['tutor_name']) . "</strong> from the University of Leicester
                  has scheduled a meeting with " . htmlspecialchars($info['company_name']) . ".
                </p>
                <div style='background:#f8f5f0;padding:18px;border-radius:8px;margin-bottom:1.25rem;'>
                  <table style='width:100%;font-size:14px;border-collapse:collapse;'>
                    <tr><td style='padding:7px 0;color:#6b7a8d;font-weight:600;width:35%;'>Date</td>
                        <td style='padding:7px 0;color:#1a2332;'>" . $startDT->format('l, F j, Y') . "</td></tr>
                    <tr><td style='padding:7px 0;color:#6b7a8d;font-weight:600;'>Time</td>
                        <td style='padding:7px 0;color:#1a2332;'>" . $startDT->format('g:i A') . " – " . $endDT->format('g:i A') . "</td></tr>
                    <tr><td style='padding:7px 0;color:#6b7a8d;font-weight:600;'>Type</td>
                        <td style='padding:7px 0;color:#1a2332;'>" . ($type === 'virtual' ? 'Virtual (Online)' : 'In-Person') . "</td></tr>
                    <tr><td style='padding:7px 0;color:#6b7a8d;font-weight:600;'>Location</td>
                        <td style='padding:7px 0;color:#1a2332;'>" . htmlspecialchars($locationStr) . "</td></tr>
                    <tr><td style='padding:7px 0;color:#6b7a8d;font-weight:600;'>Organiser</td>
                        <td style='padding:7px 0;color:#1a2332;'>" . htmlspecialchars($info['tutor_name']) . " (" . htmlspecialchars($info['tutor_email']) . ")</td></tr>
                  </table>
                </div>";

            if ($type === 'virtual' && $meetingLink) {
                $htmlBody .= "
                <div style='background:#e0f2fe;padding:14px;border-radius:8px;margin-bottom:1.25rem;'>
                  <strong style='font-size:14px;'>Join Meeting:</strong><br>
                  <a href='" . htmlspecialchars($meetingLink) . "' style='color:#0369a1;word-break:break-all;font-size:14px;'>
                    " . htmlspecialchars($meetingLink) . "
                  </a>
                </div>";
            }

            if ($agenda) {
                $htmlBody .= "
                <div style='margin-bottom:1.25rem;'>
                  <strong style='color:#1a2332;font-size:14px;'>Agenda:</strong>
                  <p style='color:#6b7a8d;font-size:14px;line-height:1.6;margin-top:6px;'>"
                    . nl2br(htmlspecialchars($agenda)) . "
                  </p>
                </div>";
            }

            $htmlBody .= "
                <p style='font-size:12px;color:#9ca3af;margin-top:1.5rem;border-top:1px solid #e2e8f0;padding-top:1rem;'>
                  A calendar invite (.ics) is attached. You can Accept or Decline from your email client.
                  <br>This is an automated notification from InPlace Placement Management System.
                </p>
              </div>
            </div>";

            loadAppConfig($pdo);
            $mailCfg = require __DIR__ . '/../config/email_config.php';

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
                $mail->addAddress($contactEmail, $contactName ?: $info['company_name']);
                $mail->addAddress($info['tutor_email'], $info['tutor_name']); // CC tutor
                $mail->isHTML(true);
                $mail->Subject = '📅 Meeting Request: ' . $info['company_name'] . ' — ' . $startDT->format('d M Y');
                $mail->Body    = $htmlBody;
                $mail->AltBody = "Provider meeting scheduled.\n\nDate: " . $startDT->format('d M Y') . "\nTime: " . $startDT->format('g:i A') . "\nWith: " . $info['tutor_name'];
                $mail->addStringAttachment($ics, 'meeting.ics', 'base64', 'text/calendar; method=REQUEST; charset=UTF-8');
                $mail->send();
                $emailSent = true;
            } catch (MailException $e) {
                error_log('Provider meeting invite failed: ' . $mail->ErrorInfo);
            }
        }

        if ($emailSent) {
            $success = "Meeting scheduled! Calendar invite sent to " . htmlspecialchars($contactEmail) . " and your email.";
        } else {
            $success = "Meeting scheduled successfully!" . ($contactEmail ? " (Email invite could not be sent — check SMTP settings.)" : " No contact email — invite not sent.");
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ── Handle status update (complete / cancel) ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $mid    = (int)$_POST['meeting_id'];
    $status = in_array($_POST['new_status'], ['completed','cancelled']) ? $_POST['new_status'] : '';
    if ($mid && $status) {
        $pdo->prepare("UPDATE provider_meetings SET status=? WHERE id=? AND tutor_id=?")
            ->execute([$status, $mid, $userId]);
        $success = "Meeting marked as $status.";
    }
}

// ── Fetch companies for dropdown ─────────────────────────────────
$companies = $pdo->query("
    SELECT c.id, c.name, c.city, c.contact_name, c.contact_email, c.contact_phone
    FROM companies c
    ORDER BY c.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Fetch existing meetings ───────────────────────────────────────
$meetings = $pdo->prepare("
    SELECT pm.*, c.name AS company_name, c.city
    FROM provider_meetings pm
    JOIN companies c ON pm.company_id = c.id
    WHERE pm.tutor_id = ?
    ORDER BY pm.meeting_date DESC, pm.meeting_time DESC
");
$meetings->execute([$userId]);
$meetings = $meetings->fetchAll(PDO::FETCH_ASSOC);
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>

    <div class="page-content">

        <?php if ($success): ?>
        <div style="background:var(--success-bg);border:1px solid #6ee7b7;border-radius:var(--radius);
                    padding:1.25rem 2rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:1rem;">
            <span style="font-size:1.5rem;">✅</span>
            <p style="color:var(--success);font-weight:500;"><?= htmlspecialchars($success) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div style="background:var(--danger-bg);border:1px solid #fca5a5;border-radius:var(--radius);
                    padding:1.25rem 2rem;margin-bottom:1.5rem;">
            <p style="color:var(--danger);font-weight:500;">⚠️ <?= htmlspecialchars($error) ?></p>
        </div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:1fr 380px;gap:1.5rem;align-items:start;">

            <!-- ── Schedule Form ──────────────────────────────── -->
            <div class="panel" style="margin-bottom:0;">
                <div class="panel-header">
                    <h3>📅 Schedule Provider Meeting</h3>
                    <p>A calendar invite will be sent to the provider's contact email</p>
                </div>
                <div class="panel-body">
                    <form method="POST">
                        <input type="hidden" name="schedule_meeting" value="1">

                        <div class="form-grid">

                            <!-- Company -->
                            <div class="form-group full-col">
                                <label>Provider / Company <span style="color:var(--danger);">*</span></label>
                                <select name="company_id" id="companySelect" required
                                        onchange="fillContactInfo()"
                                        style="padding:0.875rem 1rem;border:2px solid var(--border);
                                               border-radius:var(--radius-sm);width:100%;
                                               font-family:inherit;font-size:0.9375rem;background:var(--cream);">
                                    <option value="">-- Select a company --</option>
                                    <?php foreach ($companies as $co): ?>
                                    <option value="<?= $co['id'] ?>"
                                            data-contact="<?= htmlspecialchars($co['contact_name'] ?? '') ?>"
                                            data-email="<?= htmlspecialchars($co['contact_email'] ?? '') ?>"
                                            data-city="<?= htmlspecialchars($co['city'] ?? '') ?>">
                                        <?= htmlspecialchars($co['name']) ?>
                                        <?= $co['city'] ? '— ' . htmlspecialchars($co['city']) : '' ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Contact info (auto-filled) -->
                            <div class="form-group">
                                <label>Contact Person</label>
                                <input type="text" name="contact_name" id="contactName"
                                       placeholder="e.g., Jane Smith"
                                       value="<?= htmlspecialchars($_POST['contact_name'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label>Contact Email <small style="color:var(--muted);">(invite sent here)</small></label>
                                <input type="email" name="contact_email" id="contactEmail"
                                       placeholder="contact@company.com"
                                       value="<?= htmlspecialchars($_POST['contact_email'] ?? '') ?>">
                            </div>

                            <!-- Date & Time -->
                            <div class="form-group">
                                <label>Meeting Date <span style="color:var(--danger);">*</span></label>
                                <input type="date" name="meeting_date" required
                                       min="<?= date('Y-m-d') ?>"
                                       value="<?= htmlspecialchars($_POST['meeting_date'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label>Meeting Time <span style="color:var(--danger);">*</span></label>
                                <input type="time" name="meeting_time" required
                                       value="<?= htmlspecialchars($_POST['meeting_time'] ?? '10:00') ?>">
                            </div>

                            <!-- Duration & Type -->
                            <div class="form-group">
                                <label>Duration</label>
                                <select name="duration">
                                    <option value="0.5">30 minutes</option>
                                    <option value="1" selected>1 hour</option>
                                    <option value="1.5">1.5 hours</option>
                                    <option value="2">2 hours</option>
                                    <option value="3">3 hours</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Meeting Type <span style="color:var(--danger);">*</span></label>
                                <select name="type" id="meetingType" onchange="toggleTypeFields()" required>
                                    <option value="physical">📍 In-Person</option>
                                    <option value="virtual">🖥 Virtual (Teams / Zoom)</option>
                                </select>
                            </div>

                            <!-- Location (physical) -->
                            <div class="form-group full-col" id="locationField">
                                <label>Location / Address</label>
                                <input type="text" name="location"
                                       placeholder="e.g., Company HQ, 10 Main Street, London"
                                       value="<?= htmlspecialchars($_POST['location'] ?? '') ?>">
                            </div>

                            <!-- Meeting link (virtual) -->
                            <div class="form-group full-col" id="linkField" style="display:none;">
                                <label>Meeting Link <span style="color:var(--danger);">*</span></label>
                                <input type="url" name="meeting_link" id="meetingLinkInput"
                                       placeholder="https://teams.microsoft.com/..."
                                       value="<?= htmlspecialchars($_POST['meeting_link'] ?? '') ?>">
                                <small style="color:var(--muted);font-size:0.8125rem;margin-top:0.25rem;display:block;">
                                    Included in the calendar invite
                                </small>
                            </div>

                            <!-- Agenda -->
                            <div class="form-group full-col">
                                <label>Agenda / Purpose</label>
                                <textarea name="agenda" rows="4"
                                          placeholder="What will be discussed? e.g., student progress review, placement extension, site visit coordination..."><?= htmlspecialchars($_POST['agenda'] ?? '') ?></textarea>
                            </div>

                        </div>

                        <div style="display:flex;justify-content:space-between;align-items:center;
                                    margin-top:1.5rem;padding-top:1.5rem;border-top:1px solid var(--border);">
                            <p style="font-size:0.875rem;color:var(--muted);">
                                📧 Calendar invite (.ics) sent to provider & your email
                            </p>
                            <button type="submit" class="btn btn-primary">
                                📅 Schedule & Send Invite
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ── Info panel ─────────────────────────────────── -->
            <div style="position:sticky;top:1.5rem;">
                <div class="panel">
                    <div class="panel-header"><h3>About Provider Meetings</h3></div>
                    <div class="panel-body">
                        <div style="display:flex;flex-direction:column;gap:1rem;font-size:0.875rem;">
                            <div style="padding:1rem;background:var(--cream);border-radius:var(--radius-sm);">
                                <p style="font-weight:600;color:var(--navy);margin-bottom:0.25rem;">📧 Calendar Invite</p>
                                <p style="color:var(--muted);">A .ics file is emailed to the provider's contact. They can Accept or Decline directly from their email client (Outlook, Gmail, etc.)</p>
                            </div>
                            <div style="padding:1rem;background:var(--cream);border-radius:var(--radius-sm);">
                                <p style="font-weight:600;color:var(--navy);margin-bottom:0.25rem;">🖥 Virtual Meetings</p>
                                <p style="color:var(--muted);">For virtual meetings, paste a Teams or Zoom link — it will appear in the calendar invite and email body.</p>
                            </div>
                            <div style="padding:1rem;background:var(--cream);border-radius:var(--radius-sm);">
                                <p style="font-weight:600;color:var(--navy);margin-bottom:0.25rem;">📋 No Contact Email?</p>
                                <p style="color:var(--muted);">Update the provider's contact email in
                                    <a href="/inplace/tutor/providers.php" style="color:var(--navy);font-weight:600;">Provider Directory</a>
                                    first, then schedule the meeting.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick stats -->
                <div class="panel" style="margin-top:1.25rem;">
                    <div class="panel-body">
                        <?php
                        $upcoming  = count(array_filter($meetings, fn($m) => $m['status'] === 'scheduled' && $m['meeting_date'] >= date('Y-m-d')));
                        $completed = count(array_filter($meetings, fn($m) => $m['status'] === 'completed'));
                        ?>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;text-align:center;">
                            <div>
                                <div style="font-size:1.75rem;font-weight:700;color:var(--navy);
                                            font-family:'Playfair Display',serif;"><?= $upcoming ?></div>
                                <div style="font-size:0.8rem;color:var(--muted);">Upcoming</div>
                            </div>
                            <div>
                                <div style="font-size:1.75rem;font-weight:700;color:var(--success);
                                            font-family:'Playfair Display',serif;"><?= $completed ?></div>
                                <div style="font-size:0.8rem;color:var(--muted);">Completed</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- ── Meetings List ──────────────────────────────────── -->
        <?php if (!empty($meetings)): ?>
        <div class="panel" style="margin-top:1.5rem;">
            <div class="panel-header">
                <h3>All Provider Meetings</h3>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Company</th>
                            <th>Contact</th>
                            <th>Date & Time</th>
                            <th>Type</th>
                            <th>Agenda</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($meetings as $m): ?>
                        <?php
                        $isPast = $m['meeting_date'] < date('Y-m-d');
                        $badgeClass = match($m['status']) {
                            'completed' => 'approved',
                            'cancelled' => 'rejected',
                            default     => $isPast ? 'open' : 'pending',
                        };
                        ?>
                        <tr>
                            <td>
                                <div style="font-weight:600;color:var(--navy);"><?= htmlspecialchars($m['company_name']) ?></div>
                                <?php if ($m['city']): ?>
                                <div style="font-size:0.75rem;color:var(--muted);"><?= htmlspecialchars($m['city']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($m['contact_name']): ?>
                                <div style="font-size:0.875rem;"><?= htmlspecialchars($m['contact_name']) ?></div>
                                <?php endif; ?>
                                <?php if ($m['contact_email']): ?>
                                <div style="font-size:0.75rem;color:var(--muted);"><?= htmlspecialchars($m['contact_email']) ?></div>
                                <?php endif; ?>
                                <?php if (!$m['contact_name'] && !$m['contact_email']): ?>
                                <span style="color:var(--muted);font-size:0.8rem;">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:0.875rem;">
                                <div style="font-weight:500;"><?= date('d M Y', strtotime($m['meeting_date'])) ?></div>
                                <div style="color:var(--muted);"><?= date('g:i A', strtotime($m['meeting_time'])) ?>
                                    · <?= $m['duration_hours'] ?>h
                                </div>
                            </td>
                            <td>
                                <?php if ($m['type'] === 'virtual'): ?>
                                    <span style="font-size:0.8rem;">🖥 Virtual</span>
                                    <?php if ($m['meeting_link']): ?>
                                    <br><a href="<?= htmlspecialchars($m['meeting_link']) ?>" target="_blank"
                                           style="font-size:0.75rem;color:var(--navy);">Join Link</a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="font-size:0.8rem;">📍 In-Person</span>
                                    <?php if ($m['location']): ?>
                                    <div style="font-size:0.75rem;color:var(--muted);"><?= htmlspecialchars($m['location']) ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td style="max-width:200px;">
                                <?php if ($m['agenda']): ?>
                                <div style="font-size:0.8125rem;color:var(--muted);
                                            overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <?= htmlspecialchars(substr($m['agenda'], 0, 80)) . (strlen($m['agenda']) > 80 ? '…' : '') ?>
                                </div>
                                <?php else: ?>
                                <span style="color:var(--muted);font-size:0.8rem;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-<?= $badgeClass ?>">
                                    <?= ucfirst($m['status']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($m['status'] === 'scheduled'): ?>
                                <div style="display:flex;gap:0.5rem;">
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="update_status" value="1">
                                        <input type="hidden" name="meeting_id" value="<?= $m['id'] ?>">
                                        <input type="hidden" name="new_status" value="completed">
                                        <button type="submit" class="btn btn-success btn-sm"
                                                onclick="return confirm('Mark this meeting as completed?')">
                                            ✓ Done
                                        </button>
                                    </form>
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="update_status" value="1">
                                        <input type="hidden" name="meeting_id" value="<?= $m['id'] ?>">
                                        <input type="hidden" name="new_status" value="cancelled">
                                        <button type="submit" class="btn btn-ghost btn-sm"
                                                onclick="return confirm('Cancel this meeting?')">
                                            Cancel
                                        </button>
                                    </form>
                                </div>
                                <?php else: ?>
                                <span style="font-size:0.8rem;color:var(--muted);">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<script>
// Auto-fill contact info when company is selected
const companiesData = <?= json_encode(array_column($companies, null, 'id')) ?>;

function fillContactInfo() {
    const sel = document.getElementById('companySelect');
    const opt = sel.options[sel.selectedIndex];
    document.getElementById('contactName').value  = opt.dataset.contact || '';
    document.getElementById('contactEmail').value = opt.dataset.email   || '';
    const loc = document.querySelector('input[name="location"]');
    if (loc && opt.dataset.city) loc.value = opt.dataset.city;
}

function toggleTypeFields() {
    const type      = document.getElementById('meetingType').value;
    const locField  = document.getElementById('locationField');
    const linkField = document.getElementById('linkField');
    const linkInput = document.getElementById('meetingLinkInput');

    if (type === 'virtual') {
        locField.style.display  = 'none';
        linkField.style.display = 'block';
        linkInput.required = true;
    } else {
        locField.style.display  = 'block';
        linkField.style.display = 'none';
        linkInput.required = false;
    }
}
</script>

<?php include '../includes/footer.php'; ?>
