<?php
declare(strict_types=1);

/**
 * src/views/purchase_item.php — 품목 상세 화면의 마크업 (시안 ⑮ Item, PRD F-28)
 *
 * 표시 값은 전부 `purchase.js` 가 `/api/purchase/items/{id}` 응답을 `textContent` 로 채운다.
 * 서버가 이 HTML 에 넣는 동적 값은 **`data-item-id` 하나**이며, 라우터 자리표시자
 * (`[0-9]{1,9}`)를 통과한 숫자이고 htmlspecialchars 를 거친다 (PRD S-03).
 *
 * @var array<string,mixed> $user
 * @var string              $itemId  경로에서 온 숫자 문자열 (핸들러가 좁혔다)
 */

require __DIR__ . '/shell_top.php';
$itemIdAttr = htmlspecialchars(isset($itemId) && is_string($itemId) ? $itemId : '', ENT_QUOTES, 'UTF-8');
?>
    <link rel="stylesheet" href="/assets/purchase.css?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase.css') ?>">

    <header class="page-head">
      <div class="page-head__text">
        <h1 class="page-head__title">구매관리 › 기초정보 › 품목</h1>
        <p class="page-head__sub">품목코드는 거래처 카탈로그 번호를 그대로 씁니다. 재고 규칙 세 칸이 발주 제안의 재료입니다.</p>
      </div>
      <div class="page-head__actions">
        <a class="btn btn--quiet" href="/purchase/masters">목록</a>
      </div>
    </header>

  <section class="panel" id="p-item" data-item-id="<?= $itemIdAttr ?>">
    <div class="panel__head"><h2 class="panel__title" id="p-item-name">품목</h2></div>
    <dl class="p-facts" id="p-item-facts"></dl>
  </section>

  <section class="panel">
    <div class="panel__head"><h2 class="panel__title">사용 지점</h2></div>
    <p class="muted">비어 있으면 브랜드 전체에서 쓰는 품목입니다. 여기 보이는 지점은 <strong>내가 볼 수 있는 지점</strong>뿐입니다.</p>
    <ul class="p-chips" id="p-item-branches"></ul>
  </section>

  <section class="panel">
    <div class="panel__head"><h2 class="panel__title">지점별 인가량</h2></div>
    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr>
            <th scope="col">지점</th>
            <th scope="col">장소</th>
            <th scope="col">인가량</th>
            <th scope="col">적용 시작</th>
          </tr>
        </thead>
        <tbody id="p-item-par"></tbody>
      </table>
    </div>
    <p id="p-item-par-empty" class="muted" hidden>설정된 인가량이 없습니다.</p>
  </section>

  <p class="notice">
    <strong>현재고는 21단계</strong>에 생깁니다 — 재고 원장(입고·이동·사용·실사)이 있어야 계산되는 값이라
    지금은 「—」로 둡니다. 0 으로 보여 주면 「재고가 없다」는 틀린 사실을 말하게 됩니다.
  </p>

<script src="/assets/purchase.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase.js') ?>"></script>
<?php require __DIR__ . '/shell_bottom.php'; ?>
