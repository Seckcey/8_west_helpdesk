import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { readFile, writeFile, mkdtemp, rm, mkdir } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// Production PHP workspace and assets; synthetic saved receipts and transport.
// No provider, actual computer, production account, or inference replay.
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const origin='https://safeharbor.test',conversation='a'.repeat(32),operation='b'.repeat(32);
const temp=await mkdtemp(path.join(tmpdir(),'portal-desktop-cleanup-'));
await writeFile(path.join(temp,'render.php'),String.raw`<?php
function cfg(string $key,mixed $default=null):mixed{return $default;}
function portal_csrf_token():string{return str_repeat('c',64);}
function portal_action_nonce(string $purpose,?int $now=null):string{return str_repeat('d',64);}
require $argv[1].'/app/lib/portal_data.php';
require $argv[1].'/app/lib/portal_render.php';
$_SERVER['REQUEST_URI']='/portal/';
$_SESSION['desktop_companion_session']=str_repeat('a',32);
portal_render_workspace(['identity'=>['display_name'=>'Alex Morgan','role'=>'client_admin'],'binding'=>['client_name'=>'Northwind Studio']]);
`);
const rendered=spawnSync('php',[path.join(temp,'render.php'),root],{encoding:'utf8'});
assert.equal(rendered.status,0,rendered.stderr);
const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
const screenshots=process.env.PORTAL_CLEANUP_SCREENSHOTS;
if(screenshots)await mkdir(screenshots,{recursive:true});
try{
  for(const viewport of [{width:1440,height:900},{width:390,height:844}]){
    await test(`unconfirmed desktop Stop survives refresh at ${viewport.width}px`,{timeout:30000},async()=>{
      const context=await browser.newContext({viewport}),page=await context.newPage();
      const errors=[],requests=[],external=[];let cleanup=true,loseStop=true,extraTurn=null,releaseStream;
      const state=()=>({enabled:true,ai_available:true,can_write:true,tools_enabled:false,conversation,
        conversations:[{key:conversation,title:'Computer task'}],draft:null,turns:[{
          operation_key:operation,input_text:'Read the heading in the browser.',
          state:viewport.width===390?'unavailable':'complete',reason_code:viewport.width===390?'hourly_limit':'',
          reply:{reply:'The saved computer action receipt is available.',sources:[],tools:[]},
          run:null,desktop_cleanup:cleanup?{state:'stop_unconfirmed'}:null,
        },...(extraTurn?[extraTurn]:[])]});
      page.on('pageerror',error=>errors.push(error.message));
      page.on('console',message=>{if(['warning','error'].includes(message.type()))errors.push(message.text());});
      await page.addInitScript(()=>{
        const fetchOriginal=window.fetch.bind(window);window.streamAborts=0;
        window.fetch=(url,options)=>{
          if(options?.headers?.Accept==='text/event-stream')options.signal.addEventListener('abort',()=>window.streamAborts++);
          return fetchOriginal(url,options);
        };
      });
      await page.route('**/*',async route=>{
        const url=new URL(route.request().url());
        if(url.origin!==origin){external.push(url.origin);return route.abort();}
        if(url.pathname==='/portal/')return route.fulfill({contentType:'text/html',body:rendered.stdout});
        if(url.pathname==='/portal/westy.php'){
          if(route.request().method()==='POST'){
            const input=route.request().postDataJSON();requests.push(input);
            assert.equal(route.request().headers()['x-portal-csrf'],'c'.repeat(64));
            if(input.action==='stop'){
              assert.deepEqual(input,{action:'stop',operation});
              if(loseStop){loseStop=false;return route.fulfill({status:503,json:{ok:false,reason:'stop_unconfirmed'}});}
              cleanup=false;return route.fulfill({json:{ok:true,state:state()}});
            }
            assert.equal(input.action,'message','cleanup must never submit inference or run_resume');
            extraTurn={operation_key:input.operation,input_text:input.message,state:'pending',reply:{reply:'',sources:[],tools:[]}};
            await new Promise(resolve=>{releaseStream=resolve;});
            extraTurn.state='complete';extraTurn.reply.reply='The separate reply finished.';
            return route.fulfill({contentType:'text/event-stream',body:'event: done\ndata: '+JSON.stringify({state:state()})+'\n\n'});
          }
          return route.fulfill({json:{ok:true,state:url.searchParams.has('devices')?{devices:[]}:url.search?{}:state()}});
        }
        if(url.pathname.startsWith('/assets/')){
          let file=path.join(root,'app/public',url.pathname);
          if(url.pathname==='/assets/brand/favicon.svg')file=path.join(root,'brand/svg/favicon.svg');
          if(url.pathname==='/assets/brand/safeharbor-logo-horizontal-transparent-20260909.png')file=path.join(root,'brand/png/safeharbor-logo-horizontal-transparent-20260909.png');
          return route.fulfill({body:await readFile(file),contentType:({'.js':'text/javascript','.css':'text/css','.svg':'image/svg+xml','.png':'image/png'})[path.extname(file)]});
        }
        throw new Error('Unexpected fixture request '+url.pathname);
      });
      await page.goto(origin+'/portal/');assert.match(await page.title(),/Westy/);
      const cleanupButton=page.getByRole('button',{name:'Stop computer control',exact:true});
      const stop=page.locator('#portal-chat-stop'),input=page.getByRole('textbox',{name:'Ask Westy about your computer'});
      await cleanupButton.waitFor();assert.equal(await stop.isVisible(),true);
      assert.equal(await input.isEnabled(),true,'old unconfirmed cleanup does not deny new authorized work');
      assert.equal(requests.length,0,'finished cleanup cannot auto-resume inference');
      await page.reload();await cleanupButton.waitFor();
      await stop.click();await page.getByText('Computer control has not been confirmed stopped. The previous action will not be repeated.').waitFor();
      await page.waitForFunction(()=>document.querySelector('#portal-chat-stop').disabled===false);
      assert.equal(await cleanupButton.isVisible(),true);assert.equal(requests.length,1);
      assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'no horizontal viewport overflow');
      if(screenshots)await page.screenshot({path:path.join(screenshots,`desktop-cleanup-${viewport.width}.png`)});
      await cleanupButton.focus();await page.keyboard.press('Enter');
      await cleanupButton.waitFor({state:'detached'});assert.equal(await stop.isHidden(),true);
      assert.equal(requests.length,2,'one explicit Stop per click and no action replay');

      // An old cleanup control must not abort a separate, newly submitted reply.
      cleanup=true;await page.reload();await cleanupButton.waitFor();
      await input.fill('Explain what the saved receipt means.');await input.press('Enter');
      await page.waitForFunction(()=>document.querySelector('#portal-chat-input').disabled);
      await cleanupButton.click();await cleanupButton.waitFor({state:'detached'});
      assert.equal(await page.evaluate(()=>window.streamAborts),0,'old Stop cannot abort unrelated current inference');
      assert.equal(await input.isDisabled(),true);assert.equal(requests.at(-1).operation,operation);
      assert.ok(releaseStream,'separate inference request is waiting');releaseStream();
      await page.getByText('The separate reply finished.',{exact:true}).waitFor();
      assert.equal(requests.filter(request=>request.action==='message').length,1);
      assert.equal(requests.filter(request=>request.action==='run_resume').length,0);
      assert.deepEqual(external,[]);assert.deepEqual(errors.filter(error=>!error.includes('503')),[]);
      await context.close();
    });
  }
}finally{await browser.close();await rm(temp,{recursive:true,force:true});}
