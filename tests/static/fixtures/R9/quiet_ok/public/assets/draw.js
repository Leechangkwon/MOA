// 가짜 렌더러 — 단계 2 까지 전부 다룬다(9 단계 table 은 아직)
function draw(n) { switch (n.kind) { case 'para': return p(n); case 'title': return h(n); case 'quote': return q(n); } }
