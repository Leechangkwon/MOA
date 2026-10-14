/* Optional, account-persisted tours. Only guide preferences are written; tour steps
 * never click application controls, submit a form, or open/close an existing modal.
 * One exception (site Q:F-11 C-3): the messenger tour shows the floating messenger window (DESIGN 2.17). If that
 * window is closed when the tour needs it, the tour presses the window's own opener ([data-messenger-open]) once,
 * the same as a person would — it never sends, closes the window or changes what was typed. The floating window is
 * non-modal, so it never blocks the offer, a page tour or resuming (it is left out of every open-dialog check). */
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
      step('#work-home-welcome','업무 홈','오늘 할 일과 맡은 작업, 내 프로젝트를 한눈에 봐요. 이야기와 자료실은 왼쪽 아이콘 줄이나 앱 이름 메뉴에서 옮겨 가요.'),
      step('#work-home-tasks','내 작업 요약','기한이 지난 작업 · 오늘 · 이번 주 작업을 세 칸에 모아요. 동그라미를 누르면 완료, 이름을 누르면 상세가 열려요.'),
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
      step('#compose-travel-plan,#travel-plan-select,#memory-travel-plan','여행 계획과 연결','기록이 어느 여행과 일정에 해당하는지 선택할 수 있어요. 계획 밖의 순간은 연결하지 않아도 됩니다.'),
      step('#publish','확인하고 저장하기','사진, 메모, 날짜와 공개 범위를 확인한 뒤 이 버튼으로 게시해요. 저장이 실패하면 오류를 확인하고 다시 시도하세요.')
    ],
    workspace: [
      step('.page-head,#community-page','문서와 업무 공간','개인 메모, 팀 문서, 공지와 질문을 함께 정리해요. 볼 수 있는 문서와 기능은 내 권한에 맞춰 나타나요.'),
      step('#community-new','새 문서 작성','비공개 문서부터 시작하고 필요할 때 함께 볼 사람을 정해요. 템플릿은 더보기에서 고를 수 있어요.'),
      step('#community-search','제목과 내용 검색','문서가 많아지면 제목·내용으로 찾고 검증 상태, 기한, 진행 상태로 좁혀 보세요.'),
      step('#community-space,#community-tree','개인·팀 공간과 문서 트리','공간을 고르고 문서를 계층으로 정리해요. 내가 볼 권한이 있는 문서만 목록에 나타나요.'),
      step('#moa-board-doors','게시판의 소식과 질문','공지·질문·일상 소식은 게시판에서 함께 읽고 남겨요. 게시판과 문서, 개인 스레드는 각각의 목적에 맞춰 사용할 수 있어요.'),
      step('#community-editor,.ProseMirror','블록으로 쓰는 문서','문단·할 일·표 등 필요한 블록을 더하고 내용을 수정해요. 자동 저장 상태를 확인한 뒤 이동하세요.'),
      step('#community-more','양식과 휴지통','더보기에서 템플릿 작성과 내 휴지통 등 부가 기능을 찾을 수 있어요. 항목은 권한에 따라 다를 수 있어요.')
    ],
    files: [
      step('#upload-toggle','자료실에 파일 올리기','폴더를 정하고 문서·사진·파일을 올려요. 권한과 파일 형식에 따라 사용할 수 있는 동작이 달라요.'),
      step('#folder-pane','폴더로 차곡차곡','원하는 폴더를 선택해 관련 파일을 모아 보세요. 폴더 경로로 상위 위치로 돌아갈 수 있어요.'),
      step('#search-q','이름과 설명으로 검색','파일을 찾고 미리보기에서 내용을 확인해요. 별을 달면 즐겨찾기에서 다시 찾을 수 있어요.'),
      step('#moa-file-list','열기·받기·공유','파일의 작업 메뉴에서 미리보기·다운로드·공유 기능을 찾아보세요. 공유할 수 있는 파일에만 공유 항목이 표시돼요.')
    ],
    account: [
      step('#welcome-security,#settings-passkeys','로그인 방법 선택','비밀번호로 시작하거나 OTP·기기 패스키를 추가해요. 패스키는 지문·얼굴·기기 잠금으로 본인을 확인하고 생체 정보는 모아에 저장하지 않아요.'),
      step('#welcome-choose-passkey','기기로 빠르게 로그인','지원하는 브라우저에서 이 기기를 등록해요. 이후 로그인 화면에서 패스키를 선택하면 OTP와 별도로 로그인할 수 있어요.'),
      step('#welcome-choose-otp','OTP 인증 앱 선택','원할 때 등록할 수 있어요. 인증 앱이 없다면 다운로드 안내를 열고 앱을 설치한 뒤 등록을 이어가세요.'),
      step('#welcome-buddy','AI 비서는 나중에 해도 좋아요','설치와 연결은 선택 사항이에요. 웹앱은 로그인한 계정으로 쓰며 외부 비서는 지원되는 설치·연결 방법을 먼저 확인하세요.'),
      step('#welcome-install,#member-install','모바일과 컴퓨터 설치','기기에 맞는 설치 안내를 따라요. 설치 후 같은 계정으로 로그인하면 내 기록과 이어지고 웹앱용 별도 토큰을 복사할 필요가 없어요.'),
      step('#welcome-token-form','외부 비서용 연결 토큰','필요한 경우에만 사용할 기능을 골라 발급해요. 이 값은 AI 제공자의 API 키와 다르며 타인에게 공유하면 안 됩니다.'),
      step('#welcome-steps','언제든 다시 이어가기','선택 항목은 나중에 설정해도 돼요. 업무 홈과 이야기에서 일과 일상 기록을 함께 살펴보세요.')
    ],
    // 떠 있는 메신저 창(#messenger-window — DESIGN 2.17). 창은 목록 ↔ 대화 중 하나만 보이므로 보이는 단계만 남는다.
    messenger: [
      step('#messenger-window #messenger-back,#messenger-window #message-people','대화 상대 목록','함께하는 사람과 모아 AI가 목록에 있어요. 사람을 고르면 이전 대화를 이어서 보고, 대화 중에는 ‹ 목록으로 돌아와요.'),
      step('#messenger-window #message-search','사람 찾기','이름이나 아이디로 함께하는 사람을 찾아요. 안내 중에는 검색하거나 대화를 열지 않아요.'),
      step('#messenger-window #message-ai,#messenger-window #message-ai-notice','모아 AI와 전달되는 내용','모아 AI는 공용 Gemini로 답하고 대화 기록은 나만 봐요. AI에게 보낸 내용과 최근 AI 대화가 Google로 전송되니 개인정보·기밀은 입력하지 마세요.'),
      step('#messenger-window #message-text','작성과 전송','Enter는 전송, Shift+Enter는 줄바꿈이에요. 쓰던 글은 이 기기에 암호화해 두어 다른 화면에서 창을 열어도 이어서 쓸 수 있어요.'),
      step('#messenger-window .modal__head a[href]','크게 보기','메신저를 전체 화면으로 열어요. 내 PC의 비서 연결도 전체 화면에서 할 수 있어요.'),
      step('#messenger-window .modal__head [data-messenger-close]','닫아도 이어져요','창은 보던 화면 위에 떠 있어 뒤 화면을 그대로 쓸 수 있어요. 닫아도 상대와 쓰던 글은 남고, 다른 화면으로 옮기면 열어 둔 창이 그대로 돌아와요.')
    ],
    story: [
      step('#memory-detail-dialog,#post-detail-dialog,#detail-dialog,#studio-dialog .dialog-head','사진과 이야기를 자세히','피드에서 접어 둔 사진과 글을 여기서 이어서 읽어요. 닫으면 원래 피드로 돌아가요.'),
      step('#studio-tone','글의 분위기 선택','간결한 에세이, 관찰 기록, 여행 노트 등 원하는 분위기를 고를 수 있어요.'),
      step('#gemini-wish','내가 원하는 이야기','사진을 보고 어떤 내용을 더해 주면 좋을지 적어요. 있었던 일을 알려 주면 사실에 가까운 초안을 만드는 데 도움이 돼요.'),
      step('#gemini-generate','선택한 사진으로 초안 만들기','버튼을 누를 때 사진의 축소본과 메모 등 안내된 내용을 Gemini에 전달해요. 결과는 검토한 뒤 저장하세요.'),
      step('#studio-story-editor,#studio-result','내 목소리로 다듬기','생성된 장면별 글이나 초안을 직접 고칠 수 있어요. 수정한 글을 확인하고 적용하세요.'),
      step('#studio-append,#apply-studio','원문을 보존하며 적용','기존 글에 추가하거나 교체 편집을 선택해요. 안내는 이 버튼을 누르거나 저장하지 않아요.'),
      step('[data-comment-post]','댓글로 마음 남기기','댓글 아이콘을 누르면 그 글 아래에서 바로 댓글을 적고 「게시」로 전해요. 공개 범위와 내 권한 안에서 사용할 수 있어요.'),
      step('#share-url','공유 가능한 기록만 나누기','링크가 있어도 소유자가 정한 범위를 우회할 수 없어요. 공유 안내와 링크 유효기간을 확인해 주세요.')
    ],
    travel: [
      step('#travel-dialog,#travel-plans-dialog','계획을 기록으로 이어가기','가고 싶은 곳과 일정을 적고 실제로 남긴 사진·글을 연결해요. 계획이 바뀌어도 언제든 수정할 수 있어요.'),
      step('#travel-new,#travel-plan-new','새 여행 계획','여행 이름과 기간, 메모를 입력해요. 날짜를 확정하기 어려우면 가능한 범위부터 작성하세요.'),
      step('#travel-title,#travel-plan-title','이름과 여행 메모','이 여행을 알아볼 이름과 준비할 내용을 정리해요. 일정과 장소는 여행 중에도 바꿀 수 있어요.'),
      step('#travel-add-step','일정·장소 추가','들를 곳을 넣고 순서를 정해요. 새 장소를 더하거나 기존 계획을 변경하면서 실제 동선에 맞춰요.'),
      step('#travel-steps','진행 상태와 기록','예정·완료·건너뜀 같은 상태를 표시하고 사진 기록을 연결해요. 계획과 달라졌다는 이유로 기록을 지울 필요는 없어요.'),
      step('#travel-save,#travel-plan-save','변경 내용 저장','수정 내용을 확인한 뒤 저장해요. 사진 기록을 쓸 때 여행 계획과 일정은 선택적으로 연결할 수 있어요.')
    ],
    // Q:F-12 D — 내 작업(`/community/my-tasks` — 옛 「오늘」 맥락 이름을 잇는다)
    today: [
      step('#my-tasks-list','기한으로 묶은 내 작업','지난 기한 · 오늘 · 이번 주 · 나중에 · 기한 없음으로 묶어요. 묶음 이름을 누르면 접고 펼쳐요.'),
      step('#my-tasks-add,[data-add-bucket]','바로 적기','「작업 추가」에서 이름을 적고 Enter 를 누르면 저장하고 다음 줄로 가요. 프로젝트 없이 적은 작업은 나만 보는 「내 할 일」에 모여요.'),
      step('[data-my-view]','목록 · 보드 · 달력','같은 작업을 묶음별 보드나 기한 달력으로도 볼 수 있어요. 「끝난 작업 보기」로 끝낸 작업을 다시 봐요.'),
      step('#my-tasks-groups .task-row__title','작업 상세','이름을 누르면 오른쪽에 상세가 열려요. 담당자 · 마감 · 상태를 바꾸고 댓글과 모든 활동을 한 줄기로 봐요.')
    ]
  };
  tours.travel.push(step('#travel-record','여행 중의 순간 기록','이 여행에 연결할 새 사진과 글을 작성해요. 여행 계획은 유연하게 고치고 기록은 별도로 남길 수 있어요.'));
  tours.travel.push(step('#travel-linked','이미 쓴 글도 연결하기','내 기록을 불러와 이 여행에 연결할 수 있어요. 연결을 바꿔도 원래 사진과 글은 그대로 유지돼요.'));
  tours.travel.push(step('#travel-ai-prompt','AI와 계획 다듬기','요청문을 복사해 ChatGPT 등에 상담할 수 있어요. MCP가 연결되어 있으면 허용한 여행 읽기·쓰기 범위 안에서 계획을 다룰 수 있어요.'));
  const modalContexts = new Map([
    ['messenger-window','messenger'],['compose-dialog','composer'],['studio-dialog','story'],
    ['share-dialog','story'],['memory-detail-dialog','story'],
    ['post-detail-dialog','story'],['detail-dialog','story'],['travel-dialog','travel'],['travel-plans-dialog','travel']
  ]);
  tours.threads.push(step('#social-search','함께 기록할 사람 찾기','이름이나 아이디로 찾고 프로필을 열어 팔로우할 수 있어요. 공개하지 않은 기록은 팔로워라도 볼 수 없어요.'));
  tours.threads.push(step('#social-list','팔로잉·팔로워 목록','상대의 프로필을 열거나 팔로우 상태를 바꿀 수 있어요. 팔로워 공개 글은 나를 팔로우한 모든 회원에게 보이므로 글을 올리기 전에 범위를 확인해 주세요.'));
  tours.threads.push(step('#default-visibility','새 글의 기본 공개 범위','새로 작성하는 글의 기본값을 정해요. 기존 기록은 바뀌지 않고 발견에는 직접 선택한 공개 글만 소개돼요.'));
  modalContexts.set('social-dialog','threads'); modalContexts.set('privacy-dialog','threads');
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
  async function request(method = 'GET', body) {
    const controller=new AbortController(), timer=setTimeout(()=>controller.abort(),8000);
    try {
      const response = await fetch(endpoint, { method, signal:controller.signal, credentials:'same-origin', headers:{ Accept:'application/json', ...(body ? {'Content-Type':'application/json','X-CSRF-Token':csrf} : {}) }, ...(body ? {body:JSON.stringify(body)} : {}) });
      if (!response.ok) throw new Error('guide_unavailable');
      const result = await response.json();
      if (result.csrf_token) csrf = result.csrf_token;
      saved = result.guide;
      return saved;
    } catch(e) { throw new Error('안내 진행 상태를 저장하지 못했어요. 다시 열어 이어갈 수 있어요.'); }
    finally { clearTimeout(timer); }
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
  function makeDialog() {
    returnFocus = document.activeElement;
    dialog = node('dialog', '', 'usage-guide-dialog'); dialog.setAttribute('aria-labelledby','usage-guide-title'); dialog.setAttribute('aria-describedby','usage-guide-body');
    const svg = document.createElementNS('http://www.w3.org/2000/svg','svg'); svg.classList.add('usage-guide-shade'); svg.setAttribute('aria-hidden','true');
    for (const part of ['top','left','right','bottom','outline']) { const r = document.createElementNS(svg.namespaceURI,'rect'); r.id='usage-guide-'+part; r.classList.add(part==='outline'?'usage-guide-outline':'usage-guide-dim'); if (part==='outline') r.setAttribute('rx','7'); svg.append(r); }
    dialog.append(svg, node('section','','usage-guide-card')); document.body.append(dialog);
    dialog.addEventListener('cancel', (event) => { event.preventDefault(); finish('dismissed'); });
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
    if(dialog) return; context=currentContext(); index=0; makeDialog(); dialog.classList.add('is-consent'); target=null; position();
    const card=dialog.querySelector('.usage-guide-card'), title=node('h2','모아 사용 방법을 안내해 드릴까요?'); title.id='usage-guide-title';
    const body=node('p','일상 기록뿐 아니라 문서·게시판·자료실·메신저도 사용할 수 있어요. 화면의 버튼을 강조하며 차근차근 설명해 드릴게요. 언제든 건너뛰거나 물음표에서 다시 볼 수 있어요.'); body.id='usage-guide-body';
    const actions=node('div','','usage-guide-actions'); actions.append(button('나중에 볼게요',()=>finish('dismissed')),button('네, 안내해 주세요',()=>{ close(); start(); },true));
    card.append(title,body,actions); actions.lastElementChild.focus();
  }
  document.addEventListener('click',(event)=>{ if(event.target instanceof Element && event.target.closest('[data-usage-guide-open]')) { if(dialog) return; load().catch(()=>{}); start(saved?.status==='active', event.target); } });
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
  load().then((s)=>{
    if(s.offer) setTimeout(()=>{ if(!openDialogs().length && !document.hidden && !document.activeElement?.matches('input,textarea,[contenteditable="true"]')) consent(); },700);
    else if(s.status==='active' && !openDialogs().length && (s.context===currentContext() || (s.context==='messenger' && $('messenger-window')))) start(true, null, s.context);
  }).catch(()=>{});
})();
