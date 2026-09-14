<?php
declare(strict_types=1);

/**
 * src/views/purchase_item.php — 품목 상세 화면의 마크업 (시안 ⑮ Item, PRD F-28)
 *
 * 표시 값은 전부 `purchase.js` 가 `/api/purchase/items/{id}` 응답을 `textContent` 로 채운다.
 * 서버가 이 HTML 에 넣는 동적 값은 **`data-item-id` 하나**이며, 라우터 자리표시자
 * (`[0-9]{1,9}`)를 통과한 숫자이고 htmlspecialchars 를 거친다 (PRD S-03).
 *
 * ★ 25단계 — 「현재고는 21단계에 생깁니다」 박스를 지웠다. 현재고는 이제 있는 값이고,
 *   화면이 `GET /api/purchase/stock?q=<품목코드>` 응답에서 같은 품목의 장소별 현재고를
 *   더해 보여 준다(행이 없으면 「—」 — 0 을 지어내지 않는다). 새 API 는 없다.
 *
 * @var array<string,mixed> $user
 * @var string              $itemId  경로에서 온 숫자 문자열 (핸들러가 좁혔다)
 */

require __DIR__ . '/shell_top.php';
$itemIdAttr = htmlspecialchars(isset($itemId) && is_string($itemId) ? $itemId : '', ENT_QUOTES, 'UTF-8');
?>
    <header class="page-head">
      <div class="page-head__text">
        <nav class="crumbs muted" aria-label="위치">구매관리 › 기초정보 › 품목</nav>
        <h1 class="page-head__title" id="p-item-name">품목</h1>
        <p class="page-head__sub">품목코드는 거래처 카탈로그 번호를 그대로 씁니다. 재고 규칙 세 칸이 발주 제안의 재료입니다.</p>
      </div>
    </header>

  <p id="msg" class="msg" role="alert" aria-live="polite"></p>

  <section class="panel" id="p-item" data-item-id="<?= $itemIdAttr ?>">
    <div class="panel__head panel__head--row">
      <h2 class="panel__title">기본 정보</h2>
    </div>
    <dl class="p-facts" id="p-item-facts"></dl>
    <p class="help">장소별 내역은 「재고 · 미입고」 화면에서 봅니다.</p>
  </section>

  <section class="panel">
    <div class="panel__head panel__head--row">
      <h2 class="panel__title">사용 지점</h2>
    </div>
    <ul class="p-chips" id="p-item-branches"></ul>
    <p class="help">비어 있으면 브랜드 전체에서 쓰는 품목입니다.</p>
  </section>

  <section class="panel">
    <div class="panel__head panel__head--row">
      <h2 class="panel__title">지점별 인가량</h2>
    </div>
    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr>
            <th scope="col">지점</th>
            <th scope="col">장소</th>
            <th scope="col" class="num">인가량</th>
            <th scope="col">적용 시작</th>
          </tr>
        </thead>
        <tbody id="p-item-par"></tbody>
      </table>
    </div>
    <p id="p-item-par-empty" class="empty" hidden>설정된 인가량이 없습니다.</p>
  </section>

<script src="/assets/purchase_common.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_common.js') ?>"></script>
<script src="/assets/purchase.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase.js') ?>"></script>
<?php require __DIR__ . '/shell_bottom.php'; ?>
