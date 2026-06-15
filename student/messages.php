<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('student');

$pageTitle    = 'Messages';
$pageSubtitle = 'Welcome back, ' . explode(' ', authName())[0];
$activePage   = 'messages';
$userId       = authId();

// unread messages count for the sidebar badge
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount = (int)$stmt->fetchColumn();

// get all tutors assigned to this student via their placements
$stmt = $pdo->prepare("
    SELECT 
        u.id,
        u.full_name,
        u.email,
        u.role,
        COUNT(DISTINCT p.id) as placement_count,
        GROUP_CONCAT(DISTINCT p.role_title ORDER BY p.role_title SEPARATOR ' • ') as placement_roles,
        GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ' • ') as companies
    FROM placements p
    JOIN users u ON p.tutor_id = u.id
    LEFT JOIN companies c ON p.company_id = c.id
    WHERE p.student_id = ?
    AND p.tutor_id IS NOT NULL
    GROUP BY u.id, u.full_name, u.email, u.role
    ORDER BY u.full_name ASC
");
$stmt->execute([$userId]);
$assignedTutors = $stmt->fetchAll(PDO::FETCH_ASSOC);

// check which column names actually exist in the messages table
// (the schema uses 'body' and 'created_at' but this makes it flexible)
function pickExistingColumn(PDO $pdo, string $table, array $candidates): ?string {
    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME IN (" . implode(',', array_fill(0, count($candidates), '?')) . ")
    ");
    $stmt->execute(array_merge([$table], $candidates));
    $found = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($candidates as $c) {
        if (in_array($c, $found, true)) return $c;
    }
    return null;
}

$timeCol = pickExistingColumn($pdo, 'messages', [
    'created_at', 'sent_at', 'timestamp', 'date_sent', 'sent_on', 'created_on', 'time', 'uploaded_at'
]);

$textCol = pickExistingColumn($pdo, 'messages', [
    'body', 'message', 'content', 'text'
]);

if (!$timeCol) $timeCol = 'id';
if (!$textCol) $textCol = 'id';

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

// active chat
$withId = isset($_GET['with']) ? (int)$_GET['with'] : 0;
if ($withId <= 0 && !empty($conversations)) {
    $withId = (int)$conversations[0]['other_id'];
}

// chat user info
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
        $error = "Please type a message.";
    } else {
        $hasRealTime = ($timeCol !== 'id');

        if ($hasRealTime) {
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

        header("Location: /inplace/student/messages.php?with=" . $toId);
        exit;
    }
}

// Load thread + mark read
$thread = [];
if ($chatUser) {
    $stmt = $pdo->prepare("
        UPDATE messages
        SET is_read = 1
        WHERE receiver_id = ? AND sender_id = ? AND is_read = 0
    ");
    $stmt->execute([$userId, $withId]);

    $sqlThread = "
        SELECT id, sender_id, receiver_id, `$textCol` AS body, `$timeCol` AS created_at
        FROM messages
        WHERE (sender_id = ? AND receiver_id = ?)
           OR (sender_id = ? AND receiver_id = ?)
        ORDER BY id ASC
        LIMIT 300
    ";
    $stmt = $pdo->prepare($sqlThread);
    $stmt->execute([$userId, $withId, $withId, $userId]);
    $thread = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function timeLabel($val) {
    if ($val === null || $val === '') return '';
    if (is_numeric($val)) return '';
    $ts = strtotime($val);
    if (!$ts) return '';
    return date('g:i A', $ts);
}

function initials($name) {
    $parts = preg_split('/\s+/', trim((string)$name));
    $a = strtoupper(substr($parts[0] ?? 'U', 0, 1));
    $b = strtoupper(substr($parts[1] ?? '', 0, 1));
    return $a . ($b ?: $a);
}
?>
<?php include '../includes/header.php'; ?>

<!-- modal: start a new conversation with an assigned tutor -->
<div id="newMessageModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); 
                                  z-index:9999; align-items:center; justify-content:center;">
  <div style="background:white; border-radius:16px; width:90%; max-width:600px; 
              max-height:80vh; display:flex; flex-direction:column; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
    
    <!-- modal header -->
    <div style="padding:1.5rem 2rem; border-bottom:1px solid var(--border); display:flex; 
                align-items:center; justify-content:space-between;">
      <h3 style="font-family:'Playfair Display',serif; font-size:1.375rem; color:var(--navy);">
        📨 Message Your Tutor
      </h3>
      <button onclick="closeNewMessageModal()" 
              style="width:32px; height:32px; border:none; background:var(--cream); 
                     border-radius:8px; cursor:pointer; font-size:1.25rem; color:var(--muted);">
        ×
      </button>
    </div>

    <!-- search box to filter tutors -->
    <div style="padding:1rem 2rem; border-bottom:1px solid var(--border);">
      <input type="text" id="tutorSearch" placeholder="🔍 Search tutors..." 
             onkeyup="filterTutors()"
             style="width:100%; padding:0.75rem 1rem; border:2px solid var(--border); 
                    border-radius:10px; font-family:inherit; font-size:0.9375rem;">
    </div>

    <!-- list of tutors the student can message -->
    <div id="tutorList" style="flex:1; overflow-y:auto; padding:0.5rem;">
      <?php if (empty($assignedTutors)): ?>
        <div style="text-align:center; padding:3rem 2rem; color:var(--muted);">
          <div style="font-size:2.5rem; margin-bottom:1rem;">👨‍🏫</div>
          <h4 style="color:var(--navy); margin-bottom:0.5rem;">No Tutors Assigned</h4>
          <p style="font-size:0.875rem;">You don't have any tutors assigned to your placements yet.</p>
        </div>
      <?php else: ?>
        <?php foreach ($assignedTutors as $t): ?>
          <div class="tutor-item" 
               data-name="<?= htmlspecialchars(strtolower($t['full_name'])) ?>" 
               data-companies="<?= htmlspecialchars(strtolower($t['companies'] ?? '')) ?>"
               onclick="startConversation(<?= (int)$t['id'] ?>)"
               style="display:flex; align-items:center; gap:1rem; padding:1rem 1.5rem; 
                      cursor:pointer; border-radius:12px; transition:all 0.2s; margin:0.25rem 0;">
            
            <!-- avatar with initials -->
            <div style="width:44px; height:44px; border-radius:12px; 
                        background:#8b5cf6; color:white; 
                        display:flex; align-items:center; justify-content:center; 
                        font-weight:700; font-size:0.875rem; flex-shrink:0;">
              <?= htmlspecialchars(initials($t['full_name'])) ?>
            </div>

            <!-- tutor name, email and placement info -->
            <div style="flex:1; min-width:0;">
              <div style="font-weight:600; color:var(--navy); font-size:0.9375rem;">
                <?= htmlspecialchars($t['full_name']) ?>
                <?php if ($t['placement_count'] > 1): ?>
                  <span style="font-size:0.75rem; color:var(--muted); font-weight:400;">
                    (<?= $t['placement_count'] ?> placements)
                  </span>
                <?php endif; ?>
              </div>
              <div style="font-size:0.8125rem; color:var(--muted); margin-top:0.125rem;">
                <?= htmlspecialchars($t['email']) ?>
              </div>
              <?php if ($t['companies']): ?>
                <div style="font-size:0.75rem; color:var(--muted); margin-top:0.25rem;">
                  📍 <?= htmlspecialchars($t['placement_roles']) ?>
                </div>
                <div style="font-size:0.75rem; color:var(--muted); margin-top:0.125rem;">
                  🏢 <?= htmlspecialchars($t['companies']) ?>
                </div>
              <?php endif; ?>
            </div>

            <!-- role badge -->
            <span style="padding:0.25rem 0.75rem; border-radius:50px; font-size:0.75rem; 
                         font-weight:600; background:#8b5cf620; color:#8b5cf6; white-space:nowrap;">
              👨‍🏫 Tutor
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
            <div style="background:var(--danger-bg);border:1px solid #fca5a5;border-radius:var(--radius);
                        padding:1.25rem 2rem;margin-bottom:1.5rem;">
                <p style="color:var(--danger);font-weight:600;">⚠️ <?= htmlspecialchars($error) ?></p>
            </div>
        <?php endif; ?>

        <div class="two-col" style="grid-template-columns: 420px 1fr;">

            <!-- left side: conversation list -->
            <div class="panel">
                <div class="panel-header" style="display:flex; justify-content:space-between; align-items:center;">
                    <h3>Conversations</h3>
                    <?php if (!empty($assignedTutors)): ?>
                      <button onclick="openNewMessageModal()" class="btn btn-primary btn-sm">
                        ➕ New
                      </button>
                    <?php endif; ?>
                </div>

                <div class="panel-body" style="padding:0;">
                    <?php if (empty($conversations)): ?>
                        <div style="text-align:center;padding:2.5rem 1.5rem;">
                            <div style="font-size:2.25rem;margin-bottom:0.5rem;">💬</div>
                            <p style="color:var(--muted); margin-bottom:1rem;">No conversations yet.</p>
                            <?php if (!empty($assignedTutors)): ?>
                              <button onclick="openNewMessageModal()" class="btn btn-primary">
                                Message Your Tutor
                              </button>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <?php foreach ($conversations as $c): ?>
                            <?php $active = ($withId === (int)$c['other_id']); ?>
                            <a href="/inplace/student/messages.php?with=<?= (int)$c['other_id'] ?>"
                               style="display:flex;gap:0.9rem;align-items:center;
                                      padding:1.1rem 1.25rem;
                                      text-decoration:none;
                                      border-bottom:1px solid var(--border);
                                      background:<?= $active ? 'var(--cream)' : 'transparent' ?>;">

                                <div style="width:40px;height:40px;border-radius:12px;
                                            background:var(--navy);color:white;
                                            display:flex;align-items:center;justify-content:center;
                                            font-weight:700;font-size:0.8rem;">
                                    <?= htmlspecialchars(initials($c['other_name'])) ?>
                                </div>

                                <div style="flex:1;min-width:0;">
                                    <div style="display:flex;justify-content:space-between;gap:0.75rem;">
                                        <div style="font-weight:650;color:var(--text);">
                                            <?= htmlspecialchars($c['other_name']) ?>
                                        </div>
                                        <div style="font-size:0.75rem;color:var(--muted);white-space:nowrap;">
                                            <?= htmlspecialchars(timeLabel($c['last_time'])) ?>
                                        </div>
                                    </div>

                                    <div style="display:flex;justify-content:space-between;gap:0.75rem;margin-top:0.2rem;">
                                        <div style="font-size:0.82rem;color:var(--muted);
                                                    overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                            <?= htmlspecialchars($c['other_role'] ? ucwords(str_replace('_',' ', $c['other_role'])) : '') ?>
                                            <?= $c['last_body'] ? '· ' . htmlspecialchars($c['last_body']) : '' ?>
                                        </div>

                                        <?php if ((int)$c['unread_count'] > 0): ?>
                                            <span class="badge badge-open" style="min-width:28px;text-align:center;">
                                                <?= (int)$c['unread_count'] ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="width:28px;"></span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- right side: chat thread -->
            <div class="panel" style="display:flex;flex-direction:column;min-height:620px;">
                <div class="panel-header" style="display:flex;align-items:center;gap:0.9rem;">
                    <?php if ($chatUser): ?>
                        <div style="width:42px;height:42px;border-radius:14px;background:var(--navy);color:white;
                                    display:flex;align-items:center;justify-content:center;font-weight:700;">
                            <?= htmlspecialchars(initials($chatUser['full_name'])) ?>
                        </div>
                        <div>
                            <div style="font-weight:700;"><?= htmlspecialchars($chatUser['full_name']) ?></div>
                            <div style="font-size:0.82rem;color:var(--muted);">
                                <?= htmlspecialchars($chatUser['role'] ? ucwords(str_replace('_',' ', $chatUser['role'])) : '') ?> · Online
                            </div>
                        </div>
                    <?php else: ?>
                        <div style="font-weight:700;">Select a conversation</div>
                    <?php endif; ?>
                </div>

                <div style="flex:1; padding:1.25rem; overflow:auto;">
                    <?php if (!$chatUser): ?>
                        <div style="text-align:center;padding:3rem 1rem;color:var(--muted);">
                            Choose a conversation from the left
                            <?php if (!empty($assignedTutors)): ?>
                              <br>or
                              <button onclick="openNewMessageModal()" class="btn btn-primary btn-sm" 
                                      style="margin-top:1rem;">
                                Message Your Tutor
                              </button>
                            <?php endif; ?>
                        </div>
                    <?php elseif (empty($thread)): ?>
                        <div style="text-align:center;padding:3rem 1rem;color:var(--muted);">
                            No messages yet. Say hi 👋
                        </div>
                    <?php else: ?>
                        <?php foreach ($thread as $m): ?>
                            <?php $mine = ((int)$m['sender_id'] === $userId); ?>
                            <div style="display:flex;justify-content:<?= $mine ? 'flex-end' : 'flex-start' ?>; margin-bottom:0.9rem;">
                                <div style="max-width:70%;
                                            padding:0.9rem 1rem;
                                            border-radius:14px;
                                            background:<?= $mine ? 'var(--navy)' : 'var(--cream)' ?>;
                                            color:<?= $mine ? 'white' : 'var(--text)' ?>;
                                            box-shadow:0 8px 25px rgba(0,0,0,0.06);">
                                    <div style="white-space:pre-wrap;line-height:1.45;">
                                        <?= htmlspecialchars($m['body']) ?>
                                    </div>
                                    <div style="font-size:0.72rem;margin-top:0.45rem;opacity:0.75;">
                                        <?= htmlspecialchars(timeLabel($m['created_at'])) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php if ($chatUser): ?>
                <div style="border-top:1px solid var(--border); padding:1rem 1.25rem;">
                    <form method="POST" style="display:flex;gap:0.75rem;align-items:center;">
                        <input type="hidden" name="to_id" value="<?= (int)$chatUser['id'] ?>">
                        <input type="text" name="body" placeholder="Type a message..."
                               style="flex:1;padding:0.9rem 1rem;border:2px solid var(--border);
                                      border-radius:12px;background:var(--cream);font-family:inherit;">
                        <button type="submit" name="send_message" class="btn btn-primary" style="white-space:nowrap;">
                            Send →
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<script>
function openNewMessageModal() {
  document.getElementById('newMessageModal').style.display = 'flex';
  document.getElementById('tutorSearch').value = '';
  document.getElementById('tutorSearch').focus();
  filterTutors(); // Show all
}

function closeNewMessageModal() {
  document.getElementById('newMessageModal').style.display = 'none';
}

function filterTutors() {
  const search = document.getElementById('tutorSearch').value.toLowerCase();
  const items = document.querySelectorAll('.tutor-item');
  
  items.forEach(item => {
    const name = item.getAttribute('data-name');
    const companies = item.getAttribute('data-companies');
    const matches = name.includes(search) || companies.includes(search);
    item.style.display = matches ? 'flex' : 'none';
  });
}

function startConversation(tutorId) {
  window.location.href = `/inplace/student/messages.php?with=${tutorId}`;
}

// hover effect for tutor list items
document.addEventListener('DOMContentLoaded', () => {
  const items = document.querySelectorAll('.tutor-item');
  items.forEach(item => {
    item.addEventListener('mouseenter', () => {
      item.style.background = 'var(--cream)';
    });
    item.addEventListener('mouseleave', () => {
      item.style.background = 'transparent';
    });
  });
});

// close modal if clicking outside the dialog box
document.getElementById('newMessageModal').addEventListener('click', (e) => {
  if (e.target.id === 'newMessageModal') {
    closeNewMessageModal();
  }
});
</script>

<?php include '../includes/footer.php'; ?>