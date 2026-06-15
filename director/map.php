<?php
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../config/app_config.php';
loadAppConfig($pdo);

requireAuth('director');

$pageTitle    = 'Placement Map';
$pageSubtitle = 'Geographic view of all active placement locations';
$activePage   = 'dir-map';
$userId       = authId();
$unreadCount  = 0; $pendingRequests = 0;

// ── Load all active placements with lat/lng ──────────────────────
$placements = $pdo->query("
    SELECT p.id, p.role_title, p.status, p.start_date, p.end_date,
           c.name AS company_name, c.city, c.sector, c.address,
           c.latitude, c.longitude,
           u.full_name AS student_name, u.academic_year,
           t.full_name AS tutor_name
    FROM placements p
    JOIN companies c ON p.company_id=c.id
    JOIN users u ON p.student_id=u.id
    LEFT JOIN users t ON p.tutor_id=t.id
    WHERE p.status IN ('approved','active')
    ORDER BY c.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$withCoords    = array_filter($placements, fn($p) => $p['latitude'] && $p['longitude']);
$withoutCoords = count($placements) - count($withCoords);

// Group by sector for legend
$sectorCounts = [];
foreach ($placements as $p) {
    $s = $p['sector'] ?: 'Unknown';
    $sectorCounts[$s] = ($sectorCounts[$s] ?? 0) + 1;
}
arsort($sectorCounts);
?>
<?php include '../includes/header.php'; ?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<div class="main">
    <?php include '../includes/topbar.php'; ?>
    <div class="page-content">

        <!-- Stats bar -->
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1.25rem;margin-bottom:1.5rem;">
            <div class="panel" style="margin-bottom:0;padding:1.25rem;">
                <p style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);margin-bottom:0.4rem;">Active Placements</p>
                <h3 style="font-family:'Playfair Display',serif;font-size:1.875rem;color:var(--navy);"><?= count($placements) ?></h3>
            </div>
            <div class="panel" style="margin-bottom:0;padding:1.25rem;">
                <p style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);margin-bottom:0.4rem;">On Map</p>
                <h3 style="font-family:'Playfair Display',serif;font-size:1.875rem;color:var(--success);"><?= count($withCoords) ?></h3>
            </div>
            <div class="panel" style="margin-bottom:0;padding:1.25rem;">
                <p style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);margin-bottom:0.4rem;">Sectors</p>
                <h3 style="font-family:'Playfair Display',serif;font-size:1.875rem;color:var(--info);"><?= count($sectorCounts) ?></h3>
            </div>
            <div class="panel" style="margin-bottom:0;padding:1.25rem;">
                <p style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);margin-bottom:0.4rem;">No Coords</p>
                <h3 style="font-family:'Playfair Display',serif;font-size:1.875rem;color:var(--warning);"><?= $withoutCoords ?></h3>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 280px;gap:1.5rem;align-items:start;">

            <!-- Map -->
            <div class="panel" style="margin-bottom:0;padding:0;overflow:hidden;">
                <div id="map" style="height:580px;width:100%;border-radius:var(--radius);"></div>
            </div>

            <!-- Right: Sector legend + list -->
            <div style="display:flex;flex-direction:column;gap:1.25rem;">
                <div class="panel" style="margin-bottom:0;">
                    <div class="panel-header"><h3>By Sector</h3></div>
                    <div style="padding:0.5rem 0;">
                        <?php $sColors = ['#0c1b33','#2563eb','#059669','#d97706','#7c3aed','#db2777','#0891b2','#65a30d','#dc2626','#374151'];
                        $si = 0;
                        foreach ($sectorCounts as $sec => $cnt): ?>
                        <div style="display:flex;align-items:center;gap:0.6rem;padding:0.5rem 1.25rem;
                                    border-bottom:1px solid var(--border);">
                            <span style="width:10px;height:10px;border-radius:50%;background:<?= $sColors[$si%count($sColors)] ?>;flex-shrink:0;"></span>
                            <span style="flex:1;font-size:0.8125rem;"><?= htmlspecialchars($sec) ?></span>
                            <span style="font-weight:700;font-size:0.875rem;"><?= $cnt ?></span>
                        </div>
                        <?php $si++; endforeach; ?>
                    </div>
                </div>

                <?php if ($withoutCoords > 0): ?>
                <div class="panel" style="margin-bottom:0;background:#fffbeb;border:1px solid #fde68a;">
                    <div style="padding:1rem 1.25rem;">
                        <p style="font-weight:600;color:#92400e;margin-bottom:0.25rem;">⚠️ <?= $withoutCoords ?> without coordinates</p>
                        <p style="font-size:0.8125rem;color:#78350f;">
                            These placements cannot be shown on the map. Coordinates are added when students use the address autocomplete.
                        </p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<script>
const placements = <?= json_encode(array_values($withCoords)) ?>;
const sectorColors = {
    <?php $si=0; foreach($sectorCounts as $sec=>$cnt): ?>
    <?= json_encode($sec) ?>: '<?= $sColors[$si++ % count($sColors)] ?>',
    <?php endforeach; ?>
};

const map = L.map('map', { center: [52.8, -1.8], zoom: 6 });
L.tileLayer('<?= appConfig('leaflet_tile_url','https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png') ?>', {
    attribution: '© OpenStreetMap contributors', maxZoom: 18
}).addTo(map);

function coloredIcon(color) {
    return L.divIcon({
        className: '',
        html: `<div style="width:14px;height:14px;border-radius:50%;background:${color};
                            border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,0.35);"></div>`,
        iconSize: [14, 14], iconAnchor: [7, 7]
    });
}

const bounds = [];
placements.forEach(p => {
    const lat = parseFloat(p.latitude), lng = parseFloat(p.longitude);
    if (!lat || !lng) return;
    const color = sectorColors[p.sector || 'Unknown'] || '#0c1b33';
    const marker = L.marker([lat, lng], { icon: coloredIcon(color) }).addTo(map);
    marker.bindPopup(`
        <div style="min-width:200px;font-family:'DM Sans',sans-serif;">
          <p style="font-weight:700;color:#0c1b33;margin:0 0 0.25rem;">${p.company_name}</p>
          <p style="font-size:0.8rem;color:#6b7a8d;margin:0 0 0.5rem;">${p.city || ''}</p>
          <hr style="border:none;border-top:1px solid #e5e7eb;margin:0.5rem 0;">
          <p style="font-size:0.85rem;margin:0.2rem 0;"><strong>Student:</strong> ${p.student_name}</p>
          <p style="font-size:0.85rem;margin:0.2rem 0;"><strong>Role:</strong> ${p.role_title || '—'}</p>
          <p style="font-size:0.85rem;margin:0.2rem 0;"><strong>Sector:</strong> ${p.sector || '—'}</p>
          <p style="font-size:0.85rem;margin:0.2rem 0;"><strong>Tutor:</strong> ${p.tutor_name || '—'}</p>
          <p style="font-size:0.85rem;margin:0.2rem 0;"><strong>Year:</strong> ${p.academic_year || '—'}</p>
        </div>
    `);
    bounds.push([lat, lng]);
});

if (bounds.length > 0) map.fitBounds(bounds, { padding: [40, 40], maxZoom: 11 });
</script>

<?php include '../includes/footer.php'; ?>
