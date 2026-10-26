<?php
declare(strict_types=1);
/**
 * php tests/community/guide_journey_unit.php — 사이트 Q:F-15 S3 길잡이 수락 [unit] Q:T-G1 (DB 0 · 운영 0 · 앱 부트스트랩 0)
 *
 *   ① 여섯 여정 × {active, page 0, step 0} · {…, restart:true} 통과 · 각 여정의 마지막 page 통과
 *   ② 400(InvalidArgumentException): 빈 본문 · user_id 키 · confirm 키 · 키 하나 빠짐 · journey 'x' · '../a' · 'Task-add' · status 'new' ·
 *      page -1 · page = 화면 수 · step 31 · '0'(문자열) · restart 'yes' (+ 숫자 키 · 실수 page · null)
 *   ③ GuideJourney::JOURNEYS 키 = public/assets/usage-guide.js 의 journeys 키(파일을 읽어 데이터로만 셈) · 화면 수 = pages 길이
 */
require __DIR__ . '/../../src/community/GuideJourney.php';
$count = 0;
function check(bool $result, string $label): void
{
    global $count;
    if (!$result) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    $count++;
    echo "PASS: $label\n";
}
function accepts(array $body): bool
{
    try { GuideJourney::validate($body); return true; } catch (InvalidArgumentException $e) { return false; }
}
function rejects(array $body, string $label): void
{
    try { GuideJourney::validate($body); } catch (InvalidArgumentException $e) { check(true, '② 400 ' . $label); return; }
    check(false, '② 400 ' . $label);
}

// ① 여섯 여정
check(array_keys(GuideJourney::JOURNEYS) === ['task-add', 'file-share', 'minutes-write', 'notice-post', 'message-send', 'invite-colleague'],
    '① JOURNEYS keys are the six journeys in order');
foreach (GuideJourney::JOURNEYS as $journey => $pages) {
    $ok = ['journey' => $journey, 'status' => 'active', 'page' => 0, 'step' => 0];
    check(accepts($ok), "① $journey {active, page 0, step 0} accepted");
    $v = GuideJourney::validate($ok + ['restart' => true]);
    check($v['restart'] === true && $v['journey'] === $journey && $v['page'] === 0 && $v['step'] === 0, "① $journey {…, restart:true} accepted (restart true)");
    check(GuideJourney::validate($ok)['restart'] === false, "① $journey restart defaults to false");
    check(accepts(['journey' => $journey, 'status' => 'paused', 'page' => $pages - 1, 'step' => 30]), "① $journey last page " . ($pages - 1) . ' · step 30 accepted');
    foreach (GuideJourney::STATUSES as $status) {
        check(accepts(['journey' => $journey, 'status' => $status, 'page' => 0, 'step' => 0]), "① $journey status $status accepted by the shape check");
    }
}

// ② 400
$base = ['journey' => 'task-add', 'status' => 'active', 'page' => 0, 'step' => 0];
rejects([], 'empty body');
rejects($base + ['user_id' => 1], 'user_id key');
rejects($base + ['confirm' => true], 'confirm key');
foreach (array_keys($base) as $missing) {
    $b = $base; unset($b[$missing]);
    rejects($b, "missing key $missing");
}
rejects(['journey' => 'x'] + $base, "journey 'x'");
rejects(['journey' => '../a'] + $base, "journey '../a'");
rejects(['journey' => 'Task-add'] + $base, "journey 'Task-add'");
rejects(['journey' => 'task-add '] + $base, "journey 'task-add ' (trailing space)");
rejects(['journey' => 1] + $base, 'journey number');
rejects(['status' => 'new'] + $base, "status 'new'");
rejects(['status' => 'ACTIVE'] + $base, "status 'ACTIVE'");
rejects(['page' => -1] + $base, 'page -1');
foreach (GuideJourney::JOURNEYS as $journey => $pages) {
    rejects(['journey' => $journey, 'status' => 'active', 'page' => $pages, 'step' => 0], "$journey page = screen count ($pages)");
}
rejects(['step' => 31] + $base, 'step 31');
rejects(['step' => -1] + $base, 'step -1');
rejects(['page' => '0'] + $base, "page '0' (string)");
rejects(['step' => '0'] + $base, "step '0' (string)");
rejects(['page' => 0.0] + $base, 'page 0.0 (float)');
rejects(['page' => null] + $base, 'page null');
rejects(['page' => true] + $base, 'page true');
rejects($base + ['restart' => 'yes'], "restart 'yes'");
rejects($base + ['restart' => 1], 'restart 1');
rejects([0 => 'task-add', 1 => 'active', 2 => 0, 3 => 0], 'list body (numeric keys)');
rejects($base + [7 => 'x'], 'extra numeric key');

// ③ JOURNEYS 키 = usage-guide.js journeys 키 · 화면 수 = pages 길이
$js = (string) file_get_contents(__DIR__ . '/../../public/assets/usage-guide.js');
$from = strpos($js, '/* @journeys-begin */');
$to = strpos($js, '/* @journeys-end */');
check($from !== false && $to !== false && $to > $from, '③ usage-guide.js has one @journeys-begin … @journeys-end block');
$block = substr($js, $from, $to - $from);
$at = strpos($block, 'const journeys = ');
$end = strrpos($block, '};');
check($at !== false && $end !== false, '③ the block holds `const journeys = { … };`');
$literal = substr($block, $at + strlen('const journeys = '), $end - $at - strlen('const journeys = ') + 1);
/**
 * 데이터 묶음(객체 · 배열 · 작은따옴표 글자 · 숫자 · true/false/null)만 읽는 작은 해석기 — 코드가 섞이면 실패한다(묶음은 글자 · 선택자만이라는 약속).
 * @return mixed
 */
function js_value(string $src, int &$i)
{
    $ws = static function () use ($src, &$i): void { while ($i < strlen($src) && strpos(" \t\r\n", $src[$i]) !== false) { $i++; } };
    $ws();
    $c = $src[$i] ?? '';
    if ($c === '{' || $c === '[') {
        $object = $c === '{';
        $i++;
        $out = [];
        while (true) {
            $ws();
            if (($src[$i] ?? '') === ($object ? '}' : ']')) { $i++; return $out; }
            if ($object) {
                if ($src[$i] === "'") { $key = js_value($src, $i); }
                elseif (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $src, $m, 0, $i) === 1) { $key = $m[0]; $i += strlen($m[0]); }
                else { throw new RuntimeException('key at ' . $i); }
                $ws();
                if (($src[$i] ?? '') !== ':') { throw new RuntimeException('colon at ' . $i); }
                $i++;
                $out[$key] = js_value($src, $i);
            } else {
                $out[] = js_value($src, $i);
            }
            $ws();
            if (($src[$i] ?? '') === ',') { $i++; continue; }
            if (($src[$i] ?? '') !== ($object ? '}' : ']')) { throw new RuntimeException('separator at ' . $i); }
        }
    }
    if ($c === "'") {
        $i++;
        $s = '';
        while ($i < strlen($src) && $src[$i] !== "'") {
            if ($src[$i] === '\\') { $i++; }
            $s .= $src[$i];
            $i++;
        }
        $i++;
        return $s;
    }
    foreach (['true' => true, 'false' => false, 'null' => null] as $word => $value) {
        if (substr($src, $i, strlen($word)) === $word) { $i += strlen($word); return $value; }
    }
    if (preg_match('/\G-?\d+/', $src, $m, 0, $i) === 1) { $i += strlen($m[0]); return (int) $m[0]; }
    throw new RuntimeException('value at ' . $i);
}
$shape = null;
$parsed = null;
try {
    $at0 = 0;
    $parsed = js_value($literal, $at0);
    $shape = [];
    foreach ($parsed as $k => $v) { $shape[$k] = ['pages' => count($v['pages']), 'steps' => array_map(static fn($p) => count($p['steps']), $v['pages'])]; }
} catch (Throwable $e) {
    $shape = null;
}
check(is_array($shape), '③ journeys literal is data only (objects · arrays · strings · numbers) — parsed without running it');
check(array_keys($shape) === array_keys(GuideJourney::JOURNEYS), '③ usage-guide.js journeys keys = GuideJourney::JOURNEYS keys (same order) ' . json_encode(array_keys($shape)));
foreach (GuideJourney::JOURNEYS as $journey => $pages) {
    check(($shape[$journey]['pages'] ?? -1) === $pages, "③ $journey screen count $pages = pages length " . ($shape[$journey]['pages'] ?? 'none'));
    check(max($shape[$journey]['steps']) <= GuideJourney::STEP_MAX + 1, "③ $journey steps per screen ≤ " . (GuideJourney::STEP_MAX + 1) . ' ' . json_encode($shape[$journey]['steps']));
}
check(max(GuideJourney::JOURNEYS) <= 10, '③ every screen count fits the DB CHECK (page BETWEEN 0 AND 9)');
foreach (array_keys(GuideJourney::JOURNEYS) as $journey) {
    check(preg_match('/^[a-z][a-z0-9-]{1,31}$/', $journey) === 1, "③ $journey matches the DB CHECK pattern");
}
echo "RESULT pass=$count fail=0\n";
