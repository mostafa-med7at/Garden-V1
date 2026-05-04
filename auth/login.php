<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

startSession();
if (currentUser()) {
    redirect('index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email && $password) {
        $db   = getDB();
        $stmt = $db->prepare("
            SELECT u.*, r.name AS role_name
            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE u.email = ? AND u.is_active = 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user'] = [
                'id'         => $user['id'],
                'full_name'  => $user['full_name'],
                'email'      => $user['email'],
                'role_id'    => $user['role_id'],
                'role_name'  => $user['role_name'],
                'membership' => $user['membership_status'],
                'karma'      => $user['karma_points'],
                'points'     => $user['community_points'],
                'credits'    => $user['seed_bank_credits'],
            ];
            loadPermissions($user['role_id']);
            auditLog('login', 'auth', 'users', $user['id'], 'User logged in');
            redirect('index.php');
        } else {
            $error = 'Invalid email or password.';
        }
    } else {
        $error = 'Please fill in all fields.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — <?= APP_NAME ?></title>
    <!-- Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <!-- Custom styles -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>

<body class="auth-page">

    <div class="auth-card">
        <div class="auth-logo">🌱</div>
        <h1 class="h4 fw-bold text-center mb-1" style="color:var(--green-dark)"><?= APP_NAME ?></h1>
        <p class="auth-sub">Sign in to your garden account</p>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2 small"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="" novalidate>
            <div class="mb-3">
                <label for="email" class="form-label fw-semibold small">Email address</label>
                <input type="email" id="email" name="email" class="form-control"
                    placeholder="you@example.com"
                    value="<?= e($_POST['email'] ?? '') ?>" required autofocus>
            </div>
            <div class="mb-4">
                <label for="password" class="form-label fw-semibold small">Password</label>
                <input type="password" id="password" name="password" class="form-control"
                    placeholder="" required>
            </div>
            <button type="submit" class="btn btn-success w-100 fw-semibold">Sign In</button>
        </form>

        <p class="auth-footer mt-3">
            Don't have an account? <a href="<?= APP_URL ?>/auth/register.php" class="fw-semibold">Register</a>
        </p>

        <div class="demo-creds">
            <strong>Demo accounts</strong><br>
            admin@garden.com / password<br>
            warden@garden.com / password<br>
            alice@garden.com / password (plot owner)<br>
            bob@garden.com / password (member)
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>