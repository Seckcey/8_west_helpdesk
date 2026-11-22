#!/usr/bin/env bash
# --tag vX.Y.Z vendors a verified GitHub release. --from builds a local checkout's
# committed HEAD into --out without writing to the checkout or contacting a remote.
set -euo pipefail

die() { echo "$*" >&2; exit 1; }
usage() { die "usage: $0 --tag vX.Y.Z | --from <catalog checkout> --out <scratch directory>"; }

repo=Seckcey/8_west_help_catalog
tag= checkout= out=
while (( $# )); do
    [[ -n ${2:-} ]] || usage
    case "$1" in
        --tag)
            [[ -z $tag && $2 =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || usage
            tag=$2 ;;
        --from) [[ -z $checkout ]] || usage; checkout=$2 ;;
        --out)  [[ -z $out ]] || usage; out=$2 ;;
        *) usage ;;
    esac
    shift 2
done

app=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../../app" && pwd -P)
if [[ -n $tag ]]; then
    [[ -z $checkout && -z $out ]] || usage
    dest="$app/help"
else
    [[ -n $checkout && -n $out && -d $checkout ]] || usage
    dest=$(realpath -m -- "$out")
    # Compare physical ancestors as well as resolving symlinks, including Windows path aliases.
    ancestor=$dest
    while [[ $ancestor != / ]]; do
        [[ ! $ancestor -ef $app ]] || die '--out must be outside app/'
        ancestor=$(dirname -- "$ancestor")
    done
fi

shopt -s nullglob dotglob
check_pair() {
    local dir=$1
    [[ -d $dir && ! -L $dir ]] || die "expected a regular directory: $dir"
    local entries=("$dir"/*)
    if [[ ${2:-} == allow-empty && ${#entries[@]} == 0 ]]; then return 0; fi
    [[ ${#entries[@]} == 2 && -f $dir/customer-index.json && ! -L $dir/customer-index.json &&
        -f $dir/help-manifest.json && ! -L $dir/help-manifest.json ]] || die "unexpected files in $dir"
}
check_destination() {
    # Never replace unrelated files in a caller's scratch directory.
    if [[ -e $dest || -L $dest ]]; then check_pair "$dest" allow-empty; fi
}
check_destination
parent=$(dirname -- "$dest")
mkdir -p -- "$parent"
work=$(mktemp -d -- "$parent/.help-catalog.XXXXXXXX")
trap 'rm -rf -- "$work"' EXIT
trap 'exit 1' HUP INT TERM
build="$work/.build"
src="$build/source"
mkdir -p -- "$src"

# Verify the raw bytes before building or publishing. The only stdout is the manifest pin.
verify_pair() {
    node - "$1" "$2" <<'JS'
const assert = require('node:assert/strict');
const { createHash } = require('node:crypto');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const [dir, version] = process.argv.slice(2);
const index = readFileSync(join(dir, 'customer-index.json'));
const manifest = readFileSync(join(dir, 'help-manifest.json'));
const hash = bytes => createHash('sha256').update(bytes).digest('hex');
assert.match(version, /^[0-9]+\.[0-9]+\.[0-9]+$/);
assert.ok(!index.includes(13) && !manifest.includes(13), 'expected LF catalog files');
assert.deepEqual(JSON.parse(manifest), {
    format: '8west-help-manifest-v1', index: 'customer', version,
    files: { 'customer-index.json': { sha256: hash(index), bytes: index.length } },
    hash_format: 'SHA-256 of the exact LF file bytes',
});
const data = JSON.parse(index);
assert.equal(data.format, '8west-help-index-v1');
assert.equal(data.index, 'customer');
assert.equal(data.version, version);
console.log(hash(manifest));
JS
}

if [[ -n $tag ]]; then
    sha=$(gh api "repos/$repo/commits/$tag" --jq .sha)
    [[ $sha =~ ^[0-9a-f]{40}$ ]] || die 'could not resolve the tag to a full commit'
    status=$(gh api "repos/$repo/compare/$sha...main" --jq .status)
    [[ $status == ahead || $status == identical ]] || die 'tag commit is not on catalog main'

    archive="$build/help-customer-$tag.zip"
    gh release download "$tag" --repo "$repo" --pattern "help-customer-$tag.zip" --dir "$build"
    unset UNZIP UNZIPOPT ZIPINFO ZIPINFOOPT
    expected=$(printf '%s\n' customer-index.json help-manifest.json)
    names=$(unzip -Z1 "$archive" | LC_ALL=C sort)
    [[ $names == "$expected" ]] || die 'unexpected customer archive entries'
    regular=$(LC_ALL=C zipinfo -l "$archive" | awk '/^-/ {print $NF}' | LC_ALL=C sort)
    [[ $regular == "$expected" ]] || die 'customer archive entries must both be regular files'
    pair="$build/release"
    mkdir -- "$pair"
    unzip -q "$archive" -d "$pair"
    check_pair "$pair"
    pin=$(verify_pair "$pair" "${tag#v}")

    # Fetch only the resolved commit. Even if the tag moves, the rebuild uses the reviewed SHA.
    git init -q "$src"
    git -C "$src" -c credential.helper= -c 'credential.helper=!gh auth git-credential' \
        fetch --quiet --depth=1 --no-tags "https://github.com/$repo.git" "$sha"
    git -C "$src" -c core.autocrlf=false checkout --quiet --detach FETCH_HEAD
    [[ $(git -C "$src" rev-parse HEAD) == "$sha" ]] || die 'checkout differs from the resolved tag'
else
    # git archive reads the committed bytes without checkout filters, including core.autocrlf.
    local_git() { GIT_ALLOW_PROTOCOL= GIT_NO_LAZY_FETCH=1 git -C "$checkout" "$@"; }
    prefix=$(local_git rev-parse --show-prefix)
    [[ -z $prefix ]] || die '--from must name the catalog checkout root'
    sha=$(local_git rev-parse --verify 'HEAD^{commit}')
    local_git cat-file -e "$sha:tools/build.mjs" || die 'catalog checkout lacks tools/build.mjs'
    local_git archive --format=tar "$sha" | tar -xf - -C "$src"
fi

version=$(cd -- "$src" && node -p "require('./package.json').version")
[[ -z $tag || $tag == "v$version" ]] || die 'tag differs from the catalog package version'
# The catalog tracks dist/. Compare against fresh output even if a builder omits a file.
rm -rf -- "$src/dist"
(cd -- "$src" && node tools/build.mjs >/dev/null)
rebuilt="$src/dist/customer"
check_pair "$rebuilt"
if [[ -n $tag ]]; then
    cmp -- "$pair/customer-index.json" "$rebuilt/customer-index.json"
    cmp -- "$pair/help-manifest.json" "$rebuilt/help-manifest.json"
else
    pair=$rebuilt
    pin=$(verify_pair "$pair" "$version")
fi

cp -- "$pair/customer-index.json" "$pair/help-manifest.json" "$work/"
rm -rf -- "$build"
chmod 0644 "$work/customer-index.json" "$work/help-manifest.json"
chmod 0755 "$work"

# A plain mv cannot replace a populated directory. Exchange the old and new directories
# in one filesystem operation (or one NTFS transaction on Git for Windows).
exchange_dirs() {
    if [[ $(mv --help 2>/dev/null) == *--exchange* ]]; then
        mv -T --exchange --no-copy -- "$work" "$dest"
        return
    fi
    local interpreter=python3
    case "$OSTYPE" in msys*|cygwin*) interpreter=python ;; esac
    "$interpreter" - "$work" "$dest" <<'PY'
import ctypes
import os
import sys

stage, dest = sys.argv[1:]
if os.name == 'nt':
    from ctypes.wintypes import HANDLE
    files = ctypes.WinDLL('kernel32', use_last_error=True)
    ktm = ctypes.WinDLL('ktmw32', use_last_error=True)
    ktm.CreateTransaction.restype = HANDLE
    tx = HANDLE(ktm.CreateTransaction(None, None, 0, 0, 0, 0, None))
    if tx.value == HANDLE(-1).value:
        raise ctypes.WinError(ctypes.get_last_error())
    try:
        # The previous pair ends at stage for the EXIT trap. Closing an uncommitted
        # transaction rolls every rename back, including on process termination.
        for src, dst in [(dest, os.path.join(stage, '.previous')), (stage, dest),
                         (os.path.join(dest, '.previous'), stage)]:
            if not files.MoveFileTransactedW(src, dst, None, None, 0, tx):
                raise ctypes.WinError(ctypes.get_last_error())
        if not ktm.CommitTransaction(tx):
            raise ctypes.WinError(ctypes.get_last_error())
    finally:
        files.CloseHandle(tx)
else:
    libc = ctypes.CDLL(None, use_errno=True)
    src, dst = os.fsencode(stage), os.fsencode(dest)
    if sys.platform == 'darwin':
        result = libc.renamex_np(src, dst, 2)  # RENAME_SWAP
    else:
        result = libc.renameat2(-100, src, -100, dst, 2)  # AT_FDCWD, RENAME_EXCHANGE
    if result != 0:
        raise OSError(ctypes.get_errno(), os.strerror(ctypes.get_errno()))
PY
}

check_destination
if [[ ! -e $dest ]]; then
    mv -T -- "$work" "$dest"
elif [[ -f $dest/customer-index.json ]] && cmp -s -- "$work/customer-index.json" "$dest/customer-index.json" &&
        cmp -s -- "$work/help-manifest.json" "$dest/help-manifest.json"; then
    : # The verified release is already installed.
else
    exchange_dirs
fi
printf 'manifest sha256 %s\nsource commit %s\n' "$pin" "$sha"
