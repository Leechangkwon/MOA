<?php
declare(strict_types=1);

/**
 * src/community/handlers/recent_clear.php — DELETE /community/api/me/recent (정책: community.read — 개발 대기열 6 · Q:F-3a 라운드 A)
 *
 * 「최근 기록 지우기」 — **내 줄만** 지운다(지시서 1.2). 조회수(`page_views`)·감사·공지 읽음 같은 기록은 그대로다 —
 * 이 표는 기록이 아니라 화면 편의다(결정 M-3). CSRF 는 라우터 [3] 이 본다. 대상은 세션의 본인뿐(요청에서 사람을 받지 않는다).
 * 감사에 남기지 않는다(개인 화면 설정 — M-2 와 같은 판단).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

$sqlClear = <<<'SQL'
    DELETE FROM community.page_recent WHERE user_id = CAST(:me AS bigint)
    SQL;
Db::instance()->run($sqlClear, [':me' => (int) $user['id']]);

Http::json(['ok' => true]);
