<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('admin');

$pageTitle    = 'User Management';
$pageSubtitle = 'Create, edit and manage user accounts';
$activePage   = 'users';
$userId       = authId();

$unreadCount = 0;
$pendingRequests = 0;

// Handle user actions
$actionMsg = '';
$actionType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_user'])) {
        // Create new user
        $fullName = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $role = $_POST['role'];
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $studentId = trim($_POST['student_id'] ?? '');
        $companyId = $_POST['company_id'] ?? null;

        try {
            $stmt = $pdo->prepare("
                INSERT INTO users (full_name, email, password, role, student_id, company_id, avatar_initials)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $initials = strtoupper(substr($fullName, 0, 1) . substr(strrchr($fullName, ' ') ?: '', 1, 1));
            $stmt->execute([$fullName, $email, $password, $role, $studentId, $companyId, $initials]);

            $actionMsg = "✅ User created successfully!";
            $actionType = 'success';
        } catch (Exception $e) {
            $actionMsg = "❌ Error: " . $e->getMessage();
            $actionType = 'danger';
        }
    }

    if (isset($_POST['deactivate_user'])) {
        $targetUserId = (int)$_POST['user_id'];
        $stmt = $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
        $stmt->execute([$targetUserId]);
        $actionMsg = "User deactivated successfully.";
        $actionType = 'warning';
    }

    if (isset($_POST['activate_user'])) {
        $targetUserId = (int)$_POST['user_id'];
        $stmt = $pdo->prepare("UPDATE users SET is_active = 1 WHERE id = ?");
        $stmt->execute([$targetUserId]);
        $actionMsg = "User activated successfully.";
        $actionType = 'success';
    }

    if (isset($_POST['delete_user'])) {
        $targetUserId = (int)$_POST['user_id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND id != ?");
            $stmt->execute([$targetUserId, $userId]); // Prevent self-deletion
            $actionMsg = "User deleted successfully.";
            $actionType = 'success';
        } catch (Exception $e) {
            $actionMsg = "Cannot delete user - they have associated records. Use Hard Delete instead.";
            $actionType = 'danger';
        }
    }

    if (isset($_POST['hard_delete_user'])) {
        $targetUserId = (int)$_POST['user_id'];
        if ($targetUserId === $userId) {
            $actionMsg  = "You cannot delete your own account.";
            $actionType = 'danger';
        } else {
            try {
                $pdo->beginTransaction();

                // delete records that reference placements first
                $pdo->prepare("DELETE FROM placement_change_requests WHERE student_id = ?")->execute([$targetUserId]);
                $pdo->prepare("DELETE pt FROM provider_tokens pt JOIN placements p ON pt.placement_id = p.id WHERE p.student_id = ?")->execute([$targetUserId]);
                $pdo->prepare("DELETE d FROM documents d JOIN placements p ON d.placement_id = p.id WHERE p.student_id = ?")->execute([$targetUserId]);
                $pdo->prepare("DELETE FROM documents WHERE uploaded_by = ?")->execute([$targetUserId]);
                $pdo->prepare("DELETE v FROM visits v JOIN placements p ON v.placement_id = p.id WHERE p.student_id = ?")->execute([$targetUserId]);
                $pdo->prepare("DELETE r FROM reflections r JOIN placements p ON r.placement_id = p.id WHERE p.student_id = ?")->execute([$targetUserId]);

                // delete records that reference the user directly
                $pdo->prepare("DELETE FROM messages WHERE sender_id = ? OR receiver_id = ?")->execute([$targetUserId, $targetUserId]);
                $pdo->prepare("DELETE FROM notifications WHERE user_id = ?")->execute([$targetUserId]);
                $pdo->prepare("DELETE FROM audit_log WHERE user_id = ?")->execute([$targetUserId]);
                $pdo->prepare("DELETE FROM announcement_reads WHERE student_id = ?")->execute([$targetUserId]);

                // delete placements, then the user
                $pdo->prepare("DELETE FROM placements WHERE student_id = ?")->execute([$targetUserId]);
                $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$targetUserId]);

                $pdo->commit();
                $actionMsg  = "User and all associated data permanently deleted.";
                $actionType = 'success';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $actionMsg  = "Hard delete failed: " . $e->getMessage();
                $actionType = 'danger';
            }
        }
    }

    if (isset($_POST['edit_user'])) {
        $targetUserId = (int)$_POST['user_id'];
        $fullName  = trim($_POST['full_name']);
        $email     = trim($_POST['email']);
        $role      = $_POST['role'];
        $studentId = trim($_POST['student_id'] ?? '');
        $companyId = $_POST['company_id'] ? (int)$_POST['company_id'] : null;
        $isActive  = isset($_POST['is_active']) ? 1 : 0;
        $parts     = preg_split('/\s+/', $fullName);
        $initials  = strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));

        try {
            if (!empty($_POST['new_password'])) {
                $passwordHash = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    UPDATE users SET full_name=?, email=?, role=?, student_id=?, company_id=?,
                    is_active=?, avatar_initials=?, password=? WHERE id=?
                ");
                $stmt->execute([$fullName, $email, $role, $studentId, $companyId, $isActive, $initials, $passwordHash, $targetUserId]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE users SET full_name=?, email=?, role=?, student_id=?, company_id=?,
                    is_active=?, avatar_initials=? WHERE id=?
                ");
                $stmt->execute([$fullName, $email, $role, $studentId, $companyId, $isActive, $initials, $targetUserId]);
            }
            $actionMsg  = "User updated successfully.";
            $actionType = 'success';
        } catch (Exception $e) {
            $actionMsg  = "Error updating user: " . $e->getMessage();
            $actionType = 'danger';
        }
    }
}

// Filters
$filterRole = $_GET['role'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterSearch = trim($_GET['search'] ?? '');

$where = [];
$params = [];

if ($filterRole) {
    $where[] = "role = ?";
    $params[] = $filterRole;
}

if ($filterStatus === 'active') {
    $where[] = "is_active = 1";
} elseif ($filterStatus === 'inactive') {
    $where[] = "is_active = 0";
}

if ($filterSearch) {
    $where[] = "(full_name LIKE ? OR email LIKE ? OR student_id LIKE ?)";
    $params[] = "%$filterSearch%";
    $params[] = "%$filterSearch%";
    $params[] = "%$filterSearch%";
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Fetch users
$stmt = $pdo->prepare("
    SELECT u.*, c.name AS company_name
    FROM users u
    LEFT JOIN companies c ON u.company_id = c.id
    $whereSQL
    ORDER BY u.created_at DESC
");
$stmt->execute($params);
$users = $stmt->fetchAll();

// Get all companies for dropdown
$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name ASC")->fetchAll();
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>

    <div class="page-content">

        <?php if ($actionMsg): ?>
        <div style="background:var(--<?= $actionType ?>-bg);
                    border:1px solid <?= $actionType==='success'?'#6ee7b7':($actionType==='danger'?'#fca5a5':'#fcd34d') ?>;
                    border-radius:var(--radius);padding:1.25rem 2rem;margin-bottom:1.5rem;">
            <p style="color:var(--<?= $actionType ?>);font-weight:500;"><?= htmlspecialchars($actionMsg) ?></p>
        </div>
        <?php endif; ?>

        <!-- Filter Bar -->
        <form method="GET" style="display:flex;gap:0.875rem;margin-bottom:1.5rem;flex-wrap:wrap;">
            
            <input type="text" name="search"
                   value="<?= htmlspecialchars($filterSearch) ?>"
                   placeholder="🔍  Search by name, email, student ID..."
                   style="padding:0.6875rem 1rem;border:1.5px solid var(--border);
                          border-radius:var(--radius-sm);font-family:inherit;font-size:0.875rem;
                          background:var(--white);min-width:300px;">

            <select name="role" onchange="this.form.submit()">
                <option value="">All Roles</option>
                <option value="student"  <?= $filterRole==='student' ?'selected':'' ?>>Students</option>
                <option value="tutor"    <?= $filterRole==='tutor'   ?'selected':'' ?>>Tutors</option>
                <option value="provider" <?= $filterRole==='provider'?'selected':'' ?>>Providers</option>
                <option value="admin"    <?= $filterRole==='admin'   ?'selected':'' ?>>Admins</option>
                <option value="director" <?= $filterRole==='director'?'selected':'' ?>>Directors</option>
            </select>

            <select name="status" onchange="this.form.submit()">
                <option value="">All Status</option>
                <option value="active" <?= $filterStatus==='active'?'selected':'' ?>>Active Only</option>
                <option value="inactive" <?= $filterStatus==='inactive'?'selected':'' ?>>Inactive Only</option>
            </select>

            <div style="margin-left:auto;display:flex;gap:0.75rem;">
                <?php if ($filterSearch || $filterRole || $filterStatus): ?>
                    <a href="users.php" class="btn btn-ghost btn-sm">✕ Clear</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <button type="button" class="btn btn-success btn-sm"
                        onclick="document.getElementById('createModal').style.display='flex'">
                    + Create User
                </button>
            </div>

        </form>

        <!-- Users Table -->
        <div class="panel">
            <div class="panel-header">
                <h3><?= count($users) ?> User<?= count($users)!==1?'s':'' ?></h3>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Student ID / Company</th>
                            <th>Created</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                        <tr>
                            <td>
                                <div class="avatar-cell">
                                    <div class="avatar"><?= htmlspecialchars($u['avatar_initials']??'??') ?></div>
                                    <div>
                                        <h4><?= htmlspecialchars($u['full_name']) ?></h4>
                                        <p><?= htmlspecialchars($u['email']) ?></p>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge badge-<?= $u['role']==='admin'?'review':($u['role']==='tutor'?'approved':'pending') ?>">
                                    <?= ucfirst($u['role']) ?>
                                </span>
                            </td>
                            <td style="font-size:0.875rem;color:var(--muted);">
                                <?php if ($u['student_id']): ?>
                                    <?= htmlspecialchars($u['student_id']) ?>
                                <?php elseif ($u['company_name']): ?>
                                    <?= htmlspecialchars($u['company_name']) ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td style="font-family:'DM Mono',monospace;font-size:0.8125rem;">
                                <?= date('d M Y', strtotime($u['created_at'])) ?>
                            </td>
                            <td>
                                <span class="badge badge-<?= $u['is_active']?'approved':'rejected' ?>">
                                    <?= $u['is_active']?'Active':'Inactive' ?>
                                </span>
                            </td>
                            <td>
                                <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
                                    <button class="btn btn-ghost btn-sm"
                                            onclick="openEdit(<?= htmlspecialchars(json_encode($u), ENT_QUOTES) ?>)">
                                        Edit
                                    </button>
                                    <?php if ($u['is_active']): ?>
                                        <form method="POST" action="users.php" style="display:inline;">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <button type="button" class="btn btn-warning btn-sm"
                                                    onclick="openConfirm('deactivate', <?= $u['id'] ?>, '<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>')">
                                                Deactivate
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" action="users.php" id="activateForm_<?= $u['id'] ?>" style="display:inline;">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <button type="submit" name="activate_user" class="btn btn-success btn-sm">
                                                Activate
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($u['id'] != $userId): ?>
                                        <button type="button" class="btn btn-danger btn-sm"
                                                onclick="openConfirm('delete', <?= $u['id'] ?>, '<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>')">
                                            Delete
                                        </button>
                                        <button type="button" class="btn btn-sm"
                                                style="background:#7f1d1d;color:#fff;border:none;"
                                                onclick="openConfirm('hard_delete', <?= $u['id'] ?>, '<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>')">
                                            Hard Delete
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<!-- CREATE USER MODAL -->
<div id="createModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);
     z-index:1000;align-items:center;justify-content:center;overflow-y:auto;">
    <div style="background:var(--white);border-radius:var(--radius);padding:2.5rem;
                width:100%;max-width:600px;margin:2rem;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
        <h3 style="font-family:'Playfair Display',serif;font-size:1.375rem;
                   color:var(--navy);margin-bottom:1.5rem;">Create New User</h3>

        <form method="POST">
            <div class="form-grid" style="margin-bottom:1.5rem;">
                <div class="form-group full-col">
                    <label>Full Name <span style="color:var(--danger);">*</span></label>
                    <input type="text" name="full_name" required>
                </div>
                <div class="form-group full-col">
                    <label>Email <span style="color:var(--danger);">*</span></label>
                    <input type="email" name="email" required>
                </div>
                <div class="form-group">
                    <label>Password <span style="color:var(--danger);">*</span></label>
                    <input type="password" name="password" required minlength="6">
                </div>
                <div class="form-group">
                    <label>Role <span style="color:var(--danger);">*</span></label>
                    <select name="role" required id="roleSelect" onchange="toggleFields()">
                        <option value="student">Student</option>
                        <option value="tutor">Tutor</option>
                        <option value="provider">Provider</option>
                        <option value="admin">Admin</option>
                        <option value="director">Programme Director</option>
                    </select>
                </div>
                <div class="form-group" id="studentIdField">
                    <label>Student ID</label>
                    <input type="text" name="student_id" placeholder="e.g., 190123456">
                </div>
                <div class="form-group" id="companyField" style="display:none;">
                    <label>Company</label>
                    <select name="company_id">
                        <option value="">-- Select company --</option>
                        <?php foreach ($companies as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button type="button" class="btn btn-ghost"
                        onclick="document.getElementById('createModal').style.display='none'">
                    Cancel
                </button>
                <button type="submit" name="create_user" class="btn btn-success">
                    Create User
                </button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT USER MODAL -->
<div id="editModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);
     z-index:1000;align-items:center;justify-content:center;overflow-y:auto;">
    <div style="background:var(--white);border-radius:var(--radius);padding:2.5rem;
                width:100%;max-width:600px;margin:2rem;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
        <h3 style="font-family:'Playfair Display',serif;font-size:1.375rem;
                   color:var(--navy);margin-bottom:1.5rem;">Edit User</h3>
        <form method="POST" action="users.php">
            <input type="hidden" name="user_id" id="editUserId">
            <div class="form-grid" style="margin-bottom:1.5rem;">
                <div class="form-group full-col">
                    <label>Full Name <span style="color:var(--danger);">*</span></label>
                    <input type="text" name="full_name" id="editFullName" required>
                </div>
                <div class="form-group full-col">
                    <label>Email <span style="color:var(--danger);">*</span></label>
                    <input type="email" name="email" id="editEmail" required>
                </div>
                <div class="form-group">
                    <label>Role <span style="color:var(--danger);">*</span></label>
                    <select name="role" id="editRole" required onchange="toggleEditFields()">
                        <option value="student">Student</option>
                        <option value="tutor">Tutor</option>
                        <option value="provider">Provider</option>
                        <option value="admin">Admin</option>
                        <option value="director">Programme Director</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="is_active" id="editIsActive">
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
                <div class="form-group" id="editStudentIdField">
                    <label>Student ID</label>
                    <input type="text" name="student_id" id="editStudentId" placeholder="e.g., 190123456">
                </div>
                <div class="form-group" id="editCompanyField" style="display:none;">
                    <label>Company</label>
                    <select name="company_id" id="editCompanyId">
                        <option value="">-- Select company --</option>
                        <?php foreach ($companies as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group full-col">
                    <label>New Password <span style="color:var(--muted);font-weight:400;">(leave blank to keep current)</span></label>
                    <input type="password" name="new_password" placeholder="Enter new password to change it" minlength="6">
                </div>
            </div>
            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button type="button" class="btn btn-ghost" onclick="closeEdit()">Cancel</button>
                <button type="submit" name="edit_user" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- CONFIRM ACTION MODAL (Deactivate / Delete) -->
<div id="confirmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);
     z-index:1001;align-items:center;justify-content:center;">
    <div style="background:var(--white);border-radius:var(--radius);padding:2rem;
                width:100%;max-width:440px;margin:2rem;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
        <h3 id="confirmTitle" style="font-family:'Playfair Display',serif;font-size:1.25rem;
                   color:var(--navy);margin-bottom:0.5rem;"></h3>
        <p id="confirmDesc" style="color:var(--muted);font-size:0.9rem;margin-bottom:1.5rem;"></p>
        <form method="POST" action="users.php" id="confirmForm">
            <input type="hidden" name="user_id" id="confirmUserId">
            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button type="button" class="btn btn-ghost" onclick="closeConfirm()">Cancel</button>
                <button type="submit" id="confirmSubmitBtn" name="deactivate_user" class="btn btn-danger">Confirm</button>
            </div>
        </form>
    </div>
</div>

<script>
// ---------- Create modal ----------
function toggleFields() {
    const role = document.getElementById('roleSelect').value;
    document.getElementById('studentIdField').style.display = (role === 'student') ? 'block' : 'none';
    document.getElementById('companyField').style.display   = (role === 'provider') ? 'block' : 'none';
}

document.getElementById('createModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});

// ---------- Edit modal ----------
function openEdit(user) {
    document.getElementById('editUserId').value    = user.id;
    document.getElementById('editFullName').value  = user.full_name;
    document.getElementById('editEmail').value     = user.email;
    document.getElementById('editRole').value      = user.role;
    document.getElementById('editStudentId').value = user.student_id || '';
    document.getElementById('editIsActive').value  = user.is_active ? '1' : '0';

    const companySelect = document.getElementById('editCompanyId');
    if (companySelect) companySelect.value = user.company_id || '';

    toggleEditFields();
    document.getElementById('editModal').style.display = 'flex';
}

function closeEdit() {
    document.getElementById('editModal').style.display = 'none';
}

function toggleEditFields() {
    const role = document.getElementById('editRole').value;
    document.getElementById('editStudentIdField').style.display = (role === 'student') ? 'block' : 'none';
    document.getElementById('editCompanyField').style.display   = (role === 'provider') ? 'block' : 'none';
}

document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) closeEdit();
});

// ---------- Confirm (deactivate / delete) modal ----------
function openConfirm(action, userId, userName) {
    document.getElementById('confirmUserId').value = userId;

    const btn = document.getElementById('confirmSubmitBtn');
    if (action === 'deactivate') {
        document.getElementById('confirmTitle').textContent = 'Deactivate User';
        document.getElementById('confirmDesc').textContent  = 'Deactivate account for: ' + userName + '? They will not be able to log in.';
        btn.className   = 'btn btn-warning';
        btn.textContent = 'Deactivate';
        btn.name        = 'deactivate_user';
    } else if (action === 'hard_delete') {
        document.getElementById('confirmTitle').textContent = 'Hard Delete User';
        document.getElementById('confirmDesc').innerHTML    =
            '<strong style="color:#7f1d1d;">' + userName + '</strong> and ALL their data will be permanently erased — placements, documents, messages, audit logs, and tokens. This cannot be undone.';
        btn.style.background = '#7f1d1d';
        btn.style.color      = '#fff';
        btn.style.border     = 'none';
        btn.className        = 'btn';
        btn.textContent      = 'Permanently Delete Everything';
        btn.name             = 'hard_delete_user';
    } else {
        document.getElementById('confirmTitle').textContent = 'Delete User';
        document.getElementById('confirmDesc').textContent  = 'Permanently delete ' + userName + '? This cannot be undone. If they have associated records, use Hard Delete instead.';
        btn.className   = 'btn btn-danger';
        btn.style.background = '';
        btn.style.color      = '';
        btn.style.border     = '';
        btn.textContent = 'Delete';
        btn.name        = 'delete_user';
    }

    document.getElementById('confirmModal').style.display = 'flex';
}

function closeConfirm() {
    document.getElementById('confirmModal').style.display = 'none';
}

document.getElementById('confirmModal').addEventListener('click', function(e) {
    if (e.target === this) closeConfirm();
});
</script>

<?php include '../includes/footer.php'; ?>