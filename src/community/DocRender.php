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
    private const MARK_ORDER = ['code', 'color', 'strike', 'underline', 'italic', 'bold', 'link', 'mention'];

    /**
     * `child_page` 가 가리키는 페이지를 볼 수 없을 때의 문구 (4단계).
     * **클라이언트 렌더러(`doc-render.js`)의 같은 상수와 한 글자도 다르면 안 된다** — C:T-19.
     */
    private const CHILD_PAGE_UNREADABLE = '볼 수 없는 페이지';
    /** 5-E — 보는 사람이 t-box 에서 볼 수 없는(또는 없는) 파일 자리. 클라이언트 렌더러와 같은 문구(C:T-19) */
    private const FILE_UNREADABLE = '볼 수 없는 파일';
    /** 7-C — 임베드 카드 문구. 클라이언트 렌더러와 같은 문구(C:T-19) */
    private const EMBED_WAITING = '허용 대기 · 새 탭에서 열기';
    private const EMBED_BLOCKED = '허용 목록 밖의 임베드';
    /**
     * 7-D4 — 글 안 멘션의 대상을 볼 수 없을 때(없는 대상·못 보는 대상·모양 밖 id 모두 같은 글자) 「@」 뒤에 붙는 문구.
     * **클라이언트 렌더러(`doc-render.js`)의 같은 상수와 한 글자도 다르면 안 된다** — C:T-19 · C:T-225.
     */
    private const MENTION_UNREADABLE = '볼 수 없음';

    /**
     * 6-C — 합친 칸이 덮는 자리 `[행][열] => true`. doc-render.js `coveredCells` 와 같은 규칙(행 순서로 훑어 시작 칸만 덮는다).
     *
     * @param list<mixed> $rows
     * @return array<int,array<int,bool>>
     */
    private static function coveredCells(array $rows): array
    {
        $covered = [];
        foreach ($rows as $ri => $row) {
            $cells = is_array($row) && isset($row['cells']) && is_array($row['cells']) ? $row['cells'] : [];
            foreach ($cells as $ci => $cell) {
                if (isset($covered[$ri][$ci]) || !is_array($cell)) {
                    continue;
                }
                $rs = is_int($cell['rowspan'] ?? null) ? max(1, min(20, (int) $cell['rowspan'])) : 1;
                $cs = is_int($cell['colspan'] ?? null) ? max(1, min(20, (int) $cell['colspan'])) : 1;
                for ($y = $ri; $y < $ri + $rs; $y++) {
                    for ($x = $ci; $x < $ci + $cs; $x++) {
                        if ($y !== $ri || $x !== $ci) {
                            $covered[$y][$x] = true;
                        }
                    }
                }
            }
        }
        return $covered;
    }

    /** 5-E — 주소에 넣는 페이지·파일 id 의 모양 (doc-render.js 의 UUID_RE 와 같다) */
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

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
        $text  = isset($block['text']) && is_array($block['text']) ? self::rich($block['text'], $ctx) : '';
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
                $cap  = isset($block['caption']) && is_array($block['caption']) ? self::rich($block['caption'], $ctx) : '';
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

            /* ── 5-E 블록 다섯 — 클라이언트 렌더러(`doc-render.js`)와 **같은 태그·클래스·문구**(C:T-19) ── */

            // 토글 — 펼치고 접는 것은 `<details>` 가 한다(스크립트 0). 하위 블록은 **안에** 들어간다.
            case 'toggle':
                return '<details class="' . self::cls('doc-toggle', $block) . '"' . $idAttr . '>'
                     . '<summary class="doc-toggle__summary">' . $text . '</summary>'
                     . '<div class="doc-toggle__body">' . $kids . '</div></details>';

            // 6-C 단 나누기 — 단 목록은 단 수를 클래스로(2·3), 단 안은 보통 블록. 좁은 화면에서는 CSS 가 세로로 쌓는다.
            case 'column_list':
                $n = isset($block['children']) && is_array($block['children']) ? count($block['children']) : 0;
                return '<div class="doc-columns doc-columns--' . ($n === 3 ? '3' : '2') . '"' . $idAttr . '>' . $kids . '</div>';
            case 'column':
                return '<div class="doc-column"' . $idAttr . '>' . $kids . '</div>';

            // 표 — 머리 행·머리 열은 `<th>`. 6-C 칸 병합: 합친 칸은 rowspan·colspan(정수, 1 이면 쓰지 않음), 덮인 칸은 그리지 않는다.
            case 'table':
                $rows    = isset($block['rows']) && is_array($block['rows']) ? $block['rows'] : [];
                $headRow = ($block['header_row'] ?? false) === true;
                $headCol = ($block['header_col'] ?? false) === true;
                $html    = '<div class="doc-table-wrap"' . $idAttr . '><table class="doc-table"><tbody>';
                $covered = self::coveredCells($rows);
                foreach ($rows as $ri => $row) {
                    $rowCls = is_array($row) && isset($row['color']) && is_string($row['color']) ? self::colorClass($row['color']) : null;
                    $html  .= '<tr' . ($rowCls === null ? '' : ' class="' . self::esc($rowCls) . '"') . '>';
                    $cells  = is_array($row) && isset($row['cells']) && is_array($row['cells']) ? $row['cells'] : [];
                    foreach ($cells as $ci => $cell) {
                        if (isset($covered[$ri][$ci])) {
                            continue;
                        }
                        $tag     = ($headRow && $ri === 0) || ($headCol && $ci === 0) ? 'th' : 'td';
                        $cellCls = is_array($cell) && isset($cell['color']) && is_string($cell['color']) ? self::colorClass($cell['color']) : null;
                        $rs      = is_array($cell) && is_int($cell['rowspan'] ?? null) && $cell['rowspan'] > 1 ? ' rowspan="' . (int) $cell['rowspan'] . '"' : '';
                        $cs      = is_array($cell) && is_int($cell['colspan'] ?? null) && $cell['colspan'] > 1 ? ' colspan="' . (int) $cell['colspan'] . '"' : '';
                        $html   .= '<' . $tag . $rs . $cs . ' class="doc-table__cell' . ($cellCls === null ? '' : ' ' . self::esc($cellCls)) . '">'
                                 . (is_array($cell) && isset($cell['text']) && is_array($cell['text']) ? self::rich($cell['text'], $ctx) : '')
                                 . '</' . $tag . '>';
                    }
                    $html .= '</tr>';
                }
                return $html . '</tbody></table></div>';

            // 페이지 링크 — 하위 페이지 카드와 같은 모양, 제목은 `$ctx['link_titles']`(볼 수 있는 것만)
            case 'link_to_page':
                // UUID 형태만 주소에 넣는다(지도 키가 DB 값이라 이미 안전하지만 한 겹 더 — 독립 검토 L6)
                $linkId = isset($block['page_id']) && is_string($block['page_id']) && preg_match(self::UUID_RE, $block['page_id']) === 1
                    ? $block['page_id'] : '';
                $lmap   = is_array($ctx['link_titles'] ?? null) ? $ctx['link_titles'] : [];
                $lent   = is_array($lmap[$linkId] ?? null) ? $lmap[$linkId] : null;
                if ($lent === null) {
                    return '<div class="card card--soon doc-link-page"' . $idAttr . '>'
                         . '<span class="card__title">' . self::CHILD_PAGE_UNREADABLE . '</span></div>';
                }
                $licon = isset($lent['icon']) && is_string($lent['icon']) && $lent['icon'] !== '' ? $lent['icon'] . ' ' : '';
                return '<a class="card card--link doc-link-page"'
                     . ' href="' . self::esc(Http::communityUrl('/p/' . $linkId)) . '"' . $idAttr . '>'
                     . '<span class="card__title">' . self::esc($licon . (string) ($lent['title'] ?? '')) . '</span></a>';

            // 그림·파일 — 파일 지도(`$ctx['file_titles']`, 보는 사람이 t-box 에서 볼 수 있는 것만)에 있을 때만 그린다.
            // 그림 주소는 t-box 창구 — 가져가는 순간에도 t-box 가 자기 권한으로 다시 판단한다.
            case 'image':
            case 'file':
                $ref   = isset($block['file_ref']) && is_string($block['file_ref']) && strncmp($block['file_ref'], 'tbox:', 5) === 0
                    && preg_match(self::UUID_RE, substr($block['file_ref'], 5)) === 1
                    ? substr($block['file_ref'], 5) : '';
                $fmap  = is_array($ctx['file_titles'] ?? null) ? $ctx['file_titles'] : [];
                $fent  = is_array($fmap[$ref] ?? null) ? $fmap[$ref] : null;
                $cap   = isset($block['caption']) && is_array($block['caption']) ? self::rich($block['caption'], $ctx) : '';
                if ($fent === null) {
                    return '<div class="doc-file"' . $idAttr . '><div class="card card--soon">'
                         . '<span class="card__title">' . self::FILE_UNREADABLE . '</span></div></div>';
                }
                $fname = (string) ($fent['name'] ?? '');
                if ($type === 'image' && ($fent['image'] ?? false) === true) {
                    $width = isset($block['width']) && in_array($block['width'], ['full', 'wide', 'normal', 'small'], true)
                        ? $block['width'] : 'normal';
                    return '<figure class="doc-image doc-image--' . $width . '"' . $idAttr . '>'
                         . '<img class="doc-image__img" src="' . self::esc(Http::url('/api/files/' . $ref . '/preview/raw'))
                         . '" alt="' . self::esc($fname) . '" loading="lazy">'
                         . ($cap === '' ? '' : '<figcaption class="doc-image__caption">' . $cap . '</figcaption>')
                         . '</figure>';
                }
                return '<div class="doc-file"' . $idAttr . '>'
                     . '<a class="card card--link" href="' . self::esc(Http::url('/api/files/' . $ref . '/download')) . '">'
                     . '<span class="card__title">' . self::esc($fname) . '</span>'
                     . '<span class="card__meta">' . self::esc(self::sizeText((int) ($fent['size'] ?? 0))) . '</span></a>'
                     . ($cap === '' ? '' : '<p class="doc-file__caption">' . $cap . '</p>')
                     . '</div>';

            /* 7-C 임베드 (K-4) — `$ctx['embeds']` = {hosts: 지금 허용 목록, frames: 사이트가 틀을 여는가}.
               목록 안 + 틀 열림 → `<iframe sandbox="COMMUNITY_EMBED_SANDBOX">`(사용자 결정 2026-09-24 — 스크립트·같은 출처·전체 화면·팝업,
               이 페이지를 딴 곳으로 보내기·폼·다운로드는 없음) + `referrerpolicy="strict-origin"`(영상 사이트가 출처만 받게 — 경로는 안 보낸다)
               · 목록 안 → 「허용 대기」 카드 + 새 탭 링크 · 목록 밖(빠진 호스트 포함) → 링크 없는 카드.
               주소는 검증기가 본 https 모양이고 여기서도 호스트를 다시 뽑는다. */
            case 'embed':
                $eurl   = isset($block['url']) && is_string($block['url']) ? $block['url'] : '';
                $ehost  = Doc::embedHost($eurl);
                $emb    = is_array($ctx['embeds'] ?? null) ? $ctx['embeds'] : [];
                $ehosts = is_array($emb['hosts'] ?? null) ? $emb['hosts'] : [];
                $listed = $ehost !== null && in_array($ehost, $ehosts, true);
                $ecap   = isset($block['caption']) && is_array($block['caption']) ? self::rich($block['caption'], $ctx) : '';
                if ($listed && ($emb['frames'] ?? false) === true) {
                    return '<figure class="doc-embed"' . $idAttr . '>'
                         . '<iframe class="doc-embed__frame" src="' . self::esc($eurl) . '" sandbox="' . self::esc(COMMUNITY_EMBED_SANDBOX) . '"'
                         . ' allow="fullscreen; picture-in-picture; encrypted-media" referrerpolicy="strict-origin" loading="lazy"'
                         . ' title="' . self::esc('임베드 — ' . $ehost) . '"></iframe>'
                         . ($ecap === '' ? '' : '<figcaption class="doc-embed__caption">' . $ecap . '</figcaption>')
                         . '</figure>';
                }
                if ($listed) {
                    return '<div class="doc-embed"' . $idAttr . '>'
                         . '<a class="card card--link" href="' . self::esc($eurl) . '" target="_blank" rel="noopener noreferrer">'
                         . '<span class="card__title">' . self::esc('임베드 — ' . $ehost) . '</span>'
                         . '<span class="card__meta">' . self::EMBED_WAITING . '</span></a>'
                         . ($ecap === '' ? '' : '<p class="doc-embed__caption">' . $ecap . '</p>')
                         . '</div>';
                }
                return '<div class="doc-embed"' . $idAttr . '><div class="card card--soon">'
                     . '<span class="card__title">' . self::EMBED_BLOCKED . '</span>'
                     . ($ehost === null ? '' : '<span class="card__meta">' . self::esc($ehost) . '</span>')
                     . '</div></div>';

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
    /**
     * 파일 크기 글자 — 「1.8 MB」 (DESIGN 5장 — 단위 앞 한 칸). **정수 계산만** 써서 클라이언트 렌더러와 같은 값을 낸다(C:T-19).
     */
    private static function sizeText(int $bytes): string
    {
        if ($bytes < 1024) {
            return (string) $bytes . ' B';
        }
        if ($bytes < 1048576) {
            $t = intdiv($bytes * 10 + 512, 1024);
            return intdiv($t, 10) . '.' . ($t % 10) . ' KB';
        }
        $t = intdiv($bytes * 10 + 524288, 1048576);
        return intdiv($t, 10) . '.' . ($t % 10) . ' MB';
    }

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
     *
     * 7-D2 관리자 팔레트 — `pal:<이름>` → `pal-<이름>` · `pal:<이름>_bg` → `pal-<이름>-bg`(모양은 `Doc::isPaletteColor` 한 곳).
     * **목록을 보지 않는다**(P-10) — 뺀 이름은 서버가 만든 CSS(`/community/palette.css`)에 규칙이 없어 색 없이 그려진다.
     * `doc-render.js` 의 `colorClass` 와 같은 규칙이다(C:T-206).
     */
    private static function colorClass(string $color): ?string
    {
        $pal = Doc::paletteName($color);
        if ($pal !== null) {
            return 'pal-' . $pal . (substr($color, -3) === '_bg' ? '-bg' : '');
        }
        if (!in_array($color, Doc::colorValues(), true)) {
            return null;
        }
        if (substr($color, -3) === '_bg') {
            return 'nt-' . substr($color, 0, -3) . '-bg';
        }
        return 'nt-' . $color;
    }

    /**
     * 7-D4 글 안 멘션 한 조각 — [글자(「@」 + 라벨), 감쌀 여는 태그, 닫는 태그]. 지시서 M-6.
     *   · 사람·페이지는 `$ctx['mention_labels']`(창구가 준 **볼 수 있는 것만**의 지도)에 있을 때만 이름 — 없으면 「@볼 수 없음」(글자만 · 링크 0)
     *   · 페이지는 `<a class="doc-mention" href="<커뮤니티>/p/<uuid>">`(사이트 안 — `target` 없음) · 날짜는 판정 없이 id 그대로
     *   · id 모양은 그리기 직전 한 번 더 본다(`Doc::mentionShapeOk` — 검증기와 같은 함수). 모양 밖은 「@볼 수 없음」
     * `doc-render.js` 의 `mentionParts` 와 같은 규칙이다(C:T-225).
     *
     * @param  mixed               $value
     * @param  array<string,mixed> $ctx
     * @return array{0:string,1:string,2:string}
     */
    private static function mentionParts($value, array $ctx): array
    {
        $unknown = ['@' . self::MENTION_UNREADABLE, '<span class="doc-mention doc-mention--unknown">', '</span>'];
        if (!Doc::mentionShapeOk($value)) {
            return $unknown;
        }
        if ($value['kind'] === 'date') {
            return ['@' . (string) $value['id'], '<span class="doc-mention">', '</span>'];
        }
        $labels = is_array($ctx['mention_labels'] ?? null) ? $ctx['mention_labels'] : [];
        $key    = $value['kind'] === 'user' ? 'user:' . (string) $value['id'] : 'page:' . (string) $value['id'];
        $entry  = is_array($labels[$key] ?? null) ? $labels[$key] : null;
        if ($entry === null || !isset($entry['label']) || !is_string($entry['label'])) {
            return $unknown;
        }
        if ($value['kind'] === 'page') {
            return ['@' . $entry['label'],
                    '<a class="doc-mention" href="' . self::esc(Http::communityUrl('/p/' . (string) $value['id'])) . '">', '</a>'];
        }
        return ['@' . $entry['label'], '<span class="doc-mention">', '</span>'];
    }

    /**
     * 리치 텍스트 → HTML. 세그먼트마다 마크를 **고정 순서**로 감싼다.
     *
     * 7-D4 — `mention` 마크가 있는 세그먼트는 원문 글자(「@」)를 **라벨로 바꿔** 그리고(`mentionParts`), 멘션을 가장 바깥에 둔다.
     * 멘션과 링크는 함께 오지 않는다(검증기) — 그래도 둘 다 있으면 링크는 그리지 않는다(링크 속 링크 0).
     *
     * @param list<mixed>         $text
     * @param array<string,mixed> $ctx  `mention_labels` 지도(없으면 모든 사람·페이지 멘션이 「@볼 수 없음」)
     */
    private static function rich(array $text, array $ctx = []): string
    {
        $out = '';
        foreach ($text as $seg) {
            if (!is_array($seg) || !isset($seg['t']) || !is_string($seg['t'])) {
                continue;
            }
            $marks   = isset($seg['m']) && is_array($seg['m']) ? $seg['m'] : [];
            $mention = array_key_exists('mention', $marks) ? self::mentionParts($marks['mention'], $ctx) : null;
            $inner   = self::esc($mention === null ? $seg['t'] : $mention[0]);

            foreach (self::MARK_ORDER as $name) {
                if (!array_key_exists($name, $marks)) {
                    continue;
                }
                $value = $marks[$name];

                if ($name === 'mention') {
                    $inner = $mention[1] . $inner . $mention[2];
                    continue;
                }

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
                    if ($mention !== null || !is_array($value) || !isset($value['href']) || !is_string($value['href'])) {
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
