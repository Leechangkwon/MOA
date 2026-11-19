/** Root-client review: real extracted handlers, deterministic DOM/XHR and buffered performance records. */
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const app=await readFile(new URL('../public/assets/app.js',import.meta.url),'utf8');
const files=await readFile(new URL('../public/assets/files-page.js',import.meta.url),'utf8');
const diagnostics=await readFile(new URL('../public/assets/performance-diagnostics.js',import.meta.url),'utf8');
const messenger=await readFile(new URL('../public/assets/messenger.js',import.meta.url),'utf8');
let checks=0;
function check(value,label) { assert.ok(value,label); checks++; console.log('PASS '+label); }
const turn=()=>new Promise(resolve=>setImmediate(resolve));
function deferred() { let resolve,reject; const promise=new Promise((yes,no)=>{resolve=yes;reject=no;}); return {promise,resolve,reject}; }
class Element {
  constructor(id='',tag='div') { this.id=id; this.tagName=tag.toUpperCase(); this.children=[]; this.attrs=new Map(); this.listeners=new Map(); this.dataset={}; this.style={}; this.value=''; this.hidden=false; this.disabled=false; this.textContent=''; this.isConnected=true; this.classList={add(){},remove(){},toggle(){},contains(){return false;}}; }
  get firstChild() { return this.children[0]||null; }
  get nextSibling() { const at=this.parentNode?.children.indexOf(this); return this.parentNode?.children[at+1]||null; }
  get parentElement() { return this.parentNode; }
  appendChild(node) { this.children.push(node); node.parentNode=this; return node; }
  append(...nodes) { nodes.forEach(node=>this.appendChild(node)); }
  insertBefore(node,before) { const at=before ? this.children.indexOf(before) : this.children.length; this.children.splice(at,0,node); node.parentNode=this; }
  removeChild(node) { this.children.splice(this.children.indexOf(node),1); node.parentNode=null; }
  replaceChildren(...nodes) { this.children=[]; this.append(...nodes); }
  remove() { this.parentNode?.removeChild(this); }
  setAttribute(name,value) { this.attrs.set(name,String(value)); }
  getAttribute(name) { return this.attrs.has(name) ? this.attrs.get(name) : null; }
  removeAttribute(name) { this.attrs.delete(name); }
  addEventListener(name,fn) { const list=this.listeners.get(name)||[]; list.push(fn); this.listeners.set(name,list); }
  removeEventListener(name,fn) { this.listeners.set(name,(this.listeners.get(name)||[]).filter(item=>item!==fn)); }
  emit(name,props={}) { const event={target:this,defaultPrevented:false,preventDefault(){this.prevented=true;this.defaultPrevented=true;},stopImmediatePropagation(){this.stopped=true;},...props}; for(const fn of this.listeners.get(name)||[]) { fn(event); } return event; }
  querySelector() { return null; }
  querySelectorAll() { return []; }
  closest() { return this; }
  focus() { this.focusCount=(this.focusCount||0)+1; }
  click() { this.emit('click'); }
  requestSubmit() { this.emit('submit'); }
  scrollIntoView() {}
  getClientRects() { return []; }
}
function fixture() {
  const nodes=new Map(), document=new Element('document'), requests=[], xhrs=[], globals=[], timers=new Map(); let nextTimer=1;
  document.body=new Element('body'); document.body.dataset={}; document.body.setAttribute('data-base','/tbox');
  document.documentElement=new Element('html'); document.visibilityState='visible'; document.readyState='loading';
  document.getElementById=id=>{
    if(id.startsWith('moa-field-error-')) { return null; }
    if(!nodes.has(id)) { nodes.set(id,new Element(id)); }
    return nodes.get(id);
  };
  document.createElement=tag=>new Element('',tag); document.createTextNode=text=>({textContent:text});
  class Xhr extends Element {
    constructor() { super(); this.upload=new Element(); this.headers={}; this.status=0; this.responseText=''; this.statusText=''; xhrs.push(this); }
    open(method,url) { this.method=method; this.url=url; }
    setRequestHeader(name,value) { this.headers[name]=value; }
    getAllResponseHeaders() { return 'Content-Type: application/json\r\nX-Review: synthetic\r\n'; }
    send(body) { this.body=body; }
    reply(status,data) { this.status=status; this.responseText=typeof data==='string' ? data : JSON.stringify(data); this.emit('load'); }
  }
  const location={pathname:'/tbox/',search:'',protocol:'http:',href:'http://localhost/tbox/',reload(){this.reloads=(this.reloads||0)+1;},assign(){},replace(){}};
  const context=vm.createContext({document,Headers,Response,Request,FormData,Blob,URL,URLSearchParams,TextEncoder,AbortController,console,
    setTimeout(fn) { const id=nextTimer++; timers.set(id,fn); return id; },clearTimeout(id){timers.delete(id);},setInterval:()=>1,clearInterval(){},XMLHttpRequest:Xhr,location,
    navigator:{onLine:true},performance:{mark(){},now:()=>1,getEntriesByType:()=>[]},history:{replaceState(){}},
    matchMedia:()=>({matches:false,addEventListener(){}}),addEventListener(){},removeEventListener(){},
    fetch(url,options) { requests.push({url,options}); return new Promise(()=>{}); },__import:()=>Promise.reject(new Error('Unconfigured import'))});
  context.window=context;
  vm.runInContext(app.replace(/\bimport\(/g,'__import('),context);
  vm.runInContext("fetchMe=function(){return Promise.resolve({status:200,me:{username:'synthetic',csrf_token:'old'}});};",context);
  const module=vm.runInContext('(function(){'+files.replace(/^export /gm,'')+'; return {initFiles,sendFileUpload};})()',context);
  return {context,document,nodes,requests,xhrs,module,globals,run:code=>vm.runInContext(code,context),runTimers(){ const callbacks=[...timers.values()]; timers.clear(); callbacks.forEach(fn=>fn()); }};
}

{
  const env=fixture(), urls=[];
  env.context.__import=url=>{urls.push(url); return Promise.resolve({start:()=>({active:true}),snapshot:()=>({active:false})});};
  await env.run('window.MoaPerformanceDiagnostics.start()'); await env.run('window.MoaPerformanceDiagnostics.snapshot()');
  check(urls.length===1 && !urls[0].endsWith('v=1'),'standalone app-script pages use a fresh per-page diagnostic fallback and share one import');
}
{
  const env=fixture(), pending=deferred(); let imports=0, binds=0, clicks=0;
  env.context.__import=()=>{imports++; return pending.promise;};
  const first=env.run('initFiles("old",["private"],false,null)'), second=env.run('initFiles("old",["private"],false,null)');
  const button=env.document.getElementById('upload-toggle');
  env.document.emit('click',{target:button});
  pending.resolve({initFiles(){binds++; button.addEventListener('click',()=>clicks++);}}); await first; await second;
  check(first===second && imports===1 && binds===1,'concurrent file initialization shares one import and one binding promise');
  check(clicks===1,'first upload action during the import is replayed exactly once after binding');
  check(env.document.getElementById('files-body').getAttribute('aria-busy')==='false','file loading region clears its busy state after binding');
}
{
  const env=fixture(); let imports=0, binds=0;
  env.context.__import=()=>{imports++; return imports===1 ? Promise.reject(new Error('transient import')) : Promise.resolve({initFiles(){binds++;}});};
  await env.run('initFiles("old",[],false,null)');
  await env.run('initFiles("old",[],false,null)');
  check(imports===2 && binds===1,'failed module download retries without duplicating initialization');
  const broken=fixture(); let partial=0;
  broken.context.__import=()=>Promise.resolve({initFiles(){partial++; throw new Error('after partial binding');}});
  await broken.run('initFiles("old",[],false,null)'); await broken.run('initFiles("old",[],false,null)');
  const reload=broken.document.getElementById('msg').children[0]; reload.click();
  check(partial===1 && broken.context.location.reloads===1,'partial binding failure requires reload instead of installing duplicate write handlers');
}
{
  const env=fixture(); env.run('initFormValidation();');
  const first=new Element('first','input'), second=new Element('second','input'), parent=new Element('parent');
  for(const field of [first,second]) { parent.appendChild(field); field.form={}; field.validity={valid:false}; field.validationMessage='한글 필수 입력'; }
  first.setAttribute('aria-describedby','original-help other-help');
  check(!first.getAttribute('aria-invalid'),'required empty fields are not marked before interaction');
  env.document.emit('blur',{target:first}); check(!first.getAttribute('aria-invalid'),'untouched required field blur does not introduce an error');
  const one=env.document.emit('invalid',{target:first}), two=env.document.emit('invalid',{target:second});
  check(!one.prevented && !two.prevented && !first.focusCount && !second.focusCount,'multiple invalid fields retain browser first-invalid focus behavior');
  const description=first.getAttribute('aria-describedby');
  check(description.startsWith('original-help other-help ') && parent.children[1].textContent===first.validationMessage,'native error preserves existing descriptions and exposes literal validation text');
  first.validity.valid=true; env.document.emit('input',{target:first});
  check(first.getAttribute('aria-describedby')==='original-help other-help' && first.getAttribute('aria-invalid')===null,'correction clears only the helper-owned description and invalid state');
  second.setAttribute('aria-describedby',second.getAttribute('aria-describedby')+' added-later');
  second.validity.valid=true; env.document.emit('change',{target:second});
  check(second.getAttribute('aria-describedby')==='added-later','correction preserves descriptions added by another feature after the error');
}
{
  const env=fixture(); env.run('initFormValidation();');
  const form=new Element('reset-form','form'), parent=new Element('field-parent'), outside=new Element('external-parent');
  const field=new Element('reset-field','input'), associated=new Element('associated-field','input');
  parent.appendChild(field); outside.appendChild(associated); form.elements=[field,associated];
  for(const control of form.elements) { control.form=form; control.validity={valid:false}; control.validationMessage='required'; }
  field.setAttribute('aria-describedby','original-help'); associated.setAttribute('aria-describedby','outside-help'); associated.setAttribute('aria-invalid','grammar');
  for(const control of form.elements) { env.document.emit('input',{target:control}); env.document.emit('invalid',{target:control}); }
  associated.setAttribute('aria-describedby',associated.getAttribute('aria-describedby')+' later-help');
  const event=env.document.emit('reset',{target:form});
  check(field.getAttribute('aria-invalid')==='true' && !event.defaultPrevented,'reset handler leaves native default behavior untouched and waits until dispatch ends');
  env.runTimers();
  check(field.getAttribute('aria-invalid')===null && field.getAttribute('aria-describedby')==='original-help' && parent.children.length===1,'accepted reset removes only helper errors and keeps existing descriptions');
  check(associated.getAttribute('aria-invalid')==='grammar' && associated.getAttribute('aria-describedby')==='outside-help later-help' && outside.children.length===1,'reset preserves prior aria state and later descriptions on externally associated controls');
  for(const control of form.elements) { env.document.emit('blur',{target:control}); }
  check(parent.children.length===1 && outside.children.length===1 && !field.focusCount && !associated.focusCount,'accepted reset clears interacted state without moving focus or re-marking pristine required fields');
  env.document.emit('input',{target:field}); env.document.emit('invalid',{target:field});
  const before=field.getAttribute('aria-describedby');
  const canceled=env.document.emit('reset',{target:form}); canceled.preventDefault(); env.runTimers();
  check(field.getAttribute('aria-describedby')===before && field.getAttribute('aria-invalid')==='true','later cancellation of reset preserves the current helper error');
  field.validity.valid=true; env.document.emit('input',{target:field}); field.validity.valid=false;
  const pristineCanceled=env.document.emit('reset',{target:form}); pristineCanceled.preventDefault(); env.runTimers(); env.document.emit('blur',{target:field});
  check(field.getAttribute('aria-invalid')==='true' && !field.focusCount,'canceled reset retains interaction history and never forces focus');
}

function startFiles(env) {
  env.module.initFiles('old',['private'],false,null);
  const level=env.document.getElementById('upload-level'); level.value='private'; level.form={}; level.validity={valid:true};
  const file=new Blob(['synthetic-upload'],{type:'text/plain'}); file.name='synthetic.txt';
  const input=env.document.getElementById('upload-file'); input.files=[file]; input.emit('change');
  return {level,form:env.document.getElementById('upload-form')};
}
{
  const env=fixture(), {form}=startFiles(env); form.emit('submit');
  check(env.xhrs.length===1 && env.xhrs[0].headers['X-CSRF-Token']==='old' && !env.xhrs[0].headers['Content-Type'],'extracted real upload handler resolves classic globals and sends CSRF with a browser multipart boundary');
  const xhr=env.xhrs[0]; xhr.upload.emit('progress',{lengthComputable:true,loaded:4,total:8});
  const rows=env.document.getElementById('upload-status-body'), state=rows.children[0].children[2];
  check(state.children[0].textContent.includes('50%'),'actual XHR byte progress reaches the real file status row');
  xhr.upload.emit('progress',{lengthComputable:false,loaded:5,total:0});
  check(state.children[1].getAttribute('aria-valuetext')==='전송 중' && state.children[1].getAttribute('value')===null,'unknown transfer length clears stale spoken percentage and uses indeterminate progress');
  xhr.upload.emit('load');
  check(state.children[0].textContent.includes('서버 확인') && env.document.getElementById('upload-submit').disabled,'upload transfer completion waits for the server result before allowing another submission');
  xhr.emit('error'); await turn(); await turn();
  check(env.xhrs.length===1 && state.children[0].textContent.includes('목록에서 파일을 확인'),'uncertain upload outcome is not retried and tells the user to check the list first');
}
{
  const env=fixture(), {form}=startFiles(env);
  env.run('probeSession=function(){return Promise.resolve({why:"fresh",token:"new"});}; setSessionCsrf=function(token){window.reviewToken=token;};');
  form.emit('submit'); const body=env.xhrs[0].body; env.xhrs[0].reply(403,{error:'CSRF rejected'});
  await turn(); await turn();
  check(env.xhrs.length===2 && env.xhrs[1].headers['X-CSRF-Token']==='new' && env.xhrs[1].body===body,'403 session refresh retries once through the same XHR transport and original FormData');
  env.xhrs[1].reply(403,{error:'still denied'}); await turn(); await turn();
  const state=env.document.getElementById('upload-status-body').children[0].children[2];
  check(env.xhrs.length===2 && state.children[0].textContent==='still denied','second 403 remains readable and is never retried again');
}
{
  const env=fixture(), {form}=startFiles(env); env.run('probeSession=function(){throw new Error("must not probe a reauth denial");};');
  form.emit('submit'); env.xhrs[0].reply(403,{error:'reauth required',next:'reauth'}); await turn(); await turn();
  check(env.xhrs.length===1 && env.document.getElementById('upload-msg').textContent==='reauth required','reauth403 keeps the original readable response without probing or retrying upload');
  const other=fixture(), next=startFiles(other); other.run('probeSession=function(){return Promise.resolve({why:"other"});};');
  next.form.emit('submit'); other.xhrs[0].reply(403,{error:'CSRF rejected'}); await turn(); await turn(); next.form.emit('submit'); await turn(); await turn();
  check(other.xhrs.length===1 && other.document.getElementById('upload-msg').textContent.includes('다른 계정'),'account change halts the current and subsequent writes without retrying');
}
{
  const env=fixture(), {form,level}=startFiles(env), parent=new Element(); parent.appendChild(level); level.setAttribute('aria-describedby','level-help'); level.value='';
  form.emit('submit');
  check(env.xhrs.length===0 && level.focusCount===1 && level.getAttribute('aria-describedby').startsWith('level-help '),'custom missing-upload-level error focuses and associates the select without losing its existing help');
  for(const status of [204,205,304]) {
    const promise=env.module.sendFileUpload('/tbox/api/files',{method:'POST',headers:{},body:new FormData()},null);
    env.xhrs.at(-1).reply(status,''); const result=await promise;
    check(result.status===status && await result.text()==='','XHR empty-body status '+status+' settles as a usable Response');
  }
}

{
  const entries={longtask:[{name:'private-title',startTime:10,duration:70}],event:[{name:'private-control',interactionId:9,duration:24}],
    'layout-shift':Array.from({length:6},(_,i)=>({startTime:500+i*900,value:.02,hadRecentInput:false}))};
  const observers=[];
  class Observer {
    static supportedEntryTypes=['longtask','event','layout-shift'];
    constructor(callback) {this.callback=callback; observers.push(this);}
    observe(options) {this.type=options.type;}
    disconnect() {}
    emit(values=entries[this.type]||[]) {this.callback({getEntries:()=>values});}
  }
  const context=vm.createContext({PerformanceObserver:Observer,performance:{getEntriesByType:()=>[]},URL,Blob,setTimeout,document:{createElement:()=>new Element()}});
  const module=vm.runInContext('(function(){'+diagnostics.replace(/^export /gm,'')+';return {start,stop,snapshot};})()',context);
  module.start(); observers.forEach(observer=>observer.emit()); const first=module.snapshot();
  check(first.observed.long_task_count===1 && Math.abs(first.observed.cls_observed-.12)<.0001,'early CLS window starts at its first real shift and accumulates a full five-second session');
  const old=observers[0]; module.stop(); module.start(); observers.slice(3).forEach(observer=>observer.emit()); old.emit();
  const restarted=module.snapshot();
  check(restarted.observed.long_task_count===1 && restarted.observed.interaction_entries===1,'stop/start rebuilds buffered summaries once and ignores callbacks from prior observer generations');
  check(!JSON.stringify(restarted).includes('private-') && !JSON.stringify(restarted).includes('interactionId'),'local diagnostic snapshot excludes source names and interaction identifiers');
}
{
  const line=messenger.split('\n').find(value=>value.includes("$('message-text').addEventListener('keydown'"));
  const text=new Element(), send=new Element(), form=new Element(); let submitted=0; form.requestSubmit=()=>submitted++;
  const context=vm.createContext({$:name=>({'message-text':text,'message-send':send,'message-form':form})[name]}); vm.runInContext(line,context);
  text.emit('keydown',{key:'Enter',isComposing:true,keyCode:13}); text.emit('keydown',{key:'Enter',isComposing:false,keyCode:229}); text.emit('keydown',{key:'Enter',shiftKey:true,keyCode:13});
  check(submitted===0,'messenger preserves composition Enter, legacy IME229 and Shift+Enter');
  text.emit('keydown',{key:'Enter',isComposing:false,keyCode:13}); send.disabled=true; text.emit('keydown',{key:'Enter',keyCode:13});
  check(submitted===1,'messenger submits a normal Enter once and respects the disabled sending state');
}
console.log('RESULT pass='+checks+' fail=0');
