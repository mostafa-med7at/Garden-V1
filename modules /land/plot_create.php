<?php
// modules/land/plot_create.php — Fn 1: Grid-based plot creation
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
$user      = requireLogin();
$pageTitle = 'Add New Plot';
$db        = getDB();
if (!hasPermission('land','create')) {
    setFlash('danger','Permission denied.');
    redirect('modules/land/plots.php');
}

// Fetch existing plots for the grid picker preview
$existingPlots = $db->query("SELECT plot_code, status, grid_x, grid_y FROM plots WHERE grid_x IS NOT NULL AND grid_y IS NOT NULL")->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code     = strtoupper(trim($_POST['plot_code']    ?? ''));
    $sunlight = $_POST['sunlight_level'] ?? 'full';
    $soil     = $_POST['soil_quality']   ?? 'standard';
    $area     = (float)($_POST['area_sqm']  ?? 0);
    $gridX    = (int)($_POST['grid_x']   ?? 0);
    $gridY    = (int)($_POST['grid_y']   ?? 0);

    // Validate
    if (!$code)        $errors[] = 'Plot code is required.';
    if ($gridX < 1)   $errors[] = 'Grid column (X) must be ≥ 1.';
    if ($gridY < 1)   $errors[] = 'Grid row (Y) must be ≥ 1.';
    if ($area  <= 0)  $errors[] = 'Area must be greater than 0.';

    if (!$errors) {
        // Duplicate code check
        $chk = $db->prepare("SELECT id FROM plots WHERE plot_code = ?");
        $chk->execute([$code]);
        if ($chk->fetch()) $errors[] = "Plot code '$code' already exists.";
    }
    if (!$errors) {
        // Duplicate grid cell check
        $gchk = $db->prepare("SELECT id FROM plots WHERE grid_x = ? AND grid_y = ?");
        $gchk->execute([$gridX, $gridY]);
        if ($gchk->fetch()) $errors[] = "Grid cell ($gridX, $gridY) is already taken by another plot.";
    }

    if (!$errors) {
        $db->prepare("INSERT INTO plots (plot_code, area_sqm, sunlight_level, soil_quality, grid_x, grid_y) VALUES (?,?,?,?,?,?)")
           ->execute([$code, $area, $sunlight, $soil, $gridX, $gridY]);
        $plotId = (int)$db->lastInsertId();
        auditLog('plot_created','land','plots',$plotId,"Code: $code, {$area}m², grid($gridX,$gridY)");
        setFlash('success',"Plot $code added at grid position ($gridX, $gridY). Area: {$area}m²");
        header('Location: plots.php'); exit;
    }
}

// Grid picker dimensions
$pickerCols = 10;
$pickerRows = 8;

// Build taken-cell lookup
$takenCells = [];
foreach ($existingPlots as $ep) {
    $takenCells[$ep['grid_y']][$ep['grid_x']] = $ep;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h3 fw-bold text-success mb-0">➕ Add New Plot</h1>
        <p class="text-muted small mb-0">Pick a grid position, then fill in the plot details.</p>
    </div>
    <a href="plots.php" class="btn btn-outline-secondary btn-sm">← Back to Map</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="row g-4">

<!-- ── LEFT: Grid Picker ─────────────────────────────────── -->
<div class="col-12 col-lg-7">
    <div class="card shadow-sm h-100">
        <div class="card-header fw-semibold d-flex align-items-center gap-2">
            🗺️ Choose Grid Position
            <span class="badge bg-secondary ms-auto"><?= $pickerCols ?>×<?= $pickerRows ?> grid</span>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-2">
                <span class="plot-legend-dot bg-available d-inline-block me-1"></span>Available &nbsp;
                <span class="plot-legend-dot bg-occupied  d-inline-block me-1"></span>Taken &nbsp;
                <span style="display:inline-block;width:13px;height:13px;border-radius:3px;background:var(--green-mid)" class="me-1"></span>Your selection
            </p>

            <!-- Grid picker -->
            <div class="grid-picker-wrap" style="--picker-cols:<?= $pickerCols ?>">
                <!-- Corner -->
                <div class="grid-picker-label"></div>
                <!-- Col headers -->
                <?php for ($x = 1; $x <= $pickerCols; $x++): ?>
                    <div class="grid-picker-label"><?= $x ?></div>
                <?php endfor; ?>

                <?php for ($y = 1; $y <= $pickerRows; $y++): ?>
                    <div class="grid-picker-label"><?= $y ?></div>
                    <?php for ($x = 1; $x <= $pickerCols; $x++):
                        $taken = isset($takenCells[$y][$x]) ? $takenCells[$y][$x] : null;
                        $cls   = $taken ? 'taken' : '';
                        $label = $taken ? e($taken['plot_code']) : '';
                    ?>
                        <div class="grid-picker-cell <?= $cls ?>"
                             data-x="<?= $x ?>" data-y="<?= $y ?>"
                             <?= $taken ? 'title="' . e($taken['plot_code']) . ' (' . e($taken['status']) . ')"' : "title=\"Select ($x,$y)\"" ?>
                             <?= $taken ? '' : 'onclick="selectCell(this)"' ?>>
                            <?= $label ?>
                        </div>
                    <?php endfor; ?>
                <?php endfor; ?>
            </div>

            <p class="text-muted small mt-2 mb-0" id="picker-hint">Click an empty cell to place the new plot.</p>
        </div>
    </div>
</div>

<!-- ── RIGHT: Plot Details Form ──────────────────────────── -->
<div class="col-12 col-lg-5">
    <div class="card shadow-sm">
        <div class="card-header fw-semibold">📋 Plot Details</div>
        <div class="card-body">
            <form method="POST" id="plot-form" novalidate>
                <!-- Hidden grid coordinates -->
                <input type="hidden" name="grid_x" id="input-grid-x" value="<?= (int)($_POST['grid_x'] ?? 0) ?>">
                <input type="hidden" name="grid_y" id="input-grid-y" value="<?= (int)($_POST['grid_y'] ?? 0) ?>">

                <!-- Grid position display -->
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Selected Position</label>
                    <div id="grid-pos-display" class="form-control-plaintext border rounded px-3 py-2 bg-light text-muted small">
                        No cell selected — click the grid on the left
                    </div>
                </div>

                <!-- Plot code -->
                <div class="mb-3">
                    <label for="plot_code" class="form-label fw-semibold small">Plot Code <span class="text-danger">*</span></label>
                    <input type="text" id="plot_code" name="plot_code" class="form-control form-control-sm"
                           required placeholder="e.g. C-01"
                           value="<?= e($_POST['plot_code'] ?? '') ?>">
                    <div class="form-text">Format: Letter-Number (A-01, B-03 …)</div>
                </div>

                <!-- Area -->
                <div class="mb-3">
                    <label for="area_sqm" class="form-label fw-semibold small">Area (m²) <span class="text-danger">*</span></label>
                    <input type="number" id="area_sqm" name="area_sqm" class="form-control form-control-sm"
                           min="1" step="0.5" required placeholder="e.g. 20"
                           value="<?= e($_POST['area_sqm'] ?? '') ?>">
                </div>

                <!-- Sunlight -->
                <div class="mb-3">
                    <label for="sunlight_level" class="form-label fw-semibold small">Sunlight Level</label>
                    <select id="sunlight_level" name="sunlight_level" class="form-select form-select-sm">
                        <option value="full"    <?= (($_POST['sunlight_level'] ?? 'full') === 'full')    ? 'selected' : '' ?>>☀️ Full sun (6+ hours)</option>
                        <option value="partial" <?= (($_POST['sunlight_level'] ?? '') === 'partial')     ? 'selected' : '' ?>>⛅ Partial shade (3–6 hours)</option>
                        <option value="shade"   <?= (($_POST['sunlight_level'] ?? '') === 'shade')       ? 'selected' : '' ?>>🌥️ Shade (&lt;3 hours)</option>
                    </select>
                </div>

                <!-- Soil quality -->
                <div class="mb-3">
                    <label for="soil_quality" class="form-label fw-semibold small">Soil Quality</label>
                    <select id="soil_quality" name="soil_quality" class="form-select form-select-sm">
                        <option value="premium"  <?= (($_POST['soil_quality'] ?? '') === 'premium')  ? 'selected' : '' ?>>🌟 Premium (raised beds)</option>
                        <option value="standard" <?= (($_POST['soil_quality'] ?? 'standard') === 'standard') ? 'selected' : '' ?>>✅ Standard</option>
                        <option value="poor"     <?= (($_POST['soil_quality'] ?? '') === 'poor')     ? 'selected' : '' ?>>⚠️ Poor (needs amendment)</option>
                    </select>
                </div>

                <!-- Fee preview -->
                <div id="fee-preview" class="alert alert-success py-2 small d-none mb-3"></div>

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-success" id="submit-btn" disabled>
                        🌿 Create Plot
                    </button>
                    <a href="plots.php" class="btn btn-outline-secondary btn-sm">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

</div><!-- /row -->

<script>
const RATE = <?= BILLING_RATE_PER_SQM ?>;
const MULT = <?= json_encode(SOIL_MULTIPLIER) ?>;

let selectedX = <?= (int)($_POST['grid_x'] ?? 0) ?>;
let selectedY = <?= (int)($_POST['grid_y'] ?? 0) ?>;

// Restore selection from POST (on validation failure)
if (selectedX && selectedY) {
    const cells = document.querySelectorAll('.grid-picker-cell');
    cells.forEach(c => {
        if (parseInt(c.dataset.x) === selectedX && parseInt(c.dataset.y) === selectedY) {
            c.classList.add('selected');
        }
    });
    refreshDisplay();
}

function selectCell(el) {
    document.querySelectorAll('.grid-picker-cell.selected').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    selectedX = parseInt(el.dataset.x);
    selectedY = parseInt(el.dataset.y);
    document.getElementById('input-grid-x').value = selectedX;
    document.getElementById('input-grid-y').value = selectedY;
    refreshDisplay();
}

function refreshDisplay() {
    const posEl  = document.getElementById('grid-pos-display');
    const btnEl  = document.getElementById('submit-btn');
    if (selectedX && selectedY) {
        posEl.textContent = `Column ${selectedX}, Row ${selectedY}  →  (${selectedX}, ${selectedY})`;
        posEl.classList.remove('text-muted');
        posEl.classList.add('text-success', 'fw-semibold');
        btnEl.disabled = false;
    } else {
        posEl.textContent = 'No cell selected — click the grid on the left';
        posEl.classList.add('text-muted');
        posEl.classList.remove('text-success', 'fw-semibold');
        btnEl.disabled = true;
    }
    updateFeePreview();
}

function updateFeePreview() {
    const area = parseFloat(document.getElementById('area_sqm').value) || 0;
    const soil = document.getElementById('soil_quality').value;
    const feeEl = document.getElementById('fee-preview');
    if (area > 0) {
        const total = (area * RATE * (MULT[soil] || 1)).toFixed(2);
        feeEl.innerHTML = `💰 Base annual rental fee: <strong>£${total}</strong>/year`;
        feeEl.classList.remove('d-none');
    } else {
        feeEl.classList.add('d-none');
    }
}

document.getElementById('area_sqm').addEventListener('input', updateFeePreview);
document.getElementById('soil_quality').addEventListener('change', updateFeePreview);
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
