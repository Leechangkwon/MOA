<?php
declare(strict_types=1);

/**
 * scripts/community_asset_version.php — 커뮤니티 JS 부품의 import 에 **버전 표시**를 찍고 확인한다
 *                                       (2026-09-24 핫픽스 — 캐시된 옛 부품 때문에 커뮤니티 단추가 전부 먹통)
 *
 * 왜 있나
 *   `/tbox/assets/` 는 브라우저가 7일 동안 캐시한다(nginx). 셸은 진입 파일 `editor.js` 만
 *   `?v=<파일 시각>` 으로 부르고, editor.js 가 부르는 부품(`./doc-render.js` 등)은 주소에 버전이 없었다.
 *   7-C 부터 editor.js 가 doc-render.js 의 새 export(`embedHost`)를 가져오는데, 옛 doc-render.js 를
 *   캐시한 브라우저는 **모듈 연결 단계에서 실패**해 커뮤니티 JS 가 한 줄도 돌지 않았다
 *   (서버에는 요청이 0 이라 로그에도 흔적이 없다 — 사용자가 폰에서 직접 겪음).
 *
 *   CSP 가 `script-src 'self'` 라 인라인 import map 을 쓸 수 없다(`vendor_prosemirror.sh` 머리글).
 *   그래서 **import 문 자체에** `?v=<버전>` 을 적는다. 이 스크립트가 그 버전을 계산해 찍고(쓰기),
 *   커밋·발행 전에 찍힌 값이 지금 내용과 맞는지 본다(`--check`).
 *
 * 버전 두 개 — 왜 하나가 아닌가
 *   ES 모듈은 **주소가 곧 정체**다. 같은 파일을 `a.js?v=1` 과 `a.js` 로 부르면 두 벌이 따로 돌아
 *   ProseMirror 가 깨진다(Selection·Plugin 의 instanceof). 그래서 한 파일을 부르는 import 는
 *   **어디서 부르든 같은 문자열**이어야 한다 — 이 스크립트가 파일마다가 아니라 묶음마다 한 값을 쓴다.
 *   · 부품 버전  = sha256(ProseMirror 버전 + 우리 부품 전부의 내용)의 앞 12자리
 *                  — 우리 부품이 하나라도 바뀌면 **우리 부품 전부**의 주소가 바뀐다(옛 부품과 섞이지 않는다).
 *   · ProseMirror 버전 = sha256(vendor/pm 전부의 내용)의 앞 12자리
 *                  — vendor/pm 끼리 부르는 import 와 우리 부품이 vendor/pm 을 부르는 import 에 쓴다.
 *                    고정 버전이라 평소에는 바뀌지 않는다(발행마다 400KB 를 다시 받게 하지 않는다).
 *                    `vendor_prosemirror.sh` 로 새 판을 들이면 값이 바뀌고, 부품 버전도 따라 바뀐다.
 *   내용을 셀 때는 import 의 `?v=…` 를 **지우고** 센다 — 찍은 값이 다시 해시에 들어가 돌지 않게.
 *
 * 무엇을 보나 (`--check`)
 *   ① 커뮤니티 폴더의 모든 `.js` 에서 상대 경로 import(`from './x.js'`·`import './x.js'`)를 찾는다
 *   ② 가리키는 파일이 실제로 있는가 (없으면 문제 — 오타가 브라우저에서 처음 드러나지 않게)
 *   ③ 버전이 찍혀 있고, 지금 내용으로 계산한 값과 같은가 (다르면 문제 — 이 스크립트를 쓰기로 다시 돌린다)
 *
 * 사용법
 *   php scripts/community_asset_version.php            # 계산해서 찍는다(바뀐 파일 이름을 찍는다)
 *   php scripts/community_asset_version.php --check    # 찍지 않고 확인만 한다
 *   php scripts/community_asset_version.php --print    # 두 버전 값만 찍는다(시험용)
 *   (앞 두 모양 모두 `--root <앱 폴더>` 로 다른 트리를 볼 수 있다 — 시험이 임시 사본에 쓴다)
 *
 * 종료 코드
 *   0 문제 없음 · 1 문제 있음(확인 실패) · 2 실행 오류
 *
 * ★ DB·부트스트랩을 쓰지 않는다. 웹으로는 실행되지 않는다(`public/` 밖 · SAPI 확인).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$mode = 'write';
$root = dirname(__DIR__);
$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); $i++) {
    $a = $args[$i];
    if ($a === '--check') {
        $mode = 'check';
    } elseif ($a === '--print') {
        $mode = 'print';
    } elseif ($a === '--root' && isset($args[$i + 1])) {
        $root = rtrim($args[++$i], '/');
    } else {
        fwrite(STDERR, "쓰는 법: php scripts/community_asset_version.php [--check|--print] [--root <앱 폴더>]\n");
        exit(2);
    }
}

$dir = $root . '/public/assets/community';
if (!is_dir($dir)) {
    fwrite(STDERR, "커뮤니티 부품 폴더가 없다: $dir\n");
    exit(2);
}

// 상대 경로 import 한 개. 줄 머리의 `import`/`export` 로 시작하는 문장만 본다 — 주석 속 글자는 건드리지 않는다.
//   1 = 앞부분(`import { a } from '` · `import '` — 여러 줄 import 도 `;` 전까지 한 문장으로 본다)
//   2 = 따옴표  3 = 경로(`./x.js`·`../x.js`)  4 = 지금 붙은 질의(없으면 빈 값 — `?v=낡은값`·`?v=…&x=1` 등
//   **무엇이 붙어 있든** 지우고 새 값으로 찍는다. 손으로 쓴 이상한 값이 남아 모듈이 두 벌 되지 않게 — 독립 검토 L-1)
const IMPORT_RE = '~^(import\s*|(?:import|export)\b[^;\'"]*?\bfrom\s*)([\'"])(\.\.?/[^\'"?]+\.js)(?:\?([^\'"]*))?\2~m';
// 상대 경로 `.js`·`.json` 문자열 — 위 모양(줄 머리 import/export … from) **밖**에 남으면 문제로 센다
// (들여 쓴 import·동적 import()·new URL()·JSON 모듈 import 는 버전을 찍지 못한다 — 독립 검토 L-4)
const BARE_REL_RE = '~[\'"]\.\.?/[^\'"?]+\.js(?:on)?(?:\?[^\'"]*)?[\'"]~';

/** 폴더 안 `.js` 전부 — 앱 폴더 기준 상대 경로로, 정렬해서 (해시가 순서에 따라 흔들리지 않게) */
function list_js(string $dir): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        // Excalidraw uses its own content-hashed chunks and reproducible vendor manifest.
        if (str_contains($f->getPathname(), '/vendor/excalidraw/')) { continue; }
        if ($f->isFile() && substr($f->getFilename(), -3) === '.js') {
            $out[] = substr($f->getPathname(), strlen($dir) + 1);
        }
    }
    sort($out, SORT_STRING);
    return $out;
}

/** import 의 `?v=…` 를 지운 내용 — 해시는 이것으로 센다 */
function normalized(string $src): string
{
    return (string) preg_replace_callback(IMPORT_RE, static fn(array $m): string => $m[1] . $m[2] . $m[3] . $m[2], $src);
}

function is_vendor(string $rel): bool
{
    return strpos($rel, 'vendor/') === 0;
}

/** $from 파일이 부른 상대 경로 $spec 을 커뮤니티 폴더 기준 경로로 푼다. 폴더 밖으로 나가면 null */
function resolve_rel(string $from, string $spec): ?string
{
    $parts = explode('/', dirname($from) === '.' ? '' : dirname($from));
    $parts = array_values(array_filter($parts, static fn(string $p): bool => $p !== ''));
    foreach (explode('/', $spec) as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..') {
            if ($parts === []) {
                return null;
            }
            array_pop($parts);
            continue;
        }
        $parts[] = $seg;
    }
    return implode('/', $parts);
}

$files = list_js($dir);
$content = [];
foreach ($files as $rel) {
    $s = file_get_contents($dir . '/' . $rel);
    if ($s === false) {
        fwrite(STDERR, "읽을 수 없다: $rel\n");
        exit(2);
    }
    $content[$rel] = $s;
}

$hv = hash_init('sha256');
$ha = hash_init('sha256');
foreach ($files as $rel) {
    if (is_vendor($rel)) {
        hash_update($hv, $rel . "\0" . normalized($content[$rel]) . "\0");
    }
}
$vendorVer = substr(hash_final($hv), 0, 12);
hash_update($ha, 'pm=' . $vendorVer . "\0");
foreach ($files as $rel) {
    if (!is_vendor($rel)) {
        hash_update($ha, $rel . "\0" . normalized($content[$rel]) . "\0");
    }
}
$appVer = substr(hash_final($ha), 0, 12);

if ($mode === 'print') {
    echo "app=$appVer\npm=$vendorVer\n";
    exit(0);
}

$problems = [];
$changed = [];
$imports = 0;
foreach ($files as $rel) {
    $src = $content[$rel];
    $want = preg_replace_callback(IMPORT_RE, static function (array $m) use ($rel, $files, $vendorVer, $appVer, &$problems, &$imports): string {
        $imports++;
        $target = resolve_rel($rel, $m[3]);
        if ($target === null || !in_array($target, $files, true)) {
            $problems[] = "$rel: 가리키는 파일이 없다 — {$m[3]}";
            return $m[0];
        }
        // vendor/pm 을 부르면 ProseMirror 버전, 우리 부품을 부르면 부품 버전
        $ver = is_vendor($target) ? $vendorVer : $appVer;
        if (is_vendor($rel) && !is_vendor($target)) {
            $problems[] = "$rel: vendor 가 우리 부품을 부른다 — {$m[3]}";
        }
        return $m[1] . $m[2] . $m[3] . '?v=' . $ver . $m[2];
    }, $src);
    if (!is_string($want)) {
        fwrite(STDERR, "정규식 실패: $rel\n");
        exit(2);
    }
    // 찍은 import 문을 빼고 남은 글자에서 상대 경로를 찾는다
    $rest = (string) preg_replace(IMPORT_RE, '', $want);
    if (preg_match_all(BARE_REL_RE, $rest, $bare) > 0) {
        foreach ($bare[0] as $b) {
            $problems[] = "$rel: import 문으로 보이지 않는 상대 경로 — $b (줄 머리의 import/export … from 만 버전을 찍는다)";
        }
    }
    if ($want !== $src) {
        $changed[] = $rel;
        if ($mode === 'write') {
            if (file_put_contents($dir . '/' . $rel, $want) === false) {
                fwrite(STDERR, "쓸 수 없다: $rel\n");
                exit(2);
            }
        }
    }
}

/*
 * 커뮤니티 폴더 **밖**의 진입 파일이 커뮤니티 부품을 부르는 import (사이트 Q:F-11 · A 독립 검토 3번, 지시서 B-7).
 *   `public/assets/messenger.js` 가 `./community/drafts.js?v=…` 를 부른다. 커뮤니티 화면에서는 editor.js 도 같은
 *   drafts.js 를 부르므로 **같은 주소**(같은 부품 버전)여야 모듈이 한 벌이고, 옛 부품이 캐시에서 섞이지 않는다.
 *   이 파일들은 부품 버전 해시에 넣지 않는다(진입 파일이지 부품이 아니다 — editor.js 와 달리 폴더 밖이다).
 *   찍는 규칙은 폴더 안과 같다: `./community/` 아래만 허용, 가리키는 파일이 있어야 하고, vendor 면 ProseMirror 버전.
 */
const OUTSIDE_ENTRIES = ['messenger.js'];
$outside = 0;
foreach (OUTSIDE_ENTRIES as $entry) {
    $path = $root . '/public/assets/' . $entry;
    if (!is_file($path)) {
        $problems[] = "$entry: 커뮤니티 부품을 부르는 진입 파일이 없다";
        continue;
    }
    $src = file_get_contents($path);
    if ($src === false) {
        fwrite(STDERR, "읽을 수 없다: $entry\n");
        exit(2);
    }
    $outside++;
    $want = preg_replace_callback(IMPORT_RE, static function (array $m) use ($entry, $files, $vendorVer, $appVer, &$problems, &$imports): string {
        $imports++;
        if (strpos($m[3], './community/') !== 0) {
            $problems[] = "$entry: 커뮤니티 부품이 아닌 상대 import — {$m[3]}";
            return $m[0];
        }
        $target = resolve_rel('entry.js', './' . substr($m[3], strlen('./community/')));
        if ($target === null || !in_array($target, $files, true)) {
            $problems[] = "$entry: 가리키는 파일이 없다 — {$m[3]}";
            return $m[0];
        }
        return $m[1] . $m[2] . $m[3] . '?v=' . (is_vendor($target) ? $vendorVer : $appVer) . $m[2];
    }, $src);
    if (!is_string($want)) {
        fwrite(STDERR, "정규식 실패: $entry\n");
        exit(2);
    }
    $rest = (string) preg_replace(IMPORT_RE, '', $want);
    if (preg_match_all(BARE_REL_RE, $rest, $bare) > 0) {
        foreach ($bare[0] as $b) {
            $problems[] = "$entry: import 문으로 보이지 않는 상대 경로 — $b (줄 머리의 import/export … from 만 버전을 찍는다)";
        }
    }
    if ($want !== $src) {
        $changed[] = '../' . $entry;
        if ($mode === 'write' && file_put_contents($path, $want) === false) {
            fwrite(STDERR, "쓸 수 없다: $entry\n");
            exit(2);
        }
    }
}

echo "부품 버전 $appVer · ProseMirror 버전 $vendorVer · 파일 " . count($files) . "개 · 상대 import {$imports}개 · 폴더 밖 진입 {$outside}개\n";
foreach ($problems as $p) {
    echo "  ✖ $p\n";
}
if ($mode === 'check') {
    foreach ($changed as $rel) {
        echo "  ✖ 버전 표시가 지금 내용과 맞지 않다: $rel\n";
    }
    if ($changed !== []) {
        echo "  → php scripts/community_asset_version.php 로 다시 찍고 함께 커밋한다\n";
    }
    exit(($problems === [] && $changed === []) ? 0 : 1);
}
foreach ($changed as $rel) {
    echo "  ✎ $rel\n";
}
echo $changed === [] ? "  (바뀐 파일 없음)\n" : '';
exit($problems === [] ? 0 : 1);
