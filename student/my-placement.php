<?php
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/storage_helper.php';

requireAuth('student');

$pageTitle    = 'My Placement';
$pageSubtitle = 'Welcome back, ' . explode(' ', authName())[0];
$activePage   = 'placement';
$userId       = authId();

$pendingRequests = 0;

// unread messages count for the sidebar badge
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount = (int)$stmt->fetchColumn();

// get the student's active placement along with company and tutor info
$stmt = $pdo->prepare("
    SELECT
        p.*,
        c.name        AS company_name,
        c.city        AS company_city,
        c.address     AS company_address,
        c.sector      AS company_sector,
        u.full_name   AS tutor_name
    FROM placements p
    JOIN companies c ON p.company_id = c.id
    LEFT JOIN users u ON p.tutor_id = u.id
    WHERE p.student_id = ?
      AND p.status IN ('approved','active')
    ORDER BY p.id DESC
    LIMIT 1
");
$stmt->execute([$userId]);
$placement = $stmt->fetch();

// calculate placement progress percentage
$progressPct   = 0;
$monthsElapsed = 0;
$monthsTotal   = 0;
if ($placement) {
    $start         = new DateTime($placement['start_date']);
    $end           = new DateTime($placement['end_date']);
    $today         = new DateTime();
    $totalDays     = max(1, $start->diff($end)->days);
    $elapsedDays   = $start->diff($today)->days;
    $progressPct   = min(100, round(($elapsedDays / $totalDays) * 100));
    $monthsElapsed = round($elapsedDays / 30, 1);
    $monthsTotal   = round($totalDays / 30, 1);
}

// load any change requests the student has submitted for this placement
$changeRequests = [];
if ($placement) {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM placement_change_requests
            WHERE placement_id = ? AND student_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$placement['id'], $userId]);
        $changeRequests = $stmt->fetchAll();
    } catch (Exception $e) {}
}

// documents the student has uploaded for this placement
$documents = [];
if ($placement) {
    $stmt = $pdo->prepare("
        SELECT * FROM documents
        WHERE placement_id = ? AND uploaded_by = ?
        ORDER BY uploaded_at DESC
    ");
    $stmt->execute([$placement['id'], $userId]);
    $documents = $stmt->fetchAll();
}

// load the student's recent weekly reflections
$reflections = [];
if ($placement) {
    $stmt = $pdo->prepare("
        SELECT * FROM reflections
        WHERE student_id = ? AND placement_id = ?
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$userId, $placement['id']]);
    $reflections = $stmt->fetchAll();
}
?>
<?php
// Flash messages from change request action
$changeSuccess = $_SESSION['change_success'] ?? '';
$changeError   = $_SESSION['change_error']   ?? '';
unset($_SESSION['change_success'], $_SESSION['change_error']);
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>

    <div class="page-content">

        <?php if ($changeSuccess): ?>
        <div style="background:var(--success-bg);border:1px solid #6ee7b7;border-radius:var(--radius);
                    padding:1.25rem 2rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:1rem;">
            <span style="font-size:1.5rem;">✅</span>
            <p style="color:var(--success);font-weight:500;"><?= htmlspecialchars($changeSuccess) ?></p>
        </div>
        <?php endif; ?>
        <?php if ($changeError): ?>
        <div style="background:var(--danger-bg);border:1px solid #fca5a5;border-radius:var(--radius);
                    padding:1.25rem 2rem;margin-bottom:1.5rem;">
            <p style="color:var(--danger);font-weight:500;">⚠️ <?= htmlspecialchars($changeError) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($placement): ?>

        <!-- main placement details card -->
        <div class="panel" style="margin-bottom:1.5rem;">
            <div class="panel-header">
                <div>
                    <h3>Current Placement</h3>
                    <p>Effective from <?= date('j F Y', strtotime($placement['start_date'])) ?></p>
                </div>
                <div style="display:flex;gap:0.75rem;align-items:center;">
                    <span class="badge badge-approved">Approved</span>
                    <button class="btn btn-ghost btn-sm"
                            onclick="document.getElementById('changeModal').style.display='flex'">
                        Request Change
                    </button>
                </div>
            </div>

            <div class="panel-body">
                <!-- Info Grid -->
                <div class="info-grid" style="margin-bottom:2rem;">

                    <div class="info-item">
                        <label>Company</label>
                        <p><?= htmlspecialchars($placement['company_name']) ?></p>
                    </div>

                    <div class="info-item">
                        <label>Role</label>
                        <p><?= htmlspecialchars($placement['role_title'] ?? 'N/A') ?></p>
                    </div>

                    <div class="info-item">
                        <label>Location</label>
                        <p><?= htmlspecialchars($placement['company_city'] ?? 'N/A') ?></p>
                    </div>

                    <div class="info-item">
                        <label>Start Date</label>
                        <p><?= date('j F Y', strtotime($placement['start_date'])) ?></p>
                    </div>

                    <div class="info-item">
                        <label>End Date</label>
                        <p><?= date('j F Y', strtotime($placement['end_date'])) ?></p>
                    </div>

                    <div class="info-item">
                        <label>Salary</label>
                        <p><?= htmlspecialchars($placement['salary'] ?? 'Not specified') ?></p>
                    </div>

                    <div class="info-item">
                        <label>Supervisor</label>
                        <p><?= htmlspecialchars($placement['supervisor_name'] ?? 'N/A') ?></p>
                    </div>

                    <div class="info-item">
                        <label>Supervisor Email</label>
                        <p>
                            <a href="mailto:<?= htmlspecialchars($placement['supervisor_email'] ?? '') ?>"
                               style="color:var(--navy);text-decoration:none;">
                                <?= htmlspecialchars($placement['supervisor_email'] ?? 'N/A') ?>
                            </a>
                        </p>
                    </div>

                    <div class="info-item">
                        <label>Placement Tutor</label>
                        <p><?= htmlspecialchars($placement['tutor_name'] ?? 'Not assigned') ?></p>
                    </div>

                </div><!-- /info-grid -->

                <div class="divider"></div>

                <!-- Placement Progress -->
                <h4 style="font-size:1rem;font-weight:600;margin-bottom:1.25rem;">Placement Progress</h4>
                <div style="display:flex;align-items:center;gap:1.25rem;margin-bottom:0.5rem;">
                    <div style="flex:1;">
                        <div class="progress-bar" style="height:10px;">
                            <div class="progress-fill" style="width:<?= $progressPct ?>%;height:100%;"></div>
                        </div>
                    </div>
                    <div style="font-size:0.9375rem;font-weight:700;color:var(--navy);white-space:nowrap;">
                        <?= $progressPct ?>% Complete
                    </div>
                </div>
                <p style="font-size:0.875rem;color:var(--muted);">
                    <?= $monthsElapsed ?> months completed of <?= $monthsTotal ?>-month placement
                </p>

            </div><!-- /panel-body -->
        </div><!-- /placement panel -->


        <!-- documents and reflections side by side -->
        <div class="two-col">

            <!-- documents uploaded for this placement -->
            <div class="panel">
                <div class="panel-header">
                    <h3>Documents</h3>
                    <button class="btn btn-primary btn-sm"
                            onclick="document.getElementById('uploadModal').style.display='flex'">
                        Upload New
                    </button>
                </div>

                <div class="panel-body" style="padding:0;">
                    <?php if (empty($documents)): ?>
                        <div style="text-align:center;padding:3rem 2rem;">
                            <div style="font-size:2.5rem;margin-bottom:0.75rem;">📁</div>
                            <p style="color:var(--muted);">No documents uploaded yet.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($documents as $doc): ?>
                        <div style="display:flex;align-items:center;gap:1rem;
                                    padding:1.125rem 2rem;
                                    border-bottom:1px solid var(--border);">
                            <div style="width:40px;height:40px;background:var(--info-bg);
                                        border-radius:8px;display:flex;align-items:center;
                                        justify-content:center;font-size:1.25rem;flex-shrink:0;">
                                📄
                            </div>
                            <div style="flex:1;min-width:0;">
                                <p style="font-weight:500;font-size:0.9375rem;
                                          overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <?= htmlspecialchars($doc['file_name']) ?>
                                </p>
                                <p style="font-size:0.8125rem;color:var(--muted);">
                                    <?= ucwords(str_replace('_', ' ', $doc['doc_type'])) ?>
                                    · Uploaded <?= date('d M Y', strtotime($doc['uploaded_at'])) ?>
                                </p>
                            </div>
                            <a href="<?= htmlspecialchars(fileUrl($doc['file_path'])) ?>"
                               download
                               class="btn btn-ghost btn-sm">
                                ⬇ Download
                            </a>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div><!-- /documents panel -->


            <!-- weekly reflection log -->
            <div class="panel">
                <div class="panel-header">
                    <h3>Weekly Reflection Log</h3>
                    <button class="btn btn-primary btn-sm"
                            onclick="document.getElementById('reflectionModal').style.display='flex'">
                        + Add Entry
                    </button>
                </div>

                <div class="panel-body" style="padding:0;">
                    <?php if (empty($reflections)): ?>
                        <div style="text-align:center;padding:3rem 2rem;">
                            <div style="font-size:2.5rem;margin-bottom:0.75rem;">📝</div>
                            <p style="color:var(--muted);">No reflections logged yet.<br>Start recording your weekly progress!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($reflections as $r): ?>
                        <div style="padding:1.25rem 2rem;border-bottom:1px solid var(--border);">
                            <p style="font-size:0.75rem;font-weight:600;color:var(--muted);
                                      text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.4rem;">
                                <?= htmlspecialchars($r['week_label']) ?>
                                · <?= date('d M Y', strtotime($r['created_at'])) ?>
                            </p>
                            <p style="font-size:0.9rem;color:var(--text);line-height:1.6;">
                                <?= nl2br(htmlspecialchars($r['content'])) ?>
                            </p>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div><!-- /reflections panel -->

        </div><!-- /two-col -->


        <!-- history of placement change requests -->
        <div class="panel" style="margin-top:1.5rem;">
            <div class="panel-header">
                <div>
                    <h3>Change Requests</h3>
                    <p>History of placement change requests you have submitted</p>
                </div>
                <button class="btn btn-ghost btn-sm"
                        onclick="document.getElementById('changeModal').style.display='flex'">
                    + New Request
                </button>
            </div>

            <?php if (empty($changeRequests)): ?>
            <div style="text-align:center;padding:2.5rem 2rem;">
                <div style="font-size:2.5rem;margin-bottom:0.75rem;">🔄</div>
                <p style="color:var(--muted);">No change requests submitted yet.</p>
            </div>
            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Type of Change</th>
                            <th>Justification</th>
                            <th>Proposed Details</th>
                            <th>Status</th>
                            <th>Submitted</th>
                            <th>Comments</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($changeRequests as $cr):
                            $crBadge = match($cr['status']) {
                                'pending_provider' => 'pending',
                                'pending_tutor'    => 'review',
                                'approved'         => 'approved',
                                'rejected'         => 'rejected',
                                default            => 'open'
                            };
                            $crLabel = match($cr['change_type']) {
                                'end_date'   => 'Extend / Change End Date',
                                'start_date' => 'Change Start Date',
                                'role'       => 'Change Role',
                                'supervisor' => 'Change Supervisor',
                                'transfer'   => 'Transfer Company',
                                'salary'     => 'Change Salary / Terms',
                                default      => ucwords(str_replace('_',' ',$cr['change_type'])),
                            };
                        ?>
                        <tr>
                            <td><span class="type-chip"><?= htmlspecialchars($crLabel) ?></span></td>
                            <td style="max-width:220px;font-size:0.875rem;">
                                <?= nl2br(htmlspecialchars($cr['justification'])) ?>
                            </td>
                            <td style="max-width:180px;font-size:0.875rem;color:var(--muted);">
                                <?= $cr['proposed_details'] ? nl2br(htmlspecialchars($cr['proposed_details'])) : '—' ?>
                            </td>
                            <td><span class="badge badge-<?= $crBadge ?>">
                                <?= ucwords(str_replace('_',' ',$cr['status'])) ?>
                            </span></td>
                            <td style="font-size:0.8125rem;color:var(--muted);">
                                <?= date('d M Y', strtotime($cr['created_at'])) ?>
                            </td>
                            <td style="font-size:0.8125rem;">
                                <?php if ($cr['provider_comment']): ?>
                                    <p><strong>Provider:</strong> <?= htmlspecialchars($cr['provider_comment']) ?></p>
                                <?php endif; ?>
                                <?php if ($cr['tutor_comment']): ?>
                                    <p><strong>Tutor:</strong> <?= htmlspecialchars($cr['tutor_comment']) ?></p>
                                <?php endif; ?>
                                <?php if (!$cr['provider_comment'] && !$cr['tutor_comment']): ?>
                                    <span style="color:var(--muted);">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <!-- shown when the student doesn't have an approved placement yet -->
        <div class="panel">
            <div class="panel-body" style="text-align:center;padding:4rem 2rem;">
                <div style="font-size:4rem;margin-bottom:1rem;">🏢</div>
                <h3 style="font-family:'Playfair Display',serif;font-size:1.5rem;
                           color:var(--navy);margin-bottom:0.75rem;">
                    No Active Placement
                </h3>
                <p style="color:var(--muted);margin-bottom:2rem;max-width:400px;margin-left:auto;margin-right:auto;">
                    You don't have an approved placement yet. Submit an authorisation
                    request to get started.
                </p>
                <a href="/inplace/student/submit-request.php" class="btn btn-primary">
                    Submit a Request →
                </a>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /page-content -->
</div><!-- /main -->


<!-- modal: add a weekly reflection entry -->
<div id="reflectionModal" style="display:none;position:fixed;inset:0;
     background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--white);border-radius:var(--radius);padding:2.5rem;
                width:100%;max-width:520px;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
        <h3 style="font-family:'Playfair Display',serif;font-size:1.375rem;
                   color:var(--navy);margin-bottom:1.5rem;">Add Weekly Reflection</h3>
      <form method="POST" action="/inplace/actions/save-reflection.php">
            <div class="form-group" style="margin-bottom:1.25rem;">
                <label>Week Label (e.g. "Week 12 · Mar 18")</label>
                <input type="text" name="week_label"
                       placeholder="Week 12 · Mar 18"
                       style="padding:0.875rem 1rem;border:2px solid var(--border);
                              border-radius:var(--radius-sm);width:100%;font-family:inherit;
                              font-size:0.9375rem;background:var(--cream);">
            </div>
            <div class="form-group" style="margin-bottom:1.5rem;">
                <label>What did you work on this week?</label>
                <textarea name="content" rows="5"
                          placeholder="Describe your tasks, learnings, and challenges..."
                          style="padding:0.875rem 1rem;border:2px solid var(--border);
                                 border-radius:var(--radius-sm);width:100%;font-family:inherit;
                                 font-size:0.9375rem;background:var(--cream);resize:vertical;"></textarea>
            </div>
            <input type="hidden" name="placement_id" value="<?= $placement['id'] ?? '' ?>">
            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button type="button" class="btn btn-ghost"
                        onclick="document.getElementById('reflectionModal').style.display='none'">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">Save Entry</button>
            </div>
        </form>
    </div>
</div>


<!-- modal: upload a document for this placement -->
<div id="uploadModal" style="display:none;position:fixed;inset:0;
     background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--white);border-radius:var(--radius);padding:2.5rem;
                width:100%;max-width:520px;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
        <h3 style="font-family:'Playfair Display',serif;font-size:1.375rem;
                   color:var(--navy);margin-bottom:1.5rem;">Upload Document</h3>
        <form method="POST" action="/inplace/student/actions/upload-doc.php"
              enctype="multipart/form-data">
            <div class="form-group" style="margin-bottom:1.25rem;">
                <label>Document Type</label>
                <select name="doc_type"
                        style="padding:0.875rem 1rem;border:2px solid var(--border);
                               border-radius:var(--radius-sm);width:100%;font-family:inherit;
                               font-size:0.9375rem;background:var(--cream);">
                    <option value="offer_letter">Offer Letter</option>
                    <option value="job_description">Job Description</option>
                    <option value="interim_report">Interim Report</option>
                    <option value="final_report">Final Report</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:1.5rem;">
                <label>File (PDF, max 10 MB)</label>
                <div class="upload-zone"
                     onclick="document.getElementById('fileInput').click()"
                     style="cursor:pointer;">
                    <div style="font-size:2.5rem;margin-bottom:0.5rem;">📎</div>
                    <p><strong>Click to choose file</strong> or drag and drop</p>
                    <p style="font-size:0.8125rem;color:var(--muted);margin-top:0.25rem;">PDF only · max 10 MB</p>
                </div>
                <input id="fileInput" type="file" name="document" accept=".pdf"
                       style="display:none;"
                       onchange="document.getElementById('fileName').textContent = this.files[0]?.name || ''">
                <p id="fileName" style="font-size:0.875rem;color:var(--success);margin-top:0.5rem;"></p>
            </div>
            <input type="hidden" name="placement_id" value="<?= $placement['id'] ?? '' ?>">
            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button type="button" class="btn btn-ghost"
                        onclick="document.getElementById('uploadModal').style.display='none'">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">Upload →</button>
            </div>
        </form>
    </div>
</div>


<!-- modal: submit a placement change request -->
<div id="changeModal" style="display:none;position:fixed;inset:0;
     background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--white);border-radius:var(--radius);padding:2.5rem;
                width:100%;max-width:560px;box-shadow:0 20px 60px rgba(0,0,0,0.2);
                max-height:90vh;overflow-y:auto;">
        <h3 style="font-family:'Playfair Display',serif;font-size:1.375rem;
                   color:var(--navy);margin-bottom:0.5rem;">Request Placement Change</h3>
        <p style="color:var(--muted);font-size:0.875rem;margin-bottom:1.5rem;">
            Your request will be sent to the placement provider for approval, then your tutor.
        </p>
        <form method="POST" action="/inplace/student/actions/request-change.php">
            <input type="hidden" name="placement_id" value="<?= $placement['id'] ?? '' ?>">

            <div class="form-group" style="margin-bottom:1.25rem;">
                <label>Type of Change <span style="color:var(--danger);">*</span></label>
                <select name="change_type" required
                        style="padding:0.875rem 1rem;border:2px solid var(--border);
                               border-radius:var(--radius-sm);width:100%;font-family:inherit;
                               font-size:0.9375rem;background:var(--cream);">
                    <option value="">— Select change type —</option>
                    <option value="end_date">Extend / Change End Date</option>
                    <option value="start_date">Change Start Date</option>
                    <option value="role">Change Role (same company)</option>
                    <option value="supervisor">Change Supervisor</option>
                    <option value="salary">Change Salary / Terms</option>
                    <option value="transfer">Transfer to Different Company</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:1.25rem;">
                <label>Justification <span style="color:var(--danger);">*</span></label>
                <textarea name="justification" rows="3" required
                          placeholder="Explain why this change is needed..."
                          style="padding:0.875rem 1rem;border:2px solid var(--border);
                                 border-radius:var(--radius-sm);width:100%;font-family:inherit;
                                 font-size:0.9375rem;background:var(--cream);resize:vertical;"></textarea>
            </div>

            <div class="form-group" style="margin-bottom:1.5rem;">
                <label>Proposed New Details</label>
                <textarea name="proposed_details" rows="3"
                          placeholder="e.g., New end date: 30 June 2026 / New supervisor: Jane Smith (jane@company.com)"
                          style="padding:0.875rem 1rem;border:2px solid var(--border);
                                 border-radius:var(--radius-sm);width:100%;font-family:inherit;
                                 font-size:0.9375rem;background:var(--cream);resize:vertical;"></textarea>
                <small style="color:var(--muted);">Provide the specific new values you are requesting.</small>
            </div>

            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button type="button" class="btn btn-ghost"
                        onclick="document.getElementById('changeModal').style.display='none'">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">Submit Change Request →</button>
            </div>
        </form>
    </div>
</div>

<!-- Close modals when clicking outside -->
<script>
['reflectionModal','uploadModal','changeModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });
});
</script>

<?php include '../includes/footer.php'; ?>