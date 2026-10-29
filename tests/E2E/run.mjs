import https from 'node:https';
import http from 'node:http';
import net from 'node:net';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync, spawn } from 'node:child_process';

const wordpress = process.env.SWSL_TEST_WORDPRESS;
if (!wordpress) throw new Error('Set SWSL_TEST_WORDPRESS to the disposable WordPress site.');
const temporary = mkdtempSync(join(tmpdir(),'swsl-e2e-'));
let php, proxy;
try {
 execFileSync('openssl',['req','-x509','-newkey','rsa:2048','-nodes','-keyout',join(temporary,'key.pem'),'-out',join(temporary,'cert.pem'),'-days','1','-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost'],{stdio:'ignore'});
 php=spawn('php',['-S','127.0.0.1:8089','-t',wordpress,fileURLToPath(new URL('./router.php',import.meta.url))],{stdio:'ignore'});
 await new Promise((resolve,reject)=>{
  let attempts=0;
  const connect=()=>{
   if (php.exitCode!==null) return reject(new Error('The disposable PHP server did not start.'));
   const socket=net.connect(8089,'127.0.0.1',()=>{socket.destroy();resolve();});
   socket.on('error',()=>{
    socket.destroy();
    if (++attempts>100) reject(new Error('The disposable PHP server was not ready.'));
    else setTimeout(connect,100);
   });
  };
  connect();
 });
 proxy=https.createServer({key:readFileSync(join(temporary,'key.pem')),cert:readFileSync(join(temporary,'cert.pem'))},(request,response)=>{
  const upstream=http.request({host:'127.0.0.1',port:8089,path:request.url,method:request.method,headers:request.headers},result=>{
   response.writeHead(result.statusCode,result.headers);result.pipe(response);
  });
  upstream.on('error',()=>{response.writeHead(502);response.end();});
  request.pipe(upstream);
 });
 await new Promise((resolve,reject)=>{proxy.once('error',reject);proxy.listen(8443,'127.0.0.1',resolve);});
 const status=await new Promise((resolve,reject)=>{
  const child=spawn(process.execPath,[fileURLToPath(new URL('./passkeys.mjs',import.meta.url))],{stdio:'inherit',env:{...process.env,SWSL_TEST_ORIGIN:'https://localhost:8443'}});
  child.once('error',reject);child.once('exit',code=>resolve(code));
 });
 if (status!==0) throw new Error('Passkey browser tests failed.');
} finally {
 if (proxy) {proxy.closeAllConnections();proxy.close();}
 if (php) php.kill('SIGTERM');
 rmSync(temporary,{recursive:true,force:true});
}
