import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { readFile, writeFile, mkdtemp, rm, mkdir } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const origin='https://safeharbor.test';
const conversation='a'.repeat(32), operation='b'.repeat(32), responseId='c'.repeat(64);
const reply='Check the printer queue.\nKeep this exact line: <script> & café.';
const fixture=String.raw`<?php
function cfg(string $key,mixed $default=null):mixed{return $default;}
function portal_csrf_token():string{return str_repeat('c',64);}
function portal_action_nonce(string $purpose,?int $now=null):string{return str_repeat('d',64);}
require $argv[1].'/app/lib/portal_data.php';
require $argv[1].'/app/lib/portal_render.php';
$_SERVER['REQUEST_URI']='/portal/';
if(($argv[2]??'')==='companion')$_SESSION['desktop_companion_session']=str_repeat('a',32);
portal_render_workspace(['identity'=>['display_name'=>'Alex Morgan','role'=>'client_admin'],'binding'=>['client_name'=>'Northwind Studio']]);
`;
const temp=await mkdtemp(path.join(tmpdir(),'portal-reply-actions-'));
await writeFile(path.join(temp,'render.php'),fixture);
const render=mode=>{
  const result=spawnSync('php',[path.join(temp,'render.php'),root,mode],{encoding:'utf8'});
  assert.equal(result.status,0,result.stderr);return result.stdout;
};
const html=render('companion');
const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
const screenshots=process.env.PORTAL_REPLY_SCREENSHOTS;
if(screenshots)await mkdir(screenshots,{recursive:true});
try {
  for(const viewport of [{width:1440,height:900},{width:390,height:844}]) {
    await test(`saved reply Copy and feedback at ${viewport.width}px`,async()=>{
      const context=await browser.newContext({viewport});const page=await context.newPage();
      const errors=[],requests=[],external=[];let loseReceipt=false,conflict=false,signOut=false;
      let feedback={response_id:responseId,revision:0,reaction:'none',reacted_at:null};
      let currentReply=reply,currentResponse=responseId,pending=false;
      const state=()=>({enabled:true,ai_available:true,conversation,conversations:[{key:conversation,title:'Printer help'}],
        can_write:true,tools_enabled:false,draft:null,turns:[{operation_key:operation,input_text:'Why did printing stop?',
          state:pending?'pending':'complete',reason_code:'',reply:{reply:currentReply,sources:[],tools:[]},feedback:pending?null:{...feedback,response_id:currentResponse}}]});
      page.on('pageerror',e=>errors.push(e.message));
      page.on('console',m=>{if(['warning','error'].includes(m.type()))errors.push(m.text());});
      await page.addInitScript(()=>{
        window.copyMode='clipboard';window.copied=[];
        Object.defineProperty(navigator,'clipboard',{value:{writeText:async text=>{if(window.copyMode!=='clipboard')throw new Error('Clipboard denied');window.copied.push(text);}}});
        document.execCommand=command=>{
          if(command==='copy'&&window.copyMode==='fallback'){window.copied.push(document.activeElement.value);return true;}
          return false;
        };
      });
      await page.route('**/*',async route=>{
        const url=new URL(route.request().url());
        if(url.origin!==origin){external.push(url.origin);return route.abort();}
        if(url.pathname==='/portal/')return route.fulfill({contentType:'text/html',body:html});
        if(url.pathname==='/portal/westy_feedback.php'){
          const request=route.request().postDataJSON();requests.push(request);
          assert.equal(route.request().headers()['x-portal-csrf'],'c'.repeat(64));
          assert.deepEqual(Object.keys(request).sort(),['conversation','operation','reaction','request_id','response_id','revision']);
          if(signOut)return route.fulfill({status:401,json:{ok:false,reason:'sign_in'}});
          if(conflict)return route.fulfill({status:409,json:{ok:false,reason:'feedback_changed'}});
          if(request.revision===feedback.revision)feedback={...feedback,revision:feedback.revision+1,reaction:request.reaction,reacted_at:'2026-10-09 02:00:00'};
          if(loseReceipt){loseReceipt=false;return route.abort('connectionclosed');}
          return route.fulfill({json:{ok:true,feedback}});
        }
        if(url.pathname==='/portal/westy.php'){
          const value=url.searchParams.has('devices')?{devices:[]}:url.search?{}:state();
          return route.fulfill({json:{ok:true,state:value}});
        }
        if(url.pathname.startsWith('/assets/')){
          let file=path.join(root,'app/public',url.pathname);
          if(url.pathname==='/assets/brand/favicon.svg')file=path.join(root,'brand/svg/favicon.svg');
          if(url.pathname==='/assets/brand/safeharbor-logo-horizontal-transparent-20260909.png')file=path.join(root,'brand/png/safeharbor-logo-horizontal-transparent-20260909.png');
          try{return route.fulfill({body:await readFile(file),contentType:({'.js':'text/javascript','.css':'text/css','.png':'image/png','.svg':'image/svg+xml'})[path.extname(file)]||'application/octet-stream'});}
          catch{return route.fulfill({status:404,body:'Missing fixture asset'});}
        }
        return route.fulfill({status:404,body:'Unexpected fixture route'});
      });
      await page.goto(origin+'/portal/');
      assert.equal(page.url(),origin+'/portal/');assert.match(await page.title(),/Westy/);
      const copy=page.getByRole('button',{name:'Copy reply',exact:true});
      const up=page.getByRole('button',{name:'Helpful',exact:true});
      const down=page.getByRole('button',{name:'Not helpful',exact:true});
      await copy.waitFor();assert.equal(await page.locator('.portal-chat-reply').textContent(),reply);
      assert.equal(await page.locator('.portal-chat-reply script').count(),0);
      assert.equal(await page.getByRole('textbox',{name:'Ask Westy about your computer'}).count(),1);
      await copy.focus();await page.keyboard.press('Enter');
      await page.waitForFunction(()=>window.copied.length===1);
      assert.deepEqual(await page.evaluate(()=>window.copied),[reply]);assert.equal(requests.length,0);
      await page.evaluate(()=>window.copyMode='fallback');await copy.click();
      await page.waitForFunction(()=>window.copied.length===2);assert.equal(await copy.evaluate(e=>e===document.activeElement),true);
      await page.evaluate(()=>window.copyMode='manual');await copy.click();
      const manual=page.getByRole('textbox',{name:'Reply text to copy'});await manual.waitFor();
      assert.equal(await manual.inputValue(),reply);assert.equal(await manual.evaluate(e=>e.readOnly&&e.selectionEnd===e.value.length),true);
      await page.getByRole('button',{name:'Close copy text'}).click();assert.equal(await manual.count(),0);
      await up.focus();await page.keyboard.press('Space');
      await page.waitForFunction(()=>document.querySelector('[aria-label="Helpful"]').getAttribute('aria-pressed')==='true');
      assert.equal(await down.getAttribute('aria-pressed'),'false');assert.equal(await up.evaluate(e=>e===document.activeElement),true);
      await up.click();await page.waitForFunction(()=>document.querySelector('[aria-label="Helpful"]').getAttribute('aria-pressed')==='false');
      assert.equal(requests.at(-1).reaction,'none');
      await down.click();await page.waitForFunction(()=>document.querySelector('[aria-label="Not helpful"]').getAttribute('aria-pressed')==='true');
      await page.reload();await down.waitFor();assert.equal(await down.getAttribute('aria-pressed'),'true');
      const boxes=await Promise.all([copy,up,down].map(control=>control.boundingBox()));
      assert.ok(boxes.every(box=>box&&Math.abs(box.y-boxes[0].y)<2),'reply controls share one compact row');
      assert.ok(boxes[2].x+boxes[2].width-boxes[0].x<250,'reply controls stay compact');
      assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'no horizontal viewport overflow');
      const composer=await page.locator('#portal-chat-form').boundingBox();
      assert.ok(composer&&composer.y>=0&&composer.y+composer.height<=viewport.height,JSON.stringify({composer,viewport}));
      if(screenshots)await page.screenshot({path:path.join(screenshots,`reply-${viewport.width}.png`)});
      // Lost acknowledgment can be retried using the same idempotency identity.
      loseReceipt=true;await up.click();
      const retry=page.getByRole('button',{name:'Retry feedback'});await retry.waitFor();
      const uncertain=requests.at(-1);await retry.click();
      await page.waitForFunction(()=>document.querySelector('[aria-label="Helpful"]').getAttribute('aria-pressed')==='true');
      assert.deepEqual(requests.at(-1),uncertain);assert.equal(feedback.revision,4);
      // A stale tab refreshes actual state and never retries a changed response.
      conflict=true;await down.click();await page.getByText('This reply or feedback changed. Check the saved reply and choose again.').waitFor();
      assert.equal(await up.getAttribute('aria-pressed'),'true');conflict=false;
      pending=true;await page.reload();await page.locator('.portal-chat-reply').waitFor();assert.equal(await copy.count(),0);assert.equal(await up.count(),0);
      pending=false;currentReply='A continued reply.';currentResponse='e'.repeat(64);feedback={response_id:currentResponse,revision:0,reaction:'none',reacted_at:null};
      await page.reload();await copy.waitFor();assert.equal(await up.getAttribute('aria-pressed'),'false');
      signOut=true;await up.click();await page.waitForFunction(()=>document.querySelectorAll('.portal-chat-message').length===0);
      assert.equal(await copy.count(),0);assert.equal(await page.evaluate(()=>localStorage.length+sessionStorage.length),0);
      assert.deepEqual(external,[]);
      // Deliberately dropped synthetic feedback transport is the only expected console error.
      assert.deepEqual(errors.filter(e=>!e.includes('ERR_CONNECTION_CLOSED')&&!e.includes('409')&&!e.includes('401')),[]);
      await context.close();
    });
  }
} finally {await browser.close();await rm(temp,{recursive:true,force:true});}
