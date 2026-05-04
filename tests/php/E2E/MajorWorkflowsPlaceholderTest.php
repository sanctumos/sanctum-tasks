<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\E2E;

use PHPUnit\Framework\TestCase;

/**
 * Browser-level “major workflow” coverage is tracked separately from PHPUnit line %.
 * See docs/PHP_TEST_BENCHMARK.md — run design-smoke / Playwright against a live stack.
 */
final class MajorWorkflowsPlaceholderTest extends TestCase
{
    public function testDocPointsToPlaywrightSuite(): void
    {
        $doc = dirname(__DIR__, 3) . '/docs/PHP_TEST_BENCHMARK.md';
        $this->assertFileExists($doc);
        $body = (string)file_get_contents($doc);
        $this->assertStringContainsString('design-smoke', $body);
        $this->assertStringContainsString('90', $body);
    }
}
