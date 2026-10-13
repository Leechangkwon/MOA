<?php
declare(strict_types=1);

/** C:F-40. Pure block-to-Markdown conversion; permission-filtered maps are inputs. */
final class DocMarkdown
{
    public static function render(array $doc, array $ctx = []): string
    {
        $blocks = is_array($doc['blocks'] ?? null) ? $doc['blocks'] : [];
        return rtrim(self::blocks($blocks, $blocks, $ctx)) . "\n";
    }

    public static function escape(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace(['&', '<'], ['&amp;', '&lt;'], $text);
        // Escape punctuation before a parser can mistake plain text for markup.
        return preg_replace('/([\\\\`*_\[\]()~|!#>+\-=.])/', '\\\\$1', $text) ?? '';
    }

    /** Only generated relative links or revalidated http(s)/mailto targets. */
    public static function safeHref(?string $href): ?string
    {
        if ($href === null || $href === '' || preg_match('/[\x00-\x1f\x7f\\\\]/', $href) === 1) {
            return null;
        }
        if (Doc::isExternal($href)) {
            if (!Doc::schemeOk($href)) {
                return null;
            }
        } elseif (str_contains($href, ':') || str_starts_with($href, '//')) {
            return null;
        }
        return preg_replace_callback('/[()<>\s"`{}|]/u',
            static fn(array $m): string => rawurlencode($m[0]), $href);
    }

    public static function link(string $label, ?string $href): string
    {
        $safe = self::safeHref($href);
        $label = self::escape($label);
        return $safe === null ? $label : '[' . $label . '](' . $safe . ')';
    }

    private static function rich(array $segments, array $ctx): string
    {
        $out = '';
        foreach ($segments as $seg) {
            if (!is_array($seg) || !is_string($seg['t'] ?? null)) {
                continue;
            }
            $marks = is_array($seg['m'] ?? null) ? $seg['m'] : [];
            $text = $seg['t'];
            if (array_key_exists('mention', $marks)) {
                $mention = $marks['mention'];
                $label = '@볼 수 없음';
                $href = null;
                if (Doc::mentionShapeOk($mention)) {
                    if ($mention['kind'] === 'date') {
                        $label = '@' . $mention['id'];
                    } else {
                        $entry = $ctx['mention_labels'][$mention['kind'] . ':' . $mention['id']] ?? null;
                        if (is_array($entry) && is_string($entry['label'] ?? null)) {
                            $label = '@' . $entry['label'];
                            if ($mention['kind'] === 'page') {
                                $href = $ctx['page_href'][$mention['id']] ?? null;
                                if (self::safeHref($href) === null) {
                                    $label = '@볼 수 없음';
                                }
                            }
                        }
                    }
                }
                $out .= self::link($label, $href);
                continue;
            }
            $href = $marks['link']['href'] ?? null;
            if (is_string($href) && str_starts_with($href, 'page:')) {
                $pageId = substr($href, 5);
                $target = $ctx['page_href'][$pageId] ?? null;
                if (!Doc::schemeOk($href) || self::safeHref($target) === null) {
                    $out .= self::escape('볼 수 없는 페이지');
                    continue;
                }
                $href = $target;
            } elseif (!is_string($href) || !Doc::schemeOk($href)) {
                $href = null;
            }
            if (($marks['code'] ?? false) === true) {
                $code = str_replace(["\r\n", "\r", "\n"], ' ', $text);
                preg_match_all('/`+/', $code, $runs);
                $fence = str_repeat('`', max([0, ...array_map('strlen', $runs[0])]) + 1);
                $pad = (str_starts_with($code, '`') || str_ends_with($code, '`')
                    || (str_starts_with($code, ' ') && str_ends_with($code, ' ') && trim($code) !== '')) ? ' ' : '';
                $part = $fence . $pad . $code . $pad . $fence;
            } else {
                $part = self::escape($text);
            }
            foreach (['strike' => '~~', 'italic' => '*', 'bold' => '**'] as $mark => $delimiter) {
                if (($marks[$mark] ?? false) === true && $part !== '') {
                    $part = $delimiter . $part . $delimiter;
                }
            }
            $safe = self::safeHref($href);
            $out .= $safe === null ? $part : '[' . $part . '](' . $safe . ')';
        }
        return $out;
    }

    private static function indent(string $text, string $prefix): string
    {
        return $prefix . str_replace("\n", "\n" . $prefix, rtrim($text));
    }

    private static function blocks(array $blocks, array $root, array $ctx): string
    {
        $out = '';
        foreach ($blocks as $block) {
            if (!is_array($block) || !is_string($block['type'] ?? null)) {
                $out .= "지원하지 않는 블록\n\n";
                continue;
            }
            $text = self::rich(is_array($block['text'] ?? null) ? $block['text'] : [], $ctx);
            $children = is_array($block['children'] ?? null) ? $block['children'] : [];
            $kids = self::blocks($children, $root, $ctx);
            $caption = self::rich(is_array($block['caption'] ?? null) ? $block['caption'] : [], $ctx);
            switch ($block['type']) {
                case 'synced_source':
                    $out.=$kids;break;
                case 'synced_ref':
                    $source=$ctx['synced'][$block['source_id']??'']??['message'=>'볼 수 없는 원본'];
                    $out.=isset($source['blocks'])?self::blocks($source['blocks'],$source['blocks'],$ctx):self::escape($source['message'])."\n\n";break;
                case 'paragraph':
                    $out .= $text . "\n\n" . $kids;
                    break;
                case 'heading':
                    $level = is_int($block['level'] ?? null) ? max(1, min(3, $block['level'])) : 1;
                    $out .= str_repeat('#', $level) . ' ' . $text . "\n\n" . $kids;
                    break;
                case 'bulleted':
                case 'numbered':
                case 'to_do':
                    $prefix = match ($block['type']) {
                        'numbered' => '1. ',
                        'to_do' => ($block['checked'] ?? false) === true ? '- [x] ' : '- [ ] ',
                        default => '- ',
                    };
                    $out .= $prefix . str_replace("\n", "\n" . str_repeat(' ', strlen($prefix)), $text) . "\n";
                    if ($kids !== '') {
                        $out .= self::indent($kids, str_repeat(' ', strlen($prefix))) . "\n";
                    }
                    $out .= "\n";
                    break;
                case 'quote':
                case 'callout':
                    $icon = $block['type'] === 'callout' && is_string($block['icon'] ?? null)
                        ? self::escape($block['icon']) . ' ' : '';
                    $out .= self::indent($icon . $text, '> ') . "\n\n" . $kids;
                    break;
                case 'divider':
                    $out .= "---\n\n";
                    break;
                case 'code':
                    $code = is_string($block['code'] ?? null) ? $block['code'] : '';
                    preg_match_all('/`+/', $code, $runs);
                    $fence = str_repeat('`', max([2, ...array_map('strlen', $runs[0])]) + 1);
                    $language = is_string($block['language'] ?? null)
                        && preg_match('/^[a-z0-9+#-]+$/D', $block['language']) === 1 ? $block['language'] : '';
                    $out .= $fence . $language . "\n" . $code . "\n" . $fence . "\n\n";
                    if ($caption !== '') {
                        $out .= $caption . "\n\n";
                    }
                    break;
                case 'toc':
                    $out .= self::headings($root, $ctx) . "\n";
                    break;
                case 'toggle':
                    $out .= '▸ ' . $text . "\n\n";
                    if ($kids !== '') {
                        $out .= self::indent($kids, '    ') . "\n\n";
                    }
                    break;
                case 'column_list':
                case 'column':
                    $out .= $kids;
                    break;
                case 'table':
                    $out .= self::table($block, $ctx) . "\n\n";
                    break;
                case 'child_page':
                case 'link_to_page':
                    $id = is_string($block['page_id'] ?? null) ? $block['page_id'] : '';
                    $map = $block['type'] === 'child_page' ? 'child_titles' : 'link_titles';
                    $entry = $ctx[$map][$id] ?? null;
                    $href = $ctx['page_href'][$id] ?? null;
                    if (!is_array($entry) || self::safeHref($href) === null) {
                        $out .= "볼 수 없는 페이지\n\n";
                    } else {
                        $icon = is_string($entry['icon'] ?? null) && $entry['icon'] !== '' ? $entry['icon'] . ' ' : '';
                        $out .= self::link($icon . (string) ($entry['title'] ?? ''), $href) . "\n\n";
                    }
                    break;
                case 'image':
                case 'file':
                    // Q:F-12 U3-1 — `att:` 문서 전용 첨부는 부르는 쪽이 준 첨부 지도 · 주소(로그인 필요한 창구)로만 링크(없으면 「볼 수 없는 파일」)
                    $isAtt = is_string($block['file_ref'] ?? null) && str_starts_with($block['file_ref'], 'att:');
                    $ref = $isAtt ? substr($block['file_ref'], 4)
                        : (is_string($block['file_ref'] ?? null) && str_starts_with($block['file_ref'], 'tbox:')
                            ? substr($block['file_ref'], 5) : '');
                    $entry = $ctx[$isAtt ? 'attachment_titles' : 'file_titles'][$ref] ?? null;
                    $href = $ctx[$isAtt ? 'attachment_href' : 'file_href'][$ref] ?? null;
                    if (!is_array($entry) || self::safeHref($href) === null) {
                        $out .= "볼 수 없는 파일\n\n";
                    } else {
                        $label = ($block['type'] === 'image' ? '그림: ' : '파일: ') . (string) ($entry['name'] ?? '');
                        if ($block['type'] === 'file') {
                            $label .= ' (' . number_format(max(0, (int) ($entry['size'] ?? 0))) . ' B)';
                        }
                        $out .= self::link($label, $href) . "\n\n";
                        if ($caption !== '') {
                            $out .= $caption . "\n\n";
                        }
                    }
                    break;
                case 'embed':
                    $url = is_string($block['url'] ?? null) ? $block['url'] : '';
                    $host = Doc::embedHost($url);
                    $hosts = is_array($ctx['embeds']['hosts'] ?? null) ? $ctx['embeds']['hosts'] : [];
                    if ($host === null || !in_array($host, $hosts, true)) {
                        $out .= "허용 목록 밖의 임베드\n\n";
                    } else {
                        $out .= self::link('임베드: ' . $host, $url) . "\n\n";
                        if ($caption !== '') {
                            $out .= $caption . "\n\n";
                        }
                    }
                    break;
                case 'db_view':
                    $widget=$ctx['db_views'][$block['id']] ?? null;
                    $out .= self::escape($widget===null ? '[볼 수 없는 데이터베이스]' : '[데이터베이스 보기: ' . $widget['title'] . ' · ' . $widget['name'] . ']') . "\n\n";
                    break;
                case 'button':
                    // Stage 9-C exports an inert label, never an automation identifier.
                    $out .= self::escape('[버튼: ' . (string) ($block['label'] ?? '') . ']') . "\n\n";
                    break;
                default:
                    $out .= "지원하지 않는 블록\n\n";
            }
        }
        return $out;
    }

    private static function headings(array $blocks, array $ctx): string
    {
        $out = '';
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['type'] ?? '') === 'heading') {
                $level = is_int($block['level'] ?? null) ? max(1, min(3, $block['level'])) : 1;
                $out .= str_repeat('  ', $level - 1) . '- '
                    . self::rich(is_array($block['text'] ?? null) ? $block['text'] : [], $ctx) . "\n";
            }
            if (is_array($block['children'] ?? null)) {
                $out .= self::headings($block['children'], $ctx);
            }
        }
        return $out;
    }

    private static function table(array $block, array $ctx): string
    {
        $rows = is_array($block['rows'] ?? null) ? $block['rows'] : [];
        if ($rows === []) {
            return '';
        }
        $columns = max(1, ...array_map(static fn($r): int => is_array($r['cells'] ?? null) ? count($r['cells']) : 0, $rows));
        $covered = [];
        $rendered = [];
        $merged = false;
        foreach ($rows as $ri => $row) {
            $cells = [];
            for ($ci = 0; $ci < $columns; ++$ci) {
                $cell = $row['cells'][$ci] ?? [];
                if (isset($covered[$ri][$ci])) {
                    $cells[] = '';
                    continue;
                }
                $rs = max(1, min(count($rows), (int) ($cell['rowspan'] ?? 1)));
                $cs = max(1, min($columns, (int) ($cell['colspan'] ?? 1)));
                $merged = $merged || $rs > 1 || $cs > 1;
                for ($r = $ri; $r < min(count($rows), $ri + $rs); ++$r) {
                    for ($c = $ci; $c < min($columns, $ci + $cs); ++$c) {
                        if ($r !== $ri || $c !== $ci) {
                            $covered[$r][$c] = true;
                        }
                    }
                }
                $value = self::rich(is_array($cell['text'] ?? null) ? $cell['text'] : [], $ctx);
                // Code spans also need escaped pipes for GFM's table parser.
                $value = preg_replace('/(?<!\\\\)\|/', '\\|', $value) ?? '';
                $cells[] = str_replace(["\r", "\n"], ' ', $value);
            }
            $rendered[] = '| ' . implode(' | ', $cells) . ' |';
        }
        if (($block['header_row'] ?? false) !== true) {
            array_unshift($rendered, '| ' . implode(' | ', array_fill(0, $columns, '')) . ' |');
        }
        array_splice($rendered, 1, 0, ['| ' . implode(' | ', array_fill(0, $columns, '---')) . ' |']);
        return implode("\n", $rendered) . ($merged ? "\n\n합친 칸 있음" : '');
    }
}
