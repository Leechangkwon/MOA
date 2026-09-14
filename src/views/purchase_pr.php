<?php
declare(strict_types=1);

/**
 * src/views/purchase_pr.php — 발주요청 화면의 마크업 (시안 ① Main · ② ItemPicker · ③ MyList)
 *
 * 공통 셸(`shell_top.php` · `shell_bottom.php`)을 그대로 쓴다.
 * 이 파일이 하는 일은 **본문 틀**을 놓는 것뿐이고, 표와 값은 `purchase_docs.js` 가
 * `/api/purchase/*` 응답을 `textContent` 로만 채운다 (PRD S-03).
 *
 * ★ 25단계 — 시안 ① 의 배치로 되돌렸다: **머리 카드가 위, 줄 카드가 아래 전체 폭**이다.
 *   그 전에는 둘이 같은 그리드의 좌·우 칸에 들어가 표가 좁은 칸에 눌려 있었다.
 *   스타일시트는 셸이 head 에서 싣고(본문 <link> 0), 알림 줄은 여기 한 번만 있다.
 *
 * ★ 서버가 HTML 에 넣는 동적 값이 **하나도 없다**(셸의 사용자명·역할 제외).
 *   담당자 칸도 서버가 심지 않는다 — `/api/me` 가 준 이름을 JS 가 `textContent` 로 넣는다.
 * ★ 인라인 <script>·style 속성·on* 이벤트 속성을 쓰지 않는다 (CSP: script-src 'self').
 *
 * @var array<string,mixed> $user
 */

require __DIR__ . '/shell_top.php';
?>
    <header class="page-head">
      <div class="page-head__text">
        <nav class="crumbs muted" aria-label="위치">구매관리 › 전표 › 발주요청</nav>
        <h1 class="page-head__title">발주요청 입력</h1>
        <p class="page-head__sub">필요한 것을 적어 두면 구매실이 거래처별로 묶어 발주합니다. <strong>재고는 움직이지 않습니다.</strong></p>
      </div>
      <div class="page-head__actions">
        <button id="pr-goto-docs" class="btn btn--quiet" type="button">
          <span class="icon" data-icon="log"></span><span>전표 보기</span>
        </button>
        <button id="pr-reset" class="btn btn--quiet" type="button">
          <span class="icon" data-icon="restore"></span><span>다시 작성</span>
        </button>
      </div>
    </header>

  <p id="msg" class="msg" role="alert" aria-live="polite"></p>

  <nav class="tabs" id="pr-tabs" role="tablist" aria-label="발주요청 화면">
    <button class="tab is-on" type="button" role="tab" aria-selected="true" data-tab="input">입력</button>
    <button class="tab" type="button" role="tab" aria-selected="false" data-tab="docs">전표</button>
    <button class="tab" type="button" role="tab" aria-selected="false" data-tab="status">현황</button>
  </nav>

  <!-- ── 입력 탭 — 머리 카드(위) + 줄 카드(아래) ─────────────── -->
  <form id="pr-input" class="form" autocomplete="off">
    <section class="panel">
      <div class="panel__head panel__head--row">
        <h2 class="panel__title">새 발주요청</h2>
      </div>
      <div class="grid-form">
        <label class="field">
          <span class="field__label">일자</span>
          <input id="pr-date" type="date" required>
        </label>
        <label class="field">
          <span class="field__label">지점</span>
          <select id="pr-branch"></select>
        </label>
        <label class="field">
          <span class="field__label">받을 장소</span>
          <select id="pr-location"></select>
        </label>
        <label class="field">
          <span class="field__label">거래처 (선택)</span>
          <select id="pr-vendor"></select>
        </label>
        <div class="field">
          <span class="field__label">담당자</span>
          <div class="field__row">
            <input id="pr-manager" type="text" readonly>
            <span class="badge badge--accent">본인</span>
          </div>
        </div>
        <label class="field field--full">
          <span class="field__label">비고</span>
          <input id="pr-memo" type="text" maxlength="500">
        </label>
      </div>
    </section>

    <section class="panel">
      <div class="panel__head panel__head--row">
        <h2 class="panel__title">품목 줄</h2>
        <div class="panel__tools">
          <button id="pr-pick" class="btn btn--quiet" type="button">품목 찾기</button>
          <button id="pr-reco" class="btn btn--quiet" type="button">추천 · 내 리스트</button>
        </div>
      </div>

      <div class="table-wrap">
        <table class="files p-lines">
          <thead>
            <tr>
              <th scope="col" class="p-col-no">#</th>
              <th scope="col">코드</th>
              <th scope="col">품목명 · 규격</th>
              <th scope="col">거래처</th>
              <th scope="col" class="num">수량</th>
              <th scope="col" class="num">단가</th>
              <th scope="col" class="num">금액</th>
              <th scope="col">비고</th>
              <th scope="col"><span class="sr-only">줄 빼기</span></th>
            </tr>
          </thead>
          <tbody id="pr-line-body"></tbody>
        </table>
      </div>
      <p id="pr-line-empty" class="empty">품목을 담으면 여기에 줄이 생깁니다.</p>

      <div class="table-foot">
        <span id="pr-sum" class="muted"></span>
        <span class="muted">합계(부가세 별도) <b id="pr-total" class="p-total">0</b></span>
      </div>
      <p class="help">
        단가와 금액은 <strong>서버가 품목 정보에서 계산</strong>합니다 — 화면에서 고칠 수 없고, 고쳐 보내도 서버는 읽지 않습니다.
        요청은 <strong>미확인</strong>일 때만 고칠 수 있고, 구매실이 담은 줄은 잠깁니다(잠긴 뒤 더 적는 내역은 새 요청 전표로 분리됩니다).
      </p>
    </section>

    <div class="form-actions">
      <button id="pr-save" class="btn" type="submit">저장</button>
    </div>
  </form>

  <!-- ── 전표 탭 ───────────────────────────────────────────── -->
  <section class="panel" id="pr-docs" hidden>
    <div class="panel__head panel__head--row">
      <h2 class="panel__title">발주요청 전표</h2>
      <div class="panel__tools">
        <label class="field field--inline">
          <span class="field__label">검색</span>
          <input id="pr-q" type="search" maxlength="100" autocomplete="off" spellcheck="false">
        </label>
        <label class="field field--inline">
          <span class="field__label">상태</span>
          <select id="pr-status">
            <option value="">전체(취소 제외)</option>
            <option value="unconfirmed">미확인</option>
            <option value="in_progress">진행중</option>
            <option value="partial">일부 발주</option>
            <option value="done">완료</option>
            <option value="void">취소</option>
          </select>
        </label>
        <label class="check">
          <input id="pr-mine" type="checkbox">
          <span>내 요청만</span>
        </label>
      </div>
    </div>

    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr>
            <th scope="col">일자 · 번호</th><th scope="col">지점</th><th scope="col">장소</th><th scope="col">거래처</th>
            <th scope="col">담당자</th><th scope="col" class="num">품목</th><th scope="col" class="num">금액</th><th scope="col">상태</th>
          </tr>
        </thead>
        <tbody id="pr-doc-body"></tbody>
      </table>
    </div>
    <p id="pr-doc-empty" class="empty" hidden>표시할 전표가 없습니다.</p>

    <div class="table-foot">
      <span id="pr-page-info" class="muted"></span>
      <div class="pager">
        <button id="pr-prev" class="btn btn--quiet btn--small" type="button">이전</button>
        <button id="pr-next" class="btn btn--quiet btn--small" type="button">다음</button>
      </div>
    </div>
  </section>

  <!-- 전표 상세 (전표 탭에서 한 줄을 누르면 열린다) -->
  <section class="panel" id="pr-detail" hidden>
    <div class="panel__head panel__head--row">
      <h2 class="panel__title" id="pr-detail-title">전표</h2>
      <div class="panel__tools">
        <button id="pr-detail-void" class="btn btn--danger btn--small" type="button">요청 취소</button>
        <button id="pr-detail-close" class="btn btn--quiet btn--small" type="button">닫기</button>
      </div>
    </div>
    <p id="pr-detail-sub" class="muted"></p>
    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr>
            <th scope="col" class="p-col-no">#</th><th scope="col">코드</th><th scope="col">품목명</th>
            <th scope="col" class="num">요청</th><th scope="col" class="num">발주</th><th scope="col" class="num">잔량</th>
            <th scope="col" class="num">금액</th><th scope="col">상태</th>
            <th scope="col"><span class="sr-only">작업</span></th>
          </tr>
        </thead>
        <tbody id="pr-detail-body"></tbody>
      </table>
    </div>
    <p id="pr-detail-note" class="help"></p>
  </section>

  <!-- ── 현황 탭 — 사이클 표는 전표 화면과 **같은 함수**가 그린다 ── -->
  <section class="panel" id="pr-status-panel" hidden>
    <div class="panel__head panel__head--row">
      <h2 class="panel__title">내 요청 현황 (사이클)</h2>
    </div>
    <div class="table-wrap">
      <table class="files">
        <thead><tr id="pr-cycle-head"></tr></thead>
        <tbody id="pr-cycle-body"></tbody>
      </table>
    </div>
    <div class="table-foot">
      <span id="pr-cycle-info" class="muted"></span>
    </div>
    <p class="help">입고·미입고 칸은 재고 원장의 합입니다. 보낸 발주서인데 아직 오지 않은 줄에는 「미입고」 배지가 섭니다.</p>
  </section>

  <!-- ── 품목 찾기 모달 (시안 ② ItemPicker) ─────────────────── -->
  <dialog class="modal modal--stack modal--wide" id="pick-modal" aria-labelledby="pick-title">
    <div class="modal__head">
      <h2 class="modal__title" id="pick-title">품목 찾기</h2>
      <span class="modal__meta">코드 · 이름 · 규격으로 찾아 여러 품목을 한 번에 담습니다</span>
      <button class="btn btn--icon" type="button" data-modal-close aria-label="닫기" title="닫기">
        <span class="icon" data-icon="x"></span>
      </button>
    </div>
    <div class="modal__body">
      <div class="panel__tools">
        <label class="field field--inline">
          <span class="field__label">검색</span>
          <input id="pick-q" type="search" maxlength="100" autocomplete="off" spellcheck="false">
        </label>
        <label class="field field--inline">
          <span class="field__label">거래처</span>
          <select id="pick-vendor"></select>
        </label>
        <button id="pick-search" class="btn btn--quiet" type="button">찾기</button>
      </div>
      <div class="table-wrap p-pick-wrap">
        <table class="files">
          <thead>
            <tr>
              <th scope="col" class="p-col-check"><span class="sr-only">선택</span></th>
              <th scope="col">코드</th><th scope="col">품목명 · 규격</th><th scope="col">거래처</th>
              <th scope="col" class="num">단가</th><th scope="col" class="num">수량</th>
            </tr>
          </thead>
          <tbody id="pick-body"></tbody>
        </table>
      </div>
    </div>
    <div class="modal__foot">
      <span id="pick-info" class="muted"></span>
      <div class="pager">
        <button id="pick-prev" class="btn btn--quiet btn--small" type="button">이전</button>
        <button id="pick-next" class="btn btn--quiet btn--small" type="button">다음</button>
      </div>
      <button id="pick-add" class="btn" type="button">담기</button>
    </div>
  </dialog>

  <!-- ── 추천 · 내 리스트 모달 (시안 ③ MyList) — 좌우 같은 폭 2단 ── -->
  <dialog class="modal modal--stack modal--wide" id="reco-modal" aria-labelledby="reco-title">
    <div class="modal__head">
      <h2 class="modal__title" id="reco-title">추천 · 내 발주 리스트</h2>
      <span id="reco-note" class="modal__meta"></span>
      <button class="btn btn--icon" type="button" data-modal-close aria-label="닫기" title="닫기">
        <span class="icon" data-icon="x"></span>
      </button>
    </div>
    <div class="modal__body">
      <div class="split split--even">
        <section class="panel">
          <div class="panel__head panel__head--row">
            <h3 class="panel__title">자주 주문 (최근 90일)</h3>
            <div class="panel__tools">
              <label class="check">
                <input id="reco-branch" type="checkbox">
                <span>지점 전체 기준</span>
              </label>
            </div>
          </div>
          <div class="table-wrap p-pick-wrap">
            <table class="files">
              <thead>
                <tr>
                  <th scope="col" class="p-col-check"><span class="sr-only">선택</span></th>
                  <th scope="col">품목</th><th scope="col" class="num">횟수</th>
                  <th scope="col" class="num">평균</th><th scope="col">마지막</th><th scope="col" class="num">수량</th>
                </tr>
              </thead>
              <tbody id="reco-body"></tbody>
            </table>
          </div>
        </section>

        <section class="panel">
          <div class="panel__head panel__head--row">
            <h3 class="panel__title">내 리스트</h3>
            <div class="panel__tools">
              <select id="reco-list" aria-label="내 발주 리스트"></select>
              <button id="reco-list-add" class="btn btn--quiet" type="button">전체 담기</button>
              <button id="reco-list-new" class="btn btn--quiet" type="button">지금 줄로 리스트 만들기</button>
            </div>
          </div>
          <div class="table-wrap p-pick-wrap">
            <table class="files">
              <thead>
                <tr>
                  <th scope="col" class="p-col-check"><span class="sr-only">선택</span></th>
                  <th scope="col">품목</th><th scope="col" class="num">기본 수량</th>
                </tr>
              </thead>
              <tbody id="reco-list-body"></tbody>
            </table>
          </div>
        </section>
      </div>
    </div>
    <div class="modal__foot">
      <button class="btn btn--quiet" type="button" data-modal-close>닫기</button>
      <button id="reco-add" class="btn" type="button">담기</button>
    </div>
  </dialog>

<script src="/assets/purchase_common.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_common.js') ?>"></script>
<script src="/assets/purchase_docs.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_docs.js') ?>"></script>
<?php require __DIR__ . '/shell_bottom.php'; ?>
