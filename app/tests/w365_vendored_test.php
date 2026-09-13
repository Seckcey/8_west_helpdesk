<?php
/**
 * Safeharbor — the w365 vendoring guard. CLI only. HERMETIC and DB-FREE.
 *
 *   php app/tests/w365_vendored_test.php
 *
 * The shared 8 West IT 365 suite chrome under app/public/assets/w365/ must be EXACTLY the pinned
 * release of Seckcey/8_west_suite_ui. This fails when the vendored copy is edited in place,
 * half-synced, carries a stray file, or is not the release Safeharbor has pinned. (It is the PHP
 * equivalent of the package's tools/guard-template.test.js, put in the language this app's CI
 * already runs on every app/ change.)
 *
 * Bumping to a new release is one commit:
 *   bash tools/w365/sync-suite-ui.sh --tag vX.Y.Z --dest app/public/assets/w365
 *   update PINNED_VERSION and PINNED_MANIFEST_SHA256 below from the script's output
 *   bump the hand-typed ?v= tags in app/lib/render.php
 * Never hand-edit a file under app/public/assets/w365/.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

error_reporting(E_ALL);

const VENDORED_DIR = __DIR__ . '/../public/assets/w365';
const PINNED_VERSION = '0.2.0';
const PINNED_MANIFEST_SHA256 = 'f70a308b0646ac03e4e6e6d6cc55e79bde98d591536d43c5b56277e657760e17';

$PASS = 0; $FAIL = 0;
function t(string $name, bool $cond): void
{
    global $PASS, $FAIL;
    if ($cond) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n"; }
}

/**
 * Text files are hashed with LF line endings: the manifest was built from LF files, and a Windows
 * checkout with core.autocrlf rewrites them as CRLF. Binary files (the PNG marks) are hashed as-is.
 */
function w365_sha256(string $path): string
{
    $bytes = (string)file_get_contents($path);
    if (preg_match('/\.(css|js|json|mjs|svg|txt|md)$/i', $path) === 1) {
        $bytes = str_replace("\r\n", "\n", $bytes);
    }
    return hash('sha256', $bytes);
}

/** Every file under a directory, as paths relative to it, with forward slashes. */
function w365_walk(string $dir, string $prefix = ''): array
{
    $out = [];
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') { continue; }
        $full = $dir . '/' . $entry;
        $relative = $prefix === '' ? $entry : $prefix . '/' . $entry;
        if (is_dir($full)) { $out = array_merge($out, w365_walk($full, $relative)); }
        else { $out[] = $relative; }
    }
    sort($out);
    return $out;
}

$manifestPath = VENDORED_DIR . '/w365-manifest.json';
if (!is_file($manifestPath)) {
    echo "FAIL  the vendored package is missing: run tools/w365/sync-suite-ui.sh --tag v" . PINNED_VERSION
        . " --dest app/public/assets/w365\n";
    exit(1);
}

t('the vendored manifest is the pinned release', w365_sha256($manifestPath) === PINNED_MANIFEST_SHA256);

$manifest = json_decode((string)file_get_contents($manifestPath), true);
t('the manifest is readable and names the w365 package',
    is_array($manifest) && ($manifest['name'] ?? null) === 'w365' && is_array($manifest['files'] ?? null));
t('the vendored version is the pinned version', is_array($manifest) && ($manifest['version'] ?? null) === PINNED_VERSION);

$files = is_array($manifest) && is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
$present = array_values(array_filter(w365_walk(VENDORED_DIR), static fn (string $f): bool => $f !== 'w365-manifest.json'));
$stray = array_values(array_diff($present, array_keys($files)));
t('no file under app/public/assets/w365 that the release does not contain'
    . ($stray === [] ? '' : ': ' . implode(', ', $stray)), $stray === []);

foreach ($files as $file => $meta) {
    $full = VENDORED_DIR . '/' . $file;
    if (!is_file($full)) { t("vendored file present: $file", false); continue; }
    t("vendored file is byte-exact: $file", w365_sha256($full) === (string)($meta['sha256'] ?? ''));
}

/* The registry the drawer reads is the package's, never a hand-kept copy in this repo. */
$suiteApps = (string)file_get_contents(__DIR__ . '/../lib/suite_apps.php');
t('the product registry is read from the vendored package, not hard-coded here',
    str_contains($suiteApps, "/../public/assets/w365/w365-products.json")
    && !preg_match('~=>\s*\'https://(support|coastmark|logbook|cloudline|cp)\.8westit\.com~', $suiteApps));

echo "\n";
if ($FAIL > 0) { echo "W365 VENDORING GUARD FAILED: $FAIL of " . ($PASS + $FAIL) . " checks failed.\n"; exit(1); }
echo "W365 VENDORING GUARD PASSED: all $PASS checks passed.\n";
