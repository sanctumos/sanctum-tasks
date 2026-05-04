<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class Base32AndTotpTest extends TestCase
{
    public function testBase32RoundTrip(): void
    {
        $raw = random_bytes(20);
        $enc = base32Encode($raw);
        $this->assertSame($raw, base32Decode($enc));
    }

    public function testGenerateTotpSecretShape(): void
    {
        $secret = generateTotpSecret();
        $this->assertGreaterThan(10, strlen($secret));
        $decoded = base32Decode($secret);
        $this->assertGreaterThan(0, strlen($decoded));
    }

    public function testTotpVerifyCurrentSlice(): void
    {
        $secret = generateTotpSecret();
        $code = generateTotpCode($secret);
        $this->assertSame(6, strlen($code));
        $this->assertTrue(verifyTotpCode($secret, $code, 1));
        $this->assertFalse(verifyTotpCode($secret, '000000', 0));
    }
}
