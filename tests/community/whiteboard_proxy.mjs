/** Disposable same-origin HTTP/WebSocket proxy for real browser integration tests only. */
import http from 'node:http';
const server=http.createServer((req,res)=>{
 const upstream=http.request({hostname:'127.0.0.1',port:8768,path:req.url,method:req.method,headers:req.headers},response=>{
   res.writeHead(response.statusCode,response.headers);response.pipe(res);
 });
 upstream.on('error',()=>{res.writeHead(502);res.end();});req.pipe(upstream);
});
server.on('upgrade',(req,socket,head)=>{
 if(req.url!=='/community/whiteboard-relay') {socket.end('HTTP/1.1 404 Not Found\r\n\r\n');return;}
 const upstream=http.request({hostname:'127.0.0.1',port:18093,path:req.url,method:'GET',headers:req.headers});
 upstream.on('upgrade',(response,peer,peerHead)=>{
   const headers=Object.entries(response.headers).map(([k,v])=>k+': '+v).join('\r\n');
   socket.write('HTTP/1.1 101 Switching Protocols\r\n'+headers+'\r\n\r\n');
   if(peerHead.length) {socket.write(peerHead);}if(head.length) {peer.write(head);}
   socket.pipe(peer);peer.pipe(socket);socket.on('error',()=>peer.destroy());peer.on('error',()=>socket.destroy());
 });
 upstream.on('response',response=>{socket.end('HTTP/1.1 '+response.statusCode+' Forbidden\r\n\r\n');});
 upstream.on('error',()=>socket.destroy());upstream.end();
});
server.listen(18868,'127.0.0.1',()=>process.stdout.write('READY\n'));
process.on('SIGTERM',()=>{server.close();process.exit(0);});
