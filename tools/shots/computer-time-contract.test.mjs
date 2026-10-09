import test from 'node:test';
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
const require=createRequire(import.meta.url);
const time=require('../../app/public/assets/js/portal-computer-time.js');
const base={schema:'milepost.computer_time.v1',timezone:'America/Los_Angeles',reported_timezone:'Pacific Standard Time',
  observed_at:'2026-07-01T12:00:00Z',received_at:'2026-07-01T12:00:00Z',expires_at:'2026-07-01T20:00:00Z',
  server_now:'2026-07-01T12:00:00Z',source:'agent_inventory'};
test('server UTC baseline plus monotonic elapsed time ignores browser clock and timezone',()=>{
  const clock=time.create(base,100);
  assert.equal(time.current(clock,60100),Date.parse(base.server_now)+60000);
  assert.match(time.summary(clock,100),/5:00.*GMT-7.*America\/Los_Angeles/);
  assert.match(time.format('2026-01-01 12:00:00',clock,100),/4:00.*GMT-8/);
});
test('fall repeated hour and spring gap retain event-specific offsets',()=>{
  const clock=time.create(base,100);
  assert.match(time.format('2026-11-01T08:30:00Z',clock,100),/1:30.*GMT-7/);
  assert.match(time.format('2026-11-01T09:30:00Z',clock,100),/1:30.*GMT-8/);
  assert.match(time.format('2026-03-08T09:59:00Z',clock,100),/1:59.*GMT-8/);
  assert.match(time.format('2026-03-08T10:00:00Z',clock,100),/3:00.*GMT-7/);
});
test('different endpoints, actual UTC, half-hour zones and unknown evidence',()=>{
  assert.match(time.summary(time.create({...base,timezone:'America/New_York'},0),0),/8:00.*GMT-4/);
  assert.match(time.summary(time.create({...base,timezone:'Asia/Kolkata'},0),0),/5:30.*GMT\+5:30/);
  assert.match(time.summary(time.create({...base,timezone:'UTC'},0),0),/12:00.*UTC/);
  for(const bad of [null,{}, {...base,timezone:'Bad/Zone'}, {...base,observed_at:'2026-02-30T12:00:00Z'},
    {...base,expires_at:base.server_now},{...base,observed_at:'2026-07-01T12:06:00Z'}])assert.equal(time.create(bad,0),null);
  assert.match(time.summary(null,0),/unavailable.*UTC/);
  assert.match(time.format('2026-07-01T12:00:00Z',null,0),/UTC \(computer timezone unavailable\)/);
  assert.equal(time.instant('2026-07-01'),null);
});
test('expired metadata and monotonic-clock reset do not keep a stale zone',()=>{
  const clock=time.create(base,100);
  assert.equal(time.current(clock,99),null);
  assert.match(time.summary(clock,28800100),/unavailable.*UTC/);
});
test('PHP real consumer supports old, new and unavailable device metadata',()=>{
  assert.match(execFileSync('php',[fileURLToPath(new URL('../../app/tests/portal_computer_time_test.php',import.meta.url))],{encoding:'utf8'}),/computer time consumer checks passed/);
});
