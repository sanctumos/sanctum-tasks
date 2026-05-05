<?php
require_once __DIR__ . '/config.php';

// Ensure DB is initialized when helpers are loaded
initializeDatabase();

function nowUtc(): string {
    return gmdate('Y-m-d H:i:s');
}

function requestIpAddress(): string {
    $remoteAddr = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remoteAddr === '') {
        return 'unknown';
    }

    if (!TRUST_PROXY) {
        return $remoteAddr;
    }
    $trustedList = array_map('trim', array_filter(explode(',', (string)TRUSTED_PROXY_IPS)));
    if ($trustedList === [] || !in_array($remoteAddr, $trustedList, true)) {
        return $remoteAddr;
    }

    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'];
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
    return $remoteAddr;
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
    $r = strtolower(trim($role));
    return in_array($r, ['admin', 'manager'], true);
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
    $db->exec('BEGIN');
    try {
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
        $stmt->execute();
        $db->exec('COMMIT');
        createAuditLog(null, 'task_status.create', 'task_status', $slug, ['label' => $label]);
        return ['success' => true, 'slug' => $slug];
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        return ['success' => false, 'error' => 'Status slug already exists'];
    }
}

// --------------------
// Users
// --------------------
function normalizePersonKind(?string $raw): string {
    $s = strtolower(trim((string)$raw));
    return $s === 'client' ? 'client' : 'team_member';
}

function getDefaultOrganizationId(): ?int {
    $db = getDbConnection();
    $id = $db->querySingle('SELECT id FROM organizations ORDER BY id ASC LIMIT 1');
    return $id !== null && $id !== false ? (int)$id : null;
}

/**
 * Whether this user may belong to multiple organizations (admin + manager — shared staff).
 */
function userQualifiesForMultiOrganizationMemberships(array $userRow): bool {
    return isAdminRole((string)($userRow['role'] ?? ''));
}

function ensureUserOrganizationMembershipRow(int $userId, int $orgId): void {
    if ($userId <= 0 || $orgId <= 0) {
        return;
    }
    $db = getDbConnection();
    $stmt = $db->prepare('INSERT OR IGNORE INTO user_organization_memberships (user_id, org_id) VALUES (:u, :o)');
    $stmt->bindValue(':u', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':o', $orgId, SQLITE3_INTEGER);
    $stmt->execute();
}

/** Replace membership rows for admin/manager users (primary org should be included in $orgIds). */
function replaceStaffOrganizationMemberships(int $actorUserId, int $targetUserId, array $orgIds): array {
    if ($targetUserId <= 0) {
        return ['success' => false, 'error' => 'Invalid user'];
    }
    $tgt = getUserById($targetUserId, false);
    if (!$tgt || !userQualifiesForMultiOrganizationMemberships($tgt)) {
        return ['success' => false, 'error' => 'Multi-organization access applies to admin and manager roles only'];
    }
    $clean = [];
    foreach ($orgIds as $oid) {
        $oid = (int)$oid;
        if ($oid > 0 && getOrganizationById($oid)) {
            $clean[$oid] = true;
        }
    }
    $clean = array_keys($clean);
    sort($clean);
    if ($clean === []) {
        return ['success' => false, 'error' => 'At least one organization is required'];
    }
    $db = getDbConnection();
    $db->exec('BEGIN');
    try {
        $del = $db->prepare('DELETE FROM user_organization_memberships WHERE user_id = :u');
        $del->bindValue(':u', $targetUserId, SQLITE3_INTEGER);
        $del->execute();
        $ins = $db->prepare('INSERT INTO user_organization_memberships (user_id, org_id) VALUES (:u, :o)');
        foreach ($clean as $oid) {
            $ins->bindValue(':u', $targetUserId, SQLITE3_INTEGER);
            $ins->bindValue(':o', $oid, SQLITE3_INTEGER);
            $ins->execute();
        }
        $db->exec('COMMIT');
        createAuditLog($actorUserId, 'user.organization_memberships_set', 'user', (string)$targetUserId, ['org_ids' => $clean]);
        return ['success' => true];
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        return ['success' => false, 'error' => 'Could not update organization memberships'];
    }
}

function listOrganizationMembershipIdsForUser(int $userId): array {
    if ($userId <= 0) {
        return [];
    }
    $db = getDbConnection();
    $stmt = $db->prepare('SELECT org_id FROM user_organization_memberships WHERE user_id = :u ORDER BY org_id ASC');
    $stmt->bindValue(':u', $userId, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $out = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $out[] = (int)$row['org_id'];
    }
    return $out;
}

/**
 * Organizations this user may access in the directory (single org for members; many for admin/manager).
 *
 * **Admin** always receives every organization id so workspace/project counts and listings are global.
 * (Membership rows alone can omit orgs where projects exist — that hid directory projects from admins.)
 *
 * @return int[]
 */
function listOrganizationIdsForUserAccess(array $userRow): array {
    if (strtolower(trim((string)($userRow['role'] ?? ''))) === 'admin') {
        $db = getDbConnection();
        if (!tableExists($db, 'organizations')) {
            return [];
        }
        $res = $db->query('SELECT id FROM organizations ORDER BY id ASC');
        $all = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $all[] = (int)$row['id'];
        }
        return $all;
    }

    $primary = isset($userRow['org_id']) ? (int)$userRow['org_id'] : 0;
    if (!userQualifiesForMultiOrganizationMemberships($userRow)) {
        return $primary > 0 ? [$primary] : [];
    }
    $uid = (int)($userRow['id'] ?? 0);
    $ids = listOrganizationMembershipIdsForUser($uid);
    if ($primary > 0) {
        $found = false;
        foreach ($ids as $x) {
            if ($x === $primary) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $ids[] = $primary;
            sort($ids);
        }
    }
    if ($ids === [] && $primary > 0) {
        return [$primary];
    }
    return $ids;
}

function userMayAccessOrganization(array $userRow, int $orgId): bool {
    if ($orgId <= 0) {
        return false;
    }
    if (strtolower(trim((string)($userRow['role'] ?? ''))) === 'admin' && getOrganizationById($orgId)) {
        return true;
    }
    foreach (listOrganizationIdsForUserAccess($userRow) as $oid) {
        if ($oid === $orgId) {
            return true;
        }
    }
    return false;
}

/**
 * Organization used when creating a directory project (session override for multi-org staff).
 */
function getEffectiveDirectoryOrgId(array $userRow): int {
    $primary = isset($userRow['org_id']) ? (int)$userRow['org_id'] : 0;
    if (!userQualifiesForMultiOrganizationMemberships($userRow)) {
        return $primary;
    }
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['active_org_id'])) {
        $sid = (int)$_SESSION['active_org_id'];
        if ($sid > 0 && userMayAccessOrganization($userRow, $sid)) {
            return $sid;
        }
    }
    return $primary;
}

function getUserById($id, bool $includeSensitive = false): ?array {
    $db = getDbConnection();
    $sql = $includeSensitive
        ? "SELECT id, username, role, is_active, must_change_password, mfa_enabled, mfa_secret, password_hash, org_id, person_kind, limited_project_access, created_at FROM users WHERE id = :id LIMIT 1"
        : "SELECT id, username, role, is_active, must_change_password, mfa_enabled, org_id, person_kind, limited_project_access, created_at FROM users WHERE id = :id LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':id', (int)$id, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $row = $res->fetchArray(SQLITE3_ASSOC) ?: null;
    if ($row) {
        $row['is_active'] = (int)$row['is_active'];
        $row['must_change_password'] = (int)$row['must_change_password'];
        $row['mfa_enabled'] = (int)$row['mfa_enabled'];
        if (isset($row['org_id']) && $row['org_id'] !== null) {
            $row['org_id'] = (int)$row['org_id'];
        }
        $row['role'] = normalizeRole((string)($row['role'] ?? 'member')) ?? 'member';
        $row['person_kind'] = normalizePersonKind($row['person_kind'] ?? 'team_member');
        $row['limited_project_access'] = (int)($row['limited_project_access'] ?? 0);
    }
    return $row;
}

function getUserByUsername($username, bool $includeSensitive = false): ?array {
    $db = getDbConnection();
    $sql = $includeSensitive
        ? "SELECT id, username, role, is_active, must_change_password, mfa_enabled, mfa_secret, password_hash, org_id, person_kind, limited_project_access, created_at FROM users WHERE username = :u LIMIT 1"
        : "SELECT id, username, role, is_active, must_change_password, mfa_enabled, org_id, person_kind, limited_project_access, created_at FROM users WHERE username = :u LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':u', normalizeUsername((string)$username), SQLITE3_TEXT);
    $res = $stmt->execute();
    $row = $res->fetchArray(SQLITE3_ASSOC) ?: null;
    if ($row) {
        $row['is_active'] = (int)$row['is_active'];
        $row['must_change_password'] = (int)$row['must_change_password'];
        $row['mfa_enabled'] = (int)$row['mfa_enabled'];
        if (isset($row['org_id']) && $row['org_id'] !== null) {
            $row['org_id'] = (int)$row['org_id'];
        }
        $row['role'] = normalizeRole((string)($row['role'] ?? 'member')) ?? 'member';
        $row['person_kind'] = normalizePersonKind($row['person_kind'] ?? 'team_member');
        $row['limited_project_access'] = (int)($row['limited_project_access'] ?? 0);
    }
    return $row;
}

function listUsers(bool $includeDisabled = false): array {
    $db = getDbConnection();
    $sql = "
        SELECT u.id, u.username, u.role, u.is_active, u.must_change_password, u.mfa_enabled, u.org_id, u.person_kind, u.limited_project_access, u.created_at,
               o.name AS org_name
        FROM users u
        LEFT JOIN organizations o ON o.id = u.org_id
        " . ($includeDisabled ? "" : "WHERE u.is_active = 1") . "
        ORDER BY u.username ASC
    ";
    $res = $db->query($sql);
    $users = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $row['is_active'] = (int)$row['is_active'];
        $row['must_change_password'] = (int)$row['must_change_password'];
        $row['mfa_enabled'] = (int)$row['mfa_enabled'];
        if (isset($row['org_id']) && $row['org_id'] !== null) {
            $row['org_id'] = (int)$row['org_id'];
        }
        $row['role'] = normalizeRole((string)($row['role'] ?? 'member')) ?? 'member';
        $row['person_kind'] = normalizePersonKind($row['person_kind'] ?? 'team_member');
        $row['limited_project_access'] = (int)($row['limited_project_access'] ?? 0);
        $users[] = $row;
    }
    return $users;
}

function createUser(string $username, string $password, string $role = 'member', bool $mustChangePassword = true, ?int $orgId = null, string $personKind = 'team_member', bool $limitedProjectAccess = false): array {
    $db0 = getDbConnection();
    ensureDefaultOrganizationAndUsers($db0);

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

    $oid = $orgId ?? getDefaultOrganizationId();
    if ($oid === null || $oid <= 0) {
        return ['success' => false, 'error' => 'No organization configured'];
    }
    $pk = normalizePersonKind($personKind);

    $db = getDbConnection();
    $stmt = $db->prepare("
        INSERT INTO users (username, password_hash, role, is_active, must_change_password, org_id, person_kind, limited_project_access)
        VALUES (:username, :hash, :role, 1, :must_change_password, :org_id, :person_kind, :limited_project_access)
    ");
    $stmt->bindValue(':username', normalizeUsername($username), SQLITE3_TEXT);
    $stmt->bindValue(':hash', password_hash($password, PASSWORD_BCRYPT, ['cost' => PASSWORD_COST]), SQLITE3_TEXT);
    $stmt->bindValue(':role', $normalizedRole, SQLITE3_TEXT);
    $stmt->bindValue(':must_change_password', $mustChangePassword ? 1 : 0, SQLITE3_INTEGER);
    $stmt->bindValue(':org_id', $oid, SQLITE3_INTEGER);
    $stmt->bindValue(':person_kind', $pk, SQLITE3_TEXT);
    $stmt->bindValue(':limited_project_access', $limitedProjectAccess ? 1 : 0, SQLITE3_INTEGER);

    try {
        $stmt->execute();
        $id = (int)$db->lastInsertRowID();
        createAuditLog(null, 'user.create', 'user', (string)$id, ['username' => normalizeUsername($username), 'role' => $normalizedRole]);
        if ($oid !== null && $oid > 0 && userQualifiesForMultiOrganizationMemberships(['role' => $normalizedRole])) {
            ensureUserOrganizationMembershipRow($id, $oid);
        }
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
    if ($db->changes() === 0) {
        return ['success' => false, 'error' => 'User not found'];
    }
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
    if ($db->changes() === 0) {
        return ['success' => false, 'error' => 'User not found'];
    }
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

/** Encrypt MFA secret for storage (C-02). Returns "enc:" . base64(iv . tag . ciphertext). */
function encryptMfaSecret(string $plaintext): string {
    $cipher = 'aes-256-gcm';
    $key = getMfaEncryptionKey();
    $ivLen = openssl_cipher_iv_length($cipher);
    $iv = random_bytes($ivLen);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ciphertext === false || $tag === '') {
        return '';
    }
    return 'enc:' . base64_encode($iv . $tag . $ciphertext);
}

/** Decrypt MFA secret from storage. Returns plaintext or original value if not encrypted. */
function decryptMfaSecret(string $stored): string {
    if (strpos($stored, 'enc:') !== 0) {
        return $stored;
    }
    $raw = base64_decode(substr($stored, 4), true);
    $cipher = 'aes-256-gcm';
    $ivLen = openssl_cipher_iv_length($cipher);
    if ($raw === false || strlen($raw) < $ivLen + 16) {
        return '';
    }
    $key = getMfaEncryptionKey();
    $iv = substr($raw, 0, $ivLen);
    $tag = substr($raw, $ivLen, 16);
    $ciphertext = substr($raw, $ivLen + 16);
    $decrypted = @openssl_decrypt($ciphertext, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $decrypted !== false ? $decrypted : '';
}

function enableUserMfa(int $userId, string $secret): array {
    if ($userId <= 0 || trim($secret) === '') {
        return ['success' => false, 'error' => 'Invalid MFA setup'];
    }
    $db = getDbConnection();
    $encrypted = encryptMfaSecret(strtoupper(trim($secret)));
    $stmt = $db->prepare("UPDATE users SET mfa_secret = :secret, mfa_enabled = 1 WHERE id = :id");
    $stmt->bindValue(':secret', $encrypted, SQLITE3_TEXT);
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
          AND username = :username AND ip_address = :ip
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
    $stmt = $db->prepare("DELETE FROM login_attempts WHERE username = :username AND ip_address = :ip");
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
    $keyHash = hash('sha256', $apiKey);
    $keyPreview = substr($apiKey, 0, 12);

    $stmt = $db->prepare("
        INSERT INTO api_keys (user_id, key_name, api_key, api_key_hash, key_preview, created_by_user_id)
        VALUES (:uid, :name, :key, :hash, :preview, :created_by)
    ");
    $stmt->bindValue(':uid', (int)$userId, SQLITE3_INTEGER);
    $stmt->bindValue(':name', truncateString(trim((string)$keyName) ?: 'Unnamed Key', 80), SQLITE3_TEXT);
    $stmt->bindValue(':key', $keyHash, SQLITE3_TEXT);
    $stmt->bindValue(':hash', $keyHash, SQLITE3_TEXT);
    $stmt->bindValue(':preview', $keyPreview, SQLITE3_TEXT);
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
    $keyHash = hash('sha256', $apiKey);
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
        WHERE ak.api_key_hash = :hash
          AND ak.revoked_at IS NULL
        LIMIT 1
    ");
    $stmt->bindValue(':hash', $keyHash, SQLITE3_TEXT);
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

    $full = getUserById((int)$row['user_id'], false);
    if (!$full || (int)$full['is_active'] !== 1) {
        return null;
    }
    $full['api_key_id'] = (int)$row['api_key_id'];
    return $full;
}

function getAllApiKeys(bool $includeRevoked = false): array {
    $db = getDbConnection();
    $sql = "
        SELECT
            ak.id,
            ak.user_id,
            ak.key_name,
            COALESCE(ak.key_preview, SUBSTR(ak.api_key, 1, 12)) AS api_key_preview,
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
        SELECT id, user_id, key_name, COALESCE(key_preview, SUBSTR(api_key, 1, 12)) AS api_key_preview, created_at, last_used, revoked_at
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
    if ($db->changes() === 0) {
        return false;
    }
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
    if (array_key_exists('project_id', $row)) {
        $row['project_id'] = $row['project_id'] !== null && $row['project_id'] !== '' ? (int)$row['project_id'] : null;
    }
    if (array_key_exists('list_id', $row)) {
        $row['list_id'] = $row['list_id'] !== null && $row['list_id'] !== '' ? (int)$row['list_id'] : null;
    } else {
        $row['list_id'] = null;
    }
    if (isset($row['directory_project_name']) && $row['directory_project_name'] !== null && (string)$row['directory_project_name'] !== '') {
        $row['directory_project'] = [
            'id' => $row['project_id'],
            'name' => (string)$row['directory_project_name'],
        ];
    } else {
        $row['directory_project'] = null;
    }
    if (array_key_exists('directory_project_name', $row)) {
        unset($row['directory_project_name']);
    }
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
    $creatorUser = getUserById($createdByUserId, false);
    $project = normalizeTaskProject($options['project'] ?? null);
    $projectFk = null;
    if (array_key_exists('project_id', $options) && $options['project_id'] !== null && $options['project_id'] !== '') {
        if (!$creatorUser) {
            return ['success' => false, 'error' => 'Invalid creator user'];
        }
        $resolved = resolveTaskDirectoryProjectId($creatorUser, $options['project_id'], false);
        if (!$resolved['success']) {
            return ['success' => false, 'error' => $resolved['error'] ?? 'Invalid project_id'];
        }
        $projectFk = $resolved['project_id'];
        if ($resolved['project'] !== null && $resolved['project'] !== '') {
            $project = $resolved['project'];
        }
    }
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

    $listIdOpt = isset($options['list_id']) ? (int)$options['list_id'] : 0;
    if ($listIdOpt > 0) {
        if (!$creatorUser) {
            return ['success' => false, 'error' => 'Invalid creator user'];
        }
        $dbPre = getDbConnection();
        $ls = $dbPre->prepare('SELECT project_id FROM todo_lists WHERE id = :i LIMIT 1');
        $ls->bindValue(':i', $listIdOpt, SQLITE3_INTEGER);
        $lr = $ls->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$lr) {
            return ['success' => false, 'error' => 'Invalid list_id'];
        }
        $lpid = (int)$lr['project_id'];
        $pRow = getDirectoryProjectById($lpid);
        if (!$pRow || !userCanAccessDirectoryProject($creatorUser, $pRow)) {
            return ['success' => false, 'error' => 'Invalid list_id'];
        }
        if ($projectFk !== null && $projectFk !== $lpid) {
            return ['success' => false, 'error' => 'list_id does not belong to the selected project'];
        }
        if ($projectFk === null) {
            $projectFk = $lpid;
            $project = (string)$pRow['name'];
        }
    }

    if ($projectFk === null || (int)$projectFk <= 0) {
        return ['success' => false, 'error' => 'A directory project is required. Provide project_id or list_id for a list inside a project.'];
    }

    $db = getDbConnection();
    $stmt = $db->prepare("
        INSERT INTO tasks
        (
            title, body, status, due_at, priority, project, project_id, list_id, tags_json, rank, recurrence_rule,
            created_by_user_id, assigned_to_user_id, created_at, updated_at
        )
        VALUES
        (
            :title, :body, :status, :due_at, :priority, :project, :project_id, :list_id, :tags_json, :rank, :recurrence_rule,
            :cuid, :auid, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
        )
    ");
    $stmt->bindValue(':title', $title, SQLITE3_TEXT);
    $stmt->bindValue(':body', $body, $body === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $stmt->bindValue(':status', $statusSlug, SQLITE3_TEXT);
    $stmt->bindValue(':due_at', $dueAt, $dueAt === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $stmt->bindValue(':priority', $priority, SQLITE3_TEXT);
    $stmt->bindValue(':project', $project, $project === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $stmt->bindValue(':project_id', $projectFk, $projectFk === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    if ($listIdOpt > 0) {
        $stmt->bindValue(':list_id', $listIdOpt, SQLITE3_INTEGER);
    } else {
        $stmt->bindValue(':list_id', null, SQLITE3_NULL);
    }
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

function taskUserIsWatcher(int $taskId, int $userId): bool {
    if ($taskId <= 0 || $userId <= 0) {
        return false;
    }
    $db = getDbConnection();
    $stmt = $db->prepare('SELECT 1 FROM task_watchers WHERE task_id = :t AND user_id = :u LIMIT 1');
    $stmt->bindValue(':t', $taskId, SQLITE3_INTEGER);
    $stmt->bindValue(':u', $userId, SQLITE3_INTEGER);
    $res = $stmt->execute();
    return (bool)$res->fetchArray(SQLITE3_ASSOC);
}

/** Task read/delete side: unrestricted staff sees all tasks; others via linked workspace project, or creator/assignee/watcher when unlinked. */
function userCanAccessTaskForViewer(array $viewerRow, array $task): bool {
    if (userHasUnrestrictedOrgDirectoryAccess($viewerRow)) {
        return true;
    }
    $uid = (int)$viewerRow['id'];
    $pid = isset($task['project_id']) ? (int)$task['project_id'] : 0;
    if ($pid > 0) {
        $proj = getDirectoryProjectById($pid);
        if ($proj && userCanAccessDirectoryProject($viewerRow, $proj)) {
            return true;
        }
    }
    if ((int)($task['created_by_user_id'] ?? 0) === $uid) {
        return true;
    }
    if ((int)($task['assigned_to_user_id'] ?? 0) === $uid) {
        return true;
    }
    return taskUserIsWatcher((int)$task['id'], $uid);
}

/** C-03: Whether the caller may read/update/delete this task (API + PHP). Loads user row — pass full row to userCanAccessTaskForViewer when available. */
function userCanAccessTask(int $userId, array $task, string $role): bool {
    unset($role);
    $viewer = getUserById($userId, false);
    if (!$viewer) {
        return false;
    }
    return userCanAccessTaskForViewer($viewer, $task);
}

function getTaskById($id, bool $includeRelations = true): ?array {
    $db = getDbConnection();
    $stmt = $db->prepare("
        SELECT
          t.*,
          dp.name AS directory_project_name,
          ts.label AS status_label,
          ts.sort_order AS status_sort_order,
          ts.is_done AS status_is_done,
          cu.username AS created_by_username,
          au.username AS assigned_to_username,
          COALESCE(tc_counts.comment_count, 0) AS comment_count,
          COALESCE(ta_counts.attachment_count, 0) AS attachment_count,
          COALESCE(tw_counts.watcher_count, 0) AS watcher_count
        FROM tasks t
        JOIN users cu ON cu.id = t.created_by_user_id
        LEFT JOIN users au ON au.id = t.assigned_to_user_id
        LEFT JOIN projects dp ON dp.id = t.project_id
        LEFT JOIN task_statuses ts ON ts.slug = t.status
        LEFT JOIN (
            SELECT task_id, COUNT(*) AS comment_count
            FROM task_comments
            GROUP BY task_id
        ) tc_counts ON tc_counts.task_id = t.id
        LEFT JOIN (
            SELECT task_id, COUNT(*) AS attachment_count
            FROM task_attachments
            GROUP BY task_id
        ) ta_counts ON ta_counts.task_id = t.id
        LEFT JOIN (
            SELECT task_id, COUNT(*) AS watcher_count
            FROM task_watchers
            GROUP BY task_id
        ) tw_counts ON tw_counts.task_id = t.id
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

function listTasks($filters = [], bool $withPagination = false, ?array $apiUser = null, ?array $directoryScopeUser = null) {
    $db = getDbConnection();

    $where = [];
    $filterJoins = [];
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

    if (array_key_exists('project_id', $filters) && $filters['project_id'] !== null && $filters['project_id'] !== '') {
        $where[] = 't.project_id = :project_id';
        $params[':project_id'] = [(int)$filters['project_id'], SQLITE3_INTEGER];
    }

    if (array_key_exists('list_id', $filters) && $filters['list_id'] !== null && $filters['list_id'] !== '') {
        $where[] = 't.list_id = :list_id';
        $params[':list_id'] = [(int)$filters['list_id'], SQLITE3_INTEGER];
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
        $filterJoins[] = 'JOIN task_watchers tw_filter ON tw_filter.task_id = t.id';
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

    $scopeUser = $directoryScopeUser ?? null;
    if ($scopeUser === null && $apiUser !== null) {
        $scopeUser = $apiUser;
    }
    if ($scopeUser !== null && !userHasUnrestrictedOrgDirectoryAccess($scopeUser)) {
        $rUid = (int)$scopeUser['id'];
        $accessible = getAccessibleDirectoryProjectIdsForUser($scopeUser);
        $params[':dir_scope_uid'] = [$rUid, SQLITE3_INTEGER];
        if ($accessible === []) {
            $where[] = '(t.project_id IS NULL AND (t.created_by_user_id = :dir_scope_uid OR t.assigned_to_user_id = :dir_scope_uid))';
        } else {
            $ph = [];
            foreach ($accessible as $i => $apid) {
                $key = ':dir_scope_proj_' . $i;
                $ph[] = $key;
                $params[$key] = [(int)$apid, SQLITE3_INTEGER];
            }
            $where[] = '((t.project_id IS NULL AND (t.created_by_user_id = :dir_scope_uid OR t.assigned_to_user_id = :dir_scope_uid)) OR (t.project_id IS NOT NULL AND t.project_id IN (' . implode(',', $ph) . ')))';
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
    $filterJoinsSql = implode("\n", array_unique($filterJoins));

    $selectSql = "
        SELECT
          t.*,
          dp.name AS directory_project_name,
          ts.label AS status_label,
          ts.sort_order AS status_sort_order,
          ts.is_done AS status_is_done,
          cu.username AS created_by_username,
          au.username AS assigned_to_username,
          COALESCE(tc_counts.comment_count, 0) AS comment_count,
          COALESCE(ta_counts.attachment_count, 0) AS attachment_count,
          COALESCE(tw_counts.watcher_count, 0) AS watcher_count
        FROM tasks t
        JOIN users cu ON cu.id = t.created_by_user_id
        LEFT JOIN users au ON au.id = t.assigned_to_user_id
        LEFT JOIN projects dp ON dp.id = t.project_id
        LEFT JOIN task_statuses ts ON ts.slug = t.status
        LEFT JOIN (
            SELECT task_id, COUNT(*) AS comment_count
            FROM task_comments
            GROUP BY task_id
        ) tc_counts ON tc_counts.task_id = t.id
        LEFT JOIN (
            SELECT task_id, COUNT(*) AS attachment_count
            FROM task_attachments
            GROUP BY task_id
        ) ta_counts ON ta_counts.task_id = t.id
        LEFT JOIN (
            SELECT task_id, COUNT(*) AS watcher_count
            FROM task_watchers
            GROUP BY task_id
        ) tw_counts ON tw_counts.task_id = t.id
        $filterJoinsSql
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
        $filterJoinsSql
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

    if (array_key_exists('project_id', $fields)) {
        $pidRaw = $fields['project_id'];
        if ($pidRaw === null || $pidRaw === '') {
            return ['success' => false, 'error' => 'project_id cannot be cleared; every task must belong to a directory project.'];
        }
        $sets[] = 'project_id = :project_id';
        $params[':project_id'] = [(int)$pidRaw, SQLITE3_INTEGER];
    }

    if (array_key_exists('list_id', $fields)) {
        $lidRaw = $fields['list_id'];
        if ($lidRaw === null || $lidRaw === '') {
            $sets[] = 'list_id = :list_id';
            $params[':list_id'] = [null, SQLITE3_NULL];
        } else {
            $sets[] = 'list_id = :list_id';
            $params[':list_id'] = [(int)$lidRaw, SQLITE3_INTEGER];
        }
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

function listOrganizations(): array {
    $db = getDbConnection();
    $res = $db->query('SELECT id, name, settings_json, created_at FROM organizations ORDER BY id ASC');
    $rows = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    return $rows;
}

function getOrganizationById(int $id): ?array {
    if ($id <= 0) {
        return null;
    }
    $db = getDbConnection();
    $stmt = $db->prepare('SELECT id, name, settings_json, created_at FROM organizations WHERE id = :id LIMIT 1');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $row = $res->fetchArray(SQLITE3_ASSOC) ?: null;
    return $row ?: null;
}

function createOrganization(string $name, ?int $actorUserId = null): array {
    $name = truncateString(trim($name), 200);
    if ($name === '') {
        return ['success' => false, 'error' => 'Organization name is required'];
    }
    $db = getDbConnection();
    try {
        $stmt = $db->prepare('INSERT INTO organizations (name) VALUES (:name)');
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->execute();
        $oid = (int)$db->lastInsertRowID();
        createAuditLog($actorUserId, 'organization.create', 'organization', (string)$oid, ['name' => $name]);
        return ['success' => true, 'id' => $oid, 'name' => $name];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'Could not create organization'];
    }
}

function updateOrganizationName(int $orgId, string $name, ?int $actorUserId): array {
    $name = truncateString(trim($name), 200);
    if ($orgId <= 0 || $name === '') {
        return ['success' => false, 'error' => 'Invalid organization or name'];
    }
    $db = getDbConnection();
    $stmt = $db->prepare('UPDATE organizations SET name = :name WHERE id = :id');
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':id', $orgId, SQLITE3_INTEGER);
    $stmt->execute();
    if ($db->changes() === 0) {
        return ['success' => false, 'error' => 'Organization not found'];
    }
    createAuditLog($actorUserId, 'organization.update', 'organization', (string)$orgId, ['name' => $name]);
    return ['success' => true];
}

/** Remove memberships on projects outside the given organization (runs before org reassignment). */
function removeUserProjectMembershipsOutsideOrganization(int $userId, int $orgId): void {
    if ($userId <= 0 || $orgId <= 0) {
        return;
    }
    $db = getDbConnection();
    $stmt = $db->prepare('
        DELETE FROM project_members
        WHERE user_id = :u
          AND project_id IN (SELECT id FROM projects WHERE org_id != :org)
    ');
    $stmt->bindValue(':u', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':org', $orgId, SQLITE3_INTEGER);
    $stmt->execute();
}

function setUserOrganization(int $actorUserId, int $targetUserId, int $newOrgId): array {
    if ($targetUserId <= 0 || $newOrgId <= 0) {
        return ['success' => false, 'error' => 'Invalid user or organization'];
    }
    if (!getOrganizationById($newOrgId)) {
        return ['success' => false, 'error' => 'Organization not found'];
    }
    $tgt = getUserById($targetUserId, false);
    if (!$tgt) {
        return ['success' => false, 'error' => 'User not found'];
    }
    if (!userQualifiesForMultiOrganizationMemberships($tgt)) {
        removeUserProjectMembershipsOutsideOrganization($targetUserId, $newOrgId);
    }
    $db = getDbConnection();
    $stmt = $db->prepare('UPDATE users SET org_id = :oid WHERE id = :id');
    $stmt->bindValue(':oid', $newOrgId, SQLITE3_INTEGER);
    $stmt->bindValue(':id', $targetUserId, SQLITE3_INTEGER);
    $stmt->execute();
    if (userQualifiesForMultiOrganizationMemberships($tgt)) {
        ensureUserOrganizationMembershipRow($targetUserId, $newOrgId);
    }
    createAuditLog($actorUserId, 'user.organization_set', 'user', (string)$targetUserId, ['org_id' => $newOrgId]);
    return ['success' => true];
}

function setUserLimitedProjectAccess(int $actorUserId, int $targetUserId, bool $limited): array {
    if ($targetUserId <= 0) {
        return ['success' => false, 'error' => 'Invalid user'];
    }
    $tgt = getUserById($targetUserId, false);
    if (!$tgt) {
        return ['success' => false, 'error' => 'User not found'];
    }
    $db = getDbConnection();
    $stmt = $db->prepare('UPDATE users SET limited_project_access = :l WHERE id = :id');
    $stmt->bindValue(':l', $limited ? 1 : 0, SQLITE3_INTEGER);
    $stmt->bindValue(':id', $targetUserId, SQLITE3_INTEGER);
    $stmt->execute();
    createAuditLog($actorUserId, 'user.limited_project_access_set', 'user', (string)$targetUserId, ['limited' => $limited ? 1 : 0]);
    return ['success' => true];
}

/** All directory projects in an organization (non-trashed), sorted by name. For admin UX / membership bulk edit. */
function listAllDirectoryProjectsInOrganization(int $orgId, int $limit = 500): array {
    if ($orgId <= 0) {
        return [];
    }
    $limit = max(1, min(500, $limit));
    $db = getDbConnection();
    $stmt = $db->prepare('
        SELECT id, org_id, name, description, status, client_visible, all_access, created_at, updated_at
        FROM projects
        WHERE org_id = :org AND status != \'trashed\'
        ORDER BY name COLLATE NOCASE ASC
        LIMIT :lim
    ');
    $stmt->bindValue(':org', $orgId, SQLITE3_INTEGER);
    $stmt->bindValue(':lim', $limit, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $items = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $row['id'] = (int)$row['id'];
        $row['org_id'] = (int)$row['org_id'];
        $row['client_visible'] = (int)$row['client_visible'];
        $row['all_access'] = (int)$row['all_access'];
        $items[] = $row;
    }
    return $items;
}

/**
 * Legacy `tasks.project` text buckets with no directory link (`project_id` null).
 * For admin UX: API/automation often fills `project` without creating a `projects` row.
 *
 * @return array<int, array{namespace: string, task_count: int}>
 */
function listLegacyOnlyTaskProjectNamespaces(): array {
    $db = getDbConnection();
    $stmt = $db->prepare("
        SELECT TRIM(project) AS ns, COUNT(*) AS c
        FROM tasks
        WHERE project_id IS NULL AND project IS NOT NULL AND TRIM(project) <> ''
        GROUP BY LOWER(TRIM(project))
        ORDER BY ns COLLATE NOCASE
    ");
    $res = $stmt->execute();
    $out = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $out[] = [
            'namespace' => (string)$row['ns'],
            'task_count' => (int)$row['c'],
        ];
    }
    return $out;
}

function listOrganizationsWithStats(): array {
    $orgs = listOrganizations();
    if (!$orgs) {
        return [];
    }
    $db = getDbConnection();
    foreach ($orgs as &$o) {
        $oid = (int)$o['id'];
        if (tableExists($db, 'user_organization_memberships')) {
            $st = $db->prepare("
                SELECT COUNT(*) FROM (
                    SELECT id FROM users WHERE org_id = :o
                    UNION
                    SELECT user_id FROM user_organization_memberships WHERE org_id = :o2
                )
            ");
            $st->bindValue(':o', $oid, SQLITE3_INTEGER);
            $st->bindValue(':o2', $oid, SQLITE3_INTEGER);
        } else {
            $st = $db->prepare('SELECT COUNT(*) FROM users WHERE org_id = :o');
            $st->bindValue(':o', $oid, SQLITE3_INTEGER);
        }
        $res = $st->execute();
        $row = $res->fetchArray(SQLITE3_NUM);
        $o['user_count'] = $row ? (int)$row[0] : 0;
        $st2 = $db->prepare('SELECT COUNT(*) FROM projects WHERE org_id = :o AND status != \'trashed\'');
        $st2->bindValue(':o', $oid, SQLITE3_INTEGER);
        $res2 = $st2->execute();
        $row2 = $res2->fetchArray(SQLITE3_NUM);
        $o['project_count'] = $row2 ? (int)$row2[0] : 0;
    }
    unset($o);
    return $orgs;
}

/** Valid directory project lifecycle status values. */
function normalizeDirectoryProjectStatus(?string $status): ?string {
    $s = strtolower(trim((string)$status));
    if (in_array($s, ['active', 'archived', 'trashed'], true)) {
        return $s;
    }
    return null;
}

/** lead | member | client */
function normalizeProjectMemberRole(?string $role): ?string {
    $r = strtolower(trim((string)$role));
    if (in_array($r, ['lead', 'member', 'client'], true)) {
        return $r;
    }
    return null;
}

function getProjectMemberRole(int $userId, int $projectId): ?string {
    if ($userId <= 0 || $projectId <= 0) {
        return null;
    }
    $db = getDbConnection();
    $stmt = $db->prepare('SELECT role FROM project_members WHERE project_id = :p AND user_id = :u LIMIT 1');
    $stmt->bindValue(':p', $projectId, SQLITE3_INTEGER);
    $stmt->bindValue(':u', $userId, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $row = $res->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        return null;
    }
    return normalizeProjectMemberRole($row['role']) ?? 'member';
}

/**
 * When true: user sees all non-trashed projects in their organization (respecting client_visibility for clients never applies — clients always restricted).
 */
function userHasUnrestrictedOrgDirectoryAccess(array $userRow): bool {
    if (normalizePersonKind($userRow['person_kind'] ?? 'team_member') === 'client') {
        return false;
    }
    $role = strtolower(trim((string)($userRow['role'] ?? 'member')));
    if ($role === 'admin') {
        return true;
    }
    if ($role === 'manager' && empty((int)($userRow['limited_project_access'] ?? 0))) {
        return true;
    }
    return false;
}

/** Project IDs the user may open (respects memberships, all_access, client visibility). */
function getAccessibleDirectoryProjectIdsForUser(array $userRow): array {
    $rows = listDirectoryProjectsForUser($userRow, 500);
    $ids = [];
    foreach ($rows as $r) {
        $ids[] = (int)$r['id'];
    }
    return $ids;
}

/**
 * Whether the user may see this project row in the directory (non-trashed; org match; client_visible for clients; membership or staff).
 */
function userCanAccessDirectoryProject(array $userRow, ?array $project): bool {
    if (!$project) {
        return false;
    }
    $projectOrg = (int)($project['org_id'] ?? 0);
    if ($projectOrg <= 0 || !userMayAccessOrganization($userRow, $projectOrg)) {
        return false;
    }
    if (($project['status'] ?? '') === 'trashed') {
        return false;
    }
    $pk = normalizePersonKind($userRow['person_kind'] ?? 'team_member');
    if ($pk === 'client' && empty((int)($project['client_visible'] ?? 0))) {
        return false;
    }
    if (userHasUnrestrictedOrgDirectoryAccess($userRow)) {
        return true;
    }
    if (!empty((int)($project['all_access'] ?? 0))) {
        return true;
    }
    $uid = (int)$userRow['id'];
    $pid = (int)$project['id'];
    $db = getDbConnection();
    $stmt = $db->prepare('SELECT 1 FROM project_members WHERE project_id = :p AND user_id = :u LIMIT 1');
    $stmt->bindValue(':p', $pid, SQLITE3_INTEGER);
    $stmt->bindValue(':u', $uid, SQLITE3_INTEGER);
    $res = $stmt->execute();
    return (bool)$res->fetchArray(SQLITE3_ASSOC);
}

/** Team lead, org admin, or unrestricted manager may change project settings and membership (limited managers: lead-only). */
function userCanManageDirectoryProject(array $userRow, array $project): bool {
    if (!userCanAccessDirectoryProject($userRow, $project)) {
        return false;
    }
    if (normalizePersonKind($userRow['person_kind'] ?? 'team_member') === 'client') {
        return false;
    }
    $role = (string)($userRow['role'] ?? 'member');
    if ($role === 'admin') {
        return true;
    }
    if ($role === 'manager' && userHasUnrestrictedOrgDirectoryAccess($userRow)) {
        return true;
    }
    return getProjectMemberRole((int)$userRow['id'], (int)$project['id']) === 'lead';
}

/**
 * Resolve optional project_id for task create/update: must be in user's org and visible in directory.
 *
 * @return array{success:bool, project_id:?int, project:?string, error?:string}
 */
function resolveTaskDirectoryProjectId(array $userRow, $projectIdRaw, bool $allowNull = true): array {
    if ($projectIdRaw === null || $projectIdRaw === '') {
        if (!$allowNull) {
            return ['success' => false, 'error' => 'project_id is required'];
        }
        return ['success' => true, 'project_id' => null, 'project' => null];
    }
    $pid = (int)$projectIdRaw;
    if ($pid <= 0) {
        return ['success' => false, 'error' => 'Invalid project_id'];
    }
    $proj = getDirectoryProjectById($pid);
    if (!$proj || !userCanAccessDirectoryProject($userRow, $proj)) {
        return ['success' => false, 'error' => 'Project not found or not accessible'];
    }
    return ['success' => true, 'project_id' => $pid, 'project' => (string)$proj['name']];
}

/**
 * Project entities (directory) visible to the given user within their org.
 */
function listDirectoryProjectsForUser(array $userRow, int $limit = 200): array {
    $limit = max(1, min(500, $limit));
    $uid = (int)$userRow['id'];
    $orgIds = listOrganizationIdsForUserAccess($userRow);
    $pk = normalizePersonKind($userRow['person_kind'] ?? 'team_member');
    $clientOnly = ($pk === 'client');
    if ($orgIds === []) {
        return [];
    }
    $db = getDbConnection();
    $cvClause = $clientOnly ? ' AND client_visible = 1' : '';
    $cvClauseP = $clientOnly ? ' AND p.client_visible = 1' : '';
    $canSeeAll = userHasUnrestrictedOrgDirectoryAccess($userRow);
    $inClause = [];
    $bind = [':uid' => [$uid, SQLITE3_INTEGER], ':lim' => [$limit, SQLITE3_INTEGER]];
    foreach ($orgIds as $i => $oid) {
        $k = ':org' . $i;
        $inClause[] = $k;
        $bind[$k] = [$oid, SQLITE3_INTEGER];
    }
    $inSql = implode(', ', $inClause);
    if ($canSeeAll) {
        $sql = "
            SELECT id, org_id, name, description, status, client_visible, all_access, created_at, updated_at
            FROM projects
            WHERE org_id IN ($inSql) AND status != 'trashed'{$cvClause}
            ORDER BY name COLLATE NOCASE ASC
            LIMIT :lim
        ";
    } else {
        $sql = "
            SELECT DISTINCT p.id, p.org_id, p.name, p.description, p.status, p.client_visible, p.all_access, p.created_at, p.updated_at
            FROM projects p
            LEFT JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = :uid
            WHERE p.org_id IN ($inSql)
              AND p.status != 'trashed'{$cvClauseP}
              AND (p.all_access = 1 OR pm.user_id IS NOT NULL)
            ORDER BY p.name COLLATE NOCASE ASC
            LIMIT :lim
        ";
    }
    $stmt = $db->prepare($sql);
    foreach ($bind as $param => $pair) {
        $stmt->bindValue($param, $pair[0], $pair[1]);
    }
    $res = $stmt->execute();
    $items = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $row['id'] = (int)$row['id'];
        $row['org_id'] = (int)$row['org_id'];
        $row['client_visible'] = (int)$row['client_visible'];
        $row['all_access'] = (int)$row['all_access'];
        $items[] = $row;
    }
    return $items;
}

function getDirectoryProjectById(int $id): ?array {
    if ($id <= 0) {
        return null;
    }
    $db = getDbConnection();
    $stmt = $db->prepare('
        SELECT id, org_id, name, description, status, client_visible, all_access, created_at, updated_at
        FROM projects WHERE id = :id LIMIT 1
    ');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $row = $res->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        return null;
    }
    $row['id'] = (int)$row['id'];
    $row['org_id'] = (int)$row['org_id'];
    $row['client_visible'] = (int)$row['client_visible'];
    $row['all_access'] = (int)$row['all_access'];
    return $row;
}

function createDirectoryProject(int $userId, string $name, ?string $description = null, bool $clientVisible = false, bool $allAccess = false): array {
    $name = trim($name);
    if ($name === '') {
        return ['success' => false, 'error' => 'Project name is required'];
    }
    $u = getUserById($userId, false);
    if (!$u) {
        return ['success' => false, 'error' => 'User not found'];
    }
    $orgId = getEffectiveDirectoryOrgId($u);
    if ($orgId <= 0) {
        return ['success' => false, 'error' => 'User has no organization'];
    }
    $role = (string)($u['role'] ?? 'member');
    if (!in_array($role, ['admin', 'manager', 'member'], true)) {
        return ['success' => false, 'error' => 'Insufficient permission to create projects'];
    }
    $db = getDbConnection();
    $now = gmdate('Y-m-d H:i:s');
    try {
        $stmt = $db->prepare("
            INSERT INTO projects (org_id, name, description, status, client_visible, all_access, created_at, updated_at)
            VALUES (:org, :name, :descr, 'active', :cv, :aa, :c1, :c2)
        ");
        $stmt->bindValue(':org', $orgId, SQLITE3_INTEGER);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $descrVal = ($description !== null && trim($description) !== '') ? trim($description) : null;
        if ($descrVal === null) {
            $stmt->bindValue(':descr', null, SQLITE3_NULL);
        } else {
            $stmt->bindValue(':descr', $descrVal, SQLITE3_TEXT);
        }
        $stmt->bindValue(':cv', $clientVisible ? 1 : 0, SQLITE3_INTEGER);
        $stmt->bindValue(':aa', $allAccess ? 1 : 0, SQLITE3_INTEGER);
        $stmt->bindValue(':c1', $now, SQLITE3_TEXT);
        $stmt->bindValue(':c2', $now, SQLITE3_TEXT);
        $stmt->execute();
        $pid = (int)$db->lastInsertRowID();
        $m = $db->prepare('INSERT INTO project_members (project_id, user_id, role) VALUES (:p, :u, :r)');
        $m->bindValue(':p', $pid, SQLITE3_INTEGER);
        $m->bindValue(':u', $userId, SQLITE3_INTEGER);
        $m->bindValue(':r', 'lead', SQLITE3_TEXT);
        $m->execute();
        createAuditLog($userId, 'project.create', 'project', (string)$pid, ['name' => $name, 'org_id' => $orgId]);
        return [
            'success' => true,
            'id' => $pid,
            'org_id' => $orgId,
            'name' => $name,
        ];
    } catch (Throwable $e) {
        if (strpos($e->getMessage(), 'UNIQUE') !== false) {
            return ['success' => false, 'error' => 'A project with this name already exists in your organization'];
        }
        return ['success' => false, 'error' => 'Could not create project'];
    }
}

/**
 * Update directory project fields (admin/manager org-wide or project lead).
 *
 * @param array<string,mixed> $fields
 */
function updateDirectoryProject(int $userId, int $projectId, array $fields): array {
    $proj = getDirectoryProjectById($projectId);
    $actor = getUserById($userId, false);
    if (!$proj || !$actor) {
        return ['success' => false, 'error' => 'Project not found'];
    }
    if (!userCanManageDirectoryProject($actor, $proj)) {
        return ['success' => false, 'error' => 'Insufficient permission to update this project'];
    }

    $sets = [];
    $params = [':id' => [$projectId, SQLITE3_INTEGER]];

    if (array_key_exists('name', $fields)) {
        $name = trim((string)$fields['name']);
        if ($name === '') {
            return ['success' => false, 'error' => 'Project name cannot be empty'];
        }
        $sets[] = 'name = :name';
        $params[':name'] = [$name, SQLITE3_TEXT];
    }
    if (array_key_exists('description', $fields)) {
        $d = $fields['description'];
        $descr = ($d === null || $d === '') ? null : trim((string)$d);
        $sets[] = 'description = :description';
        $params[':description'] = [$descr, $descr === null ? SQLITE3_NULL : SQLITE3_TEXT];
    }
    if (array_key_exists('status', $fields)) {
        $st = normalizeDirectoryProjectStatus((string)$fields['status']);
        if ($st === null) {
            return ['success' => false, 'error' => 'Invalid status (use active, archived, or trashed)'];
        }
        $sets[] = 'status = :status';
        $params[':status'] = [$st, SQLITE3_TEXT];
    }
    if (array_key_exists('client_visible', $fields)) {
        $sets[] = 'client_visible = :client_visible';
        $params[':client_visible'] = [empty($fields['client_visible']) ? 0 : 1, SQLITE3_INTEGER];
    }
    if (array_key_exists('all_access', $fields)) {
        $sets[] = 'all_access = :all_access';
        $params[':all_access'] = [empty($fields['all_access']) ? 0 : 1, SQLITE3_INTEGER];
    }

    if (!$sets) {
        return ['success' => false, 'error' => 'No fields to update'];
    }

    $now = gmdate('Y-m-d H:i:s');
    $sets[] = 'updated_at = :u_at';
    $params[':u_at'] = [$now, SQLITE3_TEXT];

    $db = getDbConnection();
    $sql = 'UPDATE projects SET ' . implode(', ', $sets) . ' WHERE id = :id';
    try {
        $stmt = $db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v[0], $v[1]);
        }
        $stmt->execute();
    } catch (Throwable $e) {
        if (strpos($e->getMessage(), 'UNIQUE') !== false) {
            return ['success' => false, 'error' => 'A project with this name already exists in your organization'];
        }
        return ['success' => false, 'error' => 'Could not update project'];
    }

    createAuditLog($userId, 'project.update', 'project', (string)$projectId, ['fields' => array_keys($fields)]);
    return ['success' => true];
}

/** Members of a project with username (same-org users only). */
function listProjectMembers(int $projectId): array {
    if ($projectId <= 0) {
        return [];
    }
    $db = getDbConnection();
    $stmt = $db->prepare("
        SELECT pm.project_id, pm.user_id, pm.role, pm.created_at,
               u.username, u.role AS user_role, u.person_kind
        FROM project_members pm
        JOIN users u ON u.id = pm.user_id
        WHERE pm.project_id = :p
        ORDER BY u.username COLLATE NOCASE ASC
    ");
    $stmt->bindValue(':p', $projectId, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $rows = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $row['project_id'] = (int)$row['project_id'];
        $row['user_id'] = (int)$row['user_id'];
        $row['person_kind'] = normalizePersonKind($row['person_kind'] ?? 'team_member');
        $rows[] = $row;
    }
    return $rows;
}

function addProjectMember(int $actorUserId, int $projectId, int $targetUserId, string $memberRole = 'member'): array {
    $proj = getDirectoryProjectById($projectId);
    $actor = getUserById($actorUserId, false);
    $target = getUserById($targetUserId, false);
    if (!$proj || !$actor || !$target) {
        return ['success' => false, 'error' => 'User or project not found'];
    }
    if (!userCanManageDirectoryProject($actor, $proj)) {
        return ['success' => false, 'error' => 'Insufficient permission to manage members'];
    }
    $orgId = (int)$proj['org_id'];
    if (!userMayAccessOrganization($target, $orgId)) {
        return ['success' => false, 'error' => 'User is not in this organization'];
    }
    $mr = normalizeProjectMemberRole($memberRole);
    if ($mr === null) {
        return ['success' => false, 'error' => 'Invalid member role'];
    }

    $db = getDbConnection();
    if ($mr === 'lead') {
        $demote = $db->prepare("UPDATE project_members SET role = 'member' WHERE project_id = :p AND role = 'lead'");
        $demote->bindValue(':p', $projectId, SQLITE3_INTEGER);
        $demote->execute();
    }

    try {
        $ins = $db->prepare('INSERT INTO project_members (project_id, user_id, role) VALUES (:p, :u, :r)');
        $ins->bindValue(':p', $projectId, SQLITE3_INTEGER);
        $ins->bindValue(':u', $targetUserId, SQLITE3_INTEGER);
        $ins->bindValue(':r', $mr, SQLITE3_TEXT);
        $ins->execute();
    } catch (Throwable $e) {
        if (strpos($e->getMessage(), 'UNIQUE') !== false) {
            $up = $db->prepare('UPDATE project_members SET role = :r WHERE project_id = :p AND user_id = :u');
            $up->bindValue(':r', $mr, SQLITE3_TEXT);
            $up->bindValue(':p', $projectId, SQLITE3_INTEGER);
            $up->bindValue(':u', $targetUserId, SQLITE3_INTEGER);
            $up->execute();
        } else {
            return ['success' => false, 'error' => 'Could not add project member'];
        }
    }

    createAuditLog($actorUserId, 'project.member_add', 'project', (string)$projectId, ['user_id' => $targetUserId, 'role' => $mr]);
    return ['success' => true];
}

function removeProjectMember(int $actorUserId, int $projectId, int $targetUserId): array {
    $proj = getDirectoryProjectById($projectId);
    $actor = getUserById($actorUserId, false);
    if (!$proj || !$actor) {
        return ['success' => false, 'error' => 'Not found'];
    }
    if (!userCanManageDirectoryProject($actor, $proj)) {
        return ['success' => false, 'error' => 'Insufficient permission'];
    }
    $current = getProjectMemberRole($targetUserId, $projectId);
    if ($current === null) {
        return ['success' => false, 'error' => 'User is not on this project'];
    }
    if ($current === 'lead') {
        $db = getDbConnection();
        $cnt = $db->prepare('SELECT COUNT(*) AS c FROM project_members WHERE project_id = :p');
        $cnt->bindValue(':p', $projectId, SQLITE3_INTEGER);
        $crow = $cnt->execute()->fetchArray(SQLITE3_ASSOC);
        $n = (int)($crow['c'] ?? 0);
        if ($n <= 1) {
            return ['success' => false, 'error' => 'Cannot remove the last member; trash the project or add another lead first'];
        }
    }

    $db = getDbConnection();
    $del = $db->prepare('DELETE FROM project_members WHERE project_id = :p AND user_id = :u');
    $del->bindValue(':p', $projectId, SQLITE3_INTEGER);
    $del->bindValue(':u', $targetUserId, SQLITE3_INTEGER);
    $del->execute();

    createAuditLog($actorUserId, 'project.member_remove', 'project', (string)$projectId, ['user_id' => $targetUserId]);
    return ['success' => true];
}

/**
 * Idempotent: set tasks.project_id where legacy tasks.project string matches a directory project name in the same org as the task creator (best-effort).
 */
function backfillTaskProjectIdsFromLegacyNames(): array {
    $db = getDbConnection();
    $updated = 0;
    $res = $db->query("
        SELECT t.id, t.project, t.created_by_user_id
        FROM tasks t
        WHERE t.project_id IS NULL
          AND t.project IS NOT NULL
          AND TRIM(t.project) <> ''
    ");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $creator = getUserById((int)$row['created_by_user_id'], false);
        $orgId = $creator && isset($creator['org_id']) ? (int)$creator['org_id'] : 0;
        if ($orgId <= 0) {
            continue;
        }
        $name = trim((string)$row['project']);
        $stmt = $db->prepare('SELECT id FROM projects WHERE org_id = :o AND name = :n AND status != \'trashed\' LIMIT 1');
        $stmt->bindValue(':o', $orgId, SQLITE3_INTEGER);
        $stmt->bindValue(':n', $name, SQLITE3_TEXT);
        $q = $stmt->execute();
        $prow = $q->fetchArray(SQLITE3_ASSOC);
        if (!$prow) {
            continue;
        }
        $pid = (int)$prow['id'];
        $u = $db->prepare('UPDATE tasks SET project_id = :p WHERE id = :id AND project_id IS NULL');
        $u->bindValue(':p', $pid, SQLITE3_INTEGER);
        $u->bindValue(':id', (int)$row['id'], SQLITE3_INTEGER);
        $u->execute();
        if ($db->changes() > 0) {
            $updated++;
        }
    }
    return ['updated' => $updated];
}

/** To-do lists under a directory project (requires directory access). */
function listTodoListsForProject(array $userRow, int $projectId): array {
    if ($projectId <= 0) {
        return [];
    }
    $proj = getDirectoryProjectById($projectId);
    if (!$proj || !userCanAccessDirectoryProject($userRow, $proj)) {
        return [];
    }
    $db = getDbConnection();
    $stmt = $db->prepare('SELECT id, project_id, name, sort_order, created_at FROM todo_lists WHERE project_id = :p ORDER BY sort_order ASC, name COLLATE NOCASE ASC');
    $stmt->bindValue(':p', $projectId, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $rows = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $row['id'] = (int)$row['id'];
        $row['project_id'] = (int)$row['project_id'];
        $row['sort_order'] = (int)$row['sort_order'];
        $rows[] = $row;
    }
    return $rows;
}

function createTodoList(int $userId, int $projectId, string $name): array {
    $proj = getDirectoryProjectById($projectId);
    $actor = getUserById($userId, false);
    if (!$proj || !$actor || !userCanManageDirectoryProject($actor, $proj)) {
        return ['success' => false, 'error' => 'Insufficient permission'];
    }
    $name = trim($name);
    if ($name === '') {
        return ['success' => false, 'error' => 'List name is required'];
    }
    $db = getDbConnection();
    $mxStmt = $db->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort FROM todo_lists WHERE project_id = :p');
    $mxStmt->bindValue(':p', $projectId, SQLITE3_INTEGER);
    $mxRow = $mxStmt->execute()->fetchArray(SQLITE3_ASSOC);
    $maxSort = (int)($mxRow['next_sort'] ?? 0);
    $stmt = $db->prepare('INSERT INTO todo_lists (project_id, name, sort_order) VALUES (:p, :n, :s)');
    $stmt->bindValue(':p', $projectId, SQLITE3_INTEGER);
    $stmt->bindValue(':n', $name, SQLITE3_TEXT);
    $stmt->bindValue(':s', $maxSort, SQLITE3_INTEGER);
    $stmt->execute();
    $id = (int)$db->lastInsertRowID();
    createAuditLog($userId, 'todo_list.create', 'todo_list', (string)$id, ['project_id' => $projectId, 'name' => $name]);
    return ['success' => true, 'id' => $id];
}

function listUserProjectPinsForUser(array $userRow, int $limit = 200): array {
    $uid = (int)$userRow['id'];
    if ($uid <= 0) {
        return [];
    }
    $limit = max(1, min(500, $limit));
    $orgIds = listOrganizationIdsForUserAccess($userRow);
    if ($orgIds === []) {
        return [];
    }
    $db = getDbConnection();
    $inClause = [];
    $bind = [':u' => [$uid, SQLITE3_INTEGER], ':lim' => [$limit, SQLITE3_INTEGER]];
    foreach ($orgIds as $i => $oid) {
        $k = ':o' . $i;
        $inClause[] = $k;
        $bind[$k] = [$oid, SQLITE3_INTEGER];
    }
    $inSql = implode(', ', $inClause);
    $sql = "
        SELECT upp.project_id, upp.sort_order, p.name, p.status, p.client_visible
        FROM user_project_pins upp
        JOIN projects p ON p.id = upp.project_id
        WHERE upp.user_id = :u AND p.org_id IN ($inSql) AND p.status != 'trashed'
        ORDER BY upp.sort_order ASC, p.name COLLATE NOCASE ASC
        LIMIT :lim
    ";
    $stmt = $db->prepare($sql);
    foreach ($bind as $param => $pair) {
        $stmt->bindValue($param, $pair[0], $pair[1]);
    }
    $res = $stmt->execute();
    $out = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        if (!userCanAccessDirectoryProject($userRow, getDirectoryProjectById((int)$row['project_id']))) {
            continue;
        }
        $row['project_id'] = (int)$row['project_id'];
        $row['sort_order'] = (int)$row['sort_order'];
        $row['client_visible'] = (int)$row['client_visible'];
        $out[] = $row;
    }
    return $out;
}

function setUserProjectPin(int $userId, int $projectId, int $sortOrder = 0): array {
    $u = getUserById($userId, false);
    $proj = getDirectoryProjectById($projectId);
    if (!$u || !$proj || !userCanAccessDirectoryProject($u, $proj)) {
        return ['success' => false, 'error' => 'Project not accessible'];
    }
    $db = getDbConnection();
    $stmt = $db->prepare('
        INSERT INTO user_project_pins (user_id, project_id, sort_order) VALUES (:u, :p, :s)
        ON CONFLICT(user_id, project_id) DO UPDATE SET sort_order = excluded.sort_order
    ');
    $stmt->bindValue(':u', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':p', $projectId, SQLITE3_INTEGER);
    $stmt->bindValue(':s', $sortOrder, SQLITE3_INTEGER);
    $stmt->execute();
    return ['success' => true];
}

function removeUserProjectPin(int $userId, int $projectId): array {
    $db = getDbConnection();
    $stmt = $db->prepare('DELETE FROM user_project_pins WHERE user_id = :u AND project_id = :p');
    $stmt->bindValue(':u', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':p', $projectId, SQLITE3_INTEGER);
    $stmt->execute();
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
    $sel = $db->prepare("SELECT created_at FROM task_comments WHERE id = :id LIMIT 1");
    $sel->bindValue(':id', $id, SQLITE3_INTEGER);
    $createdRow = $sel->execute()->fetchArray(SQLITE3_ASSOC);
    $createdAt = $createdRow ? $createdRow['created_at'] : nowUtc();
    createAuditLog($userId, 'task.comment_add', 'task_comment', (string)$id, ['task_id' => $taskId]);
    return ['success' => true, 'id' => $id, 'created_at' => $createdAt];
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
                'project_id' => $item['project_id'] ?? null,
                'list_id' => $item['list_id'] ?? null,
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

