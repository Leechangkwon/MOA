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
        <button id="page-done" class="btn" type="button" hidden>
          <span class="icon" data-icon="check"></span>편집 끝
        </button>
      </div>
    </header>

    <!-- 속성 줄 (4-D, C:F-37) — 제목 바로 아래다.
         배지 자체는 **누를 수 없다**(DESIGN 2.2 「눌러도 아무 일도 없다」) — 누르는 것은
         배지를 담은 작은 단추이고, 고칠 수 없는 사람에게는 그 단추가 `disabled` 다.
         값도 잠금도 전부 창구가 준 것(`status`·`owner`·`can_set_properties`·`can_handover`)이고,
         화면에는 소유·역할을 따지는 코드가 없다 (PRD S-01, DESIGN 원칙 1). -->
    <div id="page-props" class="page-props" hidden>
      <span class="page-props__group">
        <span class="muted">상태</span>
        <button id="page-status" class="btn btn--small btn--quiet" type="button" aria-haspopup="dialog">
          <span id="page-status-badge" class="badge">없음</span>
        </button>
      </span>
      <!-- 「소유자」 (5-A — 4-D 의 「담당자」 자리). 이 페이지가 든 **공간의 주인**이고, 팀 공간이면
           「팀 공간」이다. 「넘기기」는 창구가 준 `can_handover` 가 참일 때만 보인다 — 화면이
           소유·역할을 따지지 않는다. 되살려 눌러도 `POST …/handover` 가 같은 함수로 다시 막는다. -->
      <span class="page-props__group">
        <!-- 5-C — 게시판 글에서는 「쓴 사람」으로 바뀐다(글자는 editor.js 가 창구의 `writer` 로 고른다) -->
        <span id="page-owner-label" class="muted">소유자</span>
        <span id="page-owner-name">…</span>
        <button id="page-handover" class="btn btn--small btn--quiet" type="button" aria-haspopup="dialog" hidden>
          <span class="icon" data-icon="users"></span>넘기기
        </button>
      </span>
      <!-- 공개 범위·이력은 머리글에서 이 줄로 옮겼다 (사용자 지시 2026-09-23 — 문서의 속성끼리 한 줄에).
           공개 범위 배지 (4-B, DESIGN 2.2) — 한글 라벨을 보이고 원래 값은 `title` 에 둔다.
           색은 t-box 등급 배지의 뜻 그대로다: 비공개=`--admin-only`(빨강, 가장 좁다) ·
           팀=`--team`(보라) · 사내=`--internal`(파랑). 새 색값을 만들지 않았다.
           「바꾸기」는 창구가 준 `can_set_visibility` 가 참일 때만 보인다 — 화면이 소유·역할을
           따지지 않는다. 되살려 눌러도 서버가 다시 막는다 (PRD S-01). -->
      <span class="page-props__sep" aria-hidden="true"></span>
      <span class="page-props__group">
        <span class="muted">공개 범위</span>
        <span id="page-visibility" class="badge" hidden></span>
        <button id="page-visibility-edit" class="btn btn--small btn--quiet" type="button" aria-haspopup="dialog" hidden>
          <span class="icon" data-icon="shield"></span>바꾸기
        </button>
        <!-- 5-A — 공유받은 사람에게는 받은 권한이 보이고, 같은 창(공유 부분만)을 열어 자기 공유를 뺄 수 있다.
             5-A″ — 팀 공간의 페이지에서는 관리할 수 있는 팀원에게 같은 단추가 「사람 추가」로 보인다
             (팀 공간에는 공개 범위가 없다 — 창에는 공유 부분만 열린다). 글자는 editor.js 가 고른다. -->
        <span id="page-share-mine" class="badge" hidden></span>
        <button id="page-share-open" class="btn btn--small btn--quiet" type="button" aria-haspopup="dialog" hidden>
          <span class="icon" data-icon="users"></span><span id="page-share-open-label">공유 보기</span>
        </button>
      </span>
      <!-- 「이력」 (4-C). 이 페이지를 **볼 수 있는 사람이면 누구나** 연다 — 읽기 판정과 같은
           자격이고(`decidePageRead`), 되돌리기 단추만 고칠 수 있는 사람에게 보인다. -->
      <!-- 게시판 (5-C) — 이 페이지가 걸린 게시판(게시판 글이면 그 게시판, 게시했으면 게시한 게시판).
           「게시」·「게시 해제」·「숨기기」·「숨김 풀기」·「해결됨」은 창구가 준 `can_publish`·`can_unpublish`·
           `can_hide`·`can_resolve` 가 참일 때만 보인다 — 화면이 소유·역할을 따지지 않는다(PRD S-01). -->
      <span class="page-props__group" id="page-board-group" hidden>
        <span class="muted">게시판</span>
        <a id="page-board-link" href="#">게시판</a>
        <span id="page-board-hidden" class="badge badge--off" hidden>숨김</span>
        <span id="page-resolved-badge" class="badge" hidden>미해결</span>
      </span>
      <button id="page-publish" class="btn btn--small btn--quiet" type="button" aria-haspopup="dialog" hidden>
        <span class="icon" data-icon="upload"></span>게시
      </button>
      <button id="page-unpublish" class="btn btn--small btn--quiet" type="button" hidden>게시 해제</button>
      <button id="page-hide" class="btn btn--small btn--quiet" type="button" hidden>숨기기</button>
      <button id="page-resolve" class="btn btn--small btn--quiet" type="button" hidden>해결됨으로 표시</button>
      <!-- 태그 (5-C) — 칩 + 입력 칸. 추천은 `<datalist>`(브라우저 기능 — 떠 있는 목록을 새로 만들지 않는다,
           DESIGN 2.8 ★). 칩의 ✕ 와 입력 칸은 창구가 준 `can_tag` 가 참일 때만 보인다. -->
      <span class="page-props__sep" aria-hidden="true"></span>
      <span class="page-props__group" id="page-tags-group">
        <span class="muted">태그</span>
        <span id="page-tags"></span>
        <input id="page-tag-input" type="text" maxlength="30" autocomplete="off" spellcheck="false"
               list="page-tag-options" placeholder="태그 추가" aria-label="태그 추가 — Enter 로 붙입니다" hidden>
        <datalist id="page-tag-options"></datalist>
      </span>
      <span id="page-views" class="muted" hidden></span>
      <span class="page-props__sep" aria-hidden="true"></span>
      <button id="page-history" class="btn btn--small btn--quiet" type="button" aria-haspopup="dialog">
        <span class="icon" data-icon="log"></span>이력
      </button>
    </div>

    <p id="msg" class="msg" role="alert" aria-live="polite"></p>

    <!-- 제목 고치기 (편집 모드에서만 보인다) -->
    <label class="field" id="page-title-field" hidden>
      <span class="field__label">제목</span>
      <input id="page-title-input" type="text" maxlength="200" autocomplete="off" spellcheck="false">
    </label>

    <!-- 읽기 모드의 본문이 들어오는 자리 (renderDoc 의 결과) -->
    <article id="page-doc" class="panel" aria-live="polite"></article>

    <!-- 편집하는 동안 본문 위에 붙어 있는 줄 — 「블록 추가」 + 「색」 「배경」 「링크」 (지시서 1장).
         굵게·기울임·밑줄·취소선·인라인 코드는 만들지 않는다(사용자 결정 2026-09-23).
         「블록 추가」가 머리글이 아니라 여기 있는 것은 긴 글에서 위로 오르내리지 않게 하려는 것이다
         (사용자 지적 2026-09-23). 줄은 sticky 라 스크롤해도 화면 위에 남는다. 단추는 editor.js 가
         줄의 맨 앞으로 옮긴다. -->
    <div id="inline-toolbar" class="panel editor-toolbar" hidden role="toolbar" aria-label="블록 추가와 글자 꾸미기">
      <button id="page-add-block" class="btn btn--small btn--quiet" type="button" hidden>
        <span class="icon" data-icon="plus"></span>블록 추가
      </button>
    </div>

    <!-- 편집 모드의 본문이 들어오는 자리 (ProseMirror 가 자기 DOM 을 그린다) -->
    <div id="page-editor" class="panel" hidden></div>

    <!-- 빈 페이지 안내 (2026-09-23 사용자 요청 「제목·목차를 모르면 못 쓴다 — 기본 블록 구성을 보여 주자」).
         문서가 비어 있을 때만 편집기 **아래**에 보인다 — 위에 두면 첫 글자를 칠 때 사라지며 본문이 밀린다.
         단축키 줄은 editor.js 가 `BLOCK_TYPES` 에서 채운다(창의 표시와 한 곳에서 나온다). -->
    <section id="editor-starter" class="panel editor-starter" hidden aria-labelledby="editor-starter-title">
      <p id="editor-starter-title" class="panel__title">빈 페이지입니다 — 줄마다 블록 하나로 씁니다</p>
      <p class="muted">자주 쓰는 블록은 줄 맨 앞에서 글자를 치면 바뀝니다. 나머지는 위 「블록 추가」에서 고릅니다.</p>
      <ul id="editor-starter-keys" class="editor-starter__keys"></ul>
      <div class="editor-starter__actions">
        <button id="editor-starter-apply" class="btn" type="button">
          <span class="icon" data-icon="plus"></span>기본 구성 넣기
        </button>
        <span class="muted">제목 · 목차 · 개요 · 할 일 · 메모 뼈대를 넣습니다. 글자는 고쳐 쓰면 됩니다.</span>
      </div>
    </section>

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
         세 줄 라디오 + 「하위 페이지까지 함께」 체크 + 설명 한 줄이다. 값은 셋뿐이다 —
         게시(5-C)는 이 창이 아니라 속성 줄의 「게시」 단추(게시판 고르기)로 한다.
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
        <div id="visibility-choices">
        <p class="muted">이 페이지를 누가 볼 수 있는지 고릅니다. 고치는 사람은 나와, 아래에서 「편집」으로 고른 사람입니다.</p>
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
            하위 페이지까지 함께 (하위 페이지 전부를 같은 공개 범위로 맞춥니다)
          </span>
        </label>

        <p class="muted">하위 페이지는 상위보다 넓게 열 수 없습니다.</p>
        </div>

        <!-- 「사람 추가」 (5-A, PRD NG-8 해제). 공개 범위 위에 **더하는** 권한이다 — 아이디로 찾아
             보기·편집을 고른다. 후보는 창구 `GET /api/share-candidates` 가, 목록은
             `GET /api/pages/{id}/shares` 가 준 것만 그린다(화면이 누가 볼 수 있는지 따지지 않는다).
             추가·등급 바꾸기·빼기는 **누르는 즉시** 창구로 간다(아래 「바꾸기」는 공개 범위만 바꾼다).
             새 클래스 0 — `.field`·`.toolbar`·`.list`·`.badge` 는 DESIGN 2장의 구성 요소다. -->
        <section id="share-section" aria-labelledby="share-title">
          <p id="share-title" class="field__label">사람 추가</p>
          <p id="share-msg" class="msg" role="alert" aria-live="polite"></p>
          <div id="share-add" class="toolbar">
            <input id="share-search" type="search" placeholder="아이디나 이름으로 찾기" maxlength="64"
                   autocomplete="off" spellcheck="false" aria-label="공유할 사람 찾기">
            <select id="share-level" aria-label="줄 권한">
              <option value="view">보기</option>
              <option value="edit">편집</option>
            </select>
          </div>
          <!-- 5-A″ — 후보는 한 글자부터 나온다(창구도 빈 입력에는 빈 목록). 치기 전에는 이 안내만 보인다. -->
          <p id="share-hint" class="muted" hidden>아이디나 이름을 입력하세요</p>
          <ul id="share-candidates" class="list list--scroll" aria-label="찾은 사람" hidden></ul>
          <p id="share-count" class="muted"></p>
          <ul id="share-list" class="list" aria-label="공유한 사람"></ul>
          <p class="muted">공유는 하위 페이지에도 미칩니다. 「편집」으로 받은 사람도 지우기·옮기기·공개 범위·공유·넘기기는 못 합니다.</p>
          <p>
            <button id="share-leave" class="btn btn--quiet btn--small btn--danger" type="button" hidden>내 공유 빼기</button>
          </p>
        </section>
      </div>
      <div class="form-actions">
        <button class="btn btn--quiet" type="button" data-visibility-close>닫기</button>
        <button id="visibility-save" class="btn" type="button">바꾸기</button>
      </div>
    </dialog>

    <!-- 「소유자 넘기기」 창 (5-A 인계, DESIGN 2.7 `.modal--form` · PRD 4.1 「인계(F-28)」).
         받는 사람 찾기 + 「기존 소유자에게 남길 권한」 라디오 셋. **라디오에 기본 선택이 없다**
         (사용자 결정 2) — 받는 사람과 남길 권한을 둘 다 고르기 전에는 「넘기기」가 눌리지 않는다.
         후보는 창구 `GET /api/share-candidates?for=handover` 가 준 것만이고(손님·쓰지 않는 계정 제외),
         서버가 다시 판단한다(`decidePageHandover`·`decideHandoverTarget`). -->
    <dialog id="handover-dialog" class="modal modal--form" aria-labelledby="handover-title">
      <div class="modal__head">
        <h2 id="handover-title" class="modal__title">소유자 넘기기</h2>
        <button class="btn btn--icon btn--plain" type="button" data-handover-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p id="handover-lead" class="muted">이 페이지와 하위 페이지가 받는 사람의 공간 맨 위로 옮겨 갑니다.</p>
        <p id="handover-msg" class="msg" role="alert" aria-live="polite"></p>

        <label class="field field--full">
          <span class="field__label">받는 사람</span>
          <input id="handover-search" type="search" placeholder="아이디나 이름으로 찾기" maxlength="64"
                 autocomplete="off" spellcheck="false">
        </label>
        <!-- 5-A″ — 받는 사람도 한 글자부터 찾는다(창을 열자마자 사람 목록을 통째로 보이지 않는다). -->
        <p id="handover-hint" class="muted">아이디나 이름을 입력하세요</p>
        <ul id="handover-candidates" class="list list--scroll" aria-label="받을 수 있는 사람"></ul>
        <p id="handover-picked" class="muted">받는 사람을 아직 고르지 않았습니다.</p>

        <p class="field__label">기존 소유자에게 남길 권한</p>
        <label class="field field--full">
          <span class="field__label">
            <input type="radio" name="handover-keep" value="none"> 없음 — 넘긴 뒤에는 볼 수 없습니다
          </span>
        </label>
        <label class="field field--full">
          <span class="field__label">
            <input type="radio" name="handover-keep" value="view"> 보기 — 「공유받은 페이지」에서 읽기만 합니다
          </span>
        </label>
        <label class="field field--full">
          <span class="field__label">
            <input type="radio" name="handover-keep" value="edit"> 편집 — 내용을 계속 고칠 수 있습니다
          </span>
        </label>
        <p class="muted">내용·이력·체크 기록·작성자 이름은 그대로 따라갑니다.</p>
      </div>
      <div class="form-actions">
        <button class="btn btn--quiet" type="button" data-handover-close>그만두기</button>
        <button id="handover-confirm" class="btn btn--danger" type="button" disabled>넘기기</button>
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
          <p id="block-keys" class="block-picker__keys"></p>
          <div id="block-sample" class="block-picker__sample"></div>
        </div>
      </div>
      <div class="form-actions">
        <button class="btn btn--quiet" type="button" data-block-close>닫기</button>
        <button id="block-insert" class="btn" type="button">넣기</button>
      </div>
    </dialog>


    <!-- 「상태」 창 (4-D, DESIGN 2.7 `.modal--form` 520px · 2.2 배지).
         라디오 여섯 — 「없음」 + 다섯 값이다. 「없음」이 기본이고 맨 위에 있다:
         모든 페이지가 업무는 아니다. 글자와 배지 색은 DESIGN 2.2 의 뜻 그대로이고
         새 색값을 만들지 않았다(회색·파랑·보라·초록·노랑 토큰을 그대로 쓴다). -->
    <dialog id="status-dialog" class="modal modal--form" aria-labelledby="status-title">
      <div class="modal__head">
        <h2 id="status-title" class="modal__title">상태</h2>
        <button class="btn btn--icon btn--plain" type="button" data-status-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p class="muted">이 페이지가 업무라면 지금 어디까지 왔는지 고릅니다. 업무가 아니면 「없음」으로 둡니다.</p>
        <p id="status-msg" class="msg" role="alert" aria-live="polite"></p>

        <label class="field field--full">
          <span class="field__label">
            <input type="radio" name="page-status" value="" checked> 없음 — 업무가 아닙니다
          </span>
        </label>
        <label class="field field--full">
          <span class="field__label">
            <input type="radio" name="page-status" value="todo"> 할 일 — 아직 시작하지 않았습니다
          </span>
        </label>
        <label class="field field--full">
          <span class="field__label">
            <input type="radio" name="page-status" value="doing"> 진행 — 하고 있습니다
          </span>
        </label>
        <label class="field field--full">
          <span class="field__label">
            <input type="radio" name="page-status" value="review"> 검토 — 다 하고 확인을 기다립니다
          </span>
        </label>
        <label class="field field--full">
          <span class="field__label">
            <input type="radio" name="page-status" value="done"> 완료 — 끝났습니다
          </span>
        </label>
        <label class="field field--full">
          <span class="field__label">
            <input type="radio" name="page-status" value="hold"> 보류 — 멈춰 두었습니다
          </span>
        </label>
      </div>
      <div class="form-actions">
        <button class="btn btn--quiet" type="button" data-status-close>그만두기</button>
        <button id="status-save" class="btn" type="button">바꾸기</button>
      </div>
    </dialog>

    <!-- 「게시판에 게시」 창 (5-C, DESIGN 2.7 `.modal--form`). 고를 수 있는 게시판은 창구 `GET /api/boards` 가
         `can_write` 로 답한 것만이다(화면이 등급을 따지지 않는다). 창구가 다시 판단한다(`decideBoardWrite`). -->
    <dialog id="publish-dialog" class="modal modal--form" aria-labelledby="publish-title">
      <div class="modal__head">
        <h2 id="publish-title" class="modal__title">게시판에 게시</h2>
        <button class="btn btn--icon btn--plain" type="button" data-publish-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p class="muted">이 페이지를 고른 게시판 목록에 겁니다. 원본은 내 공간에 그대로 있고, 고치면 게시판에도 그대로 보입니다.
           게시하는 동안은 그 게시판을 볼 수 있는 사람이 이 페이지를 봅니다. 하위 페이지는 따라가지 않습니다.</p>
        <p id="publish-msg" class="msg" role="alert" aria-live="polite"></p>
        <div id="publish-choices"></div>
        <p id="publish-empty" class="muted" hidden>글을 쓸 수 있는 게시판이 없습니다.</p>
      </div>
      <div class="form-actions">
        <button class="btn btn--quiet" type="button" data-publish-close>닫기</button>
        <button id="publish-save" class="btn" type="button" disabled>게시</button>
      </div>
    </dialog>

    <!-- 페이지 id 를 전하는 자리. 값은 라우터가 UUID 로만 통과시킨 뒤 이스케이프한 것이다. -->
    <span id="page-meta" hidden data-page-id="<?= htmlspecialchars($viewPageId, ENT_QUOTES, 'UTF-8') ?>"></span>
<?php
$pageMsgTop = true;
require __DIR__ . '/../../views/shell_bottom.php';
