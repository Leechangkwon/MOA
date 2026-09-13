<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_structure_get.php — GET /api/purchase/structure
 *   (정책: purchase.master.write → buyer·Admin 만, **재인증 대상**)
 *
 * ★ 24단계 (PRD F-37, ARCH 5.18). 화면 하나가 쓰는 값을 **한 번에** 내려 준다:
 *   브랜드 → 지점 → 연결된 창고 나무 · 미연결 창고(추천 포함) · 제외된 창고 · 건수.
 *
 * 조회는 감사 로그를 남기지 않는다 (ARCH 5.1 마지막 줄 — 조회는 사건이 아니다).
 *
 * 지점 격리 — 이 라우트는 `purchase.master.write`(buyer·Admin 전용)다. 그 둘의 지점 범위는
 *   언제나 전 지점(`1=1`)이라 좁힐 기준이 존재하지 않는다. 표식은 조회마다
 *   `src/purchase/Structure.php` 에 적혀 있고(23단계 규칙), 그 자리가 곧 검토 지점이다.
 *   `p_location_inbox` 는 **지점 축이 아예 없는 마스터**다 — 연결되기 전의 창고라
 *   지점이 정해지지 않았다는 것이 이 표의 존재 이유다 (SECURITY R-65).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Master.php';
require_once __DIR__ . '/../purchase/Importer.php';
require_once __DIR__ . '/../purchase/Structure.php';

$tree  = Structure::tree();
$inbox = Structure::inbox();

Http::json([
    'brands'   => $tree['brands'],
    'unlinked' => $inbox['unlinked'],
    'excluded' => $inbox['excluded'],
    'counts'   => $inbox['counts'] + [
        'brands'    => count($tree['brands']),
        'branches'  => $tree['branches'],
        'locations' => $tree['locations'],
    ],
    // 장소 용도 목록 — 화면의 셀렉트가 이 값으로 채워진다(목록이 두 곳에 있으면 어긋난다).
    'kinds' => Master::LOCATION_KINDS,
]);
