<?php
// Unread notifications from the notifications table
$_notifCount   = 0;
$_recentNotifs = [];
try {
    $_uid = authId();
    $s = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
    $s->execute([$_uid]);
    $_notifCount = (int)$s->fetchColumn();

    $s = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 10");
    $s->execute([$_uid]);
    $_recentNotifs = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>
<div class="topbar">
  <div class="topbar-title">
    <h2><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?></h2>
    <p><?= htmlspecialchars($pageSubtitle ?? '') ?></p>
  </div>
  <div class="topbar-actions">

    <!-- Notification Bell -->
    <div style="position:relative;">
      <button id="notifBtn" onclick="toggleNotifDropdown(event)"
              style="background:none;border:none;cursor:pointer;font-size:1.35rem;
                     position:relative;padding:0.3rem 0.4rem;line-height:1;">
        🔔
        <?php if ($_notifCount > 0): ?>
        <span id="notifBadge"
              style="position:absolute;top:-2px;right:-4px;background:#ef4444;color:white;
                     font-size:0.62rem;font-weight:700;padding:0.1rem 0.32rem;
                     border-radius:50px;min-width:17px;text-align:center;line-height:1.4;">
          <?= $_notifCount > 99 ? '99+' : $_notifCount ?>
        </span>
        <?php else: ?>
        <span id="notifBadge" style="display:none;position:absolute;top:-2px;right:-4px;
              background:#ef4444;color:white;font-size:0.62rem;font-weight:700;
              padding:0.1rem 0.32rem;border-radius:50px;min-width:17px;text-align:center;line-height:1.4;">
        </span>
        <?php endif; ?>
      </button>

      <!-- Dropdown -->
      <div id="notifDropdown"
           style="display:none;position:absolute;right:0;top:calc(100% + 10px);
                  width:360px;background:white;border-radius:14px;
                  box-shadow:0 12px 40px rgba(0,0,0,0.15);border:1px solid #e2e8f0;
                  z-index:9999;overflow:hidden;">

        <div style="padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;
                    display:flex;justify-content:space-between;align-items:center;">
          <h4 style="margin:0;font-size:0.9375rem;color:#0c1b33;font-weight:700;">Notifications</h4>
          <?php if ($_notifCount > 0): ?>
          <button onclick="markAllRead()" style="background:none;border:none;font-size:0.8rem;
                  color:#0c1b33;cursor:pointer;text-decoration:underline;padding:0;">
            Mark all read
          </button>
          <?php endif; ?>
        </div>

        <div style="max-height:400px;overflow-y:auto;">
          <?php if (empty($_recentNotifs)): ?>
          <div style="padding:2.5rem 1.5rem;text-align:center;color:#6b7a8d;font-size:0.875rem;">
            <div style="font-size:2rem;margin-bottom:0.5rem;">🔔</div>
            No notifications yet
          </div>
          <?php else: ?>
          <?php foreach ($_recentNotifs as $_n):
            $isUnread = !$_n['is_read'];
            $typeIcon = match(trim($_n['type'] ?? '')) {
              'evaluation'      => '⭐',
              'provider_issue'  => '⚠️',
              'role_change'     => '💼',
              'placement_change'=> '📋',
              'opportunity'     => '💡',
              'report_reviewed' => '📄',
              default           => '🔔',
            };
          ?>
          <div class="notif-item"
               data-id="<?= (int)$_n['id'] ?>"
               onclick="markOneRead(this, <?= (int)$_n['id'] ?>)"
               style="padding:0.9rem 1.25rem;border-bottom:1px solid #f1f5f9;cursor:pointer;
                      background:<?= $isUnread ? '#f0f9ff' : 'white' ?>;
                      transition:background 0.15s;">
            <div style="display:flex;gap:0.75rem;align-items:flex-start;">
              <span style="font-size:1.1rem;margin-top:0.1rem;"><?= $typeIcon ?></span>
              <div style="flex:1;min-width:0;">
                <div style="font-size:0.8625rem;color:#1a2332;line-height:1.5;
                            font-weight:<?= $isUnread ? '600' : '400' ?>;">
                  <?= htmlspecialchars($_n['message']) ?>
                </div>
                <div style="font-size:0.75rem;color:#6b7a8d;margin-top:0.25rem;">
                  <?= $_n['created_at'] ? date('d M Y, g:i A', strtotime($_n['created_at'])) : '' ?>
                </div>
              </div>
              <?php if ($isUnread): ?>
              <span style="width:8px;height:8px;background:#0ea5e9;border-radius:50%;
                           flex-shrink:0;margin-top:0.35rem;"></span>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>

      </div>
    </div>

    <a href="/inplace/logout.php" class="topbar-notif" title="Sign out">🚪</a>
  </div>
</div>

<script>
function toggleNotifDropdown(e) {
  e.stopPropagation();
  const d = document.getElementById('notifDropdown');
  d.style.display = d.style.display === 'none' ? 'block' : 'none';
}
document.addEventListener('click', function(e) {
  const d = document.getElementById('notifDropdown');
  if (d && !d.contains(e.target) && e.target.id !== 'notifBtn') {
    d.style.display = 'none';
  }
});

function markOneRead(el, id) {
  if (!el.dataset.read) {
    el.dataset.read = '1';
    el.style.background = 'white';
    const dot = el.querySelector('span[style*="background:#0ea5e9"]');
    if (dot) dot.remove();
    fetch('/inplace/api/notifications-read.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({id})
    });
    updateBadge(-1);
  }
}

function markAllRead() {
  document.querySelectorAll('.notif-item').forEach(el => {
    el.style.background = 'white';
    el.dataset.read = '1';
    const dot = el.querySelector('span[style*="background:#0ea5e9"]');
    if (dot) dot.remove();
  });
  fetch('/inplace/api/notifications-read.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({id: 0})
  });
  const badge = document.getElementById('notifBadge');
  if (badge) badge.style.display = 'none';
}

function updateBadge(delta) {
  const badge = document.getElementById('notifBadge');
  if (!badge) return;
  let count = parseInt(badge.textContent || '0', 10) + delta;
  if (count <= 0) { badge.style.display = 'none'; }
  else { badge.style.display = ''; badge.textContent = count > 99 ? '99+' : count; }
}
</script>
