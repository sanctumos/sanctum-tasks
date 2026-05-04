<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Uses bootstrap SQLite (shared temp DB per PHPUnit process).
 */
final class DbTaskLifecycleTest extends TestCase
{
    public function testCreateUserAndTask(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = createUser("mem_{$suffix}", 'MemberPass123456', 'member', false);
        $this->assertTrue($user['success'], (string)($user['error'] ?? 'create user'));
        $uid = (int)$user['id'];

        $task = createTask("Unit task {$suffix}", 'todo', $uid, null, 'Body text', ['priority' => 'normal']);
        $this->assertTrue($task['success'], (string)($task['error'] ?? 'create task'));
        $tid = (int)$task['id'];

        $loaded = getTaskById($tid);
        $this->assertNotNull($loaded);
        $this->assertSame("Unit task {$suffix}", $loaded['title']);
        $this->assertSame('todo', $loaded['status']);
    }
}
