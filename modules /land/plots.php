<?php
// modules/land/plots.php — Fn 1: Grid Plot Map  |  Fn 2: Billing preview
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';

// Public access — guests may view
$user      = currentUser();
$pageTitle = 'Garden Plot Map';
$db        = getDB();

$plots = $db->query("
    SELECT p.*, l.user_id AS owner_id, u.full_name AS owner_name, l.status AS lease_status, l.end_date
    FROM plots p
    LEFT JOIN leases l ON l.plot_id = p.id AND l.status = 'active'
    LEFT JOIN users u ON l.user_id = u.id
    ORDER BY p.grid_y, p.grid_x
")->fetchAll();

// Determine grid dimensions
$maxX = 1; $maxY = 1;
foreach ($plots as $p) {
    if ($p['grid_x'] > $maxX) $maxX = $p['grid_x'];
    if ($p['grid_y'] > $maxY) $maxY = $p['grid_y'];
}
// Pad grid so empty cells show
$gridCols = max($maxX, 6);
$gridRows = max($maxY, 5);

// Build lookup: [y][x] => plot
$gridMap = [];
foreach ($plots as $p) {
    if ($p['grid_x'] && $p['grid_y']) {
        $gridMap[$p['grid_y']][$p['grid_x']] = $p;
    }
}

// Check if current user is on waitlist
$onWaitlist = false;
if ($user) {
    $wchk = $db->prepare("SELECT id FROM waitlist WHERE user_id=? AND status IN ('waiting','notified')");
    $wchk->execute([$user['id']]);
    $onWaitlist = (bool)$wchk->fetch();
}

// Billing calc (POST) — members only
$calcResult = null;
if ($user && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['calc_fee'])) {
    $plotId = (int)$_POST['plot_id'];
    $stmt   = $db->prepare("SELECT * FROM plots WHERE id=?");
    $stmt->execute([$plotId]);
    $plot   = $stmt->fetch();
    if ($plot) {
        $calcResult = calculateRentalFee(
            $plot['area_sqm'],
            $plot['soil_quality'],
            $user['membership'] ?? $user['membership_status'] ?? 'standard'
        );
        $calcResult['plot_code']   = $plot['plot_code'];
        $calcResult['area_sqm']    = $plot['area_sqm'];
        $calcResult['soil_quality']= $plot['soil_quality'];
        $calcResult['plot_id']     = $plot['id'];
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- ══════════════════════════════════════════════════════
     PAGE HEADER
══════════════════════════════════════════════════════ -->
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 fw-bold text-success mb-0">🌿 Garden Plot Map</h1>
        <p class="text-muted small mb-0">Click any plot cell for details, pricing, and booking options.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($user && hasPermission('land','create')): ?>
            <a href="plot_create.php" class="btn btn-success btn-sm">
                <i class="bi bi-plus-circle"></i> Add Plot
            </a>
        <?php endif; ?>
        <?php if ($user): ?>
            <a href="waitlist.php" class="btn btn-outline-secondary btn-sm">📋 Waitlist</a>
            <a href="soil.php"     class="btn btn-outline-secondary btn-sm">🌱 Soil Tracker</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$user): ?>
<!-- Guest banner -->
<div class="alert alert-info d-flex align-items-center gap-2 mb-3" role="alert">
    <span>👋</span>
    <span><strong>Viewing as guest.</strong> <a href="<?= APP_URL ?>/auth/login.php" class="alert-link">Log in</a> or <a href="<?= APP_URL ?>/auth/register.php" class="alert-link">register</a> to rent a plot or join the waitlist.</span>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════
     LEGEND
══════════════════════════════════════════════════════ -->
<div class="d-flex gap-3 flex-wrap mb-3">
    <span class="d-flex align-items-center gap-1"><span class="plot-legend-dot bg-available"></span> Available</span>
    <span class="d-flex align-items-center gap-1"><span class="plot-legend-dot bg-occupied"></span> Occupied</span>
    <span class="d-flex align-items-center gap-1"><span class="plot-legend-dot bg-maintenance"></span> Maintenance</span>
    <span class="d-flex align-items-center gap-1"><span class="plot-legend-dot bg-reserved"></span> Reserved</span>
    <span class="d-flex align-items-center gap-1"><span class="plot-legend-dot bg-empty"></span> Empty</span>
</div>

<!-- ══════════════════════════════════════════════════════
     GRID MAP
══════════════════════════════════════════════════════ -->
<div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold d-flex align-items-center gap-2">
        🗺️ Plot Grid
        <span class="badge bg-secondary ms-auto"><?= $gridCols ?>×<?= $gridRows ?> grid</span>
    </div>
    <div class="card-body p-3">
        <!-- Axis labels: column numbers -->
        <div class="plot-grid-wrap" style="--grid-cols:<?= $gridCols ?>">
            <div class="plot-axis-corner"></div>
            <?php for ($x = 1; $x <= $gridCols; $x++): ?>
                <div class="plot-axis-label plot-axis-col text-muted small"><?= $x ?></div>
            <?php endfor; ?>

            <?php for ($y = 1; $y <= $gridRows; $y++): ?>
                <div class="plot-axis-label plot-axis-row text-muted small"><?= $y ?></div>
                <?php for ($x = 1; $x <= $gridCols; $x++): ?>
                    <?php if (isset($gridMap[$y][$x])): $p = $gridMap[$y][$x]; ?>
                        <div class="plot-cell plot-cell--<?= $p['status'] ?>"
                             role="button"
                             tabindex="0"
                             title="Plot <?= e($p['plot_code']) ?> — <?= e($p['status']) ?>"
                             data-bs-toggle="modal"
                             data-bs-target="#plotModal"
                             data-id="<?= $p['id'] ?>"
                             data-code="<?= e($p['plot_code']) ?>"
                             data-status="<?= e($p['status']) ?>"
                             data-area="<?= $p['area_sqm'] ?>"
                             data-sunlight="<?= e($p['sunlight_level']) ?>"
                             data-soil="<?= e($p['soil_quality']) ?>"
                             data-owner="<?= e($p['owner_name'] ?? '') ?>"
                             data-compliance="<?= e($p['compliance_status']) ?>"
                             data-x="<?= $p['grid_x'] ?>"
                             data-y="<?= $p['grid_y'] ?>">
                            <span class="plot-code"><?= e($p['plot_code']) ?></span>
                            <span class="plot-area-label"><?= $p['area_sqm'] ?>m²</span>
                        </div>
                    <?php else: ?>
                        <div class="plot-cell plot-cell--empty" title="Empty (<?= $x ?>,<?= $y ?>)"></div>
                    <?php endif; ?>
                <?php endfor; ?>
            <?php endfor; ?>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════
     BILLING CALCULATOR (members only)
══════════════════════════════════════════════════════ -->
<?php if ($user): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">💰 Rental Fee Calculator</div>
    <div class="card-body">
        <form method="POST" class="row g-2 align-items-end">
            <div class="col-12 col-md-8">
                <label class="form-label small fw-semibold">Select an available plot</label>
                <select name="plot_id" class="form-select form-select-sm" required>
                    <option value="">— choose a plot —</option>
                    <?php foreach ($plots as $p): ?>
                        <option value="<?= $p['id'] ?>"
                            <?= ($calcResult && $calcResult['plot_id'] == $p['id']) ? 'selected' : '' ?>
                            <?= $p['status'] !== 'available' ? 'disabled' : '' ?>>
                            <?= e($p['plot_code']) ?> — <?= e($p['area_sqm']) ?>m²
                            (<?= e($p['status']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" name="calc_fee" value="1" class="btn btn-success btn-sm">
                    Calculate Fee
                </button>
            </div>
        </form>

        <?php if ($calcResult): ?>
        <div class="alert alert-info mt-3 mb-0">
            <strong>Plot <?= e($calcResult['plot_code']) ?></strong>
            — <?= e($calcResult['area_sqm']) ?>m², <?= e($calcResult['soil_quality']) ?> soil<br>
            Base fee: <strong>£<?= number_format($calcResult['base_fee'],2) ?></strong>
            × soil multiplier <?= $calcResult['multiplier'] ?>
            − <?= $calcResult['discount_pct'] ?>% membership discount
            = <strong class="fs-6">£<?= number_format($calcResult['total_fee'],2) ?>/year</strong>
            <div class="mt-2">
                <a href="lease_create.php?plot_id=<?= $calcResult['plot_id'] ?>"
                   class="btn btn-success btn-sm">🌱 Rent This Plot</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════
     PLOTS TABLE
══════════════════════════════════════════════════════ -->
<div class="card shadow-sm">
    <div class="card-header fw-semibold">All Plots</div>
    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Code</th>
                    <th>Grid</th>
                    <th>Area (m²)</th>
                    <th>Sunlight</th>
                    <th>Soil</th>
                    <th>Status</th>
                    <th>Owner</th>
                    <th>Compliance</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($plots as $p): ?>
            <tr>
                <td><strong><?= e($p['plot_code']) ?></strong></td>
                <td class="text-muted small">(<?= (int)$p['grid_x'] ?>,<?= (int)$p['grid_y'] ?>)</td>
                <td><?= e($p['area_sqm']) ?></td>
                <td><?= e($p['sunlight_level']) ?></td>
                <td><?= e($p['soil_quality']) ?></td>
                <td>
                    <?php
                    $bsCls = ['available'=>'success','occupied'=>'danger','maintenance'=>'warning','reserved'=>'info'][$p['status']] ?? 'secondary';
                    ?>
                    <span class="badge text-bg-<?= $bsCls ?>"><?= e($p['status']) ?></span>
                </td>
                <td><?= e($p['owner_name'] ?? '—') ?></td>
                <td>
                    <?php $cc = ['compliant'=>'success','warning'=>'warning','violation'=>'danger'][$p['compliance_status']] ?? 'secondary'; ?>
                    <span class="badge text-bg-<?= $cc ?>"><?= e($p['compliance_status']) ?></span>
                </td>
                <td>
                    <a class="btn btn-outline-secondary btn-sm" href="plot_detail.php?id=<?= $p['id'] ?>">View</a>
                    <?php if ($user && $p['status']==='available' && hasPermission('land','create')): ?>
                        <a class="btn btn-success btn-sm" href="lease_create.php?plot_id=<?= $p['id'] ?>">Rent</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════
     PLOT DETAIL MODAL
══════════════════════════════════════════════════════ -->
<div class="modal fade" id="plotModal" tabindex="-1" aria-labelledby="plotModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header" id="plotModalHeader">
        <h5 class="modal-title fw-bold" id="plotModalLabel">Plot Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="plotModalBody">
        <!-- Filled by JS -->
      </div>
      <div class="modal-footer" id="plotModalFooter">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
// ─── Plot Modal ─────────────────────────────────────────────
const RATE = <?= BILLING_RATE_PER_SQM ?>;
const MULT = <?= json_encode(SOIL_MULTIPLIER) ?>;
const DISC = <?= json_encode(MEMBERSHIP_DISCOUNT) ?>;
const isLoggedIn  = <?= $user ? 'true' : 'false' ?>;
const onWaitlist  = <?= $onWaitlist ? 'true' : 'false' ?>;
const memberMembership = <?= $user ? json_encode($user['membership'] ?? $user['membership_status'] ?? 'standard') : "null" ?>;

const statusLabel = {
    available:   '<span class="badge text-bg-success">Available</span>',
    occupied:    '<span class="badge text-bg-danger">Occupied</span>',
    maintenance: '<span class="badge text-bg-warning">Maintenance</span>',
    reserved:    '<span class="badge text-bg-info">Reserved</span>'
};

const modalEl = document.getElementById('plotModal');
modalEl.addEventListener('show.bs.modal', function(e) {
    const btn    = e.relatedTarget;
    const status = btn.dataset.status;
    const area   = parseFloat(btn.dataset.area) || 0;
    const soil   = btn.dataset.soil;
    const code   = btn.dataset.code;
    const id     = btn.dataset.id;

    // Fee estimate
    let feeHtml = '';
    if (isLoggedIn && memberMembership && area) {
        const base  = area * RATE;
        const mult  = MULT[soil] || 1;
        const disc  = DISC[memberMembership] || 0;
        const total = (base * mult * (1 - disc)).toFixed(2);
        feeHtml = `
            <div class="alert alert-success py-2 mb-3">
                <strong>Estimated rental fee:</strong><br>
                £${(base * mult).toFixed(2)} × (1 − ${(disc*100).toFixed(0)}% discount)
                = <strong>£${total}/year</strong>
            </div>`;
    } else if (!isLoggedIn && area) {
        feeHtml = `<div class="alert alert-secondary py-2 mb-3 small">
            <a href="<?= APP_URL ?>/auth/login.php">Log in</a> to see your personalised rental fee.
        </div>`;
    }

    // Action buttons
    let actions = '';
    if (isLoggedIn) {
        if (status === 'available') {
            actions = `<a href="lease_create.php?plot_id=${id}" class="btn btn-success btn-sm">🌱 Rent This Plot</a>`;
        }
        if (status !== 'available' && !onWaitlist) {
            actions = `<form method="POST" action="waitlist.php" class="d-inline">
                <input type="hidden" name="join" value="1">
                <button type="submit" class="btn btn-outline-warning btn-sm">📋 Join Waitlist</button>
            </form>`;
        } else if (onWaitlist && status !== 'available') {
            actions = `<span class="badge text-bg-warning">✓ You are on the waitlist</span>`;
        }
    } else {
        actions = `<a href="<?= APP_URL ?>/auth/login.php" class="btn btn-outline-success btn-sm">Log in to Rent / Join Waitlist</a>`;
    }

    // Header colour
    const hdrColors = { available:'#d1f0dc', occupied:'#fde8e8', maintenance:'#fff9e6', reserved:'#dbeeff' };
    document.getElementById('plotModalHeader').style.background = hdrColors[status] || '#f8f9fa';
    document.getElementById('plotModalLabel').textContent = `Plot ${code}`;

    document.getElementById('plotModalBody').innerHTML = `
        <div class="d-flex align-items-center gap-2 mb-3">
            ${statusLabel[status] || ''}
            <span class="text-muted small">Grid (${btn.dataset.x}, ${btn.dataset.y})</span>
        </div>
        <table class="table table-sm table-bordered mb-3">
            <tr><th class="table-light" style="width:40%">Area</th><td>${btn.dataset.area} m²</td></tr>
            <tr><th class="table-light">Sunlight</th><td>${btn.dataset.sunlight}</td></tr>
            <tr><th class="table-light">Soil quality</th><td>${btn.dataset.soil}</td></tr>
            <tr><th class="table-light">Compliance</th><td>${btn.dataset.compliance}</td></tr>
            ${btn.dataset.owner ? `<tr><th class="table-light">Owner</th><td>${btn.dataset.owner}</td></tr>` : ''}
        </table>
        ${feeHtml}`;

    const footer = document.getElementById('plotModalFooter');
    footer.innerHTML = `
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
        <a href="plot_detail.php?id=${id}" class="btn btn-outline-secondary btn-sm">View Details</a>
        ${actions}`;
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
