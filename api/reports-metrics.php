<?php
require_once '../../includes/auth.php';
require_once '../../config/db.php';

requireAuth('tutor');
header('Content-Type: application/json; charset=utf-8');

// Filters
$filterStudent = trim($_GET['student'] ?? '');
$filterCompany = trim($_GET['company'] ?? '');
$filterStatus  = trim($_GET['status'] ?? '');

$where = ["LOWER(p.status) IN ('approved','active','completed')"];
$params = [];

if ($filterStudent !== '') {
  $where[] = "u.full_name LIKE ?";
  $params[] = "%$filterStudent%";
}
if ($filterCompany !== '') {
  $where[] = "c.name LIKE ?";
  $params[] = "%$filterCompany%";
}
if ($filterStatus !== '') {
  $where[] = "LOWER(p.status) = ?";
  $params[] = strtolower($filterStatus);
}

$whereSQL = "WHERE " . implode(" AND ", $where);

try {
  // KPI total placements
  $stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM placements p
    JOIN users u ON u.id = p.student_id
    JOIN companies c ON c.id = p.company_id
    $whereSQL
  ");
  $stmt->execute($params);
  $totalPlacements = (int)$stmt->fetchColumn();

  // KPI total reflections
  $stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM reflections r
    JOIN placements p ON p.id = r.placement_id
    JOIN users u ON u.id = p.student_id
    JOIN companies c ON c.id = p.company_id
    $whereSQL
  ");
  $stmt->execute($params);
  $totalReflections = (int)$stmt->fetchColumn();

  // KPI total visits
  $stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM visits v
    JOIN placements p ON p.id = v.placement_id
    JOIN users u ON u.id = p.student_id
    JOIN companies c ON c.id = p.company_id
    $whereSQL
  ");
  $stmt->execute($params);
  $totalVisits = (int)$stmt->fetchColumn();

  $avgReflections = $totalPlacements > 0 ? round($totalReflections / $totalPlacements, 1) : 0;

  // Chart: placements by status
  $stmt = $pdo->prepare("
    SELECT LOWER(p.status) AS status, COUNT(*) AS cnt
    FROM placements p
    JOIN users u ON u.id = p.student_id
    JOIN companies c ON c.id = p.company_id
    $whereSQL
    GROUP BY LOWER(p.status)
    ORDER BY cnt DESC
  ");
  $stmt->execute($params);
  $status = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // Chart: placements by city (top 8)
  $stmt = $pdo->prepare("
    SELECT COALESCE(NULLIF(c.city,''),'Unknown') AS city, COUNT(*) AS cnt
    FROM placements p
    JOIN users u ON u.id = p.student_id
    JOIN companies c ON c.id = p.company_id
    $whereSQL
    GROUP BY city
    ORDER BY cnt DESC
    LIMIT 8
  ");
  $stmt->execute($params);
  $city = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // Chart: reflections trend (last 12 weeks) - safer than DATE_FORMAT
  $stmt = $pdo->prepare("
    SELECT CONCAT(YEAR(r.created_at), '-', LPAD(WEEK(r.created_at, 1), 2, '0')) AS yearweek,
           COUNT(*) AS cnt
    FROM reflections r
    JOIN placements p ON p.id = r.placement_id
    JOIN users u ON u.id = p.student_id
    JOIN companies c ON c.id = p.company_id
    $whereSQL
    GROUP BY yearweek
    ORDER BY yearweek DESC
    LIMIT 12
  ");
  $stmt->execute($params);
  $tmp = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $reflectionTrend = array_reverse($tmp);

  // Chart: visits by type (handles NULL type)
  $stmt = $pdo->prepare("
    SELECT COALESCE(NULLIF(LOWER(v.type),''),'unknown') AS type, COUNT(*) AS cnt
    FROM visits v
    JOIN placements p ON p.id = v.placement_id
    JOIN users u ON u.id = p.student_id
    JOIN companies c ON c.id = p.company_id
    $whereSQL
    GROUP BY type
    ORDER BY cnt DESC
  ");
  $stmt->execute($params);
  $visitType = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    'ok' => true,
    'kpis' => [
      'totalPlacements' => $totalPlacements,
      'totalReflections' => $totalReflections,
      'totalVisits' => $totalVisits,
      'avgReflections' => $avgReflections,
    ],
    'charts' => [
      'status' => $status,
      'city' => $city,
      'reflectionTrend' => $reflectionTrend,
      'visitType' => $visitType,
    ],
    'ts' => time()
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'error' => $e->getMessage()
  ]);
}