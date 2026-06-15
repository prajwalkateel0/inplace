<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('provider');

$pageTitle    = 'Messages';
$pageSubtitle = 'Communicate with students and tutors';
$activePage   = 'messages';
$userId       = authId();

// Get provider's company
$stmt = $pdo->prepare("SELECT company_id FROM users WHERE id = ?");
$stmt->execute([$userId]);
$provider = $stmt->fetch();
$companyId = $provider['company_id'] ?? null;

// Sidebar badges
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM placements WHERE company_id = ? AND status = 'awaiting_provider'");
$stmt->execute([$companyId ?? 0]);
$pendingRequests = (int)$stmt->fetchColumn();

if (!$companyId) {
    include '../includes/header.php';
    echo '<div class="main">';
    include '../includes/topbar.php';
    echo '<div class="page-content"><div class="panel" style="padding:3rem;text-align:center;">
        <p style="color:var(--danger);">Your account is not linked to a company. Contact the administrator.</p>
        <a href="/inplace/provider/dashboard.php" class="btn btn-primary" style="margin-top:1.5rem;">← Back</a>
        </div></div></div>';
    include '../includes/footer.php';
    exit;
}

/**
 * Auto-detect which column names exist in `messages`
 */
function pickCol(PDO $pdo, string $table, array $candidates): ?string {
    $placeholders = implode(',', array_fill(0, count($candidates), '?'));
    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
          AND COLUMN_NAME IN ($placeholders)
    ");
    $stmt->execute(array_merge([$table], $candidates));
    $found = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($candidates as $c) {
        if (in_array($c, $found, true)) return $c;
    }
    return null;
}

$timeCol = pickCol($pdo, 'messages', ['created_at','sent_at','timestamp','date_sent','sent_on','created_on']) ?? 'id';
$textCol = pickCol($pdo, 'messages', ['body','message','content','text']) ?? 'id';

// Contacts: students at company + their assigned tutors
$stmt = $pdo->prepare("
    SELECT DISTINCT u.id, u.full_name, u.role, u.email
    FROM placements p
    JOIN users u ON u.id = p.student_id
    WHERE p.company_id = ? AND p.status IN ('awaiting_provider','awaiting_tutor','approved','active')
    UNION
    SELECT DISTINCT u.id, u.full_name, u.role, u.email
    FROM placements p
    JOIN users u ON u.id = p.tutor_id
    WHERE p.company_id = ? AND p.tutor_id IS NOT NULL
      AND p.status IN ('awaiting_tutor','approved','active')
    ORDER BY full_name ASC
");
$stmt->execute([$companyId, $companyId]);
$contacts = $stmt->fetchAll();

// Conversations list
$sqlConvos = "
    SELECT
        t.other_id,
        u.full_name AS other_name,
        u.role      AS other_role,
        t.last_body,
        t.last_time,
        COALESCE(unread.unread_count, 0) AS unread_count
    FROM (
        SELECT
            CASE WHEN m.sender_id = ? THEN m.receiver_id ELSE m.sender_id END AS other_id,
            MAX(m.id) AS last_msg_id,
            MAX(m.`$timeCol`) AS last_time,
            SUBSTRING_INDEX(
                GROUP_CONCAT(m.`$textCol` ORDER BY m.id DESC SEPARATOR '|||'),
                '|||', 1
            ) AS last_body
        FROM messages m
        WHERE m.sender_id = ? OR m.receiver_id = ?
        GROUP BY other_id
    ) t
    JOIN users u ON u.id = t.other_id
    LEFT JOIN (
        SELECT sender_id AS other_id, COUNT(*) AS unread_count
        FROM messages
        WHERE receiver_id = ? AND is_read = 0
        GROUP BY sender_id
    ) unread ON unread.other_id = t.other_id
    ORDER BY t.last_time DESC
";
$stmt = $pdo->prepare($sqlConvos);
$stmt->execute([$userId, $userId, $userId, $userId]);
$conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Active chat
$withId = isset($_GET['with']) ? (int)$_GET['with'] : 0;
if ($withId <= 0 && !empty($conversations)) {
    $withId = (int)$conversations[0]['other_id'];
}

// Chat user info
$chatUser = null;
if ($withId > 0) {
    $stmt = $pdo->prepare("SELECT id, full_name, role FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$withId]);
    $chatUser = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Send message
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $toId = (int)($_POST['to_id'] ?? 0);
    $body = trim($_POST['body'] ?? '');

    if ($toId <= 0 || $body === '') {
        $error = 'Please type a message.';
    } else {
        if ($timeCol !== 'id') {
            $stmt = $pdo->prepare("
                INSERT INTO messages (sender_id, receiver_id, `$textCol`, `$timeCol`, is_read)
                VALUES (?, ?, ?, NOW(), 0)
            ");
            $stmt->execute([$userId, $toId, $body]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO messages (sender_id, receiver_id, `$textCol`, is_read)
                VALUES (?, ?, ?, 0)
            ");
            $stmt->execute([$userId, $toId, $body]);
        }
        header("Location: /inplace/provider/messages.php?with=$toId");
        exit;
    }
}

// Load thread + mark read
$thread = [];
if ($chatUser) {
    $stmt = $pdo->prepare("
        UPDATE messages SET is_read = 1
        WHERE receiver_id = ? AND sender_id = ? AND is_read = 0
    ");
    $stmt->execute([$userId, $withId]);

    $stmt = $pdo->prepare("
        SELECT id, sender_id, receiver_id, `$textCol` AS body, `$timeCol` AS created_at
        FROM messages
        WHERE (sender_id = ? AND receiver_id = ?)
           OR (sender_id = ? AND receiver_id = ?)
        ORDER BY id ASC
        LIMIT 300
    ");
    $stmt->execute([$userId, $withId, $withId, $userId]);
    $thread = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function timeLabel($val): string {
    if (!$val || is_numeric($val)) return '';
    $ts = strtotime($val);
    return $ts ? date('g:i A', $ts) : '';
}
function initials($name): string {
    $parts = preg_split('/\s+/', trim((string)$name));
    $a = strtoupper(substr($parts[0] ?? 'U', 0, 1));
    $b = strtoupper(substr($parts[1] ?? '', 0, 1));
    return $a . ($b ?: $a);
}
?>
<?php include '../includes/header.php'; ?>

<!-- New Message Modal -->
<div id="newMsgModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);
                              z-index:9999;align-items:center;justify-content:center;">
  <div style="background:white;border-radius:16px;width:90%;max-width:560px;max-height:80vh;
              display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
    <div style="padding:1.5rem 2rem;border-bottom:1px solid var(--border);
                display:flex;align-items:center;justify-content:space-between;">
      <h3 style="font-family:'Playfair Display',serif;font-size:1.25rem;color:var(--navy);">
        📨 New Message
      </h3>
      <button onclick="closeModal()" style="width:32px;height:32px;border:none;background:var(--cream);
              border-radius:8px;cursor:pointer;font-size:1.25rem;color:var(--muted);">×</button>
    </div>
    <div style="padding:1rem 2rem;border-bottom:1px solid var(--border);">
      <input type="text" id="contactSearch" placeholder="🔍 Search contacts..."
             oninput="filterContacts()"
             style="width:100%;padding:0.75rem 1rem;border:2px solid var(--border);
                    border-radius:10px;font-family:inherit;font-size:0.9375rem;">
    </div>
    <div id="contactList" style="flex:1;overflow-y:auto;padding:0.5rem;">
      <?php if (empty($contacts)): ?>
        <div style="text-align:center;padding:3rem 2rem;color:var(--muted);">
          <div style="font-size:2.5rem;margin-bottom:0.75rem;">👥</div>
          <p>No students or tutors linked to your company yet.</p>
        </div>
      <?php else: ?>
        <?php foreach ($contacts as $c): ?>
        <div class="contact-item"
             data-name="<?= htmlspecialchars(strtolower($c['full_name'])) ?>"
             onclick="startChat(<?= (int)$c['id'] ?>)"
             style="display:flex;align-items:center;gap:0.9rem;padding:0.9rem 1rem;
                    cursor:pointer;border-radius:10px;transition:background 0.15s;">
          <div style="width:40px;height:40px;border-radius:12px;background:var(--navy);color:white;
                      display:flex;align-items:center;justify-content:center;
                      font-weight:700;font-size:0.8rem;flex-shrink:0;">
            <?= htmlspecialchars(initials($c['full_name'])) ?>
          </div>
          <div style="flex:1;">
            <div style="font-weight:600;color:var(--navy);"><?= htmlspecialchars($c['full_name']) ?></div>
            <div style="font-size:0.8rem;color:var(--muted);"><?= htmlspecialchars($c['email']) ?></div>
          </div>
          <span style="padding:0.2rem 0.65rem;border-radius:50px;font-size:0.72rem;font-weight:600;
                       background:<?= $c['role']==='student'?'#dbeafe':'#ede9fe' ?>;
                       color:<?= $c['role']==='student'?'#1d4ed8':'#7c3aed' ?>;">
            <?= ucfirst(htmlspecialchars($c['role'])) ?>
          </span>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="main">
    <?php include '../includes/topbar.php'; ?>

    <div class="page-content">

        <?php if ($error): ?>
        <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:var(--radius);
                    padding:1rem 1.5rem;margin-bottom:1.25rem;">
            <p style="color:#991b1b;font-weight:600;">⚠️ <?= htmlspecialchars($error) ?></p>
        </div>
        <?php endif; ?>

        <div class="two-col" style="grid-template-columns:380px 1fr;">

            <!-- LEFT: Conversations -->
            <div class="panel">
                <div class="panel-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <h3>Conversations</h3>
                    <?php if (!empty($contacts)): ?>
                    <button onclick="openModal()" class="btn btn-primary btn-sm">➕ New</button>
                    <?php endif; ?>
                </div>
                <div class="panel-body" style="padding:0;">
                    <?php if (empty($conversations)): ?>
                    <div style="text-align:center;padding:3rem 1.5rem;color:var(--muted);">
                        <div style="font-size:2.25rem;margin-bottom:0.5rem;">💬</div>
                        <p style="margin-bottom:1rem;">No conversations yet.</p>
                        <?php if (!empty($contacts)): ?>
                        <button onclick="openModal()" class="btn btn-primary btn-sm">Start a Conversation</button>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <?php foreach ($conversations as $c): ?>
                    <?php $active = ($withId === (int)$c['other_id']); ?>
                    <a href="/inplace/provider/messages.php?with=<?= (int)$c['other_id'] ?>"
                       style="display:flex;gap:0.9rem;align-items:center;padding:1rem 1.25rem;
                              text-decoration:none;border-bottom:1px solid var(--border);
                              background:<?= $active ? 'var(--cream)' : 'transparent' ?>;">
                        <div style="width:40px;height:40px;border-radius:12px;background:var(--navy);color:white;
                                    display:flex;align-items:center;justify-content:center;
                                    font-weight:700;font-size:0.8rem;flex-shrink:0;">
                            <?= htmlspecialchars(initials($c['other_name'])) ?>
                        </div>
                        <div style="flex:1;min-width:0;">
                            <div style="display:flex;justify-content:space-between;gap:0.5rem;">
                                <div style="font-weight:600;color:var(--text);white-space:nowrap;
                                            overflow:hidden;text-overflow:ellipsis;">
                                    <?= htmlspecialchars($c['other_name']) ?>
                                </div>
                                <div style="font-size:0.75rem;color:var(--muted);white-space:nowrap;">
                                    <?= htmlspecialchars(timeLabel($c['last_time'])) ?>
                                </div>
                            </div>
                            <div style="display:flex;justify-content:space-between;gap:0.5rem;margin-top:0.2rem;">
                                <div style="font-size:0.8rem;color:var(--muted);overflow:hidden;
                                            text-overflow:ellipsis;white-space:nowrap;">
                                    <?= $c['last_body'] ? htmlspecialchars(mb_substr($c['last_body'], 0, 50)) : '—' ?>
                                </div>
                                <?php if ((int)$c['unread_count'] > 0): ?>
                                <span class="badge badge-open" style="min-width:24px;text-align:center;">
                                    <?= (int)$c['unread_count'] ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- RIGHT: Thread -->
            <div class="panel" style="display:flex;flex-direction:column;min-height:600px;">
                <div class="panel-header" style="display:flex;align-items:center;gap:0.9rem;">
                    <?php if ($chatUser): ?>
                    <div style="width:42px;height:42px;border-radius:14px;background:var(--navy);color:white;
                                display:flex;align-items:center;justify-content:center;font-weight:700;">
                        <?= htmlspecialchars(initials($chatUser['full_name'])) ?>
                    </div>
                    <div>
                        <div style="font-weight:700;"><?= htmlspecialchars($chatUser['full_name']) ?></div>
                        <div style="font-size:0.82rem;color:var(--muted);">
                            <?= ucfirst(htmlspecialchars($chatUser['role'] ?? '')) ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <div style="font-weight:700;color:var(--muted);">Select a conversation</div>
                    <?php endif; ?>
                </div>

                <div style="flex:1;padding:1.25rem;overflow:auto;background:var(--cream);">
                    <?php if (!$chatUser): ?>
                    <div style="text-align:center;padding:4rem 1rem;color:var(--muted);">
                        <div style="font-size:3rem;margin-bottom:1rem;">💬</div>
                        <p>Choose a conversation or start a new one</p>
                    </div>
                    <?php elseif (empty($thread)): ?>
                    <div style="text-align:center;padding:4rem 1rem;color:var(--muted);">
                        No messages yet. Say hi 👋
                    </div>
                    <?php else: ?>
                    <?php foreach ($thread as $m): ?>
                    <?php $mine = ((int)$m['sender_id'] === $userId); ?>
                    <div style="display:flex;justify-content:<?= $mine ? 'flex-end' : 'flex-start' ?>;
                                margin-bottom:0.9rem;">
                        <div style="max-width:70%;padding:0.9rem 1rem;border-radius:14px;
                                    background:<?= $mine ? 'var(--navy)' : 'white' ?>;
                                    color:<?= $mine ? 'white' : 'var(--text)' ?>;
                                    box-shadow:0 4px 12px rgba(0,0,0,0.07);">
                            <div style="white-space:pre-wrap;line-height:1.45;">
                                <?= htmlspecialchars($m['body']) ?>
                            </div>
                            <div style="font-size:0.72rem;margin-top:0.4rem;opacity:0.7;">
                                <?= htmlspecialchars(timeLabel($m['created_at'])) ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php if ($chatUser): ?>
                <div style="border-top:1px solid var(--border);padding:1rem 1.25rem;">
                    <form method="POST" style="display:flex;gap:0.75rem;align-items:center;">
                        <input type="hidden" name="to_id" value="<?= (int)$chatUser['id'] ?>">
                        <input type="text" name="body" placeholder="Type a message..."
                               style="flex:1;padding:0.875rem 1rem;border:2px solid var(--border);
                                      border-radius:12px;font-family:inherit;background:var(--cream);">
                        <button type="submit" name="send_message" class="btn btn-primary"
                                style="white-space:nowrap;">Send →</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<script>
function openModal()  { document.getElementById('newMsgModal').style.display = 'flex'; }
function closeModal() { document.getElementById('newMsgModal').style.display = 'none'; }
function startChat(id) { window.location.href = '/inplace/provider/messages.php?with=' + id; }

function filterContacts() {
    const q = document.getElementById('contactSearch').value.toLowerCase();
    document.querySelectorAll('.contact-item').forEach(el => {
        el.style.display = el.dataset.name.includes(q) ? 'flex' : 'none';
    });
}

// Hover for contact items
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.contact-item').forEach(el => {
        el.addEventListener('mouseenter', () => el.style.background = 'var(--cream)');
        el.addEventListener('mouseleave', () => el.style.background = 'transparent');
    });
});

document.getElementById('newMsgModal').addEventListener('click', e => {
    if (e.target.id === 'newMsgModal') closeModal();
});
</script>

<?php include '../includes/footer.php'; ?>
