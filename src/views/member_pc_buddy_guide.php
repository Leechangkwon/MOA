<div class="moa-setup-heading">
  <p class="moa-kicker">PC ASSISTANT</p>
  <h1>PC 비서 연결하기</h1>
  <p>비서를 설치하고, PC에서 ChatGPT로 로그인한 뒤 웹에서 한 번 승인하세요.</p>
</div>
<p id="member-status" role="status" aria-live="polite"></p>
<p><strong>같은 Windows PC에서 진행해 주세요.</strong> 휴대폰에서는 이 안내를 읽을 수 있지만, 다른 PC의 비서에 연결할 수는 없어요.</p>
<nav class="moa-setup-actions" aria-label="PC 비서 연결 순서">
  <a href="#pc-install">1. 설치·업데이트</a><a href="#pc-login">2. PC에서 로그인</a><a href="#pc-connect">3. 웹 연결</a><a href="#pc-use">4. 사용하기</a>
</nav>
<section class="moa-setup-card" id="pc-install">
  <span class="moa-step-number">01</span><h2>PC 비서를 준비하세요</h2>
  <p>배포함에서 <strong>update.3 이상</strong> ZIP을 받으세요. 다운로드 확인란을 체크한 뒤 다운로드 버튼을 누릅니다.</p>
  <p><a class="btn" href="<?= Http::url('/programs') ?>" target="_blank" rel="noopener noreferrer">AI 비서 다운로드 · 새 탭</a></p>
  <p><strong>ZIP 파일을 우클릭해 ‘압축 풀기’ 또는 ‘모두 추출’을 먼저 하세요.</strong> 압축 파일 안에서 바로 실행하면 필요한 파일을 찾지 못할 수 있어요.</p>
  <details open><summary>이미 쓰던 PC 비서가 있어요 — 업데이트</summary>
    <ol>
      <li>작업 표시줄 오른쪽의 <strong>숨겨진 아이콘 표시(∧)</strong>를 열고, 비서 아이콘을 우클릭해 <strong>종료</strong>합니다.</li>
      <li>새로 압축을 푼 폴더에서 <code>update.cmd</code>를 실행합니다.</li>
      <li>폴더 선택창에서는 <strong>원래 쓰던 비서의 설치 폴더</strong>를 고릅니다. <code>app.py</code>와 <code>tbox</code> 폴더가 있는 곳이에요. 새로 받은 ZIP 폴더를 고르는 것이 아닙니다.</li>
      <li>업데이트 완료 후 <strong>기존 설치 폴더</strong>의 <code>setup.cmd</code>를 실행해 필요한 구성요소를 설치합니다. 완료되면 기존 바로가기 또는 그 폴더의 <code>run.cmd</code>로 실행합니다.</li>
    </ol>
    <p class="moa-muted">업데이트 도구가 변경 전 소스를 백업합니다. 기존 설정·업무 파일·바로가기는 유지됩니다.</p>
  </details>
  <details><summary>이 PC에 처음 설치해요</summary>
    <ol>
      <li>압축을 푼 폴더를 계속 사용할 위치에 둡니다. 예: 문서 폴더 안의 <code>tbox-buddy</code>.</li>
      <li><code>setup.cmd</code>를 실행하고 <strong>Setup complete</strong>가 나올 때까지 기다립니다. Python 3.10 이상과 Tcl/Tk가 설치된 Windows PC가 필요해요.</li>
      <li>같은 폴더의 <code>run.cmd</code>를 실행합니다. 처음 설치할 때는 <code>update.cmd</code>를 쓰지 않습니다.</li>
    </ol>
  </details>
  <p><strong>다음 단계로 가기 전:</strong> 작업 표시줄의 ∧ 안에 비서 아이콘이 보이는지 확인하세요. 실행할 때 파일 탐색 창이 먼저 열릴 수 있습니다.</p>
</section>
<section class="moa-setup-card" id="pc-login">
  <span class="moa-step-number">02</span><h2>PC 비서에서 ChatGPT로 로그인하세요</h2>
  <ol>
    <li>작업 표시줄 오른쪽 ∧ → <strong>비서 아이콘 우클릭</strong> → <strong>개인 ChatGPT · 텍스트 / 연결 설정</strong>을 누릅니다.</li>
    <li>열린 PC 창 위쪽의 <strong>계정 연결 · 설정</strong> 탭을 선택합니다.</li>
    <li><strong>Continue with ChatGPT</strong>를 누르고, 브라우저에서 사용하려는 ChatGPT 계정으로 로그인을 마칩니다.</li>
    <li>PC 창으로 돌아와 계정 목록에 본인 계정이 표시되는지 확인합니다. 사용 권한이 없다고 나오면 해당 창의 안내에 따라 구독 사용 동의를 확인하세요.</li>
  </ol>
  <p><strong>웹에 ChatGPT 토큰을 붙여 넣을 필요는 없어요.</strong> 모아 로그인과 ChatGPT 로그인은 각각 한 번씩 필요합니다.</p>
  <details><summary>로그인이 실제로 되는지 먼저 확인하고 싶어요</summary><p>PC 창의 <strong>연결 시험</strong> 탭 → <strong>연결 시험 · 짧은 답변 1회</strong>를 누르세요. 짧은 응답 완료까지 확인하면 됩니다. 계정 요금제나 사용 가능한 크레딧이 적용됩니다.</p></details>
</section>
<section class="moa-setup-card" id="pc-connect">
  <span class="moa-step-number">03</span><h2>웹에서 GPT 연결을 누르세요</h2>
  <p>PC 비서를 켜 둔 상태에서, <strong>같은 PC의 브라우저</strong>로 아래 순서를 따라가세요.</p>
  <p><a class="btn" href="<?= Http::url('/messages') ?>" target="_blank" rel="noopener noreferrer">웹 메신저 열기 · 새 탭</a></p>
  <ol>
    <li>메신저에서 <strong>동료 한 명의 대화</strong>를 엽니다. 연결만 할 때는 메시지를 보내지 않아도 됩니다.</li>
    <li>메시지 입력칸 위의 <strong>✦ AI 도구</strong>를 누릅니다.</li>
    <li><strong>GPT · 내 ChatGPT</strong>를 선택하고 <strong>GPT 연결</strong>을 누릅니다.</li>
    <li>PC 비서의 <strong>모아 웹 연결 확인</strong> 창에서 모아 주소를 확인하고 브라우저 기억을 승인합니다. 브라우저가 로컬 네트워크 접근을 물으면 허용하세요.</li>
  </ol>
  <p><strong>완료 표시:</strong> 웹 AI 도구에 <strong>PC 연결됨</strong>이 보이면 연결된 상태입니다.</p>
  <p class="moa-muted">‘모아 AI’ 대화는 기본 Gemini입니다. 현재 웹의 GPT 연결 버튼은 동료 대화의 문장 도구 안에 있습니다.</p>
  <details><summary>PC 승인창이 보이지 않아요</summary>
    <p>작업 표시줄에서 개인 ChatGPT 창을 확인하세요. 비서가 꺼져 있다면 아래 실행 버튼을 누르고 웹에서 GPT 연결을 다시 누르세요.</p>
    <p><a class="btn btn--quiet" href="tbox-buddy://connect">PC 비서 실행</a></p>
    <p>버튼이 열리지 않으면 업데이트한 설치 폴더의 <code>run.cmd</code>를 한 번 실행하세요. 실행 링크는 앱을 실행할 때 등록됩니다. 그래도 연결되지 않으면 PC 창의 <strong>모아 웹 연결 → 간편 연결 켜기</strong>를 누른 뒤 다시 시도하세요.</p>
  </details>
</section>
<section class="moa-setup-card" id="pc-use">
  <span class="moa-step-number">04</span><h2>문장을 처리하고 결과를 확인하세요</h2>
  <ol>
    <li>웹 AI 도구에서 <strong>문장 다듬기·맞춤법·번역</strong> 중 하나를 고르고 원문을 입력합니다.</li>
    <li><strong>GPT로 처리</strong>를 누르면 연결한 PC가 개인 ChatGPT 계정으로 처리합니다.</li>
    <li>결과를 읽고 <strong>작성란에 넣기</strong>를 누릅니다. 동료에게 보낼 때는 별도로 <strong>보내기</strong>를 누르세요.</li>
  </ol>
  <p>승인한 브라우저는 최대 30일 기억합니다. 다음에도 PC 비서가 켜져 있으면 웹에서 GPT를 선택해 다시 연결할 수 있어요. PC 창을 닫아도 비서는 트레이에서 대기합니다.</p>
  <p>PC에서 GPT와 직접 대화하려면 파일 팔레트의 <strong>AI</strong> 단추 또는 <kbd>Ctrl+J</kbd> → 공급자 <strong>ChatGPT</strong>를 선택하세요. 팔레트는 기본 <kbd>Ctrl+Shift+Space</kbd> 또는 트레이의 <strong>빠른 비서 · 파일/폴더 탐색</strong>으로 엽니다.</p>
</section>
<section class="moa-setup-card" id="pc-help">
  <h2>막히는 지점이 있나요?</h2>
  <details><summary>‘모듈을 찾을 수 없다’거나 실행하자마자 꺼져요</summary><p>ZIP을 모두 풀었는지 확인하고, 실제 실행할 설치 폴더에서 <code>setup.cmd</code>를 다시 실행하세요. <strong>Setup complete</strong> 후 같은 폴더의 <code>run.cmd</code>를 사용합니다. 설치 실패가 나오면 화면의 오류를 확인해야 해요.</p></details>
  <details><summary>Ctrl+Alt+Space를 눌러도 안 열려요</summary><p>다른 프로그램이 단축키를 사용 중일 수 있습니다. <strong>Ctrl+Shift+Space → AI 또는 Ctrl+J</strong>를 사용하거나 트레이에서 <strong>AI 대화 · 빠른 비서에서 Ctrl+J</strong>를 누르세요.</p></details>
  <details><summary>연결됨인데 답변이 안 나와요</summary><p>PC 연결과 ChatGPT 응답 권한은 별개예요. PC의 <strong>연결 시험</strong>을 확인하고, 재로그인·구독 사용 권한·사용 한도 안내를 따라 주세요. 다른 유료 API로 자동 전환하지 않습니다.</p></details>
  <details><summary>PC 비서의 기본 Gemini를 쓰고 싶어요</summary><p>PC Gemini는 모아에서 발급한 <strong>비서 실행(assistant.run)</strong> 토큰이 필요해요. <a href="<?= Http::url('/welcome') ?>#welcome-external-buddy">기존 시작 안내의 외부 PC 비서용 연결 토큰</a>에서 해당 기능을 골라 발급한 뒤, PC 트레이의 <strong>비서 실행용 토큰 등록</strong>에 넣습니다. 발급에는 OTP가 필요합니다. 이 토큰은 개인 GPT 연결에는 쓰지 않습니다.</p></details>
  <details><summary>연결을 지우거나 다른 계정으로 바꾸고 싶어요</summary><p>웹 AI 도구의 <strong>연결 해제</strong> 또는 PC의 <strong>모아 웹 연결 → 연결 중지 · 모두 해제</strong>를 누릅니다. 새 브라우저를 승인하면 이전 브라우저의 연결 기억은 바뀝니다.</p></details>
</section>
<p><a href="/">모아 홈으로</a> · <a href="<?= Http::url('/welcome') ?>">계정 시작 안내</a></p>
