/** Q:F-17 ephemeral room relay. No DB, filesystem writes, content logs or outbound connections. */
import { createServer } from 'node:http';
import { createHmac, timingSafeEqual } from 'node:crypto';
import { WebSocketServer, WebSocket } from 'ws';

const secret = process.env.WHITEBOARD_RELAY_SECRET || '';
const origin = process.env.WHITEBOARD_ORIGIN || '';
const port = Number(process.env.WHITEBOARD_PORT || 8093);
if (secret.length < 32 || !/^https?:\/\/[a-zA-Z0-9.:-]+$/.test(origin) || !Number.isInteger(port) || port < 1024 || port > 65535) {
  throw new Error('Whiteboard relay configuration unavailable');
}
const server = createServer((req, res) => { res.writeHead(404); res.end(); });
const wss = new WebSocketServer({ noServer: true, maxPayload: 262144, perMessageDeflate: false });
const rooms = new Map();
const usedTickets = new Map();
const verify = (ticket) => {
  if (typeof ticket !== 'string' || ticket.length > 2048) { throw new Error('ticket'); }
  const [encoded, signature, extra] = ticket.split('.');
  if (extra !== undefined || !/^[A-Za-z0-9_-]+$/.test(encoded) || !/^[a-f0-9]{64}$/.test(signature)) { throw new Error('ticket'); }
  const expected = createHmac('sha256', secret).update(encoded).digest();
  if (!timingSafeEqual(expected, Buffer.from(signature, 'hex'))) { throw new Error('ticket'); }
  const p = JSON.parse(Buffer.from(encoded, 'base64url').toString('utf8'));
  const now = Math.floor(Date.now() / 1000);
  if (p.v !== 1 || p.origin !== origin || !Number.isSafeInteger(p.exp) || p.exp <= now || p.exp > now + 65
      || typeof p.room !== 'string' || !/^[a-f0-9-]{36}$/.test(p.room) || !Number.isSafeInteger(p.uid) || p.uid < 1
      || typeof p.name !== 'string' || p.name.length > 100 || typeof p.draw !== 'boolean') { throw new Error('ticket'); }
  if (typeof p.nonce !== 'string' || !/^[a-f0-9]{32}$/.test(p.nonce) || usedTickets.has(p.nonce) || usedTickets.size>=10000) { throw new Error('ticket replay'); }
  return p;
};
server.on('upgrade', (req, socket, head) => {
  if (req.headers.origin !== origin || !['/community/whiteboard-relay','/'].includes(req.url) || wss.clients.size >= 500) {
    socket.end('HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n'); return;
  }
  wss.handleUpgrade(req, socket, head, ws => wss.emit('connection', ws));
});
wss.on('connection', ws => {
  let identity = null, room = null, windowAt = Date.now(), messages = 0, bytes = 0;
  const authTimer = setTimeout(() => ws.close(1008, 'Authentication required'), 5000);
  ws.on('error', () => {});
  ws.on('message', (buffer, binary) => {
    try {
      if (binary) { throw new Error('binary'); }
      if (Date.now() - windowAt >= 1000) { windowAt = Date.now(); messages = bytes = 0; }
      if (++messages > 80 || (bytes += buffer.length) > 1048576) { throw new Error('limit'); }
      const message = JSON.parse(buffer.toString('utf8'));
      if (!identity || message.type === 'auth') {
        if (message.type !== 'auth') { throw new Error('auth'); }
        const next = verify(message.ticket);
        if (identity && (next.uid !== identity.uid || next.room !== identity.room)) { throw new Error('room'); }
        if (!identity) {
          room = rooms.get(next.room) || new Set();
          if (room.size >= 100 || [...wss.clients].filter(c => c.identity?.uid === next.uid).length >= 20) { throw new Error('room full'); }
          rooms.set(next.room, room); room.add(ws);
        }
        usedTickets.set(next.nonce,next.exp);
        identity = next; ws.identity = identity; clearTimeout(authTimer);
        ws.send(JSON.stringify({ type: 'ready', uid: identity.uid, expires_at: identity.exp })); return;
      }
      if (identity.exp <= Math.floor(Date.now()/1000)) { throw new Error('expired'); }
      if (!['pointer','preview','committed'].includes(message.type) || (!identity.draw && message.type !== 'pointer')) { throw new Error('permission'); }
      const out = { type: message.type, uid: identity.uid, name: identity.name };
      if (message.type === 'pointer') {
        if (!Number.isFinite(message.x) || !Number.isFinite(message.y) || Math.abs(message.x)>1e7 || Math.abs(message.y)>1e7) { throw new Error('pointer'); }
        out.x=message.x; out.y=message.y; out.button=message.button==='down' ? 'down' : 'up';
      } else if (message.type === 'committed') {
        if (!Number.isSafeInteger(message.seq) || message.seq < 0) { throw new Error('seq'); }
        out.seq=message.seq;
      } else {
        if (!Array.isArray(message.elements) || message.elements.length>200 || buffer.length>131072) { throw new Error('preview'); }
        out.elements=message.elements;
        if (Number.isSafeInteger(message.sent_at) && message.sent_at>=0 && message.sent_at<1e13) { out.sent_at=message.sent_at; }
      }
      const text=JSON.stringify(out);
      for (const peer of room) {
        if (peer !== ws && peer.readyState === WebSocket.OPEN) {
          if (peer.bufferedAmount>1048576) { peer.close(1013,'Slow consumer'); }
          else { peer.send(text); }
        }
      }
    } catch { ws.close(1008, 'Invalid message'); }
  });
  ws.on('close', () => {
    clearTimeout(authTimer);
    if (room && identity) { room.delete(ws); if (!room.size) { rooms.delete(identity.room); } }
  });
});
const heartbeat=setInterval(() => {
  const now=Math.floor(Date.now()/1000);
  for (const [nonce,expiry] of usedTickets) { if (expiry<=now) { usedTickets.delete(nonce); } }
  for (const ws of wss.clients) {
    if (ws.identity && ws.identity.exp<=now) { ws.close(1008,'Ticket expired'); }
    else { ws.ping(); }
  }
},10000);
server.listen(port,'127.0.0.1');
const stop=() => { clearInterval(heartbeat); for(const ws of wss.clients) { ws.terminate(); } wss.close(); server.close(); };
process.on('SIGTERM',stop); process.on('SIGINT',stop);
