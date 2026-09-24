<?php
declare(strict_types=1);
// memo_move — 가짜 견본: 문장표에 없는 키를 쓴다 (C1 한 건)
[$may, $why] = MemoRules::mayEdit($user, $memo);
if (!$may) {
    Audit::deny('memo.edit', MemoRules::WHY_WRONG_DESK, ['target_type' => 'memo']);
    Http::error(403, 'forbidden');
}
Audit::allow('memo.edit', ['target_type' => 'memo']);
