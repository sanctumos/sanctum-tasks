<?php
/**
 * Settings tab: change password (self).
 * Expects: $currentUser already loaded, _layout_top.php already included.
 * Sets: $pwd_error, $pwd_success.
 */

$pwd_error = null;
$pwd_success = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['settings_action'] ?? '') === 'change_password') {
    requireCsrfToken();
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($newPassword !== $confirmPassword) {
        $pwd_error = 'New password and confirmation do not match.';
    } else {
        $result = changePassword((int)$currentUser['id'], $currentPassword, $newPassword);
        if ($result['success']) {
            $pwd_success = 'Password changed successfully.';
            $_SESSION['must_change_password'] = 0;
        } else {
            $pwd_error = $result['error'] ?? 'Failed to change password.';
        }
    }
}
?>

<div class="surface surface-pad">
    <div class="section-title"><i class="bi bi-asterisk"></i> Change password</div>
    <p class="fine-print mb-3">Set a new password for <code><?= htmlspecialchars($currentUser['username']) ?></code>.</p>

    <?php if (!empty($_SESSION['must_change_password'])): ?>
        <div class="alert alert-warning"><i class="bi bi-shield-exclamation me-1"></i>You must change your password before continuing.</div>
    <?php endif; ?>
    <?php if ($pwd_error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($pwd_error) ?></div>
    <?php endif; ?>
    <?php if ($pwd_success): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle-fill me-1"></i><?= htmlspecialchars($pwd_success) ?></div>
    <?php endif; ?>

    <form method="post" action="/admin/settings.php?tab=password" style="max-width: 480px;">
        <?= csrfInputField() ?>
        <input type="hidden" name="settings_action" value="change_password">
        <div class="mb-3">
            <label class="form-label">Current password</label>
            <input class="form-control" type="password" name="current_password" required autocomplete="current-password">
        </div>
        <div class="mb-3">
            <label class="form-label">New password</label>
            <input class="form-control" type="password" name="new_password" required autocomplete="new-password">
            <div class="form-text">At least <?= (int)PASSWORD_MIN_LENGTH ?> characters, with uppercase, lowercase, and a number.</div>
        </div>
        <div class="mb-3">
            <label class="form-label">Confirm new password</label>
            <input class="form-control" type="password" name="confirm_password" required autocomplete="new-password">
        </div>
        <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg me-1"></i>Change password</button>
    </form>
</div>
