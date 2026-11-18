// Independent Node crypto implementation of the fixed S5 interop inputs.
// node app/tests/fixtures/westy_work_log_vectors.mjs [output.json]
import {createHash, createHmac} from 'node:crypto';
import {readFileSync, writeFileSync} from 'node:fs';
import assert from 'node:assert/strict';
const sha = text => createHash('sha256').update(text, 'utf8').digest('hex');
// PHP escapes the two Unicode line separators even with UNESCAPED_UNICODE.
const json = value => JSON.stringify(value).replaceAll('\u2028', '\\u2028').replaceAll('\u2029', '\\u2029');
const workflow = '22222222-2222-4222-8222-222222222222';
const genesis = sha('milepost-westy-case-log-v1\n' + workflow);
const rows = [
  [1,'2026-11-17T11:50:00Z','system:milepost','detect','Print Spooler service stopped.','unknown',null,'alert:4242',true],
  [2,'2026-11-17T11:52:00Z','ai-staff:8west:l1_tech:1','action','Restarted the Print Spooler service (café ✓ a/b).','svc_running:Spooler','a'.repeat(64),'alert:4242;job:345;repair_execution:7',true],
  [3,'2026-11-17T11:58:00Z','ai-staff:8west:l1_tech:1','verify','Fresh monitoring confirms the service is running.','svc_running:Spooler','b'.repeat(64),'alert:4242;job:345;outcome:9',false],
];
const keys = ['seq','occurred_at','actor_key','kind','summary','cause_code','evidence_sha256','milepost_ref','customer_visible'];
let prev = genesis;
const entries = rows.map(row => {
  prev = sha(prev + '\n' + json(row));
  return {...Object.fromEntries(keys.map((key, i) => [key, row[i]])), entry_sha256:prev};
});
const identity = {workflow_key:workflow,tenant_slug:'8west',customer_id:'11111111-1111-4111-8111-111111111111',alert_key:'alert:4242',occurred_at:'2026-11-17T12:00:00Z'};
const log_body = json({schema_version:4,action:'log',event_key:'33333333-3333-4333-8333-333333333333',...identity,first_seq:1,entries,sealed_seq:3,log_sha256:prev});
const qa_body = json({schema_version:4,action:'qa',event_key:'44444444-4444-4444-8444-444444444444',...identity,verdict:'passed',reason_code:'no_recurrence',log_sha256:prev,evidence_sha256:'b'.repeat(64)});
const sign = body => createHmac('sha256','s'.repeat(40)).update('safeharbor-westy-workflow-v1\n1795000000\n'+body,'utf8').digest('hex');
const vectors = {genesis,entry_sha256:entries.map(e=>e.entry_sha256),log_body,log_signature:sign(log_body),qa_body,qa_signature:sign(qa_body)};
if (process.argv[2]) writeFileSync(process.argv[2], json(vectors)+'\n', 'utf8');
else assert.deepEqual(vectors, JSON.parse(readFileSync(new URL('./westy_work_log_vectors.json',import.meta.url),'utf8')));
console.log('PASS: fixed genesis, three entry hashes, two canonical bodies and two HMAC signatures.');
