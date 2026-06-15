<?php
// /inplace/tools/geocode-companies.php
require_once __DIR__ . '/../config/db.php';

/**
 * Nominatim usage notes:
 * - Must send a valid User-Agent (include app name + contact)
 * - Rate-limit requests (~1/sec)
 * Docs: https://nominatim.org/release-docs/latest/api/Search/
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

function buildQuery(array $c): string {
    $parts = [];

    $addr = trim((string)($c['address'] ?? ''));
    $city = trim((string)($c['city'] ?? ''));
    $pc   = trim((string)($c['postcode'] ?? ''));

    if ($addr !== '') $parts[] = $addr;
    if ($city !== '') $parts[] = $city;
    if ($pc   !== '') $parts[] = $pc;

    // If your placements are UK-based, keep this:
    $parts[] = 'United Kingdom';

    return implode(', ', $parts);
}

function nominatimSearch(string $query): ?array {
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q' => $query,
        'format' => 'json',
        'limit' => 1,
        'addressdetails' => 1,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            // IMPORTANT: change contact email to yours
            'User-Agent: InPlacePlacementSystem/1.0 (contact: yourname@example.com)',
            'Accept: application/json',
        ],
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $err) return null;
    if ($code < 200 || $code >= 300) return null;

    $json = json_decode($raw, true);
    if (!is_array($json) || empty($json)) return null;

    $first = $json[0];
    if (!isset($first['lat'], $first['lon'])) return null;

    return [
        'lat' => (float)$first['lat'],
        'lng' => (float)$first['lon'],
        'display_name' => (string)($first['display_name'] ?? ''),
    ];
}

echo "<pre>";

try {
    // Fetch companies missing coordinates
    $stmt = $pdo->query("
        SELECT id, name, address, city, postcode
        FROM companies
        WHERE latitude IS NULL OR longitude IS NULL
        ORDER BY id ASC
        LIMIT 100
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        echo "✅ No companies to geocode. All have lat/lng.\n";
        exit;
    }

    $upd = $pdo->prepare("
        UPDATE companies
        SET latitude = ?, longitude = ?
        WHERE id = ?
    ");

    $ok = 0;
    $fail = 0;

    foreach ($rows as $c) {
        $query = buildQuery($c);

        echo "🔎 [{$c['id']}] {$c['name']} -> {$query}\n";

        $res = nominatimSearch($query);
        if (!$res) {
            echo "   ❌ Not found\n\n";
            $fail++;
            // Rate limit even on failures
            sleep(1);
            continue;
        }

        $upd->execute([$res['lat'], $res['lng'], $c['id']]);
        $ok++;

        echo "   ✅ lat={$res['lat']} lng={$res['lng']}\n";
        echo "   📍 {$res['display_name']}\n\n";

        // Rate limit (IMPORTANT)
        sleep(1);
    }

    echo "----------------------------------\n";
    echo "Done.\n";
    echo "✅ Updated: {$ok}\n";
    echo "❌ Failed:  {$fail}\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "</pre>";