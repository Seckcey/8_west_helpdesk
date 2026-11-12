import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { readFile,mkdir } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const shots=process.env.PORTAL_SUMMARY_SHOTS;
async function shot(page,name) { if(shots){await mkdir(shots,{recursive:true});await page.screenshot({path:path.join(shots,name+'.png'),fullPage:true});} }
for(const theme of ['light','dark'])for(const width of [1440,390]) {
  test(`saved summaries and provenance: ${theme} ${width}`,async t=>{
    const browser=await chromium.launch({headless:true});t.after(()=>browser.close());
    const page=await browser.newPage({viewport:{width,height:1000}});page.setDefaultTimeout(5000);
    const errors=[];const posts=[];page.on('pageerror',e=>errors.push(e.message));
    await page.route('**/*',async route=>{
      const url=new URL(route.request().url());
      if(route.request().method()!=='GET'){posts.push(url.pathname);return route.fulfill({status:405,body:''});}
      if(url.pathname==='/portal/reports.php'){
        const rendered=spawnSync('php',[path.join(root,'app/tests/fixtures/portal_summary_display.php'),url.searchParams.get('fixture')??'detail',theme],{encoding:'utf8'});
        assert.equal(rendered.status,0,rendered.stderr);return route.fulfill({contentType:'text/html',body:rendered.stdout});
      }
      if(url.pathname.startsWith('/assets/'))return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':url.pathname.endsWith('.js')?'text/javascript':url.pathname.endsWith('.svg')?'image/svg+xml':'image/png',body:await readFile(path.join(root,'app/public',url.pathname))});
      return route.fulfill({contentType:'application/json',body:'{"ok":true,"messages":[],"devices":[]}'});
    });
    for(const state of ['list','detail','missing','zero','empty']){
      await page.goto('http://safeharbor.test/portal/reports.php?fixture='+state);
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
      if(state==='list'||state==='empty')assert.match(await page.locator('main').last().textContent(),/Showing up to 24 weekly summaries/);
      else {
        assert.equal(await page.locator('.portal-report-integrity').getAttribute('open'),null);
        assert.equal(await page.locator('.portal-report-integrity code').isVisible(),false);
        assert.equal(await page.locator('.portal-report-official pre').isVisible(),false);
        if(state==='missing')assert.equal(await page.locator('.portal-report-missing').count(),2);
        if(state==='zero')assert.equal(await page.getByText('0 min',{exact:true}).count(),1);
      }
      await shot(page,`${state}-${theme}-${width}`);
      if(state==='detail'){
        await page.getByText('Read the exact archived summary',{exact:true}).click();
        assert.match(await page.locator('.portal-report-official pre').textContent(),/Exact saved summary <script>invalid\(\)<\/script>/);
        await page.getByText('Archive details',{exact:true}).focus();await page.keyboard.press('Enter');
        assert.match(await page.locator('.portal-report-integrity code').textContent(),/^[a-f0-9]{64}$/);
        assert.ok((await page.locator('.portal-report-integrity summary').boundingBox()).height>=44);
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
        await shot(page,`details-open-${theme}-${width}`);
      }
    }
    assert.deepEqual(posts,[]);assert.deepEqual(errors,[]);
  });
}
