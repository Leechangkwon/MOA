/** Q:F-17. Durable writes go through PHP; WebSocket traffic is transient preview only. */
const body=document.body, community=body.dataset.communityBase, base=body.dataset.base;
const page=body.dataset.pageId, endpoint=community+'/api/pages/'+page+'/whiteboard';
const $=id=>document.getElementById('whiteboard-'+id);
const status=text=>{ $('status').textContent=text; };
const stamp=e=>e.version+':'+e.versionNonce;
const types=new Set(['rectangle','diamond','ellipse','arrow','line','freedraw','text','frame']);
let board=null, can={}, engine=null, adapter=null, applying=false, replaying=false, selectedVersion=null;
let known=new Map(), remoteStamps=new Map(), localStamps=new Map(), durable=[], dirty=new Map(), saving=false, drawDown=false, pendingTimer=null;
let pollTimer=null, socket=null, socketRenew=null, closed=false, events=[], collaborators=new Map();
let backoff=1000, lastPreview=0, confirming=false, pollBusy=false, pollAgain=false, commitTimer=null, previewAt=0, needsAuthoritativeRender=false, intentUntil=0;
let lastPointer=null, pointerAt=0;

async function request(path='', method='GET', data=null) {
  const headers={Accept:'application/json'};
  if(method!=='GET') { headers['X-CSRF-Token']=body.dataset.csrf; }
  const opts={method,headers,credentials:'same-origin',cache:'no-store',redirect:'error'};
  if(data instanceof FormData) { opts.body=data; }
  else if(data!==null) { headers['Content-Type']='application/json'; opts.body=JSON.stringify(data); }
  const response=await fetch(endpoint+path,opts);
  let value;
  try { value=await response.json(); } catch { throw new Error('서버 응답을 읽을 수 없어요. 그린 내용은 창에 남아 있어요.'); }
  if(!response.ok) { const error=new Error(value.error || '연결을 확인해 주세요.'); error.status=response.status; throw error; }
  return value;
}
function scene(elements, preview=false) {
  if(!engine) { return; }
  applying=true;
  try {
    // Use the engine's own fractional-index reconciliation before stamping remote state.
    // Its index repairs may bump versions; those are synchronization, not recipient edits.
    const reconciled=adapter.reconcileElements(preview ? engine.api.getSceneElementsIncludingDeleted() : [],
      elements.map(e=>({...e})),preview ? engine.api.getAppState() : {});
    for(const e of reconciled) { remoteStamps.set(e.id,stamp(e)); }
    engine.api.updateScene({elements:reconciled,captureUpdate:adapter.CaptureUpdateAction.NEVER});
    for(const e of engine.api.getSceneElementsIncludingDeleted()) { remoteStamps.set(e.id,stamp(e)); }
  }
  finally { applying=false; }
}
function send(message) { if(socket?.readyState===WebSocket.OPEN) { socket.send(JSON.stringify(message)); } }
function changed(elements) {
  if(applying || replaying || !can.draw || confirming || !board) { return; }
  if(performance.now()>intentUntil) { for(const e of elements) { if(!dirty.has(e.id)) { remoteStamps.set(e.id,stamp(e)); } } return; }
  const updates=[];
  for(const e of elements) {
    if(!types.has(e.type)) { continue; }
    const value=stamp(e);
    if(known.get(e.id)!==value && remoteStamps.get(e.id)!==value && localStamps.get(e.id)!==value) {
      // Keep the last observed stamp separately: the engine can mutate element objects.
      localStamps.set(e.id,value); dirty.set(e.id,{...e}); updates.push(e);
    }
  }
  // A remote render may call onChange while our own write is pending. It must
  // neither re-broadcast that pending write nor postpone its autosave timer.
  if(!updates.length) { return; }
  status('저장 중…');
  if(Date.now()-lastPreview>60) {
    lastPreview=Date.now();
    const preview=updates.slice(-200);
    if(JSON.stringify(preview).length<100000) { send({type:'preview',elements:preview,sent_at:Date.now()}); }
  }
  clearTimeout(pendingTimer);
  if(!drawDown) { pendingTimer=setTimeout(flush,300); }
}
async function flush() {
  clearTimeout(pendingTimer);
  if(saving || drawDown || !dirty.size || !can.draw) { return; }
  saving=true;
  const sent=[]; let bytes=0;
  for(const element of dirty.values()) { const size=new TextEncoder().encode(JSON.stringify(element)).length; if(sent.length>=200 || bytes+size>240000) { break; } sent.push(element); bytes+=size; }
  try {
    const result=await request('/elements','POST',{whiteboard_id:board.id,base_seq:board.last_seq,elements:sent});
    const replacements=new Map();
    for(const accepted of result.accepted) {
      known.set(accepted.id,stamp(accepted.element));
      if(dirty.has(accepted.id) && stamp(dirty.get(accepted.id))===stamp(accepted.element)) { dirty.delete(accepted.id); }
      replacements.set(accepted.id,accepted.element);
    }
    let rejected=false;
    for(const item of result.rejected) {
      // Conflicts resolve to the authoritative winner; other failures stay visible for recovery.
      if(item.reason==='conflict' && item.element) { known.set(item.id,stamp(item.element)); dirty.delete(item.id); replacements.set(item.id,item.element); }
      else { rejected=true; }
    }
    durable=merge(durable,[...replacements.values()]);
    if(result.rejected.some(item=>item.reason==='conflict') && !replaying) { scene(merge(engine.api.getSceneElementsIncludingDeleted(),[...replacements.values()])); }
    if(!dirty.size) { $('recover').hidden=true; }
    board.last_seq=Math.max(board.last_seq,result.last_seq);
    send({type:'committed',seq:result.last_seq}); queueSync();
    status(rejected ? '저장하지 못한 요소가 있어요. 크기와 지원하는 도구를 확인해 주세요.' : dirty.size ? '저장 중…' : '저장됨 · '+board.last_seq+'번째 기록');
    backoff=1000;
    if(rejected) { $('recover').hidden=false; }
    if(!rejected && dirty.size) { pendingTimer=setTimeout(flush,300); }
  } catch(error) {
    status(error.message+' 그린 내용은 이 창에 남아 있어요.'); $('recover').hidden=false;
    if(error.status===413 || error.status===409 || error.status===404 || error.status===401 || error.status===403) {
      can.draw=false; engine?.setReadOnly(true);
    } else { pendingTimer=setTimeout(flush,backoff); backoff=Math.min(15000,backoff*2); }
  } finally { saving=false; }
}
function merge(current,incoming) {
  const map=new Map(current.map(e=>[e.id,e]));
  for(const e of incoming) { map.set(e.id,e); }
  return [...map.values()].sort((a,b)=>{ const left=String(a.index||a.id),right=String(b.index||b.id); return left===right ? 0 : left<right ? -1 : 1; });
}
function controls(data) {
  can=data.can; board=data.board;
  $('version').textContent='· 판 '+board.version_no+(board.state==='locked' ? ' · '+(board.locked_by_name || '')+' 확정' : '');
  $('fingerprints').hidden=!board.snapshot;
  $('fingerprints').textContent=board.snapshot ? '원본 장면: '+board.snapshot.scene_sha256+'\n참조 PNG: '+board.snapshot.png_sha256+'\n기록 사슬: '+board.snapshot.chain_head : '';
  $('confirm').hidden=!can.confirm;
  $('new').hidden=!can.new_version || selectedVersion!==null;
  $('presence').textContent=(data.presence||[]).map(p=>p.name).join(' · ');
  const select=$('versions'); select.replaceChildren();
  for(const item of data.versions||[]) {
    const option=document.createElement('option'); option.value=String(item.version_no);
    option.textContent='판 '+item.version_no+(item.state==='locked' ? ' · 확정' : ' · 그리는 중');
    option.selected=Number(item.version_no)===board.version_no; select.appendChild(option);
  }
  if(selectedVersion!==null) { can.draw=false; can.confirm=false; $('confirm').hidden=true; }
  engine?.setReadOnly(!can.draw || replaying || confirming);
}
async function load() {
  const data=await request(selectedVersion===null ? '' : '?version_no='+selectedVersion);
  if(!data.board) {
    if(data.can.create) {
      status('아직 그림판이 없어요.');
      const button=document.createElement('button'); button.className='btn'; button.textContent='회의 그림판 만들기';
      button.addEventListener('click',async()=>{
        button.disabled=true;
        try { await request('','POST',{}); await load(); } catch(error) { status(error.message); button.disabled=false; }
      });
      $('canvas').replaceChildren(button);
    } else { status('아직 회의 그림판이 없어요.'); }
    return;
  }
  dirty.clear(); localStamps.clear(); remoteStamps.clear(); known=new Map(data.elements.map(e=>[e.id,stamp(e)])); durable=data.elements;
  controls(data);
  if(!engine) {
    window.EXCALIDRAW_ASSET_PATH=base+'/assets/community/vendor/excalidraw/';
    adapter=await import(base+'/assets/community/vendor/excalidraw/editor.js?v='+body.dataset.vendorVersion);
    $('canvas').replaceChildren(); applying=true;
    engine=await adapter.mount($('canvas'),{elements:durable,readOnly:!can.draw,
      theme:(document.documentElement.dataset.theme==='dark' || (!document.documentElement.dataset.theme && matchMedia('(prefers-color-scheme: dark)').matches)) ? 'dark' : 'light',
      onChange:changed,onPointerUpdate:({pointer,button})=>{
        const released=drawDown && button!=='down';
        drawDown=button==='down';
        const now=performance.now();
        if(!lastPointer || (lastPointer.button!==button || now-pointerAt>=30)
          && (lastPointer.x!==pointer.x || lastPointer.y!==pointer.y || lastPointer.button!==button)) {
          lastPointer={x:pointer.x,y:pointer.y,button}; pointerAt=now;
          send({type:'pointer',...lastPointer});
        }
        if(released && dirty.size) { clearTimeout(pendingTimer); pendingTimer=setTimeout(flush,300); }
      }});
    for(const e of engine.api.getSceneElementsIncludingDeleted()) { remoteStamps.set(e.id,stamp(e)); }
    applying=false;
  } else { scene(durable); }
  controls(data);
  status(board.state==='locked' ? '확정한 판입니다. Claude 앱의 모아 도구로 회의록을 작성할 수 있어요.' : '저장됨 · '+board.last_seq+'번째 기록');
  startPoll(); connectRelay();
}
async function synchronize() {
  if(pollBusy) { pollAgain=true; return; }
  if(closed || document.hidden || !board || confirming) { return; }
  pollBusy=true;
  try {
    const data=await request(selectedVersion===null ? '' : '?version_no='+selectedVersion);
    if(data.board?.id!==board.id) {
      if(dirty.size) { status('새 판이 열렸어요. 미저장 그림은 내 그림 보관으로 남겨 주세요.'); $('recover').hidden=false; can.draw=false; engine.setReadOnly(true); }
      else { selectedVersion=null; await load(); }
      return;
    }
    const changed=data.elements.length!==durable.length || data.elements.some(e=>known.get(e.id)!==stamp(e));
    if(changed) { needsAuthoritativeRender=true; }
    durable=data.elements;
    for(const e of durable) { if(!dirty.has(e.id)) { known.set(e.id,stamp(e)); } }
    const expiredPreview=previewAt && Date.now()-previewAt>3000;
    if(!replaying && !dirty.size && !saving && (needsAuthoritativeRender || expiredPreview)) { scene(durable); needsAuthoritativeRender=false; if(expiredPreview) { previewAt=0; } }
    controls(data);
    if(!dirty.size && !replaying) { status(board.state==='locked' ? '확정한 판입니다. Claude 앱의 모아 도구로 회의록을 작성할 수 있어요.' : (can.draw ? '저장됨' : '보기 전용')+' · '+board.last_seq+'번째 기록'); }
    if(board.state==='locked' && dirty.size) { status('다른 사람이 판을 확정했어요. 미저장 그림은 내 그림 보관으로 남겨 주세요.'); $('recover').hidden=false; }
  } catch(error) {
    status(error.message);
    if([401,403,404].includes(error.status)) { closed=true; clearTimeout(pollTimer); clearTimeout(socketRenew); clearTimeout(commitTimer); can.draw=false; engine?.setReadOnly(true); socket?.close(); if(dirty.size) { $('recover').hidden=false; } }
  } finally {
    pollBusy=false;
    if(pollAgain) { pollAgain=false; queueSync(); }
  }
}
function queueSync() {
  if(commitTimer===null) { commitTimer=setTimeout(()=>{ commitTimer=null; synchronize(); },100); }
}
function startPoll() {
  clearTimeout(pollTimer);
  pollTimer=setTimeout(async()=>{ await synchronize(); if(!closed) { startPoll(); } },socket?.readyState===WebSocket.OPEN ? 5000 : 1000);
}
async function connectRelay() {
  clearTimeout(socketRenew); socket?.close();
  if(closed || !board || selectedVersion!==null) { return; }
  try {
    const ticket=await request('/ticket','POST',{whiteboard_id:board.id});
    const url=new URL(ticket.url,location.origin); url.protocol=location.protocol==='https:' ? 'wss:' : 'ws:';
    const ws=new WebSocket(url); socket=ws;
    ws.addEventListener('open',()=>send({type:'auth',ticket:ticket.ticket}));
    ws.addEventListener('message',event=>{
      try {
        const message=JSON.parse(event.data);
        if(message.type==='pointer' && Number.isFinite(message.x) && Number.isFinite(message.y)) {
          collaborators.set(String(message.uid),{username:String(message.name),pointer:{x:message.x,y:message.y,tool:'pointer'},button:message.button});
          engine.api.updateScene({collaborators:new Map(collaborators)});
        } else if(message.type==='preview' && !replaying && Array.isArray(message.elements)) {
          const currentIds=new Set(engine.api.getSceneElementsIncludingDeleted().map(e=>e.id));
          const preview=message.elements.filter(e=>{
            if(!e || !types.has(e.type) || typeof e.id!=='string' || !/^[A-Za-z0-9_-]{1,64}$/.test(e.id) || dirty.has(e.id)
              || !['x','y','width','height','angle'].every(k=>Number.isFinite(e[k]) && Math.abs(e[k])<=10000000)
              || !Number.isInteger(e.version) || !Number.isInteger(e.versionNonce) || typeof e.isDeleted!=='boolean') { return false; }
            if(e.link!=null && (typeof e.link!=='string' || e.link.length>2048 || /[\x00-\x20\x7f]/.test(e.link) || !/^(https?:\/\/|mailto:|\/(?!\/))/i.test(e.link))) { return false; }
            if(!currentIds.has(e.id) && currentIds.size>=5000) { return false; }
            currentIds.add(e.id); return true;
          });
          previewAt=Date.now();
          for(const e of preview) { remoteStamps.set(e.id,stamp(e)); }
          scene(preview,true);
        } else if(message.type==='committed') { queueSync(); }
      } catch { /* Untrusted transient previews never become durable records. */ }
    });
    ws.addEventListener('close',()=>{ if(socket===ws) { collaborators.clear(); engine?.api.updateScene({collaborators:new Map()}); if(!closed) { startPoll(); } } });
    socketRenew=setTimeout(connectRelay,45000);
  } catch { socketRenew=setTimeout(connectRelay,15000); }
}
async function history() {
  const aside=$('records'); aside.hidden=!aside.hidden;
  $('history').setAttribute('aria-expanded',String(!aside.hidden));
  if(aside.hidden) { return; }
  events=[]; let after=0;
  try {
    do {
      const data=await request('/events?after_seq='+after+'&limit=500&version_no='+board.version_no);
      events.push(...data.events); after=data.next_seq;
      if(events.length>100000) { throw new Error('기록이 많아 최근 판을 먼저 확인해 주세요.'); }
    } while(after!==null);
    $('replay').max=String(board.last_seq); $('replay').value=String(board.last_seq);
    const list=$('event-list'); list.replaceChildren();
    for(const event of events.slice(-500)) {
      const item=document.createElement('li');
      const button=document.createElement('button'); button.className='btn btn--quiet btn--small';
      button.textContent='#'+event.seq+' '+event.actor_name+' · '+new Date(event.at_utc).toLocaleTimeString('ko-KR')+' · '+({create:'그림',update:'고침',delete:'지움'}[event.op]||event.op)+' · '+String(event.data.text||event.data.type||'');
      button.addEventListener('click',()=>{ $('replay').value=String(event.seq); replay(event.seq); }); item.appendChild(button); list.appendChild(item);
    }
  } catch(error) { status(error.message); }
}
function replay(seq) {
  if(dirty.size || saving) { status('저장이 끝난 뒤 되감기를 열어 주세요.'); return; }
  replaying=true; engine.setReadOnly(true);
  const map=new Map(); for(const event of events) { if(event.seq>seq) { break; } if(event.data.id) { map.set(event.data.id,event.data); } }
  scene([...map.values()]); $('replay-label').textContent=seq+'번째 기록까지 · 보기 전용';
}
async function confirm() {
  const dialog=$('confirm-dialog'); dialog.returnValue=''; dialog.showModal();
}
$('confirm-dialog').addEventListener('close',async()=>{
  if($('confirm-dialog').returnValue!=='confirm') { return; }
  if(drawDown) { status('그리기를 마친 뒤 확정해 주세요.'); return; }
  await flush();
  if(dirty.size || saving || !can.confirm || replaying) { status('저장이 끝난 현재 판에서 확정해 주세요.'); return; }
  confirming=true; engine.setReadOnly(true); $('confirm').disabled=true;
  try {
    // Re-read before export. A concurrent edit after this point is rejected by the server's last_seq check.
    const current=await request();
    if(current.board.id!==board.id || current.board.state!=='open') { throw new Error('판이 바뀌었습니다. 새로 열어 주세요.'); }
    durable=current.elements; scene(durable); board=current.board;
    const png=await adapter.exportToBlob({elements:durable.filter(e=>!e.isDeleted),files:engine.api.getFiles(),
      appState:{...engine.api.getAppState(),exportBackground:true,exportWithDarkMode:false,exportEmbedScene:false},
      mimeType:'image/png',maxWidthOrHeight:4000});
    const form=new FormData(); form.append('whiteboard_id',board.id); form.append('last_seq',String(board.last_seq)); form.append('png',png,'meeting.png');
    await request('/confirm','POST',form);
    confirming=false; await load();
  } catch(error) { status(error.message); }
  finally { confirming=false; $('confirm').disabled=false; engine.setReadOnly(!can.draw || replaying); }
});
$('confirm').addEventListener('click',confirm);
$('new').addEventListener('click',async()=>{
  if(dirty.size || saving) { $('recover').hidden=false; status('미저장 그림을 먼저 보관한 뒤 판을 다시 열어 주세요.'); return; }
  $('new').disabled=true;
  try { await request('/versions','POST',{whiteboard_id:board.id}); replaying=false; await load(); }
  catch(error) { status(error.message); } finally { $('new').disabled=false; }
});
$('recover').addEventListener('click',()=>{
  if(!engine) { return; }
  const data={type:'excalidraw',version:2,source:'moa',elements:engine.api.getSceneElementsIncludingDeleted(),appState:{viewBackgroundColor:engine.api.getAppState().viewBackgroundColor},files:{}};
  const url=URL.createObjectURL(new Blob([JSON.stringify(data)],{type:'application/json'}));
  const link=document.createElement('a'); link.href=url; link.download='회의그림판-미저장.excalidraw'; link.click();
  setTimeout(()=>URL.revokeObjectURL(url),1000);
});
$('history').addEventListener('click',history);
$('replay').addEventListener('input',event=>replay(Number(event.target.value)));
$('live').addEventListener('click',async()=>{ replaying=false; if(selectedVersion!==null) { selectedVersion=null; await load(); } else { scene(durable); engine.setReadOnly(!can.draw); } $('replay-label').textContent='현재 판'; });
$('versions').addEventListener('change',async(event)=>{
  if(dirty.size || saving) { event.target.value=String(board.version_no); status('저장을 마친 뒤 판을 바꿔 주세요.'); return; }
  selectedVersion=event.target.selectedIndex===0 ? null : Number(event.target.value); replaying=false;
  try { await load(); } catch(error) { status(error.message); }
});
window.addEventListener('beforeunload',event=>{ if(dirty.size || saving) { event.preventDefault(); event.returnValue=''; } });
// Excalidraw's pointer-update callback follows movement; a stationary release also ends a stroke.
window.addEventListener('pointerup',()=>{ drawDown=false; if(dirty.size) { clearTimeout(pendingTimer); pendingTimer=setTimeout(flush,300); } },true);
window.addEventListener('pointercancel',()=>{ drawDown=false; if(dirty.size) { clearTimeout(pendingTimer); pendingTimer=setTimeout(flush,300); } },true);
window.addEventListener('pagehide',()=>{ closed=true; clearTimeout(pollTimer); clearTimeout(socketRenew); clearTimeout(commitTimer); socket?.close(); });
load().catch(error=>status(error.message));

window.addEventListener('pageshow',event=>{ if(event.persisted) { closed=false; startPoll(); connectRelay(); synchronize(); } });

// Image paste/drop bypasses the toolbar setting in the drawing engine.
// Keep this phase's file boundary explicit before the engine sees those events.
for(const type of ['paste','drop']) {
  window.addEventListener(type,event=>{
    const data=event.clipboardData || event.dataTransfer;
    if([...data?.items || []].some(item=>item.kind==='file')) {
      event.preventDefault(); event.stopImmediatePropagation();
      status('이 판은 그림 파일 붙여넣기를 아직 지원하지 않아요. 배경 캡처는 다음 단계에서 제공해요.');
    }
  },true);
}

for(const type of ['pointerdown','pointerup','keydown','input','paste','cut','drop']) { window.addEventListener(type,event=>{ if(event.isTrusted) { intentUntil=performance.now()+2000; } },true); }
window.addEventListener('pointermove',event=>{ if(event.isTrusted && event.buttons) { intentUntil=performance.now()+2000; } },true);
