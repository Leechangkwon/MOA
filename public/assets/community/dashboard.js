import {drawCalendar,rangeFor} from './calendar.js?v=d44d8daf57f9';
import {drawChart} from './charts.js?v=d44d8daf57f9';
export function hydrateDashboard(container,doc,context,api) {
  const blocks=[];(function walk(items){for(const b of items||[]){if(b.type==='db_view')blocks.push(b);if(b.children)walk(b.children);}})(doc.blocks);
  if(!blocks.length)return;
  const bar=document.createElement('form');bar.className='toolbar';const controls={};
  for(const[name,label,type]of [['owner','소유자 또는 사람','text'],['from','시작일','date'],['to','종료일','date']]){const l=document.createElement('label');l.className='field';const span=document.createElement('span');span.textContent=label;const input=document.createElement('input');input.type=type;input.className='input';input.setAttribute('aria-label',label);l.append(span,input);bar.append(l);controls[name]=input;}
  const apply=document.createElement('button');apply.type='submit';apply.className='btn btn--quiet';apply.textContent='전체 뷰 거르기';bar.append(apply);container.prepend(bar);
  async function draw(block){
    const host=container.querySelector('#block-'+block.id);const entry=context.db_views&&context.db_views[block.id];if(!host||!entry)return;
    let body=host.querySelector('.db-widget__body');if(!body){body=document.createElement('div');body.className='db-widget__body';host.append(body);}body.replaceChildren();
    const query=new URLSearchParams({view:block.view_id});let applied=true;
    const schemaResponse=entry.view;const schema=entry.schema||[];let range=rangeFor(schemaResponse);const dateProp=schema.find(p=>p.type==='date');const personProp=schema.find(p=>p.type==='person');
    if(['calendar','timeline'].includes(entry.type)){if(controls.from.value&&controls.to.value)range=[controls.from.value,controls.to.value];query.set('from',range[0]);query.set('to',range[1]);if(controls.owner.value)query.set('filter',JSON.stringify({and:[{prop:personProp?personProp.key:'owner',op:'eq',value:controls.owner.value}]}));}
    else if(entry.type==='chart'){for(const name of ['owner','from','to'])if(controls[name].value)query.set(name,controls[name].value);}
    else {
      if(controls.from.value||controls.to.value){if(dateProp){query.set('date_prop',dateProp.key);query.set('from',controls.from.value);query.set('to',controls.to.value);}else applied=false;}
      if(controls.owner.value)query.set('filter',JSON.stringify({and:[{prop:personProp?personProp.key:'owner',op:'eq',value:controls.owner.value}]}));
    }
    const suffix=entry.type==='chart'?'chart':'rows';
    const result=await api('GET','/api/databases/'+block.database_id+'/'+suffix+'?'+query.toString());
    if(result.status!==200){body.textContent=result.status===403||result.status===404?'볼 수 없는 데이터베이스':(result.data.error||'불러오지 못했습니다');if(result.status===403||result.status===404)host.querySelector('h3').textContent='볼 수 없는 데이터베이스';return;}
    if(result.data.filter_applied===false)applied=false;
    if(!applied){const p=document.createElement('p');p.className='muted';p.textContent='거르개 적용 안됨';body.append(p);}
    const content=document.createElement('div');body.append(content);
    if(entry.type==='chart')drawChart(content,result.data.chart);
    else if(['calendar','timeline'].includes(entry.type))drawCalendar(content,entry.view,result.data.rows,range,{base:context.base});
    else {const list=document.createElement('ul');list.className='list';for(const row of result.data.rows){const li=document.createElement('li');const link=document.createElement('a');link.href=context.base+'/p/'+row.id;link.textContent=row.title;li.append(link);list.append(li);}content.append(list);}
    if(result.data.truncated){const note=document.createElement('p');note.className='muted';note.textContent='500개까지만 표시합니다. 기간을 좁혀 주세요.';body.append(note);}
  }
  const run=()=>Promise.all(blocks.map(b=>draw(b).catch(()=>{})));bar.addEventListener('submit',e=>{e.preventDefault();run();});run();
}
