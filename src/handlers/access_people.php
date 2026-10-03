<?php
declare(strict_types=1);

/**
 * src/handlers/access_people.php — GET /api/access/people?q= (정책: file.update — 손님은 여기서 끝난다)
 *
 * G-0 (t-box PRD F-73 ②) — 권한을 줄 사람 후보. 커뮤니티 5-A″ 「공유 후보」(`GET /community/api/share-candidates`)와
 * **같은 판정·같은 모양**이다: 앞뒤 공백을 뺀 뒤 한 글자도 없으면 빈 목록(입력 전에 사람 목록을 통째로 보이지 않는다) ·
 * 활성 계정만 · 아이디 또는 이름 조각 · 계정명·이름·역할만 · 최대 20 · 부른 사람 자신은 뺀다.
 * 자격은 **줄 수 있는 사람**(쓰기 자격 — 라우트 정책 file.update · 손님은 무엇의 주인도 될 수 없다). 특정 항목을 묻지 않는다 —
 * 실제로 줄 때 `POST …/grants` 가 그 항목의 주인인지 다시 본다(목록 조회라 감사 없음 · 커뮤니티 후보 창구와 같다).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params (없음)
 */

require_once __DIR__ . '/_access_common.php';

$limit  = 20;
$q      = (string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', Http::queryText('q', 64));
$people = tbox_people_candidates($q, $limit, (int) $user['id']);

Http::json([
    'candidates' => $people,
    'total'      => count($people),
    'limit'      => $limit,
    'q'          => $q,
]);
