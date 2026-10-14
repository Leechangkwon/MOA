/* Stage11 calendar/timeline: layout only; all edits pass the existing values endpoint.
 * Q:F-12 W1 (결정 WK-29 · DESIGN 2.34 · 기획 work-ux-plan.md 19.2) — 달력 칸 그리기는 이 한 벌(내 작업 · 프로젝트 · 데이터베이스 달력 보기).
 *   달 격자 = 주 줄(`.cal-week` — 7칸 격자) 하나씩. 날짜 칸은 줄 전체 높이, 막대는 그 위 층(`cal-lane-1~4`)에 칸 수만큼(`cal-col-N` 시작 · `cal-span-N` 폭).
 *   위치 · 폭은 전부 클래스(인라인 style 0). 기한을 날짜 칸으로 쓰는 달력(date_prop 'due')은 작업 한 건에 막대 둘까지 —
 *   예정(시작 예정 → 기한 · 점선) · 실제(실제 시작 → 끝난 날 또는 오늘 · 진한 칠, 기한 다음 날부터는 「기한 넘김」 조각) · 기한 날 칸에 깃발.
 *   시작 예정도 실제 시작도 없으면 지금처럼 기한 하루. 주 줄을 넘는 막대는 다음 줄 첫 칸에서 이어 그린다. 한 칸에 층이 4 를 넘으면 「+N」. */
export const dayText = date => date.toISOString().slice(0,10);
export function shifted(day,n) { const date=new Date(day+'T12:00:00Z'); date.setUTCDate(date.getUTCDate()+n); return dayText(date); }
export function rangeFor(view,anchor=dayText(new Date())) {
  if ((view.mode||view.scale)==='week') { const d=new Date(anchor+'T12:00:00Z');d.setUTCDate(d.getUTCDate()-d.getUTCDay());return [dayText(d),shifted(dayText(d),6)]; }
  return [anchor.slice(0,7)+'-01',dayText(new Date(Date.UTC(Number(anchor.slice(0,4)),Number(anchor.slice(5,7)),0)))];
}
const el=(tag,text,cls)=>{const n=document.createElement(tag);if(text)n.textContent=text;if(cls)n.className=cls;return n;};
const isDay=v=>typeof v==='string'&&/^\d{4}-\d{2}-\d{2}$/.test(v);
const daysBetween=(a,b)=>Math.round((Date.parse(b+'T12:00:00Z')-Date.parse(a+'T12:00:00Z'))/86400000);
/** 서울 오늘(Y-m-d) — 화면이 따로 주지 않으면 */
function seoulToday() {
  try { return new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Seoul',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date()); }
  catch (e) { return dayText(new Date()); }
}
const MAX_LANES=4;
const monthDay=d=>Number(d.slice(5,7))+'월 '+Number(d.slice(8,10))+'일';
/** 「10월 10일 ~ 16일」 — 같은 달이면 뒤는 날만 */
function rangeText(a,b) {
  if (a===b) { return monthDay(a); }
  return monthDay(a)+' ~ '+(a.slice(0,7)===b.slice(0,7)?Number(b.slice(8,10))+'일':monthDay(b));
}

/**
 * 한 행의 막대 조각들(달력 범위와 상관없는 날짜) + 막대 이름표 문장. kind = plan · actual · late · base(기한 하루 · 일반 날짜 속성).
 * 기한 달력이 아니면(일반 날짜 속성) 지금처럼 그 값의 start ~ end 하나.
 */
export function taskSpans(row,view,today) {
  if (view.date_prop!=='due') {
    const v=row.values&&row.values[view.date_prop];
    if (!v||!isDay(String(v.start||'').slice(0,10))) { return {spans:[],due:null,label:null}; }
    const s=v.start.slice(0,10); let e=isDay(String(v.end||'').slice(0,10))?v.end.slice(0,10):s; if (e<s) { e=s; }
    return {spans:[{kind:'base',from:s,to:e}],due:null,label:row.title+' — '+rangeText(s,e)};
  }
  const due=isDay(row.due)?row.due:null, start=isDay(row.start)?row.start:null, started=isDay(row.started)?row.started:null, finished=isDay(row.finished)?row.finished:null;
  const spans=[]; const parts=[];
  if (start) {
    const end=due&&due>=start?due:start;
    spans.push({kind:'plan',from:start,to:end});
    parts.push('예정 '+(due&&due>=start?rangeText(start,due):monthDay(start)+' ~'));
  } else if (due&&!started) {
    spans.push({kind:'base',from:due,to:due});
    parts.push('기한 '+monthDay(due));
  }
  if (started) {
    let end=finished&&finished>=started?finished:today; if (end<started) { end=started; }
    if (due&&end>due) {
      if (started<=due) { spans.push({kind:'actual',from:started,to:due}); }
      spans.push({kind:'late',from:started>due?started:shifted(due,1),to:end});
    } else {
      spans.push({kind:'actual',from:started,to:end});
    }
    parts.push('실제 '+monthDay(started)+' ~ '+(finished?(finished.slice(0,7)===started.slice(0,7)?Number(finished.slice(8,10))+'일':monthDay(finished)):'진행 중'));
    if (due&&end>due) { parts.push('기한 넘김 '+daysBetween(due,end)+'일'); }
  }
  if (due&&!start&&started) { parts.push('기한 '+monthDay(due)); }
  return {spans,due,label:spans.length?row.title+' — '+parts.join(' · '):null};
}

/** 달력 도구 줄 오른쪽 작은 범례 셋(예정 · 실제 · 기한 넘김) — 기한 달력에서만 붙인다 */
export function drawLegend() {
  const box=el('div',null,'cal-legend');box.setAttribute('aria-label','범례');
  for (const [kind,text] of [['plan','예정'],['actual','실제'],['late','기한 넘김']]) {
    const item=el('span',null,'cal-legend__item');const sw=el('span',null,'cal-legend__swatch cal-bar--'+kind);sw.setAttribute('aria-hidden','true');
    item.append(sw,document.createTextNode(text));box.append(item);
  }
  return box;
}

export function drawCalendar(host,view,rows,range,options={}) {
  host.replaceChildren(); const [from,to]=range;
  const getDate=row=>view.date_prop==='due' ? (row.due?{start:row.due}:null) : row.values && row.values[view.date_prop];
  async function move(row,delta) {
    const value=getDate(row);if(!value||!options.save)return;
    const start=shifted(value.start.slice(0,10),delta); const next={start};if(value.end)next.end=shifted(value.end.slice(0,10),delta);
    await options.save(row.id,view.date_prop,view.date_prop==='due'?start:next);
  }
  function card(row,label) {
    const a=el('a',null,'db-calendar__item');a.href=(options.base||'/community')+'/p/'+row.id;
    // 누름 = 그 작업을 오른쪽 칸으로(화면이 칸을 줄 때만 — 아니면 지금처럼 그 페이지로). 새 탭 · 수정 키는 링크 그대로
    if(typeof options.open==='function'){a.addEventListener('click',e=>{if(e.button!==0||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey)return;e.preventDefault();options.open(row.id);});}
    if(options.edit&&getDate(row)){a.draggable=true;a.addEventListener('dragstart',e=>e.dataTransfer.setData('text/plain',row.id));
      a.addEventListener('keydown',e=>{if(e.key==='ArrowLeft'||e.key==='ArrowRight'){e.preventDefault();move(row,e.key==='ArrowRight'?1:-1).catch(options.error||(()=>{}));}});a.setAttribute('aria-label',(label||row.title)+' · 좌우 화살표로 하루 이동');}
    else if(label){a.setAttribute('aria-label',label);}
    return a;
  }
  if(view.type==='timeline') {
    const list=el('div',null,'db-timeline');const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');
    const height=Math.max(70,rows.length*38+30);svg.setAttribute('viewBox','0 0 720 '+height);svg.setAttribute('role','img');svg.setAttribute('aria-label','선후행 일정');svg.classList.add('db-timeline__graphic');
    const points=new Map(); const span=Math.max(1,(Date.parse(to)-Date.parse(from))/86400000+1);
    rows.forEach((row,i)=>{const d=getDate(row);if(!d)return;const x=160+Math.max(0,(Date.parse(d.start.slice(0,10))-Date.parse(from))/86400000)*550/span;const end=d.end||d.start;const w=Math.max(4,Math.min(710-x,((Date.parse(end.slice(0,10))-Date.parse(d.start.slice(0,10)))/86400000+1)*550/span));const y=i*38+12;points.set(row.id,{x,y,w});
      const label=document.createElementNS(svg.namespaceURI,'text');label.setAttribute('x','4');label.setAttribute('y',String(y+12));label.textContent=row.title.length>18?row.title.slice(0,18)+'…':row.title;svg.append(label);
      const rect=document.createElementNS(svg.namespaceURI,'rect');for(const[k,v]of Object.entries({x,y,width:w,height:18,rx:3}))rect.setAttribute(k,String(v));rect.classList.add('db-timeline__bar');svg.append(rect);
      const item=card(row);item.textContent=row.title;item.append(document.createTextNode(' · '+d.start+(d.end?' ~ '+d.end:'')));list.append(item);});
    if(view.depends_prop)for(const row of rows)for(const dep of (row.values&&row.values[view.depends_prop])||[]){const a=points.get(dep),b=points.get(row.id);if(!a||!b)continue;const line=document.createElementNS(svg.namespaceURI,'path');line.setAttribute('d','M '+(a.x+a.w)+' '+(a.y+9)+' L '+b.x+' '+(b.y+9)+' l -5 -3 m 5 3 l -5 3');line.classList.add('db-timeline__arrow');svg.append(line);}
    if(options.edit){svg.addEventListener('dragover',e=>e.preventDefault());svg.addEventListener('drop',e=>{e.preventDefault();const row=rows.find(r=>r.id===e.dataTransfer.getData('text/plain'));const value=row&&getDate(row);if(!value)return;const bounds=svg.getBoundingClientRect();const x=(e.clientX-bounds.left)*720/bounds.width;const offset=Math.max(0,Math.min(span-1,Math.floor((x-160)*span/550)));const day=shifted(from,offset);move(row,(Date.parse(day)-Date.parse(value.start.slice(0,10)))/86400000).catch(options.error||(()=>{}));});}
    const canvas=el('div',null,'db-timeline-canvas');canvas.append(svg);host.append(canvas,list);return;
  }

  /* ── 달 · 주 격자 ── */
  const today=isDay(options.today)?options.today:seoulToday();
  const tasks=[];
  for (const row of rows) { const t=taskSpans(row,view,today); if (t.spans.length) { tasks.push({row,spans:t.spans,due:t.due,label:t.label}); } }
  const grid=el('div',null,'db-calendar');
  const head=el('div',null,'db-calendar__head');head.setAttribute('aria-hidden','true');
  for(const name of ['일','월','화','수','목','금','토'])head.append(el('span',name,'muted'));
  grid.append(head);
  // 주 줄 — 일요일부터 7칸(달 앞뒤 빈칸은 null)
  const weeks=[]; let week=new Array(new Date(from+'T12:00:00Z').getUTCDay()).fill(null);
  for(let day=from;day<=to;day=shifted(day,1)){week.push(day);if(week.length===7){weeks.push(week);week=[];}}
  if(week.length){while(week.length<7)week.push(null);weeks.push(week);}
  const dueDays=new Set(tasks.filter(t=>t.due).map(t=>t.due));
  for (const days of weeks) {
    const line=el('div',null,'cal-week');
    const first=days.find(Boolean), last=[...days].reverse().find(Boolean);
    days.forEach((day,i)=>{
      if(!day){const blank=el('span',null,'cal-blank cal-col-'+(i+1));blank.setAttribute('aria-hidden','true');line.append(blank);return;}
      const cell=el('section',null,'db-calendar__day cal-col-'+(i+1));cell.setAttribute('aria-label',day);
      if(day===today){cell.setAttribute('aria-current','date');}
      cell.append(el('h4',String(Number(day.slice(8,10))),'muted'));
      if(dueDays.has(day)){const flag=el('span',null,'cal-due');flag.setAttribute('aria-hidden','true');cell.append(flag);}
      if(options.edit){cell.addEventListener('dragover',e=>e.preventDefault());cell.addEventListener('drop',e=>{e.preventDefault();const r=rows.find(x=>x.id===e.dataTransfer.getData('text/plain'));const value=r&&getDate(r);if(value)move(r,(Date.parse(day)-Date.parse(value.start.slice(0,10)))/86400000).catch(options.error||(()=>{}));});}
      line.append(cell);
    });
    // 이 줄에 걸친 조각 — 작업마다 [{kind, c0, c1, cont, more}] (칸 번호 0~6)
    const col=d=>days.indexOf(d);
    const groups=[];
    for (const t of tasks) {
      const segs=[];
      for (const s of t.spans) {
        if (s.to<first||s.from>last) { continue; }
        const a=s.from<first?first:s.from, b=s.to>last?last:s.to;
        segs.push({kind:s.kind,c0:col(a),c1:col(b),cont:s.from<first,more:s.to>last});
      }
      if (segs.length) { groups.push({t,segs,c0:Math.min(...segs.map(s=>s.c0)),c1:Math.max(...segs.map(s=>s.c1))}); }
    }
    groups.sort((x,y)=>x.c0-y.c0||(y.c1-y.c0)-(x.c1-x.c0));
    // 층 — 예정(또는 기한 하루)은 위 층, 실제 · 기한 넘김은 바로 아래 층(한 작업은 붙은 두 층). 비어 있는 가장 낮은 층부터
    const used=[]; const free=(lane,c0,c1)=>{for(let c=c0;c<=c1;c++){if(used[lane]&&used[lane][c])return false;}return true;};
    const take=(lane,c0,c1)=>{used[lane]=used[lane]||[];for(let c=c0;c<=c1;c++)used[lane][c]=true;};
    const hidden=new Array(7).fill(0);
    for (const g of groups) {
      const layers=[g.segs.filter(s=>s.kind==='plan'||s.kind==='base'),g.segs.filter(s=>s.kind==='actual'||s.kind==='late')].filter(x=>x.length);
      let lane0=0; while(!layers.every((segs,k)=>segs.every(s=>free(lane0+k,s.c0,s.c1)))) { lane0++; }
      const over=new Set();
      layers.forEach((segs,k)=>{
        const lane=lane0+k;
        for (const s of segs) {
          take(lane,s.c0,s.c1);
          if (lane>=MAX_LANES) { for(let c=s.c0;c<=s.c1;c++)over.add(c); continue; }
          const bar=card(g.t.row,g.t.label);
          bar.className='db-calendar__item cal-bar cal-bar--'+s.kind+' cal-col-'+(s.c0+1)+' cal-span-'+(s.c1-s.c0+1)+' cal-lane-'+(lane+1)
            +(s.cont?' cal-bar--cont':'')+(s.more?' cal-bar--more':'');
          // 제목은 그 줄에서 막대가 시작하는 조각에만 — 같은 줄에서 실제 막대 뒤에 붙은 「기한 넘김」 조각은 글자 0
          if (!(s.kind==='late'&&segs.some(x=>x.kind==='actual'))) { bar.append(el('span',g.t.row.title,'cal-bar__text')); }
          line.append(bar);
        }
      });
      for (const c of over) { hidden[c]++; }
    }
    hidden.forEach((n,c)=>{ if(n>0&&days[c]){const more=el('span','+'+n,'cal-more cal-col-'+(c+1));more.setAttribute('aria-label','작업 '+n+'개 더 — 목록 보기에서 볼 수 있어요');line.append(more);} });
    grid.append(line);
  }
  host.append(grid);
}
