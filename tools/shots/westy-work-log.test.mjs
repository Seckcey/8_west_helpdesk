import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const css = await readFile(path.join(root, 'app/public/assets/css/app.css'), 'utf8');
const staffRow = {
  source:'staff', occurred_at:'2026-11-17 12:00:00', kind:'finding', actor_type:'user',
  actor_key:'safeharbor-user:1', summary:'A technician recorded a finding.'
};
const mirroredRow = {
  ...staffRow, source:'milepost', actor_type:'ai_staff', actor_key:'ai-staff:'+'x'.repeat(240),
  summary:'<script>window.injected=true</script> A recorded finding.'
};
const sealed = {log_sealed_seq:2, qa_state:'fault_zero', qa_reason:'service_fault'};
const pending = 'Not sealed yet. The log is sealed when the case ends.';
const render = ({rows = [], state = null, hasWorkflow = false, customer = false}) => execFileSync('php', ['-r', `
function h(?string $s):string{return htmlspecialchars($s??'',ENT_QUOTES,'UTF-8');}
function csrf_field():string{return '<input type="hidden" name="csrf" value="fixture">';}
require $argv[1];
$fixture = json_decode($argv[2],true,16,JSON_THROW_ON_ERROR);
echo westy_work_log_timeline($fixture['rows'],$fixture['customer'],$fixture['state'],$fixture['hasWorkflow']);
if (!$fixture['customer']) echo westy_work_log_form($fixture['hasWorkflow']);
`, path.join(root,'app/lib/westy_work_log.php'), JSON.stringify({rows,state,hasWorkflow,customer})], {encoding:'utf8'});

for (const width of [390, 1280]) {
  test(`actual Work log render and Log step form at ${width}px`, async t => {
    const browser = await chromium.launch({headless:true}); t.after(()=>browser.close());
    const page = await browser.newPage({viewport:{width,height:1000}});
    const errors=[];
    page.on('pageerror',error=>errors.push(error.message));
    page.on('console',message=>{ if (['error','warning'].includes(message.type())) errors.push(message.text()); });
    const show = fixture => page.setContent(`<!doctype html><html lang="en" data-theme="dark"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Work log fixture</title><style>${css}</style><main class="page">${render(fixture)}</main></html>`);
    const card = page.getByRole('region',{name:'Work log'});
    await show({});
    assert.equal(await card.count(),0);
    assert.equal(await page.getByRole('button',{name:'Log step',exact:true}).count(),1);
    await show({rows:[staffRow]});
    assert.match(await card.innerText(),/A technician recorded a finding\./);
    assert.doesNotMatch(await card.innerText(),/seal|QA:/i);
    for (const fixture of [{rows:[staffRow],hasWorkflow:true}, {rows:[mirroredRow]}]) {
      await show(fixture);
      assert.equal(await card.getByText(pending,{exact:true}).count(),1);
      assert.doesNotMatch(await card.innerText(),/QA:/);
    }
    await show({rows:[mirroredRow],state:sealed,hasWorkflow:true});
    assert.equal(await card.getByRole('heading',{name:'Work log',exact:true}).count(),1);
    assert.match(await card.innerText(),/<script>/);
    assert.equal(await page.getByText('Sealed through step 2.',{exact:true}).count(),1);
    assert.equal(await page.getByText('QA: our fault, no charge [service_fault]',{exact:true}).count(),1);
    assert.equal(await page.evaluate(()=>window.injected),undefined);
    await page.getByLabel('Kind',{exact:true}).selectOption('verify');
    await page.getByLabel('Summary',{exact:true}).fill('Verified the recorded finding.');
    const form = await page.locator('form').evaluate(f=>Object.fromEntries(new FormData(f)));
    assert.deepEqual(form,{csrf:'fixture',work_log_step:'1',kind:'verify',summary:'Verified the recorded finding.'});
    assert.equal(await page.getByRole('button',{name:'Log step',exact:true}).isEnabled(),true);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
    await show({rows:[mirroredRow],state:sealed,hasWorkflow:true,customer:true});
    assert.match(await card.innerText(),/A recorded finding\./);
    assert.equal(await page.getByRole('button',{name:'Log step',exact:true}).count(),0);
    assert.equal(await page.getByText(/ai-staff:/).count(),0);
    assert.doesNotMatch(await card.innerText(),/seal|QA:|our fault|service_fault/i);
    assert.equal(await page.evaluate(()=>window.injected),undefined);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
    assert.deepEqual(errors,[]);
  });
}
