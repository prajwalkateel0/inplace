<?php
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../config/app_config.php';
loadAppConfig($pdo);

requireAuth('tutor');

$pageTitle    = 'Placement Map';
$pageSubtitle = 'View all placement locations and plan visits';
$activePage   = 'map';
$userId       = authId();

// Sidebar badges
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM placements WHERE status IN ('submitted','awaiting_tutor')");
$pendingRequests = (int)$stmt->fetchColumn();

// No fixed start places — user types any UK address/postcode now

/**
 * Fetch placements with company lat/lng (stored in companies table)
 */
$stmt = $pdo->query("
  SELECT
    p.id,
    p.role_title,
    u.full_name       AS student_name,
    u.avatar_initials AS student_initials,
    c.name            AS company_name,
    c.city            AS company_city,
    c.address         AS company_address,
    c.latitude        AS latitude,
    c.longitude       AS longitude
  FROM placements p
  JOIN users u     ON p.student_id = u.id
  JOIN companies c ON p.company_id = c.id
  WHERE p.status IN ('approved','active')
  ORDER BY c.city ASC, c.name ASC
");
$placements = $stmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * Group by "location key" (use address if you want the card title to be the full address)
 * Your screenshot shows address like: "Moor Lane, Derby, DE24 8BJ, United Kingdom"
 */
$locationGroups = [];
foreach ($placements as $p) {
    $key = trim($p['company_address'] ?: ($p['company_city'] ?: 'Unknown'));
    if (!isset($locationGroups[$key])) $locationGroups[$key] = [];
    $locationGroups[$key][] = $p;
}

$totalPlacements = count($placements);
$totalRegions    = count($locationGroups);

/**
 * Routing URL params
 * map-view.php?route_loc=...&start_lat=...&start_lng=...&start_label=...&dest=0
 */
$routeLoc   = isset($_GET['route_loc'])   ? trim($_GET['route_loc'])   : '';
$startLat   = isset($_GET['start_lat'])   ? (float)$_GET['start_lat'] : null;
$startLng   = isset($_GET['start_lng'])   ? (float)$_GET['start_lng'] : null;
$startLabel = isset($_GET['start_label']) ? trim($_GET['start_label']) : '';
$destIdx    = isset($_GET['dest'])        ? (int)$_GET['dest']         : 0;
?>
<?php include '../includes/header.php'; ?>

<!-- Leaflet CSS/JS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
/* nice small pin badge like your previous design */
.city-pin {
  width: 34px; height: 34px;
  border-radius: 12px;
  display:flex; align-items:center; justify-content:center;
  color:#fff; font-weight:800;
  box-shadow: 0 10px 25px rgba(0,0,0,0.18);
  border: 2px solid rgba(255,255,255,0.9);
  font-size: 12px;
}
.pin-navy { background: var(--navy); }
.pin-gold { background: var(--gold); color:#0b1b34; }
.pin-red  { background: var(--danger); }
.leaflet-container { border-radius: var(--radius); }
</style>

<div class="main">
  <?php include '../includes/topbar.php'; ?>

  <div class="page-content">

    <!-- STATS -->
    <div style="display:flex;gap:1.25rem;margin-bottom:1.5rem;flex-wrap:wrap;">

      <div class="panel" style="flex:1;margin-bottom:0;padding:1.25rem 1.75rem;">
        <div style="display:flex;align-items:center;gap:1rem;">
          <span style="font-size:1.75rem;">🔵</span>
          <div>
            <p style="font-size:0.75rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);">
              Total Placements
            </p>
            <h3 style="font-family:'Playfair Display',serif;font-size:1.625rem;color:var(--navy);">
              <?= $totalPlacements ?>
            </h3>
          </div>
        </div>
      </div>

      <div class="panel" style="flex:1;margin-bottom:0;padding:1.25rem 1.75rem;">
        <div style="display:flex;align-items:center;gap:1rem;">
          <span style="font-size:1.75rem;">📍</span>
          <div>
            <p style="font-size:0.75rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);">
              Regions Covered
            </p>
            <h3 style="font-family:'Playfair Display',serif;font-size:1.625rem;color:var(--navy);">
              <?= $totalRegions ?>
            </h3>
          </div>
        </div>
      </div>

      <div class="panel" style="flex:2;margin-bottom:0;padding:1.25rem 1.75rem;">
        <p style="font-size:0.75rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);margin-bottom:0.35rem;">
          🧭 Route Planner (OSRM)
        </p>
        <p style="font-size:0.875rem;color:var(--muted);margin:0;">
          Click a pin or “Focus on Map” → pick a start place → Generate Route.
          (Needs company latitude/longitude in DB.)
        </p>
      </div>

    </div>

    <!-- MAP -->
    <div class="panel" style="padding:0;overflow:hidden;">
      <div id="map" style="height:560px;width:100%;"></div>
    </div>

    <!-- LIST -->
    <div style="margin-top:2rem;">
      <h3 style="font-family:'Playfair Display',serif;font-size:1.25rem;color:var(--navy);margin-bottom:1.25rem;">
        Placements by Location
      </h3>

      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:1.25rem;">
        <?php foreach ($locationGroups as $loc => $group): ?>
          <div class="panel" style="margin-bottom:0;">
            <div class="panel-header" style="background:var(--cream);">
              <h3 style="font-size:1rem;">
                📍 <?= htmlspecialchars($loc) ?>
                <span style="font-size:0.875rem;font-weight:400;color:var(--muted);margin-left:0.5rem;">
                  (<?= count($group) ?>)
                </span>
              </h3>
            </div>

            <div style="padding:1rem;">
              <?php foreach ($group as $p): ?>
                <div style="padding:0.75rem 0;border-bottom:1px solid var(--border);">
                  <div style="display:flex;align-items:center;gap:0.75rem;">
                    <div class="avatar" style="width:32px;height:32px;font-size:0.7rem;">
                      <?= htmlspecialchars($p['student_initials'] ?? '??') ?>
                    </div>

                    <div style="flex:1;min-width:0;">
                      <p style="font-weight:500;font-size:0.875rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= htmlspecialchars($p['student_name']) ?>
                      </p>
                      <p style="font-size:0.75rem;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= htmlspecialchars($p['company_name']) ?>
                      </p>
                    </div>

                    <button class="btn btn-ghost"
                      style="padding:0.35rem 0.65rem;font-size:0.75rem;"
                      onclick="window.location='/inplace/tutor/schedule-visit.php?placement_id=<?= (int)$p['id'] ?>'">
                      🗓
                    </button>
                  </div>
                </div>
              <?php endforeach; ?>

              <button class="btn btn-primary btn-sm"
                style="width:100%;margin-top:1rem;justify-content:center;"
                onclick="focusAndOpen('<?= htmlspecialchars($loc, ENT_QUOTES) ?>')">
                Focus on Map
              </button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>
</div>

<!-- MODAL (same clean style you liked) -->
<div id="locationModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);
     z-index:1000;align-items:center;justify-content:center;">
  <div style="background:var(--white);border-radius:var(--radius);padding:2.5rem;width:100%;max-width:720px;
              box-shadow:0 20px 60px rgba(0,0,0,0.2);max-height:80vh;overflow-y:auto;">
    <h3 style="font-family:'Playfair Display',serif;font-size:1.375rem;color:var(--navy);margin-bottom:1.25rem;"
        id="locationTitle"></h3>

    <div id="locationStudents"></div>

    <div style="margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid var(--border);">

      <!-- Start place: free-text with postcode/address search -->
      <div style="margin-bottom:1rem;">
        <label style="display:block;font-size:0.82rem;font-weight:700;margin-bottom:0.5rem;">
          📍 Start place — type any UK address or postcode
        </label>
        <div style="display:flex;gap:0.5rem;">
          <input type="text" id="startAddressInput"
                 placeholder="e.g. LE1 7RH, Derby Station, University of Leicester..."
                 style="flex:1;padding:0.85rem 1rem;border:2px solid var(--border);
                        border-radius:12px;background:var(--cream);font-family:inherit;font-size:0.9rem;">
          <button class="btn btn-ghost" id="geocodeBtn" onclick="geocodeStart()" style="white-space:nowrap;">
            🔍 Find
          </button>
        </div>
        <!-- Suggestions dropdown -->
        <div id="geocodeSuggestions"
             style="display:none;position:relative;z-index:50;background:white;
                    border:1.5px solid var(--border);border-radius:10px;margin-top:4px;
                    box-shadow:0 8px 24px rgba(0,0,0,0.1);max-height:200px;overflow-y:auto;">
        </div>
        <div id="geocodeStatus" style="font-size:0.8rem;margin-top:0.4rem;color:var(--muted);"></div>
      </div>

      <!-- Destination -->
      <div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:flex-end;">
        <div style="flex:1;min-width:240px;">
          <label style="display:block;font-size:0.82rem;font-weight:700;margin-bottom:0.5rem;">Destination company</label>
          <select id="routeDestSelect"
                  style="width:100%;padding:0.85rem 1rem;border:2px solid var(--border);
                         border-radius:12px;background:var(--cream);font-family:inherit;">
          </select>
        </div>

        <button class="btn btn-ghost" onclick="closeLocationModal()" style="height:44px;">Close</button>

        <button class="btn btn-primary" id="routeNavBtn" style="height:44px;">
          Generate Route
        </button>
      </div>

      <p id="routeHint" style="margin:0.8rem 0 0;color:var(--muted);font-size:0.85rem;"></p>
    </div>
  </div>
</div>

<script>
const locationData    = <?= json_encode($locationGroups, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const routeLocFromUrl = <?= json_encode($routeLoc,   JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const startLatFromUrl = <?= json_encode($startLat,   JSON_HEX_TAG) ?>;
const startLngFromUrl = <?= json_encode($startLng,   JSON_HEX_TAG) ?>;
const startLblFromUrl = <?= json_encode($startLabel, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const destIdxFromUrl  = <?= (int)$destIdx ?>;

function toNum(v){
  if (v === null || v === undefined || v === '') return null;
  const n = Number(v);
  return Number.isFinite(n) ? n : null;
}
function esc(s){
  return String(s ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'","&#039;");
}

// Leaflet map
const map = L.map('map').setView([54.5, -3.0], 6);
L.tileLayer('<?= appConfig('leaflet_tile_url', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png') ?>', {
  maxZoom: 19,
  attribution: '&copy; OpenStreetMap'
}).addTo(map);

let routeLayer = null;
function clearRoute(){
  if(routeLayer){
    map.removeLayer(routeLayer);
    routeLayer = null;
  }
}
function drawRoute(geo){
  clearRoute();
  routeLayer = L.geoJSON(geo, { style:{ weight:5 } }).addTo(map);
  map.fitBounds(routeLayer.getBounds().pad(0.25));
}

async function buildOsrmRoute(points){
  // OSRM expects lon,lat pairs: lon,lat;lon,lat
  const coords = points.map(p => `${p.lng},${p.lat}`).join(';');
  const url = `https://router.project-osrm.org/route/v1/driving/${coords}?overview=full&geometries=geojson&steps=true`;
  const res = await fetch(url);
  if(!res.ok) throw new Error("OSRM request failed");
  const data = await res.json();
  if(!data.routes || !data.routes[0]) throw new Error("No route found");
  const route = data.routes[0];
  return {
    geometry: route.geometry,
    distanceM: route.distance,   // metres
    durationS: route.duration    // seconds
  };
}

function formatRouteInfo(distanceM, durationS) {
  const km   = (distanceM / 1000).toFixed(1);
  const totalMins = Math.round(durationS / 60);
  const hrs  = Math.floor(totalMins / 60);
  const mins = totalMins % 60;
  const timeStr = hrs > 0
    ? `${hrs} hr ${mins > 0 ? mins + ' min' : ''}`
    : `${mins} min`;
  return { km, timeStr };
}

function showRouteInfoBanner(distanceM, durationS, fromLabel, toLabel) {
  const { km, timeStr } = formatRouteInfo(distanceM, durationS);

  // Remove existing banner
  const old = document.getElementById('routeInfoBanner');
  if (old) old.remove();

  const banner = document.createElement('div');
  banner.id = 'routeInfoBanner';
  banner.style.cssText = `
    position:absolute; bottom:20px; left:50%; transform:translateX(-50%);
    z-index:1000; background:white; border-radius:14px;
    padding:0.875rem 1.5rem; box-shadow:0 8px 30px rgba(0,0,0,0.18);
    display:flex; align-items:center; gap:1.25rem;
    border:2px solid var(--navy); min-width:300px; max-width:90%;
    font-family:inherit;
  `;
  banner.innerHTML = `
    <div style="display:flex;align-items:center;gap:0.5rem;">
      <span style="font-size:1.5rem;">🚗</span>
      <div>
        <div style="font-size:0.72rem;font-weight:600;text-transform:uppercase;
                    letter-spacing:0.05em;color:var(--muted);margin-bottom:0.15rem;">
          Driving route
        </div>
        <div style="display:flex;gap:1rem;align-items:baseline;">
          <span style="font-size:1.25rem;font-weight:800;color:var(--navy);">${km} km</span>
          <span style="font-size:1rem;font-weight:600;color:var(--text);">≈ ${timeStr}</span>
        </div>
        ${fromLabel ? `<div style="font-size:0.78rem;color:var(--muted);margin-top:0.2rem;max-width:280px;
                                   overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
          ${esc(fromLabel)} → ${esc(toLabel || '')}
        </div>` : ''}
      </div>
    </div>
    <button onclick="document.getElementById('routeInfoBanner').remove(); clearRoute();"
            style="margin-left:auto;background:none;border:none;cursor:pointer;
                   font-size:1.1rem;color:var(--muted);padding:0.25rem 0.5rem;">✕</button>
  `;

  // Attach to map container
  const mapEl = document.getElementById('map');
  mapEl.style.position = 'relative';
  mapEl.appendChild(banner);
}

// 1 marker per location key, placed at average of company coords
function makeCityMarker(locKey){
  const list = locationData[locKey] || [];
  const pts = list
    .map(p => ({lat: toNum(p.latitude), lng: toNum(p.longitude)}))
    .filter(p => p.lat !== null && p.lng !== null);

  if (pts.length === 0) return null;

  const avgLat = pts.reduce((a,b)=>a+b.lat,0) / pts.length;
  const avgLng = pts.reduce((a,b)=>a+b.lng,0) / pts.length;

  const n = list.length;
  const cls = (n >= 5) ? 'pin-red' : (n >= 2) ? 'pin-gold' : 'pin-navy';

  const icon = L.divIcon({
    className: '',
    html: `<div class="city-pin ${cls}">${n}</div>`,
    iconSize: [34,34],
    iconAnchor: [17,17]
  });

  const marker = L.marker([avgLat, avgLng], {icon}).addTo(map);
  marker.on('click', () => {
    openLocationModal(locKey);
  });

  return marker;
}

Object.keys(locationData).forEach(locKey => makeCityMarker(locKey));

// Fit all markers initially (if you have coords)
(function fitAll(){
  const allPts = [];
  Object.keys(locationData).forEach(k => {
    (locationData[k]||[]).forEach(p => {
      const lat = toNum(p.latitude), lng = toNum(p.longitude);
      if(lat!==null && lng!==null) allPts.push([lat,lng]);
    });
  });
  if(allPts.length >= 1){
    map.fitBounds(L.latLngBounds(allPts), {padding:[40,40]});
  }
})();

function focusLocationOnMap(locKey){
  const list = locationData[locKey] || [];
  const pts = list
    .map(p => [toNum(p.latitude), toNum(p.longitude)])
    .filter(x => x[0] !== null && x[1] !== null);

  if (pts.length < 1){
    alert("No latitude/longitude saved for this location yet. Fill companies.latitude/longitude.");
    return;
  }
  const bounds = L.latLngBounds(pts.map(p => L.latLng(p[0], p[1])));
  map.fitBounds(bounds.pad(0.35));
}

function focusAndOpen(locKey){
  focusLocationOnMap(locKey);
  openLocationModal(locKey);
}

// Geocoded start point (set when user selects a suggestion)
let geocodedStart = null;

// Nominatim geocode with UK bias + autocomplete suggestions
let geocodeTimer = null;
document.addEventListener('DOMContentLoaded', () => {
  const inp = document.getElementById('startAddressInput');
  if (!inp) return;

  // Restore label from URL if present
  if (startLblFromUrl) {
    inp.value = startLblFromUrl;
    geocodedStart = { lat: startLatFromUrl, lng: startLngFromUrl, label: startLblFromUrl };
    document.getElementById('geocodeStatus').textContent = '✅ ' + startLblFromUrl;
  }

  inp.addEventListener('input', () => {
    clearTimeout(geocodeTimer);
    const q = inp.value.trim();
    if (q.length < 3) { hideSuggestions(); return; }
    geocodeTimer = setTimeout(() => fetchSuggestions(q), 350);
  });

  inp.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); geocodeStart(); }
  });
});

async function fetchSuggestions(q) {
  try {
    const url = `https://nominatim.openstreetmap.org/search?format=json&countrycodes=gb&limit=6&q=${encodeURIComponent(q)}`;
    const res = await fetch(url, { headers: { 'Accept-Language': 'en' } });
    const data = await res.json();
    showSuggestions(data);
  } catch(e) { /* silent */ }
}

function showSuggestions(results) {
  const box = document.getElementById('geocodeSuggestions');
  if (!results.length) { hideSuggestions(); return; }

  box.innerHTML = results.map((r, i) =>
    `<div class="geo-item" data-idx="${i}"
          style="padding:0.7rem 1rem;cursor:pointer;border-bottom:1px solid var(--border);
                 font-size:0.875rem;line-height:1.4;"
          onmousedown="selectSuggestion(${r.lat}, ${r.lon}, ${JSON.stringify(r.display_name)})">
       📍 ${esc(r.display_name)}
     </div>`
  ).join('');

  box.style.display = 'block';

  // Hover highlight
  box.querySelectorAll('.geo-item').forEach(el => {
    el.addEventListener('mouseenter', () => el.style.background = 'var(--cream)');
    el.addEventListener('mouseleave', () => el.style.background = 'white');
  });
}

function hideSuggestions() {
  document.getElementById('geocodeSuggestions').style.display = 'none';
}

function selectSuggestion(lat, lng, label) {
  geocodedStart = { lat: parseFloat(lat), lng: parseFloat(lng), label };
  document.getElementById('startAddressInput').value = label;
  document.getElementById('geocodeStatus').textContent = '✅ Found: ' + label;
  hideSuggestions();
}

async function geocodeStart() {
  const q = document.getElementById('startAddressInput').value.trim();
  if (!q) { document.getElementById('geocodeStatus').textContent = 'Please enter an address or postcode.'; return; }

  document.getElementById('geocodeStatus').textContent = '🔍 Searching...';
  document.getElementById('geocodeBtn').disabled = true;

  try {
    const url = `https://nominatim.openstreetmap.org/search?format=json&countrycodes=gb&limit=1&q=${encodeURIComponent(q)}`;
    const res = await fetch(url, { headers: { 'Accept-Language': 'en' } });
    const data = await res.json();

    if (!data.length) {
      document.getElementById('geocodeStatus').textContent = '❌ Not found. Try a different postcode or address.';
      geocodedStart = null;
    } else {
      selectSuggestion(data[0].lat, data[0].lon, data[0].display_name);
    }
  } catch(e) {
    document.getElementById('geocodeStatus').textContent = '❌ Geocoding failed. Check your internet connection.';
  } finally {
    document.getElementById('geocodeBtn').disabled = false;
  }
}

function openLocationModal(locKey){
  const list = locationData[locKey] || [];
  document.getElementById('locationTitle').textContent = '📍 ' + locKey + ` (${list.length} placements)`;

  // Student cards
  let html = '<div style="display:flex;flex-direction:column;gap:0.875rem;">';
  list.forEach(p => {
    html += `
      <div style="display:flex;align-items:center;gap:1rem;padding:1rem;
                  background:var(--cream);border-radius:var(--radius-sm);border:1px solid var(--border);">
        <div class="avatar">${esc(p.student_initials || '??')}</div>
        <div style="flex:1;min-width:0;">
          <p style="font-weight:600;font-size:0.9375rem;">${esc(p.student_name)}</p>
          <p style="font-size:0.8125rem;color:var(--muted);">${esc(p.company_name)}</p>
          ${p.role_title ? `<p style="margin-top:0.25rem;"><span class="type-chip" style="padding:0.2rem 0.5rem;">${esc(p.role_title)}</span></p>` : ''}
        </div>
        <button class="btn btn-primary btn-sm"
                onclick="window.location='/inplace/tutor/schedule-visit.php?placement_id=${p.id}'">
          🗓 Schedule
        </button>
      </div>`;
  });
  html += '</div>';
  document.getElementById('locationStudents').innerHTML = html;

  // Reset geocode state (keep value if re-opening)
  document.getElementById('geocodeStatus').textContent = geocodedStart
    ? '✅ ' + geocodedStart.label
    : 'Enter a UK postcode or address above, then click Find.';

  // Destination dropdown
  const destSel = document.getElementById('routeDestSelect');
  const destList = list
    .map((p, idx) => ({ idx, name: p.company_name, lat: toNum(p.latitude), lng: toNum(p.longitude) }))
    .filter(x => x.lat !== null && x.lng !== null);

  destSel.innerHTML = destList.length
    ? destList.map((d,i)=>`<option value="${i}">${esc(d.name)}</option>`).join('')
    : `<option value="-1">No companies with coordinates</option>`;

  if (destList.length && destIdxFromUrl < destList.length) destSel.value = String(destIdxFromUrl);

  const hint = document.getElementById('routeHint');
  hint.textContent = destList.length
    ? 'Enter your start location above, select the destination, then click Generate Route.'
    : 'Add latitude/longitude to companies in the database to enable routing.';

  // Generate Route button — geocode inline then draw directly (no page reload needed)
  document.getElementById('routeNavBtn').onclick = async () => {
    if (!geocodedStart) {
      document.getElementById('geocodeStatus').textContent = '⚠️ Please find your start location first.';
      return;
    }
    if (!destList.length) {
      alert('No coordinates found for companies at this location.');
      return;
    }

    const d = destList[Number(destSel.value) || 0];
    const btn = document.getElementById('routeNavBtn');
    btn.disabled = true;
    btn.textContent = 'Routing...';

    try {
      const result = await buildOsrmRoute([
        { lat: geocodedStart.lat, lng: geocodedStart.lng },
        { lat: d.lat, lng: d.lng }
      ]);
      drawRoute(result.geometry);
      showRouteInfoBanner(result.distanceM, result.durationS, geocodedStart.label, d.name);
      closeLocationModal();

      // Update URL without reload (for shareable link)
      const u = new URL(window.location.href);
      u.searchParams.set('route_loc',   locKey);
      u.searchParams.set('start_lat',   geocodedStart.lat);
      u.searchParams.set('start_lng',   geocodedStart.lng);
      u.searchParams.set('start_label', geocodedStart.label);
      u.searchParams.set('dest',        String(destSel.value || 0));
      history.replaceState(null, '', u.toString());
    } catch(e) {
      alert('Could not generate route: ' + e.message);
    } finally {
      btn.disabled = false;
      btn.textContent = 'Generate Route';
    }
  };

  document.getElementById('locationModal').style.display = 'flex';
}

function closeLocationModal(){
  document.getElementById('locationModal').style.display = 'none';
  hideSuggestions();
}
document.getElementById('locationModal').addEventListener('click', function(e){
  if(e.target === this) closeLocationModal();
});
// Hide suggestions when clicking outside
document.addEventListener('click', e => {
  if (!e.target.closest('#startAddressInput') && !e.target.closest('#geocodeSuggestions')) {
    hideSuggestions();
  }
});

// AUTO route on page load if URL params present
(async function autoRoute(){
  if(!routeLocFromUrl || !startLatFromUrl || !startLngFromUrl) return;

  const list = locationData[routeLocFromUrl] || [];
  const destList = list
    .map((p, idx) => ({ idx, lat: toNum(p.latitude), lng: toNum(p.longitude) }))
    .filter(x => x.lat !== null && x.lng !== null);

  if (!destList.length) return;

  geocodedStart = { lat: startLatFromUrl, lng: startLngFromUrl, label: startLblFromUrl };
  const dest = destList[Math.min(destIdxFromUrl, destList.length - 1)];

  try {
    const result = await buildOsrmRoute([
      { lat: startLatFromUrl, lng: startLngFromUrl },
      { lat: dest.lat, lng: dest.lng }
    ]);
    drawRoute(result.geometry);
    showRouteInfoBanner(result.distanceM, result.durationS, startLblFromUrl, '');
    focusLocationOnMap(routeLocFromUrl);
  } catch(e) {
    console.warn('Auto-route failed:', e.message);
  }
})();
</script>

<?php include '../includes/footer.php'; ?>