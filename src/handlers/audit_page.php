<?php
declare(strict_types=1);

/**
 * src/handlers/audit_page.php — GET /audit (정책: audit.read → Admin 만, PRD 5.4 Q-8)
 *
 * 요구사항: PRD F-09 · 10장(감사 로그 화면), S-07 "조회 화면 필터", ARCH 7.6, SECURITY 1.4
 *
 * 12단계(F-20)에서 공통 셸(`src/views/shell_*.php`)을 적용했다. 상단바·"파일 목록으로" 링크는
 * 좌측 내비가 대신하고, 본문 마크업·id·요청은 그대로다 — **서버 규칙·권한·API 는 바뀌지 않았다.**
 *
 * 이 화면이 HTML 에 넣는 동적 값은 공통 셸의 **사용자명·역할 두 개뿐**이며 둘 다 DB 에서 읽은
 * 값이고 htmlspecialchars(ENT_QUOTES) 를 거친다 (PRD S-03). 감사 로그 행은 서버가 HTML 로 만들지 않는다 —
 * app.js 가 `/api/audit` 응답을 **textContent 로만** 그린다.
 * `summary`·`target_name`·`user_agent`·`detail` 은 공격자가 값을 정할 수 있는 문자열이므로
 * (파일명·UA) 다른 사용자 입력과 똑같이 다룬다 (SECURITY 1.4 보완, ARCH 7.4 마지막 규칙).
 *
 * ★ 이 파일에도 역할 비교를 두지 않는다. "Admin 만" 은 라우트 정책 `audit.read` 가 판단하고
 *   (Authz::canReadAudit), 화면은 여기까지 왔다는 사실 자체가 통과의 증거다 (PRD S-01).
 *
 * 인라인 <script>·style 속성·on* 이벤트 속성을 쓰지 않는다 (CSP: script-src 'self').
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

$pageTitle = '감사 로그';
$activeNav = 'audit';
$pageKey   = 'audit';
/* 「사용 방법」 도움말의 키 — 글은 `src/help/screens.php` 한 벌이고 셸이 그린다 (PRD F-38, ARCH 5.19). */
$pageHelp  = 'audit';
require __DIR__ . '/../views/shell_top.php';
?>
    <header class="page-head">
      <div class="page-head__text">
        <h1 class="page-head__title">감사 로그</h1>
        <p class="page-head__sub">누가 · 언제 · 어디서 · 무엇을 · 어떻게 · 왜 — 여섯 가지로 찾습니다.</p>
      </div>
      <div class="page-head__actions">
        <button type="button" class="btn btn--quiet" data-help-open>
          <span class="icon" data-icon="help"></span><span>사용 방법</span>
        </button>
      </div>
    </header>

  <p class="help">ip·기기 정보는 개인정보이며 보안 사고 조사·감사 목적으로만 봅니다.</p>

  <section class="panel">
    <div class="panel__head"><h2 class="panel__title">필터 — 누가 · 언제 · 어디서 · 무엇을 · 어떻게 · 왜</h2></div>
    <form id="audit-filter" class="form form--grid" autocomplete="off">
      <label class="field">
        <span class="field__label">누가 — 계정명 또는 로그인 회차(12자)</span>
        <input id="f-actor" type="text" maxlength="64" placeholder="claude_employee01 · 3f2a1b0c9d8e">
      </label>
      <label class="field">
        <span class="field__label">언제 — 시작</span>
        <input id="f-from" type="datetime-local">
      </label>
      <label class="field">
        <span class="field__label">언제 — 끝</span>
        <input id="f-to" type="datetime-local">
      </label>
      <label class="field">
        <span class="field__label">어디서 — IP</span>
        <input id="f-ip" type="text" maxlength="45" placeholder="127.0.0.1">
      </label>
      <label class="field">
        <span class="field__label">무엇을 — 대상 이름(부분 일치)</span>
        <input id="f-target" type="text" maxlength="200">
      </label>
      <label class="field">
        <span class="field__label">무엇을 — 등급</span>
        <select id="f-level">
          <option value="">(전체)</option>
          <option value="public">공개</option>
          <option value="internal">사내</option>
          <option value="team">팀</option>
          <option value="admin-only">관리자</option>
        </select>
      </label>
      <label class="field">
        <span class="field__label">어떻게 — 행동</span>
        <select id="f-action">
          <option value="">(전체)</option>
          <option value="auth.login">auth.login</option>
          <option value="auth.logout">auth.logout</option>
          <option value="auth.required">auth.required</option>
          <option value="csrf">csrf</option>
          <option value="authz.route">authz.route</option>
          <option value="file.upload">file.upload</option>
          <option value="file.fetch">file.fetch</option>
          <option value="file.download">file.download</option>
          <option value="file.delete">file.delete</option>
          <option value="file.update">file.update</option>
          <option value="file.level_change">file.level_change</option>
          <option value="file.restore">file.restore</option>
          <option value="audit.view">audit.view</option>
          <!-- 9단계 운영 배치 — cron 이 남기는 행. actor_* 가 비어 있어
               「누가」로는 찾을 수 없고 여기(어떻게)로 골라야 보인다. -->
          <option value="file.purge">file.purge</option>
          <option value="backup.run">backup.run</option>
          <option value="backup.restore">backup.restore</option>
          <option value="audit.integrity">audit.integrity</option>
          <option value="alert.raised">alert.raised</option>
          <!-- 10단계 계정 관리·본인 계정 (F-10, R-05, R-12).
               이 목록은 서버 audit_list.php 의 $allowedActions 와 **함께** 늘어난다 —
               한쪽만 늘리면 선택지가 있는데 걸리지 않거나, 걸리는데 선택지가 없다. -->
          <option value="user.create">user.create</option>
          <option value="user.role_change">user.role_change</option>
          <option value="user.expiry_change">user.expiry_change</option>
          <option value="user.update">user.update</option>
          <option value="user.deactivate">user.deactivate</option>
          <option value="user.activate">user.activate</option>
          <option value="user.expired">user.expired</option>
          <option value="auth.password_change">auth.password_change</option>
          <option value="auth.mfa">auth.mfa</option>
          <option value="auth.mfa_enroll">auth.mfa_enroll</option>
          <option value="auth.mfa_disable">auth.mfa_disable</option>
          <!-- 11단계 (F-16) -->
          <option value="auth.gate">auth.gate</option>
          <option value="user.password_reset">user.password_reset</option>
          <option value="user.mfa_reset">user.mfa_reset</option>
          <!-- 15-2 공유 링크 (F-13). share.download 는 actor_* 가 비어 있어
               「누가」로는 찾을 수 없고 여기(어떻게)로 골라야 보인다 — 발급자는 상세의 issued_by. -->
          <option value="share.create">share.create</option>
          <option value="share.revoke">share.revoke</option>
          <option value="share.download">share.download</option>
          <!-- 디버깅 묶음 S2 검토 L-4 — 잠금이 걸린 순간(auth.lock) · 관리 기능 재인증(auth.reauth, 16-1 — 전에 빠져 있었다) -->
          <option value="auth.lock">auth.lock</option>
          <option value="auth.reauth">auth.reauth</option>
        </select>
      </label>
      <label class="field">
        <span class="field__label">왜 — 결과</span>
        <select id="f-result">
          <option value="">(전체)</option>
          <option value="allow">allow</option>
          <option value="deny">deny</option>
          <option value="error">error</option>
        </select>
      </label>
      <label class="field">
        <span class="field__label">왜 — 거부 사유(reason)</span>
        <input id="f-reason" type="text" maxlength="40" placeholder="team_mismatch">
      </label>
      <label class="field">
        <span class="field__label">왜 — 허용 근거(granted_by)</span>
        <input id="f-granted" type="text" maxlength="40" placeholder="owner">
      </label>
      <button id="audit-submit" class="btn" type="submit">조회</button>
      <button id="audit-reset" class="btn btn--quiet" type="button">필터 지우기</button>
    </form>
  </section>

  <section class="panel">
    <div class="panel__head"><h2 class="panel__title">기록</h2></div>
    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr>
            <th scope="col">시각</th>
            <th scope="col">누가</th>
            <th scope="col">역할</th>
            <th scope="col">행동</th>
            <th scope="col">대상</th>
            <th scope="col">등급</th>
            <th scope="col">결과</th>
            <th scope="col">사유 / 근거</th>
            <th scope="col">IP</th>
            <th scope="col">요약</th>
            <th scope="col">상세</th>
          </tr>
        </thead>
        <tbody id="audit-body"></tbody>
      </table>
    </div>
    <p id="audit-empty" class="muted" hidden>조건에 맞는 기록이 없습니다.</p>

    <div class="table-foot">
      <span id="page-info" class="muted"></span>
      <div class="pager">
        <button id="page-prev" class="btn btn--quiet btn--small" type="button">이전</button>
        <button id="page-next" class="btn btn--quiet btn--small" type="button">다음</button>
      </div>
    </div>
  </section>

<?php require __DIR__ . '/../views/shell_bottom.php'; ?>
