<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('tutor');

$pageTitle    = 'Messages';
$pageSubtitle = 'Welcome back, ' . explode(' ', authName())[0];
$activePage   = 'messages';
$userId       = authId();

// ===== helpers =====
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

    foreach ($candidates as $c) if (in_array($c, $found, true)) return $c;
    return null;
}

$timeCol = pickExistingColumn($pdo, 'messages', ['sent_at','created_at','timestamp','date_sent','sent_on']);
$textCol = pickExistingColumn($pdo, 'messages', ['body','message','content','text']);
if (!$timeCol) $timeCol = 'id';
if (!$textCol) $textCol = 'body';

// Online column (optional)
$onlineCol = pickExistingColumn($pdo, 'users', ['last_seen_at','last_active_at','last_seen','online_at']);

// Update tutor last seen (if column exists)
if ($onlineCol) {
    $stmt = $pdo->prepare("UPDATE users SET `$onlineCol` = NOW() WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
}

// Sidebar unread badge
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount = (int)$stmt->fetchColumn();

// ===== GET ALL USERS FOR "NEW MESSAGE" =====
$stmt = $pdo->query("
    SELECT id, full_name, email, role 
    FROM users 
    WHERE role IN ('student', 'provider', 'tutor', 'admin') 
    AND approval_status = 'approved'
    ORDER BY full_name ASC
");
$allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== 1) Conversations list =====
$sqlConvos = "
    SELECT
        t.other_id,
        u.full_name AS other_name,
        u.role      AS other_role,
        t.last_body,
        t.last_time,
        COALESCE(unread.unread_count, 0) AS unread_count
        " . ($onlineCol ? ", u.`$onlineCol` AS other_last_seen" : "") . "
    FROM (
        SELECT
            CASE WHEN m.sender_id = ? THEN m.receiver_id ELSE m.sender_id END AS other_id,
            MAX(m.id) AS last_msg_id,
            MAX(m.$timeCol) AS last_time,
            SUBSTRING_INDEX(
                GROUP_CONCAT(m.$textCol ORDER BY m.id DESC SEPARATOR '|||'),
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

// active chat user id
$withId = isset($_GET['with']) ? (int)$_GET['with'] : 0;
if ($withId <= 0 && !empty($conversations)) $withId = (int)$conversations[0]['other_id'];

// chat user info
$chatUser = null;
if ($withId > 0) {
    $stmt = $pdo->prepare("SELECT id, full_name, role " . ($onlineCol ? ", `$onlineCol` AS last_seen" : "") . " FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$withId]);
    $chatUser = $stmt->fetch(PDO::FETCH_ASSOC);
}

// mark read + load initial thread
$thread = [];
if ($chatUser) {
    $stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE receiver_id = ? AND sender_id = ? AND is_read = 0");
    $stmt->execute([$userId, $withId]);

    $sqlThread = "
        SELECT id, sender_id, receiver_id, `$textCol` AS body, `$timeCol` AS sent_at
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
    if ($val === null || $val === '' || is_numeric($val)) return '';
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

function isOnline($lastSeenStr, $minutes = 5) {
    if (!$lastSeenStr) return false;
    $ts = strtotime($lastSeenStr);
    if (!$ts) return false;
    return (time() - $ts) <= ($minutes * 60);
}

function getRoleBadgeColor($role) {
    switch(strtolower($role)) {
        case 'student': return '#0ea5e9';
        case 'tutor': return '#8b5cf6';
        case 'provider': return '#f59e0b';
        case 'admin': return '#ef4444';
        default: return '#6b7280';
    }
}
?>
<?php include '../includes/header.php'; ?>

<!-- NEW MESSAGE MODAL -->
<div id="newMessageModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); 
                                  z-index:9999; align-items:center; justify-content:center;">
  <div style="background:white; border-radius:16px; width:90%; max-width:600px; 
              max-height:80vh; display:flex; flex-direction:column; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
    
    <!-- Modal Header -->
    <div style="padding:1.5rem 2rem; border-bottom:1px solid var(--border); display:flex; 
                align-items:center; justify-content:space-between;">
      <h3 style="font-family:'Playfair Display',serif; font-size:1.375rem; color:var(--navy);">
        📨 New Message
      </h3>
      <button onclick="closeNewMessageModal()" 
              style="width:32px; height:32px; border:none; background:var(--cream); 
                     border-radius:8px; cursor:pointer; font-size:1.25rem; color:var(--muted);">
        ×
      </button>
    </div>

    <!-- Search Box -->
    <div style="padding:1rem 2rem; border-bottom:1px solid var(--border);">
      <input type="text" id="userSearch" placeholder="🔍 Search users..." 
             onkeyup="filterUsers()"
             style="width:100%; padding:0.75rem 1rem; border:2px solid var(--border); 
                    border-radius:10px; font-family:inherit; font-size:0.9375rem;">
    </div>

    <!-- User List -->
    <div id="userList" style="flex:1; overflow-y:auto; padding:0.5rem;">
      <?php foreach ($allUsers as $u): ?>
        <?php if ((int)$u['id'] === $userId) continue; // Skip self ?>
        <div class="user-item" data-name="<?= htmlspecialchars(strtolower($u['full_name'])) ?>" 
             data-role="<?= htmlspecialchars(strtolower($u['role'])) ?>"
             onclick="startConversation(<?= (int)$u['id'] ?>)"
             style="display:flex; align-items:center; gap:1rem; padding:1rem 1.5rem; 
                    cursor:pointer; border-radius:12px; transition:all 0.2s; margin:0.25rem 0;">
          
          <!-- Avatar -->
          <div style="width:44px; height:44px; border-radius:12px; 
                      background:<?= getRoleBadgeColor($u['role']) ?>; color:white; 
                      display:flex; align-items:center; justify-content:center; 
                      font-weight:700; font-size:0.875rem; flex-shrink:0;">
            <?= htmlspecialchars(initials($u['full_name'])) ?>
          </div>

          <!-- Info -->
          <div style="flex:1; min-width:0;">
            <div style="font-weight:600; color:var(--navy); font-size:0.9375rem;">
              <?= htmlspecialchars($u['full_name']) ?>
            </div>
            <div style="font-size:0.8125rem; color:var(--muted); margin-top:0.125rem;">
              <?= htmlspecialchars($u['email']) ?>
            </div>
          </div>

          <!-- Role Badge -->
          <span style="padding:0.25rem 0.75rem; border-radius:50px; font-size:0.75rem; 
                       font-weight:600; background:<?= getRoleBadgeColor($u['role']) ?>20; 
                       color:<?= getRoleBadgeColor($u['role']) ?>; white-space:nowrap;">
            <?= htmlspecialchars(ucfirst($u['role'])) ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>

  </div>
</div>

<div class="main">
  <?php include '../includes/topbar.php'; ?>

  <div class="page-content">
    <div class="two-col" style="grid-template-columns: 420px 1fr;">

      <!-- LEFT: Conversations -->
      <div class="panel">
        <div class="panel-header" style="display:flex; justify-content:space-between; align-items:center;">
          <h3>Conversations</h3>
          <button onclick="openNewMessageModal()" class="btn btn-primary btn-sm">
            ➕ New
          </button>
        </div>

        <div class="panel-body" style="padding:0;">
          <?php if (empty($conversations)): ?>
            <div style="text-align:center;padding:2.5rem 1.5rem;">
              <div style="font-size:2.25rem;margin-bottom:0.5rem;">💬</div>
              <p style="color:var(--muted); margin-bottom:1rem;">No conversations yet.</p>
              <button onclick="openNewMessageModal()" class="btn btn-primary">
                Start New Conversation
              </button>
            </div>
          <?php else: ?>
            <?php foreach ($conversations as $c): $active = ($withId === (int)$c['other_id']); ?>
              <a href="/inplace/tutor/messages.php?with=<?= (int)$c['other_id'] ?>"
                 style="display:flex;gap:0.9rem;align-items:center;
                        padding:1.1rem 1.25rem;text-decoration:none;
                        border-bottom:1px solid var(--border);
                        background:<?= $active ? 'var(--cream)' : 'transparent' ?>;">

                <div style="position:relative;width:40px;height:40px;border-radius:12px;
                            background:var(--navy);color:white;
                            display:flex;align-items:center;justify-content:center;
                            font-weight:700;font-size:0.8rem;">
                  <?= htmlspecialchars(initials($c['other_name'])) ?>

                  <!-- Unread DOT -->
                  <?php if ((int)$c['unread_count'] > 0): ?>
                    <span style="position:absolute;right:-2px;top:-2px;width:10px;height:10px;
                                 border-radius:50%;background:#0ea5e9;border:2px solid white;"></span>
                  <?php endif; ?>
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

      <!-- RIGHT: Chat -->
      <div class="panel" style="display:flex;flex-direction:column;min-height:620px;">
        <div class="panel-header" style="display:flex;align-items:center;gap:0.9rem;">
          <?php if ($chatUser): ?>
            <div style="width:42px;height:42px;border-radius:14px;background:var(--navy);color:white;
                        display:flex;align-items:center;justify-content:center;font-weight:700;">
              <?= htmlspecialchars(initials($chatUser['full_name'])) ?>
            </div>
            <div>
              <div style="font-weight:700;"><?= htmlspecialchars($chatUser['full_name']) ?></div>
              <div id="onlineText" style="font-size:0.82rem;color:var(--muted);">
                <?= htmlspecialchars($chatUser['role'] ? ucwords(str_replace('_',' ', $chatUser['role'])) : '') ?>
                ·
                <?php
                  $online = ($onlineCol && !empty($chatUser['last_seen'])) ? isOnline($chatUser['last_seen']) : true;
                  echo $online ? 'Online' : 'Offline';
                ?>
              </div>
            </div>
          <?php else: ?>
            <div style="font-weight:700;">Select a conversation</div>
          <?php endif; ?>
        </div>

        <div id="chatBox" style="flex:1; padding:1.25rem; overflow:auto;">
          <?php if (!$chatUser): ?>
            <div style="text-align:center;padding:3rem 1rem;color:var(--muted);">
              Choose a conversation from the left or 
              <button onclick="openNewMessageModal()" class="btn btn-primary btn-sm" 
                      style="margin-top:1rem;">
                Start New Conversation
              </button>
            </div>
          <?php elseif (empty($thread)): ?>
            <div id="emptyState" style="text-align:center;padding:3rem 1rem;color:var(--muted);">
              No messages yet. Say hi 👋
            </div>
          <?php else: ?>
            <?php foreach ($thread as $m): $mine = ((int)$m['sender_id'] === $userId); ?>
              <div class="msgRow" style="display:flex;justify-content:<?= $mine ? 'flex-end' : 'flex-start' ?>; margin-bottom:0.9rem;"
                   data-id="<?= (int)$m['id'] ?>">
                <div style="max-width:70%;
                            padding:0.9rem 1rem;border-radius:14px;
                            background:<?= $mine ? 'var(--navy)' : 'var(--cream)' ?>;
                            color:<?= $mine ? 'white' : 'var(--text)' ?>;
                            box-shadow:0 8px 25px rgba(0,0,0,0.06);">
                  <div style="white-space:pre-wrap;line-height:1.45;"><?= htmlspecialchars($m['body']) ?></div>
                  <div style="font-size:0.72rem;margin-top:0.45rem;opacity:0.75;">
                    <?= htmlspecialchars(timeLabel($m['sent_at'])) ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <?php if ($chatUser): ?>
        <div style="border-top:1px solid var(--border); padding:1rem 1.25rem;">
          <form id="sendForm" style="display:flex;gap:0.75rem;align-items:center;">
            <input type="hidden" id="toId" value="<?= (int)$chatUser['id'] ?>">
            <input id="msgInput" type="text" placeholder="Type a message..."
                   style="flex:1;padding:0.9rem 1rem;border:2px solid var(--border);
                          border-radius:12px;background:var(--cream);font-family:inherit;">
            <button type="submit" class="btn btn-primary" style="white-space:nowrap;">Send →</button>
          </form>
        </div>
        <?php endif; ?>

      </div>
    </div>
  </div>
</div>

<script>
// Modal Functions
function openNewMessageModal() {
  document.getElementById('newMessageModal').style.display = 'flex';
  document.getElementById('userSearch').value = '';
  document.getElementById('userSearch').focus();
  filterUsers(); // Show all
}

function closeNewMessageModal() {
  document.getElementById('newMessageModal').style.display = 'none';
}

function filterUsers() {
  const search = document.getElementById('userSearch').value.toLowerCase();
  const items = document.querySelectorAll('.user-item');
  
  items.forEach(item => {
    const name = item.getAttribute('data-name');
    const role = item.getAttribute('data-role');
    const matches = name.includes(search) || role.includes(search);
    item.style.display = matches ? 'flex' : 'none';
  });
}

function startConversation(userId) {
  window.location.href = `/inplace/tutor/messages.php?with=${userId}`;
}

// Hover effect for user items
document.addEventListener('DOMContentLoaded', () => {
  const items = document.querySelectorAll('.user-item');
  items.forEach(item => {
    item.addEventListener('mouseenter', () => {
      item.style.background = 'var(--cream)';
    });
    item.addEventListener('mouseleave', () => {
      item.style.background = 'transparent';
    });
  });
});

// Close modal when clicking outside
document.getElementById('newMessageModal').addEventListener('click', (e) => {
  if (e.target.id === 'newMessageModal') {
    closeNewMessageModal();
  }
});

// Message functionality (existing code)
(function(){
  const chatBox = document.getElementById('chatBox');
  const sendForm = document.getElementById('sendForm');
  const msgInput = document.getElementById('msgInput');
  const toIdEl = document.getElementById('toId');
  const onlineText = document.getElementById('onlineText');

  // Auto-scroll to bottom on load
  if (chatBox) chatBox.scrollTop = chatBox.scrollHeight;

  const withId = toIdEl ? parseInt(toIdEl.value, 10) : 0;

  function lastMsgId(){
    const rows = chatBox ? chatBox.querySelectorAll('.msgRow') : [];
    if (!rows.length) return 0;
    const last = rows[rows.length - 1];
    return parseInt(last.getAttribute('data-id') || '0', 10);
  }

  function appendMsg(m, mine){
    // remove empty state
    const empty = document.getElementById('emptyState');
    if (empty) empty.remove();

    const row = document.createElement('div');
    row.className = 'msgRow';
    row.setAttribute('data-id', m.id);
    row.style.display = 'flex';
    row.style.marginBottom = '0.9rem';
    row.style.justifyContent = mine ? 'flex-end' : 'flex-start';

    const bubble = document.createElement('div');
    bubble.style.maxWidth = '70%';
    bubble.style.padding = '0.9rem 1rem';
    bubble.style.borderRadius = '14px';
    bubble.style.boxShadow = '0 8px 25px rgba(0,0,0,0.06)';
    bubble.style.background = mine ? 'var(--navy)' : 'var(--cream)';
    bubble.style.color = mine ? 'white' : 'var(--text)';

    const body = document.createElement('div');
    body.style.whiteSpace = 'pre-wrap';
    body.style.lineHeight = '1.45';
    body.textContent = m.body;

    const time = document.createElement('div');
    time.style.fontSize = '0.72rem';
    time.style.marginTop = '0.45rem';
    time.style.opacity = '0.75';
    time.textContent = m.time || '';

    bubble.appendChild(body);
    bubble.appendChild(time);
    row.appendChild(bubble);
    chatBox.appendChild(row);

    chatBox.scrollTop = chatBox.scrollHeight;
  }

  // AJAX send (no refresh)
  if (sendForm) {
    sendForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const text = (msgInput.value || '').trim();
      if (!text || !withId) return;

      msgInput.value = '';
      msgInput.focus();

      const res = await fetch('/inplace/api/messages_send.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ to_id: withId, body: text })
      });
      const data = await res.json();
      if (data && data.ok && data.message) {
        appendMsg(data.message, true);
      } else {
        alert(data?.error || 'Send failed');
      }
    });
  }

  // Poll new messages every 2s
  async function poll(){
    if (!withId) return;
    const since = lastMsgId();

    const res = await fetch(`/inplace/api/messages_thread.php?with=${withId}&since=${since}`);
    const data = await res.json();

    if (data && data.ok) {
      if (typeof data.online !== 'undefined' && onlineText) {
        // Update Online/Offline
        const parts = onlineText.textContent.split('·');
        onlineText.textContent = (parts[0] ? parts[0].trim() : '') + ' · ' + (data.online ? 'Online' : 'Offline');
      }

      if (Array.isArray(data.messages) && data.messages.length) {
        data.messages.forEach(m => appendMsg(m, false));
      }
    }
  }
  setInterval(poll, 2000);

  // Ping endpoint to update your own last-seen (if column exists)
  setInterval(() => fetch('/inplace/api/ping.php').catch(()=>{}), 30000);
})();
</script>

<?php include '../includes/footer.php'; ?>