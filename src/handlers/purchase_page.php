<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_page.php — GET /purchase  (정책: purchase.read)
 *
 * ★ 20단계 — 모듈에 화면이 넷이 되었으므로, `/purchase` 는 **그 사람이 매일 여는 화면**으로 보낸다.
 *
 *   발주서를 쓰거나 보낼 수 있는 사람(buyer·viewer·Admin) → `/purchase/po`
 *   재고를 움직이는 사람(stock)                          → `/purchase/stock`  ★ 21단계
 *   요청을 쓰는 사람(requester)                          → `/purchase/pr`
 *   그 밖                                                → `/purchase/masters`
 *
 * ★ 21단계에서 stock 줄이 생겼다. 순서가 뜻이다: buyer 는 `purchase.stock.write` 도 참이지만
 *   위에서 이미 `/purchase/po` 로 갔으므로, 이 줄에 닿는 사람은 **창고 담당뿐**이다.
 *
 * ★ 역할 문자열을 여기서 비교하지 않는다 (PRD S-01, SECURITY R-70). 판정은 전부
 *   `Authz::purchaseAuthorize` 가 하고, 이 파일은 그 **참/거짓만** 본다.
 *   가는 곳은 코드에 적힌 상수이고 요청 값이 Location 헤더에 섞이는 자리가 없다(오픈 리다이렉트 0).
 *   보내진 화면도 **같은 정책**을 다시 검사한다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

const TBOX_PURCHASE_HOME_PO      = '/purchase/po';
const TBOX_PURCHASE_HOME_STOCK   = '/purchase/stock';
const TBOX_PURCHASE_HOME_PR      = '/purchase/pr';
const TBOX_PURCHASE_HOME_MASTERS = '/purchase/masters';

if (Authz::purchaseAuthorize('purchase.buy.write', $user)
    || Authz::purchaseAuthorize('purchase.send', $user)
) {
    Http::redirect(TBOX_PURCHASE_HOME_PO);
}
if (Authz::purchaseAuthorize('purchase.stock.write', $user)) {
    Http::redirect(TBOX_PURCHASE_HOME_STOCK);
}
if (Authz::purchaseAuthorize('purchase.request.write', $user)) {
    Http::redirect(TBOX_PURCHASE_HOME_PR);
}
Http::redirect(TBOX_PURCHASE_HOME_MASTERS);
