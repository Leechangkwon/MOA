/* Optional, account-persisted tours. Only guide preferences are written; tour steps
 * never click application controls, submit a form, or open/close an existing modal.
 * One exception (site Q:F-11 C-3): the messenger tour shows the floating messenger window (DESIGN 2.17). If that
 * window is closed when the tour needs it, the tour presses the window's own opener ([data-messenger-open]) once,
 * the same as a person would — it never sends, closes the window or changes what was typed. The floating window is
 * non-modal, so it never blocks the offer, a page tour or resuming (it is left out of every open-dialog check).
 *
 * Site Q:F-15 ③ 과업 길잡이(DESIGN 2.37 · community ARCH 26장 · 지시서 docs/qf15-s3-guide-directive.md D · E) — 「무엇을 하고 싶으세요?」에서
 * 과업 하나를 고르면 실제 화면을 넘어가며 진짜 단추에 테두리를 치고, 사람이 직접 눌러 서버에 결과가 생기면 완성 카드로 끝난다.
 * ★ 켜짐 조건: GET 응답에 `journeys` · `can` 이 객체로 있을 때만. 없거나 null 이면 아래 옛 투어 · 제안이 지금과 한 글자도 다르지 않게 돈다.
 * ★ 진행 카드는 <dialog> 가 아니다(section.usage-guide-float) — 그릴 때 초점을 가져가지 않고 Tab 을 가두지 않으며 어둡게 하지 않는다.
 * ★ 안내는 앱 단추를 누르거나 폼을 제출하거나 앱 창을 열고 닫지 않는다 — 예외 하나: 「동료에게 한마디」 첫 단계 「다음」의 messengerWindow().
 * ★ 자격은 서버가 준 `can`(Authz 함수 값)만 본다 — 이 파일에 사람의 등급 이름을 견주는 코드가 없다. 글자는 전부 textContent.
 */
(() => {
  'use strict';
  const root = document.getElementById('usage-guide-root');
  if (!root) return;
  const base = root.dataset.base;
  if (!base || !base.startsWith('/') || base.startsWith('//')) return;
  const endpoint = base + '/api/me/guide';
  let csrf = '', saved = null, dialog = null, steps = [], index = 0, context = '', target = null, returnFocus = null;
  let queued = Promise.resolve(), loading = null, noticeTimer, frame;
  const $ = (id) => document.getElementById(id);
  const visible = (el) => Boolean(el && !el.closest('[hidden]') && el.getClientRects().length && getComputedStyle(el).visibility !== 'hidden');
  const pick = (selector) => Array.from(document.querySelectorAll(selector)).find(visible);
  const step = (selector, title, text) => ({ selector, title, text });
  const tours = {
    // Q:F-12 D — 업무 홈(첫 화면 `/community/`). 옛 둘러보기 안내를 잇는다 — 맥락 이름(overview)은 저장된 진행 상태와 맞추려고 그대로 둔다
    overview: [
      step('#work-home-welcome','업무 홈','오늘 할 일과 맡은 작업, 내 프로젝트를 한눈에 봐요. 자료실 · 메신저 · 우리 공간은 오른쪽 위 「앱」에서 옮겨 가요.'),
      step('#work-home-tasks','내 작업 요약','중요한 순서로 모은 내 작업이에요. 동그라미를 누르면 완료, 이름을 누르면 상세가 열려요.'),
      step('#work-projects','내 프로젝트','볼 수 있는 프로젝트와 진행률이에요. 진행률은 내가 볼 수 있는 작업만 세어서 사람마다 숫자가 다를 수 있어요.'),
      step('#nav-new-menu','새로 만들기','작업 · 프로젝트 · 문서를 여기서 만들어요. 프로젝트는 섹션과 목록 · 보드 · 타임라인 · 달력 보기를 갖춰 만들어져요.'),
      step('#nav-work-my-tasks','내 작업','나에게 맡겨진 작업과 내 할 일을 기한별로 모아 봐요. 맡긴 사람이 있는 작업에는 「○○ 님이 맡김」이 붙어요.'),
      step('#nav-community-bell','받은 알림','작업을 맡기거나 댓글에서 나를 부르면 여기로 알려 드려요. 볼 수 없게 된 페이지는 제목 없이 보여요.'),
      step('#work-home-notice','공지 한 줄','아직 읽지 않은 공지는 작업과 섞지 않고 한 줄로 따로 보여 줘요.'),
      step('#work-home-docs','문서','회의록 · 절차서 · 게시판 같은 문서는 「내 문서」와 사이드바 「문서」 묶음에 그대로 있어요.')
    ],
    threads: [
      step('.threads-tabs','나의 기록과 사람들의 소식','나의 스레드는 내 개인 페이지예요. 팔로잉은 내가 따르는 사람, 발견은 주인이 공개 게시한 기록을 보여 줘요.'),
      step('[data-compose]','지금의 순간 남기기','사진을 찍거나 앨범에서 고르고 메모를 적어요. 작성 창을 연 뒤 그 창의 사용 안내에서 각 입력칸을 살펴볼 수 있어요.'),
      step('#find-people','사람 찾기와 팔로우','이름이나 아이디로 사람을 찾고 프로필에서 팔로우해요. 팔로우해도 상대가 나만 보기로 둔 글은 보이지 않아요.'),
      step('#profile-stats','팔로우와 팔로워','내가 팔로우한 사람과 나를 팔로우한 사람을 확인해요. 목록에서 사람을 고르면 그 사람의 공개 가능한 기록을 볼 수 있어요.'),
      step('#profile-settings','기본 공개 범위 정하기','내 프로필과 새 기록의 공개 범위를 설정해요. 각 글을 작성하거나 수정할 때도 범위를 따로 선택할 수 있어요.'),
      step('[data-travel-open]','여행 계획과 기록 연결','내 여행을 계획하고 실제 기록을 일정에 연결해요. 여행 계획 창에도 버튼별 사용 안내가 있어요.'),
      step('#feed','긴 이야기는 가볍게 둘러보기','피드에는 대표 사진과 짧은 요약이 보여요. 내용 더 보기로 전체 사진과 장면을 읽고 댓글·좋아요·공유 버튼을 사용할 수 있어요.'),
      step('#search','기록 찾기','여행 이름, 장소, 글의 한 줄로 찾아보세요. 앨범 버튼으로 사진을 모아 볼 수도 있어요.')
    ],
    composer: [
      step('#compose-dialog .dialog-head','사진과 짧은 메모부터','작성 내용은 저장하기를 누르기 전에는 게시되지 않아요. 안내 중에는 글이나 파일을 변경하지 않으니 안심하고 살펴보세요.'),
      step('#take-photo,#pick-media,#take-video','사진·영상 첨부','사진 찍기는 카메라, 사진·영상 고르기는 앨범과 파일을 열어요. 선택한 파일의 미리보기와 오류 안내를 확인한 뒤 저장하세요.'),
      step('#caption','오늘의 한 줄','어떤 하루였는지 적어 주세요. 처음 쓴 글과 사진은 AI로 꾸밀 때도 원본으로 보관해요.'),
      step('#location','어디에서의 기억인가요?','장소를 적으면 나중에 찾기 편해요. 쓰고 싶지 않다면 비워 두어도 좋아요.'),
      step('#taken-on','기록한 날짜','사진 속 하루의 날짜를 선택해요. 지난 여행의 기록도 원하는 날짜로 남길 수 있어요.'),
      step('#visibility','누가 볼 수 있나요?','나만 보기·팔로워 등 원하는 범위를 선택해요. 팔로우 관계와 링크 공유도 이 공개 범위를 넘어설 수 없어요.'),
      step('#discoverable','발견에 공개 게시하기','새로운 사람에게도 소개하고 싶을 때 선택해요. 공개 가능한 범위에서만 사용할 수 있고 비공개 글을 자동으로 공개하지 않아요.'),
      step('#memory-travel-plan','여행 계획과 연결','기록이 어느 여행과 일정에 해당하는지 선택할 수 있어요. 계획 밖의 순간은 연결하지 않아도 됩니다.'),
      step('#publish','확인하고 저장하기','사진, 메모, 날짜와 공개 범위를 확인한 뒤 이 버튼으로 게시해요. 저장이 실패하면 오류를 확인하고 다시 시도하세요.')
    ],
    workspace: [
      step('.page-head','문서와 업무 공간','개인 메모, 팀 문서, 공지와 질문을 함께 정리해요. 볼 수 있는 문서와 기능은 내 권한에 맞춰 나타나요.'),
      step('#community-new','새 문서 작성','비공개 문서부터 시작하고 필요할 때 함께 볼 사람을 정해요. 템플릿은 더보기에서 고를 수 있어요.'),
      step('#community-search','제목과 내용 검색','문서가 많아지면 제목·내용으로 찾고 검증 상태, 기한, 진행 상태로 좁혀 보세요.'),
      step('#community-space,#community-tree','개인·팀 공간과 문서 트리','공간을 고르고 문서를 계층으로 정리해요. 내가 볼 권한이 있는 문서만 목록에 나타나요.'),
      step('#moa-board-doors','게시판의 소식과 질문','공지·질문·일상 소식은 게시판에서 함께 읽고 남겨요. 게시판과 문서, 개인 스레드는 각각의 목적에 맞춰 사용할 수 있어요.'),
      step('.ProseMirror','블록으로 쓰는 문서','문단·할 일·표 등 필요한 블록을 더하고 내용을 수정해요. 자동 저장 상태를 확인한 뒤 이동하세요.'),
      step('#community-more','양식과 휴지통','더보기에서 템플릿 작성과 내 휴지통 등 부가 기능을 찾을 수 있어요. 항목은 권한에 따라 다를 수 있어요.')
    ],
    files: [
      step('#upload-toggle','자료실에 파일 올리기','폴더를 정하고 문서·사진·파일을 올려요. 권한과 파일 형식에 따라 사용할 수 있는 동작이 달라요.'),
      step('#folder-pane','폴더로 차곡차곡','원하는 폴더를 선택해 관련 파일을 모아 보세요. 폴더 경로로 상위 위치로 돌아갈 수 있어요.'),
      step('#search-q','이름과 설명으로 검색','파일을 찾고 미리보기에서 내용을 확인해요. 별을 달면 즐겨찾기에서 다시 찾을 수 있어요.'),
      step('#moa-file-list','열기·받기·공유','파일의 작업 메뉴에서 미리보기·다운로드·공유 기능을 찾아보세요. 공유할 수 있는 파일에만 공유 항목이 표시돼요.')
    ],
    account: [
      step('#welcome-security,#passkey-panel','로그인 방법 선택','비밀번호로 시작하거나 OTP·기기 패스키를 추가해요. 패스키는 지문·얼굴·기기 잠금으로 본인을 확인하고 생체 정보는 모아에 저장하지 않아요.'),
      step('#welcome-choose-passkey','기기로 빠르게 로그인','지원하는 브라우저에서 이 기기를 등록해요. 이후 로그인 화면에서 패스키를 선택하면 OTP와 별도로 로그인할 수 있어요.'),
      step('#welcome-choose-otp','OTP 인증 앱 선택','원할 때 등록할 수 있어요. 인증 앱이 없다면 다운로드 안내를 열고 앱을 설치한 뒤 등록을 이어가세요.'),
      step('#welcome-buddy','PC 비서는 나중에 해도 좋아요','설치와 연결은 선택 사항이에요. 웹앱은 로그인한 계정으로 쓰며 외부 비서는 지원되는 설치·연결 방법을 먼저 확인하세요.'),
      step('#member-install','모바일과 컴퓨터 설치','기기에 맞는 설치 안내를 따라요. 설치 후 같은 계정으로 로그인하면 내 기록과 이어지고 웹앱용 별도 토큰을 복사할 필요가 없어요.'),
      step('#welcome-token-form','외부 비서용 연결 토큰','필요한 경우에만 사용할 기능을 골라 발급해요. 이 값은 AI 제공자의 API 키와 다르며 타인에게 공유하면 안 됩니다.'),
      step('#welcome-steps','언제든 다시 이어가기','선택 항목은 나중에 설정해도 돼요. 업무 홈과 이야기에서 일과 일상 기록을 함께 살펴보세요.')
    ],
    // 떠 있는 메신저 창(#messenger-window — DESIGN 2.17). 창은 목록 ↔ 대화 중 하나만 보이므로 보이는 단계만 남는다.
    messenger: [
      step('#messenger-window #messenger-back,#messenger-window #message-people','대화 상대 목록','함께하는 사람과 모아 AI가 목록에 있어요. 사람을 고르면 이전 대화를 이어서 보고, 대화 중에는 ‹ 목록으로 돌아와요.'),
      step('#messenger-window #message-search','사람 찾기','이름이나 아이디로 함께하는 사람을 찾아요. 안내 중에는 검색하거나 대화를 열지 않아요.'),
      step('#messenger-window #message-ai,#messenger-window #message-ai-notice','모아 AI와 전달되는 내용','모아 AI는 회사 공용 AI로 답하고 대화 기록은 나만 봐요. AI에게 보낸 내용과 최근 AI 대화가 Google로 전송되니 개인정보·기밀은 입력하지 마세요.'),
      step('#messenger-window #message-text','작성과 전송','Enter는 전송, Shift+Enter는 줄바꿈이에요. 쓰던 글은 이 기기에 암호화해 두어 다른 화면에서 창을 열어도 이어서 쓸 수 있어요.'),
      step('#messenger-window .modal__head a[href]','크게 보기','메신저를 전체 화면으로 열어요. PC 비서 연결도 전체 화면에서 할 수 있어요.'),
      step('#messenger-window .modal__head [data-messenger-close]','닫아도 이어져요','창은 보던 화면 위에 떠 있어 뒤 화면을 그대로 쓸 수 있어요. 닫아도 상대와 쓰던 글은 남고, 다른 화면으로 옮기면 열어 둔 창이 그대로 돌아와요.')
    ],
    story: [
      step('#detail-dialog,#studio-dialog .dialog-head','사진과 이야기를 자세히','피드에서 접어 둔 사진과 글을 여기서 이어서 읽어요. 닫으면 원래 피드로 돌아가요.'),
      step('#studio-tone','글의 분위기 선택','간결한 에세이, 관찰 기록, 여행 노트 등 원하는 분위기를 고를 수 있어요.'),
      step('#gemini-wish','내가 원하는 이야기','사진을 보고 어떤 내용을 더해 주면 좋을지 적어요. 있었던 일을 알려 주면 사실에 가까운 초안을 만드는 데 도움이 돼요.'),
      step('#gemini-generate','선택한 사진으로 초안 만들기','버튼을 누를 때 사진의 축소본과 메모 등 안내된 내용을 Gemini에 전달해요. 결과는 검토한 뒤 저장하세요.'),
      step('#studio-story-editor,#studio-result','내 목소리로 다듬기','생성된 장면별 글이나 초안을 직접 고칠 수 있어요. 수정한 글을 확인하고 적용하세요.'),
      step('#studio-append,#apply-studio','원문을 보존하며 적용','기존 글에 추가하거나 교체 편집을 선택해요. 안내는 이 버튼을 누르거나 저장하지 않아요.'),
      step('[data-comment-post]','댓글로 마음 남기기','댓글 아이콘을 누르면 그 글 아래에서 바로 댓글을 적고 「게시」로 전해요. 공개 범위와 내 권한 안에서 사용할 수 있어요.'),
      step('#share-url','공유 가능한 기록만 나누기','링크가 있어도 소유자가 정한 범위를 우회할 수 없어요. 공유 안내와 링크 유효기간을 확인해 주세요.')
    ],
    travel: [
      step('#travel-dialog','계획을 기록으로 이어가기','가고 싶은 곳과 일정을 적고 실제로 남긴 사진·글을 연결해요. 계획이 바뀌어도 언제든 수정할 수 있어요.'),
      step('#travel-new','새 여행 계획','여행 이름과 기간, 메모를 입력해요. 날짜를 확정하기 어려우면 가능한 범위부터 작성하세요.'),
      step('#travel-title','이름과 여행 메모','이 여행을 알아볼 이름과 준비할 내용을 정리해요. 일정과 장소는 여행 중에도 바꿀 수 있어요.'),
      step('#travel-add-step','일정·장소 추가','들를 곳을 넣고 순서를 정해요. 새 장소를 더하거나 기존 계획을 변경하면서 실제 동선에 맞춰요.'),
      step('#travel-steps','진행 상태와 기록','예정·완료·건너뜀 같은 상태를 표시하고 사진 기록을 연결해요. 계획과 달라졌다는 이유로 기록을 지울 필요는 없어요.'),
      step('#travel-save','변경 내용 저장','수정 내용을 확인한 뒤 저장해요. 사진 기록을 쓸 때 여행 계획과 일정은 선택적으로 연결할 수 있어요.')
    ],
    // Q:F-12 D — 내 작업(`/community/my-tasks` — 옛 「오늘」 맥락 이름을 잇는다)
    today: [
      step('#my-tasks-list','기한으로 묶은 내 작업','지난 기한 · 오늘 · 이번 주 · 나중에 · 기한 없음으로 묶어요. 묶음 이름을 누르면 접고 펼쳐요.'),
      step('#my-tasks-add,[data-add-bucket]','바로 적기','「작업 추가」에서 이름을 적고 Enter 를 누르면 저장하고 다음 줄로 가요. 프로젝트 없이 적은 작업은 나만 보는 「내 할 일」에 모여요.'),
      step('[data-my-view]','목록 · 보드 · 달력','같은 작업을 묶음별 보드나 기한 달력으로도 볼 수 있어요. 「끝난 작업 보기」로 끝낸 작업을 다시 봐요.'),
      step('#my-tasks-list .task-row__title','작업 상세','이름을 누르면 오른쪽에 상세가 열려요. 담당자 · 마감 · 상태를 바꾸고 댓글과 모든 활동을 한 줄기로 봐요.')
    ]
  };
  tours.travel.push(step('#travel-record','여행 중의 순간 기록','이 여행에 연결할 새 사진과 글을 작성해요. 여행 계획은 유연하게 고치고 기록은 별도로 남길 수 있어요.'));
  tours.travel.push(step('#travel-linked','이미 쓴 글도 연결하기','내 기록을 불러와 이 여행에 연결할 수 있어요. 연결을 바꿔도 원래 사진과 글은 그대로 유지돼요.'));
  tours.travel.push(step('#travel-ai-prompt','AI와 계획 다듬기','요청문을 복사해 ChatGPT 등에 상담할 수 있어요. MCP가 연결되어 있으면 허용한 여행 읽기·쓰기 범위 안에서 계획을 다룰 수 있어요.'));
  const modalContexts = new Map([
    ['messenger-window','messenger'],['compose-dialog','composer'],['studio-dialog','story'],
    ['share-dialog','story'],
    ['detail-dialog','story'],['travel-dialog','travel']
  ]);
  tours.threads.push(step('#social-search','함께 기록할 사람 찾기','이름이나 아이디로 찾고 프로필을 열어 팔로우할 수 있어요. 공개하지 않은 기록은 팔로워라도 볼 수 없어요.'));
  tours.threads.push(step('#social-list','팔로잉·팔로워 목록','상대의 프로필을 열거나 팔로우 상태를 바꿀 수 있어요. 팔로워 공개 글은 나를 팔로우한 모든 회원에게 보이므로 글을 올리기 전에 범위를 확인해 주세요.'));
  tours.threads.push(step('#default-visibility','새 글의 기본 공개 범위','새로 작성하는 글의 기본값을 정해요. 기존 기록은 바뀌지 않고 발견에는 직접 선택한 공개 글만 소개돼요.'));
  modalContexts.set('social-dialog','threads'); modalContexts.set('privacy-dialog','threads');

  /* ─── 길잡이 여정 여섯 (지시서 E 표 · DESIGN 2.37 「글」) ────────────────────────────────────────────
   * 꼴: { place, label, pages: [{ app, path?, here, name, steps: [{ anchor, title, text, kind, until?, fact?, box?, back?, retry?,
   *        ahead?, when?, empty?, alt?, go?, fail?, opens? }] }], done: { text, chip? } }
   * 키는 src/community/GuideJourney.php JOURNEYS 와 같고 pages 길이 = 그 화면 수(수락 [static] 이 센다). 이 묶음은 글자 · 선택자만 —
   * 코드 0(시험이 이 사이를 그대로 읽어 셈한다).
   *   kind   read = 「다음」으로 넘김 · do = 사람이 진짜 단추를 눌러야 넘어감(「건너뛰기」 있음)
   *   until  선택자(일치하면 넘김) · {event} window 이벤트 · {value:true} change 또는 입력 0.8초 멈춤 · {text:선택자} 들어온 뒤 글자가 바뀜 ·
   *          {navigate:true} 그릴 때 다음 화면 0단계를 미리 저장 · {submit:폼 선택자} 그 폼 제출
   *   fact   넘김 신호 뒤 서버 사실을 한 번 더(GET + 2초 간격 두 번) · box 창 안 단계(그 앱 창 머리 아래 한 줄) · back 창이 닫히면 돌아갈 단계 ·
   *   retry  사실이 거짓일 때 돌아갈 단계 · ahead 그 요소를 사람이 먼저 누르거나 초점을 주면 그 요소를 가리키는 뒤 단계로 ·
   *   when   'visible' = 보일 때만(안 보이면 건너뜀) · empty 3초 뒤 빈 상태 · alt 고르개에 그 값이 없을 때 글 ·
   *   go     {box, off, min} — 폭이 min 보다 좁거나(폰 서랍) 그 묶음이 닫혔거나 off 가 맞으면(접힌 사이드바) 기다리지 않고 다음 화면 「이 화면으로 가기」
   */
  /* @journeys-begin */
  const journeys = {
    'task-add': {
      place: '업무 홈', label: '맡은 일 보기 · 할 일 적기',
      pages: [
        { app: 'community', path: '/', here: '#work-home', name: '업무 홈', steps: [
          { anchor: '#work-home-tasks', kind: 'read', title: '내 작업', text: '중요한 순서로 모은 내 작업이에요. 비어 있으면 지금 하나 적어 봐요.' },
          { anchor: '#work-home-add', kind: 'do', until: '#work-new-task-dialog[open]', title: '「작업 추가」 누르기', text: '「작업 추가」를 누르세요. 「새 작업」 창이 열려요.' },
          { anchor: '#work-new-task-form input[type=text]', kind: 'do', box: '#work-new-task-dialog', back: 1, retry: 1, until: { event: 'work:changed' }, fact: true, title: '할 일 적기', text: '할 일을 한 줄 적고 「만들기」를 누르세요 — 예 「내일까지 견적 확인」. 「프로젝트」가 「내 할 일」이면 나만 보는 할 일이 돼요.' },
          { anchor: '#work-home-all', kind: 'read', title: '다시 찾기', text: '업무 홈은 중요한 순서로 앞의 몇 줄만 보여요. 방금 적은 일이 안 보이면 「모두 보기」에서 찾아요.' }
        ] }
      ],
      done: { text: '할 일 하나를 적었어요. 업무 홈과 「내 작업」 화면에서 다시 보여요.' }
    },
    'file-share': {
      place: '자료실', label: '파일 올려서 동료에게 보여 주기',
      pages: [
        { app: 'tbox', path: '/', here: '#moa-file-list', name: '자료실', steps: [
          { anchor: '#folder-pane', kind: 'read', title: '폴더 고르기', text: '폴더에서 팀 폴더를 먼저 고르세요. 없으면 지금 자리에 올려도 돼요.' },
          { anchor: '#upload-toggle', kind: 'do', until: '#upload-dialog[open]', title: '「파일 올리기」 누르기', text: '「파일 올리기」를 누르세요. 창이 열리면 파일 → 등급 → 「올리기」 순서예요.' },
          { anchor: '#upload-file', kind: 'do', box: '#upload-dialog', back: 1, until: '#upload-picked:not(:empty)', title: '파일 고르기', text: '파일을 고르거나 끌어다 놓으세요. 여러 개도 한 번에 올라가요.' },
          { anchor: '#upload-level', kind: 'do', box: '#upload-dialog', back: 1, until: { value: true }, ahead: '#upload-submit', title: '누가 볼 수 있나요', text: '볼 사람을 등급으로 고르세요. 「팀 — 고른 팀만」이면 우리 팀, 「개인 — 나와 지정한 사람만」이면 고른 사람만 봐요.',
            alt: { unless: '#upload-level option[value=team]', text: '볼 사람을 등급으로 고르세요. 「팀」이 없으면 「사내 — 사내 구성원 모두」로 — 관리자가 팀을 정하면 열려요.' } },
          { anchor: '#upload-submit', kind: 'do', box: '#upload-dialog', back: 1, retry: 1, until: '#upload-dialog:not([open])', fact: true, title: '「올리기」 누르기', text: '「올리기」를 누르세요. 모두 올라가면 창이 저절로 닫혀요.' },
          { anchor: '#files-body', kind: 'read', title: '목록에서 확인', text: '올린 파일이 목록에 있어요. 같은 등급의 동료는 자료실 목록과 검색에서 찾아 받아요.' }
        ] }
      ],
      done: { text: '파일을 올렸어요. 자료실 목록과 검색에서 다시 찾을 수 있어요. 알리려면 메신저로 한 줄 보내요.', chip: 'message-send' }
    },
    'minutes-write': {
      place: '업무 홈 › 문서', label: '회의록 쓰기',
      pages: [
        { app: 'community', path: '/workspace', here: '#moa-my-documents', name: '내 문서', steps: [
          { anchor: '#community-more > summary', kind: 'do', until: '#community-more[open]', title: '「더보기」 열기', text: '「더보기」를 누르세요. 「템플릿에서 만들기」가 안에 있어요.' },
          { anchor: '#community-template-new', kind: 'do', until: '#template-dialog[open]', title: '「템플릿에서 만들기」', text: '「템플릿에서 만들기」를 누르세요. 템플릿 목록 창이 열려요.' },
          { anchor: '#template-body button[data-template-use][aria-label="회의록 으로 새 페이지 만들기"]', kind: 'do', box: '#template-dialog', back: 0, until: { navigate: true },
            empty: { test: '#template-empty:not([hidden])', text: '「회의록」 틀이 아직 없어요 — 관리자가 만들면 여기 보여요' }, title: '「회의록」 만들기', text: '「회의록」 줄의 「만들기」를 누르세요. 그 틀이 든 새 문서가 열려요.' }
        ] },
        { app: 'community', here: 'doc', name: '문서', prev: [0, 0], steps: [
          { anchor: '#page-edit', kind: 'do', until: 'body[data-page-editing]', title: '「편집」 누르기', text: '「편집」을 누르세요. 제목 칸과 본문 칸이 열려요.' },
          { anchor: '#page-title-input', kind: 'do', until: { value: true }, ahead: '#page-editor .ProseMirror', title: '제목 적기', text: '제목을 적으세요 — 예 「2026-10-27 주간 회의」. 검색은 제목부터 찾아요.' },
          { anchor: '#page-editor .ProseMirror', kind: 'do', until: { value: true }, title: '칸 채우기', text: '칸을 채우세요. 저장은 저절로 되고 위 배지가 「저장됨」으로 알려 줘요.' },
          { anchor: '#page-done', kind: 'do', until: '#page-done[hidden]', title: '「편집 마침」', text: '다 쓰면 「편집 마침」을 누르세요. 읽기 모양으로 바뀌어요.' },
          { anchor: '#page-visibility-edit', kind: 'do', when: 'visible', until: '#visibility-dialog[open]', title: '팀과 같이 보기', text: '공개 범위 옆 「바꾸기」를 누르세요. 같이 볼 사람을 고르는 창이 열려요.' },
          { anchor: '#visibility-choices', kind: 'do', box: '#visibility-dialog', back: 4, until: { value: true }, ahead: '#visibility-save', title: '같이 볼 사람', text: '같이 볼 사람을 고르세요. 「팀 — 내 팀 사람들이 봅니다」면 우리 팀이 같이 봐요.' },
          { anchor: '#visibility-save', kind: 'do', box: '#visibility-dialog', back: 4, until: '#visibility-dialog:not([open])', fact: true, title: '「바꾸기」 누르기', text: '「바꾸기」를 누르세요. 문서의 공개 범위가 바뀌어요.' }
        ] }
      ],
      done: { text: '회의록을 만들었어요. 업무 홈 › 내 문서와 검색 칸에서 제목으로 다시 찾아요. 다음 회의 땐 같은 틀로 하나 더 만들어요.' }
    },
    'notice-post': {
      place: '업무 홈 › 게시판', label: '공지 올리기',
      pages: [
        { app: 'community', path: '/', here: '#work-home', name: '업무 홈', find: 'notice', steps: [
          { anchor: '#nav-community-boards a[href$="/b/{board}"]', kind: 'do', go: { box: '#nav-community-boards-group', off: 'body[data-sidebar="collapsed"]', min: 960 }, until: { navigate: true }, title: '공지 게시판 열기', text: '「{name}」 게시판을 누르세요. 공지 게시판 화면이 열려요.' }
        ] },
        { app: 'community', here: 'board', name: '게시판', find: 'notice', prev: [0, 0], steps: [
          { anchor: '#board-new', kind: 'do', until: '#board-new-dialog[open]', title: '「새 글」 누르기', text: '「새 글」을 누르세요. 제목 창이 열려요.' },
          { anchor: '#board-new-input', kind: 'do', box: '#board-new-dialog', back: 0, until: { navigate: true }, title: '제목 적기', text: '제목을 적고 「만들고 쓰기」를 누르세요. 글이 바로 게시되고 본문 쓰는 화면이 열려요.' }
        ] },
        { app: 'community', here: 'doc', name: '문서', prev: [1, 0], steps: [
          { anchor: '#page-editor .ProseMirror', kind: 'do', until: { value: true }, title: '본문 적기', text: '본문을 적으세요. 저장은 저절로 되고 글은 이미 게시돼 있어요.' },
          { anchor: '#page-done', kind: 'do', until: '#page-done[hidden]', fact: true, title: '「편집 마침」', text: '「편집 마침」을 누르세요. 읽기 모양으로 바뀌어요.' }
        ] }
      ],
      done: { text: '공지를 올렸어요. 동료의 업무 홈 공지 줄에 보이고, 읽으면 사라져요.', chip: 'message-send' }
    },
    'message-send': {
      place: '메신저', label: '동료에게 한마디',
      pages: [
        { app: 'any', here: 'messenger', name: '메신저', steps: [
          { anchor: '[data-messenger-open]', kind: 'read', until: '#messenger-window[open]', opens: 'messenger', title: '메신저 열기', text: '메신저는 이 단추로 열어요. 「다음」을 누르면 이번만 안내가 창을 열어 드려요.' },
          { anchor: ['#messenger-window[data-view=conversation] #messenger-back', '#messenger-window #message-people .moa-person'], kind: 'do', box: '#messenger-window', back: 0,
            until: '#messenger-window[data-view=conversation]:has(#message-ai[aria-pressed=false])', title: '동료 고르기', text: '동료를 고르세요 — 다른 대화가 열려 있으면 「‹ 목록」부터 눌러요. 맨 위 「모아 AI」는 회사 공용 AI 예요.' },
          { anchor: '#messenger-window #message-text', kind: 'do', box: '#messenger-window', back: 0, until: { value: true }, ahead: '#messenger-window #message-send', title: '한 줄 적기', text: '한 줄 적으세요 — 예 「업무 홈에 하나 맡겼어요」. Enter 는 보내기, Shift+Enter 는 줄바꿈이에요.' },
          { anchor: '#messenger-window #message-send', kind: 'do', box: '#messenger-window', back: 0, retry: 1, until: { submit: '#message-form' }, fact: true, fail: '아직 결과가 없어요 — 동료를 골라 보내면 끝나요',
            title: '「보내기」 누르기', text: '「보내기」를 누르세요. 상대는 어느 화면에 있든 메신저 단추의 숫자로 알아요.' }
        ] }
      ],
      done: { text: '보냈어요. 창을 닫아도 대화는 남고, 다시 열면 마지막 대화부터 이어져요.' }
    },
    'invite-colleague': {
      place: '계정 관리', label: '동료 초대하기',
      pages: [
        { app: 'tbox', path: '/users', here: '#member-invites', name: '계정 관리', steps: [
          { anchor: '#invite-form', kind: 'do', until: '#invite-result:not([hidden])', fact: true, title: '초대 링크 만들기', text: '초대 이름과 「소속 팀」을 정하고 「초대 링크 만들기」를 누르세요. 팀을 정해 두면 가입하자마자 그 팀 파일을 봐요.' },
          { anchor: '#invite-copy', kind: 'do', until: { text: '#invite-status' }, title: '링크 보내기', text: '「링크 복사」를 누르고 지금 쓰는 메신저나 메일로 보내세요. 링크 원문은 이번에만 보여요.' },
          { anchor: '#invite-list', kind: 'read', title: '초대 목록', text: '보낸 초대와 쓰인 횟수가 여기 남아요. 가입하면 계정 목록에 이름이 생겨요.' }
        ] }
      ],
      done: { text: '초대 링크를 만들었어요. 가입하면 계정 관리 목록에 이름이 생겨요.' }
    }
  };
  /* @journeys-end */
  /** 안내 카드 단추 글자(DESIGN 5장 「안내 카드 단추」 — 수락 [static] 이 그 집합과 센다) · 질문 카드 바닥의 옛 안내 입구 둘(DESIGN 2.37 「질문 카드」) */
  const WORDS = { later: '나중에', next: '다음', skip: '건너뛰기', finish: '마치기', resume: '이어 하기', restart: '처음부터', redo: '다시 하기',
    go: '이 화면으로 가기', recheck: '다시 보기', close: '닫기', offerLater: '나중에 볼게요' };
  const ENTRY = { tour: '이 화면 사용 안내', help: '사용 방법 읽기' };
  const ASK = '무엇을 하고 싶으세요?';
  const NO_RESULT = '아직 결과가 없어요';
  const SAVE_FAIL = '안내 진행 상태를 저장하지 못했어요. 다시 열어 이어갈 수 있어요.';
  const journeyEndpoint = base + '/api/me/journeys';
  const appBaseRaw = root.dataset.appBase;
  const appBase = typeof appBaseRaw === 'string' && appBaseRaw.startsWith('/') && !appBaseRaw.startsWith('//') ? appBaseRaw : null;
  let jstate = null, jcan = null;
  /** 주소 꼬리 ?guide= — 스크립트 시작 때 한 번 읽고 곧바로 지운다(앱이 넣은 history.state 보존 · 꼬리는 guide 하나) */
  let wanted = null;
  try { wanted = new URLSearchParams(location.search).get('guide'); } catch (_) { wanted = null; }
  if (wanted !== null) { try { history.replaceState(history.state, '', location.pathname + location.hash); } catch (_) { /* 꼬리를 못 지워도 안내는 같다 */ } }

  /** 열린 창 — 안내 창과 떠 있는 창(비모달 · DESIGN 2.17)은 뺀다: 떠 있는 창은 자동 제안·화면 안내·이어 보기를 막지 않는다 */
  const openDialogs = () => Array.from(document.querySelectorAll('dialog[open]')).filter((el) => el !== dialog && !el.matches('dialog.floating'));
  /** origin: 안내를 연 단추 — 창 안의 「? 이 창 사용 안내」면 그 창(떠 있는 창 포함)의 안내 */
  function currentContext(origin = null) {
    const from = origin instanceof Element ? origin.closest('dialog') : null;
    if (from && from !== dialog && modalContexts.has(from.id)) return modalContexts.get(from.id);
    const opened = openDialogs().pop();
    if (opened && modalContexts.has(opened.id)) return modalContexts.get(opened.id);
    if ($('work-home')) return 'overview';
    if ($('work-my-tasks')) return 'today';
    if ($('community-threads')) return 'threads';
    if ($('welcome-security') || document.body.dataset.page === 'settings' || document.body.dataset.memberPage === 'welcome') return 'account';
    if ($('moa-file-list')) return 'files';
    return 'workspace';
  }
  function notice(text) {
    const n = $('usage-guide-notice'); n.textContent = text; n.hidden = false;
    if (dialog) {
      const card=dialog.querySelector('.usage-guide-card');
      let error=card.querySelector('.usage-guide-error');
      if (!error) { error=node('p','','usage-guide-error'); error.setAttribute('role','status'); card.append(error); }
      error.textContent=text;
    }
    clearTimeout(noticeTimer); noticeTimer = setTimeout(() => { n.hidden = true; }, 9000);
  }
  /** 두 창구(옛 안내 · 길잡이)가 함께 쓰는 한 번의 요청 — 같은 8초 · 같은 CSRF 갱신 */
  async function call(url, method = 'GET', body) {
    const controller=new AbortController(), timer=setTimeout(()=>controller.abort(),8000);
    try {
      const response = await fetch(url, { method, signal:controller.signal, credentials:'same-origin', headers:{ Accept:'application/json', ...(body ? {'Content-Type':'application/json','X-CSRF-Token':csrf} : {}) }, ...(body ? {body:JSON.stringify(body)} : {}) });
      let result = null;
      try { result = await response.json(); } catch (_) { result = null; }
      if (response.ok && result && result.csrf_token) csrf = result.csrf_token;
      return { ok: response.ok, status: response.status, data: result };
    } finally { clearTimeout(timer); }
  }
  const isObject = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
  /** GET 응답의 길잡이 몫 — 객체 둘이 다 있을 때만 켠다(표 없음 · 옛 응답 목업은 꺼진 채) */
  function takeJourneys(result, fromGet) {
    if (isObject(result) && isObject(result.journeys) && isObject(result.can)) { jstate = result.journeys; jcan = result.can; }
    else if (fromGet) { jstate = null; jcan = null; }
  }
  const enabled = () => isObject(jstate) && isObject(jcan);
  async function request(method = 'GET', body) {
    try {
      const r = await call(endpoint, method, body);
      if (!r.ok || !isObject(r.data)) throw new Error('guide_unavailable');
      saved = r.data.guide;
      if (method === 'GET') takeJourneys(r.data, true);
      return saved;
    } catch(e) { throw new Error('안내 진행 상태를 저장하지 못했어요. 다시 열어 이어갈 수 있어요.'); }
  }
  function load() { if (!loading) loading = request().catch((e) => { loading = null; throw e; }); return loading; }
  function persist(status) {
    const body = { status, context, step:index };
    const job = queued.then(async () => { if (!csrf) await load(); return request('PATCH', body); });
    queued = job.catch(() => {});
    return job;
  }
  function node(tag, text, className) { const el = document.createElement(tag); if (text) el.textContent = text; if (className) el.className = className; return el; }
  function button(text, fn, primary = false) { const el = node('button', text, primary ? 'usage-guide-primary' : ''); el.type='button'; el.addEventListener('click', fn); return el; }
  /** onCancel — Esc 로 창을 닫을 때. 기본은 지금 그대로(옛 투어 · 제안: 「그만둠」 저장) — 길잡이 질문 카드(제안이 아닐 때)만 저장 없이 닫는다 */
  function makeDialog(onCancel = () => finish('dismissed')) {
    returnFocus = document.activeElement;
    dialog = node('dialog', '', 'usage-guide-dialog'); dialog.setAttribute('aria-labelledby','usage-guide-title'); dialog.setAttribute('aria-describedby','usage-guide-body');
    const svg = document.createElementNS('http://www.w3.org/2000/svg','svg'); svg.classList.add('usage-guide-shade'); svg.setAttribute('aria-hidden','true');
    for (const part of ['top','left','right','bottom','outline']) { const r = document.createElementNS(svg.namespaceURI,'rect'); r.id='usage-guide-'+part; r.classList.add(part==='outline'?'usage-guide-outline':'usage-guide-dim'); if (part==='outline') r.setAttribute('rx','7'); svg.append(r); }
    dialog.append(svg, node('section','','usage-guide-card')); document.body.append(dialog);
    dialog.addEventListener('cancel', (event) => { event.preventDefault(); onCancel(); });
    dialog.addEventListener('keydown', (event) => {
      if (event.key === 'Tab') {
        const focusable=Array.from(dialog.querySelectorAll('button:not([disabled]),a[href],[tabindex="0"]')).filter(visible);
        const first=focusable[0],last=focusable[focusable.length-1];
        if (event.shiftKey && document.activeElement===first) { event.preventDefault(); last?.focus(); }
        else if (!event.shiftKey && document.activeElement===last) { event.preventDefault(); first?.focus(); }
      }
      if (!dialog.classList.contains('is-consent') && event.key === 'ArrowRight') { event.preventDefault(); next(); }
      if (!dialog.classList.contains('is-consent') && event.key === 'ArrowLeft' && index > 0) { event.preventDefault(); index--; render(); persist('active').catch((e) => notice(e.message)); }
    });
    dialog.showModal();
  }
  function rect(id,x,y,width,height) { const r=$('usage-guide-'+id); for (const [k,v] of Object.entries({x,y,width:Math.max(0,width),height:Math.max(0,height)})) r.setAttribute(k,String(v)); }
  function position() {
    if (!dialog) return;
    const w=innerWidth,h=innerHeight;
    if (!target || !visible(target)) { rect('top',0,0,w,h); for (const id of ['left','right','bottom','outline']) rect(id,0,0,0,0); return; }
    const r=target.getBoundingClientRect(),x=Math.max(5,r.left-6),y=Math.max(5,r.top-6),right=Math.min(w-5,r.right+6),bottom=Math.min(h-5,r.bottom+6);
    if (right<=x || bottom<=y) { target=null; position(); return; }
    rect('top',0,0,w,y); rect('left',0,y,x,bottom-y); rect('right',right,y,w-right,bottom-y); rect('bottom',0,bottom,w,h-bottom); rect('outline',x,y,right-x,bottom-y);
    dialog.classList.toggle('is-top',(y+bottom)/2>h/2);
  }
  function schedulePosition() { cancelAnimationFrame(frame); frame=requestAnimationFrame(position); }
  function render() {
    if (!dialog) return;
    const s=steps[index]; target=s.el; target?.scrollIntoView({block:'center',behavior:'instant'});
    dialog.classList.remove('is-consent'); const card=dialog.querySelector('.usage-guide-card'); card.replaceChildren();
    card.append(node('p',`${index+1} / ${steps.length} · 이 화면 사용 안내`,'usage-guide-progress'));
    const title=node('h2',s.title); title.id='usage-guide-title'; const body=node('p',s.text); body.id='usage-guide-body'; card.append(title,body);
    const actions=node('div','','usage-guide-actions'); actions.append(button('나중에',()=>finish('dismissed')));
    if(index>0) actions.append(button('이전',()=>{ index--; render(); persist('active').catch((e)=>notice(e.message)); }));
    if(index===steps.length-1 && context!=='overview' && !openDialogs().length) {
      actions.append(button('업무 홈 안내 보기',async()=>{
        context='overview'; index=0;
        try { await persist('active'); } catch(e) { notice(e.message); }
        location.assign(base+'/');
      }));
    }
    actions.append(button(index===steps.length-1?'안내 마치기':'다음',next,true)); card.append(actions); position(); actions.lastElementChild.focus({preventScroll:true});
  }
  function next() { if(index===steps.length-1) { finish('completed'); return; } index++; render(); persist('active').catch((e)=>notice(e.message)); }
  function close() { if(!dialog) return; dialog.close(); dialog.remove(); dialog=null; target=null; if (returnFocus?.isConnected && visible(returnFocus)) returnFocus.focus({preventScroll:true}); }
  function finish(status) { persist(status).catch((e)=>notice(e.message)); close(); }
  /**
   * 메신저 안내가 보일 떠 있는 창 — 닫혀 있으면 그 창의 여는 단추를 한 번 누른다(창 모듈이 아직 붙지 않았으면 1초마다 다시, 5초까지).
   * 여는 단추가 링크(런처 타일·모아 머리)여도 이 누름으로는 /messages 로 옮겨 가지 않는다(모듈이 붙기 전 기본 동작을 막는다).
   */
  async function messengerWindow() {
    const win = $('messenger-window');
    if (!win) return null;
    const wasOpen = win.open;
    for (let tick = 0; tick < 50 && !win.open; tick++) {
      if (tick % 10 === 0) {
        const opener = pick('button[data-messenger-open]') || pick('[data-messenger-open]') || document.querySelector('[data-messenger-open]');
        if (!opener) return null;
        const stay = (event) => event.preventDefault();
        window.addEventListener('click', stay, { once:true });
        opener.click();
        window.removeEventListener('click', stay);
      }
      await new Promise((done) => setTimeout(done, 100));
    }
    // 안내가 연 창은 모듈이 이어 열 자리(목록 ↔ 대화)를 정할 때까지 기다린다 — 보기(data-view)가 0.6초 그대로면, 길어도 4초
    if (win.open && !wasOpen) {
      let view = win.getAttribute('data-view'), same = 0;
      for (let tick = 0; tick < 40 && same < 6; tick++) {
        await new Promise((done) => setTimeout(done, 100));
        if (win.getAttribute('data-view') === view) same++; else { view = win.getAttribute('data-view'); same = 0; }
      }
    }
    return win.open ? win : null;
  }
  let starting = false;
  async function start(resume=false, origin=null, wanted=null) {
    if (starting) return;
    starting = true;
    try {
      context=wanted||currentContext(origin);
      const opened=context==='messenger' ? await messengerWindow() : openDialogs().pop();
      if (dialog) return;
      steps=(tours[context]||tours.workspace).map((s)=>({...s,el:pick(s.selector)})).filter((s)=>s.el && (!opened || opened.contains(s.el) || s.el===opened));
      if (!steps.length) { notice('이 화면은 메뉴의 사용 방법을 참고해 주세요. 업무 홈에서는 작업 · 프로젝트 · 문서를 차례로 안내해 드려요.'); return; }
      index=resume&&saved?.context===context ? Math.min(saved.step||0,steps.length-1) : 0;
      if (!dialog) makeDialog(); render(); persist('active').catch((e)=>notice(e.message));
    } finally { starting = false; }
  }
  function consent() {
    if(enabled()) { ask(true); return; }   // 길잡이가 켜져 있으면 제안 창의 본문이 곧 질문 카드(DESIGN 2.37 입구 ③)
    if(dialog) return; context=currentContext(); index=0; makeDialog(); dialog.classList.add('is-consent'); target=null; position();
    const card=dialog.querySelector('.usage-guide-card'), title=node('h2','모아 사용 방법을 안내해 드릴까요?'); title.id='usage-guide-title';
    const body=node('p','할 일 · 파일 · 문서 · 메신저를 화면의 버튼을 강조하며 차근차근 안내해 드릴게요. 언제든 건너뛰거나 오른쪽 아래 「? 사용 안내」에서 다시 볼 수 있어요.'); body.id='usage-guide-body';
    const actions=node('div','','usage-guide-actions'); actions.append(button('나중에 볼게요',()=>finish('dismissed')),button('네, 안내해 주세요',()=>{ close(); start(); },true));
    card.append(title,body,actions); actions.lastElementChild.focus();
  }
  document.addEventListener('click',(event)=>{
    if(!(event.target instanceof Element)) return;
    const origin = event.target.closest('[data-usage-guide-open]');
    if(!origin) return;
    if(dialog) return;
    // 길잡이가 켜졌으면 셸의 「? 사용 안내」는 질문 카드부터(창 안 「? 이 창 사용 안내」 · 업무 홈 머리 「사용 방법」은 지금처럼 그 투어)
    if(origin.closest('.usage-guide-launch') && (enabled() || saved === null)) {
      load().then(() => { if (dialog) return; if (enabled()) ask(false); else start(saved?.status==='active', origin); }).catch(() => start(false, origin));
      return;
    }
    load().catch(()=>{}); start(saved?.status==='active', event.target);
  });
  function addModalHelp() {
    for (const [id] of modalContexts) {
      const modal=$(id); if(!modal?.open || modal.querySelector('[data-usage-guide-open]')) continue;
      const help=node('button','? 이 창 사용 안내','usage-guide-modal-help'); help.type='button'; help.setAttribute('data-usage-guide-open','');
      // 떠 있는 창은 머리(.modal__head) 바로 아래 — 그 안의 대화 머리(header)는 목록 보기에서 숨는다
      const head=modal.matches('dialog.floating') ? modal.querySelector(':scope > .modal__head') : modal.querySelector('header'); if(head) head.insertAdjacentElement('afterend',help); else modal.prepend(help);
    }
  }
  new MutationObserver(addModalHelp).observe(document.body,{subtree:true,childList:true,attributes:true,attributeFilter:['open']}); addModalHelp();
  window.addEventListener('resize',schedulePosition); document.addEventListener('scroll',schedulePosition,true);

  /* ─── 길잡이 — 질문 카드 · 진행 카드 · 창 안 한 줄 (DESIGN 2.37) ──────────────────────────────── */
  let run = null, floatEl = null, ringEl = null, insetEl = null, targetEl = null, leaving = false, jFrame = 0, boardFind = null;
  const sleep = (ms) => new Promise((done) => setTimeout(done, ms));
  /** 메신저 옆 카드의 가장 좁은 폭 — 이보다 좁으면 글 · 단추가 깨져 가운데 위 · 아래로 */
  const BESIDE_MIN = 300;
  /** 길잡이의 보임 판정 — 그려진 상자가 있고 visibility 가 보임(옛 visible 은 [hidden] 조상만 보고 거짓이라, 넓은 화면에서 CSS 가 보이게 하는 사이드바 안 링크를 놓친다) */
  const shown = (el) => Boolean(el && el.isConnected && el.getClientRects().length && (typeof el.checkVisibility !== 'function' || el.checkVisibility({ visibilityProperty: true })));
  const pickShown = (selector) => Array.from(document.querySelectorAll(selector)).find(shown);
  /** 값이 바뀔 때만 쓴다 — hidden 등은 관찰자 attributeFilter 안이라, 같은 값을 되쓰면 그 기록이 evaluate → place → 되쓰기를 프레임마다 돌린다(독립 검토 지적 1) */
  const setHidden = (el, on) => { if (el && el.hidden !== on) el.hidden = on; };
  const setText = (el, text) => { if (el && el.textContent !== text) el.textContent = text; };
  const setAttr = (el, name, value) => { if (el && el.getAttribute(name) !== value) el.setAttribute(name, value); };
  const ids = Object.keys(journeys);
  const total = (def) => def.pages.reduce((n, p) => n + p.steps.length, 0);
  const ordinal = (def, page, at) => def.pages.slice(0, page).reduce((n, p) => n + p.steps.length, 0) + at + 1;
  const anchorsOf = (s) => (Array.isArray(s.anchor) ? s.anchor : [s.anchor]);
  const appBaseOf = (app) => (app === 'community' ? base : app === 'tbox' ? appBase : null);
  window.addEventListener('pagehide', () => { leaving = true; });
  /** 내 행 — 진행 중 하나 · 멈춘 것들 · 끝낸 것들(서버가 준 그대로) */
  function rowOf(id) {
    if (!enabled()) return null;
    if (isObject(jstate.active) && jstate.active.journey === id) return jstate.active;
    return (Array.isArray(jstate.paused) ? jstate.paused : []).find((r) => isObject(r) && r.journey === id) || null;
  }
  const allowedId = (id) => typeof id === 'string' && Object.prototype.hasOwnProperty.call(journeys, id) && enabled() && jcan[id] === true;

  /** 길잡이 PATCH — 옛 안내와 같은 줄 세우기 · 8초 · CSRF 갱신. 실패는 서버 문장 그대로(없으면 저장 실패 문장) */
  function jPatch(body) {
    const job = queued.then(async () => {
      if (!csrf) { try { await load(); } catch (_) { /* 아래 요청이 실패를 알린다 */ } }
      try {
        const r = await call(journeyEndpoint, 'PATCH', body);
        if (r.ok && isObject(r.data)) { takeJourneys(r.data, false); return { ok: true, status: r.status }; }
        return { ok: false, status: r.status, message: isObject(r.data) && typeof r.data.error === 'string' ? r.data.error : SAVE_FAIL };
      } catch (_) { return { ok: false, status: 0, message: SAVE_FAIL }; }
    });
    queued = job.catch(() => {});
    return job;
  }
  function jSave(page, at, status = 'active') {
    if (!run) return Promise.resolve({ ok: true });
    return jPatch({ journey: run.id, status, page, step: at }).then((r) => { if (!r.ok && run) error(r.message); return r; });
  }
  /** 서버 사실 — GET 한 번 + 2초 간격 다시 두 번(tries 1 이면 한 번만) */
  async function factCheck(id, tries = 3) {
    for (let k = 0; k < tries; k++) {
      if (k) await sleep(2000);
      try { await request(); } catch (_) { continue; }
      if (enabled() && isObject(jstate.active) && jstate.active.journey === id && isObject(jstate.active.fact) && jstate.active.fact.ok === true) return true;
    }
    return false;
  }
  /** 업무 쪽 「공지」 게시판 하나 — 목록 창구(공지 먼저 · 활성 먼저 · 번호 순)의 첫 활성 공지. 이 화면 동안 기억한다 */
  function findBoard() {
    if (!boardFind) {
      boardFind = call(base + '/api/boards?area=work').then((r) => {
        const list = r.ok && isObject(r.data) && Array.isArray(r.data.boards) ? r.data.boards : null;
        if (!list) return { state: 'error' };
        const b = list.find((x) => isObject(x) && x.board_kind === 'notice' && x.is_active === true);
        if (!b || !Number.isInteger(b.id)) return { state: 'none' };
        return { state: b.can_write === true ? 'ok' : 'denied', id: b.id, name: String(b.name) };
      }).catch(() => ({ state: 'error' }));
    }
    return boardFind;
  }
  /** 화면 판정 — 고정 주소는 선택자와 경로 둘 다 · 문서는 사실의 ref · 게시판은 찾은 번호 · 메신저는 창이 DOM 에 있으면 */
  async function here(page) {
    if (page.here === 'messenger') return Boolean($('messenger-window'));
    if (page.here === 'doc') {
      const ref = enabled() && isObject(jstate.active) && isObject(jstate.active.fact) ? jstate.active.fact.ref : null;
      return typeof ref === 'string' && document.body.matches('[data-page="community-page"]:not([data-project-view])') && location.pathname === base + '/p/' + ref;
    }
    if (page.here === 'board') {
      if (!document.body.matches('[data-page="community-board"]:not([data-area="cafe"])')) return false;
      const m = location.pathname.slice(base.length).match(/^\/b\/(\d+)$/);
      if (!m || !location.pathname.startsWith(base + '/')) return false;
      const b = await findBoard();
      return (b.state === 'ok' || b.state === 'denied') && b.id === Number(m[1]);
    }
    const app = appBaseOf(page.app);
    return Boolean(app && document.querySelector(page.here)) && location.pathname === app + page.path;
  }
  /** 그 화면으로 가는 주소 — 없으면 null(메신저 · 아직 ref 가 없는 문서) */
  async function pageHref(page) {
    if (page.here === 'doc') {
      const ref = enabled() && isObject(jstate.active) && isObject(jstate.active.fact) ? jstate.active.fact.ref : null;
      return typeof ref === 'string' ? base + '/p/' + ref : null;
    }
    if (page.here === 'board') { const b = await findBoard(); return b.state === 'ok' || b.state === 'denied' ? base + '/b/' + b.id : null; }
    const app = appBaseOf(page.app);
    return app && page.path ? app + page.path : null;
  }
  /** 화면 이름 — 게시판 화면은 창구가 준 이름(「<게시판 이름>」) · 그 밖은 정의의 이름(업무 홈 · 자료실 · 내 문서 · 문서 · 메신저 · 계정 관리) */
  function nameOf(page, board) {
    return page.here === 'board' && board && (board.state === 'ok' || board.state === 'denied') ? '「' + board.name + '」' : page.name;
  }
  async function pageName(page) { return nameOf(page, page.here === 'board' ? await findBoard() : null); }
  const fill = (text, b) => (b && b.name ? text.split('{name}').join(b.name) : text);

  /* ─── 그리기 ─── */
  function ring() {
    if (!ringEl) {
      ringEl = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); ringEl.classList.add('usage-guide-ring'); ringEl.setAttribute('aria-hidden', 'true');
      ringEl.append(document.createElementNS(ringEl.namespaceURI, 'rect')); document.body.append(ringEl);
    }
    return ringEl;
  }
  function ringAt(r) {
    const box = ring().firstChild;
    const v = r ? { x: r.left - 4.5, y: r.top - 4.5, width: r.width + 9, height: r.height + 9 } : { x: 0, y: 0, width: 0, height: 0 };
    for (const [k, value] of Object.entries(v)) box.setAttribute(k, String(Math.max(k === 'width' || k === 'height' ? 0 : -20, Math.round(value * 10) / 10)));
  }
  function card() {
    if (!floatEl) {
      floatEl = node('section', '', 'usage-guide-float'); floatEl.setAttribute('role', 'region'); floatEl.setAttribute('aria-label', '안내');
      // 비모달 — Esc 는 초점이 카드 안일 때만(= 나중에). 다른 키는 앱 몫
      floatEl.addEventListener('keydown', (event) => { if (event.key !== 'Escape' || !run) return; event.preventDefault(); event.stopPropagation(); if (run.mode === 'done') teardown(); else later(); });
      document.body.append(floatEl);
    }
    return floatEl;
  }
  function setTarget(el) {
    if (targetEl === el) return;
    if (targetEl) targetEl.classList.remove('usage-guide-target');
    targetEl = el || null;
    if (targetEl) targetEl.classList.add('usage-guide-target');
  }
  function dropInset() { if (insetEl) { insetEl.remove(); insetEl = null; } }
  /** 창 안 한 줄 — 그 앱 창 머리 바로 아래(메신저는 :scope > .modal__head) · 단추 0 */
  function inset(box, n, m, text) {
    const head = box.querySelector(':scope > .modal__head') || box.querySelector('.modal__head');
    if (!insetEl) { insetEl = node('p', '', 'usage-guide-inset'); insetEl.setAttribute('role', 'status'); }
    if (insetEl.textContent !== n + ' / ' + m + ' ' + text) insetEl.replaceChildren(node('b', n + ' / ' + m), document.createTextNode(' ' + text));
    if (head) { if (head.nextElementSibling !== insetEl) head.insertAdjacentElement('afterend', insetEl); }
    else if (insetEl.parentElement !== box) box.prepend(insetEl);
    setHidden(floatEl, true);
    ringAt(null);
  }
  /**
   * 진행 카드 한 장 — parts: { progress, title, text, error, actions: [[글자, 함수, 주?] | {href, text}], chips: [[글자, 함수]] }.
   * 초점은 이미 카드 안이었을 때만 새 주 단추로(그릴 때 focus() 0 · Tab 가둠 0).
   */
  function paint(parts) {
    dropInset();
    const c = card();
    const had = c.contains(document.activeElement);
    const folded = c.classList.contains('is-folded') && innerWidth < 720 && editing(document.activeElement);
    const chipRow = node('div', '', 'usage-guide-chips');
    const chip = node('button', '', ''); chip.type = 'button';
    chip.append(document.createTextNode('안내 ' + (parts.short || '') + ' '), ariaHidden('▾'));
    chip.addEventListener('click', () => fold(false));
    chipRow.append(chip); chipRow.hidden = !folded;
    const body = node('div');
    body.append(node('p', parts.progress, 'usage-guide-progress'));
    if (parts.title) body.append(node('h2', parts.title));
    const text = node('p', parts.text); text.setAttribute('role', 'status'); body.append(text);
    if (parts.error) { const e = node('p', parts.error, 'usage-guide-error'); e.setAttribute('role', 'status'); body.append(e); }
    if (parts.chips && parts.chips.length) {
      const row = node('div', '', 'usage-guide-chips');
      for (const [label, fn] of parts.chips) row.append(button(label, fn));
      body.append(row);
    }
    const actions = node('div', '', 'usage-guide-actions');
    let primary = null;
    for (const a of parts.actions) {
      if (!Array.isArray(a)) { const link = node('a', a.text, 'usage-guide-primary'); link.href = a.href; actions.append(link); primary = link; continue; }
      const b = button(a[0], a[1], a[2] === true); actions.append(b); if (a[2] === true) primary = b;
    }
    body.append(actions); body.hidden = folded;
    c.replaceChildren(chipRow, body);
    c.classList.toggle('is-folded', folded);
    setHidden(c, false);
    if (run) run.short = parts.short || '';
    place();
    const focusTo = primary || actions.lastElementChild;   // 주 단추가 없는 하는 단계는 마지막 단추(초점이 카드 밖으로 새지 않게)
    if (had && focusTo) focusTo.focus({ preventScroll: true });
  }
  function ariaHidden(text) { const s = node('span', text); s.setAttribute('aria-hidden', 'true'); return s; }
  const editing = (el) => el instanceof Element && !(floatEl && floatEl.contains(el)) &&
    (el.matches('textarea,select,input:not([type=checkbox]):not([type=radio]):not([type=file]):not([type=button]):not([type=submit])') || el.isContentEditable);
  function fold(on) {
    if (!floatEl) return;
    floatEl.classList.toggle('is-folded', on);
    const [chipRow, body] = floatEl.children;
    setHidden(chipRow, !on);
    setHidden(body, on);
    place();
  }
  /** 열린 showModal 앱 창(재인증 · 확인 · 다른 앱 창)이 대상을 품지 않으면 카드는 숨고 기다린다 */
  function blocked() {
    for (const d of document.querySelectorAll('dialog[open]')) {
      if (d === dialog) return true;
      let modal = false;
      try { modal = d.matches(':modal'); } catch (_) { modal = !d.matches('dialog.floating'); }
      if (modal && !(targetEl && d.contains(targetEl))) return true;
    }
    return false;
  }
  /**
   * 자리 — 아래 가운데 · 대상이 아래 반이면 위(.is-top) · ≥720 메신저가 열려 있으면 카드 오른쪽 끝을 메신저 왼쪽 −16 에 맞춘다
   * (.is-beside — 메신저 기본 자리 기준 · 폭 min(540px, 메신저 왼쪽 − 32px)). 그 폭이 모자라거나(BESIDE_MIN 미만) 메신저를 옮겨 끝이 맞지 않으면
   * 가운데 위 · 아래로. 메신저 · 대상과 겹치지 않는 자리가 늘 먼저.
   */
  function place() {
    if (!run) return;
    const r = targetEl && shown(targetEl) ? targetEl.getBoundingClientRect() : null;
    const hide = blocked();
    if (floatEl && !insetEl) setHidden(floatEl, hide || run.mode === 'hidden');
    if (!floatEl || floatEl.hidden || insetEl) { ringAt(!hide && r && !insetEl ? r : null); return; }
    ringAt(r);
    const h = innerHeight;
    const low = r ? (r.top + r.bottom) / 2 > h / 2 : false;
    const win = $('messenger-window');
    const m = innerWidth >= 720 && win && win.open ? win.getBoundingClientRect() : null;
    const overlap = (a, b) => b && a.left < b.right && b.left < a.right && a.top < b.bottom && b.top < a.bottom;
    const besideOk = Boolean(m && m.left - 32 >= BESIDE_MIN);
    const tries = m ? [...(besideOk ? [[true, low], [true, !low]] : []), [false, !low], [false, low]] : [[false, low], [false, !low]];
    let chosen = null;
    for (const [beside, top] of tries) {
      floatEl.classList.toggle('is-beside', beside); floatEl.classList.toggle('is-top', top);
      const c = floatEl.getBoundingClientRect();
      if (beside && Math.abs(c.right - (m.left - 16)) > 1) continue;   // 옮긴 메신저 — 메신저 쪽에 붙지 않으면 쓰지 않는다
      if (!overlap(c, m) && !overlap(c, r)) { chosen = [beside, top]; break; }
      if (!chosen && !overlap(c, m)) chosen = [beside, top];
    }
    const [beside, top] = chosen || tries[0];
    floatEl.classList.toggle('is-beside', beside); floatEl.classList.toggle('is-top', top);
  }
  function error(text) {
    if (floatEl && !floatEl.hidden) {
      const body = floatEl.children[1];
      let e = body ? body.querySelector('.usage-guide-error') : null;
      if (body && !e) { e = node('p', '', 'usage-guide-error'); e.setAttribute('role', 'status'); body.insertBefore(e, body.lastElementChild); }
      if (e) { e.textContent = text; return; }
    }
    notice(text);
  }

  /* ─── 한 번의 진행 ─── */
  const watcher = new MutationObserver(() => { if (!run) return; cancelAnimationFrame(jFrame); jFrame = requestAnimationFrame(evaluate); });
  function paintLaunch() {
    const launch = root.querySelector('.usage-guide-launch');
    if (!launch || !enabled()) return;
    const label = launch.querySelector('span');
    const a = isObject(jstate.active) && allowedId(jstate.active.journey) ? jstate.active : null;
    if (a && !run) {
      const def = journeys[a.journey];
      const pg = Math.min(Math.max(0, a.page | 0), def.pages.length - 1);
      const at = ordinal(def, pg, Math.min(Math.max(0, a.step | 0), def.pages[pg].steps.length - 1)) + '/' + total(def);
      setText(label, WORDS.resume + ' ' + at);
      launch.classList.add('is-paused'); setAttr(launch, 'aria-label', WORDS.resume + ' ' + at);
    } else {
      setText(label, '사용 안내');
      launch.classList.remove('is-paused'); setAttr(launch, 'aria-label', '사용 안내 열기');
    }
    setHidden(launch, Boolean(run));
  }
  function begin(id) {
    teardown();
    run = { id, def: journeys[id], page: 0, step: 0, gen: 0, mode: 'starting', cleanup: [], timers: [] };
    watcher.observe(document.body, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ['open', 'hidden', 'data-view', 'data-page-editing', 'aria-pressed'] });
    paintLaunch();
  }
  function teardown() {
    if (!run) return;
    clearStep();
    watcher.disconnect();
    if (floatEl) { floatEl.remove(); floatEl = null; }
    if (ringEl) { ringEl.remove(); ringEl = null; }
    dropInset(); setTarget(null);
    run = null;
    paintLaunch();
  }
  function clearStep() {
    if (!run) return;
    for (const f of run.cleanup.splice(0)) { try { f(); } catch (_) { /* 이미 떨어진 요소 */ } }
    for (const t of run.timers.splice(0)) clearTimeout(t);
  }
  const cur = () => run.def.pages[run.page].steps[run.step];
  function resolve(s) {
    for (const sel of anchorsOf(s)) {
      const css = sel.includes('{board}') ? (run.board && run.board.id ? sel.split('{board}').join(String(run.board.id)) : null) : sel;
      if (!css) continue;
      const el = pickShown(css);
      if (el) return el;
    }
    return null;
  }
  /**
   * 진행 머리 「n / m · <과업 제목>」 — 「· 다음: <화면>」은 next 가 있을 때만(사람이 지금 있는 화면과 다른 화면에서 할 일이 이어질 때만 ·
   * DESIGN 2.37 「진행 카드」 · 사용자 2026-10-26). 단계 카드는 nextScreen() · 가기 카드는 가야 할 화면 · 그 밖 카드는 null.
   */
  function progressOf(page, at, next) {
    const n = ordinal(run.def, page, at), m = total(run.def);
    return { progress: n + ' / ' + m + ' · ' + run.def.label + (next ? ' · 다음: ' + next : ''), short: n + '/' + m, n, m };
  }
  /** 지금 단계 다음 단계의 화면이 지금 화면과 다르면 그 화면 이름 — 같은 화면(창 안 단계 포함)이거나 마지막 단계면 null */
  function nextScreen(page, at) {
    const pages = run.def.pages;
    if (at + 1 < pages[page].steps.length || page + 1 >= pages.length) return null;
    return nameOf(pages[page + 1], run.board);
  }
  const boxOf = (s) => (s.box ? document.querySelector(s.box) : null);
  const boxOpen = (s) => { const b = boxOf(s); return Boolean(b && b.open); };

  /** 그 화면 · 단계를 연다. 다른 화면이면 「이 화면으로 가기」 카드. opts.notice = 「아직 결과가 없어요」 줄 · opts.nosave = 이미 저장된 자리 */
  async function show(page, at, opts = {}) {
    if (!run) return;
    clearStep();
    const gen = ++run.gen;
    const def = run.def, pg = def.pages[page];
    run.page = page; run.step = Math.min(Math.max(0, at), pg.steps.length - 1); run.mode = 'step'; run.notice = opts.notice || '';
    if (pg.find) {
      run.board = await findBoard();
      if (!run || run.gen !== gen) return;
    }
    if (!(await here(pg))) {
      if (!run || run.gen !== gen) return;
      return goCard(page, run.step, opts);
    }
    if (!run || run.gen !== gen) return;
    if (pg.find && run.board.state !== 'ok') return emptyCard(page, run.step, run.board.state);
    const s = cur();
    // 창 안 단계인데 그 창이 닫혀 있으면(새로 고침 · 이어 하기) 창을 여는 단계로 — 조용히
    if (s.box && !boxOpen(s) && Number.isInteger(s.back)) return show(page, s.back, { notice: opts.notice });
    // 이미 저장된 자리면 다시 쓰지 않는다 — 다만 {navigate:true} 단계는 다음 화면 0단계를 미리 저장(다음 화면이 이어 받는다)
    const spot = saveSpot(page, run.step);
    if (!opts.nosave || spot[0] !== page) jSave(...spot);
    draw(gen);
  }
  /** 저장할 자리 — {navigate:true} 단계는 그릴 때 다음 화면 0단계를 미리 저장(다음 화면이 이어 받는다) */
  function saveSpot(page, at) {
    const s = run.def.pages[page].steps[at];
    return isObject(s.until) && s.until.navigate && page + 1 < run.def.pages.length ? [page + 1, 0] : [page, at];
  }
  /** 같은 화면 안에서 단계를 옮긴다(동기 — 앞질러 가기 · 제출 신호가 놓치지 않게) */
  function jump(at, opts = {}) {
    if (!run) return;
    clearStep();
    const gen = ++run.gen;
    run.step = at; run.mode = 'step'; run.notice = opts.notice || '';
    const s = cur();
    if (s.box && !boxOpen(s) && Number.isInteger(s.back) && s.back !== at) return jump(s.back, opts);
    jSave(...saveSpot(run.page, at));
    draw(gen);
  }
  /** 이 단계 그리기 — 대상 찾기(없으면 3초 관찰) · 창 안이면 한 줄 · 넘김 신호 걸기 */
  function draw(gen) {
    if (!run || run.gen !== gen) return;
    clearStep();
    const s = cur(), el = resolve(s);
    if (!el) {
      if (s.when === 'visible') { skipFrom(run.step); return; }
      if (s.go && goNow(s.go)) { goCard(run.page, run.step, { to: run.page + 1 }); return; }
      // 대상이 없으면 3초 기다린다 — 그동안 글은 보이고 테두리만 없다. 빈 상태는 3초가 끝난 뒤에만 판정
      setTarget(null); run.mode = 'wait';
      paintStep(s, null);
      run.timers.push(setTimeout(() => {
        if (!run || run.gen !== gen || run.mode !== 'wait') return;
        if (resolve(s)) { draw(gen); return; }
        if (s.go) { goCard(run.page, run.step, { to: run.page + 1 }); return; }
        if (s.empty) { emptyCard(run.page, run.step, 'empty'); return; }
        missingCard();
      }, 3000));
      return;
    }
    run.mode = 'step';
    setTarget(el);
    const r = el.getBoundingClientRect();
    if (r.bottom < 0 || r.top > innerHeight || r.right < 0 || r.left > innerWidth) el.scrollIntoView({ block: 'center', behavior: 'instant' });
    paintStep(s, el);
    arm(s, el, gen);
  }
  /** 묶음이 구조적으로 안 보인다(폰 서랍 · 닫힌 묶음 · 접힌 사이드바) — 링크가 그려지길 기다릴 까닭이 없다 */
  function goNow(go) {
    const box = document.querySelector(go.box);
    return innerWidth < (go.min || 0) || !box || (box instanceof HTMLDetailsElement && !box.open) || Boolean(go.off && document.querySelector(go.off));
  }
  function stepText(s) {
    if (s.alt && !document.querySelector(s.alt.unless)) return s.alt.text;
    return fill(s.text, run.board);
  }
  function paintStep(s, el) {
    const pg = run.def.pages[run.page];
    const p = progressOf(run.page, run.step, nextScreen(run.page, run.step));
    const box = boxOf(s);
    if (s.box && box && box.open) {
      setTarget(el);
      inset(box, p.n, p.m, run.notice || stepText(s));
      run.short = p.short;
      return;
    }
    const last = run.page === run.def.pages.length - 1 && run.step === pg.steps.length - 1;
    const actions = s.kind === 'read'
      ? [[WORDS.later, later], [last ? WORDS.finish : WORDS.next, () => readNext(s), true]]
      : [[WORDS.later, later], [WORDS.skip, () => advance()]];   // 하는 단계 — 둘 다 테두리 · 검정 0(주요 동작은 테두리를 친 진짜 단추 · DESIGN 2.37 「단추」)
    paint({ progress: p.progress, short: p.short, title: s.title, text: stepText(s), error: run.notice, actions });
  }
  /** 「다음」(읽기 단계) — message-send 첫 단계만 메신저 창을 한 번 연다(예외 하나 · 이미 열려 있으면 부르지 않음) */
  async function readNext(s) {
    if (!run) return;
    const gen = run.gen;
    if (s.opens === 'messenger') {
      const win = $('messenger-window');
      if (win && !win.open) { await messengerWindow(); }
      if (!run || run.gen !== gen) return;   // 창이 열리며 넘김 신호가 이미 다음으로 보냈다
      if (win && win.open) { advance(); }
      return;
    }
    advance();
  }
  /** 넘김 신호 걸기 — 단계마다 새로 · clearStep 이 뗀다 */
  function arm(s, el, gen) {
    const on = (target, type, fn, capture = false) => { target.addEventListener(type, fn, capture); run.cleanup.push(() => target.removeEventListener(type, fn, capture)); };
    const u = s.until;
    if (isObject(u) && u.event) on(window, u.event, () => { if (run && run.gen === gen) passed(gen); });
    if (isObject(u) && u.value) {
      let t = 0;
      run.cleanup.push(() => clearTimeout(t));
      on(el, 'change', () => { if (run && run.gen === gen) passed(gen); }, true);
      on(el, 'input', (event) => {
        const src = event.target;
        if (src instanceof HTMLSelectElement || (src instanceof HTMLInputElement && ['radio', 'checkbox', 'file'].includes(src.type))) return;
        clearTimeout(t);
        t = setTimeout(() => {
          if (!run || run.gen !== gen) return;
          const v = src instanceof HTMLInputElement || src instanceof HTMLTextAreaElement ? src.value : (el.isContentEditable ? el.textContent : (src instanceof Element ? src.textContent : ''));
          if (String(v || '').trim() !== '') passed(gen);
        }, 800);
      }, true);
    }
    if (isObject(u) && u.text) run.textAt = (document.querySelector(u.text) || {}).textContent || '';
    // 앞질러 가기 · 제출 — 같은 화면의 뒤 단계 신호(위임 하나 · 문서에 capture)
    on(document, 'pointerdown', ahead, true);
    on(document, 'focusin', ahead, true);
    on(document, 'submit', submitted, true);
    on(document, 'focusin', (event) => { if (innerWidth < 720 && editing(event.target) && floatEl && !floatEl.hidden) fold(true); }, true);
    // 바로 맞는 선택자 신호(이미 열린 창 · 복원된 대화 · 편집 모드로 열린 문서)
    if (typeof u === 'string' && document.querySelector(u)) { queueMicrotask(() => { if (run && run.gen === gen) passed(gen); }); }
  }
  function ahead(event) {
    if (!run || run.mode !== 'step') return;
    const s = cur(), t = event.target;
    if (!s.ahead || !(t instanceof Element) || !t.closest(s.ahead)) return;
    const list = run.def.pages[run.page].steps;
    for (let k = run.step + 1; k < list.length; k++) { if (anchorsOf(list[k]).includes(s.ahead)) { jump(k); return; } }
  }
  function submitted(event) {
    if (!run || run.mode !== 'step' || !(event.target instanceof Element)) return;
    const list = run.def.pages[run.page].steps;
    for (let k = run.step; k < list.length; k++) {
      const u = list[k].until;
      if (isObject(u) && u.submit && event.target.matches(u.submit)) { if (k !== run.step) jump(k); passed(run.gen); return; }
    }
  }
  /** 넘김 신호가 왔다 — 사실이 필요하면 서버에 다시 묻고, 거짓이면 「아직 결과가 없어요」 + 돌아갈 단계 */
  async function passed(gen) {
    if (!run || run.gen !== gen || run.busy) return;
    const s = cur();
    run.busy = true;
    try {
      if (s.fact) {
        run.mode = 'checking';
        const ok = await factCheck(run.id);
        if (!run || run.gen !== gen) return;
        if (!ok) { failed(s); return; }
      }
      advance();
    } finally { if (run) run.busy = false; }
  }
  function failed(s) {
    const back = Number.isInteger(s.retry) ? s.retry : run.step;
    const text = s.fail || NO_RESULT;
    if (back === run.step) { run.mode = 'step'; run.notice = text; paintStep(s, targetEl); return; }
    jump(back, { notice: text });
  }
  /** 「보일 때만」 단계가 안 보인다 — 그 단계와 그 단계로 돌아가는 창 안 단계들을 건너뛰고 다음(없으면 완성 판정) */
  function skipFrom(at) {
    const list = run.def.pages[run.page].steps;
    let k = at + 1;
    while (k < list.length && list[k].back === at) k++;
    if (k < list.length) { jump(k); return; }
    run.step = list.length - 1;
    if (run.page + 1 < run.def.pages.length) { show(run.page + 1, 0); return; }
    complete();
  }
  /** 다음 단계 · 다음 화면 · 완성 */
  function advance() {
    if (!run) return;
    const pg = run.def.pages[run.page];
    if (run.step + 1 < pg.steps.length) { jump(run.step + 1); return; }
    if (run.page + 1 < run.def.pages.length) { show(run.page + 1, 0); return; }
    complete();
  }
  /** 관찰자 — 선택자 신호 · 창이 닫힘 · 늦게 그려진 대상 · 자리 */
  function evaluate() {
    if (!run || leaving) return;
    const s = cur();
    if (run.mode === 'wait') { if (resolve(s)) draw(run.gen); else place(); return; }
    if (run.mode !== 'step') { place(); return; }
    const u = s.until;
    if (typeof u === 'string' && document.querySelector(u)) { passed(run.gen); return; }
    if (isObject(u) && u.text) { const now = (document.querySelector(u.text) || {}).textContent || ''; if (now !== run.textAt) { passed(run.gen); return; } }
    if (s.box && !boxOpen(s)) { boxClosed(run.gen); return; }
    const el = resolve(s);
    if (el !== targetEl) {
      if (el) { setTarget(el); if (s.box && boxOpen(s)) paintStep(s, el); }
      else if (!s.box) { draw(run.gen); return; }
    }
    if (s.box && boxOpen(s) && insetEl && !insetEl.isConnected) paintStep(s, targetEl);
    place();
  }
  /** 창 안 단계 도중 그 창이 닫혔다 — 사실을 한 번 보고, 참이면 창 다음 단계로 · 거짓이면 「아직 결과가 없어요」 + 창을 여는 단계로 */
  async function boxClosed(gen) {
    const s = cur();
    run.mode = 'checking'; dropInset();
    if (isObject(s.until) && s.until.navigate) { await sleep(1200); if (!run || run.gen !== gen || leaving) return; }
    const ok = await factCheck(run.id, 1);
    if (!run || run.gen !== gen) return;
    if (ok) {
      const list = run.def.pages[run.page].steps;
      let k = run.step; while (k + 1 < list.length && list[k + 1].box === s.box) k++;
      run.step = k; advance(); return;
    }
    if (Number.isInteger(s.back)) { jump(s.back, { notice: NO_RESULT }); return; }
    run.mode = 'step'; run.notice = NO_RESULT; paintStep(s, targetEl);
  }

  /* ─── 카드 종류 ─── */
  /** 다른 화면 단계 — 「나중에」 · 검정 링크 「이 화면으로 가기 → <앱 이름>」(길잡이 자신의 a[href] — 앱 단추 누름 0) */
  async function goCard(page, at, opts = {}) {
    if (!run) return;
    const gen = run.gen;
    const def = run.def;
    const pg = def.pages[page];
    if (!pg) { complete(); return; }
    // opts.to — 이 단계의 대상(게시판 링크)이 안 보일 때 기다리지 않고 다음 화면으로 가는 링크(글은 이 단계 그대로)
    const leap = Number.isInteger(opts.to);
    const to = def.pages[leap ? opts.to : page];
    const href = to ? await pageHref(to) : null;
    if (!run || run.gen !== gen) return;
    // 그 화면의 주소가 아직 없다(결과 전의 문서 · 못 찾은 게시판) — 그 화면으로 이어지는 앞 단계로(그 자리를 저장해 다음 화면이 이어 받게)
    if (!href && !leap && Array.isArray(pg.prev)) { show(pg.prev[0], pg.prev[1], Object.assign({}, opts, { nosave: false })); return; }
    run.page = page; run.step = at; run.mode = 'go'; setTarget(null);
    if (!opts.nosave) jSave(...(leap ? saveSpot(page, at) : [page, at]));
    const name = to ? await pageName(to) : pg.name;
    if (!run || run.gen !== gen) return;
    const s = pg.steps[at];
    const p = progressOf(page, at, name);
    const actions = href ? [[WORDS.later, later], { href, text: WORDS.go + ' → ' + name }] : [[WORDS.later, later], [WORDS.skip, () => advance(), true]];
    paint({ progress: p.progress, short: p.short, title: s.title, text: fill(s.text, run.board), error: opts.notice || '', actions });
  }
  /** 3초 뒤에도 대상이 없다 — 소리 없이 건너뛰지 않는다(n / m 그대로) */
  async function missingCard() {
    if (!run) return;
    const gen = run.gen, s = cur();
    run.mode = 'missing'; setTarget(null);
    const pg = run.def.pages[run.page];
    const href = await pageHref(pg);
    if (!run || run.gen !== gen) return;
    const p = progressOf(run.page, run.step, null);   // 「안 보여요」 카드 — 「다음:」 0(단추 「이 화면으로 가기 → <이름>」이 그 화면을 말한다)
    const box = boxOf(s);
    if (s.box && box && box.open) { inset(box, p.n, p.m, '이 단추가 지금 안 보여요 — ' + stepText(s)); return; }
    const actions = [[WORDS.skip, () => advance()], [WORDS.later, later],
      href ? { href, text: WORDS.go + ' → ' + await pageName(pg) } : [WORDS.recheck, () => { if (run) show(run.page, run.step); }, true]];
    if (!run || run.gen !== gen) return;
    paint({ progress: p.progress, short: p.short, title: '이 단추가 지금 안 보여요', text: stepText(s), actions });
  }
  /** 빈 상태 · 자격 — 「아직 …이 없어요 — <지금 할 수 있는 한 가지>」 */
  async function emptyCard(page, at, state) {
    if (!run) return;
    const gen = run.gen;
    run.page = page; run.step = at; run.mode = 'empty'; setTarget(null);
    const pg = run.def.pages[page], s = pg.steps[at];
    const text = state === 'denied' ? '이 게시판엔 글을 쓸 자격이 없어요 — 관리자에게 물어보세요'
      : state === 'none' ? '공지 게시판이 아직 없어요 — 관리자가 만들면 여기 생겨요'
      : state === 'empty' && s.empty ? s.empty.text : '이 단추가 지금 안 보여요';
    if (!run || run.gen !== gen) return;
    const p = progressOf(page, at, null);   // 빈 상태 · 자격 — 「다음:」 0
    const box = boxOf(s);
    if (s.box && box && box.open) { inset(box, p.n, p.m, text); return; }
    paint({ progress: p.progress, short: p.short, title: s.title, text, actions: [[WORDS.later, later], [WORDS.recheck, () => { boardFind = null; show(page, at); }, true]] });
  }
  /** 마지막 단계 뒤 한 번 — 서버가 결과를 다시 잰 뒤에만 완성 카드 */
  async function complete() {
    if (!run) return;
    const gen = ++run.gen;
    clearStep();
    run.mode = 'checking'; setTarget(null); dropInset();
    const r = await jPatch({ journey: run.id, status: 'completed', page: run.page, step: run.step });
    if (!run || run.gen !== gen) return;
    if (r.ok) { doneCard(); return; }
    if (r.status === 400) { noResultCard(r.message); return; }
    run.mode = 'step'; paintStep(cur(), null); error(r.message);
  }
  function doneCard() {
    const def = run.def, m = total(def);
    run.mode = 'done';
    const chips = def.done.chip && allowedId(def.done.chip) ? [[journeys[def.done.chip].label, () => { const id = def.done.chip; teardown(); startJourney(id); }]] : [];
    paint({ progress: m + ' / ' + m + ' · ' + def.label, short: m + '/' + m, title: '', text: def.done.text, chips, actions: [[WORDS.finish, teardown, true]] });
  }
  function noResultCard(message) {
    const p = progressOf(run.page, run.step, null);
    run.mode = 'noresult';
    paint({ progress: p.progress, short: p.short, title: NO_RESULT, text: message || NO_RESULT, actions: [[WORDS.later, later], [WORDS.recheck, () => complete(), true]] });
  }
  /** 이어 하기 줄을 눌렀는데 결과가 이미 있다 — 「마치기」 = 완성 · 「다시 하기」 = 처음부터 */
  function alreadyCard() {
    const p = progressOf(run.page, run.step, null);
    run.mode = 'already';
    const id = run.id;
    paint({ progress: p.progress, short: p.short, title: '결과가 이미 있어요', text: '시작한 뒤에 한 일이 이미 남아 있어요. 「마치기」를 누르면 끝낸 일로 남아요.',
      actions: [[WORDS.redo, () => startJourney(id, { restart: true })], [WORDS.later, later], [WORDS.finish, () => complete(), true]] });
  }
  /** 「나중에」 · Esc — 멈춤(화면 · 단계 보존) · 카드를 닫는다 */
  function later() {
    if (!run) return;
    const { id, page, step: at } = run;
    jPatch({ journey: id, status: 'paused', page, step: at }).then((r) => { if (!r.ok) notice(r.message); paintLaunch(); });
    teardown();
  }

  /* ─── 시작 · 이어 하기 ─── */
  /** 과업 시작 — 진행 중 · 멈춘 행이면 저장 자리 그대로(시작 시각 그대로), 없거나 끝낸 · 그만둔 행이면 0쪽 0단계 */
  async function startJourney(id, opts = {}) {
    if (!allowedId(id)) return;
    const row = rowOf(id);
    const body = row && !opts.restart ? { journey: id, status: 'active', page: row.page | 0, step: row.step | 0 } : { journey: id, status: 'active', page: 0, step: 0 };
    if (opts.restart) body.restart = true;
    begin(id);
    const r = await jPatch(body);
    if (!run || run.id !== id) return;
    if (!r.ok) { teardown(); notice(r.message); return; }
    const a = isObject(jstate.active) && jstate.active.journey === id ? jstate.active : { page: body.page, step: body.step };
    if (opts.resume && isObject(a.fact) && a.fact.ok === true) {
      run.page = Math.min(a.page | 0, run.def.pages.length - 1); run.step = Math.min(a.step | 0, run.def.pages[run.page].steps.length - 1);
      alreadyCard(); return;
    }
    await openAt(a.page | 0, a.step | 0);
  }
  /** 저장 자리부터 — 지금 화면이 그 화면(이후 화면 포함)이면 그 자리에서, 아니면 「이 화면으로 가기」 */
  async function openAt(page, at) {
    if (!run) return;
    const def = run.def;
    page = Math.min(Math.max(0, page), def.pages.length - 1);
    for (let p = page; p < def.pages.length; p++) {
      if (def.pages[p].find) { run.board = await findBoard(); if (!run) return; }
      if (await here(def.pages[p])) { if (!run) return; show(p, p === page ? at : 0, { nosave: p === page }); return; }
      if (!run) return;
    }
    show(page, at, { nosave: true });
  }
  /** 로드 — 진행 중 여정이 이 화면(저장 화면 이상 — 앞으로만)이면 이어 연다. 아니면 「? 사용 안내」 글자가 「이어 하기 n/m」 */
  async function resumeOnLoad() {
    const a = enabled() && isObject(jstate.active) ? jstate.active : null;
    if (!a || !allowedId(a.journey)) return false;
    const def = journeys[a.journey];
    const from = Math.min(Math.max(0, a.page | 0), def.pages.length - 1);
    for (let p = from; p < def.pages.length; p++) {
      if (def.pages[p].find) await findBoard();
      if (await here(def.pages[p])) {
        begin(a.journey);
        if (def.pages[p].find) run.board = await findBoard();
        if (p === from) show(p, a.step | 0, { nosave: true }); else show(p, 0);
        return true;
      }
    }
    return false;
  }

  /* ─── 질문 카드 「무엇을 하고 싶으세요?」 — 입구 셋(홈 카드는 주소 꼬리 · 「? 사용 안내」 · 첫 로그인 제안)이 같은 질문 ─── */
  function ask(offer) {
    if (dialog || !enabled()) return;
    context = currentContext(); index = 0;
    makeDialog(offer ? undefined : () => close());
    dialog.classList.add('is-consent'); dialog.removeAttribute('aria-describedby'); target = null; position();
    const cardEl = dialog.querySelector('.usage-guide-card');
    const title = node('h2', ASK); title.id = 'usage-guide-title';
    const list = node('div', '', 'usage-guide-options');
    const leave = () => { if (offer) persist('dismissed').catch((e) => notice(e.message)); };
    const choose = (fn) => () => { leave(); close(); fn(); };
    // 맨 위 — 하던 일 이어 하기(진행 중, 없으면 가장 최근 멈춘 것 하나) + 「처음부터」
    const paused = Array.isArray(jstate.paused) ? jstate.paused : [];
    const resume = [isObject(jstate.active) ? jstate.active : null, ...paused].find((r) => isObject(r) && allowedId(r.journey));
    if (resume) {
      const def = journeys[resume.journey];
      const pg = Math.min(Math.max(0, resume.page | 0), def.pages.length - 1);
      const n = ordinal(def, pg, Math.min(Math.max(0, resume.step | 0), def.pages[pg].steps.length - 1));
      const row = node('div');
      const b = node('button', '', 'usage-guide-option'); b.type = 'button';
      b.append(node('span', '하던 일 ' + WORDS.resume), node('strong', def.label + ' (' + n + ' / ' + total(def) + ')'));
      b.addEventListener('click', choose(() => startJourney(resume.journey, { resume: true })));
      row.append(b, button(WORDS.restart, choose(() => startJourney(resume.journey, { restart: true }))));
      list.append(row);
    }
    const done = new Set((Array.isArray(jstate.completed) ? jstate.completed : []).filter(isObject).map((r) => r.journey));
    for (const id of ids) {
      if (!allowedId(id)) continue;
      const def = journeys[id];
      const b = node('button', '', 'usage-guide-option'); b.type = 'button';
      const label = node('strong', def.label);
      if (done.has(id)) { label.append(' ', ariaHidden('✓'), node('span', '끝냄', 'sr-only')); }
      b.append(node('span', def.place), label);
      b.addEventListener('click', choose(() => startJourney(id)));
      list.append(b);
    }
    list.hidden = list.children.length === 0;
    const actions = node('div', '', 'usage-guide-actions');
    actions.append(button(ENTRY.tour, choose(() => start())));
    if ($('help-dialog')) {
      // 앱의 위임(app.js initHelpDialog)이 도움말 창을 연다 — 이 단추는 질문 카드를 먼저 닫을 뿐(창 위에 창 0 · 안내가 창을 여는 코드 0)
      const help = button(ENTRY.help, () => { leave(); close(); }); help.setAttribute('data-help-open', ''); actions.append(help);
    }
    actions.append(offer ? button(WORDS.offerLater, () => finish('dismissed')) : button(WORDS.close, () => close()));
    cardEl.append(title, list, actions);
    (list.querySelector('button') || actions.lastElementChild).focus();
  }

  load().then(async (s)=>{
    if (enabled()) {
      // 길잡이 — ① 주소 꼬리 ② 진행 중 이어 하기 ③ 첫 로그인 제안(질문 카드) ④ 옛 투어 이어 보기
      if (wanted !== null && allowedId(wanted)) { await startJourney(wanted); return; }
      if (await resumeOnLoad()) return;
      paintLaunch();
      if(s.offer) { setTimeout(()=>{ if(!run && !openDialogs().length && !document.hidden && !document.activeElement?.matches('input,textarea,[contenteditable="true"]')) ask(true); },700); return; }
      if(s.status==='active' && !openDialogs().length && (s.context===currentContext() || (s.context==='messenger' && $('messenger-window')))) start(true, null, s.context);
      return;
    }
    if(s.offer) setTimeout(()=>{ if(!openDialogs().length && !document.hidden && !document.activeElement?.matches('input,textarea,[contenteditable="true"]')) consent(); },700);
    else if(s.status==='active' && !openDialogs().length && (s.context===currentContext() || (s.context==='messenger' && $('messenger-window')))) start(true, null, s.context);
  }).catch(()=>{});
  window.addEventListener('resize', () => { if (run) { cancelAnimationFrame(jFrame); jFrame = requestAnimationFrame(place); } });
  document.addEventListener('scroll', () => { if (run) { cancelAnimationFrame(jFrame); jFrame = requestAnimationFrame(place); } }, true);
})();
