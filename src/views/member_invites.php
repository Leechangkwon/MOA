<section class="panel moa-invite-panel" id="member-invites" data-base="<?= TBOX_BASE ?>">
<div class="panel__head"><h2 class="panel__title">초대 링크로 함께 시작하기</h2><p>링크를 받은 사람이 아이디·비밀번호·OTP를 직접 설정합니다. 일반 구성원 권한으로 가입합니다.</p></div>
<form id="invite-form" class="form form--grid"><label class="field"><span>초대 이름 / 사유</span><input id="invite-label" maxlength="80" placeholder="예: 새로 합류한 구성원" required></label><label class="field"><span>소속 팀</span><select id="invite-team"><option value="">팀 없음</option></select></label><label class="field"><span>가입 가능 인원</span><input id="invite-uses" type="number" min="1" max="100" value="1" required></label><label class="field"><span>유효기간 (일)</span><input id="invite-days" type="number" min="1" max="30" value="7" required></label><button type="submit" class="btn">초대 링크 만들기</button></form>
<div id="invite-result" hidden><p>이 링크를 초대할 사람에게 보내 주세요. 링크 원문은 이번에만 표시됩니다.</p><label class="field"><span>가입 링크</span><input id="invite-url" readonly></label><button id="invite-copy" class="btn btn--quiet" type="button">링크 복사</button></div><p id="invite-status" role="status" aria-live="polite"></p><div id="invite-list"></div>
<details><summary>tbox-buddy 설치 파일 연결</summary><p>실제 Windows 설치 파일의 HTTPS 다운로드 주소를 등록하면 가입 안내에 표시됩니다.</p><form id="installer-form" class="form"><label class="field"><span>다운로드 주소</span><input id="installer-url" type="url" placeholder="https://…" maxlength="2000"></label><button class="btn btn--quiet" type="submit">다운로드 주소 저장</button></form></details></section>
<section class="panel moa-invite-panel" id="member-ai-settings">
  <div class="panel__head"><p class="moa-kicker">MOA × GEMINI</p><h2 class="panel__title">모두에게 한 명씩, 모아 AI</h2><p>공용 API 키 하나로 구성원 각자가 AI와 대화합니다. 대화 기록은 계정별로 분리됩니다.</p></div>
  <form id="ai-settings-form" class="form form--grid" autocomplete="off">
    <label class="field"><span>Google AI Studio API 키</span><input id="ai-settings-key" type="password" autocomplete="new-password" spellcheck="false" maxlength="4096" placeholder="새 키 입력 · 비워 두면 기존 키 유지"><small id="ai-settings-key-state">등록 상태를 확인하고 있어요.</small></label>
    <label class="field"><span>AI 대화 사용</span><select id="ai-settings-enabled"><option value="false">잠시 끄기</option><option value="true">구성원에게 열기</option></select><small>모델: Gemini 3.5 Flash</small></label>
    <label class="field"><span>한 사람당 하루 요청 수</span><input id="ai-settings-user-limit" type="number" min="1" max="500" value="40" required></label>
    <label class="field"><span>사이트 전체 하루 요청 수</span><input id="ai-settings-site-limit" type="number" min="1" max="5000" value="300" required></label>
    <fieldset class="moa-image-settings">
      <legend>대화에 곁들이는 이미지 <span class="moa-ai-badge">유료 API</span></legend>
      <p class="moa-muted">Gemini 3.1 Flash Lite Image로 이미지를 만들고 확인한 뒤 보낼 수 있습니다. 무료 API 대상이 아니며, Google 프로젝트의 결제 설정이 필요합니다. 이미지 요청은 위의 전체 요청 한도에도 포함됩니다.</p>
      <div class="form form--grid">
        <label class="field"><span>이미지 생성 사용</span><select id="ai-settings-image-enabled"><option value="false">끄기 (기본)</option><option value="true">유료 이미지 생성 켜기</option></select></label>
        <label class="field"><span>한 사람당 하루 이미지 요청</span><input id="ai-settings-image-user-limit" type="number" min="1" max="20" value="3" required></label>
        <label class="field"><span>사이트 전체 하루 이미지 요청</span><input id="ai-settings-image-site-limit" type="number" min="1" max="200" value="20" required></label>
      </div>
    </fieldset>
    <button class="btn" type="submit">AI 연결 설정 저장</button>
  </form>
  <p id="ai-settings-status" role="status" aria-live="polite"></p>
  <p id="ai-settings-usage" class="moa-muted"></p>
  <p class="moa-muted">사용 횟수는 한국 시간 자정에 초기화됩니다. 실패하거나 응답을 못 받은 요청도 포함하며, 실제 요금은 Google의 토큰 사용량에 따라 달라집니다. API 키는 저장 후 다시 표시하지 않습니다.</p>
  <p class="moa-muted">Google 프로젝트의 한도·비용은 모든 사용자가 공유합니다. 무료 API에는 개인정보·기밀을 보내지 마세요. <a href="https://aistudio.google.com/api-keys" target="_blank" rel="noopener noreferrer">AI Studio에서 키 관리 ↗</a> · <a href="<?= Http::url('/reauth?to=users') ?>">관리자 인증 갱신</a></p>
</section>
<script defer src="<?= Http::url('/assets/member.js') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/member.js') ?>"></script>
