<?php
// includes/header.php
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'Dashboard') ?> — <?= APP_NAME ?></title>

    <!-- Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!-- App stylesheet (sits on top of Bootstrap) -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body>

<nav class="navbar navbar-expand-md navbar-dark bg-garden sticky-top shadow-sm px-3" style="background: var(--green-dark) !important;">
    <a class="navbar-brand fw-semibold" href="<?= APP_URL ?>/index.php">🌱 <?= APP_NAME ?></a>

    <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
        <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="mainNav">
        <ul class="navbar-nav me-auto gap-1">
            <?php if ($user): ?>
                <?php $role = $user['role_name']; ?>
                <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/modules/land/plots.php">🗺️ Plots</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/modules/resources/tools.php">🔧 Tools</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/modules/resources/seeds.php">🌰 Seeds</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/modules/volunteer/tasks.php">🤝 Volunteer</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/modules/marketplace/trades.php">🛒 Market</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/modules/media/index.php">📁 Files</a></li>
                <?php if ($role === 'admin' || $role === 'warden'): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle text-warning fw-semibold" href="#" id="adminMenu" role="button" data-bs-toggle="dropdown">⚙️ Admin</a>
                        <ul class="dropdown-menu shadow-sm" style="border:1px solid var(--gray-300)">
                            <?php if ($role === 'admin'): ?>
                            <li><a class="dropdown-item" href="<?= APP_URL ?>/admin/users.php">👥 Manage Users</a></li>
                            <li><a class="dropdown-item" href="<?= APP_URL ?>/modules/notifications/index.php">📧 Emails & Alerts</a></li>
                            <?php endif; ?>
                            <li><a class="dropdown-item" href="<?= APP_URL ?>/modules/reports/index.php">📊 Reports & Export</a></li>
                            <li><a class="dropdown-item" href="<?= APP_URL ?>/modules/land/audit.php">🔍 System Audit</a></li>
                        </ul>
                    </li>
                <?php endif; ?>
            <?php else: ?>
                <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/modules/land/plots.php">🗺️ Plots</a></li>
            <?php endif; ?>
        </ul>

        <div class="d-flex align-items-center gap-2 ms-2">
            <?php if ($user): ?>
                <span class="user-badge role-<?= e($role) ?>"><?= e($role) ?></span>
                <span class="text-white-50 small"><?= e($user['full_name']) ?></span>
                <span class="karma-pill">⭐ <?= (int)($user['karma'] ?? $user['karma_points'] ?? 0) ?></span>
                <a href="<?= APP_URL ?>/auth/logout.php" class="btn btn-sm btn-outline-light">Sign out</a>
            <?php else: ?>
                <a href="<?= APP_URL ?>/auth/login.php"    class="btn btn-sm btn-outline-light">Login</a>
                <a href="<?= APP_URL ?>/auth/register.php" class="btn btn-sm btn-light text-success fw-semibold">Register</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<main class="main-content">
<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
        <?= e($flash['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
