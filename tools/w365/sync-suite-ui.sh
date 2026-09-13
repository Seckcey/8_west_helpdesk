#!/usr/bin/env bash
# Vendor one released version of the w365 package into a product app.
#
#   tools/sync-suite-ui.sh --tag v0.1.0 --dest portal/public/assets/w365
#   tools/sync-suite-ui.sh --tag v0.1.0 --dest portal/public/assets/w365 --from ../8_west_suite_ui
#
# Without --from, the release asset w365-<tag>.zip is downloaded from the GitHub Release of
# Seckcey/8_west_suite_ui (needs `gh` signed in). With --from, the files are copied from a local
# checkout of the package repo — for trying an unreleased build; the guard test in the consumer
# still pins the manifest hash, so an unreleased copy cannot merge by accident.
#
# Every file is verified against w365-manifest.json before the destination is replaced, and the
# replacement is atomic (a sibling temp dir is moved into place). Fails closed on any mismatch.
set -euo pipefail

REPO="Seckcey/8_west_suite_ui"
TAG="" DEST="" FROM=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --tag)  TAG="$2"; shift 2 ;;
    --dest) DEST="$2"; shift 2 ;;
    --from) FROM="$2"; shift 2 ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done
[[ -n "$TAG" && -n "$DEST" ]] || { echo "usage: $0 --tag vX.Y.Z --dest <dir> [--from <package checkout>]" >&2; exit 2; }
[[ "$TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "tag must look like v0.1.0" >&2; exit 2; }
VERSION="${TAG#v}"

sha256_of() {
  if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | awk '{print $1}'
  else shasum -a 256 "$1" | awk '{print $1}'; fi
}

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

if [[ -n "$FROM" ]]; then
  [[ -d "$FROM/dist/w365" ]] || { echo "no dist/w365 under $FROM" >&2; exit 2; }
  cp -R "$FROM/dist/w365/." "$work/w365/"
else
  command -v gh >/dev/null 2>&1 || { echo "gh is required to download a release (or pass --from)" >&2; exit 2; }
  gh release download "$TAG" -R "$REPO" -p "w365-${TAG}.zip" -D "$work"
  command -v unzip >/dev/null 2>&1 || { echo "unzip is required" >&2; exit 2; }
  mkdir -p "$work/w365"
  unzip -q "$work/w365-${TAG}.zip" -d "$work/w365"
fi

manifest="$work/w365/w365-manifest.json"
[[ -f "$manifest" ]] || { echo "the package has no w365-manifest.json" >&2; exit 1; }
command -v node >/dev/null 2>&1 || { echo "node is required to verify the manifest" >&2; exit 2; }
node - "$manifest" "$VERSION" "$work/w365" <<'EOF'
const fs = require('node:fs'); const path = require('node:path'); const crypto = require('node:crypto');
const [manifestPath, version, dir] = process.argv.slice(2);
const m = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
if (m.version !== version) { console.error(`manifest version ${m.version} != requested ${version}`); process.exit(1); }
const listed = new Set(Object.keys(m.files));
const walk = (d) => fs.readdirSync(d, { withFileTypes: true }).flatMap((e) => e.isDirectory() ? walk(path.join(d, e.name)) : [path.join(d, e.name)]);
const present = walk(dir).map((f) => path.relative(dir, f).split(path.sep).join('/')).filter((f) => f !== 'w365-manifest.json');
for (const f of present) if (!listed.has(f)) { console.error(`unexpected file not in manifest: ${f}`); process.exit(1); }
for (const [f, meta] of Object.entries(m.files)) {
  const p = path.join(dir, f);
  if (!fs.existsSync(p)) { console.error(`missing: ${f}`); process.exit(1); }
  const sha = crypto.createHash('sha256').update(fs.readFileSync(p)).digest('hex');
  if (sha !== meta.sha256) { console.error(`sha256 mismatch: ${f}`); process.exit(1); }
}
console.log(`verified w365 ${m.version}: ${Object.keys(m.files).length} files`);
EOF

mkdir -p "$(dirname "$DEST")"
staged="$(dirname "$DEST")/.w365-incoming-$$"
rm -rf "$staged"
cp -R "$work/w365" "$staged"
if [[ -d "$DEST" ]]; then rm -rf "$DEST.previous"; mv "$DEST" "$DEST.previous"; fi
mv "$staged" "$DEST"
rm -rf "$DEST.previous"
echo "vendored w365 $VERSION into $DEST"
echo "manifest sha256: $(sha256_of "$DEST/w365-manifest.json")  (pin this in the consumer guard test)"
