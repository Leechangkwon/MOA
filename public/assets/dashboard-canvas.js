/* Shared freeform geometry. Only validated numeric values enter same-origin CSSOM rules. */
(() => {
 'use strict';
 const clone=value=>structuredClone(value), modes=['desktop','mobile']; let serial=0;
 const shortcutIds=['spaces','work','tasks','library','story','messages','assistant','account'],hasShortcuts=v=>Object.hasOwn(v,'shortcuts');
 const safeId=id=>typeof id==='string'&&/^[a-z][a-z0-9_-]{0,63}$/.test(id);
 const v3Types=['my_tasks','briefing','recent','favorites','space_notice','space_event','space_task','space_file','space_story','legacy_recent','legacy_services','legacy_tools','legacy_stories'];
 const exact=(v,keys)=>v&&typeof v==='object'&&!Array.isArray(v)&&Object.keys(v).length===keys.length&&Object.keys(v).every(k=>keys.includes(k));
 const geometry=(g,m,v3=false)=>exact(g,v3?['x','y','w','h','size']:['x','y','w','h'])&&['x','y','w','h'].every(k=>Number.isInteger(g[k]))&&g.x>=0&&g.y>=0&&g.w>=(m==='mobile'?160:240)&&g.w<=width(m)&&g.h>=160&&g.h<=2000&&g.x<=width(m)-g.w&&g.y<=20000-g.h&&(!v3||['s','m','l'].includes(g.size));
 const mode=()=>window.innerWidth<760?'mobile':'desktop', width=m=>m==='mobile'?360:1200;
 function valid(value,ids=[],context={}){
  if(!value||![1,2,3].includes(value.version)||!Array.isArray(value.widgets)||!['all','files','pages'].includes(value.source)||!['recent','favorites'].includes(value.view))return false;
  if(value.version!==3&&hasShortcuts(value))return false;
  if(value.version===3){
   if(hasShortcuts(value)&&(context.spaceId||context.personal===false||!Array.isArray(value.shortcuts)||value.shortcuts.length>8||new Set(value.shortcuts).size!==value.shortcuts.length||!Array.from(value.shortcuts).every(id=>typeof id==='string'&&shortcutIds.includes(id))))return false;
   if(!exact(value,hasShortcuts(value)?['version','widgets','source','view','guide','shortcuts']:['version','widgets','source','view','guide'])||!['pending','later','done'].includes(value.guide)||value.widgets.length>20||new Set(value.widgets.map(w=>w?.id)).size!==value.widgets.length)return false;
   return value.widgets.every(w=>exact(w,['id','type','source','visible','locked','desktop','mobile'])&&safeId(w.id)&&v3Types.includes(w.type)&&typeof w.visible==='boolean'&&typeof w.locked==='boolean'&&validSource(w.type,w.source)&&modes.every(m=>geometry(w[m],m,true)));
  }
  return value.widgets.length===ids.length&&new Set(value.widgets.map(w=>w?.id)).size===ids.length&&value.widgets.every(w=>w&&ids.includes(w.id)&&typeof w.visible==='boolean'&&(value.version===1?['wide','small'].includes(w.size):modes.every(m=>geometry(w[m],m))));
 }
 function validSource(type,source){
  if(type.startsWith('space_'))return exact(source,['space_id','scope'])&&typeof source.space_id==='string'&&/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(source.space_id)&&['all','mine'].includes(source.scope)&&(source.scope!=='mine'||type==='space_task');
  return ['recent','favorites'].includes(type)?exact(source,['kind'])&&['all','files','pages'].includes(source.kind):exact(source,[]);
 }
 function clean(value){
  const copyGeometry=(g,v3)=>({x:g.x,y:g.y,w:g.w,h:g.h,...(v3?{size:g.size}:{})});
  const result={version:value.version,widgets:value.widgets.map(w=>value.version===1?{id:w.id,visible:w.visible,size:w.size}:{id:w.id,...(value.version===3?{type:w.type,source:{...w.source},locked:w.locked}:{}),visible:w.visible,desktop:copyGeometry(w.desktop,value.version===3),mobile:copyGeometry(w.mobile,value.version===3)}),source:value.source||'all',view:value.view||'recent'};
  if(value.version===3){result.guide=value.guide;if(hasShortcuts(value))result.shortcuts=[...value.shortcuts];}return result;
 }
 function convert(value,nodes){if(value.version!==1)return clean(value);const result={version:2,widgets:[],source:value.source||'all',view:value.view||'recent'};let desktopY=0,mobileY=0;for(const w of value.widgets){const measured=Math.ceil(nodes[w.id]?.getBoundingClientRect().height||320),h=Math.min(2000,Math.max(200,measured));const row={id:w.id,visible:w.visible,desktop:{x:0,y:desktopY,w:w.size==='small'?588:1200,h},mobile:{x:0,y:mobileY,w:360,h:Math.max(240,h)}};result.widgets.push(row);if(w.visible){desktopY+=h+16;mobileY+=row.mobile.h+16;}}return result;}
 function upgrade(value,nodes,context={}){
  if(value.version===3){if(!valid(value,[],context))throw Error('위젯 설정을 확인해 주세요.');return clean(value);}const result=convert(value,nodes);result.version=3;result.guide='done';
  for(const w of result.widgets){w.type=context.spaceId?'space_'+w.id:'legacy_'+w.id;w.source=context.spaceId?{space_id:context.spaceId,scope:'all'}:{};w.locked=false;for(const m of modes)w[m].size=w[m].w>=600&&w[m].h>=400?'l':w[m].h>=280?'m':'s';}return result;
 }
 function intersects(a,b){return a.x<b.x+b.w&&a.x+a.w>b.x&&a.y<b.y+b.h&&a.y+a.h>b.y;}
 // Stable downward placement never compacts a user's intentional whitespace.
 function resolve(layout,m,active){
  const widgets=layout.widgets.filter(w=>w.visible),locked=widgets.filter(w=>w.locked),placed=[...locked];
  for(let i=0;i<locked.length;i++)for(let j=i+1;j<locked.length;j++)if(intersects(locked[i][m],locked[j][m]))return false;
  const first=widgets.find(w=>w.id===active);
  if(first&&!first.locked){if(placed.some(w=>intersects(first[m],w[m])))return false;placed.push(first);}
  const rest=widgets.filter(w=>!w.locked&&w.id!==active).sort((a,b)=>a[m].y-b[m].y);
  for(const widget of rest){const g=widget[m];for(let n=0;n<=widgets.length;n++){const hit=placed.filter(p=>intersects(g,p[m]));if(!hit.length)break;g.y=Math.max(...hit.map(p=>p[m].y+p[m].h))+16;}if(g.y+g.h>20000)return false;placed.push(widget);}return true;
 }
 function el(tag,text,className){const n=document.createElement(tag);if(text)n.textContent=text;if(className)n.className=className;return n;}
 class LegacyCanvas{
  constructor(host,nodes,names,options={}){this.host=host;this.nodes=nodes;this.names=names;this.options=options;this.id=++serial;this.wrappers={};this.temporary=new Set();this.editing=false;this.busy=false;this.dead=false;this.rules=[];this.original=[...host.children];this.sheet=[...document.styleSheets].find(s=>s.href&&new URL(s.href).pathname.endsWith('/dashboard-canvas.css'));if(!this.sheet)throw Error('대시보드 스타일을 불러오지 못했습니다.');host.dataset.dashboardCanvas=String(this.id);this.rootRule=this.rule('[data-dashboard-canvas="'+this.id+'"]');this.resize=()=>{if(this.gesture)this.cancelGesture();if(this.layout)this.paint();};window.addEventListener('resize',this.resize);this.escape=e=>{if(e.key==='Escape'&&this.editing&&!this.busy&&!document.querySelector('dialog[open]')){e.preventDefault();this.cancel();}};document.addEventListener('keydown',this.escape);}
  rule(selector){const at=this.sheet.cssRules.length;this.sheet.insertRule(selector+' {}',at);const rule=this.sheet.cssRules[at];this.rules.push(rule);return rule.style;}
  mount(value){this.layout=convert(value,this.nodes);this.fallback=false;this.host.classList.add('dashboard-canvas');for(const w of this.layout.widgets){const node=this.nodes[w.id];if(!node)continue;const wrapper=el('div',undefined,'dashboard-card');wrapper.dataset.dashboardWidget=w.id;const chrome=el('div',undefined,'dashboard-card-chrome');const move=this.button(this.names[w.id]+' 이동',()=>{});move.className='btn btn--quiet dashboard-move';move.dataset.dashboardMove=w.id;move.setAttribute('aria-label',this.names[w.id]+' 이동: 방향키, Shift로 빠르게');const hide=this.button('숨기기',()=>{if(this.busy)return;this.layout.widgets.find(v=>v.id===w.id).visible=false;this.paint();this.announce(this.names[w.id]+' 위젯을 숨겼습니다. 위젯 추가로 복원할 수 있습니다.');});hide.setAttribute('aria-label',this.names[w.id]+' 숨기기');chrome.append(move,hide);const body=el('div',undefined,'dashboard-card-body');body.append(node);const resize=this.button('↘',()=>{});resize.className='btn btn--quiet dashboard-resize';resize.dataset.dashboardResize=w.id;resize.setAttribute('aria-label',this.names[w.id]+' 크기 조절: 방향키, Shift로 빠르게');wrapper.append(chrome,body,resize);this.host.append(wrapper);this.wrappers[w.id]={wrapper,node,move,resize,style:this.rule('[data-dashboard-canvas="'+this.id+'"] [data-dashboard-widget="'+w.id+'"]')};for(const [handle,kind]of [[move,'move'],[resize,'resize']]){handle.addEventListener('pointerdown',e=>this.start(e,w.id,kind));handle.addEventListener('pointermove',e=>this.move(e));handle.addEventListener('pointerup',e=>this.end(e));handle.addEventListener('pointercancel',()=>this.cancelGesture());handle.addEventListener('keydown',e=>this.key(e,w.id,kind));}}this.paint();if(this.fallback)this.options.notice?.('저장된 위젯의 겹침을 풀 수 없어 읽기 쉬운 세로 배치로 표시합니다. 기본 배치를 선택해 다시 저장할 수 있습니다.');}
  button(text,action){const b=el('button',text,'btn btn--quiet');b.type='button';b.addEventListener('click',action);return b;}
  revealTemporary(id){this.temporary.add(id);this.paint();}
  begin(value){if(this.editing||this.dead)return;this.temporary.clear();this.saved=clean(value);if(!this.layout)this.mount(value);this.editing=true;this.host.classList.add('dashboard-editing');this.toolbar=el('section',undefined,'panel dashboard-editor');this.toolbar.setAttribute('aria-label',this.options.title||'대시보드 편집');this.toolbar.append(el('h2',this.options.title||'대시보드 편집'),el('p','손잡이를 끌어 이동하고 오른쪽 아래를 끌어 가로·세로 크기를 조절하세요. 손잡이에서 방향키를 사용해도 됩니다.','help'));this.controls=el('div',undefined,'toolbar');this.add=el('select',undefined,'input dashboard-add');this.add.setAttribute('aria-label','위젯 추가');this.add.addEventListener('change',()=>{const w=this.layout.widgets.find(w=>w.id===this.add.value);if(!w||this.busy)return;const next=clone(this.layout);next.widgets.find(v=>v.id===w.id).visible=true;if(!resolve(next,mode(),w.id)){this.announce('위젯을 배치할 공간이 부족합니다. 기본 배치를 선택해 주세요.');return;}this.layout=next;this.paint();this.wrappers[w.id].move.focus();this.announce(this.names[w.id]+' 위젯을 복원했습니다.');});this.controls.append(this.add,this.button('기본 배치',()=>{if(this.busy)return;const currentMode=mode(),defaults=convert({version:1,widgets:this.layout.widgets.map(w=>({id:w.id,visible:true,size:'wide'})),source:this.layout.source,view:this.layout.view},{});for(const w of this.layout.widgets){w.visible=true;w[currentMode]=defaults.widgets.find(d=>d.id===w.id)[currentMode];}this.fallback=false;this.paint();this.announce('현재 화면의 기본 배치를 선택했습니다. 저장하면 적용됩니다.');}),this.button('취소',()=>this.cancel()),this.button('저장',()=>this.save()));this.toolbar.append(this.controls);this.extra=el('div',undefined,'dashboard-editor-extra');this.toolbar.append(this.extra);this.status=el('p',undefined,'help');this.status.setAttribute('role','status');this.toolbar.append(this.status);this.host.before(this.toolbar);this.toolbar.id='dashboard-editor-'+this.id;this.back=el('a','편집 도구로 돌아가기 ↑','dashboard-back');this.back.href='#'+this.toolbar.id;this.host.after(this.back);this.options.extra?.(this.extra,this.layout);this.options.editChange?.(true);this.paint();this.wrappers[this.layout.widgets.find(w=>w.visible)?.id]?.move.focus();}
  announce(text){if(this.status)this.status.textContent=text;}
  paint(){const m=mode(),logical=width(m),display=clone(this.layout);for(const w of display.widgets)if(this.temporary.has(w.id))w.visible=true;this.fallback=!resolve(display,m);this.visualLayout=display;let bottom=160;this.host.classList.toggle('dashboard-flow',this.fallback);for(const w of this.layout.widgets){const item=this.wrappers[w.id];if(!item)continue;const visible=this.visualLayout.widgets.find(v=>v.id===w.id).visible;item.wrapper.hidden=!visible;item.node.hidden=!visible;item.wrapper.classList.toggle('dashboard-card-editing',this.editing);const g=this.visualLayout.widgets.find(v=>v.id===w.id)[m];item.style.setProperty('left',g.x/logical*100+'%');item.style.setProperty('top',g.y+'px');item.style.setProperty('width',g.w/logical*100+'%');item.style.setProperty('height',g.h+'px');if(visible)bottom=Math.max(bottom,g.y+g.h);item.move.disabled=this.busy;item.resize.disabled=this.busy;}this.rootRule.setProperty('height',this.fallback?'auto':bottom+'px');if(this.add){this.add.replaceChildren(el('option','위젯 추가'));this.add.firstChild.value='';for(const w of this.layout.widgets.filter(w=>!w.visible)){const option=el('option',this.names[w.id]);option.value=w.id;this.add.append(option);}this.add.disabled=this.busy||this.add.options.length===1;}this.options.changed?.(this.layout);}
  baseForGesture(){const base=clone(this.layout),m=mode();for(const w of base.widgets)w[m]={...this.visualLayout.widgets.find(v=>v.id===w.id)[m]};return base;}
  start(event,id,kind){if(!this.editing||this.busy||event.button!==0||this.fallback)return;event.preventDefault();this.gesture={id,kind,pointer:event.pointerId,handle:event.currentTarget,startX:event.clientX,startY:event.clientY+window.scrollY,base:this.baseForGesture(),original:clone(this.layout),mode:mode(),scale:width(mode())/this.host.getBoundingClientRect().width};event.currentTarget.setPointerCapture(event.pointerId);}
  adjust(id,kind,dx,dy,base,m){const next=clone(base),w=next.widgets.find(v=>v.id===id),g=w[m];if(kind==='move'){g.x=Math.max(0,Math.min(width(m)-g.w,Math.round(g.x+dx)));g.y=Math.max(0,Math.min(20000-g.h,Math.round(g.y+dy)));}else{g.w=Math.max(m==='mobile'?160:240,Math.min(width(m)-g.x,Math.round(g.w+dx)));g.h=Math.max(160,Math.min(2000,20000-g.y,Math.round(g.h+dy)));}if(!resolve(next,m,id)){this.layout=clone(base);this.paint();this.announce('더 아래로 배치할 공간이 없어 변경을 되돌렸습니다.');return false;}this.layout=next;this.paint();this.announce(this.names[id]+' 위치 '+g.x+', '+g.y+' / 크기 '+g.w+' × '+g.h);return true;}
  move(e){const g=this.gesture;if(!g||g.pointer!==e.pointerId)return;if(mode()!==g.mode){this.cancelGesture();return;}this.adjust(g.id,g.kind,(e.clientX-g.startX)*g.scale,e.clientY+window.scrollY-g.startY,g.base,g.mode);}
  end(e){if(this.gesture?.pointer!==e.pointerId)return;if(mode()!==this.gesture.mode){this.cancelGesture();return;}this.gesture=null;}
  cancelGesture(){if(!this.gesture)return;const g=this.gesture;this.gesture=null;this.layout=g.original;if(g.handle.hasPointerCapture(g.pointer))g.handle.releasePointerCapture(g.pointer);this.paint();this.announce('진행 중인 조작을 취소했습니다.');}
  key(e,id,kind){if(!this.editing||this.busy||!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(e.key))return;e.preventDefault();const step=e.shiftKey?50:10;this.adjust(id,kind,e.key==='ArrowRight'?step:e.key==='ArrowLeft'?-step:0,e.key==='ArrowDown'?step:e.key==='ArrowUp'?-step:0,this.baseForGesture(),mode());}
  async save(){if(!this.editing||this.busy)return;this.cancelGesture();this.busy=true;for(const n of this.toolbar.querySelectorAll('button,select,input'))n.disabled=true;this.paint();try{const next=clean(this.layout);await this.options.save(next);if(this.dead)return;this.editing=false;this.toolbar.remove();this.back?.remove();this.toolbar=null;this.host.classList.remove('dashboard-editing');this.options.editChange?.(false);this.paint();this.options.saved?.(next);}catch(error){if(!this.dead)this.announce(error.message||'배치를 저장하지 못했습니다. 초안은 유지됩니다.');}finally{this.busy=false;if(!this.dead){for(const n of this.toolbar?.querySelectorAll('button,select,input')||[])n.disabled=false;this.paint();}}}
  cancel(){if(!this.editing||this.busy)return;this.cancelGesture();const saved=this.saved;this.destroy();this.options.cancel?.(saved);}
  destroy(){if(this.dead)return;this.cancelGesture();this.dead=true;window.removeEventListener('resize',this.resize);document.removeEventListener('keydown',this.escape);this.toolbar?.remove();this.back?.remove();for(const node of this.original)this.host.append(node);for(const item of Object.values(this.wrappers))item.wrapper.remove();this.host.classList.remove('dashboard-canvas','dashboard-editing','dashboard-flow');delete this.host.dataset.dashboardCanvas;for(let i=this.sheet.cssRules.length-1;i>=0;i--)if(this.rules.includes(this.sheet.cssRules[i]))this.sheet.deleteRule(i);this.options.editChange?.(false);}
 }
 class Canvas extends LegacyCanvas {
  name(id){return this.names[id]||this.layout?.widgets.find(w=>w.id===id)?.type||'위젯';}
  mount(value){
   if(value.version===3&&!valid(value,[],this.options.context||{}))throw Error('위젯 설정을 확인해 주세요.');if(value.widgets.some(w=>!safeId(w.id)))throw Error('위젯 식별자를 확인해 주세요.');
   super.mount(value);for(const w of this.layout.widgets)this.prepare(w);
  }
  prepare(w){
   const item=this.wrappers[w.id];if(!item||item.prepared)return;item.prepared=true;
   item.move.textContent='이동';item.wrapper.addEventListener('pointerdown',()=>this.select(w.id));item.move.addEventListener('focus',()=>this.select(w.id));
   const old=item.wrapper.querySelector('.dashboard-card-chrome button:nth-child(2)'),settings=this.button('설정',()=>{this.select(w.id);this.openInspector();});settings.className='btn btn--quiet dashboard-card-settings';settings.dataset.dashboardSettings=w.id;settings.setAttribute('aria-label',this.name(w.id)+' 위젯 설정');old.replaceWith(settings);item.settings=settings;
  }
  current(id){return this.layout.widgets.find(w=>w.id===id);}
  select(id){this.selected=id;for(const [key,item]of Object.entries(this.wrappers))item.wrapper.classList.toggle('dashboard-selected',key===id);this.updateTools();}
  begin(value){
   if(this.editing||this.dead)return;if(!this.layout)this.mount(value);this.layout=upgrade(this.layout,this.nodes,this.options.context||{});super.begin(value);
   this.toolbar.classList.add('dashboard-editor--top');this.controls.querySelector('button:last-child').textContent='배치 저장·완료';this.controls.querySelector('button:last-child').classList.add('dashboard-save');
   this.workspace=el('div',undefined,'dashboard-workspace');this.host.before(this.workspace);this.workspace.append(this.host);
   this.inspector=el('aside',undefined,'dashboard-inspector');this.inspector.setAttribute('aria-label','위젯 설정');this.workspace.append(this.inspector);
   this.inspectorContent=el('div',undefined,'dashboard-inspector-content');this.inspector.append(this.inspectorContent);const heading=el('div',undefined,'dashboard-inspector-heading');heading.append(el('h2','위젯 설정'));const close=this.button('닫기',()=>this.closeInspector());close.className='btn btn--quiet dashboard-inspector-close';heading.append(close);this.inspectorContent.append(heading);if(this.options.homeSettings&&this.options.context?.personal===true){this.homeSettings=el('section',undefined,'dashboard-home-settings');this.inspectorContent.append(this.homeSettings);this.options.homeSettings(this.homeSettings,this.layout,this);}
   this.selection=el('section',undefined,'dashboard-selection');this.selection.setAttribute('aria-label','선택한 위젯 크기와 잠금');this.inspectorContent.append(this.selection);
   this.batch=el('details',undefined,'dashboard-batch');this.batch.append(el('summary','배치 옵션'));const batchBody=el('div',undefined,'dashboard-batch-body');this.batch.append(batchBody);batchBody.append(this.add);this.add.addEventListener('change',()=>{if(this.busy)return;const id=this.selectedRestore;if(id&&this.current(id)?.visible)this.select(id);this.selectedRestore=null;});this.add.addEventListener('change',()=>{this.selectedRestore=this.add.value;},true);for(const b of [...this.controls.querySelectorAll('button')])if(b.textContent==='기본 배치'){b.addEventListener('click',e=>{e.stopImmediatePropagation();this.reset();},true);batchBody.append(b);}batchBody.append(this.extra);this.inspectorContent.append(this.batch);
   this.settingsOpener=this.button('위젯 설정',()=>this.openInspector());this.settingsOpener.className='btn btn--quiet dashboard-open-inspector';this.controls.prepend(this.settingsOpener);
   if(this.options.requestAdd){const add=this.button('새 위젯 추가',()=>{if(!this.busy){this.closeInspector(false);this.options.requestAdd(this);}});this.controls.prepend(add);}
   this.drawer=el('dialog',undefined,'dashboard-inspector-dialog');this.drawer.setAttribute('aria-label','위젯 설정');document.body.append(this.drawer);this.drawer.addEventListener('cancel',e=>{e.preventDefault();if(!this.busy)this.closeInspector();});this.drawer.addEventListener('close',()=>{document.body.classList.toggle('dashboard-inspector-open',Boolean(document.querySelector('.dashboard-inspector-dialog[open]')));if(this.inspectorContent?.parentNode===this.drawer)this.inspector.append(this.inspectorContent);});this.drawer.addEventListener('click',e=>{if(e.target===this.drawer&&!this.busy){const r=this.drawer.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)this.closeInspector();}});
   this.inspectorResize=()=>{if(window.innerWidth>=1100&&this.drawer?.open)this.closeInspector(false);};window.addEventListener('resize',this.inspectorResize);
   this.select(this.layout.widgets.find(w=>w.visible)?.id);this.paint();
  }
  refreshSelectedActions(){if(this.dead||!this.editing)return;this.toolsKey=null;this.updateTools();this.syncInspectorBusy();}
  openInspector(){if(!this.editing||this.busy)return;if(window.innerWidth>=1100){this.selection.querySelector('button')?.focus();return;}if(this.drawer.open)return;this.cancelGesture();this.inspectorOpener=document.activeElement;this.drawer.append(this.inspectorContent);this.drawer.showModal();document.body.classList.add('dashboard-inspector-open');this.inspectorContent.querySelector('button')?.focus();}
  closeInspector(restore=true){if(!this.drawer?.open)return;this.drawer.close();document.body.classList.toggle('dashboard-inspector-open',Boolean(document.querySelector('.dashboard-inspector-dialog[open]')));this.inspector.append(this.inspectorContent);if(restore){const item=this.wrappers[this.selected],target=[item?.settings,this.inspectorOpener,this.settingsOpener].find(n=>n?.isConnected&&!n.disabled&&n.tabIndex>=0&&n.checkVisibility());target?.focus();}}
  teardownEditor(){window.removeEventListener('resize',this.inspectorResize);if(this.drawer?.open)this.drawer.close();this.drawer?.remove();document.body.classList.toggle('dashboard-inspector-open',Boolean(document.querySelector('.dashboard-inspector-dialog[open]')));this.selection=null;this.toolsKey=null;this.inspector?.remove();if(this.workspace?.isConnected){this.workspace.before(this.host);this.workspace.remove();}this.workspace=null;this.inspector=null;this.drawer=null;}
  async save(){await super.save();if(!this.editing&&!this.dead){this.teardownEditor();this.paint();}}
  destroy(){this.teardownEditor();super.destroy();}
  updateTools(){
   if(!this.selection||!this.editing)return;const w=this.current(this.selected),key=JSON.stringify([w?.id,w?.locked,w?.[mode()].size,w?.type,w?.source,this.busy,mode()]);if(key===this.toolsKey)return;this.toolsKey=key;
   const preserve=this.selection.contains(document.activeElement)?document.activeElement.dataset.canvasAction:null,moreOpen=this.selection.querySelector('details')?.open;this.selection.replaceChildren();if(!w){this.selection.append(el('p','위젯을 선택하거나 추가해 주세요.','help'));return;}
   this.selection.append(el('h3',this.name(w.id)),el('p','소·중·대는 표시할 내용과 카드 크기를 함께 바꿉니다.','help'));const segment=el('div',undefined,'dashboard-size-segment');segment.setAttribute('role','group');segment.setAttribute('aria-label','위젯 크기');for(const [size,label]of [['s','소'],['m','중'],['l','대']]){const b=this.button(label,()=>this.setSize(w.id,size));b.dataset.canvasAction='size-'+size;b.disabled=this.busy||w.locked;b.setAttribute('aria-label',this.name(w.id)+' '+label+' 크기');b.setAttribute('aria-pressed',String(w[mode()].size===size));segment.append(b);}this.selection.append(segment);
   const lock=this.button(w.locked?'잠금 해제':'위치·크기 잠금',()=>{if(this.busy)return;this.toggleLock(w.id);this.announce(this.current(w.id).locked?'위젯의 위치와 크기를 잠갔습니다.':'위젯 잠금을 해제했습니다.');});lock.dataset.canvasAction='lock';lock.disabled=this.busy;lock.setAttribute('aria-pressed',String(w.locked));this.selection.append(lock);
   const actions=el('div',undefined,'dashboard-selected-actions dashboard-inspector-domain');this.selection.append(actions);this.options.selectedActions?.(actions,w,this);if(this.busy)for(const n of actions.querySelectorAll('button,input,select'))n.disabled=true;
   const more=el('details',undefined,'dashboard-more');more.open=Boolean(moreOpen);more.append(el('summary','더보기'));const hide=this.button('숨기기',()=>{if(this.busy||this.current(w.id).locked)return;this.current(w.id).visible=false;this.select(this.layout.widgets.find(v=>v.visible)?.id);this.paint();});hide.disabled=this.busy||w.locked;const resize=this.button('직접 크기 조절',()=>{if(this.busy||this.current(w.id).locked)return;this.closeInspector(false);this.wrappers[w.id].resize.focus();this.wrappers[w.id].resize.scrollIntoView({block:'center'});this.announce('오른쪽 아래 손잡이를 끌거나 방향키로 크기를 조절하세요.');});resize.disabled=this.busy||w.locked;more.append(hide,resize);this.selection.append(more);if(preserve)this.selection.querySelector('[data-canvas-action="'+preserve+'"]')?.focus();
  }
  toggleLock(id){
   if(this.busy)return false;const next=clone(this.layout),w=next.widgets.find(v=>v.id===id);if(!w)return false;
   if(!w.locked){for(const m of modes){const display=clone(next);if(!resolve(display,m)){this.announce('잠글 위치를 먼저 배치해 주세요.');return false;}w[m]={...display.widgets.find(v=>v.id===id)[m]};}}w.locked=!w.locked;this.layout=next;this.paint();return true;
  }
  setSize(id,size){
   const w=this.current(id);if(!w||w.locked||this.busy||!['s','m','l'].includes(size))return false;
   const m=mode(),scale=width(m)/this.host.getBoundingClientRect().width,next=clone(this.layout),g=next.widgets.find(v=>v.id===id)[m];
   const desired={s:[300,220],m:[480,420],l:[720,640]}[size];g.w=m==='mobile'?width(m):Math.max(240,Math.min(width(m)-g.x,Math.round(desired[0]*scale)));g.x=Math.min(g.x,width(m)-g.w);g.h=Math.min(desired[1],20000-g.y);g.size=size;
   if(!resolve(next,m,id)){this.announce('잠긴 위젯이나 화면 경계 때문에 크기를 바꿀 수 없습니다.');return false;}this.layout=next;this.paint();this.announce(this.name(id)+' '+{s:'소',m:'중',l:'대'}[size]+' 크기로 바꿨습니다.');return true;
  }
  reset(){
   if(this.busy)return;const m=mode(),next=clone(this.layout);let y=0;for(const w of next.widgets){if(w.locked)continue;w.visible=true;w[m]={x:0,y,w:width(m),h:420,size:'m'};y+=436;}
   if(!resolve(next,m)){this.announce('잠긴 위치를 보존하면서 기본 배치를 만들 공간이 없습니다.');return;}this.layout=next;this.paint();this.announce('잠긴 위젯을 유지하고 현재 화면의 기본 배치를 선택했습니다.');
  }
  paint(){
   if(!this.layout)return;super.paint();for(const w of this.layout.widgets){const item=this.wrappers[w.id];if(!item)continue;
    item.move.disabled=this.busy||Boolean(w.locked);item.resize.disabled=this.busy||Boolean(w.locked);item.wrapper.classList.toggle('dashboard-locked',Boolean(w.locked));item.wrapper.dataset.dashboardSize=w[mode()].size||'';
    const hiddenButton=item.wrapper.querySelector('.dashboard-card-chrome button:nth-child(2)');if(hiddenButton)hiddenButton.disabled=this.busy;
    if(!w.visible)item.sizeKey=null;
    if(w.visible&&this.layout.version===3){const g=w[mode()],actualWidth=g.w/width(mode())*this.host.getBoundingClientRect().width;let size=g.size;if(size==='l'&&(actualWidth<300||g.h<300))size='m';if(size==='m'&&(actualWidth<220||g.h<220))size='s';const key=mode()+':'+size+':'+w.type+':'+JSON.stringify(w.source);if(item.sizeKey!==key){item.sizeKey=key;this.options.onSize?.(w,size,mode(),item.node);}}
   }this.updateTools();this.syncInspectorBusy();
  }
  syncInspectorBusy(){if(!this.inspectorContent)return;this.disabledBeforeBusy??=new WeakMap();for(const n of this.inspectorContent.querySelectorAll('button,input,select,textarea')){if(this.busy){if(!this.disabledBeforeBusy.has(n))this.disabledBeforeBusy.set(n,n.disabled);n.disabled=true;}else if(this.disabledBeforeBusy.has(n)){n.disabled=this.disabledBeforeBusy.get(n);this.disabledBeforeBusy.delete(n);}}}
  start(event,id,kind){if(this.current(id)?.locked)return;this.select(id);super.start(event,id,kind);}
  key(event,id,kind){if(this.current(id)?.locked)return;this.select(id);super.key(event,id,kind);}
  adjust(id,kind,dx,dy,base,m){
   if(base.widgets.find(w=>w.id===id)?.locked)return false;const original=clone(this.layout);if(!super.adjust(id,kind,dx,dy,base,m)){this.layout=clone(this.gesture?.original||original);this.paint();return false;}
   const w=this.current(id);if(kind==='resize'&&this.layout.version===3){const g=w[m],actual=g.w/width(m)*this.host.getBoundingClientRect().width;const old=g.size;g.size=actual>=640+(old==='l'?-20:20)&&g.h>=400+(old==='l'?-20:20)?'l':actual>=380+(old==='m'?-20:20)&&g.h>=280+(old==='m'?-20:20)?'m':'s';this.paint();}return true;
  }
  removeWidget(id){
   const w=this.current(id);if(!this.editing||this.busy||!w||w.locked)return false;
   this.layout.widgets=this.layout.widgets.filter(w=>w.id!==id);const item=this.wrappers[id];item?.wrapper.remove();delete this.wrappers[id];delete this.nodes[id];delete this.names[id];this.temporary.delete(id);if(this.selected===id)this.select(this.layout.widgets.find(w=>w.visible)?.id);this.paint();return true;
  }
  addWidget(widget,node,name){
   if(!this.editing||this.busy||!node||this.layout.widgets.length>=20||this.current(widget.id))return false;
   const next=clone(this.layout);next.widgets.push(clone(widget));if(!valid(next)||!resolve(next,mode(),widget.id)){this.announce('위젯 설정이나 배치할 공간을 확인해 주세요.');return false;}
   this.layout=next;this.nodes[widget.id]=node;this.names[widget.id]=name||widget.type;
   const savedOriginal=this.original;
   // Mount a single card through the same safe DOM builder without disturbing existing instances.
   const full=this.layout;this.layout={...full,widgets:[widget]};const value=clean(this.layout);super.mount(value);this.layout=full;this.original=savedOriginal;this.prepare(widget);this.select(widget.id);this.paint();return true;
  }
 }
 window.MoaDashboardCanvas={Canvas,valid,clean,convert,upgrade};
})();
