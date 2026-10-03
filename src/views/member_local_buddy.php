<details class="moa-install-note">
  <summary>개인 ChatGPT 로컬 비서 설치·사용 안내</summary>
  <p>Windows PC에서 실행하는 tbox-buddy의 독립 텍스트 비서입니다. 모아 로그인과 ChatGPT 계정 연결은 별개이며, ChatGPT 로그인은 PC 앱에서 진행합니다.</p>
  <p><strong>현재 공유본은 연결 조건 확인 중입니다.</strong> 화면과 입력 기능은 준비되어 있지만, 배포 자격 확인 전에는 ChatGPT 로그인·AI 처리가 보류됩니다. 웹 연결은 새 공유본의 일회용 페어링을 완료한 경우에만 사용할 수 있습니다.</p>
  <p>배포 형식은 ZIP이며 별도 EXE 설치파일은 없습니다. 기존 배포 파일명은 <code>tbox-buddy-local-20261002.zip</code>입니다. 새 웹 연결 기능은 업데이트된 공유본이 필요합니다.</p>
  <ol>
    <li><a href="https://git2.tla.so/changkwon/T-Box_Re/src/branch/<?= rawurlencode('AI-비서(로컬)') ?>" target="_blank" rel="noopener noreferrer">로컬 비서 공유 브랜치 열기 ↗</a>에서 <strong>AI-비서(로컬)</strong>를 선택하고 ZIP을 받아 압축을 풉니다. 비공개 저장소이므로 본인 Git 계정에 접근 권한이 필요합니다.</li>
    <li>Python 3.10 이상과 Tk가 포함된 Windows 환경에서 <code>setup.cmd</code>, <code>run.cmd</code>를 차례로 실행합니다. 자세한 설치 조건은 공유본의 README를 확인하세요.</li>
    <li>연결 조건 확인 후 PC 앱의 ‘계정 연결 · 설정’에서 <strong>Continue with ChatGPT</strong>로 로그인하고 모델을 선택합니다.</li>
    <li>‘대화 · 문장 도구’에서 작업과 번역 언어를 고르고, 모아에서 복사한 <strong>원문만</strong> 붙여 넣습니다. 완료 결과를 검토·복사한 뒤 모아에 직접 붙여 넣으세요.</li>
  </ol>
  <p class="moa-muted">Git 자격 증명과 ChatGPT 토큰을 모아에 입력하지 마세요. 이 공유본은 파일 업로드·예약 업무·메신저 자동 전송을 포함하지 않습니다. 개인 ChatGPT 연결의 이미지 생성은 지원되지 않으며, 모아의 Gemini 이미지 생성은 별도 유료 기능입니다.</p>
</details>
