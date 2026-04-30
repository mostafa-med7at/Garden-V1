<?php
// modules/land/plot_create.php — Fn 1: Geospatial plot creation
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
$user      = requireLogin();
$pageTitle = 'Create New Plot';
$db        = getDB();
if (!hasPermission('land','create')) { setFlash('danger','Permission denied.'); redirect('modules/land/plots.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code      = trim($_POST['plot_code']);
    $coordsRaw = trim($_POST['boundary_coords']);
    $sunlight  = $_POST['sunlight_level'];
    $soil      = $_POST['soil_quality'];
    $lat       = (float)$_POST['center_lat'];
    $lng       = (float)$_POST['center_lng'];

    // Fn 3: Calculate area from bounding box (simplified Shoelace formula for polygons)
    $coords  = json_decode($coordsRaw, true);
    $areaSqm = 0;
    if ($coords && count($coords) >= 3) {
        // Shoelace formula for geographic coords → approximate m²
        $n = count($coords);
        $a = 0;
        for ($i = 0; $i < $n; $i++) {
            $j   = ($i + 1) % $n;
            $a  += $coords[$i][0] * $coords[$j][1];
            $a  -= $coords[$j][0] * $coords[$i][1];
        }
        // Convert degree² to m² (approx at equator: 1 deg ≈ 111,320m)
        $areaSqm = round(abs($a) / 2 * 111320 * 111320, 2);
    } else {
        $areaSqm = (float)($_POST['area_sqm'] ?? 0);
    }

    // Check duplicate code
    $chk = $db->prepare("SELECT id FROM plots WHERE plot_code=?");
    $chk->execute([$code]);
    if ($chk->fetch()) { setFlash('danger', "Plot code '$code' already exists."); header('Location: plot_create.php'); exit; }

    $db->prepare("INSERT INTO plots (plot_code,boundary_coords,area_sqm,sunlight_level,soil_quality,lat,lng) VALUES (?,?,?,?,?,?,?)")
       ->execute([$code, $coordsRaw ?: null, $areaSqm, $sunlight, $soil, $lat, $lng]);
    $plotId = (int)$db->lastInsertId();
    auditLog('plot_created','land','plots',$plotId,"Code: $code, {$areaSqm}m²");
    setFlash('success',"Plot $code created successfully! Area: {$areaSqm}m²");
    header('Location: plots.php'); exit;
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <h1>🌿 Create New Plot</h1>
    <p>Draw plot boundaries on the map or enter coordinates manually.</p>
</div>

<div style="display:grid;grid-template-columns:1fr 380px;gap:1rem">

<!-- Map drawing -->
<div class="card">
    <div class="card-header">Draw Plot Boundary on Map</div>
    <div class="card-body" style="padding:.75rem">
        <div id="draw-map" style="height:450px;border-radius:8px"></div>
        <div style="margin-top:.75rem;display:flex;gap:.5rem;flex-wrap:wrap">
            <button type="button" class="btn btn-primary btn-sm" onclick="startDrawing()">✏️ Start Drawing</button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="clearDrawing()">🗑️ Clear</button>
            <span id="draw-info" class="text-sm text-muted" style="align-self:center">Click the map to place boundary points. Close the polygon to finish.</span>
        </div>
    </div>
</div>

<!-- Form -->
<div class="card" style="align-self:start">
    <div class="card-header">Plot Details</div>
    <div class="card-body">
        <form method="POST" id="plot-form">
            <input type="hidden" name="boundary_coords" id="boundary_coords">
            <input type="hidden" name="center_lat"      id="center_lat" value="30.0449">
            <input type="hidden" name="center_lng"      id="center_lng" value="31.2358">

            <div class="form-group">
                <label>Plot code <span style="color:var(--coral)">*</span></label>
                <input type="text" name="plot_code" class="form-control" required placeholder="e.g. C-01" pattern="[A-Z]-\d+" title="Format: Letter-Number e.g. A-01">
            </div>
            <div class="form-group">
                <label>Sunlight level</label>
                <select name="sunlight_level" class="form-control">
                    <option value="full">☀️ Full sun (6+ hours)</option>
                    <option value="partial">⛅ Partial shade (3–6 hours)</option>
                    <option value="shade">🌥️ Shade (&lt;3 hours)</option>
                </select>
            </div>
            <div class="form-group">
                <label>Soil quality</label>
                <select name="soil_quality" class="form-control">
                    <option value="premium">🌟 Premium (raised beds)</option>
                    <option value="standard" selected>✅ Standard</option>
                    <option value="poor">⚠️ Poor (needs amendment)</option>
                </select>
            </div>
            <div class="form-group">
                <label>Area (m²) <span class="text-muted text-sm">— auto-calculated from map</span></label>
                <input type="number" name="area_sqm" id="area_display" class="form-control" step="0.01" min="1" placeholder="Draw on map or enter manually">
            </div>

            <div id="coords-preview" style="display:none;margin-bottom:1rem">
                <p class="text-sm text-muted">Boundary points: <span id="points-count">0</span></p>
                <textarea id="coords-display" class="form-control" rows="3" style="font-size:11px;font-family:monospace" readonly></textarea>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Create Plot</button>
            <a href="plots.php" class="btn btn-secondary btn-block mt-1">Cancel</a>
        </form>
    </div>
</div>

</div>

<script>
var map, drawingMode = false, points = [], polygon = null, markers = [];

window.addEventListener('load', function() {
    map = L.map('draw-map').setView([30.0449, 31.2358], 18);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap', maxZoom: 22
    }).addTo(map);

    // Load existing plots for reference
    <?php
    $existingPlots = $db->query("SELECT plot_code, boundary_coords, lat, lng FROM plots WHERE boundary_coords IS NOT NULL")->fetchAll();
    foreach ($existingPlots as $ep): if ($ep['boundary_coords']): ?>
    L.polygon(<?= $ep['boundary_coords'] ?>, {color:'#888',fillOpacity:.2,weight:1})
     .bindTooltip('<?= e($ep['plot_code']) ?>').addTo(map);
    <?php endif; endforeach; ?>

    map.on('click', function(e) {
        if (!drawingMode) return;
        points.push([e.latlng.lat, e.latlng.lng]);
        var m = L.circleMarker([e.latlng.lat, e.latlng.lng], {radius:5,color:'#40916c',fillColor:'#40916c',fillOpacity:1}).addTo(map);
        markers.push(m);
        updatePolygon();
    });
});

function startDrawing() {
    drawingMode = true;
    map.getContainer().style.cursor = 'crosshair';
    document.getElementById('draw-info').textContent = 'Click to place points. ' + (points.length > 2 ? 'Click first point to close.' : 'Need at least 3 points.');
}

function clearDrawing() {
    drawingMode = false;
    points = [];
    markers.forEach(m => map.removeLayer(m)); markers = [];
    if (polygon) { map.removeLayer(polygon); polygon = null; }
    document.getElementById('boundary_coords').value = '';
    document.getElementById('area_display').value = '';
    document.getElementById('coords-preview').style.display = 'none';
    document.getElementById('draw-info').textContent = 'Drawing cleared.';
    map.getContainer().style.cursor = '';
}

function updatePolygon() {
    if (polygon) map.removeLayer(polygon);
    if (points.length >= 2) {
        polygon = L.polygon(points, {color:'#40916c',fillColor:'#74c69d',fillOpacity:.4,weight:2}).addTo(map);
    }
    if (points.length >= 3) {
        var coordsJson = JSON.stringify(points);
        document.getElementById('boundary_coords').value = coordsJson;
        document.getElementById('coords-display').value  = coordsJson;
        document.getElementById('coords-preview').style.display = '';
        document.getElementById('points-count').textContent = points.length;

        // Calculate centroid
        var lat = points.reduce((s,p)=>s+p[0],0)/points.length;
        var lng = points.reduce((s,p)=>s+p[1],0)/points.length;
        document.getElementById('center_lat').value = lat.toFixed(8);
        document.getElementById('center_lng').value = lng.toFixed(8);

        // Approximate area (Shoelace in degrees → m²)
        var n = points.length, a = 0;
        for (var i=0;i<n;i++){var j=(i+1)%n;a+=points[i][0]*points[j][1];a-=points[j][0]*points[i][1];}
        var areaSqm = Math.round(Math.abs(a)/2 * 111320 * 111320 * 100)/100;
        document.getElementById('area_display').value = areaSqm;
        document.getElementById('draw-info').textContent = points.length + ' points — estimated area: ' + areaSqm + ' m²';
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
