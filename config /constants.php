<?php
// config/constants.php — App-wide constants & helpers

define('APP_NAME',    'Community Garden Manager');
define('APP_ROOT',    dirname(__DIR__));
define('APP_URL',     'http://localhost/garden');  // adjust if needed
define('UPLOAD_DIR',  APP_ROOT . '/assets/uploads/');

// ── Billing multipliers (Fn 2) ──────────────────────────────
define('BILLING_RATE_PER_SQM', 8.00);   // base £/m² per year
define('SOIL_MULTIPLIER', [
    'premium'  => 1.30,
    'standard' => 1.00,
    'poor'     => 0.80,
]);
define('MEMBERSHIP_DISCOUNT', [
    'premium' => 0.10,   // 10% off
    'senior'  => 0.15,   // 15% off
    'standard'=> 0.00,
]);

// ── Penalty rates (Fn 16) ───────────────────────────────────
define('LATE_FINE_PER_DAY', 1.50);          // £1.50/day late
define('LATE_SERVICE_HOURS_PER_DAY', 0.5);  // 0.5 hrs/day late

// ── Waitlist priority weights (Fn 4) ────────────────────────
define('PRIORITY_POINTS_WEIGHT',    0.6);   // 60% community points
define('PRIORITY_RESIDENCY_WEIGHT', 0.4);   // 40% residency months

// ── Karma points per kg donated (Fn 25) ─────────────────────
define('KARMA_PER_KG', 10);

// ── Monthly service hours required (Fn 18) ──────────────────
define('MONTHLY_SERVICE_HOURS', 4.0);

// ── Allergen categories (Fn 26) ─────────────────────────────
define('ALLERGEN_CATEGORIES', [
    'Nightshades', 'Tree Nuts', 'Legumes', 'Gluten', 'Brassicas', 'Alliums'
]);

// ── Soil safety ranges (Fn 3) ───────────────────────────────
define('SOIL_PH_MIN', 5.5);
define('SOIL_PH_MAX', 7.5);

// ════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ════════════════════════════════════════════════════════════

/**
 * Start session safely and return current user data.
 */
function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Get current logged-in user from session, or null.
 */
function currentUser(): ?array {
    startSession();
    return $_SESSION['user'] ?? null;
}

/**
 * Require login — redirect to login page if not authenticated.
 */
function requireLogin(): array {
    $user = currentUser();
    if (!$user) {
        header('Location: ' . APP_URL . '/auth/login.php');
        exit;
    }
    return $user;
}

/**
 * Check if current user has permission for a module+action.
 * Uses the permissions table via session-cached perms.
 */
function hasPermission(string $module, string $action): bool {
    startSession();
    $perms = $_SESSION['permissions'] ?? [];
    return in_array($module . ':' . $action, $perms, true);
}

/**
 * Load and cache permissions for a role into the session.
 */
function loadPermissions(int $roleId): void {
    $db = getDB();
    $stmt = $db->prepare("SELECT module, action FROM permissions WHERE role_id = ?");
    $stmt->execute([$roleId]);
    $perms = [];
    while ($row = $stmt->fetch()) {
        $perms[] = $row['module'] . ':' . $row['action'];
    }
    $_SESSION['permissions'] = $perms;
}

/**
 * Write to audit_log (Fn 30).
 */
function auditLog(string $actionType, string $module, ?string $table = null,
                  ?int $targetId = null, ?string $description = null): void {
    $user = currentUser();
    $db   = getDB();
    $stmt = $db->prepare("
        INSERT INTO audit_log (user_id, action_type, module, target_table, target_id, description, ip_address)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $user['id'] ?? null,
        $actionType,
        $module,
        $table,
        $targetId,
        $description,
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
}

/**
 * Flash message helper — set then retrieve.
 */
function setFlash(string $type, string $msg): void {
    startSession();
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function getFlash(): ?array {
    startSession();
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/**
 * Redirect helper.
 */
function redirect(string $path): void {
    header('Location: ' . APP_URL . '/' . ltrim($path, '/'));
    exit;
}

/**
 * Sanitize output.
 */
function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Calculate rental fee (Fn 2).
 */
function calculateRentalFee(float $areaSqm, string $soilQuality, string $membershipStatus): array {
    $base       = $areaSqm * BILLING_RATE_PER_SQM;
    $multiplier = SOIL_MULTIPLIER[$soilQuality] ?? 1.00;
    $discount   = MEMBERSHIP_DISCOUNT[$membershipStatus] ?? 0.00;
    $afterSoil  = $base * $multiplier;
    $final      = $afterSoil * (1 - $discount);
    return [
        'base_fee'    => round($base, 2),
        'multiplier'  => $multiplier,
        'discount_pct'=> $discount * 100,
        'total_fee'   => round($final, 2),
    ];
}

/**
 * Calculate waitlist priority score (Fn 4).
 */
function calculatePriorityScore(int $communityPoints, int $residencyMonths): float {
    return round(
        ($communityPoints * PRIORITY_POINTS_WEIGHT) +
        ($residencyMonths * PRIORITY_RESIDENCY_WEIGHT),
        2
    );
}

/**
 * Calculate late return penalty (Fn 16).
 */
function calculateLatePenalty(string $dueDate, string $returnDate): array {
    $due    = new DateTime($dueDate);
    $ret    = new DateTime($returnDate);
    $days   = max(0, (int)$ret->diff($due)->days * ($ret > $due ? 1 : -1));
    return [
        'days_late'       => $days,
        'fine_amount'     => round($days * LATE_FINE_PER_DAY, 2),
        'service_hours'   => round($days * LATE_SERVICE_HOURS_PER_DAY, 2),
    ];
}

/**
 * Check soil pH and flag if out of range (Fn 3).
 */
function isSoilAtRisk(?float $ph): bool {
    if ($ph === null) return false;
    return $ph < SOIL_PH_MIN || $ph > SOIL_PH_MAX;
}

/**
 * Check if a produce type is an allergen (Fn 26).
 */
function isAllergen(?string $category): bool {
    if (!$category) return false;
    return in_array($category, ALLERGEN_CATEGORIES, true);
}

/**
 * Calculate karma points from donation quantity string, e.g. "2.5 kg" (Fn 25).
 * Falls back to flat 10 points if no numeric quantity found.
 */
function calculateKarmaPoints(string $quantity): int {
    preg_match('/[\d.]+/', $quantity, $m);
    $kg = isset($m[0]) ? (float)$m[0] : 1.0;
    return max(10, (int)round($kg * KARMA_PER_KG));
}
