/** Lossless table atom draft. Only the selected cell owns a ProseMirror view. */
import {
  schema, fromDoc, toDoc, uuid4, COLORS, schemeOkForEditor, colorAllowed, editorDomParser, clipboardDomParser
} from './editor-schema.js?v=a592c4f5caf1';
import {
  renderDoc
} from './doc-render.js?v=a592c4f5caf1';
import {
  EditorState
} from './vendor/pm/prosemirror-state.js?v=5823578fa61a';
import {
  EditorView
} from './vendor/pm/prosemirror-view.js?v=5823578fa61a';
import {
  history, undo, redo
} from './vendor/pm/prosemirror-history.js?v=5823578fa61a';
import {
  keymap
} from './vendor/pm/prosemirror-keymap.js?v=5823578fa61a';
import {
  Slice, Fragment
} from './vendor/pm/prosemirror-model.js?v=5823578fa61a';
const clone = value => JSON.parse(JSON.stringify(value));
const plain = cell => (cell.text || []).map(s => s.t || '').join('');

export function tableFromAttrs(attrs) {
  if (attrs.raw && attrs.raw.type === 'table') {
    const raw=clone(attrs.raw);
    raw.id=attrs.id || raw.id;
    return raw;
  }
  const node = schema.nodes.table.create({...attrs, id: attrs.id || uuid4()});
  return toDoc({type: 'doc', content: [node.toJSON()]}).blocks[0];
}

export function tableAttrs(raw) {
  const spans = {};
  raw.rows.forEach((r,y)=>r.cells.forEach((c,x)=> {
    if ((c.rowspan || 1)>1 || (c.colspan || 1)>1) {
      spans[y+','+x]=[c.rowspan || 1,c.colspan || 1];
    }
  }));
  return {
    id: raw.id,
    raw: clone(raw),
    rows: raw.rows.map(row => row.cells.map(plain)),
    spans,
    header_row: raw.header_row === true,
    header_col: raw.header_col === true
  };
}

export function coveredCells(raw) {
  const set=new Set();
  raw.rows.forEach((r,y)=>r.cells.forEach((c,x)=> {
    for(let yy=y; yy<y+(c.rowspan||1); yy++) {
      for(let xx=x; xx<x+(c.colspan||1); xx++) {
        if(yy!==y||xx!==x)set.add(yy+','+xx);
      }
    }
  }));
  return set;
}

export function outsideSpan(raw,height,width) {
  return raw.rows.some((r,y)=>r.cells.some((c,x)=>y<height&&x<width&&((c.rowspan||1)>1||(c.colspan||1)>1)&&(y+(c.rowspan||1)>height||x+(c.colspan||1)>width)));
}

export function mergeCells(raw,y,x,h,w) {
  if(![y,x,h,w].every(Number.isInteger)||y<0||x<0||h<1||w<1||h>20||w>20)return '합침은 가로·세로 20칸까지 가능합니다.';
  if(y+h>raw.rows.length || x+w>raw.rows[0].cells.length) return '표 밖으로 나갑니다.';
  const draft=clone(raw),own=draft.rows[y].cells[x];
  delete own.rowspan;
  delete own.colspan;
  const covered=coveredCells(draft);
  if(covered.has(y+','+x))return '이미 합쳐진 칸 안입니다.';
  for(let yy=y; yy<y+h; yy++) {
    for(let xx=x; xx<x+w; xx++) {
      if(yy===y&&xx===x)continue;
      const c=draft.rows[yy].cells[xx];
      if(covered.has(yy+','+xx)||Object.keys(c).some(k=>k!=='text')||(c.text||[]).length)return '합칠 칸의 글과 서식을 먼저 비워 주세요. 기존 서식을 지우며 합치지 않습니다.';
    }
  }
  if(h>1)own.rowspan=h;
  if(w>1)own.colspan=w;
  raw.rows=draft.rows;
  return '';
}

export function createTableEditor( {
  pane,grid,note,context=()=>( {
  })
}) {
  let raw=null,picked=null,view=null,changed=false,confirmation=null;
  const section=document.createElement('section');
  section.className='table-cell-editor';
  const label=document.createElement('p');
  label.className='table-cell-editor__label';
  const toolbar=document.createElement('div');
  toolbar.className='table-cell-toolbar toolbar';
  const input=document.createElement('div');
  input.className='table-cell-input doc editor';
  const confirm=document.createElement('section');
  confirm.className='table-delete-confirm notice';
  confirm.hidden=true;
  section.append(label,toolbar,input);
  pane.append(section,confirm);
  function button(text,run) {
    const b=document.createElement('button');
    b.type='button';
    b.className='btn btn--small btn--quiet';
    b.textContent=text;
    b.addEventListener('mousedown',e=>e.preventDefault());
    b.addEventListener('click',run);
    return b;
  }
  function format(name,value) {
    if(!view)return;
    const {
      from,to
    }=view.state.selection;
    if(from===to) {
      note('먼저 꾸밀 글자를 선택하세요.');
      return;
    }
    let tr=view.state.tr.removeMark(from,to,schema.marks[name]);
    if(value)tr=tr.addMark(from,to,schema.marks[name].create(value));
    view.dispatch(tr);
    view.focus();
  }
  for(const [title,suffix]of[['글자색',''],['배경색','_bg']]) {
    const select=document.createElement('select');
    select.className='input';
    select.setAttribute('aria-label',title);
    const base=document.createElement('option');
    base.value='';
    base.textContent=title+' 기본';
    select.append(base);
    const names=['회색','갈색','주황','노랑','초록','파랑','보라','분홍','빨강'];
    COLORS.forEach((c,i)=> {
      const o=document.createElement('option');
      o.value=c+suffix;
      o.textContent=names[i];
      select.append(o);
    });
    select.addEventListener('change',()=>format('color',select.value? {
      color:select.value
    }:null));
    toolbar.append(select);
  }
  const link=document.createElement('input');
  link.type='url';
  link.className='input';
  link.autocomplete='off';
  link.setAttribute('aria-label','선택 글자의 링크');
  link.placeholder='https://';
  link.addEventListener('keydown',e=> {
    if(e.key==='Enter') {
      e.preventDefault();
      e.stopPropagation();
      if(e.isComposing||e.keyCode===229)return;
      if(schemeOkForEditor(link.value))format('link', {
        href:link.value
      });
      else note('허용된 링크 주소를 입력하세요.');
    }
  });
  toolbar.append(link,button('링크 적용',()=> {
    if(!schemeOkForEditor(link.value)) {
      note('허용된 링크 주소를 입력하세요.');
      return;
    }
    format('link', {
      href:link.value
    });
  }),button('링크 해제',()=>format('link',null)),button('되돌리기',()=> {
    if(view)undo(view.state,view.dispatch);
  }),button('다시 하기',()=> {
    if(view)redo(view.state,view.dispatch);
  }));
  function flush() {
    if(view)view.domObserver.flush();
    if(!view||!picked||!changed)return;
    const b=toDoc(view.state.doc.toJSON()).blocks[0];
    raw.rows[picked[0]].cells[picked[1]].text=clone(b.text||[]);
    const button=grid.querySelector('[data-r="'+picked[0]+'"][data-c="'+picked[1]+'"] button');
    if(button)preview(button,raw.rows[picked[0]].cells[picked[1]]);
    changed=false;
  }
  function clearConfirm() {
    confirmation=null;
    confirm.replaceChildren();
    confirm.hidden=true;
  }
  function askDelete(run) {
    clearConfirm();
    confirmation=run;
    confirm.hidden=false;
    const text=document.createElement('p');
    text.textContent='마지막 행·열을 삭제하면 그 칸의 내용과 서식도 삭제됩니다.';
    const actions=document.createElement('div');
    actions.className='toolbar';
    const yes=button('삭제하기',()=> {
      const action=confirmation;
      clearConfirm();
      if(action)action();
    });
    actions.append(yes,button('취소',()=>clearConfirm()));
    confirm.append(text,actions);
    yes.focus();
  }
  function select(y,x,focus=true) {
    flush();
    picked=[y,x];
    changed=false;
    clearConfirm();
    const cell=raw.rows[y].cells[x];
    const d= {
      schema:1,blocks:[ {
        type:'paragraph',id:uuid4(),text:clone(cell.text||[])
      }]
    };
    const state=EditorState.create( {
      schema,doc:schema.nodeFromJSON(fromDoc(d,context())),plugins:[history(),keymap( {
        'Mod-z': undo, 'Mod-y': redo, 'Shift-Mod-z': redo,
        Enter: (state, dispatch) => { if (dispatch) dispatch(state.tr.insertText('\n')); return true; },
        'Shift-Enter': (state, dispatch) => { if (dispatch) dispatch(state.tr.insertText('\n')); return true; }
      })]
    });
    if(!view) {
      view=new EditorView(input, {
        state,attributes: {
          'aria-label':'선택 셀 글자 편집','role':'textbox'
        },domParser:editorDomParser,clipboardParser:clipboardDomParser,dispatchTransaction(tr) {
          view.updateState(view.state.apply(tr));
          if(tr.docChanged)changed=true;
        },transformPasted(slice) {
          const nodes = [];
          slice.content.forEach((node, _offset, index) => {
            if (index > 0) nodes.push(schema.text('\n'));
            if (node.isText) nodes.push(node);
            else if (node.type.name === 'table' && node.attrs.raw) {
              // A cell cannot contain a table atom. Preserve imported cell marks/text
              // in reading order instead of dropping the new leaf node's contents.
              node.attrs.raw.rows.forEach((row, y) => {
                if (y > 0) nodes.push(schema.text('\n'));
                row.cells.forEach((cell, x) => {
                  if (x > 0) nodes.push(schema.text(' | '));
                  const paragraph = schema.nodeFromJSON(fromDoc({ blocks: [{ type: 'paragraph', text: cell.text }] }).content[0]);
                  paragraph.forEach(child => nodes.push(child));
                });
              });
            }
            else node.descendants(child => { if (child.isText) nodes.push(child); });
          });
          return new Slice(Fragment.fromArray(nodes),0,0);
        },handleDOMEvents: {
          click:(_v,e)=> {
            if(e.target.closest('a')) {
              e.preventDefault();
              return true;
            }
            return false;
          }
        }
      });
    } else view.updateState(state);
    label.textContent=(y+1)+'행 '+(x+1)+'열 편집';
    link.value='';
    for(const td of grid.querySelectorAll('td'))td.classList.toggle('is-picked',td.dataset.r===String(y)&&td.dataset.c===String(x));
    if(focus)view.focus();
  }
  function colorClass(color) {
    return colorAllowed(color)?(color.startsWith('pal:')?'pal-'+color.slice(4).replace(/_bg$/,'-bg'):'nt-'+color.replace(/_bg$/,'-bg')):'';
  }
  function preview(button,cell) {
    button.replaceChildren();
    const dom=renderDoc( {
      schema:1,blocks:[ {
        type:'paragraph',text:cell.text||[]
      }]
    },context());
    const p=dom.querySelector('p');
    if(p) {
      while(p.firstChild)button.append(p.firstChild);
    }
    if(!button.textContent)button.textContent='빈 칸';
    for(const a of button.querySelectorAll('a')) {
      a.removeAttribute('href');
      a.tabIndex=-1;
      a.addEventListener('click',e=>e.preventDefault());
    }
  }
  function draw() {
    clearConfirm();
    grid.replaceChildren();
    const covered=coveredCells(raw);
    raw.rows.forEach((row,y)=> {
      const tr=document.createElement('tr');
      tr.className=colorClass(row.color);
      row.cells.forEach((cell,x)=> {
        const td=document.createElement('td');
        td.className='doc-table__cell '+colorClass(cell.color);
        td.dataset.r=String(y);
        td.dataset.c=String(x);
        const b=document.createElement('button');
        b.type='button';
        b.className='table-cell-preview';
        b.setAttribute('aria-label',(y+1)+'행 '+(x+1)+'열');
        b.disabled=covered.has(y+','+x);
        if(b.disabled)b.textContent='(합침)';
        else {
          preview(b,cell);
          b.addEventListener('click',()=>select(y,x));
        }
        td.append(b);
        tr.append(td);
      });
      grid.append(tr);
    });
    if(picked&&raw.rows[picked[0]]&&raw.rows[picked[0]].cells[picked[1]]&&!covered.has(picked.join(',')))select(...picked,false);
    else select(0,0,false);
  }
  function changeShape(kind) {
    flush();
    const h=raw.rows.length,w=raw.rows[0].cells.length;
    if(kind==='row_add') {
      if(h>=200)return;
      raw.rows.push( {
        cells:Array.from( {
          length:w
        },()=>( {
          text:[]
        }))
      });
      draw();
      return;
    }
    if(kind==='col_add') {
      if(w>=20)return;
      for(const r of raw.rows)r.cells.push( {
        text:[]
      });
      if(Array.isArray(raw.col_widths))raw.col_widths.push(120);
      draw();
      return;
    }
    const height=kind==='row_del'?h-1:h,width=kind==='col_del'?w-1:w;
    if(height<1||width<1)return;
    if(outsideSpan(raw,height,width)) {
      note('삭제할 행·열을 가로지르는 합침을 먼저 풀어 주세요.');
      return;
    }
    askDelete(()=> {
      if(kind==='row_del')raw.rows.pop();
      else {
        for(const r of raw.rows)r.cells.pop(); if(Array.isArray(raw.col_widths))raw.col_widths.pop(); }
      draw();
    });
  }
  return {
    open(attrs) {
      this.close();
      raw=tableFromAttrs(attrs);
      draw();
    },collect(headerRow,headerCol) {
      flush();
      if((raw.header_row===true)!==headerRow)raw.header_row=headerRow;
      if((raw.header_col===true)!==headerCol)raw.header_col=headerCol;
      return tableAttrs(raw);
    },changeShape,merge(h,w) {
      flush();
      if(!picked)return;
      const error=mergeCells(raw,...picked,h,w);
      if(error)note(error);
      else draw();
    },unmerge() {
      flush();
      if(!picked)return;
      const c=raw.rows[picked[0]].cells[picked[1]];
      delete c.rowspan;
      delete c.colspan;
      draw();
    },composing() {
      return Boolean(view&&view.composing);
    },close() {
      if(view)view.destroy();
      view=null;
      raw=null;
      picked=null;
      changed=false;
      link.value='';
      input.replaceChildren();
      grid.replaceChildren();
      clearConfirm();
    },get view() {
      return view;
    },get raw() {
      return raw;
    }
  };
}
