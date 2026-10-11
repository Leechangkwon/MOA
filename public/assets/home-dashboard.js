/* Account-specific layout only. No content, credentials, space IDs or permission cache. */
(() => {
  'use strict';
  const host = document.getElementById('home-widgets');
  if (!host || !/^[1-9][0-9]*$/.test(host.dataset.owner || '')) return;
  const ids = ['recent','services','tools','stories'];
  const names = {recent:'최근 작업 · 즐겨찾기',services:'서비스 바로가기',tools:'연락과 시작 안내',stories:'이야기와 읽을거리'};
  const nodes = Object.fromEntries(ids.map(id => [id,host.querySelector('[data-home-widget="'+id+'"]')]));
  if (ids.some(id => !nodes[id])) return;
  const key = 'moa-home:v1:'+host.dataset.owner;
  const note = document.getElementById('home-layout-status');
  const defaults = () => ({version:1,widgets:ids.map(id => ({id,visible:true,size:'wide'})),source:'all',view:'recent'});
  const engine=window.MoaDashboardCanvas;
  const valid=value=>engine.valid(value,ids),clean=engine.clean;
  let canvas=null;
  let saved = defaults();
  try {
    const raw = localStorage.getItem(key);
    if (raw) { const value = JSON.parse(raw); if (valid(value)) saved = clean(value); else note.textContent='저장된 구성을 읽을 수 없어 기본 홈을 표시합니다.'; }
  } catch (_) { note.textContent='브라우저 저장소를 사용할 수 없어 기본 홈을 표시합니다.'; }
  const empty = el('p','표시할 위젯이 없습니다. 홈 구성하기에서 필요한 위젯을 추가하세요.');
  empty.className = 'notice'; host.before(empty);
  let revision=null, csrf='', serverReady=false, saving=false;
  const browserCandidate=clean(saved);
  const base=nodes.recent.dataset.base||'/tbox';
  const editButton=document.getElementById('home-edit');editButton.disabled=true;note.textContent='계정의 홈 구성을 불러오는 중입니다.';
  async function request(method,body) {
    const response=await fetch(base+'/api/me/home-dashboard',{method,credentials:'same-origin',cache:'no-store',headers:body?{'Content-Type':'application/json','X-CSRF-Token':csrf}:{},body:body?JSON.stringify(body):undefined});
    const data=await response.json();
    if(!response.ok)throw Error(response.status===409?'다른 기기에서 홈 구성이 변경되었습니다. 취소 후 새로고침해 주세요.':data.error?.message||(typeof data.error==='string'?data.error:null)||data.message||'계정에 홈 구성을 저장하지 못했습니다.');
    return data;
  }
  const importButton=el('button','이 브라우저 구성을 계정으로 가져오기');importButton.type='button';importButton.className='btn btn--quiet';importButton.hidden=true;note.after(importButton);
  importButton.addEventListener('click',async()=>{if(canvas?.editing||saving)return;importButton.disabled=true;editButton.disabled=true;try{const data=await request('PATCH',{layout:browserCandidate,revision});revision=data.revision;saved=clean(browserCandidate);apply();serverReady=true;importButton.hidden=true;note.textContent='브라우저 구성을 계정에 저장했습니다.';}catch(error){note.textContent=error.message;}finally{importButton.disabled=false;editButton.disabled=Boolean(canvas?.editing);}});

  function apply() {
    canvas?.destroy();canvas=null;
    for (const widget of saved.widgets) {
      const node=nodes[widget.id];host.append(node);node.hidden=!widget.visible;
      node.classList.toggle('home-widget--wide',widget.size==='wide');
      if(!widget.visible&&widget.id==='stories')node.open=false;
    }
    empty.hidden=saved.widgets.some(w=>w.visible);
    contentSettings(saved);
    if(saved.version===2){canvas=makeCanvas();canvas.mount(saved);}
  }
  function contentSettings(layout){nodes.recent.dataset.startSource=layout.source;nodes.recent.dataset.startDefault=layout.view;nodes.recent.dispatchEvent(new Event('home-layout-change'));}
  function makeCanvas(){return new engine.Canvas(host,nodes,names,{
    title:'내 홈 대시보드 편집',changed:layout=>{empty.hidden=layout.widgets.some(w=>w.visible);const visible=layout.widgets.find(w=>w.id==='recent').visible;if(nodes.recent.dataset.canvasVisible!==String(visible)){nodes.recent.dataset.canvasVisible=String(visible);contentSettings(layout);}},notice:text=>note.textContent=text,
    editChange:active=>{editButton.disabled=active;importButton.disabled=active;},
    extra:(extra,layout)=>{extra.append(select('최근 작업 자료 선택',[['all','자료와 문서'],['files','자료실 파일'],['pages','업무 문서']],layout.source,value=>{canvas.layout.source=value;contentSettings(canvas.layout);}),select('처음 표시할 목록',[['recent','최근 작업'],['favorites','즐겨찾기']],layout.view,value=>{canvas.layout.view=value;contentSettings(canvas.layout);}));},
    save:async next=>{saving=true;try{if(serverReady){const data=await request('PATCH',{layout:next,revision});revision=data.revision;note.textContent='홈 배치를 계정에 저장했습니다. 다른 기기에서도 적용됩니다.';}else{localStorage.setItem(key,JSON.stringify(next));note.textContent='계정 동기화를 사용할 수 없어 이 브라우저에 저장했습니다.';}saved=clean(next);}catch(error){note.textContent=serverReady?error.message:'홈 배치를 저장하지 못했습니다. 브라우저 저장소 설정을 확인하세요.';throw Error(note.textContent);}finally{saving=false;}},
    saved:()=>{empty.hidden=saved.widgets.some(w=>w.visible);contentSettings(saved);editButton.focus();},
    cancel:()=>{canvas=null;apply();note.textContent='저장 전 배치로 돌아왔습니다.';editButton.focus();}
  });}
  function el(tag,text) {const node=document.createElement(tag);if(text)node.textContent=text;return node;}
  function select(label,choices,value,change) {
    const field=el('label');field.className='field';field.append(el('span',label));
    const input=el('select');input.className='input';input.setAttribute('aria-label',label);
    for(const [id,name] of choices){const option=el('option',name);option.value=id;input.append(option);}
    input.value=value;input.addEventListener('change',()=>change(input.value));field.append(input);return field;
  }
  for (const link of document.querySelectorAll('a[href="#services"]')) link.addEventListener('click',() => {
    if (nodes.services.hidden) { nodes.services.hidden = false; canvas?.revealTemporary('services'); empty.hidden = true; note.textContent = '서비스 바로가기를 잠시 표시합니다. 저장한 홈 구성은 유지됩니다.'; }
    const heading = document.getElementById('moa-choose-title'); heading.tabIndex = -1; heading.focus({preventScroll:true});
  });
  editButton.addEventListener('click',()=>{if(saving)return;if(!canvas)canvas=makeCanvas();canvas.begin(saved);});
  apply();
  (async()=>{try{
    const response=await fetch(base+'/api/me',{credentials:'same-origin',cache:'no-store'});const me=await response.json();if(!response.ok||me.next||String(me.member_id??me.id??me.user?.id)!==host.dataset.owner)throw Error('계정 확인을 완료하지 못했습니다.');csrf=me.csrf_token;if(typeof csrf!=='string'||!csrf)throw Error('계정 확인을 완료하지 못했습니다.');
    const data=await request('GET');revision=data.revision;serverReady=true;if(revision===0&&localStorage.getItem(key))importButton.hidden=false;
    if(data.layout&&valid(data.layout)){saved=clean(data.layout);apply();note.textContent='계정에 저장된 홈 구성입니다. 다른 기기에서도 적용됩니다.';}
    else {note.textContent='계정에 홈 구성을 저장하면 다른 기기에서도 적용됩니다.';importButton.hidden=!localStorage.getItem(key);}
  }catch(_){note.textContent='계정 동기화를 사용할 수 없어 이 브라우저의 홈 구성을 표시합니다.';}finally{editButton.disabled=false;}})();
})();
