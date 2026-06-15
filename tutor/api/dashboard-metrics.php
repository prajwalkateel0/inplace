<?php
require_once '../../includes/auth.php';
require_once '../../config/db.php';

requireAuth('tutor');

header('Content-Type: application/json');

try {

    // 1️⃣ Placements by Status
    $status = $pdo->query("
        SELECT LOWER(status) as status, COUNT(*) as cnt
        FROM placements
        GROUP BY LOWER(status)
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 2️⃣ Placements by City
    $city = $pdo->query("
        SELECT COALESCE(NULLIF(c.city,''),'Unknown') as city, COUNT(*) as cnt
        FROM placements p
        JOIN companies c ON c.id = p.company_id
        GROUP BY city
        ORDER BY cnt DESC
        LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 3️⃣ Reflections trend (last 12 weeks)
    $reflections = $pdo->query("
        SELECT DATE_FORMAT(created_at,'%Y-%u') as week, COUNT(*) as cnt
        FROM reflections
        GROUP BY week
        ORDER BY week DESC
        LIMIT 12
    ")->fetchAll(PDO::FETCH_ASSOC);

    $reflections = array_reverse($reflections);

    // 4️⃣ Visits by Status
    $visits = $pdo->query("
        SELECT LOWER(status) as status, COUNT(*) as cnt
        FROM visits
        GROUP BY LOWER(status)
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "ok" => true,
        "charts" => [
            "status" => $status,
            "city" => $city,
            "reflectionTrend" => $reflections,
            "visits" => $visits
        ],
        "ts" => time()
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => $e->getMessage()
    ]);
}