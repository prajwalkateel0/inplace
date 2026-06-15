<?php
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/storage_helper.php';

requireAuth('student');

$pageTitle    = 'Reports';
$pageSubtitle = 'Welcome back, ' . explode(' ', authName())[0];
$activePage   = 'reports';
$userId       = authId();

// unread messages for sidebar badge
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount = (int)$stmt->fetchColumn();

// get the student's active placement (order by id DESC since created_at may not exist)
$stmt = $pdo->prepare("
    SELECT p.*, c.name AS company_name, c.city
    FROM placements p
    JOIN companies c ON p.company_id = c.id
    WHERE p.student_id = ?
      AND p.status IN ('approved','active')
    ORDER BY p.id DESC
    LIMIT 1
");
$stmt->execute([$userId]);
$placement = $stmt->fetch(PDO::FETCH_ASSOC);

$interimDue  = null;
$finalDue    = null;
$placementId = $placement ? (int)$placement['id'] : null;

if ($placement) {
    $start = new DateTime($placement['start_date']);
    $end   = new DateTime($placement['end_date']);
    $interimDue = (clone $start)->modify('+4 months');
    $finalDue   = (clone $end)->modify('-1 month');
}

// look up the student's interim and final report documents
$interimDoc = null;
$finalDoc   = null;

if ($placementId) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM documents
        WHERE placement_id = ?
          AND uploaded_by  = ?
          AND doc_type IN ('interim_report','final_report')
        ORDER BY uploaded_at DESC
    ");
    $stmt->execute([$placementId, $userId]);
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($docs as $d) {
        if ($d['doc_type'] === 'interim_report' && !$interimDoc) $interimDoc = $d;
        if ($d['doc_type'] === 'final_report'   && !$finalDoc)   $finalDoc   = $d;
    }
}

// handle report uploads submitted from this page
$success = '';
$error   = '';

function uploadPdfToDocuments(PDO $pdo, int $placementId, int $userId, string $docType, string $fileFieldName): array {
    if (empty($_FILES[$fileFieldName]['tmp_name'])) {
        throw new Exception("Please choose a PDF file.");
    }

    $file = $_FILES[$fileFieldName];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Upload failed. Please try again.");
    }

    // Validate size (15 MB)
    $maxBytes = 15 * 1024 * 1024;
    if ($file['size'] > $maxBytes) {
        throw new Exception("File too large. Max 15 MB.");
    }

    // Validate PDF by extension
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        throw new Exception("Only PDF files are allowed.");
    }

    // Save file
    $original = $file['name'];
    $safe     = time() . '_' . ($docType === 'interim_report' ? 'interim_' : 'final_')
              . preg_replace('/[^a-zA-Z0-9._-]/', '_', $original);

    $destDb = 'assets/uploads/' . $safe; // relative path stored in DB

    if (!storeUploadedFile($file, $destDb)) {
        throw new Exception("Could not save file.");
    }

    $size = round($file['size'] / 1024) . ' KB';

    // Insert row
    $stmt = $pdo->prepare("
        INSERT INTO documents (placement_id, uploaded_by, doc_type, file_name, file_path, file_size, status)
        VALUES (?, ?, ?, ?, ?, ?, 'pending')
    ");
    $stmt->execute([$placementId, $userId, $docType, $original, $destDb, $size]);

    // Return latest row
    $stmt = $pdo->prepare("
        SELECT *
        FROM documents
        WHERE placement_id = ?
          AND uploaded_by  = ?
          AND doc_type     = ?
        ORDER BY uploaded_at DESC
        LIMIT 1
    ");
    $stmt->execute([$placementId, $userId, $docType]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!$placementId) {
        $error = "No active placement found. You can submit reports after your placement is approved.";
    } else {
        try {

            // Interim upload
            if (isset($_POST['submit_interim'])) {
                $interimDoc = uploadPdfToDocuments($pdo, $placementId, $userId, 'interim_report', 'interim_report');
                $success = "Interim report submitted successfully!";
            }

            // Final upload
            if (isset($_POST['submit_final'])) {
                $finalDoc = uploadPdfToDocuments($pdo, $placementId, $userId, 'final_report', 'final_report');
                $success = "Final report submitted successfully!";
            }

        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// helper: build the download URL for a document
function docDownloadUrl($doc) {
    if (!$doc || empty($doc['file_path'])) return '#';
    return fileUrl($doc['file_path']);
}

// helper: convert a document status to badge class and label
function statusBadge($status) {
    $status = strtolower(trim((string)$status));

    return match ($status) {
        'reviewed', 'approved'  => ['approved', 'Reviewed'],
        'rejected'              => ['rejected', 'Rejected'],
        'pending'               => ['pending', 'Pending'],
        default                 => ['open', ucfirst($status ?: 'Submitted')],
    };
}

// work out the summary numbers for the status panel
$studentReviewed = 0;
$studentPending  = 0;
$studentOverdue  = 0;
$studentUpcoming = 0;

if ($placement) {
    if ($finalDoc) {
        [$cls, $lbl] = statusBadge($finalDoc['status'] ?? 'pending');
        if ($cls === 'approved') $studentReviewed = 1;
        if ($cls === 'pending' || $cls === 'open') $studentPending = 1;
    } else {
        $today = new DateTime();
        if ($finalDue && $today > $finalDue) $studentOverdue = 1;
        if ($finalDue && $today <= $finalDue) {
            $daysLeft = (int)$today->diff($finalDue)->days;
            if ($daysLeft > 30) $studentUpcoming = 1;
        }
    }
}
?>

<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>

    <div class="page-content">

        <?php if ($success): ?>
            <div style="background:var(--success-bg);border:1px solid #6ee7b7;border-radius:var(--radius);
                        padding:1.25rem 2rem;margin-bottom:1.5rem;">
                <p style="color:var(--success);font-weight:600;">✅ <?= htmlspecialchars($success) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div style="background:var(--danger-bg);border:1px solid #fca5a5;border-radius:var(--radius);
                        padding:1.25rem 2rem;margin-bottom:1.5rem;">
                <p style="color:var(--danger);font-weight:600;">⚠️ <?= htmlspecialchars($error) ?></p>
            </div>
        <?php endif; ?>

        <!-- two columns: report list on left, submission status on right -->
        <div class="two-col">

            <!-- left: table showing the student's submitted reports -->
            <div class="panel">
                <div class="panel-header">
                    <h3>My Reports</h3>
                    <button class="btn btn-primary btn-sm"
                            onclick="document.getElementById('uploads').scrollIntoView({behavior:'smooth'})">
                        + Submit Report
                    </button>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Report</th>
                                <th>Type</th>
                                <th>Submitted</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>

                        <tbody>

                            <!-- Interim -->
                            <tr>
                                <td>
                                    <div style="font-weight:500;">Interim Placement Report</div>
                                    <div style="font-size:0.8125rem;color:var(--muted);">
                                        <?php if ($interimDoc): ?>
                                            PDF · <?= htmlspecialchars($interimDoc['file_size'] ?? '') ?>
                                        <?php elseif ($interimDue): ?>
                                            Due <?= $interimDue->format('d M Y') ?>
                                        <?php else: ?>
                                            Not available yet
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td><span class="type-chip">Interim</span></td>

                                <td style="font-size:0.875rem;color:var(--muted);">
                                    <?php if ($interimDoc && !empty($interimDoc['uploaded_at'])): ?>
                                        <?= date('d M Y', strtotime($interimDoc['uploaded_at'])) ?>
                                    <?php elseif ($interimDoc): ?>
                                        Submitted
                                    <?php else: ?>
                                        Not yet submitted
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($interimDoc): ?>
                                        <?php [$cls,$lbl] = statusBadge($interimDoc['status'] ?? 'pending'); ?>
                                        <span class="badge badge-<?= htmlspecialchars($cls) ?>"><?= htmlspecialchars($lbl) ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-pending">Pending</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($interimDoc): ?>
                                        <a class="btn btn-ghost btn-sm"
                                           href="<?= htmlspecialchars(docDownloadUrl($interimDoc)) ?>"
                                           target="_blank">⬇ Download</a>
                                    <?php else: ?>
                                        <button class="btn btn-primary btn-sm"
                                                onclick="document.getElementById('uploads').scrollIntoView({behavior:'smooth'})">
                                            Submit
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <!-- Final -->
                            <tr>
                                <td>
                                    <div style="font-weight:500;">Final Placement Report</div>
                                    <div style="font-size:0.8125rem;color:var(--muted);">
                                        <?php if ($finalDoc): ?>
                                            PDF · <?= htmlspecialchars($finalDoc['file_size'] ?? '') ?>
                                        <?php elseif ($finalDue): ?>
                                            Due <?= $finalDue->format('d M Y') ?>
                                        <?php else: ?>
                                            Not available yet
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td><span class="type-chip">Final</span></td>

                                <td style="font-size:0.875rem;color:var(--muted);">
                                    <?php if ($finalDoc && !empty($finalDoc['uploaded_at'])): ?>
                                        <?= date('d M Y', strtotime($finalDoc['uploaded_at'])) ?>
                                    <?php elseif ($finalDoc): ?>
                                        Submitted
                                    <?php else: ?>
                                        Not yet submitted
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($finalDoc): ?>
                                        <?php [$cls,$lbl] = statusBadge($finalDoc['status'] ?? 'pending'); ?>
                                        <span class="badge badge-<?= htmlspecialchars($cls) ?>"><?= htmlspecialchars($lbl) ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-pending">Pending</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($finalDoc): ?>
                                        <a class="btn btn-ghost btn-sm"
                                           href="<?= htmlspecialchars(docDownloadUrl($finalDoc)) ?>"
                                           target="_blank">⬇ Download</a>
                                    <?php else: ?>
                                        <button class="btn btn-primary btn-sm"
                                                onclick="document.getElementById('uploads').scrollIntoView({behavior:'smooth'})">
                                            Submit
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>

                        </tbody>
                    </table>
                </div>
            </div>

            <!-- right: quick summary of report submission status -->
            <div class="panel">
                <div class="panel-header">
                    <h3>Report Submission Status</h3>
                </div>

                <div class="panel-body">

                    <div style="display:flex;align-items:center;justify-content:space-between;
                                padding:0.875rem 0;border-bottom:1px solid var(--border);">
                        <span style="font-size:0.9375rem;color:var(--text);">Submitted & Reviewed</span>
                        <span class="badge badge-approved"><?= (int)$studentReviewed ?></span>
                    </div>

                    <div style="display:flex;align-items:center;justify-content:space-between;
                                padding:0.875rem 0;border-bottom:1px solid var(--border);">
                        <span style="font-size:0.9375rem;color:var(--text);">Submitted — Awaiting Review</span>
                        <span class="badge badge-open"><?= (int)$studentPending ?></span>
                    </div>

                    <div style="display:flex;align-items:center;justify-content:space-between;
                                padding:0.875rem 0;border-bottom:1px solid var(--border);">
                        <span style="font-size:0.9375rem;color:var(--text);">Overdue</span>
                        <span class="badge badge-rejected"><?= (int)$studentOverdue ?></span>
                    </div>

                    <div style="display:flex;align-items:center;justify-content:space-between;
                                padding:0.875rem 0;">
                        <span style="font-size:0.9375rem;color:var(--text);">Upcoming (&gt; 30 days)</span>
                        <span class="badge badge-pending"><?= (int)$studentUpcoming ?></span>
                    </div>

                </div>
            </div>

        </div><!-- /two-col -->

        <!-- upload forms for interim and final reports -->
        <div class="panel" id="uploads">
            <div class="panel-header">
                <h3>Submit Reports</h3>
            </div>

            <div class="panel-body">
                <?php if (!$placementId): ?>
                    <div style="text-align:center;padding:2.5rem 1rem;">
                        <div style="font-size:2.5rem;margin-bottom:0.75rem;">📄</div>
                        <p style="color:var(--muted);">No active placement yet. Reports will be enabled once your placement is approved.</p>
                    </div>
                <?php else: ?>

                    <!-- interim report upload -->
                    <div style="margin-bottom:1.75rem;">
                        <h4 style="margin-bottom:0.75rem;">Submit Interim Report</h4>

                        <?php if ($interimDoc): ?>
                            <div style="background:var(--success-bg);border:1px solid #6ee7b7;border-radius:var(--radius-sm);
                                        padding:1.25rem 1.5rem;">
                                <p style="font-weight:600;color:var(--success);margin-bottom:0.25rem;">✅ Interim report already submitted</p>
                                <p style="font-size:0.875rem;color:var(--muted);margin:0;">
                                    You can download it from the table above.
                                </p>
                            </div>
                        <?php else: ?>
                            <form method="POST" enctype="multipart/form-data">
                                <div class="upload-zone" onclick="document.getElementById('interimFile').click()">
                                    <div style="font-size:3rem;margin-bottom:0.75rem;">📤</div>
                                    <p><strong>Click to upload your interim report</strong></p>
                                    <p>PDF format, maximum 15 MB</p>
                                </div>

                                <input id="interimFile" type="file" name="interim_report" accept=".pdf"
                                       style="display:none" onchange="showInterimFile(this)">

                                <div id="interimFileName"
                                     style="margin-top:0.875rem;color:var(--muted);font-size:0.875rem;"></div>

                                <div style="display:flex;justify-content:flex-end;gap:1rem;margin-top:1.25rem;">
                                    <button type="button" class="btn btn-ghost"
                                            onclick="document.getElementById('interimFile').value=''; document.getElementById('interimFileName').textContent=''">
                                        Clear
                                    </button>
                                    <button type="submit" name="submit_interim" value="1" class="btn btn-primary">
                                        Submit Interim Report →
                                    </button>
                                </div>
                            </form>

                            <script>
                                function showInterimFile(input) {
                                    const el = document.getElementById('interimFileName');
                                    if (!input.files || !input.files[0]) { el.textContent = ''; return; }
                                    el.textContent = 'Selected: ' + input.files[0].name + ' (' + Math.round(input.files[0].size/1024) + ' KB)';
                                }
                            </script>
                        <?php endif; ?>
                    </div>

                    <hr style="border:none;border-top:1px solid var(--border);margin:1.5rem 0;">

                    <!-- final report upload -->
                    <div>
                        <h4 style="margin-bottom:0.75rem;">Submit Final Report</h4>

                        <?php if ($finalDoc): ?>
                            <div style="background:var(--success-bg);border:1px solid #6ee7b7;border-radius:var(--radius-sm);
                                        padding:1.25rem 1.5rem;">
                                <p style="font-weight:600;color:var(--success);margin-bottom:0.25rem;">✅ Final report already submitted</p>
                                <p style="font-size:0.875rem;color:var(--muted);margin:0;">
                                    You can download it from the table above.
                                </p>
                            </div>
                        <?php else: ?>
                            <form method="POST" enctype="multipart/form-data">
                                <div class="upload-zone" onclick="document.getElementById('finalFile').click()">
                                    <div style="font-size:3rem;margin-bottom:0.75rem;">📤</div>
                                    <p><strong>Click to upload your final report</strong></p>
                                    <p>PDF format, maximum 15 MB</p>
                                </div>

                                <input id="finalFile" type="file" name="final_report" accept=".pdf"
                                       style="display:none" onchange="showFinalFile(this)">

                                <div id="finalFileName"
                                     style="margin-top:0.875rem;color:var(--muted);font-size:0.875rem;"></div>

                                <div style="display:flex;justify-content:flex-end;gap:1rem;margin-top:1.25rem;">
                                    <button type="button" class="btn btn-ghost"
                                            onclick="document.getElementById('finalFile').value=''; document.getElementById('finalFileName').textContent=''">
                                        Clear
                                    </button>
                                    <button type="submit" name="submit_final" value="1" class="btn btn-primary">
                                        Submit Final Report →
                                    </button>
                                </div>
                            </form>

                            <script>
                                function showFinalFile(input) {
                                    const el = document.getElementById('finalFileName');
                                    if (!input.files || !input.files[0]) { el.textContent = ''; return; }
                                    el.textContent = 'Selected: ' + input.files[0].name + ' (' + Math.round(input.files[0].size/1024) + ' KB)';
                                }
                            </script>
                        <?php endif; ?>
                    </div>

                <?php endif; ?>
            </div>
        </div>

    </div><!-- /page-content -->
</div><!-- /main -->

<?php include '../includes/footer.php'; ?>