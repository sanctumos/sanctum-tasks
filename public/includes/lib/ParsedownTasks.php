<?php

/**
 * ParsedownTasks — Sanctum Tasks markdown dialect.
 *
 * Extends ParsedownExtra (footnotes, definition lists, abbreviations) with
 * one safe-mode correction: ParsedownExtra 0.8.1 emits footnote <li> bodies
 * as rawHtml, which Parsedown escapes when safe mode is on, producing
 * double-escaped footnote text. The bodies are already sanitized — they are
 * rendered by a recursive pass through the same safe-mode parser — so we
 * re-flag them as allowed raw HTML in safe mode.
 *
 * Vendored libraries (Parsedown.php, ParsedownExtra.php) are left pristine
 * so upstream updates drop in cleanly.
 */

require_once __DIR__ . '/Parsedown.php';
require_once __DIR__ . '/ParsedownExtra.php';

class ParsedownTasks extends ParsedownExtra
{
    protected function buildFootnoteElement()
    {
        $Element = parent::buildFootnoteElement();

        # Footnote bodies were produced by Parsedown itself (safe mode
        # applies to that render pass), so they are trusted markup.
        $items = &$Element['text'][1]['text'];
        foreach ($items as &$li) {
            if (isset($li['rawHtml'])) {
                $li['allowRawHtmlInSafeMode'] = true;
            }
        }
        unset($li, $items);

        return $Element;
    }
}
