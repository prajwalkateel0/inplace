<?php
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../config/app_config.php';
require_once '../includes/provider_token_helper.php';
loadAppConfig($pdo);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;
require_once __DIR__ . '/../PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/../PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer-master/src/SMTP.php';

requireAuth('tutor');

$pageTitle    = 'Add Placement';
$pageSubtitle = 'Create a placement record on behalf of a student';
$activePage   = 'create-placement';
$userId       = authId();

// Sidebar badges
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount = (int)$stmt->fetchColumn();
$stmt = $pdo->query("SELECT COUNT(*) FROM placements WHERE status IN ('submitted','awaiting_tutor','awaiting_provider')");
$pendingRequests = (int)$stmt->fetchColumn();

$success = '';
$error   = '';

// ── POST handler ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentId      = (int)($_POST['student_id'] ?? 0);
    $companyName    = trim($_POST['company_name']    ?? '');
    $companyAddress = trim($_POST['company_address'] ?? '');
    $companySector  = trim($_POST['sector']          ?? '');
    $supName        = trim($_POST['supervisor_name']  ?? '');
    $supEmail       = trim($_POST['supervisor_email'] ?? '');
    $supPhone       = trim($_POST['supervisor_phone'] ?? '');
    $roleTitle      = trim($_POST['role_title']       ?? '');
    $jobDesc        = trim($_POST['job_description']  ?? '');
    $startDate      = $_POST['start_date'] ?? '';
    $endDate        = $_POST['end_date']   ?? '';
    $salary         = trim($_POST['salary']           ?? '');
    $workPattern    = trim($_POST['working_pattern']  ?? '');
    $initialStatus  = in_array($_POST['initial_status']??'', ['awaiting_provider','awaiting_tutor','approved'])
                        ? $_POST['initial_status'] : 'awaiting_provider';
    $lat = is_numeric($_POST['company_lat'] ?? '') ? (float)$_POST['company_lat'] : null;
    $lng = is_numeric($_POST['company_lng'] ?? '') ? (float)$_POST['company_lng'] : null;

    if (!$studentId || !$companyName || !$roleTitle || !$startDate || !$endDate) {
        $error = 'Student, company name, role title, start date and end date are all required.';
    } elseif ($endDate <= $startDate) {
        $error = 'End date must be after start date.';
    } else {
        // Verify student exists and is approved
        $stmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE id=? AND role='student' AND approval_status='approved'");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch();
        if (!$student) {
            $error = 'Selected student not found or not approved.';
        }
    }

    if (!$error) {
        try {
            $pdo->beginTransaction();

            // Find or create company
            $stmt = $pdo->prepare("SELECT id FROM companies WHERE name = ? LIMIT 1");
            $stmt->execute([$companyName]);
            $existingCo = $stmt->fetch();

            if ($existingCo) {
                $companyId = (int)$existingCo['id'];
                $pdo->prepare("UPDATE companies SET address=COALESCE(NULLIF(?,\"\"),address), sector=COALESCE(NULLIF(?,\"\"),sector), contact_name=COALESCE(NULLIF(?,\"\"),contact_name), contact_email=COALESCE(NULLIF(?,\"\"),contact_email), contact_phone=COALESCE(NULLIF(?,\"\"),contact_phone), latitude=COALESCE(?,latitude), longitude=COALESCE(?,longitude) WHERE id=?")
                    ->execute([$companyAddress,$companySector,$supName,$supEmail,$supPhone,$lat,$lng,$companyId]);
            } else {
                $pdo->prepare("INSERT INTO companies (name,address,sector,contact_name,contact_email,contact_phone,latitude,longitude) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$companyName,$companyAddress,$companySector,$supName,$supEmail,$supPhone,$lat,$lng]);
                $companyId = (int)$pdo->lastInsertId();
            }

            // Create placement
            $pdo->prepare("
                INSERT INTO placements
                    (student_id,company_id,role_title,job_description,start_date,end_date,
                     salary,working_pattern,supervisor_name,supervisor_email,supervisor_phone,
                     status,tutor_id,created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
            ")->execute([
                $student['id'],$companyId,$roleTitle,$jobDesc,$startDate,$endDate,
                $salary,$workPattern,$supName,$supEmail,$supPhone,
                $initialStatus,$userId
            ]);
            $placementId = (int)$pdo->lastInsertId();

            // Audit log
            try {
                $pdo->prepare("INSERT INTO audit_log (user_id,action,table_affected,record_id,details) VALUES (?,'tutor_created_placement','placements',?,?)")
                    ->execute([$userId, $placementId, 'Created on behalf of student #'.$student['id']]);
            } catch (Exception $e) {}

            $pdo->commit();

            // Notify student via message
            try {
                $tCol = null;
                $s2 = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='messages' AND COLUMN_NAME IN (?,?,?)");
                $s2->execute(['created_at','sent_at','timestamp']);
                foreach (['created_at','sent_at','timestamp'] as $c) {
                    if (in_array($c,$s2->fetchAll(PDO::FETCH_COLUMN),true)){$tCol=$c;break;}
                }
                $notifyMsg = "A placement record has been created for you at $companyName ($roleTitle) by your tutor. Status: " . ucwords(str_replace('_',' ',$initialStatus)) . ". Please log in to review the details.";
                if ($tCol) {
                    $pdo->prepare("INSERT INTO messages (sender_id,receiver_id,body,`$tCol`,is_read) VALUES (?,?,?,NOW(),0)")
                        ->execute([$userId,$student['id'],$notifyMsg]);
                } else {
                    $pdo->prepare("INSERT INTO messages (sender_id,receiver_id,body,is_read) VALUES (?,?,?,0)")
                        ->execute([$userId,$student['id'],$notifyMsg]);
                }
            } catch (Exception $e) {}

            // Email provider if status = awaiting_provider
            if ($initialStatus === 'awaiting_provider' && $supEmail) {
                $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $provUrl  = $scheme . '://' . $host . '/inplace/provider/requests.php';

                // Generate single-use token for approve/reject without login
                $confirmUrl = generateProviderToken($pdo, $placementId, $supEmail);

                $mailCfg = require __DIR__ . '/../config/email_config.php';
                $htmlBody = "
                <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;'>
                  <div style='background:#0c1b33;padding:2rem;text-align:center;'>
                    <h1 style='color:#fff;font-size:1.5rem;margin:0;'>InPlace</h1>
                    <p style='color:rgba(255,255,255,0.8);margin:0.5rem 0 0;font-size:0.9rem;'>Placement Authorisation Required</p>
                  </div>
                  <div style='padding:2rem;'>
                    <p style='color:#374151;'>Dear " . htmlspecialchars($supName ?: 'Placement Provider') . ",</p>
                    <p style='color:#374151;margin:1rem 0 1.5rem;'>A placement record has been created for <strong>" . htmlspecialchars($student['full_name']) . "</strong> at <strong>" . htmlspecialchars($companyName) . "</strong> and requires your confirmation.</p>
                    <table style='width:100%;border-collapse:collapse;margin-bottom:1.5rem;'>
                      <tr style='background:#f8f5f0;'><td style='padding:0.75rem;font-weight:600;color:#0c1b33;width:40%;border-bottom:1px solid #e2e8f0;'>Student</td><td style='padding:0.75rem;color:#374151;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars($student['full_name']) . "</td></tr>
                      <tr><td style='padding:0.75rem;font-weight:600;color:#0c1b33;border-bottom:1px solid #e2e8f0;'>Role</td><td style='padding:0.75rem;color:#374151;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars($roleTitle) . "</td></tr>
                      <tr style='background:#f8f5f0;'><td style='padding:0.75rem;font-weight:600;color:#0c1b33;border-bottom:1px solid #e2e8f0;'>Start Date</td><td style='padding:0.75rem;color:#374151;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars($startDate) . "</td></tr>
                      <tr><td style='padding:0.75rem;font-weight:600;color:#0c1b33;'>End Date</td><td style='padding:0.75rem;color:#374151;'>" . htmlspecialchars($endDate) . "</td></tr>
                    </table>
                    <div style='text-align:center;margin:2rem 0;'>
                      <a href='$confirmUrl' style='display:inline-block;padding:0.875rem 2rem;background:#059669;color:#fff !important;text-decoration:none;border-radius:10px;font-weight:700;font-size:1rem;margin-bottom:0.75rem;'>
                        ✓ Approve or Decline (no login needed)
                      </a><br>
                      <a href='$provUrl' style='display:inline-block;padding:0.625rem 1.5rem;background:#0c1b33;color:#fff !important;text-decoration:none;border-radius:10px;font-weight:600;font-size:0.9rem;margin-top:0.5rem;'>
                        Log in to InPlace
                      </a>
                    </div>
                    <p style='color:#6b7a8d;font-size:0.8rem;text-align:center;margin-top:0.5rem;'>
                      The quick-confirm link expires in 7 days and can only be used once.
                    </p>
                    <p style='color:#6b7a8d;font-size:0.85rem;text-align:center;'>Automated notification from InPlace.</p>
                  </div>
                </div>";

                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP(); $mail->Host=$mailCfg['smtp_host']; $mail->SMTPAuth=true;
                    $mail->Username=$mailCfg['smtp_user']; $mail->Password=$mailCfg['smtp_pass'];
                    $mail->SMTPSecure=PHPMailer::ENCRYPTION_STARTTLS; $mail->Port=$mailCfg['smtp_port'];
                    $mail->CharSet='UTF-8';
                    $mail->setFrom($mailCfg['from_email'],$mailCfg['from_name']);
                    $mail->addAddress($supEmail,$supName?:'Provider');
                    $mail->isHTML(true);
                    $mail->Subject='InPlace – Placement Confirmation Required: '.$student['full_name'];
                    $mail->Body=$htmlBody;
                    $mail->send();
                } catch (MailException $e) { error_log('CP email: '.$mail->ErrorInfo); }
            }

            $success = "Placement created successfully for {$student['full_name']} at $companyName. Status set to: " . ucwords(str_replace('_',' ',$initialStatus)) . ".";

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Error creating placement: ' . $e->getMessage();
        }
    }
}

// ── Data for form ────────────────────────────────────────────────
$students = $pdo->query("
    SELECT id, full_name, email, academic_year, programme_type
    FROM users
    WHERE role='student' AND approval_status='approved' AND is_active=1
    ORDER BY full_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$existingCompanies = $pdo->query("SELECT DISTINCT name FROM companies ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);

$sectors = [
    'Technology & Software','Engineering & Manufacturing','Finance & Banking',
    'Healthcare & Life Sciences','Consultancy','Media & Communications',
    'Retail & E-commerce','Public Sector / Government','Education & Research','Other',
];
$patterns = ["Full-time (37.5 hrs/week)","Full-time (40 hrs/week)","Hybrid","Remote","Part-time"];
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>
    <div class="page-content">

        <?php if ($success): ?>
        <div style="background:var(--success-bg);border:1px solid #6ee7b7;border-radius:var(--radius);
                    padding:1.25rem 2rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:1rem;">
            <span style="font-size:1.5rem;">✅</span>
            <div style="flex:1;">
                <p style="color:var(--success);font-weight:600;"><?= htmlspecialchars($success) ?></p>
            </div>
            <a href="/inplace/tutor/all-placements.php" class="btn btn-success btn-sm">View Placements →</a>
        </div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div style="background:var(--danger-bg);border:1px solid #fca5a5;border-radius:var(--radius);
                    padding:1.25rem 2rem;margin-bottom:1.5rem;">
            <p style="color:var(--danger);font-weight:500;">⚠️ <?= htmlspecialchars($error) ?></p>
        </div>
        <?php endif; ?>

        <div class="panel">
            <div class="panel-header">
                <div>
                    <h3>Create Placement on Behalf of Student</h3>
                    <p>Fill in all required fields. The student will be notified automatically.</p>
                </div>
                <a href="/inplace/tutor/all-placements.php" class="btn btn-ghost btn-sm">← Back to Placements</a>
            </div>
            <div class="panel-body">
                <form method="POST">

                    <!-- SECTION 1: Student -->
                    <div style="font-size:0.8rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;
                                color:var(--muted);margin-bottom:1.25rem;padding-bottom:0.6rem;border-bottom:2px solid var(--border);">
                        1 · Select Student
                    </div>
                    <div class="form-grid" style="margin-bottom:2rem;">
                        <div class="form-group full-col">
                            <label>Student <span style="color:var(--danger);">*</span></label>
                            <select name="student_id" required>
                                <option value="">— Select an approved student —</option>
                                <?php foreach ($students as $s): ?>
                                <option value="<?= $s['id'] ?>"
                                        <?= (($_POST['student_id']??0)==$s['id'])?'selected':'' ?>>
                                    <?= htmlspecialchars($s['full_name']) ?>
                                    <?= $s['academic_year'] ? ' ('.$s['academic_year'].')' : '' ?>
                                    — <?= htmlspecialchars($s['email']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($students)): ?>
                            <small style="color:var(--danger);">No approved students found. Students must be approved before a placement can be created for them.</small>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- SECTION 2: Company -->
                    <div style="font-size:0.8rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;
                                color:var(--muted);margin-bottom:1.25rem;padding-bottom:0.6rem;border-bottom:2px solid var(--border);">
                        2 · Company &amp; Role
                    </div>
                    <div class="form-grid" style="margin-bottom:2rem;">
                        <div class="form-group">
                            <label>Company Name <span style="color:var(--danger);">*</span></label>
                            <input type="text" name="company_name" id="companyNameInput" required
                                   list="companySuggest"
                                   placeholder="e.g., Rolls-Royce plc"
                                   value="<?= htmlspecialchars($_POST['company_name']??'') ?>">
                            <datalist id="companySuggest">
                                <?php foreach ($existingCompanies as $ec): ?>
                                <option value="<?= htmlspecialchars($ec) ?>">
                                <?php endforeach; ?>
                            </datalist>
                            <small style="color:var(--muted);">Start typing to find an existing company or enter a new one.</small>
                        </div>

                        <div class="form-group">
                            <label>Industry / Sector</label>
                            <select name="sector">
                                <option value="">Select sector</option>
                                <?php foreach ($sectors as $s): ?>
                                <option value="<?= htmlspecialchars($s) ?>"
                                        <?= (($_POST['sector']??'')===$s)?'selected':'' ?>>
                                    <?= htmlspecialchars($s) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group full-col">
                            <label>Company Address</label>
                            <div style="position:relative;">
                                <input type="text" id="addrSearch" name="company_address"
                                       autocomplete="off"
                                       placeholder="Start typing an address or postcode…"
                                       value="<?= htmlspecialchars($_POST['company_address']??'') ?>">
                                <div id="addrDrop"
                                     style="display:none;position:absolute;top:100%;left:0;right:0;
                                            background:white;border:2px solid var(--border);border-top:none;
                                            border-radius:0 0 10px 10px;z-index:999;max-height:200px;overflow-y:auto;
                                            box-shadow:0 4px 12px rgba(0,0,0,0.12);"></div>
                            </div>
                            <input type="hidden" name="company_lat" id="comp_lat" value="">
                            <input type="hidden" name="company_lng" id="comp_lng" value="">
                        </div>

                        <div class="form-group">
                            <label>Role / Job Title <span style="color:var(--danger);">*</span></label>
                            <input type="text" name="role_title" required
                                   placeholder="e.g., Software Engineering Intern"
                                   value="<?= htmlspecialchars($_POST['role_title']??'') ?>">
                        </div>

                        <div class="form-group">
                            <label>Working Pattern</label>
                            <select name="working_pattern">
                                <?php foreach ($patterns as $p): ?>
                                <option value="<?= htmlspecialchars($p) ?>"
                                        <?= (($_POST['working_pattern']??'Full-time (37.5 hrs/week)')===$p)?'selected':'' ?>>
                                    <?= htmlspecialchars($p) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group full-col">
                            <label>Job Description</label>
                            <textarea name="job_description" rows="3"
                                      placeholder="Describe the role and responsibilities…"><?= htmlspecialchars($_POST['job_description']??'') ?></textarea>
                        </div>
                    </div>

                    <!-- SECTION 3: Dates -->
                    <div style="font-size:0.8rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;
                                color:var(--muted);margin-bottom:1.25rem;padding-bottom:0.6rem;border-bottom:2px solid var(--border);">
                        3 · Placement Dates &amp; Terms
                    </div>
                    <div class="form-grid" style="margin-bottom:2rem;">
                        <div class="form-group">
                            <label>Start Date <span style="color:var(--danger);">*</span></label>
                            <input type="date" name="start_date" required
                                   value="<?= htmlspecialchars($_POST['start_date']??'') ?>">
                        </div>
                        <div class="form-group">
                            <label>End Date <span style="color:var(--danger);">*</span></label>
                            <input type="date" name="end_date" required
                                   value="<?= htmlspecialchars($_POST['end_date']??'') ?>">
                        </div>
                        <div class="form-group">
                            <label>Salary (Annual)</label>
                            <input type="text" name="salary"
                                   placeholder="e.g., £22,000"
                                   value="<?= htmlspecialchars($_POST['salary']??'') ?>">
                        </div>
                    </div>

                    <!-- SECTION 4: Supervisor -->
                    <div style="font-size:0.8rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;
                                color:var(--muted);margin-bottom:1.25rem;padding-bottom:0.6rem;border-bottom:2px solid var(--border);">
                        4 · Supervisor Details
                    </div>
                    <div class="form-grid" style="margin-bottom:2rem;">
                        <div class="form-group">
                            <label>Supervisor Name</label>
                            <input type="text" name="supervisor_name"
                                   placeholder="e.g., Mark Henderson"
                                   value="<?= htmlspecialchars($_POST['supervisor_name']??'') ?>">
                        </div>
                        <div class="form-group">
                            <label>Supervisor Email</label>
                            <input type="email" name="supervisor_email"
                                   placeholder="supervisor@company.com"
                                   value="<?= htmlspecialchars($_POST['supervisor_email']??'') ?>">
                        </div>
                        <div class="form-group">
                            <label>Supervisor Phone</label>
                            <input type="tel" name="supervisor_phone"
                                   placeholder="+44 7700 000000"
                                   value="<?= htmlspecialchars($_POST['supervisor_phone']??'') ?>">
                        </div>
                    </div>

                    <!-- SECTION 5: Initial Status -->
                    <div style="font-size:0.8rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;
                                color:var(--muted);margin-bottom:1.25rem;padding-bottom:0.6rem;border-bottom:2px solid var(--border);">
                        5 · Initial Status
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:2rem;">
                        <?php
                        $statusOpts = [
                            'awaiting_provider' => ['📩', 'Awaiting Provider', 'Provider must confirm. An email will be sent to the supervisor.'],
                            'awaiting_tutor'    => ['📋', 'Awaiting Tutor Review', 'Provider has already confirmed. Tutor needs to approve.'],
                            'approved'          => ['✅', 'Approved',  'Mark as fully approved immediately. Use only if all parties have already agreed verbally.'],
                        ];
                        $curStatus = $_POST['initial_status'] ?? 'awaiting_provider';
                        foreach ($statusOpts as $val => [$icon, $label, $desc]):
                        ?>
                        <label style="cursor:pointer;">
                            <input type="radio" name="initial_status" value="<?= $val ?>"
                                   <?= $curStatus===$val?'checked':'' ?>
                                   style="display:none;" class="status-radio">
                            <div class="status-card-opt <?= $curStatus===$val?'selected':'' ?>"
                                 style="border:2px solid var(--border);border-radius:10px;padding:1rem;
                                        transition:all 0.2s;<?= $curStatus===$val?'border-color:var(--navy);background:var(--cream);':'' ?>">
                                <div style="font-size:1.5rem;margin-bottom:0.4rem;"><?= $icon ?></div>
                                <p style="font-weight:600;color:var(--navy);font-size:0.9rem;margin-bottom:0.3rem;"><?= $label ?></p>
                                <p style="font-size:0.78rem;color:var(--muted);line-height:1.4;"><?= $desc ?></p>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="divider"></div>
                    <div style="display:flex;justify-content:flex-end;gap:1rem;margin-top:1.5rem;">
                        <a href="/inplace/tutor/all-placements.php" class="btn btn-ghost">Cancel</a>
                        <button type="submit" class="btn btn-primary">Create Placement →</button>
                    </div>

                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Status card visual selection
document.querySelectorAll('.status-radio').forEach(r => {
    r.addEventListener('change', function() {
        document.querySelectorAll('.status-card-opt').forEach(c => {
            c.style.borderColor = 'var(--border)';
            c.style.background  = '';
        });
        this.nextElementSibling.style.borderColor = 'var(--navy)';
        this.nextElementSibling.style.background  = 'var(--cream)';
    });
});

// Nominatim address autocomplete
(function() {
    const inp  = document.getElementById('addrSearch');
    const drop = document.getElementById('addrDrop');
    if (!inp) return;
    let t;
    inp.addEventListener('input', function() {
        clearTimeout(t);
        if (this.value.length < 3) { drop.style.display='none'; return; }
        t = setTimeout(() => {
            fetch('https://nominatim.openstreetmap.org/search?format=json&countrycodes=gb&addressdetails=1&limit=6&q=' + encodeURIComponent(inp.value), {headers:{'Accept-Language':'en'}})
                .then(r=>r.json()).then(data => {
                    drop.innerHTML = '';
                    if (!data.length) { drop.style.display='none'; return; }
                    data.forEach(item => {
                        const d = document.createElement('div');
                        d.style.cssText = 'padding:0.6rem 1rem;cursor:pointer;font-size:0.875rem;border-bottom:1px solid #f0f0f0;';
                        d.textContent = item.display_name;
                        d.addEventListener('mouseenter', () => d.style.background='#f8f5f0');
                        d.addEventListener('mouseleave', () => d.style.background='');
                        d.addEventListener('mousedown', e => e.preventDefault());
                        d.addEventListener('click', () => {
                            inp.value = item.display_name;
                            document.getElementById('comp_lat').value = item.lat;
                            document.getElementById('comp_lng').value = item.lon;
                            drop.style.display = 'none';
                        });
                        drop.appendChild(d);
                    });
                    drop.style.display = 'block';
                }).catch(() => drop.style.display='none');
        }, 350);
    });
    document.addEventListener('click', e => { if (!inp.contains(e.target) && !drop.contains(e.target)) drop.style.display='none'; });
}());

// Date validation
document.querySelector('form').addEventListener('submit', function(e) {
    const s = document.querySelector('[name="start_date"]').value;
    const en = document.querySelector('[name="end_date"]').value;
    if (s && en && en <= s) { e.preventDefault(); alert('End date must be after start date.'); }
});
</script>

<?php include '../includes/footer.php'; ?>
