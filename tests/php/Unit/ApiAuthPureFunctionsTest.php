<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ApiAuthPureFunctionsTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('TASKS_APP_BASE_URL');
        unset($_ENV['TASKS_APP_BASE_URL']);
        unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT'], $_SERVER['SERVER_NAME'], $_SERVER['SERVER_ADDR']);
        parent::tearDown();
    }

    public function testSanitizeUrlHost(): void
    {
        require_once dirname(__DIR__, 3) . '/public/includes/api_auth.php';

        $this->assertSame('example.com', sanitizeUrlHost('Example.COM'));
        $this->assertSame('[2001:db8::1]', sanitizeUrlHost('[2001:db8::1]'));
        $this->assertNull(sanitizeUrlHost(''));
        $this->assertNull(sanitizeUrlHost('bad host!'));
    }

    public function testConfiguredAppOrigin(): void
    {
        require_once dirname(__DIR__, 3) . '/public/includes/api_auth.php';

        putenv('TASKS_APP_BASE_URL=https://tasks.example.com');
        $_ENV['TASKS_APP_BASE_URL'] = 'https://tasks.example.com';

        $this->assertSame('https://tasks.example.com', configuredAppOrigin());
    }

    public function testPaginationMeta(): void
    {
        require_once dirname(__DIR__, 3) . '/public/includes/api_auth.php';

        $_SERVER['HTTPS'] = 'on';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['SERVER_PORT'] = '443';

        $meta = paginationMeta('/api/list-tasks.php', [], 10, 0, 25);
        $this->assertSame(25, $meta['total']);
        $this->assertSame(10, $meta['next_offset']);
        $this->assertNotNull($meta['next_url']);
    }
}
