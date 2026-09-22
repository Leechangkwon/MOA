<?php
declare(strict_types=1);

/**
 * src/community/handlers/page_view.php — GET /community/p/{id} (정책: community.read)
 *
 * 요구사항: `C:F-15`·`C:F-16`, `DESIGN.md` 2.9 · 2.14 · 6장,
 *           `../../../../community/ARCHITECTURE.md` 12.4 · 13.3 · 14장 · 21.3.
 *
 * 읽기 모드와 편집 모드가 **같은 화면**이다 (ARCH 14.3). 이 파일은 뼈대만 그리고,
 * 본문은 `assets/community/editor.js` 가 `GET /community/api/pages/{id}` 로 받아
 *   · 읽기 → `doc-render.js` 의 `renderDoc()` (createElement/textContent 만)
 *   · 편집 → ProseMirror (우리 스키마의 노드·마크만 등록한 것)
 * 로 그린다.
 *
 * ★ **이 화면은 판단하지 않는다** (PRD S-01). 페이지를 볼 수 있는지·고칠 수 있는지는
 *   창구가 매 요청 `CommunityAuthz` 로 판단하고, 화면은 그 답(403·404·`can_edit`)을 따를 뿐이다.
 *   그래서 여기서 페이지를 읽지도, 권한을 보지도 않는다 — 감사 행이 화면과 창구에서 두 번
 *   남는 일도 없다.
 * ★ HTML 로 나가는 동적 값은 **페이지 id 하나**이고, 그 값은 라우터의 자리표시자 `{id}` 가
 *   UUID 36자만 통과시킨 뒤 여기서 다시 `htmlspecialchars` 를 거친다 (PRD S-03).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 페이지 public_id (UUID)
 */

$viewPageId = (string) $params[0];

$pageTitle   = '페이지';
$activeNav   = 'community-page';
$pageKey     = 'community-page';
$pageHelp    = 'community-page';
$pageCss     = ['community/community.css'];
$shellModule = 'community';
require __DIR__ . '/../../views/shell_top.php';
?>
    <!-- 경로(breadcrumb) — 뿌리부터 이 페이지까지 (4-A, DESIGN 2.14 `.crumb`).
         줄의 내용은 editor.js 가 createElement/textContent 로 채운다. -->
    <nav id="page-crumb" class="crumb" aria-label="페이지 경로" hidden></nav>

    <header class="page-head">
      <div class="page-head__text">
        <!-- 제목은 창구가 준 값을 editor.js 가 textContent 로 넣는다(편집 모드에서는 입력 칸). -->
        <h1 id="page-title" class="page-head__title">페이지</h1>
        <p id="page-sub" class="page-head__sub"></p>
      </div>
      <div class="page-head__actions">
        <button type="button" class="btn btn--quiet" data-help-open>
          <span class="icon" data-icon="help"></span><span>사용 방법</span>
        </button>
        <span id="page-editors" class="badge" hidden></span>
        <span id="page-save-state" class="badge" hidden></span>
        <button id="page-delete" class="btn btn--quiet btn--danger" type="button" hidden>
          <span class="icon" data-icon="trash"></span>삭제
        </button>
        <button id="page-move" class="btn btn--quiet" type="button" hidden>
          <span class="icon" data-icon="files"></span>옮기기
        </button>
        <button id="page-edit" class="btn" type="button" hidden>
          <span class="icon" data-icon="edit"></span>편집
        </button>
        <button id="page-add-block" class="btn btn--quiet" type="button" hidden>
          <span class="icon" data-icon="plus"></span>블록 추가
        </button>
        <button id="page-done" class="btn" type="button" hidden>
          <span class="icon" data-icon="check"></span>편집 끝
        </button>
      </div>
    </header>

    <p id="msg" class="msg" role="alert" aria-live="polite"></p>

    <!-- 제목 고치기 (편집 모드에서만 보인다) -->
    <label class="field" id="page-title-field" hidden>
      <span class="field__label">제목</span>
      <input id="page-title-input" type="text" maxlength="200" autocomplete="off" spellcheck="false">
    </label>

    <!-- 읽기 모드의 본문이 들어오는 자리 (renderDoc 의 결과) -->
    <article id="page-doc" class="panel" aria-live="polite"></article>

    <!-- 글자를 고르면 뜨는 줄 — 「색」 「배경」 「링크」 셋뿐이다 (지시서 1장).
         굵게·기울임·밑줄·취소선·인라인 코드는 만들지 않는다(사용자 결정 2026-09-23). -->
    <div id="inline-toolbar" class="panel editor-toolbar" hidden role="toolbar" aria-label="글자 색과 링크"></div>

    <!-- 편집 모드의 본문이 들어오는 자리 (ProseMirror 가 자기 DOM 을 그린다) -->
    <div id="page-editor" class="panel" hidden></div>

    <!-- 하위 페이지 (4-A). 「하위 페이지 만들기」는 언제나 보이고(고칠 수 있을 때),
         목록은 있을 때만 보인다. 카드는 editor.js 가 `.card--link` 로 만든다. -->
    <section id="page-children-wrap" hidden>
      <div class="toolbar">
        <p class="nav-title">하위 페이지</p>
        <span id="page-children-count" class="muted"></span>
        <button id="page-new-child" class="btn btn--quiet" type="button" hidden>
          <span class="icon" data-icon="plus"></span>하위 페이지 만들기
        </button>
      </div>
      <div id="page-children" class="card-grid"></div>
      <p id="page-children-empty" class="muted" hidden>아직 하위 페이지가 없습니다.</p>
    </section>

    <!-- 409 모달 (ARCH 14.3 「다른 곳에서 수정됨」). 내용은 고정 문구뿐이다. -->
    <dialog id="conflict-dialog" class="modal" aria-labelledby="conflict-title">
      <div class="modal__head">
        <h2 id="conflict-title" class="modal__title">다른 곳에서 수정됨</h2>
        <button class="btn btn--icon btn--plain" type="button" data-conflict-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p>이 페이지는 다른 창이나 다른 기기에서 먼저 저장되었습니다. 어떻게 할지 고르세요.</p>
        <p class="muted">「새로 고침」은 지금 서버에 있는 내용을 가져옵니다 — 이 창에서 쓴 내용은 사라집니다.
           「내 것으로 덮어쓰기」는 이 창의 내용을 새 판으로 저장합니다.</p>
      </div>
      <div class="form-actions">
        <button id="conflict-reload" class="btn btn--quiet" type="button">새로 고침</button>
        <button id="conflict-overwrite" class="btn" type="button">내 것으로 덮어쓰기</button>
      </div>
    </dialog>

    <!-- 삭제 확인 (DESIGN 2.7 · 4장 「되돌릴 수 없는 동작은 확인이 뒤따른다」) -->
    <dialog id="delete-dialog" class="modal" aria-labelledby="delete-title">
      <div class="modal__head">
        <h2 id="delete-title" class="modal__title">페이지를 지울까요?</h2>
        <button class="btn btn--icon btn--plain" type="button" data-delete-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p>이 페이지와 하위 페이지가 목록에서 사라집니다.</p>
        <p class="muted">되살리는 화면은 아직 없습니다. 지우기 전에 한 번 더 확인하세요.</p>
      </div>
      <div class="form-actions">
        <button class="btn btn--quiet" type="button" data-delete-close>그만두기</button>
        <button id="delete-confirm" class="btn btn--danger" type="button">지우기</button>
      </div>
    </dialog>

    <!-- 「옮기기」 창 (4-A, DESIGN 2.7 모달 · 2.14 `.move-tree`).
         내 공간의 트리를 한 번(`GET /api/pages?tree=1`)에 받아 그린다. 자기 자신과 자기
         하위는 고를 수 없게 빼 두지만, **서버가 다시 판단한다**(`decidePageMove` — cycle).
         목록 내용은 전부 editor.js 가 createElement/textContent 로 채운다. -->
    <dialog id="move-dialog" class="modal" aria-labelledby="move-title">
      <div class="modal__head">
        <h2 id="move-title" class="modal__title">페이지 옮기기</h2>
        <button class="btn btn--icon btn--plain" type="button" data-move-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p class="muted">새 상위 페이지를 고르세요. 하위 페이지도 함께 따라갑니다.</p>
        <p id="move-msg" class="msg" role="alert" aria-live="polite"></p>
        <div id="move-tree" class="move-tree" role="listbox" aria-label="새 상위 페이지"></div>
      </div>
      <div class="form-actions">
        <button class="btn btn--quiet" type="button" data-move-close>그만두기</button>
        <button id="move-confirm" class="btn" type="button">여기로 옮기기</button>
      </div>
    </dialog>

    <!-- 「블록 추가」 창 (DESIGN 2.7 모달 · 2.14). 왼쪽 목록에서 고르면 오른쪽에 미리보기가
         그려진다 — 미리보기는 읽기 렌더러 `renderDoc` 이 그리므로 견본과 실제가 어긋나지 않는다.
         목록·미리보기 내용은 전부 `editor.js` 가 createElement/textContent 로 채운다. -->
    <dialog id="block-dialog" class="modal" aria-labelledby="block-dialog-title">
      <div class="modal__head">
        <h2 id="block-dialog-title" class="modal__title">블록 추가</h2>
        <button class="btn btn--icon btn--plain" type="button" data-block-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body block-picker">
        <ul id="block-list" class="list" role="listbox" aria-label="블록 종류"></ul>
        <div class="block-picker__detail">
          <p id="block-preview-name" class="panel__title"></p>
          <p id="block-desc" class="muted"></p>
          <div id="block-sample" class="block-picker__sample"></div>
        </div>
      </div>
      <div class="form-actions">
        <button class="btn btn--quiet" type="button" data-block-close>닫기</button>
        <button id="block-insert" class="btn" type="button">넣기</button>
      </div>
    </dialog>


    <!-- 페이지 id 를 전하는 자리. 값은 라우터가 UUID 로만 통과시킨 뒤 이스케이프한 것이다. -->
    <span id="page-meta" hidden data-page-id="<?= htmlspecialchars($viewPageId, ENT_QUOTES, 'UTF-8') ?>"></span>
<?php
$pageMsgTop = true;
require __DIR__ . '/../../views/shell_bottom.php';
