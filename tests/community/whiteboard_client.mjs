/** Focused client scheduling tests: actual application functions with deterministic clocks and transport. */
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const source=await readFile(new URL('../../public/assets/community/whiteboard.js',import.meta.url),'utf8');
let checks=0;
function check(value,label) { assert.ok(value,label); checks++; process.stdout.write('PASS '+label+'\n'); }
const shape=(id='shape',version=1,versionNonce=10)=>({id,type:'rectangle',version,versionNonce,isDeleted:false,x:0,y:0,width:20,height:20,angle:0});
function setup() {
  const nodes=new Map(), timers=new Map(), frames=new Map(), listeners=new Map(), requests=[], sockets=[];
  let nextId=1, now=100, respond=()=>new Promise(()=>{}), current=[], updates=[], scrolls=[];
  function element() { return {hidden:true,dataset:{},textContent:'',children:[],attributes:{},
    addEventListener(name,fn) { this[name]=fn; },setAttribute(name,value) { this.attributes[name]=value; },
    appendChild(child) { child.parentElement=this; this.children.push(child); },replaceChildren(...children) { this.children=children; },
    insertBefore(child,before) { const old=this.children.indexOf(child); if(old>=0) { this.children.splice(old,1); }
      const at=before ? this.children.indexOf(before) : this.children.length; this.children.splice(at,0,child); child.parentElement=this; },
    remove() { const at=this.parentElement.children.indexOf(this); this.parentElement.children.splice(at,1); }}; }
  const document={body:{dataset:{communityBase:'/community',base:'/tbox',pageId:'synthetic',csrf:'synthetic'}},hidden:false,
    getElementById(id) { if(!nodes.has(id)) { nodes.set(id,element()); } return nodes.get(id); },createElement:element,
    addEventListener(name,fn) { listeners.set('document:'+name,fn); }};
  class Socket {
    static OPEN=1;
    constructor(url) { this.url=url; this.readyState=1; this.sent=[]; this.handlers=new Map(); sockets.push(this); }
    addEventListener(name,fn) { this.handlers.set(name,fn); }
    send(raw) { this.sent.push(JSON.parse(raw)); }
    close() { this.readyState=3; this.handlers.get('close')?.(); }
    emit(name,value) { this.handlers.get(name)?.(value); }
  }
  const context=vm.createContext({document,TextEncoder,FormData,Blob,URL,Map,Set,Date,console,WebSocket:Socket,
    location:{origin:'http://localhost',protocol:'http:'},performance:{now:()=>now},
    setTimeout(fn,ms) { const id=nextId++; timers.set(id,{fn,ms}); return id; },clearTimeout(id) { timers.delete(id); },
    requestAnimationFrame(fn) { const id=nextId++; frames.set(id,fn); return id; },cancelAnimationFrame(id) { frames.delete(id); },
    fetch(url,options) { requests.push({url,options}); return respond(url,options); },
    addEventListener(name,fn) { listeners.set('window:'+name,fn); }});
  context.window=context;
  context.mockEngine={setReadOnly() {},api:{getSceneElementsIncludingDeleted:()=>current,getAppState:()=>({selectedElementIds:{}}),
    updateScene(value) { updates.push(value); if(value.elements) { current=value.elements; } },scrollToContent(...args) { scrolls.push(args); }}};
  context.mockAdapter={CaptureUpdateAction:{NEVER:'never'},reconcileElements(local,incoming) {
    const merged=new Map(local.map(e=>[e.id,e]));
    for(const e of incoming) { const old=merged.get(e.id); if(!old || e.version>old.version || e.version===old.version && e.versionNonce<=old.versionNonce) { merged.set(e.id,e); } }
    return [...merged.values()];
  }};
  vm.runInContext(source,context);
  vm.runInContext("board={id:'synthetic-board',last_seq:0,version_no:1,state:'open'}; can={draw:true}; engine=mockEngine; adapter=mockAdapter; intentUntil=10000; loadBusy=false; loadedVersion=null; eventsBoard=board.id;",context);
  requests.length=0;
  return {context,document,nodes,timers,frames,listeners,requests,sockets,updates,scrolls,
    run:code=>vm.runInContext(code,context),setCurrent:elements=>{current=elements;},setNow:value=>{now=value;},
    respond:fn=>{respond=fn;},async drain() { await Promise.resolve(); await Promise.resolve(); },
    render() { const work=[...frames.values()]; frames.clear(); for(const fn of work) { fn(now); } }};
}
const response=data=>({ok:true,json:async()=>data});

{
  const env=setup(), e=shape(); env.context.e=e;
  env.run('socket=new WebSocket("synthetic"); relayReady=true; drawDown=true; changed([e]);');
  e.version=2; e.width=45; env.run('changed([e]);');
  check(env.run('dirty.size===1 && outgoingPreviews.size===1 && dirty.get(e.id).version===2'),'successive mutable element versions coalesce without losing the latest edit');
  env.run('endStroke();');
  check(env.sockets[0].sent.length===1 && env.sockets[0].sent[0].elements[0].width===45,'pointer release sends the final queued preview immediately');
  check(env.run('encoded(dirty.get(e.id))===encoded({...dirty.get(e.id)})'),'serialization cache uses immutable element stamp rather than object identity');
  check(env.run('encoded({...dirty.get(e.id),isDeleted:true}).stamp!==encoded(dirty.get(e.id)).stamp'),'deletion changes the serialization cache key');
  check([...env.timers.values()].some(timer=>timer.ms===300),'stationary release schedules the durable final write');
}
for(const conflict of [false,true]) {
  const env=setup(); let finish;
  env.respond(()=>new Promise(resolve=>{finish=resolve;})); env.context.e=shape();
  env.run('changed([e]);'); const write=env.run('flush();');
  env.context.e=shape('shape',3); env.run('changed([e]);');
  finish(response({accepted:conflict ? [] : [{id:'shape',element:shape()}],rejected:conflict ? [{id:'shape',reason:'conflict',element:shape('shape',2)}] : [],last_seq:1}));
  await write;
  check(env.run('dirty.size===1 && dirty.get("shape").version===3'),(conflict?'conflict':'accepted')+' reply to an older submission preserves the newer pending version');
  check(JSON.parse(env.requests[0].options.body).elements[0].version===1,'durable request contains the frozen submitted version');
}
{
  const env=setup(); env.context.batch=Array.from({length:401},(_,i)=>shape('shape_'+i));
  env.run('socket=new WebSocket("synthetic"); relayReady=true; queuePreview(batch); flushPreviews();');
  env.run('flushPreviews(); flushPreviews();');
  check(env.sockets[0].sent.reduce((count,message)=>count+message.elements.length,0)===401 && env.run('outgoingPreviews.size===0'),'preview batches retain every distinct element across the 200-element boundary');
}
{
  const env=setup(); env.respond(async()=>response({url:'/community/whiteboard-relay',ticket:'one-time'}));
  await env.run('connectRelay();'); const socket=env.sockets[0]; socket.emit('open');
  check(socket.sent[0].type==='auth' && socket.sent[0].ticket==='one-time','fresh relay connection authenticates before sending queued previews');
  socket.emit('message',{data:JSON.stringify({type:'ready'})});
  for(const e of [shape('remote',5,10),shape('remote',5,20),shape('other',2)]) { socket.emit('message',{data:JSON.stringify({type:'preview',elements:[e],sent_at:999999999})}); }
  socket.emit('message',{data:JSON.stringify({type:'pointer',uid:8,name:'synthetic',x:1,y:2})});
  check(env.frames.size===1 && env.updates.length===0,'remote preview bursts and cursor updates schedule one animation frame');
  env.setNow(125); env.render();
  check(env.updates.length===1 && env.updates[0].collaborators.size===1 && env.run('engine.api.getSceneElementsIncludingDeleted().find(e=>e.id==="remote").versionNonce===10'),'one scene-and-cursor API update preserves the same-version nonce winner');
  check(env.scrolls.length===0,'remote synchronization leaves the user zoom and viewport alone');
  env.run('dirty.set("mine",'+JSON.stringify(shape('mine',3))+');');
  socket.emit('message',{data:JSON.stringify({type:'preview',elements:[shape('mine',9)]})}); env.render();
  check(env.run('dirty.get("mine").version===3 && !engine.api.getSceneElementsIncludingDeleted().some(e=>e.id==="mine")'),'transient preview cannot replace pending local content');
  env.run('replaying=true;');
  socket.emit('message',{data:JSON.stringify({type:'preview',elements:[shape('replay_intrusion')]})}); env.render();
  check(env.run('!engine.api.getSceneElementsIncludingDeleted().some(e=>e.id==="replay_intrusion")'),'transient preview is ignored during replay');
  env.run('replaying=false; confirming=true;');
  socket.emit('message',{data:JSON.stringify({type:'preview',elements:[shape('export_intrusion')]})}); env.render();
  check(env.run('!engine.api.getSceneElementsIncludingDeleted().some(e=>e.id==="export_intrusion")'),'transient preview is ignored while preparing the confirmation export');
  env.run('confirming=false; queuePreview([dirty.get("mine")]);'); env.document.hidden=true; env.run('pauseRelay();');
  check(env.run('dirty.size===1 && outgoingPreviews.size===1 && !relayReady'),'background pause preserves durable pending edits and queued final previews');
  env.document.hidden=false; await env.run('connectRelay();');
  check(env.requests.filter(req=>req.url.endsWith('/ticket')).length===2 && env.sockets.length===2,'returning from background requests a new one-time ticket');
  const resumed=env.sockets[1]; resumed.emit('open'); resumed.emit('message',{data:JSON.stringify({type:'ready'})});
  check(resumed.sent.some(message=>message.type==='preview' && message.elements[0].id==='mine'),'reauthenticated connection sends the preserved final preview');
  env.run('drawDown=true; fitAll();'); check(env.scrolls.length===0,'fit all waits for a stroke to finish');
  env.run('drawDown=false; fitAll();'); check(env.scrolls.length===1 && env.scrolls[0][1].fitToContent,'fit all uses the exposed engine API only when invoked');
  for(let i=0;i<150;i++) { env.run('timing("local_change_ms",performance.now()-1);'); }
  const diagnostic=env.run('window.MoaWhiteboardDiagnostics.snapshot()');
  check(diagnostic.local_change_ms.count===120 && diagnostic.preview_to_update_ms.p95_ms===25,'local diagnostic samples are bounded and use the receiver monotonic clock');
  check(!JSON.stringify(diagnostic).includes('synthetic'),'diagnostic export contains metric names and numeric aggregates only');
}
{
  const env=setup(), text={...shape('text'),type:'text',text:'<img src=x onerror=alert(1)> 한글'};
  env.setCurrent([text]); env.document.getElementById('whiteboard-elements').hidden=false;
  env.run('renderElementList();');
  const list=env.document.getElementById('whiteboard-element-list'), button=list.children[0].children[0];
  check(button.type==='button' && button.textContent.includes(text.text),'text alternative uses a native keyboard button and literal DOM text');
  button.click();
  check(env.updates[0].appState.selectedElementIds.text && env.scrolls.length===1,'text alternative selects its current engine element and scrolls it into view');
  env.context.large={...shape('too_large'),text:'x'.repeat(250000)}; env.run('dirty.set(large.id,large);');
  await env.run('flush();');
  check(env.run('dirty.size===1 && !saving') && env.requests.length===0 && !env.document.getElementById('whiteboard-recover').hidden,'oversized local element remains recoverable without an endless empty durable request');
}
// Independent review: responses arriving after later edits/view transitions must not reset the scene.
const snapshot=(elements=[],last_seq=0,id='synthetic-board')=>({board:{id,last_seq,version_no:1,state:'open'},elements,
  can:{draw:true,confirm:true,new_version:false},presence:[],versions:[{version_no:1,state:'open'}]});
{
  const env=setup(); let finish;
  env.respond(()=>new Promise(resolve=>{finish=resolve;}));
  const loading=env.run('load();'), later=shape('during_load',2); env.context.later=later;
  env.setCurrent([later]); env.run('changed([later]);'); finish(response(snapshot())); await loading;
  check(env.run('dirty.has("during_load")') && env.run('engine.api.getSceneElementsIncludingDeleted().some(e=>e.id==="during_load")'),
    'late local edit survives a snapshot requested before that edit');
}
{
  const env=setup(); let finish;
  env.respond(()=>new Promise(resolve=>{finish=resolve;})); const polling=env.run('synchronize();');
  env.context.latest=shape('shape',5); env.setCurrent([env.context.latest]);
  env.run('board.last_seq=5; durable=[latest]; known.set(latest.id,stamp(latest));');
  finish(response(snapshot([shape('shape',1)],1))); await polling;
  check(env.run('board.last_seq===5 && durable[0].version===5 && engine.api.getSceneElementsIncludingDeleted()[0].version===5'),
    'poll response older than an acknowledged write cannot roll back sequence or scene');
}
{
  const env=setup(); let finish;
  env.respond(()=>new Promise(resolve=>{finish=resolve;})); const polling=env.run('synchronize();');
  env.run('confirming=true;'); finish(response(snapshot([shape('older')],1))); await polling;
  check(env.updates.length===0,'a poll begun before confirmation cannot alter its export scene');
}
{
  const env=setup(); env.run('confirming=true; replay(0);');
  check(!env.run('replaying') && env.updates.length===0,'history replay cannot change the scene while confirmation exports it');
}
{
  const env=setup(), finishes=[];
  env.respond(()=>new Promise(resolve=>{finishes.push(resolve);}));
  const first=env.run('load();'), second=env.run('load();');
  finishes[1](response(snapshot([shape('latest')],2,'new-board'))); await second;
  finishes[0](response(snapshot([shape('obsolete')],1))); await first;
  check(env.run('board.id==="new-board" && durable[0].id==="latest"'),
    'overlapping snapshot requests adopt only the latest requested view');
}
{
  const env=setup(), finishes=[];
  env.respond(()=>new Promise(resolve=>{finishes.push(resolve);}));
  const poll=env.run('synchronize();'), loading=env.run('load();');
  finishes[1](response(snapshot([shape('latest')],2,'new-board'))); await loading;
  finishes[0](response(snapshot([shape('obsolete')],1))); await poll;
  check(env.run('board.id==="new-board"') && env.requests.filter(req=>!req.url.endsWith('/ticket')).length===2,
    'poll from the previous view cannot start another load after a version transition');
}
{
  const env=setup(); let finish;
  env.respond(()=>new Promise(resolve=>{finish=resolve;})); const pending=env.run('history();');
  env.run('board={...board,id:"another-board"};'); finish(response({events:[{seq:1,data:shape('old_history')}],next_seq:null})); await pending;
  check(env.run('eventsBoard===null && events.length===0') && env.document.getElementById('whiteboard-replay').disabled,
    'history reply for a previous board cannot enable replay on the new board');
}
process.stdout.write('RESULT pass='+checks+' fail=0\n');
