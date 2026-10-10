'use strict';
// PC onboarding uses only the existing same-origin, session/CSRF-protected APIs.
window.MoaBuddySetup={async init({api,me:initialMe}){
  const $=id=>document.getElementById(id);
  let me=initialMe,mode='ai',pending=false,issued=false;
  const say=text=>{$('pc-setup-status').textContent=text;};
  const atoms=()=>Array.isArray(me.token_scopes)?me.token_scopes:[];
  const allowed=()=>atoms().includes(mode==='ai'?'assistant.run':'read');
  const clearCredentials=()=>{for(const id of ['pc-token-password','pc-token-otp','pc-otp-code'])$(id).value='';};
  function clearEnrollment(){
    $('pc-otp-secret').textContent='';const canvas=$('pc-otp-qr');canvas.getContext('2d').clearRect(0,0,canvas.width,canvas.height);
    $('pc-otp-enrollment').hidden=true;$('pc-otp-start').hidden=false;
  }
  function clearResult(){issued=false;$('pc-token-value').value='';$('pc-token-value').type='password';$('pc-token-result').hidden=true;}
  function paint(){
    const gpt=mode==='gpt',can=allowed(),ready=me.can_issue_tokens===true;
    for(const name of ['ai','files','gpt']){$('pc-mode-'+name).setAttribute('aria-pressed',String(mode===name));$('pc-mode-'+name).classList.toggle('btn--quiet',mode!==name);}
    $('pc-token-setup').hidden=gpt;$('pc-gpt-guide').hidden=!gpt;$('pc-gpt-note').hidden=!gpt;
    $('pc-token-unavailable').hidden=can;$('pc-otp-setup').hidden=!can||ready;
    $('pc-token-form').hidden=!can||!ready||issued;
    const name=mode==='ai'?'기본 AI':'파일';
    $('pc-token-heading').textContent='1. '+name+' 연결 토큰 만들기';
    $('pc-token-issue').textContent=name+' 연결 토큰 만들기';
    $('pc-token-description').textContent=mode==='ai'?'PC에서 Gemini로 대화할 때 쓰는 모아 연결 토큰이에요. 필요한 기능은 자동으로 선택됩니다.':'PC에서 모아 파일을 찾고 내려받을 때 쓰는 연결 토큰이에요. 업로드도 사용할 경우에만 아래 항목을 선택하세요.';
    $('pc-upload-field').hidden=mode!=='files'||!atoms().includes('upload');
  }
  async function work(action){
    if(pending)return;pending=true;
    const controls=[...$('pc-setup').querySelectorAll('button,input')];controls.forEach(el=>el.disabled=true);
    try{await action();}catch(error){say(error.message||'처리하지 못했어요. 잠시 후 다시 시도해 주세요.');}
    finally{clearCredentials();pending=false;controls.forEach(el=>el.disabled=false);paint();}
  }
  for(const name of ['ai','files','gpt'])$('pc-mode-'+name).addEventListener('click',()=>{
    if(pending||mode===name)return;mode=name;clearResult();clearCredentials();clearEnrollment();$('pc-upload').checked=false;paint();
    say(mode==='gpt'?'아래의 PC 로그인 안내부터 진행하세요.':'필요한 기능을 선택했어요. 인증 후 연결 토큰을 만들 수 있습니다.');
  });
  $('pc-otp-start').addEventListener('click',()=>work(async()=>{
    say('인증 앱 등록을 준비하고 있어요…');
    const data=await api('/api/me/mfa/enroll','POST',{});
    $('pc-otp-secret').textContent=data.secret;
    const rows=data.qr?.rows,canvas=$('pc-otp-qr');
    canvas.hidden=!Array.isArray(rows)||!rows.length;
    if(!canvas.hidden){const size=rows.length,scale=Math.max(1,Math.min(4,Math.floor(240/(size+8))));canvas.width=canvas.height=(size+8)*scale;const ctx=canvas.getContext('2d');ctx.fillStyle='#fff';ctx.fillRect(0,0,canvas.width,canvas.height);ctx.fillStyle='#000';rows.forEach((row,y)=>Array.from(row).forEach((v,x)=>{if(v===1||v==='1'||v===true)ctx.fillRect((x+4)*scale,(y+4)*scale,scale,scale);}));}
    $('pc-otp-enrollment').hidden=false;$('pc-otp-start').hidden=true;
    say('인증 앱에 등록한 뒤 6자리 코드를 입력하세요.');
  }));
  $('pc-otp-form').addEventListener('submit',e=>{e.preventDefault();work(async()=>{
    await api('/api/me/mfa/confirm','POST',{otp:$('pc-otp-code').value.trim()});clearEnrollment();
    me=await api('/api/me');
    say('인증 앱 등록이 완료됐어요. 앱의 숫자가 바뀌면 새로운 코드와 비밀번호로 연결 토큰을 만드세요.');
  });});
  $('pc-token-form').addEventListener('submit',e=>{e.preventDefault();if(issued)return;work(async()=>{
    if(!allowed()||me.can_issue_tokens!==true)throw Error('계정의 연결 권한을 확인해 주세요.');
    const scope=mode==='ai'?'assistant.run':$('pc-upload').checked&&atoms().includes('upload')?'read+upload':'read';
    say('연결 토큰을 만들고 있어요…');
    const data=await api('/api/me/api-tokens','POST',{name:$('pc-token-name').value.trim(),purpose:mode==='ai'?'PC 비서 기본 AI 연결':'PC 비서 파일 연결',scope,password:$('pc-token-password').value,otp:$('pc-token-otp').value.trim()});
    if(typeof data.token!=='string'||!data.token)throw Error('발급 결과를 확인할 수 없어요. 내 계정의 토큰 목록을 확인해 주세요.');
    issued=true;$('pc-token-value').value=data.token;$('pc-token-result').hidden=false;
    $('pc-token-result-label').textContent=mode==='ai'?'기본 AI 연결 토큰':scope==='read'?'파일 읽기 토큰':'파일 읽기·업로드 토큰';
    $('pc-token-menu').textContent=mode==='ai'?'비서 실행용 토큰 등록':'API 토큰 변경';
    $('pc-token-use').textContent=mode==='ai'?'비서의 AI 창에서 공급자 Gemini를 선택하고 짧은 문장을 입력해 보세요. AI 창은 Ctrl+Alt+Space, 또는 Ctrl+Shift+Space → AI(Ctrl+J)로 열 수 있어요.':'Ctrl+Shift+Space로 파일 탐색 창을 열어 모아 파일이 보이는지 확인하세요.';
    $('pc-token-expiry').textContent='유효기간: '+new Date(data.expires_at).toLocaleString('ko-KR')+(data.bound_ip?' · 이 토큰은 발급한 네트워크에 연결됩니다.':'');
    say('발급 완료. 연결 토큰을 복사한 뒤 PC 비서에 등록하세요.');
    $('pc-token-result').scrollIntoView({block:'nearest'});
  });});
  $('pc-token-copy').addEventListener('click',async()=>{
    if(!$('pc-token-value').value)return;
    try{await navigator.clipboard.writeText($('pc-token-value').value);say('복사했어요. PC 비서의 ‘'+$('pc-token-menu').textContent+'’에 붙여 넣으세요.');}
    catch(_){$('pc-token-value').type='text';$('pc-token-value').select();say('자동 복사가 제한됐어요. 선택된 연결 토큰을 직접 복사하세요.');}
  });
  addEventListener('pagehide',()=>{clearCredentials();clearResult();clearEnrollment();paint();});
  if(me.next==='password_change'){location.assign(document.body.dataset.base+'/');return;}
  paint();say('설치했다면 기본 AI 연결부터 시작하세요. 이미 연결했다면 추가로 쓸 기능만 고르세요.');
}};
