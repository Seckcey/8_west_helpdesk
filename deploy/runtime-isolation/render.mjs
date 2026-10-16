#!/usr/bin/env node
/**
 * Offline private-runtime isolation candidate renderer — Safeharbor (T-20261013-05).
 *
 * Reads ONE closed-schema JSON input, validates it against the reviewed Safeharbor runtime contract and
 * writes deterministic, reviewable candidate files into a NEW directory outside this repository:
 *
 *   pool-safeharbor.conf                  dedicated PHP-FPM master/pool candidate
 *   apache-safeharbor.conf                proxy_fcgi insert candidate (not a complete vhost)
 *   provision-private-dirs-safeharbor.sh  verify-only by default; --apply-missing creates ABSENT private dirs only
 *   cron-safeharbor.env                   /etc/cron.d candidate routing every accounted cron script to the app UID
 *   facts.json                            unmet compatibility facts; the candidate is never certified
 *   manifest.json                         SHA-256 and size of every artifact above
 *
 * This file never executes provisioning, starts/stops/reloads services, changes permissions or deploys. It
 * imports no process-spawning module, and it is a review tool, not an application service. Generation is not
 * runtime evidence: every fact in facts.json stays open until the owners' separate live acceptance closes it.
 *
 * Usage:
 *   node deploy/runtime-isolation/render.mjs --input FILE --check
 *   node deploy/runtime-isolation/render.mjs --input FILE --output NEW_DIRECTORY
 */
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
export const TEMPLATE_DIR = path.join(HERE, 'templates');
export const SOURCE_ROOT = path.resolve(HERE, '..', '..');

export const INPUT_SCHEMA = 'eightwest-runtime-isolation-input-v1';
export const FACTS_SCHEMA = 'eightwest-runtime-isolation-facts-v1';

/** The reviewed Safeharbor runtime contract. Inputs must match it; they cannot widen it. */
export const PROFILE = Object.freeze({
  app: 'safeharbor',
  user: 'eightwest-safeharbor',
  deployOwner: 'ubuntu',
  appRoot: '/srv/8west/apps/safeharbor/current',
  documentRoot: '/srv/8west/apps/safeharbor/current/public',
  // The report-scheduler deploy lock and release records live here, outside the application root.
  deployState: '/var/lib/safeharbor-report-scheduler',
  state: '/var/lib/8west-php/safeharbor',
  // Existing JWKS/revocation path meanings: a private cache root and its portal-revocations child, both 0700.
  cache: '/var/cache/8west/safeharbor',
  extraPrivate: Object.freeze({ portalRevocations: '/var/cache/8west/safeharbor/portal-revocations' }),
  socketDir: '/run/8west-php-safeharbor',
  socket: '/run/8west-php-safeharbor/fpm.sock',
  rootAncestors: Object.freeze(['/var/cache/8west', '/var/lib/8west-php']),
  codeDirModes: Object.freeze(['2750']),
  codeFileMode: '0640',
  cronDir: 'app/cron',
  cronInvokers: Object.freeze({ '.php': '/usr/bin/php', '.sh': '/usr/bin/bash' }),
  cronFile: 'safeharbor',
  requiredHandlers: Object.freeze([
    Object.freeze({ serverName: 'safeharbor.8westit.com', port: 80 }),
    Object.freeze({ serverName: 'safeharbor.8westit.com', port: 443 }),
  ]),
  requiredConfigReads: Object.freeze([
    Object.freeze({ id: 'app-config', path: '/srv/8west/apps/safeharbor/current/config/config.php' }),
  ]),
  // Issuer/JWKS/revocation cache reads belong to the held cache change; they must be accounted for, never certified.
  heldConfigReads: Object.freeze(['suite-jwks-cache', 'suite-revocation-cache', 'portal-revocation-cache']),
  staticFacts: Object.freeze([
    ['safeharbor.attachments', '/srv/8west/apps/safeharbor/shared/attachments is www-data 770 today; migrate it per path with exact metadata, never by a recursive reassignment over customer files.'],
    ['safeharbor.cache-config-current', 'The composed app/config/config.sample.php selects /var/cache/8west/safeharbor for suite JWKS and revocation caches, including its portal-revocations child. These are candidate source paths, not a change to protected live config or proof of provisioning, effective UID or cache acceptance; those compatibility facts remain held.'],
    ['safeharbor.mail-state', 'The support-mail lock/cursor and the Westy mail certificate key need exact per-path ownership migration; the private key stays 0400.'],
    ['safeharbor.mod-php', 'Safeharbor runs under mod_php today; the safeharbor.8westit.com vhosts route PHP through FPM only after the frontend cutover.'],
    ['safeharbor.frontend-ancestors', 'The carrier-run cold installer phases grant the frontend traverse on the app root and read/traverse only on the public/ leaves they write; existing public/ ACLs and traverse on /srv/8west/apps/safeharbor and higher ancestors are separate exact selected cold-window entries.'],
    ['safeharbor.other-crons', 'The composed deploy/ report, Westy mail and portal maintenance cron templates name eightwest-safeharbor. The last-observed installed copies and host-only /etc/cron.d/safeharbor and safeharbor-westy-billing entries used www-data; source composition does not update or re-observe them. Each actual identity transition remains an exact selected cold-window entry at cutover.'],
    ['safeharbor.cold-installer', 'deploy.sh SAFEHARBOR_DEPLOY_MODE=cold streams the reviewed app tarball to the root cold carrier and prints Live only for its ACCEPTED receipt; the installer cold phases run only from the root-installed copy with the carrier-held report lock and bind the carrier-selected accepted-current-source inverse and SELECTED canonical sidecar. This composition documents the accepted 6dcf source baseline; old 2c inputs are historical. Version normalization and the prohibition on broad recursive chmod/chown remain. Source composition proves no host installation, execution or inverse acceptance.'],
    ['safeharbor.report-wrapper-tmp', 'app/cron/run_business_reports.sh stages runner output with mktemp in shared /tmp (0600, owner-only); it does not use the private TMPDIR.'],
    ['safeharbor.scheduler-identity', 'The composed report wrapper, scheduler manager, cron template and ordinary installer pin eightwest-safeharbor; carrier-run cold phases retain their server-pinned runtime group. The last-observed tree, protected config and report lock used www-data grouping, which this source work neither changes nor re-observes. Exact selected cold-window entries must establish the compatible metadata, and the owner must regenerate the protected scheduler bundle before runtime acceptance.'],
    ['safeharbor.tenant-ai-freeze-pins', 'deploy/tenant_ai_freeze_profile.json keeps the installed (www-data) cron and vhost pins; they are regenerated by their owner from the installed bytes at cutover, never replaced with prospective source hashes.'],
  ]),
});

const COMMON_FACTS = Object.freeze([
  ['apache.merged-config', 'Validate the merged Apache configuration and real requests for every vhost and alias; the rendered file is an insert candidate, not a complete vhost.'],
  ['cache.config-held', 'The private cache directory is provisioned here, but pointing the application at it is a held cache/config change; no cache configuration is edited by this candidate.'],
  ['cold.first-transition', 'The first transition runs only inside the root cold window (R2): two planned same-parent pidfd USR1 reloads adopt a static-503 maintenance generation and retire the captured finite worker set naturally, cron is stopped dispatcher-only (running jobs survive, skipped ticks are recorded and never replayed), then one WINCH; one 7200-second operation with 15/45/90/120-minute gates and a reserved independent inverse. No universal zero-work observer, nft admission seal or 120-second whole attempt is assumed, and nothing here proves the installed Apache/cron/systemd packages behave as designed.'],
  ['cold.fpm-process-control', 'process_control_timeout = 0 is explicit immediate escalation, not protection, and no finite value protects accepted work; a later cold release sends one bound QUIT only to an original master that is already idle.'],
  ['cold.independent-inverse', 'The inverse is an explicit preselected branch that starts only before the 90-minute gate and only after the NEW runtime is cold again (maintenance re-selected, its captured generation retired, one WINCH); it restores accepted-current-source leaves with their exact prior owner/group/mode and reproduces installer version stamps exactly, removes only unchanged created leaves, and keeps current data, attachments, report state and private config in place.'],
  ['fpm.dedicated-master', 'Run one root FPM master per app loading only its own pool, with empty opcache.preload and opcache.file_cache; verify separate opcache and PrivateTmp lifetimes on the target host.'],
  ['fpm.raw-fastcgi-proof', 'Prove with crafted FastCGI requests (sibling SCRIPT_FILENAME, PHP_VALUE, PHP_ADMIN_VALUE) that siblings and app workers cannot use this socket; a refused HTTP route is not an IPC test.'],
  ['frontend.mod-php-retirement', 'Disable mod_php only after every PHP vhost, alias, CLI and cron path runs under its own identity; any PHP left under the frontend UID can reach every app socket.'],
  ['provision.private-tree-prerequisites', 'Private trees are created root-only and handed over, and creation inside an existing app-owned directory is refused. Not enforced: no concurrent root process, package script or mount on these paths during a run, and no capabilities or root-equivalent helper for the app UID. The Linux race fixture runs only as root on Linux.'],
  ['php.sapi-ini', 'PHP-FPM reads its own php.ini, not the Apache SAPI one; compare effective upload, post, memory and execution limits before cutover and never silently lower a live attachment or body limit.'],
  ['rollback.metadata-preserving', 'Rehearse a metadata-preserving rollback of owners, modes, ACLs, units and cron files before any production step.'],
  ['runtime.identities-prospective', 'UID/GID values are review inputs, not measured production identities; resolve them from the actual identity database before any live step.'],
  ['session.migration', 'Existing sessions live in the shared PHP save path; migrate only with app-specific provenance or a bounded reauthentication, never by copying all shared session files.'],
]);

const NAME_RE = /^[a-z_][a-z0-9_-]{0,31}$/;
const PATH_RE = /^(?:\/[A-Za-z0-9._-]+)+$/;
const MODE_RE = /^[0-7]{4}$/;
const HOST_RE = /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/;
const SCRIPT_RE = /^[a-z0-9_]+\.[a-z]+$/;
const ARG_RE = /^--?[a-z][a-z0-9-]*(?:=[A-Za-z0-9._:/-]+)?$/;
const CRON_FIELD_RE = /^(?:\*|[0-9]{1,2}(?:-[0-9]{1,2})?(?:,[0-9]{1,2}(?:-[0-9]{1,2})?)*)(?:\/[0-9]{1,2})?$/;
const ID_RE = /^[a-z][a-z0-9-]{0,63}$/;
const MAX_ARRAY = 200;
const MAX_INPUT_BYTES = 262144;
const MAX_TEMPLATE_BYTES = 65536;

/** Paths that are never acceptable as a private, code or state location (or as a shared prefix of one). */
const BROAD_PATHS = new Set([
  '/', '/bin', '/boot', '/dev', '/dev/shm', '/etc', '/home', '/lib', '/opt', '/proc', '/root', '/run', '/run/lock',
  '/sbin', '/srv', '/srv/8west', '/srv/8west/apps', '/sys', '/tmp', '/usr', '/var', '/var/cache', '/var/cache/8west',
  '/var/lib', '/var/lib/8west-php', '/var/log', '/var/run', '/var/tmp', '/var/www',
]);
const SHARED_TMP = ['/tmp', '/var/tmp', '/dev/shm'];

const TEMPLATES = Object.freeze({
  pool: 'app-pool.conf.tpl',
  apache: 'apache-site.conf.tpl',
  provision: 'provision-private-dirs.sh.tpl',
  cron: 'cron.env.tpl',
});

/** Tokens that must never appear in the rendered provisioning script: it verifies and creates absent dirs only. */
export const FORBIDDEN_PROVISION_TOKENS = /\b(?:systemctl|service|chown|chmod|chgrp|setfacl|rm|rmdir|sudo|curl|wget|useradd|usermod|groupadd|apt|apt-get|a2enmod|a2dismod|apache2ctl|mount|kill)\b/;

export class RenderError extends Error {
  constructor(errors) {
    super(`runtime isolation input refused:\n  ${errors.join('\n  ')}`);
    this.name = 'RenderError';
    this.errors = errors;
  }
}

const isObject = (v) => v !== null && typeof v === 'object' && !Array.isArray(v) && Object.getPrototypeOf(v) === Object.prototype;
const isInt = (v) => typeof v === 'number' && Number.isInteger(v);
const modeBits = (m) => parseInt(m, 8);
const within = (child, parent) => child === parent || child.startsWith(`${parent}/`);
const strictlyWithin = (child, parent) => child.startsWith(`${parent}/`);

/** Reject duplicate object keys, which JSON.parse would silently collapse and hide from a reviewer. */
function assertNoDuplicateKeys(text) {
  let i = 0;
  const fail = (why) => { throw new RenderError([`schema.json: ${why} at offset ${i}`]); };
  const ws = () => { while (i < text.length && ' \t\r\n'.includes(text[i])) i += 1; };
  const str = () => {
    const start = i; i += 1;
    while (i < text.length) {
      if (text[i] === '\\') { i += 2; continue; }
      if (text[i] === '"') { i += 1; return JSON.parse(text.slice(start, i)); }
      i += 1;
    }
    return fail('unterminated string');
  };
  const value = () => {
    ws();
    const c = text[i];
    if (c === '{') {
      i += 1; ws();
      const seen = new Set();
      if (text[i] === '}') { i += 1; return; }
      for (;;) {
        ws();
        if (text[i] !== '"') fail('expected key');
        const key = str();
        if (seen.has(key)) throw new RenderError([`schema.duplicate-key: ${JSON.stringify(key)}`]);
        seen.add(key);
        ws();
        if (text[i] !== ':') fail('expected colon');
        i += 1; value(); ws();
        const d = text[i]; i += 1;
        if (d === '}') return;
        if (d !== ',') fail('expected comma');
      }
    }
    if (c === '[') {
      i += 1; ws();
      if (text[i] === ']') { i += 1; return; }
      for (;;) {
        value(); ws();
        const d = text[i]; i += 1;
        if (d === ']') return;
        if (d !== ',') fail('expected comma');
      }
    }
    if (c === '"') { str(); return; }
    const m = /^(?:-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?|true|false|null)/.exec(text.slice(i));
    if (!m) fail('unexpected token');
    i += m[0].length;
  };
  value(); ws();
  if (i !== text.length) fail('trailing data');
}

/** Stable JSON with sorted keys, used for the input digest and deterministic artifacts. */
export function canonicalJson(value) {
  if (Array.isArray(value)) return `[${value.map(canonicalJson).join(',')}]`;
  if (isObject(value)) return `{${Object.keys(value).sort().map((k) => `${JSON.stringify(k)}:${canonicalJson(value[k])}`).join(',')}}`;
  return JSON.stringify(value);
}

const sha256 = (data) => crypto.createHash('sha256').update(data).digest('hex');
const byKey = (fn) => (a, b) => { const x = fn(a); const y = fn(b); return x < y ? -1 : x > y ? 1 : 0; };

/** Order-insensitive arrays are sorted so a permuted but equivalent input renders identical bytes. */
function canonicalize(input) {
  const copy = JSON.parse(JSON.stringify(input));
  const sortIf = (key, fn) => { if (Array.isArray(copy[key])) copy[key].sort(byKey(fn)); };
  sortIf('siblings', (s) => String(s && s.app));
  sortIf('handlers', (h) => `${h && h.serverName}:${String(h && h.port).padStart(5, '0')}`);
  sortIf('cron', (c) => `${c && c.script}\0${c && Array.isArray(c.args) ? c.args.join(' ') : ''}\0${c && c.schedule}`);
  sortIf('unroutedCron', (c) => String(c && c.script));
  sortIf('configReads', (c) => String(c && c.id));
  sortIf('observed', (o) => String(o && o.path));
  for (const key of ['identity', 'frontend']) {
    if (isObject(copy[key]) && Array.isArray(copy[key].supplementaryGroups)) copy[key].supplementaryGroups.sort();
  }
  return copy;
}

/**
 * Validate a parsed input against the closed schema and the profile. Returns { facts, model } or throws a
 * RenderError listing every violation. `options.sourceRoot` lets tests point the cron inventory at a fixture tree.
 */
export function validate(rawInput, options = {}) {
  const P = options.profile || PROFILE;
  const sourceRoot = options.sourceRoot || SOURCE_ROOT;
  const errors = [];
  const facts = [];
  const err = (code, detail) => errors.push(`${code}: ${detail}`);
  const fact = (id, detail) => facts.push({ id, detail });

  const closed = (obj, label, required, optional = []) => {
    if (!isObject(obj)) { err('schema.type', `${label} must be an object`); return false; }
    for (const k of Object.keys(obj)) if (!required.includes(k) && !optional.includes(k)) err('schema.unknown-key', `${label}.${k}`);
    let ok = true;
    for (const k of required) if (!Object.prototype.hasOwnProperty.call(obj, k)) { err('schema.missing-key', `${label}.${k}`); ok = false; }
    return ok;
  };
  const list = (v, label, { min = 0 } = {}) => {
    if (!Array.isArray(v)) { err('schema.type', `${label} must be an array`); return []; }
    if (v.length > MAX_ARRAY) { err('schema.size', `${label} exceeds ${MAX_ARRAY} entries`); return []; }
    if (v.length < min) err('schema.size', `${label} needs at least ${min} entr${min === 1 ? 'y' : 'ies'}`);
    return v;
  };
  const name = (v, label) => {
    if (typeof v !== 'string' || !NAME_RE.test(v)) { err('identity.name', `${label} must match ${NAME_RE}`); return false; }
    return true;
  };
  const id = (v, label, { allowSystem = false } = {}) => {
    if (!isInt(v)) { err('identity.id-type', `${label} must be an integer`); return false; }
    if (v === 0) { err('identity.root', `${label} must not be 0 (root)`); return false; }
    if (v === 65534) { err('identity.nobody', `${label} must not be the overflow/nobody id`); return false; }
    if (v < (allowSystem ? 1 : 100) || v > 59999) { err('identity.id-range', `${label}=${v} is outside the reviewed range`); return false; }
    return true;
  };
  const mode = (v, label) => {
    if (typeof v !== 'string' || !MODE_RE.test(v)) { err('mode.format', `${label} must be a four-digit octal string`); return null; }
    return modeBits(v);
  };
  const safePath = (v, label) => {
    if (typeof v !== 'string' || v.length > 255) { err('path.type', `${label} must be an absolute path string`); return false; }
    if (v.split('/').some((seg) => seg === '.' || seg === '..')) { err('path.traversal', `${label}=${JSON.stringify(v)} contains a . or .. segment`); return false; }
    if (BROAD_PATHS.has(v)) { err('path.broad', `${label}=${v} is a broad or critical root`); return false; }
    if (!PATH_RE.test(v)) { err('path.unsafe', `${label}=${JSON.stringify(v)} is not a normalized absolute path of [A-Za-z0-9._-] segments`); return false; }
    return true;
  };

  let input = rawInput;
  if (!closed(input, 'input', ['schema', 'app', 'identity', 'frontend', 'deployOwner', 'code', 'paths', 'fpm', 'siblings', 'handlers', 'cron', 'unroutedCron', 'configReads'], ['observed'])) {
    throw new RenderError(errors);
  }
  input = canonicalize(input);
  if (input.schema !== INPUT_SCHEMA) err('schema.version', `schema must be ${INPUT_SCHEMA}`);
  if (input.app !== P.app) err('schema.app', `app must be ${P.app}`);

  // ---- identities -------------------------------------------------------------------------------------------
  const ident = input.identity;
  const front = input.frontend;
  const owner = input.deployOwner;
  const siblings = list(input.siblings, 'siblings');
  const siblingGroups = new Set(siblings.filter(isObject).map((s) => s.group).filter((g) => typeof g === 'string'));
  if (closed(ident, 'identity', ['user', 'group', 'uid', 'gid', 'supplementaryGroups'])) {
    if (name(ident.user, 'identity.user') && ident.user !== P.user) err('identity.name', `identity.user must be the dedicated ${P.user} account`);
    if (name(ident.group, 'identity.group') && ident.group !== P.user) err('identity.name', `identity.group must be the private ${P.user} group`);
    id(ident.uid, 'identity.uid');
    id(ident.gid, 'identity.gid');
    for (const g of list(ident.supplementaryGroups, 'identity.supplementaryGroups')) {
      err('identity.supplementary-group', `the runtime account must not join ${JSON.stringify(g)}; frontend and sibling groups would break the boundary`);
    }
  }
  if (closed(front, 'frontend', ['user', 'group', 'uid', 'gid', 'supplementaryGroups', 'phpModule'])) {
    name(front.user, 'frontend.user');
    name(front.group, 'frontend.group');
    id(front.uid, 'frontend.uid', { allowSystem: true });
    id(front.gid, 'frontend.gid', { allowSystem: true });
    for (const g of list(front.supplementaryGroups, 'frontend.supplementaryGroups')) {
      if (g === ident.group || siblingGroups.has(g)) err('frontend.app-group-member', `the frontend must not belong to app group ${JSON.stringify(g)}`);
      else if (typeof g !== 'string' || !NAME_RE.test(g)) err('identity.name', 'frontend.supplementaryGroups entries must be group names');
    }
    if (front.phpModule === 'loaded') err('frontend.mod-php', 'a mod_php frontend runs PHP as the web UID that can reach every app socket; the boundary fails');
    else if (front.phpModule === 'unverified') fact('frontend.php-module-unverified', 'The frontend mod_php state was not measured; the candidate assumes it is absent.');
    else if (front.phpModule !== 'absent') err('schema.enum', 'frontend.phpModule must be absent, loaded or unverified');
    if (isObject(ident)) {
      if (front.uid === ident.uid || front.user === ident.user) err('identity.shared-uid', 'the app runtime shares the frontend web UID; different directory names under one UID do not isolate apps');
      if (front.gid === ident.gid || front.group === ident.group) err('identity.shared-gid', 'the app runtime shares the frontend group');
    }
  }
  if (closed(owner, 'deployOwner', ['user', 'uid'])) {
    if (name(owner.user, 'deployOwner.user') && owner.user !== P.deployOwner) err('code.owner', `deployOwner.user must be ${P.deployOwner}`);
    if (id(owner.uid, 'deployOwner.uid', { allowSystem: true }) && isObject(ident) && owner.uid === ident.uid) err('code.runtime-owned', 'the deploy owner must not be the runtime UID');
  }

  // ---- code tree is deployer-owned and never runtime- or frontend-writable ---------------------------------
  const code = input.code;
  if (closed(code, 'code', ['owner', 'group', 'dirMode', 'fileMode'])) {
    if (isObject(ident) && code.owner === ident.user) err('code.runtime-owned', 'application code must not be owned by the runtime account');
    else if (isObject(owner) && code.owner !== owner.user) err('code.owner', `code.owner must be the deploy owner ${P.deployOwner}`);
    if (isObject(front) && code.group === front.group) err('code.frontend-group', 'the frontend group must not read the whole code tree; it receives public-only access from the deployer');
    else if (isObject(ident) && code.group !== ident.group) err('code.group', `code.group must be ${P.user}`);
    for (const [key, allowed] of [['dirMode', P.codeDirModes], ['fileMode', [P.codeFileMode]]]) {
      const bits = mode(code[key], `code.${key}`);
      if (bits === null) continue;
      if (bits & 0o022) err('code.writable', `code.${key}=${code[key]} lets group or other write application code`);
      else if (bits & 0o007) err('code.world-access', `code.${key}=${code[key]} grants other users access to application code`);
      else if (!allowed.includes(code[key])) err('code.mode', `code.${key} must be one of ${allowed.join(', ')}`);
    }
  }

  // ---- paths ---------------------------------------------------------------------------------------------------
  const paths = input.paths;
  const privateKeys = ['state', 'sessions', 'tmp', 'cache', ...Object.keys(P.extraPrivate)];
  const pathsOk = closed(paths, 'paths', ['appRoot', 'documentRoot', 'deployState', ...privateKeys]);
  const valid = {};
  if (pathsOk) {
    for (const key of ['appRoot', 'documentRoot', 'deployState', ...privateKeys]) valid[key] = safePath(paths[key], `paths.${key}`);
    if (valid.appRoot && paths.appRoot !== P.appRoot) err('paths.app-root', `paths.appRoot must be the reviewed application root ${P.appRoot}`);
    if (valid.documentRoot && paths.documentRoot !== `${paths.appRoot}/public`) err('paths.document-root', 'paths.documentRoot must be exactly the application root plus /public');
    if (valid.deployState) {
      if (within(paths.deployState, paths.appRoot) || within(paths.appRoot, paths.deployState)) err('paths.state-inside-code', 'deployment state/backups must stay outside the application root');
      else if (paths.deployState !== P.deployState) err('paths.deploy-state', `paths.deployState must be ${P.deployState}`);
    }
    if (valid.state && paths.state !== P.state) err('path.private-prefix', `paths.state must be ${P.state}`);
    if (valid.cache && paths.cache !== P.cache) err('path.private-prefix', `paths.cache must keep the reviewed meaning ${P.cache}`);
    for (const [key, expected] of Object.entries(P.extraPrivate)) {
      if (valid[key] && paths[key] !== expected) err('path.private-prefix', `paths.${key} must keep the reviewed meaning ${expected}`);
    }
    for (const key of ['sessions', 'tmp']) {
      if (valid[key] && !strictlyWithin(paths[key], P.state)) err('path.private-prefix', `paths.${key} must be inside ${P.state}`);
    }
    const seen = new Map();
    for (const key of privateKeys) {
      if (!valid[key]) continue;
      const p = paths[key];
      if (SHARED_TMP.some((t) => within(p, t))) err('path.shared-tmp', `paths.${key} is inside a shared temporary root`);
      if (within(p, paths.appRoot) || within(paths.appRoot, p)) err('path.private-inside-code', `paths.${key} overlaps the application root`);
      if (seen.has(p)) err('path.duplicate', `paths.${key} repeats paths.${seen.get(p)}`);
      seen.set(p, key);
    }
    // Race-free creation builds each tree top root-only under a root ancestor, then its direct children, so
    // every private directory must be such a top or a direct child of one.
    const tops = new Set([...seen.keys()].filter((p) => P.rootAncestors.includes(path.posix.dirname(p))));
    for (const [p, key] of seen) {
      if (!tops.has(p) && !tops.has(path.posix.dirname(p))) err('path.private-tree', `paths.${key} must sit directly under ${P.rootAncestors.join(' or ')} or directly inside such a private directory`);
    }
  }

  // ---- FPM socket: root:frontend 0660 in its own directory, never TCP, never ACL-overridden -----------------
  const fpm = input.fpm;
  if (isObject(fpm)) {
    for (const k of Object.keys(fpm)) if (/acl/i.test(k)) err('fpm.acl-override', `fpm.${k}: listen ACLs override socket owner/group/mode and are refused`);
  }
  if (closed(fpm, 'fpm', ['listen', 'listenOwner', 'listenGroup', 'listenMode', 'maxChildren'])) {
    if (typeof fpm.listen !== 'string' || !fpm.listen.startsWith('/')) err('fpm.tcp-listener', 'fpm.listen must be a Unix socket path; TCP listeners are reachable by every local user');
    else if (safePath(fpm.listen, 'fpm.listen') && fpm.listen !== P.socket) err('fpm.socket-path', `fpm.listen must be ${P.socket}`);
    if (fpm.listenOwner !== 'root') err('fpm.socket-owner', 'fpm.listenOwner must be root so app workers cannot connect as the socket owner');
    if (isObject(ident) && fpm.listenGroup === ident.group) err('fpm.socket-app-group', 'the socket group must not be the app group; workers would reach their own master');
    else if (siblingGroups.has(fpm.listenGroup)) err('fpm.socket-sibling-group', 'a sibling app group must not reach this socket');
    else if (isObject(front) && fpm.listenGroup !== front.group) err('fpm.socket-group', 'fpm.listenGroup must be the frontend group');
    const bits = mode(fpm.listenMode, 'fpm.listenMode');
    if (bits !== null) {
      if (bits & 0o007) err('fpm.socket-world', `fpm.listenMode=${fpm.listenMode} lets every local user connect`);
      else if (fpm.listenMode !== '0660') err('fpm.socket-mode', 'fpm.listenMode must be 0660');
    }
    if (!isInt(fpm.maxChildren) || fpm.maxChildren < 1 || fpm.maxChildren > 32) err('fpm.max-children', 'fpm.maxChildren must be an integer from 1 to 32');
  }

  // ---- siblings: no shared UID/GID/socket, and no socket reachable from this app ----------------------------
  const siblingNames = new Set();
  if (siblings.length === 0) fact('siblings.none-declared', 'No sibling runtimes were declared; cross-app socket and UID separation is unverified.');
  siblings.forEach((s, n) => {
    const label = `siblings[${n}]`;
    if (!closed(s, label, ['app', 'user', 'group', 'uid', 'gid', 'listen', 'listenGroup', 'listenMode', 'evidence'])) return;
    if (typeof s.app !== 'string' || !NAME_RE.test(s.app)) err('identity.name', `${label}.app must be an app name`);
    else if (s.app === P.app) err('sibling.self', `${label} names this app`);
    else if (siblingNames.has(s.app)) err('sibling.duplicate', `${label}.app ${s.app} is declared twice`);
    siblingNames.add(s.app);
    name(s.user, `${label}.user`); name(s.group, `${label}.group`);
    id(s.uid, `${label}.uid`, { allowSystem: true }); id(s.gid, `${label}.gid`, { allowSystem: true });
    if (isObject(ident)) {
      if (s.uid === ident.uid || s.user === ident.user) err('identity.shared-uid', `${label} (${s.app}) shares this app's runtime UID`);
      if (s.gid === ident.gid || s.group === ident.group) err('identity.shared-gid', `${label} (${s.app}) shares this app's runtime group`);
    }
    if (isObject(front) && (s.uid === front.uid || s.user === front.user)) err('sibling.frontend-uid', `${label} (${s.app}) runs PHP as the frontend UID, which can reach every app socket`);
    if (safePath(s.listen, `${label}.listen`) && isObject(fpm) && s.listen === fpm.listen) err('sibling.shared-socket', `${label} (${s.app}) uses this app's socket`);
    if (isObject(ident) && s.listenGroup === ident.group) err('sibling.socket-reachable', `${label} (${s.app}) socket group is this app's group`);
    const bits = mode(s.listenMode, `${label}.listenMode`);
    if (bits !== null && bits & 0o007) err('sibling.socket-reachable', `${label} (${s.app}) socket mode ${s.listenMode} is reachable by every local user`);
    if (isObject(front) && typeof s.listenGroup === 'string' && s.listenGroup !== front.group && s.listenGroup !== (isObject(ident) && ident.group)) {
      fact(`sibling.${s.app}.socket-group`, `${s.app} socket group ${s.listenGroup} is not the frontend group; its routing is unverified.`);
    }
    if (s.evidence === 'unverified') fact(`sibling.${s.app}.unverified`, `${s.app} identity and socket values are declared, not measured.`);
    else if (s.evidence !== 'declared') err('schema.enum', `${label}.evidence must be declared or unverified`);
  });

  // ---- handlers: every required vhost accounted for; PHP only through this app's FPM socket ------------------
  const handlers = list(input.handlers, 'handlers', { min: 1 });
  const handlerKeys = new Set();
  handlers.forEach((h, n) => {
    const label = `handlers[${n}]`;
    if (!closed(h, label, ['serverName', 'port', 'documentRoot', 'php'])) return;
    if (typeof h.serverName !== 'string' || !HOST_RE.test(h.serverName)) err('handler.server-name', `${label}.serverName must be a lowercase host name`);
    if (h.port !== 80 && h.port !== 443) err('handler.port', `${label}.port must be 80 or 443`);
    const key = `${h.serverName}:${h.port}`;
    if (handlerKeys.has(key)) err('handler.duplicate', `${label} repeats ${key}`);
    handlerKeys.add(key);
    const rootOk = safePath(h.documentRoot, `${label}.documentRoot`);
    if (h.php === 'mod_php') err('handler.mod-php', `${key} executes PHP in the frontend; the boundary fails`);
    else if (h.php === 'fpm') { if (rootOk && isObject(paths) && h.documentRoot !== paths.documentRoot) err('handler.cross-root', `${key} would send another root's PHP to this app's socket`); }
    else if (h.php === 'none') { if (rootOk && isObject(paths) && within(h.documentRoot, paths.appRoot)) err('handler.source-exposure', `${key} serves this application's tree without its PHP handler and would expose source`); }
    else if (h.php === 'unknown') fact(`handler.${key}.unverified`, `${key} PHP handling was not examined; it stays unverified.`);
    else err('schema.enum', `${label}.php must be fpm, none, mod_php or unknown`);
  });
  for (const r of P.requiredHandlers) {
    if (!handlerKeys.has(`${r.serverName}:${r.port}`)) err('handler.omission', `required handler ${r.serverName}:${r.port} is not accounted for`);
  }

  // ---- cron/CLI: every script in the source cron directory is routed to the app UID or explicitly unrouted --
  const cronDir = path.join(sourceRoot, ...P.cronDir.split('/'));
  const sourceScripts = new Set();
  try {
    const st = fs.lstatSync(cronDir);
    if (st.isSymbolicLink() || !st.isDirectory()) err('source.cron-dir', `${P.cronDir} is not a physical directory`);
    else {
      for (const entry of fs.readdirSync(cronDir).sort()) {
        const ext = path.extname(entry);
        if (!Object.prototype.hasOwnProperty.call(P.cronInvokers, ext)) continue;
        const es = fs.lstatSync(path.join(cronDir, entry));
        if (es.isSymbolicLink()) err('source.symlink', `${P.cronDir}/${entry} is a symlink`);
        else if (es.isFile()) sourceScripts.add(entry);
      }
    }
  } catch (e) {
    err('source.cron-dir', `${P.cronDir} cannot be inventoried (${e.code || e.message})`);
  }
  const routed = new Set();
  const cronEntries = list(input.cron, 'cron');
  const cronLines = new Set();
  cronEntries.forEach((c, n) => {
    const label = `cron[${n}]`;
    if (!closed(c, label, ['script', 'schedule', 'user', 'args'])) return;
    if (typeof c.script !== 'string' || !SCRIPT_RE.test(c.script)) { err('cron.script', `${label}.script must be a plain script file name`); return; }
    if (!sourceScripts.has(c.script)) err('cron.unknown-script', `${label}.script ${c.script} is not in ${P.cronDir}`);
    const fields = typeof c.schedule === 'string' ? c.schedule.split(' ') : [];
    if (fields.length !== 5 || !fields.every((f) => CRON_FIELD_RE.test(f))) err('cron.schedule', `${label}.schedule must be five plain cron fields`);
    if (isObject(front) && c.user === front.user) err('cron.frontend-user', `${label} (${c.script}) would run application code as the frontend UID`);
    else if (isObject(ident) && c.user !== ident.user) err('cron.user', `${label} (${c.script}) must run as ${P.user}`);
    const args = list(c.args, `${label}.args`);
    if (args.length > 6 || !args.every((a) => typeof a === 'string' && ARG_RE.test(a))) err('cron.args', `${label}.args must be at most six plain --flag[=value] arguments`);
    const line = `${c.script} ${args.join(' ')}`;
    if (cronLines.has(line)) err('cron.duplicate', `${label} repeats ${line.trim()}`);
    cronLines.add(line);
    routed.add(c.script);
  });
  const unrouted = new Set();
  list(input.unroutedCron, 'unroutedCron').forEach((u, n) => {
    const label = `unroutedCron[${n}]`;
    if (!closed(u, label, ['script', 'reason'])) return;
    if (typeof u.script !== 'string' || !SCRIPT_RE.test(u.script)) { err('cron.script', `${label}.script must be a plain script file name`); return; }
    if (!sourceScripts.has(u.script)) err('cron.unknown-script', `${label}.script ${u.script} is not in ${P.cronDir}`);
    if (routed.has(u.script)) err('cron.conflict', `${u.script} is both routed and unrouted`);
    if (unrouted.has(u.script)) err('cron.duplicate', `${label} repeats ${u.script}`);
    unrouted.add(u.script);
    const reasons = {
      'invoked-by-wrapper': 'runs only through a routed wrapper; the wrapper identity checks must move with it',
      'manual-operator': 'is an operator CLI; each invocation must run as the app identity, which is unverified',
      'root-controller': 'runs as root by design; its group and ownership checks must move with the runtime identity',
      retired: 'is declared retired; confirm no live cron or unit still invokes it',
      unverified: 'routing was not examined',
    };
    if (!Object.prototype.hasOwnProperty.call(reasons, u.reason)) err('schema.enum', `${label}.reason must be one of ${Object.keys(reasons).join(', ')}`);
    else fact(`cron.${u.script}.unrouted`, `${P.cronDir}/${u.script} ${reasons[u.reason]}.`);
  });
  for (const script of sourceScripts) {
    if (!routed.has(script) && !unrouted.has(script)) err('cron.omission', `${P.cronDir}/${script} is neither routed to ${P.user} nor explicitly unrouted`);
  }

  // ---- config/issuer reads: app-only, never frontend- or world-readable, never public -----------------------
  const configIds = new Map();
  list(input.configReads, 'configReads', { min: 1 }).forEach((c, n) => {
    const label = `configReads[${n}]`;
    if (!isObject(c)) { err('schema.type', `${label} must be an object`); return; }
    if (c.status === 'declared') {
      if (!closed(c, label, ['id', 'status', 'path', 'owner', 'group', 'mode'])) return;
    } else if (!closed(c, label, ['id', 'status'], ['note'])) return;
    if (typeof c.id !== 'string' || !ID_RE.test(c.id)) { err('config.id', `${label}.id must be a short lowercase id`); return; }
    if (configIds.has(c.id)) err('config.duplicate', `${label}.id ${c.id} is repeated`);
    configIds.set(c.id, c);
    if (c.status === 'held' || c.status === 'unverified') {
      if (c.note !== undefined && (typeof c.note !== 'string' || c.note.length > 240 || /[\r\n]/.test(c.note))) err('config.note', `${label}.note must be one short line`);
      fact(`config.${c.id}.${c.status}`, `${c.id} read path and permissions are ${c.status === 'held' ? 'held with the cache/config change' : 'not examined'}${c.note ? `: ${c.note}` : ''}.`);
      return;
    }
    if (c.status !== 'declared') { err('schema.enum', `${label}.status must be declared, held or unverified`); return; }
    if (safePath(c.path, `${label}.path`) && isObject(paths) && typeof paths.documentRoot === 'string' && within(c.path, paths.documentRoot)) err('config.public', `${c.id} is inside the DocumentRoot`);
    if (isObject(ident) && c.owner === ident.user) err('config.runtime-writable', `${c.id} must not be owned by the runtime account`);
    else if (!(c.owner === 'root' || (isObject(owner) && c.owner === owner.user))) err('config.owner', `${c.id} owner must be root or the deploy owner`);
    if (isObject(front) && c.group === front.group) err('config.frontend-readable', `${c.id} is readable by the frontend group`);
    else if (isObject(ident) && c.group !== ident.group) err('config.group', `${c.id} group must be ${P.user}`);
    const bits = mode(c.mode, `${label}.mode`);
    if (bits !== null) {
      if (bits & 0o007) err('config.world-readable', `${c.id} mode ${c.mode} grants other users access`);
      else if (bits & 0o022) err('config.writable', `${c.id} mode ${c.mode} is group-writable`);
      else if (c.mode !== '0640' && c.mode !== '0440') err('config.mode', `${c.id} mode must be 0640 or 0440`);
    }
  });
  for (const r of P.requiredConfigReads) {
    const c = configIds.get(r.id);
    if (!c) err('config.omission', `required config read ${r.id} is not accounted for`);
    else if (c.status !== 'declared') err('config.omission', `required config read ${r.id} must be declared, not ${c.status}`);
    else if (c.path !== r.path) err('config.path', `${r.id} must be ${r.path}`);
  }
  for (const held of P.heldConfigReads) {
    if (!configIds.has(held)) err('config.omission', `issuer/cache read ${held} is not accounted for`);
  }

  // ---- optional read-only observations of the target host (lstat snapshot fixture) ---------------------------
  const observed = new Map();
  list(input.observed === undefined ? [] : input.observed, 'observed').forEach((o, n) => {
    const label = `observed[${n}]`;
    if (!closed(o, label, ['path', 'type'], ['uid', 'gid', 'mode'])) return;
    // Observations may describe broad ancestors (a symlinked /srv matters), so only shape is checked here.
    if (typeof o.path !== 'string' || o.path.split('/').some((seg) => seg === '.' || seg === '..')
        || !(o.path === '/' || PATH_RE.test(o.path))) { err('path.unsafe', `${label}.path must be a normalized absolute path`); return; }
    if (!['directory', 'file', 'symlink', 'socket', 'absent'].includes(o.type)) { err('schema.enum', `${label}.type is not a known file type`); return; }
    if (o.uid !== undefined && !isInt(o.uid)) err('schema.type', `${label}.uid must be an integer`);
    if (o.gid !== undefined && !isInt(o.gid)) err('schema.type', `${label}.gid must be an integer`);
    if (o.mode !== undefined) mode(o.mode, `${label}.mode`);
    if (observed.has(o.path)) err('observed.duplicate', `${label} repeats ${o.path}`);
    observed.set(o.path, o);
  });
  const configured = [];
  if (pathsOk) {
    for (const key of ['appRoot', 'documentRoot', 'deployState', ...privateKeys]) if (valid[key]) configured.push([key, paths[key]]);
  }
  configured.push(['socketDir', P.socketDir]);
  for (const [cid, c] of configIds) if (c.status === 'declared' && typeof c.path === 'string' && PATH_RE.test(c.path)) configured.push([`config:${cid}`, c.path]);
  const unmeasured = [];
  for (const [key, p] of configured) {
    const segments = p.split('/').slice(1);
    for (let k = 1; k <= segments.length; k += 1) {
      const ancestor = `/${segments.slice(0, k).join('/')}`;
      const o = observed.get(ancestor);
      if (o && o.type === 'symlink') err('path.symlink', `${key}=${p} traverses the observed symlink ${ancestor}`);
    }
    const o = observed.get(p);
    if (!o) { unmeasured.push(p); continue; }
    if (key.startsWith('config:')) { if (o.type !== 'file' && o.type !== 'absent' && o.type !== 'symlink') err('path.type', `${p} is observed as ${o.type}, not a file`); continue; }
    if (o.type === 'file' || o.type === 'socket') err('path.type', `${key}=${p} is observed as a ${o.type}, not a directory`);
    if (key === 'appRoot' && o.type === 'directory' && typeof o.mode === 'string' && MODE_RE.test(o.mode) && (modeBits(o.mode) & 0o022)) {
      err('code.writable-observed', `${p} is observed group/other-writable (${o.mode})`);
    }
    if (privateKeys.includes(key) && o.type === 'directory' && isObject(ident)) {
      if (o.uid !== ident.uid || o.gid !== ident.gid || o.mode !== '0700') {
        fact(`observed.${key}.mismatch`, `${p} exists as ${o.uid}:${o.gid} ${o.mode}; provisioning refuses to adopt it, and a reviewed per-path migration is required.`);
      }
    }
  }
  if (unmeasured.length) fact('observed.unmeasured', `No read-only observation covers ${unmeasured.length} configured path(s), so symlinks and existing metadata there are unverified: ${[...new Set(unmeasured)].sort().join(', ')}.`);

  if (errors.length) throw new RenderError(errors);

  for (const [fid, detail] of [...COMMON_FACTS, ...P.staticFacts]) fact(fid, detail);
  facts.sort(byKey((f) => `${f.id}\0${f.detail}`));
  return { input, facts, sourceScripts: [...sourceScripts].sort(), unrouted: [...unrouted].sort() };
}

function loadTemplates(templateDir) {
  const dir = fs.lstatSync(templateDir);
  if (dir.isSymbolicLink() || !dir.isDirectory()) throw new RenderError([`template.dir: ${templateDir} is not a physical directory`]);
  const out = {};
  for (const [key, file] of Object.entries(TEMPLATES)) {
    const full = path.join(templateDir, file);
    let st;
    try { st = fs.lstatSync(full); } catch (e) { throw new RenderError([`template.missing: ${file}`]); }
    if (st.isSymbolicLink() || !st.isFile()) throw new RenderError([`template.symlink: ${file} must be a regular file`]);
    if (st.size > MAX_TEMPLATE_BYTES) throw new RenderError([`template.size: ${file} is too large`]);
    out[key] = fs.readFileSync(full, 'utf8').replace(/\r\n/g, '\n');
  }
  return out;
}

function fill(template, values, label) {
  const missing = [];
  const text = template.replace(/\{\{([A-Z0-9_]+)\}\}/g, (match, key) => {
    if (!Object.prototype.hasOwnProperty.call(values, key)) { missing.push(key); return match; }
    return values[key];
  });
  if (missing.length) throw new RenderError([`template.placeholder: ${label} uses unknown placeholder(s) ${[...new Set(missing)].join(', ')}`]);
  if (/\{\{|\}\}/.test(text)) throw new RenderError([`template.placeholder: ${label} contains an unterminated placeholder`]);
  return text;
}

/** Validate and produce the artifact map { fileName: utf8 text } without touching the filesystem. */
export function renderArtifacts(rawInput, options = {}) {
  const P = options.profile || PROFILE;
  const { input, facts } = validate(rawInput, options);
  const templates = loadTemplates(options.templateDir || TEMPLATE_DIR);
  const digest = sha256(canonicalJson(input));
  const { identity: ident, frontend: front, deployOwner: owner, paths, fpm, code } = input;
  const privateKeys = ['state', 'sessions', 'tmp', 'cache', ...Object.keys(P.extraPrivate)];

  const cronLines = input.cron.map((c) => {
    const invoker = P.cronInvokers[path.extname(c.script)];
    return `${c.schedule} ${ident.user} ${invoker} ${paths.appRoot}/cron/${c.script}${c.args.length ? ` ${c.args.join(' ')}` : ''}`;
  });
  const unroutedLines = input.unroutedCron.map((u) => `# unrouted (${u.reason}): ${paths.appRoot}/cron/${u.script}`);
  const handlerLines = input.handlers.map((h) => {
    if (h.php === 'fpm') return `#   ${h.serverName}:${h.port}  ${h.documentRoot}  -> include the FPM section below`;
    if (h.php === 'none') return `#   ${h.serverName}:${h.port}  ${h.documentRoot}  -> static/proxy only: include the PHP-denial section below`;
    return `#   ${h.serverName}:${h.port}  ${h.documentRoot}  -> UNVERIFIED: not examined, no section rendered`;
  });
  const denyRoots = [...new Set(input.handlers.filter((h) => h.php === 'none').map((h) => h.documentRoot))].sort();
  const denySections = denyRoots.map((root) => [
    `<Directory "${root}">`,
    '    <FilesMatch "(?i)\\.(?:php[0-9]*|phtml|phar)(?:/|$)">',
    '        SetHandler None',
    '        Require all denied',
    '    </FilesMatch>',
    '</Directory>',
  ].join('\n'));
  const privateDirs = privateKeys.map((k) => paths[k]).sort();
  const treeChecks = privateDirs.filter((d) => P.rootAncestors.includes(path.posix.dirname(d))).map((top) => [
    `ensure_private_tree '${top}'`,
    ...privateDirs.filter((d) => path.posix.dirname(d) === top).map((d) => `'${path.posix.basename(d)}'`),
  ].join(' '));
  const configChecks = input.configReads.filter((c) => c.status === 'declared').map((c) => {
    const ownerUid = c.owner === 'root' ? 0 : owner.uid;
    return `verify_config '${c.path}' ${ownerUid} ${ident.gid} ${c.mode.replace(/^0/, '')}`;
  });

  const values = {
    APP: P.app,
    APP_USER: ident.user,
    APP_GROUP: ident.group,
    APP_UID: String(ident.uid),
    APP_GID: String(ident.gid),
    FRONTEND_USER: front.user,
    FRONTEND_GROUP: front.group,
    FRONTEND_GID: String(front.gid),
    DEPLOY_OWNER: owner.user,
    DEPLOY_UID: String(owner.uid),
    APP_ROOT: paths.appRoot,
    DOCUMENT_ROOT: paths.documentRoot,
    STATE_DIR: paths.state,
    SESSION_DIR: paths.sessions,
    TMP_DIR: paths.tmp,
    CACHE_DIR: paths.cache,
    SOCKET_DIR: P.socketDir,
    SOCKET: fpm.listen,
    MAX_CHILDREN: String(fpm.maxChildren),
    CODE_DIR_MODE: code.dirMode.replace(/^0/, ''),
    CODE_FILE_MODE: code.fileMode.replace(/^0/, ''),
    CRON_FILE: P.cronFile,
    CRON_LINES: [...cronLines, ...unroutedLines].join('\n'),
    HANDLER_LINES: handlerLines.join('\n'),
    DENY_SECTIONS: denySections.length ? denySections.join('\n\n') : '# (no static/proxy-only roots declared)',
    ROOT_ANCESTOR_CHECKS: P.rootAncestors.map((a) => `ensure_root_ancestor '${a}'`).join('\n'),
    PRIVATE_DIR_CHECKS: treeChecks.join('\n'),
    CONFIG_CHECKS: configChecks.length ? configChecks.join('\n') : ': # no declared config reads',
    INPUT_SHA256: digest,
  };

  const artifacts = {
    [`pool-${P.app}.conf`]: fill(templates.pool, values, TEMPLATES.pool),
    [`apache-${P.app}.conf`]: fill(templates.apache, values, TEMPLATES.apache),
    [`provision-private-dirs-${P.app}.sh`]: fill(templates.provision, values, TEMPLATES.provision),
    [`cron-${P.app}.env`]: fill(templates.cron, values, TEMPLATES.cron),
  };
  const provision = artifacts[`provision-private-dirs-${P.app}.sh`];
  const forbidden = provision.split('\n').filter((l) => !/^\s*#/.test(l)).join('\n').match(FORBIDDEN_PROVISION_TOKENS);
  if (forbidden) throw new RenderError([`template.forbidden: rendered provisioning script contains ${forbidden[0]}`]);

  artifacts['facts.json'] = `${JSON.stringify({
    schema: FACTS_SCHEMA,
    app: P.app,
    status: 'offline-candidate-not-installed',
    certified: false,
    inputSha256: digest,
    unmet: facts,
  }, null, 2)}\n`;
  const manifest = {};
  for (const name of Object.keys(artifacts).sort()) {
    const data = Buffer.from(artifacts[name], 'utf8');
    manifest[name] = { bytes: data.length, sha256: sha256(data) };
  }
  artifacts['manifest.json'] = `${JSON.stringify(manifest, null, 2)}\n`;
  return { artifacts, facts, inputSha256: digest };
}

/** Read and parse one input file: a regular, non-symlink, bounded JSON document without duplicate keys. */
export function readInput(file) {
  let st;
  try { st = fs.lstatSync(file); } catch (e) { throw new RenderError([`input.missing: ${file}`]); }
  if (st.isSymbolicLink() || !st.isFile()) throw new RenderError(['input.symlink: the input must be a regular file, not a symlink']);
  if (st.size > MAX_INPUT_BYTES) throw new RenderError(['input.size: the input is too large']);
  const text = fs.readFileSync(file, 'utf8');
  let parsed;
  try { parsed = JSON.parse(text); } catch (e) { throw new RenderError([`schema.json: ${e.message}`]); }
  assertNoDuplicateKeys(text);
  return parsed;
}

/** Render into a NEW directory outside the source tree. Existing paths are never overwritten or adopted. */
export function renderToDirectory(rawInput, outputDir, options = {}) {
  const sourceRoot = options.sourceRoot || SOURCE_ROOT;
  const target = path.resolve(outputDir);
  const rel = path.relative(path.resolve(sourceRoot), target);
  if (rel === '' || (!rel.startsWith('..') && !path.isAbsolute(rel))) throw new RenderError(['output.inside-source: render outside the repository; candidates are never written into source']);
  const result = renderArtifacts(rawInput, options);
  let exists = true;
  try { fs.lstatSync(target); } catch (e) { if (e.code === 'ENOENT') exists = false; else throw e; }
  if (exists) throw new RenderError(['output.exists: the output must be a new directory; existing artifacts are never overwritten']);
  const parent = fs.lstatSync(path.dirname(target));
  if (parent.isSymbolicLink() || !parent.isDirectory()) throw new RenderError(['output.parent: the output parent must be a physical directory']);
  fs.mkdirSync(target, { mode: 0o750 });
  for (const name of Object.keys(result.artifacts).sort()) {
    fs.writeFileSync(path.join(target, name), result.artifacts[name], { flag: 'wx', mode: 0o640 });
  }
  return result;
}

function main(argv) {
  const args = argv.slice(2);
  const usage = () => { process.stderr.write('usage: render.mjs --input FILE (--check | --output NEW_DIRECTORY)\n'); return 64; };
  let input = null; let output = null; let check = false;
  for (let k = 0; k < args.length; k += 1) {
    if (args[k] === '--input' && input === null && k + 1 < args.length) input = args[++k];
    else if (args[k] === '--output' && output === null && k + 1 < args.length) output = args[++k];
    else if (args[k] === '--check' && !check) check = true;
    else return usage();
  }
  if (input === null || check === (output !== null)) return usage();
  try {
    const raw = readInput(input);
    const result = check ? renderArtifacts(raw) : renderToDirectory(raw, output);
    process.stdout.write(`${JSON.stringify({
      status: 'offline-candidate-not-installed',
      certified: false,
      inputSha256: result.inputSha256,
      unmetFacts: result.facts.length,
      artifacts: Object.keys(result.artifacts).sort(),
      output: check ? null : path.resolve(output),
    }, null, 2)}\n`);
    return 0;
  } catch (e) {
    if (!(e instanceof RenderError)) throw e;
    process.stderr.write(`${JSON.stringify({ status: 'refused', errors: e.errors }, null, 2)}\n`);
    return 2;
  }
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  process.exitCode = main(process.argv);
}
