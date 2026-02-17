<?php
require_once __DIR__ . '/../includes/auth.php';

if (isLoggedIn()) {
    header('Location: /admin/');
    exit();
}

$error = null;
$lockoutSeconds = 0;
$mfaRequired = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $mfaCode = $_POST['mfa_code'] ?? null;
    $result = login($username, $password, $mfaCode);
    if ($result['success']) {
        header('Location: /admin/');
        exit();
    }
    $error = $result['error'] ?? 'Login failed';
    $lockoutSeconds = (int)($result['lockout_seconds'] ?? 0);
    $mfaRequired = !empty($result['mfa_required']);
}

require __DIR__ . '/_layout_top.php';
?>

<div class="row justify-content-center">
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h1 class="h4 mb-3">Login</h1>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <?php if ($lockoutSeconds > 0): ?>
                    <div class="alert alert-warning">Login locked for approximately <?= (int)$lockoutSeconds ?> seconds.</div>
                <?php endif; ?>
                <form method="post" action="/admin/login.php">
                    <?= csrfInputField() ?>
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input class="form-control" name="username" autocomplete="username" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input class="form-control" type="password" name="password" autocomplete="current-password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">MFA Code (if enabled)</label>
                        <input class="form-control" name="mfa_code" inputmode="numeric" pattern="[0-9]{6}" placeholder="123456">
                        <?php if ($mfaRequired): ?>
                            <div class="form-text text-danger">A valid 6-digit MFA code is required for this account.</div>
                        <?php endif; ?>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Sign in</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>

