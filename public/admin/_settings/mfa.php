<?php
/**
 * Settings tab: MFA (self).
 * Expects: $currentUser loaded.
 */

$mfa_message = null;
$mfa_messageType = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string)($_POST['settings_action'] ?? ''), ['mfa_generate', 'mfa_enable', 'mfa_disable'], true)) {
    requireCsrfToken();
    $action = (string)$_POST['settings_action'];

    if ($action === 'mfa_generate') {
        $_SESSION['pending_mfa_secret'] = generateTotpSecret();
        $mfa_message = 'Generated a new MFA secret. Confirm with a code from your authenticator app.';
    } elseif ($action === 'mfa_enable') {
        $secret = (string)($_SESSION['pending_mfa_secret'] ?? '');
        $code = trim((string)($_POST['code'] ?? ''));
        if ($secret === '') {
            $mfa_message = 'Generate an MFA secret first.';
            $mfa_messageType = 'danger';
        } elseif (!verifyTotpCode($secret, $code)) {
            $mfa_message = 'Invalid MFA code. Ensure your authenticator time is correct.';
            $mfa_messageType = 'danger';
        } else {
            $res = enableUserMfa((int)$currentUser['id'], $secret);
            if ($res['success']) {
                unset($_SESSION['pending_mfa_secret']);
                $mfa_message = 'MFA enabled successfully.';
                $currentUser = getCurrentUser();
            } else {
                $mfa_message = $res['error'] ?? 'Failed to enable MFA';
                $mfa_messageType = 'danger';
            }
        }
    } elseif ($action === 'mfa_disable') {
        $password = (string)($_POST['current_password'] ?? '');
        $fullUser = getUserById((int)$currentUser['id'], true);
        if (!$fullUser || !password_verify($password, (string)$fullUser['password_hash'])) {
            $mfa_message = 'Current password is incorrect.';
            $mfa_messageType = 'danger';
        } else {
            $res = disableUserMfa((int)$currentUser['id']);
            if ($res['success']) {
                unset($_SESSION['pending_mfa_secret']);
                $mfa_message = 'MFA disabled.';
                $currentUser = getCurrentUser();
            } else {
                $mfa_message = $res['error'] ?? 'Failed to disable MFA';
                $mfa_messageType = 'danger';
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
$mfaEnabled = (int)$currentUser['mfa_enabled'] === 1;
?>

<?php if ($mfa_message): ?>
    <div class="alert alert-<?= htmlspecialchars($mfa_messageType) ?> alert-dismissible fade show"><?= htmlspecialchars($mfa_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="surface surface-pad mb-3">
    <div class="section-title"><i class="bi bi-shield-lock"></i> Status</div>
    <p class="mb-0">
        MFA is currently
        <?php if ($mfaEnabled): ?>
            <span class="status-pill status-pill--done"><i class="bi bi-shield-check"></i> Enabled</span>
        <?php else: ?>
            <span class="status-pill status-pill--blocked"><i class="bi bi-shield-exclamation"></i> Disabled</span>
        <?php endif; ?>
        for <code><?= htmlspecialchars($currentUser['username']) ?></code>.
    </p>
</div>

<?php if (!$mfaEnabled): ?>
    <div class="surface surface-pad">
        <div class="section-title"><i class="bi bi-key"></i> Enable MFA (TOTP)</div>
        <p class="text-muted small mb-3">Generate a secret, scan it into your authenticator app (Google Authenticator, 1Password, Authy…), then confirm with a 6-digit code.</p>

        <?php if ($pendingSecret === ''): ?>
            <form method="post" action="/admin/settings.php?tab=mfa">
                <?= csrfInputField() ?>
                <input type="hidden" name="settings_action" value="mfa_generate">
                <button class="btn btn-primary" type="submit"><i class="bi bi-key me-1"></i>Generate new secret</button>
            </form>
        <?php else: ?>
            <div class="alert alert-warning mb-3">
                <p class="mb-2"><strong>Add this secret to your authenticator app:</strong></p>
                <div class="font-monospace bg-white border rounded p-2 mb-2" style="word-break: break-all;"><?= htmlspecialchars($pendingSecret) ?></div>
                <p class="mb-0 small text-muted">OTPAuth URI: <code style="word-break: break-all;"><?= htmlspecialchars($otpauthUri) ?></code></p>
            </div>
            <form method="post" action="/admin/settings.php?tab=mfa" class="row g-2 align-items-end">
                <?= csrfInputField() ?>
                <input type="hidden" name="settings_action" value="mfa_enable">
                <div class="col-12 col-md-4">
                    <label class="form-label">6-digit code</label>
                    <input class="form-control" name="code" inputmode="numeric" pattern="[0-9]{6}" required>
                </div>
                <div class="col-12 col-md-3">
                    <button class="btn btn-primary w-100" type="submit"><i class="bi bi-check-lg me-1"></i>Enable MFA</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="surface surface-pad">
        <div class="section-title text-danger"><i class="bi bi-shield-exclamation"></i> Disable MFA</div>
        <p class="text-muted small mb-3">You'll need to re-enroll if you disable. Confirm your current password to proceed.</p>
        <form method="post" action="/admin/settings.php?tab=mfa" class="row g-2 align-items-end">
            <?= csrfInputField() ?>
            <input type="hidden" name="settings_action" value="mfa_disable">
            <div class="col-12 col-md-4">
                <label class="form-label">Current password</label>
                <input class="form-control" type="password" name="current_password" required autocomplete="current-password">
            </div>
            <div class="col-12 col-md-3">
                <button class="btn btn-outline-danger w-100" type="submit"><i class="bi bi-x-octagon me-1"></i>Disable MFA</button>
            </div>
        </form>
    </div>
<?php endif; ?>
