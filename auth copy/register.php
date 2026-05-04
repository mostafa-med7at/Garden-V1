<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

startSession();
if (currentUser()) { redirect('index.php'); }

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['full_name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (!$name || !$email || !$password) {
        $error = 'Full name, email, and password are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $db  = getDB();
        $chk = $db->prepare("SELECT id FROM users WHERE email = ?");
        $chk->execute([$email]);
        if ($chk->fetch()) {
            $error = 'An account with this email already exists.';
        } else {
            $gateCode = 'GATE' . strtoupper(substr(md5($email . time()), 0, 6));
            $hash     = password_hash($password, PASSWORD_DEFAULT);
            $stmt     = $db->prepare("
                INSERT INTO users (full_name, email, password_hash, phone, role_id, gate_code)
                VALUES (?, ?, ?, ?, 4, ?)
            ");
            $stmt->execute([$name, $email, $hash, $phone, $gateCode]);
            $success = 'Account created! Your gate code is: <strong>' . e($gateCode) . '</strong><br>You can now <a href="login.php" class="alert-link fw-semibold">sign in</a>.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account — <?= APP_NAME ?></title>
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
    <div class="auth-logo">🌿</div>
    <h1 class="h4 fw-bold text-center mb-1" style="color:var(--green-dark)">Create Account</h1>
    <p class="auth-sub"><?= APP_NAME ?></p>

    <?php if ($error): ?>
        <div class="alert alert-danger py-2 small"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success py-2 small"><?= $success ?></div>
    <?php endif; ?>

    <?php if (!$success): ?>
    <form method="POST" action="" novalidate>
        <div class="mb-3">
            <label for="full_name" class="form-label fw-semibold small">Full name <span class="text-danger">*</span></label>
            <input type="text" id="full_name" name="full_name" class="form-control"
                   placeholder=""
                   value="<?= e($_POST['full_name'] ?? '') ?>" required>
        </div>
        <div class="mb-3">
            <label for="email" class="form-label fw-semibold small">Email address <span class="text-danger">*</span></label>
            <input type="email" id="email" name="email" class="form-control"
                   placeholder="you@example.com"
                   value="<?= e($_POST['email'] ?? '') ?>" required>
        </div>
        <div class="mb-3">
            <label for="phone" class="form-label fw-semibold small">Phone <span class="text-muted small fw-normal">(optional)</span></label>
            <input type="text" id="phone" name="phone" class="form-control"
                   placeholder=""
                   value="<?= e($_POST['phone'] ?? '') ?>">
        </div>
        <div class="mb-3">
            <label for="password" class="form-label fw-semibold small">Password <span class="text-danger">*</span></label>
            <input type="password" id="password" name="password" class="form-control"
                   placeholder="Min. 6 characters" required>
        </div>
        <div class="mb-4">
            <label for="confirm_password" class="form-label fw-semibold small">Confirm password <span class="text-danger">*</span></label>
            <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                   placeholder="" required>
        </div>
        <button type="submit" class="btn btn-success w-100 fw-semibold">Create Account</button>
    </form>
    <?php endif; ?>

    <p class="auth-footer mt-3">
        Already have an account? <a href="login.php" class="fw-semibold">Sign in</a>
    </p>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
