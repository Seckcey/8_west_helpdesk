import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { readFile,mkdir } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const shots=process.env.PORTAL_DEVICE_PAGE_SHOTS;
for(const theme of ['light','dark'])for(const width of [1440,390])test(`computer page navigation: ${theme} ${width}`,async t=>{
  const browser=await chromium.launch({headless:true});t.after(()=>browser.close());
  const page=await browser.newPage({viewport:{width,height:1000},colorScheme:theme});
  page.setDefaultTimeout(5000);
  const errors=[],posts=[];page.on('pageerror',error=>errors.push(error.message));
  await page.route('**/*',async route=>{
    const request=route.request(),url=new URL(request.url());
    if(request.method()==='POST'){posts.push(url.pathname);return route.fulfill({status:405,body:'Unexpected test submission'});}
    if(url.pathname==='/portal/devices.php'&&url.searchParams.get('watch')==='1')return route.fulfill({json:{ok:true,computers:[],complete:true}});
    if(url.pathname==='/portal/devices.php'){
      const rendered=spawnSync('php',[path.join(root,'app/tests/fixtures/portal_device_paging_display.php'),url.searchParams.get('fixture')??'first-empty',theme],{encoding:'utf8'});
      assert.equal(rendered.status,0,rendered.stderr||rendered.stdout);return route.fulfill({contentType:'text/html',body:rendered.stdout});
    }
    if(url.pathname.startsWith('/assets/'))return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':url.pathname.endsWith('.js')?'text/javascript':url.pathname.endsWith('.svg')?'image/svg+xml':'image/png',body:await readFile(path.join(root,'app/public',url.pathname))});
    return route.fulfill({json:{ok:true,messages:[],devices:[]}});
  });
  for(const state of ['first-empty','later-populated','later-empty-owner','later-empty-admin','later-empty-member','later-empty-viewer','later-error','later-download']){
    await page.goto('http://safeharbor.test/portal/devices.php?fixture='+state);
    await page.waitForLoadState('load');
    await page.evaluate(async()=>{await document.fonts.ready;await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));});
    const main=page.locator('main'),later=state.startsWith('later-');
    const refresh=main.getByRole('link',{name:'Refresh status',exact:true});
    assert.equal(await refresh.getAttribute('href'),'/portal/devices.php'+(later?'?after=50':''));
    const back=main.getByRole('link',{name:'Back to first computers',exact:true});
    assert.equal(await back.count(),later?1:0);
    if(later){
      assert.equal(await back.getAttribute('href'),'/portal/devices.php');
      await back.focus();assert.equal(await back.evaluate(link=>link===document.activeElement),true);
      assert.ok((await back.boundingBox()).height >= (width===390?44:36));
      assert.equal((await main.innerText()).includes('Add your first Windows computer'),false);
    }
    if(state.includes('empty')&&later)assert.equal(await main.getByRole('heading',{name:'No more computers on this page'}).isVisible(),true);
    if(state==='later-error'){
      assert.equal(await main.getByText('We cannot retrieve your computers right now. Their status has not been changed.',{exact:true}).isVisible(),true);
      assert.equal(await main.locator('.portal-device-empty').count(),0);
    }
    if(state==='later-populated')assert.equal(await main.getByRole('link',{name:'Next computers'}).getAttribute('href'),'/portal/devices.php?after=100');
    if(state==='later-empty-viewer'||state==='later-empty-member')assert.equal(await main.locator('form').count(),0);
    if(state==='later-download')assert.equal(await main.getByRole('heading',{name:'Install on your Windows computer'}).isVisible(),true);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
    if(shots){await mkdir(shots,{recursive:true});await page.screenshot({path:path.join(shots,`${state}-${theme}-${width}.png`),fullPage:true});}
  }
  await page.getByRole('link',{name:'Back to first computers',exact:true}).focus();await page.keyboard.press('Enter');
  await page.waitForURL('http://safeharbor.test/portal/devices.php');
  assert.equal(await page.getByRole('link',{name:'Back to first computers',exact:true}).count(),0);
  assert.deepEqual(posts,[]);assert.deepEqual(errors,[]);
});
