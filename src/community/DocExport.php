<?php
declare(strict_types=1);

/** C:F-40. Pure file content/name construction; callers supply URLs, clock and CSS. */
final class DocExport
{
    public const MAX_PAGES = 200;
    public const MAX_BYTES = 20971520;
    private const META_KEYS = [
        'title', 'kind', 'source', 'exported_at', 'owner', 'status', 'tags',
        'verify_state', 'verified_by', 'verified_at', 'verify_expires',
    ];
    private const LABELS = [
        'kind' => '문서 종류', 'source' => '원본', 'exported_at' => '내보낸 시각',
        'owner' => '소유자', 'status' => '상태', 'tags' => '태그', 'verify_state' => '검증 상태',
        'verified_by' => '확인한 사람', 'verified_at' => '확인한 시각', 'verify_expires' => '유효 기한',
    ];

    public static function markdown(array $doc, array $meta, array $ctx = []): string
    {
        return self::frontMatter($meta) . "\n" . DocMarkdown::render($doc, $ctx);
    }

    public static function frontMatter(array $meta): string
    {
        $out = "---\n";
        foreach (self::META_KEYS as $key) {
            if (!array_key_exists($key, $meta) || $meta[$key] === null) {
                continue;
            }
            $value = self::metaValue($key, $meta[$key]);
            // JSON escapes are also YAML double-quoted escapes. Hex tags keep
            // this inert in Markdown readers which do not understand YAML.
            $out .= $key . ': ' . json_encode($value, JSON_UNESCAPED_UNICODE
                | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_THROW_ON_ERROR) . "\n";
        }
        return $out . "---\n";
    }

    public static function html(array $doc, array $meta, array $ctx, string $fixedCss): string
    {
        if (stripos($fixedCss, '</style') !== false) {
            throw new InvalidArgumentException('invalid_export_stylesheet');
        }
        $ctx['export'] = true;
        return '<!doctype html><html lang="ko"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta http-equiv="Content-Security-Policy" content="default-src &#039;none&#039;; style-src &#039;unsafe-inline&#039;">'
            . '<meta name="referrer" content="no-referrer"><title>' . self::esc((string) ($meta['title'] ?? ''))
            . '</title><style>' . $fixedCss . '</style></head><body><main>'
            . self::metadataHtml($meta) . DocRender::html($doc, $ctx) . '</main></body></html>';
    }

    public static function metadataHtml(array $meta): string
    {
        $out = '<header class="doc"><h1 class="doc-h1">' . self::esc((string) ($meta['title'] ?? '')) . '</h1><dl>';
        foreach (self::LABELS as $key => $label) {
            if (!array_key_exists($key, $meta) || $meta[$key] === null || $meta[$key] === '') {
                continue;
            }
            $value = self::metaValue($key, $meta[$key]);
            if ($key === 'kind') {
                $value = $value === 'procedure' ? '절차서' : '일반 문서';
            } elseif ($key === 'verify_state') {
                $value = ['verified' => '검증됨', 'expired' => '검증 만료', 'none' => '검증 없음'][$value] ?? $value;
            } elseif ($key === 'status') {
                $value = ['todo' => '할 일', 'doing' => '진행 중', 'review' => '검토', 'done' => '완료', 'hold' => '보류'][$value] ?? $value;
            }
            $text = self::esc($value);
            if ($key === 'source' && DocMarkdown::safeHref($value) !== null) {
                $text = '<a href="' . self::esc((string) DocMarkdown::safeHref($value)) . '" rel="noopener noreferrer">' . $text . '</a>';
            }
            $out .= '<dt>' . self::esc($label) . '</dt><dd>' . $text . '</dd>';
        }
        return $out . '</dl></header>';
    }

    private static function metaValue(string $key, $value): string
    {
        if ($key === 'kind') {
            return $value === 'procedure' ? 'procedure' : 'page';
        }
        if ($key === 'tags' && is_array($value)) {
            return implode(', ', array_filter($value, 'is_string'));
        }
        return is_scalar($value) ? (string) $value : '';
    }

    public static function safeName(string $title, int $limit = 80): string
    {
        $name = preg_replace('/[\p{C}]/u', '', $title) ?? '';
        $name = preg_replace('/[\\\\\/:"<>|?*]/u', '-', $name) ?? '';
        $name = preg_replace('/\.{2,}/u', '-', $name) ?? '';
        $name = trim($name, ". \t\n\r\0\x0B");
        $name = iconv_substr($name, 0, max(1, $limit), 'UTF-8') ?: '';
        $name = trim($name, '. ');
        if ($name === '') {
            $name = 'page';
        }
        if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/iD', $name) === 1) {
            $name = 'page-' . $name;
        }
        return (string) iconv_substr($name, 0, max(1, $limit), 'UTF-8');
    }

    public static function filename(string $title, string $format): string
    {
        if (!in_array($format, ['md', 'html', 'zip'], true)) {
            throw new InvalidArgumentException('invalid_export_format');
        }
        return self::safeName($title, 80 - strlen($format) - 1) . '.' . $format;
    }

    /** Names are generated from sanitized components, not request filesystem paths. */
    public static function uniqueName(string $title, string $format, array &$used, string $prefix = ''): string
    {
        if (!in_array($format, ['md', 'html'], true) || preg_match('/^[0-9-]*$/D', $prefix) !== 1) {
            throw new InvalidArgumentException('invalid_export_name');
        }
        $n = 1;
        do {
            $suffix = $n === 1 ? '' : '-' . $n;
            $limit = 80 - strlen($prefix . $suffix . '.' . $format);
            $leaf = self::safeName($title, $limit) . $suffix . '.' . $format;
            $name = $prefix . $leaf;
            $folded = function_exists('mb_strtolower') ? mb_strtolower($leaf, 'UTF-8') : strtolower($leaf);
            ++$n;
        } while (isset($used[$folded]));
        $used[$folded] = true;
        return $name;
    }

    public static function relativeHref(string $fromFile, string $toFile): string
    {
        $from = explode('/', $fromFile);
        array_pop($from);
        $to = explode('/', $toFile);
        while ($from !== [] && $to !== [] && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }
        return str_repeat('../', count($from)) . implode('/', array_map('rawurlencode', $to));
    }

    public static function index(array $entries, int $skipped, string $format, string $fixedCss = ''): string
    {
        $summary = '내보낸 페이지 ' . count($entries) . '개 · 볼 수 없어 빠진 페이지 ' . max(0, $skipped) . '개';
        if ($format === 'md') {
            $out = "# 내보낸 문서\n\n" . $summary . "\n\n";
            foreach ($entries as $entry) {
                $out .= '- ' . DocMarkdown::link((string) $entry['title'], self::relativeHref('README.md', (string) $entry['path'])) . "\n";
            }
            return $out . "\n첨부 파일은 링크로 제공됩니다. 열람할 때 사이트에서 권한을 확인합니다.\n";
        }
        if ($format !== 'html' || stripos($fixedCss, '</style') !== false) {
            throw new InvalidArgumentException('invalid_export_index');
        }
        $out = '<!doctype html><html lang="ko"><head><meta charset="utf-8">'
            . '<meta http-equiv="Content-Security-Policy" content="default-src &#039;none&#039;; style-src &#039;unsafe-inline&#039;">'
            . '<meta name="referrer" content="no-referrer"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>내보낸 문서</title><style>' . $fixedCss . '</style></head><body><main><h1>내보낸 문서</h1><p>'
            . self::esc($summary) . '</p><ul>';
        foreach ($entries as $entry) {
            $out .= '<li><a href="' . self::esc(self::relativeHref('index.html', (string) $entry['path'])) . '">'
                . self::esc((string) $entry['title']) . '</a></li>';
        }
        return $out . '</ul><p>첨부 파일은 링크로 제공됩니다. 열람할 때 사이트에서 권한을 확인합니다.</p></main></body></html>';
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
