<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('tutor');

$pageTitle    = 'Provider Directory';
$pageSubtitle = 'Manage placement companies and providers';
$activePage   = 'providers';
$userId       = authId();

// Sidebar badges
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount = (int)$stmt->fetchColumn();
$stmt = $pdo->query("SELECT COUNT(*) FROM placements WHERE status IN ('submitted','awaiting_tutor','awaiting_provider')");
$pendingRequests = (int)$stmt->fetchColumn();

$flash = ['msg'=>'','type'=>''];

// ── POST: update company ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $cid    = (int)($_POST['company_id'] ?? 0);

    if ($action === 'update' && $cid) {
        $name    = trim($_POST['name']          ?? '');
        $address = trim($_POST['address']       ?? '');
        $city    = trim($_POST['city']          ?? '');
        $sector  = trim($_POST['sector']        ?? '');
        $website = trim($_POST['website']       ?? '');
        $phone   = trim($_POST['phone']         ?? '');
        $contact = trim($_POST['contact_name']  ?? '');
        $cemail  = trim($_POST['contact_email'] ?? '');
        $cphone  = trim($_POST['contact_phone'] ?? '');
        $desc    = trim($_POST['description']   ?? '');

        if (!$name) {
            $flash = ['msg'=>'Company name is required.','type'=>'danger'];
        } else {
            // Add missing columns gracefully
            foreach (['website VARCHAR(255)','phone VARCHAR(50)','description TEXT'] as $colDef) {
                $colName = explode(' ', $colDef)[0];
                try {
                    $pdo->exec("ALTER TABLE companies ADD COLUMN $colDef DEFAULT NULL");
                } catch (Exception $e) {}
            }

            $pdo->prepare("
                UPDATE companies
                SET name=?, address=?, city=?, sector=?, website=?, phone=?,
                    contact_name=?, contact_email=?, contact_phone=?, description=?
                WHERE id=?
            ")->execute([$name,$address,$city,$sector,$website,$phone,$contact,$cemail,$cphone,$desc,$cid]);
            $flash = ['msg'=>'Company updated successfully.','type'=>'success'];
        }

    } elseif ($action === 'delete' && $cid) {
        // Only allow delete if no placements linked
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM placements WHERE company_id=?");
        $stmt->execute([$cid]);
        if ((int)$stmt->fetchColumn() > 0) {
            $flash = ['msg'=>'Cannot delete — this company has placement records.','type'=>'danger'];
        } else {
            $pdo->prepare("DELETE FROM companies WHERE id=?")->execute([$cid]);
            $flash = ['msg'=>'Company deleted.','type'=>'success'];
        }
    }
}

// ── Filters ──────────────────────────────────────────────────────
$search     = trim($_GET['search'] ?? '');
$filterSect = $_GET['sector'] ?? '';

$where  = [];
$params = [];

if ($search) {
    $where[]  = "(c.name LIKE ? OR c.city LIKE ? OR c.contact_email LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($filterSect) {
    $where[]  = "c.sector = ?";
    $params[] = $filterSect;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Fetch companies with stats ───────────────────────────────────
$stmt = $pdo->prepare("
    SELECT c.*,
           COUNT(DISTINCT p.id)  AS total_placements,
           SUM(p.status IN ('approved','active')) AS active_placements,
           (SELECT pu.full_name FROM users pu
              WHERE pu.company_id = c.id AND pu.role='provider' AND pu.is_active=1
              LIMIT 1) AS provider_user_name,
           (SELECT pu.email FROM users pu
              WHERE pu.company_id = c.id AND pu.role='provider' AND pu.is_active=1
              LIMIT 1) AS provider_user_email
    FROM companies c
    LEFT JOIN placements p ON p.company_id = c.id
    $whereSQL
    GROUP BY c.id
    ORDER BY active_placements DESC, total_placements DESC, c.name ASC
");
$stmt->execute($params);
$companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Sectors for filter dropdown
$sectors = $pdo->query("SELECT DISTINCT sector FROM companies WHERE sector IS NOT NULL AND sector!='' ORDER BY sector ASC")->fetchAll(PDO::FETCH_COLUMN);

$allSectors = [
    'Technology & Software','Engineering & Manufacturing','Finance & Banking',
    'Healthcare & Life Sciences','Consultancy','Media & Communications',
    'Retail & E-commerce','Public Sector / Government','Education & Research','Other',
];
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>
    <div class="page-content">

        <?php if ($flash['msg']): ?>
        <div style="background:var(--<?= $flash['type'] ?>-bg);border:1px solid <?= $flash['type']==='success'?'#6ee7b7':'#fca5a5' ?>;
                    border-radius:var(--radius);padding:1.25rem 2rem;margin-bottom:1.5rem;">
            <p style="color:var(--<?= $flash['type'] ?>);font-weight:500;"><?= htmlspecialchars($flash['msg']) ?></p>
        </div>
        <?php endif; ?>

        <!-- Stats -->
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1.25rem;margin-bottom:1.5rem;">
            <?php
            $totalCo  = count($companies);
            $withActive = count(array_filter($companies, fn($c) => $c['active_placements'] > 0));
            $noProvider = count(array_filter($companies, fn($c) => !$c['provider_user_name']));
            ?>
            <div class="panel" style="margin-bottom:0;padding:1.25rem 1.5rem;">
                <p style="font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);margin-bottom:0.4rem;">Total Companies</p>
                <h3 style="font-family:'Playfair Display',serif;font-size:2rem;color:var(--navy);"><?= $totalCo ?></h3>
            </div>
            <div class="panel" style="margin-bottom:0;padding:1.25rem 1.5rem;">
                <p style="font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);margin-bottom:0.4rem;">With Active Placements</p>
                <h3 style="font-family:'Playfair Display',serif;font-size:2rem;color:var(--success);"><?= $withActive ?></h3>
            </div>
            <div class="panel" style="margin-bottom:0;padding:1.25rem 1.5rem;">
                <p style="font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);margin-bottom:0.4rem;">No Provider Account</p>
                <h3 style="font-family:'Playfair Display',serif;font-size:2rem;color:var(--warning);"><?= $noProvider ?></h3>
            </div>
        </div>

        <!-- Filter -->
        <form method="GET" style="display:flex;gap:0.75rem;margin-bottom:1.5rem;flex-wrap:wrap;">
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                   placeholder="🔍 Search company, city or email…"
                   style="padding:0.625rem 1rem;border:1.5px solid var(--border);border-radius:var(--radius-sm);
                          font-family:inherit;font-size:0.875rem;min-width:280px;">
            <select name="sector" onchange="this.form.submit()"
                    style="padding:0.625rem 1rem;border:1.5px solid var(--border);border-radius:var(--radius-sm);
                           font-family:inherit;font-size:0.875rem;">
                <option value="">All Sectors</option>
                <?php foreach ($sectors as $s): ?>
                <option value="<?= htmlspecialchars($s) ?>" <?= $filterSect===$s?'selected':'' ?>>
                    <?= htmlspecialchars($s) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">Search</button>
            <?php if ($search || $filterSect): ?>
            <a href="providers.php" class="btn btn-ghost btn-sm">✕ Clear</a>
            <?php endif; ?>
        </form>

        <!-- Table -->
        <div class="panel">
            <div class="panel-header">
                <h3><?= count($companies) ?> Compan<?= count($companies)!==1?'ies':'y' ?></h3>
            </div>

            <?php if (empty($companies)): ?>
            <div style="text-align:center;padding:4rem 2rem;">
                <div style="font-size:3rem;margin-bottom:1rem;">🏢</div>
                <p style="color:var(--muted);">No companies found.</p>
            </div>
            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Company</th>
                            <th>Location</th>
                            <th>Sector</th>
                            <th>Provider Account</th>
                            <th>Placements</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($companies as $co): ?>
                        <tr>
                            <td>
                                <div style="font-weight:600;color:var(--navy);"><?= htmlspecialchars($co['name']) ?></div>
                                <?php if ($co['contact_email']): ?>
                                <div style="font-size:0.8rem;color:var(--muted);"><?= htmlspecialchars($co['contact_email']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:0.875rem;">
                                <?= htmlspecialchars($co['city'] ?? '—') ?>
                            </td>
                            <td>
                                <?php if ($co['sector']): ?>
                                <span class="type-chip"><?= htmlspecialchars($co['sector']) ?></span>
                                <?php else: ?>
                                <span style="color:var(--muted);font-size:0.8125rem;">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:0.875rem;">
                                <?php if ($co['provider_user_name']): ?>
                                <div style="font-weight:500;color:var(--navy);"><?= htmlspecialchars($co['provider_user_name']) ?></div>
                                <div style="font-size:0.78rem;color:var(--muted);"><?= htmlspecialchars($co['provider_user_email']) ?></div>
                                <?php else: ?>
                                <span class="badge badge-pending" style="font-size:0.75rem;">No account</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <span style="font-weight:700;color:<?= $co['active_placements']>0?'var(--success)':'var(--muted)' ?>;">
                                    <?= $co['active_placements'] ?>
                                </span>
                                <span style="color:var(--muted);font-size:0.78rem;"> / <?= $co['total_placements'] ?></span>
                            </td>
                            <td>
                                <div style="display:flex;gap:0.4rem;">
                                    <button class="btn btn-ghost btn-sm"
                                            onclick="openEdit(<?= htmlspecialchars(json_encode($co)) ?>)">
                                        ✏️ Edit
                                    </button>
                                    <?php if ($co['total_placements'] == 0): ?>
                                    <form method="POST" style="display:inline;"
                                          onsubmit="return confirm('Delete <?= htmlspecialchars(addslashes($co['name'])) ?>?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="company_id" value="<?= $co['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">🗑</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);
     z-index:1000;align-items:center;justify-content:center;overflow-y:auto;padding:1rem;">
    <div style="background:var(--white);border-radius:var(--radius);width:100%;max-width:640px;
                box-shadow:0 20px 60px rgba(0,0,0,0.2);margin:auto;">
        <div style="padding:1.75rem 2rem;border-bottom:1px solid var(--border);display:flex;
                    align-items:center;justify-content:space-between;">
            <h3 style="font-family:'Playfair Display',serif;color:var(--navy);font-size:1.25rem;">Edit Company</h3>
            <button onclick="document.getElementById('editModal').style.display='none'"
                    style="background:none;border:none;font-size:1.25rem;cursor:pointer;color:var(--muted);">✕</button>
        </div>
        <form method="POST" style="padding:2rem;">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="company_id" id="editId">
            <div class="form-grid" style="margin-bottom:1.5rem;">
                <div class="form-group">
                    <label>Company Name <span style="color:var(--danger);">*</span></label>
                    <input type="text" name="name" id="editName" required>
                </div>
                <div class="form-group">
                    <label>City / Town</label>
                    <input type="text" name="city" id="editCity" placeholder="e.g., Derby">
                </div>
                <div class="form-group full-col">
                    <label>Address</label>
                    <input type="text" name="address" id="editAddress">
                </div>
                <div class="form-group">
                    <label>Sector</label>
                    <select name="sector" id="editSector">
                        <option value="">Select sector</option>
                        <?php foreach ($allSectors as $s): ?>
                        <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Website</label>
                    <input type="url" name="website" id="editWebsite" placeholder="https://…">
                </div>
                <div class="form-group">
                    <label>Company Phone</label>
                    <input type="tel" name="phone" id="editPhone">
                </div>
                <div class="form-group">
                    <label>Contact Name</label>
                    <input type="text" name="contact_name" id="editContactName">
                </div>
                <div class="form-group">
                    <label>Contact Email</label>
                    <input type="email" name="contact_email" id="editContactEmail">
                </div>
                <div class="form-group">
                    <label>Contact Phone</label>
                    <input type="tel" name="contact_phone" id="editContactPhone">
                </div>
                <div class="form-group full-col">
                    <label>Description / Notes</label>
                    <textarea name="description" id="editDesc" rows="3"></textarea>
                </div>
            </div>
            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button type="button" class="btn btn-ghost"
                        onclick="document.getElementById('editModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes →</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEdit(co) {
    document.getElementById('editId').value           = co.id;
    document.getElementById('editName').value         = co.name        || '';
    document.getElementById('editCity').value         = co.city        || '';
    document.getElementById('editAddress').value      = co.address     || '';
    document.getElementById('editSector').value       = co.sector      || '';
    document.getElementById('editWebsite').value      = co.website     || '';
    document.getElementById('editPhone').value        = co.phone       || '';
    document.getElementById('editContactName').value  = co.contact_name  || '';
    document.getElementById('editContactEmail').value = co.contact_email || '';
    document.getElementById('editContactPhone').value = co.contact_phone || '';
    document.getElementById('editDesc').value         = co.description || '';
    document.getElementById('editModal').style.display = 'flex';
}
document.getElementById('editModal').addEventListener('click', function(e){
    if(e.target===this) this.style.display='none';
});
</script>

<?php include '../includes/footer.php'; ?>
