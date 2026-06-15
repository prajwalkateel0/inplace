<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth();

header('Content-Type: application/json');

$userId   = authId();
$userRole = authRole();
$events   = [];

// ── Helper: format a date-time as FullCalendar ISO string ────────
function fcDate(string $date, ?string $time = null): string {
    if ($time && $time !== '00:00:00' && $time !== '00:00') {
        return $date . 'T' . substr($time, 0, 5);
    }
    return $date;
}

// ════════════════════════════════════════════════════════════════
// STUDENT
// ════════════════════════════════════════════════════════════════
if ($userRole === 'student') {

    // Active/approved placement
    $stmt = $pdo->prepare("
        SELECT p.*, c.name AS company_name
        FROM placements p
        JOIN companies c ON p.company_id = c.id
        WHERE p.student_id = ?
          AND p.status IN ('approved','active','awaiting_tutor','awaiting_provider')
        ORDER BY p.id DESC LIMIT 1
    ");
    $stmt->execute([$userId]);
    $placement = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($placement) {
        $pid = $placement['id'];

        // Placement start
        $events[] = [
            'id'              => 'pstart_' . $pid,
            'title'           => '🏢 Placement Starts — ' . $placement['company_name'],
            'start'           => $placement['start_date'],
            'allDay'          => true,
            'backgroundColor' => '#0c1b33',
            'borderColor'     => '#0c1b33',
            'textColor'       => '#ffffff',
            'extendedProps'   => ['type' => 'placement_start', 'description' => 'Your placement at ' . $placement['company_name'] . ' begins.'],
        ];

        // Placement end
        $events[] = [
            'id'              => 'pend_' . $pid,
            'title'           => '🏁 Placement Ends — ' . $placement['company_name'],
            'start'           => $placement['end_date'],
            'allDay'          => true,
            'backgroundColor' => '#64748b',
            'borderColor'     => '#64748b',
            'textColor'       => '#ffffff',
            'extendedProps'   => ['type' => 'placement_end', 'description' => 'Your placement at ' . $placement['company_name'] . ' ends.'],
        ];

        // Interim report deadline (start + 4 months)
        $interimDue = (new DateTime($placement['start_date']))->modify('+4 months')->format('Y-m-d');
        $events[] = [
            'id'              => 'interim_' . $pid,
            'title'           => '📋 Interim Report Due',
            'start'           => $interimDue,
            'allDay'          => true,
            'backgroundColor' => '#f97316',
            'borderColor'     => '#f97316',
            'textColor'       => '#ffffff',
            'extendedProps'   => ['type' => 'report_deadline', 'description' => 'Interim report must be submitted by this date.'],
        ];

        // Final report deadline (end - 1 month)
        $finalDue = (new DateTime($placement['end_date']))->modify('-1 month')->format('Y-m-d');
        $events[] = [
            'id'              => 'final_' . $pid,
            'title'           => '📝 Final Report Due',
            'start'           => $finalDue,
            'allDay'          => true,
            'backgroundColor' => '#ef4444',
            'borderColor'     => '#ef4444',
            'textColor'       => '#ffffff',
            'extendedProps'   => ['type' => 'report_deadline', 'description' => 'Final report must be submitted by this date.'],
        ];
    }

    // Visits
    $stmt = $pdo->prepare("
        SELECT v.*, c.name AS company_name, u.full_name AS tutor_name
        FROM visits v
        JOIN placements p ON v.placement_id = p.id
        JOIN companies c ON p.company_id = c.id
        LEFT JOIN users u ON v.tutor_id = u.id
        WHERE p.student_id = ?
        ORDER BY v.visit_date ASC
    ");
    $stmt->execute([$userId]);
    $visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($visits as $v) {
        $statusColors = [
            'scheduled'  => ['#3b82f6', '#3b82f6'],
            'confirmed'  => ['#10b981', '#10b981'],
            'completed'  => ['#6b7280', '#6b7280'],
            'cancelled'  => ['#fca5a5', '#ef4444'],
        ];
        [$bg, $border] = $statusColors[$v['status']] ?? ['#3b82f6', '#3b82f6'];

        $typeLabel = ucwords(str_replace('_', ' ', $v['type'] ?? 'visit'));
        $title = '📅 ' . $typeLabel;
        if ($v['company_name']) $title .= ' — ' . $v['company_name'];

        $desc = '';
        if ($v['tutor_name'])  $desc .= 'Tutor: ' . $v['tutor_name'] . "\n";
        if ($v['location'])    $desc .= 'Location: ' . $v['location'] . "\n";
        if ($v['meeting_link'])$desc .= 'Link: ' . $v['meeting_link'];

        $events[] = [
            'id'              => 'visit_' . $v['id'],
            'title'           => $title,
            'start'           => fcDate($v['visit_date'], $v['visit_time'] ?? null),
            'allDay'          => empty($v['visit_time']) || $v['visit_time'] === '00:00:00',
            'backgroundColor' => $bg,
            'borderColor'     => $border,
            'textColor'       => '#ffffff',
            'extendedProps'   => [
                'type'        => 'visit',
                'status'      => $v['status'],
                'description' => trim($desc),
            ],
        ];
    }
}

// ════════════════════════════════════════════════════════════════
// TUTOR
// ════════════════════════════════════════════════════════════════
elseif ($userRole === 'tutor') {

    // All active placements visible to tutor
    $stmt = $pdo->query("
        SELECT p.*, c.name AS company_name, u.full_name AS student_name
        FROM placements p
        JOIN companies c ON p.company_id = c.id
        JOIN users u ON p.student_id = u.id
        WHERE p.status IN ('approved','active','awaiting_tutor')
        ORDER BY p.start_date ASC
    ");
    $placements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($placements as $p) {
        $pid = $p['id'];
        $sn  = $p['student_name'];
        $cn  = $p['company_name'];

        // Placement start
        $events[] = [
            'id'              => 'pstart_' . $pid,
            'title'           => '🏢 ' . $sn . ' @ ' . $cn,
            'start'           => $p['start_date'],
            'end'             => (new DateTime($p['end_date']))->modify('+1 day')->format('Y-m-d'),
            'allDay'          => true,
            'display'         => 'background',
            'backgroundColor' => 'rgba(12,27,51,0.08)',
            'extendedProps'   => ['type' => 'placement_span', 'description' => $sn . ' at ' . $cn],
        ];

        $events[] = [
            'id'              => 'pstartmark_' . $pid,
            'title'           => '🏢 Start: ' . $sn,
            'start'           => $p['start_date'],
            'allDay'          => true,
            'backgroundColor' => '#0c1b33',
            'borderColor'     => '#0c1b33',
            'textColor'       => '#fff',
            'extendedProps'   => ['type' => 'placement_start', 'description' => $sn . ' starts placement at ' . $cn],
        ];

        $events[] = [
            'id'              => 'pendmark_' . $pid,
            'title'           => '🏁 End: ' . $sn,
            'start'           => $p['end_date'],
            'allDay'          => true,
            'backgroundColor' => '#64748b',
            'borderColor'     => '#64748b',
            'textColor'       => '#fff',
            'extendedProps'   => ['type' => 'placement_end', 'description' => $sn . ' ends placement at ' . $cn],
        ];

        // Interim report deadline
        $interimDue = (new DateTime($p['start_date']))->modify('+4 months')->format('Y-m-d');
        $events[] = [
            'id'              => 'interim_' . $pid,
            'title'           => '📋 Interim Due: ' . $sn,
            'start'           => $interimDue,
            'allDay'          => true,
            'backgroundColor' => '#f97316',
            'borderColor'     => '#f97316',
            'textColor'       => '#fff',
            'extendedProps'   => ['type' => 'report_deadline', 'description' => $sn . ' — interim report deadline'],
        ];

        // Final report deadline
        $finalDue = (new DateTime($p['end_date']))->modify('-1 month')->format('Y-m-d');
        $events[] = [
            'id'              => 'final_' . $pid,
            'title'           => '📝 Final Due: ' . $sn,
            'start'           => $finalDue,
            'allDay'          => true,
            'backgroundColor' => '#ef4444',
            'borderColor'     => '#ef4444',
            'textColor'       => '#fff',
            'extendedProps'   => ['type' => 'report_deadline', 'description' => $sn . ' — final report deadline'],
        ];
    }

    // All visits for tutor
    $stmt = $pdo->prepare("
        SELECT v.*, c.name AS company_name, u.full_name AS student_name
        FROM visits v
        JOIN placements p ON v.placement_id = p.id
        JOIN companies c ON p.company_id = c.id
        JOIN users u ON p.student_id = u.id
        WHERE v.tutor_id = ? OR p.tutor_id = ?
        ORDER BY v.visit_date ASC
    ");
    $stmt->execute([$userId, $userId]);
    $visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($visits as $v) {
        $statusColors = [
            'scheduled' => '#3b82f6',
            'confirmed' => '#10b981',
            'completed' => '#6b7280',
            'cancelled' => '#ef4444',
        ];
        $bg = $statusColors[$v['status']] ?? '#3b82f6';
        $typeLabel = ucwords(str_replace('_', ' ', $v['type'] ?? 'visit'));

        $events[] = [
            'id'              => 'visit_' . $v['id'],
            'title'           => '📅 ' . $typeLabel . ': ' . $v['student_name'],
            'start'           => fcDate($v['visit_date'], $v['visit_time'] ?? null),
            'allDay'          => empty($v['visit_time']) || $v['visit_time'] === '00:00:00',
            'backgroundColor' => $bg,
            'borderColor'     => $bg,
            'textColor'       => '#ffffff',
            'extendedProps'   => [
                'type'        => 'visit',
                'status'      => $v['status'],
                'description' => $v['student_name'] . ' @ ' . $v['company_name'] . ($v['location'] ? "\nLocation: " . $v['location'] : ''),
            ],
        ];
    }
}

// ════════════════════════════════════════════════════════════════
// PROVIDER
// ════════════════════════════════════════════════════════════════
elseif ($userRole === 'provider') {

    $stmt = $pdo->prepare("SELECT company_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $companyId = (int)$stmt->fetchColumn();

    if ($companyId) {
        // Placements at this company
        $stmt = $pdo->prepare("
            SELECT p.*, u.full_name AS student_name
            FROM placements p
            JOIN users u ON p.student_id = u.id
            WHERE p.company_id = ? AND p.status IN ('approved','active','awaiting_provider','awaiting_tutor')
            ORDER BY p.start_date ASC
        ");
        $stmt->execute([$companyId]);
        $placements = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($placements as $p) {
            $events[] = [
                'id'              => 'pstart_' . $p['id'],
                'title'           => '🏢 ' . $p['student_name'] . ' joins',
                'start'           => $p['start_date'],
                'allDay'          => true,
                'backgroundColor' => '#0c1b33',
                'borderColor'     => '#0c1b33',
                'textColor'       => '#fff',
                'extendedProps'   => ['type' => 'placement_start', 'description' => $p['student_name'] . ' starts their placement.'],
            ];
            $events[] = [
                'id'              => 'pend_' . $p['id'],
                'title'           => '🏁 ' . $p['student_name'] . ' leaves',
                'start'           => $p['end_date'],
                'allDay'          => true,
                'backgroundColor' => '#64748b',
                'borderColor'     => '#64748b',
                'textColor'       => '#fff',
                'extendedProps'   => ['type' => 'placement_end', 'description' => $p['student_name'] . ' ends their placement.'],
            ];
        }

        // Visits at this company
        $stmt = $pdo->prepare("
            SELECT v.*, u.full_name AS student_name, t.full_name AS tutor_name
            FROM visits v
            JOIN placements p ON v.placement_id = p.id
            JOIN users u ON p.student_id = u.id
            LEFT JOIN users t ON v.tutor_id = t.id
            WHERE p.company_id = ?
            ORDER BY v.visit_date ASC
        ");
        $stmt->execute([$companyId]);
        $visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($visits as $v) {
            $bg = ['scheduled'=>'#3b82f6','confirmed'=>'#10b981','completed'=>'#6b7280','cancelled'=>'#ef4444'][$v['status']] ?? '#3b82f6';
            $events[] = [
                'id'              => 'visit_' . $v['id'],
                'title'           => '📅 Visit: ' . $v['student_name'],
                'start'           => fcDate($v['visit_date'], $v['visit_time'] ?? null),
                'allDay'          => empty($v['visit_time']) || $v['visit_time'] === '00:00:00',
                'backgroundColor' => $bg,
                'borderColor'     => $bg,
                'textColor'       => '#fff',
                'extendedProps'   => [
                    'type'        => 'visit',
                    'status'      => $v['status'],
                    'description' => 'Student: ' . $v['student_name'] . "\nTutor: " . ($v['tutor_name'] ?? 'TBC') . ($v['location'] ? "\nAt: " . $v['location'] : ''),
                ],
            ];
        }
    }
}

echo json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
