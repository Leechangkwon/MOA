/* Stage11 calendar/timeline: layout only; all edits pass the existing values endpoint. */
export const dayText = date => date.toISOString().slice(0,10);
export function shifted(day,n) { const date=new Date(day+'T12:00:00Z'); date.setUTCDate(date.getUTCDate()+n); return dayText(date); }
export function rangeFor(view,anchor=dayText(new Date())) {
  if ((view.mode||view.scale)==='week') { const d=new Date(anchor+'T12:00:00Z');d.setUTCDate(d.getUTCDate()-d.getUTCDay());return [dayText(d),shifted(dayText(d),6)]; }
  return [anchor.slice(0,7)+'-01',dayText(new Date(Date.UTC(Number(anchor.slice(0,4)),Number(anchor.slice(5,7)),0)))];
}
const el=(tag,text,cls)=>{const n=document.createElement(tag);if(text)n.textContent=text;if(cls)n.className=cls;return n;};
export function drawCalendar(host,view,rows,range,options={}) {
  host.replaceChildren(); const [from,to]=range;
  const getDate=row=>view.date_prop==='due' ? (row.due?{start:row.due}:null) : row.values && row.values[view.date_prop];
  async function move(row,delta) {
    const value=getDate(row);if(!value||!options.save)return;
    const start=shifted(value.start.slice(0,10),delta); const next={start};if(value.end)next.end=shifted(value.end.slice(0,10),delta);
    await options.save(row.id,view.date_prop,view.date_prop==='due'?start:next);
  }
  function card(row) {
    const a=el('a',row.title,'db-calendar__item');a.href=(options.base||'/community')+'/p/'+row.id;
    if(options.edit){a.draggable=true;a.addEventListener('dragstart',e=>e.dataTransfer.setData('text/plain',row.id));
      a.addEventListener('keydown',e=>{if(e.key==='ArrowLeft'||e.key==='ArrowRight'){e.preventDefault();move(row,e.key==='ArrowRight'?1:-1).catch(options.error||(()=>{}));}});a.setAttribute('aria-label',row.title+' · 좌우 화살표로 하루 이동');}
    return a;
  }
  if(view.type==='timeline') {
    const list=el('div',null,'db-timeline');const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');
    const height=Math.max(70,rows.length*38+30);svg.setAttribute('viewBox','0 0 720 '+height);svg.setAttribute('role','img');svg.setAttribute('aria-label','선후행 일정');svg.classList.add('db-timeline__graphic');
    const points=new Map(); const span=Math.max(1,(Date.parse(to)-Date.parse(from))/86400000+1);
    rows.forEach((row,i)=>{const d=getDate(row);if(!d)return;const x=160+Math.max(0,(Date.parse(d.start.slice(0,10))-Date.parse(from))/86400000)*550/span;const end=d.end||d.start;const w=Math.max(4,Math.min(710-x,((Date.parse(end.slice(0,10))-Date.parse(d.start.slice(0,10)))/86400000+1)*550/span));const y=i*38+12;points.set(row.id,{x,y,w});
      const label=document.createElementNS(svg.namespaceURI,'text');label.setAttribute('x','4');label.setAttribute('y',String(y+12));label.textContent=row.title.length>18?row.title.slice(0,18)+'…':row.title;svg.append(label);
      const rect=document.createElementNS(svg.namespaceURI,'rect');for(const[k,v]of Object.entries({x,y,width:w,height:18,rx:3}))rect.setAttribute(k,String(v));rect.classList.add('db-timeline__bar');svg.append(rect);
      const item=card(row);item.append(document.createTextNode(' · '+d.start+(d.end?' ~ '+d.end:'')));list.append(item);});
    if(view.depends_prop)for(const row of rows)for(const dep of (row.values&&row.values[view.depends_prop])||[]){const a=points.get(dep),b=points.get(row.id);if(!a||!b)continue;const line=document.createElementNS(svg.namespaceURI,'path');line.setAttribute('d','M '+(a.x+a.w)+' '+(a.y+9)+' L '+b.x+' '+(b.y+9)+' l -5 -3 m 5 3 l -5 3');line.classList.add('db-timeline__arrow');svg.append(line);}
    if(options.edit){svg.addEventListener('dragover',e=>e.preventDefault());svg.addEventListener('drop',e=>{e.preventDefault();const row=rows.find(r=>r.id===e.dataTransfer.getData('text/plain'));const value=row&&getDate(row);if(!value)return;const bounds=svg.getBoundingClientRect();const x=(e.clientX-bounds.left)*720/bounds.width;const offset=Math.max(0,Math.min(span-1,Math.floor((x-160)*span/550)));const day=shifted(from,offset);move(row,(Date.parse(day)-Date.parse(value.start.slice(0,10)))/86400000).catch(options.error||(()=>{}));});}
    const canvas=el('div',null,'db-timeline-canvas');canvas.append(svg);host.append(canvas,list);return;
  }
  const grid=el('div',null,'db-calendar');grid.setAttribute('role','list');
  for(const name of ['일','월','화','수','목','금','토'])grid.append(el('span',name,'muted'));
  for(let i=0;i<new Date(from+'T12:00:00Z').getUTCDay();i++){const blank=el('span');blank.setAttribute('aria-hidden','true');grid.append(blank);}
  for(let day=from;day<=to;day=shifted(day,1)){const cell=el('section',null,'db-calendar__day');cell.setAttribute('aria-label',day);cell.append(el('h4',day.slice(5),'muted'));
    for(const row of rows){const value=getDate(row);if(value&&value.start.slice(0,10)<=day&&(value.end||value.start).slice(0,10)>=day)cell.append(card(row));}
    if(options.edit){cell.addEventListener('dragover',e=>e.preventDefault());cell.addEventListener('drop',e=>{e.preventDefault();const row=rows.find(r=>r.id===e.dataTransfer.getData('text/plain'));const value=row&&getDate(row);if(value)move(row,(Date.parse(day)-Date.parse(value.start.slice(0,10)))/86400000).catch(options.error||(()=>{}));});}
    grid.append(cell);
  }host.append(grid);
}
