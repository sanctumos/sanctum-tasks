<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class RequestIpAddressTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR']);
        parent::tearDown();
    }

    public function testDirectRemoteAddr(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        $this->assertSame('203.0.113.5', requestIpAddress());
    }

    public function testUnknownWhenEmpty(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        $this->assertSame('unknown', requestIpAddress());
    }
}
