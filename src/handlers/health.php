<?php
declare(strict_types=1);

/**
 * src/handlers/health.php — GET /api/health (정책: public)
 *
 * 살아 있는가 + **DB 가 답하는가** (PRD F-48, ARCHITECTURE 5.24).
 * PHP 까지 살아 있다는 뜻뿐이던 `{"ok":true}` 로는 2026-09-15 사고(디스크 0 → PostgreSQL PANIC,
 * SECURITY R-80)처럼 **DB 만 죽은 상태**를 구분하지 못했다. 그래서 질의 하나를 해 보고 답한다.
 *
 *   DB 가 답함        200  {"status":"ok","db":"ok","time":"<DATE_ATOM>"}
 *   연결 자체가 안 됨  503  {"status":"fail","db":"connect_failed"}
 *   질의가 실패        503  {"status":"fail","db":"query_failed"}
 *
 * `db` 가 자유 문장이 아니라 **고정 분류어**인 이유 (PRD S-09-7) — PDO 의 오류 문구에는
 * `connection to server at "127.0.0.1", port 5432 failed: … for user "tbox_app"` 처럼
 * 호스트·포트·DB 계정 이름이 들어 있다. 로그인 없이 부를 수 있는 창구가 그것을 그대로 내주면
 * 곧 정찰 재료가 된다. 원문은 서버 로그에 `request_id` 와 함께만 남긴다.
 * 버전·서버 이름도 담지 않고(F-47 ④·S-09-5), CORS 헤더도 없다(F-42). 감사 기록 대상도 아니다.
 *
 * 연결 실패와 질의 실패를 `Db::ready()` 로 가른다 — `PDOException` 은 `RuntimeException` 의
 * 자식이라 catch 순서로 가르면 질의 실패가 connect_failed 로 잘못 분류될 수 있다.
 *
 * @var array<string,mixed>|null $user   이 라우트는 public 이므로 항상 null
 * @var list<string>             $params 경로 변수 없음
 */

if (!Db::ready()) {
    // 부팅의 Db::boot 이 연결에 실패한 경우. 예외 문구는 이미 Db::boot 이 로그에 남겼다.
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' health: connect_failed');
    Http::json(['status' => 'fail', 'db' => 'connect_failed'], 503);
    return;
}

try {
    Db::instance()->run('SELECT 1')->fetchColumn();
} catch (Throwable $e) {
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' health: query_failed '
        . get_class($e) . ': ' . $e->getMessage());
    Http::json(['status' => 'fail', 'db' => 'query_failed'], 503);
    return;
}

// DATE_ATOM = ISO 8601. 시간대는 서버 시간대(Asia/Seoul)가 그대로 붙는다.
Http::json(['status' => 'ok', 'db' => 'ok', 'time' => date(DATE_ATOM)]);
