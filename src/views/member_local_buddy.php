<div class="moa-install-note">
  <h3>기존 PC AI 비서 업데이트</h3>
  <p>사용하던 트레이 비서에 Gemini 기본 선택과 개인 GPT 연결을 추가합니다. 기존 바로가기·파일 팔레트·하나의 트레이를 유지하는 업데이트를 준비하고 있습니다.</p>
  <p><a class="btn btn--quiet" href="<?= Http::url('/programs') ?>">AI 비서 배포함 열기</a></p>
  <p class="moa-muted">기존 GPT 연결 시험본의 기능을 기존 비서에 통합하고 있습니다. 검증을 마친 업데이트는 배포함에 버전이 표시된 뒤 내려받으세요. Git 계정은 필요하지 않습니다.</p>
  <details><summary>기존 비서에서 개인 GPT와 모아 웹 연결하기</summary>
  <ol>
    <li><strong>기존 비서 업데이트하기.</strong> 기존 비서 폴더와 설정을 백업하고 트레이에서 비서를 종료합니다. 받은 업데이트 ZIP을 모두 푼 뒤 <code>update.cmd</code>를 실행해 기존 비서 폴더를 선택하세요. 업데이트 후에도 기존 바로가기를 사용하고 트레이 아이콘이 하나인지 확인하세요.</li>
    <li><strong>AI 열기.</strong> 기존 파일 팔레트를 <kbd>Ctrl+Shift+Space</kbd>로 열고 AI 단추 또는 <kbd>Ctrl+J</kbd>를 누릅니다. 현재 검증 PC의 <kbd>Ctrl+Alt+Space</kbd> 등록 충돌은 해결되지 않아 이 경로를 사용합니다.</li>
    <li><strong>내 ChatGPT 연결하기.</strong> PC 앱의 ‘계정 연결 · 설정’에서 <strong>Continue with ChatGPT</strong>로 로그인하고 모델을 선택합니다. ‘연결 시험’에서 짧은 답변의 응답 완료까지 확인하세요.</li>
    <li><strong>모아와 연결하기.</strong> 앱의 ‘모아 웹 연결’에서 일회용 코드를 만듭니다. 같은 PC에서 모아 메신저를 크게 열고, 동료 대화 → AI 도구 → ‘GPT · 내 ChatGPT’를 선택하세요. 코드를 2분 안에 입력해 ‘PC 연결’을 누르고 PC 앱에서 모아 주소를 확인해 승인합니다.</li>
    <li><strong>결과 확인하기.</strong> 웹에서 ‘PC에서 처리’를 누르고 PC 앱에서 원문과 작업을 승인합니다. 돌아온 결과를 확인한 뒤 ‘작성란에 넣기’를 누르세요. 상대에게 보내는 동작은 별도입니다.</li>
  </ol>
  <p>본인의 ChatGPT 요금제 또는 사용 가능한 크레딧 범위로 처리하며 유료 API로 자동 전환하지 않습니다. 모아 로그인과 별개이고, ChatGPT 토큰이나 모아 업무용 연결 토큰을 여기에 입력하지 않습니다.</p>
  <p class="moa-muted">휴대폰에서는 다른 PC의 비서에 직접 연결할 수 없습니다. 모아 기본 Gemini 또는 ‘ChatGPT 웹’을 이용하세요. PC 연결은 30분 뒤 만료되며 앱 종료·계정 변경 시 다시 연결해야 합니다. 연결이 어려우면 원문과 결과를 직접 복사할 수 있습니다. 이 PC 연결은 문장 도구용이며 이미지 생성·파일 올리기·예약 업무·자동 전송은 포함하지 않습니다.</p>
  <p class="moa-muted">기존 앱에 업데이트를 적용하고 GPT 응답을 확인했습니다. Gemini는 앱의 연결 설정에 개인 assistant.run 토큰을 등록해야 하며, 이번 업데이트의 실제 Gemini 응답은 아직 확인 대기입니다. 게시 전 업데이트는 배포함에 준비 중으로 표시합니다.</p>
  </details>
</div>
