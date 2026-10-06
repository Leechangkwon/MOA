<?php
declare(strict_types=1);

/**
 * src/handlers/me_consents.php — GET · POST /api/me/consents (사이트 Q:F-13 P1 · 기획 5.2 「기존 회원」 「AI 국외 전송」 · src/Legal.php)
 *
 * 정책 authenticated · 대상은 언제나 **세션의 본인**(본문에 사람 칸이 없다). POST 는 라우터 [3] CSRF 를 지났다.
 * 동의 관문(Auth::requireConsent)이 열어 두는 창구라 약관 동의 전에도 부를 수 있다. 토큰 창구 집합 밖(사람의 세션만).
 *
 * GET  → 지금 상태 {current, required, ai_overseas, consents(문서별 마지막 줄 — 판·행동·때), notice(AI 동의 창 글자)}
 * POST → 본문은 `{doc, action?}` 두 칸까지만(그 밖 키·모양 400):
 *          {doc:"terms"}                          약관 동의 + 처리방침 확인 — terms · privacy 두 줄(지금 판). 가입과 같은 묶음(PT-4)
 *          {doc:"ai_overseas"}                    AI 국외 전송 동의 — ai_overseas agree 한 줄(PT-8)
 *          {doc:"ai_overseas", action:"withdraw"} 철회 — ai_overseas withdraw 한 줄(「약관·동의」 화면)
 *        이미 그 상태면 줄을 더하지 않는다(`changed:false`). 줄은 **더하기만** 한다(앱 계정은 그 표에 SELECT·INSERT 만).
 *        사람마다 잠금(pg_advisory_xact_lock) 뒤에 상태를 다시 읽고 판단한다 — 두 번 누름이 줄 둘이 되지 않게.
 *        감사 consent.agree · consent.withdraw (Legal::record — 같은 트랜잭션, 감사 실패면 줄도 없다).
 */

require_once __DIR__ . '/../Legal.php';

$consentUid = (int) $user['id'];

if (Http::method() === 'GET') {
    Http::json(Legal::summary($consentUid) + ['notice' => Legal::aiNotice()]);
    exit;
}

$consentBody = Http::jsonBody(1024);
$consentKeys = array_keys($consentBody);
$consentDoc = $consentBody['doc'] ?? null;
$consentAction = array_key_exists('action', $consentBody) ? $consentBody['action'] : 'agree';
if (array_diff($consentKeys, ['doc', 'action']) !== []
    || !is_string($consentDoc) || !is_string($consentAction)
    || !in_array([$consentDoc, $consentAction], [['terms', 'agree'], ['ai_overseas', 'agree'], ['ai_overseas', 'withdraw']], true)
) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

$consentDb = Db::instance();
$consentChanged = false;
$consentDb->begin();
try {
    $consentDb->run('SELECT pg_advisory_xact_lock(1313, CAST(:uid AS integer))',
        [':uid' => $consentUid]);
    $consentState = Legal::state($consentUid, true);
    if ($consentDoc === 'terms') {
        $consentDocs = [];
        foreach (Legal::REQUIRED_DOCS as $consentOne) {
            $consentLast = $consentState[$consentOne];
            if ($consentLast === null || $consentLast['action'] !== 'agree' || $consentLast['version'] !== Legal::current($consentOne)) {
                $consentDocs[] = $consentOne;
            }
        }
        if ($consentDocs !== []) {
            // 두 줄 모두 남긴다(이미 지금 판인 한쪽도) — 같은 화면에서 함께 한 동의라 기록도 한 묶음이다.
            Legal::record(['id' => $consentUid, 'username' => (string) $user['username']], Legal::REQUIRED_DOCS, 'agree', ['via' => 'consent_page']);
            $consentChanged = true;
        }
    } else {
        $consentAgreed = $consentState['ai_overseas'] !== null && $consentState['ai_overseas']['action'] === 'agree';
        if (($consentAction === 'agree') !== $consentAgreed) {
            Legal::record(['id' => $consentUid, 'username' => (string) $user['username']], ['ai_overseas'], $consentAction);
            $consentChanged = true;
        }
    }
    $consentDb->commit();
} catch (Throwable $consentError) {
    $consentDb->rollback();
    throw $consentError;
}

Http::json(['ok' => true, 'changed' => $consentChanged] + Legal::summary($consentUid, true));
