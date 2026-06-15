<?php
require_once 'includes/auth.php';
require_once 'config/db.php';

requireAuth(); // any logged-in role

$pageTitle    = 'My Profile';
$pageSubtitle = 'Manage your personal contact information';
$activePage   = 'profile';
$userId       = authId();
$userRole     = authRole();

// ── Sidebar badge stubs (required by sidebar.php) ───────────────
$pendingRequests = 0;
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount = (int)$stmt->fetchColumn();

// ── Ensure optional columns exist ───────────────────────────────
$extraCols = [
    'phone'                   => "VARCHAR(30)  DEFAULT NULL",
    'personal_email'          => "VARCHAR(255) DEFAULT NULL",
    'home_address'            => "TEXT         DEFAULT NULL",
    'emergency_contact_name'  => "VARCHAR(100) DEFAULT NULL",
    'emergency_contact_phone' => "VARCHAR(30)  DEFAULT NULL",
    'student_id_number'       => "VARCHAR(50)  DEFAULT NULL",
    'bio'                     => "TEXT         DEFAULT NULL",
];
$existing = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($extraCols as $col => $def) {
    if (!in_array($col, $existing, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN `$col` $def");
    }
}

// ── Handle form submission ───────────────────────────────────────
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $fullName    = trim($_POST['full_name']    ?? '');
        $phone       = trim($_POST['phone']        ?? '');
        $personalEmail = trim($_POST['personal_email'] ?? '');
        $homeAddress = trim($_POST['home_address'] ?? '');
        $ecName      = trim($_POST['emergency_contact_name']  ?? '');
        $ecPhone     = trim($_POST['emergency_contact_phone'] ?? '');
        $bio         = trim($_POST['bio']          ?? '');
        $studentIdNo = trim($_POST['student_id_number'] ?? '');
        $academicYear   = trim($_POST['academic_year']   ?? '');
        $programmeType  = trim($_POST['programme_type']  ?? '');

        if (!$fullName) {
            $error = 'Full name is required.';
        } elseif ($personalEmail && !filter_var($personalEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid alternative email address.';
        } else {
            // Recalculate initials
            $parts = preg_split('/\s+/', $fullName);
            $initials = count($parts) >= 2
                ? strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1))
                : strtoupper(substr($fullName, 0, 2));

            $stmt = $pdo->prepare("
                UPDATE users SET
                    full_name                = ?,
                    avatar_initials          = ?,
                    phone                    = ?,
                    personal_email           = ?,
                    home_address             = ?,
                    emergency_contact_name   = ?,
                    emergency_contact_phone  = ?,
                    bio                      = ?,
                    student_id_number        = ?,
                    academic_year            = ?,
                    programme_type           = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $fullName, $initials, $phone ?: null, $personalEmail ?: null,
                $homeAddress ?: null, $ecName ?: null, $ecPhone ?: null,
                $bio ?: null, $studentIdNo ?: null,
                $academicYear ?: null, $programmeType ?: null,
                $userId
            ]);

            // Refresh session
            $_SESSION['user']['full_name']       = $fullName;
            $_SESSION['user']['avatar_initials'] = $initials;

            $success = 'Profile updated successfully.';
        }

    } elseif ($action === 'change_password') {
        $currentPwd = $_POST['current_password'] ?? '';
        $newPwd     = $_POST['new_password']     ?? '';
        $confirmPwd = $_POST['confirm_password'] ?? '';

        // Fetch current hash
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($currentPwd, $row['password'])) {
            $error = 'Current password is incorrect.';
        } elseif (strlen($newPwd) < 8) {
            $error = 'New password must be at least 8 characters.';
        } elseif ($newPwd !== $confirmPwd) {
            $error = 'New passwords do not match.';
        } else {
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
                ->execute([password_hash($newPwd, PASSWORD_DEFAULT), $userId]);
            $success = 'Password changed successfully.';
        }
    }
}

// ── Fetch current user data ──────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<?php include 'includes/header.php'; ?>

<div class="main">
    <?php include 'includes/topbar.php'; ?>

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

        <div style="display:grid;grid-template-columns:260px 1fr;gap:1.5rem;align-items:start;">

            <!-- ── Left: Avatar Card ─────────────────────────── -->
            <div class="panel" style="text-align:center;padding:2rem 1.5rem;">
                <div style="width:80px;height:80px;border-radius:50%;
                            background:linear-gradient(135deg,#0c1b33,#1a2d4d);
                            display:flex;align-items:center;justify-content:center;
                            font-size:2rem;font-weight:700;color:white;
                            margin:0 auto 1rem;">
                    <?= htmlspecialchars($user['avatar_initials'] ?? 'U') ?>
                </div>
                <h3 style="font-family:'Playfair Display',serif;font-size:1.25rem;
                           color:var(--navy);margin-bottom:0.25rem;">
                    <?= htmlspecialchars($user['full_name']) ?>
                </h3>
                <p style="color:var(--muted);font-size:0.875rem;margin-bottom:0.5rem;">
                    <?= htmlspecialchars($user['email']) ?>
                </p>
                <span class="badge badge-approved" style="display:inline-block;">
                    <?= ucfirst($userRole) ?>
                </span>

                <?php if (!empty($user['phone'])): ?>
                <p style="margin-top:1rem;font-size:0.875rem;color:var(--text);">
                    📞 <?= htmlspecialchars($user['phone']) ?>
                </p>
                <?php endif; ?>

                <?php if ($userRole === 'student' && !empty($user['student_id_number'])): ?>
                <p style="margin-top:0.5rem;font-size:0.8125rem;color:var(--muted);">
                    ID: <?= htmlspecialchars($user['student_id_number']) ?>
                </p>
                <?php endif; ?>

                <?php if (!empty($user['bio'])): ?>
                <div style="margin-top:1.25rem;padding-top:1.25rem;border-top:1px solid var(--border);
                            font-size:0.875rem;color:var(--text);line-height:1.6;text-align:left;">
                    <?= nl2br(htmlspecialchars($user['bio'])) ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($user['emergency_contact_name'])): ?>
                <div style="margin-top:1.25rem;padding-top:1.25rem;border-top:1px solid var(--border);
                            font-size:0.8125rem;color:var(--muted);text-align:left;">
                    <p style="font-weight:600;color:var(--navy);margin-bottom:0.25rem;">Emergency Contact</p>
                    <p><?= htmlspecialchars($user['emergency_contact_name']) ?></p>
                    <?php if (!empty($user['emergency_contact_phone'])): ?>
                    <p><?= htmlspecialchars($user['emergency_contact_phone']) ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── Right: Forms ─────────────────────────────── -->
            <div style="display:flex;flex-direction:column;gap:1.5rem;">

                <!-- Personal & Contact Info -->
                <div class="panel">
                    <div class="panel-header">
                        <div>
                            <h3>Personal &amp; Contact Information</h3>
                            <p>Update your name, phone, address and emergency contact</p>
                        </div>
                    </div>
                    <div class="panel-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="update_profile">

                            <!-- Section: Identity -->
                            <div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;
                                        letter-spacing:0.08em;color:var(--muted);margin-bottom:1rem;
                                        padding-bottom:0.5rem;border-bottom:2px solid var(--border);">
                                Identity
                            </div>
                            <div class="form-grid" style="margin-bottom:1.75rem;">
                                <div class="form-group">
                                    <label>Full Name <span style="color:var(--danger);">*</span></label>
                                    <input type="text" name="full_name" required
                                           value="<?= htmlspecialchars($user['full_name'] ?? '') ?>"
                                           placeholder="Your full name">
                                </div>
                                <?php if ($userRole === 'student'): ?>
                                <div class="form-group">
                                    <label>Student ID Number</label>
                                    <input type="text" name="student_id_number"
                                           value="<?= htmlspecialchars($user['student_id_number'] ?? '') ?>"
                                           placeholder="e.g., 23010001">
                                </div>
                                <div class="form-group">
                                    <label>Academic Year</label>
                                    <select name="academic_year">
                                        <option value="">— Select —</option>
                                        <?php
                                        $years = ['2024/25','2025/26','2026/27','2027/28','2028/29'];
                                        foreach ($years as $y) {
                                            $sel = (($user['academic_year'] ?? '') === $y) ? 'selected' : '';
                                            echo "<option value=\"$y\" $sel>$y</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Programme</label>
                                    <select name="programme_type">
                                        <option value="">— Select —</option>
                                        <?php
                                        $progs = [
                                            'BSc Computer Science',
                                            'BSc Software Engineering',
                                            'BSc Data Science',
                                            'BEng Engineering',
                                            'MEng Engineering',
                                            'MSc Computer Science',
                                            'Other',
                                        ];
                                        foreach ($progs as $p) {
                                            $sel = (($user['programme_type'] ?? '') === $p) ? 'selected' : '';
                                            echo "<option value=\"" . htmlspecialchars($p) . "\" $sel>" . htmlspecialchars($p) . "</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                                <?php endif; ?>

                                <div class="form-group full-col">
                                    <label>Short Bio</label>
                                    <textarea name="bio" rows="2"
                                              placeholder="A brief note about yourself (optional)…"><?= htmlspecialchars($user['bio'] ?? '') ?></textarea>
                                </div>
                            </div>

                            <!-- Section: Contact -->
                            <div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;
                                        letter-spacing:0.08em;color:var(--muted);margin-bottom:1rem;
                                        padding-bottom:0.5rem;border-bottom:2px solid var(--border);">
                                Contact Details
                            </div>
                            <div class="form-grid" style="margin-bottom:1.75rem;">
                                <div class="form-group">
                                    <label>Mobile / Phone</label>
                                    <input type="tel" name="phone"
                                           value="<?= htmlspecialchars($user['phone'] ?? '') ?>"
                                           placeholder="+44 7700 000000">
                                </div>
                                <div class="form-group">
                                    <label>Alternative Email</label>
                                    <input type="email" name="personal_email"
                                           value="<?= htmlspecialchars($user['personal_email'] ?? '') ?>"
                                           placeholder="personal@gmail.com">
                                    <small style="color:var(--muted);">Secondary contact, not used for login.</small>
                                </div>
                                <div class="form-group full-col">
                                    <label>Home Address</label>
                                    <textarea name="home_address" rows="2"
                                              placeholder="Street, City, Postcode…"><?= htmlspecialchars($user['home_address'] ?? '') ?></textarea>
                                </div>
                            </div>

                            <!-- Section: Emergency Contact -->
                            <div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;
                                        letter-spacing:0.08em;color:var(--muted);margin-bottom:1rem;
                                        padding-bottom:0.5rem;border-bottom:2px solid var(--border);">
                                Emergency Contact
                            </div>
                            <div class="form-grid" style="margin-bottom:1.75rem;">
                                <div class="form-group">
                                    <label>Emergency Contact Name</label>
                                    <input type="text" name="emergency_contact_name"
                                           value="<?= htmlspecialchars($user['emergency_contact_name'] ?? '') ?>"
                                           placeholder="e.g., Jane Smith (Mother)">
                                </div>
                                <div class="form-group">
                                    <label>Emergency Contact Phone</label>
                                    <input type="tel" name="emergency_contact_phone"
                                           value="<?= htmlspecialchars($user['emergency_contact_phone'] ?? '') ?>"
                                           placeholder="+44 7700 000000">
                                </div>
                            </div>

                            <div style="display:flex;justify-content:flex-end;">
                                <button type="submit" class="btn btn-primary">Save Changes →</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Change Password -->
                <div class="panel">
                    <div class="panel-header">
                        <div>
                            <h3>Change Password</h3>
                            <p>Set a new password — minimum 8 characters</p>
                        </div>
                    </div>
                    <div class="panel-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="change_password">
                            <div class="form-grid">
                                <div class="form-group full-col">
                                    <label>Current Password <span style="color:var(--danger);">*</span></label>
                                    <div style="position:relative;">
                                        <input type="password" name="current_password" id="curPwd"
                                               required placeholder="Enter your current password"
                                               style="padding-right:3rem;">
                                        <button type="button" class="pwd-toggle"
                                                onclick="togglePwd('curPwd',this)"
                                                style="position:absolute;right:0.75rem;top:50%;
                                                       transform:translateY(-50%);background:none;
                                                       border:none;cursor:pointer;font-size:1.1rem;
                                                       color:var(--muted);">👁</button>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>New Password <span style="color:var(--danger);">*</span></label>
                                    <div style="position:relative;">
                                        <input type="password" name="new_password" id="newPwd"
                                               required minlength="8"
                                               placeholder="Minimum 8 characters"
                                               style="padding-right:3rem;"
                                               oninput="pwdStrength(this.value)">
                                        <button type="button" class="pwd-toggle"
                                                onclick="togglePwd('newPwd',this)"
                                                style="position:absolute;right:0.75rem;top:50%;
                                                       transform:translateY(-50%);background:none;
                                                       border:none;cursor:pointer;font-size:1.1rem;
                                                       color:var(--muted);">👁</button>
                                    </div>
                                    <div style="height:4px;border-radius:2px;background:var(--border);
                                                overflow:hidden;margin-top:0.4rem;">
                                        <div id="pwdBar" style="height:100%;width:0;border-radius:2px;transition:all 0.3s;"></div>
                                    </div>
                                    <small id="pwdLabel" style="color:var(--muted);"></small>
                                </div>
                                <div class="form-group">
                                    <label>Confirm New Password <span style="color:var(--danger);">*</span></label>
                                    <div style="position:relative;">
                                        <input type="password" name="confirm_password" id="conPwd"
                                               required minlength="8"
                                               placeholder="Repeat new password"
                                               style="padding-right:3rem;">
                                        <button type="button" class="pwd-toggle"
                                                onclick="togglePwd('conPwd',this)"
                                                style="position:absolute;right:0.75rem;top:50%;
                                                       transform:translateY(-50%);background:none;
                                                       border:none;cursor:pointer;font-size:1.1rem;
                                                       color:var(--muted);">👁</button>
                                    </div>
                                </div>
                            </div>
                            <div style="display:flex;justify-content:flex-end;margin-top:0.5rem;">
                                <button type="submit" class="btn btn-primary">Update Password →</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Account Info (read-only) -->
                <div class="panel">
                    <div class="panel-header">
                        <h3>Account Information</h3>
                    </div>
                    <div class="panel-body">
                        <div class="info-grid">
                            <div class="info-item">
                                <label>Login Email</label>
                                <p><?= htmlspecialchars($user['email']) ?></p>
                            </div>
                            <div class="info-item">
                                <label>Role</label>
                                <p><?= ucfirst($userRole) ?></p>
                            </div>
                            <div class="info-item">
                                <label>Account Status</label>
                                <p><span class="badge badge-approved"><?= ucfirst($user['approval_status'] ?? 'approved') ?></span></p>
                            </div>
                            <div class="info-item">
                                <label>Member Since</label>
                                <p><?= $user['created_at'] ? date('d F Y', strtotime($user['created_at'])) : '—' ?></p>
                            </div>
                        </div>
                        <p style="font-size:0.8125rem;color:var(--muted);margin-top:1rem;">
                            Your login email cannot be changed. Contact the placement office if you need to update it.
                        </p>
                    </div>
                </div>

            </div><!-- /right col -->
        </div><!-- /grid -->
    </div><!-- /page-content -->
</div><!-- /main -->

<script>
function togglePwd(id, btn) {
    const inp = document.getElementById(id);
    inp.type = inp.type === 'password' ? 'text' : 'password';
    btn.textContent = inp.type === 'password' ? '👁' : '🙈';
}

function pwdStrength(val) {
    let score = 0;
    if (val.length >= 8)           score++;
    if (val.length >= 12)          score++;
    if (/[A-Z]/.test(val))         score++;
    if (/[0-9]/.test(val))         score++;
    if (/[^A-Za-z0-9]/.test(val))  score++;
    const levels = [
        { pct: '20%', color: '#ef4444', text: 'Very weak'  },
        { pct: '40%', color: '#f97316', text: 'Weak'        },
        { pct: '60%', color: '#eab308', text: 'Fair'        },
        { pct: '80%', color: '#84cc16', text: 'Strong'      },
        { pct: '100%',color: '#10b981', text: 'Very strong' },
    ];
    const lvl = levels[Math.max(0, score - 1)] || { pct: '0%', color: '', text: '' };
    const bar = document.getElementById('pwdBar');
    bar.style.width      = val ? lvl.pct : '0%';
    bar.style.background = lvl.color;
    document.getElementById('pwdLabel').textContent = val ? lvl.text : '';
}

// Warn if new passwords don't match on submit
document.querySelectorAll('form').forEach(f => {
    f.addEventListener('submit', e => {
        const np = f.querySelector('[name="new_password"]');
        const cp = f.querySelector('[name="confirm_password"]');
        if (np && cp && np.value && np.value !== cp.value) {
            e.preventDefault();
            alert('New passwords do not match.');
        }
    });
});
</script>

<?php include 'includes/footer.php'; ?>
