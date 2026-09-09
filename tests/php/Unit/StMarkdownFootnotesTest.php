<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/public/admin/_helpers.php';

final class StMarkdownFootnotesTest extends TestCase
{
    public function testFootnoteRefBecomesLinkedSuperscript(): void
    {
        $raw = "Claim in the body.[^alpha]\n\n[^alpha]: Source definition.";
        $html = st_markdown($raw);

        $this->assertStringContainsString('<sup id="fnref1:alpha">', $html);
        $this->assertStringContainsString('class="footnote-ref"', $html);
        $this->assertStringNotContainsString('[^alpha]', $html);
    }

    public function testFootnoteDefinitionsRenderAsLinkedList(): void
    {
        $raw = "One.[^a] Two.[^b]\n\n[^a]: First *source*.\n[^b]: Second source.";
        $html = st_markdown($raw);

        $this->assertStringContainsString('<div class="footnotes">', $html);
        $this->assertStringContainsString('<li id="fn:a">', $html);
        $this->assertStringContainsString('<li id="fn:b">', $html);
        // Definition bodies render as real HTML, not escaped text.
        $this->assertStringContainsString('<em>source</em>', $html);
        $this->assertStringNotContainsString('&lt;p&gt;', $html);
        $this->assertStringNotContainsString('&lt;em&gt;', $html);
        // Back-reference links return to the in-text marker.
        $this->assertStringContainsString('class="footnote-backref"', $html);
    }

    public function testFootnoteDefinitionBodyMarkdownIsRendered(): void
    {
        $raw = "Ref.[^x]\n\n[^x]: See https://example.com/doc for details.";
        $html = st_markdown($raw);

        $this->assertStringContainsString('<a href="https://example.com/doc">', $html);
    }

    public function testUndefinedFootnoteMarkerStaysLiteral(): void
    {
        $raw = 'This has [^missing] with no definition.';
        $html = st_markdown($raw);

        $this->assertStringContainsString('[^missing]', $html);
        $this->assertStringNotContainsString('class="footnotes"', $html);
    }

    public function testSafeModeStillEscapesRawHtmlWithFootnotes(): void
    {
        $raw = "Text.[^a]\n\n[^a]: Source.\n\n<script>alert(1)</script>";
        $html = st_markdown($raw);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('<div class="footnotes">', $html);
    }

    public function testFootnoteBodyCannotInjectRawHtml(): void
    {
        $raw = "Text.[^a]\n\n[^a]: <img src=x onerror=alert(1)>";
        $html = st_markdown($raw);

        $this->assertStringNotContainsString('<img src=x onerror', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }

    public function testMultipleRefsToSameFootnoteShareOneListEntry(): void
    {
        $raw = "First.[^a] Second.[^a]\n\n[^a]: Shared source.";
        $html = st_markdown($raw);

        $this->assertSame(1, substr_count($html, '<li id="fn:a">'));
        $this->assertStringContainsString('id="fnref1:a"', $html);
        $this->assertStringContainsString('id="fnref2:a"', $html);
    }

    public function testOrdinaryMarkdownUnchanged(): void
    {
        $raw = "# Title\n\n- one\n- two\n\n**bold** and `code`";
        $html = st_markdown($raw);

        $this->assertStringContainsString('<h1>Title</h1>', $html);
        $this->assertStringContainsString('<li>one</li>', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<code>code</code>', $html);
    }

    public function testInlineModeDoesNotEmitFootnoteList(): void
    {
        $html = st_markdown('Just **bold** text', true);

        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringNotContainsString('footnotes', $html);
    }
}
