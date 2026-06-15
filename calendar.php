<?php
require_once 'includes/auth.php';
require_once 'config/db.php';

requireAuth();

$pageTitle    = 'Calendar';
$pageSubtitle = 'Visits, report deadlines and placement dates';
$activePage   = 'calendar';
$userId       = authId();
$userRole     = authRole();

// Sidebar badge stubs
$pendingRequests = 0;
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount = (int)$stmt->fetchColumn();

// Fetch upcoming events for the agenda panel (next 60 days)
$today    = date('Y-m-d');
$horizon  = date('Y-m-d', strtotime('+60 days'));
$agenda   = [];

if ($userRole === 'student') {
    // Visits
    $stmt = $pdo->prepare("
        SELECT v.visit_date, v.visit_time, v.type, v.status,
               c.name AS company_name, u.full_name AS tutor_name,
               v.location, 'visit' AS event_type
        FROM visits v
        JOIN placements p ON v.placement_id = p.id
        JOIN companies c ON p.company_id = c.id
        LEFT JOIN users u ON v.tutor_id = u.id
        WHERE p.student_id = ? AND v.visit_date BETWEEN ? AND ?
        ORDER BY v.visit_date ASC, v.visit_time ASC
    ");
    $stmt->execute([$userId, $today, $horizon]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $agenda[] = ['date' => $r['visit_date'], 'time' => $r['visit_time'], 'type' => 'visit',
                     'title' => ucwords(str_replace('_',' ',$r['type'])) . ' — ' . $r['company_name'],
                     'sub'   => $r['tutor_name'] ? 'Tutor: ' . $r['tutor_name'] : '',
                     'status'=> $r['status'], 'color' => '#3b82f6'];
    }
    // Placement dates + report deadlines
    $stmt = $pdo->prepare("
        SELECT p.start_date, p.end_date, c.name AS company_name
        FROM placements p JOIN companies c ON p.company_id = c.id
        WHERE p.student_id = ? AND p.status IN ('approved','active') LIMIT 1
    ");
    $stmt->execute([$userId]);
    if ($p = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $milestones = [
            [$p['start_date'], '🏢 Placement Starts',       'placement_start', '#0c1b33'],
            [$p['end_date'],   '🏁 Placement Ends',         'placement_end',   '#64748b'],
            [(new DateTime($p['start_date']))->modify('+4 months')->format('Y-m-d'), '📋 Interim Report Due', 'report', '#f97316'],
            [(new DateTime($p['end_date']))->modify('-1 month')->format('Y-m-d'),    '📝 Final Report Due',   'report', '#ef4444'],
        ];
        foreach ($milestones as [$d, $t, $et, $c]) {
            if ($d >= $today && $d <= $horizon) {
                $agenda[] = ['date'=>$d,'time'=>null,'type'=>$et,'title'=>$t,'sub'=>$p['company_name'],'status'=>'','color'=>$c];
            }
        }
    }

} elseif ($userRole === 'tutor') {
    // Visits
    $stmt = $pdo->prepare("
        SELECT v.visit_date, v.visit_time, v.type, v.status,
               c.name AS company_name, u.full_name AS student_name,
               v.location, 'visit' AS event_type
        FROM visits v
        JOIN placements p ON v.placement_id = p.id
        JOIN companies c ON p.company_id = c.id
        JOIN users u ON p.student_id = u.id
        WHERE (v.tutor_id = ? OR p.tutor_id = ?) AND v.visit_date BETWEEN ? AND ?
        ORDER BY v.visit_date ASC, v.visit_time ASC
    ");
    $stmt->execute([$userId, $userId, $today, $horizon]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $agenda[] = ['date'=>$r['visit_date'],'time'=>$r['visit_time'],'type'=>'visit',
                     'title'=>ucwords(str_replace('_',' ',$r['type'])) . ': ' . $r['student_name'],
                     'sub'=>$r['company_name'],'status'=>$r['status'],'color'=>'#3b82f6'];
    }
    // Deadlines
    $stmt = $pdo->query("
        SELECT p.start_date, p.end_date, u.full_name AS student_name
        FROM placements p JOIN users u ON p.student_id = u.id
        WHERE p.status IN ('approved','active')
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $milestones = [
            [(new DateTime($p['start_date']))->modify('+4 months')->format('Y-m-d'), '📋 Interim: '.$p['student_name'], '#f97316'],
            [(new DateTime($p['end_date']))->modify('-1 month')->format('Y-m-d'),    '📝 Final: '  .$p['student_name'], '#ef4444'],
            [$p['start_date'], '🏢 Starts: '.$p['student_name'], '#0c1b33'],
            [$p['end_date'],   '🏁 Ends: '  .$p['student_name'], '#64748b'],
        ];
        foreach ($milestones as [$d, $t, $c]) {
            if ($d >= $today && $d <= $horizon) {
                $agenda[] = ['date'=>$d,'time'=>null,'type'=>'milestone','title'=>$t,'sub'=>'','status'=>'','color'=>$c];
            }
        }
    }

} elseif ($userRole === 'provider') {
    $stmt = $pdo->prepare("SELECT company_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $companyId = (int)$stmt->fetchColumn();

    if ($companyId) {
        $stmt = $pdo->prepare("
            SELECT v.visit_date, v.visit_time, v.type, v.status,
                   u.full_name AS student_name, v.location
            FROM visits v
            JOIN placements p ON v.placement_id = p.id
            JOIN users u ON p.student_id = u.id
            WHERE p.company_id = ? AND v.visit_date BETWEEN ? AND ?
            ORDER BY v.visit_date ASC
        ");
        $stmt->execute([$companyId, $today, $horizon]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $agenda[] = ['date'=>$r['visit_date'],'time'=>$r['visit_time'],'type'=>'visit',
                         'title'=>'Visit: '.$r['student_name'],
                         'sub'=>$r['location']??'','status'=>$r['status'],'color'=>'#3b82f6'];
        }
    }
}

// Sort agenda by date then time
usort($agenda, fn($a,$b) => ($a['date'].$a['time']) <=> ($b['date'].$b['time']));
?>
<?php include 'includes/header.php'; ?>

<!-- FullCalendar via CDN -->
<link href='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css' rel='stylesheet'>
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js'></script>

<style>
/* Calendar container */
#calendarWrap {
    background: var(--white);
    border-radius: var(--radius);
    padding: 1.5rem;
    box-shadow: var(--shadow);
}
/* FullCalendar overrides */
.fc { font-family: 'DM Sans', sans-serif; }
.fc-toolbar-title { font-family: 'Playfair Display', serif; font-size: 1.375rem !important; color: var(--navy); }
.fc-button-primary {
    background: var(--navy) !important; border-color: var(--navy) !important;
    font-family: 'DM Sans', sans-serif !important; font-size: 0.8125rem !important;
    font-weight: 600 !important; border-radius: 8px !important;
}
.fc-button-primary:not(:disabled):hover { background: #1a2d4d !important; }
.fc-button-primary:not(:disabled).fc-button-active { background: #e8a020 !important; border-color: #e8a020 !important; }
.fc-day-today { background: rgba(232,160,32,0.07) !important; }
.fc-event { border-radius: 5px !important; font-size: 0.78rem !important; cursor: pointer; }
.fc-daygrid-event { padding: 2px 5px !important; }
.fc-list-event:hover td { background: var(--cream) !important; }

/* Legend */
.legend-dot { width: 12px; height: 12px; border-radius: 50%; display: inline-block; flex-shrink: 0; }

/* Event detail popup */
#eventPopup {
    position: fixed; z-index: 2000;
    background: white; border-radius: 14px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
    padding: 1.5rem; min-width: 260px; max-width: 340px;
    display: none;
}
#eventPopup .ep-dot {
    width: 10px; height: 10px; border-radius: 50%;
    display: inline-block; margin-right: 0.4rem; flex-shrink: 0;
}
</style>

<div class="main">
    <?php include 'includes/topbar.php'; ?>

    <div class="page-content">

        <!-- Layout: Calendar left, Agenda right -->
        <div style="display:grid;grid-template-columns:1fr 310px;gap:1.5rem;align-items:start;">

            <!-- ── Calendar ──────────────────────────────────── -->
            <div>
                <!-- Legend -->
                <div style="display:flex;flex-wrap:wrap;gap:0.75rem 1.5rem;
                            margin-bottom:1rem;padding:0.875rem 1.25rem;
                            background:var(--white);border-radius:var(--radius);
                            box-shadow:var(--shadow);">
                    <?php
                    $legend = [
                        ['#0c1b33', 'Placement Start'],
                        ['#64748b', 'Placement End'],
                        ['#f97316', 'Interim Report Due'],
                        ['#ef4444', 'Final Report Due'],
                        ['#3b82f6', 'Visit (Scheduled)'],
                        ['#10b981', 'Visit (Confirmed)'],
                        ['#6b7280', 'Visit (Completed)'],
                    ];
                    foreach ($legend as [$color, $label]):
                    ?>
                    <div style="display:flex;align-items:center;gap:0.4rem;font-size:0.8125rem;color:var(--text);">
                        <span class="legend-dot" style="background:<?= $color ?>;"></span>
                        <?= $label ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Calendar -->
                <div id="calendarWrap">
                    <div id="fullcal"></div>
                </div>
            </div>

            <!-- ── Upcoming Agenda ────────────────────────────── -->
            <div class="panel" style="position:sticky;top:1.5rem;">
                <div class="panel-header" style="padding-bottom:0.75rem;">
                    <div>
                        <h3>Upcoming Events</h3>
                        <p>Next 60 days</p>
                    </div>
                </div>
                <div style="padding:0;max-height:600px;overflow-y:auto;">
                    <?php if (empty($agenda)): ?>
                    <div style="text-align:center;padding:2.5rem 1.5rem;">
                        <div style="font-size:2.5rem;margin-bottom:0.5rem;">🗓</div>
                        <p style="color:var(--muted);font-size:0.875rem;">Nothing scheduled in the next 60 days.</p>
                    </div>
                    <?php else:
                        $lastDate = null;
                        foreach ($agenda as $ev):
                            $evDate = new DateTime($ev['date']);
                            $isToday   = $ev['date'] === $today;
                            $isTomorrow= $ev['date'] === date('Y-m-d', strtotime('+1 day'));
                            if ($ev['date'] !== $lastDate):
                                $lastDate = $ev['date'];
                                $dayLabel = $isToday ? 'Today' : ($isTomorrow ? 'Tomorrow' : $evDate->format('D, d M'));
                    ?>
                    <div style="padding:0.5rem 1.25rem;background:var(--cream);
                                font-size:0.7rem;font-weight:700;text-transform:uppercase;
                                letter-spacing:0.08em;color:var(--muted);
                                border-bottom:1px solid var(--border);
                                <?= $isToday ? 'color:var(--navy);' : '' ?>">
                        <?= $dayLabel ?>
                    </div>
                    <?php endif; ?>

                    <div style="display:flex;align-items:flex-start;gap:0.75rem;
                                padding:0.875rem 1.25rem;border-bottom:1px solid var(--border);">
                        <div style="width:4px;min-height:40px;border-radius:2px;
                                    background:<?= $ev['color'] ?>;flex-shrink:0;margin-top:2px;"></div>
                        <div style="flex:1;min-width:0;">
                            <p style="font-size:0.875rem;font-weight:600;color:var(--navy);
                                      margin-bottom:0.15rem;white-space:nowrap;overflow:hidden;
                                      text-overflow:ellipsis;">
                                <?= htmlspecialchars($ev['title']) ?>
                            </p>
                            <?php if ($ev['sub']): ?>
                            <p style="font-size:0.8rem;color:var(--muted);">
                                <?= htmlspecialchars($ev['sub']) ?>
                            </p>
                            <?php endif; ?>
                            <?php if ($ev['time'] && $ev['time'] !== '00:00:00'): ?>
                            <p style="font-size:0.78rem;color:var(--muted);margin-top:0.1rem;">
                                🕐 <?= date('g:i A', strtotime($ev['time'])) ?>
                            </p>
                            <?php endif; ?>
                            <?php if ($ev['status']): ?>
                            <?php
                            $sBadge = match($ev['status']) {
                                'confirmed' => 'approved', 'completed' => 'open',
                                'cancelled' => 'rejected', default => 'pending'
                            };
                            ?>
                            <span class="badge badge-<?= $sBadge ?>" style="font-size:0.7rem;padding:0.15rem 0.5rem;margin-top:0.25rem;">
                                <?= ucfirst($ev['status']) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </div><!-- /grid -->
    </div><!-- /page-content -->
</div><!-- /main -->

<!-- Event detail popup -->
<div id="eventPopup">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:0.75rem;">
        <div style="display:flex;align-items:center;gap:0.4rem;flex:1;min-width:0;">
            <span class="ep-dot" id="epDot"></span>
            <strong id="epTitle" style="font-size:0.9375rem;color:var(--navy);word-break:break-word;"></strong>
        </div>
        <button onclick="document.getElementById('eventPopup').style.display='none'"
                style="background:none;border:none;cursor:pointer;font-size:1.1rem;
                       color:var(--muted);padding:0 0 0 0.5rem;flex-shrink:0;">✕</button>
    </div>
    <p id="epDate" style="font-size:0.8125rem;color:var(--muted);margin-bottom:0.5rem;"></p>
    <p id="epDesc" style="font-size:0.875rem;color:var(--text);line-height:1.6;white-space:pre-line;"></p>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const popup = document.getElementById('eventPopup');

    const cal = new FullCalendar.Calendar(document.getElementById('fullcal'), {
        initialView:     'dayGridMonth',
        headerToolbar: {
            left:   'prev,next today',
            center: 'title',
            right:  'dayGridMonth,timeGridWeek,listMonth'
        },
        buttonText: { today:'Today', month:'Month', week:'Week', list:'List' },
        height:          'auto',
        nowIndicator:    true,
        eventSources: [{
            url:   '/inplace/api/calendar-events.php',
            failure: function() {
                console.warn('Could not load calendar events.');
            }
        }],

        // Click handler — show popup
        eventClick: function(info) {
            const ev   = info.event;
            const ep   = ev.extendedProps;
            const bg   = ev.backgroundColor || '#3b82f6';

            document.getElementById('epDot').style.background = bg;
            document.getElementById('epTitle').textContent    = ev.title;

            const start = ev.start;
            const opts  = ev.allDay
                ? { weekday:'long', year:'numeric', month:'long', day:'numeric' }
                : { weekday:'long', year:'numeric', month:'long', day:'numeric', hour:'2-digit', minute:'2-digit' };
            document.getElementById('epDate').textContent = start
                ? start.toLocaleDateString('en-GB', opts) : '';

            document.getElementById('epDesc').textContent = ep.description || '';

            // Position near the click
            const rect = info.el.getBoundingClientRect();
            popup.style.display = 'block';
            const pw = popup.offsetWidth;
            const ph = popup.offsetHeight;
            let left = rect.right + 8;
            let top  = rect.top + window.scrollY;
            if (left + pw > window.innerWidth - 16) left = rect.left - pw - 8;
            if (top + ph > window.scrollY + window.innerHeight - 16)
                top = window.scrollY + window.innerHeight - ph - 16;
            popup.style.left = Math.max(8, left) + 'px';
            popup.style.top  = Math.max(8, top)  + 'px';

            info.jsEvent.stopPropagation();
        },

        // Close popup on calendar day/outside click
        dateClick: function() { popup.style.display = 'none'; },
    });

    cal.render();

    document.addEventListener('click', function(e) {
        if (!popup.contains(e.target)) popup.style.display = 'none';
    });

    // Responsive: switch to list view on narrow screens
    if (window.innerWidth < 700) {
        cal.changeView('listMonth');
    }
});
</script>

<?php include 'includes/footer.php'; ?>
