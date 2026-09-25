<?php
declare(strict_types=1);

/**
 * src/handlers/push_key.php — GET /api/admin/push/key (정책: role:Admin + 재인증, PRD F-70)
 *
 * 요구사항: 지시서 docs/f70-push-alerts-directive.md D 표 · 구현 기록 2 · ARCH 5.32 · API.md 15장
 *
 * 하는 일: 휴대폰 「받기」를 만들 때 브라우저에 넘길 **VAPID 공개 키**(비밀 아님, base64url 65바이트 점)를 준다.
 * 브라우저는 이 값을 `pushManager.subscribe({applicationServerKey})` 에 넣는다 — 그러면 그 받기는 이 서버의
 * 비밀 키로 서명한 요청만 받는다(RFC 8292 4장). 비밀 키는 보내는 배치 계정(tbox-push) 0600 파일에만 있고
 * 이 핸들러(웹 계정 tbox-php)는 **읽지 못한다**(L-3).
 *
 * ★ 공개 키 파일은 코드 상수 `WebPush::PUBLIC_KEY_FILE`(/etc/tbox-v2/vapid-public.txt, root 0644) — `.env` 가 아니다
 *   (구현 기록 2). 요청 값으로 경로가 바뀌는 길이 없다. 파일이 없거나 모양이 틀리면 503 — 반영 전 상태다.
 * ★ 외부 요청 0 · 감사 없음(비밀 아닌 값 읽기).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params
 */

require_once __DIR__ . '/../WebPush.php';

$key = WebPush::readPublicKeyFile(WebPush::PUBLIC_KEY_FILE);
if ($key === null) {
    Http::error(503, '휴대폰 경보가 아직 준비되지 않았습니다 (서버의 알림 키가 없습니다)');
}

Http::json(['public_key' => $key]);
