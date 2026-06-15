<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('admin');

// Apply same filters as placements.php
$filterStatus  = $_GET['status']  ?? '';
$filterCompany = $_GET['company'] ?? '';
$filterYear    = $_GET['year']    ?? '';
$filterSearch  = trim($_GET['search'] ?? '');

$where  = [];
$params = [];

if ($filterStatus) {
    $where[]  = "p.status = ?";
    $params[] = $filterStatus;
}
if ($filterCompany) {
    $where[]  = "p.company_id = ?";
    $params[] = $filterCompany;
}
if ($filterYear) {
    $where[]  = "u.academic_year = ?";
    $params[] = $filterYear;
}
if ($filterSearch) {
    $where[]  = "(u.full_name LIKE ? OR c.name LIKE ? OR p.role_title LIKE ?)";
    $params[] = "%$filterSearch%";
    $params[] = "%$filterSearch%";
    $params[] = "%$filterSearch%";
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT
        u.full_name         AS student_name,
        u.email             AS student_email,
        u.academic_year,
        u.programme_type,
        c.name              AS company_name,
        c.city              AS company_city,
        c.sector            AS company_sector,
        p.role_title,
        p.start_date,
        p.end_date,
        p.salary,
        p.working_pattern,
        p.status,
        p.supervisor_name,
        p.supervisor_email,
        p.supervisor_phone,
        (SELECT full_name FROM users WHERE id = p.tutor_id) AS tutor_name,
        p.created_at
    FROM placements p
    JOIN users u ON p.student_id = u.id
    JOIN companies c ON p.company_id = c.id
    $whereSQL
    ORDER BY p.created_at DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filename = 'placements_export_' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// BOM for Excel UTF-8 compatibility
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// Header row
fputcsv($out, [
    'Student Name', 'Student Email', 'Academic Year', 'Programme',
    'Company', 'City', 'Sector',
    'Role / Job Title', 'Start Date', 'End Date', 'Salary', 'Working Pattern',
    'Status', 'Tutor',
    'Supervisor Name', 'Supervisor Email', 'Supervisor Phone',
    'Submitted At'
]);

foreach ($rows as $r) {
    fputcsv($out, [
        $r['student_name'],
        $r['student_email'],
        $r['academic_year'],
        $r['programme_type'],
        $r['company_name'],
        $r['company_city'],
        $r['company_sector'],
        $r['role_title'],
        $r['start_date'],
        $r['end_date'],
        $r['salary'],
        $r['working_pattern'],
        ucwords(str_replace('_', ' ', $r['status'])),
        $r['tutor_name'] ?? 'Unassigned',
        $r['supervisor_name'],
        $r['supervisor_email'],
        $r['supervisor_phone'],
        $r['created_at'],
    ]);
}

fclose($out);
exit;
