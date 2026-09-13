<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_po_print_page.php — GET /purchase/po/{num}/print  (정책: purchase.read)
 *
 * ★ 20단계 **발주서 인쇄 화면** (Q-18② 확정, ARCH 5.18 「발주서 PDF」).
 *
 * PDF 라이브러리를 쓰지 않는다 (PRD S-10-C — 의존성을 늘리지 않는다).
 * A4 세로 인쇄 CSS(`@page`)를 입힌 **보통 HTML** 이고, 사람은 브라우저의
 * 「인쇄 → PDF 로 저장」으로 파일을 만든다. 새 외부 코드도, 새 패키지도 없다.
 *
 * 이 화면이 그리는 값은 **전부 서버가 계산한 것**이다:
 *   · 머리(일련번호·수신·납품창고·납기·회사 정보·담당)는 `p_docs.header_json`
 *     (서버가 `Doc::buildHeader` 로 채우고 buyer 가 허용 키만 고친 값)
 *   · 표 8열(품목코드·품목명[규격]·수량·단가·공급가액·부가세·창고명·적요)은 `p_doc_lines`
 *   · 공급가액·부가세·합계는 **저장값이 아니라** `Doc::totals()` 가 매번 계산한 값 (R-66)
 * 화면에 들어가는 모든 문자열은 `htmlspecialchars` 를 지난다 (PRD S-03). JS 는 쓰지 않는다.
 *
 * 지점 격리 (SECURITY R-65): `Authz::purchaseScopeWhere($user, 'd')`. 범위 밖은 404.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params $params[0] = p_docs.id
 */

require_once __DIR__ . '/../purchase/Doc.php';

$idRaw = $params[0] ?? '';
if (!is_string($idRaw) || preg_match('/^[0-9]{1,9}$/', $idRaw) !== 1) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$docId = (int) $idRaw;

$scope = Authz::purchaseScopeWhere($user, 'd');

$sqlDoc = <<<'SQL'
    SELECT d.id, d.doc_no, CAST(d.doc_date AS text) AS doc_date, d.status, d.memo,
           d.branch_id, br.name AS branch_name,
           ven.name AS vendor_name, ven.biz_no AS vendor_biz_no,
           loc.name AS location_name,
           mgr.username AS manager_name,
           d.sent_at, sb.username AS sent_by_name,
           CAST(d.header_json AS text) AS header_json
      FROM p_docs d
      LEFT JOIN p_branches  br  ON br.id  = d.branch_id
      LEFT JOIN p_vendors   ven ON ven.id = d.vendor_id
      LEFT JOIN p_locations loc ON loc.id = d.to_location_id
      LEFT JOIN users       mgr ON mgr.id = d.manager_id
      LEFT JOIN users       sb  ON sb.id  = d.sent_by
     WHERE d.id = CAST(:id AS bigint) AND d.doc_type = :type AND
    SQL;

$db  = Db::instance();
$doc = $db->run(
    $sqlDoc . ' ' . $scope['sql'],
    [':id' => $docId, ':type' => Doc::TYPE_PO] + $scope['params']
)->fetch();
if (!is_array($doc)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

$header = Doc::headerOf($doc['header_json']);
$lines  = Doc::lines($db, $docId);
$totals = Doc::totals($lines);

/*
 * 로고는 **기존 파일 미리보기 라우트로만** 건다 (지시서 보충 4).
 * 파일을 이 화면이 직접 읽지 않으므로 권한 판정은 그 라우트의 `decideRead` 가 그대로 한다 —
 * 볼 수 없는 사람에게는 그림이 뜨지 않을 뿐, 이 화면이 파일을 대신 내주지 않는다.
 * URL 에 들어가는 값은 DB 에서 읽은 UUID 하나뿐이다(요청 값이 섞이지 않는다).
 */
$sqlLogo = <<<'SQL'
    SELECT CAST(f.public_id AS text) AS public_id
      FROM p_docs d
      JOIN p_branches b ON b.id = d.branch_id
      JOIN p_brands  bd ON bd.id = b.brand_id
      JOIN files     f  ON f.id = bd.logo_file_id
     WHERE d.id = CAST(:id AS bigint) AND f.deleted_at IS NULL
    SQL;

$logoRow = $db->run($sqlLogo, [':id' => $docId])->fetch();
$logoUrl = is_array($logoRow) && preg_match('/^[0-9a-f-]{36}$/', (string) $logoRow['public_id']) === 1
    ? '/api/files/' . (string) $logoRow['public_id'] . '/preview/raw'
    : null;

require __DIR__ . '/../views/purchase_po_print.php';
