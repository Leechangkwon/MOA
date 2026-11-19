import { createDeferredEditor } from './editor-loader.js?v=a9fddd18fefd';
import { renderDoc } from './doc-render.js?v=a9fddd18fefd';
/** Page reading, properties, comments and history. Editing dependencies arrive only on demand. */
import { hydrateDashboard } from './dashboard.js?v=a9fddd18fefd';
import { buildSuggestionOps, createSuggestions, suggestionEqual } from './suggestions.js?v=a9fddd18fefd';
import { createMentionInput } from './mention-input.js?v=a9fddd18fefd';
import { createDatabaseView } from './database.js?v=a9fddd18fefd';
import { createTemplateSave } from './templates.js?v=a9fddd18fefd';
import { drawAutomationPage } from './automations.js?v=a9fddd18fefd';
import { drawTrust } from './procedures.js?v=a9fddd18fefd';
import { createPageExport } from './export.js?v=a9fddd18fefd';
import { drawPageAi } from './ai.js?v=a9fddd18fefd';
import { drawIntegrations } from './integrations.js?v=a9fddd18fefd';
import { isAnon, personInto } from './cafe.js?v=a9fddd18fefd';

export function initPage(deps) {
  const { APP_BASE, BOARD_LEVEL_LABELS, BOARD_REASONS, COMMUNITY_BASE, GENERIC_ERROR, IS_CAFE, SHARE_LABELS, STATUS_LABELS, VISIBILITY_REASONS, allBoards, api, boardLevelBadge, body, cafe, clear, drafts, errorMessage, favoriteListeners, fillTagOptions, leaveHooks, loadFavoritesNav, loadRecentNav, localWhen, makeIcon, meCanWrite, meUsername, mentionInto, paintStatus, paintVisibility, personLabel, propValueText, redrawAttachmentViews, roleText, setText, setWhenParts, shareBadge, shortWhen, show, spaceHref, splitTags, titleForInput, toLogin, watchAttachments, whileVisible, work, shared, loadEditorRuntime } = deps;
  let ATOM_TYPES, BLOCK_TYPES, Decoration, DecorationSet, EditorState, EditorView, Plugin, TextSelection, atomDialogFor, atomEditCommand, baseKeymap, blockDialogFor, blockHandles, blockKeymapFor, buildInputRules, chainCommands, clipboardDomParser, commandBackspace, commandCodeEnter, commandEnter, commandShiftEnter, createToolbar, createUploads, dropCursor, editorDomParser, fromDoc, gapCursor, history, imageViews, imageWidthKeys, keymap, liveToc, mdLabel, redo, schema, suggestionTypeAllowed, toDoc, todoToggle, undo, uniqueBlockIds, uuid4;

  const meta = document.getElementById('page-meta');
  if (!meta) { return; }
  const pageId = meta.getAttribute('data-page-id') || '';

  const msg = document.getElementById('msg');
  const titleEl = document.getElementById('page-title');
  const crumbEl = document.getElementById('page-crumb');
  const subEl = document.getElementById('page-sub');
  const docEl = document.getElementById('page-doc');
  const editorEl = document.getElementById('page-editor');
  const titleField = document.getElementById('page-title-field');
  const titleInput = document.getElementById('page-title-input');
  // 사용감 라운드 1 UX-2 — 좁은 폭 편집 모드에서 「더보기」 맨 위로 들어오는 「사용 방법」·「이력」(넓은 폭은 CSS 가 숨긴다)
  const moreHelpItem = document.getElementById('page-more-help');
  const moreHistoryItem = document.getElementById('page-more-history');
  // UX-1 — 새로 만든 페이지(`?edit=1&new=1`)로 열렸으면 편집을 켤 때 커서를 제목 칸에 한 번 둔다
  let focusTitleOnce = false;
  const editButton = document.getElementById('page-edit');
  const doneButton = document.getElementById('page-done');
  const bodyHead = document.getElementById('page-body-head');   // Q:F-12 W1c — 편집 중에만(「편집 마침」이 든 본문 머리 줄)
  const addBlockButton = document.getElementById('page-add-block');
  const deleteButton = document.getElementById('page-delete');
  const saveState = document.getElementById('page-save-state');
  const editorsBadge = document.getElementById('page-editors');
  const pageStateLine = document.getElementById('page-state');   // 디버깅 묶음 U H5 — 위 두 배지를 담는 제목 아래 줄
  const childrenWrap = document.getElementById('page-children-wrap');
  const childrenBox = document.getElementById('page-children');
  const childrenCount = document.getElementById('page-children-count');
  const childrenEmpty = document.getElementById('page-children-empty');
  const newChildButton = document.getElementById('page-new-child');
  const newDbButton = document.getElementById('page-new-db');
  // 6-A — DB 표·행 속성 줄. 도우미는 이 파일의 것을 그대로 넘긴다(두 벌로 두지 않는다).
  const dbView = createDatabaseView({
    api: api, setText: setText, show: show, clear: clear, errorMessage: errorMessage,
    base: COMMUNITY_BASE, statusLabels: STATUS_LABELS, genericError: GENERIC_ERROR, toLogin: toLogin,
    tboxBase: APP_BASE, spaceId: () => (state.space ? state.space.id : 0),
    // 디버깅 묶음 U2 · 독립 검토 L-2 — 데이터베이스의 쓰기 단추도 `/api/me` 의 쓰기 자격과 **둘 다** 참일 때만(페이지 머리와 같은 규칙)
    canWrite: () => meCanWrite,
    // Q:F-12 D — 쓰임이 프로젝트면 work.js 가 프로젝트 화면(머리 · 작업 목록 · 상세 칸)을 그린다(행 목록 창구의 `database.purpose`)
    project: work ? work.projectHooks() : null
  });
  const moveButton = document.getElementById('page-move');
  const pageExport = createPageExport({ base: COMMUNITY_BASE, pageId, beforeOpen: async () => {
    await flushSave();
    if (serial !== savedSerial || pendingConflict) throw new Error('저장을 마친 뒤 다시 내보내 주세요.');
  } });
  // 7-B — 「템플릿으로 저장」. 고를 수 있는 범위는 창구가 준 `template_scopes` 뿐이다.
  const templateSave = createTemplateSave({
    api: api, setText: setText, show: show, clear: clear, errorMessage: errorMessage,
    genericError: GENERIC_ERROR, toLogin: toLogin
  });
  shared.pageTemplateSave = templateSave;
  const conflictDialog = document.getElementById('conflict-dialog');
  const visibilityBadgeEl = document.getElementById('page-visibility');
  const visibilityButton = document.getElementById('page-visibility-edit');
  // 4-D — 속성 줄
  const propsRow = document.getElementById('page-props');
  const statusButton = document.getElementById('page-status');
  const statusBadgeEl = document.getElementById('page-status-badge');
  // 5-A — 「소유자」 칸과 「넘기기」, 공유받은 사람의 「공유 보기」
  const ownerNameEl = document.getElementById('page-owner-name');
  // 디버깅 묶음 U3 — 좁은 폭(720 미만) 속성 요약 한 줄과 「속성 더보기」(넓은 화면에서는 CSS 가 요약을 숨기고 속성 줄을 그대로 보인다)
  const propsSummary = document.getElementById('page-props-summary');
  const propsLine = document.getElementById('page-props-line');
  const propsToggle = document.getElementById('page-props-toggle');
  const propsToggleLabel = document.getElementById('page-props-toggle-label');
  let propsOpen = false;
  const handoverButton = document.getElementById('page-handover');
  const shareMineEl = document.getElementById('page-share-mine');
  const shareOpenButton = document.getElementById('page-share-open');
  // 개발 대기열 6 — 「더보기」 맨 위의 즐겨찾기 ☆. 넣었나는 창구의 즐겨찾기 목록(지금 볼 수 있는 것)에 이 페이지가 있나만 본다.
  const favButton = document.getElementById('page-fav');
  const favLabel = document.getElementById('page-fav-label');

  let state = {
    doc: null, version: 0, title: '', canEdit: false, editing: false,
    // 4-A — 창구가 준 「볼 수 있는 하위 페이지」 제목 지도. 렌더러 두 벌이 이것만 본다.
    childTitles: {},
    // 5-E — 페이지 링크 · 붙인 파일의 제목 지도(창구가 **보는 사람 권한으로** 거른 것).
    linkTitles: {}, fileTitles: {}, embeds: { hosts: [], frames: false },
    // Q:F-12 U3 C — 이 문서 전용 첨부(`att:`)의 이름 지도(창구 `attachment_titles` — 받을 수 있는 사람에게만 준 것)
    attachmentTitles: {},
    // 7-D4 — 글 안 멘션의 이름 지도(창구가 **보는 사람 기준으로** 거른 것 — 볼 수 있는 것만)
    mentionLabels: {},
    // 4-B — 지금의 공개 범위와 이 페이지가 든 공간. 둘 다 창구가 준 값이다.
    visibility: 'private', space: null, canSetVisibility: false,
    // 4-D — 상태. 문자열 또는 null.
    status: null, canSetProperties: false,
    // 5-A — 소유자(`{kind, username|name, is_active}`) · 관리 자격 · 공유 · 인계. 전부 창구가 준 값이다.
    owner: null, canManage: false, canShare: false, canHandover: false,
    shareLevel: null, subtreeCount: null,
    // 5-C — 걸린 게시판 · 쓴 사람 · 태그 · 조회수 · 해결됨과 단추 다섯의 값. 전부 창구가 준 값이다.
    board: null, writer: null, tags: [], viewCount: 0, resolved: false,
    canTag: false, canPublish: false, canUnpublish: false, canHide: false, canResolve: false,
    // 5-D — 댓글을 쓸 수 있나 · 공지 「읽은 사람」을 볼 수 있나(창구가 준 값)
    canComment: false, canViewReads: false
  };
  let view = null;
  let toolbar = null;
  let saveTimer = null;
  let heartbeat = null;
  let pendingConflict = null;
  let proposal = null;
  let documentDeletionLocked = false;
  let documentPageReady = false;
  let documentPageRemoved = false;
  let documentAncestorIds = new Set([pageId]);
  shared.documentTrashGuard = async (items) => {
    if (pendingEditing) throw new Error('편집기를 불러온 뒤 다시 정리하세요. 입력한 내용은 그대로 보존됩니다.');
    if (!documentPageReady || documentPageRemoved) throw new Error('문서 불러오기가 끝난 뒤 다시 정리하세요.');
    if (todoBusy) throw new Error('문서 변경을 저장하는 중입니다. 저장이 끝난 뒤 다시 정리하세요.');
    if (proposal) throw new Error('수정 제안을 먼저 보내거나 취소한 뒤 문서를 정리하세요.');
    if (pendingConflict) throw new Error('저장 충돌을 먼저 해결하세요. 쓰던 내용은 그대로 보존됩니다.');
    const currentIncluded = items.some(item => documentAncestorIds.has(item.id));
    const editingView = view;
    const oldTitleDisabled = titleInput?.disabled;
    if (currentIncluded) { documentDeletionLocked = true; if (view) view.setProps({editable: () => false}); if (titleInput) titleInput.disabled = true; }
    const unlock = (job) => {
      const removed = job?.items.some(item => item.result === 'ok' && !item.parent && documentAncestorIds.has(item.id));
      if (removed) {
        documentPageRemoved = true;
        if (pendingEditing) { pendingEditing.destroy(); pendingEditing = null; }
        window.clearTimeout(saveTimer); clearPageDraft(); shared.leavingOnPurpose = true;
        if (view) { view.destroy(); view = null; }
        const read = document.getElementById('page-doc'); if (read) { clear(read); read.hidden = true; }
        if (editorEl) { clear(editorEl); editorEl.hidden = true; }
        if (bodyHead) { bodyHead.hidden = true; }
        state.editing = false; state.canEdit = false; serial = savedSerial;
        setText(msg,'이 문서가 휴지통으로 이동했습니다. 결과 창에서 되돌릴 수 있습니다.');
        shared.documentTrashAfterClose = () => window.location.assign(crumbRoot().href || COMMUNITY_BASE + '/workspace');
        shared.documentTrashAfterRestore = restored => { if (restored.items.some(item=>item.result==='ok' && documentAncestorIds.has(item.id))) { shared.documentTrashAfterClose=()=>{}; window.location.assign(COMMUNITY_BASE+'/p/'+pageId); } };
        return;
      }
      documentDeletionLocked = false;
      if (view && view === editingView) view.setProps({editable: () => true});
      if (titleInput) titleInput.disabled = oldTitleDisabled === true;
    };
    try {
      await flushSave();
      if (serial !== savedSerial || pendingConflict || saving) throw new Error('문서를 저장하지 못했습니다. 저장 또는 충돌 해결 후 다시 정리하세요.');
      return unlock;
    } catch (error) { unlock(); throw error; }
  };
  const proposalButton=document.createElement('button'), proposalsButton=document.createElement('button'), proposalCancel=document.createElement('button');
  for (const [button,id,text] of [[proposalButton,'page-suggest','수정 제안'],[proposalsButton,'page-suggestions','제안'],[proposalCancel,'page-suggestion-cancel','취소']]) {
    button.type='button';button.id=id;button.className='btn btn--quiet btn--small';button.textContent=text;button.hidden=true;
    if(editButton && editButton.parentNode)editButton.parentNode.appendChild(button);
  }
  const suggestions=createSuggestions({api,base:COMMUNITY_BASE,pageId,refresh:()=>load()});
  proposalButton.addEventListener('click',async()=>{
    if(!state.canSuggest || state.editing)return;
    proposal={original:structuredClone(state.doc),version:state.version};await startEditing();
    if(!view)proposal=null;
  });
  proposalsButton.addEventListener('click',()=>suggestions.list().catch(e=>setText(msg,e.message)));
  proposalCancel.addEventListener('click',()=>stopEditing());

  function proposalPlugin() {
    return new Plugin({
      filterTransaction(tr) {
        if(!proposal || !tr.docChanged)return true;
        try { buildSuggestionOps(proposal.original,toDoc(tr.doc.toJSON()));return true; }
        catch(e) { setText(msg,e.message);return false; }
      },
      props:{decorations(pmState) {
        if(!proposal)return null;
        const old=new Map(proposal.original.blocks.map(b=>[b.id,b]));
        const doc=toDoc(pmState.doc.toJSON()),marks=[];
        let index=0;
        pmState.doc.forEach((node,pos)=>{
          const block=doc.blocks[index++];
          if(block && !suggestionEqual(old.get(block.id),block))marks.push(Decoration.widget(pos,()=>{
            const label=document.createElement('span');label.className='badge badge--internal';label.textContent='제안';label.contentEditable='false';return label;
          },{side:-1}));
        });return DecorationSet.create(pmState.doc,marks);
      }}
    });
  }

  /* ── 저장 경합을 막는 번호 (지시서 4장) ──
     `serial`   변경이 있을 때마다 1 오른다.
     `sentSerial` 지금 날아가 있는 요청이 담고 있는 번호.
     `savedSerial` 서버가 200 으로 받아 준 마지막 번호.
     변경이 남아 있는 동안(`serial !== savedSerial`) 「저장됨」을 보이지 않는다. */
  let serial = 0;
  let sentSerial = -1;
  let savedSerial = 0;
  let saving = false;
  let inFlight = null;

  function badge(text, kind) {
    if (!saveState) { return; }
    saveState.className = 'badge' + (kind ? ' ' + kind : '');
    setText(saveState, text);
    show(saveState, text !== '');
    syncPageState();
  }

  /* 디버깅 묶음 U H5 — 상태 배지 줄(`#page-state`, 제목 아래)은 보이는 배지가 하나라도 있을 때만 보인다 */
  function syncPageState() {
    if (!pageStateLine) { return; }
    show(pageStateLine, Array.prototype.some.call(pageStateLine.children, (el) => !el.hidden));
  }

  /* ── 쓰던 글 임시저장 (Q:F-4 B1-2 설계 3·4·6) ──
     저장이 안 된 변경이 있는 동안만, 그 사본을 이 브라우저에 **암호화해서** 남긴다(`drafts.js`).
       쓰는 때   변경마다(1초 묶음) · 401 을 받은 그 순간(`leaveHooks` — 로그인 화면으로 가기 전) · 창 닫기·탭 숨김
       지우는 때 그 판이 200 으로 저장된 뒤(`serial === savedSerial`) · 「버리기」 · 409 창의 「새로 고침」
     열쇠를 못 받았으면 쓰지 않는다(평문 대체 0) — 그때와 쓰기가 실패했을 때는 배지 「임시저장 못 함」. 저장(서버)은 그대로다. */
  const draftPageKey = drafts ? drafts.pageKey(pageId) : null;
  let draftTimer = null;
  let draftSeq = 0;          // 더 새 쓰기가 시작되면 앞선 쓰기는 암호화가 끝나도 쓰지 않는다
  let draftBadge = null;
  let commentsLoaded = Promise.resolve();

  /** 배지 「임시저장 못 함」(회색 — 꺼짐, DESIGN 2.2). 저장 상태 배지 옆에 한 번만 만든다. */
  function draftOff(on) {
    const want = on !== false;
    if (!want && draftBadge === null) { return; }
    if (draftBadge === null) {
      if (!saveState || !saveState.parentNode) { return; }
      draftBadge = document.createElement('span');
      draftBadge.className = 'badge badge--off';
      draftBadge.setAttribute('data-draft-off', '');
      draftBadge.textContent = '임시저장 못 함';
      draftBadge.setAttribute('title', '쓰던 글을 이 브라우저에 남기지 못했습니다. 서버 저장은 그대로 됩니다');
      saveState.parentNode.insertBefore(draftBadge, saveState.nextSibling);
    }
    show(draftBadge, want);
    syncPageState();
  }

  function titleNow() {
    return titleInput && titleInput.value.trim() !== '' ? titleInput.value.trim() : state.title;
  }

  /** 지금 편집기의 저장 안 된 사본을 쓴다. 쓸 것이 없으면(저장됨·편집 중 아님·열쇠 없음) 아무것도 하지 않는다. */
  function writePageDraft() {
    if (proposal || !drafts || draftPageKey === null || !state.editing || view === null || serial === savedSerial || !drafts.hasKey()) {
      return Promise.resolve();
    }
    const mySerial = serial;
    draftSeq += 1;
    const mySeq = draftSeq;
    let payload;
    try {
      payload = { base_version: state.version, title: titleNow(), doc: currentDoc() };
    } catch (e) {
      return Promise.resolve();
    }
    return drafts.write(draftPageKey, payload, () => mySeq === draftSeq && savedSerial < mySerial)
      .then((ok) => { draftOff(!ok); });
  }

  function scheduleDraft() {
    if (!drafts || draftPageKey === null || draftTimer !== null) { return; }
    draftTimer = window.setTimeout(() => { draftTimer = null; writePageDraft(); }, 1000);
  }

  /** 서버에 저장됐다 — 사본을 지우고, 날아가 있는 쓰기도 무르게 한다(번호를 올린다). */
  function clearPageDraft() {
    window.clearTimeout(draftTimer);
    draftTimer = null;
    draftSeq += 1;
    if (drafts && draftPageKey !== null) { drafts.remove(draftPageKey); }
  }

  /** 읽기 렌더러에 넘길 지도 — 하위 페이지(4-A) · 페이지 링크 · 파일(5-E, 셋 다 보는 사람이 볼 수 있는 것만) */
  function readCtx() {
    return {
      child_titles: state.childTitles, link_titles: state.linkTitles, file_titles: state.fileTitles,
      attachment_titles: state.attachmentTitles, attachment_page: pageId,
      synced: state.synced, embeds: state.embeds, base: COMMUNITY_BASE, tbox_base: APP_BASE, mention_labels: state.mentionLabels, db_views: state.dbViews, buttons: state.buttons, pressButton: async (id, button) => {
        button.disabled=true;
        try { const result=await api('POST','/api/automations/'+id+'/run',{page_id:pageId});
          if (result.status === 200) {
            if (result.data.status !== 'ok') { setText(msg, result.data.status === 'partial' ? '일부 작업만 완료했습니다. 자동 작업의 실행 기록을 확인해 주세요.' : '작업을 실행하지 못했습니다. 원본과 현재 권한을 확인해 주세요.'); return; }
            const p = (result.data.created_pages || [])[0]; if (p) location.assign(COMMUNITY_BASE + '/p/' + p.id); else setText(msg, '실행했습니다.');
          }
          else setText(msg,errorMessage(result.data));
        } catch(e) { setText(msg,GENERIC_ERROR); } finally {button.disabled=false;}
      }
    };
  }

  /** 「이 페이지를 참조한 곳」 (5-E, F-23) — 창구가 준 목록(볼 수 있는 페이지만)을 그대로. 비면 절을 숨긴다. */
  const backlinksWrap = document.getElementById('page-backlinks-wrap');
  const backlinksEl = document.getElementById('page-backlinks');
  function drawBacklinks(items) {
    if (!backlinksWrap || !backlinksEl) { return; }
    clear(backlinksEl);
    for (const b of items) {
      if (!b || typeof b.id !== 'string') { continue; }
      const li = document.createElement('li');
      li.className = 'list__item';
      const a = document.createElement('a');
      a.href = COMMUNITY_BASE + '/p/' + encodeURIComponent(b.id);
      a.textContent = (b.title && b.title !== '' ? b.title : '제목 없는 페이지');
      li.appendChild(a);
      backlinksEl.appendChild(li);
    }
    show(backlinksWrap, backlinksEl.firstChild !== null);
  }

  function drawRead() {
    clear(docEl);
    if (state.doc) {
      docEl.appendChild(renderDoc(state.doc, readCtx()));
      hydrateDashboard(docEl,state.doc,readCtx(),api);
    }
    drawEmptyRead();
    armTodos();
  }

  /* Q:F-12 W1c (DESIGN 2.35 「읽을 때」) — 빈 문서(블록 0 · 빈 문단 하나)는 빈 자리 대신 한 줄. 고칠 수 있으면 누르면 편집 시작(작업 칸 「설명」과 같은 동작 —
     「편집」 단추와 같은 함수) · 못 고치면 「아직 내용이 없어요」. 판단은 창구가 준 can_edit(+ 쓰기 자격) 그대로 · 데이터베이스 페이지는 전처럼 0 */
  function drawEmptyRead() {
    if (state.database) { return; }
    const blocks = state.doc && Array.isArray(state.doc.blocks) ? state.doc.blocks : [];
    const node = docEl.firstChild;
    const blank = blocks.length === 0 || (node && node.textContent.trim() === '' && !node.querySelector('img, table, iframe, hr, a, input, figure, .doc-atom'));
    if (!blank) { return; }
    clear(docEl);
    if (state.canEdit) {
      const hint = document.createElement('button');
      hint.type = 'button';
      hint.className = 'page-doc__empty';
      hint.textContent = '누르면 내용을 적을 수 있어요';
      hint.addEventListener('click', () => { startEditing(); });
      docEl.appendChild(hint);
    } else {
      const hint = document.createElement('p');
      hint.className = 'muted page-doc__empty';
      hint.textContent = '아직 내용이 없어요';
      docEl.appendChild(hint);
    }
  }

  /* ── 읽기 화면의 할 일 상자 (2026-09-23 사용자 지적 「편집 끝 상태에서 체크가 안 됨」) ──
   *
   * 렌더러(`doc-render.js`)는 서버 렌더러와 **같은 모양**이어야 하므로(C:T-19) 건드리지 않고,
   * 그린 뒤에 여기서 상자에 누를 수 있는 성질만 붙인다. 고칠 수 있는 사람(`can_edit`)에게만이다 —
   * 눌러 봐야 서버가 다시 막지만, 못 하는 일을 할 수 있는 것처럼 보이게 하지 않는다.
   * 누르면 문서의 그 할 일만 뒤집어 **보통 저장 창구로 곧바로** 보낸다. 체크 기록은 서버가
   * 이전 문서와 견주어 남긴다(「처리 이력」). 상자와 할 일 블록은 그린 순서로 짝짓는다.
   */
  function todoBlocks(doc) {
    const out = [];
    (function walk(blocks) {
      for (const b of Array.isArray(blocks) ? blocks : []) {
        if (b && b.type === 'to_do') { out.push(b); }
        if (b && Array.isArray(b.children)) { walk(b.children); }
      }
    })(doc && doc.blocks);
    return out;
  }

  function armTodos() {
    docEl.classList.toggle('doc--checkable', state.canEdit);
    if (!state.canEdit) { return; }
    docEl.querySelectorAll('.doc-todo__box').forEach((box, i) => {
      box.removeAttribute('aria-hidden');
      box.setAttribute('role', 'checkbox');
      box.setAttribute('tabindex', '0');
      box.setAttribute('aria-checked', box.parentNode.classList.contains('is-done') ? 'true' : 'false');
      box.setAttribute('aria-label', '할 일 체크');
      box.setAttribute('data-todo-index', String(i));
    });
  }

  let todoBusy = false;
  function toggleTodo(index) {
    if (todoBusy || !state.canEdit || state.editing || !state.doc) { return; }
    const doc = JSON.parse(JSON.stringify(state.doc));
    const target = todoBlocks(doc)[index];
    if (!target) { return; }
    target.checked = target.checked !== true;
    todoBusy = true;
    badge('저장 중…', '');
    api('PATCH', '/api/pages/' + pageId, { doc_version: state.version, doc: doc, title: state.title })
      .then((result) => {
        todoBusy = false;
        if (result.status === 401) { toLogin(); return; }
        if (result.status === 200) {
          state.version = result.data.doc_version;
          state.doc = doc;
          drawRead();
          const again = docEl.querySelector('[data-todo-index="' + String(index) + '"]');
          if (again) { again.focus(); }
          badge('저장됨', 'badge--ok');
          return;
        }
        if (result.status === 409) {
          badge('', '');
          setText(msg, '다른 곳에서 먼저 고쳐져 새로 불러왔습니다. 다시 눌러 주세요.');
          load();
          return;
        }
        badge('저장 못 함', 'badge--admin-only');
        setText(msg, errorMessage(result.data));
      })
      .catch(() => {
        todoBusy = false;
        badge('저장 못 함', 'badge--admin-only');
        setText(msg, GENERIC_ERROR);
      });
  }

  function todoIndexOf(target) {
    const box = target instanceof Element ? target.closest('.doc-todo__box[data-todo-index]') : null;
    return box && docEl.contains(box) ? Number(box.getAttribute('data-todo-index')) : -1;
  }

  docEl.addEventListener('click', (event) => {
    const i = todoIndexOf(event.target);
    if (i >= 0) { event.preventDefault(); toggleTodo(i); }
  });
  docEl.addEventListener('keydown', (event) => {
    if (event.key !== ' ' && event.key !== 'Enter') { return; }
    const i = todoIndexOf(event.target);
    if (i >= 0) { event.preventDefault(); toggleTodo(i); }
  });

  /**
   * 경로 줄 첫 칸 — 이 페이지가 든 **실제 공간**(디버깅 묶음 U3 · 사용자 지적 2026-09-26 「늘 『내 공간 ›』」).
   * 이름은 전부 창구가 준 값이다 — 게시판 글은 `board.name`(게시판을 볼 수 있을 때만 창구가 싣는다) · 팀 공간은 `space.name` ·
   * 남의 개인 공간은 `space.name`(「아이디 의 공간」 — 공유받은 페이지 목록이 이미 보이는 글자) · 내 공간은 「내 공간」.
   * 링크는 **그 사람이 열 수 있는 곳만**: 내 공간 → 커뮤니티 첫 화면 · 팀원 → 그 팀 공간 · 게시판 글 → 그 게시판.
   * 남의 개인 공간·팀원이 아닌 팀 공간(공유·관리자 열람)은 들어갈 화면이 없어 글자만이다. 고르는 값 `granted_by` 는 창구가 한
   * 판정의 결과이고 화면은 모양만 고른다 — 막는 것은 창구다(원칙 1).
   */
  function crumbRoot() {
    const b = state.board;
    const sp = state.space;
    if (b && b.kind === 'post' && typeof b.name === 'string') {
      return { text: b.name, href: COMMUNITY_BASE + '/b/' + String(b.id) };
    }
    if (sp && sp.kind === 'team' && typeof sp.name === 'string') {
      return { text: sp.name, href: state.grantedBy === 'team_member' ? spaceHref(sp.id, 'team') : null };
    }
    if (sp && sp.kind === 'personal' && state.grantedBy !== 'owner' && typeof sp.name === 'string' && sp.name !== '') {
      return { text: sp.name, href: null };
    }
    if (sp && sp.kind !== 'personal') { return { text: '게시판', href: null }; }   // 게시판 이름을 받지 못한 글(창구가 싣지 않음) — 이름을 짓지 않는다
    return { text: '내 공간', href: COMMUNITY_BASE + '/workspace' };
  }

  /** 경로(breadcrumb) — 「공간 › 부모 › 이 페이지」. 마지막 칸만 링크가 아니다. */
  function drawCrumb(breadcrumb) {
    if (!crumbEl) { return; }
    clear(crumbEl);
    const trail = Array.isArray(breadcrumb) ? breadcrumb : [];

    const root = crumbRoot();
    const home = document.createElement(root.href ? 'a' : 'span');
    home.className = 'crumb__item';
    if (root.href) { home.setAttribute('href', root.href); }
    home.textContent = root.text;
    crumbEl.appendChild(home);

    for (let i = 0; i < trail.length; i++) {
      const sep = document.createElement('span');
      sep.className = 'crumb__sep';
      sep.setAttribute('aria-hidden', 'true');
      sep.textContent = '›';
      crumbEl.appendChild(sep);

      const label = (trail[i].icon ? trail[i].icon + ' ' : '') + trail[i].title;
      if (i === trail.length - 1) {
        const here = document.createElement('span');
        here.className = 'crumb__item is-current';
        here.setAttribute('aria-current', 'page');
        here.textContent = label;
        crumbEl.appendChild(here);
        continue;
      }
      const a = document.createElement('a');
      a.className = 'crumb__item';
      a.setAttribute('href', COMMUNITY_BASE + '/p/' + trail[i].id);
      a.textContent = label;
      crumbEl.appendChild(a);
    }
    show(crumbEl, true);
  }

  function drawChildren(children) {
    clear(childrenBox);
    const list = Array.isArray(children) ? children : [];
    for (const child of list) {
      const a = document.createElement('a');
      a.className = 'card card--link';
      a.setAttribute('href', COMMUNITY_BASE + '/p/' + child.id);
      const title = document.createElement('span');
      title.className = 'card__title';
      title.textContent = (child.icon ? child.icon + ' ' : '') + child.title;
      a.appendChild(title);
      if (child.child_count > 0) {
        const meta = document.createElement('span');
        meta.className = 'card__meta';
        meta.textContent = '하위 ' + String(child.child_count);
        a.appendChild(meta);
      }
      childrenBox.appendChild(a);
    }
    setText(childrenCount, list.length === 0 ? '' : String(list.length) + '개');
    show(childrenEmpty, list.length === 0);
    // 하위가 없어도 「하위 페이지 만들기」가 있어야 하므로, 고칠 수 있으면 칸을 연다.
    // 5-C — 게시판 글에는 하위 페이지가 없다(한 줄 목록 — 창구도 400 `space_governed`).
    const flat = !!state.board && state.board.kind === 'post';
    // 6-A — DB 페이지 아래 페이지는 **행**이다(표의 「새 행」으로 만든다) — 하위 만들기 단추 둘을 감춘다.
    const isDb = !!state.database;
    show(childrenWrap, list.length > 0 || (state.canEdit && !flat && !isDb));
    show(newChildButton, state.canEdit && !flat && !isDb);
    show(newDbButton, state.canEdit && !flat && !isDb);
  }

  /* ── 즐겨찾기 ☆ (개발 대기열 6 · 지시서 1.1) — 글자·별 모양·aria-pressed 를 창구가 준 목록에 맞춘다 ── */
  function paintFavorite() {
    if (!favButton) { return; }
    const on = shared.favoriteIds.has(pageId);
    favButton.setAttribute('aria-pressed', on ? 'true' : 'false');
    setText(favLabel, on ? '즐겨찾기에서 빼기' : '즐겨찾기에 넣기');
    const icon = favButton.querySelector('.icon');
    const fresh = makeIcon(on ? 'star-fill' : 'star');
    if (icon) { favButton.replaceChild(fresh, icon); } else { favButton.insertBefore(fresh, favButton.firstChild); }
  }
  favoriteListeners.push(paintFavorite);
  if (favButton) {
    favButton.addEventListener('click', () => {
      const on = shared.favoriteIds.has(pageId);
      favButton.disabled = true;
      setText(msg, '');
      api(on ? 'DELETE' : 'PUT', '/api/pages/' + pageId + '/favorite').then((result) => {
        favButton.disabled = false;
        if (result.status === 401) { toLogin(); return; }
        if (result.status !== 200) { setText(msg, errorMessage(result.data)); return; }
        /* 넣었지만 목록 조건(검색과 같은 조건절) 밖인 페이지 — 관리자 권한으로 연 남의 비공개 페이지처럼(QF3A-T3).
           화면은 창구가 준 참·거짓(`listed`)만 보고 알린다. */
        if (!on && result.data && result.data.listed === false) {
          setText(msg, '즐겨찾기에 넣었지만 이 페이지는 즐겨찾기 목록에 나오지 않습니다 — 검색에도 나오지 않는 페이지(관리자 권한으로 연 다른 사람의 페이지)입니다.');
        }
        loadFavoritesNav();
      }).catch(() => { favButton.disabled = false; setText(msg, GENERIC_ERROR); });
    });
  }

  function load() {
    return api('GET', '/api/pages/' + pageId).then((result) => {
      if (result.status === 401) { toLogin(); return; }
      if (result.status !== 200) {
        setText(msg, errorMessage(result.data));
        show(docEl, false);
        return;
      }
      const page = result.data.page;
      drawTrust(result.data, pageId, api, load);
      drawIntegrations(result.data,pageId,api,load);
      drawPageAi(result.data,pageId,api,load,()=>state.editing);
      drawAutomationPage(result.data, pageId, api, load);
      if (documentPageRemoved) return;
      documentPageReady = true;
      state.procedureCount = result.data.procedures_in_branch || 0;
      state.doc = page.doc;
      state.version = page.doc_version;
      state.title = page.title;
      // 디버깅 묶음 U H3 — 쓰는 단추는 페이지별 값과 `/api/me` 의 쓰기 자격이 **둘 다** 참일 때만(위 `meCanWrite`)
      const w = meCanWrite;
      state.canEdit = w && result.data.can_edit === true;
      state.canSuggest = w && result.data.can_suggest === true;
      state.suggestionsOpen = result.data.suggestions_open;
      show(proposalButton,state.canSuggest && !state.editing);
      show(proposalsButton,state.canEdit && Number(state.suggestionsOpen)>0 && !state.editing);
      proposalsButton.textContent='제안 '+String(state.suggestionsOpen || 0);
      state.childTitles = result.data.child_titles && typeof result.data.child_titles === 'object'
        ? result.data.child_titles
        : {};
      state.linkTitles = result.data.link_titles && typeof result.data.link_titles === 'object' ? result.data.link_titles : {};
      state.fileTitles = result.data.file_titles && typeof result.data.file_titles === 'object' ? result.data.file_titles : {};
      state.attachmentTitles = result.data.attachment_titles && typeof result.data.attachment_titles === 'object' && !Array.isArray(result.data.attachment_titles)
        ? result.data.attachment_titles : {};
      pageWatch.kick();   // 카페 C4 — 새로 고침 뒤에도 검사 중이면 「검사 중이에요」 상자 → 끝나면 바뀜
      state.synced = result.data.synced || {};
      state.buttons = result.data.buttons || {};
      state.dbViews = result.data.db_views || {};
      state.mentionLabels = result.data.mention_labels && typeof result.data.mention_labels === 'object' ? result.data.mention_labels : {};
      // 7-C — 임베드 재료(허용 목록 · 틀을 여는가). 판단은 서버가 준 값뿐이다
      state.embeds = result.data.embeds && typeof result.data.embeds === 'object' ? result.data.embeds : { hosts: [], frames: false };
      drawBacklinks(Array.isArray(result.data.backlinks) ? result.data.backlinks : []);
      state.visibility = typeof page.visibility === 'string' ? page.visibility : 'private';
      state.space = result.data.space || null;
      // 디버깅 묶음 U3 — 이 페이지를 **무엇으로** 볼 수 있나(창구의 판정 결과 `granted_by`). 경로 줄 첫 칸의 이름·링크만 고른다(권한 판단 아님)
      state.grantedBy = typeof result.data.granted_by === 'string' ? result.data.granted_by : '';
      state.canSetVisibility = w && result.data.can_set_visibility === true;
      state.status = typeof page.status === 'string' ? page.status : null;
      state.canSetProperties = w && result.data.can_set_properties === true;
      state.owner = page.owner && typeof page.owner === 'object' ? page.owner : null;
      state.canManage = w && result.data.can_manage === true;
      state.canShare = w && result.data.can_share === true;
      state.canHandover = w && result.data.can_handover === true;
      state.shareLevel = typeof result.data.share_level === 'string' ? result.data.share_level : null;
      state.subtreeCount = typeof result.data.subtree_count === 'number' ? result.data.subtree_count : null;
      state.board = result.data.board && typeof result.data.board === 'object' ? result.data.board : null;
      state.writer = result.data.writer && typeof result.data.writer === 'object' ? result.data.writer : null;
      state.tags = Array.isArray(result.data.tags) ? result.data.tags : [];
      state.viewCount = typeof result.data.view_count === 'number' ? result.data.view_count : 0;
      state.resolved = result.data.resolved === true;
      state.canTag = w && result.data.can_tag === true;
      state.canPublish = w && result.data.can_publish === true;
      state.canUnpublish = w && result.data.can_unpublish === true;
      state.canHide = w && result.data.can_hide === true;
      state.canResolve = w && result.data.can_resolve === true;
      state.canComment = w && result.data.can_comment === true;
      state.canViewReads = result.data.can_view_reads === true;
      drawVisibility();
      // 카페 C2 — 익명게시판 글인가(창구가 준 종류) · 신고 메뉴 · 안내 한 줄. 이름표를 그리기 전에 정한다
      shared.pageIsAnon = !!(state.board && state.board.board_kind === 'anonymous');
      drawProps();
      drawBoard();
      drawCafe();
      drawTags();
      commentsLoaded = loadComments();   // B1-2 — 답글 사본을 되살릴 때 대상 댓글이 그려진 뒤에 찾는다
      loadReads();
      setText(titleEl, page.title);
      document.title = page.title + ' · 모아';
      setText(subEl, result.data.form_response && result.data.form_response.hidden ? '익명 응답' : (page.parent ? '상위: ' + page.parent.title : ''));
      documentAncestorIds = new Set([pageId, ...(Array.isArray(result.data.breadcrumb) ? result.data.breadcrumb.map(item => item.id) : [])]);
      drawCrumb(result.data.breadcrumb);
      drawRead();
      state.database = result.data.database || null;
      drawChildren(result.data.children);
      if (work) { work.pageLoaded(pageId, result.data); }
      dbView.draw(pageId, result.data);
      templateSave.draw(pageId, result.data);
      pageExport.draw(result.data);
      showEditors(result.data.editors);
      show(editButton, state.canEdit && !state.editing);
      // 디버깅 묶음 U3 — 「이력」은 창구가 이 페이지를 내준(읽기 판정 통과) 뒤에만. 막힌·없는 페이지에서는 숨은 채다(page_view.php 기본 hidden)
      show(document.getElementById('page-history'), true);
      show(moreHistoryItem, state.editing);   // UX-2 — 편집 중이면 「더보기」 안의 「이력」도(좁은 폭에서만 보인다 — CSS)
      // 5-A — 지우기·옮기기는 **관리**다. 편집 공유자에게는 `can_edit` 이 참이어도 이 둘이 없다.
      show(deleteButton, state.canManage);
      // 5-C — 게시판 글은 트리가 없어 옮길 자리가 없다(창구도 `space_governed`). 지우기는 쓴 사람이 한다.
      show(moveButton, state.canManage && !(state.board && state.board.kind === 'post'));
      // 개발 대기열 6 — 즐겨찾기 ☆ 는 이 페이지를 볼 수 있는 사람이면 누구나(넣기는 창구가 다시 판단한다)
      show(favButton, true);
      paintFavorite();
      show(visibilityButton, state.canSetVisibility);
      // 공유받은 사람 — 받은 권한 배지와 「공유 보기」(자기 공유 빼기). 관리할 수 있는 사람은 「바꾸기」로 연다.
      if (shareMineEl) {
        const label = SHARE_LABELS[state.shareLevel];
        setText(shareMineEl, label ? '받은 권한: ' + label : '');
        show(shareMineEl, !!label && !state.canShare);
      }
      /* 5-A″ — 팀 공간의 페이지에는 공개 범위가 없다(공간 등급을 따른다). 관리할 수 있는 팀원에게는
         같은 창의 **공유 부분만** 여는 「사람 추가」가 보인다. 판단은 창구가 준 `can_share` 뿐이다. */
      const teamShare = state.canShare && !!state.space && state.space.kind === 'team';
      setText(shareOpenLabel, teamShare ? '사람 추가' : '공유 보기');
      show(shareOpenButton, (!!SHARE_LABELS[state.shareLevel] && !state.canShare) || teamShare);
      setText(msg, '');
    });
  }

  /**
   * 머리의 공개 범위 배지 (4-B).
   * 팀 공간의 페이지에는 자기 범위가 없다(공간 등급을 따른다) — 그 자리에는 「팀 공간」을
   * 보인다. 어느 쪽이든 **창구가 준 값**이고 화면이 판단해서 고르지 않는다.
   */
  function drawVisibility() {
    /* 5-C — 게시판 글은 게시판 등급을 따른다(자기 범위가 없다) · 게시된 페이지는 「게시됨」.
       어느 쪽이든 창구가 준 값(`board.access_level`·`visibility`)이고 화면이 판단하지 않는다. */
    if (state.board && state.board.kind === 'post') {
      const level = BOARD_LEVEL_LABELS[state.board.access_level];
      if (visibilityBadgeEl && level) {
        /* 사용감 라운드 1 UX-6 — 게시판 글은 만들면 곧바로 보인다(5-C · 「게시」 단추가 없다) — 그래서 상태 글자가 **누가 보는지**를 말한다.
           값은 창구가 준 `board.access_level` · `board.hidden` 뿐이다(추측 0): 숨긴 글은 쓴 사람과 그 게시판 관리자만 본다
           (CommunityAuthz::decideBoardPostRead — 숨기면 게시판 갈래가 꺼진다) · 아니면 게시판 등급의 사람들(DESIGN 5장 등급 낱말). */
        const hidden = state.board.hidden === true;
        visibilityBadgeEl.className = 'badge ' + (hidden ? 'badge--off' : level.badge);
        visibilityBadgeEl.setAttribute('title', state.board.access_level + (hidden ? ' · hidden' : ''));
        setText(visibilityBadgeEl, hidden ? '숨김 · 쓴 사람·게시판 관리자만 봄' : '게시됨 · ' + level.audience);
        show(visibilityBadgeEl, true);
      }
      return;
    }
    if (state.visibility === 'published') {
      if (visibilityBadgeEl) {
        visibilityBadgeEl.className = 'badge badge--ok';
        visibilityBadgeEl.setAttribute('title', 'published');
        setText(visibilityBadgeEl, '게시됨');
        show(visibilityBadgeEl, true);
      }
      return;
    }
    if (state.space && state.space.kind === 'team') {
      if (visibilityBadgeEl) {
        visibilityBadgeEl.className = 'badge badge--team';
        visibilityBadgeEl.setAttribute('title', 'team');
        setText(visibilityBadgeEl, '팀 공간');
        show(visibilityBadgeEl, true);
      }
      return;
    }
    paintVisibility(visibilityBadgeEl, state.visibility);
  }

  /* ── 속성 줄 (4-D, C:F-37) ──
     상태 배지와 담당자 이름을 창구가 준 값으로 그린다. 고칠 수 없는 사람에게는 두 단추가
     `disabled` 이고 값은 그대로 보인다 — 「볼 수는 있으나 바꿀 수 없다」가 화면에도 그대로
     드러난다. 되살려 눌러도 `PATCH …/properties` 가 같은 함수로 다시 막는다 (C:T-51). */
  function drawProps() {
    if (!propsRow) { return; }
    paintStatus(statusBadgeEl, state.status);
    // 5-A 「소유자」 — 공간의 주인. 팀 공간은 「팀 공간」이다. 글자는 창구가 준 값 그대로다.
    // 5-C — 게시판 글은 「쓴 사람」이다(공간의 주인이 사람이 아니다).
    setText(document.getElementById('page-owner-label'), state.writer ? '쓴 사람' : '소유자');
    if (ownerNameEl && state.writer) {
      // 카페 C2 — 익명게시판 글은 「글쓴이」(+ 사람 아이콘 · 내 글이면 「(나)」). 실명은 지금 그대로
      personInto(ownerNameEl, state.writer, personLabel, { avatar: true });
    } else if (ownerNameEl) {
      let text = '—';
      if (state.owner && state.owner.kind === 'personal' && typeof state.owner.username === 'string') {
        text = personLabel(state.owner.display_name, state.owner.username)
          + (state.owner.is_active === false ? ' (쓰지 않는 계정)' : '');
      } else if (state.owner && state.owner.kind === 'team') {
        text = '팀 공간';
      }
      setText(ownerNameEl, text);
    }
    if (statusButton) { statusButton.disabled = !state.canSetProperties; }
    show(handoverButton, state.canHandover);
    show(propsRow, true);
  }

  /* ── 게시판 · 게시 · 숨김 · 해결됨 (5-C) ──
     단추 다섯은 창구가 준 `can_*` 로만 보인다. 누르면 창구를 부르고, 끝나면 **다시 받아** 그린다 —
     화면이 결과를 지어내지 않는다. 되살려 눌러도 창구가 같은 함수로 다시 막는다(DESIGN 원칙 1). */
  const boardGroup = document.getElementById('page-board-group');
  const boardLink = document.getElementById('page-board-link');
  const boardHidden = document.getElementById('page-board-hidden');
  const resolvedBadge = document.getElementById('page-resolved-badge');
  const publishButton = document.getElementById('page-publish');
  const unpublishButton = document.getElementById('page-unpublish');
  const hideButton = document.getElementById('page-hide');
  const resolveButton = document.getElementById('page-resolve');
  const viewsEl = document.getElementById('page-views');

  function drawBoard() {
    const b = state.board;
    show(boardGroup, !!b);
    if (b) {
      if (boardLink) {
        boardLink.setAttribute('href', COMMUNITY_BASE + '/b/' + String(b.id));
        setText(boardLink, b.name);
      }
      show(boardHidden, b.hidden === true);
      const qa = b.board_kind === 'qa';
      if (resolvedBadge) {
        resolvedBadge.className = 'badge' + (state.resolved ? ' badge--ok' : '');
        setText(resolvedBadge, state.resolved ? '해결됨' : '미해결');
      }
      show(resolvedBadge, qa);
      setText(resolveButton, state.resolved ? '해결됨 빼기' : '해결됨으로 표시');
    }
    show(publishButton, state.canPublish);
    show(unpublishButton, state.canUnpublish);
    show(hideButton, state.canHide);
    setText(hideButton, b && b.hidden === true ? '숨김 풀기' : '숨기기');
    show(resolveButton, state.canResolve);
    setText(viewsEl, '조회 ' + String(state.viewCount));
    show(viewsEl, !!b);
  }

  /* ── 카페 C2 (DESIGN 2.29 「익명 표시」 · 「신고」) ──
     안내 한 줄 · 댓글 칸 멘션 끔 · 「신고」(카페 글이고 내 글이 아니고 쓰기 자격일 때 — 표시용이다. 막는 것은 창구 decideReportCreate) */
  const anonNote = document.getElementById('page-anon-note');
  const reportItem = document.getElementById('page-report');
  function mine(person, owner) {
    if (person && typeof person === 'object') { return isAnon(person) ? person.is_me === true : (meUsername !== null && person.username === meUsername); }
    return !!owner && meUsername !== null && owner.username === meUsername;
  }
  function drawCafe() {
    show(anonNote, shared.pageIsAnon);
    if (commentInput) {
      commentInput.setAttribute('placeholder', shared.pageIsAnon ? '댓글을 적어 주세요 — 다른 사람에게는 「익명N」으로 보여요' : '@아이디 로 사람을 부를 수 있습니다');
    }
    if (commentMention) { commentMention.refresh(); }
    const b = state.board;
    show(reportItem, IS_CAFE && meCanWrite && !!b && b.hidden !== true && !mine(state.writer, state.owner));
  }
  if (reportItem) {
    reportItem.addEventListener('click', () => {
      if (!cafe) { return; }
      cafe.openReport({ pageId: pageId, onDone: (text) => { setText(msg, text); if (msg) { msg.classList.add('msg--ok'); } } });
    });
  }

  /** 단추 하나 = 창구 한 번. 성공하면 다시 받아 그리고(배지·단추가 바뀌는 것이 곧 알림 — DESIGN 4장),
      실패하면 서버 문구(또는 사유 표)를 `#msg`(오류 줄)에 보인다. */
  function boardAction(button, method, path, payload, okText) {
    if (!button) { return; }
    button.disabled = true;
    api(method, path, payload).then((result) => {
      button.disabled = false;
      if (result.status === 401) { toLogin(); return null; }
      if (result.status === 200) {
        return api('GET', '/api/pages/' + pageId).then((again) => {
          if (again.status === 200) { return load().then(() => setText(msg, okText)); }
          // 해제·숨김 뒤 더는 볼 수 없으면(게시판 등급으로만 보던 관리자) 게시판으로 돌아간다.
          window.location.assign(state.board ? COMMUNITY_BASE + '/b/' + String(state.board.id) : COMMUNITY_BASE + '/workspace');
          return null;
        });
      }
      const reason = result.data && typeof result.data.reason === 'string' ? result.data.reason : '';
      setText(msg, BOARD_REASONS[reason] || errorMessage(result.data));
      return null;
    }).catch(() => { button.disabled = false; setText(msg, GENERIC_ERROR); });
  }

  if (unpublishButton) {
    unpublishButton.addEventListener('click', () => boardAction(unpublishButton, 'DELETE',
      '/api/pages/' + pageId + '/publish', undefined, ''));
  }
  if (hideButton) {
    hideButton.addEventListener('click', () => {
      const hidden = !!state.board && state.board.hidden === true;
      boardAction(hideButton, hidden ? 'DELETE' : 'POST', '/api/pages/' + pageId + '/hide', undefined,
        '');
    });
  }
  if (resolveButton) {
    resolveButton.addEventListener('click', () => boardAction(resolveButton, 'PATCH',
      '/api/pages/' + pageId + '/properties', { resolved: !state.resolved }, ''));
  }

  /* 「게시판에 게시」 창 — 고를 수 있는 게시판은 창구가 `can_write` 로 답한 것만. */
  const publishDialog = document.getElementById('publish-dialog');
  const publishChoices = document.getElementById('publish-choices');
  const publishEmpty = document.getElementById('publish-empty');
  const publishMsg = document.getElementById('publish-msg');
  const publishSave = document.getElementById('publish-save');
  if (publishButton && publishDialog && typeof publishDialog.showModal === 'function') {
    publishButton.addEventListener('click', () => {
      setText(publishMsg, '');
      clear(publishChoices);
      if (publishSave) { publishSave.disabled = true; }
      allBoards().then((data) => {
        // 카페 C2 — 익명게시판에는 게시할 수 없다(ARCH 25.3 — 창구 decidePublish 가 403 anon_board). 고를 거리에서 미리 뺀다(편의)
        const writable = data && Array.isArray(data.boards) ? data.boards.filter((b) => b.can_write === true && b.anonymous !== true) : [];
        for (const b of writable) {
          const label = document.createElement('label');
          label.className = 'field field--full';
          const line = document.createElement('span');
          line.className = 'field__label';
          const radio = document.createElement('input');
          radio.type = 'radio';
          radio.name = 'publish-board';
          radio.value = String(b.id);
          radio.addEventListener('change', () => { if (publishSave) { publishSave.disabled = false; } });
          line.appendChild(radio);
          line.appendChild(document.createTextNode(' ' + b.name + ' '));
          const badge = boardLevelBadge(b.access_level);
          if (badge) { line.appendChild(badge); }
          label.appendChild(line);
          if (b.description) {
            const help = document.createElement('span');
            help.className = 'help';
            help.textContent = b.description;
            label.appendChild(help);
          }
          publishChoices.appendChild(label);
        }
        show(publishEmpty, writable.length === 0);
      });
      publishDialog.showModal();
    });
    for (const close of publishDialog.querySelectorAll('[data-publish-close]')) {
      close.addEventListener('click', () => publishDialog.close());
    }
    if (publishSave) {
      publishSave.addEventListener('click', () => {
        const picked = publishDialog.querySelector('input[name="publish-board"]:checked');
        if (!picked) { return; }
        publishSave.disabled = true;
        api('POST', '/api/pages/' + pageId + '/publish', { board_id: Number(picked.value) }).then((result) => {
          publishSave.disabled = false;
          if (result.status === 401) { toLogin(); return null; }
          if (result.status === 200) {
            publishDialog.close();
            // 성공은 화면이 곧바로 바뀌는 것으로 알린다(배지 「게시됨」 — DESIGN 4장 「성공」). `#msg` 는 오류 줄이다.
            return load();
          }
          const reason = result.data && typeof result.data.reason === 'string' ? result.data.reason : '';
          setText(publishMsg, BOARD_REASONS[reason] || errorMessage(result.data));
          return null;
        }).catch(() => { publishSave.disabled = false; setText(publishMsg, GENERIC_ERROR); });
      });
    }
  }

  /* ── 태그 (5-C) — 칩 + 입력 칸. 보내는 것은 언제나 **새 태그 전부**(`PUT …/tags`) ── */
  const tagsEl = document.getElementById('page-tags');
  const tagInput = document.getElementById('page-tag-input');
  const tagOptions = document.getElementById('page-tag-options');

  function drawTags() {
    clear(tagsEl);
    if (state.tags.length === 0 && !state.canTag) { setText(tagsEl, '—'); }
    for (const t of state.tags) {
      if (state.canTag) {
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'btn btn--small btn--quiet';
        chip.setAttribute('aria-label', '태그 ' + t + ' 빼기');
        chip.appendChild(document.createTextNode('#' + t + ' '));
        chip.appendChild(makeIcon('x'));
        chip.addEventListener('click', () => putTags(state.tags.filter((x) => x !== t)));
        tagsEl.appendChild(chip);
      } else {
        const badge = document.createElement('span');
        badge.className = 'badge';
        badge.textContent = '#' + t;
        tagsEl.appendChild(badge);
      }
    }
    show(tagInput, state.canTag);
    if (state.canTag && state.board) { fillTagOptions(tagOptions, state.board.id, ''); }
    drawPropsSummary();
  }

  /* ── 좁은 폭 속성 요약 (디버깅 묶음 U3 · DESIGN 2.9 「좁은 폭 글 화면」) ──
     720px 미만에서는 속성 줄(상태·쓴 사람·공개 범위·게시판·태그·조회 — 한 줄에 하나라 여섯 줄)이 본문을 첫 화면 아래로 밀었다.
     그래서 속성 줄을 접고(`data-folded` — CSS 가 좁은 폭에서만 숨긴다) 요약 한 줄 + 「속성 더보기」를 둔다. 요약은 속성 줄이
     **이미 그린 것**을 옮긴다 — 배지는 cloneNode, 이름·수는 textContent(새 값·새 판단 0). 단추·편집 권한은 속성 줄 그대로다
     (펼치면 같은 단추가 같은 자리에 있다). 넓은 화면에서는 요약이 숨고 `data-folded` 는 아무 일도 하지 않는다. */
  function paintPropsFold() {
    if (!propsRow || !propsToggle) { return; }
    propsRow.toggleAttribute('data-folded', !propsOpen);
    propsToggle.setAttribute('aria-expanded', propsOpen ? 'true' : 'false');
    setText(propsToggleLabel, propsOpen ? '속성 접기' : '속성 더보기');
  }

  function drawPropsSummary() {
    if (!propsSummary || !propsLine) { return; }
    clear(propsLine);
    // 배지(상태 — 있을 때만 · 공개 범위)는 속성 줄의 것을 복제하고, 나머지는 글자 한 덩어리(「쓴 사람 … · 게시판 … · 태그 n · 조회 n」)
    for (const el of [state.status ? statusBadgeEl : null, visibilityBadgeEl]) {
      if (!el || el.hidden || el.textContent.trim() === '') { continue; }
      const c = el.cloneNode(true);
      c.removeAttribute('id');
      propsLine.appendChild(c);
    }
    const words = [];
    const who = ownerNameEl ? ownerNameEl.textContent.trim() : '';
    if (who !== '' && who !== '—' && who !== '…') {
      const label = document.getElementById('page-owner-label');
      words.push((label ? label.textContent.trim() + ' ' : '') + who);
    }
    if (state.board && typeof state.board.name === 'string' && state.board.kind !== 'post') { words.push('게시판 ' + state.board.name); }
    if (state.tags.length > 0) { words.push('태그 ' + String(state.tags.length)); }
    if (state.board) { words.push('조회 ' + String(state.viewCount)); }
    if (words.length > 0) {
      const t = document.createElement('span');
      t.textContent = words.join(' · ');
      propsLine.appendChild(t);
    }
    paintPropsFold();
    show(propsSummary, true);
  }
  if (propsToggle) {
    // 초점은 「속성 접기」에 그대로 둔다 — 펼친 줄은 바로 아래라 Tab 한 번이면 닿고, 다시 접기도 쉽다
    propsToggle.addEventListener('click', () => { propsOpen = !propsOpen; paintPropsFold(); });
  }

  function putTags(list) {
    return api('PUT', '/api/pages/' + pageId + '/tags', { tags: list }).then((result) => {
      if (result.status === 401) { toLogin(); return; }
      if (result.status === 200) {
        state.tags = Array.isArray(result.data.page.tags) ? result.data.page.tags : [];
        drawTags();
        setText(msg, '');
        return;
      }
      setText(msg, result.status === 400
        ? '태그는 10개까지, 하나에 30자까지 붙일 수 있습니다(줄바꿈·제어 문자는 안 됩니다).'
        : errorMessage(result.data));
    }).catch(() => setText(msg, GENERIC_ERROR));
  }

  if (tagInput) {
    tagInput.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' || event.isComposing) { return; }
      event.preventDefault();
      const add = splitTags(tagInput.value);
      if (add.length === 0) { return; }
      tagInput.value = '';
      putTags(state.tags.concat(add));
    });
    tagInput.addEventListener('input', () => {
      if (state.board) { fillTagOptions(tagOptions, state.board.id, tagInput.value.trim()); }
    });
  }

  /* ── 댓글 (5-D, PRD F-21 · F-22) ──
     목록·단추는 창구가 준 값(`can_edit`·`can_hide`·`can_resolve`·`can_comment`)만 따른다. 본문은 평문 —
     `textContent` 로만 넣고 CSS 가 줄바꿈을 보인다(결정 D-1). 누를 때마다 창구를 부르고 **다시 받아** 그린다. */
  const commentsWrap = document.getElementById('page-comments-wrap');
  const commentsList = document.getElementById('page-comments');
  const commentsEmpty = document.getElementById('page-comments-empty');
  const commentsCount = document.getElementById('page-comments-count');
  const commentsMsg = document.getElementById('comments-msg');
  const commentForm = document.getElementById('comment-form');
  const commentInput = document.getElementById('comment-input');
  const commentQuoteEl = document.getElementById('comment-quote');
  const commentReplyEl = document.getElementById('comment-reply-to');
  const commentQuotePick = document.getElementById('comment-quote-pick');
  const commentCancel = document.getElementById('comment-cancel');
  const commentSubmit = document.getElementById('comment-submit');
  const readsEl = document.getElementById('page-reads');
  let replyTo = null;      // {id, who}
  let anchor = null;       // {block_id, quote}
  /* Q:F-12 U4-4(DESIGN 2.27) — `@` 후보 목록 · 확인 색 · 「부를 사람」 줄은 멘션 입력 부품 한 벌(작업 칸 댓글과 같은 함수).
     떠 있는 목록 id 는 옛 칸 안 목록의 id(`comment-mentions`)를 잇는다 · 「찾을 수 없는 사람」 안내는 보내기 줄 앞 */
  const commentMention = commentInput
    ? createMentionInput(commentInput, { pageId: pageId, api: api, personLabel: personLabel, listId: 'comment-mentions', disabled: () => shared.pageIsAnon })
    : null;
  if (commentMention && commentSubmit && commentSubmit.parentNode) { commentSubmit.parentNode.insertBefore(commentMention.notice, commentSubmit.parentNode.firstChild); }

  /* B1-2 — 댓글·답글 쓰기 칸의 임시저장(W-4). 쓰기 칸에는 자동 저장이 없어, 로그인이 끊긴 채 「댓글 달기」를 누르면
     쓴 글 전체가 사라지던 자리다. 열쇠는 `…:comment:<페이지>:<답글 대상 uuid 또는 new>` — 지금 쓰기 칸의 대상이 곧 열쇠이고,
     대상이 바뀌면(「답글」·「그만두기」) 사본도 새 열쇠로 옮긴다. 열쇠는 처음 입력할 때 받는다(한 화면에 한 번). */
  let commentKeyNow = null;
  let commentDraftTimer = null;
  let commentSeq = 0;

  function composerKey() {
    return drafts ? drafts.commentKey(pageId, replyTo ? replyTo.id : 'new') : null;
  }

  function writeCommentDraft() {
    if (!drafts || !commentInput || !drafts.hasKey()) { return Promise.resolve(); }
    window.clearTimeout(commentDraftTimer);
    commentDraftTimer = null;
    const k = composerKey();
    if (k === null) { return Promise.resolve(); }
    if (commentKeyNow !== null && commentKeyNow !== k) { drafts.remove(commentKeyNow); }
    commentKeyNow = k;
    commentSeq += 1;
    const mySeq = commentSeq;
    const text = commentInput.value;
    if (text.trim() === '') { drafts.remove(k); return Promise.resolve(); }
    return drafts.write(k, { body: text }, () => mySeq === commentSeq).then((ok) => { draftOff(!ok); });
  }

  function scheduleCommentDraft() {
    if (commentDraftTimer !== null) { return; }
    commentDraftTimer = window.setTimeout(() => { commentDraftTimer = null; writeCommentDraft(); }, 1000);
  }

  /** 보내졌다 — 그 사본을 지우고 날아가 있는 쓰기를 무른다 */
  function clearCommentDraft(k) {
    window.clearTimeout(commentDraftTimer);
    commentDraftTimer = null;
    commentSeq += 1;
    if (drafts && k) { drafts.remove(k); }
    if (drafts && commentKeyNow && commentKeyNow !== k) { drafts.remove(commentKeyNow); }
    commentKeyNow = null;
  }

  function commentButton(text, onClick) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'btn btn--small btn--quiet';
    b.textContent = text;
    b.addEventListener('click', onClick);
    return b;
  }

  function commentCall(method, path, payload) {
    setText(commentsMsg, '');
    if (commentsMsg) { commentsMsg.classList.remove('msg--ok'); }
    return api(method, path, payload).then((result) => {
      if (result.status === 401) { toLogin(); return false; }
      if (result.status === 200 || result.status === 201) { loadComments(); return true; }
      const reason = result.data && typeof result.data.reason === 'string' ? result.data.reason : '';
      setText(commentsMsg, reason === 'reply_depth' ? '답글에는 답글을 달 수 없습니다.'
        : (BOARD_REASONS[reason] || errorMessage(result.data)));
      return false;
    }).catch(() => { setText(commentsMsg, GENERIC_ERROR); return false; });
  }

  /**
   * 댓글 본문의 주소를 링크로 (디버깅 묶음 U3 · 시연 2026-09-26 「댓글 속 주소가 링크가 아니다」).
   * 본문은 평문 그대로다(결정 D-1) — **글자 노드와 `<a>` 를 하나씩 만들어** 붙인다(문자열을 HTML 로 바꾸는 자리 0). 링크가 되는 것은
   * `http://`·`https://` 로 시작하는 조각뿐이고(`javascript:`·`data:` 등 다른 스킴은 정규식에 걸리지 않아 글자로 남는다),
   * 걸린 조각도 `new URL()` 로 다시 읽어 스킴이 http·https 인 것만 쓴다(doc-render 의 링크 규칙과 같은 두 스킴).
   * 끝의 문장 부호(. , ) 」 등)는 주소에서 떼고, 한글이 붙으면 거기서 끊는다(「…/p/uuid에서」 — 복사한 주소는 한글이 %xx 로 바뀌어 있다).
   * 사이트 안 주소(`location.origin` 과 같은 곳)는 같은 창, 밖 주소는 새 창 — 둘 다 `rel="noopener noreferrer"`. 아이디·비밀번호가 든 주소는 글자로.
   */
  const COMMENT_URL_SPLIT = /(https?:\/\/[^\s<>"'「」『』\u3131-\u318e\uac00-\ud7a3]+)/;
  const COMMENT_URL_TAIL = /[.,;:!?)\]}'"。、…]+$/;
  let commentMentions = {};
  function linkifyInto(el, text) {
    const parts = String(text).split(COMMENT_URL_SPLIT);
    for (let i = 0; i < parts.length; i++) {
      const piece = parts[i];
      if (piece === '') { continue; }
      // 카페 C4 — 주소가 아닌 글자 조각의 `@아이디` 는 입력 칸과 같은 확인 표시(작업 칸 댓글과 같은 함수 mentionInto)
      if (i % 2 === 0) { mentionInto(el, piece, commentMentions); continue; }
      let raw = piece;
      let tail = '';
      const cut = raw.replace(COMMENT_URL_TAIL, '');
      if (cut !== raw) { tail = raw.slice(cut.length); raw = cut; }
      let url = null;
      try { url = new URL(raw); } catch (e) { url = null; }
      // 아이디·비밀번호가 든 주소(`https://좋은곳@다른곳`)는 보이는 글자와 가는 곳이 달라 보일 수 있어 글자로 둔다
      if (!url || (url.protocol !== 'https:' && url.protocol !== 'http:') || url.hostname === '' || url.username !== '' || url.password !== '') {
        el.appendChild(document.createTextNode(piece));
        continue;
      }
      // 클래스 없는 글 속 링크 — 터치 넓힘(app.css 끝 `a[class]`)이 윗줄·아랫줄 글자를 덮지 않게(DESIGN 4장 「글 속 링크는 규칙 밖」). 모양은 community.css
      const a = document.createElement('a');
      a.setAttribute('href', url.href);
      a.setAttribute('rel', 'noopener noreferrer');
      if (url.origin !== window.location.origin) { a.setAttribute('target', '_blank'); }
      a.textContent = raw;
      el.appendChild(a);
      if (tail !== '') { el.appendChild(document.createTextNode(tail)); }
    }
  }

  function commentItem(c) {
    const li = document.createElement('li');
    li.className = 'list__item' + (c.parent_id ? ' is-reply' : '');
    li.id = 'comment-' + c.id;
    const head = document.createElement('div');
    head.className = 'list__head';
    const who = document.createElement('strong');
    personInto(who, c.author, personLabel, { avatar: true });   // 카페 C2 — 익명게시판은 「익명N」 · 「글쓴이」(+ 사람 아이콘 · 「(나)」)
    head.appendChild(who);
    const when = document.createElement('span');
    when.className = 'muted';
    when.textContent = shortWhen(c.created_at) + (c.edited ? ' · 고침' : '');
    head.appendChild(when);
    if (c.resolved) {
      const r = document.createElement('span');
      r.className = 'badge badge--ok';
      r.textContent = '해결됨';
      head.appendChild(r);
    }
    if (c.hidden) {
      const h = document.createElement('span');
      h.className = 'badge badge--off';
      h.textContent = '숨김';
      head.appendChild(h);
    }
    li.appendChild(head);
    if (c.quote) {
      const q = document.createElement('p');
      q.className = 'help';
      q.textContent = '「' + c.quote + '」';
      if (c.block_id) {
        const go = document.createElement('a');
        go.setAttribute('href', '#block-' + c.block_id);
        go.textContent = ' 본문에서 보기';
        q.appendChild(go);
      }
      li.appendChild(q);
    }
    const body = document.createElement('div');
    body.className = 'list__body';
    if (c.deleted) { body.textContent = '지운 댓글입니다.'; body.classList.add('muted'); }
    else if (c.body === null) { body.textContent = '숨긴 댓글입니다.'; body.classList.add('muted'); }
    else { linkifyInto(body, c.body); }
    li.appendChild(body);

    if (!c.deleted) {
      const actions = document.createElement('div');
      actions.className = 'toolbar';
      if (state.canComment && !c.parent_id) {
        actions.appendChild(commentButton('답글', () => {
          replyTo = { id: c.id, who: who.textContent };
          drawComposer();
          if (commentInput) { commentInput.focus(); }
        }));
      }
      if (!c.hidden && c.body !== null) {
        const help = commentButton((c.helped ? '도움됐어요 취소' : '도움됐어요') + ' ' + String(c.helpful), () => {
          commentCall(c.helped ? 'DELETE' : 'POST', '/api/comments/' + c.id + '/helpful');
        });
        help.disabled = !state.canComment;
        actions.appendChild(help);
      }
      if (c.can_resolve) {
        actions.appendChild(commentButton(c.resolved ? '해결됨 빼기' : '해결됨', () => {
          commentCall(c.resolved ? 'DELETE' : 'POST', '/api/comments/' + c.id + '/resolve');
        }));
      }
      if (c.can_edit) {
        // 고치기 — 그 자리에서 입력 칸으로 바뀐다(모달을 띄우지 않는다). 「저장」 을 누르면 창구로.
        actions.appendChild(commentButton('고치기', () => {
          const box = document.createElement('div');
          box.className = 'field field--full';
          const area = document.createElement('textarea');
          area.rows = 3;
          area.maxLength = 2000;
          area.value = c.body || '';
          area.setAttribute('aria-label', '댓글 고치기');
          box.appendChild(area);
          const row = document.createElement('div');
          row.className = 'toolbar';
          row.appendChild(commentButton('저장', () => {
            if (area.value.trim() !== '') { commentCall('PATCH', '/api/comments/' + c.id, { body: area.value }); }
          }));
          row.appendChild(commentButton('그만두기', () => loadComments()));
          box.appendChild(row);
          body.replaceWith(box);
          actions.hidden = true;
          area.focus();
        }));
        // 지우기 — 되돌릴 수 없는 동작이라 한 번 더 누르게 한다(DESIGN 4장 「위험 동작 → 확인」).
        const del = commentButton('지우기', () => {
          if (del.dataset.armed === '1') { commentCall('DELETE', '/api/comments/' + c.id); return; }
          del.dataset.armed = '1';
          del.textContent = '정말 지우기';
        });
        del.classList.add('btn--danger');
        actions.appendChild(del);
      }
      if (c.can_hide) {
        actions.appendChild(commentButton(c.hidden ? '숨김 풀기' : '숨기기', () => {
          commentCall(c.hidden ? 'DELETE' : 'POST', '/api/comments/' + c.id + '/hide');
        }));
      }
      /* 카페 C2 (DESIGN 2.29 「신고」) — 카페 글 · 쓰기 자격 · 내 댓글이 아님 · 보이는 댓글일 때만(표시용 — 창구가 다시 막는다).
         Q:F-12 W1d(2026-10-14 사용자 확인 「댓글 신고 기능은 없어 보임」) — 「…」 메뉴 안에 숨기지 않고 「답글」 · 「고치기」와 같은 줄의 글자 단추.
         숨긴 글(그 글의 머리 「신고」와 같은 조건 — state.board.hidden)의 댓글에도 0 */
      if (IS_CAFE && meCanWrite && !c.hidden && c.body !== null && !mine(c.author, null) && !(state.board && state.board.hidden === true)) {
        const rep = commentButton('신고', () => {
          if (cafe) { cafe.openReport({ pageId: pageId, commentId: c.id, onDone: (text) => { setText(commentsMsg, text); if (commentsMsg) { commentsMsg.classList.add('msg--ok'); } } }); }
        });
        rep.setAttribute('aria-haspopup', 'dialog');
        rep.setAttribute('data-comment-report', c.id);
        actions.appendChild(rep);
      }
      if (actions.firstChild) { li.appendChild(actions); }
    }
    return li;
  }

  function loadComments() {
    return api('GET', '/api/pages/' + pageId + '/comments').then((result) => {
      if (result.status !== 200) { show(commentsWrap, false); return; }
      const all = Array.isArray(result.data.comments) ? result.data.comments : [];
      // 카페 C4 — 남긴 댓글의 `@아이디` 확인 지도(서버 판정 — 이 목록에 나온 아이디만)
      commentMentions = result.data.mention_status && typeof result.data.mention_status === 'object' && !Array.isArray(result.data.mention_status)
        ? result.data.mention_status : {};
      clear(commentsList);
      // 첫 댓글 → 그 아래 답글 순으로(창구는 시각 순으로 준다)
      const replies = new Map();
      for (const c of all) {
        if (c.parent_id) {
          if (!replies.has(c.parent_id)) { replies.set(c.parent_id, []); }
          replies.get(c.parent_id).push(c);
        }
      }
      for (const c of all.filter((x) => !x.parent_id)) {
        commentsList.appendChild(commentItem(c));
        for (const r of replies.get(c.id) || []) { commentsList.appendChild(commentItem(r)); }
      }
      setText(commentsCount, String(result.data.count || 0) + '개');
      show(commentsEmpty, all.length === 0);
      state.canComment = meCanWrite && result.data.can_comment === true;
      show(commentForm, state.canComment);
      show(commentsWrap, true);
      if (window.location.hash.indexOf('#comment-') === 0) {
        const target = document.getElementById(window.location.hash.slice(1));
        if (target) { target.scrollIntoView({ block: 'center' }); }
      }
    }).catch(() => {});
  }

  function drawComposer() {
    setText(commentReplyEl, replyTo ? replyTo.who + ' 님 댓글에 답글' : '');
    show(commentReplyEl, !!replyTo);
    setText(commentQuoteEl, anchor ? '인용: 「' + anchor.quote + '」' : '');
    show(commentQuoteEl, !!anchor);
    show(commentCancel, !!replyTo || !!anchor);
    // B1-2 — 답글 대상이 바뀌었으면 임시저장 사본도 그 열쇠로 옮긴다
    if (drafts && drafts.hasKey() && commentKeyNow !== null && commentKeyNow !== composerKey()) { writeCommentDraft(); }
  }

  /* 블록 앵커 — 읽기 화면에서 고른 글자와 그 글자가 든 블록(렌더러가 붙인 `id="block-<uuid>"`) */
  if (commentQuotePick) {
    commentQuotePick.addEventListener('click', () => {
      const sel = window.getSelection ? window.getSelection() : null;
      const text = sel ? String(sel).replace(/\s+/g, ' ').trim() : '';
      const node = sel && sel.anchorNode ? (sel.anchorNode.nodeType === 1 ? sel.anchorNode : sel.anchorNode.parentElement) : null;
      const block = node && docEl.contains(node) ? node.closest('[id^="block-"]') : null;
      if (text === '' || !block) {
        setText(commentsMsg, '먼저 본문(읽기 화면)에서 인용할 글자를 끌어서 고르세요.');
        return;
      }
      // 글자(코드 포인트) 단위로 300자 · 제어 문자는 빈칸으로 — 서버 규칙(`community_clean_plain`)과 같게
      const clean = Array.from(text.replace(/[\u0000-\u001f\u007f]/g, ' ')).slice(0, 300).join('').trim();
      anchor = { block_id: block.id.slice('block-'.length), quote: clean };
      setText(commentsMsg, '');
      drawComposer();
    });
  }
  if (commentCancel) {
    commentCancel.addEventListener('click', () => { replyTo = null; anchor = null; drawComposer(); });
  }

  if (commentInput) {
    commentInput.addEventListener('input', () => {
      // B1-2 — 처음 입력할 때 열쇠를 받고(한 번), 그 뒤 1초 묶음으로 사본을 쓴다. 못 받으면 쓰지 않는다(평문 대체 0).
      if (drafts) {
        if (!drafts.available()) { draftOff(true); return; }
        drafts.ready().then((ok) => { if (ok) { scheduleCommentDraft(); } else { draftOff(true); } });
      }
    });
  }
  if (commentForm) {
    commentForm.addEventListener('submit', (event) => {
      event.preventDefault();
      const text = commentInput ? commentInput.value : '';
      if (text.trim() === '') { setText(commentsMsg, '댓글을 적어 주세요.'); return; }
      const payload = { body: text };
      if (replyTo) { payload.parent_id = replyTo.id; }
      if (anchor) { payload.block_id = anchor.block_id; payload.quote = anchor.quote; }
      commentSubmit.disabled = true;
      const sentKey = composerKey();
      // 401 이면 commentCall → toLogin 이 떠나기 전에 이 칸의 사본을 암호화해 남긴다(leaveHooks). 되살릴 때 자동 전송 0.
      commentCall('POST', '/api/pages/' + pageId + '/comments', payload).then((ok) => {
        commentSubmit.disabled = false;
        if (ok) {
          clearCommentDraft(sentKey);   // B1-2 설계 4 — 보내졌다
          commentInput.value = '';
          replyTo = null;
          anchor = null;
          drawComposer();
          if (commentMention) { commentMention.reset(); }
        }
      });
    });
  }

  /* 공지 「읽은 사람 n/N」 — 쓴 사람·Admin 에게만(창구 `can_view_reads`). 수만 보인다(결정 D-8). */
  function loadReads() {
    if (!readsEl) { return; }
    if (!state.canViewReads) { show(readsEl, false); return; }
    api('GET', '/api/pages/' + pageId + '/reads').then((result) => {
      if (result.status !== 200) { show(readsEl, false); return; }
      setText(readsEl, '읽은 사람 ' + String(result.data.reads.read) + ' / ' + String(result.data.reads.total));
      show(readsEl, true);
    }).catch(() => {});
  }

  /* ── 「상태」 창 (4-D) ──
     라디오 여섯(없음 + 다섯)이다. 「없음」의 값은 빈 문자열이고, 창구에는 `null` 로 보낸다 —
     「값이 없다」와 「빈 글자」는 다른 것이고, DB 도 NULL 과 '' 를 다르게 본다. */
  const statusDialog = document.getElementById('status-dialog');
  const statusMsg = document.getElementById('status-msg');
  const statusSave = document.getElementById('status-save');

  function statusRadios() {
    return statusDialog ? statusDialog.querySelectorAll('input[name="page-status"]') : [];
  }

  function openStatus() {
    if (!statusDialog || typeof statusDialog.showModal !== 'function') { return; }
    setText(statusMsg, '');
    for (const radio of statusRadios()) {
      radio.checked = radio.value === (state.status === null ? '' : state.status);
    }
    if (!statusDialog.open) { statusDialog.showModal(); }
  }

  function pickedStatusValue() {
    for (const radio of statusRadios()) {
      if (radio.checked) { return radio.value === '' ? null : radio.value; }
    }
    return null;
  }

  /** 속성 창구 한 번. 성공하면 화면을 **창구가 준 값으로** 다시 그린다. */
  function patchProperties(payload, msgEl, onDone) {
    return api('PATCH', '/api/pages/' + pageId + '/properties', payload)
      .then((result) => {
        if (result.status === 401) { toLogin(); return null; }
        if (result.status === 200) { return load().then(() => onDone(result.data)); }
        const reason = result.data && typeof result.data.reason === 'string' ? result.data.reason : '';
        setText(msgEl, VISIBILITY_REASONS[reason] || errorMessage(result.data));
        return null;
      });
  }

  if (statusButton && statusDialog) {
    statusButton.addEventListener('click', openStatus);
    for (const close of statusDialog.querySelectorAll('[data-status-close]')) {
      close.addEventListener('click', () => statusDialog.close());
    }
    if (statusSave) {
      statusSave.addEventListener('click', () => {
        statusSave.disabled = true;
        patchProperties({ status: pickedStatusValue() }, statusMsg, () => { statusDialog.close(); })
          .then(() => { statusSave.disabled = false; })
          .catch(() => { statusSave.disabled = false; setText(statusMsg, GENERIC_ERROR); });
      });
    }
  }

  /* ── 「공개 범위」 창 (4-B) ──
     라디오 셋(비공개·팀·사내) + 「하위 페이지까지 함께」 체크다. 「게시」는 없다 —
     게시판 공간이 아직 없기 때문이고, 보내도 창구가 400 으로 막는다.
     거부 사유는 **사람 문장**으로 바꿔 보인다(표 `VISIBILITY_REASONS`). */
  const visibilityDialog = document.getElementById('visibility-dialog');
  const visibilityMsg = document.getElementById('visibility-msg');
  const visibilitySave = document.getElementById('visibility-save');
  const cascadeBox = document.getElementById('visibility-cascade');

  function visibilityRadios() {
    return visibilityDialog
      ? visibilityDialog.querySelectorAll('input[name="page-visibility"]')
      : [];
  }

  /* ── 「사람 추가」 (5-A) — 공개 범위 창 아래쪽 ──
     후보는 `GET /api/share-candidates?page=&q=`, 목록은 `GET /api/pages/{id}/shares` 가 준 것만
     그린다. 추가·등급 바꾸기·빼기는 **누르는 즉시** 창구로 가고, 끝나면 목록을 **다시 받는다** —
     화면이 줄을 지어내지 않는다. 보기 공유자·팀·사내로 보는 사람에게 창구는 **수만** 준다. */
  const choicesBox = document.getElementById('visibility-choices');
  const shareMsg = document.getElementById('share-msg');
  const shareAdd = document.getElementById('share-add');
  const shareSearch = document.getElementById('share-search');
  const shareLevelPick = document.getElementById('share-level');
  const shareCandidates = document.getElementById('share-candidates');
  const shareCount = document.getElementById('share-count');
  const shareList = document.getElementById('share-list');
  const shareLeave = document.getElementById('share-leave');
  const shareTitle = document.getElementById('share-title');
  const shareHint = document.getElementById('share-hint');
  const visibilityTitle = document.getElementById('visibility-title');
  const shareOpenLabel = document.getElementById('page-share-open-label');
  let shareTimer = null;
  let shareMyLevel = null;
  // 「내 공유 빼기」가 보낼 계정명 — **창구가 알려 준다**(`me`). 화면이 내가 누구인지 따로 알지 않는다.
  let shareMe = '';

  function sharePath(username) {
    return '/api/pages/' + pageId + '/shares' + (username ? '/' + encodeURIComponent(username) : '');
  }

  function shareFail(result) {
    const reason = result.data && typeof result.data.reason === 'string' ? result.data.reason : '';
    setText(shareMsg, VISIBILITY_REASONS[reason] || errorMessage(result.data));
  }

  /** 공유한 사람 한 줄 — 이름·역할 · 등급 고르개(관리자만) · 「빼기」(관리자만). 물려받은 줄은 읽기만. */
  function shareRow(share, canManage) {
    const li = document.createElement('li');
    li.className = 'list__item';
    li.setAttribute('data-share-user', share.username);
    const head = document.createElement('div');
    head.className = 'list__head';
    const who = document.createElement('strong');
    who.textContent = personLabel(share.display_name, share.username) + ' · ' + roleText(share.role)
      + (share.is_active === false ? ' (쓰지 않는 계정)' : '');
    head.appendChild(who);
    const level = shareBadge(share.level);
    if (level) { head.appendChild(level); }
    li.appendChild(head);

    const body = document.createElement('div');
    body.className = 'list__body';
    if (share.inherited === true) {
      const from = document.createElement('span');
      from.className = 'muted';
      from.textContent = '상위 「' + String(share.from_title || '') + '」 에서 물려받음';
      body.appendChild(from);
    } else if (canManage) {
      const pick = document.createElement('select');
      pick.setAttribute('aria-label', personLabel(share.display_name, share.username) + ' 의 권한');
      pick.setAttribute('data-share-level', share.username);
      for (const value of ['view', 'edit']) {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = SHARE_LABELS[value];
        option.selected = value === share.level;
        pick.appendChild(option);
      }
      pick.addEventListener('change', () => {
        putShare(share.username, pick.value).catch(() => setText(shareMsg, GENERIC_ERROR));
      });
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'btn btn--quiet btn--small btn--danger';
      remove.setAttribute('data-share-remove', share.username);
      remove.textContent = '빼기';
      remove.addEventListener('click', () => {
        remove.disabled = true;
        removeShare(share.username).catch(() => { remove.disabled = false; setText(shareMsg, GENERIC_ERROR); });
      });
      body.appendChild(pick);
      body.appendChild(remove);
    }
    li.appendChild(body);
    return li;
  }

  function loadShares() {
    return api('GET', sharePath(null)).then((result) => {
      if (result.status === 401) { toLogin(); return; }
      if (result.status !== 200) { shareFail(result); return; }
      const canManage = result.data.can_manage === true;
      shareMyLevel = typeof result.data.my_level === 'string' ? result.data.my_level : null;
      shareMe = typeof result.data.me === 'string' ? result.data.me : '';
      clear(shareList);
      const shares = Array.isArray(result.data.shares) ? result.data.shares : null;
      if (shares !== null) {
        for (const share of shares) { shareList.appendChild(shareRow(share, canManage)); }
      }
      const n = typeof result.data.count === 'number' ? result.data.count : 0;
      setText(shareCount, n === 0 ? '아직 공유한 사람이 없습니다.' : '공유받은 사람 ' + String(n) + '명');
      show(shareAdd, canManage);
      // 5-A″ — 「아이디나 이름을 입력하세요」는 찾을 수 있는 사람(관리 자격)에게만, 아직 안 쳤을 때만.
      show(shareHint, canManage && (!shareSearch || shareSearch.value.trim() === ''));
      setText(shareTitle, canManage ? '사람 추가' : '공유');
      show(shareLeave, shareMyLevel !== null && shareMe !== '');
    });
  }

  function putShare(username, level) {
    setText(shareMsg, '');
    return api('PUT', sharePath(username), { level: level }).then((result) => {
      if (result.status === 401) { toLogin(); return null; }
      if (result.status !== 200) { shareFail(result); return null; }
      return loadShares();
    });
  }

  function removeShare(username) {
    setText(shareMsg, '');
    return api('DELETE', sharePath(username), {}).then((result) => {
      if (result.status === 401) { toLogin(); return null; }
      if (result.status !== 200) { shareFail(result); return null; }
      return loadShares();
    });
  }

  function candidateItem(person, onPick, label) {
    const li = document.createElement('li');
    li.className = 'list__item';
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn--quiet btn--small';
    button.setAttribute('data-candidate', person.username);
    button.textContent = personLabel(person.display_name, person.username) + ' · ' + roleText(person.role) + (label ? ' — ' + label : '');
    button.addEventListener('click', () => onPick(person, button));
    li.appendChild(button);
    return li;
  }

  function loadShareCandidates() {
    const q = shareSearch ? shareSearch.value.trim() : '';
    // 5-A″ — 한 글자도 없으면 찾지 않는다(창구도 빈 목록을 준다). 대신 무엇을 칠지 알려 준다.
    show(shareHint, q === '');
    if (q === '') { clear(shareCandidates); show(shareCandidates, false); setText(shareMsg, ''); return Promise.resolve(); }
    return api('GET', '/api/share-candidates?page=' + encodeURIComponent(pageId) + '&q=' + encodeURIComponent(q))
      .then((result) => {
        if (result.status === 401) { toLogin(); return; }
        if (result.status !== 200) { shareFail(result); return; }
        const people = Array.isArray(result.data.candidates) ? result.data.candidates : [];
        clear(shareCandidates);
        for (const person of people) {
          shareCandidates.appendChild(candidateItem(person, (who, button) => {
            button.disabled = true;
            putShare(who.username, shareLevelPick ? shareLevelPick.value : 'view')
              .then(() => {
                if (shareSearch) { shareSearch.value = ''; }
                clear(shareCandidates);
                show(shareCandidates, false);
              })
              .catch(() => { button.disabled = false; setText(shareMsg, GENERIC_ERROR); });
          }, '추가'));
        }
        show(shareCandidates, people.length > 0);
        if (people.length === 0) { setText(shareMsg, '그런 아이디나 이름이 없습니다'); } else { setText(shareMsg, ''); }
      });
  }

  if (shareSearch) {
    shareSearch.addEventListener('input', () => {
      window.clearTimeout(shareTimer);
      shareTimer = window.setTimeout(() => {
        loadShareCandidates().catch(() => setText(shareMsg, GENERIC_ERROR));
      }, 300);
    });
  }
  if (shareLeave) {
    shareLeave.addEventListener('click', () => {
      if (shareMe === '') { return; }
      shareLeave.disabled = true;
      api('DELETE', sharePath(shareMe), {})
        .then((result) => {
          shareLeave.disabled = false;
          if (result.status === 401) { toLogin(); return null; }
          if (result.status !== 200) { shareFail(result); return null; }
          // 뺀 그 순간부터 서버가 다시 판단한다 — 이 페이지를 계속 볼 수 있는지는 창구에 묻는다.
          visibilityDialog.close();
          return api('GET', '/api/pages/' + pageId).then((again) => {
            if (again.status === 200) { return load(); }
            window.location.assign(COMMUNITY_BASE + '/workspace');
            return null;
          });
        })
        .catch(() => { shareLeave.disabled = false; setText(shareMsg, GENERIC_ERROR); });
    });
  }

  /**
   * 공개 범위 창을 연다. `mode` 가 `share-only` 면(공유받은 사람) 범위 고르기를 숨기고 공유 부분만 보인다.
   */
  function openVisibility(mode) {
    if (!visibilityDialog || typeof visibilityDialog.showModal !== 'function') { return; }
    const shareOnly = mode === 'share-only';
    setText(visibilityMsg, '');
    setText(shareMsg, '');
    if (cascadeBox) { cascadeBox.checked = false; }
    for (const radio of visibilityRadios()) {
      radio.checked = radio.value === state.visibility;
    }
    show(choicesBox, !shareOnly);
    show(visibilitySave, !shareOnly);
    // 5-A″ — 공유 부분만 여는 창(공유받은 사람 · 팀 공간)은 제목도 「공유」다. 팀 공간에는 공개 범위가 없다.
    setText(visibilityTitle, shareOnly ? '공유' : '공개 범위');
    show(shareHint, true);
    if (shareSearch) { shareSearch.value = ''; }
    clear(shareCandidates);
    show(shareCandidates, false);
    if (!visibilityDialog.open) { visibilityDialog.showModal(); }
    loadShares().catch(() => setText(shareMsg, GENERIC_ERROR));
  }

  function pickedVisibility() {
    for (const radio of visibilityRadios()) {
      if (radio.checked) { return radio.value; }
    }
    return null;
  }

  if (visibilityButton && visibilityDialog) {
    visibilityButton.addEventListener('click', () => openVisibility('full'));
    if (shareOpenButton) { shareOpenButton.addEventListener('click', () => openVisibility('share-only')); }
    for (const close of visibilityDialog.querySelectorAll('[data-visibility-close]')) {
      close.addEventListener('click', () => visibilityDialog.close());
    }
    if (visibilitySave) {
      visibilitySave.addEventListener('click', () => {
        const want = pickedVisibility();
        if (want === null) { setText(visibilityMsg, '공개 범위를 고르세요'); return; }
        visibilitySave.disabled = true;
        api('PATCH', '/api/pages/' + pageId + '/visibility', {
          visibility: want,
          cascade: cascadeBox ? cascadeBox.checked === true : false
        })
          .then((result) => {
            visibilitySave.disabled = false;
            if (result.status === 401) { toLogin(); return; }
            if (result.status === 200) {
              visibilityDialog.close();
              // 배지·하위 목록이 함께 바뀐다 — 서버가 준 값으로 다시 그린다.
              return load();
            }
            const reason = result.data && typeof result.data.reason === 'string' ? result.data.reason : '';
            setText(visibilityMsg, VISIBILITY_REASONS[reason] || errorMessage(result.data));
          })
          .catch(() => { visibilitySave.disabled = false; setText(visibilityMsg, GENERIC_ERROR); });
      });
    }
  }

  /* ── 「소유자 넘기기」 창 (5-A 인계) ──
     받는 사람은 `GET /api/share-candidates?for=handover` 가 준 사람 중에서 고르고(손님·쓰지 않는
     계정은 창구가 빼고 준다), 「기존 소유자에게 남길 권한」 라디오 셋에는 **기본 선택이 없다**
     (사용자 결정 2). 둘 다 고르기 전에는 「넘기기」가 `disabled` 다 — 그래도 서버가 `keep` 없는
     요청을 400 으로 다시 막는다. 관리자가 남의 페이지를 넘길 때 재인증이 필요하면 창구가
     `next:"reauth"` 로 답하고, 화면은 그렇게 말할 뿐 스스로 판단하지 않는다. */
  const handoverDialog = document.getElementById('handover-dialog');
  const handoverLead = document.getElementById('handover-lead');
  const handoverMsg = document.getElementById('handover-msg');
  const handoverSearch = document.getElementById('handover-search');
  const handoverList = document.getElementById('handover-candidates');
  const handoverPicked = document.getElementById('handover-picked');
  const handoverConfirm = document.getElementById('handover-confirm');
  let handoverTo = '';
  let handoverToLabel = '';
  let handoverTimer = null;
  const handoverHint = document.getElementById('handover-hint');

  function handoverRadios() {
    return handoverDialog ? handoverDialog.querySelectorAll('input[name="handover-keep"]') : [];
  }

  function handoverKeep() {
    for (const radio of handoverRadios()) {
      if (radio.checked) { return radio.value; }
    }
    return null;
  }

  function handoverArm() {
    if (handoverConfirm) { handoverConfirm.disabled = handoverTo === '' || handoverKeep() === null; }
  }

  function loadHandoverCandidates() {
    const q = handoverSearch ? handoverSearch.value.trim() : '';
    // 5-A″ — 한 글자부터 찾는다. 비었으면 목록을 비우고 무엇을 칠지 알려 준다(창구도 빈 목록을 준다).
    show(handoverHint, q === '');
    if (q === '') { clear(handoverList); setText(handoverMsg, ''); return Promise.resolve(); }
    return api('GET', '/api/share-candidates?for=handover&page=' + encodeURIComponent(pageId)
      + '&q=' + encodeURIComponent(q))
      .then((result) => {
        if (result.status === 401) { toLogin(); return; }
        if (result.status !== 200) { setText(handoverMsg, errorMessage(result.data)); return; }
        const people = Array.isArray(result.data.candidates) ? result.data.candidates : [];
        clear(handoverList);
        for (const person of people) {
          handoverList.appendChild(candidateItem(person, (who) => {
            handoverTo = who.username;
            handoverToLabel = personLabel(who.display_name, who.username);
            setText(handoverPicked, '받는 사람: ' + handoverToLabel + ' · ' + roleText(who.role));
            for (const b of handoverList.querySelectorAll('[data-candidate]')) {
              b.setAttribute('aria-pressed', b.getAttribute('data-candidate') === who.username ? 'true' : 'false');
            }
            handoverArm();
          }, ''));
        }
        if (people.length === 0) { setText(handoverMsg, '그런 아이디나 이름으로 넘겨받을 수 있는 사람이 없습니다'); } else { setText(handoverMsg, ''); }
      });
  }

  function openHandover() {
    if (!handoverDialog || typeof handoverDialog.showModal !== 'function') { return; }
    handoverTo = '';
    handoverToLabel = '';
    clear(handoverList);
    show(handoverHint, true);
    setText(handoverMsg, '');
    setText(handoverPicked, '받는 사람을 아직 고르지 않았습니다.');
    // ★ 기본값을 두지 않는다 — 열 때마다 라디오를 모두 비운다(사용자 결정 2).
    for (const radio of handoverRadios()) { radio.checked = false; }
    if (handoverSearch) { handoverSearch.value = ''; }
    const n = typeof state.subtreeCount === 'number' ? state.subtreeCount : 0;
    setText(handoverLead, n > 0
      ? '이 페이지와 하위 페이지 ' + String(n) + '개(그중 절차서 ' + String(state.procedureCount || 0) + '건)가 받는 사람의 공간 맨 위로 옮겨 갑니다.'
      : '이 페이지가 받는 사람의 공간 맨 위로 옮겨 갑니다.');
    handoverArm();
    if (!handoverDialog.open) { handoverDialog.showModal(); }
  }

  if (handoverButton && handoverDialog) {
    handoverButton.addEventListener('click', openHandover);
    for (const close of handoverDialog.querySelectorAll('[data-handover-close]')) {
      close.addEventListener('click', () => handoverDialog.close());
    }
    for (const radio of handoverRadios()) { radio.addEventListener('change', handoverArm); }
    if (handoverSearch) {
      handoverSearch.addEventListener('input', () => {
        window.clearTimeout(handoverTimer);
        handoverTimer = window.setTimeout(() => {
          loadHandoverCandidates().catch(() => setText(handoverMsg, GENERIC_ERROR));
        }, 300);
      });
    }
    if (handoverConfirm) {
      handoverConfirm.addEventListener('click', () => {
        const keep = handoverKeep();
        if (handoverTo === '' || keep === null) { handoverArm(); return; }
        handoverConfirm.disabled = true;
        api('POST', '/api/pages/' + pageId + '/handover', { to: handoverTo, keep: keep })
          .then((result) => {
            if (result.status === 401) { toLogin(); return null; }
            if (result.status === 200) {
              handoverDialog.close();
              // 넘긴 뒤에도 볼 수 있는지는 서버가 다시 판단한다 — 못 보면 내 공간으로 돌아간다.
              return api('GET', '/api/pages/' + pageId).then((again) => {
                if (again.status === 200) {
                  return load().then(() => setText(msg, '넘겼습니다. 새 소유자: '
                    + personLabel(result.data.to_display_name, result.data.to)));
                }
                window.location.assign(COMMUNITY_BASE + '/workspace');
                return null;
              });
            }
            handoverArm();
            if (result.data && result.data.next === 'reauth') {
              setText(handoverMsg, '관리자 권한으로 넘기려면 비밀번호를 다시 확인해야 합니다. 관리자 페이지에서 확인한 뒤 다시 누르세요.');
              return null;
            }
            const reason = result.data && typeof result.data.reason === 'string' ? result.data.reason : '';
            setText(handoverMsg, VISIBILITY_REASONS[reason] || errorMessage(result.data));
            return null;
          })
          .catch(() => { handoverArm(); setText(handoverMsg, GENERIC_ERROR); });
      });
    }
  }

  /* ── 「이력」 창 (4-C, C:F-19 · C:F-20) ──
     탭 둘은 단추 둘이다 — 고른 쪽이 검정(`.btn`), 나머지가 흐림(`.btn--quiet`).
     · 「버전」  판마다 한 줄. 「보기」는 이 창 안에서 그 판을 **읽기 렌더러로** 그리고,
                「되돌리기」는 같은 창 안에서 한 번 더 묻는다(모달 안에 모달을 열지 않는다).
     · 「처리 이력」 판·체크를 시간순 한 줄씩. **본문 글자는 여기에 없다** — 창구가 주지
                않기 때문이고(C:T-44), 화면도 지어내지 않는다.
     되돌리기 단추는 창구가 준 `can_edit` 을 따른다. 되살려 눌러도 서버가 다시 막는다. */
  const historyDialog = document.getElementById('history-dialog');
  const historyMeta = document.getElementById('history-meta');
  const historyMsg = document.getElementById('history-msg');
  const historyTabVersions = document.getElementById('history-tab-versions');
  const historyTabEvents = document.getElementById('history-tab-events');
  const historyVersions = document.getElementById('history-versions');
  const historyVersionsBody = document.getElementById('history-versions-body');
  const historyVersionsEmpty = document.getElementById('history-versions-empty');
  const historyEvents = document.getElementById('history-events');
  const historyEventsList = document.getElementById('history-events-list');
  const historyEventsEmpty = document.getElementById('history-events-empty');
  const historyPreview = document.getElementById('history-preview');
  const historyPreviewTitle = document.getElementById('history-preview-title');
  const historyPreviewDoc = document.getElementById('history-preview-doc');
  const historyPreviewBack = document.getElementById('history-preview-back');
  const historyConfirm = document.getElementById('history-confirm');
  const historyConfirmText = document.getElementById('history-confirm-text');
  const historyConfirmOk = document.getElementById('history-confirm-ok');
  const historyConfirmCancel = document.getElementById('history-confirm-cancel');
  const historyButton = document.getElementById('page-history');

  let historyCanEdit = false;      // 창구가 준 값. 화면이 정하지 않는다
  let historyPending = null;       // 되돌리기 확인을 기다리는 판 번호

  /** 탭 하나를 고른다. 단추 두 개의 모양과 `aria-selected`, 그리고 보이는 칸이 함께 바뀐다. */
  function historyTab(which) {
    const onVersions = which === 'versions';
    if (historyTabVersions) {
      historyTabVersions.className = onVersions ? 'btn' : 'btn btn--quiet';
      historyTabVersions.setAttribute('aria-selected', onVersions ? 'true' : 'false');
    }
    if (historyTabEvents) {
      historyTabEvents.className = onVersions ? 'btn btn--quiet' : 'btn';
      historyTabEvents.setAttribute('aria-selected', onVersions ? 'false' : 'true');
    }
    historyClosePreview();
    show(historyVersions, onVersions);
    show(historyEvents, !onVersions);
  }

  /** 미리보기를 닫고 목록으로 돌아온다. */
  function historyClosePreview() {
    show(historyPreview, false);
    clear(historyPreviewDoc);
    setText(historyPreviewTitle, '');
  }

  function historyHideConfirm() {
    historyPending = null;
    show(historyConfirm, false);
    setText(historyConfirmText, '');
  }

  /** 판 한 줄. 마지막 칸이 「보기」·「되돌리기」다 (DESIGN 2.4 `td.actions`). */
  function historyRow(entry) {
    const tr = document.createElement('tr');

    const num = document.createElement('td');
    num.textContent = entry.is_current
      ? String(entry.version) + ' (지금)'
      : String(entry.version);
    tr.appendChild(num);

    const title = document.createElement('td');
    title.textContent = entry.title;
    tr.appendChild(title);

    const who = document.createElement('td');
    who.textContent = entry.by === '' ? '—' : personLabel(entry.by_display_name, entry.by);
    tr.appendChild(who);

    const when = document.createElement('td');
    setWhenParts(when, entry.at);   // 디버깅 묶음 U M2
    when.setAttribute('title', String(entry.at));
    tr.appendChild(when);

    const blocks = document.createElement('td');
    blocks.textContent = String(entry.block_count);
    tr.appendChild(blocks);

    const actions = document.createElement('td');
    actions.className = 'actions';

    const view = document.createElement('button');
    view.type = 'button';
    view.className = 'btn btn--small btn--quiet';
    view.textContent = '보기';
    view.addEventListener('click', () => historyShow(entry.version, entry.is_current === true));
    actions.appendChild(view);

    if (historyCanEdit && entry.is_current !== true) {
      const back = document.createElement('button');
      back.type = 'button';
      back.className = 'btn btn--small btn--quiet btn--danger';
      back.textContent = '되돌리기';
      back.addEventListener('click', () => historyAskRestore(entry.version));
      actions.appendChild(back);
    }
    tr.appendChild(actions);

    // 되돌리기로 만들어진 판이면 그 사실을 줄 아래에 한 줄로 적는다(본문 글자가 아니다).
    if (typeof entry.restored_from === 'number') {
      const note = document.createElement('span');
      note.className = 'muted';
      note.textContent = ' · 판 ' + String(entry.restored_from) + ' 에서 되돌림';
      num.appendChild(note);
    }
    return tr;
  }

  function historyLoadVersions() {
    return api('GET', '/api/pages/' + pageId + '/versions').then((result) => {
      if (result.status === 401) { toLogin(); return; }
      if (result.status !== 200) { setText(historyMsg, errorMessage(result.data)); return; }
      setText(historyMsg, '');
      historyCanEdit = meCanWrite && result.data.can_edit === true;   // 디버깅 묶음 U H3
      const list = Array.isArray(result.data.versions) ? result.data.versions : [];
      clear(historyVersionsBody);
      for (const entry of list) {
        const row=historyRow(entry);
        if(entry.suggestion){const cell=row.querySelector('td');const info=document.createElement('p');info.className='muted';info.textContent='제안: '+(entry.suggestion.author ? entry.suggestion.author.username : '익명')+' · 수락: '+(entry.suggestion.accepted_by ? entry.suggestion.accepted_by.username : '익명');cell.appendChild(info);}
        historyVersionsBody.appendChild(row);
      }
      // 지금 판 한 줄만 있으면 「지난 판이 없다」는 뜻이다.
      show(historyVersionsEmpty, list.length <= 1);
      setText(historyMeta, '판 ' + String(result.data.page.doc_version) + ' · 기록 ' + String(list.length) + '개');
    });
  }

  /** 줄 하나 — 「무엇을 · 누가 · 언제」. 본문 글자는 창구가 주지 않는다 (C:T-44). */
  function historyEventItem(event) {
    const li = document.createElement('li');
    li.className = 'list__item';

    const head = document.createElement('div');
    head.className = 'list__head';
    const what = document.createElement('strong');
    if (event.kind === 'property' && event.prop === 'publish') {
      // 5-C — 「게시: 자유게시판」·「게시 해제: 자유게시판」(값은 게시판 이름 — 창구가 준 글자 그대로)
      what.textContent = event.to ? '게시: ' + String(event.to) : '게시 해제: ' + String(event.from || '');
    } else if (event.kind === 'property' && event.prop === 'hide') {
      what.textContent = event.to === 'hidden' ? '게시판에서 숨김' : '숨김 풀림';
    } else if (event.kind === 'property' && event.prop === 'resolved') {
      what.textContent = event.to === 'resolved' ? '해결됨으로 표시' : '해결됨 표시를 뺌';
    } else if (event.kind === 'property') {
      // 4-D — 「상태: 할 일 → 진행」·「담당자: 없음 → qa_employee01」(담당자는 5-A 전의 옛 기록)
      what.textContent = ({assignee:'담당자',status:'상태',verified:'검증',kind:'문서 종류',due:'기한'}[event.prop] || event.prop) + ': '
        + propValueText(event.prop, event.from) + ' → ' + propValueText(event.prop, event.to);
    } else if (event.kind === 'handover') {
      // 5-A — 「소유자: qa_employee01 → qa_employee02」
      what.textContent = '소유자: ' + personLabel(event.from_display_name, event.from)
        + ' → ' + personLabel(event.to_display_name, event.to);
    } else if (event.kind === 'check') {
      what.textContent = event.checked === true ? '할 일을 체크했습니다' : '체크를 풀었습니다';
    } else if (typeof event.restored_from === 'number') {
      what.textContent = '판 ' + String(event.version) + ' — 판 ' + String(event.restored_from) + ' 에서 되돌림';
    } else {
      what.textContent = '판 ' + String(event.version) + ' 저장';
    }
    const when = document.createElement('span');
    when.className = 'muted';
    when.textContent = shortWhen(event.at);
    when.setAttribute('title', String(event.at));
    head.appendChild(what);
    head.appendChild(when);
    li.appendChild(head);

    const body = document.createElement('div');
    body.className = 'list__body';
    const who = document.createElement('span');
    who.textContent = (event.by === '' ? '—' : personLabel(event.by_display_name, event.by)) + ' 님';
    body.appendChild(who);
    if (event.kind === 'version') {
      const blocks = document.createElement('span');
      blocks.textContent = '블록 ' + String(event.block_count) + '개';
      body.appendChild(blocks);
      if (event.is_current === true) {
        const now = document.createElement('span');
        now.textContent = '지금 판';
        body.appendChild(now);
      }
    } else if (event.kind === 'handover') {
      const count = document.createElement('span');
      count.textContent = String(event.page_count) + '쪽';
      body.appendChild(count);
      const kept = document.createElement('span');
      kept.textContent = '옛 소유자에게 남긴 권한: ' + (SHARE_LABELS[event.kept] || '없음');
      body.appendChild(kept);
    } else if (event.kind === 'check') {
      // 어느 칸인가는 블록 id 앞 8자로 가리킨다 — 할 일에 적힌 글자는 싣지 않는다.
      const which = document.createElement('span');
      which.textContent = '칸 ' + String(event.block_id).slice(0, 8);
      body.appendChild(which);
    }
    li.appendChild(body);
    return li;
  }

  function historyLoadEvents() {
    return api('GET', '/api/pages/' + pageId + '/history').then((result) => {
      if (result.status === 401) { toLogin(); return; }
      if (result.status !== 200) { setText(historyMsg, errorMessage(result.data)); return; }
      setText(historyMsg, '');
      const list = Array.isArray(result.data.events) ? result.data.events : [];
      clear(historyEventsList);
      for (const event of list) { historyEventsList.appendChild(historyEventItem(event)); }
      const accepted=Array.isArray(result.data.suggestions)?result.data.suggestions:[];
      for(const entry of accepted){const li=document.createElement('li');li.className='list__item';li.textContent='판 '+entry.version+' · 제안: '+(entry.author ? entry.author.username : '익명')+' · 수락: '+(entry.accepted_by ? entry.accepted_by.username : '익명');historyEventsList.appendChild(li);}
      show(historyEventsEmpty, list.length === 0 && accepted.length===0);
    });
  }

  /** 그 판을 **이 창 안에서** 읽기 렌더러로 그린다. 본문은 이 창구로만 들어온다. */
  function historyShow(version, isCurrent) {
    historyHideConfirm();
    return api('GET', '/api/pages/' + pageId + '/versions/' + String(version))
      .then((result) => {
        if (result.status === 401) { toLogin(); return; }
        if (result.status !== 200) { setText(historyMsg, errorMessage(result.data)); return; }
        setText(historyMsg, '');
        const got = result.data.version;
        setText(historyPreviewTitle, '판 ' + String(got.version) + (isCurrent ? ' (지금)' : '') + ' · ' + got.title);
        clear(historyPreviewDoc);
        /* 하위 페이지 카드의 제목 지도는 **지금 문서의 것**이다 — 지난 판이 가리키던 페이지가
           그 뒤에 지워졌다면 지도에 없고, 렌더러가 「볼 수 없는 페이지」로 그린다.
           7-D4 — 글 안 멘션의 이름은 **그 판** 응답의 지도(`mention_labels` — 볼 수 있는 것만)로 그린다. */
        const verCtx = readCtx();
        verCtx.mention_labels = result.data.mention_labels && typeof result.data.mention_labels === 'object' ? result.data.mention_labels : {};
        historyPreviewDoc.appendChild(renderDoc(got.doc, verCtx));
        show(historyVersions, false);
        show(historyEvents, false);
        show(historyPreview, true);
      })
      .catch(() => setText(historyMsg, GENERIC_ERROR));
  }

  function historyAskRestore(version) {
    historyPending = version;
    setText(historyConfirmText, '판 ' + String(version) + ' 의 내용으로 되돌릴까요?');
    show(historyConfirm, true);
  }

  function historyDoRestore() {
    if (historyPending === null) { return; }
    const version = historyPending;
    historyConfirmOk.disabled = true;
    api('POST', '/api/pages/' + pageId + '/versions/' + String(version) + '/restore', {})
      .then((result) => {
        historyConfirmOk.disabled = false;
        if (result.status === 401) { toLogin(); return; }
        if (result.status !== 200) { setText(historyMsg, errorMessage(result.data)); return; }
        historyHideConfirm();
        historyClosePreview();
        /* 본문·판 번호가 바뀌었다 — 창구가 준 값으로 화면과 목록을 다시 그린다.
           알림은 **다시 그린 뒤에** 놓는다: 목록을 새로 받는 일이 알림 칸을 비우기 때문이다
           (그 전에 적어 두면 사람이 읽기도 전에 사라진다 — 브라우저 스모크 30번 실측). */
        return load()
          .then(() => historyLoadVersions())
          .then(() => historyLoadEvents())
          .then(() => setText(historyMsg, '판 ' + String(version) + ' 의 내용으로 되돌렸습니다'));
      })
      .catch(() => { historyConfirmOk.disabled = false; setText(historyMsg, GENERIC_ERROR); });
  }

  function openHistory() {
    if (!historyDialog || typeof historyDialog.showModal !== 'function') { return; }
    setText(historyMsg, '');
    historyHideConfirm();
    historyTab('versions');
    if (!historyDialog.open) { historyDialog.showModal(); }
    historyLoadVersions()
      .then(() => historyLoadEvents())
      .catch(() => setText(historyMsg, GENERIC_ERROR));
  }

  if (historyButton && historyDialog) {
    historyButton.addEventListener('click', openHistory);
    if (moreHistoryItem) { moreHistoryItem.addEventListener('click', openHistory); }   // UX-2 — 좁은 폭 편집 모드의 「더보기 → 이력」
    for (const close of historyDialog.querySelectorAll('[data-history-close]')) {
      close.addEventListener('click', () => historyDialog.close());
    }
    if (historyTabVersions) { historyTabVersions.addEventListener('click', () => historyTab('versions')); }
    if (historyTabEvents) { historyTabEvents.addEventListener('click', () => historyTab('events')); }
    if (historyPreviewBack) {
      historyPreviewBack.addEventListener('click', () => {
        historyClosePreview();
        show(historyVersions, historyTabVersions.getAttribute('aria-selected') === 'true');
        show(historyEvents, historyTabEvents.getAttribute('aria-selected') === 'true');
      });
    }
    if (historyConfirmCancel) { historyConfirmCancel.addEventListener('click', historyHideConfirm); }
    if (historyConfirmOk) { historyConfirmOk.addEventListener('click', historyDoRestore); }
  }

  function showEditors(editors) {
    const list = Array.isArray(editors) ? editors : [];
    if (list.length === 0) { show(editorsBadge, false); syncPageState(); return; }
    const names = list.map((e) => personLabel(e.display_name, e.username)).join(', ');
    setText(editorsBadge, names + ' 님도 보는 중');
    show(editorsBadge, true);
    syncPageState();
  }

  /**
   * 이 페이지 **아래**에 빈 페이지를 하나 만든다 (4-A).
   * 「블록 추가」 창의 「하위 페이지」와 「하위 페이지 만들기」 단추가 같이 쓴다.
   * 만들어진 제목은 곧 카드에 보여야 하므로 제목 지도에도 함께 넣는다.
   */
  function createChildPage() {
    return api('POST', '/api/pages', { parent_id: pageId }).then((result) => {
      if (result.status === 401) { toLogin(); return null; }
      if (result.status !== 201 || !result.data.page || typeof result.data.page.id !== 'string') {
        setText(msg, errorMessage(result.data));
        return null;
      }
      const made = { id: result.data.page.id, title: result.data.page.title || '제목 없는 페이지' };
      state.childTitles[made.id] = { title: made.title, icon: null };
      return made;
    });
  }

  /* 5-E — 고른 페이지·파일의 이름을 제목 지도에 넣어 두면 저장 뒤 읽기 화면이 곧바로 이름을 그린다.
     지도는 다음 `load()` 에서 서버 것으로 바뀐다(서버가 **보는 사람 권한으로** 다시 거른다). */
  /* Q:F-12 U3 — 창 둘(블록 추가 · 블록 고치기)은 화면에 한 벌 — 칸 편집기(mountPaneEditor)와 **같은 함수 · 같은 창**을 나눠 쓴다
     (`atomDialogFor` · `blockDialogFor` — 연 쪽의 편집기로 넣는다). `upload` 는 「이 문서에 올리기」 탭(U3 C — 고칠 수 있고 제안 중이 아닐 때만) */
  let atomDialog = null, blockDialog = null, pageUploads = null, commandAtomEdit = null;
  let editingPrepared = false;
  let pendingEditing = null;
  let pendingTitleDirty = false;
  let titleComposing = false;
  let titleCompositionEnd = null;
  function editAtom(type, attrs, pos) {
    if(proposal && !suggestionTypeAllowed(type)){setText(msg,'제안에서는 글 블록만 넣거나 바꿀 수 있습니다.');return;} if (atomDialog) { atomDialog.open(type, attrs, pos); } }
  function prepareEditing() {
    if (editingPrepared) return;
    atomDialog = atomDialogFor(() => view, {
    selfId: () => pageId,
    context: () => ({mention_labels:state.mentionLabels}),
    rememberLink: (id, entry) => { state.linkTitles[id] = entry; },
    rememberFile: (id, entry) => { state.fileTitles[id] = entry; },
    upload: () => (view !== null && state.canEdit && !proposal ? pageUploads : null)
  });
    commandAtomEdit = atomEditCommand(editAtom);

    blockDialog = blockDialogFor(() => view, { createChildPage: createChildPage, editAtom: editAtom, suggestionMode:()=>proposal!==null });
  /* U3 C — 이 문서에 바로 올리기(붙여넣기 · 끌어놓기 · 「이 문서에 올리기」 탭)의 한 벌. 칸 편집기와 같은 함수다 */
    pageUploads = createUploads({
    pageId: () => pageId,
    onError: (t) => setText(msg, t),
    remember: (id, entry) => { state.attachmentTitles[id] = entry; },
    onUploaded: () => pageWatch.kick()
  });
    drawStarterKeys();
    editingPrepared = true;
  }
  /* 카페 C4 (DESIGN 2.26 「검사 중 미리보기」 ②③) — 이 문서의 검사 중 첨부를 다시 묻는다. 끝나면 편집 중이면 그 그림만, 읽기 화면이면 다시 그린다 */
  const pageWatch = watchAttachments({
    pageId: () => pageId,
    titles: () => state.attachmentTitles,
    onChange: (ids) => { if (view !== null) { redrawAttachmentViews(ids); } else if (!state.editing) { drawRead(); } }
  });

  /* ── 편집 모드 ── */

  /* ── 빈 페이지 안내 · 기본 구성 (2026-09-23) ── */

  const starterEl = document.getElementById('editor-starter');
  const starterKeys = document.getElementById('editor-starter-keys');
  const starterApply = document.getElementById('editor-starter-apply');

  // 안내에 보일 블록 — 처음 쓰는 사람이 알아야 할 것만. 단축키 글자는 창과 같은 곳(BLOCK_TYPES)에서 온다.
  const STARTER_PICKS = [['paragraph'], ['heading', 1], ['heading', 2], ['bulleted'], ['numbered'], ['to_do'], ['toc']];
  function drawStarterKeys() {
  if (starterKeys) {
    for (const [type, level] of STARTER_PICKS) {
      const entry = BLOCK_TYPES.find((e) => e.type === type && (level === undefined || (e.attrs && e.attrs.level === level)));
      if (!entry) { continue; }
      const li = document.createElement('li');
      const name = document.createElement('strong');
      name.textContent = entry.label;
      const keys = document.createElement('span');
      keys.className = 'block-pick__keys';
      keys.textContent = mdLabel(entry.md) || '「블록 추가」에서';
      li.appendChild(name);
      li.appendChild(keys);
      starterKeys.appendChild(li);
    }
  }

  }

  function docIsEmpty() {
    if (!view) { return false; }
    const d = view.state.doc;
    return d.childCount === 1 && d.firstChild.type === schema.nodes.paragraph && d.firstChild.content.size === 0;
  }

  function drawStarter() { show(starterEl, docIsEmpty()); }

  /** 기본 구성 — 빈 문서일 때만 통째로 넣는다(쓴 글을 덮지 않는다). 되돌리기(Ctrl+Z)로 뺄 수 있다. */
  function applyStarter() {
    if (!view || !docIsEmpty()) { return; }
    const n = schema.nodes;
    const text = (t) => (t === '' ? null : schema.text(t));
    const blocks = [
      n.heading.create({ id: uuid4(), level: 1 }, text('제목')),
      n.toc.create({ id: uuid4() }),
      n.heading.create({ id: uuid4(), level: 2 }, text('개요')),
      n.paragraph.create({ id: uuid4() }, text('무엇에 관한 글인지 한두 줄로 적습니다.')),
      n.heading.create({ id: uuid4(), level: 2 }, text('할 일')),
      n.to_do.create({ id: uuid4(), checked: false }, text('첫 번째 할 일')),
      n.heading.create({ id: uuid4(), level: 2 }, text('메모')),
      n.paragraph.create({ id: uuid4() })
    ];
    const tr = view.state.tr.replaceWith(0, view.state.doc.content.size, blocks);
    // 커서는 맨 위 「제목」 끝 — 바로 고쳐 쓰게.
    tr.setSelection(TextSelection.create(tr.doc, 1 + '제목'.length));
    view.dispatch(tr.scrollIntoView());
    view.focus();
  }

  if (starterApply) { starterApply.addEventListener('click', applyStarter); }

  /** Ctrl+Alt+숫자 — 커서가 있는 블록을 그 종류로 바꾼다(`BLOCK_TYPES[].key`, 창의 「바꾸기」와 같다) */
  function blockKeymap() { return blockKeymapFor(() => blockDialog); }

  function startEditing() {
    if (pendingEditing) return pendingEditing.ready;
    if (state.editing || view !== null || (!state.canEdit && !proposal) || documentDeletionLocked || documentPageRemoved) return Promise.resolve(null);
    let loadingError = '';
    show(docEl, false);
    show(editorEl, true);
    const titleFirst = focusTitleOnce && titleInput && !proposal;
    if (titleFirst) {
      titleInput.value = titleForInput(state.title);
      show(titleField, true);
    }
    pendingEditing = createDeferredEditor(editorEl, {
      load: () => loadEditorRuntime().then(async runtime => {
        if (titleFirst && titleComposing) await new Promise(resolve => { titleCompositionEnd = resolve; });
        return () => {
        if (documentDeletionLocked || documentPageRemoved || (!state.canEdit && !proposal)) return null;
        ({ ATOM_TYPES, BLOCK_TYPES, Decoration, DecorationSet, EditorState, EditorView, Plugin, TextSelection, atomDialogFor, atomEditCommand, baseKeymap, blockDialogFor, blockHandles, blockKeymapFor, buildInputRules, chainCommands, clipboardDomParser, commandBackspace, commandCodeEnter, commandEnter, commandShiftEnter, createToolbar, createUploads, dropCursor, editorDomParser, fromDoc, gapCursor, history, imageViews, imageWidthKeys, keymap, liveToc, mdLabel, redo, schema, suggestionTypeAllowed, toDoc, todoToggle, undo, uniqueBlockIds, uuid4 } = runtime);
        prepareEditing();
        const title = titleFirst ? titleInput.value : null;
        startEditingNow(false);
        if (!view) return null;
        if (titleFirst) titleInput.value = title;
        if (pendingTitleDirty) { pendingTitleDirty = false; markDirty(); }
        return {
          focus: () => { if (view) view.focus(); },
          insertText: text => { if (view) view.pasteText(text); },
          insertFiles: files => { if (view && !proposal) pageUploads.insert(view, files, view.state.selection.from); },
          flush: flushSave,
          destroy() {}
        };
        };
      }),
      onError: text => { loadingError = text; setText(msg, text); }
    });
    const controller = pendingEditing;
    controller.ready.then(editor => {
      if (pendingEditing !== controller) return;
      pendingEditing = null;
      if (editor && loadingError && msg && msg.textContent === loadingError) setText(msg, '');
    });
    if (titleFirst) titleInput.focus(); else controller.focus();
    return controller.ready;
  }

  function startEditingNow(focus = true) {
    // ★ 편집기는 **한 번만** 만든다 (지시서 4장) — 두 벌이 겹치면 친 글자가 사라진다.
    if (state.editing || view !== null || (!state.canEdit && !proposal)) { return; }

    let pmDoc;
    try {
      // 하위 페이지 카드에 제목을 보여 주려고 지도를 함께 넘긴다 (4-A).
      pmDoc = fromDoc(state.doc, { child_titles: state.childTitles, link_titles: state.linkTitles, file_titles: state.fileTitles, attachment_titles: state.attachmentTitles, mention_labels: state.mentionLabels });
      if(proposal)proposal.original=toDoc(pmDoc); // Compare the editor's canonical round trip; untouched atoms remain untouched.
    } catch (e) {
      setText(msg, e && e.message ? e.message : GENERIC_ERROR);
      return;
    }

    const editorState = EditorState.create({
      schema: schema,
      doc: schema.nodeFromJSON(pmDoc),
      plugins: [
        buildInputRules(),
        blockKeymap(),
        // ★ 우리 명령을 `baseKeymap` **앞에** 둔다 (지시서 3장).
        //   Ctrl+B·I·U 는 등록하지 않는다 — 문자 꾸미기를 만들지 않기로 했다.
        keymap({
          'Mod-z': undo,
          'Mod-y': redo,
          'Shift-Mod-z': redo,
          Enter: chainCommands(commandAtomEdit, commandCodeEnter, commandEnter),
          'Shift-Enter': commandShiftEnter,
          Backspace: commandBackspace,
          // 3단계에는 들여쓰기가 없다. 탭으로 초점이 새어 나가지 않게 여기서 멈춘다.
          Tab: () => true,
          'Shift-Tab': () => true
        }),
        keymap(baseKeymap),
        history(),
        dropCursor(),
        gapCursor(),
        uniqueBlockIds,
        blockHandles,
        ...(proposal ? [proposalPlugin()] : [todoToggle]),
        liveToc,
        // U4-3 — 편집 중에도 그림 자체 · 크기 손잡이 · Alt+←/→(제안 중에는 손잡이 · 단축키 0)
        imageViews({ ctx: readCtx, resizable: () => state.canEdit && !proposal }),
        ...(proposal ? [] : [imageWidthKeys()]),
        // U3 C — 붙여넣기 · 끌어놓기로 이 문서에 바로 올리기(고칠 수 있고 제안 중이 아닐 때만 — 막는 것은 서버)
        ...(state.canEdit && !proposal ? [pageUploads.plugin()] : [])
      ]
    });

    view = new EditorView(editorEl, {
      state: editorState,
      attributes: { class: 'doc editor' },
      // 7-D4 — 자기 DOM 을 다시 읽을 때만 멘션을 되살린다 · 붙여넣기·끌어 놓기는 스키마 그대로(멘션 0) — editor-schema.js 머리글
      domParser: editorDomParser,
      clipboardParser: clipboardDomParser,
      handleDoubleClickOn(v, p, node, nodePos, event, direct) {
        if (!direct || ATOM_TYPES.indexOf(node.type.name) === -1) { return false; }
        editAtom(node.type.name, node.attrs, nodePos);
        return true;
      },
      dispatchTransaction(tr) {
        if (documentDeletionLocked && tr.docChanged) return;
        const next = view.state.apply(tr);
        view.updateState(next);
        if (toolbar) { toolbar.refresh(); }
        if (tr.docChanged) { markDirty(); drawStarter(); }
      }
    });

    toolbar = createToolbar(view, {
      pageId: pageId,
      // 7-D4 — 새로 넣은 멘션의 라벨을 이름 지도에 넣어 두면 저장 뒤 읽기 화면이 곧바로 그린다(다음 `load()` 에서 서버 것으로 바뀐다)
      rememberMention: (key, entry) => { state.mentionLabels[key] = entry; }
    });
    drawStarter();

    show(docEl, false);
    show(editorEl, true);
    show(titleField, !proposal);
    // UX-1 — 서버 기본 제목이면 빈 칸 + 자리표시 「제목」(비워 두면 저장은 지금 제목 그대로 — save 의 titleNow 규칙)
    if (titleInput) { titleInput.value = titleForInput(state.title); }
    show(editButton, false);
    show(doneButton, true);
    show(bodyHead, true);   // W1c — 본문 머리 줄 오른쪽 「편집 마침」
    show(addBlockButton, true);
    state.editing = true;
    // UX-2 — 좁은 폭 편집 머리(CSS 가 이 표식으로 큰 제목·경로·이름표를 가리고 「사용 방법」·「이력」을 「더보기」로 옮긴다 — 넓은 폭은 그대로)
    body.setAttribute('data-page-editing', '');
    show(moreHelpItem, true);
    show(moreHistoryItem, !!document.getElementById('page-history') && !document.getElementById('page-history').hidden);
    serial = 0;
    savedSerial = 0;
    sentSerial = -1;
    badge(proposal ? '제안 중 — 원문은 바뀌지 않습니다' : '저장됨', proposal ? 'badge--internal' : 'badge--ok');
    doneButton.textContent=proposal?'제안 보내기':'편집 마침';
    show(proposalCancel,!!proposal);show(proposalButton,false);show(proposalsButton,false);
    if (focusTitleOnce && titleInput) {
      focusTitleOnce = false;
      if (focus) titleInput.focus();
    } else if (focus) {
      view.focus();
    }
    startHeartbeat();
    // B1-2 — 편집 모드에 들어갈 때 임시저장 열쇠를 받는다(한 화면에 한 번). 못 받으면(503·막힌 브라우저) 쓰지 않고 배지로 알린다.
    if (drafts && !proposal) {
      if (!drafts.available()) {
        draftOff(true);
      } else {
        drafts.ready().then((ok) => { if (!ok && state.editing) { draftOff(true); } });
      }
    }
  }

  /* ── 임시저장 되살리기 (B1-2 설계 5) ──
     페이지를 열 때 **이 사람·이 페이지** 항목이 있으면 열쇠를 받아(한 번) 풀어 보고, 풀리는 것만 알림 줄로 묻는다.
     불러오기 → 본문은 편집 모드에 넣고 **평소 저장 경로**로 보낸다(보낼 판 번호는 임시저장 때의 `base_version` —
     그사이 서버 판이 바뀌었으면 기존 409 충돌 창이 뜬다, 새 병합 코드 0). 되살린 본문은 `fromDoc`(스키마 통로)을 지나고
     서버 `Doc::validate` 가 다시 본다. 댓글은 쓰기 칸에 **넣기만** 한다(보내지 않는다). 자동 실행 0 — 사람이 누를 때만. */
  let draftNotice = null;

  async function applyPageDraft(data) {
    if (!state.editing) { await startEditing(); }
    if (!view) return false;
    let node;
    try {
      const pmDoc = fromDoc(data.doc, { child_titles: state.childTitles, link_titles: state.linkTitles, file_titles: state.fileTitles, attachment_titles: state.attachmentTitles, mention_labels: state.mentionLabels });
      node = schema.nodeFromJSON(pmDoc);
    } catch (e) {
      return false;
    }
    if (!state.editing) { startEditing(); }
    if (view === null) { return false; }
    if (titleInput) { titleInput.value = titleForInput(typeof data.title === 'string' && data.title.trim() !== '' ? data.title : state.title); }
    state.version = data.base_version;
    // 문서 전체를 사본으로 바꾸는 편집 한 번 → dispatchTransaction 이 markDirty → 평소 저장. Ctrl+Z 로 서버 판으로 되돌릴 수 있다.
    view.dispatch(view.state.tr.replaceWith(0, view.state.doc.content.size, node.content).scrollIntoView());
    return true;
  }

  function applyCommentDraft(c) {
    return commentsLoaded.then(() => {
      if (!commentInput || !state.canComment) { return; }
      replyTo = null;
      if (c.target !== 'new') {
        const li = document.getElementById('comment-' + c.target);
        const who = li ? li.querySelector('.list__head strong') : null;
        if (li && !li.classList.contains('is-reply')) { replyTo = { id: c.target, who: who ? who.textContent : '' }; }
      }
      commentInput.value = c.body;
      if (commentMention) { commentMention.refresh(); }
      commentKeyNow = c.key;
      drawComposer();   // 대상 댓글이 사라졌으면 새 댓글 열쇠로 옮겨 쓴다(drawComposer 안)
    });
  }

  function drawDraftNotice(pageDraft, commentDraft) {
    if (!msg || !msg.parentNode) { return; }
    if (draftNotice !== null) { draftNotice.remove(); }
    const box = document.createElement('section');
    box.className = 'notice';
    box.setAttribute('role', 'status');
    box.setAttribute('data-draft-notice', '');
    const newest = Math.max(pageDraft ? pageDraft.savedAt : 0, commentDraft ? commentDraft.savedAt : 0);
    const what = [];
    if (pageDraft) { what.push('본문'); }
    if (commentDraft) { what.push(commentDraft.target === 'new' ? '댓글' : '답글'); }
    const title = document.createElement('p');
    title.className = 'notice__title';
    title.textContent = '저장 안 된 내용이 있습니다 (' + localWhen(newest) + ' · ' + what.join(' · ') + ')';
    box.appendChild(title);
    const note = document.createElement('p');
    note.textContent = '로그인이 끊기기 전에 쓰던 것을 이 브라우저에 암호화해 남겨 두었습니다. 불러오면 본문은 편집 모드로 넣어 저장하고, 댓글은 쓰기 칸에 넣기만 합니다.';
    box.appendChild(note);
    const actions = document.createElement('div');
    actions.className = 'toolbar notice__actions';
    const loadButton = document.createElement('button');
    loadButton.type = 'button';
    loadButton.className = 'btn btn--quiet btn--small';
    loadButton.setAttribute('data-draft-load', '');
    loadButton.textContent = '불러오기';
    const dropButton = document.createElement('button');
    dropButton.type = 'button';
    dropButton.className = 'btn btn--quiet btn--small';
    dropButton.setAttribute('data-draft-drop', '');
    dropButton.textContent = '버리기';
    actions.appendChild(loadButton);
    actions.appendChild(dropButton);
    box.appendChild(actions);

    const close = () => { box.remove(); if (draftNotice === box) { draftNotice = null; } };
    loadButton.addEventListener('click', async () => {
      loadButton.disabled = true;
      dropButton.disabled = true;
      if (pageDraft && !await applyPageDraft(pageDraft.data)) {
        drafts.remove(pageDraft.key);
        setText(msg, '임시저장된 본문을 불러오지 못해 버렸습니다(모양이 맞지 않습니다).');
      }
      (commentDraft ? applyCommentDraft(commentDraft) : Promise.resolve()).then(close, close);
    });
    dropButton.addEventListener('click', () => {
      // 서버 요청 0 — 이 브라우저의 사본만 지운다. 그사이 새로 쓰기 시작한 것(지금 지키는 중인 사본)은 건드리지 않는다.
      if (pageDraft && !(state.editing && serial !== savedSerial)) { drafts.remove(pageDraft.key); }
      if (commentDraft && !(commentInput && commentInput.value.trim() !== '' && commentKeyNow === commentDraft.key)) {
        drafts.remove(commentDraft.key);
      }
      close();
    });
    msg.parentNode.insertBefore(box, msg.nextSibling);
    draftNotice = box;
  }

  function offerDrafts() {
    if (!drafts || !drafts.available()) { return; }
    const here = drafts.entries().filter((e) => e.pageId === pageId);
    if (here.length === 0) { return; }
    drafts.ready().then((ok) => {
      if (!ok) { return null; }
      const pageEntry = here.find((e) => e.kind === 'page') || null;
      const commentEntry = here.filter((e) => e.kind === 'comment').sort((a, b) => b.savedAt - a.savedAt)[0] || null;
      return Promise.all([
        pageEntry ? drafts.read(pageEntry.key) : null,
        commentEntry ? drafts.read(commentEntry.key) : null
      ]).then((got) => {
        const p = got[0];
        const c = got[1];
        let pageDraft = null;
        let commentDraft = null;
        if (p) {
          if (typeof p.data.base_version === 'number' && p.data.doc !== null && typeof p.data.doc === 'object') {
            pageDraft = { key: pageEntry.key, data: p.data, savedAt: p.savedAt };
          } else {
            drafts.remove(pageEntry.key);   // 풀렸지만 모양이 아니다 — 되살릴 수 없는 사본은 남기지 않는다
          }
        }
        if (c) {
          if (typeof c.data.body === 'string' && c.data.body.trim() !== '') {
            commentDraft = { key: commentEntry.key, target: commentEntry.target, body: c.data.body, savedAt: c.savedAt };
          } else {
            drafts.remove(commentEntry.key);
          }
        }
        // 고칠 수 없게 된 페이지의 본문·댓글을 쓸 수 없게 된 페이지의 댓글은 묻지 않는다(사본은 7일 뒤 정리된다).
        if (pageDraft && !state.canEdit) { pageDraft = null; }
        if (commentDraft && !state.canComment) { commentDraft = null; }
        if (pageDraft || commentDraft) { drawDraftNotice(pageDraft, commentDraft); }
      });
    }).catch(() => { /* 임시저장은 편의 — 페이지를 막지 않는다 */ });
  }

  function stopEditing() {
    if (pendingEditing) return pendingEditing.ready.then(() => stopEditing());
    return flushSave().then(() => {
      if (blockDialog) { blockDialog.close(); }
      if (atomDialog) { atomDialog.close(); }
      if (toolbar) { toolbar.hide(); toolbar = null; }
      show(starterEl, false);
      if (view) { view.destroy(); view = null; }
      clear(editorEl);
      stopHeartbeat();
      state.editing = false;proposal=null;serial=savedSerial;badge('','');
      show(proposalCancel,false);show(proposalButton,state.canSuggest);
      show(proposalsButton,state.canEdit && Number(state.suggestionsOpen)>0);

      body.removeAttribute('data-page-editing');
      show(moreHelpItem, false);
      show(moreHistoryItem, false);
      show(editorEl, false);
      show(titleField, false);
      show(docEl, true);
      show(doneButton, false);
      show(bodyHead, false);
      show(addBlockButton, false);
      show(editButton, state.canEdit);
      drawRead();
      setText(titleEl, state.title);
    });
  }

  /* ── 자동 저장 (1.5초 멈춤 · 늦어도 10초) ── */

  let firstDirtyAt = 0;

  function markDirty() {
    serial += 1;
    if(proposal){badge('제안 중 — 원문은 바뀌지 않습니다','badge--internal');return;}
    badge('저장 중…', '');
    const now = Date.now();
    if (firstDirtyAt === 0) { firstDirtyAt = now; }
    window.clearTimeout(saveTimer);
    const wait = now - firstDirtyAt >= 10000 ? 0 : 1500;
    saveTimer = window.setTimeout(() => { save(); }, wait);
    scheduleDraft();   // B1-2 — 1초 묶음으로 이 브라우저에 암호화 사본
  }

  function currentDoc() {
    return view ? toDoc(view.state.doc.toJSON()) : state.doc;
  }

  /**
   * 남은 것을 지금 보낸다. 보내는 중이었다면 **그 뒤에 이어지는 저장까지** 기다린다.
   * @param {boolean} [closing] 창을 닫거나 탭이 숨는 중 — 요청에 `keepalive` 를 단다(B1-2 설계 7, 닫히면서 취소되지 않게)
   */
  function flushSave(closing) {
    if (pendingEditing && !state.editing) return pendingEditing.ready.then(() => flushSave(closing));
    if(proposal)return Promise.resolve();
    window.clearTimeout(saveTimer);
    if (saving) { return inFlight || Promise.resolve(); }
    if (serial === savedSerial) { return Promise.resolve(); }
    return Promise.resolve(save(closing === true));
  }

  /**
   * 저장 한 번.
   *
   * ★ 보내는 중에 또 고쳐지면 **그 변경을 잃지 않는다**(관문 ③ 실측 2026-09-22·09-23).
   *   전에는 요청이 날아가 있는 1초 남짓 사이에 친 글자가 조용히 사라졌고, 배지는 앞선 요청이
   *   끝나며 「저장됨」으로 바뀌어 **사용자는 저장된 줄 알았다**. 이제
   *     ① 보낼 때의 변경 번호(`sentSerial`)를 기억하고
   *     ② 200 이 와도 `serial` 이 그보다 크면 「저장됨」을 **보이지 않고** 곧바로 한 번 더 보낸다.
   *   409(충돌)일 때는 이어서 보내지 않는다 — 무엇을 할지는 사람이 모달에서 고른다.
   */
  function save(closing) {
    if(proposal)return Promise.resolve();
    if (saving) { return inFlight || Promise.resolve(); }
    saving = true;

    const mySerial = serial;
    sentSerial = mySerial;
    const doc = currentDoc();
    const title = titleInput && titleInput.value.trim() !== '' ? titleInput.value.trim() : state.title;

    inFlight = api('PATCH', '/api/pages/' + pageId, {
      doc_version: state.version,
      doc: doc,
      title: title
    }, { keepalive: closing === true })
      .then((result) => {
        saving = false;
        // B1-2 — 로그인이 끊겼다: `toLogin` 이 떠나기 **전에** 이 판의 사본을 암호화해 남긴다(leaveHooks).
        if (result.status === 401) { toLogin(); return; }
        if (result.status === 200) {
          state.version = result.data.doc_version;
          state.title = result.data.title;
          state.doc = doc;
          state.buttons = result.data.buttons || {};
      state.dbViews = result.data.db_views || {};
          savedSerial = mySerial;
          setText(titleEl, state.title);
          // 사이드바 트리의 지금 페이지 줄도 새 제목으로 (5-A′) — 다시 불러오지 않는다
          const navHere = document.querySelector('.nav-tree__row.is-active .nav-tree__link');
          if (navHere) { navHere.textContent = state.title; }
          if (serial !== savedSerial) {
            // 보내는 사이에 또 고쳐졌다 — 아직 「저장됨」이 아니다.
            badge('저장 중…', '');
            return save();
          }
          firstDirtyAt = 0;
          badge('저장됨', 'badge--ok');
          clearPageDraft();   // B1-2 설계 4 — 이 판이 서버에 있다. 사본은 필요 없다
          pageWatch.kick(true);   // 카페 C4 — 저장이 끝날 때 검사 중 첨부를 한 번 더 묻는다
          return;
        }
        if (result.status === 409) {
          badge('저장 못 함', 'badge--admin-only');
          pendingConflict = { doc: doc, title: title, server: result.data };
          openConflict();
          return;
        }
        badge('저장 못 함', 'badge--admin-only');
        setText(msg, errorMessage(result.data));
        if (Array.isArray(result.data.errors) && result.data.errors.length > 0) {
          setText(msg, errorMessage(result.data) + ' — ' + result.data.errors[0].path + ' (' + result.data.errors[0].reason + ')');
        }
      })
      .catch(() => {
        saving = false;
        badge('저장 못 함', 'badge--admin-only');
        setText(msg, GENERIC_ERROR);
      });
    return inFlight;
  }

  /* ── 409 안내 (ARCH 14.3) ── */

  function openConflict() {
    if (!conflictDialog || typeof conflictDialog.showModal !== 'function') {
      setText(msg, '다른 곳에서 먼저 저장되었습니다. 새로 고침하세요.');
      return;
    }
    if (!conflictDialog.open) { conflictDialog.showModal(); }
  }

  if (conflictDialog) {
    // 디버깅 묶음 U2 — 머리 ✕ 와 바닥 「닫기」 둘 다(전엔 querySelector 라 첫 것만 이어졌다)
    for (const closeButton of conflictDialog.querySelectorAll('[data-conflict-close]')) {
      closeButton.addEventListener('click', () => conflictDialog.close());
    }
    const reload = document.getElementById('conflict-reload');
    if (reload) {
      reload.addEventListener('click', () => {
        conflictDialog.close();
        // B1-2 — 「새로 고침」은 이 창에서 쓴 내용을 버리는 선택이다(창의 안내문). 임시저장 사본도 함께 버린다.
        clearPageDraft();
        shared.leavingOnPurpose = true;
        window.location.reload();
      });
    }
    const overwrite = document.getElementById('conflict-overwrite');
    if (overwrite) {
      overwrite.addEventListener('click', () => {
        conflictDialog.close();
        if (!pendingConflict) { return; }
        // 서버가 준 **지금의 판 번호**로 다시 보낸다 — 내 것이 새 판이 된다.
        state.version = typeof pendingConflict.server.current_version === 'number'
          ? pendingConflict.server.current_version
          : state.version;
        pendingConflict = null;
        save();
      });
    }
  }

  /* ── 편집 중 표시 (30초) ── */

  /* P-4a — 편집 중 heartbeat 도 탭이 보일 때만 돈다(숨으면 멈추고, 다시 보이면 곧바로 한 번).
     숨어 있는 동안 서버의 「편집 중」 표시는 기존 만료 규칙(마지막 신호 뒤 2분, pages_heartbeat.php)대로
     다른 사람 화면에서 사라질 수 있다 — 받아들인 동작이다(지시서 P-4a ④). */
  function startHeartbeat() {
    if(proposal)return;
    if (heartbeat === null) {
      // U-3 — heartbeat 도 자동 요청이다(표식). 편집 모드로 켜 두기만 한 탭은 30분 뒤 로그아웃되고, 쓰던 글은 임시저장이 지킨다.
      heartbeat = whileVisible(() => {
        api('POST', '/api/pages/' + pageId + '/heartbeat', {}, { background: true })
          .then((result) => { if (result.status === 200) { showEditors(result.data.editors); } })
          .catch(() => {});
      }, 30000);
    }
    heartbeat.start();
  }

  function stopHeartbeat() {
    if (heartbeat !== null) { heartbeat.stop(); }
  }

  /* ── 단추 ── */

  if (editButton) { editButton.addEventListener('click', startEditing); }
  if (doneButton) { doneButton.addEventListener('click',()=>{
    if(!proposal){stopEditing();return;}
    suggestions.send(proposal.original,currentDoc(),proposal.version,()=>stopEditing().then(()=>{setText(msg,'제안을 보냈습니다.');return load();})).catch(e=>setText(msg,e.message));
  }); }
  if (addBlockButton) {
    addBlockButton.addEventListener('click', () => { if (blockDialog) blockDialog.open('insert', null); });
  }
  if (titleInput) {
    titleInput.addEventListener('compositionstart', () => { titleComposing = true; });
    titleInput.addEventListener('compositionend', () => { titleComposing = false; if (titleCompositionEnd) { const done = titleCompositionEnd; titleCompositionEnd = null; done(); } });
    titleInput.addEventListener('input', () => { if (pendingEditing && !state.editing) { pendingTitleDirty = true; return; } markDirty(); });
    /* 사용감 라운드 1 UX-1 — 제목 칸에서 Enter 면 본문 첫 줄로(커서는 첫 블록 맨 앞). 한글 조합 중의 Enter(조합 끝내기)는 건드리지 않는다. */
    titleInput.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' || event.isComposing || event.keyCode === 229 || event.shiftKey || event.altKey || event.ctrlKey || event.metaKey) { return; }
      if (view === null) { return; }
      event.preventDefault();
      view.focus();
      view.dispatch(view.state.tr.setSelection(TextSelection.atStart(view.state.doc)).scrollIntoView());
    });
  }

  /* ── 「하위 페이지 만들기」 (4-A) ──
     본문은 건드리지 않고 트리에만 한 장 더한다. 만든 뒤 목록을 다시 받아 **결과를 보인다** —
     화면이 스스로 카드를 지어내지 않는다(서버가 준 목록만 그린다). */
  if (newChildButton) {
    newChildButton.addEventListener('click', () => {
      newChildButton.disabled = true;
      createChildPage()
        .then((made) => {
          newChildButton.disabled = false;
          if (!made) { return; }
          return load();
        })
        .catch(() => { newChildButton.disabled = false; setText(msg, GENERIC_ERROR); });
    });
  }

  /* ── 「하위 데이터베이스 만들기」 (6-A) — 만든 뒤 그 DB 페이지로 간다(표·속성은 거기서) ── */
  if (newDbButton) {
    newDbButton.addEventListener('click', () => {
      newDbButton.disabled = true;
      api('POST', '/api/pages', { parent_id: pageId, database: true })
        .then((result) => {
          newDbButton.disabled = false;
          if (result.status === 401) { toLogin(); return; }
          if (result.status !== 201 || !result.data.page) { setText(msg, errorMessage(result.data)); return; }
          window.location.assign(COMMUNITY_BASE + '/p/' + result.data.page.id);
        })
        .catch(() => { newDbButton.disabled = false; setText(msg, GENERIC_ERROR); });
    });
  }

  /* ── 「옮기기」 창 (4-A) ──
     트리를 한 번(`?tree=1`)에 받아 고를 수 있는 부모만 그린다. 자기 자신과 자기 하위는
     목록에서 빼지만, **막는 것은 서버**다(`decidePageMove` — cycle·depth_exceeded).
     화면의 목록은 편의일 뿐 판정이 아니다 (DESIGN 원칙 1). */
  const moveDialog = document.getElementById('move-dialog');
  const moveTreeBox = document.getElementById('move-tree');
  const moveMsg = document.getElementById('move-msg');
  const moveConfirm = document.getElementById('move-confirm');
  let movePick = null;          // 고른 새 부모의 id (null = 맨 위)
  let movePicked = false;       // 하나라도 골랐나

  function movePickButton(label, id, depth) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'move-tree__pick';
    button.setAttribute('role', 'option');
    button.setAttribute('aria-selected', 'false');
    button.setAttribute('data-parent-id', id === null ? '' : id);
    button.setAttribute('data-depth', String(depth));
    button.textContent = label;
    button.addEventListener('click', () => {
      movePick = id;
      movePicked = true;
      for (const other of moveTreeBox.querySelectorAll('.move-tree__pick')) {
        const on = other === button;
        other.className = 'move-tree__pick' + (on ? ' is-selected' : '');
        other.setAttribute('aria-selected', on ? 'true' : 'false');
      }
    });
    return button;
  }

  function moveLevel(byParent, key, depth, skip) {
    const box = document.createDocumentFragment();
    if (depth > 12) { return box; }
    for (const node of byParent.get(key) || []) {
      if (node.id === skip) { continue; }   // 자기 자신과 그 아래는 부모가 될 수 없다
      const wrap = document.createElement('div');
      wrap.className = 'move-tree__node';
      wrap.appendChild(movePickButton((node.icon ? node.icon + ' ' : '') + node.title, node.id, depth));
      const kids = moveLevel(byParent, node.id, depth + 1, skip);
      if (kids.childNodes.length > 0) {
        const kidBox = document.createElement('div');
        kidBox.className = 'move-tree__kids';
        kidBox.appendChild(kids);
        wrap.appendChild(kidBox);
      }
      box.appendChild(wrap);
    }
    return box;
  }

  function openMove() {
    if (!moveDialog || typeof moveDialog.showModal !== 'function') { return; }
    movePick = null;
    movePicked = false;
    setText(moveMsg, '');
    clear(moveTreeBox);
    // 4-B — 이 페이지가 **든 공간**의 트리를 받는다(팀 공간에서도 같은 창을 쓴다).
    api('GET', '/api/pages?tree=1' + (state.space ? '&space=' + encodeURIComponent(String(state.space.id)) : ''))
      .then((result) => {
        if (result.status === 401) { toLogin(); return; }
        if (result.status !== 200) { setText(moveMsg, errorMessage(result.data)); return; }
        const pages = Array.isArray(result.data.pages) ? result.data.pages : [];
        const byParent = new Map();
        for (const page of pages) {
          const k = typeof page.parent_id === 'string' ? page.parent_id : '';
          if (!byParent.has(k)) { byParent.set(k, []); }
          byParent.get(k).push(page);
        }
        moveTreeBox.appendChild(movePickButton('맨 위 (상위 없음)', null, 0));
        moveTreeBox.appendChild(moveLevel(byParent, '', 0, pageId));
      })
      .catch(() => setText(moveMsg, GENERIC_ERROR));
    if (!moveDialog.open) { moveDialog.showModal(); }
  }

  if (moveButton && moveDialog) {
    moveButton.addEventListener('click', openMove);
    for (const close of moveDialog.querySelectorAll('[data-move-close]')) {
      close.addEventListener('click', () => moveDialog.close());
    }
    if (moveConfirm) {
      moveConfirm.addEventListener('click', () => {
        if (!movePicked) { setText(moveMsg, '새 상위 페이지를 고르세요'); return; }
        moveConfirm.disabled = true;
        api('PATCH', '/api/pages/' + pageId + '/move', { parent_id: movePick })
          .then((result) => {
            moveConfirm.disabled = false;
            if (result.status === 401) { toLogin(); return; }
            if (result.status === 200) {
              moveDialog.close();
              // 경로·하위 목록이 함께 바뀐다 — 서버가 준 값으로 다시 그린다.
              return load();
            }
            setText(moveMsg, errorMessage(result.data));
          })
          .catch(() => { moveConfirm.disabled = false; setText(moveMsg, GENERIC_ERROR); });
      });
    }
  }

  // 블록 손잡이(⋮⋮) — 자리는 클릭할 때 다시 찾는다(장식은 문서가 바뀔 때마다 새로 그려진다).
  if (editorEl) {
    editorEl.addEventListener('mousedown', (event) => {
      const target = event.target;
      const handle = target && target.closest ? target.closest('.block-handle') : null;
      if (!handle || view === null) { return; }
      event.preventDefault();
      let pos = null;
      try { pos = view.posAtDOM(handle.parentNode, 0); } catch (e) { pos = null; }
      if (pos === null) { return; }
      const $pos = view.state.doc.resolve(pos);
      if ($pos.depth < 1) { return; }
      blockDialog.open('change', $pos.before(1));
    });
  }

  const deleteDialog = document.getElementById('delete-dialog');
  if (deleteButton && deleteDialog) {
    deleteButton.addEventListener('click', () => {
      if (typeof deleteDialog.showModal === 'function') { deleteDialog.showModal(); }
    });
    // 디버깅 묶음 U2 — 머리 ✕ 와 바닥 「닫기」 둘 다. 전엔 querySelector 라 첫 것(✕)만 이어져 바닥의 「그만두기」를 눌러도 닫히지 않았다
    for (const cancel of deleteDialog.querySelectorAll('[data-delete-close]')) {
      cancel.addEventListener('click', () => deleteDialog.close());
    }
    const confirmButton = document.getElementById('delete-confirm');
    if (confirmButton) {
      confirmButton.addEventListener('click', () => {
        deleteDialog.close();
        confirmButton.disabled = true;
        api('DELETE', '/api/pages/' + pageId, {})
          .then((result) => {
            if (result.status === 401) { toLogin(); return; }
            if (result.status === 200) { window.location.assign(COMMUNITY_BASE + '/workspace'); return; }
            setText(msg, errorMessage(result.data));
            confirmButton.disabled = false;
          })
          .catch(() => { setText(msg, GENERIC_ERROR); confirmButton.disabled = false; });
      });
    }
  }

  // B1-2 — 로그인 화면으로 떠나기 전(401 그 순간)에 이 화면의 저장 안 된 본문·쓰기 칸을 암호화해 남긴다.
  leaveHooks.push(() => Promise.all([writePageDraft(), writeCommentDraft()]));

  // 독립 검토 M-1(2026-09-24) — 로그아웃 단추(app.js)가 사본을 지우기 **직전**에 알린다. 그 뒤로는
  // 떠나는 길(beforeunload·visibilitychange·401 → leaveHooks)이 사본을 다시 쓰지 않는다.
  document.addEventListener('qaredi:logout', () => {
    shared.leavingOnPurpose = true;
    // 끄면 hasKey() 가 false 가 되어 writePageDraft·writeCommentDraft(1초 묶음 타이머 포함)가 곧바로 빠진다
    if (drafts) { drafts.shutdown(); }
  });

  // 창을 닫거나 다른 탭으로 옮겨 갈 때 남은 변경을 보낸다 (지시서 4장).
  // B1-2 설계 7 — 그 요청에 `keepalive`(닫히면서 취소되지 않게) · 임시저장 사본도 한 번 더 ·
  // 저장 안 된 변경이 남았으면 브라우저 기본 닫기 경고(문구는 브라우저가 정한다 — 일부러 떠나는 중이면 묻지 않는다).
  window.addEventListener('beforeunload', (event) => {
    if (pendingEditing && pendingTitleDirty && !shared.leavingOnPurpose) { event.preventDefault(); event.returnValue = ''; }
    if (state.editing) {
      flushSave(true);
      writePageDraft();
      if (!shared.leavingOnPurpose && serial !== savedSerial) {
        event.preventDefault();
        event.returnValue = '';
      }
    }
    writeCommentDraft();
  });
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'hidden') { return; }
    if (state.editing) { flushSave(true); writePageDraft(); }
    writeCommentDraft();
  });

  /* 5-C — 게시판 「새 글」은 `?edit=1` 로 열린다: 고칠 수 있으면(창구의 `can_edit`) 곧바로 편집 모드.
     꼬리표는 주소에서 지운다(새로 고침·뒤로 가기에 다시 편집 모드로 들어가지 않게). */
  load().then(() => {
    // 개발 대기열 6 — 「최근」은 이 페이지를 연 **뒤에** 받는다(창구가 방금 이 줄을 남겼다 — 읽기 판정을 지난 경우만)
    loadRecentNav();
    // B1-2 설계 5 — 이 사람·이 페이지의 임시저장이 있으면 알림 줄로 묻는다(불러오기는 사람이 누를 때만)
    offerDrafts();
    let wantEdit = false;
    try {
      const q = new URLSearchParams(window.location.search);
      wantEdit = q.get('edit') === '1';
      focusTitleOnce = wantEdit && q.get('new') === '1';   // UX-1 — 만든 쪽이 붙인 표식(주소에서 함께 지운다)
    } catch (e) { wantEdit = false; }
    if (!wantEdit) { return; }
    try { window.history.replaceState(null, '', window.location.pathname); } catch (e) { /* 주소 정리는 편의일 뿐 */ }
    // Q:F-12 U4-2 — 작업 전체 화면(`<body data-page-kind="task">`)에서는 일반 문서 편집기를 열지 않는다(설명은 칸 부품의 그 자리 편집기 — 누르면 편집)
    if (body.getAttribute('data-page-kind') === 'task') { return; }
    if (state.canEdit && !state.editing) { startEditing(); }
  }).catch(() => { loadRecentNav(); setText(msg, GENERIC_ERROR); });
}
