/* Local SVG charts. User labels enter textContent only; no CDN or inline CSS. */
export function drawChart(host,chart) {
  host.replaceChildren();const groups=chart.groups||[];
  if(!groups.length){const p=document.createElement('p');p.className='muted';p.textContent='표시할 값이 없습니다';host.append(p);return;}
  if(chart.kind==='number'){const strong=document.createElement('strong');strong.className='db-chart__number';strong.textContent=groups[0].value===null?'—':String(groups[0].value);host.append(strong);}
  else {
    const ns='http://www.w3.org/2000/svg',svg=document.createElementNS(ns,'svg');svg.classList.add('db-chart');svg.setAttribute('viewBox','0 0 640 260');svg.setAttribute('role','img');svg.setAttribute('aria-label','집계 차트');
    const max=Math.max(1,...groups.map(g=>Math.abs(g.value||0)));const baseline=groups.some(g=>(g.value||0)<0)?130:210;const amplitude=baseline===130?100:180;let cumulative=0;const total=groups.reduce((n,g)=>n+Math.max(0,g.value||0),0);const points=[];
    groups.forEach((g,i)=>{const value=g.value||0,x=30+i*580/groups.length,y=baseline-amplitude*value/max;
      if(chart.kind==='donut' && total>0){const fraction=Math.max(0,value)/total;const circle=document.createElementNS(ns,'circle');for(const[k,v]of Object.entries({cx:320,cy:125,r:80,'stroke-width':35,'stroke-dasharray':(fraction*502.655)+' '+502.655,'stroke-dashoffset':-cumulative*502.655}))circle.setAttribute(k,String(v));circle.classList.add('db-chart__slice','db-chart__series-'+(i%5));svg.append(circle);cumulative+=fraction;}
      else if(chart.kind==='line'){points.push(x+','+y);const dot=document.createElementNS(ns,'circle');dot.setAttribute('cx',String(x));dot.setAttribute('cy',String(y));dot.setAttribute('r','4');dot.classList.add('db-chart__point');svg.append(dot);}
      else {const r=document.createElementNS(ns,'rect');for(const[k,v]of Object.entries({x,y:Math.min(baseline,y),width:Math.max(2,560/groups.length),height:Math.max(1,Math.abs(baseline-y))}))r.setAttribute(k,String(v));r.classList.add('db-chart__bar');svg.append(r);}
    });
    if(chart.kind==='line'){const line=document.createElementNS(ns,'polyline');line.setAttribute('points',points.join(' '));line.classList.add('db-chart__line');svg.append(line);}host.append(svg);
  }
  const table=document.createElement('table');table.className='table';const body=document.createElement('tbody');for(const g of groups){const tr=document.createElement('tr');for(const value of [g.label,g.value===null?'—':String(g.value)]){const td=document.createElement('td');td.textContent=value;tr.append(td);}body.append(tr);}table.append(body);host.append(table);
}
