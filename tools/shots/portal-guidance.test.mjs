import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { readFile,mkdir } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const shots=process.env.PORTAL_GUIDANCE_SHOTS;
async function settledLayout(page) {
  await page.waitForLoadState('load');
  await page.evaluate(async () => {
    await document.fonts.ready;
    await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
  });
}
async function fixture(t,theme,width,javaScriptEnabled=true) {
  const browser=await chromium.launch({headless:true});t.after(()=>browser.close());
  const page=await browser.newPage({viewport:{width,height:1000},colorScheme:theme,javaScriptEnabled});
  page.setDefaultTimeout(5000);
  const errors=[],posts=[];page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/*',async route=>{
    const request=route.request(),url=new URL(request.url());
    if(request.method()==='POST'){
      posts.push({path:url.pathname,data:Object.fromEntries(new URLSearchParams(request.postData()))});
      return route.fulfill({contentType:'text/html',body:'<p>Synthetic submission captured</p>'});
    }
    if(['/portal/new.php','/portal/clients.php'].includes(url.pathname)){
      const result=spawnSync('php',[path.join(root,'app/tests/fixtures/portal_guidance_display.php'),url.searchParams.get('fixture')??'blank',theme],{encoding:'utf8'});
      assert.equal(result.status,0,result.stderr);return route.fulfill({contentType:'text/html',body:result.stdout});
    }
    if(url.pathname.startsWith('/assets/'))return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':url.pathname.endsWith('.js')?'text/javascript':url.pathname.endsWith('.svg')?'image/svg+xml':'image/png',body:await readFile(path.join(root,'app/public',url.pathname))});
    return route.fulfill({contentType:'application/json',body:'{"ok":true,"messages":[],"devices":[]}'});
  });
  return {page,errors,posts};
}
for(const theme of ['light','dark'])for(const width of [1440,390])test(`persistent request help and client choices: ${theme} ${width}`,async t=>{
  const {page,errors,posts}=await fixture(t,theme,width);
  for(const state of ['blank','filled','invalid','uncertain','delegated','picker-empty','picker-one','picker-many']) {
    const picker=state.startsWith('picker');
    await page.goto('http://safeharbor.test/portal/'+(picker?'clients':'new')+'.php?fixture='+state);
    await settledLayout(page);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
    if(!picker){
      const supportLabel=await page.locator('#portal-nav nav a[href="/portal/requests.php"]').innerText();
      assert.ok((await page.locator('main .page-head').innerText()).includes(`follow replies in ${supportLabel.trim()}.`),'form guidance matches this account navigation label');
      for(const label of ['Short summary','How urgent is it?','What happened?']) {
        const field=page.getByLabel(label,{exact:true});
        const help=await field.getAttribute('aria-describedby');
        assert.equal(await page.locator('#'+help).isVisible(),true);
      }
      if(state==='blank') {
        await page.getByLabel('Short summary',{exact:true}).fill('Typing a summary');
        await page.getByLabel('What happened?',{exact:true}).fill('Typing the details');
        assert.equal(await page.locator('#request-subject-help').isVisible(),true);
        assert.equal(await page.locator('#request-body-help').isVisible(),true);
        await page.getByLabel('Short summary',{exact:true}).fill('');
        await page.getByLabel('What happened?',{exact:true}).fill('');
      }
      if(state==='invalid'||state==='uncertain')assert.equal(await page.getByRole('alert').isVisible(),true);
      assert.ok((await page.getByRole('button',{name:'Send request',exact:true}).boundingBox()).height>=44);
    } else {
      assert.equal(await page.getByRole('heading',{name:'Choose a client',exact:true}).isVisible(),true);
      const count=state==='picker-empty'?0:state==='picker-one'?1:2;
      assert.equal(await page.getByRole('button',{name:'Continue with this client',exact:true}).count(),count);
      if(count)assert.ok((await page.getByRole('button',{name:'Continue with this client',exact:true}).first().boundingBox()).height>=44);
    }
    if(shots){await mkdir(shots,{recursive:true});await page.screenshot({path:path.join(shots,`${state}-${theme}-${width}.png`),fullPage:true});}
    if(state==='blank'){
      await page.locator('#portal-chat-bubble').click();
      await page.locator('#portal-chat-panel').waitFor({state:'visible'});
      await settledLayout(page);
      const send=await page.locator('#portal-chat-send').boundingBox();
      assert.equal(send.width,40,'page form styles leave the Westy send button width unchanged');
      assert.equal(send.height,40,'page form styles leave the Westy send button height unchanged');
      if(shots)await page.screenshot({path:path.join(shots,`chat-open-${theme}-${width}.png`),fullPage:true});
    }
  }
  assert.deepEqual(errors,[]);assert.deepEqual(posts,[]);
});
test('forms retain their no-JavaScript POST payloads and keyboard submission',async t=>{
  const {page,posts}=await fixture(t,'light',390,false);
  await page.goto('http://safeharbor.test/portal/new.php?fixture=blank');
  await page.getByRole('button',{name:'Send request',exact:true}).click();
  assert.equal(posts.length,0,'blank required fields still prevent submission');
  await page.getByLabel('Short summary',{exact:true}).fill('Example printer issue');
  await page.getByLabel('How urgent is it?',{exact:true}).selectOption('high');
  await page.getByLabel('What happened?',{exact:true}).fill('The printer is offline.');
  await page.getByRole('button',{name:'Send request',exact:true}).focus();await page.keyboard.press('Enter');
  await page.getByText('Synthetic submission captured').waitFor();
  assert.deepEqual(posts[0],{path:'/portal/new.php',data:{csrf:'synthetic-form-csrf',action_nonce:'synthetic-form-nonce',subject:'Example printer issue',priority:'high',body:'The printer is offline.'}});
  await page.goto('http://safeharbor.test/portal/clients.php?fixture=picker-many');
  await page.getByRole('button',{name:'Continue with this client',exact:true}).nth(1).focus();await page.keyboard.press('Enter');
  await page.getByText('Synthetic submission captured').waitFor();
  assert.deepEqual(posts[1],{path:'/portal/clients.php',data:{csrf:'synthetic-form-csrf',reference:'example-reference-2',generation:'4'}});
});
