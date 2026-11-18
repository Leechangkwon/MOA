import { build } from 'esbuild';
import { createHash } from 'node:crypto';
import * as fs from 'node:fs/promises';
import * as path from 'node:path';
import * as os from 'node:os';
import { fileURLToPath } from 'node:url';

const here=path.dirname(fileURLToPath(import.meta.url));
const root=path.resolve(here,'../..');
const verify=process.argv.includes('--verify');
const temporary=await fs.mkdtemp(path.join(os.tmpdir(),'moa-whiteboard-build-'));
const output=path.join(temporary,'excalidraw');
await fs.mkdir(output);
const sha = bytes => createHash('sha256').update(bytes).digest('hex');
try {
  const bundle=await build({entryPoints:[path.join(here,'adapter.jsx')],outdir:output,entryNames:'editor',splitting:true,chunkNames:'chunks/[name]-[hash]',metafile:true,
    absWorkingDir:here,bundle:true,format:'esm',platform:'browser',target:['es2022'],minify:true,conditions:['production'],
    define:{'process.env.NODE_ENV':'"production"'},loader:{'.woff2':'file'},assetNames:'fonts/[name]-[hash]',legalComments:'eof',logLevel:'warning'});
  const shipped=[...new Set(Object.values(bundle.metafile.outputs).flatMap(o=>Object.entries(o.inputs).filter(([k,v])=>v.bytesInOutput>0).map(([k])=>k.replace(here+'/', ''))))].sort();
  await fs.writeFile(path.join(output,'BUNDLE-MODULES.json'),JSON.stringify(shipped,null,2)+'\n');
  async function localFonts(dir) {
    for(const item of await fs.readdir(dir,{withFileTypes:true})) {
      const file=path.join(dir,item.name);
      if(item.isDirectory()) { await localFonts(file); }
      else if(item.name.endsWith('.js')) {
        const source=await fs.readFile(file,'utf8');
        const replaced=source.replace(/(ASSETS_FALLBACK_URL",)`https:\/\/esm\.sh\/.*?\/dist\/prod\/`/g, '$1window.location.origin+"/tbox/assets/community/vendor/excalidraw/"');
        if(replaced.includes('https://esm.sh/')) { throw new Error('Unpatched external font fallback'); }
        await fs.writeFile(file,replaced);
      }
    }
  }
  await localFonts(output);
  await fs.cp(path.join(here,'node_modules/@excalidraw/excalidraw/dist/prod/fonts'),path.join(output,'fonts'),{recursive:true});
  await build({entryPoints:[path.join(root,'relay/whiteboard-relay.mjs')],outfile:path.join(temporary,'whiteboard-runtime.mjs'),
    absWorkingDir:here,bundle:true,format:'esm',platform:'node',target:'node24',minify:false,legalComments:'eof',
    banner:{js:"import { createRequire } from 'node:module'; const require = createRequire(import.meta.url);"},nodePaths:[path.join(here,'node_modules')],logLevel:'warning'});
  const lock=JSON.parse(await fs.readFile(path.join(here,'package-lock.json'),'utf8'));
  const artifacts=[];
  for(const name of ['@excalidraw/excalidraw','react','react-dom','ws','esbuild']) {
    const p=lock.packages['node_modules/'+name];
    if(!p.resolved.startsWith('https://registry.npmjs.org/')) { throw new Error('Unexpected registry'); }
    const response=await fetch(p.resolved,{redirect:'error',signal:AbortSignal.timeout(15000)});
    if(!response.ok) { throw new Error('Tarball unavailable'); }
    const bytes=Buffer.from(await response.arrayBuffer());
    const [algorithm,want]=p.integrity.split('-');
    if(createHash(algorithm).update(bytes).digest('base64')!==want) { throw new Error('Tarball integrity mismatch'); }
    artifacts.push({name,version:p.version,url:p.resolved,sha256:sha(bytes)});
  }
  await fs.writeFile(path.join(output,'ARTIFACTS.json'),JSON.stringify(artifacts,null,2)+'\n');
  const packages=[];
  for(const [location,p] of Object.entries(lock.packages)) {
    if(!location || p.os && !p.os.includes('linux')) { continue; }
    packages.push({name:location.replace(/^.*node_modules\//,''),version:p.version,license:p.license,integrity:p.integrity});
  }
  await fs.writeFile(path.join(output,'PACKAGES.json'),JSON.stringify(packages,null,2)+'\n');
  const licenses=[];
  for(const name of ['@excalidraw/excalidraw','react','react-dom','ws','esbuild']) {
    const base=path.join(here,'node_modules',name), p=JSON.parse(await fs.readFile(path.join(base,'package.json'),'utf8'));
    const licenseFile=(await fs.readdir(base)).find(n=>/^licen[sc]e(?:\.md|\.txt)?$/i.test(n));
    licenses.push(name+' '+p.version+' ('+p.license+')\n'+(licenseFile ? await fs.readFile(path.join(base,licenseFile),'utf8') : 'See package license.'));
  }
  await fs.writeFile(path.join(output,'LICENSES.txt'),licenses.join('\n\n'));
  const files=[];
  async function walk(dir,prefix='') { for(const ent of (await fs.readdir(dir,{withFileTypes:true})).sort((a,b)=>a.name.localeCompare(b.name))) {
    if(ent.isDirectory()) { await walk(path.join(dir,ent.name),prefix+ent.name+'/'); }
    else { files.push({file:prefix+ent.name,sha256:sha(await fs.readFile(path.join(dir,ent.name)))}); }
  }}
  await walk(output);
  const manifest={packages:packages.filter(p=>['@excalidraw/excalidraw','react','react-dom','ws','esbuild'].includes(p.name)),files,
    relay_sha256:sha(await fs.readFile(path.join(temporary,'whiteboard-runtime.mjs')))};
  await fs.writeFile(path.join(output,'MANIFEST.json'),JSON.stringify(manifest,null,2)+'\n');
  const target=path.join(root,'public/assets/community/vendor/excalidraw');
  if(verify) {
    for(const f of [...files,{file:'MANIFEST.json',sha256:sha(await fs.readFile(path.join(output,'MANIFEST.json')))}]) {
      if(sha(await fs.readFile(path.join(target,f.file)))!==f.sha256) { throw new Error('Vendor mismatch: '+f.file); }
    }
    if(sha(await fs.readFile(path.join(root,'relay/whiteboard-runtime.mjs')))!==manifest.relay_sha256) { throw new Error('Relay mismatch'); }
    process.stdout.write('Vendor rebuild: byte-for-byte verified\n');
  } else {
    await fs.rm(target,{recursive:true,force:true});
    await fs.mkdir(target,{recursive:true}); await fs.cp(output,target,{recursive:true});
    await fs.copyFile(path.join(temporary,'whiteboard-runtime.mjs'),path.join(root,'relay/whiteboard-runtime.mjs'));
    process.stdout.write('Vendored '+files.length+' files; lockfile integrity retained\n');
  }
} finally { await fs.rm(temporary,{recursive:true,force:true}); }
