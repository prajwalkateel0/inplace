<?php
/**
 * Generate .ics calendar file for a visit
 * Works with Outlook, Google Calendar, Apple Calendar
 */

require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth(['student', 'tutor']);

$visitId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($visitId <= 0) {
    die('Invalid visit ID');
}

// Get visit details
$stmt = $pdo->prepare("
    SELECT 
        v.*,
        p.role_title,
        s.full_name AS student_name,
        s.email AS student_email,
        t.full_name AS tutor_name,
        t.email AS tutor_email,
        c.name AS company_name,
        c.address,
        c.city,
        c.postal_code,
        c.country
    FROM visits v
    JOIN placements p ON v.placement_id = p.id
    JOIN users s ON p.student_id = s.id
    LEFT JOIN users t ON p.tutor_id = t.id
    LEFT JOIN companies c ON p.company_id = c.id
    WHERE v.id = ?
    LIMIT 1
");
$stmt->execute([$visitId]);
$visit = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$visit) {
    die('Visit not found');
}

// Build calendar event
$summary = $visit['role_title'] . ' - Placement Visit';
$location = $visit['company_name'];
if ($visit['address']) {
    $location .= ', ' . $visit['address'] . ', ' . $visit['city'] . ' ' . $visit['postal_code'];
}

$description = "Placement Visit\\n\\n";
$description .= "Student: " . $visit['student_name'] . "\\n";
if ($visit['tutor_name']) {
    $description .= "Tutor: " . $visit['tutor_name'] . "\\n";
}
$description .= "Company: " . $visit['company_name'] . "\\n";
if ($visit['purpose']) {
    $description .= "Purpose: " . $visit['purpose'] . "\\n";
}
if ($visit['notes']) {
    $description .= "Notes: " . $visit['notes'] . "\\n";
}

// Format dates for iCalendar (YYYYMMDDTHHmmss)
$startDateTime = new DateTime($visit['visit_date'] . ' ' . $visit['visit_time']);
$endDateTime = clone $startDateTime;
$endDateTime->modify('+' . ($visit['duration_hours'] ?? 2) . ' hours');

$dtStart = $startDateTime->format('Ymd\THis');
$dtEnd = $endDateTime->format('Ymd\THis');
$dtStamp = gmdate('Ymd\THis\Z');
$uid = 'visit-' . $visitId . '@inplace-system';

// Generate .ics content
$icsContent = "BEGIN:VCALENDAR\r\n";
$icsContent .= "VERSION:2.0\r\n";
$icsContent .= "PRODID:-//InPlace//Placement Visit//EN\r\n";
$icsContent .= "CALSCALE:GREGORIAN\r\n";
$icsContent .= "METHOD:PUBLISH\r\n";
$icsContent .= "BEGIN:VEVENT\r\n";
$icsContent .= "UID:" . $uid . "\r\n";
$icsContent .= "DTSTAMP:" . $dtStamp . "\r\n";
$icsContent .= "DTSTART:" . $dtStart . "\r\n";
$icsContent .= "DTEND:" . $dtEnd . "\r\n";
$icsContent .= "SUMMARY:" . $summary . "\r\n";
$icsContent .= "DESCRIPTION:" . $description . "\r\n";
$icsContent .= "LOCATION:" . $location . "\r\n";
$icsContent .= "STATUS:CONFIRMED\r\n";
$icsContent .= "SEQUENCE:0\r\n";

// Add attendees
if ($visit['student_email']) {
    $icsContent .= "ATTENDEE;CN=\"" . $visit['student_name'] . "\";ROLE=REQ-PARTICIPANT:mailto:" . $visit['student_email'] . "\r\n";
}
if ($visit['tutor_email']) {
    $icsContent .= "ATTENDEE;CN=\"" . $visit['tutor_name'] . "\";ROLE=REQ-PARTICIPANT:mailto:" . $visit['tutor_email'] . "\r\n";
}

// Add reminder (1 day before)
$icsContent .= "BEGIN:VALARM\r\n";
$icsContent .= "TRIGGER:-P1D\r\n";
$icsContent .= "ACTION:DISPLAY\r\n";
$icsContent .= "DESCRIPTION:Reminder: Placement visit tomorrow\r\n";
$icsContent .= "END:VALARM\r\n";

$icsContent .= "END:VEVENT\r\n";
$icsContent .= "END:VCALENDAR\r\n";

// Send headers to download .ics file
$filename = 'visit-' . date('Y-m-d', strtotime($visit['visit_date'])) . '.ics';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($icsContent));
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

echo $icsContent;
exit;