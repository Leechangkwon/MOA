<?php
declare(strict_types=1);
// memo_new — 가짜 견본 (조용해야 함)
try {
    $made = memo_insert($user, $body);
} catch (QuotaFull $e) {
    Http::json(['error' => 'full', 'reason' => 'quota_full'], 400);   // try 몸이 감사하는 함수를 부른다
    exit;
}
Audit::allow($made['draft'] ? 'memo.create' : 'memo.edit', ['target_type' => 'memo']);

function memo_insert(array $u, array $b): array
{
    if ($b['count'] > 99) {
        Audit::deny('memo.create', 'quota_full', []);
        throw new QuotaFull();
    }
    return ['draft' => true];
}
