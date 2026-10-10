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
  const dialog = document.getElementById('home-editor');
  const rows = document.getElementById('home-editor-rows');
  const note = document.getElementById('home-layout-status');
  const defaults = () => ({version:1,widgets:ids.map(id => ({id,visible:true,size:'wide'})),source:'all',view:'recent'});
  function valid(value) {
    return value && value.version === 1 && Array.isArray(value.widgets) && value.widgets.length === ids.length
      && new Set(value.widgets.map(w => w?.id)).size === ids.length
      && value.widgets.every(w => w && ids.includes(w.id) && typeof w.visible === 'boolean' && ['wide','small'].includes(w.size))
      && ['all','files','pages'].includes(value.source) && ['recent','favorites'].includes(value.view);
  }
  // Canonicalize even valid input: unknown keys are never retained or re-saved.
  const clean = value => ({version:1,widgets:value.widgets.map(({id,visible,size}) => ({id,visible,size})),source:value.source,view:value.view});
  let saved = defaults();
  try {
    const raw = localStorage.getItem(key);
    if (raw) { const value = JSON.parse(raw); if (valid(value)) saved = clean(value); else note.textContent='저장된 구성을 읽을 수 없어 기본 홈을 표시합니다.'; }
  } catch (_) { note.textContent='브라우저 저장소를 사용할 수 없어 기본 홈을 표시합니다.'; }
  const empty = el('p','표시할 위젯이 없습니다. 홈 구성하기에서 필요한 위젯을 추가하세요.');
  empty.className = 'notice'; host.before(empty);
  let draft;
  function apply() {
    for (const widget of saved.widgets) {
      const node = nodes[widget.id];
      host.append(node); node.hidden = !widget.visible;
      node.classList.toggle('home-widget--wide',widget.size === 'wide');
      if (!widget.visible && widget.id === 'stories') node.open = false;
    }
    empty.hidden = saved.widgets.some(widget => widget.visible);
    nodes.recent.dataset.startSource = saved.source;
    nodes.recent.dataset.startDefault = saved.view;
    nodes.recent.dispatchEvent(new Event('home-layout-change'));
  }
  function el(tag,text) {const node=document.createElement(tag);if(text)node.textContent=text;return node;}
  function select(label,choices,value,change) {
    const field=el('label');field.className='field';field.append(el('span',label));
    const input=el('select');input.className='input';input.setAttribute('aria-label',label);
    for(const [id,name] of choices){const option=el('option',name);option.value=id;input.append(option);}
    input.value=value;input.addEventListener('change',()=>change(input.value));field.append(input);return field;
  }
  function draw(focusId) {
    rows.replaceChildren();
    draft.widgets.forEach((widget,index) => {
      const row=el('fieldset');row.className='panel';row.append(el('legend',names[widget.id]));
      const label=el('label');label.className='field';const checkbox=el('input');checkbox.type='checkbox';checkbox.checked=widget.visible;checkbox.dataset.homeToggle=widget.id;
      checkbox.addEventListener('change',()=>widget.visible=checkbox.checked);label.append(checkbox,el('span','홈에 표시하기'));row.append(label);
      row.append(select(names[widget.id]+' 카드 폭',[['wide','넓게'],['small','작게']],widget.size,value=>widget.size=value));
      const controls=el('div');controls.className='toolbar';
      for(const [direction,text] of [[-1,'위로'],[1,'아래로']]){
        const button=el('button',text);button.type='button';button.className='btn btn--quiet';button.setAttribute('aria-label',names[widget.id]+' '+text);button.disabled=index+direction<0||index+direction>=draft.widgets.length;
        button.addEventListener('click',()=>{[draft.widgets[index],draft.widgets[index+direction]]=[draft.widgets[index+direction],draft.widgets[index]];draw(widget.id);document.getElementById('home-editor-status').textContent=names[widget.id]+' 순서를 변경했습니다.';});controls.append(button);
      }
      row.append(controls);rows.append(row);
    });
    rows.append(select('최근 작업 자료 선택',[['all','자료와 문서'],['files','자료실 파일'],['pages','업무 문서']],draft.source,value=>draft.source=value));
    rows.append(select('처음 표시할 목록',[['recent','최근 작업'],['favorites','즐겨찾기']],draft.view,value=>draft.view=value));
    if(focusId)rows.querySelector('[data-home-toggle="'+focusId+'"]').focus();
  }
  for (const link of document.querySelectorAll('a[href="#services"]')) link.addEventListener('click',() => {
    if (nodes.services.hidden) { nodes.services.hidden = false; empty.hidden = true; note.textContent = '서비스 바로가기를 잠시 표시합니다. 저장한 홈 구성은 유지됩니다.'; }
    const heading = document.getElementById('moa-choose-title'); heading.tabIndex = -1; heading.focus({preventScroll:true});
  });
  document.getElementById('home-edit').addEventListener('click',()=>{draft=clean(saved);draw();document.getElementById('home-editor-status').textContent='';dialog.showModal();});
  document.getElementById('home-editor-close').addEventListener('click',()=>dialog.close());
  document.getElementById('home-reset').addEventListener('click',()=>{draft=defaults();draw();document.getElementById('home-editor-status').textContent='기본 구성을 선택했습니다. 저장하면 적용됩니다.';});
  document.getElementById('home-editor-form').addEventListener('submit',event=>{
    event.preventDefault();saved=clean(draft);
    try {localStorage.setItem(key,JSON.stringify(saved));note.textContent='홈 구성을 이 브라우저에 저장했습니다.';}
    catch (_){note.textContent='이 화면에는 적용했지만 저장하지 못했습니다. 브라우저 저장소 설정을 확인하세요.';}
    apply();dialog.close();
  });
  dialog.addEventListener('close',()=>document.getElementById('home-edit').focus());
  apply();
})();
