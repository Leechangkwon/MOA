/** Q:F-17. Durable writes go through PHP; WebSocket traffic is transient preview only. */
const body=document.body, community=body.dataset.communityBase, base=body.dataset.base;
const page=body.dataset.pageId, endpoint=community+'/api/pages/'+page+'/whiteboard';
const $=id=>document.getElementById('whiteboard-'+id);
const status=text=>{ if($('status').textContent!==text) { $('status').textContent=text; } };
const stamp=e=>e.version+':'+e.versionNonce+':'+Boolean(e.isDeleted);
const types=new Set(['rectangle','diamond','ellipse','arrow','line','freedraw','text','frame']);
let board=null, can={}, engine=null, adapter=null, applying=false, replaying=false, selectedVersion=null;
let known=new Map(), remoteStamps=new Map(), localStamps=new Map(), durable=[], dirty=new Map(), saving=false, drawDown=false, pendingTimer=null;
let pollTimer=null, socket=null, socketRenew=null, closed=false, events=[], collaborators=new Map();
let backoff=1000, confirming=false, pollBusy=false, pollAgain=false, commitTimer=null, previewAt=0, needsAuthoritativeRender=false, intentUntil=0;
let lastPointer=null, pointerAt=0;
let previewTimer=null, remoteFrame=null, cursorChanged=false, relayConnecting=false, relayGeneration=0, relayReady=false;
let listTimer=null, remoteQueuedAt=null;
let loadGeneration=0, loadBusy=false, loadedVersion=null, historyGeneration=0, eventsBoard=null;
const outgoingPreviews=new Map(), incomingPreviews=new Map(), encodedElements=new Map(), sceneIds=new Set();
const encoder=new TextEncoder(), timings=new Map();
// Durations never compare clocks on different devices. Only bounded numeric samples stay in this tab.
function timing(name,started) {
  const samples=timings.get(name)||[]; samples.push(Math.max(0,performance.now()-started));
  if(samples.length>120) { samples.shift(); } timings.set(name,samples);
}
window.MoaWhiteboardDiagnostics=Object.freeze({snapshot:()=>Object.fromEntries([...timings].map(([name,values])=>{
  const sorted=[...values].sort((a,b)=>a-b);
  return [name,{count:values.length,p95_ms:sorted[Math.min(sorted.length-1,Math.floor(sorted.length*.95))]||0}];
}))});
function connection(state) {
  if(document.hidden && state!=='offline') { state='paused'; }
  $('connection').textContent=({connecting:'연결 확인 중',live:'실시간 연결',polling:'주기적으로 동기화 중',paused:'다른 화면을 보는 중 · 돌아오면 연결',offline:'연결 끊김 · 내용은 이 창에 보관'}[state]||state);
}
function pendingStatus() { status(saving || dirty.size ? '저장 대기 · '+dirty.size+'개 요소는 이 창에 있어요.' : '저장됨 · '+board.last_seq+'번째 기록'); }
function encoded(element) {
  const version=stamp(element), cached=encodedElements.get(element.id);
  if(cached?.stamp===version) { return cached; }
  const json=JSON.stringify(element), value={stamp:version,json,bytes:encoder.encode(json).length};
  encodedElements.set(element.id,value); return value;
}

async function request(path='', method='GET', data=null, serialized=null) {
  const started=performance.now();
  const headers={Accept:'application/json'};
  if(method!=='GET') { headers['X-CSRF-Token']=body.dataset.csrf; }
  const opts={method,headers,credentials:'same-origin',cache:'no-store',redirect:'error'};
  if(data instanceof FormData) { opts.body=data; }
  else if(data!==null) { headers['Content-Type']='application/json'; opts.body=serialized??JSON.stringify(data); }
  const response=await fetch(endpoint+path,opts);
  let value;
  try { value=await response.json(); } catch { throw new Error('서버 응답을 읽을 수 없어요. 그린 내용은 창에 남아 있어요.'); }
  timing(method==='GET' ? 'read_ms' : 'write_ms',started);
  if(!response.ok) { const error=new Error(value.error || '연결을 확인해 주세요.'); error.status=response.status; throw error; }
  return value;
}
function scene(elements, preview=false) {
  if(!engine) { return; }
  const started=performance.now(); applying=true;
  try {
    // Use the engine's own fractional-index reconciliation before stamping remote state.
    // Its index repairs may bump versions; those are synchronization, not recipient edits.
    const current=engine.api.getSceneElementsIncludingDeleted(), currentById=new Map(current.map(e=>[e.id,e]));
    const reconciled=adapter.reconcileElements(preview ? current : [],
      elements.map(e=>currentById.get(e.id)===e ? e : {...e}),preview ? engine.api.getAppState() : {});
    for(const e of reconciled) { remoteStamps.set(e.id,stamp(e)); }
    const update={elements:reconciled,captureUpdate:adapter.CaptureUpdateAction.NEVER};
    if(cursorChanged) { update.collaborators=new Map(collaborators); cursorChanged=false; }
    engine.api.updateScene(update);
    sceneIds.clear();
    for(const e of engine.api.getSceneElementsIncludingDeleted()) { remoteStamps.set(e.id,stamp(e)); sceneIds.add(e.id); }
  }
  finally { applying=false; timing('scene_update_ms',started); scheduleElementList(); }
}
function send(message) { if(relayReady && socket?.readyState===WebSocket.OPEN) { socket.send(JSON.stringify(message)); return true; } return false; }
function queuePreview(elements) {
  for(const e of elements) { outgoingPreviews.set(e.id,e); }
  if(previewTimer===null && !document.hidden) { previewTimer=setTimeout(flushPreviews,60); }
}
function flushPreviews() {
  clearTimeout(previewTimer); previewTimer=null;
  if(document.hidden || !relayReady || socket?.readyState!==WebSocket.OPEN) { return; }
  const parts=[]; let bytes=0;
  for(const [id,e] of outgoingPreviews) {
    const value=encoded(e);
    if(value.bytes>95000) { outgoingPreviews.delete(id); continue; }
    if(parts.length>=200 || bytes+value.bytes>95000) { break; }
    parts.push(value.json); bytes+=value.bytes+1; outgoingPreviews.delete(id);
  }
  if(parts.length) { socket.send('{"type":"preview","elements":['+parts.join(',')+'],"sent_at":'+Date.now()+'}'); }
  if(outgoingPreviews.size) { previewTimer=setTimeout(flushPreviews,60); }
}
function scheduleRemote() {
  if(remoteFrame===null && !document.hidden) { remoteFrame=requestAnimationFrame(renderRemote); }
}
function renderRemote() {
  remoteFrame=null;
  if(!engine || document.hidden) { return; }
  if(incomingPreviews.size && !replaying && !confirming) {
    const updates=[...incomingPreviews.values()].filter(e=>!dirty.has(e.id)); incomingPreviews.clear();
    if(updates.length) { scene(updates,true); if(remoteQueuedAt!==null) { timing('preview_to_update_ms',remoteQueuedAt); } }
  } else { incomingPreviews.clear(); }
  remoteQueuedAt=null;
  if(cursorChanged) { cursorChanged=false; engine.api.updateScene({collaborators:new Map(collaborators)}); }
}
function discardTransient() {
  incomingPreviews.clear(); remoteQueuedAt=null; collaborators.clear(); cursorChanged=true;
  if(remoteFrame!==null) { cancelAnimationFrame(remoteFrame); remoteFrame=null; }
}
function changed(elements) {
  if(applying || replaying || !can.draw || confirming || !board) { return; }
  scheduleElementList();
  if(performance.now()>intentUntil) { for(const e of elements) { if(!dirty.has(e.id)) { remoteStamps.set(e.id,stamp(e)); } } return; }
  const started=performance.now(), updates=[];
  for(const e of elements) {
    sceneIds.add(e.id);
    if(!types.has(e.type)) { continue; }
    const value=stamp(e);
    if(known.get(e.id)!==value && remoteStamps.get(e.id)!==value && localStamps.get(e.id)!==value) {
      // Keep the last observed stamp separately: the engine can mutate element objects.
      localStamps.set(e.id,value); dirty.set(e.id,{...e}); updates.push(e);
    }
  }
  // A remote render may call onChange while our own write is pending. It must
  // neither re-broadcast that pending write nor postpone its autosave timer.
  timing('local_change_ms',started);
  if(!updates.length) { return; }
  pendingStatus(); queuePreview(updates.map(e=>dirty.get(e.id))); scheduleElementList();
  clearTimeout(pendingTimer);
  if(!drawDown) { pendingTimer=setTimeout(flush,300); }
}
async function flush() {
  clearTimeout(pendingTimer);
  if(saving || drawDown || !dirty.size || !can.draw) { return; }
  saving=true;
  const sent=[], parts=[]; let bytes=0;
  for(const element of dirty.values()) {
    const value=encoded(element);
    if(value.bytes>240000) { continue; }
    if(sent.length>=200 || bytes+value.bytes>240000) { break; }
    sent.push(element); parts.push(value.json); bytes+=value.bytes+1;
  }
  if(!sent.length) {
    saving=false; $('recover').hidden=false;
    status('요소가 너무 커서 저장하지 못했어요. 내 그림 보관으로 내용을 남겨 주세요.'); return;
  }
  try {
    // The same immutable stamp is encoded once for preview and durable submission.
    const payload='{"whiteboard_id":'+JSON.stringify(board.id)+',"base_seq":'+board.last_seq+',"elements":['+parts.join(',')+']}';
    const result=await request('/elements','POST',{},payload);
    connection(relayReady ? 'live' : 'polling');
    const replacements=new Map();
    for(const accepted of result.accepted) {
      known.set(accepted.id,stamp(accepted.element));
      if(dirty.has(accepted.id) && stamp(dirty.get(accepted.id))===stamp(accepted.element)) { dirty.delete(accepted.id); }
      replacements.set(accepted.id,accepted.element);
    }
    let rejected=false;
    for(const item of result.rejected) {
      // Conflicts resolve to the authoritative winner; other failures stay visible for recovery.
      if(item.reason==='conflict' && item.element) {
        known.set(item.id,stamp(item.element));
        const submitted=sent.find(e=>e.id===item.id);
        // A response to an older request must not erase the next local version.
        if(submitted && dirty.has(item.id) && stamp(dirty.get(item.id))===stamp(submitted)) { dirty.delete(item.id); }
        replacements.set(item.id,item.element);
      }
      else { rejected=true; }
    }
    durable=merge(durable,[...replacements.values()]);
    if(result.rejected.some(item=>item.reason==='conflict') && !replaying) { scene(merge(engine.api.getSceneElementsIncludingDeleted(),[...replacements.values()].filter(e=>!dirty.has(e.id)))); }
    if(!dirty.size) { $('recover').hidden=true; }
    board.last_seq=Math.max(board.last_seq,result.last_seq);
    send({type:'committed',seq:result.last_seq}); queueSync();
    status(rejected ? '저장하지 못한 요소가 있어요. 크기와 지원하는 도구를 확인해 주세요.' : dirty.size ? '저장 중…' : '저장됨 · '+board.last_seq+'번째 기록');
    backoff=1000;
    if(rejected) { $('recover').hidden=false; }
    if(!rejected && dirty.size) { pendingTimer=setTimeout(flush,300); }
  } catch(error) {
    connection('offline');
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
  const select=$('versions'), versionsKey=(data.versions||[]).map(item=>item.version_no+':'+item.state).join('|');
  if(select.dataset.versionsKey!==versionsKey) {
    select.dataset.versionsKey=versionsKey; select.replaceChildren();
    for(const item of data.versions||[]) {
      const option=document.createElement('option'); option.value=String(item.version_no);
      option.textContent='판 '+item.version_no+(item.state==='locked' ? ' · 확정' : ' · 그리는 중');
      select.appendChild(option);
    }
  }
  select.value=String(board.version_no);
  if(selectedVersion!==null) { can.draw=false; can.confirm=false; $('confirm').hidden=true; }
  engine?.setReadOnly(!can.draw || replaying || confirming);
}
async function load() {
  if(engine && (dirty.size || saving || drawDown)) {
    selectedVersion=loadedVersion;
    status('저장을 마친 뒤 판을 바꿔 주세요. 미저장 그림은 이 창에 남아 있어요.');
    if(dirty.size) { $('recover').hidden=false; }
    return;
  }
  const generation=++loadGeneration, requestedVersion=selectedVersion;
  loadBusy=true; engine?.setReadOnly(true);
  try {
    const data=await request(requestedVersion===null ? '' : '?version_no='+requestedVersion);
    if(generation!==loadGeneration) { return; }
    // A final engine callback can follow the read-only transition. Keep that edit,
    // rather than clearing it with a snapshot requested before the callback.
    if(dirty.size || saving) {
      selectedVersion=loadedVersion; $('versions').value=String(board.version_no);
      status('새 변경이 있어 판 전환을 멈췄어요. 미저장 그림은 이 창에 남아 있어요.'); $('recover').hidden=false;
      return;
    }
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
    pauseRelay(); outgoingPreviews.clear(); encodedElements.clear(); sceneIds.clear();
    ++historyGeneration; eventsBoard=null;
    for(const e of data.elements) { sceneIds.add(e.id); }
    dirty.clear(); localStamps.clear(); remoteStamps.clear(); known=new Map(data.elements.map(e=>[e.id,stamp(e)])); durable=data.elements;
    controls(data);
    loadedVersion=requestedVersion;
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
          if(released) { endStroke(); }
        }});
      for(const e of engine.api.getSceneElementsIncludingDeleted()) { remoteStamps.set(e.id,stamp(e)); }
      applying=false;
    } else { scene(durable); }
    controls(data);
    scheduleElementList();
    status(board.state==='locked' ? '확정한 판입니다. Claude 앱의 모아 도구로 회의록을 작성할 수 있어요.' : '저장됨 · '+board.last_seq+'번째 기록');
    startPoll(); connectRelay();
  } catch(error) {
    if(generation===loadGeneration) { selectedVersion=loadedVersion; if(board) { $('versions').value=String(board.version_no); } }
    throw error;
  } finally {
    if(generation===loadGeneration) { loadBusy=false; engine?.setReadOnly(!can.draw || replaying || confirming); }
  }
}
async function synchronize() {
  if(pollBusy) { pollAgain=true; return; }
  if(closed || document.hidden || !board || confirming || loadBusy) { return; }
  pollBusy=true;
  const generation=loadGeneration, boardId=board.id, version=selectedVersion;
  try {
    const data=await request(selectedVersion===null ? '' : '?version_no='+selectedVersion);
    if(generation!==loadGeneration || board.id!==boardId || selectedVersion!==version || confirming) { return; }
    if(data.board?.id===board.id && data.board.last_seq<board.last_seq) { return; }
    if(data.board?.id!==board.id) {
      if(dirty.size) { status('새 판이 열렸어요. 미저장 그림은 내 그림 보관으로 남겨 주세요.'); $('recover').hidden=false; can.draw=false; engine.setReadOnly(true); }
      else { selectedVersion=null; await load(); }
      return;
    }
    const changed=data.elements.length!==durable.length || data.elements.some(e=>known.get(e.id)!==stamp(e));
    if(changed) { needsAuthoritativeRender=true; }
    durable=data.elements;
    for(const e of durable) { if(!dirty.has(e.id)) { known.set(e.id,stamp(e)); } }
    const expiredPreview=previewAt && performance.now()-previewAt>3000;
    if(!replaying && !dirty.size && !saving && (needsAuthoritativeRender || expiredPreview)) {
      incomingPreviews.clear(); scene(durable); needsAuthoritativeRender=false; previewAt=0;
    }
    connection(relayReady ? 'live' : 'polling');
    controls(data);
    if(!dirty.size && !replaying) { status(board.state==='locked' ? '확정한 판입니다. Claude 앱의 모아 도구로 회의록을 작성할 수 있어요.' : (can.draw ? '저장됨' : '보기 전용')+' · '+board.last_seq+'번째 기록'); }
    if(board.state==='locked' && dirty.size) { status('다른 사람이 판을 확정했어요. 미저장 그림은 내 그림 보관으로 남겨 주세요.'); $('recover').hidden=false; }
  } catch(error) {
    connection('offline');
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
  if(closed || document.hidden) { return; }
  pollTimer=setTimeout(async()=>{ await synchronize(); if(!closed) { startPoll(); } },socket?.readyState===WebSocket.OPEN ? 5000 : 1000);
}
async function connectRelay() {
  if(relayConnecting) { clearTimeout(socketRenew); socketRenew=setTimeout(connectRelay,500); return; }
  clearTimeout(socketRenew);
  if(closed || document.hidden || !board || selectedVersion!==null) { return; }
  const generation=++relayGeneration, boardId=board.id;
  relayConnecting=true;
  try {
    const ticket=await request('/ticket','POST',{whiteboard_id:board.id});
    if(generation!==relayGeneration || closed || document.hidden || board.id!==boardId) { return; }
    clearTimeout(socketRenew);
    relayReady=false; socket?.close();
    const url=new URL(ticket.url,location.origin); url.protocol=location.protocol==='https:' ? 'wss:' : 'ws:';
    const ws=new WebSocket(url); socket=ws;
    ws.addEventListener('open',()=>{ if(socket===ws) { ws.send(JSON.stringify({type:'auth',ticket:ticket.ticket})); } });
    ws.addEventListener('message',event=>{
      if(socket!==ws || document.hidden || closed) { return; }
      try {
        const message=JSON.parse(event.data);
        if(message.type==='ready') {
          relayReady=true; connection('live'); flushPreviews();
          if(dirty.size && !saving && !drawDown) { flush(); }
        } else if(message.type==='pointer' && Number.isFinite(message.x) && Number.isFinite(message.y)) {
          collaborators.set(String(message.uid),{username:String(message.name),pointer:{x:message.x,y:message.y,tool:'pointer'},button:message.button});
          cursorChanged=true; scheduleRemote();
        } else if(message.type==='preview' && !replaying && !confirming && Array.isArray(message.elements)) {
          const received=performance.now();
          for(const e of message.elements.slice(0,200)) {
            if(!e || !types.has(e.type) || typeof e.id!=='string' || !/^[A-Za-z0-9_-]{1,64}$/.test(e.id) || dirty.has(e.id)
              || !['x','y','width','height','angle'].every(k=>Number.isFinite(e[k]) && Math.abs(e[k])<=10000000)
              || !Number.isInteger(e.version) || !Number.isInteger(e.versionNonce) || typeof e.isDeleted!=='boolean') { continue; }
            if(e.link!=null && (typeof e.link!=='string' || e.link.length>2048 || /[\x00-\x20\x7f]/.test(e.link) || !/^(https?:\/\/|mailto:|\/(?!\/))/i.test(e.link))) { continue; }
            if(!sceneIds.has(e.id) && sceneIds.size>=5000) { continue; }
            const queued=incomingPreviews.get(e.id);
            if(!queued || e.version>queued.version || e.version===queued.version && e.versionNonce<=queued.versionNonce) { incomingPreviews.set(e.id,e); }
            sceneIds.add(e.id);
          }
          previewAt=received; if(remoteQueuedAt===null) { remoteQueuedAt=received; }
          scheduleRemote(); timing('preview_receive_ms',received);
        } else if(message.type==='committed') { queueSync(); }
      } catch { /* Untrusted transient previews never become durable records. */ }
    });
    ws.addEventListener('close',()=>{ if(socket===ws) {
      relayReady=false; collaborators.clear(); cursorChanged=true; scheduleRemote();
      if(!closed && !document.hidden) { connection('polling'); startPoll(); clearTimeout(socketRenew); socketRenew=setTimeout(connectRelay,1500); }
    } });
    socketRenew=setTimeout(connectRelay,45000);
  } catch { if(!closed && !document.hidden) { connection(relayReady ? 'live' : 'polling'); socketRenew=setTimeout(connectRelay,15000); } }
  finally { relayConnecting=false; }
}
async function history() {
  const aside=$('records'); aside.hidden=!aside.hidden;
  if(!aside.hidden) { $('elements').hidden=true; $('element-toggle').setAttribute('aria-expanded','false'); }
  $('history').setAttribute('aria-expanded',String(!aside.hidden));
  if(aside.hidden) { return; }
  const generation=++historyGeneration, boardId=board.id, version=board.version_no, loaded=[];
  eventsBoard=null; $('replay').disabled=true;
  let after=0;
  try {
    do {
      const data=await request('/events?after_seq='+after+'&limit=500&version_no='+version);
      if(generation!==historyGeneration || board.id!==boardId) { return; }
      loaded.push(...data.events); after=data.next_seq;
      if(loaded.length>100000) { throw new Error('기록이 많아 최근 판을 먼저 확인해 주세요.'); }
    } while(after!==null);
    events=loaded; eventsBoard=boardId; $('replay').disabled=false;
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
  if(dirty.size || saving || confirming || loadBusy || eventsBoard!==board.id) { status('저장과 판 불러오기가 끝난 뒤 되감기를 열어 주세요.'); return; }
  replaying=true; discardTransient(); engine.setReadOnly(true);
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
  if(dirty.size || saving || !can.confirm || replaying || confirming || loadBusy) { status('저장이 끝난 현재 판에서 확정해 주세요.'); return; }
  confirming=true; discardTransient(); engine.setReadOnly(true); $('confirm').disabled=true;
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
  if(dirty.size || saving || confirming || loadBusy) { $('recover').hidden=false; status('저장과 판 불러오기를 마친 뒤 판을 다시 열어 주세요.'); return; }
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
function fitAll() {
  if(!engine) { return; }
  if(drawDown) { status('그리기를 마친 뒤 전체 보기를 눌러 주세요.'); return; }
  const elements=engine.api.getSceneElementsIncludingDeleted().filter(e=>!e.isDeleted);
  if(elements.length) { engine.api.scrollToContent(elements,{fitToContent:true,animate:false}); }
}
const elementButtons=new Map();
function scheduleElementList() {
  if($('elements').hidden || listTimer!==null) { return; }
  listTimer=setTimeout(()=>{ listTimer=null; renderElementList(); },250);
}
function renderElementList() {
  if(!engine || $('elements').hidden) { return; }
  const elements=engine.api.getSceneElementsIncludingDeleted().filter(e=>!e.isDeleted), ids=new Set(elements.map(e=>e.id));
  const list=$('element-list'), selected=engine.api.getAppState().selectedElementIds||{};
  const names={rectangle:'사각형',diamond:'마름모',ellipse:'타원',arrow:'화살표',line:'선',freedraw:'손그림',text:'글',frame:'틀'};
  for(const [id,button] of elementButtons) { if(!ids.has(id)) { button.parentElement.remove(); elementButtons.delete(id); } }
  elements.forEach((element,index)=>{
    let button=elementButtons.get(element.id);
    if(!button) {
      const item=document.createElement('li'); button=document.createElement('button'); button.type='button'; button.className='btn btn--quiet btn--small';
      button.addEventListener('click',()=>{
        const current=engine.api.getSceneElementsIncludingDeleted().find(e=>e.id===element.id && !e.isDeleted);
        if(!current || drawDown) { return; }
        engine.api.updateScene({appState:{selectedElementIds:{[current.id]:true}},captureUpdate:adapter.CaptureUpdateAction.NEVER});
        engine.api.scrollToContent([current],{animate:false}); renderElementList();
      });
      item.appendChild(button); elementButtons.set(element.id,button);
    }
    const label=(index+1)+'. '+(names[element.type]||'요소')+(element.type==='text' ? ' · '+String(element.text||'빈 글') : element.label ? ' · '+String(element.label) : '');
    if(button.textContent!==label) { button.textContent=label; }
    button.setAttribute('aria-pressed',String(Boolean(selected[element.id])));
    const item=button.parentElement;
    if(list.children[index]!==item) { list.insertBefore(item,list.children[index]||null); }
  });
  $('element-count').textContent=elements.length ? elements.length+'개 요소 · 누르면 그림판에서 선택해요.' : '현재 판에 요소가 없어요.';
}
$('fit').addEventListener('click',fitAll);
$('element-toggle').addEventListener('click',()=>{
  const aside=$('elements'); aside.hidden=!aside.hidden;
  $('element-toggle').setAttribute('aria-expanded',String(!aside.hidden));
  if(!aside.hidden) { $('records').hidden=true; $('history').setAttribute('aria-expanded','false'); renderElementList(); }
});
function renderDiagnostics() {
  const summary=window.MoaWhiteboardDiagnostics.snapshot(), labels={read_ms:'서버 읽기 응답',write_ms:'서버 쓰기 응답',local_change_ms:'내 변경 확인',scene_update_ms:'장면 API 갱신',preview_receive_ms:'미리보기 검증',preview_to_update_ms:'미리보기 수신 → 장면 API 갱신'};
  $('diagnostic-values').textContent=Object.entries(summary).map(([name,value])=>(labels[name]||name)+': p95 '+value.p95_ms.toFixed(1)+'ms · 최근 '+value.count+'회').join('\n')||'아직 측정한 동작이 없어요.';
}
$('diagnostics').addEventListener('toggle',()=>{ if($('diagnostics').open) { renderDiagnostics(); } });
$('diagnostic-refresh').addEventListener('click',renderDiagnostics);
$('history').addEventListener('click',history);
$('replay').addEventListener('input',event=>replay(Number(event.target.value)));
$('live').addEventListener('click',async()=>{
  if(confirming || loadBusy) { status('판 처리를 마친 뒤 현재 판을 열어 주세요.'); return; }
  replaying=false;
  try {
    if(selectedVersion!==null) { selectedVersion=null; await load(); }
    else { scene(durable); engine.setReadOnly(!can.draw); }
    $('replay-label').textContent='현재 판';
  } catch(error) { status(error.message); }
});
$('versions').addEventListener('change',async(event)=>{
  if(dirty.size || saving || confirming || loadBusy) { event.target.value=String(board.version_no); status('저장과 판 불러오기를 마친 뒤 판을 바꿔 주세요.'); return; }
  selectedVersion=event.target.selectedIndex===0 ? null : Number(event.target.value); replaying=false;
  try { await load(); } catch(error) { status(error.message); }
});
window.addEventListener('beforeunload',event=>{ if(dirty.size || saving) { event.preventDefault(); event.returnValue=''; } });
// Excalidraw's pointer-update callback follows movement; a stationary release also ends a stroke.
function endStroke() {
  drawDown=false; flushPreviews();
  if(dirty.size) { clearTimeout(pendingTimer); pendingTimer=setTimeout(flush,300); }
}
window.addEventListener('pointerup',endStroke,true);
window.addEventListener('pointercancel',endStroke,true);
function pauseRelay() {
  ++relayGeneration; relayReady=false;
  clearTimeout(pollTimer); clearTimeout(socketRenew); clearTimeout(commitTimer); commitTimer=null;
  clearTimeout(previewTimer); previewTimer=null; discardTransient(); socket?.close();
  engine?.api.updateScene({collaborators:new Map()});
}
window.addEventListener('pagehide',()=>{ endStroke(); closed=true; pauseRelay(); });
document.addEventListener('visibilitychange',()=>{
  if(document.hidden) {
    endStroke(); pauseRelay(); connection('paused');
    // Durable PHP writes continue; ephemeral tickets are renewed only on return.
    if(dirty.size && !saving) { flush(); }
  } else if(!closed) {
    connection('connecting'); synchronize(); startPoll(); connectRelay();
    if(dirty.size && !saving) { flush(); }
  }
});
load().catch(error=>status(error.message));

window.addEventListener('pageshow',event=>{ if(event.persisted) { closed=false; connection('connecting'); startPoll(); connectRelay(); synchronize(); if(dirty.size) { flush(); } } });

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
