<?php
// modules/land/plots.php — Fn 1: Geospatial Plot Mapping, Fn 2: Billing preview
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
$user      = requireLogin();
$pageTitle = 'Plot Map';
$db        = getDB();

$plots = $db->query("
    SELECT p.*, l.user_id AS owner_id, u.full_name AS owner_name, l.status AS lease_status, l.end_date
    FROM plots p
    LEFT JOIN leases l ON l.plot_id = p.id AND l.status = 'active'
    LEFT JOIN users u ON l.user_id = u.id
    ORDER BY p.plot_code
")->fetchAll();

// For billing calc form
$calcResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['calc_fee'])) {
    $plotId = (int)$_POST['plot_id'];
    $stmt   = $db->prepare("SELECT * FROM plots WHERE id=?");
    $stmt->execute([$plotId]);
    $plot   = $stmt->fetch();
    if ($plot) {
        $calcResult = calculateRentalFee(
            $plot['area_sqm'],
            $plot['soil_quality'],
            $user['membership']
        );
        $calcResult['plot_code']  = $plot['plot_code'];
        $calcResult['area_sqm']   = $plot['area_sqm'];
        $calcResult['soil_quality']= $plot['soil_quality'];
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <h1>🗺️ Garden Plot Map</h1>
    <p>View all plots, their status, and boundaries.</p>
</div>

<div class="page-actions">
    <?php if (hasPermission('land','create')): ?>
        <a href="plot_create.php" class="btn btn-primary">+ Add New Plot</a>
    <?php endif; ?>
    <a href="waitlist.php" class="btn btn-secondary">📋 Waitlist</a>
    <a href="soil.php" class="btn btn-secondary">🌱 Soil Tracker</a>
</div>

<!-- Legend -->
<div style="display:flex;gap:1rem;margin-bottom:1rem;flex-wrap:wrap">
    <span><span style="display:inline-block;width:14px;height:14px;background:#40916c;border-radius:3px"></span> Available</span>
    <span><span style="display:inline-block;width:14px;height:14px;background:#e76f51;border-radius:3px"></span> Occupied</span>
    <span><span style="display:inline-block;width:14px;height:14px;background:#e9c46a;border-radius:3px"></span> Maintenance</span>
    <span><span style="display:inline-block;width:14px;height:14px;background:#457b9d;border-radius:3px"></span> Reserved</span>
</div>

<!-- Map -->
<div class="card mb-2">
    <div class="card-body" style="padding:.75rem">
        <div id="plot-map"></div>
    </div>
</div>

<!-- Billing calculator (Fn 2) -->
<div class="card mb-2">
    <div class="card-header">💰 Rental Fee Calculator</div>
    <div class="card-body">
        <form method="POST" style="display:flex;gap:1rem;align-items:flex-end;flex-wrap:wrap">
            <div class="form-group" style="margin:0;flex:1;min-width:180px">
                <label>Select plot</label>
                <select name="plot_id" class="form-control" required>
                    <option value="">-- choose a plot --</option>
                    <?php foreach ($plots as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= ($p['status']==='available'?'':'disabled') ?>>
                            <?= e($p['plot_code']) ?> — <?= e($p['area_sqm']) ?>m² (<?= e($p['status']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit" name="calc_fee" value="1" class="btn btn-primary">Calculate Fee</button>
            </div>
        </form>
        <?php if ($calcResult): ?>
        <div class="alert alert-info mt-2" style="margin-bottom:0">
            <div>
                <strong>Plot <?= e($calcResult['plot_code']) ?></strong>
                — <?= e($calcResult['area_sqm']) ?>m², <?= e($calcResult['soil_quality']) ?> soil,
                <?= e($user['membership']) ?> membership<br>
                Base fee: <strong>£<?= number_format($calcResult['base_fee'],2) ?></strong>
                × soil multiplier <?= $calcResult['multiplier'] ?>
                − <?= $calcResult['discount_pct'] ?>% membership discount
                = <strong style="font-size:1.1rem">£<?= number_format($calcResult['total_fee'],2) ?>/year</strong>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Plots table -->
<div class="card">
    <div class="card-header">All Plots</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Code</th><th>Area (m²)</th><th>Sunlight</th>
                    <th>Soil</th><th>Status</th><th>Owner</th>
                    <th>Compliance</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($plots as $p): ?>
            <tr>
                <td><strong><?= e($p['plot_code']) ?></strong></td>
                <td><?= e($p['area_sqm']) ?></td>
                <td><?= e($p['sunlight_level']) ?></td>
                <td><?= e($p['soil_quality']) ?></td>
                <td>
                    <?php
                    $badges = ['available'=>'success','occupied'=>'danger','maintenance'=>'warning','reserved'=>'info'];
                    $b = $badges[$p['status']] ?? 'secondary';
                    ?>
                    <span class="badge badge-<?= $b ?>"><?= e($p['status']) ?></span>
                </td>
                <td><?= e($p['owner_name'] ?? '—') ?></td>
                <td>
                    <?php $c = $p['compliance_status'];
                    $cb = ['compliant'=>'success','warning'=>'warning','violation'=>'danger'][$c]??'secondary'; ?>
                    <span class="badge badge-<?= $cb ?>"><?= e($c) ?></span>
                </td>
                <td>
                    <a class="btn btn-sm btn-secondary" href="plot_detail.php?id=<?= $p['id'] ?>">View</a>
                    <?php if ($p['status']==='available' && hasPermission('land','create')): ?>
                        <a class="btn btn-sm btn-primary" href="lease_create.php?plot_id=<?= $p['id'] ?>">Rent</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Fn 1: Leaflet map — plot boundaries & markers
window.addEventListener('load', function() {
    var map = L.map('plot-map').setView([30.0449, 31.2358], 17);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors', maxZoom: 22
    }).addTo(map);

    var statusColors = {
        available:   '#40916c',
        occupied:    '#e76f51',
        maintenance: '#e9c46a',
        reserved:    '#457b9d'
    };

    var plots = <?= json_encode(array_map(fn($p) => [
        'id'          => $p['id'],
        'code'        => $p['plot_code'],
        'status'      => $p['status'],
        'area'        => $p['area_sqm'],
        'sunlight'    => $p['sunlight_level'],
        'soil'        => $p['soil_quality'],
        'owner'       => $p['owner_name'],
        'lat'         => $p['lat'],
        'lng'         => $p['lng'],
        'boundary'    => $p['boundary_coords'] ? json_decode($p['boundary_coords'], true) : null,
    ], $plots)) ?>;

    plots.forEach(function(p) {
        var color = statusColors[p.status] || '#888';
        var popup = '<strong>Plot ' + p.code + '</strong><br>' +
            'Status: ' + p.status + '<br>' +
            'Area: ' + p.area + ' m²<br>' +
            'Sunlight: ' + p.sunlight + '<br>' +
            'Soil: ' + p.soil + '<br>' +
            (p.owner ? 'Owner: ' + p.owner : '') +
            '<br><a href="plot_detail.php?id=' + p.id + '">View details</a>';

        if (p.boundary && p.boundary.length >= 3) {
            L.polygon(p.boundary, {color: color, fillColor: color, fillOpacity: 0.35, weight: 2})
             .bindPopup(popup).addTo(map);
        }
        if (p.lat && p.lng) {
            L.circleMarker([p.lat, p.lng], {radius: 8, color: color, fillColor: color, fillOpacity: .9, weight: 2})
             .bindPopup(popup).addTo(map);
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
