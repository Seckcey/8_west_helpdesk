/**
 * Private runtime isolation renderer — adversarial offline tests (T-20261013-05).
 *
 * Run: node --test tests/runtime_isolation.test.mjs
 *
 * Node standard library only: no framework, dependency, PHP, shell, service, network or privileged
 * operation. Candidates render only into the OS temporary directory, and the renderer tree is hashed before
 * and after to prove it is never written. Passing proves the renderer's input contract, not deployed
 * isolation: every runtime fact stays open.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import crypto from 'node:crypto';
import { execFileSync, spawn, spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

import {
  renderArtifacts, renderToDirectory, readInput, RenderError, PROFILE, TEMPLATE_DIR, FORBIDDEN_PROVISION_TOKENS,
} from '../deploy/runtime-isolation/render.mjs';

const REPO_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const TOOL_DIR = path.join(REPO_ROOT, 'deploy', 'runtime-isolation');
const RENDERER = path.join(TOOL_DIR, 'render.mjs');

const CRON_SCRIPTS = fs.readdirSync(path.join(REPO_ROOT, 'app', 'cron')).filter((f) => /\.(?:php|sh)$/.test(f)).sort();
const WRAPPED = ['business_reports.php'].filter((s) => CRON_SCRIPTS.includes(s));
const OPERATOR_CLI = ['managed_provider_register.php', 'suite_integration_inspect.php', 'suite_workspace_provision.php'].filter((s) => CRON_SCRIPTS.includes(s));

function sampleInput() {
  const unrouted = new Set([...WRAPPED, ...OPERATOR_CLI]);
  return {
    schema: 'eightwest-runtime-isolation-input-v1',
    app: 'safeharbor',
    identity: { user: 'eightwest-safeharbor', group: 'eightwest-safeharbor', uid: 1602, gid: 1602, supplementaryGroups: [] },
    frontend: { user: 'www-data', group: 'www-data', uid: 33, gid: 33, supplementaryGroups: [], phpModule: 'absent' },
    deployOwner: { user: 'ubuntu', uid: 1000 },
    code: { owner: 'ubuntu', group: 'eightwest-safeharbor', dirMode: '2750', fileMode: '0640' },
    paths: {
      appRoot: '/srv/8west/apps/safeharbor/current',
      documentRoot: '/srv/8west/apps/safeharbor/current/public',
      deployState: '/var/lib/safeharbor-report-scheduler',
      state: '/var/lib/8west-php/safeharbor',
      sessions: '/var/lib/8west-php/safeharbor/sessions',
      tmp: '/var/lib/8west-php/safeharbor/tmp',
      cache: '/var/cache/8west/safeharbor',
      portalRevocations: '/var/cache/8west/safeharbor/portal-revocations',
    },
    fpm: { listen: '/run/8west-php-safeharbor/fpm.sock', listenOwner: 'root', listenGroup: 'www-data', listenMode: '0660', maxChildren: 4 },
    siblings: [
      { app: 'milepost', user: 'eightwest-milepost', group: 'eightwest-milepost', uid: 1601, gid: 1601, listen: '/run/8west-php-milepost/fpm.sock', listenGroup: 'www-data', listenMode: '0660', evidence: 'declared' },
      { app: 'id', user: 'eightwest-id', group: 'eightwest-id', uid: 1603, gid: 1603, listen: '/run/8west-php-id/fpm.sock', listenGroup: 'www-data', listenMode: '0660', evidence: 'declared' },
      { app: 'website', user: 'eightwest-website', group: 'eightwest-website', uid: 1604, gid: 1604, listen: '/run/8west-php-website/fpm.sock', listenGroup: 'www-data', listenMode: '0660', evidence: 'unverified' },
    ],
    handlers: [
      { serverName: 'safeharbor.8westit.com', port: 80, documentRoot: '/srv/8west/apps/safeharbor/current/public', php: 'fpm' },
      { serverName: 'safeharbor.8westit.com', port: 443, documentRoot: '/srv/8west/apps/safeharbor/current/public', php: 'fpm' },
    ],
    cron: CRON_SCRIPTS.filter((s) => !unrouted.has(s)).map((script) => ({
      script, schedule: script === 'run_business_reports.sh' ? '*/5 * * * *' : '* * * * *', user: 'eightwest-safeharbor', args: [],
    })),
    unroutedCron: [
      ...WRAPPED.map((script) => ({ script, reason: 'invoked-by-wrapper' })),
      ...OPERATOR_CLI.map((script) => ({ script, reason: 'manual-operator' })),
    ],
    configReads: [
      { id: 'app-config', status: 'declared', path: '/srv/8west/apps/safeharbor/current/config/config.php', owner: 'ubuntu', group: 'eightwest-safeharbor', mode: '0640' },
      { id: 'portal-revocation-cache', status: 'held', note: 'portal.revocation_cache_dir moves to the private portal-revocations child with the cache change' },
      { id: 'suite-jwks-cache', status: 'held' },
      { id: 'suite-revocation-cache', status: 'held' },
    ],
    observed: [],
  };
}

const mutate = (fn) => { const input = sampleInput(); fn(input); return input; };

function refusal(input, code, options) {
  let caught = null;
  try { renderArtifacts(input, options); } catch (e) { caught = e; }
  assert.ok(caught instanceof RenderError, `expected a RenderError with ${code}, got ${caught && caught.stack}`);
  assert.ok(caught.errors.some((e) => e.startsWith(`${code}:`)), `expected ${code} in:\n${caught.errors.join('\n')}`);
}

const tmp = (prefix) => fs.mkdtempSync(path.join(os.tmpdir(), `sh-runtime-isolation-${prefix}-`));
function trySymlink(target, link, type) {
  try { fs.symlinkSync(target, link, type); return true; } catch (e) { if (['EPERM', 'EACCES', 'ENOSYS'].includes(e.code)) return false; throw e; }
}
function treeDigest(root) {
  const hash = crypto.createHash('sha256');
  const walk = (dir) => {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true }).sort((a, b) => (a.name < b.name ? -1 : 1))) {
      const full = path.join(dir, entry.name);
      hash.update(`${path.relative(root, full)}\0`);
      if (entry.isDirectory()) walk(full);
      else hash.update(fs.readFileSync(full));
    }
  };
  walk(root);
  return hash.digest('hex');
}

test('the reviewed sample renders a complete candidate that is never certified', () => {
  const { artifacts, facts } = renderArtifacts(sampleInput());
  assert.deepEqual(Object.keys(artifacts).sort(), [
    'apache-safeharbor.conf', 'cron-safeharbor.env', 'facts.json', 'manifest.json', 'pool-safeharbor.conf', 'provision-private-dirs-safeharbor.sh',
  ]);
  const report = JSON.parse(artifacts['facts.json']);
  assert.equal(report.certified, false);
  assert.equal(report.status, 'offline-candidate-not-installed');
  const ids = new Set(facts.map((f) => f.id));
  for (const [id] of PROFILE.staticFacts) assert.ok(ids.has(id), `per-app compatibility fact ${id} must stay open`);
  for (const id of ['config.portal-revocation-cache.held', 'config.suite-jwks-cache.held', 'sibling.website.unverified', 'php.sapi-ini', 'fpm.raw-fastcgi-proof']) {
    assert.ok(ids.has(id), `missing unmet fact ${id}`);
  }
  for (const script of WRAPPED) assert.ok(ids.has(`cron.${script}.unrouted`));

  const pool = artifacts['pool-safeharbor.conf'];
  assert.match(pool, /^user = eightwest-safeharbor$/m);
  assert.match(pool, /^listen = \/run\/8west-php-safeharbor\/fpm\.sock$/m);
  assert.match(pool, /^listen\.group = www-data$/m);
  assert.match(pool, /^listen\.mode = 0660$/m);
  assert.doesNotMatch(pool, /listen\.acl_/);

  const cron = artifacts['cron-safeharbor.env'];
  assert.match(cron, /^\*\/5 \* \* \* \* eightwest-safeharbor \/usr\/bin\/bash \/srv\/8west\/apps\/safeharbor\/current\/cron\/run_business_reports\.sh$/m);
  assert.match(cron, /^# unrouted \(invoked-by-wrapper\): \/srv\/8west\/apps\/safeharbor\/current\/cron\/business_reports\.php$/m);
  assert.doesNotMatch(cron, / www-data /);
});

test('the Safeharbor cache contract: private cache and portal-revocations child, both 0700 and app-owned', () => {
  const script = renderArtifacts(sampleInput()).artifacts['provision-private-dirs-safeharbor.sh'];
  assert.match(script, /^ensure_private_tree '\/var\/cache\/8west\/safeharbor' 'portal-revocations'$/m, 'the cache root and its portal-revocations child form one private tree');
  assert.match(script, /^ensure_private_tree '\/var\/lib\/8west-php\/safeharbor' 'sessions' 'tmp'$/m);
  assert.doesNotMatch(script, /ensure_private_dir/);
  assert.match(script, /ensure_root_ancestor '\/var\/cache\/8west'/);
  assert.match(script, /install -d -o "\$APP_UID" -g "\$APP_GID" -m 0700/);
  assert.match(script, /"\$DEPLOY_UID:\$APP_GID:2750:directory"/);

  refusal(mutate((i) => { i.paths.cache = '/var/cache/8west/shared'; }), 'path.private-prefix');
  refusal(mutate((i) => { i.paths.portalRevocations = '/srv/8west/apps/safeharbor/shared/portal-revocations'; }), 'path.private-prefix');
  refusal(mutate((i) => { i.paths.portalRevocations = '/tmp/safeharbor-portal-revocations'; }), 'path.shared-tmp');
  refusal(mutate((i) => { i.paths.portalRevocations = i.paths.cache; }), 'path.duplicate');
  refusal(mutate((i) => { delete i.paths.portalRevocations; }), 'schema.missing-key');
  refusal(mutate((i) => { i.configReads = i.configReads.filter((c) => c.id !== 'portal-revocation-cache'); }), 'config.omission');
  const mismatch = renderArtifacts(mutate((i) => { i.observed = [{ path: '/var/cache/8west/safeharbor/portal-revocations', type: 'directory', uid: 33, gid: 33, mode: '0770' }]; }));
  assert.ok(mismatch.facts.some((f) => f.id === 'observed.portalRevocations.mismatch'));
});

test('rendering is deterministic and independent of input ordering', () => {
  const first = renderArtifacts(sampleInput()).artifacts;
  assert.deepEqual(renderArtifacts(sampleInput()).artifacts, first);
  const permuted = sampleInput();
  for (const key of ['siblings', 'handlers', 'cron', 'unroutedCron', 'configReads']) permuted[key].reverse();
  const reordered = Object.fromEntries(Object.entries(permuted).reverse());
  assert.deepEqual(renderArtifacts(reordered).artifacts, first);
  for (const text of Object.values(first)) assert.doesNotMatch(text, /\r/);
});

test('malicious path, UID, socket, owner and mode inputs are refused', () => {
  const cases = [
    ['path.traversal', (i) => { i.paths.sessions = '/var/lib/8west-php/safeharbor/../milepost/sessions'; }],
    ['path.unsafe', (i) => { i.paths.tmp = '/var/lib/8west-php/safeharbor/tmp dir'; }],
    ['path.broad', (i) => { i.paths.deployState = '/var/lib'; }],
    ['path.broad', (i) => { i.paths.appRoot = '/srv/8west/apps'; }],
    ['path.private-inside-code', (i) => { i.paths.sessions = '/srv/8west/apps/safeharbor/current/sessions'; }],
    ['path.private-tree', (i) => { i.paths.sessions = '/var/lib/8west-php/safeharbor/php/sessions'; }],
    ['paths.state-inside-code', (i) => { i.paths.deployState = '/srv/8west/apps/safeharbor/current/var'; }],
    ['identity.root', (i) => { i.identity.uid = 0; }],
    ['identity.id-type', (i) => { i.identity.gid = '1602'; }],
    ['identity.shared-uid', (i) => { i.identity.uid = 33; }],
    ['identity.supplementary-group', (i) => { i.identity.supplementaryGroups = ['www-data']; }],
    ['fpm.tcp-listener', (i) => { i.fpm.listen = '127.0.0.1:9001'; }],
    ['fpm.socket-world', (i) => { i.fpm.listenMode = '0666'; }],
    ['fpm.socket-app-group', (i) => { i.fpm.listenGroup = 'eightwest-safeharbor'; }],
    ['fpm.acl-override', (i) => { i.fpm.listenAclGroups = 'www-data'; }],
    ['code.runtime-owned', (i) => { i.code.owner = 'eightwest-safeharbor'; }],
    ['code.frontend-group', (i) => { i.code.group = 'www-data'; }],
    ['code.writable', (i) => { i.code.dirMode = '2770'; }],
    ['code.mode', (i) => { i.code.dirMode = '0750'; }],
    ['config.frontend-readable', (i) => { i.configReads[0].group = 'www-data'; }],
    ['config.world-readable', (i) => { i.configReads[0].mode = '0644'; }],
  ];
  for (const [code, fn] of cases) refusal(mutate(fn), code);
});

test('same-UID, sibling and frontend exposure fail the boundary', () => {
  const cases = [
    ['identity.shared-uid', (i) => { i.siblings[0].uid = 1602; }],
    ['sibling.frontend-uid', (i) => { i.siblings[2].uid = 33; i.siblings[2].user = 'www-data'; }],
    ['sibling.shared-socket', (i) => { i.siblings[1].listen = '/run/8west-php-safeharbor/fpm.sock'; }],
    ['sibling.socket-reachable', (i) => { i.siblings[0].listenGroup = 'eightwest-safeharbor'; }],
    ['frontend.app-group-member', (i) => { i.frontend.supplementaryGroups = ['eightwest-safeharbor']; }],
    ['frontend.mod-php', (i) => { i.frontend.phpModule = 'loaded'; }],
    ['handler.mod-php', (i) => { i.handlers[1].php = 'mod_php'; }],
    ['handler.cross-root', (i) => { i.handlers.push({ serverName: 'support.8westit.com', port: 443, documentRoot: '/srv/8west/apps/milepost/current/public', php: 'fpm' }); }],
  ];
  for (const [code, fn] of cases) refusal(mutate(fn), code);
});

test('symlinks are refused in observed target paths and in local renderer inputs', (t) => {
  refusal(mutate((i) => { i.observed = [{ path: '/var/cache/8west/safeharbor', type: 'symlink' }]; }), 'path.symlink');
  refusal(mutate((i) => { i.observed = [{ path: '/srv/8west/apps/safeharbor', type: 'symlink' }]; }), 'path.symlink');
  refusal(mutate((i) => { i.observed = [{ path: '/srv/8west/apps/safeharbor/current', type: 'directory', uid: 1000, gid: 1602, mode: '2770' }]; }), 'code.writable-observed');

  const dir = tmp('symlink');
  try {
    const real = path.join(dir, 'input.json');
    fs.writeFileSync(real, JSON.stringify(sampleInput()));
    if (!trySymlink(real, path.join(dir, 'link.json'), 'file')) { t.diagnostic('symlink creation is not permitted here; local symlink cases skipped'); return; }
    assert.throws(() => readInput(path.join(dir, 'link.json')), (e) => e instanceof RenderError && e.errors[0].startsWith('input.symlink:'));
    assert.ok(trySymlink(TEMPLATE_DIR, path.join(dir, 'templates'), 'dir'));
    refusal(sampleInput(), 'template.dir', { templateDir: path.join(dir, 'templates') });
    const fakeRoot = path.join(dir, 'source');
    fs.mkdirSync(path.join(fakeRoot, 'app', 'cron'), { recursive: true });
    for (const s of CRON_SCRIPTS.slice(1)) fs.writeFileSync(path.join(fakeRoot, 'app', 'cron', s), '#\n');
    fs.writeFileSync(path.join(dir, 'outside.php'), '<?php\n');
    assert.ok(trySymlink(path.join(dir, 'outside.php'), path.join(fakeRoot, 'app', 'cron', CRON_SCRIPTS[0]), 'file'));
    refusal(sampleInput(), 'source.symlink', { sourceRoot: fakeRoot });
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});

test('handler, cron and config omissions are refused rather than silently certified', () => {
  const cases = [
    ['handler.omission', (i) => { i.handlers = i.handlers.filter((h) => h.port !== 80); }],
    ['handler.source-exposure', (i) => { i.handlers[0].php = 'none'; }],
    ['cron.omission', (i) => { i.cron = i.cron.filter((c) => c.script !== 'run_business_reports.sh'); }],
    ['cron.omission', (i) => { i.unroutedCron = i.unroutedCron.filter((u) => u.script !== 'business_reports.php'); }],
    ['cron.frontend-user', (i) => { i.cron[0].user = 'www-data'; }],
    ['cron.unknown-script', (i) => { i.cron.push({ script: 'westy_mail.cron', schedule: '* * * * *', user: 'eightwest-safeharbor', args: [] }); }],
    ['cron.conflict', (i) => { i.unroutedCron.push({ script: 'run_business_reports.sh', reason: 'unverified' }); }],
    ['config.omission', (i) => { i.configReads = i.configReads.filter((c) => c.id !== 'app-config'); }],
    ['config.omission', (i) => { i.configReads = i.configReads.filter((c) => c.id !== 'suite-jwks-cache'); }],
  ];
  for (const [code, fn] of cases) refusal(mutate(fn), code);
});

test('immutable source: rendering writes only a NEW directory outside the repository', () => {
  const before = treeDigest(TOOL_DIR);
  const dir = tmp('out');
  try {
    const out = path.join(dir, 'candidate');
    const result = renderToDirectory(sampleInput(), out);
    assert.deepEqual(fs.readdirSync(out).sort(), Object.keys(result.artifacts).sort());
    const original = fs.readFileSync(path.join(out, 'facts.json'));
    assert.throws(() => renderToDirectory(sampleInput(), out), (e) => e instanceof RenderError && e.errors[0].startsWith('output.exists:'));
    assert.deepEqual(fs.readFileSync(path.join(out, 'facts.json')), original);
    assert.throws(() => renderToDirectory(sampleInput(), path.join(REPO_ROOT, 'deploy', 'rendered')), (e) => e instanceof RenderError && e.errors[0].startsWith('output.inside-source:'));
    assert.equal(fs.existsSync(path.join(REPO_ROOT, 'deploy', 'rendered')), false);
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
  assert.equal(treeDigest(TOOL_DIR), before);
});

test('the renderer is Node stdlib only and never executes provisioning, services or permission changes', () => {
  const source = fs.readFileSync(RENDERER, 'utf8');
  const imports = [...source.matchAll(/^import .* from '([^']+)';$/gm)].map((m) => m[1]).sort();
  assert.deepEqual(imports, ['node:crypto', 'node:fs', 'node:path', 'node:url']);
  assert.doesNotMatch(source, /['"](?:node:)?child_process['"]|\b(?:execFile|execFileSync|execSync|spawn|spawnSync|fork)\(/);
  assert.doesNotMatch(source, /\.(?:chmod|chown|lchown|symlink|rm|rmdir|unlink|rename)(?:Sync)?\(|createServer|listen\(/);

  const script = renderArtifacts(sampleInput()).artifacts['provision-private-dirs-safeharbor.sh'];
  assert.doesNotMatch(script.split('\n').filter((l) => !/^\s*#/.test(l)).join('\n'), FORBIDDEN_PROVISION_TOKENS);
  assert.match(script, /^mode=verify$/m);
  const installs = script.split('\n').filter((l) => !/^\s*#/.test(l) && /\binstall -d\b/.test(l));
  assert.equal(installs.length, 4, 'root ancestor, root-only tree top, child and top hand-over are the only creators');
  const tree = privateTreeFunctions(script).split('\n');
  const rootTop = tree.findIndex((l) => /install -d -o root -g root -m 0700 -- "\$top"$/.test(l));
  const child = tree.findIndex((l) => /install -d -o "\$APP_UID" -g "\$APP_GID" -m 0700 -- "\$p"$/.test(l));
  const handOver = tree.findIndex((l) => /install -d -o "\$APP_UID" -g "\$APP_GID" -m 0700 -- "\$top"$/.test(l));
  assert.ok(rootTop > 0 && child > rootTop && handOver > child, 'the top is root-only while its children are created, and is handed over last');
  assert.match(script, /--apply-missing refuses to create inside an app-writable directory/);
  assert.doesNotMatch(script, /protected_parent/, 'the old check that accepted an app-owned parent for creation is gone');
});

/** The rendered private-tree functions, so the Linux race fixtures run the shipped bytes. */
function privateTreeFunctions(script) {
  return ['fail', 'meta', 'protected_chain', 'ensure_private_tree'].map((name) => {
    const m = new RegExp(`^${name}\\(\\) \\{(?:.*\\}$|[\\s\\S]*?^\\}$)`, 'm').exec(script);
    assert.ok(m, `rendered provisioning script defines ${name}()`);
    return m[0];
  }).join('\n');
}

/** A directory whose whole ancestor chain is root-owned without group/other write, as protected_chain requires. */
function protectedFixtureParent() {
  for (const candidate of ['/run', '/var/lib']) {
    let ok = true;
    for (let d = candidate; ; d = path.dirname(d)) {
      let st;
      try { st = fs.lstatSync(d); } catch { ok = false; break; }
      if (!st.isDirectory() || st.uid !== 0 || (st.mode & 0o022)) { ok = false; break; }
      if (d === '/') break;
    }
    if (ok) return candidate;
  }
  return null;
}

test('Linux fixtures: private trees are created root-only, never inside an app-writable directory, under a symlink swap', (t) => {
  if (process.platform !== 'linux' || process.getuid() !== 0) {
    t.skip('Linux root only: the race fixtures need real ownership changes, so nothing about the private-directory race is proven on this host');
    return;
  }
  const SETPRIV = '/usr/bin/setpriv';
  const parent = protectedFixtureParent();
  assert.ok(fs.existsSync(SETPRIV), 'util-linux setpriv is required to act as the app UID; a missing attacker is not a pass');
  assert.ok(parent, 'a root-owned, non-writable fixture parent (/run or /var/lib) is required');
  const functions = privateTreeFunctions(renderArtifacts(sampleInput()).artifacts['provision-private-dirs-safeharbor.sh']);
  const APP = 1602;
  const appMeta = `${APP}:${APP}:700:directory`;
  const meta = (p) => {
    const s = fs.lstatSync(p);
    return `${s.uid}:${s.gid}:${(s.mode & 0o7777).toString(8)}:${s.isSymbolicLink() ? 'symlink' : s.isDirectory() ? 'directory' : 'other'}`;
  };
  const asApp = (...argv) => execFileSync(SETPRIV, [`--reuid=${APP}`, `--regid=${APP}`, '--clear-groups', '--', ...argv]);
  const q = (s) => `'${s}'`;
  const fixtures = [];
  const fixture = () => {
    const base = fs.mkdtempSync(path.join(parent, 'sh-private-tree-'));
    fixtures.push(base);
    fs.chmodSync(base, 0o755); // the attacker can traverse to every target; refusals are not ENOENT/EACCES on the way
    const anc = path.join(base, 'anc');
    const sentinel = path.join(base, 'sentinel');
    for (const d of [anc, sentinel]) { fs.mkdirSync(d); fs.chmodSync(d, 0o755); }
    return { base, anc, top: path.join(anc, 'app'), sentinel, log: path.join(base, 'attack.log') };
  };
  const appOwnedTop = (f) => { fs.mkdirSync(f.top); fs.chownSync(f.top, APP, APP); fs.chmodSync(f.top, 0o700); };
  // The attacker runs as the app UID immediately before every install call, i.e. after the absence check:
  // it tries to replace each target with a symlink to a root-owned sentinel directory.
  const run = (f, mode, targets, call) => {
    fs.writeFileSync(f.log, '');
    const harness = [
      'set -euo pipefail', 'export LC_ALL=C',
      `readonly APP_UID=${APP} APP_GID=${APP}`, `mode=${mode}`, 'failures=0', functions,
      `readonly SENTINEL=${q(f.sentinel)} ATTACK_LOG=${q(f.log)}`,
      `ATTACK_TARGETS=(${targets.map(q).join(' ')})`,
      '/usr/bin/install() {',
      '    local t',
      '    printf \'INSTALL %s\\n\' "${*: -1}" >>"$ATTACK_LOG"',
      '    for t in ${ATTACK_TARGETS[@]+"${ATTACK_TARGETS[@]}"}; do',
      `        if ${SETPRIV} --reuid="$APP_UID" --regid="$APP_GID" --clear-groups -- /usr/bin/ln -s -T -- "$SENTINEL" "$t" 2>/dev/null; then`,
      '            printf \'PLANTED %s\\n\' "$t" >>"$ATTACK_LOG"',
      '        else',
      '            printf \'BLOCKED %s\\n\' "$t" >>"$ATTACK_LOG"',
      '        fi',
      '    done',
      '    command /usr/bin/install "$@"',
      '}',
      call,
      'printf "FAILURES %d\\n" "$failures"',
    ].join('\n');
    const out = execFileSync('bash', ['-c', harness], { encoding: 'utf8' });
    return { out, log: fs.readFileSync(f.log, 'utf8') };
  };
  const count = (text, re) => (text.match(re) || []).length;

  try {
    // Control: a naive check-then-install under an app-owned directory gives the attacker a real window, and
    // the hook really intercepts /usr/bin/install. Without this, the cases below could pass vacuously.
    {
      const f = fixture();
      appOwnedTop(f);
      const ctl = path.join(f.top, 'ctl');
      const { log } = run(f, 'apply', [ctl], [
        'naive_create() { [[ -e "$1" ]] || /usr/bin/install -d -o "$APP_UID" -g "$APP_GID" -m 0700 -- "$1"; }',
        `naive_create ${q(ctl)} || printf 'NAIVE_STATUS %d\\n' "$?"`,
      ].join('\n'));
      assert.match(log, /^INSTALL /m, `the install hook must intercept /usr/bin/install:\n${log}`);
      assert.ok(log.includes(`PLANTED ${ctl}`), `the attacker must be able to swap a path inside an app-owned directory:\n${log}`);
      t.diagnostic(`naive control: the root-owned sentinel is ${meta(f.sentinel)} after install -d met the swapped symlink (0:0:755 means this install did not follow it)`);
    }

    // A fresh tree: the top is root-only while children are created, so every swap attempt is blocked.
    {
      const f = fixture();
      const targets = [f.top, path.join(f.top, 'sessions'), path.join(f.top, 'tmp')];
      const { out, log } = run(f, 'apply', targets, `ensure_private_tree ${q(f.top)} 'sessions' 'tmp'`);
      assert.match(out, /^FAILURES 0$/m, out);
      assert.doesNotMatch(log, /^PLANTED /m, `no swap may succeed while the tree is created:\n${log}`);
      assert.equal(count(log, /^INSTALL /gm), 4, log);
      assert.equal(count(log, /^BLOCKED /gm), 12, log);
      for (const p of targets) assert.equal(meta(p), appMeta, p);
      assert.equal(meta(f.sentinel), '0:0:755:directory');
    }

    // Verify mode creates nothing.
    {
      const f = fixture();
      const { out, log } = run(f, 'verify', [], `ensure_private_tree ${q(f.top)} 'sessions' 'tmp'`);
      assert.match(out, /^FAILURES 3$/m, out);
      assert.equal(log, '');
      assert.equal(fs.existsSync(f.top), false);
    }

    // An existing app-owned top: --apply-missing refuses the absent children instead of racing the app UID.
    {
      const f = fixture();
      appOwnedTop(f);
      const sessions = path.join(f.top, 'sessions');
      const first = run(f, 'apply', [sessions], `ensure_private_tree ${q(f.top)} 'sessions' 'tmp'`);
      assert.match(first.out, /^FAILURES 2$/m, first.out);
      assert.equal(count(first.out, /refuses to create inside an app-writable directory/g), 2, first.out);
      assert.equal(first.log, '', 'no install may run inside an app-writable directory');
      assert.equal(fs.existsSync(sessions), false);
      asApp('/usr/bin/ln', '-s', '-T', '--', f.sentinel, sessions); // the app UID really can plant here
      const second = run(f, 'apply', [], `ensure_private_tree ${q(f.top)} 'sessions' 'tmp'`);
      assert.ok(second.out.includes(`MISMATCH ${sessions} is a symlink`), second.out);
      assert.equal(second.log, '');
      assert.equal(meta(f.sentinel), '0:0:755:directory');
    }

    // An app-writable, sticky-world-writable or symlinked ancestor refuses before anything is created.
    for (const [label, prepare, why] of [
      ['app-owned ancestor', (f) => fs.chownSync(f.anc, APP, APP), 'is not root-owned without group or other write'],
      ['sticky world-writable ancestor', (f) => fs.chmodSync(f.anc, 0o1777), 'is not root-owned without group or other write'],
      ['group-writable ancestor', (f) => fs.chmodSync(f.base, 0o775), 'is not root-owned without group or other write'],
    ]) {
      const f = fixture();
      prepare(f);
      const { out, log } = run(f, 'apply', [f.top], `ensure_private_tree ${q(f.top)} 'sessions' 'tmp'`);
      assert.match(out, /^FAILURES 1$/m, `${label}:\n${out}`);
      assert.ok(out.includes(why), `${label}:\n${out}`);
      assert.equal(log, '', `${label}: nothing may be created`);
      assert.equal(fs.existsSync(f.top), false, label);
    }
    {
      const f = fixture();
      const linked = path.join(f.base, 'anc-link');
      fs.symlinkSync(f.anc, linked);
      const { out, log } = run(f, 'apply', [], `ensure_private_tree ${q(path.join(linked, 'app'))} 'sessions'`);
      assert.ok(out.includes(`ancestor ${linked} is not a physical directory`), out);
      assert.equal(log, '');
    }
  } finally {
    for (const base of fixtures) fs.rmSync(base, { recursive: true, force: true });
  }
});

test('CLI: --check writes nothing and refusals exit 2 with every reason', () => {
  const dir = tmp('cli');
  try {
    const good = path.join(dir, 'good.json');
    fs.writeFileSync(good, JSON.stringify(sampleInput()));
    const out = JSON.parse(execFileSync(process.execPath, [RENDERER, '--input', good, '--check'], { encoding: 'utf8' }));
    assert.equal(out.certified, false);
    assert.deepEqual(fs.readdirSync(dir), ['good.json']);
    const bad = path.join(dir, 'bad.json');
    fs.writeFileSync(bad, JSON.stringify(mutate((i) => { i.identity.uid = 33; i.handlers[0].php = 'mod_php'; })));
    let status = null; let stderr = '';
    try { execFileSync(process.execPath, [RENDERER, '--input', bad, '--check'], { encoding: 'utf8', stdio: 'pipe' }); } catch (e) { status = e.status; stderr = e.stderr; }
    assert.equal(status, 2);
    const refused = JSON.parse(stderr);
    assert.ok(refused.errors.some((e) => e.startsWith('identity.shared-uid:')));
    assert.ok(refused.errors.some((e) => e.startsWith('handler.mod-php:')));
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});

// ---------------------------------------------------------------------------------------------- R2 cold window

const INSTALLER = path.join(REPO_ROOT, 'deploy', 'remote-install-safeharbor-app.sh');
const CLIENT = path.join(REPO_ROOT, 'deploy', 'deploy.sh');
const HASHER = path.join(REPO_ROOT, 'deploy', 'hash-safeharbor-app-artifact.sh');

test('R2 cold facts stay open and the pool states FPM process control truthfully', () => {
  const { artifacts, facts } = renderArtifacts(sampleInput());
  const byId = new Map(facts.map((f) => [f.id, f.detail]));
  for (const id of ['cold.first-transition', 'cold.fpm-process-control', 'cold.independent-inverse', 'safeharbor.cold-installer']) {
    assert.ok(byId.has(id), `missing open R2 fact ${id}`);
  }
  assert.match(byId.get('cold.first-transition'), /two planned same-parent pidfd USR1/);
  assert.match(byId.get('cold.first-transition'), /nothing here proves/);
  assert.match(byId.get('cold.independent-inverse'), /only before the 90-minute gate/);
  // Facts describe THIS branch: the dedicated-identity cron/wrapper port is not claimed here.
  assert.doesNotMatch(byId.get('safeharbor.other-crons'), /now name eightwest-safeharbor/);
  assert.equal(JSON.parse(artifacts['facts.json']).certified, false);
  const pool = artifacts['pool-safeharbor.conf'];
  const global = pool.slice(pool.indexOf('[global]'), pool.indexOf('[safeharbor]'));
  assert.match(global, /^process_control_timeout = 0$/m);
  assert.match(global, /^; WARNING: 0 is explicit and is NOT protection\./m);
  assert.equal((pool.match(/process_control_timeout/g) || []).length, 1);
  assert.match(pool, /^pm = ondemand$/m);
  assert.match(pool, /^pm\.process_idle_timeout = 20s$/m);
});

test('cold installer: carrier-only phases precede the unchanged ordinary path and never broaden metadata', () => {
  const source = fs.readFileSync(INSTALLER, 'utf8');
  const coldStart = source.indexOf("if [[ \"${1:-}\" == '--cold' ]]; then");
  const ordinaryArgs = source.indexOf('[[ "$#" -eq 8 \\');
  assert.ok(coldStart > 0 && ordinaryArgs > coldStart, 'the cold block must exit before the ordinary argument contract');
  const cold = source.slice(coldStart, ordinaryArgs);
  assert.match(cold, /case "\$COLD_PHASE" in prepare\|apply\|commit\|inverse\) ;; \*\) deploy_fail 'cold-phase-invalid' 64 ;; esac/);
  assert.match(cold, /\[\[ "\$here" == "\$COLD_ADAPTER_DIR" \]\] \|\| deploy_fail 'cold-not-the-installed-adapter'/);
  assert.match(cold, /\/usr\/local\/libexec\/8west\/cold-window\.d\/safeharbor/);
  assert.match(cold, /\/proc\/self\/fd\/\$SAFEHARBOR_COLD_LOCK_FD/);
  assert.match(cold, /cold-report-lock-not-held-by-the-carrier/);
  assert.match(cold, /cold-inverse-map-does-not-cover-the-plan/);
  assert.match(cold, /cold-inverse-did-not-reproduce-the-previous-deployed-artifact/);
  assert.match(cold, /cold-prepare-replayed/);
  assert.match(cold, /cold-apply-replayed/);
  // SH-R2-01..03: exact ACL in the map and the inverse, partial-effect journal and apply lock, prior release record.
  assert.match(cold, /read -r op path sha uid gid mode acl extra/);
  assert.match(cold, /setfacl --set="\$acl"/);
  assert.match(cold, /cold_journal "leaf"\$'\\t'"\$op"\$'\\t'"\$relative"/);
  assert.match(cold, /flock --exclusive --nonblock "\$cold_lock_fd" \|\| deploy_fail 'cold-apply-may-still-run' 75/);
  assert.match(cold, /cold-prior-record-not-restored/);
  assert.ok(cold.indexOf("cold-inverse-did-not-reproduce-the-previous-deployed-artifact") < cold.indexOf('cold_record=restored'),
    'the prior release record returns only after the previous source verified');
  // MP-F2/SH-F1 (A105): the installed leaf is journaled before its rename, and the inverse acts only on that after-state.
  const installAt = cold.indexOf('cold_install_leaf() {');
  const install = cold.slice(installAt, cold.indexOf('\n    }\n', installAt));
  assert.ok(install.indexOf('cold_journal "installed"') > 0
    && install.indexOf('cold_journal "installed"') < install.indexOf('/usr/bin/mv -f -T -- "$temp" "$target"'));
  assert.match(cold, /restore:installed\|remove:installed\)/);
  assert.match(cold, /held\) printf 'SAFEHARBOR_COLD_PRESERVED=%s\\n' "\$relative" >&2; cold_rc=2; continue ;;/);
  // A109: a created directory's identity, type, owner/group/mode and ACL are journaled right after install -d, before
  // any leaf is placed in it; the inverse removes only that exact empty directory, its removal journaled first, and
  // keeps no empty-only rmdir rule.
  const dirRecord = install.indexOf("printf 'dir\\t%s\\t%s\\t%s\\n'");
  assert.ok(dirRecord > install.indexOf('/usr/bin/install -d') && dirRecord < install.indexOf('cold_journal "temp"'));
  const dirRemoved = cold.indexOf("printf 'removed\\t%s\\n' \"$relative\" >> \"$COLD_DIRS\"");
  assert.ok(dirRemoved > 0 && dirRemoved < cold.indexOf('/usr/bin/rmdir -- "$live"'));
  assert.doesNotMatch(cold, /rmdir -- "\$COLD_APP_ROOT\/\$relative" 2>\/dev\/null \|\|/);
  // SH-R2-04: the active-scheduler gate stays in every cold phase; this file never removes or renames that name.
  assert.match(cold, /\[\[ ! -e "\$COLD_CRON_ACTIVE" && ! -L "\$COLD_CRON_ACTIVE" \]\] \|\| deploy_fail 'active-report-scheduler-present' 75/);
  assert.doesNotMatch(cold, /(?:rm|mv|unlink)\b[^\n]*COLD_CRON_ACTIVE/);
  // Version normalization is kept; no recursive chown/chmod and no hasher path from the caller in cold mode.
  assert.match(cold, /\?v=\$version/);
  assert.doesNotMatch(cold, /chown -R|chmod -R|-exec \/usr\/bin\/chmod|--artifact-hasher/);
  assert.doesNotMatch(cold, /systemctl|apache2ctl|reload/);
  // The ordinary path keeps every existing gate exactly.
  const ordinary = source.slice(ordinaryArgs);
  for (const token of ['--artifact-hasher', 'active-report-scheduler-present', 'legacy-report-runner-in-flight',
    '/usr/bin/flock --exclusive --nonblock 9', 'staged-source-artifact-digest-mismatch',
    'SAFEHARBOR_DEPLOY=installed-under-exclusive-report-lock', '/usr/bin/chown -R "$DEPLOY_OWNER:www-data" -- "$APP_ROOT"']) {
    assert.ok(ordinary.includes(token), `ordinary path lost ${token}`);
  }
});

test('cold client: the artifact is pinned before any remote effect and Live needs a verified receipt', () => {
  const source = fs.readFileSync(CLIENT, 'utf8');
  const coldStart = source.indexOf('if [[ "$SAFEHARBOR_DEPLOY_MODE" == cold ]]; then');
  const staging = source.indexOf('echo "==> Staging exact root-owned deploy controls on $SERVER"');
  assert.ok(coldStart > 0 && staging > coldStart, 'the cold branch must precede any uploaded control');
  const cold = source.slice(coldStart, staging);
  assert.match(cold, /tar --sort=name --format=gnu --owner=0 --group=0 --numeric-owner --mtime=@0/);
  assert.match(cold, /gzip -n -9/);
  assert.match(cold, /SAFEHARBOR_COLD_EXPECT_SHA256:-\}" != "\$COLD_SHA"/);
  assert.match(cold, /sudo -n \$COLD_WINDOW enter safeharbor/);
  assert.match(cold, /"state": "ACCEPTED"/);
  assert.doesNotMatch(cold, /\/usr\/bin\/tee|chmod 0600|\$REMOTE_INSTALLER_PATH|\$REMOTE_HASHER_PATH/);
  assert.ok(source.indexOf('exit 3') < staging, 'an unpinned digest stops before any remote effect');
  const ordinaryLive = source.lastIndexOf('==> Live: https://safeharbor.8westit.com');
  assert.ok(source.lastIndexOf('SAFEHARBOR_DEPLOY=installed-under-exclusive-report-lock') < ordinaryLive,
    'the ordinary path prints Live only after verifying the installer receipt');
});

const LINUX_ROOT = process.platform === 'linux' && process.getuid() === 0;
const COLD_SKIP = 'Linux root only: the cold installer requires real root, flock, getfacl/setfacl and ownership; nothing is proven on this host';

/**
 * One fresh synthetic cold slot: the root-installed adapter copy, a live (previous) tree whose selected leaves carry a
 * public-read ACL and a foreign/custom named user+group ACL, the current release record and a candidate artifact
 * (it adds lib/s/new.php inside a new directory). join() stands in for the carrier's leaf join (the real join is the
 * ID carrier's, covered by its model tests): accepted bytes plus the exact prior uid/gid/mode and numeric access ACL.
 */
function coldFixture() {
  const root = fs.mkdtempSync('/tmp/safeharbor-cold-test.');
  const op = 'd'.repeat(32);
  const base = { PATH: '/usr/sbin:/usr/bin:/sbin:/bin' };
  const sh = (script) => execFileSync('bash', ['-c', script], { encoding: 'utf8', env: base });
  const sha = (p) => crypto.createHash('sha256').update(fs.readFileSync(p)).digest('hex');
  fs.chmodSync(root, 0o755);
  const adapter = path.join(root, 'usr/local/libexec/8west/cold-window.d/safeharbor');
  const slot = path.join(root, 'var/lib/8west-cold-window', op, 'safeharbor');
  const app = path.join(root, 'srv/8west/apps/safeharbor/current');
  const lockDir = path.join(root, 'var/lib/safeharbor-report-scheduler');
  const records = path.join(lockDir, 'releases');
  const record = path.join(records, 'current-app-artifact.manifest');
  const lock = path.join(lockDir, 'business-reports-deploy.lock');
  for (const d of [adapter, slot, path.join(app, 'lib'), path.join(app, 'public'), path.join(app, 'config'), records]) fs.mkdirSync(d, { recursive: true });
  fs.chmodSync(slot, 0o700); fs.chmodSync(records, 0o700);
  fs.copyFileSync(INSTALLER, path.join(adapter, 'remote-install-safeharbor-app.sh'));
  fs.copyFileSync(HASHER, path.join(adapter, 'hash-safeharbor-app-artifact.sh'));
  const live = { 'lib/render.php': '<?php // css?v=20260101000000\n', 'lib/westy.php': '<?php // westy\n',
    'public/login.php': '<?php // login?v=20260101000000\n', 'lib/old.php': '<?php // removed by the release\n' };
  for (const [p, c] of Object.entries(live)) fs.writeFileSync(path.join(app, p), c);
  fs.writeFileSync(path.join(app, 'config/config.php'), '<?php // private\n');
  sh(`chown -R root:root '${app}' && find '${app}' -type d -exec chmod 2750 {} + && find '${app}' -type f -exec chmod 0640 {} +`);
  sh(`setfacl -m u:33:r-- '${app}/public/login.php' && setfacl -m u:4321:r--,g:2001:r-- '${app}/lib/westy.php'`);
  const previous = sh(`bash '${HASHER}' '${app}'`).trim();
  const priorRecord = `schema=safeharbor-deployed-app-artifact-v1\nrelease_sha=${'b'.repeat(40)}\ndeployed_artifact_sha256=${previous}\n`;
  fs.writeFileSync(record, priorRecord); fs.chmodSync(record, 0o600);
  const stage = fs.mkdtempSync(path.join(os.tmpdir(), 'sh-cold-stage-'));
  const cand = { 'lib/render.php': '<?php // css?v=abc\n', 'lib/s/new.php': '<?php // new in a new directory\n',
    'lib/westy.php': '<?php // westy v2\n', 'public/login.php': '<?php // login?v=abc\n', 'public/new.php': '<?php // new\n' };
  for (const [p, c] of Object.entries(cand)) { fs.mkdirSync(path.join(stage, 'app', path.dirname(p)), { recursive: true }); fs.writeFileSync(path.join(stage, 'app', p), c); }
  fs.mkdirSync(path.join(stage, 'app/config'), { recursive: true });
  sh(`tar -czf '${slot}/artifact' -C '${stage}' app && chmod 0400 '${slot}/artifact'`);
  const source = sh(`bash '${HASHER}' '${stage}/app'`).trim();
  fs.rmSync(stage, { recursive: true, force: true });
  fs.writeFileSync(lock, '');
  const env = { ...base, SAFEHARBOR_COLD_TEST_ROOT: root, COLD_WINDOW_OPERATION: op, COLD_WINDOW_SLOT: slot,
    SAFEHARBOR_COLD_APP_ROOT: '/srv/8west/apps/safeharbor/current', SAFEHARBOR_COLD_RUNTIME_GROUP: 'root',
    SAFEHARBOR_COLD_DEPLOY_OWNER: 'root', SAFEHARBOR_COLD_HASHER: '/usr/local/libexec/8west/cold-window.d/safeharbor/hash-safeharbor-app-artifact.sh',
    SAFEHARBOR_COLD_HASHER_SHA256: sha(HASHER), SAFEHARBOR_COLD_RELEASE_SHA: 'a'.repeat(40),
    SAFEHARBOR_COLD_SOURCE_ARTIFACT_SHA256: source, SAFEHARBOR_COLD_LOCK_FD: '9' };
  const held = (phase) => `exec 9<'${lock}' && flock -x 9 && bash '${adapter}/remote-install-safeharbor-app.sh' --cold ${phase} --cold-slot '${slot}'`;
  const run = (phase) => spawnSync('bash', ['-c', held(phase)], { encoding: 'utf8', env });
  const ok = (phase) => {
    const r = run(phase);
    assert.equal(r.status, 0, `cold ${phase} failed (${r.status}):\n${r.stdout}\n${r.stderr}`);
    return r.stdout;
  };
  const meta = (p) => sh(`stat -c '%u:%g:%a' -- '${p}'`).trim();
  const acl = (p) => {
    const out = sh(`getfacl --absolute-names --omit-header --numeric --no-effective -- '${p}' | sed '/^$/d' | paste -sd, -`).trim();
    return /^user::[rwx-]{3},group::[rwx-]{3},other::[rwx-]{3}$/.test(out) ? '-' : out;
  };
  const snapshot = () => Object.fromEntries(Object.keys(live).map((p) => {
    const f = path.join(app, p);
    return [p, fs.existsSync(f) ? `${fs.readFileSync(f, 'utf8')}|${meta(f)}|${acl(f)}` : 'absent'];
  }));
  const join = () => {
    const lines = [];
    for (const row of fs.readFileSync(path.join(slot, 'plan.tsv'), 'utf8').trim().split('\n')) {
      const [op2, rel, digest] = row.split('\t');
      if (op2 === 'create') { lines.push(`remove\t${rel}\t${digest}\t-\t-\t-\t-`); continue; }
      fs.mkdirSync(path.join(slot, 'recovery', path.dirname(rel)), { recursive: true });
      fs.copyFileSync(path.join(app, rel), path.join(slot, 'recovery', rel));
      const [u, g, m] = meta(path.join(app, rel)).split(':');
      lines.push(`restore\t${rel}\t${digest}\t${u}\t${g}\t0${m}\t${acl(path.join(app, rel))}`);
    }
    fs.writeFileSync(path.join(slot, 'inverse.tsv'), `${lines.join('\n')}\n`);
  };
  const hash = () => sh(`bash '${HASHER}' '${app}'`).trim();
  const cleanup = () => fs.rmSync(root, { recursive: true, force: true });
  return { root, op, slot, app, lock, record, records, priorRecord, previous, cand, sh, run, ok, held, env, meta, acl,
    snapshot, join, hash, cleanup, progress: path.join(slot, 'apply.progress'), applyLock: path.join(slot, 'apply.lock') };
}

const lockBusy = (file) => spawnSync('flock', ['-n', file, 'true']).status !== 0;
function until(condition, what) {
  const end = Date.now() + 10000;
  while (!condition()) {
    if (Date.now() > end) assert.fail(`timed out waiting for ${what}`);
    execFileSync('sleep', ['0.1']);
  }
}

test('Linux root fixture: cold installer phases bind the plan, keep exact metadata and ACLs and invert exactly', (t) => {
  if (!LINUX_ROOT) { t.skip(COLD_SKIP); return; }
  const f = coldFixture();
  try {
    const before = f.snapshot();
    assert.match(before['public/login.php'], /\|user::rw-,user:33:r--,group::r--,mask::r--,other::---$/);
    assert.match(before['lib/westy.php'], /\|user::rw-,user:4321:r--,group::r--,group:2001:r--,mask::r--,other::---$/);
    // Without the carrier's held lock the phase refuses.
    assert.notEqual(spawnSync('bash', ['-c', f.held('prepare').replace(' && flock -x 9', '')], { encoding: 'utf8', env: f.env }).status, 0);
    f.ok('prepare');
    const plan = fs.readFileSync(path.join(f.slot, 'plan.tsv'), 'utf8');
    for (const row of ['replace\tlib/render.php\t', 'replace\tlib/westy.php\t', 'replace\tpublic/login.php\t', 'create\tpublic/new.php\t',
      'create\tlib/s/new.php\t', 'delete\tlib/old.php\t']) {
      assert.ok(plan.includes(row), `plan lacks ${row}`);
    }
    assert.equal(fs.readFileSync(path.join(f.slot, 'prior-current-record'), 'utf8'), f.priorRecord);
    assert.notEqual(f.run('apply').status, 0, 'apply refuses without the carrier inverse map');
    f.join();
    const map = fs.readFileSync(path.join(f.slot, 'inverse.tsv'), 'utf8');
    assert.match(map, /^restore\tlib\/westy\.php\t[0-9a-f]{64}\t0\t0\t0640\tuser::rw-,user:4321:r--,group::r--,group:2001:r--,mask::r--,other::---$/m);
    assert.match(map, /^restore\tlib\/render\.php\t[0-9a-f]{64}\t0\t0\t0640\t-$/m);
    f.ok('apply');
    assert.match(fs.readFileSync(path.join(f.app, 'lib/westy.php'), 'utf8'), /westy v2/);
    assert.ok(!fs.existsSync(path.join(f.app, 'lib/old.php')));
    assert.doesNotMatch(fs.readFileSync(path.join(f.app, 'lib/render.php'), 'utf8'), /\?v=abc/, 'version normalization kept');
    assert.equal((fs.statSync(path.join(f.app, 'public/new.php')).mode & 0o7777).toString(8), '640');
    const journal = fs.readFileSync(f.progress, 'utf8');
    assert.match(journal, /^leaf\treplace\tlib\/render\.php$/m);
    assert.match(journal, /^temp\tlib\/s\/new\.php\.sh-cold\.[0-9]+$/m);
    const westy = fs.statSync(path.join(f.app, 'lib/westy.php'));
    assert.ok(journal.includes(`installed\tlib/westy.php\t${westy.dev}:${westy.ino}\t${crypto.createHash('sha256').update('<?php // westy v2\n').digest('hex')}\t-\n`),
      'the installed identity, bytes and ACL are journaled');
    const created = fs.statSync(path.join(f.app, 'lib/s'));
    assert.ok(fs.readFileSync(path.join(f.slot, 'created-dirs.list'), 'utf8')
      .includes(`lib/s\ndir\tlib/s\t${created.dev}:${created.ino}\tdirectory\t0:0:2750\t-\n`),
    'the new directory is journaled before it exists and with its identity, owner/group/mode and ACL right after');
    const out = f.ok('inverse');
    assert.match(out, /^SAFEHARBOR_COLD_APPLY=complete$/m);
    assert.match(out, /^SAFEHARBOR_COLD_RELEASE_RECORD=unchanged$/m);
    assert.match(out, /^SAFEHARBOR_COLD=inverse-exact$/m);
    assert.deepEqual(f.snapshot(), before, 'bytes, owner/group/mode and the public-read and custom ACLs are exact');
    assert.ok(!fs.existsSync(path.join(f.app, 'public/new.php')) && !fs.existsSync(path.join(f.app, 'lib/s')));
    assert.equal(f.hash(), f.previous);
  } finally {
    f.cleanup();
  }
});

test('Linux root fixture: an ACL changed after the carrier join refuses the cold apply before any effect', (t) => {
  if (!LINUX_ROOT) { t.skip(COLD_SKIP); return; }
  const f = coldFixture();
  try {
    f.ok('prepare');
    f.join();
    f.sh(`setfacl -x g:2001 '${f.app}/lib/westy.php'`);
    const r = f.run('apply');
    assert.equal(r.status, 75, r.stderr);
    assert.match(r.stderr, /cold-live-drift-since-prepare/);
    assert.equal(fs.readFileSync(path.join(f.app, 'lib/render.php'), 'utf8'), '<?php // css?v=20260101000000\n');
    assert.ok(!fs.existsSync(f.progress) && !fs.existsSync(f.applyLock), 'no effect and no apply custody before the check');
  } finally {
    f.cleanup();
  }
});

test('Linux root fixture: a first-leaf failure stays accountable and an explicitly selected inverse restores it exactly', (t) => {
  if (!LINUX_ROOT) { t.skip(COLD_SKIP); return; }
  const f = coldFixture();
  try {
    const before = f.snapshot();
    const unreached = ['lib/westy.php', 'public/login.php', 'lib/old.php'];
    const inodes = () => unreached.map((p) => fs.statSync(path.join(f.app, p)).ino);
    const priorInodes = inodes();
    f.ok('prepare');
    f.join();
    // The injected fault: a regular file where the second planned leaf needs its new directory.
    fs.writeFileSync(path.join(f.app, 'lib/s'), 'fault\n');
    const r = f.run('apply');
    assert.equal(r.status, 77, r.stderr);
    assert.match(r.stderr, /cold-live-parent-not-directory/);
    assert.equal(fs.readFileSync(path.join(f.app, 'lib/render.php'), 'utf8'), f.cand['lib/render.php'], 'the first leaf changed');
    const journal = fs.readFileSync(f.progress, 'utf8');
    assert.match(journal, /^leaf\treplace\tlib\/render\.php\ntemp\tlib\/render\.php\.sh-cold\.[0-9]+\nleaf\tcreate\tlib\/s\/new\.php$/m);
    assert.ok(!fs.existsSync(path.join(f.slot, 'apply.env')));
    assert.match(f.run('apply').stderr, /cold-apply-replayed/, 'a failed apply is never replayed');
    fs.rmSync(path.join(f.app, 'lib/s'));
    const out = f.ok('inverse');
    assert.match(out, /^SAFEHARBOR_COLD_APPLY=partial$/m);
    assert.match(out, /^SAFEHARBOR_COLD=inverse-exact$/m);
    assert.deepEqual(f.snapshot(), before);
    assert.deepEqual(inodes(), priorInodes, 'leaves the failed apply never reached are verified, not rewritten');
    assert.equal(f.hash(), f.previous);
  } finally {
    f.cleanup();
  }
});

test('Linux root fixture: after a lost apply result the inverse waits for the apply lock and removes only journaled temps', (t) => {
  if (!LINUX_ROOT) { t.skip(COLD_SKIP); return; }
  const f = coldFixture();
  let holder = null;
  try {
    const before = f.snapshot();
    f.ok('prepare');
    f.join();
    f.ok('apply');
    // What a lost result can leave behind: a journaled temp of an install that never renamed, and an apply child that
    // still holds the apply lock.
    const own = 'public/login.php.sh-cold.99999';
    fs.appendFileSync(f.progress, `temp\t${own}\n`);
    fs.writeFileSync(path.join(f.app, own), 'partial install\n');
    holder = spawn('bash', ['-c', `exec 7<'${f.applyLock}'; flock -x 7; exec sleep 120`], { stdio: 'ignore' });
    until(() => lockBusy(f.applyLock), 'the stand-in apply child to hold the apply lock');
    let r = f.run('inverse');
    assert.equal(r.status, 75, r.stderr);
    assert.match(r.stderr, /cold-apply-may-still-run/);
    assert.ok(fs.existsSync(path.join(f.app, 'public/new.php')) && fs.existsSync(path.join(f.app, own)), 'nothing touched');
    holder.kill('SIGKILL');
    until(() => !lockBusy(f.applyLock), 'the apply lock to be released');
    const foreign = 'lib/westy.php.sh-cold.1';
    fs.writeFileSync(path.join(f.app, foreign), 'foreign or unknown\n');
    r = f.run('inverse');
    assert.equal(r.status, 74, `a foreign leaf keeps the previous digest unreproducible:\n${r.stdout}\n${r.stderr}`);
    assert.match(r.stderr, new RegExp(`^SAFEHARBOR_COLD_FOREIGN_TEMP_PRESERVED=${foreign.replace(/\./g, '\\.')}$`, 'm'));
    assert.ok(!fs.existsSync(path.join(f.app, own)), 'the journaled operation temp is removed');
    assert.equal(fs.readFileSync(path.join(f.app, foreign), 'utf8'), 'foreign or unknown\n', 'the foreign temp survives');
    fs.rmSync(path.join(f.app, foreign));  // the owner's own disposition of the foreign leaf
    const out = f.ok('inverse');
    assert.match(out, /^SAFEHARBOR_COLD=inverse-exact$/m);
    assert.deepEqual(f.snapshot(), before);
    assert.equal(f.hash(), f.previous);
  } finally {
    if (holder && holder.exitCode === null) holder.kill('SIGKILL');
    f.cleanup();
  }
});

test('Linux root fixture: commit then inverse returns the prior release record only after the source verified', (t) => {
  if (!LINUX_ROOT) { t.skip(COLD_SKIP); return; }
  const cases = {
    'post-commit inverse': { change: () => {}, status: 0, record: 'prior' },
    'a foreign current record': { change: (f) => fs.writeFileSync(f.record, 'schema=someone-else\n'), status: 2, record: 'foreign' },
    'an unverified source': { change: (f) => fs.appendFileSync(path.join(f.app, 'public/new.php'), '// later owner edit\n'), status: 2, record: 'committed' },
  };
  for (const [label, c] of Object.entries(cases)) {
    const f = coldFixture();
    try {
      f.ok('prepare');
      f.join();
      f.ok('apply');
      f.ok('commit');
      const committed = fs.readFileSync(f.record, 'utf8');
      assert.notEqual(committed, f.priorRecord, label);
      const immutable = fs.readdirSync(f.records).filter((n) => /^app-artifact\.a{40}\.[0-9a-f]{64}\.manifest$/.test(n));
      assert.equal(immutable.length, 1, label);
      c.change(f);
      const foreign = fs.readFileSync(f.record, 'utf8');
      const r = f.run('inverse');
      assert.equal(r.status, c.status, `${label}:\n${r.stdout}\n${r.stderr}`);
      const now = fs.readFileSync(f.record, 'utf8');
      if (c.record === 'prior') {
        assert.match(r.stdout, /^SAFEHARBOR_COLD_RELEASE_RECORD=restored$/m);
        assert.equal(now, f.priorRecord);
        assert.equal(f.meta(f.record), '0:0:600');
        assert.equal(f.hash(), f.previous);
      } else if (c.record === 'foreign') {
        assert.match(r.stdout, /^SAFEHARBOR_COLD_RELEASE_RECORD=preserved$/m);
        assert.equal(now, foreign, 'a record this operation did not commit is never overwritten');
      } else {
        assert.doesNotMatch(r.stdout, /SAFEHARBOR_COLD_RELEASE_RECORD=/, 'no record step before the source verified');
        assert.equal(now, committed);
      }
      assert.equal(fs.readFileSync(path.join(f.records, immutable[0]), 'utf8'), committed, `${label}: immutable history is kept`);
    } finally {
      f.cleanup();
    }
  }
});

/** A leaf's whole observable state: type, identity, link count, bytes, owner/group/mode and access ACL. */
function leafState(f, rel) {
  const p = path.join(f.app, rel);
  if (!fs.existsSync(p)) return 'absent';
  const s = fs.lstatSync(p);
  if (!s.isFile()) return `${s.isDirectory() ? 'directory' : 'other'}|${s.ino}|${f.meta(p)}`;
  return `file|${s.ino}|${s.nlink}|${fs.readFileSync(p, 'utf8')}|${f.meta(p)}|${f.acl(p)}`;
}

test('Linux root fixture: an inverse before any apply verifies every prior leaf and rewrites none; a changed or foreign leaf HOLDs', (t) => {
  if (!LINUX_ROOT) { t.skip(COLD_SKIP); return; }
  const cases = {
    'untouched': { change: () => {} },
    'a prior leaf changed before any apply': { leaf: 'lib/westy.php', change: (f) => f.sh(`chmod 0600 '${f.app}/lib/westy.php'`) },
    'a foreign file where the release creates a leaf': { leaf: 'public/new.php', change: (f) => fs.writeFileSync(path.join(f.app, 'public/new.php'), 'foreign\n') },
  };
  for (const [label, c] of Object.entries(cases)) {
    const f = coldFixture();
    try {
      f.ok('prepare');
      f.join();
      c.change(f);
      const prior = Object.fromEntries(['lib/render.php', 'lib/westy.php', 'public/login.php', 'lib/old.php', 'public/new.php']
        .map((p) => [p, leafState(f, p)]));
      const r = f.run('inverse');
      assert.match(r.stdout, /^SAFEHARBOR_COLD_APPLY=not-started$/m, label);
      for (const [p, s] of Object.entries(prior)) assert.equal(leafState(f, p), s, `${label}: ${p} was rewritten or removed`);
      if (c.leaf) {
        assert.equal(r.status, 2, `${label}:\n${r.stdout}\n${r.stderr}`);
        assert.match(r.stderr, new RegExp(`^SAFEHARBOR_COLD_PRESERVED=${c.leaf.replace(/\./g, '\\.')}$`, 'm'), label);
        assert.match(r.stdout, /^SAFEHARBOR_COLD=inverse-hold$/m, label);
      } else {
        assert.equal(r.status, 0, `${label}:\n${r.stdout}\n${r.stderr}`);
        assert.match(r.stdout, /^SAFEHARBOR_COLD=inverse-exact$/m, label);
        assert.equal(f.hash(), f.previous, label);
      }
    } finally {
      f.cleanup();
    }
  }
});

test('Linux root fixture: a later owner change to an applied leaf is preserved as a HOLD, never overwritten or masked by the digest', (t) => {
  if (!LINUX_ROOT) { t.skip(COLD_SKIP); return; }
  const cases = {
    'replaced leaf rewritten in place to its prior bytes': { leaf: 'lib/westy.php', change: (f) => fs.writeFileSync(path.join(f.app, 'lib/westy.php'), '<?php // westy\n') },
    'replaced leaf mode': { leaf: 'lib/westy.php', change: (f) => f.sh(`chmod 0600 '${f.app}/lib/westy.php'`) },
    'replaced leaf owner': { leaf: 'lib/westy.php', change: (f) => f.sh(`chown 4321 '${f.app}/lib/westy.php'`) },
    'replaced leaf ACL': { leaf: 'public/login.php', change: (f) => f.sh(`setfacl -m u:4321:r-- '${f.app}/public/login.php'`) },
    'replaced leaf identity (same bytes, metadata and ACL)': { leaf: 'public/login.php',
      change: (f) => f.sh(`cp -a '${f.app}/public/login.php' '${f.app}/public/login.owner' && mv -f '${f.app}/public/login.owner' '${f.app}/public/login.php'`) },
    'replaced leaf hard-linked': { leaf: 'lib/westy.php', change: (f) => fs.linkSync(path.join(f.app, 'lib/westy.php'), path.join(f.app, 'lib/westy.link')) },
    'replaced leaf became a directory': { leaf: 'lib/westy.php',
      change: (f) => { fs.rmSync(path.join(f.app, 'lib/westy.php')); fs.mkdirSync(path.join(f.app, 'lib/westy.php')); } },
    'deleted leaf recreated': { leaf: 'lib/old.php', change: (f) => fs.writeFileSync(path.join(f.app, 'lib/old.php'), '<?php // removed by the release\n') },
    'created leaf bytes': { leaf: 'public/new.php', change: (f) => fs.appendFileSync(path.join(f.app, 'public/new.php'), '// later owner edit\n') },
    'created leaf mode': { leaf: 'public/new.php', change: (f) => f.sh(`chmod 0600 '${f.app}/public/new.php'`) },
  };
  for (const [label, c] of Object.entries(cases)) {
    const f = coldFixture();
    try {
      f.ok('prepare');
      f.join();
      f.ok('apply');
      c.change(f);
      const later = leafState(f, c.leaf);
      const r = f.run('inverse');
      assert.equal(r.status, 2, `${label}:\n${r.stdout}\n${r.stderr}`);
      assert.match(r.stderr, new RegExp(`^SAFEHARBOR_COLD_PRESERVED=${c.leaf.replace(/\./g, '\\.')}$`, 'm'), label);
      assert.match(r.stdout, /^SAFEHARBOR_COLD=inverse-hold$/m, label);
      assert.doesNotMatch(r.stdout, /SAFEHARBOR_COLD_RELEASE_RECORD=/, `${label}: no record step over an unverified source`);
      assert.equal(leafState(f, c.leaf), later, `${label}: the later state is kept exactly`);
      // The leaves still exactly as applied are inverted exactly.
      assert.equal(fs.readFileSync(path.join(f.app, 'lib/render.php'), 'utf8'), '<?php // css?v=20260101000000\n', label);
      if (c.leaf !== 'public/new.php') assert.ok(!fs.existsSync(path.join(f.app, 'public/new.php')), label);
    } finally {
      f.cleanup();
    }
  }
});

/** The created directory's whole observable state: type, identity, owner/group/mode, access ACL and the entries other
 * than the applied leaf the inverse still removes (or, for a file, its bytes). */
function dirState(f, rel) {
  const p = path.join(f.app, rel);
  if (!fs.existsSync(p)) return 'absent';
  const s = fs.lstatSync(p);
  if (!s.isDirectory()) return `${s.isFile() ? `file|${fs.readFileSync(p, 'utf8')}` : 'other'}|${s.ino}|${f.meta(p)}`;
  return `directory|${s.ino}|${f.meta(p)}|${f.acl(p)}|${fs.readdirSync(p).filter((n) => n !== 'new.php').sort().join(',')}`;
}

test('Linux root fixture: a created directory is removed only while it is exactly the journaled, empty directory; otherwise it HOLDs', (t) => {
  if (!LINUX_ROOT) { t.skip(COLD_SKIP); return; }
  const dir = (f) => path.join(f.app, 'lib/s');
  const cases = {
    'own, unchanged and empty (and a repeated inverse)': { exact: true, change: () => {} },
    'replaced by another directory (same owner, mode and ACL)': { change: (f) => f.sh(`mv '${dir(f)}' '${dir(f)}.old' && mkdir '${dir(f)}' `
      + `&& chmod 2750 '${dir(f)}' && mv '${dir(f)}.old/new.php' '${dir(f)}/new.php' && rmdir '${dir(f)}.old'`) },
    'became a regular file': { leafToo: true, change: (f) => { fs.rmSync(dir(f), { recursive: true }); fs.writeFileSync(dir(f), 'owner file\n'); } },
    'owner (uid) drift': { change: (f) => f.sh(`chown 4321 '${dir(f)}'`) },
    'group (gid) drift': { change: (f) => f.sh(`chgrp 2001 '${dir(f)}'`) },
    'mode drift': { change: (f) => f.sh(`chmod 2700 '${dir(f)}'`) },
    'access ACL drift': { change: (f) => f.sh(`setfacl -m u:4321:r-x '${dir(f)}'`) },
    'holds data': { change: (f) => fs.writeFileSync(path.join(dir(f), 'owner.txt'), 'owner data\n') },
    'missing creation proof (a crash between the creation and its record)': { change: (f) => {
      const list = path.join(f.slot, 'created-dirs.list');
      fs.writeFileSync(list, fs.readFileSync(list, 'utf8').split('\n').filter((l) => !l.startsWith('dir\tlib/s\t')).join('\n'));
    } },
  };
  for (const [label, c] of Object.entries(cases)) {
    const f = coldFixture();
    try {
      const before = f.snapshot();
      f.ok('prepare');
      f.join();
      f.ok('apply');
      const list = path.join(f.slot, 'created-dirs.list');
      const s = fs.statSync(dir(f));
      assert.ok(fs.readFileSync(list, 'utf8').includes(`lib/s\ndir\tlib/s\t${s.dev}:${s.ino}\tdirectory\t0:0:2750\t-\n`), label);
      c.change(f);
      const later = dirState(f, 'lib/s');
      const r = f.run('inverse');
      if (c.exact) {
        assert.equal(r.status, 0, `${label}:\n${r.stdout}\n${r.stderr}`);
        assert.match(r.stdout, /^SAFEHARBOR_COLD=inverse-exact$/m, label);
        assert.ok(!fs.existsSync(dir(f)), `${label}: the own unchanged empty directory is removed`);
        assert.match(fs.readFileSync(list, 'utf8'), /^removed\tlib\/s$/m, `${label}: its removal is journaled`);
        const again = f.run('inverse');
        assert.equal(again.status, 0, `${label}: a repeated inverse verifies its own removal:\n${again.stdout}\n${again.stderr}`);
        assert.equal(f.hash(), f.previous, label);
      } else {
        assert.equal(r.status, 2, `${label}:\n${r.stdout}\n${r.stderr}`);
        assert.match(r.stderr, /^SAFEHARBOR_COLD_PRESERVED=lib\/s\/$/m, label);
        assert.match(r.stdout, /^SAFEHARBOR_COLD=inverse-hold$/m, label);
        assert.doesNotMatch(r.stdout, /SAFEHARBOR_COLD_RELEASE_RECORD=/, `${label}: no record step over an unverified source`);
        assert.equal(dirState(f, 'lib/s'), later, `${label}: the later state is kept exactly`);
        if (c.leafToo) assert.match(r.stderr, /^SAFEHARBOR_COLD_PRESERVED=lib\/s\/new\.php$/m, `${label}: the gone leaf is named`);
        else assert.ok(!fs.existsSync(path.join(dir(f), 'new.php')), `${label}: the unchanged created leaf is removed`);
      }
      // Every other entry is inverted exactly.
      assert.deepEqual(f.snapshot(), before, label);
      assert.ok(!fs.existsSync(path.join(f.app, 'public/new.php')), label);
    } finally {
      f.cleanup();
    }
  }
});

test('Linux root fixture: the active report scheduler name refuses every cold phase until the carrier holds it', (t) => {
  if (!LINUX_ROOT) { t.skip(COLD_SKIP); return; }
  const f = coldFixture();
  try {
    const active = path.join(f.root, 'etc/cron.d/safeharbor-business-reports');
    fs.mkdirSync(path.dirname(active), { recursive: true });
    fs.writeFileSync(active, '*/5 * * * * www-data /usr/bin/bash /srv/8west/apps/safeharbor/current/cron/run_business_reports.sh\n');
    const r = f.run('prepare');
    assert.equal(r.status, 75, r.stderr);
    assert.match(r.stderr, /active-report-scheduler-present/);
    assert.ok(!fs.existsSync(path.join(f.slot, 'plan.tsv')));
    // The carrier's supported hold renames the name inside its operation (never deletes it); the gate then passes.
    fs.renameSync(active, `${active}.cold-hold-${f.op}`);
    f.ok('prepare');
    assert.ok(fs.existsSync(`${active}.cold-hold-${f.op}`));
  } finally {
    f.cleanup();
  }
});
