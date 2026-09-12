<?php
declare(strict_types=1);

/**
 * scripts/wiki_check.php — 위키 글 전부의 구조를 검사한다 (14단계 F-21 · ARCHITECTURE 5.10)
 *
 * 왜 있나
 *   위키 글은 `wiki/*.json` 의 구조화된 데이터이고, 모르는 블록 종류·빠진 필드·범위 밖 값은
 *   **그 글을 500 으로 끝낸다**(조용히 건너뛰면 반쪽짜리 글이 나오고도 아무도 모른다,
 *   ARCH 5.10). 그 500 을 사용자가 화면에서 처음 만나지 않도록, 커밋 전에 이 명령으로
 *   전 글을 한 번에 검사한다 (SECURITY R-56 ⑤).
 *
 * 무엇을 보나
 *   ① 글마다 `Wiki::validate()` — 필드·블록 종류·열거 값·절 id 형태와 중복
 *   ② 파일 이름과 글 안의 `slug` 가 같은가
 *   ③ 차례(`index.json`)의 구조, 차례에 적힌 slug 의 파일이 실제로 있는가
 *   ④ 파일은 있는데 차례에 없는 글 (← **경고**. 링크·이전/다음에서 빠질 뿐 화면은 뜬다)
 *   ⑤ `link.slug` 가 차례에 있는가 (없으면 링크가 되지 않고 글자만 남는다 — 경고)
 *   ⑥ `refs.doc` 이 여섯 값 안인가 (①에 포함되지만 목록에 따로 세어 보여 준다)
 *   ⑦ 표의 줄 칸 수가 머리글 칸 수와 다른가 (← 경고. HTML 은 그려지지만 표가 어긋나 보인다)
 *
 * 사용법
 *   php scripts/wiki_check.php
 *
 * 종료 코드
 *   0 문제 없음(경고는 있을 수 있다) · 1 문제 있음 · 2 실행 오류
 *
 * ★ 이 스크립트는 DB 에 붙지 않고 부트스트랩도 쓰지 않는다 — `src/Wiki.php` 한 파일만
 *   읽는다. 검사에 세션·권한·연결이 필요 없어야 어디서든 돌릴 수 있고, 렌더러가
 *   정말로 혼자 서는지도 함께 확인된다.
 * ★ 웹으로는 실행되지 않는다: `public/` 밖에 있고(URL 에 없음), 아래에서 SAPI 도 본다.
 */

if (PHP_SAPI !== 'cli') {
    // 웹에서 도달할 경로가 없지만, 만들어지더라도 실행되지 않게 한다 (심층 방어).
    http_response_code(404);
    exit(1);
}

require_once dirname(__DIR__) . '/src/Wiki.php';

/** 문제(고쳐야 함)와 경고(알아 두면 됨)를 따로 센다 */
$problems = [];
$warnings = [];

/** @param list<string> $bucket */
function wiki_check_add(array &$bucket, string $file, string $where, string $what): void
{
    $bucket[] = $file . ' : ' . $where . ' — ' . $what;
}

/**
 * 블록을 하나씩 훑으며 콜백을 부른다 (link.slug·표 칸 수 검사용).
 * 구조가 이미 깨진 글에서도 멈추지 않게 값의 모양을 매번 확인한다.
 */
function wiki_check_walk(array $page, callable $fn): void
{
    $sections = is_array($page['sections'] ?? null) ? $page['sections'] : [];
    foreach ($sections as $i => $section) {
        if (!is_array($section)) {
            continue;
        }
        $blocks = is_array($section['blocks'] ?? null) ? $section['blocks'] : [];
        foreach ($blocks as $j => $block) {
            if (is_array($block)) {
                $fn($block, 'sections[' . $i . '].blocks[' . $j . ']');
            }
        }
    }
}

try {
    $dir = Wiki::dir();
} catch (Throwable $e) {
    fwrite(STDERR, '[오류] ' . $e->getMessage() . "\n");
    exit(2);
}

echo "== 위키 구조 검사 : " . $dir . " ==\n";

/* ─── 차례 ─────────────────────────────────────────────────── */
$index        = null;
$indexEntries = [];
$indexSlugs   = [];

if (!is_file($dir . '/index.json')) {
    $warnings[] = 'index.json : (파일) — 차례가 없다. 차례 화면은 "아직 글이 없습니다" 를 보여 준다';
} else {
    try {
        $index        = Wiki::loadIndex() ?? [];
        $indexEntries = Wiki::entries($index);
        foreach ($indexEntries as $entry) {
            $indexSlugs[] = (string) $entry['slug'];
            if ($entry['exists'] !== true) {
                wiki_check_add($problems, 'index.json', (string) $entry['slug'], '차례에 있는데 글 파일이 없다');
            }
        }
    } catch (Throwable $e) {
        wiki_check_add($problems, 'index.json', '(파일)', $e->getMessage());
    }
}

/* ─── 글 ───────────────────────────────────────────────────── */
$slugs  = Wiki::allSlugs();
$okCount = 0;

foreach ($slugs as $slug) {
    $file = $slug . '.json';

    if ($indexSlugs !== [] && !in_array($slug, $indexSlugs, true)) {
        wiki_check_add($warnings, $file, '(글)', '차례(index.json)에 없다 — 링크·이전/다음에서 빠진다');
    }

    try {
        $page = Wiki::loadPage($slug);
    } catch (Throwable $e) {
        // loadPage 의 예외 메시지는 "슬러그.json 구조 오류: 위치 — 문제 | …" 형태다.
        // 앞머리를 떼고 문제 하나를 한 줄씩 "파일 : 위치 — 문제" 로 다시 적는다.
        $msg    = $e->getMessage();
        $prefix = $file . ' 구조 오류: ';
        if (strncmp($msg, $prefix, strlen($prefix)) === 0) {
            $msg = substr($msg, strlen($prefix));
        }
        foreach (explode(' | ', $msg) as $line) {
            $problems[] = $file . ' : ' . $line;
        }
        continue;
    }
    if ($page === null) {
        wiki_check_add($problems, $file, '(글)', '파일을 열 수 없다');
        continue;
    }
    $okCount++;

    wiki_check_walk($page, static function (array $block, string $where) use ($file, $index, &$warnings): void {
        $type = $block['type'] ?? '';

        // link.slug 가 차례에 없으면 링크가 되지 않고 글자만 남는다 (ARCH 5.10)
        if ($type === 'link' && is_string($block['slug'] ?? null)) {
            $target = (string) $block['slug'];
            if ($index === null || !Wiki::hasEntry($index, $target)) {
                wiki_check_add($warnings, $file, $where, '링크 대상 ' . $target . ' 이 차례에 없다 — 글자로만 나온다');
            } elseif (!Wiki::pageExists($target)) {
                wiki_check_add($warnings, $file, $where, '링크 대상 ' . $target . ' 의 글 파일이 없다');
            }
        }

        // 표의 줄 칸 수가 머리글과 다르면 표가 어긋나 보인다 (그려지기는 한다)
        if ($type === 'table' && is_array($block['header'] ?? null) && is_array($block['rows'] ?? null)) {
            $cols = count($block['header']);
            foreach ($block['rows'] as $r => $row) {
                if (is_array($row) && count($row) !== $cols) {
                    wiki_check_add(
                        $warnings,
                        $file,
                        $where . '.rows[' . $r . ']',
                        '칸 수가 머리글과 다르다 (' . count($row) . ' ≠ ' . $cols . ')'
                    );
                }
            }
        }
    });
}

/* ─── 결과 ─────────────────────────────────────────────────── */
echo '글 파일 ' . count($slugs) . '개, 차례 항목 ' . count($indexEntries) . "개\n";

if ($warnings !== []) {
    echo "\n-- 경고 " . count($warnings) . "건 (화면은 뜬다) --\n";
    foreach ($warnings as $w) {
        echo '  ! ' . $w . "\n";
    }
}

if ($problems !== []) {
    echo "\n-- 문제 " . count($problems) . "건 (그 글은 500 이 된다) --\n";
    foreach ($problems as $p) {
        echo '  x ' . $p . "\n";
    }
    echo "\n결과: 실패 — 위 문제를 고치기 전엔 커밋 금지\n";
    exit(1);
}

echo "\n결과: 통과 (구조 문제 0건, 글 " . $okCount . "편)\n";
exit(0);
