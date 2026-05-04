<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();
$currentUser = getCurrentUser();
if (!$currentUser) {
    header('Location: /admin/login.php');
    exit();
}

$message = null;
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'generate') {
        $_SESSION['pending_mfa_secret'] = generateTotpSecret();
        $message = 'Generated a new MFA secret. Confirm with a code from your authenticator app.';
        $messageType = 'success';
    } elseif ($action === 'enable') {
        $secret = (string)($_SESSION['pending_mfa_secret'] ?? '');
        $code = trim((string)($_POST['code'] ?? ''));
        if ($secret === '') {
            $message = 'Generate an MFA secret first.';
            $messageType = 'danger';
        } elseif (!verifyTotpCode($secret, $code)) {
            $message = 'Invalid MFA code. Ensure your authenticator time is correct.';
            $messageType = 'danger';
        } else {
            $res = enableUserMfa((int)$currentUser['id'], $secret);
            if ($res['success']) {
                unset($_SESSION['pending_mfa_secret']);
                $message = 'MFA enabled successfully.';
                $messageType = 'success';
                $currentUser = getCurrentUser();
            } else {
                $message = $res['error'] ?? 'Failed to enable MFA';
                $messageType = 'danger';
            }
        }
    } elseif ($action === 'disable') {
        $password = (string)($_POST['current_password'] ?? '');
        $fullUser = getUserById((int)$currentUser['id'], true);
        if (!$fullUser || !password_verify($password, (string)$fullUser['password_hash'])) {
            $message = 'Current password is incorrect.';
            $messageType = 'danger';
        } else {
            $res = disableUserMfa((int)$currentUser['id']);
            if ($res['success']) {
                unset($_SESSION['pending_mfa_secret']);
                $message = 'MFA disabled.';
                $messageType = 'success';
                $currentUser = getCurrentUser();
            } else {
                $message = $res['error'] ?? 'Failed to disable MFA';
                $messageType = 'danger';
            }
        }
    }
}

$pendingSecret = (string)($_SESSION['pending_mfa_secret'] ?? '');
$otpauthUri = '';
if ($pendingSecret !== '') {
    $issuer = rawurlencode('Sanctum Tasks');
    $label = rawurlencode('Sanctum Tasks:' . (string)$currentUser['username']);
    $otpauthUri = "otpauth://totp/{$label}?secret={$pendingSecret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
}

require __DIR__ . '/_layout_top.php';
?>

<div class="d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center justify-content-between gap-2 mb-4">
    <h1 class="h3 mb-0">MFA Settings</h1>
    <a class="btn btn-outline-secondary" href="/admin/">Back to Tasks</a>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3">Current Status</h2>
        <p>
            MFA is currently
            <strong><?= (int)$currentUser['mfa_enabled'] === 1 ? 'enabled' : 'disabled' ?></strong>
            for <code><?= htmlspecialchars($currentUser['username']) ?></code>.
        </p>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3">Enable MFA (TOTP)</h2>
        <form method="post" class="mb-3">
            <?= csrfInputField() ?>
            <input type="hidden" name="action" value="generate">
            <button class="btn btn-outline-primary" type="submit">Generate New MFA Secret</button>
        </form>

        <?php if ($pendingSecret !== ''): ?>
            <div class="alert alert-warning">
                <p class="mb-2">Add this secret to your authenticator app, then enter a 6-digit code to confirm:</p>
                <div class="font-monospace bg-light border rounded p-2 mb-2" style="word-break: break-all;">
                    <?= htmlspecialchars($pendingSecret) ?>
                </div>
                <p class="mb-0 small text-muted">OTPAuth URI: <code><?= htmlspecialchars($otpauthUri) ?></code></p>
            </div>
            <form method="post">
                <?= csrfInputField() ?>
                <input type="hidden" name="action" value="enable">
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label">6-digit code</label>
                        <input class="form-control" name="code" inputmode="numeric" pattern="[0-9]{6}" required>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button class="btn btn-primary w-100" type="submit">Enable MFA</button>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h5 mb-3 text-danger">Disable MFA</h2>
        <form method="post">
            <?= csrfInputField() ?>
            <input type="hidden" name="action" value="disable">
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label">Current password</label>
                    <input class="form-control" type="password" name="current_password" required>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button class="btn btn-outline-danger w-100" type="submit">Disable MFA</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
