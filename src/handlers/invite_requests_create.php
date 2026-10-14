<?php
declare(strict_types=1);
/**
 * src/handlers/invite_requests_create.php — POST /api/invite-requests (정책 public · 사이트 H2 · 기획 ../docs/invite-request-plan.md 2 · 3장)
 *
 * 초대 없는 사람이 로그인 전 화면(/tbox/request-invite)에서 남기는 초대 요청. 막는 것(순서대로):
 *   ① 가입과 같은 CSRF(`__Host-moa_join` 쿠키 = X-Join-CSRF) · 로그인한 사람은 409
 *   ② 키 넷(name · org · reason · agree) + 미끼 칸 website 만 · 글자 검사(올바른 UTF-8 · 앞뒤 공백 뺀 길이 · 제어 문자 0 —
 *      이유 칸만 줄바꿈 허용) · agree 는 JSON true 만
 *   ③ 한 트랜잭션 · 전역 자문 잠금 아래: IP 마다 1시간 3건 · 24시간 5건(요청 행 + 미끼에 걸린 감사 행) → 429 ·
 *      열린 요청 200건 → 503 · 미끼 칸이 차 있으면 **같은 201 모양**(가짜 코드 · 행 0) + deny 감사
 *   ④ 확인 코드 32바이트 난수 → 표에는 sha256 만 · 응답 201 `{status_path}`(코드는 주소 `#` 뒤 — 접근 로그 · Referer 0)
 * 감사: invite_request.create(대상 「초대 요청 #번호」 — 이름 · 소속 · 이유 · 코드 0). 메일 · 외부 요청 0.
 */
require_once __DIR__.'/../Member.php';
Member::requireJoinCsrf();
if (Auth::currentUser() !== null) Http::error(409, '이미 로그인되어 있습니다.');
$body = Http::jsonBody(8192);
if (array_diff(array_keys($body), ['name', 'org', 'reason', 'agree', 'website']) !== []) Http::error(400, Http::MSG_BAD_REQUEST);

/** 앞뒤 공백을 뺀 1~$max 자 · 올바른 UTF-8 · 제어 · 서식 문자 0(이유만 줄바꿈 허용). 틀리면 null */
$clean = static function (mixed $v, int $max, bool $lines): ?string {
    if (!is_string($v) || preg_match('//u', $v) !== 1) return null;
    $v = str_replace("\r\n", "\n", $v);
    // 제어 · 서식 문자는 자르기(trim) **전에** 본다 — 끝에 붙은 NUL 같은 글자가 조용히 잘려 통과하지 않게
    if (preg_match($lines ? '/[^\P{C}\n]/u' : '/\p{C}/u', $v) === 1) return null;
    $v = trim($v);
    if ($v === '' || iconv_strlen($v, 'UTF-8') > $max) return null;
    return $v;
};
$name = $clean($body['name'] ?? null, 40, false);
$org = $clean($body['org'] ?? null, 80, false);
$reason = $clean($body['reason'] ?? null, 500, true);
if ($name === null) Http::error(400, '이름은 1~40자로 입력해 주세요.');
if ($org === null) Http::error(400, '소속은 1~80자로 입력해 주세요.');
if ($reason === null) Http::error(400, '쓰려는 이유는 1~500자로 입력해 주세요.');
if (($body['agree'] ?? null) !== true) Http::error(400, '개인정보 처리방침(초대 요청 항목)을 확인해 주세요.');
$website = $body['website'] ?? '';
if (!is_string($website)) Http::error(400, Http::MSG_BAD_REQUEST);
$trapped = trim($website) !== '';
unset($body, $website);

$ip = Http::clientIp();
$code = bin2hex(random_bytes(32));
$statusPath = Http::url('/request-invite/status') . '#code=' . $code;
$db = Db::instance();
$db->begin();
try {
    // 세는 것과 넣는 것 사이에 다른 요청이 끼지 않게(요청은 드물다 — 전역 한 줄 잠금 · 트랜잭션 끝에 풀림)
    $db->run("SELECT pg_advisory_xact_lock(hashtext('qaredi.invite_requests'))");
    $counts = $db->run(<<<'SQL'
        SELECT (SELECT count(*) FROM invite_requests WHERE ip IS NOT DISTINCT FROM CAST(:ip AS inet) AND created_at > now() - interval '1 hour')
             + (SELECT count(*) FROM audit_logs WHERE action = 'invite_request.create' AND result = 'deny' AND reason = 'honeypot'
                  AND ip IS NOT DISTINCT FROM CAST(:ip2 AS inet) AND happened_at > now() - interval '1 hour') AS hour,
               (SELECT count(*) FROM invite_requests WHERE ip IS NOT DISTINCT FROM CAST(:ip3 AS inet) AND created_at > now() - interval '24 hours')
             + (SELECT count(*) FROM audit_logs WHERE action = 'invite_request.create' AND result = 'deny' AND reason = 'honeypot'
                  AND ip IS NOT DISTINCT FROM CAST(:ip4 AS inet) AND happened_at > now() - interval '24 hours') AS day,
               (SELECT count(*) FROM invite_requests WHERE status = 'open') AS open
        SQL, [':ip' => $ip, ':ip2' => $ip, ':ip3' => $ip, ':ip4' => $ip])->fetch();
    if ((int) $counts['hour'] >= 3 || (int) $counts['day'] >= 5) {
        $db->rollback();
        Audit::deny('invite_request.create', 'rate_limited', ['target_type' => 'system', 'detail' => ['window' => (int) $counts['hour'] >= 3 ? '1 hour' : '24 hours']]);
        header('Retry-After: 3600');
        Http::error(429, '요청이 많습니다. 나중에 다시 시도해 주세요.');
    }
    if ((int) $counts['open'] >= 200) {
        $db->rollback();
        Audit::deny('invite_request.create', 'too_many_open', ['target_type' => 'system']);
        header('Retry-After: 3600');
        Http::error(503, '지금은 요청이 많아 받을 수 없어요. 잠시 뒤에 다시 시도해 주세요.');
    }
    if ($trapped) {
        // 미끼 칸 — 사람에게는 보이지 않는 칸이 차 있다. 저장하지 않고 **진짜와 같은 모양**(201 · 64자리 코드 주소)으로 답한다.
        $db->rollback();
        Audit::deny('invite_request.create', 'honeypot', ['target_type' => 'system']);
        Http::json(['status_path' => $statusPath], 201);
        return;
    }
    $row = $db->run('INSERT INTO invite_requests(name,org,reason,status_hash,ip) VALUES(:name,:org,:reason,:hash,CAST(:ip AS inet)) RETURNING id',
        [':name' => $name, ':org' => $org, ':reason' => $reason, ':hash' => hash('sha256', $code), ':ip' => $ip])->fetch();
    $id = (int) $row['id'];
    Audit::allow('invite_request.create', ['target_type' => 'system', 'target_id' => $id, 'target_name' => '초대 요청 #' . $id]);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollback();
    throw $e;
}
unset($code, $name, $org, $reason);
Http::json(['status_path' => $statusPath], 201);
