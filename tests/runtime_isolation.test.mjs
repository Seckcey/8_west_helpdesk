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
import { execFileSync } from 'node:child_process';
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

test('Linux root fixture: cold installer phases bind the plan, keep exact metadata and invert exactly', (t) => {
  if (process.platform !== 'linux' || process.getuid() !== 0) {
    t.skip('Linux root only: the cold installer requires real root, flock, setfacl and ownership; nothing is proven on this host');
    return;
  }
  const root = fs.mkdtempSync('/tmp/safeharbor-cold-test.');
  const op = 'd'.repeat(32);
  const sh = (script, env = {}) => execFileSync('bash', ['-c', script], { encoding: 'utf8', env: { PATH: '/usr/sbin:/usr/bin:/sbin:/bin', ...env } });
  const sha = (p) => crypto.createHash('sha256').update(fs.readFileSync(p)).digest('hex');
  try {
    fs.chmodSync(root, 0o755);
    const adapter = path.join(root, 'usr/local/libexec/8west/cold-window.d/safeharbor');
    const slot = path.join(root, 'var/lib/8west-cold-window', op, 'safeharbor');
    const app = path.join(root, 'srv/8west/apps/safeharbor/current');
    const lockDir = path.join(root, 'var/lib/safeharbor-report-scheduler');
    const records = path.join(lockDir, 'releases');
    for (const d of [adapter, slot, path.join(app, 'lib'), path.join(app, 'public'), path.join(app, 'config'), records]) fs.mkdirSync(d, { recursive: true });
    fs.chmodSync(slot, 0o700); fs.chmodSync(records, 0o700);
    fs.copyFileSync(INSTALLER, path.join(adapter, 'remote-install-safeharbor-app.sh'));
    fs.copyFileSync(HASHER, path.join(adapter, 'hash-safeharbor-app-artifact.sh'));
    // The live (previous) tree and the candidate tree.
    const live = { 'lib/render.php': '<?php // css?v=20260101000000\n', 'lib/westy.php': '<?php // westy\n',
      'public/login.php': '<?php // login?v=20260101000000\n', 'lib/old.php': '<?php // removed by the release\n' };
    for (const [p, c] of Object.entries(live)) fs.writeFileSync(path.join(app, p), c);
    fs.writeFileSync(path.join(app, 'config/config.php'), '<?php // private\n');
    sh(`chown -R root:root '${app}' && find '${app}' -type d -exec chmod 2750 {} + && find '${app}' -type f -exec chmod 0640 {} +`);
    const previous = sh(`bash '${HASHER}' '${app}'`).trim();
    fs.writeFileSync(path.join(records, 'current-app-artifact.manifest'), `schema=safeharbor-deployed-app-artifact-v1\ndeployed_artifact_sha256=${previous}\n`);
    fs.chmodSync(path.join(records, 'current-app-artifact.manifest'), 0o600);
    const stage = fs.mkdtempSync(path.join(os.tmpdir(), 'sh-cold-stage-'));
    const cand = { 'lib/render.php': '<?php // css?v=abc\n', 'lib/westy.php': '<?php // westy v2\n',
      'public/login.php': '<?php // login?v=abc\n', 'public/new.php': '<?php // new\n' };
    for (const [p, c] of Object.entries(cand)) { fs.mkdirSync(path.join(stage, 'app', path.dirname(p)), { recursive: true }); fs.writeFileSync(path.join(stage, 'app', p), c); }
    fs.mkdirSync(path.join(stage, 'app/config'), { recursive: true });
    sh(`tar -czf '${slot}/artifact' -C '${stage}' app && chmod 0400 '${slot}/artifact'`);
    const source = sh(`bash '${HASHER}' '${stage}/app'`).trim();
    fs.writeFileSync(path.join(lockDir, 'business-reports-deploy.lock'), '');
    const env = { SAFEHARBOR_COLD_TEST_ROOT: root, COLD_WINDOW_OPERATION: op, COLD_WINDOW_SLOT: slot,
      SAFEHARBOR_COLD_APP_ROOT: '/srv/8west/apps/safeharbor/current', SAFEHARBOR_COLD_RUNTIME_GROUP: 'root',
      SAFEHARBOR_COLD_DEPLOY_OWNER: 'root', SAFEHARBOR_COLD_HASHER: '/usr/local/libexec/8west/cold-window.d/safeharbor/hash-safeharbor-app-artifact.sh',
      SAFEHARBOR_COLD_HASHER_SHA256: sha(HASHER), SAFEHARBOR_COLD_RELEASE_SHA: 'a'.repeat(40),
      SAFEHARBOR_COLD_SOURCE_ARTIFACT_SHA256: source, SAFEHARBOR_COLD_LOCK_FD: '9' };
    const run = (phase) => sh(`exec 9<'${lockDir}/business-reports-deploy.lock' && flock -x 9 && bash '${adapter}/remote-install-safeharbor-app.sh' --cold ${phase} --cold-slot '${slot}'`, env);
    // Without the carrier's held lock the phase refuses.
    assert.throws(() => sh(`exec 9<'${lockDir}/business-reports-deploy.lock' && bash '${adapter}/remote-install-safeharbor-app.sh' --cold prepare --cold-slot '${slot}'`, env));
    run('prepare');
    const plan = fs.readFileSync(path.join(slot, 'plan.tsv'), 'utf8');
    for (const row of ['replace\tlib/render.php\t', 'replace\tlib/westy.php\t', 'replace\tpublic/login.php\t', 'create\tpublic/new.php\t', 'delete\tlib/old.php\t']) {
      assert.ok(plan.includes(row), `plan lacks ${row}`);
    }
    assert.throws(() => run('apply'), 'apply refuses without the carrier inverse map');
    // The carrier's leaf join: the previous live bytes (with their installer stamps) and exact prior metadata.
    const lines = [];
    for (const row of plan.trim().split('\n')) {
      const [op2, rel, digest] = row.split('\t');
      if (op2 === 'create') { lines.push(`remove\t${rel}\t${digest}\t-\t-\t-`); continue; }
      fs.mkdirSync(path.join(slot, 'recovery', path.dirname(rel)), { recursive: true });
      fs.copyFileSync(path.join(app, rel), path.join(slot, 'recovery', rel));
      lines.push(`restore\t${rel}\t${digest}\t0\t0\t0640`);
    }
    fs.writeFileSync(path.join(slot, 'inverse.tsv'), `${lines.join('\n')}\n`);
    run('apply');
    assert.match(fs.readFileSync(path.join(app, 'lib/westy.php'), 'utf8'), /westy v2/);
    assert.ok(!fs.existsSync(path.join(app, 'lib/old.php')));
    assert.doesNotMatch(fs.readFileSync(path.join(app, 'lib/render.php'), 'utf8'), /\?v=abc/, 'version normalization kept');
    assert.equal((fs.statSync(path.join(app, 'public/new.php')).mode & 0o7777).toString(8), '640');
    run('inverse');
    for (const [p, c] of Object.entries(live)) assert.equal(fs.readFileSync(path.join(app, p), 'utf8'), c, `${p} not restored exactly`);
    assert.ok(!fs.existsSync(path.join(app, 'public/new.php')), 'the created leaf survived the inverse');
    assert.equal(sh(`bash '${HASHER}' '${app}'`).trim(), previous);
    fs.rmSync(stage, { recursive: true, force: true });
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});
