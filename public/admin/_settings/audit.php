<?php
/**
 * Settings tab: audit log (admin only).
 */

$audit_limit = isset($_GET['limit']) ? max(10, min(500, (int)$_GET['limit'])) : 100;
$audit_offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
$auditLogs = listAuditLogs($audit_limit, $audit_offset);
$audit_prevOffset = max(0, $audit_offset - $audit_limit);
$audit_nextOffset = $audit_offset + $audit_limit;
$audit_hasPrev = $audit_offset > 0;
$audit_hasNext = count($auditLogs) >= $audit_limit;
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <span class="fine-print"><?= count($auditLogs) ?> entries · most recent first</span>
    <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-secondary <?= $audit_hasPrev ? '' : 'disabled' ?>" href="?tab=audit&amp;limit=<?= $audit_limit ?>&amp;offset=<?= $audit_prevOffset ?>"><i class="bi bi-chevron-left"></i> Newer</a>
        <a class="btn btn-sm btn-outline-secondary <?= $audit_hasNext ? '' : 'disabled' ?>" href="?tab=audit&amp;limit=<?= $audit_limit ?>&amp;offset=<?= $audit_nextOffset ?>">Older <i class="bi bi-chevron-right"></i></a>
    </div>
</div>

<div class="surface">
    <table class="task-table">
        <thead>
            <tr>
                <th style="width: 170px;">When (UTC)</th>
                <th style="width: 140px;">Actor</th>
                <th style="width: 180px;">Action</th>
                <th>Entity</th>
                <th style="width: 130px;">IP</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($auditLogs as $log): ?>
                <tr>
                    <td class="small text-muted"><?= htmlspecialchars($log['created_at']) ?></td>
                    <td><?= htmlspecialchars((string)($log['actor_username'] ?? 'system')) ?></td>
                    <td><span class="tag-chip"><i class="bi bi-tag me-1"></i><?= htmlspecialchars($log['action']) ?></span></td>
                    <td class="small"><?= htmlspecialchars($log['entity_type']) ?> <span class="text-muted"><?= htmlspecialchars((string)($log['entity_id'] ?? '')) ?></span></td>
                    <td class="small text-muted"><?= htmlspecialchars((string)($log['ip_address'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$auditLogs): ?>
                <tr><td colspan="5" class="text-muted text-center py-4">No audit log entries.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
