<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AuthScriptAllowListTest extends TestCase
{
    public function testAllowedSuffixes(): void
    {
        require_once dirname(__DIR__, 3) . '/public/includes/auth.php';

        $this->assertTrue(authScriptAllowedDuringPasswordChange('/admin/change-password.php'));
        $this->assertTrue(authScriptAllowedDuringPasswordChange('/app/admin/settings.php'));
        $this->assertTrue(authScriptAllowedDuringPasswordChange('/admin/logout.php'));
        $this->assertFalse(authScriptAllowedDuringPasswordChange('/admin/index.php'));
        $this->assertFalse(authScriptAllowedDuringPasswordChange(''));
        $this->assertFalse(authScriptAllowedDuringPasswordChange(null));
    }
}
