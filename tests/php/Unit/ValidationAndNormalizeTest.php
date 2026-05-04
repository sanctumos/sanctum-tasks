<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Pure helpers from public/includes/functions.php (no HTTP).
 */
final class ValidationAndNormalizeTest extends TestCase
{
    public function testValidateUsername(): void
    {
        $this->assertNotNull(validateUsername('ab'));
        $this->assertNotNull(validateUsername(''));
        $this->assertNotNull(validateUsername('no spaces'));
        $this->assertNull(validateUsername('valid.user_01'));
    }

    public function testValidatePassword(): void
    {
        $this->assertStringContainsString('at least', (string)validatePassword('short'));
        $this->assertStringContainsString('uppercase', (string)validatePassword('alllowercase12345'));
        $this->assertNull(validatePassword('GoodPass123456'));
    }

    public function testNormalizeSlug(): void
    {
        $this->assertSame('my-project', normalizeSlug('  My Project!!  '));
        $long = normalizeSlug('a' . str_repeat('x', 200));
        $this->assertSame(50, strlen($long));
        $this->assertStringStartsWith('ax', $long);
    }

    public function testNormalizeRoleAndAdmin(): void
    {
        $this->assertSame('admin', normalizeRole('ADMIN'));
        $this->assertNull(normalizeRole('nope'));
        $this->assertTrue(isAdminRole('manager'));
        $this->assertFalse(isAdminRole('member'));
    }

    public function testNormalizePriority(): void
    {
        $this->assertSame('high', normalizePriority('HIGH'));
        $this->assertNull(normalizePriority('zzz'));
        $this->assertNull(normalizePriority(''));
    }

    public function testNormalizeNullableText(): void
    {
        $this->assertNull(normalizeNullableText(null, 10));
        $this->assertNull(normalizeNullableText('   ', 10));
        $this->assertSame('hi', normalizeNullableText(' hi ', 10));
    }

    public function testParseDateTimeOrNull(): void
    {
        $this->assertNull(parseDateTimeOrNull(null));
        $this->assertNull(parseDateTimeOrNull(''));
        $this->assertNull(parseDateTimeOrNull('not-a-date'));
        $this->assertSame('2026-01-15 12:30:00', parseDateTimeOrNull('2026-01-15T12:30:00Z'));
    }

    public function testTagsAndJson(): void
    {
        // Duplicate keys collapse; last casing wins for the display value.
        $this->assertSame(['A', 'b'], normalizeTags(['a', 'A', 'b']));
        $this->assertSame([], decodeTagsJson(''));
        $this->assertSame([], decodeTagsJson('not json'));
        $enc = encodeTagsJson(['x', 'y']);
        $this->assertIsString($enc);
        $this->assertSame(['x', 'y'], decodeTagsJson($enc));
    }

    public function testGenerateTemporaryPassword(): void
    {
        $p = generateTemporaryPassword(20);
        $this->assertSame(20, strlen($p));
        $this->assertNull(validatePassword($p));
    }
}
