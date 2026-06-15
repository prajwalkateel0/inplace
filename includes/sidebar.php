<?php
// includes/sidebar.php
// auth.php must already be included by the parent page before this is included
// All helper functions (authRole, authName, authInitials) come from includes/auth.php
?>

<aside class="sidebar">

  <div class="sidebar-logo">
    <div class="wordmark">In<span>Place</span></div>
    <div class="subtext">Placement Management System</div>
    <div class="sidebar-role-pill">
      <?= htmlspecialchars(ucfirst(authRole())) ?>
    </div>
  </div>

  <nav class="sidebar-nav">

    <!-- ══════════════════════════════════
         STUDENT NAV
    ══════════════════════════════════ -->
    <?php if (authRole() === 'student'): ?>

      <a href="/inplace/student/dashboard.php"
         class="nav-item <?= ($activePage === 'dashboard') ? 'active' : '' ?>">
        <span class="nav-icon">🏠</span> Dashboard
      </a>

      <a href="/inplace/student/my-placement.php"
         class="nav-item <?= ($activePage === 'placement') ? 'active' : '' ?>">
        <span class="nav-icon">🏢</span> My Placement
      </a>

      <a href="/inplace/student/submit-request.php"
         class="nav-item <?= ($activePage === 'request') ? 'active' : '' ?>">
        <span class="nav-icon">📋</span> Submit Request
      </a>

      <a href="/inplace/student/reports.php"
         class="nav-item <?= ($activePage === 'reports') ? 'active' : '' ?>">
        <span class="nav-icon">📄</span> My Reports
      </a>

      <a href="/inplace/student/visits.php"
         class="nav-item <?= ($activePage === 'visits') ? 'active' : '' ?>">
        <span class="nav-icon">🗓</span> Visits
      </a>

      <a href="/inplace/student/messages.php"
         class="nav-item <?= ($activePage === 'messages') ? 'active' : '' ?>">
        <span class="nav-icon">💬</span> Messages
        <?php if (!empty($unreadCount) && $unreadCount > 0): ?>
          <span class="nav-badge"><?= (int)$unreadCount ?></span>
        <?php endif; ?>
      </a>

      <?php
        // Unread announcement count for student badge
        $unreadAnnBadge = 0;
        try {
            $stmt = $pdo->prepare("SELECT academic_year, programme_type FROM users WHERE id = ?");
            $stmt->execute([authId()]);
            $meRow = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM announcements a
                WHERE (a.expires_at IS NULL OR a.expires_at >= CURDATE())
                  AND (a.audience='all'
                       OR (a.audience='year'      AND a.target_value=?)
                       OR (a.audience='programme' AND a.target_value=?))
                  AND a.id NOT IN (
                      SELECT announcement_id FROM announcement_reads WHERE student_id=?
                  )
            ");
            $stmt->execute([$meRow['academic_year']??'', $meRow['programme_type']??'', authId()]);
            $unreadAnnBadge = (int)$stmt->fetchColumn();
        } catch (Exception $e) {}
      ?>
      <a href="/inplace/student/announcements.php"
         class="nav-item <?= ($activePage === 'announcements') ? 'active' : '' ?>">
        <span class="nav-icon">📢</span> Announcements
        <?php if ($unreadAnnBadge > 0): ?>
          <span class="nav-badge"><?= $unreadAnnBadge ?></span>
        <?php endif; ?>
      </a>

      <a href="/inplace/calendar.php"
         class="nav-item <?= ($activePage === 'calendar') ? 'active' : '' ?>">
        <span class="nav-icon">🗓</span> Calendar
      </a>


    <!-- ══════════════════════════════════
         TUTOR NAV
    ══════════════════════════════════ -->
    <?php elseif (authRole() === 'tutor'): ?>

      <a href="/inplace/tutor/dashboard.php"
         class="nav-item <?= ($activePage === 'dashboard') ? 'active' : '' ?>">
        <span class="nav-icon">🏠</span> Dashboard
      </a>

      <a href="/inplace/tutor/all-placements.php"
         class="nav-item <?= ($activePage === 'placements') ? 'active' : '' ?>">
        <span class="nav-icon">👥</span> All Placements
      </a>

      <a href="/inplace/tutor/requests.php"
         class="nav-item <?= ($activePage === 'requests') ? 'active' : '' ?>">
        <span class="nav-icon">📋</span> Auth Requests
        <?php if (!empty($pendingRequests) && $pendingRequests > 0): ?>
          <span class="nav-badge"><?= (int)$pendingRequests ?></span>
        <?php endif; ?>
      </a>

      <a href="/inplace/tutor/map-view.php"
         class="nav-item <?= ($activePage === 'map') ? 'active' : '' ?>">
        <span class="nav-icon">🗺</span> Map View
      </a>

      <a href="/inplace/tutor/visits.php"
         class="nav-item <?= ($activePage === 'visits') ? 'active' : '' ?>">
        <span class="nav-icon">🗓</span> Visit Planner
      </a>

      <a href="/inplace/tutor/reports.php"
         class="nav-item <?= ($activePage === 'reports') ? 'active' : '' ?>">
        <span class="nav-icon">📄</span> Reports
      </a>

      <a href="/inplace/tutor/messages.php"
         class="nav-item <?= ($activePage === 'messages') ? 'active' : '' ?>">
        <span class="nav-icon">💬</span> Messages
        <?php if (!empty($unreadCount) && $unreadCount > 0): ?>
          <span class="nav-badge"><?= (int)$unreadCount ?></span>
        <?php endif; ?>
      </a>

      <a href="/inplace/tutor/announcements.php"
         class="nav-item <?= ($activePage === 'announcements') ? 'active' : '' ?>">
        <span class="nav-icon">📢</span> Announcements
      </a>

      <a href="/inplace/calendar.php"
         class="nav-item <?= ($activePage === 'calendar') ? 'active' : '' ?>">
        <span class="nav-icon">🗓</span> Calendar
      </a>

      <a href="/inplace/tutor/create-placement.php"
         class="nav-item <?= ($activePage === 'create-placement') ? 'active' : '' ?>">
        <span class="nav-icon">➕</span> Add Placement
      </a>

      <a href="/inplace/tutor/providers.php"
         class="nav-item <?= ($activePage === 'providers') ? 'active' : '' ?>">
        <span class="nav-icon">🏢</span> Provider Directory
      </a>

      <a href="/inplace/tutor/provider-meeting.php"
         class="nav-item <?= ($activePage === 'provider-meetings') ? 'active' : '' ?>">
        <span class="nav-icon">🤝</span> Provider Meetings
      </a>

      <?php
        // At-risk badge: count of high-risk flagged placements
        $atRiskBadge = 0;
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM placements WHERE risk_flag=1 AND risk_level='high' AND status IN ('approved','active')");
            $atRiskBadge = (int)$stmt->fetchColumn();
        } catch (Exception $e) {}
      ?>
      <a href="/inplace/tutor/at-risk.php"
         class="nav-item <?= ($activePage === 'at-risk') ? 'active' : '' ?>">
        <span class="nav-icon">⚠️</span> At-Risk Students
        <?php if ($atRiskBadge > 0): ?>
          <span class="nav-badge" style="background:#ef4444;"><?= $atRiskBadge ?></span>
        <?php endif; ?>
      </a>

      <a href="/inplace/tutor/settings.php"
         class="nav-item <?= ($activePage === 'tutor-settings') ? 'active' : '' ?>">
        <span class="nav-icon">⚙️</span> Cycle Settings
      </a>


    <!-- ══════════════════════════════════
         PROVIDER NAV
    ══════════════════════════════════ -->
    <?php elseif (authRole() === 'provider'): ?>

      <a href="/inplace/provider/dashboard.php"
         class="nav-item <?= ($activePage === 'dashboard') ? 'active' : '' ?>">
        <span class="nav-icon">🏠</span> Dashboard
      </a>

      <a href="/inplace/provider/requests.php"
         class="nav-item <?= ($activePage === 'requests') ? 'active' : '' ?>">
        <span class="nav-icon">📋</span> Auth Requests
        <?php if (!empty($pendingRequests) && $pendingRequests > 0): ?>
          <span class="nav-badge"><?= (int)$pendingRequests ?></span>
        <?php endif; ?>
      </a>

      <a href="/inplace/provider/students.php"
         class="nav-item <?= ($activePage === 'students') ? 'active' : '' ?>">
        <span class="nav-icon">👥</span> My Students
      </a>

      <a href="/inplace/provider/visits.php"
         class="nav-item <?= ($activePage === 'visits') ? 'active' : '' ?>">
        <span class="nav-icon">🗓</span> Scheduled Visits
      </a>

      <a href="/inplace/provider/messages.php"
         class="nav-item <?= ($activePage === 'messages') ? 'active' : '' ?>">
        <span class="nav-icon">💬</span> Messages
        <?php if (!empty($unreadCount) && $unreadCount > 0): ?>
          <span class="nav-badge"><?= (int)$unreadCount ?></span>
        <?php endif; ?>
      </a>

      <a href="/inplace/provider/evaluate.php"
         class="nav-item <?= ($activePage === 'evaluate') ? 'active' : '' ?>">
        <span class="nav-icon">⭐</span> Evaluations
      </a>

      <a href="/inplace/provider/issues.php"
         class="nav-item <?= ($activePage === 'issues') ? 'active' : '' ?>">
        <span class="nav-icon">⚠️</span> Report Issue
      </a>

      <a href="/inplace/provider/terminate.php"
         class="nav-item <?= ($activePage === 'terminate') ? 'active' : '' ?>">
        <span class="nav-icon">📢</span> Notify Change
      </a>

      <a href="/inplace/provider/opportunities.php"
         class="nav-item <?= ($activePage === 'opportunities') ? 'active' : '' ?>">
        <span class="nav-icon">💼</span> Opportunities
      </a>

      <a href="/inplace/provider/settings.php"
         class="nav-item <?= ($activePage === 'settings') ? 'active' : '' ?>">
        <span class="nav-icon">⚙️</span> Company Details
      </a>

      <a href="/inplace/calendar.php"
         class="nav-item <?= ($activePage === 'calendar') ? 'active' : '' ?>">
        <span class="nav-icon">🗓</span> Calendar
      </a>


    <!-- ══════════════════════════════════
         ADMIN NAV
    ══════════════════════════════════ -->
    <?php elseif (authRole() === 'admin'): ?>

  <?php
    // Pending registration count
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE approval_status = 'pending'");
    $pendingApprovals = (int)$stmt->fetchColumn();
  ?>

  <a href="/inplace/admin/dashboard.php"
     class="nav-item <?= ($activePage === 'dashboard') ? 'active' : '' ?>">
    <span class="nav-icon">🏠</span> Dashboard
  </a>

  <a href="/inplace/admin/approve-registrations.php"
     class="nav-item <?= ($activePage === 'approve_registrations') ? 'active' : '' ?>">
    <span class="nav-icon">📝</span> Registration Approvals
    <?php if ($pendingApprovals > 0): ?>
      <span class="nav-badge"><?= $pendingApprovals ?></span>
    <?php endif; ?>
  </a>

  <a href="/inplace/admin/users.php"
     class="nav-item <?= ($activePage === 'users') ? 'active' : '' ?>">
    <span class="nav-icon">👥</span> Manage Users
  </a>

  <a href="/inplace/admin/placements.php"
     class="nav-item <?= ($activePage === 'placements') ? 'active' : '' ?>">
    <span class="nav-icon">🏢</span> All Placements
  </a>

  <a href="/inplace/admin/settings.php"
     class="nav-item <?= ($activePage === 'settings') ? 'active' : '' ?>">
    <span class="nav-icon">⚙️</span> Settings
  </a>

    <!-- ══════════════════════════════════
         DIRECTOR NAV
    ══════════════════════════════════ -->
    <?php elseif (authRole() === 'director'): ?>

      <a href="/inplace/director/dashboard.php"
         class="nav-item <?= ($activePage === 'dashboard') ? 'active' : '' ?>">
        <span class="nav-icon">🏠</span> Dashboard
      </a>

      <a href="/inplace/director/placements.php"
         class="nav-item <?= ($activePage === 'dir-placements') ? 'active' : '' ?>">
        <span class="nav-icon">📊</span> Placements
      </a>

      <a href="/inplace/director/map.php"
         class="nav-item <?= ($activePage === 'dir-map') ? 'active' : '' ?>">
        <span class="nav-icon">🗺</span> Map View
      </a>

      <a href="/inplace/director/at-risk.php"
         class="nav-item <?= ($activePage === 'dir-at-risk') ? 'active' : '' ?>">
        <span class="nav-icon">⚠️</span> At-Risk Students
        <?php
          $dirAtRisk = 0;
          try { $dirAtRisk = (int)$pdo->query("SELECT COUNT(*) FROM placements WHERE risk_flag=1 AND risk_level='high'")->fetchColumn(); } catch (Exception $e) {}
          if ($dirAtRisk > 0): ?>
          <span class="nav-badge" style="background:#ef4444;"><?= $dirAtRisk ?></span>
        <?php endif; ?>
      </a>

      <a href="/inplace/director/feedback.php"
         class="nav-item <?= ($activePage === 'dir-feedback') ? 'active' : '' ?>">
        <span class="nav-icon">⭐</span> Employer Feedback
      </a>

      <a href="/inplace/director/reports.php"
         class="nav-item <?= ($activePage === 'dir-reports') ? 'active' : '' ?>">
        <span class="nav-icon">📥</span> Reports &amp; Exports
      </a>

<?php endif; ?>

  <!-- ── Application Guide (all roles) ── -->
  <a href="/inplace/app-guide.php"
     class="nav-item <?= ($activePage === 'app-guide') ? 'active' : '' ?>"
     style="margin-top:auto;">
    <span class="nav-icon">📖</span> Application Guide
  </a>

  <!-- ── Profile link (all roles) ── -->
  <a href="/inplace/profile.php"
     class="nav-item <?= ($activePage === 'profile') ? 'active' : '' ?>">
    <span class="nav-icon">👤</span> My Profile
  </a>

  <!-- ── Sidebar Footer: User Info + Logout ── -->
  <div class="sidebar-footer">
    <a href="/inplace/profile.php" class="sidebar-user"
       style="text-decoration:none;display:flex;align-items:center;gap:0.75rem;
              border-radius:8px;padding:0.5rem;margin:-0.5rem;
              transition:background 0.2s;"
       onmouseover="this.style.background='rgba(255,255,255,0.07)'"
       onmouseout="this.style.background='transparent'"
       title="Edit Profile">
      <div class="sidebar-avatar">
        <?= htmlspecialchars(authInitials()) ?>
      </div>
      <div class="sidebar-user-info">
        <h4><?= htmlspecialchars(authName()) ?></h4>
        <p style="color:rgba(255,255,255,0.45);font-size:0.75rem;">
          <?= htmlspecialchars(ucfirst(authRole())) ?> · Edit profile
        </p>
      </div>
    </a>
    <a href="/inplace/logout.php"
       style="display:flex;align-items:center;gap:0.6rem;margin-top:1rem;
              padding:0.625rem 0.875rem;border-radius:8px;
              background:rgba(255,255,255,0.05);color:rgba(255,255,255,0.5);
              text-decoration:none;font-size:0.875rem;transition:all 0.2s;"
       onmouseover="this.style.background='rgba(255,255,255,0.1)';this.style.color='#fff'"
       onmouseout="this.style.background='rgba(255,255,255,0.05)';this.style.color='rgba(255,255,255,0.5)'">
      🚪 Sign Out
    </a>
  </div>

</aside>