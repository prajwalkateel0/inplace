<?php
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/storage_helper.php';
require_once '../config/app_config.php';
require_once '../includes/provider_token_helper.php';
loadAppConfig($pdo);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;
require_once __DIR__ . '/../PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/../PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer-master/src/SMTP.php';

requireAuth('student');

$pageTitle    = 'Submit Request';
$pageSubtitle = 'New Placement Authorisation Request';
$activePage   = 'request';
$userId       = authId();

$pendingRequests = 0;

// unread messages for the sidebar badge
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount = (int)$stmt->fetchColumn();

// helper to geocode a UK postcode to lat/lng using postcodes.io
function normaliseUkPostcode(string $pc): string {
    $pc = strtoupper(trim($pc));
    $pc = preg_replace('/\s+/', '', $pc);
    if (strlen($pc) > 3) {
        $pc = substr($pc, 0, -3) . ' ' . substr($pc, -3);
    }
    return trim($pc);
}

function httpGetJson(string $url): ?array {
    // Try file_get_contents first
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 8,
            'header'  => "User-Agent: inplace\r\n"
        ]
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw !== false) {
        $json = json_decode($raw, true);
        return is_array($json) ? $json : null;
    }

    // fallback to cURL if file_get_contents is disabled
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_USERAGENT      => 'inplace',
        ]);
        $raw2 = curl_exec($ch);
        curl_close($ch);

        if ($raw2 !== false) {
            $json = json_decode($raw2, true);
            return is_array($json) ? $json : null;
        }
    }

    return null;
}

function geocodeUkPostcode(?string $postcode): array {
    $postcode = trim((string)$postcode);
    if ($postcode === '') return [null, null, null];

    $pc = normaliseUkPostcode($postcode);

    $data = httpGetJson("https://api.postcodes.io/postcodes/" . urlencode($pc));
    if (!$data || ($data['status'] ?? 0) !== 200 || empty($data['result'])) {
        return [null, null, $pc]; // keep postcode, but no lat/lng
    }

    $lat = $data['result']['latitude'] ?? null;
    $lng = $data['result']['longitude'] ?? null;

    return [$lat, $lng, $pc];
}

// handle the form submission (both draft save and final submit)
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isDraft = isset($_POST['action']) && $_POST['action'] === 'draft';

    $companyName    = trim($_POST['company_name'] ?? '');
    $companyAddress = trim($_POST['company_address'] ?? '');
    $companyCity    = '';
    $companyPostcode= '';
    $sector         = trim($_POST['sector'] ?? '');
    $supName        = trim($_POST['supervisor_name'] ?? '');
    $supEmail       = trim($_POST['supervisor_email'] ?? '');
    $supPhone       = trim($_POST['supervisor_phone'] ?? '');

    // check the supervisor email contains the company name (only on final submit)
    if (!$isDraft && $supEmail && $companyName) {
        $companyWords = preg_split('/[\s\-&.,\/\(\)]+/', strtolower($companyName));
        $emailLower   = strtolower($supEmail);
        $companyInEmail = false;
        foreach ($companyWords as $word) {
            if (strlen($word) > 2 && strpos($emailLower, $word) !== false) {
                $companyInEmail = true;
                break;
            }
        }
        if (!$companyInEmail) {
            $error = "The supervisor email must contain the company name somewhere in the address (e.g., supervisor@deloitte.com or john.deloitte@gmail.com). The email domain should be from the company you are placed at: <strong>" . htmlspecialchars($companyName) . "</strong>.";
        }
    }

    if (!$error) {
        try {
            $pdo->beginTransaction();

            // use coordinates from autocomplete if available, otherwise geocode the postcode
            $postLat = isset($_POST['company_lat']) && is_numeric($_POST['company_lat']) ? (float)$_POST['company_lat'] : null;
            $postLng = isset($_POST['company_lng']) && is_numeric($_POST['company_lng']) ? (float)$_POST['company_lng'] : null;

            if ($postLat !== null && $postLng !== null) {
                $lat          = $postLat;
                $lng          = $postLng;
                $pcNormalised = normaliseUkPostcode($companyPostcode);
            } else {
                [$lat, $lng, $pcNormalised] = geocodeUkPostcode($companyPostcode);
            }

            // look up the company by name only (case-insensitive) so it matches
            // the same record the provider registered with, regardless of postcode
            $stmt = $pdo->prepare("SELECT id, latitude, longitude FROM companies WHERE LOWER(name) = LOWER(?) LIMIT 1");
            $stmt->execute([$companyName]);
            $existingCompany = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existingCompany) {
                $companyId = (int)$existingCompany['id'];
                $stmt = $pdo->prepare("UPDATE companies SET address=?,city=?,postcode=?,sector=?,contact_name=?,contact_email=?,contact_phone=?,latitude=COALESCE(?,latitude),longitude=COALESCE(?,longitude) WHERE id=?");
                $stmt->execute([$companyAddress,$companyCity,$pcNormalised,$sector,$supName,$supEmail,$supPhone,$lat,$lng,$companyId]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO companies (name,address,city,postcode,sector,contact_name,contact_email,contact_phone,latitude,longitude) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$companyName,$companyAddress,$companyCity,$pcNormalised,$sector,$supName,$supEmail,$supPhone,$lat,$lng]);
                $companyId = (int)$pdo->lastInsertId();
            }

            // set status to draft or awaiting_provider depending on which button was clicked
            $placementStatus = $isDraft ? 'draft' : 'awaiting_provider';

            // insert the placement record
            $stmt = $pdo->prepare("INSERT INTO placements (student_id,company_id,role_title,job_description,start_date,end_date,salary,working_pattern,supervisor_name,supervisor_email,supervisor_phone,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $userId, $companyId,
                trim($_POST['role_title'] ?? ''),
                trim($_POST['job_description'] ?? ''),
                $_POST['start_date'] ?? '',
                $_POST['end_date']   ?? '',
                trim($_POST['salary'] ?? ''),
                trim($_POST['working_pattern'] ?? ''),
                $supName, $supEmail, $supPhone,
                $placementStatus
            ]);
            $placementId = (int)$pdo->lastInsertId();

            // handle any documents attached to the request (e.g. offer letter)
            if (!empty($_FILES['documents']['name'][0])) {
                foreach ($_FILES['documents']['tmp_name'] as $i => $tmp) {
                    if (!$tmp) continue;
                    $original = $_FILES['documents']['name'][$i];
                    $safe     = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $original);
                    $destRel  = 'assets/uploads/' . $safe;
                    if (storeUploadedFile(['tmp_name' => $tmp, 'name' => $original], $destRel)) {
                        $size = round($_FILES['documents']['size'][$i] / 1024) . ' KB';
                        $stmt = $pdo->prepare("INSERT INTO documents (placement_id,uploaded_by,doc_type,file_name,file_path,file_size) VALUES (?,?,'offer_letter',?,?,?)");
                        $stmt->execute([$placementId, $userId, $original, $destRel, $size]);
                    }
                }
            }

            // log this action to the audit trail
            $stmt = $pdo->prepare("INSERT INTO audit_log (user_id,action,table_affected,record_id,ip_address) VALUES (?,?,'placements',?,?)");
            $stmt->execute([$userId, $isDraft ? 'saved_draft' : 'submitted_placement_request', $placementId, $_SERVER['REMOTE_ADDR'] ?? '']);

            $pdo->commit();

            // send a notification email to the provider when submitting (not for drafts)
            if (!$isDraft) {
                // get the student's name and email
                $stmt = $pdo->prepare("SELECT full_name, email FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $studentRow  = $stmt->fetch();
                $studentName = $studentRow['full_name'] ?? '';
                $studentEmail = $studentRow['email'] ?? '';

                $scheme      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host        = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $providerRequestsUrl = $scheme . '://' . $host . '/inplace/provider/requests.php';
                $providerRegisterUrl = $scheme . '://' . $host . '/inplace/provider-register.php';

                // check if the company already has a provider account on the system
                $stmt = $pdo->prepare("SELECT email, full_name FROM users WHERE role='provider' AND company_id=? AND is_active=1 LIMIT 1");
                $stmt->execute([$companyId]);
                $providerUser = $stmt->fetch();

                $toEmail = $providerUser ? $providerUser['email'] : $supEmail;
                $toName  = $providerUser ? $providerUser['full_name'] : $supName;

                // generate a one-time token so the provider can approve/reject without logging in
                $confirmUrl   = generateProviderToken($pdo, $placementId, $toEmail);
                $actionUrl    = $providerUser ? $providerRequestsUrl : $providerRegisterUrl . '?company=' . urlencode($companyName) . '&email=' . urlencode($supEmail);
                $actionLabel  = $providerUser ? 'Review in InPlace' : 'Register & Review Request';
                $extraNote    = $providerUser ? '' : "<p style='color:#6b7a8d;font-size:0.85rem;margin-top:1rem;'>You have not yet registered on InPlace. You can still approve or decline using the quick-confirm link above, or click below to create an account for full access.</p>";

                $mailCfg = require __DIR__ . '/../config/email_config.php';
                $htmlBody = "
                <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;'>
                  <div style='background-color:#0c1b33;padding:2rem;text-align:center;'>
                    <h1 style='color:#ffffff;font-size:1.5rem;margin:0;'>InPlace</h1>
                    <p style='color:rgba(255,255,255,0.8);margin:0.5rem 0 0;font-size:0.9rem;'>New Placement Authorisation Request</p>
                  </div>
                  <div style='padding:2rem;'>
                    <p style='color:#374151;font-size:1rem;margin-bottom:1.5rem;'>Dear " . htmlspecialchars($toName) . ",</p>
                    <p style='color:#374151;font-size:1rem;margin-bottom:1.5rem;'>A student has submitted a placement request for your company and requires your authorisation.</p>
                    <table style='width:100%;border-collapse:collapse;margin-bottom:1.5rem;'>
                      <tr style='background:#f8f5f0;'><td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;width:40%;border-bottom:1px solid #e2e8f0;'>Student</td><td style='padding:0.75rem 1rem;color:#374151;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars($studentName) . "</td></tr>
                      <tr><td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;border-bottom:1px solid #e2e8f0;'>Company</td><td style='padding:0.75rem 1rem;color:#374151;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars($companyName) . "</td></tr>
                      <tr style='background:#f8f5f0;'><td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;border-bottom:1px solid #e2e8f0;'>Role</td><td style='padding:0.75rem 1rem;color:#374151;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars($_POST['role_title'] ?? '') . "</td></tr>
                      <tr><td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;border-bottom:1px solid #e2e8f0;'>Start Date</td><td style='padding:0.75rem 1rem;color:#374151;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars($_POST['start_date'] ?? '') . "</td></tr>
                      <tr style='background:#f8f5f0;'><td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;'>End Date</td><td style='padding:0.75rem 1rem;color:#374151;'>" . htmlspecialchars($_POST['end_date'] ?? '') . "</td></tr>
                    </table>
                    <div style='text-align:center;margin:2rem 0;'>
                      <a href='" . $confirmUrl . "' style='display:inline-block;padding:0.875rem 2rem;background-color:#059669;color:#ffffff !important;text-decoration:none;border-radius:10px;font-weight:700;font-size:1rem;margin-bottom:0.75rem;'>
                        ✓ Approve or Decline (no login needed)
                      </a><br>
                      <a href='" . $actionUrl . "' style='display:inline-block;padding:0.625rem 1.5rem;background-color:#0c1b33;color:#ffffff !important;text-decoration:none;border-radius:10px;font-weight:600;font-size:0.9rem;margin-top:0.5rem;'>
                        " . $actionLabel . "
                      </a>
                    </div>
                    $extraNote
                    <p style='color:#6b7a8d;font-size:0.8rem;text-align:center;margin-top:1rem;'>
                      The quick-confirm link expires in 7 days and can only be used once.
                    </p>
                    <p style='color:#6b7a8d;font-size:0.85rem;text-align:center;'>This is an automated notification from InPlace.</p>
                  </div>
                </div>";

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
                    $mail->addAddress($toEmail, $toName);
                    $mail->isHTML(true);
                    $mail->Subject = 'InPlace - Placement Authorisation Required: ' . ($studentName ?? '') . ' at ' . $companyName;
                    $mail->Body    = $htmlBody;
                    $mail->AltBody = "New placement request from $studentName at $companyName. Review at: $actionUrl";
                    $mail->send();
                } catch (MailException $e) {
                    error_log('Provider notification email failed: ' . $mail->ErrorInfo);
                }

                // send confirmation email to the student
                if ($studentEmail) {
                    $studentDashboardUrl = $scheme . '://' . $host . '/inplace/student/dashboard.php';
                    $studentHtml = "
                    <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;'>
                      <div style='background-color:#0c1b33;padding:2rem;text-align:center;'>
                        <h1 style='color:#ffffff;font-size:1.5rem;margin:0;'>InPlace</h1>
                        <p style='color:rgba(255,255,255,0.8);margin:0.5rem 0 0;font-size:0.9rem;'>Placement Request Submitted</p>
                      </div>
                      <div style='padding:2rem;'>
                        <p style='color:#374151;font-size:1rem;margin-bottom:1rem;'>Dear " . htmlspecialchars($studentName) . ",</p>
                        <p style='color:#374151;font-size:1rem;margin-bottom:1.5rem;'>
                          Your placement request has been successfully submitted. The placement provider has been notified and will review your request shortly.
                        </p>
                        <table style='width:100%;border-collapse:collapse;margin-bottom:1.5rem;'>
                          <tr style='background:#f8f5f0;'><td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;width:40%;border-bottom:1px solid #e2e8f0;'>Company</td><td style='padding:0.75rem 1rem;color:#374151;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars($companyName) . "</td></tr>
                          <tr><td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;border-bottom:1px solid #e2e8f0;'>Role</td><td style='padding:0.75rem 1rem;color:#374151;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars($_POST['role_title'] ?? '') . "</td></tr>
                          <tr style='background:#f8f5f0;'><td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;border-bottom:1px solid #e2e8f0;'>Start Date</td><td style='padding:0.75rem 1rem;color:#374151;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars($_POST['start_date'] ?? '') . "</td></tr>
                          <tr><td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;'>End Date</td><td style='padding:0.75rem 1rem;color:#374151;'>" . htmlspecialchars($_POST['end_date'] ?? '') . "</td></tr>
                        </table>
                        <p style='color:#374151;font-size:0.95rem;margin-bottom:1.5rem;'>You will receive another email once the provider has reviewed your request. You can also track the status of your placement in your dashboard.</p>
                        <div style='text-align:center;margin:2rem 0;'>
                          <a href='$studentDashboardUrl' style='display:inline-block;padding:0.875rem 2rem;background-color:#0c1b33;color:#ffffff !important;text-decoration:none;border-radius:10px;font-weight:700;font-size:1rem;'>
                            View My Dashboard
                          </a>
                        </div>
                        <p style='color:#6b7a8d;font-size:0.85rem;text-align:center;'>This is an automated notification from InPlace.</p>
                      </div>
                    </div>";

                    $mail2 = new PHPMailer(true);
                    try {
                        $mail2->isSMTP();
                        $mail2->Host       = $mailCfg['smtp_host'];
                        $mail2->SMTPAuth   = true;
                        $mail2->Username   = $mailCfg['smtp_user'];
                        $mail2->Password   = $mailCfg['smtp_pass'];
                        $mail2->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                        $mail2->Port       = $mailCfg['smtp_port'];
                        $mail2->CharSet    = 'UTF-8';
                        $mail2->setFrom($mailCfg['from_email'], $mailCfg['from_name']);
                        $mail2->addAddress($studentEmail, $studentName);
                        $mail2->isHTML(true);
                        $mail2->Subject = 'InPlace - Your Placement Request at ' . $companyName . ' Has Been Submitted';
                        $mail2->Body    = $studentHtml;
                        $mail2->AltBody = "Your placement request at $companyName has been submitted and is awaiting provider approval.";
                        $mail2->send();
                    } catch (MailException $e) {
                        error_log('Student confirmation email failed: ' . $mail2->ErrorInfo);
                    }
                }
            }

            $success = $isDraft
                ? "Draft saved successfully! You can come back and submit it later."
                : "Your placement request has been submitted! A notification was sent to: " . htmlspecialchars($toEmail ?? 'unknown') . ". The provider has been notified to confirm the details.";

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Something went wrong. Please try again. (" . $e->getMessage() . ")";
        }
    }
}

// if ?edit=id is in the URL, load that draft so the form is pre-filled
$draftData = null;
$editId    = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = $pdo->prepare("
        SELECT p.*, c.name AS company_name, c.address AS company_address,
               c.sector, c.contact_name AS supervisor_name,
               c.contact_email AS supervisor_email, c.contact_phone AS supervisor_phone,
               c.latitude AS company_lat, c.longitude AS company_lng
        FROM placements p
        JOIN companies c ON p.company_id = c.id
        WHERE p.id = ? AND p.student_id = ? AND p.status = 'draft'
        LIMIT 1
    ");
    $stmt->execute([$editId, $userId]);
    $draftData = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// handle updating an existing draft when the form is re-submitted with an edit_placement_id
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['edit_placement_id'])) {
    $editPlacementId = (int)$_POST['edit_placement_id'];
    $isDraft = isset($_POST['action']) && $_POST['action'] === 'draft';

    $companyName    = trim($_POST['company_name'] ?? '');
    $companyAddress = trim($_POST['company_address'] ?? '');
    $sector         = trim($_POST['sector'] ?? '');
    $supName        = trim($_POST['supervisor_name'] ?? '');
    $supEmail       = trim($_POST['supervisor_email'] ?? '');
    $supPhone       = trim($_POST['supervisor_phone'] ?? '');

    try {
        $pdo->beginTransaction();

        $postLat = isset($_POST['company_lat']) && is_numeric($_POST['company_lat']) ? (float)$_POST['company_lat'] : null;
        $postLng = isset($_POST['company_lng']) && is_numeric($_POST['company_lng']) ? (float)$_POST['company_lng'] : null;

        // get the company linked to this draft
        $stmt = $pdo->prepare("SELECT company_id FROM placements WHERE id = ? AND student_id = ? AND status = 'draft'");
        $stmt->execute([$editPlacementId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) throw new Exception("Draft not found.");
        $companyId = (int)$row['company_id'];

        // update company details
        $pdo->prepare("
            UPDATE companies SET name=?, address=?, sector=?,
                contact_name=?, contact_email=?, contact_phone=?,
                latitude=COALESCE(?,latitude), longitude=COALESCE(?,longitude)
            WHERE id=?
        ")->execute([$companyName, $companyAddress, $sector, $supName, $supEmail, $supPhone, $postLat, $postLng, $companyId]);

        // update placement details and set the new status
        $newStatus = $isDraft ? 'draft' : 'awaiting_provider';
        $pdo->prepare("
            UPDATE placements SET
                role_title=?, job_description=?, start_date=?, end_date=?,
                salary=?, working_pattern=?, supervisor_name=?, supervisor_email=?,
                supervisor_phone=?, status=?
            WHERE id=? AND student_id=?
        ")->execute([
            trim($_POST['role_title'] ?? ''),
            trim($_POST['job_description'] ?? ''),
            $_POST['start_date'] ?? '',
            $_POST['end_date']   ?? '',
            trim($_POST['salary'] ?? ''),
            trim($_POST['working_pattern'] ?? ''),
            $supName, $supEmail, $supPhone,
            $newStatus,
            $editPlacementId, $userId
        ]);

        // save any new documents uploaded with this edit
        if (!empty($_FILES['documents']['name'][0])) {
            foreach ($_FILES['documents']['tmp_name'] as $i => $tmp) {
                if (!$tmp) continue;
                $original = $_FILES['documents']['name'][$i];
                $safe     = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $original);
                $destRel  = 'assets/uploads/' . $safe;
                if (storeUploadedFile(['tmp_name' => $tmp, 'name' => $original], $destRel)) {
                    $size = round($_FILES['documents']['size'][$i] / 1024) . ' KB';
                    $pdo->prepare("INSERT INTO documents (placement_id,uploaded_by,doc_type,file_name,file_path,file_size,status) VALUES (?,?,'offer_letter',?,?,?,'pending_review')")
                        ->execute([$editPlacementId, $userId, $original, $destRel, $size]);
                }
            }
        }

        $pdo->commit();

        if (!$isDraft) {
            // email the provider when the student submits from draft
            $stmt = $pdo->prepare("SELECT full_name, email FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $studentRow   = $stmt->fetch();
            $studentName  = $studentRow['full_name'] ?? '';
            $studentEmail = $studentRow['email'] ?? '';

            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

            $stmt = $pdo->prepare("SELECT email, full_name FROM users WHERE role='provider' AND company_id=? AND is_active=1 LIMIT 1");
            $stmt->execute([$companyId]);
            $providerUser = $stmt->fetch();

            $toEmail = $providerUser ? $providerUser['email'] : $supEmail;
            $toName  = $providerUser ? $providerUser['full_name'] : $supName;

            if ($toEmail) {
                require_once '../includes/provider_token_helper.php';
                $confirmUrl  = generateProviderToken($pdo, $editPlacementId, $toEmail);
                $actionUrl   = $providerUser ? ($scheme . '://' . $host . '/inplace/provider/requests.php') : ($scheme . '://' . $host . '/inplace/provider-register.php?company=' . urlencode($companyName) . '&email=' . urlencode($supEmail));
                $actionLabel = $providerUser ? 'Review in InPlace' : 'Register & Review Request';
                $mailCfg = require __DIR__ . '/../config/email_config.php';

                $providerHtml = "
                <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;'>
                  <div style='background-color:#0c1b33;padding:2rem;text-align:center;'>
                    <h1 style='color:#ffffff;font-size:1.5rem;margin:0;'>InPlace</h1>
                    <p style='color:rgba(255,255,255,0.8);margin:0.5rem 0 0;font-size:0.9rem;'>New Placement Authorisation Request</p>
                  </div>
                  <div style='padding:2rem;'>
                    <p style='color:#374151;font-size:1rem;margin-bottom:1.5rem;'>Dear " . htmlspecialchars($toName) . ",</p>
                    <p style='color:#374151;font-size:1rem;margin-bottom:1.5rem;'>A student has submitted a placement request for your company and requires your authorisation.</p>
                    <table style='width:100%;border-collapse:collapse;margin-bottom:1.5rem;'>
                      <tr style='background:#f8f5f0;'><td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;width:40%;border-bottom:1px solid #e2e8f0;'>Student</td><td style='padding:0.75rem 1rem;color:#374151;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars($studentName) . "</td></tr>
                      <tr><td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;border-bottom:1px solid #e2e8f0;'>Company</td><td style='padding:0.75rem 1rem;color:#374151;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars($companyName) . "</td></tr>
                      <tr style='background:#f8f5f0;'><td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;border-bottom:1px solid #e2e8f0;'>Role</td><td style='padding:0.75rem 1rem;color:#374151;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars(trim($_POST['role_title'] ?? '')) . "</td></tr>
                      <tr><td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;border-bottom:1px solid #e2e8f0;'>Start Date</td><td style='padding:0.75rem 1rem;color:#374151;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars($_POST['start_date'] ?? '') . "</td></tr>
                      <tr style='background:#f8f5f0;'><td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;'>End Date</td><td style='padding:0.75rem 1rem;color:#374151;'>" . htmlspecialchars($_POST['end_date'] ?? '') . "</td></tr>
                    </table>
                    <div style='text-align:center;margin:2rem 0;'>
                      <a href='$confirmUrl' style='display:inline-block;padding:0.875rem 2rem;background-color:#059669;color:#ffffff !important;text-decoration:none;border-radius:10px;font-weight:700;font-size:1rem;margin-bottom:0.75rem;'>
                        Approve or Decline (no login needed)
                      </a><br>
                      <a href='$actionUrl' style='display:inline-block;padding:0.625rem 1.5rem;background-color:#0c1b33;color:#ffffff !important;text-decoration:none;border-radius:10px;font-weight:600;font-size:0.9rem;margin-top:0.5rem;'>
                        $actionLabel
                      </a>
                    </div>
                    <p style='color:#6b7a8d;font-size:0.8rem;text-align:center;'>The quick-confirm link expires in 7 days and can only be used once.</p>
                    <p style='color:#6b7a8d;font-size:0.85rem;text-align:center;'>This is an automated notification from InPlace.</p>
                  </div>
                </div>";

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
                    $mail->addAddress($toEmail, $toName);
                    $mail->isHTML(true);
                    $mail->Subject = 'InPlace - Placement Authorisation Required: ' . $studentName . ' at ' . $companyName;
                    $mail->Body    = $providerHtml;
                    $mail->AltBody = "New placement request from $studentName at $companyName. Approve or Decline: $confirmUrl";
                    $mail->send();
                } catch (MailException $e) {
                    error_log('Provider notification email failed: ' . $mail->ErrorInfo);
                }

                // send confirmation email to the student
                if ($studentEmail) {
                    $studentDashboardUrl = $scheme . '://' . $host . '/inplace/student/dashboard.php';
                    $studentHtml = "
                    <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;'>
                      <div style='background-color:#0c1b33;padding:2rem;text-align:center;'>
                        <h1 style='color:#ffffff;font-size:1.5rem;margin:0;'>InPlace</h1>
                        <p style='color:rgba(255,255,255,0.8);margin:0.5rem 0 0;font-size:0.9rem;'>Placement Request Submitted</p>
                      </div>
                      <div style='padding:2rem;'>
                        <p style='color:#374151;font-size:1rem;margin-bottom:1rem;'>Dear " . htmlspecialchars($studentName) . ",</p>
                        <p style='color:#374151;font-size:1rem;margin-bottom:1.5rem;'>
                          Your placement request has been successfully submitted. The placement provider has been notified and will review your request shortly.
                        </p>
                        <table style='width:100%;border-collapse:collapse;margin-bottom:1.5rem;'>
                          <tr style='background:#f8f5f0;'><td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;width:40%;border-bottom:1px solid #e2e8f0;'>Company</td><td style='padding:0.75rem 1rem;color:#374151;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars($companyName) . "</td></tr>
                          <tr><td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;border-bottom:1px solid #e2e8f0;'>Role</td><td style='padding:0.75rem 1rem;color:#374151;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars(trim($_POST['role_title'] ?? '')) . "</td></tr>
                          <tr style='background:#f8f5f0;'><td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;border-bottom:1px solid #e2e8f0;'>Start Date</td><td style='padding:0.75rem 1rem;color:#374151;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars($_POST['start_date'] ?? '') . "</td></tr>
                          <tr><td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;'>End Date</td><td style='padding:0.75rem 1rem;color:#374151;'>" . htmlspecialchars($_POST['end_date'] ?? '') . "</td></tr>
                        </table>
                        <p style='color:#374151;font-size:0.95rem;margin-bottom:1.5rem;'>You will receive another email once the provider has reviewed your request.</p>
                        <div style='text-align:center;margin:2rem 0;'>
                          <a href='$studentDashboardUrl' style='display:inline-block;padding:0.875rem 2rem;background-color:#0c1b33;color:#ffffff !important;text-decoration:none;border-radius:10px;font-weight:700;font-size:1rem;'>
                            View My Dashboard
                          </a>
                        </div>
                        <p style='color:#6b7a8d;font-size:0.85rem;text-align:center;'>This is an automated notification from InPlace.</p>
                      </div>
                    </div>";

                    $mail2 = new PHPMailer(true);
                    try {
                        $mail2->isSMTP();
                        $mail2->Host       = $mailCfg['smtp_host'];
                        $mail2->SMTPAuth   = true;
                        $mail2->Username   = $mailCfg['smtp_user'];
                        $mail2->Password   = $mailCfg['smtp_pass'];
                        $mail2->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                        $mail2->Port       = $mailCfg['smtp_port'];
                        $mail2->CharSet    = 'UTF-8';
                        $mail2->setFrom($mailCfg['from_email'], $mailCfg['from_name']);
                        $mail2->addAddress($studentEmail, $studentName);
                        $mail2->isHTML(true);
                        $mail2->Subject = 'InPlace - Your Placement Request at ' . $companyName . ' Has Been Submitted';
                        $mail2->Body    = $studentHtml;
                        $mail2->AltBody = "Your placement request at $companyName has been submitted and is awaiting provider approval.";
                        $mail2->send();
                    } catch (MailException $e) {
                        error_log('Student confirmation email failed: ' . $mail2->ErrorInfo);
                    }
                }
            }
        }

        $success = $isDraft
            ? "Draft updated successfully!"
            : "Your placement request has been submitted! A notification was sent to: " . htmlspecialchars($toEmail ?? 'unknown') . ".";

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "Update failed: " . $e->getMessage();
    }
}

// check if the student already has a placement in progress
$stmt = $pdo->prepare("
    SELECT id, status FROM placements
    WHERE student_id = ?
      AND status NOT IN ('rejected','terminated')
    ORDER BY created_at DESC LIMIT 1
");
$stmt->execute([$userId]);
$existingPlacement = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>

    <div class="page-content">

        <?php if ($success): ?>
        <div style="background:var(--success-bg);border:1px solid #6ee7b7;border-radius:var(--radius);
                    padding:1.5rem 2rem;margin-bottom:2rem;display:flex;align-items:center;gap:1rem;">
            <span style="font-size:1.75rem;">🎉</span>
            <div>
                <p style="font-weight:600;color:var(--success);margin-bottom:0.25rem;">Request Submitted!</p>
                <p style="font-size:0.9rem;color:var(--success);"><?= htmlspecialchars($success) ?></p>
            </div>
            <a href="/inplace/student/dashboard.php" class="btn btn-success btn-sm" style="margin-left:auto;">
                Back to Dashboard →
            </a>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div style="background:var(--danger-bg);border:1px solid #fca5a5;border-radius:var(--radius);
                    padding:1.25rem 2rem;margin-bottom:2rem;">
            <p style="color:var(--danger);font-weight:500;">⚠️ <?= htmlspecialchars($error) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($existingPlacement && !in_array($existingPlacement['status'], ['rejected','terminated'])): ?>
        <div style="background:var(--warning-bg);border:1px solid #fcd34d;border-radius:var(--radius);
                    padding:1.25rem 2rem;margin-bottom:2rem;display:flex;align-items:center;gap:1rem;">
            <span style="font-size:1.5rem;">⚠️</span>
            <div>
                <p style="font-weight:600;color:var(--warning);">You already have a placement request</p>
                <p style="font-size:0.875rem;color:var(--warning);">
                    Status: <strong><?= ucwords(str_replace('_', ' ', $existingPlacement['status'])) ?></strong>.
                    Submitting a new one will create an additional request.
                </p>
            </div>
        </div>
        <?php endif; ?>

        <div class="panel">
            <div class="panel-header">
                <div>
                    <h3><?= $draftData ? 'Edit Draft Request' : 'New Placement Authorisation Request' ?></h3>
                    <p>All fields marked * are required. The provider will be asked to confirm the details.</p>
                </div>
                <span class="badge badge-pending">Draft</span>
            </div>

            <div class="panel-body">
                <form method="POST" enctype="multipart/form-data">
                    <?php if ($draftData): ?>
                    <input type="hidden" name="edit_placement_id" value="<?= (int)$draftData['id'] ?>">
                    <?php endif; ?>

                    <!-- SECTION 1 -->
                    <div style="font-size:0.8125rem;font-weight:700;text-transform:uppercase;
                                letter-spacing:0.08em;color:var(--muted);margin-bottom:1.25rem;
                                padding-bottom:0.75rem;border-bottom:2px solid var(--border);">
                        1 · Company &amp; Role Information
                    </div>

                    <div class="form-grid" style="margin-bottom:2rem;">

                        <div class="form-group">
                            <label>Company Name <span style="color:var(--danger);">*</span></label>
                            <input type="text" name="company_name" required
                                   placeholder="e.g., Rolls-Royce plc"
                                   value="<?= htmlspecialchars($_POST['company_name'] ?? $draftData['company_name'] ?? '') ?>">
                        </div>

                        <div class="form-group full-col">
                            <label>Full Company Address</label>
                            <div style="position:relative;">
                                <input type="text" id="addressSearch" name="company_address"
                                       autocomplete="off"
                                       placeholder="Start typing a street, city or postcode…"
                                       style="width:100%;"
                                       value="<?= htmlspecialchars($_POST['company_address'] ?? $draftData['company_address'] ?? '') ?>">
                                <div id="addressSuggestions"
                                     style="display:none;position:absolute;top:100%;left:0;right:0;
                                            background:white;border:2px solid var(--border);
                                            border-top:none;border-radius:0 0 10px 10px;
                                            box-shadow:0 4px 12px rgba(0,0,0,0.15);
                                            z-index:999;max-height:220px;overflow-y:auto;"></div>
                            </div>
                            <input type="hidden" name="company_lat" id="company_lat"
                                   value="<?= htmlspecialchars($_POST['company_lat'] ?? $draftData['company_lat'] ?? '') ?>">
                            <input type="hidden" name="company_lng" id="company_lng"
                                   value="<?= htmlspecialchars($_POST['company_lng'] ?? $draftData['company_lng'] ?? '') ?>">
                            <small style="color:var(--muted);">Type an address or postcode and select from the suggestions.</small>
                        </div>

                        <div class="form-group">
                            <label>Industry / Sector</label>
                            <select name="sector">
                                <option value="">Select sector</option>
                                <?php
                                $sectors = [
                                    'Technology & Software',
                                    'Engineering & Manufacturing',
                                    'Finance & Banking',
                                    'Healthcare & Life Sciences',
                                    'Consultancy',
                                    'Media & Communications',
                                    'Retail & E-commerce',
                                    'Public Sector / Government',
                                    'Education & Research',
                                    'Other',
                                ];
                                foreach ($sectors as $s) {
                                    $sel = (($_POST['sector'] ?? $draftData['sector'] ?? '') === $s) ? 'selected' : '';
                                    echo "<option value=\"" . htmlspecialchars($s) . "\" $sel>" . htmlspecialchars($s) . "</option>";
                                }
                                ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Role / Job Title <span style="color:var(--danger);">*</span></label>
                            <input type="text" name="role_title" required
                                   placeholder="e.g., Software Engineering Intern"
                                   value="<?= htmlspecialchars($_POST['role_title'] ?? $draftData['role_title'] ?? '') ?>">
                        </div>

                        <div class="form-group full-col">
                            <label>Job Description <span style="color:var(--danger);">*</span></label>
                            <textarea name="job_description" required rows="4"
                                      placeholder="Describe the role, responsibilities, technologies, and skills involved..."><?= htmlspecialchars($_POST['job_description'] ?? $draftData['job_description'] ?? '') ?></textarea>
                        </div>

                    </div>

                    <!-- SECTION 2 -->
                    <div style="font-size:0.8125rem;font-weight:700;text-transform:uppercase;
                                letter-spacing:0.08em;color:var(--muted);margin-bottom:1.25rem;
                                padding-bottom:0.75rem;border-bottom:2px solid var(--border);">
                        2 · Placement Dates &amp; Terms
                    </div>

                    <div class="form-grid" style="margin-bottom:2rem;">

                        <div class="form-group">
                            <label>Start Date <span style="color:var(--danger);">*</span></label>
                            <input type="date" name="start_date" required
                                   value="<?= htmlspecialchars($_POST['start_date'] ?? $draftData['start_date'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label>End Date <span style="color:var(--danger);">*</span></label>
                            <input type="date" name="end_date" required
                                   value="<?= htmlspecialchars($_POST['end_date'] ?? $draftData['end_date'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label>Salary (Annual)</label>
                            <input type="text" name="salary"
                                   placeholder="e.g., £22,000"
                                   value="<?= htmlspecialchars($_POST['salary'] ?? $draftData['salary'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label>Working Pattern</label>
                            <select name="working_pattern">
                                <?php
                                $patterns = [
                                    "Full-time (37.5 hrs/week)",
                                    "Full-time (40 hrs/week)",
                                    "Hybrid",
                                    "Remote",
                                    "Part-time"
                                ];
                                $cur = $_POST['working_pattern'] ?? $draftData['working_pattern'] ?? "Full-time (37.5 hrs/week)";
                                foreach ($patterns as $p) {
                                    $sel = ($cur === $p) ? 'selected' : '';
                                    echo "<option value=\"" . htmlspecialchars($p) . "\" $sel>" . htmlspecialchars($p) . "</option>";
                                }
                                ?>
                            </select>
                        </div>

                    </div>

                    <!-- SECTION 3 -->
                    <div style="font-size:0.8125rem;font-weight:700;text-transform:uppercase;
                                letter-spacing:0.08em;color:var(--muted);margin-bottom:1.25rem;
                                padding-bottom:0.75rem;border-bottom:2px solid var(--border);">
                        3 · Supervisor Details
                    </div>

                    <div class="form-grid" style="margin-bottom:2rem;">

                        <div class="form-group">
                            <label>Supervisor Full Name <span style="color:var(--danger);">*</span></label>
                            <input type="text" name="supervisor_name" required
                                   placeholder="e.g., Mark Henderson"
                                   value="<?= htmlspecialchars($_POST['supervisor_name'] ?? $draftData['supervisor_name'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label>Supervisor Job Title</label>
                            <input type="text" name="supervisor_job_title"
                                   placeholder="e.g., Engineering Manager"
                                   value="<?= htmlspecialchars($_POST['supervisor_job_title'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label>Supervisor Email <span style="color:var(--danger);">*</span></label>
                            <input type="email" name="supervisor_email" required
                                   placeholder="supervisor@company.com"
                                   value="<?= htmlspecialchars($_POST['supervisor_email'] ?? $draftData['supervisor_email'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label>Supervisor Phone</label>
                            <input type="tel" name="supervisor_phone"
                                   placeholder="+44 7700 000000"
                                   value="<?= htmlspecialchars($_POST['supervisor_phone'] ?? $draftData['supervisor_phone'] ?? '') ?>">
                        </div>

                    </div>

                    <!-- SECTION 4 -->
                    <div style="font-size:0.8125rem;font-weight:700;text-transform:uppercase;
                                letter-spacing:0.08em;color:var(--muted);margin-bottom:1.25rem;
                                padding-bottom:0.75rem;border-bottom:2px solid var(--border);">
                        4 · Supporting Documents
                    </div>

                    <div style="margin-bottom:2rem;">
                        <div class="upload-zone"
                             onclick="document.getElementById('docInput').click()"
                             id="dropZone">
                            <div style="font-size:2.5rem;margin-bottom:0.75rem;">📎</div>
                            <p><strong>Click to upload</strong> or drag and drop</p>
                            <p style="font-size:0.8125rem;margin-top:0.25rem;color:var(--muted);">
                                Offer letter, job description PDF (max 10 MB each)
                            </p>
                        </div>
                        <input id="docInput" type="file" name="documents[]"
                               multiple accept=".pdf,.doc,.docx"
                               style="display:none;"
                               onchange="showFiles(this)">
                        <div id="fileList" style="margin-top:0.875rem;display:flex;flex-direction:column;gap:0.5rem;"></div>
                    </div>

                    <div class="divider"></div>
                    <div style="display:flex;justify-content:flex-end;gap:1rem;margin-top:1.5rem;">
                        <a href="/inplace/student/dashboard.php" class="btn btn-ghost">← Back</a>
                        <button type="submit" name="action" value="draft" class="btn btn-ghost">Save as Draft</button>
                        <button type="submit" class="btn btn-primary">Submit Request →</button>
                    </div>

                </form>
            </div>
        </div>

    </div>
</div>

<!-- Validation error modal -->
<div id="validationModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.45);
     display:none;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:14px;box-shadow:0 8px 40px rgba(0,0,0,0.22);
                max-width:440px;width:90%;padding:2rem 2rem 1.5rem;animation:modalIn .18s ease;">
        <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1rem;">
            <span style="font-size:1.5rem;line-height:1;">⚠️</span>
            <h3 style="margin:0;font-size:1.1rem;color:#0c1b33;">Validation Error</h3>
        </div>
        <p id="validationMsg" style="margin:0 0 1.5rem;color:#374151;font-size:0.95rem;line-height:1.55;"></p>
        <div style="display:flex;justify-content:flex-end;">
            <button onclick="closeValidationModal()"
                    style="padding:0.6rem 1.5rem;background:#0c1b33;color:#fff;border:none;
                           border-radius:8px;font-size:0.95rem;font-weight:600;cursor:pointer;">
                OK
            </button>
        </div>
    </div>
</div>
<style>
@keyframes modalIn { from { opacity:0; transform:translateY(-12px); } to { opacity:1; transform:none; } }
</style>

<script>
// Show selected file names under the upload zone
function showFiles(input) {
    const list = document.getElementById('fileList');
    list.innerHTML = '';
    Array.from(input.files).forEach(f => {
        const div = document.createElement('div');
        div.style.cssText =
            'display:flex;align-items:center;gap:0.75rem;padding:0.75rem 1rem;' +
            'background:var(--success-bg);border-radius:8px;border:1px solid #6ee7b7;';
        div.innerHTML = `
            <span style="font-size:1.25rem;">📄</span>
            <span style="font-size:0.875rem;font-weight:500;color:var(--success);">${escapeHtml(f.name)}</span>
            <span style="font-size:0.8125rem;color:var(--muted);margin-left:auto;">${(f.size/1024).toFixed(0)} KB</span>
        `;
        list.appendChild(div);
    });
}

function escapeHtml(str){
  return String(str).replace(/[&<>"']/g, s => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[s]));
}

// Drag and drop support
const zone = document.getElementById('dropZone');
if (zone) {
    zone.addEventListener('dragover', e => {
        e.preventDefault();
        zone.style.borderColor = 'var(--navy)';
        zone.style.background  = '#f0f2f7';
    });
    zone.addEventListener('dragleave', () => {
        zone.style.borderColor = 'var(--border)';
        zone.style.background  = 'var(--cream)';
    });
    zone.addEventListener('drop', e => {
        e.preventDefault();
        zone.style.borderColor = 'var(--border)';
        zone.style.background  = 'var(--cream)';
        const input = document.getElementById('docInput');
        input.files = e.dataTransfer.files;
        showFiles(input);
    });
}

function showValidationModal(msg) {
    document.getElementById('validationMsg').textContent = msg;
    const modal = document.getElementById('validationModal');
    modal.style.display = 'flex';
    modal.addEventListener('click', function outsideClick(e) {
        if (e.target === modal) { closeValidationModal(); modal.removeEventListener('click', outsideClick); }
    });
}
function closeValidationModal() {
    document.getElementById('validationModal').style.display = 'none';
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeValidationModal();
});

// Supervisor email must contain company name
document.querySelector('form').addEventListener('submit', function(e) {
    const btn = e.submitter;
    if (btn && btn.value === 'draft') return; // skip for drafts
    const company = (document.querySelector('[name="company_name"]').value || '').toLowerCase();
    const email   = (document.querySelector('[name="supervisor_email"]').value || '').toLowerCase();
    if (!company || !email) return;
    const words = company.split(/[\s\-&.,\/()]+/).filter(w => w.length > 2);
    const ok = words.some(w => email.includes(w));
    if (!ok) {
        e.preventDefault();
        showValidationModal('Supervisor email must contain the company name (e.g. supervisor@' + company.replace(/\s+/g,'') + '.com or john.' + company.replace(/\s+/g,'') + '@gmail.com).');
    }
}, true); // capture phase so it runs before the date check listener

// Date validation: end must be after start
document.querySelector('form').addEventListener('submit', function(e) {
    const start = document.querySelector('[name="start_date"]').value;
    const end   = document.querySelector('[name="end_date"]').value;
    if (start && end && end <= start) {
        e.preventDefault();
        showValidationModal('End date must be after start date.');
    }
});

// address autocomplete using the Nominatim (OpenStreetMap) API
(function () {
    const addrInput   = document.getElementById('addressSearch');
    const addrDrop    = document.getElementById('addressSuggestions');
    if (!addrInput) return;

    let timer = null;

    addrInput.addEventListener('input', function () {
        clearTimeout(timer);
        const q = this.value.trim();
        if (q.length < 3) { addrDrop.style.display = 'none'; return; }
        timer = setTimeout(() => fetchSuggestions(q), 350);
    });

    document.addEventListener('click', function (e) {
        if (!addrInput.contains(e.target) && !addrDrop.contains(e.target)) {
            addrDrop.style.display = 'none';
        }
    });

    function fetchSuggestions(q) {
        const url = 'https://nominatim.openstreetmap.org/search?format=json&countrycodes=gb&addressdetails=1&limit=6&q=' + encodeURIComponent(q);
        fetch(url, { headers: { 'Accept-Language': 'en', 'User-Agent': 'inplace-student-form/1.0' } })
            .then(r => r.json())
            .then(data => showSuggestions(data))
            .catch(() => { addrDrop.style.display = 'none'; });
    }

    function showSuggestions(results) {
        addrDrop.innerHTML = '';
        if (!results || results.length === 0) { addrDrop.style.display = 'none'; return; }
        results.forEach(item => {
            const div = document.createElement('div');
            div.style.cssText = 'padding:0.625rem 1rem;cursor:pointer;font-size:0.875rem;border-bottom:1px solid #f0f0f0;color:#2c3e50;';
            div.textContent = item.display_name;
            div.addEventListener('mouseenter', () => div.style.background = '#f8f5f0');
            div.addEventListener('mouseleave', () => div.style.background = '');
            div.addEventListener('mousedown', e => e.preventDefault()); // prevent blur before click
            div.addEventListener('click', () => selectSuggestion(item));
            addrDrop.appendChild(div);
        });
        addrDrop.style.display = 'block';
    }

    function selectSuggestion(item) {
        addrInput.value = item.display_name;
        document.getElementById('company_lat').value = item.lat;
        document.getElementById('company_lng').value = item.lon;

        // Auto-fill city and postcode from address details (only if field is empty)
        const addr = item.address || {};
        const city     = addr.city || addr.town || addr.village || addr.county || '';
        const postcode = addr.postcode || '';

        const cityInput = document.querySelector('[name="company_city"]');
        if (cityInput && !cityInput.value && city) cityInput.value = city;

        const pcInput = document.querySelector('[name="company_postcode"]');
        if (pcInput && !pcInput.value && postcode) pcInput.value = postcode;

        addrDrop.style.display = 'none';
    }
}());
</script>

<?php include '../includes/footer.php'; ?>