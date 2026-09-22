<?php
declare(strict_types=1);

/**
 * src/community/DocRender.php — 블록 본문을 HTML 문자열로 그리는 **서버 렌더러**
 *
 * 요구사항: `C:F-15`·`C:S-11`, `../community/ARCHITECTURE.md` 12.4, `DESIGN.md` 2.14, 수락 기준 C:T-15·C:T-19.
 *
 * 규칙
 *   · 사용자 값이 들어가는 자리는 **전부 `htmlspecialchars(ENT_QUOTES, 'UTF-8')`** 를 거친다.
 *   · 속성은 만들지 않는다. 꾸밈(색·정렬·크기)은 **허용 목록 → CSS 클래스**로만 나간다.
 *     클래스 문자열도 사용자 값이 아니라 우리가 고른 고정 문자열이다.
 *   · 링크는 그리기 직전에 **스킴을 다시 본다**(`Doc::schemeOk`). 검증기를 통과한 문서만
 *     들어오지만, 렌더러가 검증기를 믿지 않는 것이 이 자리의 규칙이다 (ARCH 12.4).
 *   · 모르는 타입은 「지원하지 않는 블록」 한 줄로 그린다 — 조용히 버리지 않는다.
 *
 * 쓰는 곳: 인쇄·발췌·알림 미리보기, 그리고 수락 기준 C:T-15·C:T-19 의 대조 기준.
 * 페이지 본문 화면은 클라이언트 렌더러(`public/assets/community/doc-render.js`)가 그린다 —
 * 두 벌의 출력(텍스트 노드 열·클래스 집합)이 같은지 시험이 확인한다.
 */
final class DocRender
{
    /**
     * 마크를 감싸는 **고정 순서**(안쪽 → 바깥쪽). 클라이언트 렌더러와 **같은 순서**여야 한다 —
     * 순서가 다르면 두 렌더러의 DOM 이 달라지고 C:T-19 가 실패한다.
     *
     * @var list<string>
     */
    private const MARK_ORDER = ['code', 'color', 'strike', 'underline', 'italic', 'bold', 'link'];

    /**
     * `child_page` 가 가리키는 페이지를 볼 수 없을 때의 문구 (4단계).
     * **클라이언트 렌더러(`doc-render.js`)의 같은 상수와 한 글자도 다르면 안 된다** — C:T-19.
     */
    private const CHILD_PAGE_UNREADABLE = '볼 수 없는 페이지';

    /** 마크 이름 → 태그 (색은 span + 클래스, 링크는 a) */
    private const MARK_TAG = [
        'code'      => 'code',
        'strike'    => 's',
        'underline' => 'u',
        'italic'    => 'em',
        'bold'      => 'strong',
    ];

    /**
     * 문서 하나를 HTML 로. 겉을 `<div class="doc">` 하나로 감싼다.
     *
     * @param array<string,mixed> $doc 검사를 통과한 문서
     */
    public static function html(array $doc, array $ctx = []): string
    {
        $blocks = is_array($doc['blocks'] ?? null) ? $doc['blocks'] : [];
        return '<div class="doc">' . self::blocks($blocks, $blocks, $ctx) . '</div>';
    }

    /**
     * 블록 배열 하나. 이어진 목록은 한 `<ul>`·`<ol>` 로 묶는다.
     *
     * @param list<mixed> $list
     * @param list<mixed> $rootBlocks 목차(`toc`)가 훑을 문서 전체
     */
    private static function blocks(array $list, array $rootBlocks, array $ctx = []): string
    {
        $out     = '';
        $openTag = '';   // 지금 열려 있는 목록 태그 (ul·ol) — 없으면 빈 문자열

        foreach ($list as $block) {
            if (!is_array($block) || !isset($block['type']) || !is_string($block['type'])) {
                $out    .= self::closeList($openTag);
                $openTag = '';
                $out    .= '<div class="block-unsupported">지원하지 않는 블록</div>';
                continue;
            }
            $type = $block['type'];
            $want = $type === 'bulleted' ? 'ul' : ($type === 'numbered' ? 'ol' : '');

            if ($want !== $openTag) {
                $out    .= self::closeList($openTag);
                $openTag = $want;
                if ($want === 'ul') {
                    $out .= '<ul class="doc-ul">';
                } elseif ($want === 'ol') {
                    $out .= '<ol class="doc-ol">';
                }
            }

            $out .= self::block($block, $rootBlocks, $ctx);
        }

        return $out . self::closeList($openTag);
    }

    private static function closeList(string $openTag): string
    {
        if ($openTag === 'ul') {
            return '</ul>';
        }
        if ($openTag === 'ol') {
            return '</ol>';
        }
        return '';
    }

    /**
     * 블록 하나.
     *
     * @param array<string,mixed> $block
     * @param list<mixed>         $rootBlocks
     */
    private static function block(array $block, array $rootBlocks, array $ctx = []): string
    {
        $type  = (string) $block['type'];
        $id    = isset($block['id']) && is_string($block['id']) ? $block['id'] : '';
        $idAttr = $id === '' ? '' : ' id="block-' . self::esc($id) . '"';
        $text  = isset($block['text']) && is_array($block['text']) ? self::rich($block['text']) : '';
        $kids  = isset($block['children']) && is_array($block['children'])
            ? self::blocks($block['children'], $rootBlocks, $ctx)
            : '';

        switch ($type) {
            case 'paragraph':
                return '<p class="' . self::cls('doc-p', $block) . '"' . $idAttr . '>' . $text . '</p>' . $kids;

            case 'heading':
                $level = isset($block['level']) && is_int($block['level']) && $block['level'] >= 1 && $block['level'] <= 3
                    ? $block['level']
                    : 1;
                $tag = 'h' . (string) $level;
                return '<' . $tag . ' class="' . self::cls('doc-h' . (string) $level, $block) . '"' . $idAttr . '>'
                     . $text . '</' . $tag . '>' . $kids;

            case 'bulleted':
            case 'numbered':
                return '<li class="' . self::cls('doc-li', $block) . '"' . $idAttr . '>' . $text . $kids . '</li>';

            case 'to_do':
                $done = ($block['checked'] ?? false) === true;
                return '<div class="' . self::cls('doc-todo' . ($done ? ' is-done' : ''), $block) . '"' . $idAttr . '>'
                     . '<span class="doc-todo__box" aria-hidden="true">' . ($done ? '✔' : '') . '</span>'
                     . '<span class="doc-todo__text">' . $text . '</span></div>' . $kids;

            case 'quote':
                return '<blockquote class="' . self::cls('doc-quote', $block) . '"' . $idAttr . '>' . $text . '</blockquote>' . $kids;

            case 'callout':
                $icon = isset($block['icon']) && is_string($block['icon']) ? $block['icon'] : '';
                return '<div class="' . self::cls('doc-callout', $block) . '"' . $idAttr . '>'
                     . '<span class="doc-callout__icon" aria-hidden="true">' . self::esc($icon) . '</span>'
                     . '<span class="doc-callout__text">' . $text . '</span></div>' . $kids;

            case 'divider':
                return '<hr class="doc-divider"' . $idAttr . '>';

            case 'code':
                $code = isset($block['code']) && is_string($block['code']) ? $block['code'] : '';
                $lang = isset($block['language']) && is_string($block['language']) ? $block['language'] : 'plain';
                $cap  = isset($block['caption']) && is_array($block['caption']) ? self::rich($block['caption']) : '';
                $html = '<div class="doc-code"' . $idAttr . '>'
                      . '<span class="doc-code__lang">' . self::esc($lang) . '</span>'
                      . '<pre><code>' . self::esc($code) . '</code></pre>';
                if ($cap !== '') {
                    $html .= '<p class="doc-code__caption">' . $cap . '</p>';
                }
                return $html . '</div>';

            case 'toc':
                return self::toc($rootBlocks, $idAttr);

            /*
             * 하위 페이지 (4단계). 본문 안에 **카드**로 놓이고 누르면 그 페이지로 간다.
             *
             * 제목은 `$ctx['child_titles']` 에서 찾는다 — 그 지도는 창구가 **볼 수 있는 것만**
             * 담아 준다(`pages_get.php`). 지도에 없으면 「볼 수 없는 페이지」 한 장이고, 그때는
             * 누를 수 없으니 `<a>` 가 아니다 (DESIGN 2.3 — 눌러도 아무 일 없는 카드에
             * `.card--link` 를 쓰지 않는다). 클라이언트 렌더러와 **같은 문구·같은 클래스**다
             * (C:T-19 가 텍스트 노드 열과 클래스 집합을 견준다).
             */
            case 'child_page':
                $childId = isset($block['page_id']) && is_string($block['page_id']) ? $block['page_id'] : '';
                $titles  = is_array($ctx['child_titles'] ?? null) ? $ctx['child_titles'] : [];
                $entry   = is_array($titles[$childId] ?? null) ? $titles[$childId] : null;
                if ($entry === null) {
                    return '<div class="card card--soon doc-child-page"' . $idAttr . '>'
                         . '<span class="card__title">' . self::CHILD_PAGE_UNREADABLE . '</span></div>';
                }
                $childIcon = isset($entry['icon']) && is_string($entry['icon']) && $entry['icon'] !== ''
                    ? $entry['icon'] . ' '
                    : '';
                $childLabel = $childIcon
                    . (isset($entry['title']) && is_string($entry['title']) ? $entry['title'] : '');
                return '<a class="card card--link doc-child-page"'
                     . ' href="' . self::esc(Http::communityUrl('/p/' . $childId)) . '"' . $idAttr . '>'
                     . '<span class="card__title">' . self::esc($childLabel) . '</span></a>';

            default:
                return '<div class="block-unsupported">지원하지 않는 블록</div>';
        }
    }

    /**
     * 목차 — 문서 안의 제목 블록을 모은다.
     *
     * @param list<mixed> $rootBlocks
     */
    private static function toc(array $rootBlocks, string $idAttr): string
    {
        $items = '';
        foreach (self::headings($rootBlocks) as $h) {
            $items .= '<li class="doc-toc__item doc-toc__item--' . (string) $h['level'] . '">'
                    . '<a href="#block-' . self::esc($h['id']) . '">' . self::esc($h['text']) . '</a></li>';
        }
        if ($items === '') {
            return '<nav class="doc-toc"' . $idAttr . '><p class="muted">제목이 아직 없습니다</p></nav>';
        }
        return '<nav class="doc-toc"' . $idAttr . '><ul>' . $items . '</ul></nav>';
    }

    /**
     * @param  list<mixed> $list
     * @return list<array{id:string,level:int,text:string}>
     */
    private static function headings(array $list): array
    {
        $out = [];
        foreach ($list as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['type'] ?? '') === 'heading' && isset($block['id']) && is_string($block['id'])) {
                $out[] = [
                    'id'    => $block['id'],
                    'level' => isset($block['level']) && is_int($block['level']) ? $block['level'] : 1,
                    'text'  => isset($block['text']) && is_array($block['text']) ? Doc::plain($block['text']) : '',
                ];
            }
            if (isset($block['children']) && is_array($block['children'])) {
                foreach (self::headings($block['children']) as $child) {
                    $out[] = $child;
                }
            }
        }
        return $out;
    }

    /**
     * 블록의 클래스 문자열 — 고정 이름 + 꾸밈 세 가지(허용 목록 안의 값만).
     *
     * @param array<string,mixed> $block
     */
    private static function cls(string $base, array $block): string
    {
        $out = [$base];

        $color = $block['color'] ?? null;
        if (is_string($color)) {
            $name = self::colorClass($color);
            if ($name !== null) {
                $out[] = $name;
            }
        }

        $align = $block['align'] ?? null;
        if ($align === 'center' || $align === 'right') {
            $out[] = 'al-' . $align;
        }

        $size = $block['size'] ?? null;
        if (in_array($size, ['small', 'normal', 'large', 'xlarge'], true)) {
            $out[] = 'sz-' . (string) $size;
        }

        return implode(' ', $out);
    }

    /**
     * 색 이름(`red`·`red_bg`) → 클래스(`nt-red`·`nt-red-bg`). 허용 목록 밖이면 null 이다 —
     * 그리기 직전에 한 번 더 보는 자리라, 값이 통과하지 못하면 클래스를 붙이지 않는다.
     */
    private static function colorClass(string $color): ?string
    {
        if (!in_array($color, Doc::colorValues(), true)) {
            return null;
        }
        if (substr($color, -3) === '_bg') {
            return 'nt-' . substr($color, 0, -3) . '-bg';
        }
        return 'nt-' . $color;
    }

    /**
     * 리치 텍스트 → HTML. 세그먼트마다 마크를 **고정 순서**로 감싼다.
     *
     * @param list<mixed> $text
     */
    private static function rich(array $text): string
    {
        $out = '';
        foreach ($text as $seg) {
            if (!is_array($seg) || !isset($seg['t']) || !is_string($seg['t'])) {
                continue;
            }
            $inner = self::esc($seg['t']);
            $marks = isset($seg['m']) && is_array($seg['m']) ? $seg['m'] : [];

            foreach (self::MARK_ORDER as $name) {
                if (!array_key_exists($name, $marks)) {
                    continue;
                }
                $value = $marks[$name];

                if ($name === 'color') {
                    if (!is_string($value)) {
                        continue;
                    }
                    $klass = self::colorClass($value);
                    if ($klass === null) {
                        continue;
                    }
                    $inner = '<span class="' . $klass . '">' . $inner . '</span>';
                    continue;
                }

                if ($name === 'link') {
                    if (!is_array($value) || !isset($value['href']) || !is_string($value['href'])) {
                        continue;
                    }
                    $href = $value['href'];
                    // ★ 그리기 직전 스킴 재검사 (ARCH 12.4). 통과하지 못하면 링크를 만들지 않고
                    //   글자만 남긴다 — 「모르는 주소」는 주소가 아니라 글자다.
                    if (!Doc::schemeOk($href)) {
                        continue;
                    }
                    $attr = ' href="' . self::esc($href) . '"';
                    if (Doc::isExternal($href)) {
                        $attr .= ' target="_blank" rel="noopener noreferrer"';
                    }
                    $inner = '<a class="doc-link"' . $attr . '>' . $inner . '</a>';
                    continue;
                }

                if ($value !== true) {
                    continue;
                }
                $tag = self::MARK_TAG[$name];
                $cls = $name === 'code' ? ' class="doc-inline-code"' : '';
                $inner = '<' . $tag . $cls . '>' . $inner . '</' . $tag . '>';
            }

            $out .= $inner;
        }
        return $out;
    }

    private static function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}
