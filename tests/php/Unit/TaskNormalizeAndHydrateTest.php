<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TaskNormalizeAndHydrateTest extends TestCase
{
    public function testNormalizeTaskFields(): void
    {
        $this->assertNull(normalizeTaskTitle(''));
        $this->assertSame('x', normalizeTaskTitle(' x '));
        $this->assertNull(normalizeTaskBody(''));
        $this->assertSame('p', normalizeTaskProject('p'));
        $this->assertNull(normalizeTaskRecurrenceRule(''));
    }

    public function testTaskOrderByClause(): void
    {
        $this->assertStringContainsString('priority', taskOrderByClause('priority', 'asc'));
        $this->assertStringContainsString('due_at', taskOrderByClause('due_at', 'DESC'));
        $this->assertStringContainsString('updated_at', taskOrderByClause('unknown', 'DESC'));
    }

    public function testHydrateTaskRow(): void
    {
        $row = [
            'id' => 1,
            'tags_json' => null,
            'project_id' => '2',
            'directory_project_name' => 'Acme',
        ];
        $h = hydrateTaskRow($row);
        $this->assertSame(2, $h['project_id']);
        $this->assertArrayHasKey('directory_project', $h);
        $this->assertSame('Acme', $h['directory_project']['name']);
    }
}
