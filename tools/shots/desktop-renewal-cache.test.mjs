import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { readFile, mkdtemp, writeFile, rm, mkdir } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

// A real HTTP cache is essential here. Playwright routing disables that cache.
// The fixture is the pre-renewal production script from bfb5fb6. No actual account,
// proof, provider, computer or production endpoint participates in this test.
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const scratch=await mkdtemp(path.join(tmpdir(),'desktop-renewal-cache-'));
assert.equal(path.dirname(path.resolve(scratch)),path.resolve(tmpdir()));
assert.ok(path.basename(scratch).startsWith('desktop-renewal-cache-'));
await writeFile(path.join(scratch,'render.php'),String.raw`<?php
function cfg(string $key,mixed $default=null):mixed{return $default;}
function portal_csrf_token():string{return str_repeat('c',64);}
function portal_action_nonce(string $purpose,?int $now=null):string{return str_repeat('d',64);}
require $argv[1].'/app/lib/portal_data.php';
require $argv[1].'/app/lib/portal_render.php';
$_SERVER['REQUEST_URI']='/portal/';
$_SESSION['desktop_companion_session']=str_repeat('a',32);
portal_render_workspace(['identity'=>['display_name'=>'Alex Morgan','role'=>'client_admin'],'binding'=>['client_name'=>'Northwind Studio']]);
`);
const rendered=spawnSync('php',[path.join(scratch,'render.php'),root],{encoding:'utf8'});
assert.equal(rendered.status,0,rendered.stderr);
const html=rendered.stdout,assetPath='/assets/js/portal-desktop.js';
const assetUrl=html.match(/src="([^"\s]*\/portal-desktop\.js\?[^"\s]+)"/)?.[1];
assert.ok(assetUrl,'production workspace includes the desktop controller');
const requests=[],errors=[],external=[];
let serveLegacy=false,proofAvailable=true,denyRenewal=false,renewals=0,denials=0,stopped=false;
const currentDesktop=await readFile(path.join(root,'app/public',assetPath));
const legacyDesktop=await readFile(path.join(root,'tools/shots/fixtures/portal-desktop-pre-renewal.js'));
const server=createServer(async(req,res)=>{
  try{
    const url=new URL(req.url,'http://localhost'),record={method:req.method,url:req.url};requests.push(record);
    if(url.pathname==='/seed'){
      res.writeHead(200,{'Content-Type':'text/html','Cache-Control':'no-store'});
      return res.end('<!doctype html><title>Prior cached asset</title><script src="'+assetPath+'?v=2"></script>');
    }
    if(url.pathname==='/portal/'){
      res.writeHead(200,{'Content-Type':'text/html','Cache-Control':'no-store'});return res.end(html);
    }
    if(url.pathname==='/portal/desktop_sessions.php'){
      let raw='';for await(const chunk of req)raw+=chunk;
      const input=JSON.parse(raw);record.action=input.action;
      assert.equal(req.headers['x-portal-csrf'],'c'.repeat(64));
      assert.deepEqual(Object.keys(input),['action'],'list/renew derives identity entirely on the server');
      res.setHeader('Content-Type','application/json');res.setHeader('Cache-Control','no-store');
      if(input.action==='list')return res.end(JSON.stringify({ok:true,result:{renewal_available:proofAvailable,items:[{
        session_id:'a'.repeat(32),device_name:'Synthetic computer',state:stopped?'stopped':'paired',
        connected:true,expires_at:Math.floor(Date.now()/1000)+1800,
      }]}}));
      assert.equal(input.action,'renew','availability cannot start/stop/replay a computer action');
      if(denyRenewal){denials++;res.statusCode=403;return res.end(JSON.stringify({ok:false,reason:'renewal_unavailable'}));}
      assert.equal(proofAvailable,true,'no browser assertion can manufacture proof');renewals++;
      return res.end(JSON.stringify({ok:true,result:{renewed:true,expires_at:Math.floor(Date.now()/1000)+1800}}));
    }
    if(url.pathname==='/portal/westy.php'){
      assert.equal(req.method,'GET','test must not submit inference');
      const state=url.searchParams.has('devices')?{devices:[]}:url.search?{}:{enabled:true,ai_available:true,
        can_write:true,tools_enabled:false,conversation:'b'.repeat(32),conversations:[],turns:[],draft:null};
      res.writeHead(200,{'Content-Type':'application/json','Cache-Control':'no-store'});return res.end(JSON.stringify({ok:true,state}));
    }
    if(url.pathname.startsWith('/assets/')){
      let body,file=path.resolve(root,'app/public',url.pathname.slice(1));
      assert.ok(file.startsWith(path.resolve(root,'app/public/assets')+path.sep));
      if(url.pathname===assetPath)body=serveLegacy?legacyDesktop:currentDesktop;
      else{
        if(url.pathname==='/assets/brand/favicon.svg')file=path.join(root,'brand/svg/favicon.svg');
        if(url.pathname==='/assets/brand/safeharbor-logo-horizontal-transparent-20260909.png')file=path.join(root,'brand/png/safeharbor-logo-horizontal-transparent-20260909.png');
        body=await readFile(file);
      }
      res.writeHead(200,{'Content-Type':({'.js':'text/javascript','.css':'text/css','.svg':'image/svg+xml','.png':'image/png'})[path.extname(file)]||'application/octet-stream',
        'Cache-Control':'public, max-age=604800'});return res.end(body);
    }
    if(url.pathname==='/favicon.ico'){res.writeHead(204);return res.end();}
    throw new Error('Unexpected test route '+url.pathname);
  }catch(error){errors.push(error.message);res.writeHead(500);res.end('Synthetic fixture failure');}
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const origin='http://127.0.0.1:'+server.address().port;
const browser=await chromium.launch({headless:true});
const screenshots=process.env.DESKTOP_RENEWAL_SCREENSHOTS;
if(screenshots)await mkdir(screenshots,{recursive:true});
const open=async(viewport={width:1440,height:900})=>{
  const context=await browser.newContext({viewport}),page=await context.newPage();
  page.on('pageerror',e=>errors.push(e.message));
  page.on('console',m=>{if(['error','warning'].includes(m.type())&&!m.text().includes('403'))errors.push(m.text());});
  page.on('request',request=>{if(new URL(request.url()).origin!==origin)external.push(request.url());});
  await page.clock.install();return {context,page};
};
const settle=page=>page.waitForFunction(()=>document.querySelector('#portal-desktop-controls')?.getAttribute('aria-busy')==='false'
  &&!document.querySelector('#portal-chat-input').disabled);
const renewalResponse=page=>page.waitForResponse(response=>new URL(response.url()).pathname==='/portal/desktop_sessions.php'
  &&response.request().postDataJSON()?.action==='renew',{timeout:3000});
try{
  await test('fresh current script renews a normal Companion workspace with collapsed controls',async()=>{
    const {context,page}=await open();const before=renewals,response=renewalResponse(page);
    await page.goto(origin+'/portal/?fresh=1');await page.clock.runFor(1600);await settle(page);
    await page.waitForFunction(()=>document.querySelector('#portal-desktop-session').options.length===2);
    assert.equal(page.url(),origin+'/portal/?fresh=1');assert.match(await page.title(),/Westy/);
    assert.equal(await page.locator('#portal-chat-panel').isVisible(),true);
    assert.equal(await page.locator('#portal-desktop-controls').evaluate(el=>el.open),false);
    assert.equal((await response).status(),200);
    assert.equal(renewals,before+1,'collapsed details do not prevent the first signed renewal');
    await context.close();
  });
  await test('cached pre-renewal asset cannot suppress renewal after a source release',async()=>{
    const {context,page}=await open();serveLegacy=true;
    await page.goto(origin+'/seed');const oldFetches=requests.filter(r=>r.url===assetPath+'?v=2').length;
    serveLegacy=false;const before=renewals,response=renewalResponse(page);
    const currentFetches=requests.filter(r=>r.url===assetUrl).length;
    await page.goto(origin+'/portal/?updated=1');await page.clock.runFor(6500);
    await settle(page);
    assert.equal(requests.filter(r=>r.url===assetPath+'?v=2').length,oldFetches,'the prior v=2 response remains in the actual browser cache');
    assert.equal((await response).status(),200,'new source must load renewal code even with the old seven-day cached asset');
    assert.equal(renewals,before+1,'new source must load renewal code even with the old seven-day cached asset');
    assert.equal(await page.locator('#portal-desktop-controls').evaluate(el=>el.open),false);
    assert.equal(requests.filter(r=>r.url===assetUrl).length,currentFetches+1,'the updated page fetched its exact production script URL');
    for(const width of [1440,390]){
      await page.setViewportSize({width,height:width===390?844:900});
      assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
      if(screenshots)await page.screenshot({path:path.join(screenshots,`renewal-collapsed-${width}.png`)});
    }
    await context.close();
  });
  await test('missing proof and explicit renewal refusal remain enforced',async()=>{
    const {context,page}=await open();proofAvailable=false;const before=renewals;
    await page.goto(origin+'/portal/?no-proof=1');await page.clock.runFor(6500);await settle(page);
    assert.equal(renewals,before,'missing server proof never renews');
    proofAvailable=true;denyRenewal=true;stopped=true;const beforeDenials=denials,response=renewalResponse(page);
    await page.clock.runFor(6500);await settle(page);
    assert.equal((await response).status(),403);
    assert.equal(renewals,before,'a server refusal cannot be turned into authorization');
    assert.equal(denials,beforeDenials+1,'the existing renewal refusal was actually exercised');
    assert.equal(await page.locator('#portal-desktop-stop').isHidden(),true,'renewal does not restart stopped control');
    assert.equal(await page.locator('#portal-chat-input').isEnabled(),true,'ordinary chat stays usable');
    await context.close();denyRenewal=false;proofAvailable=true;
  });
  assert.deepEqual(external,[]);assert.deepEqual(errors,[]);
}finally{
  await browser.close();server.closeAllConnections();await new Promise(resolve=>server.close(resolve));
  await rm(scratch,{recursive:true,force:true});
}
