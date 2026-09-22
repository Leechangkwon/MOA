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
        <!-- 공개 범위 배지 (4-B, DESIGN 2.2) — 한글 라벨을 보이고 원래 값은 `title` 에 둔다.
             색은 t-box 등급 배지의 뜻 그대로다: 비공개=`--admin-only`(빨강, 가장 좁다) ·
             팀=`--team`(보라) · 사내=`--internal`(파랑). 새 색값을 만들지 않았다. -->
        <span id="page-visibility" class="badge" hidden></span>
        <span id="page-editors" class="badge" hidden></span>
        <span id="page-save-state" class="badge" hidden></span>
        <button id="page-delete" class="btn btn--quiet btn--danger" type="button" hidden>
          <span class="icon" data-icon="trash"></span>삭제
        </button>
        <button id="page-move" class="btn btn--quiet" type="button" hidden>
          <span class="icon" data-icon="files"></span>옮기기
        </button>
        <!-- 「공개 범위」 (4-B). 창구가 준 `can_set_visibility` 가 참일 때만 보인다 —
             화면이 소유·역할을 따지지 않는다. 되살려 눌러도 서버가 다시 막는다 (PRD S-01). -->
        <button id="page-visibility-edit" class="btn btn--quiet" type="button" hidden>
          <span class="icon" data-icon="shield"></span>공개 범위
        </button>
        <!-- 「이력」 (4-C). 이 페이지를 **볼 수 있는 사람이면 누구나** 연다 — 읽기 판정과 같은
             자격이고(`decidePageRead`), 되돌리기 단추만 고칠 수 있는 사람에게 보인다.
             내용은 창구가 준 값으로 editor.js 가 채운다. -->
        <button id="page-history" class="btn btn--quiet" type="button">
          <span class="icon" data-icon="log"></span>이력
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
        <!-- 4-C — 되살리는 길이 생겼다(「내 공간」의 휴지통). 30일은 창구가 정한 값이고
             화면은 그 숫자를 되풀이해 적지 않는다(`GET /api/me/trash` 의 `keep_days`). -->
        <p class="muted">30일 안에는 「내 공간」의 휴지통에서 되살릴 수 있습니다. 하위 페이지도 함께 돌아옵니다.</p>
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

    <!-- 「공개 범위」 창 (4-B, DESIGN 2.7 `.modal--form` 520px · PRD 4.1).
         세 줄 라디오 + 「하위 페이지까지 함께」 체크 + 설명 한 줄이다. 값은 셋뿐이고
         「게시」는 없다 — 게시판 공간이 아직 없기 때문이다(5단계).
         t-box 의 「폴더 등급 바꾸기」 창(`src/handlers/home.php`)과 **같은 모양**을 쓴다:
         `.field field--full` 안에 입력을 넣고 설명을 라벨에 붙이는 방식이라 새 클래스가 0 이다. -->
    <dialog id="visibility-dialog" class="modal modal--form" aria-labelledby="visibility-title">
      <div class="modal__head">
        <h2 id="visibility-title" class="modal__title">공개 범위</h2>
        <button class="btn btn--icon btn--plain" type="button" data-visibility-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p class="muted">이 페이지를 누가 볼 수 있는지 고릅니다. 고치는 사람은 언제나 나 하나입니다.</p>
        <p id="visibility-msg" class="msg" role="alert" aria-live="polite"></p>

        <label class="field field--full">
          <span class="field__label">
            <input type="radio" name="page-visibility" value="private" checked>
            비공개 — 나와 관리자만 봅니다
          </span>
        </label>
        <label class="field field--full">
          <span class="field__label">
            <input type="radio" name="page-visibility" value="team">
            팀 — 내 팀 사람들이 봅니다
          </span>
        </label>
        <label class="field field--full">
          <span class="field__label">
            <input type="radio" name="page-visibility" value="internal">
            사내 — 로그인한 사내 구성원이 봅니다 (손님 제외)
          </span>
        </label>

        <label class="field field--full">
          <span class="field__label">
            <input id="visibility-cascade" type="checkbox">
            하위 페이지까지 함께 (더 넓게 열린 하위만 같이 좁힙니다)
          </span>
        </label>

        <p class="muted">하위 페이지는 상위보다 넓게 열 수 없습니다.</p>
      </div>
      <div class="form-actions">
        <button class="btn btn--quiet" type="button" data-visibility-close>그만두기</button>
        <button id="visibility-save" class="btn" type="button">바꾸기</button>
      </div>
    </dialog>

    <!-- 「이력」 창 (4-C, DESIGN 2.7 모달 960px · 2.4 표 · 2.13 줄 목록).
         탭 둘은 **단추 둘**이다 — 고른 쪽이 검정(`.btn`), 나머지가 흐림(`.btn--quiet`)이고
         `aria-selected` 로 읽어 주는 기계에도 알린다. 탭용 새 클래스를 만들지 않았다
         (DESIGN 원칙 6).
         · 「버전」 탭 — 판마다 한 줄인 표(`table.files`). 「보기」는 **이 창 안에서** 그 판을
           읽기 렌더러로 그리고, 「되돌리기」는 같은 창 안에서 한 번 더 묻는다
           (모달 안에 모달을 열지 않는다 — DESIGN 2.7).
         · 「처리 이력」 탭 — 판·체크를 시간순 한 줄씩(`.list`). 열이 정해져 있지 않아 표가
           아니라 줄 목록이다 (DESIGN 2.13).
         내용은 전부 editor.js 가 createElement/textContent 로 채운다. 본문 글자는 「보기」를
         눌렀을 때만, 창구 `GET …/versions/{num}` 을 지나 들어온다 (C:T-44). -->
    <dialog id="history-dialog" class="modal" aria-labelledby="history-title">
      <div class="modal__head">
        <h2 id="history-title" class="modal__title">이력</h2>
        <p id="history-meta" class="muted"></p>
        <button class="btn btn--icon btn--plain" type="button" data-history-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <div class="toolbar" role="tablist" aria-label="이력 종류">
          <button id="history-tab-versions" class="btn" type="button" role="tab"
                  aria-selected="true" aria-controls="history-versions">버전</button>
          <button id="history-tab-events" class="btn btn--quiet" type="button" role="tab"
                  aria-selected="false" aria-controls="history-events">처리 이력</button>
        </div>

        <p id="history-msg" class="msg" role="alert" aria-live="polite"></p>

        <!-- 되돌리기 확인 (DESIGN 4장 「되돌릴 수 없는 동작은 확인이 뒤따른다」) -->
        <div id="history-confirm" class="notice" role="status" hidden>
          <p id="history-confirm-text" class="notice__title"></p>
          <p class="muted">지금 내용은 새 판으로 남고, 지난 판도 그대로 남습니다. 지워지는 것은 없습니다.</p>
          <p>
            <button id="history-confirm-cancel" class="btn btn--quiet" type="button">그만두기</button>
            <button id="history-confirm-ok" class="btn btn--danger" type="button">되돌리기</button>
          </p>
        </div>

        <section id="history-versions" role="tabpanel" aria-labelledby="history-tab-versions">
          <div class="table-wrap">
            <table class="files">
              <thead>
                <tr><th>판</th><th>제목</th><th>바꾼 사람</th><th>시각</th><th>블록</th><th></th></tr>
              </thead>
              <tbody id="history-versions-body"></tbody>
            </table>
          </div>
          <p id="history-versions-empty" class="muted" hidden>아직 지난 판이 없습니다.</p>
        </section>

        <section id="history-events" role="tabpanel" aria-labelledby="history-tab-events" hidden>
          <ul id="history-events-list" class="list list--scroll"></ul>
          <p id="history-events-empty" class="muted" hidden>아직 남은 기록이 없습니다.</p>
        </section>

        <!-- 「보기」로 연 판의 본문. 읽기 렌더러(`renderDoc`)가 그리므로 화면과 실제가 같다. -->
        <section id="history-preview" hidden>
          <div class="toolbar">
            <p id="history-preview-title" class="nav-title"></p>
            <button id="history-preview-back" class="btn btn--quiet" type="button">목록으로</button>
          </div>
          <article id="history-preview-doc" class="panel"></article>
        </section>
      </div>
      <div class="form-actions">
        <button class="btn btn--quiet" type="button" data-history-close>닫기</button>
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
