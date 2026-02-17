<?php
require_once __DIR__ . '/functions.php';

// Ensure tables exist
initializeDatabase();

function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(?string $token): bool {
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        return false;
    }
    if ($token === null || $token === '') {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function csrfInputField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function requireCsrfToken(): void {
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        http_response_code(403);
        die('CSRF validation failed');
    }
}

function isLoggedIn(): bool {
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        return false;
    }
    if (isset($_SESSION['login_time']) && (time() - (int)$_SESSION['login_time']) > SESSION_LIFETIME) {
        logout();
        return false;
    }
    return true;
}

function requireAuth(): void {
    if (!isLoggedIn()) {
        header('Location: /admin/login.php');
        exit();
    }

    if (!empty($_SESSION['must_change_password'])) {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $allowed = [
            '/admin/change-password.php',
            '/admin/logout.php',
        ];
        if (!in_array($script, $allowed, true)) {
            header('Location: /admin/change-password.php');
            exit();
        }
    }
}

function requireAdmin(): void {
    requireAuth();
    $role = (string)($_SESSION['role'] ?? '');
    if (!isAdminRole($role)) {
        http_response_code(403);
        die('Admin role required');
    }
}

function login($username, $password, ?string $mfaCode = null): array {
    $username = normalizeUsername((string)$username);
    $password = (string)$password;

    $lockState = getLoginLockState($username);
    if (!empty($lockState['locked'])) {
        return [
            'success' => false,
            'error' => 'Too many failed login attempts. Try again later.',
            'lockout_seconds' => (int)$lockState['remaining_seconds'],
        ];
    }

    $user = getUserByUsername($username, true);
    if (!$user || (int)$user['is_active'] !== 1 || !password_verify($password, $user['password_hash'])) {
        recordLoginAttempt($username, false);
        return ['success' => false, 'error' => 'Invalid username or password'];
    }

    if ((int)$user['mfa_enabled'] === 1) {
        $secret = decryptMfaSecret((string)($user['mfa_secret'] ?? ''));
        if ($secret === '' || !verifyTotpCode($secret, (string)($mfaCode ?? ''))) {
            recordLoginAttempt($username, false);
            return ['success' => false, 'error' => 'MFA code is required or invalid', 'mfa_required' => true];
        }
    }

    resetLoginAttempts($username);
    recordLoginAttempt($username, true);

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['login_time'] = time();
    $_SESSION['must_change_password'] = (int)$user['must_change_password'] === 1 ? 1 : 0;
    getCsrfToken();

    createAuditLog((int)$user['id'], 'auth.login', 'session', session_id(), ['mfa_enabled' => (int)$user['mfa_enabled']]);
    return ['success' => true, 'must_change_password' => (int)$user['must_change_password'] === 1];
}

function logout(): bool {
    $actorId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    if ($actorId) {
        createAuditLog($actorId, 'auth.logout', 'session', session_id());
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        @session_destroy();
        // Expire session cookie so client removes it (M-09)
        setcookie(SESSION_NAME, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => SESSION_COOKIE_SECURE,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    return true;
}

function getCurrentUser(): ?array {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    $user = getUserById((int)$_SESSION['user_id'], false);
    if (!$user || (int)$user['is_active'] !== 1) {
        logout();
        return null;
    }
    return $user;
}

function changePassword($userId, $currentPassword, $newPassword): array {
    $userId = (int)$userId;
    if ($userId <= 0) {
        return ['success' => false, 'error' => 'Invalid user'];
    }

    $passwordError = validatePassword((string)$newPassword);
    if ($passwordError) {
        return ['success' => false, 'error' => $passwordError];
    }

    $db = getDbConnection();
    $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = :id LIMIT 1");
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);

    if (!$user || !password_verify((string)$currentPassword, $user['password_hash'])) {
        unset($result, $stmt, $db);
        return ['success' => false, 'error' => 'Current password is incorrect'];
    }

    $newHash = password_hash((string)$newPassword, PASSWORD_BCRYPT, ['cost' => PASSWORD_COST]);
    $update = $db->prepare("
        UPDATE users
        SET password_hash = :hash,
            must_change_password = 0
        WHERE id = :id
    ");
    $update->bindValue(':hash', $newHash, SQLITE3_TEXT);
    $update->bindValue(':id', $userId, SQLITE3_INTEGER);
    $update->execute();

    $_SESSION['must_change_password'] = 0;

    // Release the active writer connection before opening a new one in createAuditLog().
    unset($result, $stmt, $update, $db);

    createAuditLog($userId, 'user.password_change', 'user', (string)$userId);
    return ['success' => true];
}

