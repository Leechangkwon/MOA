<?php
declare(strict_types=1);

/** Session/initial-login gates are enforced by the MCP route dispatcher. */
$memberTitle = 'ChatGPT 연결';
$memberPage = 'mcp-connect';
require __DIR__ . '/../../views/member_top.php';
?>
<div id="mcp-connect" class="mcp-connect-layout">
  <header class="page-head">
    <div class="page-head__text">
      <h1 class="page-head__title">ChatGPT에 모아 연결하기</h1>
      <p class="page-head__sub">ChatGPT에서 내 글·사진을 살펴보고, 허용한 경우 글·여행 계획·업무 문서를 갱신합니다.</p>
    </div>
  </header>
  <p>원격 MCP(외부 도구 연결)를 사용합니다. AI 모델은 ChatGPT에서 선택하고 실행하므로 이 연결에는 OpenAI API 키가 필요하지 않습니다.</p>
  <p id="member-status" role="status" aria-live="polite">로그인과 연결 설정을 확인하고 있습니다…</p>
  <p id="mcp-error" class="msg" role="alert" hidden></p>
  <p id="mcp-login-help" class="notice notice--recommend" hidden>로그인 상태를 다시 확인해 주세요. <a href="<?= Http::url('/login') ?>?to=<?= rawurlencode(Http::url('/mcp-connect')) ?>">다시 로그인하기</a>. 승인 요청이 끝났다면 ChatGPT에서 연결을 다시 시작하세요.</p>
  <noscript><p class="notice">연결 상태 확인과 승인을 위해 JavaScript를 켠 뒤 다시 열어 주세요.</p></noscript>

  <div class="grid-2">
    <section class="panel span-all" aria-labelledby="mcp-ready-title">
      <div class="panel__head">
        <h2 id="mcp-ready-title" class="panel__title">연결 준비</h2>
        <p class="panel__sub">현재 로그인한 모아 계정에만 연결합니다.</p>
      </div>
      <div class="info-grid">
        <div class="info-item"><span class="field__label">내 계정</span><strong id="mcp-account" class="info-item__value">확인 중</strong></div>
        <div class="info-item"><span class="field__label">서버 상태</span><span id="mcp-readiness" class="info-item__value">확인 중</span></div>
      </div>
      <p id="mcp-readiness-note" class="help">서버 설정 확인은 실제 ChatGPT 연결 확인과 다릅니다.</p>
      <label class="field"><span class="field__label">ChatGPT에 입력할 연결 주소</span><input id="mcp-resource" type="url" readonly spellcheck="false" autocomplete="off" aria-describedby="mcp-copy-help" value=""></label>
      <div class="form-actions"><button id="mcp-copy" class="btn btn--quiet" type="button" disabled>연결 주소 복사하기</button><button id="mcp-refresh" class="btn btn--quiet" type="button" disabled>상태 다시 확인하기</button></div>
      <p id="mcp-copy-help" class="help">복사하는 것은 공개 연결 주소 하나입니다. 비밀번호·키·프로그램 토큰을 붙여 넣지 마세요.</p>
      <details id="mcp-metadata" hidden><summary>연결 검색 정보 주소 보기</summary><p class="help">ChatGPT가 서버의 로그인 방식을 찾는 공개 주소입니다. 표시되는 주소는 서버 설정 기준이며, 외부에서의 접속 여부는 여기서 검증하지 않습니다.</p><ul class="list"><li class="list__item"><a id="mcp-resource-metadata" target="_blank" rel="noopener noreferrer">보호된 리소스 정보</a></li><li class="list__item"><a id="mcp-oauth-metadata" target="_blank" rel="noopener noreferrer">로그인 서버 정보</a></li></ul></details>
    </section>

    <section id="mcp-consent" class="panel span-all" aria-labelledby="mcp-consent-title" hidden>
      <div class="panel__head"><h2 id="mcp-consent-title" class="panel__title">연결 권한 승인</h2><p class="panel__sub"><strong id="mcp-client"></strong>에서 연결을 요청했습니다. 앱이 요청한 범위 안에서 필요한 권한만 선택하세요.</p></div>
      <p>연결할 계정: <strong id="mcp-consent-account"></strong></p>
      <form id="mcp-consent-form" class="form">
        <fieldset class="panel panel--tight"><legend>ChatGPT에 허용할 권한</legend><div id="mcp-scopes" class="form"></div></fieldset>
        <p id="mcp-write-note" class="notice notice--recommend" hidden>쓰기 권한을 허용하면 ChatGPT가 내 글·여행 계획 또는 편집 권한이 있는 업무 문서를 저장할 수 있습니다. 저장 전에 내용을 읽고 확인하도록 요청하세요. 이미 공유한 글이나 업무 문서를 수정하면 함께 보는 사람에게도 변경 내용이 보입니다.</p>
        <p class="help">사진은 따로 허용해야 합니다. 이 승인 자체로 글·사진·여행 계획을 저장하거나 공개하지 않습니다. 언제든 아래 연결 목록에서 해제할 수 있습니다.</p>
        <p id="mcp-consent-expiry" class="help"></p>
        <div class="form-actions"><button id="mcp-deny" class="btn btn--quiet" type="button">허용하지 않기</button><button id="mcp-approve" class="btn" type="submit">선택한 권한으로 연결 승인하기</button></div>
      </form>
    </section>

    <section class="panel" aria-labelledby="mcp-guide-title">
      <div class="panel__head"><h2 id="mcp-guide-title" class="panel__title">ChatGPT에서 시작하기</h2><p class="panel__sub">먼저 위 서버 상태가 준비됨인지 확인하세요.</p></div>
      <ol class="notice__list"><li>연결 주소를 복사합니다.</li><li>ChatGPT의 설정에서 앱·커넥터 또는 개발자 모드의 사용자 지정 연결 추가 기능을 찾습니다.</li><li>원격 MCP 서버 주소에 복사한 주소를 입력하고 연결을 시작합니다.</li><li>모아 로그인 화면이 열리면 로그인한 계정과 요청 권한을 확인하고 승인합니다.</li><li>ChatGPT로 돌아가 이 연결을 선택한 뒤 “내 최근 글을 보여 줘”처럼 읽기부터 확인합니다.</li></ol>
      <p class="notice notice--recommend">ChatGPT의 메뉴 이름·제공 요금제·조직 관리자 설정에 따라 추가 방법이 달라집니다. 모바일 앱과 모바일 브라우저의 연결 추가·사용 가능 여부는 실제 계정에서 확인해야 합니다. 이 화면만으로 휴대폰 지원을 확인할 수는 없습니다.</p>
      <p class="help">휴대폰에 연결 추가 메뉴가 없다면 ChatGPT 웹 설정도 확인하세요. 메뉴가 없거나 조직에서 막았다면 해당 계정에서는 연결을 추가할 수 없을 수 있습니다.</p>
    </section>

    <section class="panel" aria-labelledby="mcp-scope-guide-title">
      <div class="panel__head"><h2 id="mcp-scope-guide-title" class="panel__title">권한별로 가능한 일</h2><p class="panel__sub">읽기가 기본입니다. 사진·쓰기·여행은 승인 화면에서 선택합니다.</p></div>
      <ul class="list">
        <li class="list__item"><strong>내 글 읽기</strong><div class="list__body">내가 쓴 글의 목록과 본문을 읽습니다.</div></li>
        <li class="list__item"><strong>내 사진 읽기</strong><div class="list__body">내 글에 현재 첨부된 사진의 축소본을 ChatGPT로 보냅니다. 원본 파일 주소는 제공하지 않습니다.</div></li>
        <li class="list__item"><strong>내 글 쓰기</strong><div class="list__body">새 비공개 글을 만들거나 내 글을 수정합니다. 삭제·공개 범위 변경은 포함하지 않습니다.</div></li>
        <li class="list__item"><strong>여행 계획 읽기·쓰기</strong><div class="list__body">읽기와 저장 권한을 따로 고릅니다. 저장 권한에는 여행 계획 읽기가 필요합니다.</div></li>
      </ul>
      <p class="help">허용한 내용은 요청할 때 ChatGPT로 전달됩니다. 동영상은 정보만 읽으며, 사진 편집이나 동영상 분석을 제공하는 연결은 아닙니다.</p>
    </section>

    <section class="panel span-all" aria-labelledby="mcp-connections-title">
      <div class="panel__head"><h2 id="mcp-connections-title" class="panel__title">내가 승인한 연결</h2><p class="panel__sub">모아에 기록된 승인 목록입니다. ChatGPT의 현재 온라인 상태를 뜻하지 않습니다. 연결을 해제하면 이후 접근을 차단하며 모아의 원본 글·사진은 유지됩니다.</p></div>
      <p id="mcp-connections-empty" class="muted" hidden>승인한 연결이 없습니다. ChatGPT에서 연결 추가를 시작하세요.</p>
      <ul id="mcp-connections" class="list" hidden></ul>
      <p class="help">연결 해제는 이미 ChatGPT에 전달된 내용을 삭제하지 않습니다. 필요한 경우 ChatGPT에서도 해당 대화·연결을 관리하세요.</p>
    </section>
  </div>
  <p><a href="<?= Http::url('/settings') ?>">내 계정으로 돌아가기</a></p>
</div>
</main></body></html>
