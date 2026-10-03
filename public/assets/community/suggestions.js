/** C:F-46: comparison/list UI and original-ID based proposal operations. */
import { renderDoc } from './doc-render.js?v=dd0021574d1b';

const TYPES = new Set(['paragraph','heading','bulleted','numbered','to_do','quote','callout','code','divider','toc','toggle','table','column_list','column']);
const STATES = {open:'검토 기다림',accepted:'받아들임',rejected:'거절',stale:'오래됨',withdrawn:'거둠'};
export const suggestionTypeAllowed = (type) => TYPES.has(type) || type === 'columns' || type === 'code_block';
export function suggestionEqual(a,b) {
  if(a===b)return true;
  if(!a||!b||typeof a!=='object'||typeof b!=='object'||Array.isArray(a)!==Array.isArray(b))return false;
  const keys=Object.keys(a);return keys.length===Object.keys(b).length && keys.every(k=>Object.prototype.hasOwnProperty.call(b,k)&&suggestionEqual(a[k],b[k]));
}
const equal=suggestionEqual;
function withoutChildren(block) { const copy={...block};delete copy.children;return copy; }
function blockMap(doc) {
  const map=new Map();
  const walk=(list)=>{for(const b of list){map.set(b.id,b);if(Array.isArray(b.children))walk(b.children);}};
  walk(doc.blocks||[]);return map;
}

/** Only original IDs become anchors; the server remakes insertion IDs. */
export function buildSuggestionOps(original, edited) {
  const before=blockMap(original), ops=[];
  const allowed=(b)=>{
    if(!TYPES.has(b.type))throw new Error('제안에는 글 블록만 넣거나 바꿀 수 있습니다.');
    const old=before.get(b.id);
    if(b.type==='to_do' && b.checked!==(old && old.type==='to_do' ? old.checked : false))throw new Error('제안에서는 할 일의 체크 상태를 바꿀 수 없습니다.');
    for(const child of b.children||[])allowed(child);
  };
  const replace=(b)=>{allowed(b);ops.push({op:'replace',block_id:b.id,block:b});};
  const walk=(oldList,newList,parent)=>{
    const oldIds=new Set(oldList.map(b=>b.id)),newIds=new Set(newList.map(b=>b.id));
    const commonOld=oldList.filter(b=>newIds.has(b.id)).map(b=>b.id);
    const commonNew=newList.filter(b=>oldIds.has(b.id)).map(b=>b.id);
    // A container replacement is the one supported way to insert before its first child.
    if(parent && ((!equal(commonOld,commonNew)) || (newList.length && !oldIds.has(newList[0].id)))) {
      replace(parent);return;
    }
    if(!equal(commonOld,commonNew))throw new Error('제안에서는 기존 블록의 순서를 바꾸지 않고 내용을 고쳐 주세요.');
    for(const b of oldList)if(!newIds.has(b.id))ops.push({op:'delete',block_id:b.id});
    // Apply insertion groups in reverse: every anchor must come from the original document.
    let anchor=null,pending=[];
    const flush=()=>{for(const b of pending.reverse()){allowed(b);ops.push({op:'insert_after',block_id:anchor,block:b});}pending=[];};
    for(const b of newList){
      if(!oldIds.has(b.id)){pending.push(b);continue;}
      flush();anchor=b.id;
      const old=before.get(b.id);
      if(equal(old,b))continue;
      if(Array.isArray(old.children)&&Array.isArray(b.children)&&equal(withoutChildren(old),withoutChildren(b)))walk(old.children,b.children,b);
      else replace(b);
    }
    flush();
  };
  walk(original.blocks||[],edited.blocks||[],null);
  if(ops.length>50)throw new Error('한 제안에는 바꾸는 블록을 50개까지 담을 수 있습니다.');
  return ops;
}

function el(tag,cls,text) {const node=document.createElement(tag);if(cls)node.className=cls;if(text!==undefined)node.textContent=text;return node;}
function button(text,fn,primary=false) {const b=el('button',primary?'btn':'btn btn--quiet',text);b.type='button';b.addEventListener('click',fn);return b;}
function person(who) {return who ? (who.display_name ? who.display_name+' ('+who.username+')' : who.username) : '';}

function modal(title) {
  const dialog=el('dialog','modal modal--stack'),head=el('div','modal__head'),body=el('div','modal__body'),foot=el('div','modal__foot');
  const heading=el('h2','modal__title',title);heading.id='suggestion-modal-title';dialog.setAttribute('aria-labelledby',heading.id);
  const close=button('×',()=>dialog.close());close.className='btn btn--icon';close.setAttribute('aria-label','닫기');
  head.append(heading,close);const error=el('p','msg');error.setAttribute('role','alert');foot.append(error,button('닫기',()=>dialog.close()));
  dialog.append(head,body,foot);document.body.append(dialog);dialog.addEventListener('close',()=>dialog.remove());
  dialog.showModal();return {dialog,body,foot,error};
}

export function createSuggestions({api,base,pageId,refresh}) {
  const request=async(method,path,body)=>{
    const response=await api(method,path,body);
    if(response.status<200||response.status>=300)throw new Error(response.data.error||'요청을 마치지 못했습니다.');
    return response.data;
  };
  async function compare(id) {
    const panel=modal('수정 제안 비교');panel.dialog.id='suggestion-comparison';
    try {
      const data=await request('GET','/api/suggestions/'+id),s=data.suggestion;
      panel.body.append(el('p','muted',STATES[s.status]+' · 제안한 사람 '+person(s.author)));
      if(s.decided_by)panel.body.append(el('p','muted','결정한 사람 '+person(s.decided_by)));
      if(s.note)panel.body.append(el('p','help',s.note));
      if(s.decision_note)panel.body.append(el('p','help','거절 사유: '+s.decision_note));
      if(data.stale)panel.body.append(el('p','notice','그 사이 이 블록이 바뀌었습니다. 새로 불러와 다시 제안해 주세요.'));
      const ctx={base,tbox_base:document.body.dataset.base||'',file_titles:data.file_titles||{},link_titles:data.link_titles||{},child_titles:data.child_titles||{},mention_labels:data.mention_labels||{},embeds:{hosts:[],frames:false},buttons:{}};
      for(const item of data.comparison||[]){
        const grid=el('div','grid-2');
        for(const [key,label] of [['before','지금 내용'],['after','제안 내용']]){
          const card=el('section','panel');card.append(el('h3','panel__title',label));
          if(item[key]){const doc=el('div','doc');doc.append(renderDoc({schema:1,blocks:[item[key]]},ctx));card.append(doc);}
          else card.append(el('p','muted',key==='before'?'새 블록':'이 블록을 지웁니다.'));
          grid.append(card);
        }
        panel.body.append(grid);
      }
      const decide=async(path,body,control)=>{
        control.disabled=true;
        try{await request('POST','/api/suggestions/'+id+'/'+path,body);panel.dialog.close();await refresh();}
        catch(e){panel.error.textContent=e.message;control.disabled=false;}
      };
      if(data.can_reject){
        const field=el('label','field'),label=el('span','field__label','거절 사유 (선택)'),note=el('textarea');note.maxLength=500;note.rows=3;note.id='suggestion-decision-note';
        field.append(label,note);panel.body.append(field);
        const reject=button('거절',()=>decide('reject',{note:note.value},reject));reject.id='suggestion-reject';panel.foot.insertBefore(reject,panel.foot.children[1]);
      }
      if(s.can_withdraw){const withdraw=button('제안 거두기',()=>decide('withdraw',{},withdraw));panel.foot.insertBefore(withdraw,panel.foot.children[1]);}
      if(data.can_accept){const accept=button('받아들이기',()=>decide('accept',{doc_version:data.doc_version},accept),true);accept.id='suggestion-accept';panel.foot.append(accept);}
    }catch(e){panel.error.textContent=e.message;}
  }
  async function list() {
    const panel=modal('수정 제안');panel.dialog.id='suggestion-list';
    try{
      const data=await request('GET','/api/pages/'+pageId+'/suggestions?status=open');
      if(!data.suggestions.length)panel.body.append(el('p','muted','열린 제안이 없습니다.'));
      for(const s of data.suggestions){const b=button(person(s.author)+' · '+(s.note||'수정 제안'),()=>{panel.dialog.close();compare(s.id);});panel.body.append(b);}
    }catch(e){panel.error.textContent=e.message;}
  }
  function send(original,edited,version,onSent) {
    let ops;
    try{ops=buildSuggestionOps(original,edited);}catch(e){return Promise.reject(e);}
    if(!ops.length)return Promise.reject(new Error('바뀐 블록이 없습니다.'));
    const panel=modal('수정 제안 보내기');panel.dialog.id='suggestion-send-dialog';
    const field=el('label','field'),label=el('span','field__label','제안 메모 (선택)'),note=el('textarea');note.maxLength=500;note.rows=4;note.id='suggestion-note';field.append(label,note);panel.body.append(field);
    const submit=button('보내기',async()=>{
      submit.disabled=true;
      try{await request('POST','/api/pages/'+pageId+'/suggestions',{base_version:version,ops,note:note.value});panel.dialog.close();await onSent();}
      catch(e){panel.error.textContent=e.message;submit.disabled=false;}
    },true);submit.id='suggestion-send';panel.foot.append(submit);note.focus();return Promise.resolve();
  }
  return {compare,list,send};
}

export function bindMySuggestions(api,base) {
  const host=document.querySelector('main');if(!host)return;
  for(const child of Array.from(host.children))child.hidden=true;
  const section=el('section','panel');section.id='my-suggestions';section.append(el('h1','page-head__title','내 제안'));
  const items=el('div','list'),error=el('p','msg');section.append(items,error);host.append(section);
  const more=button('더 보기',()=>load(more.dataset.after));more.hidden=true;section.append(more);
  const load=async(after='')=>{
    more.disabled=true;error.textContent='';if(!after)items.replaceChildren();
    try{
      const response=await api('GET','/api/me/suggestions'+(after?'?after='+encodeURIComponent(after):''));
      if(response.status!==200)throw new Error(response.data.error||'불러오지 못했습니다.');
      const data=response.data.suggestions||[];
      more.dataset.after=response.data.next_after||'';more.hidden=!more.dataset.after;more.disabled=false;
      if(!after&&!data.length)items.append(el('p','muted','아직 보낸 제안이 없습니다.'));
      for(const s of data){
        const row=el('div','list__item'),text=el('div');text.append(el('strong','',s.page_title),el('p','muted',STATES[s.status]+' · '+s.at));
        if(s.decision_note)text.append(el('p','help','거절 사유: '+s.decision_note));
        if(s.decided_by)text.append(el('p','muted','결정한 사람 '+person(s.decided_by)));
        row.append(text);
        if(s.can_view){const view=createSuggestions({api,base,pageId:s.page.id,refresh:()=>load()});row.append(button('내용 보기',()=>view.compare(s.id)));}
        items.append(row);
      }
    }catch(e){error.textContent=e.message;}
  };
  load();
}
