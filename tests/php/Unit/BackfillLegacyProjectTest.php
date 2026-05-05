<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BackfillLegacyProjectTest extends TestCase
{
    public function testBackfillFromLegacyNamesIsCaseInsensitive(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $user = createUser("bf_{$suffix}", 'MemberPass123456', 'member', false);
        $this->assertTrue($user['success']);
        $uid = (int)$user['id'];

        $proj = createDirectoryProject($uid, "CaseProj {$suffix}", null, false, true);
        $this->assertTrue($proj['success']);
        $pid = (int)$proj['id'];

        $db = getDbConnection();
        $ins = $db->prepare('INSERT INTO tasks (title, status, project, project_id, created_by_user_id, priority, rank) VALUES (:t, \'todo\', :p, NULL, :u, \'normal\', 0)');
        $ins->bindValue(':t', "Legacy {$suffix}", SQLITE3_TEXT);
        $ins->bindValue(':p', 'CASEPROJ ' . $suffix, SQLITE3_TEXT);
        $ins->bindValue(':u', $uid, SQLITE3_INTEGER);
        $ins->execute();
        $tid = (int)$db->lastInsertRowID();

        $bf = backfillTaskProjectIdsFromLegacyNames();
        $this->assertSame(1, (int)($bf['updated'] ?? 0));

        $st = getDbConnection()->prepare('SELECT project_id, project FROM tasks WHERE id = :id');
        $st->bindValue(':id', $tid, SQLITE3_INTEGER);
        $row = $st->execute()->fetchArray(SQLITE3_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame($pid, (int)$row['project_id']);
        $this->assertSame("CaseProj {$suffix}", $row['project']);
    }

    public function testBackfillLegacyTasksToDirectoryProjectByLabels(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $user = createUser("bf2_{$suffix}", 'MemberPass123456', 'member', false);
        $this->assertTrue($user['success']);
        $uid = (int)$user['id'];

        $proj = createDirectoryProject($uid, "Invoice WS {$suffix}", null, false, true);
        $this->assertTrue($proj['success']);
        $pid = (int)$proj['id'];

        $db = getDbConnection();
        foreach (['invoicing', 'INVOICES'] as $lab) {
            $ins = $db->prepare('INSERT INTO tasks (title, status, project, project_id, created_by_user_id, priority, rank) VALUES (:t, \'todo\', :p, NULL, :u, \'normal\', 0)');
            $ins->bindValue(':t', "Inv {$suffix}", SQLITE3_TEXT);
            $ins->bindValue(':p', $lab, SQLITE3_TEXT);
            $ins->bindValue(':u', $uid, SQLITE3_INTEGER);
            $ins->execute();
        }

        $res = backfillLegacyTasksToDirectoryProject($pid, ['invoicing', 'invoices'], false);
        $this->assertTrue($res['success']);
        $this->assertSame(2, (int)($res['updated'] ?? 0));

        $cnt = (int)getDbConnection()->querySingle("SELECT COUNT(*) FROM tasks WHERE project_id = {$pid}");
        $this->assertSame(2, $cnt);
    }
}
