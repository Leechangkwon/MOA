/* Authorized cross-space activity only; never persisted in browser storage. */
(() => {
'use strict';
const host=document.getElementById('product-home');if(!host)return;
const widget=host.closest('[data-home-widget]'),list=host.querySelector('[data-product-list]'),status=host.querySelector('[role=status]');let seq=0;
const names={notice:'공지',task:'할 일',event:'일정'};
async function load(){const ticket=++seq;list.replaceChildren();if(widget.hidden||document.documentElement.dataset.design==='claude'){status.textContent='';return;}status.textContent='내 공간의 최근 활동을 불러오는 중입니다.';try{const response=await fetch(host.dataset.base+'/api/me/product-home',{credentials:'same-origin',cache:'no-store'});const data=await response.json();if(ticket!==seq)return;if(!response.ok)throw Error(typeof data.error==='string'?data.error:'공간 활동을 불러오지 못했습니다.');const items=Object.entries(names).flatMap(([kind,label])=>(data.widgets?.[kind]||[]).map(item=>({...item,label}))).sort((a,b)=>String(b.updated_at||b.created_at||'').localeCompare(String(a.updated_at||a.created_at||''))).slice(0,8);for(const item of items){if(!/^[0-9a-f-]{36}$/i.test(item.space_id||''))continue;const link=document.createElement('a');link.className='q-start-item';link.href=host.dataset.base+'/spaces?space='+encodeURIComponent(item.space_id);const kind=document.createElement('span');kind.className='q-start-kind';kind.textContent=item.label+' · '+item.space_name;const title=document.createElement('strong');title.textContent=item.title;link.append(kind,title);list.append(link);}status.textContent=list.children.length?'참여 중인 공간의 공지·할 일·일정입니다.':'아직 공간 활동이 없습니다. 우리 공간에서 활동을 시작하세요.';}catch(e){if(ticket!==seq)return;list.replaceChildren();status.textContent=e.message;}}
new MutationObserver(load).observe(document.documentElement,{attributes:true,attributeFilter:['data-design']});
widget.addEventListener('home-layout-change',load);host.querySelector('button').addEventListener('click',load);load();
})();
