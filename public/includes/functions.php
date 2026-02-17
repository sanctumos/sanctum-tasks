<?php
require_once __DIR__ . '/config.php';

// Ensure DB is initialized when helpers are loaded
initializeDatabase();

function nowUtc(): string {
    return gmdate('Y-m-d H:i:s');
}

function requestIpAddress(): string {
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            $raw = trim((string)$_SERVER[$k]);
            if ($k === 'HTTP_X_FORWARDED_FOR') {
                $parts = explode(',', $raw);
                $raw = trim($parts[0]);
            }
            if ($raw !== '') {
                return $raw;
            }
        }
    }
    return 'unknown';
}

function truncateString(string $value, int $max): string {
    if (strlen($value) <= $max) {
        return $value;
    }
    return substr($value, 0, $max);
}

function normalizeSlug(string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9_-]+/', '-', $value) ?? '';
    $value = trim($value, '-_');
    return truncateString($value, 50);
}

function normalizeUsername(string $username): string {
    return strtolower(trim($username));
}

function validateUsername(string $username): ?string {
    $u = normalizeUsername($username);
    if ($u === '') {
        return 'Username is required';
    }
    if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $u)) {
        return 'Username must be 3-50 characters and only contain letters, numbers, dot, underscore, or hyphen';
    }
    return null;
}

function validatePassword(string $password): ?string {
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        return 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters';
    }
    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        return 'Password must contain uppercase, lowercase, and a number';
    }
    return null;
}

function generateTemporaryPassword(int $length = 16): string {
    $length = max(PASSWORD_MIN_LENGTH, $length);
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghijkmnopqrstuvwxyz';
    $digits = '23456789';
    $all = $upper . $lower . $digits;

    $chars = [
        $upper[random_int(0, strlen($upper) - 1)],
        $lower[random_int(0, strlen($lower) - 1)],
        $digits[random_int(0, strlen($digits) - 1)],
    ];

    for ($i = count($chars); $i < $length; $i++) {
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }

    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        $tmp = $chars[$i];
        $chars[$i] = $chars[$j];
        $chars[$j] = $tmp;
    }

    return implode('', $chars);
}

function normalizeRole(string $role): ?string {
    $allowed = ['admin', 'manager', 'member', 'api'];
    $role = strtolower(trim($role));
    if (!in_array($role, $allowed, true)) {
        return null;
    }
    return $role;
}

function isAdminRole(string $role): bool {
    return in_array($role, ['admin', 'manager'], true);
}

function normalizeNullableText($value, int $maxLen): ?string {
    if ($value === null) {
        return null;
    }
    $v = trim((string)$value);
    if ($v === '') {
        return null;
    }
    return truncateString($v, $maxLen);
}

function normalizePriority($priority): ?string {
    $allowed = ['low', 'normal', 'high', 'urgent'];
    $p = strtolower(trim((string)$priority));
    if ($p === '') {
        return null;
    }
    return in_array($p, $allowed, true) ? $p : null;
}

function parseDateTimeOrNull($value): ?string {
    if ($value === null) {
        return null;
    }
    $s = trim((string)$value);
    if ($s === '') {
        return null;
    }

    try {
        $dt = new DateTime($s, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

function normalizeTags($tags): array {
    if ($tags === null) {
        return [];
    }

    if (is_string($tags)) {
        if (trim($tags) === '') {
            return [];
        }
        $tags = preg_split('/[,]+/', $tags) ?: [];
    }

    if (!is_array($tags)) {
        return [];
    }

    $out = [];
    foreach ($tags as $tag) {
        $t = trim((string)$tag);
        if ($t === '') {
            continue;
        }
        $t = truncateString($t, 32);
        $out[strtolower($t)] = $t;
        if (count($out) >= 20) {
            break;
        }
    }
    return array_values($out);
}

function decodeTagsJson(?string $tagsJson): array {
    if ($tagsJson === null || trim($tagsJson) === '') {
        return [];
    }
    $decoded = json_decode($tagsJson, true);
    if (!is_array($decoded)) {
        return [];
    }
    return normalizeTags($decoded);
}

function encodeTagsJson(array $tags): ?string {
    $tags = normalizeTags($tags);
    if (!$tags) {
        return null;
    }
    return json_encode($tags);
}

// --------------------
// Audit logging
// --------------------
function createAuditLog(?int $actorUserId, string $action, string $entityType, ?string $entityId = null, array $metadata = []): void {
    try {
        $db = getDbConnection();
        $stmt = $db->prepare("
            INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, ip_address, metadata_json)
            VALUES (:actor, :action, :etype, :eid, :ip, :meta)
        ");
        if ($actorUserId === null) {
            $stmt->bindValue(':actor', null, SQLITE3_NULL);
        } else {
            $stmt->bindValue(':actor', (int)$actorUserId, SQLITE3_INTEGER);
        }
        $stmt->bindValue(':action', truncateString($action, 80), SQLITE3_TEXT);
        $stmt->bindValue(':etype', truncateString($entityType, 80), SQLITE3_TEXT);
        if ($entityId === null) {
            $stmt->bindValue(':eid', null, SQLITE3_NULL);
        } else {
            $stmt->bindValue(':eid', truncateString((string)$entityId, 64), SQLITE3_TEXT);
        }
        $stmt->bindValue(':ip', truncateString(requestIpAddress(), 128), SQLITE3_TEXT);
        $stmt->bindValue(':meta', $metadata ? json_encode($metadata) : null, $metadata ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmt->execute();
    } catch (Throwable $e) {
        // Best effort only; audit logging should not break app flow.
    }
}

function listAuditLogs(int $limit = 100, int $offset = 0): array {
    $limit = max(1, min(500, $limit));
    $offset = max(0, $offset);
    $db = getDbConnection();
    $stmt = $db->prepare("
        SELECT
            a.*,
            u.username AS actor_username
        FROM audit_logs a
        LEFT JOIN users u ON u.id = a.actor_user_id
        ORDER BY a.id DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $items = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $row['metadata'] = $row['metadata_json'] ? (json_decode($row['metadata_json'], true) ?: []) : [];
        $items[] = $row;
    }
    return $items;
}

// --------------------
// Status model
// --------------------
function listTaskStatuses(): array {
    $db = getDbConnection();
    $res = $db->query("SELECT slug, label, sort_order, is_done, is_default, created_at FROM task_statuses ORDER BY sort_order ASC, slug ASC");
    $statuses = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $row['is_done'] = (int)$row['is_done'];
        $row['is_default'] = (int)$row['is_default'];
        $statuses[] = $row;
    }
    return $statuses;
}

function getTaskStatusMap(): array {
    $cache = [];
    foreach (listTaskStatuses() as $s) {
        $cache[$s['slug']] = $s;
    }
    return $cache;
}

function getTaskStatusBySlug(string $slug): ?array {
    $slug = normalizeSlug($slug);
    if ($slug === '') {
        return null;
    }
    $map = getTaskStatusMap();
    return $map[$slug] ?? null;
}

function getDefaultTaskStatusSlug(): string {
    foreach (listTaskStatuses() as $s) {
        if ((int)$s['is_default'] === 1) {
            return $s['slug'];
        }
    }
    return 'todo';
}

function sanitizeStatus($status): ?string {
    $value = normalizeSlug((string)$status);
    if ($value === '') {
        return null;
    }
    return getTaskStatusBySlug($value) ? $value : null;
}

function createTaskStatus(string $slug, string $label, int $sortOrder = 100, bool $isDone = false, bool $isDefault = false): array {
    $slug = normalizeSlug($slug);
    $label = trim($label);
    if ($slug === '') {
        return ['success' => false, 'error' => 'Status slug is required'];
    }
    if ($label === '') {
        return ['success' => false, 'error' => 'Status label is required'];
    }

    $db = getDbConnection();
    if ($isDefault) {
        $db->exec("UPDATE task_statuses SET is_default = 0");
    }
    $stmt = $db->prepare("
        INSERT INTO task_statuses (slug, label, sort_order, is_done, is_default)
        VALUES (:slug, :label, :sort_order, :is_done, :is_default)
    ");
    $stmt->bindValue(':slug', $slug, SQLITE3_TEXT);
    $stmt->bindValue(':label', truncateString($label, 60), SQLITE3_TEXT);
    $stmt->bindValue(':sort_order', $sortOrder, SQLITE3_INTEGER);
    $stmt->bindValue(':is_done', $isDone ? 1 : 0, SQLITE3_INTEGER);
    $stmt->bindValue(':is_default', $isDefault ? 1 : 0, SQLITE3_INTEGER);

    try {
        $stmt->execute();
        createAuditLog(null, 'task_status.create', 'task_status', $slug, ['label' => $label]);
        return ['success' => true, 'slug' => $slug];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'Status slug already exists'];
    }
}

// --------------------
// Users
// --------------------
function getUserById($id, bool $includeSensitive = false): ?array {
    $db = getDbConnection();
    $sql = $includeSensitive
        ? "SELECT id, username, role, is_active, must_change_password, mfa_enabled, mfa_secret, password_hash, created_at FROM users WHERE id = :id LIMIT 1"
        : "SELECT id, username, role, is_active, must_change_password, mfa_enabled, created_at FROM users WHERE id = :id LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':id', (int)$id, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $row = $res->fetchArray(SQLITE3_ASSOC) ?: null;
    if ($row) {
        $row['is_active'] = (int)$row['is_active'];
        $row['must_change_password'] = (int)$row['must_change_password'];
        $row['mfa_enabled'] = (int)$row['mfa_enabled'];
    }
    return $row;
}

function getUserByUsername($username, bool $includeSensitive = false): ?array {
    $db = getDbConnection();
    $sql = $includeSensitive
        ? "SELECT id, username, role, is_active, must_change_password, mfa_enabled, mfa_secret, password_hash, created_at FROM users WHERE username = :u LIMIT 1"
        : "SELECT id, username, role, is_active, must_change_password, mfa_enabled, created_at FROM users WHERE username = :u LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':u', normalizeUsername((string)$username), SQLITE3_TEXT);
    $res = $stmt->execute();
    $row = $res->fetchArray(SQLITE3_ASSOC) ?: null;
    if ($row) {
        $row['is_active'] = (int)$row['is_active'];
        $row['must_change_password'] = (int)$row['must_change_password'];
        $row['mfa_enabled'] = (int)$row['mfa_enabled'];
    }
    return $row;
}

function listUsers(bool $includeDisabled = false): array {
    $db = getDbConnection();
    $sql = "
        SELECT id, username, role, is_active, must_change_password, mfa_enabled, created_at
        FROM users
        " . ($includeDisabled ? "" : "WHERE is_active = 1") . "
        ORDER BY username ASC
    ";
    $res = $db->query($sql);
    $users = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $row['is_active'] = (int)$row['is_active'];
        $row['must_change_password'] = (int)$row['must_change_password'];
        $row['mfa_enabled'] = (int)$row['mfa_enabled'];
        $users[] = $row;
    }
    return $users;
}

function createUser(string $username, string $password, string $role = 'member', bool $mustChangePassword = true): array {
    $usernameError = validateUsername($username);
    if ($usernameError) {
        return ['success' => false, 'error' => $usernameError];
    }
    $passwordError = validatePassword($password);
    if ($passwordError) {
        return ['success' => false, 'error' => $passwordError];
    }
    $normalizedRole = normalizeRole($role);
    if ($normalizedRole === null) {
        return ['success' => false, 'error' => 'Invalid role'];
    }

    $db = getDbConnection();
    $stmt = $db->prepare("
        INSERT INTO users (username, password_hash, role, is_active, must_change_password)
        VALUES (:username, :hash, :role, 1, :must_change_password)
    ");
    $stmt->bindValue(':username', normalizeUsername($username), SQLITE3_TEXT);
    $stmt->bindValue(':hash', password_hash($password, PASSWORD_BCRYPT, ['cost' => PASSWORD_COST]), SQLITE3_TEXT);
    $stmt->bindValue(':role', $normalizedRole, SQLITE3_TEXT);
    $stmt->bindValue(':must_change_password', $mustChangePassword ? 1 : 0, SQLITE3_INTEGER);

    try {
        $stmt->execute();
        $id = (int)$db->lastInsertRowID();
        createAuditLog(null, 'user.create', 'user', (string)$id, ['username' => normalizeUsername($username), 'role' => $normalizedRole]);
        return ['success' => true, 'id' => $id];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'Username already exists'];
    }
}

function setUserActive(int $userId, bool $isActive): array {
    if ($userId <= 0) {
        return ['success' => false, 'error' => 'Invalid user id'];
    }
    $db = getDbConnection();
    $stmt = $db->prepare("UPDATE users SET is_active = :active WHERE id = :id");
    $stmt->bindValue(':active', $isActive ? 1 : 0, SQLITE3_INTEGER);
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    $stmt->execute();
    createAuditLog(null, $isActive ? 'user.enable' : 'user.disable', 'user', (string)$userId);
    return ['success' => true];
}

function disableUser(int $userId): array {
    return setUserActive($userId, false);
}

function resetUserPassword(int $userId, string $newPassword, bool $mustChangePassword = true): array {
    $passwordError = validatePassword($newPassword);
    if ($passwordError) {
        return ['success' => false, 'error' => $passwordError];
    }
    $db = getDbConnection();
    $stmt = $db->prepare("
        UPDATE users
        SET password_hash = :hash, must_change_password = :must_change_password
        WHERE id = :id
    ");
    $stmt->bindValue(':hash', password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => PASSWORD_COST]), SQLITE3_TEXT);
    $stmt->bindValue(':must_change_password', $mustChangePassword ? 1 : 0, SQLITE3_INTEGER);
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    $stmt->execute();
    createAuditLog(null, 'user.password_reset', 'user', (string)$userId, ['must_change_password' => $mustChangePassword ? 1 : 0]);
    return ['success' => true];
}

// --------------------
// MFA (TOTP)
// --------------------
function base32Encode(string $data): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    $out = '';
    $len = strlen($data);
    for ($i = 0; $i < $len; $i++) {
        $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
    }
    $chunks = str_split($bits, 5);
    foreach ($chunks as $chunk) {
        if (strlen($chunk) < 5) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        }
        $out .= $alphabet[bindec($chunk)];
    }
    return $out;
}

function base32Decode(string $secret): string {
    $secret = strtoupper(trim($secret));
    $secret = preg_replace('/[^A-Z2-7]/', '', $secret) ?? '';
    if ($secret === '') {
        return '';
    }
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    $len = strlen($secret);
    for ($i = 0; $i < $len; $i++) {
        $pos = strpos($alphabet, $secret[$i]);
        if ($pos === false) {
            return '';
        }
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $bytes = str_split($bits, 8);
    $out = '';
    foreach ($bytes as $byte) {
        if (strlen($byte) < 8) {
            continue;
        }
        $out .= chr(bindec($byte));
    }
    return $out;
}

function generateTotpSecret(): string {
    return base32Encode(random_bytes(20));
}

function generateTotpCode(string $secret, ?int $timeSlice = null): string {
    if ($timeSlice === null) {
        $timeSlice = (int)floor(time() / 30);
    }
    $key = base32Decode($secret);
    if ($key === '') {
        return '';
    }

    $time = pack('N*', 0) . pack('N*', $timeSlice);
    $hmac = hash_hmac('sha1', $time, $key, true);
    $offset = ord(substr($hmac, -1)) & 0x0F;
    $truncated =
        ((ord($hmac[$offset]) & 0x7F) << 24) |
        ((ord($hmac[$offset + 1]) & 0xFF) << 16) |
        ((ord($hmac[$offset + 2]) & 0xFF) << 8) |
        (ord($hmac[$offset + 3]) & 0xFF);
    $code = $truncated % 1000000;
    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}

function verifyTotpCode(string $secret, string $code, int $window = 1): bool {
    $code = trim($code);
    if (!preg_match('/^\d{6}$/', $code)) {
        return false;
    }
    $slice = (int)floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(generateTotpCode($secret, $slice + $i), $code)) {
            return true;
        }
    }
    return false;
}

function enableUserMfa(int $userId, string $secret): array {
    if ($userId <= 0 || trim($secret) === '') {
        return ['success' => false, 'error' => 'Invalid MFA setup'];
    }
    $db = getDbConnection();
    $stmt = $db->prepare("UPDATE users SET mfa_secret = :secret, mfa_enabled = 1 WHERE id = :id");
    $stmt->bindValue(':secret', strtoupper(trim($secret)), SQLITE3_TEXT);
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    $stmt->execute();
    createAuditLog($userId, 'user.mfa_enable', 'user', (string)$userId);
    return ['success' => true];
}

function disableUserMfa(int $userId): array {
    if ($userId <= 0) {
        return ['success' => false, 'error' => 'Invalid user id'];
    }
    $db = getDbConnection();
    $stmt = $db->prepare("UPDATE users SET mfa_secret = NULL, mfa_enabled = 0 WHERE id = :id");
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    $stmt->execute();
    createAuditLog($userId, 'user.mfa_disable', 'user', (string)$userId);
    return ['success' => true];
}

// --------------------
// Login lockout / brute-force protection
// --------------------
function recordLoginAttempt(string $username, bool $success): void {
    $db = getDbConnection();
    $stmt = $db->prepare("INSERT INTO login_attempts (username, ip_address, success) VALUES (:username, :ip, :success)");
    $stmt->bindValue(':username', normalizeUsername($username), SQLITE3_TEXT);
    $stmt->bindValue(':ip', requestIpAddress(), SQLITE3_TEXT);
    $stmt->bindValue(':success', $success ? 1 : 0, SQLITE3_INTEGER);
    $stmt->execute();
}

function getLoginLockState(string $username): array {
    $username = normalizeUsername($username);
    if ($username === '') {
        return ['locked' => false, 'remaining_seconds' => 0];
    }

    $db = getDbConnection();
    $windowModifier = '-' . (int)LOGIN_LOCK_WINDOW_SECONDS . ' seconds';
    $stmt = $db->prepare("
        SELECT COUNT(*) AS failed_count, MAX(strftime('%s', attempted_at)) AS last_failed_epoch
        FROM login_attempts
        WHERE success = 0
          AND attempted_at >= datetime('now', :window_modifier)
          AND (username = :username OR ip_address = :ip)
    ");
    $stmt->bindValue(':window_modifier', $windowModifier, SQLITE3_TEXT);
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $stmt->bindValue(':ip', requestIpAddress(), SQLITE3_TEXT);
    $res = $stmt->execute();
    $row = $res->fetchArray(SQLITE3_ASSOC) ?: ['failed_count' => 0, 'last_failed_epoch' => null];

    $failedCount = (int)$row['failed_count'];
    if ($failedCount < LOGIN_LOCK_THRESHOLD || empty($row['last_failed_epoch'])) {
        return ['locked' => false, 'remaining_seconds' => 0];
    }

    $lockedUntil = ((int)$row['last_failed_epoch']) + LOGIN_LOCK_SECONDS;
    $remaining = $lockedUntil - time();
    if ($remaining > 0) {
        return ['locked' => true, 'remaining_seconds' => $remaining];
    }
    return ['locked' => false, 'remaining_seconds' => 0];
}

function resetLoginAttempts(string $username): void {
    $username = normalizeUsername($username);
    if ($username === '') {
        return;
    }
    $db = getDbConnection();
    $stmt = $db->prepare("DELETE FROM login_attempts WHERE username = :username OR ip_address = :ip");
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $stmt->bindValue(':ip', requestIpAddress(), SQLITE3_TEXT);
    $stmt->execute();
}

// --------------------
// API rate limits
// --------------------
function checkApiRateLimit(string $apiKey, int $maxRequests = API_RATE_LIMIT_REQUESTS, int $windowSeconds = API_RATE_LIMIT_WINDOW_SECONDS): array {
    $maxRequests = max(1, $maxRequests);
    $windowSeconds = max(1, $windowSeconds);

    $hash = hash('sha256', $apiKey);
    $windowStart = (int)(floor(time() / $windowSeconds) * $windowSeconds);
    $resetAt = $windowStart + $windowSeconds;

    $db = getDbConnection();

    // Keep table bounded.
    $cleanup = $db->prepare("DELETE FROM api_rate_limits WHERE window_start < :min_window");
    $cleanup->bindValue(':min_window', $windowStart - ($windowSeconds * 10), SQLITE3_INTEGER);
    $cleanup->execute();

    $select = $db->prepare("
        SELECT request_count
        FROM api_rate_limits
        WHERE api_key_hash = :hash AND window_start = :window_start
        LIMIT 1
    ");
    $select->bindValue(':hash', $hash, SQLITE3_TEXT);
    $select->bindValue(':window_start', $windowStart, SQLITE3_INTEGER);
    $res = $select->execute();
    $row = $res->fetchArray(SQLITE3_ASSOC);

    if (!$row) {
        $insert = $db->prepare("
            INSERT INTO api_rate_limits (api_key_hash, window_start, request_count, last_request_at)
            VALUES (:hash, :window_start, 1, CURRENT_TIMESTAMP)
        ");
        $insert->bindValue(':hash', $hash, SQLITE3_TEXT);
        $insert->bindValue(':window_start', $windowStart, SQLITE3_INTEGER);
        $insert->execute();
        return [
            'allowed' => true,
            'limit' => $maxRequests,
            'remaining' => max(0, $maxRequests - 1),
            'reset_epoch' => $resetAt,
            'retry_after' => 0,
        ];
    }

    $count = (int)$row['request_count'];
    if ($count >= $maxRequests) {
        return [
            'allowed' => false,
            'limit' => $maxRequests,
            'remaining' => 0,
            'reset_epoch' => $resetAt,
            'retry_after' => max(1, $resetAt - time()),
        ];
    }

    $update = $db->prepare("
        UPDATE api_rate_limits
        SET request_count = request_count + 1,
            last_request_at = CURRENT_TIMESTAMP
        WHERE api_key_hash = :hash AND window_start = :window_start
    ");
    $update->bindValue(':hash', $hash, SQLITE3_TEXT);
    $update->bindValue(':window_start', $windowStart, SQLITE3_INTEGER);
    $update->execute();

    return [
        'allowed' => true,
        'limit' => $maxRequests,
        'remaining' => max(0, $maxRequests - ($count + 1)),
        'reset_epoch' => $resetAt,
        'retry_after' => 0,
    ];
}

// --------------------
// API keys
// --------------------
function createApiKeyForUser($userId, $keyName, ?int $createdByUserId = null): string {
    $db = getDbConnection();
    $apiKey = bin2hex(random_bytes(32));

    $stmt = $db->prepare("
        INSERT INTO api_keys (user_id, key_name, api_key, created_by_user_id)
        VALUES (:uid, :name, :key, :created_by)
    ");
    $stmt->bindValue(':uid', (int)$userId, SQLITE3_INTEGER);
    $stmt->bindValue(':name', truncateString(trim((string)$keyName) ?: 'Unnamed Key', 80), SQLITE3_TEXT);
    $stmt->bindValue(':key', $apiKey, SQLITE3_TEXT);
    if ($createdByUserId === null) {
        $stmt->bindValue(':created_by', null, SQLITE3_NULL);
    } else {
        $stmt->bindValue(':created_by', (int)$createdByUserId, SQLITE3_INTEGER);
    }
    $stmt->execute();

    createAuditLog($createdByUserId, 'api_key.create', 'api_key', (string)$db->lastInsertRowID(), [
        'user_id' => (int)$userId,
        'key_name' => truncateString(trim((string)$keyName), 80),
    ]);

    return $apiKey;
}

function validateApiKeyAndGetUser($apiKey): ?array {
    $db = getDbConnection();
    $stmt = $db->prepare("
        SELECT
            ak.id AS api_key_id,
            ak.user_id AS user_id,
            u.username AS username,
            u.role AS role,
            u.is_active AS is_active,
            u.must_change_password AS must_change_password,
            u.mfa_enabled AS mfa_enabled,
            u.created_at AS created_at
        FROM api_keys ak
        JOIN users u ON u.id = ak.user_id
        WHERE ak.api_key = :key
          AND ak.revoked_at IS NULL
        LIMIT 1
    ");
    $stmt->bindValue(':key', $apiKey, SQLITE3_TEXT);
    $res = $stmt->execute();
    $row = $res->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        return null;
    }
    if ((int)$row['is_active'] !== 1) {
        return null;
    }

    $update = $db->prepare("UPDATE api_keys SET last_used = CURRENT_TIMESTAMP WHERE id = :id");
    $update->bindValue(':id', (int)$row['api_key_id'], SQLITE3_INTEGER);
    $update->execute();

    return [
        'id' => (int)$row['user_id'],
        'username' => $row['username'],
        'role' => $row['role'],
        'is_active' => (int)$row['is_active'],
        'must_change_password' => (int)$row['must_change_password'],
        'mfa_enabled' => (int)$row['mfa_enabled'],
        'created_at' => $row['created_at'],
        'api_key_id' => (int)$row['api_key_id'],
    ];
}

function getAllApiKeys(bool $includeRevoked = false): array {
    $db = getDbConnection();
    $sql = "
        SELECT
            ak.id,
            ak.user_id,
            ak.key_name,
            ak.api_key,
            ak.created_at,
            ak.last_used,
            ak.revoked_at,
            u.username AS user_username
        FROM api_keys ak
        JOIN users u ON u.id = ak.user_id
        " . ($includeRevoked ? "" : "WHERE ak.revoked_at IS NULL") . "
        ORDER BY ak.created_at DESC
    ";
    $res = $db->query($sql);
    $keys = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $keys[] = $row;
    }
    return $keys;
}

function listApiKeysForUser(int $userId, bool $includeRevoked = false): array {
    $db = getDbConnection();
    $sql = "
        SELECT id, user_id, key_name, api_key, created_at, last_used, revoked_at
        FROM api_keys
        WHERE user_id = :uid
          " . ($includeRevoked ? "" : "AND revoked_at IS NULL") . "
        ORDER BY created_at DESC
    ";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':uid', $userId, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $keys = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $keys[] = $row;
    }
    return $keys;
}

function revokeApiKey(int $id): bool {
    $db = getDbConnection();
    $stmt = $db->prepare("UPDATE api_keys SET revoked_at = CURRENT_TIMESTAMP WHERE id = :id");
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();
    createAuditLog(null, 'api_key.revoke', 'api_key', (string)$id);
    return true;
}

function deleteApiKey($id): bool {
    return revokeApiKey((int)$id);
}

// --------------------
// Task data shaping helpers
// --------------------
function normalizeTaskTitle($title): ?string {
    $title = trim((string)$title);
    if ($title === '') {
        return null;
    }
    return truncateString($title, 200);
}

function normalizeTaskBody($body): ?string {
    if ($body === null) {
        return null;
    }
    $body = trim((string)$body);
    if ($body === '') {
        return null;
    }
    return truncateString($body, 10000);
}

function normalizeTaskProject($project): ?string {
    return normalizeNullableText($project, 120);
}

function normalizeTaskRecurrenceRule($rrule): ?string {
    return normalizeNullableText($rrule, 255);
}

function taskOrderByClause(string $sortBy, string $sortDir): string {
    $dir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';
    $sortBy = strtolower(trim($sortBy));

    if ($sortBy === 'priority') {
        $priorityOrder = "CASE t.priority WHEN 'urgent' THEN 4 WHEN 'high' THEN 3 WHEN 'normal' THEN 2 WHEN 'low' THEN 1 ELSE 0 END";
        return "$priorityOrder $dir, t.id DESC";
    }
    if ($sortBy === 'due_at') {
        return "t.due_at $dir, t.id DESC";
    }
    if ($sortBy === 'created_at') {
        return "t.created_at $dir, t.id DESC";
    }
    if ($sortBy === 'rank') {
        return "t.rank $dir, t.updated_at DESC, t.id DESC";
    }
    if ($sortBy === 'title') {
        return "t.title $dir, t.id DESC";
    }
    if ($sortBy === 'status') {
        return "COALESCE(ts.sort_order, 9999) $dir, t.id DESC";
    }
    // Default
    return "t.updated_at $dir, t.id DESC";
}

function hydrateTaskRow(array $row): array {
    $row['tags'] = decodeTagsJson($row['tags_json'] ?? null);
    $row['rank'] = isset($row['rank']) ? (int)$row['rank'] : 0;
    $row['comment_count'] = isset($row['comment_count']) ? (int)$row['comment_count'] : 0;
    $row['attachment_count'] = isset($row['attachment_count']) ? (int)$row['attachment_count'] : 0;
    $row['watcher_count'] = isset($row['watcher_count']) ? (int)$row['watcher_count'] : 0;
    return $row;
}

// --------------------
// Tasks
// --------------------
function createTask($title, $status, $createdByUserId, $assignedToUserId = null, $body = null, array $options = []): array {
    $title = normalizeTaskTitle($title);
    if ($title === null) {
        return ['success' => false, 'error' => 'Title is required'];
    }

    $createdByUserId = (int)$createdByUserId;
    if ($createdByUserId <= 0 || !getUserById($createdByUserId)) {
        return ['success' => false, 'error' => 'Invalid creator user'];
    }

    if ($status !== null && trim((string)$status) !== '') {
        $statusSlug = sanitizeStatus((string)$status);
        if ($statusSlug === null) {
            return ['success' => false, 'error' => 'Invalid status'];
        }
    } else {
        $statusSlug = getDefaultTaskStatusSlug();
    }

    $body = normalizeTaskBody($body);
    $project = normalizeTaskProject($options['project'] ?? null);
    $dueAt = parseDateTimeOrNull($options['due_at'] ?? null);
    if (($options['due_at'] ?? null) !== null && $dueAt === null) {
        return ['success' => false, 'error' => 'Invalid due_at datetime'];
    }
    $priority = normalizePriority((string)($options['priority'] ?? 'normal'));
    if ($priority === null) {
        return ['success' => false, 'error' => 'Invalid priority'];
    }
    $tags = normalizeTags($options['tags'] ?? []);
    $tagsJson = encodeTagsJson($tags);
    $rank = isset($options['rank']) ? (int)$options['rank'] : 0;
    $rrule = normalizeTaskRecurrenceRule($options['recurrence_rule'] ?? null);

    if ($assignedToUserId !== null && $assignedToUserId !== '') {
        $assignedToUserId = (int)$assignedToUserId;
        $assignedUser = getUserById($assignedToUserId);
        if (!$assignedUser || (int)$assignedUser['is_active'] !== 1) {
            return ['success' => false, 'error' => 'Assigned user is invalid or disabled'];
        }
    } else {
        $assignedToUserId = null;
    }

    $db = getDbConnection();
    $stmt = $db->prepare("
        INSERT INTO tasks
        (
            title, body, status, due_at, priority, project, tags_json, rank, recurrence_rule,
            created_by_user_id, assigned_to_user_id, created_at, updated_at
        )
        VALUES
        (
            :title, :body, :status, :due_at, :priority, :project, :tags_json, :rank, :recurrence_rule,
            :cuid, :auid, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
        )
    ");
    $stmt->bindValue(':title', $title, SQLITE3_TEXT);
    $stmt->bindValue(':body', $body, $body === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $stmt->bindValue(':status', $statusSlug, SQLITE3_TEXT);
    $stmt->bindValue(':due_at', $dueAt, $dueAt === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $stmt->bindValue(':priority', $priority, SQLITE3_TEXT);
    $stmt->bindValue(':project', $project, $project === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $stmt->bindValue(':tags_json', $tagsJson, $tagsJson === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $stmt->bindValue(':rank', $rank, SQLITE3_INTEGER);
    $stmt->bindValue(':recurrence_rule', $rrule, $rrule === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $stmt->bindValue(':cuid', $createdByUserId, SQLITE3_INTEGER);
    $stmt->bindValue(':auid', $assignedToUserId, $assignedToUserId === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $stmt->execute();

    $id = (int)$db->lastInsertRowID();
    createAuditLog($createdByUserId, 'task.create', 'task', (string)$id, [
        'status' => $statusSlug,
        'priority' => $priority,
        'assigned_to_user_id' => $assignedToUserId,
    ]);
    return ['success' => true, 'id' => $id];
}

function getTaskById($id, bool $includeRelations = true): ?array {
    $db = getDbConnection();
    $stmt = $db->prepare("
        SELECT
          t.*,
          ts.label AS status_label,
          ts.sort_order AS status_sort_order,
          ts.is_done AS status_is_done,
          cu.username AS created_by_username,
          au.username AS assigned_to_username,
          (SELECT COUNT(*) FROM task_comments tc WHERE tc.task_id = t.id) AS comment_count,
          (SELECT COUNT(*) FROM task_attachments ta WHERE ta.task_id = t.id) AS attachment_count,
          (SELECT COUNT(*) FROM task_watchers tw WHERE tw.task_id = t.id) AS watcher_count
        FROM tasks t
        JOIN users cu ON cu.id = t.created_by_user_id
        LEFT JOIN users au ON au.id = t.assigned_to_user_id
        LEFT JOIN task_statuses ts ON ts.slug = t.status
        WHERE t.id = :id
        LIMIT 1
    ");
    $stmt->bindValue(':id', (int)$id, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $row = $res->fetchArray(SQLITE3_ASSOC) ?: null;
    if (!$row) {
        return null;
    }
    $task = hydrateTaskRow($row);
    if ($includeRelations) {
        $task['comments'] = listTaskComments((int)$task['id'], 200, 0);
        $task['attachments'] = listTaskAttachments((int)$task['id']);
        $task['watchers'] = listTaskWatchers((int)$task['id']);
    }
    return $task;
}

function listTasks($filters = [], bool $withPagination = false) {
    $db = getDbConnection();

    $where = [];
    $joins = [
        'JOIN users cu ON cu.id = t.created_by_user_id',
        'LEFT JOIN users au ON au.id = t.assigned_to_user_id',
        'LEFT JOIN task_statuses ts ON ts.slug = t.status',
    ];
    $params = [];

    if (!empty($filters['status'])) {
        $s = sanitizeStatus((string)$filters['status']);
        if ($s !== null) {
            $where[] = 't.status = :status';
            $params[':status'] = [$s, SQLITE3_TEXT];
        }
    }

    if (isset($filters['priority']) && $filters['priority'] !== '') {
        $p = normalizePriority((string)$filters['priority']);
        if ($p !== null) {
            $where[] = 't.priority = :priority';
            $params[':priority'] = [$p, SQLITE3_TEXT];
        }
    }

    if (isset($filters['project']) && trim((string)$filters['project']) !== '') {
        $where[] = 't.project = :project';
        $params[':project'] = [trim((string)$filters['project']), SQLITE3_TEXT];
    }

    if (array_key_exists('assigned_to_user_id', $filters) && $filters['assigned_to_user_id'] !== null && $filters['assigned_to_user_id'] !== '') {
        $where[] = 't.assigned_to_user_id = :assigned_to_user_id';
        $params[':assigned_to_user_id'] = [(int)$filters['assigned_to_user_id'], SQLITE3_INTEGER];
    }

    if (array_key_exists('created_by_user_id', $filters) && $filters['created_by_user_id'] !== null && $filters['created_by_user_id'] !== '') {
        $where[] = 't.created_by_user_id = :created_by_user_id';
        $params[':created_by_user_id'] = [(int)$filters['created_by_user_id'], SQLITE3_INTEGER];
    }

    if (array_key_exists('watcher_user_id', $filters) && $filters['watcher_user_id'] !== null && $filters['watcher_user_id'] !== '') {
        $joins[] = 'JOIN task_watchers tw_filter ON tw_filter.task_id = t.id';
        $where[] = 'tw_filter.user_id = :watcher_user_id';
        $params[':watcher_user_id'] = [(int)$filters['watcher_user_id'], SQLITE3_INTEGER];
    }

    if (!empty($filters['q'])) {
        $where[] = '(t.title LIKE :q OR IFNULL(t.body, \'\') LIKE :q)';
        $params[':q'] = ['%' . trim((string)$filters['q']) . '%', SQLITE3_TEXT];
    }

    if (!empty($filters['due_before'])) {
        $dueBefore = parseDateTimeOrNull($filters['due_before']);
        if ($dueBefore !== null) {
            $where[] = 't.due_at IS NOT NULL AND t.due_at <= :due_before';
            $params[':due_before'] = [$dueBefore, SQLITE3_TEXT];
        }
    }

    if (!empty($filters['due_after'])) {
        $dueAfter = parseDateTimeOrNull($filters['due_after']);
        if ($dueAfter !== null) {
            $where[] = 't.due_at IS NOT NULL AND t.due_at >= :due_after';
            $params[':due_after'] = [$dueAfter, SQLITE3_TEXT];
        }
    }

    $limit = isset($filters['limit']) ? (int)$filters['limit'] : 100;
    $offset = isset($filters['offset']) ? (int)$filters['offset'] : 0;
    if ($limit <= 0) {
        $limit = 100;
    }
    if ($limit > 500) {
        $limit = 500;
    }
    if ($offset < 0) {
        $offset = 0;
    }

    $sortBy = isset($filters['sort_by']) ? (string)$filters['sort_by'] : 'updated_at';
    $sortDir = isset($filters['sort_dir']) ? (string)$filters['sort_dir'] : 'DESC';
    $orderBy = taskOrderByClause($sortBy, $sortDir);

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $joinsSql = implode("\n", array_unique($joins));

    $selectSql = "
        SELECT
          t.*,
          ts.label AS status_label,
          ts.sort_order AS status_sort_order,
          ts.is_done AS status_is_done,
          cu.username AS created_by_username,
          au.username AS assigned_to_username,
          (SELECT COUNT(*) FROM task_comments tc WHERE tc.task_id = t.id) AS comment_count,
          (SELECT COUNT(*) FROM task_attachments ta WHERE ta.task_id = t.id) AS attachment_count,
          (SELECT COUNT(*) FROM task_watchers tw WHERE tw.task_id = t.id) AS watcher_count
        FROM tasks t
        $joinsSql
        $whereSql
        ORDER BY $orderBy
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $db->prepare($selectSql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v[0], $v[1]);
    }
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
    $res = $stmt->execute();

    $tasks = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $tasks[] = hydrateTaskRow($row);
    }

    if (!$withPagination) {
        return $tasks;
    }

    $countSql = "
        SELECT COUNT(*) AS total_count
        FROM tasks t
        $joinsSql
        $whereSql
    ";
    $countStmt = $db->prepare($countSql);
    foreach ($params as $k => $v) {
        $countStmt->bindValue($k, $v[0], $v[1]);
    }
    $countRes = $countStmt->execute();
    $countRow = $countRes->fetchArray(SQLITE3_ASSOC) ?: ['total_count' => 0];
    $total = (int)$countRow['total_count'];

    return [
        'tasks' => $tasks,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
    ];
}

function updateTask($id, $fields = []): array {
    $id = (int)$id;
    if ($id <= 0) {
        return ['success' => false, 'error' => 'Invalid id'];
    }

    if (!getTaskById($id, false)) {
        return ['success' => false, 'error' => 'Task not found'];
    }

    $sets = [];
    $params = [':id' => [$id, SQLITE3_INTEGER]];

    if (array_key_exists('title', $fields)) {
        $title = normalizeTaskTitle($fields['title']);
        if ($title === null) {
            return ['success' => false, 'error' => 'Title cannot be empty'];
        }
        $sets[] = 'title = :title';
        $params[':title'] = [$title, SQLITE3_TEXT];
    }

    if (array_key_exists('status', $fields)) {
        $status = sanitizeStatus((string)$fields['status']);
        if ($status === null) {
            return ['success' => false, 'error' => 'Invalid status'];
        }
        $sets[] = 'status = :status';
        $params[':status'] = [$status, SQLITE3_TEXT];
    }

    if (array_key_exists('assigned_to_user_id', $fields)) {
        if ($fields['assigned_to_user_id'] === null || $fields['assigned_to_user_id'] === '') {
            $sets[] = 'assigned_to_user_id = :assigned_to_user_id';
            $params[':assigned_to_user_id'] = [null, SQLITE3_NULL];
        } else {
            $auid = (int)$fields['assigned_to_user_id'];
            $assignedUser = getUserById($auid);
            if (!$assignedUser || (int)$assignedUser['is_active'] !== 1) {
                return ['success' => false, 'error' => 'Assigned user is invalid or disabled'];
            }
            $sets[] = 'assigned_to_user_id = :assigned_to_user_id';
            $params[':assigned_to_user_id'] = [$auid, SQLITE3_INTEGER];
        }
    }

    if (array_key_exists('body', $fields)) {
        $body = normalizeTaskBody($fields['body']);
        $sets[] = 'body = :body';
        $params[':body'] = [$body, $body === null ? SQLITE3_NULL : SQLITE3_TEXT];
    }

    if (array_key_exists('due_at', $fields)) {
        $dueAt = parseDateTimeOrNull($fields['due_at']);
        if ($fields['due_at'] !== null && $fields['due_at'] !== '' && $dueAt === null) {
            return ['success' => false, 'error' => 'Invalid due_at datetime'];
        }
        $sets[] = 'due_at = :due_at';
        $params[':due_at'] = [$dueAt, $dueAt === null ? SQLITE3_NULL : SQLITE3_TEXT];
    }

    if (array_key_exists('priority', $fields)) {
        $priority = normalizePriority((string)$fields['priority']);
        if ($priority === null) {
            return ['success' => false, 'error' => 'Invalid priority'];
        }
        $sets[] = 'priority = :priority';
        $params[':priority'] = [$priority, SQLITE3_TEXT];
    }

    if (array_key_exists('project', $fields)) {
        $project = normalizeTaskProject($fields['project']);
        $sets[] = 'project = :project';
        $params[':project'] = [$project, $project === null ? SQLITE3_NULL : SQLITE3_TEXT];
    }

    if (array_key_exists('tags', $fields)) {
        $tagsJson = encodeTagsJson(normalizeTags($fields['tags']));
        $sets[] = 'tags_json = :tags_json';
        $params[':tags_json'] = [$tagsJson, $tagsJson === null ? SQLITE3_NULL : SQLITE3_TEXT];
    }

    if (array_key_exists('rank', $fields)) {
        $sets[] = 'rank = :rank';
        $params[':rank'] = [(int)$fields['rank'], SQLITE3_INTEGER];
    }

    if (array_key_exists('recurrence_rule', $fields)) {
        $rrule = normalizeTaskRecurrenceRule($fields['recurrence_rule']);
        $sets[] = 'recurrence_rule = :recurrence_rule';
        $params[':recurrence_rule'] = [$rrule, $rrule === null ? SQLITE3_NULL : SQLITE3_TEXT];
    }

    if (!$sets) {
        return ['success' => false, 'error' => 'No fields to update'];
    }

    $sets[] = 'updated_at = CURRENT_TIMESTAMP';
    $sql = 'UPDATE tasks SET ' . implode(', ', $sets) . ' WHERE id = :id';

    $db = getDbConnection();
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v[0], $v[1]);
    }
    $stmt->execute();
    createAuditLog(null, 'task.update', 'task', (string)$id, ['updated_fields' => array_keys($fields)]);

    return ['success' => true];
}

function deleteTask($id): array {
    $id = (int)$id;
    if ($id <= 0) {
        return ['success' => false, 'error' => 'Invalid id'];
    }
    $db = getDbConnection();
    $stmt = $db->prepare("DELETE FROM tasks WHERE id = :id");
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();
    createAuditLog(null, 'task.delete', 'task', (string)$id);
    return ['success' => true];
}

function listProjects(int $limit = 100): array {
    $limit = max(1, min(1000, $limit));
    $db = getDbConnection();
    $stmt = $db->prepare("
        SELECT project, COUNT(*) AS task_count
        FROM tasks
        WHERE project IS NOT NULL AND TRIM(project) <> ''
        GROUP BY project
        ORDER BY project ASC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $items = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $items[] = [
            'name' => $row['project'],
            'task_count' => (int)$row['task_count'],
        ];
    }
    return $items;
}

function listTags(int $limit = 200): array {
    $limit = max(1, min(2000, $limit));
    $db = getDbConnection();
    $res = $db->query("SELECT tags_json FROM tasks WHERE tags_json IS NOT NULL AND TRIM(tags_json) <> ''");
    $counts = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        foreach (decodeTagsJson($row['tags_json']) as $tag) {
            $k = strtolower($tag);
            if (!isset($counts[$k])) {
                $counts[$k] = ['name' => $tag, 'task_count' => 0];
            }
            $counts[$k]['task_count']++;
        }
    }
    usort($counts, function ($a, $b) {
        return $b['task_count'] <=> $a['task_count'];
    });
    return array_slice(array_values($counts), 0, $limit);
}

// --------------------
// Collaboration: comments / attachments / watchers
// --------------------
function listTaskComments(int $taskId, int $limit = 100, int $offset = 0): array {
    $limit = max(1, min(500, $limit));
    $offset = max(0, $offset);
    $db = getDbConnection();
    $stmt = $db->prepare("
        SELECT c.id, c.task_id, c.user_id, c.comment, c.created_at, u.username
        FROM task_comments c
        JOIN users u ON u.id = c.user_id
        WHERE c.task_id = :task_id
        ORDER BY c.id ASC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':task_id', $taskId, SQLITE3_INTEGER);
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $rows = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    return $rows;
}

function addTaskComment(int $taskId, int $userId, string $comment): array {
    $comment = trim($comment);
    if ($comment === '') {
        return ['success' => false, 'error' => 'Comment is required'];
    }
    $comment = truncateString($comment, 2000);
    if (!getTaskById($taskId, false)) {
        return ['success' => false, 'error' => 'Task not found'];
    }
    if (!getUserById($userId)) {
        return ['success' => false, 'error' => 'User not found'];
    }

    $db = getDbConnection();
    $stmt = $db->prepare("
        INSERT INTO task_comments (task_id, user_id, comment, created_at)
        VALUES (:task_id, :user_id, :comment, CURRENT_TIMESTAMP)
    ");
    $stmt->bindValue(':task_id', $taskId, SQLITE3_INTEGER);
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':comment', $comment, SQLITE3_TEXT);
    $stmt->execute();

    $upd = $db->prepare("UPDATE tasks SET updated_at = CURRENT_TIMESTAMP WHERE id = :id");
    $upd->bindValue(':id', $taskId, SQLITE3_INTEGER);
    $upd->execute();

    $id = (int)$db->lastInsertRowID();
    createAuditLog($userId, 'task.comment_add', 'task_comment', (string)$id, ['task_id' => $taskId]);
    return ['success' => true, 'id' => $id];
}

function listTaskAttachments(int $taskId): array {
    $db = getDbConnection();
    $stmt = $db->prepare("
        SELECT a.id, a.task_id, a.uploaded_by_user_id, u.username AS uploaded_by_username,
               a.file_name, a.file_url, a.mime_type, a.size_bytes, a.created_at
        FROM task_attachments a
        JOIN users u ON u.id = a.uploaded_by_user_id
        WHERE a.task_id = :task_id
        ORDER BY a.id ASC
    ");
    $stmt->bindValue(':task_id', $taskId, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $rows = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    return $rows;
}

function addTaskAttachment(int $taskId, int $uploadedByUserId, string $fileName, string $fileUrl, ?string $mimeType = null, ?int $sizeBytes = null): array {
    $fileName = trim($fileName);
    $fileUrl = trim($fileUrl);
    if ($fileName === '' || $fileUrl === '') {
        return ['success' => false, 'error' => 'file_name and file_url are required'];
    }
    if (!filter_var($fileUrl, FILTER_VALIDATE_URL)) {
        return ['success' => false, 'error' => 'file_url must be a valid URL'];
    }
    if (!getTaskById($taskId, false)) {
        return ['success' => false, 'error' => 'Task not found'];
    }
    if (!getUserById($uploadedByUserId)) {
        return ['success' => false, 'error' => 'User not found'];
    }

    $db = getDbConnection();
    $stmt = $db->prepare("
        INSERT INTO task_attachments (task_id, uploaded_by_user_id, file_name, file_url, mime_type, size_bytes)
        VALUES (:task_id, :uploaded_by_user_id, :file_name, :file_url, :mime_type, :size_bytes)
    ");
    $stmt->bindValue(':task_id', $taskId, SQLITE3_INTEGER);
    $stmt->bindValue(':uploaded_by_user_id', $uploadedByUserId, SQLITE3_INTEGER);
    $stmt->bindValue(':file_name', truncateString($fileName, 255), SQLITE3_TEXT);
    $stmt->bindValue(':file_url', truncateString($fileUrl, 1000), SQLITE3_TEXT);
    $normalizedMime = normalizeNullableText($mimeType, 120);
    $stmt->bindValue(':mime_type', $normalizedMime, $normalizedMime === null ? SQLITE3_NULL : SQLITE3_TEXT);
    if ($sizeBytes === null) {
        $stmt->bindValue(':size_bytes', null, SQLITE3_NULL);
    } else {
        $stmt->bindValue(':size_bytes', max(0, $sizeBytes), SQLITE3_INTEGER);
    }
    $stmt->execute();

    $upd = $db->prepare("UPDATE tasks SET updated_at = CURRENT_TIMESTAMP WHERE id = :id");
    $upd->bindValue(':id', $taskId, SQLITE3_INTEGER);
    $upd->execute();

    $id = (int)$db->lastInsertRowID();
    createAuditLog($uploadedByUserId, 'task.attachment_add', 'task_attachment', (string)$id, ['task_id' => $taskId]);
    return ['success' => true, 'id' => $id];
}

function listTaskWatchers(int $taskId): array {
    $db = getDbConnection();
    $stmt = $db->prepare("
        SELECT w.task_id, w.user_id, w.created_at, u.username
        FROM task_watchers w
        JOIN users u ON u.id = w.user_id
        WHERE w.task_id = :task_id
        ORDER BY u.username ASC
    ");
    $stmt->bindValue(':task_id', $taskId, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $rows = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    return $rows;
}

function addTaskWatcher(int $taskId, int $userId): array {
    if (!getTaskById($taskId, false)) {
        return ['success' => false, 'error' => 'Task not found'];
    }
    $user = getUserById($userId);
    if (!$user || (int)$user['is_active'] !== 1) {
        return ['success' => false, 'error' => 'User not found or inactive'];
    }

    $db = getDbConnection();
    $stmt = $db->prepare("INSERT OR IGNORE INTO task_watchers (task_id, user_id) VALUES (:task_id, :user_id)");
    $stmt->bindValue(':task_id', $taskId, SQLITE3_INTEGER);
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->execute();

    createAuditLog($userId, 'task.watch_add', 'task', (string)$taskId, ['watcher_user_id' => $userId]);
    return ['success' => true];
}

function removeTaskWatcher(int $taskId, int $userId): array {
    $db = getDbConnection();
    $stmt = $db->prepare("DELETE FROM task_watchers WHERE task_id = :task_id AND user_id = :user_id");
    $stmt->bindValue(':task_id', $taskId, SQLITE3_INTEGER);
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->execute();
    createAuditLog($userId, 'task.watch_remove', 'task', (string)$taskId, ['watcher_user_id' => $userId]);
    return ['success' => true];
}

// --------------------
// Bulk operations
// --------------------
function bulkCreateTasks(array $items, int $createdByUserId): array {
    $results = [];
    $successCount = 0;
    $failureCount = 0;
    foreach ($items as $idx => $item) {
        if (!is_array($item)) {
            $results[] = ['index' => $idx, 'success' => false, 'error' => 'Item must be an object'];
            $failureCount++;
            continue;
        }
        $res = createTask(
            $item['title'] ?? '',
            $item['status'] ?? null,
            $createdByUserId,
            $item['assigned_to_user_id'] ?? null,
            $item['body'] ?? null,
            [
                'due_at' => $item['due_at'] ?? null,
                'priority' => $item['priority'] ?? 'normal',
                'project' => $item['project'] ?? null,
                'tags' => $item['tags'] ?? [],
                'rank' => $item['rank'] ?? 0,
                'recurrence_rule' => $item['recurrence_rule'] ?? null,
            ]
        );
        $results[] = ['index' => $idx] + $res;
        if (!empty($res['success'])) {
            $successCount++;
        } else {
            $failureCount++;
        }
    }
    return [
        'success' => $failureCount === 0,
        'created' => $successCount,
        'failed' => $failureCount,
        'results' => $results,
    ];
}

function bulkUpdateTasks(array $items): array {
    $results = [];
    $successCount = 0;
    $failureCount = 0;
    foreach ($items as $idx => $item) {
        if (!is_array($item)) {
            $results[] = ['index' => $idx, 'success' => false, 'error' => 'Item must be an object'];
            $failureCount++;
            continue;
        }
        $id = isset($item['id']) ? (int)$item['id'] : 0;
        if ($id <= 0) {
            $results[] = ['index' => $idx, 'success' => false, 'error' => 'Missing id'];
            $failureCount++;
            continue;
        }
        $fields = [];
        foreach (['title', 'status', 'assigned_to_user_id', 'body', 'due_at', 'priority', 'project', 'tags', 'rank', 'recurrence_rule'] as $field) {
            if (array_key_exists($field, $item)) {
                $fields[$field] = $item[$field];
            }
        }
        $res = updateTask($id, $fields);
        $results[] = ['index' => $idx, 'id' => $id] + $res;
        if (!empty($res['success'])) {
            $successCount++;
        } else {
            $failureCount++;
        }
    }

    return [
        'success' => $failureCount === 0,
        'updated' => $successCount,
        'failed' => $failureCount,
        'results' => $results,
    ];
}

