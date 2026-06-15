<?php
header('Content-Type: application/json; charset=utf-8');

$postcode = trim($_GET['postcode'] ?? '');
$postcode = strtoupper(preg_replace('/\s+/', '', $postcode)); // remove spaces
if ($postcode === '' || strlen($postcode) < 5) {
  echo json_encode(['ok' => false, 'error' => 'Postcode required']);
  exit;
}
// add space back for display
$pcPretty = substr($postcode, 0, -3) . ' ' . substr($postcode, -3);

// ─────────────────────────────────────────────────────────────
// (A) FREE FALLBACK: postcodes.io gives city + lat/lng (no full addresses)
// ─────────────────────────────────────────────────────────────
function http_get_json($url) {
  $ctx = stream_context_create([
    'http' => [
      'method' => 'GET',
      'timeout' => 8,
      'header' => "User-Agent: inplace\r\n"
    ]
  ]);
  $raw = @file_get_contents($url, false, $ctx);
  if ($raw === false) return null;
  $json = json_decode($raw, true);
  return is_array($json) ? $json : null;
}

$geo = http_get_json("https://api.postcodes.io/postcodes/" . urlencode($pcPretty));
if (!$geo || empty($geo['status']) || $geo['status'] !== 200) {
  echo json_encode(['ok' => false, 'error' => 'No results for postcode']);
  exit;
}

$city = $geo['result']['admin_district'] ?? ($geo['result']['parish'] ?? '');
$lat  = $geo['result']['latitude'] ?? null;
$lng  = $geo['result']['longitude'] ?? null;

// ─────────────────────────────────────────────────────────────
// (B) OPTIONAL: If you have a PAID address API, plug it here.
// For now, we return a single “postcode centroid” option so UI works.
// ─────────────────────────────────────────────────────────────

$label = $pcPretty . ($city ? " — " . $city : "");

echo json_encode([
  'ok' => true,
  'results' => [
    [
      'label'   => $label,
      'line1'   => $pcPretty,
      'line2'   => '',
      'city'    => $city,
      'county'  => '',
      'postcode'=> $pcPretty,
      'lat'     => $lat,
      'lng'     => $lng
    ]
  ]
]);