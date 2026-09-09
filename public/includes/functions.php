<?php
require_once __DIR__ . '/config.php';

// Ensure DB is initialized when helpers are loaded
initializeDatabase();

function nowUtc(): string {
    return gmdate('Y-m-d H:i:s');
}

/** True when the direct TCP peer is allow-listed as a trusted reverse proxy (see TASKS_TRUST_PROXY). */
function tasksTrustedProxyConnection(): bool {
    if (!TRUST_PROXY) {
        return false;
    }
    $remoteAddr = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remoteAddr === '') {
        return false;
    }
    $trustedList = array_map('trim', array_filter(explode(',', (string)TRUSTED_PROXY_IPS)));
    return $trustedList !== [] && in_array($remoteAddr, $trustedList, true);
}

function requestIpAddress(): string {
    $remoteAddr = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remoteAddr === '') {
        return 'unknown';
    }

    if (!tasksTrustedProxyConnection()) {
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
        ? "SELECT id, username, role, is_active, must_change_password, mfa_enabled, mfa_secret, password_hash, org_id, person_kind, limited_project_access, skin_slug, home_widgets_json, created_at FROM users WHERE id = :id LIMIT 1"
        : "SELECT id, username, role, is_active, must_change_password, mfa_enabled, org_id, person_kind, limited_project_access, skin_slug, home_widgets_json, created_at FROM users WHERE id = :id LIMIT 1";
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
        ensureQBridgeDefaultApiKeyForUser($id);
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

/**
 * Hard-delete a user. Refuses if there is referenced activity (created/assigned tasks,
 * comments, attachments, project memberships, audit actor rows, etc.) unless `$force`
 * is true. With force=true, dependent rows that lack ON DELETE CASCADE are nulled or
 * removed defensively before the delete. Idempotent: deleting an already-missing user
 * returns success with `already_deleted=true`.
 */
function deleteUser(int $userId, ?int $actorUserId = null, bool $force = false): array {
    if ($userId <= 0) {
        return ['success' => false, 'error' => 'Invalid user id'];
    }
    if ($actorUserId !== null && $actorUserId === $userId) {
        return ['success' => false, 'error' => 'You cannot delete your own user'];
    }
    $existing = getUserById($userId, false);
    if (!$existing) {
        return ['success' => true, 'already_deleted' => true];
    }

    $db = getDbConnection();
    $countQuery = function (string $sql, int $uid) use ($db): int {
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':uid', $uid, SQLITE3_INTEGER);
        $res = $stmt->execute();
        $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
        return $row ? (int)$row['c'] : 0;
    };

    $refs = [
        'tasks_created' => $countQuery('SELECT COUNT(*) AS c FROM tasks WHERE created_by_user_id = :uid', $userId),
        'tasks_assigned' => $countQuery('SELECT COUNT(*) AS c FROM tasks WHERE assigned_to_user_id = :uid', $userId),
        'task_comments' => $countQuery('SELECT COUNT(*) AS c FROM task_comments WHERE user_id = :uid', $userId),
        'task_attachments' => $countQuery('SELECT COUNT(*) AS c FROM task_attachments WHERE uploaded_by_user_id = :uid', $userId),
        'documents_created' => $countQuery('SELECT COUNT(*) AS c FROM documents WHERE created_by_user_id = :uid', $userId),
        'document_comments' => $countQuery('SELECT COUNT(*) AS c FROM document_comments WHERE user_id = :uid', $userId),
        'project_members' => $countQuery('SELECT COUNT(*) AS c FROM project_members WHERE user_id = :uid', $userId),
        'api_keys' => $countQuery('SELECT COUNT(*) AS c FROM api_keys WHERE user_id = :uid', $userId),
        'audit_logs' => $countQuery('SELECT COUNT(*) AS c FROM audit_logs WHERE actor_user_id = :uid', $userId),
    ];

    $hasContent = false;
    foreach ($refs as $k => $n) {
        if ($n > 0) {
            $hasContent = true;
            break;
        }
    }
    if ($hasContent && !$force) {
        return [
            'success' => false,
            'error' => 'User has referenced activity; pass force=true to delete anyway',
            'references' => $refs,
        ];
    }

    $db->exec('BEGIN');
    try {
        if ($hasContent) {
            // Null out task FKs (no ON DELETE SET NULL on these columns)
            $u = $db->prepare('UPDATE tasks SET assigned_to_user_id = NULL WHERE assigned_to_user_id = :uid');
            $u->bindValue(':uid', $userId, SQLITE3_INTEGER);
            $u->execute();
            // created_by_user_id is NOT NULL — reassign to actor (or admin id 1 fallback)
            $reassign = $actorUserId ?: 1;
            $u = $db->prepare('UPDATE tasks SET created_by_user_id = :aid WHERE created_by_user_id = :uid');
            $u->bindValue(':aid', $reassign, SQLITE3_INTEGER);
            $u->bindValue(':uid', $userId, SQLITE3_INTEGER);
            $u->execute();

            // Comments / attachments: reassign authorship
            foreach (['task_comments', 'task_attachments', 'document_comments'] as $tbl) {
                $col = $tbl === 'task_attachments' ? 'uploaded_by_user_id' : 'user_id';
                $u = $db->prepare("UPDATE {$tbl} SET {$col} = :aid WHERE {$col} = :uid");
                $u->bindValue(':aid', $reassign, SQLITE3_INTEGER);
                $u->bindValue(':uid', $userId, SQLITE3_INTEGER);
                $u->execute();
            }
            // Documents created: reassign
            $u = $db->prepare('UPDATE documents SET created_by_user_id = :aid WHERE created_by_user_id = :uid');
            $u->bindValue(':aid', $reassign, SQLITE3_INTEGER);
            $u->bindValue(':uid', $userId, SQLITE3_INTEGER);
            $u->execute();
            // project_members + api_keys: hard delete user-specific rows
            foreach (['project_members', 'api_keys'] as $tbl) {
                $u = $db->prepare("DELETE FROM {$tbl} WHERE user_id = :uid");
                $u->bindValue(':uid', $userId, SQLITE3_INTEGER);
                $u->execute();
            }
            // Audit log actor: null out (preserve history)
            $u = $db->prepare('UPDATE audit_logs SET actor_user_id = NULL WHERE actor_user_id = :uid');
            $u->bindValue(':uid', $userId, SQLITE3_INTEGER);
            $u->execute();
        }

        // Tables with ON DELETE CASCADE will clean up: user_organization_memberships,
        // user_project_pins, project_members (already cleaned above).
        $del = $db->prepare('DELETE FROM users WHERE id = :id');
        $del->bindValue(':id', $userId, SQLITE3_INTEGER);
        $del->execute();
        if ($db->changes() === 0) {
            $db->exec('ROLLBACK');
            return ['success' => false, 'error' => 'User not found'];
        }
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        return ['success' => false, 'error' => 'Delete failed: ' . $e->getMessage()];
    }

    createAuditLog($actorUserId, 'user.delete', 'user', (string)$userId, [
        'username' => (string)($existing['username'] ?? ''),
        'force' => $force ? 1 : 0,
        'references' => $refs,
    ]);
    return ['success' => true, 'references' => $refs];
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
const TASKS_API_KEY_KIND_STANDARD = 'standard';
const TASKS_API_KEY_KIND_Q_BRIDGE = 'q_bridge';

function normalizeApiKeyKind(?string $kind): string {
    $k = strtolower(trim((string)$kind));
    return $k === TASKS_API_KEY_KIND_Q_BRIDGE ? TASKS_API_KEY_KIND_Q_BRIDGE : TASKS_API_KEY_KIND_STANDARD;
}

function isHiddenApiKeyKind(?string $kind): bool {
    return normalizeApiKeyKind($kind) === TASKS_API_KEY_KIND_Q_BRIDGE;
}

/** SQL fragment: hide Q bridge keys from admin/API listings. */
function apiKeysVisibleToUserWhere(string $alias = 'ak'): string {
    $a = preg_replace('/[^a-z_]/', '', $alias) ?: 'ak';
    return "({$a}.key_kind IS NULL OR {$a}.key_kind = '' OR {$a}.key_kind = 'standard')";
}

/**
 * Deterministic per-user key for Q webchat tool injection (regenerated from master secret).
 * Never expose to browser; bridge PHP resolves by session user id.
 */
function deriveQBridgeDefaultApiKeyPlaintext(int $userId): string {
    if ($userId <= 0) {
        return '';
    }
    $secret = getQBridgeMasterSecret();
    return 'stq_' . hash_hmac('sha256', 'q-bridge-default-v1|' . (string)$userId, $secret);
}

/**
 * Idempotent: ensure active hidden q_bridge key row exists for user.
 * @return array{success:bool, created?:bool, error?:string}
 */
function ensureQBridgeDefaultApiKeyForUser(int $userId): array {
    if ($userId <= 0) {
        return ['success' => false, 'error' => 'Invalid user id'];
    }
    $user = getUserById($userId, false);
    if (!$user || (int)($user['is_active'] ?? 0) !== 1) {
        return ['success' => false, 'error' => 'User not found or inactive'];
    }

    $db = getDbConnection();

    $check = $db->prepare("
        SELECT id FROM api_keys
        WHERE user_id = :uid AND key_kind = :kind AND revoked_at IS NULL
        LIMIT 1
    ");
    $check->bindValue(':uid', $userId, SQLITE3_INTEGER);
    $check->bindValue(':kind', TASKS_API_KEY_KIND_Q_BRIDGE, SQLITE3_TEXT);
    $res = $check->execute();
    if ($res && $res->fetchArray(SQLITE3_ASSOC)) {
        return ['success' => true, 'created' => false];
    }

    $plaintext = deriveQBridgeDefaultApiKeyPlaintext($userId);
    $keyHash = hash('sha256', $plaintext);
    $keyPreview = substr($plaintext, 0, 12);

    $stmt = $db->prepare("
        INSERT INTO api_keys (user_id, key_name, api_key, api_key_hash, key_preview, created_by_user_id, key_kind)
        VALUES (:uid, :name, :key, :hash, :preview, NULL, :kind)
    ");
    $stmt->bindValue(':uid', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':name', 'Q bridge (system)', SQLITE3_TEXT);
    $stmt->bindValue(':key', $keyHash, SQLITE3_TEXT);
    $stmt->bindValue(':hash', $keyHash, SQLITE3_TEXT);
    $stmt->bindValue(':preview', $keyPreview, SQLITE3_TEXT);
    $stmt->bindValue(':kind', TASKS_API_KEY_KIND_Q_BRIDGE, SQLITE3_TEXT);
    try {
        $stmt->execute();
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'Failed to create Q bridge key'];
    }

    createAuditLog(null, 'api_key.create', 'api_key', (string)$db->lastInsertRowID(), [
        'user_id' => $userId,
        'key_kind' => TASKS_API_KEY_KIND_Q_BRIDGE,
        'hidden' => true,
    ]);
    return ['success' => true, 'created' => true];
}

/** Internal: plaintext Q bridge key for tool injection (bridge code only). */
function getQBridgeDefaultApiKeyPlaintextForUser(int $userId): ?string {
    $ensured = ensureQBridgeDefaultApiKeyForUser($userId);
    if (!$ensured['success']) {
        return null;
    }
    return deriveQBridgeDefaultApiKeyPlaintext($userId);
}

/** Backfill hidden Q bridge keys for all active users (idempotent). */
function backfillQBridgeDefaultApiKeysForAllUsers(): array {
    $db = getDbConnection();
    $res = $db->query('SELECT id FROM users WHERE is_active = 1 ORDER BY id ASC');
    $created = 0;
    $skipped = 0;
    $failed = 0;
    while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
        $r = ensureQBridgeDefaultApiKeyForUser((int)$row['id']);
        if (!$r['success']) {
            $failed++;
            continue;
        }
        if (!empty($r['created'])) {
            $created++;
        } else {
            $skipped++;
        }
    }
    return ['success' => true, 'created' => $created, 'skipped' => $skipped, 'failed' => $failed];
}

function createApiKeyForUser($userId, $keyName, ?int $createdByUserId = null): string {
    $db = getDbConnection();
    $apiKey = bin2hex(random_bytes(32));
    $keyHash = hash('sha256', $apiKey);
    $keyPreview = substr($apiKey, 0, 12);

    $stmt = $db->prepare("
        INSERT INTO api_keys (user_id, key_name, api_key, api_key_hash, key_preview, created_by_user_id, key_kind)
        VALUES (:uid, :name, :key, :hash, :preview, :created_by, :kind)
    ");
    $stmt->bindValue(':uid', (int)$userId, SQLITE3_INTEGER);
    $stmt->bindValue(':name', truncateString(trim((string)$keyName) ?: 'Unnamed Key', 80), SQLITE3_TEXT);
    $stmt->bindValue(':key', $keyHash, SQLITE3_TEXT);
    $stmt->bindValue(':hash', $keyHash, SQLITE3_TEXT);
    $stmt->bindValue(':preview', $keyPreview, SQLITE3_TEXT);
    $stmt->bindValue(':kind', TASKS_API_KEY_KIND_STANDARD, SQLITE3_TEXT);
    if ($createdByUserId === null) {
        $stmt->bindValue(':created_by', null, SQLITE3_NULL);
    } else {
        $stmt->bindValue(':created_by', (int)$createdByUserId, SQLITE3_INTEGER);
    }
    $stmt->execute();

    createAuditLog($createdByUserId, 'api_key.create', 'api_key', (string)$db->lastInsertRowID(), [
        'user_id' => (int)$userId,
        'key_name' => truncateString(trim((string)$keyName), 80),
        'key_kind' => TASKS_API_KEY_KIND_STANDARD,
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
            ak.last_used AS last_used,
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

    $throttleSeconds = defined('API_KEY_LAST_USED_THROTTLE_SECONDS')
        ? (int)API_KEY_LAST_USED_THROTTLE_SECONDS
        : 600;
    $shouldTouchLastUsed = true;
    $lastUsedRaw = $row['last_used'] ?? null;
    if ($lastUsedRaw !== null && $lastUsedRaw !== '') {
        $lastUsedTs = strtotime((string)$lastUsedRaw);
        if ($lastUsedTs !== false && (time() - $lastUsedTs) < $throttleSeconds) {
            $shouldTouchLastUsed = false;
        }
    }
    if ($shouldTouchLastUsed) {
        $update = $db->prepare("UPDATE api_keys SET last_used = CURRENT_TIMESTAMP WHERE id = :id");
        $update->bindValue(':id', (int)$row['api_key_id'], SQLITE3_INTEGER);
        $update->execute();
    }

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
        WHERE " . apiKeysVisibleToUserWhere('ak') . "
        " . ($includeRevoked ? "" : "AND ak.revoked_at IS NULL") . "
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
          AND " . apiKeysVisibleToUserWhere('api_keys') . "
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
    if ($creatorUser && normalizePersonKind($creatorUser['person_kind'] ?? 'team_member') === 'client') {
        return ['success' => false, 'error' => 'Clients have read-only access to tasks'];
    }
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

    if ($listIdOpt <= 0) {
        return ['success' => false, 'error' => 'A todo list is required. Provide list_id for a list in this project (GET /api/list-todo-lists.php?project_id=' . (int)$projectFk . ').'];
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
    $stmt->bindValue(':list_id', $listIdOpt, SQLITE3_INTEGER);
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
    $taskRow = getTaskById($id, false);
    if ($taskRow) {
        notificationsAfterTaskAssigned($createdByUserId, $taskRow, null, $assignedToUserId);
        if ($body !== null && trim((string)$body) !== '') {
            notificationsTaskBodyMentions($createdByUserId, $taskRow, '', (string)$body);
        }
    }
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

/**
 * Task write/delete side: stricter than read access.
 * - Unrestricted staff: full access
 * - Project-linked tasks: project managers/leads, plus creator fallback
 * - Unlinked tasks: creator only
 */
function userCanManageTaskForViewer(array $viewerRow, array $task): bool {
    if (userHasUnrestrictedOrgDirectoryAccess($viewerRow)) {
        return true;
    }

    $uid = (int)$viewerRow['id'];
    if ((int)($task['created_by_user_id'] ?? 0) === $uid) {
        return true;
    }

    $pid = isset($task['project_id']) ? (int)$task['project_id'] : 0;
    if ($pid > 0) {
        $proj = getDirectoryProjectById($pid);
        if ($proj && userCanManageDirectoryProject($viewerRow, $proj)) {
            return true;
        }
    }

    return false;
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

function userCanManageTask(int $userId, array $task, string $role): bool {
    unset($role);
    $viewer = getUserById($userId, false);
    if (!$viewer) {
        return false;
    }
    return userCanManageTaskForViewer($viewer, $task);
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
        } else {
            // Non-empty but unknown status must match nothing — never silently drop the filter
            // (that used to make Home "Blocked" count nearly every task via status=blocked).
            $where[] = '0';
        }
    }

    if (isset($filters['tag']) && trim((string)$filters['tag']) !== '') {
        $tag = truncateString(trim((string)$filters['tag']), 32);
        // tags_json is a JSON array of strings; match case-insensitively via json_each.
        $where[] = "EXISTS (
            SELECT 1 FROM json_each(IFNULL(t.tags_json, '[]')) AS je
            WHERE lower(je.value) = lower(:tag)
        )";
        $params[':tag'] = [$tag, SQLITE3_TEXT];
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
    } elseif (empty($filters['include_archived_projects'])) {
        // Cross-project lists (Home "all tasks", search, schedule): hide tasks on archived/trashed boards.
        // Explicit project_id (project workspace) still returns that board's tasks even when archived.
        $where[] = "(t.project_id IS NULL OR EXISTS (
            SELECT 1 FROM projects px
            WHERE px.id = t.project_id AND px.status = 'active'
        ))";
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

    if (!empty($filters['exclude_done'])) {
        $filterJoins[] = 'LEFT JOIN task_statuses ts_open ON ts_open.slug = t.status';
        $where[] = 'IFNULL(ts_open.is_done, 0) = 0';
    }

    if (!empty($filters['unassigned_only'])) {
        $where[] = 't.assigned_to_user_id IS NULL';
    }

    if (!empty($filters['updated_since'])) {
        $since = parseDateTimeOrNull($filters['updated_since']);
        if ($since !== null) {
            $where[] = 't.updated_at IS NOT NULL AND t.updated_at >= :updated_since';
            $params[':updated_since'] = [$since, SQLITE3_TEXT];
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

/**
 * Admin UI helper: fetch every matching task by paging through listTasks internally.
 * Avoids silent truncation when a board has more rows than one SQL LIMIT page.
 *
 * @return array{tasks: array<int, array>, total: int}
 */
function listAllTasks(array $filters, ?array $apiUser = null, ?array $directoryScopeUser = null): array {
    $all = [];
    $offset = 0;
    $pageSize = 500;
    $total = 0;
    do {
        $pageFilters = $filters;
        $pageFilters['limit'] = $pageSize;
        $pageFilters['offset'] = $offset;
        $result = listTasks($pageFilters, true, $apiUser, $directoryScopeUser);
        $total = (int)$result['total'];
        foreach ($result['tasks'] as $row) {
            $all[] = $row;
        }
        $offset += $pageSize;
    } while ($offset < $total);
    return ['tasks' => $all, 'total' => $total];
}

function updateTask($id, $fields = [], ?int $actorUserId = null): array {
    $id = (int)$id;
    if ($id <= 0) {
        return ['success' => false, 'error' => 'Invalid id'];
    }

    $existing = getTaskById($id, false);
    if (!$existing) {
        return ['success' => false, 'error' => 'Task not found'];
    }

    $workingFields = $fields;

    // Moving project without an explicit list_id: pick the first list in the target project.
    if (array_key_exists('project_id', $workingFields) && !array_key_exists('list_id', $workingFields)) {
        $oldPid = (int)($existing['project_id'] ?? 0);
        $newPid = (int)$workingFields['project_id'];
        if ($newPid !== $oldPid) {
            $dbMove = getDbConnection();
            $firstListId = getFirstTodoListIdForProject($dbMove, $newPid);
            if ($firstListId === null || $firstListId <= 0) {
                return ['success' => false, 'error' => 'That project has no todo lists yet; create one before moving this task.'];
            }
            $workingFields['list_id'] = $firstListId;
        }
    }

    if (array_key_exists('list_id', $workingFields) && ($workingFields['list_id'] === null || $workingFields['list_id'] === '')) {
        return ['success' => false, 'error' => 'list_id cannot be cleared; every task must belong to a todo list.'];
    }

    $mergedPid = array_key_exists('project_id', $workingFields)
        ? (int)$workingFields['project_id']
        : (int)($existing['project_id'] ?? 0);
    $mergedLid = array_key_exists('list_id', $workingFields)
        ? (int)$workingFields['list_id']
        : (int)($existing['list_id'] ?? 0);

    if ($mergedPid <= 0) {
        return ['success' => false, 'error' => 'project_id cannot be cleared; every task must belong to a directory project.'];
    }
    if ($mergedLid <= 0) {
        return ['success' => false, 'error' => 'A todo list is required. Set list_id to a list in this project.'];
    }

    $dbVal = getDbConnection();
    $lsVal = $dbVal->prepare('SELECT project_id FROM todo_lists WHERE id = :i LIMIT 1');
    $lsVal->bindValue(':i', $mergedLid, SQLITE3_INTEGER);
    $lrVal = $lsVal->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$lrVal || (int)$lrVal['project_id'] !== $mergedPid) {
        return ['success' => false, 'error' => 'list_id does not belong to the task project.'];
    }

    $fields = $workingFields;

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
        $sets[] = 'list_id = :list_id';
        $params[':list_id'] = [(int)$fields['list_id'], SQLITE3_INTEGER];
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

    $fresh = getTaskById($id, false);
    if ($fresh) {
        if (array_key_exists('assigned_to_user_id', $fields)) {
            $rawOld = $existing['assigned_to_user_id'] ?? null;
            $oldA = $rawOld !== null && $rawOld !== '' ? (int)$rawOld : null;
            if ($oldA !== null && $oldA <= 0) {
                $oldA = null;
            }
            $rawNew = $fields['assigned_to_user_id'];
            $newA = ($rawNew === null || $rawNew === '')
                ? null
                : (int)$rawNew;
            if ($newA !== null && $newA <= 0) {
                $newA = null;
            }
            notificationsAfterTaskAssigned($actorUserId, $fresh, $oldA, $newA);
        }
        if (array_key_exists('body', $fields)) {
            $newBody = normalizeTaskBody($fields['body']);
            $newBodyStr = $newBody ?? '';
            notificationsTaskBodyMentions(
                $actorUserId,
                $fresh,
                (string)($existing['body'] ?? ''),
                (string)$newBodyStr
            );
        }
    }

    return ['success' => true];
}

function deleteTask($id): array {
    $id = (int)$id;
    if ($id <= 0) {
        return ['success' => false, 'error' => 'Invalid id'];
    }
    $existing = getTaskById($id, false);
    $db = getDbConnection();
    $attachments = listTaskAttachments($id);
    foreach ($attachments as $attachment) {
        deleteLocalTaskAttachmentFile($attachment);
    }
    $stmt = $db->prepare("DELETE FROM tasks WHERE id = :id");
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();
    $meta = [];
    if ($existing) {
        $pid = (int)($existing['project_id'] ?? 0);
        if ($pid > 0) {
            $meta['project_id'] = $pid;
        }
        $meta['title'] = (string)($existing['title'] ?? '');
    }
    createAuditLog(null, 'task.delete', 'task', (string)$id, $meta);
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

function getAppSetting(string $settingKey): ?string {
    $settingKey = trim($settingKey);
    if ($settingKey === '' || !preg_match('/^[a-z0-9_.-]{1,100}$/i', $settingKey)) {
        return null;
    }
    $db = getDbConnection();
    $stmt = $db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :k LIMIT 1');
    $stmt->bindValue(':k', $settingKey, SQLITE3_TEXT);
    $res = $stmt->execute();
    $row = $res->fetchArray(SQLITE3_ASSOC);
    if (!$row || !array_key_exists('setting_value', $row)) {
        return null;
    }
    return $row['setting_value'] === null ? null : (string)$row['setting_value'];
}

function setAppSetting(string $settingKey, ?string $settingValue, ?int $actorUserId = null): array {
    $settingKey = trim($settingKey);
    if ($settingKey === '' || !preg_match('/^[a-z0-9_.-]{1,100}$/i', $settingKey)) {
        return ['success' => false, 'error' => 'Invalid setting key'];
    }
    $db = getDbConnection();
    if ($settingValue === null || $settingValue === '') {
        $del = $db->prepare('DELETE FROM app_settings WHERE setting_key = :k');
        $del->bindValue(':k', $settingKey, SQLITE3_TEXT);
        $del->execute();
        createAuditLog($actorUserId, 'app_setting.delete', 'app_setting', $settingKey);
        return ['success' => true, 'setting_key' => $settingKey, 'setting_value' => null];
    }
    $stmt = $db->prepare('
        INSERT INTO app_settings (setting_key, setting_value, updated_at)
        VALUES (:k, :v, CURRENT_TIMESTAMP)
        ON CONFLICT(setting_key) DO UPDATE SET
            setting_value = excluded.setting_value,
            updated_at = CURRENT_TIMESTAMP
    ');
    $stmt->bindValue(':k', $settingKey, SQLITE3_TEXT);
    $stmt->bindValue(':v', $settingValue, SQLITE3_TEXT);
    $stmt->execute();
    createAuditLog($actorUserId, 'app_setting.update', 'app_setting', $settingKey, [
        'setting_value' => $settingValue,
    ]);
    return ['success' => true, 'setting_key' => $settingKey, 'setting_value' => $settingValue];
}

/** Navbar / login / document title label (CRM-style branding). */
function getAppName(): string {
    $fallback = defined('TASKS_APP_NAME_DEFAULT') ? (string)TASKS_APP_NAME_DEFAULT : 'Sanctum Tasks';
    if (!function_exists('getAppSetting')) {
        return $fallback;
    }
    $raw = getAppSetting('app_name');
    if ($raw === null) {
        return $fallback;
    }
    $name = trim($raw);
    return $name !== '' ? $name : $fallback;
}

function normalizeAppNameInput(string $raw): array {
    $appName = trim($raw);
    if ($appName === '') {
        return ['success' => false, 'error' => 'Application name is required.'];
    }
    if (strlen($appName) > 100) {
        return ['success' => false, 'error' => 'Application name must be 100 characters or fewer.'];
    }
    if (preg_match('/<[^>]*>/', $appName)) {
        return ['success' => false, 'error' => 'Application name cannot contain HTML.'];
    }
    return ['success' => true, 'app_name' => $appName];
}

function updateAppNameSetting(string $appName, ?int $actorUserId): array {
    $normalized = normalizeAppNameInput($appName);
    if (empty($normalized['success'])) {
        return $normalized;
    }
    $saved = setAppSetting('app_name', (string)$normalized['app_name'], $actorUserId);
    if (empty($saved['success'])) {
        return $saved;
    }
    return ['success' => true, 'app_name' => (string)$normalized['app_name']];
}

/** Default Home widget visibility (cross-project board off — it hydrates all tasks). */
function homeWidgetDefaults(): array {
    return [
        'pulse_kpis' => true,
        'my_work' => true,
        'pinned_boards' => true,
        'board_health' => true,
        'inbox_peek' => true,
        'schedule_peek' => false,
        'projects_hub' => true,
        'cross_project_board' => false,
        'recent_activity' => true,
    ];
}

function homeWidgetKeys(): array {
    return array_keys(homeWidgetDefaults());
}

function normalizeHomeWidgets(?array $raw): array {
    $out = homeWidgetDefaults();
    if (!is_array($raw)) {
        return $out;
    }
    foreach (homeWidgetKeys() as $key) {
        if (array_key_exists($key, $raw)) {
            $out[$key] = !empty($raw[$key]);
        }
    }
    return $out;
}

function getHomeWidgetsForUser(?array $userRow): array {
    if ($userRow === null) {
        return homeWidgetDefaults();
    }
    $json = $userRow['home_widgets_json'] ?? null;
    if ($json === null || $json === '') {
        return homeWidgetDefaults();
    }
    $decoded = json_decode((string)$json, true);
    return normalizeHomeWidgets(is_array($decoded) ? $decoded : null);
}

function updateUserHomeWidgets(int $userId, array $widgets): array {
    if ($userId <= 0) {
        return ['success' => false, 'error' => 'Invalid user'];
    }
    $normalized = normalizeHomeWidgets($widgets);
    $db = getDbConnection();
    $stmt = $db->prepare('UPDATE users SET home_widgets_json = :j WHERE id = :id');
    $stmt->bindValue(':j', json_encode($normalized, JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    $stmt->execute();
    createAuditLog($userId, 'user.home_widgets.update', 'user', (string)$userId, $normalized);
    return ['success' => true, 'home_widgets' => $normalized];
}

/**
 * Lightweight Home KPI counts (paginated totals only — no task bodies).
 *
 * @return array{assigned_open:int,blocked:int,unassigned_open:int,updated_today:int}
 */
function computeHomePulseKpis(array $viewer): array {
    $uid = (int)($viewer['id'] ?? 0);
    $todayStart = gmdate('Y-m-d') . ' 00:00:00';

    $assigned = listTasks([
        'assigned_to_user_id' => $uid,
        'exclude_done' => true,
        'limit' => 1,
    ], true, null, $viewer);

    // "Blocked" is a tag in this product, not a workflow status (statuses are todo/doing/done).
    $blocked = listTasks([
        'tag' => 'blocked',
        'exclude_done' => true,
        'limit' => 1,
    ], true, null, $viewer);

    $unassigned = listTasks([
        'unassigned_only' => true,
        'exclude_done' => true,
        'limit' => 1,
    ], true, null, $viewer);

    $updated = listTasks([
        'updated_since' => $todayStart,
        'limit' => 1,
    ], true, null, $viewer);

    return [
        'assigned_open' => (int)($assigned['total'] ?? 0),
        'blocked' => (int)($blocked['total'] ?? 0),
        'unassigned_open' => (int)($unassigned['total'] ?? 0),
        'updated_today' => (int)($updated['total'] ?? 0),
    ];
}

function updateOrganizationDefaultSkin(int $orgId, ?string $skinSlug, ?int $actorUserId): array {
    if ($orgId <= 0) {
        return ['success' => false, 'error' => 'Invalid organization'];
    }
    require_once __DIR__ . '/skin-lab-env.php';
    $normalized = $skinSlug === null || $skinSlug === ''
        ? null
        : skinLabNormalizeSlug($skinSlug);
    if ($skinSlug !== null && $skinSlug !== '' && $normalized === null) {
        return ['success' => false, 'error' => 'Invalid skin'];
    }
    $org = getOrganizationById($orgId);
    if (!$org) {
        return ['success' => false, 'error' => 'Organization not found'];
    }
    $settings = [];
    if (!empty($org['settings_json'])) {
        $decoded = json_decode((string)$org['settings_json'], true);
        if (is_array($decoded)) {
            $settings = $decoded;
        }
    }
    if ($normalized === null) {
        unset($settings['default_skin_slug']);
    } else {
        $settings['default_skin_slug'] = $normalized;
    }
    $json = $settings === [] ? null : json_encode($settings, JSON_UNESCAPED_UNICODE);
    $db = getDbConnection();
    $stmt = $db->prepare('UPDATE organizations SET settings_json = :s WHERE id = :id');
    if ($json === null) {
        $stmt->bindValue(':s', null, SQLITE3_NULL);
    } else {
        $stmt->bindValue(':s', $json, SQLITE3_TEXT);
    }
    $stmt->bindValue(':id', $orgId, SQLITE3_INTEGER);
    $stmt->execute();
    createAuditLog($actorUserId, 'organization.update', 'organization', (string)$orgId, [
        'default_skin_slug' => $normalized,
    ]);
    return ['success' => true, 'default_skin_slug' => $normalized ?? 'hey'];
}

function updateUserSkinPreference(int $userId, ?string $skinSlug): array {
    if ($userId <= 0) {
        return ['success' => false, 'error' => 'Invalid user'];
    }
    require_once __DIR__ . '/skin-lab-env.php';
    $useOrgDefault = $skinSlug === null || $skinSlug === '' || $skinSlug === '__org__';
    $normalized = null;
    if (!$useOrgDefault) {
        $normalized = skinLabNormalizeSlug($skinSlug);
        if ($normalized === null) {
            return ['success' => false, 'error' => 'Invalid skin'];
        }
    }
    $db = getDbConnection();
    $stmt = $db->prepare('UPDATE users SET skin_slug = :s WHERE id = :id');
    if ($normalized === null) {
        $stmt->bindValue(':s', null, SQLITE3_NULL);
    } else {
        $stmt->bindValue(':s', $normalized, SQLITE3_TEXT);
    }
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    $stmt->execute();
    return ['success' => true, 'skin_slug' => $normalized];
}

function updateMasterSkinPreference(?string $skinSlug, ?int $actorUserId): array {
    require_once __DIR__ . '/skin-lab-env.php';
    $normalized = $skinSlug === null || $skinSlug === ''
        ? null
        : skinLabNormalizeSlug($skinSlug);
    if ($skinSlug !== null && $skinSlug !== '' && $normalized === null) {
        return ['success' => false, 'error' => 'Invalid skin'];
    }
    $saved = setAppSetting('master_skin_slug', $normalized, $actorUserId);
    if (!$saved['success']) {
        return $saved;
    }
    return ['success' => true, 'master_skin_slug' => $normalized ?? 'hey'];
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

/**
 * Admin: set users.person_kind (team_member | client).
 * Clients are read-only for create-task / create-document; flip to team_member for collaborators who should file work.
 */
function setUserPersonKind(int $actorUserId, int $targetUserId, string $personKind): array {
    if ($targetUserId <= 0) {
        return ['success' => false, 'error' => 'Invalid user'];
    }
    $tgt = getUserById($targetUserId, false);
    if (!$tgt) {
        return ['success' => false, 'error' => 'User not found'];
    }
    $pk = normalizePersonKind($personKind);
    $db = getDbConnection();
    $stmt = $db->prepare('UPDATE users SET person_kind = :pk WHERE id = :id');
    $stmt->bindValue(':pk', $pk, SQLITE3_TEXT);
    $stmt->bindValue(':id', $targetUserId, SQLITE3_INTEGER);
    $stmt->execute();
    createAuditLog($actorUserId, 'user.person_kind_set', 'user', (string)$targetUserId, [
        'person_kind' => $pk,
        'previous' => normalizePersonKind($tgt['person_kind'] ?? 'team_member'),
    ]);
    return ['success' => true, 'person_kind' => $pk];
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
    $rows = listDirectoryProjectsForUser($userRow, 500, ['include_archived' => true]);
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
 * Create a task on a project the viewer can already open.
 * Team members yes; clients no. Distinct from manage (lead/admin) — New task must not require lead.
 */
function userCanCreateTaskOnProject(array $userRow, array $project): bool {
    if (!userCanAccessDirectoryProject($userRow, $project)) {
        return false;
    }
    return normalizePersonKind($userRow['person_kind'] ?? 'team_member') !== 'client';
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
 *
 * @param array{include_archived?:bool} $options When include_archived is false (default), only active projects are listed.
 */
function listDirectoryProjectsForUser(array $userRow, int $limit = 200, array $options = []): array {
    $limit = max(1, min(500, $limit));
    $includeArchived = !empty($options['include_archived']);
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
    $statusClause = $includeArchived ? "status != 'trashed'" : "status = 'active'";
    $statusClauseP = $includeArchived ? "p.status != 'trashed'" : "p.status = 'active'";
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
            WHERE org_id IN ($inSql) AND {$statusClause}{$cvClause}
            ORDER BY name COLLATE NOCASE ASC
            LIMIT :lim
        ";
    } else {
        $sql = "
            SELECT DISTINCT p.id, p.org_id, p.name, p.description, p.status, p.client_visible, p.all_access, p.created_at, p.updated_at
            FROM projects p
            LEFT JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = :uid
            WHERE p.org_id IN ($inSql)
              AND {$statusClauseP}{$cvClauseP}
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
    if (normalizePersonKind($target['person_kind'] ?? 'team_member') === 'client' && empty((int)($proj['client_visible'] ?? 0))) {
        return ['success' => false, 'error' => 'Client users can only be added to client-visible projects'];
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

/**
 * First todo_list id for a project (sort_order, then id), or null when none exist.
 */
function getFirstTodoListIdForProject(SQLite3 $db, int $projectId): ?int {
    if ($projectId <= 0) {
        return null;
    }
    $stmt = $db->prepare('SELECT id FROM todo_lists WHERE project_id = :p ORDER BY sort_order ASC, id ASC LIMIT 1');
    $stmt->bindValue(':p', $projectId, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        return null;
    }
    return (int)$row['id'];
}

/** To-do lists under a directory project (requires directory access). */
function listTodoListsForProject(array $userRow, int $projectId, bool $includeArchived = false): array {
    if ($projectId <= 0) {
        return [];
    }
    $proj = getDirectoryProjectById($projectId);
    if (!$proj || !userCanAccessDirectoryProject($userRow, $proj)) {
        return [];
    }
    $db = getDbConnection();
    $archivedClause = $includeArchived ? '' : ' AND archived_at IS NULL';
    $stmt = $db->prepare(
        'SELECT id, project_id, name, sort_order, created_at, archived_at
         FROM todo_lists
         WHERE project_id = :p' . $archivedClause . '
         ORDER BY sort_order ASC, name COLLATE NOCASE ASC'
    );
    $stmt->bindValue(':p', $projectId, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $rows = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $row['id'] = (int)$row['id'];
        $row['project_id'] = (int)$row['project_id'];
        $row['sort_order'] = (int)$row['sort_order'];
        $row['archived_at'] = isset($row['archived_at']) && $row['archived_at'] !== null && $row['archived_at'] !== ''
            ? (string)$row['archived_at']
            : null;
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

/**
 * Hide a to-do list from the default Lists view (tasks remain; list is restorable).
 *
 * @return array{success: bool, error?: string, id?: int}
 */
function archiveTodoList(int $userId, int $listId): array {
    if ($listId <= 0) {
        return ['success' => false, 'error' => 'Invalid list id'];
    }
    $db = getDbConnection();
    $stmt = $db->prepare('SELECT id, project_id, name, archived_at FROM todo_lists WHERE id = :i LIMIT 1');
    $stmt->bindValue(':i', $listId, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        return ['success' => false, 'error' => 'List not found'];
    }
    $projectId = (int)$row['project_id'];
    $proj = getDirectoryProjectById($projectId);
    $actor = getUserById($userId, false);
    if (!$proj || !$actor || !userCanManageDirectoryProject($actor, $proj)) {
        return ['success' => false, 'error' => 'Insufficient permission'];
    }
    if (!empty($row['archived_at'])) {
        return ['success' => true, 'id' => $listId];
    }
    $up = $db->prepare('UPDATE todo_lists SET archived_at = CURRENT_TIMESTAMP WHERE id = :i');
    $up->bindValue(':i', $listId, SQLITE3_INTEGER);
    $up->execute();
    createAuditLog($userId, 'todo_list.archive', 'todo_list', (string)$listId, [
        'project_id' => $projectId,
        'name' => (string)($row['name'] ?? ''),
    ]);
    return ['success' => true, 'id' => $listId];
}

/**
 * Restore an archived to-do list to the default Lists view.
 *
 * @return array{success: bool, error?: string, id?: int}
 */
function unarchiveTodoList(int $userId, int $listId): array {
    if ($listId <= 0) {
        return ['success' => false, 'error' => 'Invalid list id'];
    }
    $db = getDbConnection();
    $stmt = $db->prepare('SELECT id, project_id, name FROM todo_lists WHERE id = :i LIMIT 1');
    $stmt->bindValue(':i', $listId, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        return ['success' => false, 'error' => 'List not found'];
    }
    $projectId = (int)$row['project_id'];
    $proj = getDirectoryProjectById($projectId);
    $actor = getUserById($userId, false);
    if (!$proj || !$actor || !userCanManageDirectoryProject($actor, $proj)) {
        return ['success' => false, 'error' => 'Insufficient permission'];
    }
    $up = $db->prepare('UPDATE todo_lists SET archived_at = NULL WHERE id = :i');
    $up->bindValue(':i', $listId, SQLITE3_INTEGER);
    $up->execute();
    createAuditLog($userId, 'todo_list.unarchive', 'todo_list', (string)$listId, [
        'project_id' => $projectId,
        'name' => (string)($row['name'] ?? ''),
    ]);
    return ['success' => true, 'id' => $listId];
}

/**
 * Delete a todo list. Refuses if it still has tasks, or if it is the only list left on the project.
 *
 * @return array{success: bool, error?: string, deleted?: bool, id?: int, already_deleted?: bool}
 */
function deleteTodoList(int $userId, int $listId): array {
    if ($listId <= 0) {
        return ['success' => false, 'error' => 'Invalid list id'];
    }
    $db = getDbConnection();
    $stmt = $db->prepare('SELECT id, project_id, name FROM todo_lists WHERE id = :i LIMIT 1');
    $stmt->bindValue(':i', $listId, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        return ['success' => true, 'deleted' => true, 'already_deleted' => true, 'id' => $listId];
    }
    $projectId = (int)$row['project_id'];
    $proj = getDirectoryProjectById($projectId);
    $actor = getUserById($userId, false);
    if (!$proj || !$actor || !userCanManageDirectoryProject($actor, $proj)) {
        return ['success' => false, 'error' => 'Insufficient permission'];
    }

    $countStmt = $db->prepare('SELECT COUNT(*) AS c FROM todo_lists WHERE project_id = :p');
    $countStmt->bindValue(':p', $projectId, SQLITE3_INTEGER);
    $countRow = $countStmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ((int)($countRow['c'] ?? 0) <= 1) {
        return ['success' => false, 'error' => 'Cannot delete the only todo list on a project'];
    }

    $taskStmt = $db->prepare('SELECT COUNT(*) AS c FROM tasks WHERE list_id = :i');
    $taskStmt->bindValue(':i', $listId, SQLITE3_INTEGER);
    $taskRow = $taskStmt->execute()->fetchArray(SQLITE3_ASSOC);
    $taskCount = (int)($taskRow['c'] ?? 0);
    if ($taskCount > 0) {
        return [
            'success' => false,
            'error' => 'Todo list still has tasks; move or delete them first',
            'task_count' => $taskCount,
        ];
    }

    $del = $db->prepare('DELETE FROM todo_lists WHERE id = :i');
    $del->bindValue(':i', $listId, SQLITE3_INTEGER);
    $del->execute();
    createAuditLog($userId, 'todo_list.delete', 'todo_list', (string)$listId, [
        'project_id' => $projectId,
        'name' => (string)($row['name'] ?? ''),
    ]);
    return ['success' => true, 'deleted' => true, 'id' => $listId];
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
    $taskRow = getTaskById($taskId, false);
    if ($taskRow) {
        notificationsAfterTaskComment($taskRow, $id, $userId, $comment);
    }
    return ['success' => true, 'id' => $id, 'created_at' => $createdAt];
}

function listTaskAttachments(int $taskId): array {
    $db = getDbConnection();
    $stmt = $db->prepare("
        SELECT a.id, a.task_id, a.uploaded_by_user_id, u.username AS uploaded_by_username,
               a.file_name, a.file_url, a.mime_type, a.size_bytes, a.storage_kind, a.storage_rel_path, a.created_at
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

function allowedTaskAssetMimeTypes(): array {
    return [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'video/mp4',
        'application/pdf',
        'text/plain',
        'text/csv',
        'text/markdown',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
        'application/x-zip-compressed',
    ];
}

function isAllowedTaskAssetMimeType(string $mimeType): bool {
    return in_array(strtolower(trim($mimeType)), allowedTaskAssetMimeTypes(), true);
}

function taskAssetStorageRoot(): string {
    return rtrim(TASKS_ASSET_STORAGE_DIR, '/');
}

function ensureTaskAssetStorageDir(): void {
    ensureDirExists(taskAssetStorageRoot());
}

function normalizeTaskAttachmentStorageKind(?string $kind): string {
    $kind = strtolower(trim((string)$kind));
    if ($kind === 'local') {
        return 'local';
    }
    return 'remote';
}

function taskAttachmentAbsolutePath(string $storageRelPath): ?string {
    $rel = trim($storageRelPath);
    if ($rel === '' || strpos($rel, '..') !== false || str_starts_with($rel, '/')) {
        return null;
    }
    $root = taskAssetStorageRoot();
    $full = $root . '/' . $rel;
    $dir = dirname($full);
    $rootReal = realpath($root);
    $dirReal = realpath($dir);
    if ($rootReal === false || $dirReal === false) {
        return null;
    }
    if (!str_starts_with($dirReal, $rootReal)) {
        return null;
    }
    return $full;
}

function buildTaskAssetStorageRelPath(int $taskId, string $mimeType): string {
    $extMap = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'text/markdown' => 'md',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/zip' => 'zip',
        'application/x-zip-compressed' => 'zip',
    ];
    $ext = $extMap[strtolower($mimeType)] ?? 'bin';
    $random = bin2hex(random_bytes(16));
    return 'task-' . $taskId . '/' . gmdate('Y/m') . '/' . $random . '.' . $ext;
}

function persistTaskAssetUpload(int $taskId, string $tmpPath, string $mimeType): array {
    if (!is_file($tmpPath)) {
        return ['success' => false, 'error' => 'Uploaded file temp path missing'];
    }
    ensureTaskAssetStorageDir();
    $rel = buildTaskAssetStorageRelPath($taskId, $mimeType);
    // taskAttachmentAbsolutePath() uses realpath() on the parent dir; nested paths do not
    // exist until we mkdir here — create before the safety check or realpath() fails.
    $root = taskAssetStorageRoot();
    ensureDirExists(dirname($root . '/' . $rel));
    $abs = taskAttachmentAbsolutePath($rel);
    if ($abs === null) {
        return ['success' => false, 'error' => 'Could not compute safe storage path'];
    }
    if (!@move_uploaded_file($tmpPath, $abs)) {
        if (!@rename($tmpPath, $abs)) {
            return ['success' => false, 'error' => 'Failed to move uploaded file'];
        }
    }
    @chmod($abs, 0640);
    return ['success' => true, 'storage_rel_path' => $rel];
}

/**
 * Markdown for pasting into a task body/comment.
 * Images use ![](url); other types (PDF, docs, zip, …) use a plain link.
 */
function taskAttachmentMarkdownSnippet(string $fileName, string $fileUrl, ?string $mimeType = null): string {
    $alt = str_replace(['[', ']'], '', trim($fileName));
    if ($alt === '') {
        $alt = 'attachment';
    }
    $url = trim($fileUrl);
    $mime = strtolower(trim((string)$mimeType));
    $inlineImage = $mime !== '' && str_starts_with($mime, 'image/');
    if (!$inlineImage && $mime === '') {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $inlineImage = in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true);
    }
    if ($inlineImage) {
        return '![' . $alt . '](' . $url . ')';
    }
    return '[' . $alt . '](' . $url . ')';
}

function deleteLocalTaskAttachmentFile(array $attachment): void {
    $kind = normalizeTaskAttachmentStorageKind((string)($attachment['storage_kind'] ?? ''));
    if ($kind !== 'local') {
        return;
    }
    $rel = trim((string)($attachment['storage_rel_path'] ?? ''));
    if ($rel === '') {
        return;
    }
    $abs = taskAttachmentAbsolutePath($rel);
    if ($abs === null || !is_file($abs)) {
        return;
    }
    @unlink($abs);
}

function addTaskAttachment(int $taskId, int $uploadedByUserId, string $fileName, string $fileUrl, ?string $mimeType = null, ?int $sizeBytes = null, array $options = []): array {
    $fileName = trim($fileName);
    $fileUrl = trim($fileUrl);
    $storageKind = normalizeTaskAttachmentStorageKind((string)($options['storage_kind'] ?? 'remote'));
    $storageRelPath = normalizeNullableText((string)($options['storage_rel_path'] ?? ''), 600);
    if ($fileName === '' || $fileUrl === '') {
        return ['success' => false, 'error' => 'file_name and file_url are required'];
    }
    if ($storageKind !== 'local' && !filter_var($fileUrl, FILTER_VALIDATE_URL)) {
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
        INSERT INTO task_attachments (task_id, uploaded_by_user_id, file_name, file_url, mime_type, size_bytes, storage_kind, storage_rel_path)
        VALUES (:task_id, :uploaded_by_user_id, :file_name, :file_url, :mime_type, :size_bytes, :storage_kind, :storage_rel_path)
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
    $stmt->bindValue(':storage_kind', $storageKind, SQLITE3_TEXT);
    $stmt->bindValue(':storage_rel_path', $storageRelPath, $storageRelPath === null ? SQLITE3_NULL : SQLITE3_TEXT);
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
        $res = updateTask($id, $fields, null);
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

// --------------------
// Documents
// --------------------
//
// Long-form markdown reference material attached to a directory project.
// Each document has its own discussion thread (document_comments), mirroring
// task comments. Documents are scoped by project access — viewers must be
// able to access the project the doc belongs to.

function normalizeDocumentTitle($title): ?string {
    $title = trim((string)$title);
    if ($title === '') return null;
    return truncateString($title, 200);
}

function normalizeDocumentBody($body): ?string {
    if ($body === null) return null;
    $body = (string)$body;
    if (trim($body) === '') return null;
    // Documents are long-form (transcripts, research dumps); cap ~1 MB markdown.
    return truncateString($body, 1000000);
}

/** 64-character hex secret for unauthenticated `/shared-document.php` access. */
function normalizeDocumentPublicLinkHexToken($token): ?string {
    $t = strtolower(trim((string)$token));
    return preg_match('/^[a-f0-9]{64}$/', $t) ? $t : null;
}

function normalizeDocumentDirectoryPath($path): string {
    $path = trim((string)$path);
    if ($path === '' || $path === '/') return '';
    $parts = preg_split('#/+#', str_replace('\\', '/', $path));
    if (!is_array($parts)) return '';
    $clean = [];
    foreach ($parts as $part) {
        $part = trim((string)$part);
        if ($part === '' || $part === '.' || $part === '..') continue;
        $clean[] = truncateString($part, 80);
    }
    return truncateString(implode('/', $clean), 500);
}

/**
 * Virtual folder grouping for docs list UIs (/admin/docs.php and project › Docs tab).
 * $currentDir uses normalized slash paths; '' means project/library root segment.
 *
 * @param array<int, array<string,mixed>> $documents Rows from listDocumentsForUser()
 * @return array{dir_children: array<string,int>, documents_in_dir: array<int, array<string,mixed>>}
 */
function aggregateDocumentsForDirectoryView(array $documents, string $currentDir): array {
    $currentDir = normalizeDocumentDirectoryPath($currentDir);
    $dirChildren = [];
    $documentsInDir = [];
    foreach ($documents as $d) {
        $docDir = normalizeDocumentDirectoryPath((string)($d['directory_path'] ?? ''));
        if ($currentDir === '') {
            if ($docDir === '') {
                $documentsInDir[] = $d;
                continue;
            }
            $top = explode('/', $docDir, 2)[0];
            if ($top !== '') {
                $dirChildren[$top] = ($dirChildren[$top] ?? 0) + 1;
            }
            continue;
        }
        if ($docDir === $currentDir) {
            $documentsInDir[] = $d;
            continue;
        }
        $prefix = $currentDir . '/';
        if (str_starts_with($docDir, $prefix)) {
            $rest = substr($docDir, strlen($prefix));
            $next = explode('/', $rest, 2)[0];
            if ($next !== '') {
                $dirChildren[$next] = ($dirChildren[$next] ?? 0) + 1;
            }
        }
    }
    ksort($dirChildren, SORT_NATURAL | SORT_FLAG_CASE);
    return ['dir_children' => $dirChildren, 'documents_in_dir' => $documentsInDir];
}

function userCanAccessDocument(array $userRow, ?array $document): bool {
    if (!$document) return false;
    $pid = (int)($document['project_id'] ?? 0);
    if ($pid <= 0) return false;
    $proj = getDirectoryProjectById($pid);
    return $proj !== null && userCanAccessDirectoryProject($userRow, $proj);
}

function userCanManageDocument(array $userRow, ?array $document): bool {
    if (!$document) return false;
    if (!userCanAccessDocument($userRow, $document)) return false;
    if (normalizePersonKind($userRow['person_kind'] ?? 'team_member') === 'client') {
        return false;
    }
    $role = (string)($userRow['role'] ?? 'member');
    if ($role === 'admin') return true;
    if ($role === 'manager' && userHasUnrestrictedOrgDirectoryAccess($userRow)) return true;
    if ((int)($document['created_by_user_id'] ?? 0) === (int)$userRow['id']) return true;
    $pid = (int)$document['project_id'];
    $member = getProjectMemberRole((int)$userRow['id'], $pid);
    return $member === 'lead';
}

function createDocument(int $userId, int $projectId, string $title, ?string $body = null, ?string $directoryPath = null, bool $enablePublicSharing = false): array {
    $title = normalizeDocumentTitle($title);
    if ($title === null) {
        return ['success' => false, 'error' => 'Title is required'];
    }
    if ($projectId <= 0) {
        return ['success' => false, 'error' => 'project_id is required'];
    }
    $body = normalizeDocumentBody($body);
    $directoryPath = normalizeDocumentDirectoryPath($directoryPath ?? '');
    $proj = getDirectoryProjectById($projectId);
    if (!$proj) {
        return ['success' => false, 'error' => 'Project not found'];
    }
    $user = getUserById($userId, false);
    if (!$user) {
        return ['success' => false, 'error' => 'Invalid user'];
    }
    if (!userCanAccessDirectoryProject($user, $proj)) {
        return ['success' => false, 'error' => 'You do not have access to this project'];
    }
    if (normalizePersonKind($user['person_kind'] ?? 'team_member') === 'client') {
        return ['success' => false, 'error' => 'Clients have read-only access to documents'];
    }

    $publicEnabled = $enablePublicSharing ? 1 : 0;
    $shareTok = '';
    if ($enablePublicSharing) {
        $shareTok = bin2hex(random_bytes(32));
    }

    $db = getDbConnection();
    $stmt = $db->prepare("
        INSERT INTO documents (project_id, title, directory_path, body, status, created_by_user_id,
            public_link_enabled, public_link_token, created_at, updated_at)
        VALUES (:project_id, :title, :directory_path, :body, 'active', :uid,
            :ple, :plink, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ");
    $stmt->bindValue(':project_id', $projectId, SQLITE3_INTEGER);
    $stmt->bindValue(':title', $title, SQLITE3_TEXT);
    $stmt->bindValue(':directory_path', $directoryPath, SQLITE3_TEXT);
    $stmt->bindValue(':body', $body, $body === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $stmt->bindValue(':uid', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':ple', $publicEnabled, SQLITE3_INTEGER);
    $stmt->bindValue(':plink', $shareTok === '' ? null : $shareTok, $shareTok === '' ? SQLITE3_NULL : SQLITE3_TEXT);
    $stmt->execute();
    $id = (int)$db->lastInsertRowID();
    createAuditLog($userId, 'document.create', 'document', (string)$id, ['project_id' => $projectId]);
    $docRow = getDocumentById($id, false);
    if ($docRow && $body !== null && trim((string)$body) !== '') {
        notificationsDocumentBodyMentions($userId, $docRow, '', (string)$body);
    }
    return ['success' => true, 'id' => $id];
}

function getDocumentById(int $id, bool $withRelations = true): ?array {
    if ($id <= 0) return null;
    $db = getDbConnection();
    $stmt = $db->prepare("
        SELECT d.id, d.project_id, d.title, d.directory_path, d.body, d.status,
               d.created_by_user_id, d.created_at, d.updated_at,
               d.public_link_enabled, d.public_link_token,
               cu.username AS created_by_username,
               p.name AS project_name,
               (SELECT COUNT(*) FROM document_comments dc WHERE dc.document_id = d.id) AS comment_count
        FROM documents d
        JOIN users cu ON cu.id = d.created_by_user_id
        LEFT JOIN projects p ON p.id = d.project_id
        WHERE d.id = :id LIMIT 1
    ");
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $row = $res->fetchArray(SQLITE3_ASSOC);
    if (!$row) return null;
    $row['id'] = (int)$row['id'];
    $row['project_id'] = (int)$row['project_id'];
    $row['created_by_user_id'] = (int)$row['created_by_user_id'];
    $row['comment_count'] = (int)$row['comment_count'];
    $row['public_link_enabled'] = !empty((int)($row['public_link_enabled'] ?? 0));
    if (($row['public_link_token'] ?? null) !== null && (string)$row['public_link_token'] === '') {
        $row['public_link_token'] = null;
    }
    if ($withRelations) {
        $row['comments'] = listDocumentComments($id, 500, 0);
    }
    return $row;
}

/**
 * Reads an active publicly shared doc by secret token — no authentication.
 * Omit comments and project internals.
 */
function getDocumentByPublicLinkToken(string $rawToken): ?array {
    $tok = normalizeDocumentPublicLinkHexToken($rawToken);
    if ($tok === null) {
        return null;
    }
    $db = getDbConnection();
    $stmt = $db->prepare("
        SELECT d.id, d.title, d.body, d.updated_at, d.public_link_token
        FROM documents d
        WHERE d.public_link_token = :t
          AND d.public_link_enabled != 0
          AND d.status = 'active'
        LIMIT 1
    ");
    $stmt->bindValue(':t', $tok, SQLITE3_TEXT);
    $res = $stmt->execute();
    $row = $res->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        return null;
    }
    $row['id'] = (int)$row['id'];
    unset($row['public_link_token']);
    return $row;
}

/**
 * Whether markdown/HTML body embeds a Tasks-hosted attachment by get-asset.php id.
 */
function documentBodyReferencesAttachmentId(string $body, int $attachmentId): bool
{
    if ($attachmentId <= 0 || $body === '') {
        return false;
    }
    return (bool)preg_match(
        '#get-asset\.php\?(?:[^"\'<>\s]*&)?id=' . $attachmentId . '(?:&|["\'\s<>)])#i',
        $body
    );
}

/**
 * Anonymous asset access when attachment is embedded in an active public document.
 */
function resolvePublicDocumentShareForAttachment(string $shareToken, int $attachmentId): ?array
{
    $doc = getDocumentByPublicLinkToken($shareToken);
    if (!$doc) {
        return null;
    }
    if (!documentBodyReferencesAttachmentId((string)($doc['body'] ?? ''), $attachmentId)) {
        return null;
    }
    return $doc;
}

/**
 * Append document_share_token to embedded get-asset.php URLs for guest viewers.
 */
function rewriteSharedDocumentAssetUrls(string $html, string $documentShareToken): string
{
    $tok = normalizeDocumentPublicLinkHexToken($documentShareToken);
    if ($tok === null || $html === '') {
        return $html;
    }
    $enc = rawurlencode($tok);
    return (string)preg_replace_callback(
        '#(/api/get-asset\.php\?id=\d+)(?![^"\'\s<>]*document_share_token)#i',
        static function (array $m) use ($enc): string {
            return $m[1] . '&document_share_token=' . $enc;
        },
        $html
    );
}

/**
 * Stream a task attachment row (local file or remote redirect). Does not return.
 *
 * @param array<string,mixed> $attachment
 */
function emitTaskAttachmentHttpResponse(array $attachment, bool $publicShare = false): void
{
    $cacheControl = $publicShare ? 'public, max-age=3600' : 'private, max-age=300';
    $kind = normalizeTaskAttachmentStorageKind((string)($attachment['storage_kind'] ?? 'remote'));
    if ($kind === 'local') {
        $rel = trim((string)($attachment['storage_rel_path'] ?? ''));
        $abs = taskAttachmentAbsolutePath($rel);
        if ($abs === null || !is_file($abs)) {
            apiError('asset.not_found', 'Asset file is missing', 404);
        }
        $mimeType = trim((string)($attachment['mime_type'] ?? ''));
        if ($mimeType === '') {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = (string)$finfo->file($abs);
        }
        if ($mimeType === '') {
            $mimeType = 'application/octet-stream';
        }
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . (string)filesize($abs));
        header('Cache-Control: ' . $cacheControl);
        readfile($abs);
        exit();
    }

    $remote = trim((string)($attachment['file_url'] ?? ''));
    if ($remote === '' || !filter_var($remote, FILTER_VALIDATE_URL)) {
        apiError('asset.not_found', 'Asset URL is unavailable', 404);
    }
    header('Cache-Control: ' . ($publicShare ? 'public, max-age=300' : 'private, max-age=60'));
    header('Location: ' . $remote, true, 302);
    exit();
}

function listDocumentsForUser(array $userRow, int $limit = 200, ?int $projectId = null): array {
    $limit = max(1, min(500, $limit));
    $uid = (int)$userRow['id'];
    $orgIds = listOrganizationIdsForUserAccess($userRow);
    if ($orgIds === []) return [];
    $pk = normalizePersonKind($userRow['person_kind'] ?? 'team_member');
    $clientOnly = ($pk === 'client');
    $canSeeAll = userHasUnrestrictedOrgDirectoryAccess($userRow);

    $db = getDbConnection();
    $bind = [':uid' => [$uid, SQLITE3_INTEGER], ':lim' => [$limit, SQLITE3_INTEGER]];
    $orgPlaceholders = [];
    foreach ($orgIds as $i => $oid) {
        $k = ':org' . $i;
        $orgPlaceholders[] = $k;
        $bind[$k] = [$oid, SQLITE3_INTEGER];
    }
    $orgIn = implode(', ', $orgPlaceholders);
    $cvJoin = $clientOnly ? ' AND p.client_visible = 1' : '';
    $accessClause = $canSeeAll
        ? ''
        : ' AND (p.all_access = 1 OR EXISTS(SELECT 1 FROM project_members pm WHERE pm.project_id = p.id AND pm.user_id = :uid))';
    $projectClause = '';
    if ($projectId !== null && $projectId > 0) {
        $projectClause = ' AND d.project_id = :pid';
        $bind[':pid'] = [$projectId, SQLITE3_INTEGER];
    }

    $sql = "
        SELECT d.id, d.project_id, d.title, d.directory_path, d.status, d.created_by_user_id,
               d.created_at, d.updated_at,
               d.public_link_enabled, d.public_link_token,
               cu.username AS created_by_username,
               p.name AS project_name,
               (SELECT COUNT(*) FROM document_comments dc WHERE dc.document_id = d.id) AS comment_count
        FROM documents d
        JOIN users cu ON cu.id = d.created_by_user_id
        JOIN projects p ON p.id = d.project_id
        WHERE d.status != 'trashed'
          AND p.status != 'trashed'
          AND p.org_id IN ($orgIn)
          {$cvJoin}
          {$accessClause}
          {$projectClause}
        ORDER BY d.updated_at DESC, d.id DESC
        LIMIT :lim
    ";
    $stmt = $db->prepare($sql);
    foreach ($bind as $k => $v) {
        $stmt->bindValue($k, $v[0], $v[1]);
    }
    $res = $stmt->execute();
    $rows = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $r['id'] = (int)$r['id'];
        $r['project_id'] = (int)$r['project_id'];
        $r['created_by_user_id'] = (int)$r['created_by_user_id'];
        $r['comment_count'] = (int)$r['comment_count'];
        $r['public_link_enabled'] = !empty((int)($r['public_link_enabled'] ?? 0));
        if (($r['public_link_token'] ?? null) !== null && (string)$r['public_link_token'] === '') {
            $r['public_link_token'] = null;
        }
        $rows[] = $r;
    }
    return $rows;
}

/** Same access rules as listDocumentsForUser; total row count for badges and empty checks. */
function countDocumentsForUser(array $userRow, ?int $projectId = null): int {
    $uid = (int)$userRow['id'];
    $orgIds = listOrganizationIdsForUserAccess($userRow);
    if ($orgIds === []) {
        return 0;
    }
    $pk = normalizePersonKind($userRow['person_kind'] ?? 'team_member');
    $clientOnly = ($pk === 'client');
    $canSeeAll = userHasUnrestrictedOrgDirectoryAccess($userRow);

    $db = getDbConnection();
    $bind = [':uid' => [$uid, SQLITE3_INTEGER]];
    $orgPlaceholders = [];
    foreach ($orgIds as $i => $oid) {
        $k = ':org' . $i;
        $orgPlaceholders[] = $k;
        $bind[$k] = [$oid, SQLITE3_INTEGER];
    }
    $orgIn = implode(', ', $orgPlaceholders);
    $cvJoin = $clientOnly ? ' AND p.client_visible = 1' : '';
    $accessClause = $canSeeAll
        ? ''
        : ' AND (p.all_access = 1 OR EXISTS(SELECT 1 FROM project_members pm WHERE pm.project_id = p.id AND pm.user_id = :uid))';
    $projectClause = '';
    if ($projectId !== null && $projectId > 0) {
        $projectClause = ' AND d.project_id = :pid';
        $bind[':pid'] = [$projectId, SQLITE3_INTEGER];
    }

    $sql = "
        SELECT COUNT(*) AS c
        FROM documents d
        JOIN users cu ON cu.id = d.created_by_user_id
        JOIN projects p ON p.id = d.project_id
        WHERE d.status != 'trashed'
          AND p.status != 'trashed'
          AND p.org_id IN ($orgIn)
          {$cvJoin}
          {$accessClause}
          {$projectClause}
    ";
    $stmt = $db->prepare($sql);
    foreach ($bind as $k => $v) {
        $stmt->bindValue($k, $v[0], $v[1]);
    }
    $res = $stmt->execute();
    $row = $res->fetchArray(SQLITE3_ASSOC);
    return isset($row['c']) ? (int)$row['c'] : 0;
}

/**
 * Enables or disables unauthenticated `/shared-document.php` access.
 * Generates a cryptographically random 256-bit hex token when turning on or rotating.
 */
function documentSetPublicSharing(int $documentId, int $actorUserId, bool $enabled, bool $rotateToken): array {
    $documentId = (int)$documentId;
    $doc = getDocumentById($documentId, false);
    if (!$doc) {
        return ['success' => false, 'error' => 'Document not found'];
    }
    $user = getUserById((int)$actorUserId, false);
    if (!$user || !userCanManageDocument($user, $doc)) {
        return ['success' => false, 'error' => 'You do not have permission to change public sharing'];
    }

    $currentTok = normalizeDocumentPublicLinkHexToken((string)($doc['public_link_token'] ?? ''));
    $newTok = null;
    $enabledInt = 0;
    if ($enabled) {
        $enabledInt = 1;
        if ($rotateToken || $currentTok === null) {
            $newTok = bin2hex(random_bytes(32));
        } else {
            $newTok = $currentTok;
        }
    }

    $db = getDbConnection();
    $stmt = $db->prepare('
        UPDATE documents
           SET public_link_enabled = :e,
               public_link_token = :tok,
               updated_at = CURRENT_TIMESTAMP
         WHERE id = :id
    ');
    $stmt->bindValue(':e', $enabledInt, SQLITE3_INTEGER);
    $stmt->bindValue(':tok', $newTok, $newTok === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $stmt->bindValue(':id', $documentId, SQLITE3_INTEGER);
    $stmt->execute();
    createAuditLog($actorUserId, 'document.public_link', 'document', (string)$documentId, [
        'enabled' => $enabled,
        'rotated' => $rotateToken,
    ]);

    return ['success' => true];
}

function updateDocument(int $id, array $fields, ?int $actorUserId = null): array {
    $id = (int)$id;
    if ($id <= 0) return ['success' => false, 'error' => 'Invalid id'];
    $existingDoc = getDocumentById($id, false);
    if (!$existingDoc) return ['success' => false, 'error' => 'Document not found'];

    $sets = [];
    $params = [':id' => [$id, SQLITE3_INTEGER]];

    if (array_key_exists('title', $fields)) {
        $title = normalizeDocumentTitle($fields['title']);
        if ($title === null) return ['success' => false, 'error' => 'Title cannot be empty'];
        $sets[] = 'title = :title';
        $params[':title'] = [$title, SQLITE3_TEXT];
    }
    if (array_key_exists('body', $fields)) {
        $body = normalizeDocumentBody($fields['body']);
        $sets[] = 'body = :body';
        $params[':body'] = [$body, $body === null ? SQLITE3_NULL : SQLITE3_TEXT];
    }
    if (array_key_exists('directory_path', $fields)) {
        $directoryPath = normalizeDocumentDirectoryPath($fields['directory_path']);
        $sets[] = 'directory_path = :directory_path';
        $params[':directory_path'] = [$directoryPath, SQLITE3_TEXT];
    }
    if (array_key_exists('status', $fields)) {
        $status = strtolower(trim((string)$fields['status']));
        if (!in_array($status, ['active', 'archived', 'trashed'], true)) {
            return ['success' => false, 'error' => 'Invalid status'];
        }
        $sets[] = 'status = :status';
        $params[':status'] = [$status, SQLITE3_TEXT];
    }
    if (array_key_exists('project_id', $fields)) {
        $newPid = (int)$fields['project_id'];
        if ($newPid <= 0 || !getDirectoryProjectById($newPid)) {
            return ['success' => false, 'error' => 'Invalid project_id'];
        }
        $sets[] = 'project_id = :project_id';
        $params[':project_id'] = [$newPid, SQLITE3_INTEGER];
    }

    if (!$sets) return ['success' => false, 'error' => 'No fields to update'];
    $sets[] = 'updated_at = CURRENT_TIMESTAMP';

    $expectedUpdatedAt = null;
    if (array_key_exists('expected_updated_at', $fields) && $fields['expected_updated_at'] !== null && $fields['expected_updated_at'] !== '') {
        $expectedUpdatedAt = trim((string)$fields['expected_updated_at']);
    }

    $db = getDbConnection();
    $sql = 'UPDATE documents SET ' . implode(', ', $sets) . ' WHERE id = :id';
    if ($expectedUpdatedAt !== null) {
        // Optimistic concurrency: reject stale full-body rewrites (multi-agent lost-update risk).
        $sql .= ' AND updated_at = :expected_updated_at';
        $params[':expected_updated_at'] = [$expectedUpdatedAt, SQLITE3_TEXT];
    }
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v[0], $v[1]);
    }
    $stmt->execute();
    if ($expectedUpdatedAt !== null && $db->changes() === 0) {
        $fresh = getDocumentById($id, false);
        return [
            'success' => false,
            'error_code' => 'document.conflict',
            'error' => 'Document was modified; expected_updated_at does not match current updated_at',
            'details' => [
                'document_id' => $id,
                'expected_updated_at' => $expectedUpdatedAt,
                'current_updated_at' => $fresh['updated_at'] ?? null,
            ],
        ];
    }
    $auditFields = array_values(array_filter(array_keys($fields), static function ($k) {
        return $k !== 'expected_updated_at';
    }));
    createAuditLog(null, 'document.update', 'document', (string)$id, ['updated_fields' => $auditFields]);
    if (array_key_exists('body', $fields)) {
        $freshDoc = getDocumentById($id, false);
        if ($freshDoc) {
            $newBodyNorm = normalizeDocumentBody($fields['body']);
            $nb = $newBodyNorm ?? '';
            notificationsDocumentBodyMentions(
                $actorUserId,
                $freshDoc,
                isset($existingDoc['body']) ? (string)$existingDoc['body'] : '',
                (string)$nb
            );
        }
    }
    return ['success' => true];
}

function deleteDocument(int $id): array {
    $id = (int)$id;
    if ($id <= 0) return ['success' => false, 'error' => 'Invalid id'];
    if (!getDocumentById($id, false)) return ['success' => false, 'error' => 'Document not found'];
    $db = getDbConnection();
    $stmt = $db->prepare('DELETE FROM documents WHERE id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();
    createAuditLog(null, 'document.delete', 'document', (string)$id);
    return ['success' => true];
}

function listDocumentComments(int $documentId, int $limit = 100, int $offset = 0): array {
    $limit = max(1, min(500, $limit));
    $offset = max(0, $offset);
    $db = getDbConnection();
    $stmt = $db->prepare("
        SELECT c.id, c.document_id, c.user_id, c.comment, c.created_at, u.username
        FROM document_comments c
        JOIN users u ON u.id = c.user_id
        WHERE c.document_id = :doc_id
        ORDER BY c.id ASC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':doc_id', $documentId, SQLITE3_INTEGER);
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $rows = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    return $rows;
}

function addDocumentComment(int $documentId, int $userId, string $comment): array {
    $comment = trim($comment);
    if ($comment === '') return ['success' => false, 'error' => 'Comment is required'];
    $comment = truncateString($comment, 2000);
    if (!getDocumentById($documentId, false)) return ['success' => false, 'error' => 'Document not found'];
    if (!getUserById($userId)) return ['success' => false, 'error' => 'User not found'];

    $db = getDbConnection();
    $stmt = $db->prepare("
        INSERT INTO document_comments (document_id, user_id, comment, created_at)
        VALUES (:doc_id, :user_id, :comment, CURRENT_TIMESTAMP)
    ");
    $stmt->bindValue(':doc_id', $documentId, SQLITE3_INTEGER);
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':comment', $comment, SQLITE3_TEXT);
    $stmt->execute();

    $upd = $db->prepare('UPDATE documents SET updated_at = CURRENT_TIMESTAMP WHERE id = :id');
    $upd->bindValue(':id', $documentId, SQLITE3_INTEGER);
    $upd->execute();

    $id = (int)$db->lastInsertRowID();
    $sel = $db->prepare('SELECT created_at FROM document_comments WHERE id = :id LIMIT 1');
    $sel->bindValue(':id', $id, SQLITE3_INTEGER);
    $createdRow = $sel->execute()->fetchArray(SQLITE3_ASSOC);
    $createdAt = $createdRow ? $createdRow['created_at'] : nowUtc();
    createAuditLog($userId, 'document.comment_add', 'document_comment', (string)$id, ['document_id' => $documentId]);
    $docRow = getDocumentById($documentId, false);
    if ($docRow) {
        notificationsAfterDocumentComment($docRow, $id, $userId, $comment);
    }
    return ['success' => true, 'id' => $id, 'created_at' => $createdAt];
}

require_once __DIR__ . '/activity_feed.php';
require_once __DIR__ . '/schedule.php';
require_once __DIR__ . '/doors.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/board_export.php';
