<?php
declare(strict_types=1);
// memo_save — 가짜 견본 (조용해야 함)
// 주석 안의 Audit::deny('memo.edit', 'nope') 와 Http::error(403, 'x') 는 코드가 아니다.
$memo = memo_find((int) $params[0]);
if ($memo === null) {
    Http::error(404, 'not found');
}
[$may, $why] = MemoRules::mayEdit($user, $memo);
if (!$may) {
    if ($why === MemoRules::WHY_NOT_WRITER || $why === MemoRules::WHY_LOCKED) {
        Audit::deny('memo.edit', $why, ['target_type' => 'memo']);
        Http::error(403, 'forbidden');
    }
    // 문장이 없는 조합은 서버 로그로만 — 앞의 갈래가 감사를 쓴다(허용되는 모양)
    error_log('memo.edit deny ' . $why);
    Http::error(403, 'forbidden');
}
$tone = $memo['locked'] ? MemoRules::WHY_LOCKED : 'not_writer';
if ($memo['frozen']) {
    Audit::deny('memo.edit', $tone, []);
    Http::json(['error' => 'frozen'], 403);
    exit;
}
$reject = static function (string $why, int $status) use ($memo): void {
    Audit::deny('memo.edit', $why, ['target_type' => 'memo']);
    Http::json(['error' => 'no', 'reason' => $why], $status);
    exit;
};
if ($memo['archived']) {
    if ($memo['locked']) {
        $reject('locked_memo', 403);         // 감사하는 클로저
    }
    error_log('memo.edit archived');
    Http::error(403, 'forbidden');
}
if ($memo['odd']) {
    $dyn = pick_reason($memo);
    Audit::deny('memo.edit', $dyn, []);      // 완전 변수 — skip
    Http::error(403, 'forbidden');
}
if (strlen((string) ($body['text'] ?? '')) > 5000) {
    // 모양 오류 — 이 창구의 action 에는 이 사유가 등록돼 있지 않다(다른 action 의 사유여도)
    Http::json(['error' => 'too long', 'reason' => 'quota_full'], 400);
    exit;
}
$ok = memo_gate($user, $memo);
if ($ok === false) {
    Http::error(403, 'forbidden');           // 판정 함수가 스스로 거부를 기록한다
}
if ($memo['curated']) {
    Audit::allow('memo.view', ['target_type' => 'memo'], 'curator');
}
Audit::allow('memo.edit', ['target_type' => 'memo']);
Http::json(['ok' => true]);

function memo_gate(array $u, array $m): bool
{
    if ($m['blocked']) {
        Audit::deny('memo.view', 'not_writer', []);
        return false;
    }
    return true;
}

function memo_guard(array $u, array $m): void
{
    if ($m['hidden']) {
        memo_refuse($m);
        Http::error(403, 'forbidden');       // 감사하는 도우미를 먼저 부른다
    }
}

function memo_refuse(array $m): void
{
    Audit::deny('memo.view', 'not_writer', []);
}
