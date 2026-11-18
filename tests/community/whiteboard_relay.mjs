/** Exercise the real relay binary, signed tickets and actual WebSocket connections on loopback. */
import { spawn } from 'node:child_process';
import { createHmac, randomBytes } from 'node:crypto';
import { once } from 'node:events';
import assert from 'node:assert/strict';
import WebSocket from '../../tools/whiteboard/node_modules/ws/wrapper.mjs';
import { setTimeout as delay } from 'node:timers/promises';
const secret=randomBytes(32).toString('hex'), origin='http://127.0.0.1:18093', port=18093;
const child=spawn(process.execPath,['relay/whiteboard-runtime.mjs'],{env:{...process.env,WHITEBOARD_RELAY_SECRET:secret,
  WHITEBOARD_ORIGIN:origin,WHITEBOARD_PORT:String(port)},stdio:['ignore','pipe','pipe']});
let checks=0; const sockets=[];
const check=(ok,name)=>{ assert.ok(ok,name); checks++; process.stdout.write('PASS '+name+'\n'); };
const ticket=(changes={})=>{
 const p={v:1,room:'11111111-1111-4111-a111-111111111111',uid:1,name:'검토 계정',origin,exp:Math.floor(Date.now()/1000)+60,draw:true,nonce:randomBytes(16).toString('hex'),...changes};
 const encoded=Buffer.from(JSON.stringify(p)).toString('base64url');
 return encoded+'.'+createHmac('sha256',secret).update(encoded).digest('hex');
};
async function connect(value=ticket(),header=origin) {
 const ws=new WebSocket('ws://127.0.0.1:'+port+'/community/whiteboard-relay',{origin:header});
 sockets.push(ws); ws.on('error',()=>{}); await once(ws,'open');
 ws.send(JSON.stringify({type:'auth',ticket:value}));return ws;
}
async function denied(value,label) {
 const ws=await connect(value); const [code]=await once(ws,'close'); check(code===1008,label);
}
try {
 await delay(400); if(child.exitCode!==null) { throw new Error('Relay startup failed'); }
 const a=await connect(); const first=JSON.parse(String((await once(a,'message'))[0]));check(first.type==='ready','valid ticket accepted');
 const b=await connect(ticket({uid:2,name:'동료'})); await once(b,'message');
 const c=await connect(ticket({uid:3,room:'22222222-2222-4222-a222-222222222222'}));await once(c,'message');
 let crossed=false;c.on('message',()=>{crossed=true;});
 const delivered=once(b,'message');a.send(JSON.stringify({type:'pointer',x:5,y:7,uid:99,name:'위조'}));
 const pointer=JSON.parse(String((await delivered)[0]));
 check(pointer.uid===1 && pointer.name==='검토 계정' && pointer.x===5,'relay replaces forged identity with signed identity');
 await delay(50);check(!crossed,'different rooms never receive content');
 const replayTicket=ticket({uid:6});const original=await connect(replayTicket);await once(original,'message');await denied(replayTicket,'one-time ticket replay rejected');
 await denied(ticket({exp:Math.floor(Date.now()/1000)-1}),'expired ticket rejected');
 await denied(ticket({origin:'https://other.invalid'}),'ticket origin mismatch rejected');
 const signed=ticket(); await denied(signed.slice(0,-1)+(signed.endsWith('a')?'b':'a'),'tampered signature rejected');
 const read=await connect(ticket({uid:4,draw:false}));await once(read,'message');
 const deniedClose=once(read,'close');read.send(JSON.stringify({type:'preview',elements:[]}));
 check((await deniedClose)[0]===1008,'read-only participant cannot send a drawing preview');
 const reauth=once(a,'message');a.send(JSON.stringify({type:'auth',ticket:ticket({draw:false})}));await reauth;
 const revoke=once(a,'close');a.send(JSON.stringify({type:'committed',seq:1}));
 check((await revoke)[0]===1008,'reauth immediately narrows drawing capability');
 const binary=await connect(ticket({uid:5}));await once(binary,'message');
 const binaryClose=once(binary,'close');binary.send(Buffer.from([1,2,3]));check((await binaryClose)[0]===1008,'binary payload rejected');
 let badOrigin=false;
 try { await connect(ticket(),'https://other.invalid'); } catch { badOrigin=true; }
 check(badOrigin,'WebSocket handshake checks Origin before upgrade');
 for(let n=0;n<20;n++) {const limited=await connect(ticket({uid:80}));await once(limited,'message');}
 await denied(ticket({uid:80,room:'33333333-3333-4333-a333-333333333333'}),'per-account connection cap safely rejects a different room');
 check(child.exitCode===null,'rejected traffic does not crash relay');
 process.stdout.write('RESULT pass='+checks+' fail=0\n');
} finally {
 for(const ws of sockets) { ws.terminate(); }
 child.kill('SIGTERM');await once(child,'exit');
}
