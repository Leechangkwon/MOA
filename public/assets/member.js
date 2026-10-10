'use strict';
(() => {
  const page=document.body.dataset.memberPage, admin=document.getElementById('member-invites');
  if(!page&&!admin)return;
  const base=document.body.dataset.base||admin?.dataset.base||'/tbox';
  const $=id=>document.getElementById(id);
  const status=$('member-status')||$('invite-status');
  let csrf='', me=null;
  const say=text=>{status.textContent=text;};
  async function api(path,method='GET',body,join=false,passive=false){
    const headers={};
    if(method!=='GET'){headers['Content-Type']='application/json';headers[join?'X-Join-CSRF':'X-CSRF-Token']=join?document.body.dataset.joinCsrf:csrf;}
    if(passive)headers['X-Qaredi-Background']='1';
    const controller=page==='mcp-connect'?new AbortController():null;
    const timer=controller?setTimeout(()=>controller.abort(),30000):null;
    try{
      const options={method,headers,credentials:'same-origin',cache:'no-store',body:body===undefined?undefined:JSON.stringify(body)};
      if(controller)options.signal=controller.signal;
      const r=await fetch(base+path,options);
      let data;try{data=await r.json();}catch(error){if(error.name==='AbortError')throw error;throw Error('응답을 받지 못했습니다. 잠시 뒤 다시 시도해 주세요.');}
      if(!r.ok){if(r.status===401&&!join){say('로그인이 만료되었습니다. 다시 로그인해 주세요.');}
        // 사이트 Q:F-13 P1 — 약관 동의 관문(403 next:"consent")이면 동의 화면으로. 막는 것은 서버이고 이 줄은 안내다.
        if(r.status===403&&data.next==='consent'&&page!=='consent'){location.assign(base+'/consent');}
        const error=Error(data.next==='reauth'?'관리자 인증 시간이 지났습니다. 관리자 인증 갱신 링크에서 다시 인증해 주세요.':data.error||'요청을 처리하지 못했습니다.');error.status=r.status;
        // 사이트 Q:F-13 P1 — 409 ai_consent_required 의 분류어와 동의 창 글자(notice)를 부르는 쪽(메신저)이 읽는다
        error.code=typeof data.code==='string'?data.code:'';error.data=data;throw error;}return data;
    }catch(error){if(controller&&error.name==='AbortError')throw Error('응답 시간이 지났습니다. 승인 여부를 확인하려면 ChatGPT에서 연결을 다시 시작해 주세요.');throw error;}
    finally{if(timer!==null)clearTimeout(timer);}
  }
  async function busy(form,work){const buttons=[...form.querySelectorAll('button')];buttons.forEach(b=>b.disabled=true);try{await work();}catch(e){say(e.message);}finally{buttons.forEach(b=>b.disabled=false);}}
  async function copy(input){try{await navigator.clipboard.writeText(input.value);say('복사했습니다.');}catch(_){input.type='text';input.select();say('자동 복사가 제한되었습니다. 선택된 값을 직접 복사해 주세요.');}}
  const node=(tag,text,cls)=>{const e=document.createElement(tag);if(text!==undefined)e.textContent=text;if(cls)e.className=cls;return e;};
  const label=p=>p.display_name||p.username;
  const date=s=>new Date(s).toLocaleString('ko-KR',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
  const scopes={'read':'파일 읽기','read+upload':'파일 읽기·업로드 (비서 파일 기능)','read+upload+delete':'파일 읽기·업로드·삭제','community.read':'커뮤니티 읽기','community.write':'커뮤니티 읽기·쓰기','travel.read':'여행 계획 읽기','travel.write':'여행 계획 읽기·쓰기','assistant.run':'비서 실행','session.open':'이 PC에서 바로 로그인'};
  if(page==='join'){
    const fragment=new URLSearchParams(location.hash.slice(1)), invite=fragment.get('invite');
    if(invite&&/^[a-f0-9]{64}$/.test(invite)){$('join-invite').value=invite;history.replaceState(null,'',location.pathname);}
    $('join-form').addEventListener('submit',e=>{e.preventDefault();busy(e.currentTarget,async()=>{
      if($('join-password').value!==$('join-confirm').value)throw Error('비밀번호가 서로 다릅니다. 다시 확인해 주세요.');
      say('계정을 만들고 있어요…');
      // 사이트 Q:F-13 P1 — 체크 둘(약관 동의 · 만 14세 이상)의 참/거짓을 그대로 보낸다. 둘 다 참이 아니면 서버가 400 으로 거부한다(화면은 판단하지 않는다).
      const registered=await api('/api/register','POST',{invite:$('join-invite').value.trim(),username:$('join-username').value.trim(),display_name:$('join-name').value.trim(),password:$('join-password').value,agree_terms:$('join-terms').checked,age14:$('join-age14').checked},true);
      $('join-password').value='';$('join-confirm').value='';location.assign(base+(registered.next==='login'?'/login':'/welcome'));
    });});return;
  }
  async function welcome(){
    if(me.next==='password_change'){location.assign(base+'/');return;}
    const required=me.next==='mfa_setup', mfaOn=me.mfa_enabled===true;
    $('welcome-buddy').hidden=required;
    $('welcome-security-later').hidden=required;
    $('welcome-mfa').hidden=!required;
    $('welcome-choose-otp').hidden=mfaOn;
    $('welcome-token-form').hidden=me.can_issue_tokens!==true;
    $('welcome-token-protection').hidden=me.can_issue_tokens===true;
    if(required)$('welcome-security-note').textContent=me.mfa_recovery_required?'관리자가 계정 복구를 시작했어요. OTP를 다시 등록하면 계속 사용할 수 있습니다.':'관리자 계정은 OTP 설정을 완료해야 합니다.';
    else if(mfaOn)$('welcome-security-note').textContent='OTP를 사용 중이에요. 패스키를 추가하면 다음 로그인부터 OTP 없이 기기에서 확인할 수 있습니다.';
    if(me.passkeys_active>0)$('welcome-choose-passkey').textContent='다른 기기 패스키 추가';
    for(const atom of me.token_scopes||[]){const scope=atom==='upload'?'read+upload':atom==='delete'?'read+upload+delete':atom;const opt=node('option',scopes[scope]||scope);opt.value=scope;$('welcome-scope').append(opt);}
    if((me.token_scopes||[]).includes('read'))$('welcome-scope').value='read';
    say(required?'계정 보호 설정을 완료해 주세요.':'가입이 완료됐어요. 로그인 방법과 비서 연결은 필요한 것만 선택하세요.');
    $('welcome-choose-otp').addEventListener('click',()=>{$('welcome-mfa').hidden=false;$('welcome-passkey').hidden=true;$('welcome-enroll').focus();});
    $('welcome-security-later').addEventListener('click',()=>{$('welcome-mfa').hidden=true;$('welcome-passkey').hidden=true;$('welcome-passkey-password').value='';$('welcome-passkey-otp').value='';$('welcome-secret').textContent='';$('welcome-qr-area').hidden=true;$('welcome-enroll').hidden=false;});
    const passkey=window.QarediPasskey;
    if(!required){
      const ready=me.passkey_available===true&&passkey&&await passkey.available();
      $('welcome-choose-passkey').hidden=!ready;$('welcome-passkey-unavailable').hidden=Boolean(ready);
    }
    $('welcome-choose-passkey').addEventListener('click',()=>{
      $('welcome-passkey').hidden=false;$('welcome-mfa').hidden=true;
      $('welcome-passkey-otp-field').hidden=!mfaOn;$('welcome-passkey-otp-note').hidden=!mfaOn;$('welcome-passkey-otp').required=mfaOn;
      $('welcome-passkey-password').focus();
    });
    $('welcome-passkey-cancel').addEventListener('click',()=>{$('welcome-passkey').hidden=true;$('welcome-passkey-password').value='';$('welcome-passkey-otp').value='';$('welcome-choose-passkey').focus();});
    $('welcome-passkey-form').addEventListener('submit',e=>{e.preventDefault();busy(e.currentTarget,async()=>{
      const payload={name:$('welcome-passkey-name').value.trim(),password:$('welcome-passkey-password').value};
      if(mfaOn)payload.otp=$('welcome-passkey-otp').value.trim();
      try{await passkey.register(payload,csrf);}finally{$('welcome-passkey-password').value='';$('welcome-passkey-otp').value='';payload.password='';delete payload.otp;}
      $('welcome-passkey').hidden=true;say('패스키를 등록했어요. 다음 로그인에서 지문·얼굴·기기 잠금 또는 비밀번호를 선택하세요.');
    });});
    $('welcome-enroll').addEventListener('click',()=>busy($('welcome-mfa'),async()=>{
      const data=await api('/api/me/mfa/enroll','POST',{});$('welcome-qr-area').hidden=false;$('welcome-enroll').hidden=true;
      $('welcome-secret').textContent=data.secret;
      if(typeof data.otpauth==='string'&&data.otpauth.startsWith('otpauth://'))$('welcome-otp-link').href=data.otpauth;
      const rows=data.qr?.rows;
      if(Array.isArray(rows)&&rows.length){const canvas=$('welcome-qr'),size=rows.length,scale=6;canvas.width=canvas.height=(size+8)*scale;const ctx=canvas.getContext('2d');ctx.fillStyle='#fff';ctx.fillRect(0,0,canvas.width,canvas.height);ctx.fillStyle='#142c27';rows.forEach((row,y)=>Array.from(row).forEach((v,x)=>{if(v===1||v==='1'||v===true)ctx.fillRect((x+4)*scale,(y+4)*scale,scale,scale);}));}else{$('welcome-qr').hidden=true;}
      $('welcome-otp').focus();say('인증 앱에 등록하고 6자리 코드를 입력해 주세요.');
    }));
    $('welcome-otp-form').addEventListener('submit',e=>{e.preventDefault();busy(e.currentTarget,async()=>{await api('/api/me/mfa/confirm','POST',{otp:$('welcome-otp').value});location.reload();});});
    $('welcome-token-form').addEventListener('submit',e=>{e.preventDefault();busy(e.currentTarget,async()=>{
      const scope=$('welcome-scope').value;
      let data;try{data=await api('/api/me/api-tokens','POST',{name:$('welcome-token-name').value,purpose:'tbox-buddy 비서 연결',scope,password:$('welcome-password').value,otp:$('welcome-token-otp').value});}finally{$('welcome-password').value='';$('welcome-token-otp').value='';}
      $('welcome-token').value=data.token;$('welcome-token-result').hidden=false;$('welcome-token-label').textContent=scopes[scope]+' 토큰';$('welcome-token-expiry').textContent='유효기간: '+date(data.expires_at)+(data.bound_ip?' · 등록 IP: '+data.bound_ip:'');
      say('연결 토큰을 발급했습니다. 이 기능을 지원하는 외부 비서에만 입력해 주세요.');$('welcome-token-result').scrollIntoView({behavior:'smooth',block:'nearest'});
    });});
    $('welcome-token-copy').addEventListener('click',()=>copy($('welcome-token')));
    addEventListener('pagehide',()=>{$('welcome-token').value='';$('welcome-secret').textContent='';});
  }
  /* 사이트 Q:F-13 P1 — 「약관·동의」 화면(/consent). 약관 동의 + 처리방침 확인(한 묶음) · 로그아웃 · AI 국외 전송 동의·철회.
     돌아갈 곳(data-consent-to)은 서버가 허용 목록(Http::returnPath)으로 거른 값 — 여기서도 모듈 경로 모양만 받고 그 밖은 첫 화면. */
  async function consent(){
    const to=document.body.dataset.consentTo||'';
    const next=/^\/(?:tbox|community)\//.test(to)&&!to.includes('//')?to:'/';
    const form=$('consent-form');
    if(form){
      form.addEventListener('submit',e=>{e.preventDefault();busy(form,async()=>{
        if(!$('consent-terms').checked||!$('consent-privacy').checked)throw Error('두 항목을 모두 확인해 주세요.');
        say('동의를 저장하고 있어요…');
        await api('/api/me/consents','POST',{doc:'terms'});
        location.assign(next);
      });});
      $('consent-logout').addEventListener('click',()=>busy(form.parentElement,async()=>{await api('/api/logout','POST',{});location.assign('/');}));
      /* 사이트 Q:F-13 P2-R1(RV-P2-1) — 동의하지 않는 사람의 탈퇴 길(약관 제3조). 「내 계정」과 같은 흐름: 공용 확인 창 → POST {confirm:true} →
         403 next:reauth 면 공용 재인증 창(탈퇴 문구) → 같은 요청 한 번 더. 창은 app.js 의 것(qarediOpenConfirm · qarediHandleReauth) 그대로.
         대상은 서버가 세션 본인으로 고정한다 — 보내는 값은 confirm 하나뿐이다. 글자는 textContent 로만. */
      const del=$('consent-delete-request'),delState=$('consent-delete-state');
      const delShow=request=>{if(!request||typeof request.status!=='string')return;const at=String(request.requested_at||'').match(/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/);del.hidden=true;delState.hidden=false;delState.textContent='탈퇴를 요청했습니다 — 관리자가 처리할 때까지 기다려 주세요.'+(at?' 요청한 때: '+at[0].replace('T',' '):'');};
      const delSend=()=>busy(form.parentElement,async()=>{
        if(typeof window.qarediSetCsrf==='function')window.qarediSetCsrf(csrf);
        try{delShow((await api('/api/me/delete-request','POST',{confirm:true})).request);say('탈퇴를 요청했어요. 관리자가 처리하면 로그인할 수 없게 됩니다.');}
        catch(e){if(e.status===403&&e.data?.next==='reauth'&&typeof window.qarediHandleReauth==='function'){window.qarediHandleReauth({status:403},e.data,delSend,'delete-request');return;}throw e;}
      });
      del.addEventListener('click',()=>{
        if(typeof window.qarediOpenConfirm!=='function'){say('화면을 다 불러오지 못했습니다. 새로고침해 주세요.');return;}
        window.qarediOpenConfirm({title:'탈퇴를 요청할까요?',lead:'관리자가 처리하면 바로 로그인할 수 없게 됩니다. 남길 자료는 미리 내려받거나 다른 사람에게 넘겨 주세요.',note:'최근에 비밀번호를 확인하지 않았다면 비밀번호를 한 번 더 묻습니다.',okLabel:'탈퇴 요청'},delSend);
      });
      api('/api/me/delete-request').then(d=>delShow(d.request)).catch(e=>say(e.message));
    }
    const agree=$('consent-ai-agree'),withdraw=$('consent-ai-withdraw');
    if(!agree||!withdraw)return;
    const paint=on=>{agree.hidden=on;withdraw.hidden=!on;$('consent-ai-state').textContent=on?'동의함':'동의하지 않음';};
    agree.addEventListener('click',()=>busy($('consent-ai'),async()=>{const d=await api('/api/me/consents','POST',{doc:'ai_overseas'});paint(d.ai_overseas===true);say('AI 국외 전송에 동의했어요.');withdraw.focus();}));
    withdraw.addEventListener('click',()=>busy($('consent-ai'),async()=>{const d=await api('/api/me/consents','POST',{doc:'ai_overseas',action:'withdraw'});paint(d.ai_overseas===true);say('AI 국외 전송 동의를 철회했어요. AI 기능을 쓰면 그때 다시 물어요.');agree.focus();}));
  }
  async function invitations(){
    const data=await api('/api/invites');$('installer-url').value=data.buddy_download_url||'';
    $('invite-list').replaceChildren();
    for(const item of data.invites){const row=node('div',undefined,'moa-invite-row');row.append(node('strong',item.label),node('span',`${item.used_count}/${item.max_uses}명 · ${date(item.expires_at)}까지`));if(!item.revoked_at&&new Date(item.expires_at)>new Date()&&item.used_count<item.max_uses){const revoke=node('button','초대 회수','btn btn--quiet btn--small');revoke.type='button';revoke.addEventListener('click',()=>busy(row,async()=>{await api('/api/invites/'+item.id,'DELETE',{});say('초대 링크를 회수했습니다.');await invitations();}));row.append(revoke);}else row.append(node('span',item.revoked_at?'회수됨':'종료'));$('invite-list').append(row);}
  }
  async function initInvites(){
    const teams=await api('/api/teams');for(const t of teams.teams){const o=node('option',t.name);o.value=t.id;$('invite-team').append(o);}
    await invitations();
    $('invite-form').addEventListener('submit',e=>{e.preventDefault();busy(e.currentTarget,async()=>{
      const result=await api('/api/invites','POST',{label:$('invite-label').value,team_id:$('invite-team').value?Number($('invite-team').value):null,max_uses:Number($('invite-uses').value),days:Number($('invite-days').value)});
      $('invite-url').value=location.origin+result.path;$('invite-result').hidden=false;say('초대 링크가 준비됐습니다. 초대할 사람에게 전달해 주세요.');await invitations();
    });});
    $('invite-copy').addEventListener('click',()=>copy($('invite-url')));
    $('installer-form').addEventListener('submit',e=>{e.preventDefault();busy(e.currentTarget,async()=>{await api('/api/member-installer','POST',{url:$('installer-url').value});say('설치 파일 주소를 저장했습니다.');});});
  }
  async function initAiSettings(){
    const form=$('ai-settings-form');if(!form)return;
    const notice=$('ai-settings-status');
    function paint(data){
      $('ai-settings-enabled').value=String(data.enabled);
      $('ai-settings-user-limit').value=data.user_daily;$('ai-settings-site-limit').value=data.site_daily;
      $('ai-settings-image-enabled').value=String(data.image_enabled);
      $('ai-settings-image-user-limit').value=data.image_user_daily;$('ai-settings-image-site-limit').value=data.image_site_daily;
      $('ai-settings-key-state').textContent=data.configured?'키가 등록되어 있습니다. 교체할 때만 새 키를 입력하세요.':'등록된 키가 없습니다. AI Studio에서 만든 키를 입력하세요.';
      $('ai-settings-usage').textContent=`오늘 사이트 전체 ${data.site_used}/${data.site_daily}회 요청 (이미지 ${data.image_site_used}/${data.image_site_daily}회 포함) · 응답에서 확인된 ${Number(data.total_tokens).toLocaleString('ko-KR')} 토큰`;
    }
    form.addEventListener('submit',async e=>{
      e.preventDefault();const button=form.querySelector('button');button.disabled=true;notice.textContent='설정을 저장하고 있어요…';
      try{
        const data=await api('/api/member-ai-settings','POST',{api_key:$('ai-settings-key').value.trim(),enabled:$('ai-settings-enabled').value==='true',user_daily:Number($('ai-settings-user-limit').value),site_daily:Number($('ai-settings-site-limit').value),image_enabled:$('ai-settings-image-enabled').value==='true',image_user_daily:Number($('ai-settings-image-user-limit').value),image_site_daily:Number($('ai-settings-image-site-limit').value)});
        paint(data);notice.textContent=data.enabled?'저장했습니다. 메신저에서 모아 AI를 선택하고 대화를 시작하세요.':'저장했습니다. AI 대화 사용을 껐습니다.';
      }catch(error){notice.textContent=error.message;}finally{$('ai-settings-key').value='';button.disabled=false;}
    });
    addEventListener('pagehide',()=>{$('ai-settings-key').value='';});
    try{paint(await api('/api/member-ai-settings'));}catch(error){notice.textContent=error.message;}
  }
  api('/api/me').then(async data=>{me=data;csrf=data.csrf_token;if(page==='mcp-connect')await window.MoaMcpConnect.init({api,me});if(page==='welcome')await welcome();if(page==='buddy-guide')await window.MoaBuddySetup.init({api,me});if(page==='consent')await consent();if(admin)await Promise.all([initInvites(),initAiSettings()]);if(page==='messages'){if(!window.MoaMessenger)throw Error('메신저를 불러오지 못했습니다. 새로고침해 주세요.');await window.MoaMessenger.mount({api,me,node,say,date,label,mode:'page'});}}).catch(e=>{if(page==='mcp-connect'&&window.MoaMcpConnect)window.MoaMcpConnect.failure(e);else say(e.message);});
})();
