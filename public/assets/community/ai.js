// Explicit requests only. Provider output is plain text; page writes need a second action.
const features={search:'뜻으로 찾기',summary:'문서 요약',assistant:'절차서에 질문',transcribe:'녹음 받아쓰기',briefing:'오늘 업무 정리'};
const base=()=>document.body.dataset.communityBase||'/community';
function node(tag,text='',cls=''){const n=document.createElement(tag);n.textContent=text;if(cls)n.className=cls;return n;}
function field(label,type='text'){const w=node('label','','field'),n=node(type==='textarea'?'textarea':'input','','input');if(type!=='textarea')n.type=type;w.append(node('span',label,'field__label'),n);return [w,n];}
function button(label,callback){const b=node('button',label,'btn btn--quiet');b.type='button';b.addEventListener('click',async()=>{b.disabled=true;try{await callback();}finally{b.disabled=false;}});return b;}
function message(){const n=node('p','','msg');n.setAttribute('role','alert');return n;}
async function request(api,method,path,data,msg){
  try{const r=await api(method,path,data);if(r.status>=200&&r.status<300)return r.data;
    if(r.status===403&&r.data?.next==='reauth'&&typeof window.qarediHandleReauth==='function'){
      msg.textContent='본인 확인 뒤 다시 눌러 주세요.';
      window.qarediHandleReauth(r,r.data,()=>{msg.textContent='본인 확인을 마쳤습니다. 다시 눌러 주세요.';});return null;
    }
    msg.textContent=r.data?.error||'요청을 완료하지 못했습니다.';
  }catch(_){msg.textContent='연결하지 못했습니다. 결과를 확인한 뒤 다시 시도해 주세요.';}return null;
}
function consent(container){const [w,c]=field('선택한 자료를 Google에 보내는 데 동의합니다. 민감하거나 비공개인 자료의 전송 조건을 확인했습니다.','checkbox');container.append(w);return c;}
function audioPicker(container,msg){
  const [findWrap,query]=field('녹음 파일 이름으로 찾기'),selectWrap=node('label','','field'),select=node('select','','input');
  selectWrap.append(node('span','사용할 녹음 파일','field__label'),select);select.append(node('option','먼저 파일을 찾아 주세요'));select.firstChild.value='';
  container.append(findWrap,button('녹음 파일 찾기',async()=>{
    if(!query.value.trim()){msg.textContent='파일 이름의 일부를 입력해 주세요.';return;}
    try{
      const r=await fetch((document.body.dataset.base||'/tbox')+'/api/files?q='+encodeURIComponent(query.value.trim()),{credentials:'same-origin',cache:'no-store'});
      if(!r.ok){msg.textContent='자료실 목록을 불러올 수 없습니다.';return;}
      const data=await r.json();select.replaceChildren();
      const empty=node('option','파일을 선택하세요');empty.value='';select.append(empty);
      for(const f of data.files||[]){if(!/\.(wav|mp3|m4a|ogg)$/i.test(f.name)||f.scan_status!=='clean'||f.size_bytes>10*1024*1024)continue;
        const o=node('option',f.name);o.value=f.id;select.append(o);}
      msg.textContent=select.options.length>1?'목록에서 녹음을 선택하세요. 파일이 없으면 더 구체적인 이름으로 찾아 주세요.':'찾은 목록에 검사 완료된 10MB 이하 녹음이 없습니다. 더 구체적인 이름으로 찾아 주세요.';
    }catch(_){msg.textContent='자료실에 연결하지 못했습니다.';}
  }),selectWrap);
  return select;
}
function renderResult(container,result){
  container.replaceChildren();container.append(node('p',result.warning||'AI가 만든 글 — 틀릴 수 있습니다','help'));
  const output=node('pre',result.text||'','ai-result');output.tabIndex=0;container.append(output);
  for(const c of result.citations||result.results||[]){
    if(!/^[0-9a-f-]{36}$/.test(c.page_id||''))continue;
    const a=node('a',c.title||'근거 문서');a.href=base()+'/p/'+c.page_id;
    const p=node('p');p.append(a);if(c.snippet)p.append(node('span',' · '+c.snippet));container.append(p);
  }
  if(Array.isArray(result.results)&&!result.results.length)container.append(node('p','AI 사용이 허용된 문서에서 결과를 찾지 못했습니다. 일반 검색도 이용해 보세요.','muted'));
}
function dialog(title,opener){
  const d=node('dialog','','modal modal--form');d.setAttribute('aria-label',title);
  const body=node('div','','modal__body form'),actions=node('div','','form-actions'),msg=message();
  d.append(node('h2',title,'modal__title'),body,msg,actions);actions.append(button('닫기',()=>d.close()));
  document.body.append(d);d.addEventListener('close',()=>{d.remove();opener?.focus();});d.showModal();return {d,body,actions,msg};
}
export function drawPageAi(data,pageId,api,reload,isEditing){
  const menu=document.querySelector('#page-more .menu__list');if(!menu)return;
  menu.querySelectorAll('[data-page-ai]').forEach(n=>n.remove());
  let opener;opener=button('문서 AI · 요약과 사용 허용',async()=>{
    const m=dialog('문서 AI',opener),path='/api/pages/'+pageId;
    const status=await request(api,'GET','/api/ai/status',null,m.msg),permission=await request(api,'GET',path+'/ai-permission',null,m.msg);
    if(!status||!permission)return;
    m.body.append(node('p',permission.allowed?'이 문서의 현재 판은 외부 AI 사용이 허용돼 있습니다.':'현재 판의 외부 AI 사용이 허용되지 않았습니다.','help'));
    const confirm=consent(m.body),resultBox=node('div');m.body.append(resultBox);
    if(permission.can_manage)m.actions.append(button(permission.allowed?'외부 AI 허용 취소':'현재 판 외부 AI 사용 허용',async()=>{
      if(!permission.allowed&&!confirm.checked){m.msg.textContent='자료 전송 조건을 확인해 주세요.';return;}
      const r=await request(api,'PATCH',path+'/ai-permission',{allowed:!permission.allowed,doc_version:permission.doc_version},m.msg);
      if(r){m.d.close();await reload();}
    }));
    const generate=button('요약 만들기',async()=>{
      if(isEditing()){m.msg.textContent='편집 중인 내용을 먼저 저장하거나 편집을 마쳐 주세요.';return;}
      if(!confirm.checked){m.msg.textContent='자료 전송 조건을 확인해 주세요.';return;}
      m.msg.textContent='요약을 만들고 있습니다.';
      const r=await request(api,'POST',path+'/ai-summary',{doc_version:permission.doc_version,confirmed:true},m.msg);
      if(!r)return;m.msg.textContent='초안을 확인하세요. 아직 문서에 저장되지 않았습니다.';renderResult(resultBox,r);
      if(data.can_edit&&r.log_id){resultBox.append(button('이 초안을 문서 끝에 넣기',async()=>{
        if(isEditing()){m.msg.textContent='편집 중인 내용을 먼저 저장하거나 편집을 마쳐 주세요.';return;}
        const saved=await request(api,'POST',path+'/ai-insert',{log_id:r.log_id,doc_version:permission.doc_version},m.msg);
        if(saved){m.d.close();await reload();}
      }));}
    });
    generate.disabled=!status.configured||!status.features.summary||!permission.allowed;m.actions.append(generate);
    if(generate.disabled)m.msg.textContent='관리자 AI 설정과 현재 문서의 사용 허용이 필요합니다.';
  });opener.className='menu__item';opener.dataset.pageAi='1';menu.append(opener);
}
export async function bindAiCenter(api){
  const root=document.getElementById('community-ai-center');if(!root)return;
  root.replaceChildren();const msg=message();root.append(msg);
  const status=await request(api,'GET','/api/ai/status',null,msg);if(!status)return;
  root.append(node('p',(status.configured?'연결 키 등록됨':'연결 설정 대기')+' · 오늘 남은 요청 '+status.remaining+'회','help'));
  const panel=(title)=>{const p=node('section','','panel');p.append(node('h2',title));root.append(p);return p;};
  for(const name of ['search','assistant','briefing','transcribe']){
    const p=panel(features[name]),local=message();let input=null,lang=null;
    if(name==='transcribe')input=audioPicker(p,local);
    else if(name!=='briefing'){const [w,n]=field(name==='search'?'찾을 내용':'절차서에 물어볼 내용','textarea');n.maxLength=2000;p.append(w);input=n;}
    if(name==='transcribe'){const w=node('label','','field');w.append(node('span','녹음 언어','field__label'));lang=node('select','','input');for(const [v,t]of [['auto','자동'],['ko','한국어'],['en','영어']]){const o=node('option',t);o.value=v;lang.append(o);}w.append(lang);p.append(w,node('p','먼저 자료실에 올린 10MB 이하 녹음 파일을 선택하세요. 검사 완료된 파일만 사용할 수 있습니다.','help'));}
    const confirm=consent(p),out=node('div');p.append(local,out);
    const go=button(features[name],async()=>{
      if(!confirm.checked){local.textContent='자료 전송 조건을 확인해 주세요.';return;}
      if(input&&!input.value.trim()){local.textContent='내용을 입력해 주세요.';return;}
      const payload={confirmed:true};if(name==='search')payload.q=input.value;if(name==='assistant')payload.question=input.value;
      if(name==='transcribe'){payload.file_id=input.value.trim();payload.language=lang.value;}
      local.textContent='요청을 처리하고 있습니다.';const r=await request(api,'POST','/api/ai/'+name,payload,local);
      if(r){local.textContent='완료했습니다. 생성 결과는 초안입니다.';renderResult(out,r);}
    });go.disabled=!status.configured||!status.features[name];p.append(go);
    if(go.disabled)local.textContent='관리자가 이 기능을 켜면 사용할 수 있습니다.';
  }
  const logs=panel('내 AI 기록'),logMsg=message(),list=node('div');logs.append(logMsg,list);
  const loadLogs=async(admin=false,cursor='')=>{const r=await request(api,'GET',(admin?'/api/admin/ai-log':'/api/me/ai-log')+(cursor?'?before='+encodeURIComponent(cursor):''),null,logMsg);if(!r)return;if(!cursor)list.replaceChildren();for(const x of r.logs){const d=node('details');d.append(node('summary',(features[x.feature]||x.feature)+' · '+x.started_at+' · '+x.status),node('pre',x.content_visible?(x.output_text||'결과 없음'):'현재 권한으로 볼 수 없는 기록입니다.','ai-result'));list.append(d);}if(!r.logs.length&&!cursor)logMsg.textContent='아직 AI 기록이 없습니다.';if(r.next_before){let next;next=button('이전 기록',async()=>{next.remove();await loadLogs(admin,r.next_before);});list.append(next);}};
  logs.append(button('내 기록 보기',()=>loadLogs()));
  if(status.can_admin){logs.append(button('전체 기록 보기 (관리자)',()=>loadLogs(true)));await adminPanel(api,panel,status);}
}
async function adminPanel(api,panel,status){
  const p=panel('AI 관리자 설정'),msg=message();p.append(msg,node('p','기능별로 켜고 끌 수 있습니다. 문서별 외부 AI 사용 허용은 별도로 필요하며, 문서가 수정되면 해제됩니다.','help'));
  const [kw,key]=field('Google AI Studio API 키','password');key.autocomplete='new-password';key.maxLength=4096;p.append(kw);
  p.append(button('연결 키 저장',async()=>{const value=key.value;key.value='';const r=await request(api,'POST','/api/admin/ai/key',{key:value},msg);if(r)msg.textContent='키를 저장했습니다. 기능 설정을 확인하세요.';}));
  const wrap=node('label','','field'),mode=node('select','','input');wrap.append(node('span','외부 자료 처리 조건','field__label'),mode);
  for(const [v,t]of [['unconfigured','설정 전 · 전송 안 함'],['free_public','무료 · 외부 공개 가능한 자료만'],['paid_private','유료 · 조직이 전송을 허용한 자료']]){const o=node('option',t);o.value=v;o.selected=v===status.external_data_mode;mode.append(o);}p.append(wrap,node('p','Google 프로젝트의 실제 요금제와 자료 처리 조건을 확인한 뒤 선택하세요. 여기서 결제 설정은 변경되지 않습니다.','help'));
  const checks={};for(const [name,label]of Object.entries(features)){const [w,c]=field(label,'checkbox');c.checked=!!status.features[name];checks[name]=c;p.append(w);}
  p.append(button('기능 설정 저장',async()=>{const r=await request(api,'PATCH','/api/admin/ai/settings',{external_data_mode:mode.value,features:Object.fromEntries(Object.entries(checks).map(([k,c])=>[k,c.checked]))},msg);if(r)msg.textContent='설정을 저장했습니다. 화면을 새로 열면 반영됩니다.';}));
}
