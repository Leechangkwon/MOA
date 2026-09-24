<?php
declare(strict_types=1);
// memo_share — 가짜 견본: 거부 갈래에 감사가 없다 (C4 두 건)
$memo = memo_find((int) $params[0]);
if ($memo === null) {
    Http::error(404, 'not found');
}
[$may, $why] = MemoRules::mayEdit($user, $memo);
if (!$may) {
    // 문장이 없어서 서버 로그로만 남긴다 — 거부가 감사 화면에 보이지 않는다
    error_log('memo.edit deny code=' . $why);
    Http::error(403, 'forbidden');
}
if ($memo['count'] > 99) {
    // 이 창구의 action(memo.create) 에 등록된 거부 사유를 싣고도 감사를 쓰지 않는다
    Http::json(['error' => 'full', 'reason' => 'quota_full'], 400);
    exit;
}
Audit::allow('memo.create', ['target_type' => 'memo']);
