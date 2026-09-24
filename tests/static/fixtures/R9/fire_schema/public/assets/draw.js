function draw(n) { switch (n.kind) { case 'para': return p(n); case 'title': return h(n); case 'quote': return q(n); case 'tabel': return t(n); } }
