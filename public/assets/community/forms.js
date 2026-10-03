/* Stage 11: authenticated form. Server is the sole authority for fields and submission. */
'use strict';
(function () {
  const form = document.getElementById('community-response-form');
  if (!form) return;
  const base = document.body.dataset.communityBase || '/community';
  const endpoint = base + '/api/forms/' + form.dataset.database + '/' + form.dataset.view;
  const status = document.getElementById('response-status');
  const submit = document.getElementById('response-submit');
  const inputs = new Map(); let definition = null; let csrf = '';
  const node = (tag, text, cls) => { const e = document.createElement(tag); if (text) e.textContent = text; if (cls) e.className = cls; return e; };
  async function request(url, options) {
    const r = await fetch(url, Object.assign({credentials:'same-origin'}, options || {}));
    const body = await r.json(); if (!r.ok) throw new Error(body.error || '요청을 처리할 수 없습니다'); return body;
  }
  function value(q) {
    const e = inputs.get(q.prop).input;
    if (q.type === 'checkbox') return e.checked;
    if (q.type === 'number') return e.value === '' ? null : Number(e.value);
    if (q.type === 'date') return e.value === '' ? null : {start:e.value};
    if (q.type === 'multi_select') return Array.from(e.selectedOptions).map(o=>o.value);
    return e.value === '' ? null : e.value;
  }
  function conditions() {
    for (const q of definition.questions) {
      const field = inputs.get(q.prop);
      const parent = q.show_if && definition.questions.find(p=>p.prop === q.show_if.prop);
      field.wrap.hidden = !!parent && value(parent) !== q.show_if.equals;
      field.input.required = q.required && !field.wrap.hidden;
      field.input.disabled = field.wrap.hidden;
    }
  }
  function build(q) {
    const wrap = node('div',null,'field'); const label = node('label',q.name + (q.required ? ' *' : ''),'field__label');
    const input = node(['select','multi_select'].includes(q.type)?'select':'input',null,'input');
    input.id = 'answer-' + q.prop; label.htmlFor = input.id;
    if (input.tagName === 'SELECT') {
      input.multiple = q.type === 'multi_select'; if (!input.multiple) { const blank=node('option','선택하세요'); blank.value=''; input.append(blank); }
      for (const v of q.options) { const option=node('option',v); option.value=v; input.append(option); }
    } else {
      input.type = ({checkbox:'checkbox',number:'number',date:'date',url:'url',email:'email',phone:'tel'})[q.type] || 'text';
      if (q.type === 'number') input.step='any'; if (input.type === 'text') input.maxLength=q.prop==='title'?200:2000;
    }
    wrap.append(label,input); inputs.set(q.prop,{wrap,input}); input.addEventListener('change',conditions); return wrap;
  }
  form.addEventListener('submit',async e=>{
    e.preventDefault(); if (!definition || !form.reportValidity()) return; submit.disabled=true; status.textContent='보내는 중입니다';
    try {
      const values={}; for (const q of definition.questions) if (!inputs.get(q.prop).wrap.hidden) values[q.prop]=value(q);
      const result=await request(endpoint+'/submit',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({values})});
      status.textContent='응답을 받았습니다';
      if (result.url) { const a=node('a','내 응답 보기'); a.href=result.url; status.append(document.createTextNode(' '),a); }
      document.getElementById('response-questions').hidden=true; submit.hidden=true;
    } catch (err) { status.textContent=err.message; submit.disabled=false; }
  });
  Promise.all([request(endpoint),request((document.body.dataset.base||'/tbox')+'/api/me')]).then(([data,me])=>{
    definition=data.form; csrf=me.csrf_token || me.csrf || '';
    document.getElementById('response-intro').textContent=definition.intro;
    document.getElementById('response-privacy').textContent=definition.anonymous?'이 폼은 화면에서 이름을 가립니다. 폼 주인·관리자·감사 기록에는 이름이 남습니다.':'응답 후 내 응답을 확인할 수 있습니다.';
    for (const q of definition.questions) document.getElementById('response-questions').append(build(q)); conditions();
  }).catch(err=>{status.textContent=err.message;submit.disabled=true;});
})();
