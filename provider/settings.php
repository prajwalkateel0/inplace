<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('provider');

$pageTitle    = 'Company Details';
$pageSubtitle = 'Update your company information';
$activePage   = 'settings';
$userId       = authId();

// Get provider's company
$stmt = $pdo->prepare("SELECT company_id FROM users WHERE id = ?");
$stmt->execute([$userId]);
$provider = $stmt->fetch();
$companyId = $provider['company_id'] ?? null;

if (!$companyId) { header('Location: dashboard.php'); exit; }

// Sidebar badges
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM placements WHERE company_id = ? AND status = 'awaiting_provider'");
$stmt->execute([$companyId]);
$pendingRequests = (int)$stmt->fetchColumn();

// Load company
$stmt = $pdo->prepare("SELECT * FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();

$successMsg = '';
$errorMsg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name']    ?? '');
    $address = trim($_POST['address'] ?? '');
    $city    = trim($_POST['city']    ?? '');
    $sector  = trim($_POST['sector']  ?? '');
    $website = trim($_POST['website'] ?? '');
    $phone   = trim($_POST['phone']   ?? '');
    $description = trim($_POST['description'] ?? '');

    $contactName  = trim($_POST['contact_name']  ?? '');
    $contactEmail = trim($_POST['contact_email'] ?? '');
    $contactPhone = trim($_POST['contact_phone'] ?? '');

    // Safely add supervisor/contact columns
    foreach (['contact_name VARCHAR(120) DEFAULT NULL','contact_email VARCHAR(255) DEFAULT NULL','contact_phone VARCHAR(50) DEFAULT NULL'] as $colDef) {
        try { $pdo->exec("ALTER TABLE companies ADD COLUMN $colDef"); } catch (Exception $e) {}
    }

    if ($name === '') {
        $errorMsg = 'Company name is required.';
    } else {
        $stmt = $pdo->prepare("
            UPDATE companies
            SET name = ?, address = ?, city = ?, sector = ?, website = ?, phone = ?,
                description = ?, contact_name = ?, contact_email = ?, contact_phone = ?
            WHERE id = ?
        ");
        $stmt->execute([$name, $address, $city, $sector, $website, $phone, $description,
                        $contactName, $contactEmail, $contactPhone, $companyId]);

        // Reload
        $stmt = $pdo->prepare("SELECT * FROM companies WHERE id = ?");
        $stmt->execute([$companyId]);
        $company = $stmt->fetch();

        $successMsg = 'Company details updated successfully.';
    }
}

function s($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES); }
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>

    <div class="page-content">

        <?php if ($successMsg): ?>
        <div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:var(--radius);
                    padding:1.25rem 2rem;margin-bottom:1.5rem;">
            <p style="color:#065f46;font-weight:500;">✅ <?= s($successMsg) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($errorMsg): ?>
        <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:var(--radius);
                    padding:1.25rem 2rem;margin-bottom:1.5rem;">
            <p style="color:#991b1b;font-weight:500;">⚠️ <?= s($errorMsg) ?></p>
        </div>
        <?php endif; ?>

        <div class="panel" style="max-width:720px;">
            <div class="panel-header">
                <h3>🏢 Company Details</h3>
            </div>
            <div style="padding:2rem;">
                <form method="POST">

                    <div class="form-group">
                        <label>Company Name <span style="color:var(--danger);">*</span></label>
                        <input type="text" name="name" class="form-input"
                               value="<?= s($company['name'] ?? '') ?>" required>
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;">
                        <div class="form-group">
                            <label>City / Town</label>
                            <input type="text" name="city" class="form-input"
                                   value="<?= s($company['city'] ?? '') ?>"
                                   placeholder="e.g. London">
                        </div>
                        <div class="form-group">
                            <label>Sector / Industry</label>
                            <input type="text" name="sector" class="form-input"
                                   value="<?= s($company['sector'] ?? '') ?>"
                                   placeholder="e.g. Technology">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Address</label>
                        <input type="text" name="address" class="form-input"
                               value="<?= s($company['address'] ?? '') ?>"
                               placeholder="Street address">
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;">
                        <div class="form-group">
                            <label>Website</label>
                            <input type="url" name="website" class="form-input"
                                   value="<?= s($company['website'] ?? '') ?>"
                                   placeholder="https://www.example.com">
                        </div>
                        <div class="form-group">
                            <label>Phone</label>
                            <input type="text" name="phone" class="form-input"
                                   value="<?= s($company['phone'] ?? '') ?>"
                                   placeholder="+44 ...">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" class="form-input" rows="4"
                                  placeholder="Brief description of the company..."><?= s($company['description'] ?? '') ?></textarea>
                    </div>

                    <hr style="border:none;border-top:1px solid var(--border);margin:1.5rem 0;">
                    <h4 style="font-size:0.875rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;
                               color:var(--muted);margin-bottom:1rem;">Supervisor / Primary Contact</h4>

                    <div class="form-group">
                        <label>Contact Name</label>
                        <input type="text" name="contact_name" class="form-input"
                               value="<?= s($company['contact_name'] ?? '') ?>"
                               placeholder="e.g. Jane Smith">
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;">
                        <div class="form-group">
                            <label>Contact Email</label>
                            <input type="email" name="contact_email" class="form-input"
                                   value="<?= s($company['contact_email'] ?? '') ?>"
                                   placeholder="supervisor@company.com">
                        </div>
                        <div class="form-group">
                            <label>Contact Phone</label>
                            <input type="tel" name="contact_phone" class="form-input"
                                   value="<?= s($company['contact_phone'] ?? '') ?>"
                                   placeholder="+44 ...">
                        </div>
                    </div>

                    <div style="display:flex;justify-content:flex-end;margin-top:1rem;">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>

                </form>
            </div>
        </div>

    </div>
</div>

<?php include '../includes/footer.php'; ?>
