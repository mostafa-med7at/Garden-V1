<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/constants.php';
$user      = requireLogin();
$pageTitle = 'Dashboard';
$db        = getDB();

// Quick stats
$totalPlots     = $db->query("SELECT COUNT(*) FROM plots")->fetchColumn();
$availablePlots = $db->query("SELECT COUNT(*) FROM plots WHERE status='available'")->fetchColumn();
$totalMembers   = $db->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();
$openIncidents  = $db->query("SELECT COUNT(*) FROM incidents WHERE status='open'")->fetchColumn();
$lowStock       = $db->query("SELECT COUNT(*) FROM consumables WHERE stock_level <= reorder_threshold")->fetchColumn();
$activeTrades   = $db->query("SELECT COUNT(*) FROM flash_trades WHERE status='active' AND expires_at > NOW()")->fetchColumn();
$openTasks      = $db->query("SELECT COUNT(*) FROM tasks WHERE status='open'")->fetchColumn();
$waitlistCount  = $db->query("SELECT COUNT(*) FROM waitlist WHERE status='waiting'")->fetchColumn();

// My lease (if plot owner)
$myLease = null;
if ($user['role_name'] === 'plot_owner') {
    $stmt = $db->prepare("SELECT l.*, p.plot_code FROM leases l JOIN plots p ON l.plot_id=p.id WHERE l.user_id=? AND l.status='active' LIMIT 1");
    $stmt->execute([$user['id']]);
    $myLease = $stmt->fetch();
}

// Recent broadcasts
$broadcasts = $db->query("SELECT b.*, u.full_name FROM broadcasts b JOIN users u ON b.admin_id=u.id ORDER BY b.sent_at DESC LIMIT 3")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1>Welcome back, <?= e($user['full_name']) ?> 👋</h1>
    <p>Here's what's happening in your garden today.</p>
</div>

<!-- Stats row -->
<div class="stats-row">
    <div class="stat-card">
        <span class="stat-value"><?= $availablePlots ?>/<?= $totalPlots ?></span>
        <span class="stat-label">Plots available</span>
    </div>
    <div class="stat-card accent-amber">
        <span class="stat-value"><?= $waitlistCount ?></span>
        <span class="stat-label">On waitlist</span>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= $totalMembers ?></span>
        <span class="stat-label">Active members</span>
    </div>
    <div class="stat-card accent-coral">
        <span class="stat-value"><?= $openIncidents ?></span>
        <span class="stat-label">Open incidents</span>
    </div>
    <div class="stat-card accent-amber">
        <span class="stat-value"><?= $lowStock ?></span>
        <span class="stat-label">Low stock items</span>
    </div>
    <div class="stat-card accent-blue">
        <span class="stat-value"><?= $activeTrades ?></span>
        <span class="stat-label">Active trades</span>
    </div>
</div>

<div class="dashboard-grid">

    <!-- My account card -->
    <div class="card">
        <div class="card-header">My Account</div>
        <div class="card-body">
            <p><strong>Role:</strong> <span class="badge badge-success"><?= e($user['role_name']) ?></span></p>
            <p class="mt-1"><strong>Community points:</strong> <?= (int)$user['points'] ?></p>
            <p class="mt-1"><strong>Karma points:</strong> ⭐ <?= (int)$user['karma'] ?></p>
            <p class="mt-1"><strong>Seed credits:</strong> 🌱 <?= (int)$user['credits'] ?></p>
            <?php if ($myLease): ?>
                <hr style="margin:1rem 0; border-color:var(--gray-200)">
                <p><strong>Plot:</strong> <?= e($myLease['plot_code']) ?></p>
                <p class="mt-1"><strong>Lease expires:</strong> <?= e($myLease['end_date']) ?></p>
                <?php
                    $daysLeft = (int)((strtotime($myLease['end_date']) - time()) / 86400);
                    $pct = max(0, min(100, ($daysLeft / 365) * 100));
                ?>
                <div class="progress mt-1">
                    <div class="progress-bar <?= $daysLeft < 30 ? 'danger' : '' ?>" style="width:<?= $pct ?>%"></div>
                </div>
                <p class="text-sm text-muted mt-1"><?= $daysLeft ?> days remaining</p>
            <?php else: ?>
                <p class="text-sm text-muted mt-2">No active lease. <a href="<?= APP_URL ?>/modules/land/waitlist.php">Join the waitlist</a></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick links card -->
    <div class="card">
        <div class="card-header">Quick Actions</div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:.6rem">
            <a class="btn btn-primary" href="<?= APP_URL ?>/modules/land/plots.php">🗺️ View Plot Map</a>
            <a class="btn btn-secondary" href="<?= APP_URL ?>/modules/resources/tools.php">🔧 Reserve a Tool</a>
            <a class="btn btn-secondary" href="<?= APP_URL ?>/modules/marketplace/trades.php">🥕 Browse Marketplace</a>
            <a class="btn btn-secondary" href="<?= APP_URL ?>/modules/volunteer/tasks.php">📋 View Tasks (<?= $openTasks ?> open)</a>
            <a class="btn btn-secondary" href="<?= APP_URL ?>/modules/land/pest_report.php">🐛 Report Pest/Disease</a>
            <a class="btn btn-secondary" href="<?= APP_URL ?>/modules/volunteer/incidents.php">⚠️ Report Incident</a>
        </div>
    </div>

    <!-- Recent broadcasts -->
    <div class="card">
        <div class="card-header">📢 Recent Announcements</div>
        <div class="card-body">
            <?php if ($broadcasts): ?>
                <?php foreach ($broadcasts as $b): ?>
                    <div style="margin-bottom:.85rem;padding-bottom:.85rem;border-bottom:1px solid var(--gray-200)">
                        <div class="d-flex align-items-center gap-1">
                            <span class="badge badge-<?= $b['site_status']==='emergency'?'danger':($b['site_status']==='warning'?'warning':'info') ?>"><?= e($b['site_status']) ?></span>
                            <strong><?= e($b['title']) ?></strong>
                        </div>
                        <p class="text-sm text-muted mt-1"><?= e(substr($b['message'],0,100)) ?>...</p>
                        <p class="text-sm text-muted">By <?= e($b['full_name']) ?> — <?= date('d M Y', strtotime($b['sent_at'])) ?></p>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="text-muted">No recent announcements.</p>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
