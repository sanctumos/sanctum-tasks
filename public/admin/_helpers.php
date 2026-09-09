<?php
/**
 * Shared admin view helpers (UI-only, no DB).
 */

if (!function_exists('st_status_kind')) {
    /**
     * Map a task_statuses row (or just slug+is_done) to a CSS modifier we use
     * for status pills and swimlanes.
     */
    function st_status_kind(array $status): string {
        if ((int)($status['is_done'] ?? 0) === 1) {
            return 'done';
        }
        $slug = strtolower((string)($status['slug'] ?? ''));
        if ($slug === '') return 'default';
        if (preg_match('/(progress|doing|active|review)/', $slug)) return 'doing';
        if (preg_match('/block/', $slug)) return 'blocked';
        if (preg_match('/(todo|to_do|backlog|new|open|pending)/', $slug)) return 'todo';
        return 'default';
    }
}

if (!function_exists('st_status_pill_html')) {
    /**
     * Render a status pill for a task row. Accepts the task array
     * (which has status, status_label, status_is_done) and the full status map
     * keyed by slug (for fallback labels and is_done).
     */
    function st_status_pill_html(array $task, array $statusMap = []): string {
        $slug = (string)($task['status'] ?? '');
        $label = (string)($task['status_label'] ?? $statusMap[$slug]['label'] ?? $slug);
        $isDone = (int)($task['status_is_done'] ?? ($statusMap[$slug]['is_done'] ?? 0));
        $kind = st_status_kind(['slug' => $slug, 'is_done' => $isDone]);
        return '<span class="status-pill status-pill--' . htmlspecialchars($kind) . '">' . htmlspecialchars($label ?: $slug) . '</span>';
    }
}

if (!function_exists('st_priority_chip_html')) {
    function st_priority_chip_html(string $priority): string {
        $p = strtolower(trim($priority)) ?: 'normal';
        $allowed = ['low', 'normal', 'high', 'urgent'];
        if (!in_array($p, $allowed, true)) $p = 'normal';
        $icon = [
            'low' => 'bi-arrow-down',
            'normal' => 'bi-dash',
            'high' => 'bi-arrow-up',
            'urgent' => 'bi-exclamation',
        ][$p];
        return '<span class="priority-chip priority-chip--' . $p . '"><i class="bi ' . $icon . '"></i>' . $p . '</span>';
    }
}

if (!function_exists('st_signal_icons_html')) {
    function st_signal_icons_html(array $task): string {
        $c = (int)($task['comment_count'] ?? 0);
        $a = (int)($task['attachment_count'] ?? 0);
        $w = (int)($task['watcher_count'] ?? 0);
        if ($c === 0 && $a === 0 && $w === 0) {
            return '';
        }
        $parts = ['<span class="icon-stats">'];
        if ($c > 0) $parts[] = '<span title="Comments"><i class="bi bi-chat-text"></i>' . $c . '</span>';
        if ($a > 0) $parts[] = '<span title="Attachments"><i class="bi bi-paperclip"></i>' . $a . '</span>';
        if ($w > 0) $parts[] = '<span title="Watchers"><i class="bi bi-eye"></i>' . $w . '</span>';
        $parts[] = '</span>';
        return implode('', $parts);
    }
}

if (!function_exists('st_relative_time')) {
    function st_relative_time(?string $iso): string {
        if (!$iso) return '';
        try {
            $dt = new DateTime($iso, new DateTimeZone('UTC'));
        } catch (Exception $e) {
            return htmlspecialchars((string)$iso);
        }
        $diff = time() - $dt->getTimestamp();
        if ($diff < 60) return 'just now';
        if ($diff < 3600) return (int)floor($diff / 60) . 'm ago';
        if ($diff < 86400) return (int)floor($diff / 3600) . 'h ago';
        if ($diff < 86400 * 7) return (int)floor($diff / 86400) . 'd ago';
        return $dt->format('M j');
    }
}

if (!function_exists('st_is_ajax')) {
    function st_is_ajax(): bool {
        $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        if ($xrw === 'xmlhttprequest') return true;
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        return strpos($accept, 'application/json') !== false;
    }
}

if (!function_exists('st_back_link')) {
    function st_back_link(string $href, string $label): string {
        return '<a class="page-back" href="' . htmlspecialchars($href) . '"><i class="bi bi-chevron-left"></i><span>' . htmlspecialchars($label) . '</span></a>';
    }
}

if (!function_exists('st_initials')) {
    /**
     * Two-letter avatar initials for a username (or "?" fallback).
     */
    function st_initials(?string $username): string {
        $u = trim((string)$username);
        if ($u === '') return '?';
        $parts = preg_split('/[\s._\-]+/u', $u, -1, PREG_SPLIT_NO_EMPTY) ?: [$u];
        if (count($parts) === 1) {
            return strtoupper(substr($parts[0], 0, 2));
        }
        return strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts) - 1], 0, 1));
    }
}

if (!function_exists('st_avatar_color')) {
    /**
     * Stable hue (0-359) derived from username so each user has a distinct
     * but pastel avatar bubble. Used by the comment thread + watcher chips.
     */
    function st_avatar_color(?string $username): int {
        $u = (string)$username;
        if ($u === '') return 220;
        $hash = 0;
        for ($i = 0, $n = strlen($u); $i < $n; $i++) {
            $hash = (int)((($hash << 5) - $hash) + ord($u[$i])) & 0xffffffff;
        }
        return abs($hash) % 360;
    }
}

if (!function_exists('st_password_policy_requirements_text')) {
    /**
     * Human-readable password rules (matches validatePassword in functions.php).
     */
    function st_password_policy_requirements_text(): string {
        $n = (int)(defined('PASSWORD_MIN_LENGTH') ? PASSWORD_MIN_LENGTH : 12);
        return sprintf(
            'At least %d characters, with at least one uppercase letter, one lowercase letter, and one number.',
            $n
        );
    }
}

if (!function_exists('st_password_policy_form_hint')) {
    /** Echo Bootstrap form-text under a password field. */
    function st_password_policy_form_hint(): void {
        echo '<div class="form-text">' . htmlspecialchars(st_password_policy_requirements_text(), ENT_QUOTES, 'UTF-8') . '</div>';
    }
}

if (!function_exists('st_password_policy_alert_html')) {
    /** Bulleted reminder for alert/modal bodies (trusted HTML). */
    function st_password_policy_alert_html(): string {
        $n = (int)(defined('PASSWORD_MIN_LENGTH') ? PASSWORD_MIN_LENGTH : 12);
        return '<ul class="small mb-0 ps-3 text-start">'
            . '<li><strong>Length:</strong> at least ' . $n . ' characters</li>'
            . '<li><strong>Mix:</strong> uppercase, lowercase, and at least one number</li>'
            . '</ul>';
    }
}

if (!function_exists('st_avatar_html')) {
    function st_avatar_html(?string $username, string $sizeClass = ''): string {
        $hue = st_avatar_color($username);
        $initials = st_initials($username);
        $cls = 'st-avatar' . ($sizeClass !== '' ? ' ' . $sizeClass : '');
        $style = "background:hsl({$hue}deg 60% 88%);color:hsl({$hue}deg 45% 28%);";
        return '<span class="' . $cls . '" style="' . $style . '" aria-hidden="true">' . htmlspecialchars($initials) . '</span>';
    }
}

if (!function_exists('st_markdown_promote_mermaid_diagrams')) {
    /**
     * Turn Parsedown fenced ```mermaid blocks into <div class="mermaid"> for client render.
     */
    function st_markdown_promote_mermaid_diagrams(string $html): string {
        if ($html === '' || stripos($html, 'language-mermaid') === false) {
            return $html;
        }
        return (string)preg_replace_callback(
            '#<pre>\s*<code\s+class="[^"]*\blanguage-mermaid\b[^"]*">(.*?)</code>\s*</pre>#is',
            static function (array $m): string {
                $src = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                return '<div class="mermaid st-mermaid-diagram" role="figure" aria-label="Diagram">'
                    . htmlspecialchars($src, ENT_NOQUOTES, 'UTF-8')
                    . '</div>';
            },
            $html
        );
    }
}

if (!function_exists('st_markdown_enhance_html')) {
    /**
     * Post-process Parsedown HTML (mermaid diagrams, @mentions).
     */
    function st_markdown_enhance_html(string $html): string {
        $html = st_markdown_promote_mermaid_diagrams($html);
        return st_linkify_mentions($html);
    }
}

if (!function_exists('st_markdown')) {
    /**
     * Render Markdown safely.
     *
     * Uses ParsedownTasks (public/includes/lib/ParsedownTasks.php) —
     * Parsedown 1.7.4 + ParsedownExtra 0.8.1 (footnotes, definition lists)
     * with a safe-mode footnote fix — with safe mode + escaped inline HTML,
     * so embedded HTML is treated as plain text and dangerous URL schemes
     * are stripped. Bare URLs are auto-linked. Fenced ```mermaid blocks
     * become diagram divs (see assets/mermaid-init.js). Markdown footnotes
     * ([^id] refs + [^id]: definitions) render as a linked footnote list.
     * Returns trusted HTML (safe to echo directly).
     *
     * Used for the task description body and individual comment bodies on
     * the admin task page.
     */
    function st_markdown(?string $raw, bool $inline = false): string {
        $raw = (string)$raw;
        if ($raw === '') {
            return '';
        }
        require_once __DIR__ . '/../includes/lib/ParsedownTasks.php';
        static $pd = null;
        if ($pd === null) {
            $pd = new ParsedownTasks();
            $pd->setSafeMode(true);
            $pd->setMarkupEscaped(true);
            $pd->setUrlsLinked(true);
            $pd->setBreaksEnabled(true);
        }
        $html = $inline ? $pd->line($raw) : $pd->text($raw);
        return st_markdown_enhance_html($html);
    }
}

if (!function_exists('st_mermaid_assets_html')) {
    /**
     * Script tags to render .mermaid blocks inside .markdown-body (admin + shared docs).
     */
    function st_mermaid_assets_html(): string {
        static $html = null;
        if ($html !== null) {
            return $html;
        }
        $v = '1';
        $html = '<script src="https://cdn.jsdelivr.net/npm/mermaid@10.9.3/dist/mermaid.min.js" '
            . 'crossorigin="anonymous"></script>'
            . '<script src="/assets/mermaid-init.js?v=' . $v . '"></script>';
        return $html;
    }
}

if (!function_exists('st_linkify_mentions')) {
    /**
     * Wrap @username tokens in rendered markdown HTML with a styled link.
     * Splits on `<code>`, `<pre>`, and `<a>` blocks so we never mangle
     * literals inside code spans/blocks or already-linked text.
     */
    function st_linkify_mentions(string $html): string {
        if ($html === '' || strpos($html, '@') === false) return $html;
        $skip = '/(<(?:code|pre)\b[^>]*>.*?<\/(?:code|pre)>|<a\b[^>]*>.*?<\/a>)/is';
        $parts = preg_split($skip, $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) return $html;
        $out = '';
        foreach ($parts as $i => $chunk) {
            if ($i % 2 === 1) {
                $out .= $chunk; // protected zone, unchanged
                continue;
            }
            $out .= preg_replace_callback(
                '/(^|[\s(>])@([A-Za-z0-9][A-Za-z0-9_.\-]{1,31})(?![A-Za-z0-9_.\-])/u',
                function ($m) {
                    $u = $m[2];
                    $href = '/admin/?q=' . rawurlencode('@' . $u);
                    return $m[1] . '<a class="mention" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8')
                        . '" data-mention-username="' . htmlspecialchars($u, ENT_QUOTES, 'UTF-8') . '">@'
                        . htmlspecialchars($u, ENT_QUOTES, 'UTF-8') . '</a>';
                },
                $chunk
            );
        }
        return $out;
    }
}

if (!function_exists('st_format_comment_body')) {
    function st_format_comment_body(string $raw): string {
        return st_markdown($raw);
    }
}

if (!function_exists('st_absolute_time_attr')) {
    /**
     * Title attribute string showing the absolute UTC timestamp for hover.
     */
    function st_absolute_time_attr(?string $iso): string {
        if (!$iso) return '';
        try {
            $dt = new DateTime($iso, new DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i') . ' UTC';
        } catch (Exception $e) {
            return (string)$iso;
        }
    }
}

if (!function_exists('st_absolute_time')) {
    /**
     * Compact human-readable UTC timestamp for inline display, e.g.
     * "May 4 14:32 UTC" (same year) or "Apr 30 2025 14:32 UTC" (other year).
     */
    function st_absolute_time(?string $iso): string {
        if (!$iso) return '';
        try {
            $dt = new DateTime($iso, new DateTimeZone('UTC'));
        } catch (Exception $e) {
            return (string)$iso;
        }
        $thisYear = (new DateTime('now', new DateTimeZone('UTC')))->format('Y');
        $fmt = ($dt->format('Y') === $thisYear) ? 'M j H:i' : 'M j Y H:i';
        return $dt->format($fmt) . ' UTC';
    }
}

if (!function_exists('st_humanize_rrule')) {
    /**
     * Best-effort plain-English rendering of an RRULE for display next to
     * the recurrence builder button. Falls back to the raw rule string
     * when it can't simplify the value.
     */
    function st_humanize_rrule(?string $rrule): string {
        $rrule = trim((string)$rrule);
        if ($rrule === '') return '';
        $bare = preg_replace('/^RRULE:/i', '', $rrule);
        $parts = [];
        foreach (explode(';', (string)$bare) as $kv) {
            if ($kv === '' || strpos($kv, '=') === false) continue;
            [$k, $v] = explode('=', $kv, 2);
            $parts[strtoupper(trim($k))] = trim($v);
        }
        $freq = strtoupper((string)($parts['FREQ'] ?? ''));
        if (!in_array($freq, ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true)) {
            return $rrule; // custom — show raw
        }
        $interval = max(1, (int)($parts['INTERVAL'] ?? 1));
        $word = [
            'DAILY' => ['day', 'days'],
            'WEEKLY' => ['week', 'weeks'],
            'MONTHLY' => ['month', 'months'],
            'YEARLY' => ['year', 'years'],
        ][$freq];
        $base = $interval === 1 ? ('Every ' . $word[0]) : ("Every {$interval} " . $word[1]);
        $extra = [];
        if ($freq === 'WEEKLY' && !empty($parts['BYDAY'])) {
            $map = ['MO' => 'Mon', 'TU' => 'Tue', 'WE' => 'Wed', 'TH' => 'Thu', 'FR' => 'Fri', 'SA' => 'Sat', 'SU' => 'Sun'];
            $days = [];
            foreach (explode(',', (string)$parts['BYDAY']) as $d) {
                $d = strtoupper(trim($d));
                if (isset($map[$d])) $days[] = $map[$d];
            }
            if ($days) $extra[] = 'on ' . implode(', ', $days);
        }
        if ($freq === 'MONTHLY' && !empty($parts['BYMONTHDAY'])) {
            $extra[] = 'on day ' . (string)$parts['BYMONTHDAY'];
        }
        $tail = '';
        if (!empty($parts['COUNT'])) {
            $tail = ' for ' . (int)$parts['COUNT'] . ' time' . (((int)$parts['COUNT']) === 1 ? '' : 's');
        } elseif (!empty($parts['UNTIL'])) {
            $u = (string)$parts['UNTIL'];
            if (preg_match('/^(\d{4})(\d{2})(\d{2})/', $u, $m)) {
                $tail = ' until ' . $m[1] . '-' . $m[2] . '-' . $m[3];
            }
        }
        return $base . ($extra ? ' ' . implode(' ', $extra) : '') . $tail;
    }
}

if (!function_exists('st_admin_breadcrumbs')) {
    /**
     * Bootstrap-style breadcrumb trail for admin UI.
     * Each item: ['label' => string, 'href' => optional string]. Omit href on the current page.
     */
    function st_admin_breadcrumbs(array $items): string {
        if ($items === []) {
            return '';
        }
        $parts = [
            '<nav class="admin-breadcrumb-nav" aria-label="Breadcrumb">',
            '<ol class="breadcrumb admin-breadcrumb mb-3">',
        ];
        $n = count($items);
        $i = 0;
        foreach ($items as $it) {
            $i++;
            $label = htmlspecialchars((string)($it['label'] ?? ''));
            $href = isset($it['href']) ? trim((string)$it['href']) : '';
            $isLast = $i === $n;
            if ($isLast) {
                $parts[] = '<li class="breadcrumb-item active" aria-current="page">' . $label . '</li>';
            } elseif ($href !== '') {
                $parts[] = '<li class="breadcrumb-item"><a href="' . htmlspecialchars($href) . '">' . $label . '</a></li>';
            } else {
                $parts[] = '<li class="breadcrumb-item">' . $label . '</li>';
            }
        }
        $parts[] = '</ol></nav>';
        return implode('', $parts);
    }
}

if (!function_exists('st_request_query')) {
    /** @param list<string> $omit */
    function st_request_query(array $omit = []): array {
        $q = $_GET;
        foreach ($omit as $key) {
            unset($q[$key]);
        }
        return $q;
    }
}

if (!function_exists('st_mine_filter_active')) {
    function st_mine_filter_active(): bool {
        return isset($_GET['mine']) && (string)$_GET['mine'] === '1';
    }
}

if (!function_exists('st_assigned_to_me_button')) {
    /**
     * Toggle link: reloads the page filtered to tasks assigned to the logged-in user.
     *
     * @param array<string, scalar|null> $baseQuery
     */
    function st_assigned_to_me_button(string $path, array $baseQuery, bool $active): string {
        $q = $baseQuery;
        if ($active) {
            unset($q['mine']);
        } else {
            $q['mine'] = '1';
        }
        $href = $path . '?' . http_build_query($q);
        $cls = $active ? 'btn-primary' : 'btn-outline-primary';
        return '<a class="btn btn-sm ' . $cls . '" href="' . htmlspecialchars($href) . '"'
            . ($active ? ' aria-current="true"' : '')
            . '><i class="bi bi-person-check me-1"></i>Assigned to me</a>';
    }
}

if (!function_exists('st_project_pin_form_html')) {
    /**
     * Compact pin/unpin POST form for board navigation.
     */
    function st_project_pin_form_html(int $projectId, bool $isPinned, string $returnUrl, string $btnClass = 'btn btn-sm btn-outline-secondary'): string {
        $action = $isPinned ? '0' : '1';
        $label = $isPinned ? 'Unpin' : 'Pin';
        $icon = $isPinned ? 'bi-pin-fill' : 'bi-pin-angle';
        $title = $isPinned ? 'Remove from pinned boards' : 'Pin this board for quick navigation';
        return '<form method="post" action="/admin/pin-project.php" class="d-inline m-0 position-relative" style="z-index:3;">'
            . csrfInputField()
            . '<input type="hidden" name="project_id" value="' . $projectId . '">'
            . '<input type="hidden" name="pinned" value="' . $action . '">'
            . '<input type="hidden" name="return" value="' . htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') . '">'
            . '<button type="submit" class="' . htmlspecialchars($btnClass) . '" title="' . htmlspecialchars($title) . '">'
            . '<i class="bi ' . $icon . ' me-1"></i>' . htmlspecialchars($label)
            . '</button></form>';
    }
}

require_once __DIR__ . '/../includes/doc_guide.php';
