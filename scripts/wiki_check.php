<?php
declare(strict_types=1);

/**
 * scripts/wiki_check.php — 위키 글 전부의 구조를 검사하고, **빠진 단계 글**을 찾는다
 *                          (14단계 F-21 · ARCHITECTURE 5.10 · 2026-09-23 책 셋)
 *
 * 왜 있나
 *   위키 글은 `wiki/<책>/*.json` 의 구조화된 데이터이고, 모르는 블록 종류·빠진 필드·범위 밖 값은
 *   **그 글을 500 으로 끝낸다**(조용히 건너뛰면 반쪽짜리 글이 나오고도 아무도 모른다,
 *   ARCH 5.10). 그 500 을 사용자가 화면에서 처음 만나지 않도록, 커밋 전에 이 명령으로
 *   전 글을 한 번에 검사한다 (SECURITY R-56 ⑤).
 *
 *   2026-09-23 — 사용자 「이건 왜 위키에 기록이 안 되니」. 이 스크립트는 **있는 글의 형식**만
 *   봐서, 끝난 단계인데 글이 없는 것(t-box 26·28, 커뮤니티 3~4-D 전부)을 한 번도 잡지 못했다.
 *   그래서 ⑧ 을 더했다: 세 STAGES 파일의 0장 표에서 「완료」 단계를 읽어 위키 글과 대조한다.
 *
 * 무엇을 보나 (책 셋 — `Wiki::BOOKS` — 을 하나씩)
 *   ① 글마다 `Wiki::validate()` — 필드·블록 종류·열거 값·절 id 형태와 중복·책별 단계 번호 모양
 *   ② 파일 이름과 글 안의 `slug` 가 같은가
 *   ③ 차례(`index.json`)의 구조, 차례에 적힌 slug 의 파일이 실제로 있는가
 *   ④ 파일은 있는데 차례에 없는 글 (← **경고**. 링크·이전/다음에서 빠질 뿐 화면은 뜬다)
 *   ⑤ `link.slug` 가 (가는 책의) 차례에 있는가 (없으면 링크가 되지 않고 글자만 남는다 — 경고)
 *   ⑥ `refs.doc` 이 허용 값 안인가 (①에 포함)
 *   ⑦ 표의 줄 칸 수가 머리글 칸 수와 다른가 (← 경고. HTML 은 그려지지만 표가 어긋나 보인다)
 *   ⑧ **빠진 글** — STAGES 0장 표에서 상태 칸에 ✅ 가 있는 단계마다 그 책에 같은 번호의 단계 글이
 *      있어야 한다. 없으면 **문제**(실패). 예외는 아래 `WIKI_MISSING_EXCEPTIONS` 에 이유와 함께.
 *   ⑨ 같은 책에 같은 단계 번호의 글이 둘 (← 문제. 이웃 글·빠진 글 검사가 한쪽을 못 본다)
 *   ⑩ `wiki/` 바로 아래에 남은 `*.json` (← 문제. 책이 나뉜 뒤로는 어느 주소로도 닿지 않는다)
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

/**
 * ⑧ 이 검사가 읽는 STAGES 파일 — 책 하나에 하나. 경로는 이 저장소(`tbox-v2/`) 기준이다.
 * 사이트·커뮤니티의 STAGES 는 저장소 **바깥**(부모 폴더 `/var/www/qaredi/`)에 있다(사이트 STAGES
 * 2장 2026-09-22 폴더 구조 결정). 파일이 없으면 조용히 건너뛰지 않고 **문제**로 올린다 —
 * 검사를 못 한 것을 「빠진 글 0」으로 보이게 하면 이 검사를 만든 이유가 사라진다.
 */
const WIKI_STAGES_FILES = [
    'tbox'      => __DIR__ . '/../STAGES.md',
    'community' => __DIR__ . '/../../community/STAGES.md',
    'qaredi'    => __DIR__ . '/../../STAGES.md',
];

/**
 * ⑧ 의 예외 — 「완료」로 적혀 있어도 그 책에 글을 두지 않는 단계. **이유가 없는 줄은 두지 않는다.**
 *
 *   t-box 19~25 : 구매·재고 모듈. 27단계(2026-09-21, F-64)에서 모듈째 떼어내 지웠고, 그 단계 글
 *                 일곱 편도 함께 지웠다(`docs/f64-purchase-removal-directive.md` — 「wiki/stage-19~25.json
 *                 삭제」). 무엇을 지웠는지는 27단계 글이 적는다. 지금 0장 표의 상태는 「🗑 제거」라
 *                 원래도 걸리지 않지만, 표가 다시 ✅ 로 고쳐져도 글을 되살리라고 하지 않게 여기 적는다.
 *   커뮤니티 0~2 : Qaredi 로 합치기 전 **별도 앱**(`/var/www/community`, 게시판 v0.2.0)의 단계다. 사용자
 *                 결정(2026-09-22) 「기존 커뮤니티 내용은 쓸모 없다 — 기획을 끝낸 뒤 처음부터」로 그 코드를
 *                 버렸고(커뮤니티 STAGES 3단계 머리 「1·2단계 게시판 코드는 버렸다」), 지금 앱에는 그 단계의
 *                 결과물이 없다. 버린 경위는 커뮤니티 안내 글(guide-what)이 적는다.
 *
 * @var array<string,array<string,string>>  책 => [단계 번호 문자열 => 이유 한 줄]
 */
const WIKI_MISSING_EXCEPTIONS = [
    'tbox' => [
        '19' => '구매·재고 모듈 — 27단계에서 제거(글도 함께 지움)',
        '20' => '구매·재고 모듈 — 27단계에서 제거(글도 함께 지움)',
        '21' => '구매·재고 모듈 — 27단계에서 제거(글도 함께 지움)',
        '22' => '구매·재고 모듈 — 27단계에서 제거(글도 함께 지움)',
        '23' => '구매·재고 모듈 — 27단계에서 제거(글도 함께 지움)',
        '24' => '구매·재고 모듈 — 27단계에서 제거(글도 함께 지움)',
        '25' => '구매·재고 모듈 — 27단계에서 제거(글도 함께 지움)',
    ],
    'community' => [
        '0' => '통합 전 별도 앱의 설계 단계 — 그 저장소·코드는 버림(2026-09-22)',
        '1' => '통합 전 별도 앱의 골격 — 코드 버림(2026-09-22)',
        '2' => '통합 전 별도 앱의 게시판 — 코드 버림(2026-09-22)',
    ],
    'qaredi' => [],
];

/**
 * ⑧ 사이트 단계 가운데 **다른 책의 글이 맡는** 것. 사이트 STAGES 0장 표는 커뮤니티 단계도 한 줄씩
 * (Q-2 = 커뮤니티 3단계, Q-3 = 커뮤니티 4단계) 적는다 — 같은 일을 두 책에 두 번 쓰지 않고,
 * 대신 그 책에 아래 번호의 글이 **모두** 있는지 본다(하나라도 없으면 문제).
 *
 * @var array<string,array{0:string,1:list<string>}>  사이트 단계 => [책, 그 책의 단계 번호들]
 */
const WIKI_SITE_COVERED_BY = [
    'Q-2' => ['community', ['3', '3-B']],
    'Q-3' => ['community', ['4-A', '4-B', '4-C', '4-D']],
];

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

/**
 * STAGES 파일 「## 0.」 장의 **첫 표**를 읽어 [단계 칸, 상태 칸, 줄 번호] 를 돌려준다.
 *
 * 표의 모양은 세 파일이 조금씩 다르다(t-box·커뮤니티는 7칸, 사이트는 3칸). 그래서 칸의 **자리**가
 * 아니라 머리줄의 **이름**(「단계」·「상태」)으로 찾는다. 칸 안의 `\|`(이스케이프한 세로줄)는
 * 칸 구분으로 보지 않는다. 머리줄에 두 이름이 없거나 0장 표가 없으면 예외 — 조용히 빈 목록을
 * 돌려주면 「빠진 글 0」으로 보인다.
 *
 * @return list<array{0:string,1:string,2:int}>
 * @throws RuntimeException
 */
function wiki_check_stage_table(string $path): array
{
    $raw = @file($path, FILE_IGNORE_NEW_LINES);
    if ($raw === false) {
        throw new RuntimeException('읽을 수 없다');
    }

    $inZero = false;
    $header = null;
    $rows   = [];
    foreach ($raw as $n => $line) {
        if (preg_match('/^##\s+0\./u', $line) === 1) {
            $inZero = true;
            continue;
        }
        if (!$inZero) {
            continue;
        }
        if (preg_match('/^##\s/u', $line) === 1) {
            break;                              // 다음 장 — 0장 끝
        }
        $t = trim($line);
        if ($t === '' || $t[0] !== '|') {
            if ($header !== null) {
                break;                          // 표가 끝났다 (0장의 **첫** 표만 본다)
            }
            continue;
        }
        $cells = array_map(
            static fn(string $c): string => trim(str_replace("\x00", '|', $c)),
            explode('|', str_replace('\\|', "\x00", trim($t, '|')))
        );
        if ($header === null) {
            $header = $cells;
            continue;
        }
        if (preg_match('/^:?-{3,}:?$/', $cells[0] ?? '') === 1) {
            continue;                           // 구분줄 |---|---|
        }
        $rows[] = [$cells, $n + 1];
    }
    if ($header === null) {
        throw new RuntimeException('「## 0.」 장에서 표를 찾지 못했다');
    }
    $stageCol  = array_search('단계', $header, true);
    $statusCol = array_search('상태', $header, true);
    if ($stageCol === false || $statusCol === false) {
        throw new RuntimeException('0장 표 머리줄에 「단계」·「상태」 칸이 없다 (' . implode(' | ', $header) . ')');
    }

    $out = [];
    foreach ($rows as [$cells, $lineNo]) {
        $out[] = [(string) ($cells[$stageCol] ?? ''), (string) ($cells[$statusCol] ?? ''), $lineNo];
    }
    return $out;
}

/**
 * 표의 단계 칸 원문 → 그 책의 단계 번호. 단계가 아닌 줄(`F-47`·`3+`(t-box)·`문구 정리`·`공개 경로`·
 * `Q-2~`)은 null — 기능 번호나 묶음 줄이지 단계 글을 요구하는 줄이 아니다.
 *
 *   t-box      `**12**` → 12 (정수 1~28 만)
 *   커뮤니티    `**4-A**` → '4-A' · `3` → '3'
 *   사이트      `Q-0 기획` → 'Q-0' (앞머리 `Q-숫자` 뒤가 공백이거나 끝일 때만 — `Q-2~` 는 아니다)
 *
 * @return int|string|null
 */
function wiki_check_stage_of(string $book, string $cell)
{
    $c = trim(str_replace(['**', '`'], '', $cell));
    switch ($book) {
        case 'tbox':
            if (preg_match('/^[0-9]{1,2}$/', $c) !== 1) {
                return null;
            }
            $n = (int) $c;
            return Wiki::validStage('tbox', $n) ? $n : null;
        case 'community':
            return Wiki::validStage('community', $c) ? $c : null;
        case 'qaredi':
            if (preg_match('/^(Q-[0-9]{1,2})(\s|$)/u', $c, $m) !== 1) {
                return null;
            }
            return Wiki::validStage('qaredi', $m[1]) ? $m[1] : null;
    }
    return null;
}

try {
    $root = Wiki::rootDir();
} catch (Throwable $e) {
    fwrite(STDERR, '[오류] ' . $e->getMessage() . "\n");
    exit(2);
}

echo "== 위키 구조 검사 : " . $root . " ==\n";

/* ─── ⑩ 뿌리에 남은 글 ─────────────────────────────────────── */
foreach (glob($root . '/*.json') ?: [] as $stray) {
    wiki_check_add($problems, basename($stray), '(wiki/ 바로 아래)',
        '책 디렉터리(' . implode('·', array_keys(Wiki::BOOKS)) . ') 밖이라 어느 주소로도 닿지 않는다 — 책 안으로 옮긴다');
}

/** 책마다 [단계 번호 문자열 => slug] — ⑧·⑨ 에 쓴다 */
$stageSlugs = [];
$totalFiles = 0;
$totalOk    = 0;
$totalIndex = 0;

foreach (array_keys(Wiki::BOOKS) as $book) {
    $stageSlugs[$book] = [];
    echo "\n-- 책 " . $book . " --\n";

    try {
        $dir = Wiki::dir($book);
    } catch (Throwable $e) {
        wiki_check_add($problems, $book . '/', '(책)', $e->getMessage());
        continue;
    }

    /* ─── 차례 ─────────────────────────────────────────────── */
    $index        = null;
    $indexEntries = [];
    $indexSlugs   = [];

    if (!is_file($dir . '/index.json')) {
        $warnings[] = $book . '/index.json : (파일) — 차례가 없다. 차례 화면은 "아직 글이 없습니다" 를 보여 준다';
    } else {
        try {
            $index        = Wiki::loadIndex($book) ?? [];
            $indexEntries = Wiki::entries($index, $book);
            foreach ($indexEntries as $entry) {
                $indexSlugs[] = (string) $entry['slug'];
                if ($entry['exists'] !== true) {
                    wiki_check_add($problems, $book . '/index.json', (string) $entry['slug'], '차례에 있는데 글 파일이 없다');
                }
            }
        } catch (Throwable $e) {
            wiki_check_add($problems, $book . '/index.json', '(파일)', $e->getMessage());
        }
    }

    /* ─── 글 ───────────────────────────────────────────────── */
    $slugs   = Wiki::allSlugs($book);
    $okCount = 0;

    foreach ($slugs as $slug) {
        $file = $book . '/' . $slug . '.json';

        if ($indexSlugs !== [] && !in_array($slug, $indexSlugs, true)) {
            wiki_check_add($warnings, $file, '(글)', '차례(index.json)에 없다 — 링크·이전/다음에서 빠진다');
        }

        try {
            $page = Wiki::loadPage($book, $slug);
        } catch (Throwable $e) {
            // loadPage 의 예외 메시지는 "슬러그.json 구조 오류: 위치 — 문제 | …" 형태다.
            // 앞머리를 떼고 문제 하나를 한 줄씩 "파일 : 위치 — 문제" 로 다시 적는다.
            $msg    = $e->getMessage();
            $prefix = $slug . '.json 구조 오류: ';
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

        // ⑨ 같은 단계 번호가 둘
        if (($page['group'] ?? '') === 'stage' && Wiki::validStage($book, $page['stage'] ?? null)) {
            $key = (string) $page['stage'];
            if (isset($stageSlugs[$book][$key])) {
                wiki_check_add($problems, $file, 'stage', '같은 단계 번호(' . $key . ')의 글이 이미 있다 — ' . $stageSlugs[$book][$key]);
            } else {
                $stageSlugs[$book][$key] = $slug;
            }
        }

        wiki_check_walk($page, static function (array $block, string $where) use ($file, $book, $index, &$warnings): void {
            $type = $block['type'] ?? '';

            // link.slug 가 (가는 책의) 차례에 없으면 링크가 되지 않고 글자만 남는다 (ARCH 5.10)
            if ($type === 'link' && is_string($block['slug'] ?? null)) {
                $target     = (string) $block['slug'];
                $targetBook = is_string($block['book'] ?? null) ? (string) $block['book'] : $book;
                try {
                    $targetIndex = $targetBook === $book ? $index : Wiki::loadIndex($targetBook);
                } catch (Throwable $e) {
                    $targetIndex = null;   // 그 책의 차례 문제는 그 책 차례 검사에서 따로 문제로 올라간다
                }
                if ($targetIndex === null || !Wiki::hasEntry($targetIndex, $target)) {
                    wiki_check_add($warnings, $file, $where, '링크 대상 ' . $targetBook . '/' . $target . ' 이 차례에 없다 — 글자로만 나온다');
                } elseif (!Wiki::pageExists($targetBook, $target)) {
                    wiki_check_add($warnings, $file, $where, '링크 대상 ' . $targetBook . '/' . $target . ' 의 글 파일이 없다');
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

    echo '글 파일 ' . count($slugs) . '개, 차례 항목 ' . count($indexEntries) . "개\n";
    $totalFiles += count($slugs);
    $totalOk    += $okCount;
    $totalIndex += count($indexEntries);
}

/* ─── ⑧ 빠진 글 — STAGES 0장의 「완료」 단계 × 위키 단계 글 ───────── */
echo "\n-- 빠진 글 검사 (STAGES 0장 ✅ × 위키 단계 글) --\n";
foreach (WIKI_STAGES_FILES as $book => $path) {
    $shown = $book . ' ← ' . (realpath($path) ?: $path);
    if (!is_file($path)) {
        wiki_check_add($problems, $shown, '(STAGES)', '파일이 없다 — 빠진 글 검사를 할 수 없다');
        continue;
    }
    try {
        $table = wiki_check_stage_table($path);
    } catch (Throwable $e) {
        wiki_check_add($problems, $shown, '(STAGES 0장)', $e->getMessage());
        continue;
    }

    $done = 0;
    $seen = [];
    foreach ($table as [$cell, $status, $lineNo]) {
        $stage = wiki_check_stage_of($book, $cell);
        if ($stage === null || strpos($status, '✅') === false) {
            continue;                                   // 단계 줄이 아니거나 완료가 아니다
        }
        $key = (string) $stage;
        if (isset($seen[$key])) {
            continue;                                   // 같은 단계가 두 줄(t-box 26) — 한 번만 센다
        }
        $seen[$key] = true;
        $done++;

        if (isset(WIKI_MISSING_EXCEPTIONS[$book][$key])) {
            echo '  · ' . $book . ' ' . $key . ' — 예외: ' . WIKI_MISSING_EXCEPTIONS[$book][$key] . "\n";
            continue;
        }
        if ($book === 'qaredi' && isset(WIKI_SITE_COVERED_BY[$key])) {
            [$otherBook, $otherStages] = WIKI_SITE_COVERED_BY[$key];
            $lack = array_values(array_filter(
                $otherStages,
                static fn(string $s): bool => !isset($stageSlugs[$otherBook][$s])
            ));
            if ($lack === []) {
                echo '  · qaredi ' . $key . ' — ' . $otherBook . ' 책의 ' . implode('·', $otherStages) . " 글이 맡는다 (전부 있음)\n";
            } else {
                wiki_check_add($problems, $shown . ':' . $lineNo, $key,
                    '완료인데 이 단계를 맡는 ' . $otherBook . ' 글이 없다 — ' . implode('·', $lack));
            }
            continue;
        }
        if (!isset($stageSlugs[$book][$key])) {
            wiki_check_add($problems, $shown . ':' . $lineNo, $key, '완료(✅)인데 ' . $book . ' 책에 이 단계 글이 없다');
        }
    }
    echo '  ' . $book . ' : 0장 표 ' . count($table) . '줄 중 완료 단계 ' . $done . "개 대조\n";
}

/* ─── 결과 ─────────────────────────────────────────────────── */
echo "\n책 " . count(Wiki::BOOKS) . '권 · 글 파일 ' . $totalFiles . '개 · 차례 항목 ' . $totalIndex . "개\n";

if ($warnings !== []) {
    echo "\n-- 경고 " . count($warnings) . "건 (화면은 뜬다) --\n";
    foreach ($warnings as $w) {
        echo '  ! ' . $w . "\n";
    }
}

if ($problems !== []) {
    echo "\n-- 문제 " . count($problems) . "건 (그 글은 500 이 되거나, 끝난 단계의 글이 없다) --\n";
    foreach ($problems as $p) {
        echo '  x ' . $p . "\n";
    }
    echo "\n결과: 실패 — 위 문제를 고치기 전엔 커밋 금지\n";
    exit(1);
}

echo "\n결과: 통과 (구조 문제 0건 · 빠진 글 0건, 글 " . $totalOk . "편)\n";
exit(0);
