import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { readFile, mkdir } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const origin = 'http://safeharbor.test';
const shots = process.env.PORTAL_REQUEST_SHOTS;
async function open(t, { theme='light', width=1440, role='client_staff', query='', javaScriptEnabled=true }={}) {
  const browser = await chromium.launch({headless:true});
  t.after(()=>browser.close());
  const page = await browser.newPage({viewport:{width,height:1000},javaScriptEnabled});
  page.setDefaultTimeout(5000);
  const posts = [];
  const errors = [];
  page.on('pageerror',error=>errors.push(error.message));
  await page.route('**/*', async route=>{
    const url = new URL(route.request().url());
    if (route.request().method() !== 'GET') { posts.push(url.pathname); return route.fulfill({status:405,body:''}); }
    if (url.pathname === '/portal/requests.php') {
      const rendered=spawnSync('php',[path.join(root,'app/tests/fixtures/portal_request_paging_render.php'),url.search.slice(1),role,theme],{encoding:'utf8'});
      assert.equal(rendered.status,0,rendered.stderr);
      return route.fulfill({contentType:'text/html',body:rendered.stdout});
    }
    if (url.pathname.startsWith('/assets/')) {
      const mime=url.pathname.endsWith('.css')?'text/css':url.pathname.endsWith('.js')?'text/javascript':url.pathname.endsWith('.svg')?'image/svg+xml':'image/png';
      return route.fulfill({contentType:mime,body:await readFile(path.join(root,'app/public',url.pathname))});
    }
    // Synthetic chat history: no live identity, message or provider request.
    return route.fulfill({contentType:'application/json',body:'{"ok":true,"messages":[],"devices":[]}'});
  });
  await page.goto(origin+'/portal/requests.php?'+query);
  return {page,posts,errors};
}
async function shot(page,name) { if(shots) { await mkdir(shots,{recursive:true}); await page.screenshot({path:path.join(shots,name+'.png'),fullPage:true}); } }
for (const theme of ['light','dark']) for (const width of [1440,390]) {
  test(`search, page and filter requests: ${theme} ${width}`,async t=>{
    const {page,posts,errors}=await open(t,{theme,width,query:'q=Paging+record'});
    assert.equal(await page.locator('.portal-request-range').textContent(),'Showing 1–50 of 65 matching requests.');
    assert.equal(await page.locator('.portal-request-row').count(),50);
    assert.equal(await page.getByRole('link',{name:'Previous',exact:true}).count(),0);
    await page.getByRole('link',{name:'Next',exact:true}).click();
    assert.equal(new URL(page.url()).searchParams.get('q'),'Paging record');
    assert.equal(await page.locator('.portal-request-range').textContent(),'Showing 51–65 of 65 matching requests.');
    assert.equal(await page.locator('.portal-request-row').count(),15);
    assert.equal(await page.getByRole('link',{name:'Next',exact:true}).count(),0);
    await shot(page,`page-two-${theme}-${width}`);
    await page.getByRole('searchbox',{name:'Search requests'}).fill('#41006');
    await page.getByRole('button',{name:'Apply filters'}).click();
    assert.equal(new URL(page.url()).searchParams.has('page'),false);
    assert.equal(await page.locator('.portal-request-row').count(),1);
    assert.match(await page.locator('.portal-request-row').textContent(),/Paging record 6/);
    await shot(page,`number-search-${theme}-${width}`);
    await page.getByRole('searchbox',{name:'Search requests'}).fill('Paging record');
    await page.getByLabel('Status',{exact:true}).selectOption('waiting');
    await page.getByRole('button',{name:'Apply filters'}).click();
    assert.equal(await page.locator('.portal-request-range').textContent(),'Showing 1–5 of 5 matching requests.');
    assert.equal(await page.locator('.portal-request-row').count(),5);
    assert.equal(await page.locator('input[name=page]').count(),0);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
    for(const control of await page.locator('.portal-request-filters :is(input,select,button,a),.portal-request-paging a').all()) {
      assert.ok((await control.boundingBox()).height>=44);
    }
    await page.getByRole('searchbox',{name:'Search requests'}).focus();
    assert.notEqual(await page.getByRole('searchbox',{name:'Search requests'}).evaluate(e=>getComputedStyle(e).outlineStyle),'none');
    await shot(page,`waiting-${theme}-${width}`);
    await page.getByRole('searchbox',{name:'Search requests'}).fill('PRIVATE PAGING CANARY');
    await page.getByRole('button',{name:'Apply filters'}).click();
    assert.equal(await page.locator('.portal-request-row').count(),0);
    assert.match(await page.locator('#requests').textContent(),/No matching requests/);
    await shot(page,`empty-${theme}-${width}`);
    await page.getByRole('link',{name:'Clear filters',exact:true}).click();
    assert.equal(new URL(page.url()).search,'');
    assert.deepEqual(posts,[]);
    assert.deepEqual(errors,[]);
  });
}
test('viewer can search without JavaScript and still has no request or reply action',async t=>{
  const {page,posts}=await open(t,{role:'client_viewer',javaScriptEnabled:false});
  assert.equal(await page.getByRole('link',{name:'New support request',exact:true}).count(),0);
  assert.equal(await page.locator('.portal-request-reply').count(),0);
  await page.getByRole('searchbox',{name:'Search requests'}).fill('#41006');
  await page.getByRole('button',{name:'Apply filters'}).click();
  assert.equal(await page.locator('.portal-request-row').count(),1);
  assert.deepEqual(posts,[]);
});
test('search text is escaped in the form and invalid page input has a bounded error',async t=>{
  const {page}=await open(t,{query:new URLSearchParams({q:'<script>invalid()</script>'}).toString()});
  assert.equal(await page.getByRole('searchbox',{name:'Search requests'}).inputValue(),'<script>invalid()</script>');
  assert.equal(await page.locator('script:not([src])').count(),0);
  await page.goto(origin+'/portal/requests.php?page[]=2');
  assert.equal(await page.getByRole('heading',{name:'Check the request filters'}).count(),1);
});
