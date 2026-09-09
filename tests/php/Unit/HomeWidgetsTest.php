<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class HomeWidgetsTest extends TestCase
{
    public function testDefaultsKeepCrossProjectBoardOff(): void
    {
        $d = homeWidgetDefaults();
        $this->assertFalse($d['cross_project_board']);
        $this->assertTrue($d['pulse_kpis']);
        $this->assertTrue($d['my_work']);
        $this->assertTrue($d['pinned_boards']);
    }

    public function testUpdateAndReadHomeWidgets(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $admin = createUser("hw_{$suffix}", 'MemberPass123456', 'admin', false);
        $this->assertTrue($admin['success']);
        $aid = (int)$admin['id'];

        $saved = updateUserHomeWidgets($aid, [
            'pulse_kpis' => true,
            'my_work' => false,
            'cross_project_board' => true,
        ]);
        $this->assertTrue($saved['success']);
        $user = getUserById($aid);
        $widgets = getHomeWidgetsForUser($user);
        $this->assertFalse($widgets['my_work']);
        $this->assertTrue($widgets['cross_project_board']);
        $this->assertTrue($widgets['projects_hub']); // default filled
    }

    public function testExcludeDoneAndUnassignedFilters(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $admin = createUser("hwf_{$suffix}", 'MemberPass123456', 'admin', false);
        $this->assertTrue($admin['success']);
        $aid = (int)$admin['id'];
        $proj = createDirectoryProject($aid, "HW {$suffix}", null, false, true);
        $pid = (int)$proj['id'];
        applySanctumSchemaMigrations(getDbConnection());
        $lid = getFirstTodoListIdForProject(getDbConnection(), $pid);

        createTask("open {$suffix}", 'todo', $aid, $aid, null, [
            'project_id' => $pid,
            'list_id' => $lid,
        ]);
        createTask("done {$suffix}", 'done', $aid, $aid, null, [
            'project_id' => $pid,
            'list_id' => $lid,
        ]);
        createTask("una {$suffix}", 'todo', $aid, null, null, [
            'project_id' => $pid,
            'list_id' => $lid,
        ]);

        $viewer = getUserById($aid);
        $open = listTasks([
            'assigned_to_user_id' => $aid,
            'exclude_done' => true,
            'project_id' => $pid,
            'limit' => 50,
        ], true, null, $viewer);
        $this->assertSame(1, (int)$open['total']);

        $una = listTasks([
            'unassigned_only' => true,
            'exclude_done' => true,
            'project_id' => $pid,
            'limit' => 50,
        ], true, null, $viewer);
        $this->assertGreaterThanOrEqual(1, (int)$una['total']);
    }

    public function testBlockedKpiUsesTagNotInvalidStatus(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $admin = createUser("hwb_{$suffix}", 'MemberPass123456', 'admin', false);
        $this->assertTrue($admin['success']);
        $aid = (int)$admin['id'];
        $proj = createDirectoryProject($aid, "HWB {$suffix}", null, false, true);
        $pid = (int)$proj['id'];
        applySanctumSchemaMigrations(getDbConnection());
        $lid = getFirstTodoListIdForProject(getDbConnection(), $pid);

        createTask("blocked open {$suffix}", 'todo', $aid, $aid, null, [
            'project_id' => $pid,
            'list_id' => $lid,
            'tags' => ['blocked'],
        ]);
        createTask("blocked done {$suffix}", 'done', $aid, $aid, null, [
            'project_id' => $pid,
            'list_id' => $lid,
            'tags' => ['blocked'],
        ]);
        createTask("plain open {$suffix}", 'todo', $aid, $aid, null, [
            'project_id' => $pid,
            'list_id' => $lid,
        ]);

        $viewer = getUserById($aid);

        $invalidStatus = listTasks([
            'status' => 'blocked',
            'project_id' => $pid,
            'limit' => 50,
        ], true, null, $viewer);
        $this->assertSame(0, (int)$invalidStatus['total'], 'unknown status must not dump all tasks');

        $taggedOpen = listTasks([
            'tag' => 'blocked',
            'exclude_done' => true,
            'project_id' => $pid,
            'limit' => 50,
        ], true, null, $viewer);
        $this->assertSame(1, (int)$taggedOpen['total']);

        $kpis = computeHomePulseKpis($viewer);
        $this->assertSame(1, (int)$kpis['blocked']);
        $this->assertSame(2, (int)$kpis['assigned_open']); // blocked open + plain open
    }
}
