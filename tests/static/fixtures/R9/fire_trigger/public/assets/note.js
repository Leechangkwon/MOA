// 가짜 메모 편집기 — 입력 규칙과 단축키, 단추 이름
import { keymap } from './vendor/km.js';
const rules = [];
rules.push(makeRule(/^\+\+\s$/, 'bigtitle'));      // ++ 뒤에 빈칸 → 큰 제목
rules.push(makeRule(/^~~~$/, 'rule'));             // ~~~ → 가로줄
const keys = keymap({ 'Mod-z': undo, 'Shift-Mod-z': redo, 'Mod-Alt-1': toTitle });
function bar() {
  addButton('tool-mark', '형광펜');
  addButton('tool-note', '각주');
  addButton('tool-link', '주소 걸기');
}
document.addEventListener('keydown', (e) => {
  if ((e.ctrlKey || e.metaKey) && e.key === 'j') { openJump(); }
});
const tidy = (s) => s.replace(/\/+$/, '');         // 바꾸기용 — 입력 규칙이 아니다
