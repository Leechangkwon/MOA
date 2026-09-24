<?php
declare(strict_types=1);
/* 가짜 견본 — 메모장 앱의 감사 문장표. 키 모양이 아닌 상수 배열은 문장표가 아니다. */
final class Audit
{
    private const KEEP_KEYS = ['memo_id' => 1, 'reason_text' => 1];

    private const SENTENCES = [
        'memo.create|allow' => '{actor} 이(가) 메모 「{target}」 을(를) 만들었습니다',
        'memo.create|deny|quota_full' => '{actor} 이(가) 메모를 더 만들려 했지만 칸이 가득 차 거부했습니다',
        'memo.edit|allow' => '{actor} 이(가) 메모 「{target}」 을(를) 고쳤습니다',
        'memo.edit|deny|not_writer' => '{actor} 이(가) 남의 메모 「{target}」 을(를) 고치려 해서 막았습니다',
        'memo.edit|deny|locked_memo' => '{actor} 이(가) 잠긴 메모 「{target}」 을(를) 고치려 해서 막았습니다',
        'memo.view|deny|not_writer' => '{actor} 이(가) 남의 메모 「{target}」 을(를) 열려 해서 막았습니다',
        'memo.view|allow|curator' => '{actor} 이(가) 관리자로서 메모 「{target}」 을(를) 열었습니다',
        'other.ping|allow' => '핑을 받았습니다',
    ];

    public static function allow(string $action, array $ctx = [], ?string $reason = null): void { self::put('allow', $action, $reason); }
    public static function deny(string $action, string $reason, array $ctx = []): void { self::put('deny', $action, $reason); }
    public static function error(string $action, string $reason, array $ctx = []): void { self::put('error', $action, $reason); }
    private static function put(string $r, string $a, ?string $why): void {}
}
