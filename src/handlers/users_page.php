<?php
declare(strict_types=1);

/**
 * src/handlers/users_page.php — GET /users (정책: user.manage → Admin 만, PRD 5.4)
 *
 * 요구사항: PRD F-10 · 10장 화면 요구사항("계정 관리 — 목록·생성·역할 변경, Admin 만"),
 *           S-03(출력 이스케이프), S-07("관리자 행동 사유"), SECURITY R-05 · R-12 · R-20
 *
 * 12단계(F-20)에서 공통 셸(`src/views/shell_*.php`)을 적용했다. 상단바·"파일 목록으로" 링크는
 * 좌측 내비가 대신하고, 본문 마크업·id·요청은 그대로다 — **서버 규칙·권한·API 는 바뀌지 않았다.**
 *
 * 이 화면이 HTML 에 넣는 동적 값은 공통 셸의 **사용자명·역할 두 개뿐**이며 둘 다 DB 에서 읽은
 * 값이고 htmlspecialchars(ENT_QUOTES) 를 거친다. 계정 목록은 서버가 HTML 로 만들지 않는다 —
 * app.js 가 `/api/users` 응답을 **textContent 로만** 그린다 (아이디도 사용자가 정한 문자열이다).
 *
 * ★ 이 파일에도 역할 비교를 두지 않는다. "Admin 만" 은 라우트 정책 `user.manage` 가 판단하고
 *   (Authz::canManageUsers), 화면은 여기까지 왔다는 사실 자체가 통과의 증거다 (PRD S-01).
 *   `/users` 화면과 `/api/users` 데이터는 같은 정책을 쓴다 — 화면만 숨기면 우회 경로가 된다.
 *
 * 사유(`reason_text`) 칸은 **화면에서 필수**이고, 비워 두면 서버도 400 으로 막는다
 * (PRD S-07 "관리자 행동 사유", SECURITY R-20 "확인 단계").
 *
 * 인라인 <script>·style 속성·on* 이벤트 속성을 쓰지 않는다 (CSP: script-src 'self').
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

$pageTitle = '계정 관리';
$activeNav = 'users';
$pageKey   = 'users';
/* 「사용 방법」 도움말의 키 — 글은 `src/help/screens.php` 한 벌이고 셸이 그린다 (PRD F-38, ARCH 5.19). */
$pageHelp  = 'users';
require __DIR__ . '/../views/shell_top.php';
?>
    <header class="page-head">
      <div class="page-head__text">
        <h1 class="page-head__title">계정 관리</h1>
        <p class="page-head__sub">계정 생성 · 이름·역할·팀·만료 변경 · 로그인 아이디 바꾸기 · 임시 비밀번호 재발급 · 2단계 인증 초기화</p>
      </div>
      <div class="page-head__actions">
        <button type="button" class="btn btn--quiet" data-help-open>
          <span class="icon" data-icon="help"></span><span>사용 방법</span>
        </button>
        <button id="user-create-open" class="btn" type="button"
                aria-haspopup="dialog" aria-controls="user-create-dialog">
          <span class="icon" data-icon="plus"></span>계정 만들기
        </button>
      </div>
    </header>

  <p class="help">계정은 1인 1개입니다. 역할 변경·비활성화·재활성화에는 사유가 필요하고, 역할·팀을 바꾸면 그 사람은 다시 로그인해야 합니다. 자기 자신의 역할·활성 상태는 바꿀 수 없고, 마지막 활성 관리자는 강등·비활성화되지 않습니다.</p>
  <p class="help">손님 계정은 만료일이 필수입니다. 계정은 지우지 않고 비활성화합니다. 한 계정에 할 일은 그 줄의 「관리」에서 고릅니다.</p>

  <!-- ─── 「계정 만들기」 모달 (D-10, DESIGN 2.7 · 2.1) ──────────────────────
       만들기 폼은 화면에 펼쳐 두지 않는다 — 화면 머리의 「계정 만들기」 단추가 이 모달을 열고,
       화면에는 목록(표)만 남는다. 마크업·id·요청은 그대로다: 폼 안의 칸도, `POST /api/users`
       본문도, 검증 문구도 바뀌지 않았다. 제출 단추가 폼 밖(.modal__foot)에 있으므로
       form="user-create-form" 로 잇는다. -->
  <dialog id="user-create-dialog" class="modal modal--form" aria-labelledby="user-create-title">
    <div class="modal__head">
      <span class="preview__mark"><span class="icon" data-icon="plus"></span></span>
      <div class="preview__headtext">
        <h2 class="modal__title" id="user-create-title">계정 만들기</h2>
        <!-- 메타는 한 줄이다. 전에는 ✕ 가 아랫줄로 밀려 `<br>` 로 끊어 두었는데,
             `.modal__head > .preview__headtext { flex: 1 1 16rem; min-width: 0 }` 규칙이
             app.css 에 들어오면서 묶음이 남은 폭 안에서 스스로 줄을 바꾼다 (D-10 2차 A). -->
        <div class="modal__meta"><span>손님은 만료일이 필수 · 비밀번호는 본인이 첫 로그인에서 정합니다</span></div>
      </div>
      <button id="user-create-dialog-x" class="btn btn--icon" type="button" aria-label="닫기">
        <span class="icon" data-icon="x"></span>
      </button>
    </div>
    <div class="modal__body">
      <form id="user-create-form" class="form form--grid" autocomplete="off">
        <label class="field">
          <span class="field__label">아이디 (소문자·숫자·<code>_ . -</code>, 3~64자)</span>
          <input id="u-username" type="text" maxlength="64" inputmode="latin"
                 autocapitalize="none" autocorrect="off" spellcheck="false" required>
        </label>
        <!-- 5-A″ — 이름(선택). 사이트 어디서나 「이름 (아이디)」로 보인다. 규칙은 서버 한 곳
             (`tbox_clean_display_name` — 앞뒤 공백 제거 뒤 1~40자, 제어·서식 문자 거부)이 정한다. -->
        <label class="field">
          <span class="field__label">이름 (선택 · 40자까지)</span>
          <input id="u-name" type="text" maxlength="40" autocomplete="off" spellcheck="false">
        </label>
        <!-- 11단계 (PRD F-16): 비밀번호 칸이 없다. 서버가 임시 비밀번호를 만들어 생성 응답에 한 번만 돌려주고,
             본인이 첫 로그인에서 바꾼다. Admin 이 비밀번호를 정하면 Admin 이 그 값을 계속 아는 상태가 남기 때문이다(R-39). -->
        <label class="field">
          <span class="field__label">역할</span>
          <select id="u-role">
            <option value="Employee">Employee</option>
            <option value="Manager">Manager</option>
            <option value="Guest">Guest</option>
            <option value="Admin">Admin</option>
          </select>
        </label>
        <label class="field">
          <span class="field__label">팀 (손님은 비움)</span>
          <select id="u-team"></select>
        </label>
        <label class="field">
          <span class="field__label">만료일 (손님은 필수)</span>
          <input id="u-expires" type="date">
        </label>
        <label class="field">
          <span class="field__label">사유 (필수 · 500자)</span>
          <input id="u-reason" type="text" maxlength="500" required>
        </label>
      </form>
    </div>
    <div class="modal__foot">
      <!-- 만들기가 서버에 막혔을 때의 문구 자리 (D-10 2차 B). 전에는 화면 맨 아래 공통
           `#msg` 에 썼는데, 모달이 열려 있는 동안에는 뒷면에 가려 보이지 않았다.
           문구는 **서버가 준 것 그대로**이고 app.js 가 textContent 로 넣는다 (PRD S-03). -->
      <p id="user-create-msg" class="msg" role="alert" aria-live="polite"></p>
      <button id="user-create-dialog-close" class="btn btn--quiet" type="button">닫기</button>
      <button id="u-submit" class="btn" type="submit" form="user-create-form">만들기</button>
    </div>
  </dialog>

  <section class="panel">
    <div class="panel__head"><h2 class="panel__title">계정 목록</h2></div>
    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr>
            <th scope="col">id</th>
            <th scope="col">이름 (아이디)</th>
            <th scope="col">역할</th>
            <th scope="col">팀</th>
            <th scope="col">활성</th>
            <th scope="col">만료</th>
            <th scope="col">2단계</th>
            <th scope="col">만든 시각</th>
            <th scope="col">작업</th>
          </tr>
        </thead>
        <tbody id="users-body"></tbody>
      </table>
    </div>
    <p id="users-empty" class="muted" hidden>표시할 계정이 없습니다.</p>

    <div class="table-foot">
      <span id="page-info" class="muted"></span>
      <div class="pager">
        <button id="page-prev" class="btn btn--quiet btn--small" type="button">이전</button>
        <button id="page-next" class="btn btn--quiet btn--small" type="button">다음</button>
      </div>
    </div>
  </section>

  <!-- ─── 계정 「관리」 상세 창 (디버깅 묶음 U2 M8 · 2026-09-26, DESIGN 2.7 `.modal--form` · 2.3 `.card--link`) ──────────
       전에는 줄마다 글자 단추 다섯(이름·역할·팀·만료 · 임시 비밀번호 재발급 · 2단계 인증 초기화 · 비활성화 · 프로그램 토큰)이 있어
       폴드 820 에서도 잘렸고 표가 단추로 가득했다. 이제 줄에는 「관리」 하나, 이 창에 그 계정의 사실과 **기존 동작 그대로**를 둔다.
       동작을 고르면 이 창을 **닫고** 그 동작의 창(계정 변경 · 사유 · 프로그램 토큰)을 연다 — 창 안에 창을 겹쳐 열지 않는다(2.7).
       창구·권한·요청은 그대로다: 무엇을 할 수 있는지는 여전히 `PATCH /api/users/{uid}` 등이 Authz 로 다시 정한다(PRD S-01).
       값(아이디·이름·역할·팀·만료)은 app.js 가 `/api/users` 응답에서 textContent 로만 채운다 (PRD S-03). -->
  <dialog id="user-manage-dialog" class="modal modal--form" aria-labelledby="um-title">
    <div class="modal__head">
      <span class="preview__mark"><span class="icon" data-icon="users"></span></span>
      <div class="preview__headtext">
        <h2 class="modal__title" id="um-title">계정 관리</h2>
        <div class="modal__meta"><span id="um-sub"></span></div>
      </div>
      <button id="um-x" class="btn btn--icon" type="button" aria-label="닫기">
        <span class="icon" data-icon="x"></span>
      </button>
    </div>
    <div class="modal__body">
      <div id="user-manage-facts" class="info-grid"></div>
      <p id="um-self" class="help" hidden>내 계정의 이름·아이디·역할·팀·상태는 여기서 바꿀 수 없습니다(다른 관리자가 바꿉니다). 프로그램 토큰은 보고 회수할 수 있습니다.</p>
      <div class="card-stack">
        <button class="card card--link" type="button" data-user-action="edit">
          <span class="card__icon"><span class="icon" data-icon="edit"></span></span>
          <span class="card__body">
            <strong class="card__title">이름·역할·팀·만료 바꾸기</strong>
            <span class="card__desc">사유가 필요합니다. 역할·팀을 바꾸면 그 사람은 다시 로그인해야 합니다.</span>
          </span>
        </button>
        <!-- d74 (2026-09-27) — 로그인 아이디 바꾸기. 고르면 이 창을 닫고 아이디 창(#user-rename)을 연다(2.7 — 겹쳐 열지 않는다).
             본인 줄에서는 다른 바꾸기처럼 숨는다(서버 self_change). 검토 계정·공용 토큰 귀속 계정은 서버가 막고 그 창에 이유를 말한다. -->
        <button class="card card--link" type="button" data-user-action="username">
          <span class="card__icon"><span class="icon" data-icon="users"></span></span>
          <span class="card__body">
            <strong class="card__title">로그인 아이디 바꾸기</strong>
            <span class="card__desc">사유가 필요합니다. 비밀번호·2단계 인증·로그인 상태는 그대로이고, 그 사람은 다음 로그인부터 새 아이디를 씁니다.</span>
          </span>
        </button>
        <button class="card card--link" type="button" data-user-action="password">
          <span class="card__icon"><span class="icon" data-icon="key"></span></span>
          <span class="card__body">
            <strong class="card__title">임시 비밀번호 재발급</strong>
            <span class="card__desc">새 임시 비밀번호를 한 번만 보여 주고, 그 계정의 모든 세션과 프로그램 토큰을 끊습니다.</span>
          </span>
        </button>
        <button class="card card--link" type="button" data-user-action="mfa">
          <span class="card__icon"><span class="icon" data-icon="shield"></span></span>
          <span class="card__body">
            <strong class="card__title">2단계 인증 초기화</strong>
            <span class="card__desc">인증 앱을 잃었을 때. 그 계정의 프로그램 토큰도 끊고, 다음 로그인에서 다시 등록합니다.</span>
          </span>
        </button>
        <button class="card card--link" type="button" data-user-action="tokens">
          <span class="card__icon"><span class="icon" data-icon="link"></span></span>
          <span class="card__body">
            <strong class="card__title">프로그램 토큰</strong>
            <span class="card__desc">이 계정의 프로그램 토큰을 보고 회수합니다. 발급은 본인만 「내 계정」에서 합니다.</span>
          </span>
        </button>
        <button class="card card--link card--warn" type="button" data-user-action="active">
          <span class="card__icon"><span class="icon" data-icon="lock"></span></span>
          <span class="card__body">
            <strong class="card__title" id="um-active-title">비활성화</strong>
            <span class="card__desc" id="um-active-desc">그 사람은 바로 로그인할 수 없게 됩니다. 사유가 필요하고, 다시 활성화할 수 있습니다.</span>
          </span>
        </button>
      </div>
    </div>
    <div class="modal__foot">
      <button id="um-close" class="btn btn--quiet" type="button">닫기</button>
    </div>
  </dialog>

  <!-- 26단계 계정 변경 모달 (PRD F-40, 사용자 지적 2026-09-15 「변경했는데 로그에 없다」).
       전에는 `window.prompt` 를 여러 번(역할→팀 번호→만료일→사유) 띄웠다.
       하나라도 취소하면 app.js 가 **조용히 return** 했고, 화면에 아무 알림이 없어 사용자는
       「바꿨다」고 느꼈지만 요청은 한 번도 나가지 않았다(감사 로그 0건이 그 증거였다).
       이제 폼 하나로 받고, 취소·무변경·실패까지 전부 #msg 로 말한다.

       ★ 마크업만 서버가 낸다. 선택지의 **값**(팀 이름, 현재 선택)은 app.js 가 API 응답에서
         받아 textContent·value 로만 채운다 — 서버가 HTML 로 만들지 않는다 (PRD S-03).
       ★ 여기에도 권한 판단이 없다. 무엇을 바꿀 수 있는지는 `PATCH /api/users/{uid}` 가
         Authz::decideUserChange 로 다시 정한다(자기 자신 금지·마지막 Admin 보호 — PRD S-01, T-22). -->
  <dialog id="user-edit" class="modal modal--form" aria-labelledby="ue-title">
    <form id="user-edit-form" class="form" autocomplete="off">
      <div class="modal__head">
        <h2 class="modal__title" id="ue-title">계정 변경</h2>
        <button id="ue-x" class="btn btn--icon" type="button" aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <!-- 5-A″ — 이름. 비우고 저장하면 이름이 지워진다(아이디만 보인다). -->
        <label class="field">
          <span class="field__label">이름 (40자까지 · 비우면 아이디만)</span>
          <input id="ue-name" type="text" maxlength="40" autocomplete="off" spellcheck="false">
        </label>
        <label class="field">
          <span class="field__label">역할</span>
          <select id="ue-role">
            <option value="Admin">Admin</option>
            <option value="Manager">Manager</option>
            <option value="Employee">Employee</option>
            <option value="Guest">Guest</option>
          </select>
        </label>
        <label class="field">
          <span class="field__label">팀</span>
          <select id="ue-team"><option value="">— 팀 없음 —</option></select>
        </label>
        <label class="field">
          <span class="field__label">만료일 (손님은 필수 · 비우면 그대로)</span>
          <input id="ue-expires" type="date">
        </label>
        <label class="field">
          <!-- `required` 를 두지 않는다: 브라우저 기본 검사가 먼저 막으면 **submit 이벤트 자체가
               일어나지 않아**, 「바뀐 칸이 없습니다」 를 알릴 기회가 사라진다(아무 칸도 바꾸지 않은
               채 저장을 누른 경우). 비었는지는 아래 app.js 가 보고, 서버도 400 으로 다시 막는다. -->
          <span class="field__label">사유 (필수 · 500자)</span>
          <textarea id="ue-reason" rows="3" maxlength="500"></textarea>
        </label>
        <p id="ue-msg" class="msg" role="alert" aria-live="polite"></p>
      </div>
      <div class="modal__foot">
        <button id="ue-cancel" class="btn btn--quiet" type="button">닫기</button>
        <button id="ue-submit" class="btn" type="submit">저장</button>
      </div>
    </form>
  </dialog>

  <!-- d74 로그인 아이디 바꾸기 창 (DESIGN 2.7 `.modal--form` — 바닥 오른쪽 끝 주요 단추 하나 · 그 왼쪽 「닫기」 · 머리 ✕).
       `PATCH /api/users/{uid}` 에 `username` + `reason_text` 만 보낸다. 규칙(형식·이미 있음·검토 접두·검토 계정·공용 토큰)은
       서버 한 곳(`_username_common.php`)이 정하고, 막히면 **서버 문구 그대로** 이 창 안에 말한다(창을 닫지 않는다 — 고쳐 다시 보낸다).
       새 아이디는 입력칸 값으로만 있고 창이 닫히면 비운다 — 브라우저 입력 기억(autocomplete)도 끈다. -->
  <dialog id="user-rename" class="modal modal--form" aria-labelledby="ur-title">
    <form id="user-rename-form" class="form" autocomplete="off">
      <div class="modal__head">
        <h2 class="modal__title" id="ur-title">로그인 아이디 바꾸기</h2>
        <button id="ur-x" class="btn btn--icon" type="button" aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p class="muted">그 사람은 다음 로그인부터 새 아이디를 씁니다. 비밀번호·2단계 인증·지금 로그인한 기기·프로그램 토큰은 그대로입니다. 인증 앱에 보이는 계정 이름은 옛 아이디로 남지만 코드는 그대로 맞습니다.</p>
        <label class="field">
          <span class="field__label">새 아이디 (소문자·숫자·<code>_ . -</code>, 3~64자)</span>
          <input id="ur-username" type="text" maxlength="64" inputmode="latin" autocomplete="off"
                 autocapitalize="none" autocorrect="off" spellcheck="false">
        </label>
        <label class="field">
          <span class="field__label">사유 (필수 · 500자)</span>
          <textarea id="ur-reason" rows="3" maxlength="500"></textarea>
        </label>
        <p id="ur-msg" class="msg" role="alert" aria-live="polite"></p>
      </div>
      <div class="modal__foot">
        <button id="ur-cancel" class="btn btn--quiet" type="button">닫기</button>
        <button id="ur-submit" class="btn" type="submit">아이디 바꾸기</button>
      </div>
    </form>
  </dialog>

  <!-- 사유만 받는 모달 — 임시 비밀번호 재발급 · 2단계 인증 초기화 · 비활성화/재활성화.
       세 가지가 같은 형식을 쓰는 이유는 하나다: 계정 화면에서 `window.prompt` 를 0개로 만든다.
       사유가 비면 화면이 먼저 막고, 비워 보내도 서버가 400 으로 막는다 (PRD S-07, SECURITY R-20). -->
  <dialog id="reason-dialog" class="modal modal--form" aria-labelledby="rd-title">
    <form id="reason-form" class="form" autocomplete="off">
      <div class="modal__head">
        <h2 class="modal__title" id="rd-title">사유</h2>
        <button id="rd-x" class="btn btn--icon" type="button" aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p id="rd-lead" class="muted"></p>
        <label class="field">
          <span class="field__label">사유 (필수 · 500자)</span>
          <textarea id="rd-reason" rows="3" maxlength="500"></textarea>
        </label>
        <p id="rd-msg" class="msg" role="alert" aria-live="polite"></p>
      </div>
      <div class="modal__foot">
        <button id="rd-cancel" class="btn btn--quiet" type="button">닫기</button>
        <button id="rd-submit" class="btn" type="submit">진행하기</button>
      </div>
    </form>
  </dialog>

<?php require __DIR__ . '/../views/shell_bottom.php'; ?>
