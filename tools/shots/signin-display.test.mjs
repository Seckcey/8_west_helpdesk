import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdtemp, mkdir, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const scratch=await mkdtemp(path.join(tmpdir(),'safeharbor-signin-display-'));
execFileSync('php',['app/tests/signin_display_test.php'],{cwd:root,env:{...process.env,SIGNIN_DISPLAY_CAPTURE_DIR:scratch}});
const browser=await chromium.launch();
after(async()=>{await browser.close();await rm(scratch,{recursive:true,force:true});});
const origin='http://safeharbor.test', shots=process.env.SIGNIN_DISPLAY_SHOTS;
async function open(t,{state='guest',theme='light',width=1440,js=true,brokenLogo=false}={}) {
  const context=await browser.newContext({viewport:{width,height:1000},javaScriptEnabled:js}); t.after(()=>context.close());
  await context.addInitScript(value=>localStorage.setItem('safeharbor.theme',value),theme);
  const page=await context.newPage(),posts=[],errors=[];
  page.on('pageerror',error=>errors.push(error.message));
  await page.route('**/*',async route=>{
    const url=new URL(route.request().url());
    if(route.request().method()==='POST'){posts.push(new URLSearchParams(route.request().postData()));return route.fulfill({contentType:'text/html',body:'<h1>Synthetic form submitted</h1>'});}
    if(url.pathname==='/login.php')return route.fulfill({contentType:'text/html',body:(await readFile(path.join(scratch,state+'.html'),'utf8')).replace('<html lang="en">',`<html lang="en" data-theme="${theme}">`)});
    if(url.pathname.startsWith('/assets/brand/')){
      if(brokenLogo)return route.fulfill({status:404,body:''});
      const name=path.basename(url.pathname),file=path.join(root,'brand',name.endsWith('.svg')?'svg':'png',name);
      return route.fulfill({contentType:name.endsWith('.svg')?'image/svg+xml':'image/png',body:await readFile(file)});
    }
    if(url.pathname.startsWith('/assets/'))return route.fulfill({contentType:url.pathname.endsWith('.js')?'text/javascript':'text/css',body:await readFile(path.join(root,'app/public',url.pathname))});
    return route.fulfill({status:204,body:''});
  });
  await page.goto(origin+'/login.php'); return {page,posts,errors};
}
async function shot(page,name){if(shots){await mkdir(shots,{recursive:true});await page.screenshot({path:path.join(shots,name+'.png'),fullPage:true});}}
for(const theme of ['light','dark'])for(const width of [1440,390])test(`staff sign-in ${theme} ${width}`,async t=>{
  const {page,posts,errors}=await open(t,{theme,width});
  assert.equal(await page.getByLabel('Password').isVisible(),false);
  assert.equal(await page.getByRole('link',{name:'Continue with 8 West ID'}).getAttribute('href'),'https://id.8westit.com');
  assert.equal(await page.locator('.login-foot').count(),1);
  assert.equal(await page.locator('.login-name').innerText(),'');
  assert.equal(await page.locator('.suite-login-logo').evaluate(el=>el.complete&&el.naturalWidth>0),true);
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
  await shot(page,`closed-${theme}-${width}`);
  await page.getByText('Use a backup password',{exact:true}).focus(); await page.keyboard.press('Enter');
  assert.equal(await page.getByLabel('Password').isVisible(),true);
  assert.equal(await page.getByLabel('Password').getAttribute('placeholder'),null);
  for(const control of await page.locator('summary,.login-form input:not([type=hidden]),.login-form button').all())assert.ok((await control.boundingBox()).height>=(width<860?44:36));
  // Under 16px a phone zooms the page when a field takes focus.
  if(width<860)for(const input of await page.locator('.login-form input:not([type=hidden])').all())assert.ok(parseFloat(await input.evaluate(el=>getComputedStyle(el).fontSize))>=16);
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
  await shot(page,`open-${theme}-${width}`); assert.deepEqual(posts,[]); assert.deepEqual(errors,[]);
  const failed=await open(t,{state:'failed',theme,width});
  assert.equal(await failed.page.getByLabel('Password').isVisible(),true);
  assert.equal(await failed.page.getByLabel('Work email').inputValue(),'teammate@example.test');
  assert.equal(await failed.page.locator('.login-error').innerText(),'That email and password didn’t match. Try again.');
  await shot(failed.page,`failed-${theme}-${width}`); assert.deepEqual(failed.posts,[]); assert.deepEqual(failed.errors,[]);
});
test('native disclosure and existing form work without JavaScript',async t=>{
  const {page,posts}=await open(t,{js:false});
  await page.getByText('Use a backup password',{exact:true}).click();
  await page.getByLabel('Work email').fill('teammate@example.test'); await page.getByLabel('Password').fill('synthetic-input');
  await page.getByRole('button',{name:'Sign in',exact:true}).click(); await page.getByRole('heading',{name:'Synthetic form submitted'}).waitFor();
  assert.equal(posts.length,1); assert.equal(posts[0].get('next'),'/ticket.php?id=1042'); assert.equal(posts[0].get('csrf'),'synthetic-csrf');
});
test('failed logo retains accessible brand fallback',async t=>{
  const {page,errors}=await open(t,{brokenLogo:true,width:390});
  assert.equal(await page.getByRole('heading',{name:'Safeharbor — 8 West IT 365'}).count(),1);
  assert.equal(await page.getByRole('img',{name:'Safeharbor — 8 West IT 365'}).getAttribute('alt'),'Safeharbor — 8 West IT 365');
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);assert.deepEqual(errors,[]);
});
