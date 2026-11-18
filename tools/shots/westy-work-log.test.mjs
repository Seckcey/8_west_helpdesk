import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const css = await readFile(path.join(root, 'app/public/assets/css/app.css'), 'utf8');
const render = customer => execFileSync('php', ['-r', `
function h(?string $s):string{return htmlspecialchars($s??'',ENT_QUOTES,'UTF-8');}
function csrf_field():string{return '<input type="hidden" name="csrf" value="fixture">';}
require $argv[1];
$row=['occurred_at'=>'2026-11-17 12:00:00','kind'=>'finding','actor_type'=>'ai_staff',
  'actor_key'=>'ai-staff:'.str_repeat('x',240),'summary'=>'<script>window.injected=true</script> A recorded finding.'];
echo westy_work_log_timeline([$row],$argv[2]==='customer');
if($argv[2]!=='customer')echo westy_work_log_form(true);
`, path.join(root,'app/lib/westy_work_log.php'), customer ? 'customer' : 'staff'], {encoding:'utf8'});

for (const width of [390, 1280]) {
  test(`actual Work log render and Log step form at ${width}px`, async t => {
    const browser = await chromium.launch({headless:true}); t.after(()=>browser.close());
    const page = await browser.newPage({viewport:{width,height:1000}});
    const errors=[]; page.on('pageerror',error=>errors.push(error.message));
    await page.setContent(`<!doctype html><html lang="en" data-theme="dark"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Work log fixture</title><style>${css}</style><main class="page">${render(false)}</main></html>`);
    assert.equal(await page.getByRole('heading',{name:'Work log',exact:true}).count(),1);
    assert.match(await page.getByRole('region',{name:'Work log'}).innerText(),/<script>/);
    assert.equal(await page.evaluate(()=>window.injected),undefined);
    await page.getByLabel('Kind',{exact:true}).selectOption('verify');
    await page.getByLabel('Summary',{exact:true}).fill('Verified the recorded finding.');
    const form = await page.locator('form').evaluate(f=>Object.fromEntries(new FormData(f)));
    assert.deepEqual(form,{csrf:'fixture',work_log_step:'1',kind:'verify',summary:'Verified the recorded finding.'});
    assert.equal(await page.getByRole('button',{name:'Log step',exact:true}).isEnabled(),true);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
    assert.deepEqual(errors,[]);
    await page.setContent(`<!doctype html><html lang="en" data-theme="dark"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reviewed work log fixture</title><style>${css}</style><main class="page">${render(true)}</main></html>`);
    assert.equal(await page.getByRole('button',{name:'Log step',exact:true}).count(),0);
    assert.equal(await page.getByText(/ai-staff:/).count(),0);
    assert.equal(await page.evaluate(()=>window.injected),undefined);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
  });
}
